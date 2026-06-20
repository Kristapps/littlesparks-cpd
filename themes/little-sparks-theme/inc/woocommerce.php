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
