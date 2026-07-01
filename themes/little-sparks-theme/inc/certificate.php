<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'template_redirect', 'ls_certificate_preview_intercept', 1 );
function ls_certificate_preview_intercept() {
	if ( ! is_singular( 'ld-certificate' ) ) return;
	if ( empty( $_GET['preview'] ) || ! current_user_can( 'manage_options' ) ) return;
	if ( ! class_exists( 'LearnDash\Certificate_Builder\Mpdf\Mpdf' ) ) return;

	$user      = wp_get_current_user();
	$cert_args = array( 'user_id' => $user->ID, 'post_id' => 0 );
	ls_generate_cpd_certificate( $cert_args, true );
}

add_action( 'learndash_tcpdf_init', 'ls_generate_cpd_certificate', 5 );
function ls_generate_cpd_certificate( $cert_args, bool $preview = false ) {
	if ( ! class_exists( 'LearnDash\Certificate_Builder\Mpdf\Mpdf' ) ) return;

	$user_id   = (int) ( $cert_args['user_id'] ?? 0 );
	$course_id = (int) ( $cert_args['post_id'] ?? 0 );

	if ( $preview ) {
		$user    = get_userdata( $user_id );
		$learner = $user ? $user->display_name : 'Learner Name';
		$course  = 'Level 3 Safeguarding Leadership for Early Years DSLs';
	} else {
		if ( ! $user_id || ! $course_id ) return;
		$user    = get_userdata( $user_id );
		$learner = $user ? $user->display_name : '';
		$course  = get_the_title( $course_id );
	}

	$issued  = ls_cert_ordinal_date( date_i18n( 'j F Y' ) );
	$cert_no = 'LS-' . date( 'Y' ) . '-' . str_pad( $user_id, 4, '0', STR_PAD_LEFT ) . str_pad( $course_id, 3, '0', STR_PAD_LEFT );

	$bg_path  = get_stylesheet_directory() . '/assets/images/cert-background.png';
	$bg_src   = '';
	if ( file_exists( $bg_path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$bg_src = 'data:image/png;base64,' . base64_encode( file_get_contents( $bg_path ) );
	}

	$sig_path = get_stylesheet_directory() . '/assets/images/signature-icon.svg';
	$sig_src  = '';
	if ( file_exists( $sig_path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$sig_src = 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $sig_path ) );
	}

	$mpdf = new \LearnDash\Certificate_Builder\Mpdf\Mpdf( array(
		'format'        => 'A4-L',
		'margin_left'   => 0,
		'margin_right'  => 0,
		'margin_top'    => 0,
		'margin_bottom' => 0,
		'margin_header' => 0,
		'margin_footer' => 0,
	) );

	$mpdf->SetDisplayMode( 'fullpage' );
	$mpdf->WriteHTML( '@page { margin:0; } body { margin:0; padding:0; }', 1 );
	$mpdf->WriteHTML( ls_certificate_html( $learner, $course, $issued, $cert_no, $bg_src, $sig_src ), 2 );

	$filename = sanitize_file_name( $learner . ' - CPD Certificate.pdf' );
	$mpdf->Output( $filename, \LearnDash\Certificate_Builder\Mpdf\Output\Destination::INLINE );
	exit;
}

function ls_cert_ordinal_date( string $date ): string {
	// "1 July 2026" → "1st July 2026"
	if ( ! preg_match( '/^(\d+)(.+)$/', $date, $m ) ) return $date;
	$n = (int) $m[1];
	if ( $n % 100 >= 11 && $n % 100 <= 13 ) {
		$suffix = 'th';
	} else {
		switch ( $n % 10 ) {
			case 1:  $suffix = 'st'; break;
			case 2:  $suffix = 'nd'; break;
			case 3:  $suffix = 'rd'; break;
			default: $suffix = 'th';
		}
	}
	return $n . $suffix . $m[2];
}

function ls_certificate_html(
	string $learner,
	string $course,
	string $issued,
	string $cert_no,
	string $bg_src,
	string $sig_src = ''
): string {

	// Figma canvas: 1754 × 1236 px → A4-L: 297 × 210 mm
	// All coordinates are CENTER points (from Figma rulers).
	// Conversion: x_mm = px × (297/1754) | y_mm = px × (210/1236)
	//
	// name            centre x=148.5mm (page centre)  y=59.7mm  (nudged 5.44mm up from 65.1mm)
	// course          centre x=148.5mm (page centre)  y=91.9mm
	// date_completed  centre x=110.2mm (px651)        y=124.6mm
	// cert_no         centre x=215.3mm (px1271)       y=124.6mm
	// signature svg   centre x=102.9mm                y=158.7mm  (svg 40mm wide, 18mm tall)
	// date_issued     centre x=194.9mm                y=163.8mm
	//
	// For centred text: div spans a generous width, left = centre_x - width/2
	// ── Adjust font sizes below once you preview ──

	$bg = $bg_src
		? '<img src="' . esc_attr( $bg_src ) . '" style="width:297mm; height:210mm; display:block;">'
		: '';

	return '
	<!-- ── Full-page background ── -->
	<div style="position:absolute; left:0; top:0; width:297mm; height:210mm;">'
		. $bg .
	'</div>

	<!-- ── Learner name ── centre x=148.5mm (full page), y=59.7mm -->
	<div style="
		position:absolute;
		left:0;
		top:59.7mm;
		width:297mm;
		text-align:center;
		font-family:freeserif,serif;
		font-style:italic;
		font-size:38pt;
		color:#9439d4;
		line-height:1;
	">' . esc_html( $learner ) . '</div>

	<!-- ── Course title ── centre x=148.5mm (full page), y=91.9mm -->
	<div style="
		position:absolute;
		left:20mm;
		top:90.54mm;
		width:257mm;
		text-align:center;
		font-family:freeserif,serif;
		font-style:italic;
		font-size:18pt;
		color:#9439d4;
		line-height:1.25;
	">' . esc_html( $course ) . '</div>

	<!-- ── Date completed ── centre x=110.2mm (px651), y=124.6mm | div 80mm → left=70.2mm -->
	<div style="
		position:absolute;
		left:70.2mm;
		top:124.6mm;
		width:80mm;
		text-align:center;
		font-family:freesans,sans-serif;
		font-size:12pt;
		color:#2A0D40;
	">' . esc_html( $issued ) . '</div>

	<!-- ── Certificate number ── centre x=215.3mm (px1271), y=124.6mm | div 80mm → left=175.3mm -->
	<div style="
		position:absolute;
		left:175.3mm;
		top:124.6mm;
		width:80mm;
		text-align:center;
		font-family:freesans,sans-serif;
		font-size:12pt;
		color:#2A0D40;
	">' . esc_html( $cert_no ) . '</div>

	<!-- ── Signature SVG ── centre x=102.9mm, y=158.7mm | 20mm wide × 9mm tall → left=92.9mm, top=156.92mm -->
	' . ( $sig_src ? '
	<div style="position:absolute; left:92.9mm; top:156.92mm; width:20mm; height:9mm;">
		<img src="' . esc_attr( $sig_src ) . '" style="width:20mm; height:9mm;">
	</div>' : '' ) . '

	<!-- ── Date issued ── centre x=194.9mm, y=162.4mm | div 100mm → left=144.9mm -->
	<div style="
		position:absolute;
		left:144.9mm;
		top:162.4mm;
		width:100mm;
		text-align:center;
		font-family:freesans,sans-serif;
		font-size:11pt;
		color:#2A0D40;
	">' . esc_html( $issued ) . '</div>
	';
}
