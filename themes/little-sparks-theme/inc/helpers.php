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

function ls_format_duration( $seconds ) {
	$seconds = absint( $seconds );
	if ( ! $seconds ) {
		return '';
	}
	$hours   = floor( $seconds / HOUR_IN_SECONDS );
	$minutes = floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
	$parts   = array();
	if ( $hours )   $parts[] = $hours . 'h';
	if ( $minutes ) $parts[] = $minutes . 'm';
	return implode( ' ', $parts );
}

function ls_hero_course_shortcode() {
	if ( ! ls_wc_active() ) {
		return '';
	}

	$query = new WP_Query( array(
		'post_type'      => 'product',
		'posts_per_page' => 1,
		'post_status'    => 'publish',
		'tax_query'      => array(
			array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => 'featured',
			),
		),
	) );

	if ( ! $query->have_posts() ) {
		return '';
	}

	$query->the_post();

	$product  = wc_get_product( get_the_ID() );
	$title    = get_the_title();
	$url      = get_permalink();
	$duration = ls_format_duration( get_post_meta( get_the_ID(), '_learndash_course_grid_duration', true ) );
	$rating   = get_field( 'course_rating' ) ?: '5.0';
	$thumb_id = get_post_thumbnail_id();

	if ( $product->is_on_sale() ) {
		$price_display = wp_kses_post( wc_price( $product->get_sale_price() ) ) . ' <span class="price-was">' . wp_kses_post( wc_price( $product->get_regular_price() ) ) . '</span>';
	} else {
		$price_display = wp_kses_post( wc_price( $product->get_regular_price() ) );
	}

	ob_start();
	?>
	<?php if ( $duration ) : ?>
	<div class="float-module">
		<div class="module-icon">
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
				<polygon points="5 3 19 12 5 21 5 3" fill="white"></polygon>
			</svg>
		</div>
		<span class="module-text"><?php echo esc_html( $duration ); ?></span>
	</div>
	<?php endif; ?>
	<a href="<?php echo esc_url( $url ); ?>" class="main-card">
		<?php if ( $thumb_id ) : ?>
		<div class="main-img-wrapper">
			<?php echo wp_get_attachment_image( $thumb_id, 'medium_large', false, array( 'class' => 'main-img', 'loading' => 'eager' ) ); ?>
		</div>
		<?php endif; ?>
		<div class="card-header">
			<h3 class="card-title"><?php echo esc_html( $title ); ?></h3>
			<div class="card-meta-row">
				<p class="card-subtitle"><?php echo esc_html( $duration ); ?></p>
				<div class="card-rating">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="var(--clr-accent)" stroke="var(--clr-accent)" stroke-width="2" aria-hidden="true" focusable="false"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
					<?php echo esc_html( number_format( (float) $rating, 1 ) ); ?>
				</div>
			</div>
		</div>
		<div class="card-footer">
			<div class="price"><?php echo $price_display; ?></div>
		</div>
	</a>
	<?php
	wp_reset_postdata();
	return ob_get_clean();
}
add_shortcode( 'ls_hero_course', 'ls_hero_course_shortcode' );

