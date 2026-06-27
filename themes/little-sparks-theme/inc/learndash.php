<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * WC for LearnDash handles the full access pipeline via WooCommerce order
 * status mapping configured in LD Admin > WooCommerce:
 *   Processing / Completed → Grant access
 *   Cancelled / Refunded / Failed → Deny access
 * No custom PHP needed for purchase or revocation.
 */

// ---------------------------------------------------------------------------
// AJAX: save scraped SCORM outline titles to gb_xapi_content post meta.
// Admin-only. Called once by the JS scraper on the course dashboard.
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_ls_save_scorm_outline', 'ls_ajax_save_scorm_outline' );
function ls_ajax_save_scorm_outline() {
	check_ajax_referer( 'ls_scorm_outline', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'forbidden' );

	$xapi_id = absint( $_POST['xapi_id'] ?? 0 );
	$items_raw = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '';
	$items     = is_array( $items_raw ) ? $items_raw : ( $items_raw ? json_decode( $items_raw, true ) : array() );
	if ( ! is_array( $items ) ) $items = array();

	if ( ! $xapi_id || empty( $items ) ) wp_send_json_error( 'missing data' );

	$clean = array();
	foreach ( $items as $item ) {
		if ( empty( $item['title'] ) ) continue;
		$clean[] = array(
			'title'   => sanitize_text_field( $item['title'] ),
			'locked'  => ! empty( $item['locked'] ),
			'current' => ! empty( $item['current'] ),
		);
	}

	if ( empty( $clean ) ) wp_send_json_error( 'no valid items' );

	update_post_meta( $xapi_id, '_ls_scorm_outline', wp_json_encode( $clean ) );
	wp_send_json_success( array( 'count' => count( $clean ) ) );
}

// ---------------------------------------------------------------------------
// AJAX: save scraped SCORM progress % to user meta.
// Admin-only per-user store; used to display real Rise progress on dashboard.
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_ls_save_scorm_progress', 'ls_ajax_save_scorm_progress' );
function ls_ajax_save_scorm_progress() {
	check_ajax_referer( 'ls_scorm_outline', 'nonce' );

	$xapi_id = absint( $_POST['xapi_id'] ?? 0 );
	$percent = isset( $_POST['percent'] ) ? min( 100, max( 0, (int) $_POST['percent'] ) ) : null;

	if ( ! $xapi_id || null === $percent ) wp_send_json_error( 'missing data' );

	if ( ! current_user_can( 'manage_options' ) ) {
		$lessons = get_posts( array(
			'post_type'   => array( 'sfwd-lessons', 'sfwd-topic' ),
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_query'  => array( array( 'key' => 'show_xapi_content', 'value' => $xapi_id ) ),
		) );
		if ( empty( $lessons ) ) wp_send_json_error( 'not found' );
		$cid = function_exists( 'learndash_get_course_id' ) ? (int) learndash_get_course_id( $lessons[0] ) : 0;
		if ( ! $cid || ! function_exists( 'sfwd_lms_has_access' ) || ! sfwd_lms_has_access( $cid, get_current_user_id() ) ) {
			wp_send_json_error( 'forbidden' );
		}
	}

	update_user_meta( get_current_user_id(), '_ls_scorm_progress_' . $xapi_id, $percent );
	wp_send_json_success();
}

// ---------------------------------------------------------------------------
// Helper: return saved SCORM outline titles for a gb_xapi_content post.
// Returns empty array if not yet scraped.
// ---------------------------------------------------------------------------
function ls_get_scorm_outline( $xapi_id ) {
	if ( ! $xapi_id ) return array();
	$saved = get_post_meta( (int) $xapi_id, '_ls_scorm_outline', true );
	if ( ! $saved ) return array();
	$decoded = json_decode( $saved, true );
	if ( ! is_array( $decoded ) || empty( $decoded ) ) return array();
	// Old format was array of strings — force re-scrape by returning empty.
	if ( isset( $decoded[0] ) && is_string( $decoded[0] ) ) return array();
	return $decoded;
}

