<?php
/**
 * Plugin Name: LS GrassBlade Debug
 * Description: Admin-only diagnostic tool for GrassBlade xAPI / SCORM integration.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_ls_gb_simulate_complete', 'ls_gb_ajax_simulate_complete' );
function ls_gb_ajax_simulate_complete() {
	check_ajax_referer( 'ls_gb_debug_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

	$content_id = (int) $_POST['content_id'];
	$user_id    = (int) $_POST['user_id'];

	if ( ! $content_id || ! $user_id ) {
		wp_send_json_error( 'Missing content_id or user_id.' );
	}

	$lessons = get_posts( array(
		'post_type'  => array( 'sfwd-lessons', 'sfwd-topic' ),
		'post_status'=> 'publish',
		'numberposts'=> 1,
		'fields'     => 'ids',
		'meta_query' => array( array( 'key' => 'show_xapi_content', 'value' => $content_id ) ),
	) );

	if ( empty( $lessons ) ) {
		wp_send_json_error( 'No lesson found with show_xapi_content = ' . $content_id . '. Check Section 4.' );
	}

	$lesson_id = (int) $lessons[0];
	$course_id = function_exists( 'learndash_get_course_id' ) ? (int) learndash_get_course_id( $lesson_id ) : 0;

	if ( ! $course_id ) {
		wp_send_json_error( 'Could not find course for lesson ' . $lesson_id . '.' );
	}

	delete_user_meta( $user_id, 'ls_scorm_ld_completed_' . $content_id );

	if ( function_exists( 'learndash_process_mark_complete' ) ) {
		learndash_process_mark_complete( $user_id, $lesson_id, false, $course_id );
	}

	$lesson_title = get_the_title( $lesson_id );
	$course_title = get_the_title( $course_id );

	wp_send_json_success( 'Fired learndash_process_mark_complete for user ' . $user_id . ' → lesson #' . $lesson_id . ' "' . $lesson_title . '" → course #' . $course_id . ' "' . $course_title . '". Check course page.' );
}

add_action( 'wp_ajax_ls_gb_reset_scorm', 'ls_gb_ajax_reset_scorm' );
function ls_gb_ajax_reset_scorm() {
	check_ajax_referer( 'ls_gb_debug_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_die( -1 );

	$content_id = (int) $_POST['content_id'];
	$user_id    = (int) $_POST['user_id'];

	if ( ! $content_id || ! $user_id ) {
		wp_send_json_error( 'Missing content_id or user_id.' );
	}

	// Clear GrassBlade SCORM vars from DB.
	if ( function_exists( 'grassblade_reset_learner_progress' ) ) {
		grassblade_reset_learner_progress( $content_id, $user_id );
	}

	// Clear registration UUID so SCORM starts fresh.
	$params      = grassblade_xapi_content::get_params( $content_id );
	$activity_id = ! empty( $params['activity_id'] ) ? $params['activity_id'] : '';
	if ( $activity_id ) {
		delete_user_meta( $user_id, 'xapi_reg_' . $activity_id );
	}

	// Clear our LD completion bridge flag.
	delete_user_meta( $user_id, 'ls_scorm_ld_completed_' . $content_id );

	wp_send_json_success( 'SCORM progress reset for user ' . $user_id . ', content ' . $content_id );
}

add_action( 'admin_menu', 'ls_gb_debug_menu' );
function ls_gb_debug_menu() {
	add_management_page(
		'GrassBlade Debug',
		'GB Debug',
		'manage_options',
		'ls-gb-debug',
		'ls_gb_debug_render'
	);
}

function ls_gb_debug_render() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.' );

	$pass  = '<span style="color:#2e7d32;font-weight:700">&#10003; PASS</span>';
	$fail  = '<span style="color:#c62828;font-weight:700">&#10007; FAIL</span>';
	$warn  = '<span style="color:#e65100;font-weight:700">&#9888; WARN</span>';
	$info  = '<span style="color:#1565c0;font-weight:700">&#9432; INFO</span>';

	echo '<div class="wrap"><h1>GrassBlade SCORM Debug</h1>';
	echo '<style>
		.gb-debug table { border-collapse:collapse; width:100%; margin-bottom:2rem; }
		.gb-debug th, .gb-debug td { border:1px solid #ccc; padding:8px 12px; text-align:left; vertical-align:top; }
		.gb-debug th { background:#f5f5f5; width:30%; }
		.gb-debug h2 { margin-top:2rem; border-bottom:2px solid #9439d4; padding-bottom:6px; }
		.gb-debug code { background:#f5f5f5; padding:2px 5px; border-radius:3px; word-break:break-all; }
		.gb-debug pre { background:#f5f5f5; padding:1rem; overflow:auto; white-space:pre-wrap; }
	</style><div class="gb-debug">';

	// -----------------------------------------------------------------------
	// Section 1: Plugin & Environment
	// -----------------------------------------------------------------------
	echo '<h2>1. Plugin &amp; Environment</h2><table>';

	$gb_active = class_exists( 'grassblade_xapi_content' );
	echo '<tr><th>GrassBlade class loaded</th><td>' . ( $gb_active ? $pass : $fail . ' — class grassblade_xapi_content not found' ) . '</td></tr>';

	$scorm_active = class_exists( 'grassblade_scorm' );
	echo '<tr><th>SCORM addon loaded</th><td>' . ( $scorm_active ? $pass : $fail . ' — grassblade_scorm class not found' ) . '</td></tr>';

	$ld_active = function_exists( 'learndash_user_get_enrolled_courses' );
	echo '<tr><th>LearnDash active</th><td>' . ( $ld_active ? $pass : $fail . ' — learndash_user_get_enrolled_courses() not found' ) . '</td></tr>';

	$ajax_url = admin_url( 'admin-ajax.php' );
	echo '<tr><th>AJAX endpoint</th><td>' . $info . ' <code>' . esc_html( $ajax_url ) . '</code></td></tr>';

	$upload_dir     = wp_upload_dir();
	$gb_upload_path = $upload_dir['basedir'] . '/grassblade';
	$gb_upload_url  = $upload_dir['baseurl'] . '/grassblade';
	$gb_writable    = is_writable( $gb_upload_path );
	echo '<tr><th>GrassBlade uploads path</th><td>' . ( $gb_writable ? $pass : $fail . ' — not writable' ) . ' <code>' . esc_html( $gb_upload_path ) . '</code></td></tr>';

	echo '</table>';

	// -----------------------------------------------------------------------
	// Section 2: GrassBlade Settings
	// -----------------------------------------------------------------------
	echo '<h2>2. GrassBlade Settings</h2><table>';

	if ( function_exists( 'grassblade_settings' ) ) {
		$endpoint = grassblade_settings( 'endpoint' );
		$version  = grassblade_settings( 'version' );
		$actor    = grassblade_settings( 'actor_type' );
		$url_slug = grassblade_settings( 'url_slug' );

		$ep_ok = ! empty( $endpoint );
		echo '<tr><th>LRS Endpoint</th><td>' . ( $ep_ok ? $info . ' <code>' . esc_html( $endpoint ) . '</code>' : $warn . ' Empty — no LRS configured. Completion tracking via LRS will not fire. OK if using LD Mark Complete only.' ) . '</td></tr>';
		echo '<tr><th>xAPI Version</th><td>' . $info . ' <code>' . esc_html( $version ?: 'not set' ) . '</code></td></tr>';
		echo '<tr><th>Actor Type</th><td>' . $info . ' <code>' . esc_html( $actor ?: 'not set' ) . '</code></td></tr>';
		echo '<tr><th>Content URL slug</th><td>' . $info . ' <code>' . esc_html( $url_slug ?: 'not set (default: gb-xapi-content)' ) . '</code></td></tr>';
	} else {
		echo '<tr><th>grassblade_settings()</th><td>' . $fail . ' — function not available</td></tr>';
	}

	echo '</table>';

	// -----------------------------------------------------------------------
	// Section 3: gb_xapi_content posts
	// -----------------------------------------------------------------------
	echo '<h2>3. Imported xAPI Content Posts</h2>';

	$posts = get_posts( array(
		'post_type'   => 'gb_xapi_content',
		'post_status' => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
		'numberposts' => -1,
	) );

	if ( empty( $posts ) ) {
		echo '<p>' . $fail . ' No gb_xapi_content posts found in database at all.</p>';
	} else {
		foreach ( $posts as $post ) {
			$meta = get_post_meta( $post->ID, 'xapi_content', true );

			echo '<h3>Post #' . $post->ID . ': ' . esc_html( $post->post_title ?: '(no title)' ) . ' — Status: <code>' . esc_html( $post->post_status ) . '</code></h3>';
			echo '<table>';

			// Status check
			$status_ok = ( 'publish' === $post->post_status );
			echo '<tr><th>Post status</th><td>' . ( $status_ok ? $pass : $fail . ' — must be "publish" to display' ) . ' <code>' . esc_html( $post->post_status ) . '</code></td></tr>';

			// Edit link
			echo '<tr><th>Edit post</th><td><a href="' . get_edit_post_link( $post->ID ) . '" target="_blank">Edit in admin</a></td></tr>';

			// xapi_content meta
			if ( empty( $meta ) ) {
				echo '<tr><th>xapi_content meta</th><td>' . $fail . ' — meta key "xapi_content" is empty. Upload processing failed to save params.</td></tr>';
				echo '</table>';
				continue;
			}

			echo '<tr><th>xapi_content meta</th><td>' . $pass . ' Meta exists.</td></tr>';

			// Version
			$ver = isset( $meta['version'] ) ? $meta['version'] : '';
			$ver_ok = in_array( $ver, array( 'scorm1.2', 'scorm2004', '1.0', '0.95', '0.9' ), true );
			echo '<tr><th>Content version</th><td>' . ( $ver_ok ? $pass : $warn ) . ' <code>' . esc_html( $ver ?: 'not set' ) . '</code></td></tr>';

			// src URL
			$src = isset( $meta['src'] ) ? $meta['src'] : '';
			$src_set = ! empty( $src );
			echo '<tr><th>src (launch URL)</th><td>' . ( $src_set ? $pass : $fail . ' — src not set. SCORM manifest parsing failed.' ) . ( $src_set ? ' <code>' . esc_html( $src ) . '</code>' : '' ) . '</td></tr>';

			// content_path
			$content_path = isset( $meta['content_path'] ) ? $meta['content_path'] : '';
			$path_ok = ! empty( $content_path ) && is_dir( $content_path );
			echo '<tr><th>content_path (folder)</th><td>' . ( $path_ok ? $pass : ( empty( $content_path ) ? $fail . ' — not set in meta' : $fail . ' — path set but folder does not exist on disk' ) ) . ( ! empty( $content_path ) ? ' <code>' . esc_html( $content_path ) . '</code>' : '' ) . '</td></tr>';

			// imsmanifest.xml
			if ( $path_ok ) {
				$manifest = $content_path . DIRECTORY_SEPARATOR . 'imsmanifest.xml';
				$manifest_ok = file_exists( $manifest );
				echo '<tr><th>imsmanifest.xml</th><td>' . ( $manifest_ok ? $pass : $fail . ' — file missing in content folder' ) . '</td></tr>';
			}

			// launch_path (actual file)
			$launch_path = isset( $meta['launch_path'] ) ? $meta['launch_path'] : '';
			if ( ! empty( $launch_path ) ) {
				$launch_path_clean = str_replace( '/', DIRECTORY_SEPARATOR, stripslashes( $launch_path ) );
				$launch_ok = file_exists( $launch_path_clean );
				echo '<tr><th>launch_path (file)</th><td>' . ( $launch_ok ? $pass : $fail . ' — file not found on disk' ) . ' <code>' . esc_html( $launch_path_clean ) . '</code></td></tr>';
			} else {
				echo '<tr><th>launch_path</th><td>' . $fail . ' — not set in meta. SCORM manifest parse did not find launch file.</td></tr>';
			}

			// completion_tracking
			$ct = isset( $meta['completion_tracking'] ) ? $meta['completion_tracking'] : false;
			echo '<tr><th>Completion tracking</th><td>' . ( $ct ? $warn . ' Enabled — SCORM must send complete statement. If no LRS, LD Mark Complete will be blocked.' : $info . ' Disabled — LD Mark Complete button will work freely.' ) . '</td></tr>';

			// activity_id
			$activity_id = isset( $meta['activity_id'] ) ? $meta['activity_id'] : '';
			echo '<tr><th>activity_id</th><td>' . ( ! empty( $activity_id ) ? $info : $warn ) . ' <code>' . esc_html( stripslashes( $activity_id ) ?: 'not set' ) . '</code></td></tr>';

			// Shortcode to use
			echo '<tr><th>Shortcode to embed</th><td><code>[grassblade id=' . $post->ID . ']</code></td></tr>';

			// URL test for src
			if ( $src_set ) {
				$src_clean = stripslashes( $src );
				$response  = wp_remote_head( $src_clean, array( 'timeout' => 5, 'sslverify' => false ) );
				if ( is_wp_error( $response ) ) {
					echo '<tr><th>HTTP: src reachable?</th><td>' . $fail . ' — wp_remote_head() error: <code>' . esc_html( $response->get_error_message() ) . '</code></td></tr>';
				} else {
					$code = wp_remote_retrieve_response_code( $response );
					$http_ok = ( 200 === (int) $code );
					echo '<tr><th>HTTP: src reachable?</th><td>' . ( $http_ok ? $pass : $fail . ' — HTTP ' . $code ) . ' URL: <code>' . esc_html( $src_clean ) . '</code></td></tr>';
				}
			}

			echo '</table>';
		}
	}

	// -----------------------------------------------------------------------
	// Section 4: LD lessons with SCORM attached
	// -----------------------------------------------------------------------
	echo '<h2>4. LearnDash Lessons / Topics with SCORM Attached</h2>';

	$ld_with_scorm = get_posts( array(
		'post_type'   => array( 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz' ),
		'post_status' => 'publish',
		'numberposts' => -1,
		'meta_query'  => array(
			array(
				'key'     => 'show_xapi_content',
				'compare' => 'EXISTS',
			),
		),
	) );

	if ( empty( $ld_with_scorm ) ) {
		echo '<p>' . $warn . ' No lessons/topics currently have SCORM content attached via metabox. To attach: edit a lesson → "xAPI Content" sidebar metabox → select content.</p>';
	} else {
		echo '<table><tr><th>Post</th><th>Type</th><th>xAPI Content ID</th><th>Content exists?</th><th>Link</th></tr>';
		foreach ( $ld_with_scorm as $ld_post ) {
			$xapi_id   = get_post_meta( $ld_post->ID, 'show_xapi_content', true );
			$xapi_post = $xapi_id ? get_post( $xapi_id ) : null;
			$exists    = ( $xapi_post && 'publish' === $xapi_post->post_status );
			echo '<tr>';
			echo '<td><a href="' . get_edit_post_link( $ld_post->ID ) . '" target="_blank">#' . $ld_post->ID . ' ' . esc_html( $ld_post->post_title ) . '</a></td>';
			echo '<td><code>' . esc_html( $ld_post->post_type ) . '</code></td>';
			echo '<td><code>' . esc_html( $xapi_id ?: 'not set' ) . '</code></td>';
			echo '<td>' . ( $exists ? $pass . ' published' : $fail . ' — content post ' . esc_html( $xapi_id ) . ' not found or not published' ) . '</td>';
			echo '<td><a href="' . get_permalink( $ld_post->ID ) . '" target="_blank">View</a></td>';
			echo '</tr>';
		}
		echo '</table>';
	}

	// -----------------------------------------------------------------------
	// Section 5: Rewrite rules check
	// -----------------------------------------------------------------------
	echo '<h2>5. Rewrite Rules</h2><table>';

	global $wp_rewrite;
	$rules  = get_option( 'rewrite_rules' );
	$gb_slug = function_exists( 'grassblade_settings' ) ? grassblade_settings( 'url_slug' ) : 'gb-xapi-content';
	$gb_slug = $gb_slug ?: 'gb-xapi-content';

	$has_gb_rule = false;
	if ( ! empty( $rules ) ) {
		foreach ( array_keys( $rules ) as $pattern ) {
			if ( false !== strpos( $pattern, $gb_slug ) ) {
				$has_gb_rule = true;
				break;
			}
		}
	}

	echo '<tr><th>gb_xapi_content rewrite rule</th><td>';
	if ( $has_gb_rule ) {
		echo $pass . ' Found rewrite rule for slug <code>' . esc_html( $gb_slug ) . '</code>';
	} else {
		echo $fail . ' No rewrite rule found for slug <code>' . esc_html( $gb_slug ) . '</code>. ';
		echo 'Go to <a href="' . admin_url( 'options-permalink.php' ) . '">Settings &gt; Permalinks</a> and click Save.';
	}
	echo '</td></tr>';

	$flush_url = admin_url( 'options-permalink.php' );
	echo '<tr><th>Flush permalink action</th><td><a href="' . $flush_url . '" target="_blank">Settings &gt; Permalinks (click Save to flush)</a></td></tr>';

	echo '</table>';

	// -----------------------------------------------------------------------
	// Section 6: Raw meta dump for debugging
	// -----------------------------------------------------------------------
	echo '<h2>6. Raw xapi_content Meta Dump</h2>';
	foreach ( $posts as $post ) {
		$meta = get_post_meta( $post->ID, 'xapi_content', true );
		echo '<h3>Post #' . $post->ID . '</h3>';
		if ( empty( $meta ) ) {
			echo '<p>No xapi_content meta.</p>';
		} else {
			echo '<pre>' . esc_html( print_r( $meta, true ) ) . '</pre>';
		}
	}

	// -----------------------------------------------------------------------
	// Section 7: Simulate SCORM completion (testing only)
	// -----------------------------------------------------------------------
	echo '<h2>7. Simulate SCORM Completion (Testing)</h2>';
	echo '<p>Fires <code>learndash_process_mark_complete</code> directly for a user + content, bypassing the SCORM. Use to verify the full LD completion pipeline (lesson → course → certificate) without sitting through the whole course. Reset afterwards.</p>';

	$all_users_s = get_users( array( 'fields' => array( 'ID', 'user_login', 'display_name' ) ) );
	$nonce_s     = wp_create_nonce( 'ls_gb_debug_nonce' );

	echo '<div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">';
	echo '<div><label><strong>Content</strong><br><select id="ls-gb-sim-content">';
	foreach ( $posts as $post ) {
		echo '<option value="' . $post->ID . '">#' . $post->ID . ' ' . esc_html( $post->post_title ?: '(no title)' ) . '</option>';
	}
	echo '</select></label></div>';
	echo '<div><label><strong>User</strong><br><select id="ls-gb-sim-user">';
	foreach ( $all_users_s as $u ) {
		echo '<option value="' . $u->ID . '">' . esc_html( $u->display_name . ' (' . $u->user_login . ')' ) . '</option>';
	}
	echo '</select></label></div>';
	echo '<div><button id="ls-gb-sim-btn" class="button button-primary">Simulate Completion</button></div>';
	echo '<div id="ls-gb-sim-result" style="margin-top:4px;font-weight:600;"></div>';
	echo '</div>';

	echo '<script>
	document.getElementById("ls-gb-sim-btn").addEventListener("click", function() {
		var btn = this;
		btn.disabled = true; btn.textContent = "Firing...";
		var data = new URLSearchParams();
		data.append("action", "ls_gb_simulate_complete");
		data.append("nonce", "' . esc_js( $nonce_s ) . '");
		data.append("content_id", document.getElementById("ls-gb-sim-content").value);
		data.append("user_id", document.getElementById("ls-gb-sim-user").value);
		fetch("' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", { method:"POST", body:data })
			.then(r => r.json())
			.then(function(r) {
				document.getElementById("ls-gb-sim-result").innerHTML = r.success
					? "<span style=\'color:green\'>" + r.data + "</span>"
					: "<span style=\'color:red\'>" + r.data + "</span>";
				btn.disabled = false; btn.textContent = "Simulate Completion";
			});
	});
	</script>';

	// -----------------------------------------------------------------------
	// Section 8: SCORM progress reset tool
	// -----------------------------------------------------------------------
	echo '<h2>8. Reset SCORM Progress (Testing)</h2>';
	echo '<p>Resets GrassBlade SCORM vars, registration UUID, and the LD bridge completion flag for a user. Does NOT reset LD course progress — do that separately via WP Admin > Users.</p>';

	$all_users = get_users( array( 'fields' => array( 'ID', 'user_login', 'display_name' ) ) );

	echo '<div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end;">';
	echo '<div><label><strong>Content</strong><br><select id="ls-gb-reset-content">';
	foreach ( $posts as $post ) {
		echo '<option value="' . $post->ID . '">#' . $post->ID . ' ' . esc_html( $post->post_title ?: '(no title)' ) . '</option>';
	}
	echo '</select></label></div>';

	echo '<div><label><strong>User</strong><br><select id="ls-gb-reset-user">';
	foreach ( $all_users as $u ) {
		echo '<option value="' . $u->ID . '">' . esc_html( $u->display_name . ' (' . $u->user_login . ')' ) . '</option>';
	}
	echo '</select></label></div>';

	echo '<div><button id="ls-gb-reset-btn" class="button button-secondary">Reset SCORM Progress</button></div>';
	echo '<div id="ls-gb-reset-result" style="margin-top:4px;"></div>';
	echo '</div>';

	$nonce = wp_create_nonce( 'ls_gb_debug_nonce' );
	echo '<script>
	document.getElementById("ls-gb-reset-btn").addEventListener("click", function() {
		var btn = this;
		btn.disabled = true;
		btn.textContent = "Resetting...";
		var data = new URLSearchParams();
		data.append("action", "ls_gb_reset_scorm");
		data.append("nonce", "' . esc_js( $nonce ) . '");
		data.append("content_id", document.getElementById("ls-gb-reset-content").value);
		data.append("user_id", document.getElementById("ls-gb-reset-user").value);
		fetch("' . esc_url( admin_url( 'admin-ajax.php' ) ) . '", { method:"POST", body:data })
			.then(r => r.json())
			.then(function(r) {
				document.getElementById("ls-gb-reset-result").innerHTML = r.success
					? "<span style=\'color:green\'>Done: " + r.data + "</span>"
					: "<span style=\'color:red\'>Error: " + r.data + "</span>";
				btn.disabled = false;
				btn.textContent = "Reset SCORM Progress";
			});
	});
	</script>';

	echo '</div></div>';
}
