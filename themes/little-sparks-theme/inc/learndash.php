<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * WC for LearnDash handles the full access pipeline via WooCommerce order
 * status mapping configured in LD Admin > WooCommerce:
 *   Processing / Completed → Grant access
 *   Cancelled / Refunded / Failed → Deny access
 * No custom PHP needed for purchase or revocation.
 */

// ---------------------------------------------------------------------------
// Single course page — hero header injected before LD infobar.
// Outputs: featured image, h1 title, short description, meta pills.
// ---------------------------------------------------------------------------
add_action( 'learndash-course-before', 'ls_course_page_header', 10, 3 );
function ls_course_page_header( $post_id, $course_id, $user_id ) {
	if ( ! is_singular( 'sfwd-courses' ) ) return;

	$product_id = get_post_meta( $course_id, '_ls_linked_product_id', true );

	// Short description from LD course meta (post_excerpt is empty on LD courses).
	$short_desc = '';
	$course_meta = get_post_meta( $course_id, '_sfwd-courses', true );
	if ( is_array( $course_meta ) && ! empty( $course_meta['sfwd-courses_course_short_description'] ) ) {
		$short_desc = $course_meta['sfwd-courses_course_short_description'];
	}

	// ACF fields + duration from linked WC product.
	$level    = $product_id ? get_field( 'course_level', $product_id ) : '';
	$duration = '';
	if ( $product_id ) {
		$secs = get_post_meta( $product_id, '_learndash_course_grid_duration', true );
		if ( $secs ) {
			$duration = ls_format_duration( (int) $secs );
		}
	}

	// First non-generic category from linked WC product.
	$cat_name = '';
	if ( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! in_array( $term->slug, array( 'uncategorized', 'uncategorised' ), true ) ) {
					$cat_name = $term->name;
					break;
				}
			}
		}
	}

	// Featured image.
	$thumb_id = get_post_thumbnail_id( $course_id );
	$img_html = '';
	if ( $thumb_id ) {
		$img_html = '<div class="ls-course-hero-img-wrap">' . wp_get_attachment_image(
			$thumb_id,
			'large',
			false,
			array(
				'class'         => 'ls-course-hero-img',
				'loading'       => 'eager',
				'fetchpriority' => 'high',
				'alt'           => esc_attr( get_the_title( $course_id ) ),
			)
		) . '</div>';
	}

	$has_meta = $cat_name || $level || $duration;
	?>
	<div class="ls-course-hero">
		<?php echo $img_html; // img already escaped via wp_get_attachment_image. ?>
		<div class="ls-course-hero-body">
			<h1 class="ls-course-title"><?php echo esc_html( get_the_title( $course_id ) ); ?></h1>
			<?php if ( $short_desc ) : ?>
				<p class="ls-course-excerpt"><?php echo esc_html( $short_desc ); ?></p>
			<?php endif; ?>
			<?php if ( $has_meta ) : ?>
				<div class="ls-course-meta-bar">
					<?php if ( $cat_name ) : ?>
						<span class="ls-meta-pill ls-meta-cat"><?php echo esc_html( $cat_name ); ?></span>
					<?php endif; ?>
					<?php if ( $level ) : ?>
						<span class="ls-meta-pill ls-meta-level"><?php echo esc_html( $level ); ?></span>
					<?php endif; ?>
					<?php if ( $duration ) : ?>
						<span class="ls-meta-pill ls-meta-duration">
							<svg aria-hidden="true" focusable="false" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
							<?php echo esc_html( $duration ); ?>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Inject WC add-to-cart button for closed courses.