// ---------------------------------------------------------------------------
// Course page init — fires on template_redirect, before template loads.
// Detects enrollment early so filters are in place before the_content runs.
// ---------------------------------------------------------------------------
add_action( 'template_redirect', 'ls_course_page_init' );
function ls_course_page_init() {
	if ( ! is_singular( 'sfwd-courses' ) ) return;

	$course_id = get_queried_object_id();
	$user_id   = get_current_user_id();

	// --- Shared data gathering ---
	$product_id = get_post_meta( $course_id, '_ls_linked_product_id', true );

	$short_desc  = '';
	$course_meta = get_post_meta( $course_id, '_sfwd-courses', true );
	if ( is_array( $course_meta ) && ! empty( $course_meta['sfwd-courses_course_short_description'] ) ) {
		$short_desc = $course_meta['sfwd-courses_course_short_description'];
	}

	$level      = $product_id ? get_field( 'course_level', $product_id ) : '';
	$total_secs = 0;
	$duration   = '';
	if ( $product_id ) {
		$total_secs = (int) get_post_meta( $product_id, '_learndash_course_grid_duration', true );
		if ( $total_secs ) {
			$duration = ls_format_duration( $total_secs );
		}
	}

	$cat_name = '';
	if ( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( ! in_array( $term->slug, array( 'uncategorized', 'uncategorised' ), true ) ) {
					$cat_name = $term->name;
					break;
				}
			}
		}
	}

	$thumb_id = get_post_thumbnail_id( $course_id );

	// --- Enrolled: replace the_content with dashboard, add body class ---
	if ( $user_id && function_exists( 'sfwd_lms_has_access' ) && sfwd_lms_has_access( $course_id, $user_id ) ) {
		add_filter( 'body_class', 'ls_add_course_dashboard_body_class' );
		$GLOBALS['ls_cd_data'] = compact(
			'course_id', 'user_id', 'product_id', 'short_desc',
			'level', 'duration', 'cat_name', 'thumb_id', 'total_secs'
		);
		add_filter( 'the_content', 'ls_course_enrolled_content', 999 );
		return;
	}

	// --- Not enrolled: store data for hero hook ---
	$GLOBALS['ls_course_hero_data'] = compact(
		'product_id', 'short_desc', 'level', 'duration',
		'cat_name', 'thumb_id'
	);
}

// ---------------------------------------------------------------------------
// Non-enrolled hero — prepended to the_content at priority 20 (after LD
// adds its output at ~10/11). Reliable across all LD template paths.
// ---------------------------------------------------------------------------
add_filter( 'the_content', 'ls_course_page_hero_content', 20 );
function ls_course_page_hero_content( $content ) {
	if ( ! is_singular( 'sfwd-courses' ) ) return $content;
	if ( ! empty( $GLOBALS['ls_cd_data'] ) ) return $content; // Enrolled — dashboard handles it.
	if ( empty( $GLOBALS['ls_course_hero_data'] ) ) return $content;

	$d        = $GLOBALS['ls_course_hero_data'];
	$course_id = get_queried_object_id();
	$img_html  = '';
	if ( $d['thumb_id'] ) {
		$img_html = '<div class="ls-course-hero-img-wrap">' . wp_get_attachment_image(
			$d['thumb_id'],
			'large',
			false,
			array(
				'class'         => 'ls-course-hero-img',
				'loading'       => 'eager',
				'fetchpriority' => 'high',
				'alt'           => esc_attr( get_the_title( $course_id ) ),
			)
		) . '</div>';
	}

	$has_meta = $d['cat_name'] || $d['level'] || $d['duration'];
	ob_start();
	?>
	<div class="ls-course-hero">
		<?php echo $img_html; // Already escaped via wp_get_attachment_image. ?>
		<div class="ls-course-hero-body">
			<h1 class="ls-course-title"><?php echo esc_html( get_the_title( $course_id ) ); ?></h1>
			<?php if ( $d['short_desc'] ) : ?>
				<p class="ls-course-excerpt"><?php echo esc_html( $d['short_desc'] ); ?></p>
			<?php endif; ?>
			<?php if ( $has_meta ) : ?>
				<div class="ls-course-meta-bar">
					<?php if ( $d['cat_name'] ) : ?>
						<span class="ls-meta-pill ls-meta-cat"><?php echo esc_html( $d['cat_name'] ); ?></span>
					<?php endif; ?>
					<?php if ( $d['level'] ) : ?>
						<span class="ls-meta-pill ls-meta-level"><?php echo esc_html( $d['level'] ); ?></span>
					<?php endif; ?>
					<?php if ( $d['duration'] ) : ?>
						<span class="ls-meta-pill ls-meta-duration">
							<svg aria-hidden="true" focusable="false" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
							<?php echo esc_html( $d['duration'] ); ?>
						</span>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return ob_get_clean() . $content;
}

function ls_add_course_dashboard_body_class( $classes ) {
	$classes[] = 'ls-course-dashboard';
	return $classes;
}

// Replaces the_content with dashboard HTML for enrolled users.
// Registered at priority 5 — before LD shortcode runs at 11.
function ls_course_enrolled_content( $content ) {
	if ( ! is_singular( 'sfwd-courses' ) || empty( $GLOBALS['ls_cd_data'] ) ) return $content;

	$d = $GLOBALS['ls_cd_data'];
	ob_start();
	ls_render_course_dashboard(
		$d['course_id'], $d['user_id'], $d['product_id'],
		$d['short_desc'], $d['level'], $d['duration'],
		$d['cat_name'], $d['thumb_id'], $d['total_secs']
	);
	$out = ob_get_clean();

	return $out;
}

