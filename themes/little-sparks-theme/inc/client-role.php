<?php
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LS_CLIENT_ROLE_VERSION', '1.0' );

add_action( 'init', 'ls_register_client_role' );
function ls_register_client_role() {
	if ( get_option( 'ls_client_role_version' ) === LS_CLIENT_ROLE_VERSION ) {
		return;
	}
	remove_role( 'little_sparks_admin' );
	add_role(
		'little_sparks_admin',
		'Little Sparks Admin',
		array(
			'read'                     => true,
			'upload_files'             => true,
			// Pages
			'edit_pages'               => true,
			'edit_published_pages'     => true,
			'publish_pages'            => true,
			// WooCommerce
			'manage_woocommerce'       => true,
			'view_woocommerce_reports' => true,
			// LearnDash
			'manage_learndash'         => true,
			// Users
			'list_users'               => true,
			'edit_users'               => true,
			'create_users'             => true,
		)
	);
	update_option( 'ls_client_role_version', LS_CLIENT_ROLE_VERSION, false );
}

// Block editing or deleting administrator accounts.
add_filter( 'map_meta_cap', 'ls_restrict_client_user_caps', 10, 4 );
function ls_restrict_client_user_caps( $caps, $cap, $user_id, $args ) {
	if ( ! in_array( $cap, array( 'edit_user', 'delete_user', 'promote_user' ), true ) ) {
		return $caps;
	}
	$acting_user = get_userdata( $user_id );
	if ( ! $acting_user || ! in_array( 'little_sparks_admin', (array) $acting_user->roles, true ) ) {
		return $caps;
	}
	if ( ! empty( $args[0] ) ) {
		$target = get_userdata( $args[0] );
		if ( $target && in_array( 'administrator', (array) $target->roles, true ) ) {
			$caps[] = 'do_not_allow';
		}
	}
	return $caps;
}

// Strip unneeded admin menu items for client role.
add_action( 'admin_menu', 'ls_restrict_client_admin_menu', 999 );
function ls_restrict_client_admin_menu() {
	$user = wp_get_current_user();
	if ( ! in_array( 'little_sparks_admin', (array) $user->roles, true ) ) {
		return;
	}
	$remove = array(
		'edit.php',                            // Posts
		'edit-comments.php',                   // Comments
		'themes.php',                          // Appearance
		'plugins.php',                         // Plugins
		'tools.php',                           // Tools
		'options-general.php',                 // Settings
		'wpcf7',                               // Contact Form 7
		'grassblade',                          // GrassBlade
		'cookie-law-info',                     // CookieYes
		'ithemes-security',                    // Kadence/Solid Security (legacy slug)
		'solid-security',                      // Kadence/Solid Security (current slug)
		'edit.php?post_type=acf-field-group',  // ACF field group manager
		'elementor',                           // Elementor
	);
	foreach ( $remove as $slug ) {
		remove_menu_page( $slug );
	}
}

// Remove Query Monitor from admin bar for client role.
add_action( 'admin_bar_menu', 'ls_remove_qm_for_client', 999 );
function ls_remove_qm_for_client( $wp_admin_bar ) {
	$user = wp_get_current_user();
	if ( in_array( 'little_sparks_admin', (array) $user->roles, true ) ) {
		$wp_admin_bar->remove_node( 'query-monitor' );
	}
}
