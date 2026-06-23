<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function ls_enqueue_assets() {
	$version = ( 'production' !== wp_get_environment_type() )
		? wp_get_theme()->get( 'Version' ) . '.' . time()
		: wp_get_theme()->get( 'Version' );

	wp_enqueue_style(
		'hello-elementor',
		get_template_directory_uri() . '/style.css',
		array(),
		null
	);

	wp_enqueue_style(
		'ls-fonts',
		'https://fonts.bunny.net/css?family=dm-sans:400,500,600,700&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'ls-main',
		get_stylesheet_directory_uri() . '/assets/css/main.css',
		array( 'hello-elementor', 'ls-fonts' ),
		$version
	);

	wp_enqueue_script(
		'ls-main',
		get_stylesheet_directory_uri() . '/assets/js/main.js',
		array(),
		$version,
		true
	);
}
add_action( 'wp_enqueue_scripts', 'ls_enqueue_assets' );

add_filter( 'hello_elementor_page_title', '__return_false' );