// ---------------------------------------------------------------------------
// Enrolled course dashboard — two-column layout with curriculum sidebar,
// progress card, continue card, and about section.
// ---------------------------------------------------------------------------
function ls_render_course_dashboard( $course_id, $user_id, $product_id, $short_desc, $level, $duration, $cat_name, $thumb_id, $total_secs ) {

	// --- Progress ---
	$progress_meta   = get_user_meta( $user_id, '_sfwd-course_progress', true );
	$completed_count = 0;
	$total_count     = 0;
	if ( is_array( $progress_meta ) && isset( $progress_meta[ $course_id ] ) ) {
		$cp              = $progress_meta[ $course_id ];
		$completed_count = (int) ( isset( $cp['completed'] ) ? $cp['completed'] : 0 );
		$total_count     = (int) ( isset( $cp['total'] ) ? $cp['total'] : 0 );
	}
	$percent = ( $total_count > 0 ) ? round( ( $completed_count / $total_count ) * 100 ) : 0;

	// --- Lessons (queried early so SCORM fallback can use $lesson_ids) ---
	$lesson_ids = get_posts( array(
		'post_type'      => 'sfwd-lessons',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
		'meta_key'       => 'course_id',
		'meta_value'     => $course_id,
		'fields'         => 'ids',
	) );

	$next_lesson_id  = 0;
	$next_lesson_url = '';
	$lesson_data     = array();
	$scrape_tasks    = array();

	// Overlay SCORM internal progress if higher than LD's completion-based %.
	if ( $percent < 100 ) {
		foreach ( $lesson_ids as $_lid ) {
			$_xapi = (int) get_post_meta( $_lid, 'show_xapi_content', true );
			if ( $_xapi ) {
				$_saved = (int) get_user_meta( $user_id, '_ls_scorm_progress_' . $_xapi, true );
				if ( $_saved > $percent ) {
					$percent = $_saved;
				}
			}
		}
	}

	$remaining_str = '';
	if ( $total_secs ) {
		$remaining_secs = ( $percent < 100 ) ? (int) round( $total_secs * ( 1 - $percent / 100 ) ) : 0;
		$remaining_str  = $remaining_secs > 0
			? 'Approx. ' . ls_format_duration( $remaining_secs ) . ' remaining'
			: 'Course complete';
	}

	foreach ( $lesson_ids as $lid ) {
		$complete = function_exists( 'learndash_is_lesson_complete' )
			? learndash_is_lesson_complete( $user_id, $lid, $course_id )
			: false;

		$xapi_id     = (int) get_post_meta( $lid, 'show_xapi_content', true );
		$launch_url  = '';
		$outline     = array();

		if ( $xapi_id ) {
			$launch_url = add_query_arg( array(
				'action'     => 'grassblade_launch',
				't'          => time(),
				'content_id' => str_replace( '=', '', base64_encode( (string) $xapi_id ) ),
			), admin_url( 'admin-ajax.php' ) );

			$outline = ls_get_scorm_outline( $xapi_id );

			// Outline: admin-only, one-time cache. Progress: all enrolled users.
			$needs_outline  = empty( $outline ) && current_user_can( 'manage_options' );
			$needs_progress = ( $percent < 100 );
			if ( $needs_outline || $needs_progress ) {
				$scrape_tasks[] = array(
					'xapi_id'         => $xapi_id,
					'launch_url'      => $launch_url,
					'scrape_outline'  => $needs_outline,
					'scrape_progress' => $needs_progress,
				);
			}
		}

		$url = $launch_url ?: get_permalink( $lid );

		$lesson_data[] = array(
			'id'        => $lid,
			'title'     => get_the_title( $lid ),
			'url'       => $url,
			'completed' => (bool) $complete,
			'outline'   => $outline,
		);

		if ( ! $complete && ! $next_lesson_id ) {
			$next_lesson_id  = $lid;
			$next_lesson_url = $url;
		}
	}

	$course_complete   = ( $percent >= 100 && count( $lesson_data ) > 0 );
	$next_lesson_title = $next_lesson_id ? get_the_title( $next_lesson_id ) : '';
	$lesson_num_label  = $completed_count + 1;

	// --- About text: post content, else short desc ---
	$post_content = wpautop( wp_kses_post( get_post_field( 'post_content', $course_id ) ) );
	if ( ! trim( strip_tags( $post_content ) ) && $short_desc ) {
		$post_content = '<p>' . esc_html( $short_desc ) . '</p>';
	}

	// --- Certificate URL ---
	$cert_url = '';
	if ( $course_complete && function_exists( 'learndash_get_course_certificate_link' ) ) {
		$cert_url = learndash_get_course_certificate_link( $course_id, $user_id );
	}
	?>
	<div class="ls-course-dashboard-wrap">

		<?php /* ===== SIDEBAR ===== */ ?>
		<aside class="ls-cd-sidebar">
			<div class="ls-cd-card">

				<?php if ( $thumb_id ) : ?>
				<div class="ls-cd-thumb-wrap">
					<?php echo wp_get_attachment_image( $thumb_id, 'medium', false, array(
						'class'         => 'ls-cd-thumb',
						'loading'       => 'eager',
						'fetchpriority' => 'high',
						'alt'           => esc_attr( get_the_title( $course_id ) ),
					) ); ?>
				</div>
				<?php endif; ?>

				<h1 class="ls-cd-course-title"><?php echo esc_html( get_the_title( $course_id ) ); ?></h1>

				<div class="ls-cd-meta-row">
					<?php if ( $cat_name ) : ?>
						<span class="ls-cd-badge"><?php echo esc_html( $cat_name ); ?></span>
					<?php endif; ?>
					<?php if ( $duration ) : ?>
						<span class="ls-cd-badge"><?php echo esc_html( $duration ); ?></span>
					<?php endif; ?>
					<?php if ( $level ) : ?>
						<span class="ls-cd-badge ls-cd-badge--accent"><?php echo esc_html( $level ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $lesson_data ) ) : ?>
				<nav class="ls-cd-curriculum" aria-label="<?php esc_attr_e( 'Course curriculum', 'little-sparks' ); ?>">
					<p class="ls-cd-curriculum-label">Curriculum</p>
					<?php foreach ( $lesson_data as $item ) :
						$has_outline = ! empty( $item['outline'] );
						if ( $has_outline ) :
							foreach ( $item['outline'] as $i => $entry ) :
								$ol_title   = is_array( $entry ) ? ( $entry['title'] ?? '' ) : (string) $entry;
								$ol_locked  = is_array( $entry ) && ! empty( $entry['locked'] );
								$ol_current = is_array( $entry ) && ! empty( $entry['current'] );

								if ( $item['completed'] ) {
									$state = 'done';
								} elseif ( $ol_locked ) {
									$state = 'locked';
								} elseif ( $ol_current ) {
									$state = 'current';
								} else {
									// Sequential course: not locked, not current = already passed through.
									$state = 'done';
								}

								$href = ( 'locked' === $state ) ? '#' : $item['url'];
							?>
							<a href="<?php echo esc_url( $href ); ?>"
							   class="ls-cd-lesson ls-cd-outline-item ls-cd-outline-item--<?php echo esc_attr( $state ); ?>"
							   <?php if ( 'locked' === $state ) : ?>aria-disabled="true" tabindex="-1"<?php endif; ?>
							   aria-label="<?php echo esc_attr( $ol_title ) . ( 'done' === $state ? ' — completed' : ( 'locked' === $state ? ' — locked' : '' ) ); ?>">
								<div class="ls-cd-lesson-icon" aria-hidden="true">
									<?php if ( 'done' === $state ) : ?>
										<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
									<?php elseif ( 'locked' === $state ) : ?>
										<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
									<?php else : ?>
										<span class="ls-cd-outline-num"><?php echo esc_html( $i + 1 ); ?></span>
									<?php endif; ?>
								</div>
								<span class="ls-cd-lesson-title"><?php echo esc_html( $ol_title ); ?></span>
							</a>
							<?php endforeach;
						else :
							$is_next   = ( ! $item['completed'] && $item['id'] === $next_lesson_id );
							$state_cls = $item['completed'] ? 'ls-cd-lesson--done' : ( $is_next ? 'ls-cd-lesson--next' : 'ls-cd-lesson--upcoming' );
						?>
						<a href="<?php echo esc_url( $item['url'] ); ?>"
						   class="ls-cd-lesson <?php echo esc_attr( $state_cls ); ?>"
						   aria-label="<?php echo esc_attr( $item['title'] ) . ( $item['completed'] ? ' — completed' : '' ); ?>">
							<div class="ls-cd-lesson-icon" aria-hidden="true">
								<?php if ( $item['completed'] ) : ?>
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
								<?php elseif ( $is_next ) : ?>
									<svg viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="5 3 19 12 5 21 5 3"/></svg>
								<?php else : ?>
									<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
								<?php endif; ?>
							</div>
							<span class="ls-cd-lesson-title"><?php echo esc_html( $item['title'] ); ?></span>
						</a>
						<?php endif; ?>
					<?php endforeach; ?>
				</nav>
				<?php endif; ?>

			</div>
		</aside>

		<?php /* ===== MAIN ===== */ ?>
		<main class="ls-cd-main">

			<?php /* Progress card */ ?>
			<div class="ls-cd-card ls-cd-progress-card">
				<div class="ls-cd-progress-header">
					<div>
						<h2 class="ls-cd-section-title">Your Progress</h2>
						<p class="ls-cd-progress-sub">
							<?php echo $course_complete
								? esc_html__( 'You\'ve completed this course.', 'little-sparks' )
								: esc_html__( 'Keep going — you\'re doing great.', 'little-sparks' ); ?>
						</p>
					</div>
					<div class="ls-cd-progress-stats">
						<div class="ls-cd-percent"><?php echo esc_html( $percent ); ?>%</div>
						<?php if ( $remaining_str ) : ?>
						<div class="ls-cd-time-left"><?php echo esc_html( $remaining_str ); ?></div>
						<?php endif; ?>
					</div>
				</div>
				<div class="ls-cd-track" role="progressbar" aria-valuenow="<?php echo esc_attr( $percent ); ?>" aria-valuemin="0" aria-valuemax="100">
					<div class="ls-cd-fill" style="width:<?php echo esc_attr( $percent ); ?>%;"></div>
				</div>
			</div>

			<?php /* Continue / complete card */ ?>
			<?php if ( $course_complete ) : ?>
			<div class="ls-cd-card ls-cd-continue-card ls-cd-continue-card--done">
				<div class="ls-cd-continue-info">
					<h4 class="ls-cd-continue-eyebrow">Complete</h4>
					<h3 class="ls-cd-continue-title">Course Finished</h3>
					<p class="ls-cd-continue-sub">All lessons completed.</p>
				</div>
				<?php if ( $cert_url ) : ?>
				<a href="<?php echo esc_url( $cert_url ); ?>" class="ls-cd-btn" target="_blank" rel="noopener noreferrer">
					Download Certificate
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="3" x2="12" y2="21"/></svg>
				</a>
				<?php endif; ?>
			</div>
			<?php elseif ( $next_lesson_id ) : ?>
			<div class="ls-cd-card ls-cd-continue-card">
				<div class="ls-cd-continue-info">
					<h4 class="ls-cd-continue-eyebrow">Up Next</h4>
					<h3 class="ls-cd-continue-title"><?php echo esc_html( $next_lesson_title ); ?></h3>
					<p class="ls-cd-continue-sub">
						<?php echo $percent > 0
							? sprintf( esc_html__( 'Lesson %1$d of %2$d', 'little-sparks' ), $lesson_num_label, $total_count )
							: esc_html__( 'Start your first lesson', 'little-sparks' ); ?>
					</p>
				</div>
				<a href="<?php echo esc_url( $next_lesson_url ); ?>" class="ls-cd-btn">
					<?php echo $percent > 0 ? esc_html__( 'Resume', 'little-sparks' ) : esc_html__( 'Start', 'little-sparks' ); ?>
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
				</a>
			</div>
			<?php endif; ?>

			<?php /* About card */ ?>
			<?php if ( $post_content ) : ?>
			<div class="ls-cd-card ls-cd-about-card">
				<h2 class="ls-cd-section-title">About this course</h2>
				<div class="ls-cd-about-body"><?php echo $post_content; // Sanitised via wp_kses_post + wpautop above. ?></div>
			</div>
			<?php endif; ?>

		</main>
	</div>

	<?php if ( ! empty( $scrape_tasks ) ) :
		$nonce = wp_create_nonce( 'ls_scorm_outline' );
		$ajax  = admin_url( 'admin-ajax.php' );
	?>
	<script>
	(function() {
		var tasks    = <?php echo wp_json_encode( $scrape_tasks ); ?>;
		var nonce    = <?php echo wp_json_encode( $nonce ); ?>;
		var ajaxUrl  = <?php echo wp_json_encode( $ajax ); ?>;

		// Walk nested iframes/frames to find a document containing selector.
		function deepQueryAll( win, selector, maxDepth, results, depth ) {
			results = results || [];
			depth   = depth || 0;
			if ( maxDepth <= 0 ) return results;
			try {
				var els = win.document.querySelectorAll( selector );
				if ( els.length ) {
					Array.prototype.forEach.call( els, function(e) { results.push(e); } );
					return results;
				}
				var frames = win.document.querySelectorAll( 'iframe, frame' );
				for ( var i = 0; i < frames.length; i++ ) {
					try { deepQueryAll( frames[i].contentWindow, selector, maxDepth - 1, results, depth + 1 ); } catch(e) {}
				}
			} catch(e) {}
			return results;
		}

		function post( action, data ) {
			var fd = new FormData();
			fd.append( 'action', action );
			fd.append( 'nonce', nonce );
			Object.keys( data ).forEach( function(k) {
				var v = data[k];
				if ( Array.isArray(v) ) {
					if ( v.length && typeof v[0] === 'object' ) {
						fd.append( k, JSON.stringify(v) );
					} else {
						v.forEach( function(i) { fd.append( k + '[]', i ); } );
					}
				} else {
					fd.append( k, v );
				}
			} );
			return fetch( ajaxUrl, { method: 'POST', body: fd } ).then( function(r) { return r.json(); } );
		}

		function updateProgressBar( pct ) {
			var fill    = document.querySelector( '.ls-cd-fill' );
			var pctEl   = document.querySelector( '.ls-cd-percent' );
			var timeEl  = document.querySelector( '.ls-cd-time-left' );
			if ( fill )   fill.style.width = pct + '%';
			if ( pctEl )  pctEl.textContent = pct + '%';
			if ( timeEl && pct >= 100 ) timeEl.textContent = 'Course complete';
		}

		function scrapeTask( task ) {
			var frame = document.createElement( 'iframe' );
			frame.src = task.launch_url;
			frame.style.cssText = 'position:fixed;width:1280px;height:800px;opacity:0;pointer-events:none;top:-9999px;left:-9999px;';
			frame.setAttribute( 'aria-hidden', 'true' );
			document.body.appendChild( frame );

			var attempts    = 0;
			var maxAttempts = 60;
			var progressDone = ! task.scrape_progress;
			var outlineDone  = ! task.scrape_outline;

			var poll = setInterval( function() {
				attempts++;

				// --- Progress ---
				if ( ! progressDone ) {
					var progEls = deepQueryAll( frame.contentWindow, '.nav-sidebar-header__progress-text', 4 );
					if ( progEls.length ) {
						progressDone = true;
						var raw   = progEls[0].textContent || '';
						var match = raw.match( /(\d+)/ );
						if ( match ) {
							var pct = Math.min( 100, Math.max( 0, parseInt( match[1], 10 ) ) );
							updateProgressBar( pct );
							post( 'ls_save_scorm_progress', { xapi_id: task.xapi_id, percent: pct } );
						}
					}
				}

				// --- Outline ---
				if ( ! outlineDone ) {
					var outlineEls = deepQueryAll( frame.contentWindow, '.nav-sidebar__outline-item__link', 4 );
					if ( outlineEls.length ) {
						outlineDone = true;
						var lockPhrases = [
							'This lesson is currently unavailable',
							'Lessons must be completed in order',
							'Complete previous lessons first',
							'lesson is locked'
						];
						var items = [];
						outlineEls.forEach( function(el) {
							var raw      = el.textContent || '';
							var isLocked = lockPhrases.some( function(p) {
								return raw.toLowerCase().indexOf( p.toLowerCase() ) !== -1;
							} );
							// Rise marks the active lesson with --active on the link element.
							var isCurrent = el.classList.contains( 'nav-sidebar__outline-item__link--active' )
								|| el.classList.contains( 'active' );
							// Strip lock phrases to get the clean title.
							var title = raw;
							lockPhrases.forEach( function(p) {
								title = title.replace( new RegExp( p, 'gi' ), '' );
							} );
							title = title.trim();
							if ( title ) {
								items.push( { title: title, locked: isLocked, current: isCurrent } );
							}
						} );
						if ( items.length ) {
							post( 'ls_save_scorm_outline', { xapi_id: task.xapi_id, items: items } )
								.then( function(data) {
									if ( data.success ) {
										document.body.removeChild( frame );
										window.location.reload();
									}
								} );
							return;
						}
					}
				}

				// Both done or timed out.
				if ( ( progressDone && outlineDone ) || attempts >= maxAttempts ) {
					clearInterval( poll );
					try { document.body.removeChild( frame ); } catch(e) {}
				}
			}, 500 );
		}

		tasks.forEach( function( task ) { scrapeTask( task ); } );
	})();
	</script>
	<?php endif; ?>
	<?php
}

