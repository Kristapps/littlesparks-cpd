<?php
/**
 * Plugin Name: LS Integration Health
 * Description: Admin-only integration health checker. Runs real behavioural checks, not plugin-active flags.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// Auto-protect LearnDash logs directory on every request.
// Creates .htaccess denying direct HTTP access if not already present.
// Runs on init so it covers both frontend and admin contexts.
// ---------------------------------------------------------------------------
add_action( 'init', 'ls_health_protect_ld_logs' );
function ls_health_protect_ld_logs() {
	$upload_dir = wp_upload_dir();
	$logs_dir   = trailingslashit( $upload_dir['basedir'] ) . 'learndash/logs';
	$htaccess   = $logs_dir . '/.htaccess';

	if ( file_exists( $htaccess ) ) return;

	if ( ! file_exists( $logs_dir ) ) {
		wp_mkdir_p( $logs_dir );
	}

	$rules = "# Block direct HTTP access to LearnDash logs\n"
		. "<FilesMatch \".*\">\n"
		. "  Order Allow,Deny\n"
		. "  Deny from all\n"
		. "</FilesMatch>\n"
		. "Options -Indexes\n";

	file_put_contents( $htaccess, $rules );
}

add_action( 'admin_menu', 'ls_health_add_menu' );
function ls_health_add_menu() {
	add_management_page(
		'LS Integration Health',
		'LS Health',
		'manage_options',
		'ls-health',
		'ls_health_render_page'
	);
}

// ---------------------------------------------------------------------------
// AJAX: send test email
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_ls_health_test_email', 'ls_health_ajax_test_email' );
function ls_health_ajax_test_email() {
	check_ajax_referer( 'ls_health_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

	$to      = get_option( 'admin_email' );
	$subject = '[LS Health] Test email ' . gmdate( 'Y-m-d H:i:s' );
	$body    = 'This is a real wp_mail() call sent from the LS Health checker. If you received it, transactional email is working.';
	$result  = wp_mail( $to, $subject, $body );

	wp_send_json( array(
		'success' => $result,
		'message' => $result
			? 'wp_mail() returned true. Email dispatched to ' . esc_html( $to ) . '. Check inbox to confirm delivery.'
			: 'wp_mail() returned false. Check FluentSMTP > Email Logs for the failure reason.',
	) );
}

// ---------------------------------------------------------------------------
// AJAX: enrollment simulation
// Creates a real WC order for a linked product, completes it, verifies LD
// access was granted, then fully cleans up. Tests the actual hook pipeline.
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_ls_health_test_enrollment', 'ls_health_ajax_test_enrollment' );
function ls_health_ajax_test_enrollment() {
	check_ajax_referer( 'ls_health_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

	if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'ld_update_course_access' ) ) {
		wp_send_json( array(
			'success' => false,
			'steps'   => array( 'WooCommerce or LearnDash not active — cannot run simulation.' ),
		) );
	}

	$steps = array();

	// 1. Find a product with a linked LD course.
	$linked_products = get_posts( array(
		'post_type'      => 'product',
		'numberposts'    => 1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'     => '_related_course',
				'compare' => 'EXISTS',
			),
		),
	) );

	if ( empty( $linked_products ) ) {
		wp_send_json( array(
			'success' => false,
			'steps'   => array( 'FAIL: No WC products have _related_course meta. Link a product to a LD course in the product edit screen first.' ),
		) );
	}

	$product_id = (int) $linked_products[0];
	$course_ids = get_post_meta( $product_id, '_related_course', true );

	if ( empty( $course_ids ) || ! is_array( $course_ids ) ) {
		wp_send_json( array(
			'success' => false,
			'steps'   => array( 'FAIL: _related_course meta exists on product ' . $product_id . ' but is empty or not an array.' ),
		) );
	}

	$course_id = (int) $course_ids[0];
	$user_id   = get_current_user_id();
	$steps[]   = 'Using product ID ' . $product_id . ' → course ID ' . $course_id . ' → user ID ' . $user_id;

	// 2. Strip any pre-existing access so the test is clean.
	ld_update_course_access( $user_id, $course_id, true );
	$before  = ld_course_check_user_access( $course_id, $user_id );
	$steps[] = 'Access before test: ' . ( $before ? 'WARNING — user already had access (revoke attempted, result may skew)' : 'None (clean baseline)' );

	// 3. Create a real WC order.
	$order = wc_create_order( array( 'customer_id' => $user_id ) );
	if ( is_wp_error( $order ) ) {
		wp_send_json( array(
			'success' => false,
			'steps'   => array_merge( $steps, array( 'FAIL: wc_create_order() error — ' . $order->get_error_message() ) ),
		) );
	}

	$product = wc_get_product( $product_id );
	$order->add_product( $product, 1 );
	$order->set_billing_email( get_option( 'admin_email' ) );
	$order->calculate_totals();
	$order->save();
	$order_id = $order->get_id();
	$steps[]  = 'Test order created (ID: ' . $order_id . ')';

	// 4. Complete the order — fires woocommerce_order_status_completed which
	//    the LD WC Integration plugin listens to for enrollment.
	$order->update_status( 'completed', 'LS Health test', true );
	$steps[] = 'Order status → completed (hook woocommerce_order_status_completed fired)';

	// 5. Verify enrollment. ld_course_check_user_access() queries the DB
	//    directly — not cached — so this is a real proof of enrollment.
	$enrolled = ld_course_check_user_access( $course_id, $user_id );
	$steps[]  = $enrolled
		? 'PASS: ld_course_check_user_access() = true. Hook pipeline working correctly.'
		: 'FAIL: ld_course_check_user_access() = false. Order completed but enrollment not granted. Check WC for LD plugin settings.';

	// 6. Clean up — delete the order and revoke access.
	$order->delete( true );
	ld_update_course_access( $user_id, $course_id, true );

	$verify_clean = ld_course_check_user_access( $course_id, $user_id );
	$steps[]      = $verify_clean
		? 'WARN: cleanup incomplete — access still shows after revoke. Check LD enrollment records.'
		: 'Cleanup complete — test order deleted, access revoked, no side-effects.';

	wp_send_json( array(
		'success' => $enrolled && ! $verify_clean,
		'steps'   => $steps,
	) );
}

// ---------------------------------------------------------------------------
// Run all passive checks
// ---------------------------------------------------------------------------
function ls_health_run_checks() {
	global $wp_filter;
	$checks = array();

	// === WooCommerce ========================================================
	$wc = array();

	// Guest checkout: read actual DB option, not plugin state.
	$guest = get_option( 'woocommerce_enable_guest_checkout', 'yes' );
	$wc[]  = array(
		'label'  => 'Guest checkout disabled',
		'status' => 'no' === $guest ? 'pass' : 'fail',
		'detail' => 'no' === $guest
			? 'woocommerce_enable_guest_checkout = "no" — correct'
			: 'Option is "' . esc_html( $guest ) . '". Must be "no" — guest orders cannot trigger LD enrollment.',
	);

	// Account creation on checkout.
	$signup = get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' );
	$wc[]   = array(
		'label'  => 'Account creation on checkout',
		'status' => 'yes' === $signup ? 'pass' : 'fail',
		'detail' => 'yes' === $signup
			? 'Customers can create an account at checkout'
			: 'Option is "' . esc_html( $signup ) . '". Set to "yes" in WC > Settings > Accounts.',
	);

	// WC default CSS: actually run the filter and inspect the result.
	// If our hook is correctly registered it returns an empty array.
	$styles_result = apply_filters( 'woocommerce_enqueue_styles', array( 'woocommerce-general' => true ) );
	$wc[]          = array(
		'label'  => 'WC default CSS stripped',
		'status' => empty( $styles_result ) ? 'pass' : 'fail',
		'detail' => empty( $styles_result )
			? 'Filter returns empty array — no WC stylesheets enqueued'
			: 'Filter returned ' . count( $styles_result ) . ' stylesheet(s) still queued: ' . esc_html( implode( ', ', array_keys( $styles_result ) ) ),
	);

	// Breadcrumbs: check the action is NOT registered.
	$crumb_priority = has_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb' );
	$wc[]           = array(
		'label'  => 'WC breadcrumbs removed',
		'status' => false === $crumb_priority ? 'pass' : 'fail',
		'detail' => false === $crumb_priority
			? 'woocommerce_breadcrumb not hooked on woocommerce_before_main_content'
			: 'Still hooked at priority ' . $crumb_priority . '. ls_remove_wc_breadcrumb() may not have run.',
	);

	// Currency.
	$currency = get_option( 'woocommerce_currency', '' );
	$wc[]     = array(
		'label'  => 'Currency = GBP',
		'status' => 'GBP' === $currency ? 'pass' : 'warn',
		'detail' => 'Current: ' . esc_html( $currency ),
	);

	$checks['WooCommerce'] = $wc;

	// === LearnDash ==========================================================
	$ld = array();

	// Plugin loaded — check class existence, not is_plugin_active().
	$wc_ld_class = class_exists( 'LearnDash_WooCommerce' );
	$ld[]        = array(
		'label'  => 'WC for LearnDash plugin loaded',
		'status' => $wc_ld_class ? 'pass' : 'fail',
		'detail' => $wc_ld_class
			? 'LearnDash_WooCommerce class exists in memory'
			: 'Class LearnDash_WooCommerce not found — install and activate the plugin on this environment.',
	);

	// Enrollment hook: walk $wp_filter directly. Handles all three patterns
	// the LD WC Integration plugin may use: object instance, static method
	// array, or standalone function name containing 'learndash'.
	$ld_hook_wired  = false;
	$ld_hook_detail = '';
	if ( $wc_ld_class && isset( $wp_filter['woocommerce_order_status_completed'] ) ) {
		foreach ( $wp_filter['woocommerce_order_status_completed']->callbacks as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$fn = $cb['function'];
				// Pattern 1: object instance — array( $obj, 'method' )
				if ( is_array( $fn ) && isset( $fn[0] ) && is_object( $fn[0] ) && $fn[0] instanceof LearnDash_WooCommerce ) {
					$ld_hook_wired  = true;
					$ld_hook_detail = 'LearnDash_WooCommerce instance::' . esc_html( $fn[1] );
					break 2;
				}
				// Pattern 2: static method — array( 'LearnDash_WooCommerce', 'method' )
				if ( is_array( $fn ) && isset( $fn[0] ) && is_string( $fn[0] ) && false !== stripos( $fn[0], 'LearnDash' ) ) {
					$ld_hook_wired  = true;
					$ld_hook_detail = esc_html( $fn[0] ) . '::' . esc_html( $fn[1] ) . ' (static)';
					break 2;
				}
				// Pattern 3: standalone function — 'learndash_woocommerce_*'
				if ( is_string( $fn ) && false !== stripos( $fn, 'learndash' ) ) {
					$ld_hook_wired  = true;
					$ld_hook_detail = 'function ' . esc_html( $fn );
					break 2;
				}
			}
		}
	}
	$ld[] = array(
		'label'  => 'Enrollment hook wired on order completion',
		'status' => $ld_hook_wired ? 'pass' : ( $wc_ld_class ? 'warn' : 'fail' ),
		'detail' => $ld_hook_wired
			? 'Callback found: ' . $ld_hook_detail
			: ( $wc_ld_class
				? 'No LearnDash callback found on woocommerce_order_status_completed. Plugin may use a different hook — run simulation test to verify actual behaviour.'
				: 'Plugin not loaded — cannot check hook.' ),
	);

	// Linked products: query meta directly. Count products where
	// _related_course is a non-empty serialised array.
	$linked_ids   = get_posts( array(
		'post_type'      => 'product',
		'numberposts'    => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'     => '_related_course',
				'compare' => 'EXISTS',
			),
		),
	) );
	$linked_count = 0;
	foreach ( $linked_ids as $pid ) {
		$meta = get_post_meta( $pid, '_related_course', true );
		if ( ! empty( $meta ) && is_array( $meta ) ) {
			$linked_count++;
		}
	}
	$ld[] = array(
		'label'  => 'Products linked to courses',
		'status' => $linked_count > 0 ? 'pass' : 'warn',
		'detail' => $linked_count > 0
			? $linked_count . ' product(s) have valid _related_course meta'
			: 'No products linked. Edit a WC product → LearnDash tab to connect a course.',
	);

	// Published courses exist.
	$course_count = (int) wp_count_posts( 'sfwd-courses' )->publish;
	$ld[]         = array(
		'label'  => 'Published courses',
		'status' => $course_count > 0 ? 'pass' : 'warn',
		'detail' => $course_count . ' published sfwd-courses post(s) found',
	);

	$checks['LearnDash'] = $ld;

	// === Email ==============================================================
	$email = array();

	// FluentSMTP active: check constant not just is_plugin_active().
	$fluent_active = defined( 'FLUENTMAIL' );
	$email[]       = array(
		'label'  => 'FluentSMTP active',
		'status' => $fluent_active ? 'pass' : 'fail',
		'detail' => $fluent_active
			? 'FLUENTMAIL constant defined'
			: 'FluentSMTP not active — emails will fall back to PHP mail() with no delivery guarantee.',
	);

	// FluentSMTP has at least one live connection in its options.
	$fluent_settings = get_option( 'fluentmail-settings', array() );
	$has_connection  = ! empty( $fluent_settings['connections'] ) && count( $fluent_settings['connections'] ) > 0;
	$email[]         = array(
		'label'  => 'FluentSMTP connection configured',
		'status' => $has_connection ? 'pass' : 'fail',
		'detail' => $has_connection
			? count( $fluent_settings['connections'] ) . ' connection(s) in fluentmail-settings'
			: 'No connections stored in fluentmail-settings option. Configure in FluentSMTP > Settings.',
	);

	// WC from-address uses the correct domain, not a default placeholder.
	$from_email = get_option( 'woocommerce_email_from_address', '' );
	$from_ok    = ! empty( $from_email ) && false !== strpos( $from_email, 'littlesparkselearning.com' );
	$email[]    = array(
		'label'  => 'WC from-address on correct domain',
		'status' => $from_ok ? 'pass' : 'warn',
		'detail' => 'woocommerce_email_from_address = "' . esc_html( $from_email ) . '"',
	);

	$checks['Email'] = $email;

	// === Security ===========================================================
	$sec = array();

	// WP_DEBUG — warn rather than fail (expected on local/staging).
	$debug_on = defined( 'WP_DEBUG' ) && WP_DEBUG;
	$sec[]    = array(
		'label'  => 'WP_DEBUG',
		'status' => $debug_on ? 'warn' : 'pass',
		'detail' => $debug_on
			? 'ON — acceptable on local/staging. Must be false in production wp-config.php.'
			: 'Off',
	);

	// DISALLOW_FILE_EDIT — prevents theme/plugin editor in WP Admin.
	$file_edit_off = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
	$sec[]         = array(
		'label'  => 'DISALLOW_FILE_EDIT',
		'status' => $file_edit_off ? 'pass' : 'fail',
		'detail' => $file_edit_off
			? 'File editing disabled in wp-config.php'
			: 'Not set — add define( "DISALLOW_FILE_EDIT", true ) to wp-config.php.',
	);

	// LiteSpeed Cache must stay off until Phase 8.
	$lsc_active = defined( 'LSWCP_PLUGIN_URL' ) || class_exists( 'LiteSpeed_Cache' );
	$sec[]      = array(
		'label'  => 'LiteSpeed Cache inactive (pre-Phase 8)',
		'status' => ! $lsc_active ? 'pass' : 'warn',
		'detail' => ! $lsc_active
			? 'Not active — correct for current phase'
			: 'Active — do not enable until Phase 8 performance work.',
	);

	// Stripe: verify the gateway class is loaded, not just that the plugin file exists.
	$stripe_loaded = class_exists( 'WC_Stripe_Gateway' ) || class_exists( 'PaymentPlugins\Stripe\WC\Payment\WC_Gateway_Stripe' );
	$sec[]         = array(
		'label'  => 'Stripe gateway loaded',
		'status' => $stripe_loaded ? 'pass' : 'warn',
		'detail' => $stripe_loaded
			? 'Stripe gateway class found in memory'
			: 'No Stripe gateway class found. Activate Payment Plugins for Stripe or check for conflicts.',
	);

	// LearnDash logs directory protection.
	// Two-part check: (1) .htaccess file exists on disk, (2) HTTP request to
	// the logs URL actually returns 403 — proves the server enforces the rule.
	$upload_dir    = wp_upload_dir();
	$logs_dir      = trailingslashit( $upload_dir['basedir'] ) . 'learndash/logs';
	$htaccess_path = $logs_dir . '/.htaccess';
	$htaccess_ok   = file_exists( $htaccess_path );

	$sec[] = array(
		'label'  => 'LD logs .htaccess exists',
		'status' => $htaccess_ok ? 'pass' : 'fail',
		'detail' => $htaccess_ok
			? $htaccess_path
			: '.htaccess missing — logs directory is unprotected on disk. Should auto-create on next page load.',
	);

	// HTTP probe: request the logs directory URL with a short timeout.
	// A 403 or 404 means the server is blocking access. A 200 or 500 is a problem.
	$logs_url    = trailingslashit( $upload_dir['baseurl'] ) . 'learndash/logs/';
	$probe       = wp_remote_get( $logs_url, array( 'timeout' => 5, 'redirection' => 0 ) );
	$probe_code  = is_wp_error( $probe ) ? 0 : wp_remote_retrieve_response_code( $probe );
	$probe_ok    = in_array( $probe_code, array( 403, 404 ), true );

	$sec[] = array(
		'label'  => 'LD logs directory blocked via HTTP',
		'status' => $probe_ok ? 'pass' : ( 0 === $probe_code ? 'warn' : 'fail' ),
		'detail' => $probe_ok
			? 'HTTP ' . $probe_code . ' returned for ' . esc_url( $logs_url ) . ' — directory not publicly accessible'
			: ( 0 === $probe_code
				? 'HTTP probe failed (' . ( is_wp_error( $probe ) ? $probe->get_error_message() : 'no response' ) . ') — check manually'
				: 'HTTP ' . $probe_code . ' returned — directory may be publicly accessible. Check server config.' ),
	);

	$checks['Security'] = $sec;

	// === LearnDash Settings =================================================
	$lds = array();

	$courses_cpt = get_option( 'learndash_settings_courses_cpt', array() );
	$lessons_cpt = get_option( 'learndash_settings_lessons_cpt', array() );
	$topics_cpt  = get_option( 'learndash_settings_topics_cpt', array() );
	$quizzes_cpt = get_option( 'learndash_settings_quizzes_cpt', array() );

	// Courses: archive on, RSS off, featured image supported, comments off.
	$course_archive = ! empty( $courses_cpt['has_archive'] ) && 'yes' === $courses_cpt['has_archive'];
	$lds[]          = array(
		'label'  => 'Courses: archive page enabled',
		'status' => $course_archive ? 'pass' : 'fail',
		'detail' => $course_archive ? '/courses archive active' : 'has_archive not set to "yes" in learndash_settings_courses_cpt',
	);

	$course_feed = ! empty( $courses_cpt['has_feed'] );
	$lds[]       = array(
		'label'  => 'Courses: RSS feed disabled',
		'status' => ! $course_feed ? 'pass' : 'warn',
		'detail' => ! $course_feed ? 'has_feed empty — correct' : 'RSS feed is enabled for courses',
	);

	$course_thumbnail = isset( $courses_cpt['supports'] ) && in_array( 'thumbnail', $courses_cpt['supports'], true );
	$lds[]            = array(
		'label'  => 'Courses: featured image supported',
		'status' => $course_thumbnail ? 'pass' : 'fail',
		'detail' => $course_thumbnail ? 'thumbnail in supports array' : 'thumbnail missing from supports — add Featured Image in Course CPT settings',
	);

	$course_comments = isset( $courses_cpt['supports'] ) && in_array( 'comments', $courses_cpt['supports'], true );
	$lds[]           = array(
		'label'  => 'Courses: comments disabled',
		'status' => ! $course_comments ? 'pass' : 'warn',
		'detail' => ! $course_comments ? 'comments not in supports — correct' : 'Comments enabled on courses — disable in Course CPT settings',
	);

	// Lessons: login-only and enrolled-only enforced, no archive.
	$lesson_login    = ! empty( $lessons_cpt['search_login_only'] ) && 'yes' === $lessons_cpt['search_login_only'];
	$lesson_enrolled = ! empty( $lessons_cpt['search_enrolled_only'] ) && 'yes' === $lessons_cpt['search_enrolled_only'];
	$lesson_archive  = ! empty( $lessons_cpt['has_archive'] );

	$lds[] = array(
		'label'  => 'Lessons: logged-in users only',
		'status' => $lesson_login ? 'pass' : 'fail',
		'detail' => $lesson_login ? 'search_login_only = yes' : 'Not set — non-logged-in users can access lesson URLs',
	);
	$lds[] = array(
		'label'  => 'Lessons: enrolled users only',
		'status' => $lesson_enrolled ? 'pass' : 'fail',
		'detail' => $lesson_enrolled ? 'search_enrolled_only = yes' : 'Not set — logged-in but non-paying users can access lesson content',
	);
	$lds[] = array(
		'label'  => 'Lessons: no public archive',
		'status' => ! $lesson_archive ? 'pass' : 'warn',
		'detail' => ! $lesson_archive ? 'has_archive empty — correct' : 'Lesson archive is public — disable in Lesson CPT settings',
	);

	// Topics: same access control as lessons.
	$topic_login    = ! empty( $topics_cpt['search_login_only'] ) && 'yes' === $topics_cpt['search_login_only'];
	$topic_enrolled = ! empty( $topics_cpt['search_enrolled_only'] ) && 'yes' === $topics_cpt['search_enrolled_only'];
	$topic_archive  = ! empty( $topics_cpt['has_archive'] );

	$lds[] = array(
		'label'  => 'Topics: logged-in users only',
		'status' => $topic_login ? 'pass' : 'fail',
		'detail' => $topic_login ? 'search_login_only = yes' : 'Not set — non-logged-in users can access topic URLs',
	);
	$lds[] = array(
		'label'  => 'Topics: enrolled users only',
		'status' => $topic_enrolled ? 'pass' : 'fail',
		'detail' => $topic_enrolled ? 'search_enrolled_only = yes' : 'Not set — logged-in but non-paying users can access topic content',
	);
	$lds[] = array(
		'label'  => 'Topics: no public archive',
		'status' => ! $topic_archive ? 'pass' : 'warn',
		'detail' => ! $topic_archive ? 'has_archive empty — correct' : 'Topic archive is public — disable in Topic CPT settings',
	);

	// Quizzes: search off, no archive.
	$quiz_search  = ! empty( $quizzes_cpt['include_in_search'] ) && 'yes' === $quizzes_cpt['include_in_search'];
	$quiz_archive = ! empty( $quizzes_cpt['has_archive'] );

	$lds[] = array(
		'label'  => 'Quizzes: excluded from search',
		'status' => ! $quiz_search ? 'pass' : 'warn',
		'detail' => ! $quiz_search ? 'include_in_search empty — quizzes not publicly searchable' : 'Quizzes appear in search results — disable in Quiz CPT settings',
	);
	$lds[] = array(
		'label'  => 'Quizzes: no public archive',
		'status' => ! $quiz_archive ? 'pass' : 'warn',
		'detail' => ! $quiz_archive ? 'has_archive empty — correct' : 'Quiz archive is public',
	);

	$checks['LearnDash Settings'] = $lds;

	return $checks;
}

// ---------------------------------------------------------------------------
// Render page
// ---------------------------------------------------------------------------
function ls_health_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$nonce  = wp_create_nonce( 'ls_health_nonce' );
	$checks = ls_health_run_checks();

	echo '<div class="wrap">';
	echo '<h1>LS Integration Health</h1>';
	echo '<p style="color:#666;">Passive checks run on every page load. Live tests fire real actions and clean up after themselves.</p>';

	foreach ( $checks as $group => $group_checks ) {
		echo '<h2>' . esc_html( $group ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px;"><thead>';
		echo '<tr><th style="width:30%;">Check</th><th style="width:8%;">Result</th><th>Detail</th></tr>';
		echo '</thead><tbody>';
		foreach ( $group_checks as $check ) {
			$colour = 'pass' === $check['status'] ? '#0a7a0a' : ( 'warn' === $check['status'] ? '#996600' : '#cc0000' );
			$label  = strtoupper( $check['status'] );
			echo '<tr>';
			echo '<td><strong>' . esc_html( $check['label'] ) . '</strong></td>';
			echo '<td style="color:' . esc_attr( $colour ) . ';font-weight:bold;">' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( $check['detail'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table><br>';
	}

	echo '<h2>Live Tests</h2>';
	echo '<p>';
	echo '<button id="ls-btn-email" class="button button-secondary">Send Test Email</button>';
	echo '&nbsp;&nbsp;';
	echo '<button id="ls-btn-enroll" class="button button-secondary">Run Enrollment Simulation</button>';
	echo '</p>';
	echo '<pre id="ls-health-output" style="display:none;background:#f0f0f0;padding:12px;max-width:900px;white-space:pre-wrap;"></pre>';

	// Inline JS acceptable here: admin-only dev tool, no front-end impact.
	?>
	<script>
	(function() {
		var nonce  = <?php echo wp_json_encode( $nonce ); ?>;
		var output = document.getElementById('ls-health-output');

		function runTest(action, btn) {
			btn.disabled = true;
			var orig = btn.textContent;
			btn.textContent = 'Running…';
			output.style.display = 'block';
			output.style.color   = '#333';
			output.textContent   = 'Running…';

			var xhr = new XMLHttpRequest();
			xhr.open('POST', ajaxurl);
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			xhr.onload = function() {
				var data;
				try { data = JSON.parse(xhr.responseText); } catch(e) {
					output.textContent = 'Unexpected response: ' + xhr.responseText;
					output.style.color = 'red';
					btn.disabled = false;
					btn.textContent = orig;
					return;
				}
				if (data.steps) {
					output.textContent = data.steps.join('\n');
				} else {
					output.textContent = data.message || JSON.stringify(data);
				}
				output.style.color = data.success ? '#0a7a0a' : '#cc0000';
				btn.disabled = false;
				btn.textContent = orig;
			};
			xhr.onerror = function() {
				output.textContent = 'Request failed.';
				output.style.color = 'red';
				btn.disabled = false;
				btn.textContent = orig;
			};
			xhr.send('action=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(nonce));
		}

		document.getElementById('ls-btn-email').addEventListener('click', function() {
			runTest('ls_health_test_email', this);
		});
		document.getElementById('ls-btn-enroll').addEventListener('click', function() {
			runTest('ls_health_test_enrollment', this);
		});
	})();
	</script>
	<?php

	echo '</div>';
}
