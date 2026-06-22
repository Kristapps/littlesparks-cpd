<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'acf/init', 'ls_register_acf_field_groups' );
function ls_register_acf_field_groups() {

	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}

	acf_add_local_field_group( array(
		'key'      => 'group_testimonial_fields',
		'title'    => 'Testimonial Details',
		'fields'   => array(
			array(
				'key'          => 'field_testimonial_quote',
				'label'        => 'Quote',
				'name'         => 'testimonial_quote',
				'type'         => 'textarea',
				'rows'         => 4,
				'required'     => 1,
				'instructions' => 'The testimonial text. No quotation marks needed.',
			),
			array(
				'key'      => 'field_testimonial_name',
				'label'    => 'Name',
				'name'     => 'testimonial_name',
				'type'     => 'text',
				'required' => 1,
			),
			array(
				'key'      => 'field_testimonial_role',
				'label'    => 'Role',
				'name'     => 'testimonial_role',
				'type'     => 'text',
				'required' => 0,
			),
			array(
				'key'      => 'field_testimonial_organisation',
				'label'    => 'Organisation',
				'name'     => 'testimonial_organisation',
				'type'     => 'text',
				'required' => 0,
			),
			array(
				'key'           => 'field_testimonial_photo',
				'label'         => 'Photo',
				'name'          => 'testimonial_photo',
				'type'          => 'image',
				'required'      => 0,
				'return_format' => 'array',
				'preview_size'  => 'thumbnail',
				'library'       => 'all',
			),
		),
		'location' => array(
			array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'testimonials',
				),
			),
		),
		'menu_order'            => 0,
		'position'              => 'normal',
		'style'                 => 'default',
		'label_placement'       => 'top',
		'instruction_placement' => 'label',
	) );

	acf_add_local_field_group( array(
		'key'    => 'group_wc_course_fields',
		'title'  => 'Course Details',
		'fields' => array(
			array(
				'key'         => 'field_course_type',
				'label'       => 'Type',
				'name'        => 'course_type',
				'type'        => 'text',
				'required'    => 0,
				'placeholder' => 'e.g. Masterclass, Workshop, Short Course',
			),
			array(
				'key'     => 'field_course_level',
				'label'   => 'Level',
				'name'    => 'course_level',
				'type'    => 'select',
				'choices' => array(
					'Beginner'     => 'Beginner',
					'Intermediate' => 'Intermediate',
					'Advanced'     => 'Advanced',
				),
				'default_value' => 'Beginner',
				'allow_null'    => 1,
				'ui'            => 1,
			),
			array(
				'key'           => 'field_course_rating',
				'label'         => 'Rating',
				'name'          => 'course_rating',
				'type'          => 'number',
				'required'      => 0,
				'default_value' => 5,
				'min'           => 1,
				'max'           => 5,
				'step'          => 0.1,
				'instructions'  => 'Out of 5. Shown on course cards.',
			),
			array(
				'key'           => 'field_course_icon',
				'label'         => 'Card Icon',
				'name'          => 'course_icon',
				'type'          => 'image',
				'required'      => 0,
				'return_format' => 'array',
				'preview_size'  => 'thumbnail',
				'library'       => 'all',
				'instructions'  => 'Small decorative icon shown on the course card (28×28px, SVG or PNG).',
			),
		),
		'location' => array(
			array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => 'product',
				),
			),
		),
		'menu_order'            => 0,
		'position'              => 'normal',
		'style'                 => 'default',
		'label_placement'       => 'top',
		'instruction_placement' => 'label',
	) );
}