// ---------------------------------------------------------------------------
// Inject WC add-to-cart button for closed courses.
// learndash-woocommerce plugin handles enrollment only — it never outputs a
// buy button. We hook learndash_payment_button_closed to inject one ourselves
// using the linked WC product stored by course-sync.php.
// ---------------------------------------------------------------------------
add_filter( 'learndash_payment_button_closed', 'ls_wc_buy_button', 10, 2 );
function ls_wc_buy_button( $button, $params ) {
	if ( ! empty( $button ) ) return $button;
	if ( ! ls_wc_active() ) return $button;

	$post_id    = get_the_ID();
	$product_id = get_post_meta( $post_id, '_ls_linked_product_id', true );

	if ( ! $product_id ) return $button;

	$product = wc_get_product( $product_id );
	if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) return $button;

	$url = $product->add_to_cart_url();

	return sprintf(
		'<a href="%s" class="btn-join button button-primary button-large ls-enrol-btn" data-product_id="%d" data-quantity="1" aria-label="%s">%s</a>',
		esc_url( $url ),
		absint( $product_id ),
		esc_attr__( 'Enrol on this course', 'little-sparks' ),
		esc_html__( 'Enrol Now', 'little-sparks' )
	);
}

// ---------------------------------------------------------------------------
// Admin notice: published course with price type 'free'.
// Warns the client before a course goes live without a payment gate.
// Fires on the course edit screen and the courses list screen.
// ---------------------------------------------------------------------------
add_action( 'admin_notices', 'ls_warn_free_course' );
function ls_warn_free_course() {
	$screen = get_current_screen();

	if ( ! $screen || 'sfwd-courses' !== $screen->post_type ) return;

	$free_courses = get_posts( array(
		'post_type'      => 'sfwd-courses',
		'post_status'    => 'publish',
		'numberposts'    => -1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'     => '_sfwd-courses',
				'value'   => 's:18:"course_price_type";s:4:"free"',
				'compare' => 'LIKE',
			),
		),
	) );

	if ( empty( $free_courses ) ) return;

	$names = array_map( function( $id ) {
		return '"' . esc_html( get_the_title( $id ) ) . '"';
	}, $free_courses );

	echo '<div class="notice notice-warning"><p>';
	echo '<strong>Little Sparks:</strong> The following published course(s) have price type set to <strong>Free</strong> — anyone can enrol without purchasing: ';
	echo implode( ', ', $names ) . '. ';
	echo 'Set price type to <strong>Closed</strong> and link a WooCommerce product unless this is intentional.';
	echo '</p></div>';
}

