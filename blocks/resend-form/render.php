<?php
/**
 * Server-side render for hm-user-activation/resend-form.
 *
 * Variables available from block context:
 *  $attributes  - Block attributes.
 *  $content     - Inner blocks HTML (unused — self-closing block).
 *  $block       - WP_Block instance.
 */

use HM\UserActivation\Activation;
use HM\UserActivation\Resend;

// Don't offer a new link after a successful activation.
if ( Activation\is_success() ) {
	return;
}

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'hm-resend-form' ] );

// Post back to the page without the old key in the URL.
$action = Resend\get_page_url();
?>
<div <?php echo $wrapper_attributes; ?>>
	<form method="post" action="<?php echo esc_url( $action ); ?>">
		<?php wp_nonce_field( 'hm_activation_resend', '_hm_resend_nonce' ); ?>

		<p class="hm-resend-form__field">
			<label class="hm-resend-form__label" for="hm-resend-email">
				<?php esc_html_e( 'Email address', 'hm-user-activation' ); ?>
			</label>
			<input
				type="email"
				id="hm-resend-email"
				name="resend_email"
				class="hm-resend-form__input"
				required
				autocomplete="email"
			>
		</p>

		<div class="hm-resend-form__submit wp-block-button">
			<button type="submit" class="hm-resend-form__button wp-block-button__button wp-element-button">
				<?php esc_html_e( 'Send a new activation link', 'hm-user-activation' ); ?>
			</button>
		</div>
	</form>
</div>
