<?php
/**
 * Plugin Name: LS Integration Health
 * Description: Admin-only integration health checker. Runs real behavioural checks, not plugin-active flags.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

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

	// Enrollment hook: walk $wp_filter directly. We check that the
	// woocommerce_order_status_completed hook has at least one callback whose
	// object is an instance of LearnDash_WooCommerce. This proves the
	// integration is wired, not just that the plugin is installed.
	$ld_hook_wired = false;
	if ( $wc_ld_class && isset( $wp_filter['woocommerce_order_status_completed'] ) ) {
		foreach ( $wp_filter['woocommerce_order_status_completed']->callbacks as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if (
					is_array( $cb['function'] ) &&
					isset( $cb['function'][0] ) &&
					$cb['function'][0] instanceof LearnDash_WooCommerce
				) {
					$ld_hook_wired = true;
					break 2;
				}
			}
		}
	}
	$ld[] = array(
		'label'  => 'Enrollment hook wired on order completion',
		'status' => $ld_hook_wired ? 'pass' : ( $wc_ld_class ? 'warn' : 'fail' ),
		'detail' => $ld_hook_wired
			? 'LearnDash_WooCommerce instance found in woocommerce_order_status_completed callbacks'
			: ( $wc_ld_class
				? 'Plugin class exists but its instance is not hooked on woocommerce_order_status_completed. Check plugin settings or conflicts.'
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

	$checks['Security'] = $sec;

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
