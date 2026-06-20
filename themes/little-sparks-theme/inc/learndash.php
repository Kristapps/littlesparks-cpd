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
