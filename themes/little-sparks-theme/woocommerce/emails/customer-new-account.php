<?php
/**
 * Customer new account email — Little Sparks override.
 * Based on WooCommerce template v10.9.0.
 *
 * @package little-sparks-theme
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

$email_improvements_enabled = FeaturesUtil::feature_is_enabled( 'email_improvements' );

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<?php
$_greeting_name = ! empty( $user_display_name ) ? $user_display_name : ( ! empty( $user_login ) ? $user_login : esc_html__( 'there', 'little-sparks' ) );
/* translators: %s: Customer first name or username */
?>
<p><?php printf( esc_html__( 'Hi %s,', 'woocommerce' ), esc_html( $_greeting_name ) ); ?></p>
<p><?php esc_html_e( 'Welcome to Little Sparks eLearning. Your account is set up and ready to go.', 'little-sparks' ); ?></p>

<?php if ( $email_improvements_enabled ) : ?>
	<div class="hr hr-top"></div>
	<?php /* translators: %s: Username */ ?>
	<p><?php echo wp_kses( sprintf( __( 'Username: <b>%s</b>', 'woocommerce' ), esc_html( $user_login ) ), array( 'b' => array() ) ); ?></p>
	<?php if ( $password_generated && $set_password_url ) : ?>
		<p>
			<a href="<?php echo esc_attr( $set_password_url ); ?>">
				<?php esc_html_e( 'Set your password', 'woocommerce' ); ?>
			</a>
		</p>
	<?php endif; ?>
	<div class="hr hr-bottom"></div>
<?php else : ?>
	<?php if ( $password_generated && $set_password_url ) : ?>
		<p>
			<a href="<?php echo esc_attr( $set_password_url ); ?>">
				<?php esc_html_e( 'Click here to set your password.', 'woocommerce' ); ?>
			</a>
		</p>
	<?php endif; ?>
<?php endif; ?>

<p style="margin: 24px 0;">
	<a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>"
	   style="background:#9439d4;color:#ffffff;text-decoration:none;font-weight:700;padding:14px 28px;border-radius:100px;display:inline-block;">
		<?php esc_html_e( 'Go to My Account', 'little-sparks' ); ?>
	</a>
</p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
if ( $additional_content ) {
	echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content email-additional-content-aligned">' : '';
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
	echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

do_action( 'woocommerce_email_footer', $email );
