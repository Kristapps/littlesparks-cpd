<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Register Little Sparks admin colour scheme.
add_action( 'admin_init', 'ls_register_admin_color_scheme' );
function ls_register_admin_color_scheme() {
	wp_admin_css_color(
		'little-sparks',
		'Little Sparks',
		get_stylesheet_directory_uri() . '/assets/css/admin-colors.css',
		array( '#2A0D40', '#9439d4', '#f5ac39', '#FBF7FF' )
	);
}

// Force Little Sparks colour scheme for client role.
add_filter( 'get_user_option_admin_color', 'ls_force_client_color_scheme', 10, 3 );
function ls_force_client_color_scheme( $color, $option, $user ) {
	if ( in_array( 'little_sparks_admin', (array) $user->roles, true ) ) {
		return 'little-sparks';
	}
	return $color;
}

// Login page styles + inline logo from WP custom logo setting.
add_action( 'login_enqueue_scripts', 'ls_login_styles' );
function ls_login_styles() {
	wp_enqueue_style(
		'ls-login',
		get_stylesheet_directory_uri() . '/assets/css/login.css',
		array(),
		'1.0'
	);
	$logo_id  = get_theme_mod( 'custom_logo' );
	$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
	if ( ! $logo_url ) {
		$logo_url = get_site_icon_url( 120 );
	}
	if ( $logo_url ) {
		wp_add_inline_style(
			'ls-login',
			'body.login .wp-login-logo a { background-image: url(' . esc_url( $logo_url ) . ') !important; }'
		);
	}
}

add_filter( 'login_headerurl', function() { return home_url(); } );
add_filter( 'login_headertext', function() { return get_bloginfo( 'name' ); } );

// Custom dashboard widget for client role.
add_action( 'wp_dashboard_setup', 'ls_setup_dashboard' );
function ls_setup_dashboard() {
	$user = wp_get_current_user();
	if ( ! in_array( 'little_sparks_admin', (array) $user->roles, true ) ) {
		return;
	}
	remove_meta_box( 'dashboard_quick_press',  'dashboard', 'side' );
	remove_meta_box( 'dashboard_primary',      'dashboard', 'side' );
	remove_meta_box( 'dashboard_activity',     'dashboard', 'normal' );
	remove_meta_box( 'dashboard_right_now',    'dashboard', 'normal' );
	wp_add_dashboard_widget(
		'ls_welcome',
		'Little Sparks eLearning — Quick Links',
		'ls_dashboard_widget_content'
	);
}

function ls_dashboard_widget_content() {
	$links = array(
		'Orders'     => admin_url( 'edit.php?post_type=shop_order' ),
		'Students'   => admin_url( 'users.php' ),
		'Courses'    => admin_url( 'edit.php?post_type=sfwd-courses' ),
		'Enquiries'  => admin_url( 'admin.php?page=flamingo' ),
		'Reports'    => admin_url( 'admin.php?page=wc-reports' ),
		'Groups'     => admin_url( 'edit.php?post_type=groups' ),
	);
	echo '<p style="margin:0 0 14px;color:#2A0D40;">Welcome to your admin area. Use the links below to manage your platform.</p>';
	echo '<ul style="margin:0;list-style:none;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:8px;">';
	foreach ( $links as $label => $url ) {
		printf(
			'<li><a href="%s" style="display:block;padding:10px 14px;background:#FBF7FF;border:1px solid #d4b8f0;border-radius:8px;color:#9439d4;font-weight:600;text-decoration:none;text-align:center;">%s</a></li>',
			esc_url( $url ),
			esc_html( $label )
		);
	}
	echo '</ul>';
}
