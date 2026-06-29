<?php
/**
 * Customer completed order email — Little Sparks override.
 * Based on WooCommerce template v10.4.0.
 *
 * @package little-sparks-theme
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if ( ! empty( $order->get_billing_first_name() ) ) {
	/* translators: %s: Customer first name */
	printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $order->get_billing_first_name() ) );
} else {
	echo esc_html__( 'Hi,', 'woocommerce' );
}
?>
</p>
<p><?php esc_html_e( 'Your payment is confirmed and your course access is ready. You can start learning straight away.', 'little-sparks' ); ?></p>

<p style="margin: 24px 0;">
	<a href="<?php echo esc_url( wc_get_account_endpoint_url( 'my-courses' ) ); ?>"
	   style="background:#9439d4;color:#ffffff;text-decoration:none;font-weight:700;padding:14px 28px;border-radius:100px;display:inline-block;">
		<?php esc_html_e( 'Go to My Courses', 'little-sparks' ); ?>
	</a>
</p>

<p><?php esc_html_e( 'Your certificate will be available in your My Courses area once you complete the course.', 'little-sparks' ); ?></p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