function ls_courses_grid_shortcode() {
	if ( ! ls_wc_active() ) {
		return '';
	}

	$query = new WP_Query( array(
		'post_type'      => 'product',
		'posts_per_page' => 3,
		'post_status'    => 'publish',
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
	) );

	if ( ! $query->have_posts() ) {
		return '';
	}

	$tilt_classes = array( 'card-tilt-left', '', 'card-tilt-right' );
	$index        = 0;

	ob_start();

	while ( $query->have_posts() ) {
		$query->the_post();

		$product   = wc_get_product( get_the_ID() );
		$title     = get_the_title();
		$url       = get_permalink();
		$excerpt   = get_the_excerpt();
		$thumb_id  = get_post_thumbnail_id();
		$duration  = ls_format_duration( get_post_meta( get_the_ID(), '_learndash_course_grid_duration', true ) );
		$level     = get_field( 'course_level' ) ?: '';
		$rating    = get_field( 'course_rating' ) ?: '5.0';
		$icon      = get_field( 'course_icon' );
		$card_num  = $index + 1;
		$tilt      = isset( $tilt_classes[ $index ] ) ? $tilt_classes[ $index ] : '';

		$terms    = get_the_terms( get_the_ID(), 'product_cat' );
		$cat_name = '';
		if ( $terms && ! is_wp_error( $terms ) ) {
			$excluded = array( 'uncategorized', 'uncategorised' );
			foreach ( $terms as $term ) {
				if ( ! in_array( $term->slug, $excluded, true ) ) {
					$cat_name = $term->name;
					break;
				}
			}
		}

		if ( $product->is_on_sale() ) {
			$price_html = wp_kses_post( wc_price( $product->get_sale_price() ) ) . ' <span class="price-was">' . wp_kses_post( wc_price( $product->get_regular_price() ) ) . '</span>';
		} else {
			$price_html = wp_kses_post( wc_price( $product->get_regular_price() ) );
		}

		?>
		<article class="course-card course-card-<?php echo esc_attr( $card_num ); ?> <?php echo esc_attr( $tilt ); ?>">
			<a href="<?php echo esc_url( $url ); ?>" class="course-card-inner" aria-label="<?php echo esc_attr( $title ); ?>">
				<div class="course-visual">
					<?php if ( $thumb_id ) : ?>
					<div class="course-image-wrap">
						<?php echo wp_get_attachment_image( $thumb_id, 'medium', false, array( 'class' => 'course-img', 'loading' => 'lazy' ) ); ?>
					</div>
					<?php endif; ?>
					<?php if ( $duration ) : ?>
					<div class="course-badge<?php echo ( 0 === $index % 2 ) ? '' : ' badge-purple'; ?>">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><polygon points="5 3 19 12 5 21 5 3" fill="currentColor"></polygon></svg>
						<?php echo esc_html( $duration ); ?>
					</div>
					<?php endif; ?>
					<?php if ( $icon && ! empty( $icon['url'] ) ) : ?>
					<div class="course-float-icon<?php echo 1 !== $card_num ? ' icon-bounce' : ''; ?>">
						<img src="<?php echo esc_url( $icon['url'] ); ?>" alt="<?php echo esc_attr( $icon['alt'] ); ?>" width="28" height="28" loading="lazy">
					</div>
					<?php endif; ?>
				</div>
				<div class="course-content">
					<div class="course-meta">
						<?php if ( $cat_name ) : ?>
						<span class="course-tag"><?php echo esc_html( $cat_name ); ?></span>
						<?php endif; ?>
						<?php if ( $level ) : ?>
						<span class="course-level"><?php echo esc_html( $level ); ?></span>
						<?php endif; ?>
					</div>
					<h3 class="course-title"><?php echo esc_html( $title ); ?></h3>
					<?php if ( $excerpt ) : ?>
					<p class="course-desc"><?php echo esc_html( $excerpt ); ?></p>
					<?php endif; ?>
					<div class="course-footer">
						<div class="course-rating">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
							<span><?php echo esc_html( number_format( (float) $rating, 1 ) ); ?></span>
						</div>
						<div class="course-price"><?php echo $price_html; ?></div>
					</div>
				</div>
			</a>
		</article>
		<?php

		$index++;
	}

	wp_reset_postdata();
	return ob_get_clean();
}
add_shortcode( 'ls_courses_grid', 'ls_courses_grid_shortcode' );

