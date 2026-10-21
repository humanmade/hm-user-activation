<?php
/**
 * Server-side render for hm-user-activation/registration-form.
 *
 * Variables available from block context:
 *  $attributes  - Block attributes.
 *  $content     - Inner blocks HTML (unused — self-closing block).
 *  $block       - WP_Block instance.
 *
 * The block hides itself on success and for logged-in users; the group
 * variations on the page handle displaying the confirmation message.
 */

use HM\UserActivation\Emails;
use HM\UserActivation\Registration;

if ( is_user_logged_in() ) {
	return;
}

// Hide the form once a signup has been created — group variations take over.
if ( Registration\is_success() ) {
	return;
}

$show_email_notice = isset( $attributes['showEmailNotice'] ) ? (bool) $attributes['showEmailNotice'] : true;
$show_login_link   = isset( $attributes['showLoginLink'] ) ? (bool) $attributes['showLoginLink'] : true;

/** This filter is documented in includes/registration.php */
$show_username = (bool) apply_filters(
	'hm_user_activation_registration_show_username',
	isset( $attributes['showUsername'] ) ? (bool) $attributes['showUsername'] : true
);

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'hm-registration-form' ] );

if ( ! Registration\is_registration_enabled() ) {
	printf(
		'<div %1$s><p class="hm-registration-form__closed">%2$s</p></div>',
		$wrapper_attributes, // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped by get_block_wrapper_attributes().
		esc_html__( 'Registration is not currently open.', 'hm-user-activation' )
	);
	return;
}

/** This filter is documented in wp-signup.php */
$signup_user = apply_filters( 'signup_user_init', [
	'user_name'  => Registration\get_submitted_user_name(),
	'user_email' => Registration\get_submitted_user_email(),
	'errors'     => Registration\get_errors(),
] );

$user_name  = (string) ( $signup_user['user_name'] ?? '' );
$user_email = (string) ( $signup_user['user_email'] ?? '' );
$errors     = is_wp_error( $signup_user['errors'] ?? null ) ? $signup_user['errors'] : new WP_Error();

// A field can carry more than one message, e.g. an invalid *and* taken username.
$name_error    = implode( ' ', $errors->get_error_messages( 'user_name' ) );
$email_error   = implode( ' ', $errors->get_error_messages( 'user_email' ) );
$generic_error = implode( ' ', $errors->get_error_messages( 'generic' ) );

// Without the username field, its errors show with the generic ones.
if ( ! $show_username && $name_error ) {
	$generic_error = trim( $generic_error . ' ' . $name_error );
}

// Core's messages can include <strong> and a log in link.
$error_html = [
	'strong' => [],
	'a'      => [ 'href' => [] ],
];
?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput -- Escaped by get_block_wrapper_attributes(). ?>>
	<form method="post" action="" class="hm-registration-form__form">
		<?php
		wp_nonce_field( 'hm_register', '_hm_register_nonce' );

		/** This action is documented in wp-signup.php */
		do_action( 'signup_hidden_fields', 'validate-user' );
		?>

		<?php if ( $show_username ) : ?>
		<p class="hm-registration-form__field">
			<label class="hm-registration-form__label" for="hm-user-name">
				<?php esc_html_e( 'Username', 'hm-user-activation' ); ?>
			</label>
			<input
				type="text"
				id="hm-user-name"
				name="user_name"
				class="hm-registration-form__input input"
				value="<?php echo esc_attr( $user_name ); ?>"
				required
				autocomplete="username"
				<?php echo $name_error ? ' aria-describedby="hm-user-name-error" aria-invalid="true"' : ''; ?>
			>
			<?php if ( $name_error ) : ?>
				<span class="hm-registration-form__error" id="hm-user-name-error"><?php echo wp_kses( $name_error, $error_html ); ?></span>
			<?php endif; ?>
		</p>
		<?php endif; ?>

		<p class="hm-registration-form__field">
			<label class="hm-registration-form__label" for="hm-user-email">
				<?php esc_html_e( 'Email address', 'hm-user-activation' ); ?>
			</label>
			<input
				type="email"
				id="hm-user-email"
				name="user_email"
				class="hm-registration-form__input input"
				value="<?php echo esc_attr( $user_email ); ?>"
				required
				autocomplete="email"
				<?php echo $email_error ? ' aria-describedby="hm-user-email-error" aria-invalid="true"' : ''; ?>
			>
			<?php if ( $email_error ) : ?>
				<span class="hm-registration-form__error" id="hm-user-email-error"><?php echo wp_kses( $email_error, $error_html ); ?></span>
			<?php endif; ?>
		</p>

		<?php if ( $generic_error ) : ?>
			<p class="hm-registration-form__error" id="hm-registration-generic-error"><?php echo wp_kses( $generic_error, $error_html ); ?></p>
		<?php endif; ?>

		<?php
		/** This action is documented in wp-signup.php */
		do_action( 'signup_extra_fields', $errors );
		?>

		<?php if ( $show_email_notice ) : ?>
			<p class="hm-registration-form__notice">
				<?php esc_html_e( 'Registration confirmation will be emailed to you.', 'hm-user-activation' ); ?>
			</p>
		<?php endif; ?>

		<div class="hm-registration-form__submit wp-block-button">
			<button type="submit" class="hm-registration-form__button wp-block-button__button button <?php echo esc_attr( wp_theme_get_element_class_name( 'button' ) ); ?>">
				<?php esc_html_e( 'Register', 'hm-user-activation' ); ?>
			</button>
		</div>
	</form>

	<?php if ( $show_login_link ) : ?>
		<p class="hm-registration-form__login">
			<a href="<?php echo esc_url( Emails\get_login_url() ); ?>">
				<?php esc_html_e( 'Already have an account? Log in', 'hm-user-activation' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
