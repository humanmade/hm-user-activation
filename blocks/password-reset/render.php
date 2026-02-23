<?php
/**
 * Server-side render for hm-user-activation/password-reset.
 *
 * Shows one of two forms depending on whether a reset key is present in the URL:
 *
 *  - No key: "Request reset link" form — user enters username or email.
 *  - key + login present: "Set new password" form — user enters and confirms
 *    a new password. The key and login are carried as hidden fields.
 *
 * The block hides itself on success; the group variations on the page handle
 * displaying the success messages.
 */

use HM\UserActivation\PasswordReset;

// Hide the form once an action has succeeded — group variations take over.
if ( PasswordReset\is_success() ) {
	return;
}

$key   = sanitize_text_field( wp_unslash( $_GET['key']   ?? '' ) );
$login = sanitize_text_field( wp_unslash( $_GET['login'] ?? '' ) );

$is_reset_mode = $key && $login;

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'hm-password-reset' ] );
?>
<div <?php echo $wrapper_attributes; ?>>
<?php if ( $is_reset_mode ) : ?>

	<form method="post" action="" class="hm-password-reset__form">
		<?php wp_nonce_field( 'hm_reset', '_hm_reset_nonce' ); ?>
		<input type="hidden" name="rp_key"   value="<?php echo esc_attr( $key ); ?>">
		<input type="hidden" name="rp_login" value="<?php echo esc_attr( $login ); ?>">

		<p class="hm-password-reset__field">
			<label class="hm-password-reset__label" for="hm-pass1">
				<?php esc_html_e( 'New password', 'hm-user-activation' ); ?>
			</label>
			<input
				type="password"
				id="hm-pass1"
				name="pass1"
				class="hm-password-reset__input"
				required
				autocomplete="new-password"
			>
		</p>

		<p class="hm-password-reset__field">
			<label class="hm-password-reset__label" for="hm-pass2">
				<?php esc_html_e( 'Confirm new password', 'hm-user-activation' ); ?>
			</label>
			<input
				type="password"
				id="hm-pass2"
				name="pass2"
				class="hm-password-reset__input"
				required
				autocomplete="new-password"
			>
		</p>

		<div class="hm-password-reset__submit wp-block-button">
			<button type="submit" class="hm-password-reset__button wp-block-button__button wp-element-button">
				<?php esc_html_e( 'Set password', 'hm-user-activation' ); ?>
			</button>
		</div>
	</form>

<?php else : ?>

	<form method="post" action="" class="hm-password-reset__form">
		<?php wp_nonce_field( 'hm_reset_request', '_hm_reset_request_nonce' ); ?>

		<p class="hm-password-reset__field">
			<label class="hm-password-reset__label" for="hm-user-login">
				<?php esc_html_e( 'Username or email address', 'hm-user-activation' ); ?>
			</label>
			<input
				type="text"
				id="hm-user-login"
				name="user_login"
				class="hm-password-reset__input"
				required
				autocomplete="username"
			>
		</p>

		<div class="hm-password-reset__submit wp-block-button">
			<button type="submit" class="hm-password-reset__button wp-block-button__button wp-element-button">
				<?php esc_html_e( 'Get new password', 'hm-user-activation' ); ?>
			</button>
		</div>
	</form>

<?php endif; ?>
</div>