// ---------------------------------------------------------------------------
// Course completion.
// Fires when a learner completes all required steps in a course.
// Phase 5: redirect to certificate download or custom completion page.
// Phase 7: trigger bespoke completion email.
// ---------------------------------------------------------------------------
add_action( 'learndash_course_completed', 'ls_on_course_completed', 10, 1 );
function ls_on_course_completed( $data ) {
	/*
	 * $data['user']     — WP_User object
	 * $data['course']   — WP_Post (sfwd-courses)
	 * $data['progress'] — progress array
	 *
	 * Phase 5 TODO: add certificate redirect once artwork and cert template done.
	 * Example:
	 *   $cert_url = learndash_get_course_certificate_link( $data['course']->ID, $data['user']->ID );
	 *   if ( $cert_url ) wp_safe_redirect( $cert_url );
	 */
}

// ---------------------------------------------------------------------------
// Lesson / Topic completion.
// Stub — extend in Phase 5 if progress tracking or analytics needed.
// ---------------------------------------------------------------------------
add_action( 'learndash_lesson_completed', 'ls_on_lesson_completed', 10, 1 );
function ls_on_lesson_completed( $data ) {
	// $data['user'], $data['lesson'], $data['course'], $data['progress']
}

add_action( 'learndash_topic_completed', 'ls_on_topic_completed', 10, 1 );
function ls_on_topic_completed( $data ) {
	// $data['user'], $data['topic'], $data['lesson'], $data['course'], $data['progress']
}

