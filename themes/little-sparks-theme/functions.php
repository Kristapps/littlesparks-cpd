<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function ls_enqueue_assets() {
	wp_enqueue_style(
		'hello-elementor',
		get_template_directory_uri() . '/style.css',
		[],
		null
	);

	wp_enqueue_style(
		'ls-main',
		get_stylesheet_directory_uri() . '/assets/css/main.css',
		[ 'hello-elementor' ],
		'1.0.0'
	);

	wp_enqueue_script(
		'ls-main',
		get_stylesheet_directory_uri() . '/assets/js/main.js',
		[],
		'1.0.0',
		true
	);
}
add_action( 'wp_enqueue_scripts', 'ls_enqueue_assets' );

add_filter( 'hello_elementor_page_title', '__return_false' );