// learndash-woocommerce plugin handles enrollment only — it never outputs a
// buy button. We hook learndash_payment_button_closed to inject one ourselves
// using the linked WC product stored by course-sync.php.
// ---------------------------------------------------------------------------
add_filter( 'learndash_payment_button_closed', 'ls_wc_buy_button', 10, 2 );
function ls_wc_buy_button( $button, $params ) {
	if ( ! empty( $button ) ) return $button;
	if ( ! ls_wc_active() ) return $button;

	$post_id    = get_the_ID();
	$product_id = get_post_meta( $post_id, '_ls_linked_product_id', true );

	if ( ! $product_id ) return $button;

	$product = wc_get_product( $product_id );
	if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) return $button;

	$url = $product->add_to_cart_url();

	return sprintf(
		'<a href="%s" class="btn-join button button-primary button-large ls-enrol-btn" data-product_id="%d" data-quantity="1" aria-label="%s">%s</a>',
		esc_url( $url ),
		absint( $product_id ),
		esc_attr__( 'Enrol on this course', 'little-sparks' ),
		esc_html__( 'Enrol Now', 'little-sparks' )
	);
}

// ---------------------------------------------------------------------------
// Admin notice: published course with price type 'free'.
// Warns the client before a course goes live without a payment gate.
// Fires on the course edit screen and the courses list screen.
// ---------------------------------------------------------------------------
add_action( 'admin_notices', 'ls_warn_free_course' );
function ls_warn_free_course() {
	$screen = get_current_screen();

	if ( ! $screen || 'sfwd-courses' !== $screen->post_type ) return;

	$free_courses = get_posts( array(
		'post_type'      => 'sfwd-courses',
		'post_status'    => 'publish',
		'numberposts'    => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'     => '_sfwd-courses',
				'value'   => 's:18:"course_price_type";s:4:"free"',
				'compare' => 'LIKE',
			),
		),
	) );

	if ( empty( $free_courses ) ) return;

	$names = array_map( function( $id ) {
		return '"' . esc_html( get_the_title( $id ) ) . '"';
	}, $free_courses );

	echo '<div class="notice notice-warning"><p>';
	echo '<strong>Little Sparks:</strong> The following published course(s) have price type set to <strong>Free</strong> — anyone can enrol without purchasing: ';
	echo implode( ', ', $names ) . '. ';
	echo 'Set price type to <strong>Closed</strong> and link a WooCommerce product unless this is intentional.';
	echo '</p></div>';
}

// ---------------------------------------------------------------------------
// Course completion.
// Fires when a learner completes all required steps in a course.
// Phase 5: redirect to certificate download or custom completion page.
// Phase 7: trigger bespoke completion email.
// ---------------------------------------------------------------------------
add_action( 'learndash_course_completed', 'ls_on_course_completed', 10, 1 );
function ls_on_course_completed( $data ) {
	/*
	 * $data['user']     — WP_User object
	 * $data['course']   — WP_Post (sfwd-courses)
	 * $data['progress'] — progress array
	 *
	 * Phase 5 TODO: add certificate redirect once artwork and cert template done.
	 * Example:
	 *   $cert_url = learndash_get_course_certificate_link( $data['course']->ID, $data['user']->ID );
	 *   if ( $cert_url ) wp_safe_redirect( $cert_url );
	 */
}

// ---------------------------------------------------------------------------
// Lesson / Topic completion.
// Stub — extend in Phase 5 if progress tracking or analytics needed.
// ---------------------------------------------------------------------------
add_action( 'learndash_lesson_completed', 'ls_on_lesson_completed', 10, 1 );
function ls_on_lesson_completed( $data ) {
	// $data['user'], $data['lesson'], $data['course'], $data['progress']
}

add_action( 'learndash_topic_completed', 'ls_on_topic_completed', 10, 1 );
function ls_on_topic_completed( $data ) {
	// $data['user'], $data['topic'], $data['lesson'], $data['course'], $data['progress']
}

// ---------------------------------------------------------------------------
// Dequeue LD assets that conflict with child theme.
// Phase 5: identify actual conflicts when styling course pages.
// Uncomment as needed after visual QA on course / lesson / topic pages.
// ---------------------------------------------------------------------------
add_action( 'wp_enqueue_scripts', 'ls_dequeue_ld_assets', 99 );
function ls_dequeue_ld_assets() {
	if ( ! function_exists( 'learndash_is_course_page' ) ) return;

	// wp_dequeue_style( 'learndash-front' );   // main LD front-end CSS
	// wp_dequeue_style( 'sfwd-module-css' );   // module styles
}
