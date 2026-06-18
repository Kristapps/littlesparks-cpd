<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function ls_register_cpts() {

	register_post_type( 'testimonials', array(
		'labels'       => array(
			'name'          => __( 'Testimonials', 'little-sparks-theme' ),
			'singular_name' => __( 'Testimonial', 'little-sparks-theme' ),
			'add_new_item'  => __( 'Add New Testimonial', 'little-sparks-theme' ),
			'edit_item'     => __( 'Edit Testimonial', 'little-sparks-theme' ),
		),
		'public'       => false,
		'show_ui'      => true,
		'show_in_rest' => true,
		'supports'     => array( 'title' ),
		'menu_icon'    => 'dashicons-format-quote',
	) );
}
add_action( 'init', 'ls_register_cpts' );