// ---------------------------------------------------------------------------
// GrassBlade SCORM → LearnDash completion bridge (no LRS required).
// Hooks into the SCORM commit AJAX call at priority 1, before GrassBlade's
// handler exits. When SCORM reports completed/passed, finds the associated
// LD lesson and marks it complete — firing the full LD completion pipeline
// (course complete, certificate, etc.).
// ---------------------------------------------------------------------------
add_action( 'wp_ajax_grassblade_scorm_commit', 'ls_scorm_bridge_ld_complete', 1 );
function ls_scorm_bridge_ld_complete() {
	global $current_user;
	if ( empty( $current_user->ID ) ) return;
	if ( empty( $_REQUEST['params']['data'] ) || empty( $_REQUEST['params']['scorm_version'] ) ) return;

	$data       = $_REQUEST['params']['data']; // phpcs:ignore WordPress.Security.NonceVerification
	$scorm_ver  = sanitize_text_field( $_REQUEST['params']['scorm_version'] );

	// SCORM 1.2 uses cmi.core.lesson_status; SCORM 2004 uses cmi.completion_status.
	$status_key  = ( '1.2' === $scorm_ver ) ? 'cmi.core.lesson_status'  : 'cmi.completion_status';
	$content_key = ( '1.2' === $scorm_ver ) ? 'cmi.core.content_id'     : 'cmi.content_id';

	if ( empty( $data[ $status_key ] ) ) return;
	if ( ! in_array( $data[ $status_key ], array( 'completed', 'passed' ), true ) ) return;
	if ( empty( $data[ $content_key ] ) ) return;

	$content_id = (int) $data[ $content_key ];
	$user_id    = (int) $current_user->ID;

	// Guard: only fire once per content per user.
	$flag = 'ls_scorm_ld_completed_' . $content_id;
	if ( get_user_meta( $user_id, $flag, true ) ) return;

	// Find the LD lesson/topic that has this content attached.
	$lessons = get_posts( array(
		'post_type'      => array( 'sfwd-lessons', 'sfwd-topic' ),
		'post_status'    => 'publish',
		'numberposts'    => 1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array(
				'key'   => 'show_xapi_content',
				'value' => $content_id,
			),
		),
	) );

	if ( empty( $lessons ) ) return;

	$lesson_id = (int) $lessons[0];
	$course_id = function_exists( 'learndash_get_course_id' ) ? (int) learndash_get_course_id( $lesson_id ) : 0;
	if ( ! $course_id ) return;

	// Mark flag before firing to prevent race conditions.
	update_user_meta( $user_id, $flag, time() );

	// Fire LD completion pipeline: lesson → course → certificate.
	if ( function_exists( 'learndash_process_mark_complete' ) ) {
		learndash_process_mark_complete( $user_id, $lesson_id, false, $course_id );
	}
}

