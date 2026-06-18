<?php
if ( ! defined( 'ABSPATH' ) ) exit;

remove_action( 'wp_head', 'wp_generator' );

add_filter( 'xmlrpc_enabled', '__return_false' );

add_filter( 'rest_endpoints', function( $endpoints ) {
	if ( isset( $endpoints['/wp/v2/users'] ) ) {
		unset( $endpoints['/wp/v2/users'] );
	}
	if ( isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ) ) {
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}
	return $endpoints;
} );
