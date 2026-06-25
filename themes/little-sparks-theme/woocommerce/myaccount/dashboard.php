<?php
defined( 'ABSPATH' ) || exit;

$user       = wp_get_current_user();
$first_name = $user->first_name ?: $user->display_name;

$course_ids      = array();
$completed_count = 0;

if ( function_exists( 'learndash_user_get_enrolled_courses' ) ) {
	$course_ids = learndash_user_get_enrolled_courses( $user->ID );
}

$course_count = count( $course_ids );

if ( $course_count > 0 ) {
	$progress_meta = get_user_meta( $user->ID, '_sfwd-course_progress', true );
	if ( is_array( $progress_meta ) ) {
		foreach ( $course_ids as $cid ) {
			$cp    = isset( $progress_meta[ $cid ] ) ? $progress_meta[ $cid ] : array();
			$total = isset( $cp['total'] ) ? (int) $cp['total'] : 0;
			$done  = isset( $cp['completed'] ) ? (int) $cp['completed'] : 0;
			if ( $total > 0 && $done >= $total ) {
				$completed_count++;
			}
		}
	}
}
?>
<div class="ls-account-dashboard">
	<h2 class="ls-account-welcome"><?php echo 'Welcome back, ' . esc_html( $first_name ) . '!'; ?></h2>

	<div class="ls-account-stats">
		<div class="ls-account-stat">
			<span class="ls-account-stat-num"><?php echo (int) $course_count; ?></span>
			<span class="ls-account-stat-label"><?php echo esc_html( _n( 'Course enrolled', 'Courses enrolled', $course_count, 'little-sparks' ) ); ?></span>
		</div>
		<div class="ls-account-stat">
			<span class="ls-account-stat-num"><?php echo (int) $completed_count; ?></span>
			<span class="ls-account-stat-label"><?php echo esc_html( _n( 'Course completed', 'Courses completed', $completed_count, 'little-sparks' ) ); ?></span>
		</div>
	</div>

	<div class="ls-account-dashboard-cta">
		<?php if ( $course_count > 0 ) : ?>
			<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'my-courses' ) ); ?>" class="ls-btn ls-btn-primary"><?php esc_html_e( 'Go to My Courses', 'little-sparks' ); ?></a>
		<?php else : ?>
			<p><?php esc_html_e( 'Ready to start learning? Browse our available courses.', 'little-sparks' ); ?></p>
			<a href="<?php echo esc_url( home_url( '/courses/' ) ); ?>" class="ls-btn ls-btn-primary"><?php esc_html_e( 'Browse Courses', 'little-sparks' ); ?></a>
		<?php endif; ?>
	</div>
</div>

<?php do_action( 'woocommerce_account_dashboard' ); ?>
