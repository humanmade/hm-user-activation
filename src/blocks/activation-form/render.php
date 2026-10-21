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
use HM\UserActivation\CompleteAccount;
use HM\UserActivation\Resend;

// A key arriving in the URL is stashed in a cookie and processed automatically,
// so the only reason to render the form is that no key has been accepted yet.
// Don't show the form after a successful activation.
if ( Activation\is_success() ) {
	return;
}

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'hm-activation-form' ] );

// "Complete your account" mode with a usable key: the user chooses a password
// and fills in any fields the site adds before the account is activated.
$complete = CompleteAccount\state();
if ( $complete ) :
	$signup = $complete['signup'];
	?>
<div <?php echo $wrapper_attributes; // phpcs:ignore HM.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped attributes ?>>
	<form method="post" action="<?php echo esc_url( remove_query_arg( [ 'key', Resend\QUERY_ARG ] ) ); ?>" class="hm-activation-form__complete">
		<?php wp_nonce_field( 'hm_activation_complete', '_hm_complete_nonce' ); ?>
		<input type="hidden" name="activation_key" value="<?php echo esc_attr( $complete['key'] ); ?>">

		<?php if ( $complete['errors'] ) : ?>
			<div class="hm-activation-form__errors" role="alert">
				<?php foreach ( $complete['errors'] as $message ) : ?>
					<p><?php echo esc_html( $message ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<p class="hm-activation-form__field">
			<label class="hm-activation-form__label" for="hm-activation-email">
				<?php esc_html_e( 'Email address', 'hm-user-activation' ); ?>
			</label>
			<input
				type="email"
				id="hm-activation-email"
				class="hm-activation-form__input"
				value="<?php echo esc_attr( $signup->user_email ); ?>"
				autocomplete="username"
				readonly
			>
		</p>

		<p class="hm-activation-form__field">
			<label class="hm-activation-form__label" for="hm-activation-pass1">
				<?php esc_html_e( 'Password', 'hm-user-activation' ); ?>
			</label>
			<input
				type="password"
				id="hm-activation-pass1"
				name="pass1"
				class="hm-activation-form__input"
				required
				autocomplete="new-password"
			>
		</p>

		<p class="hm-activation-form__field">
			<label class="hm-activation-form__label" for="hm-activation-pass2">
				<?php esc_html_e( 'Confirm password', 'hm-user-activation' ); ?>
			</label>
			<input
				type="password"
				id="hm-activation-pass2"
				name="pass2"
				class="hm-activation-form__input"
				required
				autocomplete="new-password"
			>
		</p>

		<?php
		// Plugins that add fields to wp-login.php?action=register add them here
		// too, and fill them in again from $_POST after an error.

		/** This action is documented in wp-login.php */
		do_action( 'register_form' );

		/**
		 * Fires inside the completion form, before the submit button, so a site can add its own fields.
		 *
		 * Validate them with hm_user_activation_complete_account_errors and save
		 * them on hm_user_activation_complete_account. Escape anything printed.
		 *
		 * @param object $signup Signup row. It includes the activation key, which is also in the form.
		 * @param array  $meta   Signup meta.
		 * @param array  $fields Fields posted with a form that had errors, unslashed and not sanitised, so they can be filled in again. Empty on first view.
		 */
		do_action( 'hm_user_activation_complete_account_fields', $signup, CompleteAccount\signup_meta( $signup ), $complete['fields'] );
		?>

		<div class="hm-activation-form__submit wp-block-button">
			<button type="submit" class="hm-activation-form__button wp-block-button__button wp-element-button">
				<?php esc_html_e( 'Activate account', 'hm-user-activation' ); ?>
			</button>
		</div>
	</form>
</div>
	<?php
	return;
endif;
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore HM.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns escaped attributes ?>>
	<form method="post" action="<?php echo esc_url( remove_query_arg( Resend\QUERY_ARG ) ); ?>">
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
