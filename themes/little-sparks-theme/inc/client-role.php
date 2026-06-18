<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function ls_register_client_role() {
	if ( get_role( 'little_sparks_admin' ) ) {
		return;
	}
	add_role( 'little_sparks_admin', 'Little Sparks Admin', array(
		'read'                     => true,
		'edit_posts'               => true,
		'edit_pages'               => true,
		'edit_published_posts'     => true,
		'publish_posts'            => true,
		'upload_files'             => true,
		'manage_woocommerce'       => true,
		'view_woocommerce_reports' => true,
	) );
}
add_action( 'init', 'ls_register_client_role' );
