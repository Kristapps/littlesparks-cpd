<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'before_delete_post', 'ls_delete_linked_product' );
function ls_delete_linked_product( $post_id ) {
	if ( 'sfwd-courses' !== get_post_type( $post_id ) ) {
		return;
	}

	$product_id = (int) get_post_meta( $post_id, '_ls_linked_product_id', true );
	if ( $product_id && 'product' === get_post_type( $product_id ) ) {
		wp_delete_post( $product_id, true );
	}
}

add_action( 'updated_post_meta', 'ls_sync_course_to_product', 10, 4 );
add_action( 'added_post_meta',   'ls_sync_course_to_product', 10, 4 );
function ls_sync_course_to_product( $meta_id, $post_id, $meta_key, $meta_value ) {
	if ( '_sfwd-courses' !== $meta_key ) {
		return;
	}

	if ( 'sfwd-courses' !== get_post_type( $post_id ) ) {
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return;
	}

	$product_id = (int) get_post_meta( $post_id, '_ls_linked_product_id', true );

	$short_desc = isset( $meta_value['sfwd-courses_course_short_description'] ) ? $meta_value['sfwd-courses_course_short_description'] : $post->post_excerpt;

	if ( $product_id && 'product' === get_post_type( $product_id ) ) {
		wp_update_post( array(
			'ID'           => $product_id,
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $short_desc,
			'post_name'    => $post->post_name,
		) );
	} else {
		$product_id = wp_insert_post( array(
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $short_desc,
			'post_name'    => $post->post_name,
			'post_status'  => 'publish',
			'post_type'    => 'product',
		) );

		if ( is_wp_error( $product_id ) ) {
			return;
		}

		wp_set_object_terms( $product_id, 'simple', 'product_type' );
		update_post_meta( $post_id, '_ls_linked_product_id', $product_id );
	}

	$thumbnail_id = get_post_thumbnail_id( $post_id );
	if ( $thumbnail_id ) {
		set_post_thumbnail( $product_id, $thumbnail_id );
	}

	$price = isset( $meta_value['sfwd-courses_course_price'] ) ? $meta_value['sfwd-courses_course_price'] : '';
	$price = ( '' !== $price ) ? number_format( (float) $price, 2, '.', '' ) : '0.00';
	update_post_meta( $product_id, '_price', $price );
	update_post_meta( $product_id, '_regular_price', $price );

	update_post_meta( $product_id, '_related_course', array( $post_id ) );
	update_post_meta( $product_id, '_visibility', 'visible' );

	$ld_cats = wp_get_post_terms( $post_id, 'ld_course_category', array( 'fields' => 'names' ) );
	if ( ! is_wp_error( $ld_cats ) && ! empty( $ld_cats ) ) {
		$product_cat_ids = array();
		foreach ( $ld_cats as $cat_name ) {
			$term = get_term_by( 'name', $cat_name, 'product_cat' );
			if ( ! $term ) {
				$result = wp_insert_term( $cat_name, 'product_cat' );
				if ( ! is_wp_error( $result ) ) {
					$product_cat_ids[] = $result['term_id'];
				}
			} else {
				$product_cat_ids[] = $term->term_id;
			}
		}
		if ( ! empty( $product_cat_ids ) ) {
			wp_set_post_terms( $product_id, $product_cat_ids, 'product_cat' );
		}
	}
}
