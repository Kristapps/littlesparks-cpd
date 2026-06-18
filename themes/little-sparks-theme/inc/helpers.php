<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function ls_wc_active() {
	return class_exists( 'WooCommerce' );
}

function ls_ld_active() {
	return function_exists( 'learndash_get_post_type_slug' );
}

function ls_get_enrolled_course_ids( $user_id = null ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}
	if ( ! $user_id || ! ls_ld_active() ) {
		return array();
	}
	return learndash_user_get_enrolled_courses( $user_id );
}
