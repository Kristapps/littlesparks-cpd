<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'woocommerce_enqueue_styles', '__return_empty_array' );

add_action( 'init', 'ls_remove_wc_breadcrumb', 99 );
function ls_remove_wc_breadcrumb() {
	remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
}

add_action( 'init', 'ls_remove_wc_sidebar', 99 );
function ls_remove_wc_sidebar() {
	remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );
}

// ---------------------------------------------------------------------------
// Redirect to checkout immediately after a course product is added to cart.
// Course products are identified by _related_course meta.
// Skips the cart page — better conversion for single-course purchases.
// Also prevents the ?add-to-cart= URL persisting in the browser on refresh.
// ---------------------------------------------------------------------------
add_filter( 'woocommerce_add_to_cart_redirect', 'ls_course_add_to_cart_redirect', 10, 2 );
function ls_course_add_to_cart_redirect( $url, $product ) {
	if ( ! isset( $_GET['add-to-cart'] ) ) return $url; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$product_id    = absint( $_GET['add-to-cart'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$related_course = get_post_meta( $product_id, '_related_course', true );

	if ( ! empty( $related_course ) ) {
		return wc_get_checkout_url();
	}

	return $url;
}

// ---------------------------------------------------------------------------
// Redirect WC product pages → linked LD course page.
// Redirect WC shop page → /courses/.
// Users purchase via the LD course page only — WC products are backend only.
// ---------------------------------------------------------------------------
add_action( 'template_redirect', 'ls_redirect_wc_product_pages', 99 );
function ls_redirect_wc_product_pages() {
	if ( is_singular( 'product' ) ) {
		$product_id  = get_the_ID();
		$course_ids  = get_post_meta( $product_id, '_related_course', true );

		if ( ! empty( $course_ids ) && is_array( $course_ids ) ) {
			$course_id = absint( reset( $course_ids ) );
			$course    = get_post( $course_id );

			if ( $course && 'publish' === $course->post_status ) {
				wp_safe_redirect( get_permalink( $course_id ), 301 );
				exit;
			}
		}

		// No linked LD course found — redirect to courses archive.
		wp_safe_redirect( home_url( '/courses/' ), 302 );
		exit;
	}

	if ( is_shop() ) {
		wp_safe_redirect( home_url( '/courses/' ), 301 );
		exit;
	}
}
