<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ---------------------------------------------------------------------------
// Register My Courses rewrite endpoint and tell WC it's a valid query var.
// Flush rewrite rules by visiting Settings > Permalinks after first deploy.
// ---------------------------------------------------------------------------
add_action( 'init', 'ls_register_my_courses_endpoint' );
function ls_register_my_courses_endpoint() {
	add_rewrite_endpoint( 'my-courses', EP_ROOT | EP_PAGES );
}

add_filter( 'woocommerce_get_query_vars', 'ls_my_account_query_vars' );
function ls_my_account_query_vars( $vars ) {
	$vars['my-courses'] = 'my-courses';
	return $vars;
}

// ---------------------------------------------------------------------------
// My Account navigation — insert My Courses tab, drop irrelevant items.
// Addresses removed: digital-only platform, no shipping address needed.
// Payment methods removed: Stripe not yet connected.
// ---------------------------------------------------------------------------
add_filter( 'woocommerce_account_menu_items', 'ls_my_account_menu_items' );
function ls_my_account_menu_items( $items ) {
	unset( $items['edit-address'] );
	unset( $items['payment-methods'] );

	$reordered = array();
	foreach ( $items as $key => $label ) {
		$reordered[ $key ] = $label;
		if ( 'dashboard' === $key ) {
			$reordered['my-courses'] = __( 'My Courses', 'little-sparks' );
		}
	}
	return $reordered;
}

// ---------------------------------------------------------------------------
// My Courses endpoint content.
// ---------------------------------------------------------------------------
add_action( 'woocommerce_account_my-courses_endpoint', 'ls_my_account_courses' );
function ls_my_account_courses() {
	$user_id = get_current_user_id();

	if ( ! function_exists( 'learndash_user_get_enrolled_courses' ) ) {
		echo '<p>' . esc_html__( 'Course data is unavailable.', 'little-sparks' ) . '</p>';
		return;
	}

	$course_ids = learndash_user_get_enrolled_courses( $user_id );

	echo '<div class="ls-my-courses">';

	if ( empty( $course_ids ) ) {
		echo '<div class="ls-my-courses-empty">';
		echo '<p>' . esc_html__( 'You haven\'t enrolled in any courses yet.', 'little-sparks' ) . '</p>';
		echo '<a href="' . esc_url( home_url( '/courses/' ) ) . '" class="ls-btn ls-btn-primary">' . esc_html__( 'Browse Courses', 'little-sparks' ) . '</a>';
		echo '</div>';
	} else {
		echo '<div class="ls-my-courses-grid">';
		foreach ( $course_ids as $course_id ) {
			$course = get_post( $course_id );
			if ( ! $course || 'publish' !== $course->post_status ) {
				continue;
			}
			ls_render_my_course_card( $course_id, $user_id );
		}
		echo '</div>';
	}

	echo '</div>';
}

// ---------------------------------------------------------------------------
// Render a single enrolled course card.
// Progress read directly from LD user meta for reliability.
// ---------------------------------------------------------------------------
function ls_render_my_course_card( $course_id, $user_id ) {
	$progress_meta   = get_user_meta( $user_id, '_sfwd-course_progress', true );
	$course_progress = ( is_array( $progress_meta ) && isset( $progress_meta[ $course_id ] ) ) ? $progress_meta[ $course_id ] : array();
	$completed       = isset( $course_progress['completed'] ) ? (int) $course_progress['completed'] : 0;
	$total           = isset( $course_progress['total'] ) ? (int) $course_progress['total'] : 0;
	$percent         = ( $total > 0 ) ? (int) round( ( $completed / $total ) * 100 ) : 0;

	// SCORM fallback: LD doesn't track GrassBlade progress, so read it directly.
	$scorm_percent     = 0;
	$scorm_total_mods  = 0;
	$scorm_done_mods   = 0;
	if ( function_exists( 'ls_get_grassblade_progress' ) ) {
		$lesson_ids = get_posts( array(
			'post_type'   => 'sfwd-lessons',
			'numberposts' => -1,
			'fields'      => 'ids',
			'meta_query'  => array( array(
				'key'   => 'lesson_course_id',
				'value' => $course_id,
			) ),
		) );
		foreach ( $lesson_ids as $lid ) {
			$xapi_id = (int) get_post_meta( $lid, 'show_xapi_content', true );
			if ( ! $xapi_id ) continue;
			$pct = ls_get_grassblade_progress( $xapi_id, $user_id );
			if ( $pct > $scorm_percent ) {
				$scorm_percent = $pct;
				$outline       = json_decode( get_post_meta( $xapi_id, '_ls_scorm_outline', true ), true );
				$scorm_total_mods = is_array( $outline ) ? count( $outline ) : 0;
				$scorm_done_mods  = $scorm_total_mods > 0 ? (int) round( $pct / 100 * $scorm_total_mods ) : 0;
			}
		}
	}

	// Use SCORM data when LD has no progress recorded.
	if ( 0 === $percent && $scorm_percent > 0 ) {
		$percent   = $scorm_percent;
		$completed = $scorm_done_mods;
		$total     = $scorm_total_mods;
	}

	$thumb_id   = get_post_thumbnail_id( $course_id );
	$course_url = get_permalink( $course_id );
	$title      = get_the_title( $course_id );

	if ( 100 === $percent ) {
		$status_label = 'Completed';
		$status_class = 'ls-status-complete';
	} elseif ( $percent > 0 ) {
		$status_label = $percent . '% complete';
		$status_class = 'ls-status-progress';
	} else {
		$status_label = 'Not started';
		$status_class = 'ls-status-new';
	}

	$cert_url = '';
	if ( 100 === $percent && function_exists( 'learndash_get_course_certificate_link' ) ) {
		$cert_url = learndash_get_course_certificate_link( $course_id, $user_id );
	}

	if ( 100 === $percent ) {
		$cta_label = 'View Course';
	} elseif ( $percent > 0 ) {
		$cta_label = 'Continue Learning';
	} else {
		$cta_label = 'Start Learning';
	}
	?>
	<div class="ls-course-card">
		<?php if ( $thumb_id ) : ?>
		<a href="<?php echo esc_url( $course_url ); ?>" class="ls-course-card-img-wrap" tabindex="-1" aria-hidden="true">
			<?php echo wp_get_attachment_image( $thumb_id, 'medium', false, array( 'loading' => 'lazy', 'alt' => '' ) ); ?>
		</a>
		<?php endif; ?>
		<div class="ls-course-card-body">
			<span class="ls-course-card-status <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
			<h3 class="ls-course-card-title">
				<a href="<?php echo esc_url( $course_url ); ?>"><?php echo esc_html( $title ); ?></a>
			</h3>
			<div class="ls-course-card-progress" role="progressbar" aria-valuenow="<?php echo esc_attr( $percent ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php echo esc_attr( $percent . '% of course completed' ); ?>">
				<div class="ls-course-card-progress-fill" style="width:<?php echo esc_attr( $percent ); ?>%"></div>
			</div>
			<?php if ( $total > 0 ) : ?>
			<p class="ls-course-card-steps"><?php echo esc_html( $completed . ' of ' . $total . ' modules completed' ); ?></p>
			<?php endif; ?>
			<div class="ls-course-card-actions">
				<?php if ( $cert_url ) : ?>
				<a href="<?php echo esc_url( $cert_url ); ?>" class="ls-btn ls-btn-secondary" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Download Certificate', 'little-sparks' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( $course_url ); ?>" class="ls-btn ls-btn-primary"><?php echo esc_html( $cta_label ); ?></a>
			</div>
		</div>
	</div>
	<?php
}