// ---------------------------------------------------------------------------
// GrassBlade SCORM launch page — inject branded back button.
// The grassblade_launch AJAX action outputs a bare HTML page (no theme).
// We buffer that output and inject a floating back link before </head>.
// Back URL: the lesson/topic page that has this content attached, else My Courses.
// ---------------------------------------------------------------------------
add_action( 'init', 'ls_scorm_launch_buffer', 1 );
function ls_scorm_launch_buffer() {
	if ( empty( $_REQUEST['action'] ) || 'grassblade_launch' !== $_REQUEST['action'] ) {
		return;
	}
	ob_start( 'ls_scorm_launch_inject_back' );
}

function ls_scorm_launch_inject_back( $html ) {
	$content_id = 0;
	if ( ! empty( $_REQUEST['content_id'] ) ) {
		$raw = $_REQUEST['content_id'];
		$content_id = is_numeric( $raw ) ? (int) $raw : (int) base64_decode( $raw );
	}

	// Find the LD lesson/topic this content is attached to.
	$back_url = home_url( '/my-account/my-courses/' );
	if ( $content_id ) {
		$attached = get_posts( array(
			'post_type'      => array( 'sfwd-lessons', 'sfwd-topic' ),
			'post_status'    => 'publish',
			'numberposts'    => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'   => 'show_xapi_content',
					'value' => $content_id,
				),
			),
		) );
		if ( ! empty( $attached ) ) {
			$back_url = get_permalink( $attached[0] );
		}
	}

	$btn = '<style>
