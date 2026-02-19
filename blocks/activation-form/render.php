<?php
/**
 * Server-side render for hm-user-activation/form.
 *
 * Variables available from block context:
 *  $attributes  - Block attributes.
 *  $content     - Inner blocks HTML (unused — self-closing block).
 *  $block       - WP_Block instance.
 */

use HM\UserActivation\Activation;

// Key in the URL means activation is handled automatically — no form needed.
if ( isset( $_GET['key'] ) ) {
	return;
}

// Don't show the form after a successful activation.
if ( Activation::is_success() ) {
	return;
}

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'hm-activation-form' ] );
?>
<div <?php echo $wrapper_attributes; ?>>
	<form method="post" action="">
		<?php wp_nonce_field( 'hm_activation', '_hm_activation_nonce' ); ?>

		<p class="hm-activation-form__field">
			<label class="hm-activation-form__label" for="hm-activation-key">
				<?php esc_html_e( 'Activation key', 'hm-user-activation' ); ?>
			</label>
			<input
				type="text"
				id="hm-activation-key"
				name="activation_key"
				class="hm-activation-form__input"
				required
				autocomplete="off"
				placeholder="<?php esc_attr_e( 'Paste your activation key here', 'hm-user-activation' ); ?>"
			>
		</p>

		<div class="hm-activation-form__submit wp-block-button">
			<button type="submit" class="hm-activation-form__button wp-block-button__button wp-element-button">
				<?php esc_html_e( 'Activate account', 'hm-user-activation' ); ?>
			</button>
		</div>
	</form>
</div>