function ls_testimonials_grid_shortcode() {
	$query = new WP_Query( array(
		'post_type'      => 'testimonials',
		'posts_per_page' => 3,
		'post_status'    => 'publish',
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
	) );

	if ( ! $query->have_posts() ) {
		return '';
	}

	$tilt_classes  = array( 'card-tilt-left', '', 'card-tilt-right' );
	$float_icons   = array(
		'<svg width="20" height="20" viewBox="0 0 24 24" fill="#f5ac39" aria-hidden="true" focusable="false"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>',
		'<svg width="18" height="18" viewBox="0 0 24 24" fill="#9439d4" aria-hidden="true" focusable="false"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>',
		'<svg width="16" height="16" viewBox="0 0 24 24" fill="#FEF0D1" aria-hidden="true" focusable="false"><path d="M12 2L14.4 9.6L22 12L14.4 14.4L12 22L9.6 14.4L2 12L9.6 9.6L12 2Z"></path></svg>',
	);
	$float_classes = array( 'floating-star', 'floating-heart', 'floating-sparkle' );
	$grad_classes  = array( 'avatar-grad-1', 'avatar-grad-2', 'avatar-grad-3' );

	$index = 0;

	ob_start();

	while ( $query->have_posts() ) {
		$query->the_post();

		$quote        = get_field( 'testimonial_quote' );
		$name         = get_field( 'testimonial_name' );
		$role         = get_field( 'testimonial_role' );
		$organisation = get_field( 'testimonial_organisation' );
		$photo        = get_field( 'testimonial_photo' );
		$tilt         = isset( $tilt_classes[ $index ] ) ? $tilt_classes[ $index ] : '';
		$float_class  = isset( $float_classes[ $index ] ) ? $float_classes[ $index ] : 'floating-star';
		$float_icon   = isset( $float_icons[ $index ] ) ? $float_icons[ $index ] : $float_icons[0];
		$grad_class   = isset( $grad_classes[ $index ] ) ? $grad_classes[ $index ] : 'avatar-grad-1';
		$initial      = $name ? esc_html( mb_substr( $name, 0, 1 ) ) : '';
		$byline       = array_filter( array( $role, $organisation ) );

		?>
		<article class="testimonial-card <?php echo esc_attr( $tilt ); ?>">
			<div class="testimonial-quote-mark">&ldquo;</div>
			<?php if ( $quote ) : ?>
			<p class="testimonial-text"><?php echo esc_html( $quote ); ?></p>
			<?php endif; ?>
			<div class="testimonial-author">
				<?php if ( $photo && ! empty( $photo['url'] ) ) : ?>
					<img
						src="<?php echo esc_url( $photo['url'] ); ?>"
						alt="<?php echo esc_attr( $photo['alt'] ?: $name ); ?>"
						width="<?php echo esc_attr( $photo['width'] ); ?>"
						height="<?php echo esc_attr( $photo['height'] ); ?>"
						loading="lazy"
						class="author-avatar author-avatar-img"
					>
				<?php else : ?>
					<div class="author-avatar <?php echo esc_attr( $grad_class ); ?>"><?php echo $initial; ?></div>
				<?php endif; ?>
				<div class="author-info">
					<strong><?php echo esc_html( $name ); ?></strong>
					<?php if ( $byline ) : ?>
					<span><?php echo esc_html( implode( ', ', $byline ) ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<div class="<?php echo esc_attr( $float_class ); ?>"><?php echo $float_icon; ?></div>
		</article>
		<?php

		$index++;
	}

	wp_reset_postdata();
	return ob_get_clean();
}
add_shortcode( 'ls_testimonials_grid', 'ls_testimonials_grid_shortcode' );

function ls_header_actions_shortcode() {
	$account_page_id = get_option( 'woocommerce_myaccount_page_id' );
	$account_url     = $account_page_id ? get_permalink( $account_page_id ) : wp_login_url();

	if ( is_user_logged_in() ) {
		$login_label = __( 'My Account', 'little-sparks-theme' );
	} else {
		$login_label = __( 'Login', 'little-sparks-theme' );
	}

	$cart_count = ( ls_wc_active() && WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
	$cart_url   = ls_wc_active() ? wc_get_cart_url() : '#';

	ob_start();
	?>
	<div class="header-actions">
		<a href="<?php echo esc_url( $account_url ); ?>" class="login-btn"><?php echo esc_html( $login_label ); ?></a>
		<a href="<?php echo esc_url( $cart_url ); ?>" class="cart-link" aria-label="<?php echo esc_attr( sprintf( _n( 'View cart — %d item', 'View cart — %d items', $cart_count, 'little-sparks-theme' ), $cart_count ) ); ?>">
			<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--clr-text-main)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
				<path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path>
				<line x1="3" y1="6" x2="21" y2="6"></line>
				<path d="M16 10a4 4 0 0 1-8 0"></path>
			</svg>
			<?php if ( $cart_count > 0 ) : ?>
			<span class="cart-badge"><?php echo esc_html( $cart_count ); ?></span>
			<?php endif; ?>
		</a>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'ls_header_actions', 'ls_header_actions_shortcode' );

function ls_nav_account_link_shortcode() {
	$account_page_id = get_option( 'woocommerce_myaccount_page_id' );
	$account_url     = $account_page_id ? get_permalink( $account_page_id ) : wp_login_url();
	$label           = is_user_logged_in() ? __( 'My Account', 'little-sparks-theme' ) : __( 'Login', 'little-sparks-theme' );
	return '<a href="' . esc_url( $account_url ) . '">' . esc_html( $label ) . '</a>';
}
add_shortcode( 'ls_nav_account_link', 'ls_nav_account_link_shortcode' );

function ls_copyright_shortcode() {
	return '&copy; ' . esc_html( gmdate( 'Y' ) ) . ' Little Sparks eLearning';
}
add_shortcode( 'ls_copyright', 'ls_copyright_shortcode' );