#ls-scorm-nav{position:fixed;top:14px;left:14px;z-index:99999;font-family:-apple-system,BlinkMacSystemFont,"DM Sans",sans-serif;}
#ls-scorm-nav a{display:inline-flex;align-items:center;gap:6px;background:#9439d4;color:#fff;text-decoration:none;padding:9px 20px;border-radius:20px;font-weight:700;font-size:13px;box-shadow:0 2px 12px rgba(0,0,0,0.22);transition:background .15s;}
#ls-scorm-nav a:hover{background:#7a2db5;}
</style>
<div id="ls-scorm-nav">
<a href="' . esc_url( $back_url ) . '">
<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
Back to Course
</a>
</div>';

	return str_replace( '</head>', $btn . "\n</head>", $html );
}

// ---------------------------------------------------------------------------
// Hide LD "Mark Complete" button on lessons that have SCORM attached.
// Completion is handled automatically by ls_scorm_bridge_ld_complete().
// ---------------------------------------------------------------------------
add_filter( 'learndash_mark_complete', 'ls_hide_mark_complete_on_scorm', 10, 2 );
function ls_hide_mark_complete_on_scorm( $html, $post ) {
	if ( empty( $post->ID ) ) return $html;
	$xapi_content_id = get_post_meta( $post->ID, 'show_xapi_content', true );
	if ( $xapi_content_id ) return ''; // Has SCORM — button suppressed, completion fires via ls_scorm_bridge_ld_complete().
	return $html;
}

// ---------------------------------------------------------------------------
// Dequeue LD assets that conflict with child theme.
// Phase 5: identify actual conflicts when styling course pages.
// Uncomment as needed after visual QA on course / lesson / topic pages.
// ---------------------------------------------------------------------------
add_action( 'wp_enqueue_scripts', 'ls_dequeue_ld_assets', 99 );
function ls_dequeue_ld_assets() {
	if ( ! function_exists( 'learndash_is_course_page' ) ) return;

	// wp_dequeue_style( 'learndash-front' );   // main LD front-end CSS
	// wp_dequeue_style( 'sfwd-module-css' );   // module styles
}
