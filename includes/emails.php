<?php
/**
 * Email handling: activation email, welcome email, and password reset email.
 */

namespace HM\UserActivation\Emails;

use HM\UserActivation\Activation;
use HM\UserActivation\CompleteAccount;
use HM\UserActivation\PasswordReset;
use HM\UserActivation\Security;
use HM\UserActivation\Sites;

function bootstrap(): void {
	// Replace the default user-signup activation email.
	add_filter( 'wpmu_signup_user_notification', __NAMESPACE__ . '\\intercept_user_notification', 10, 4 );

	// Replace the default blog-signup activation email.
	add_filter( 'wpmu_signup_blog_notification', __NAMESPACE__ . '\\intercept_blog_notification', 10, 7 );

	// Suppress the network's own post-activation welcome email.
	add_filter( 'wpmu_welcome_user_notification', __NAMESPACE__ . '\\suppress_network_welcome_email', 10, 3 );

	// Keep the generated password out of the new site welcome email.
	add_filter( 'update_welcome_email', __NAMESPACE__ . '\\redact_site_welcome_password', 10, 4 );
}

/**
 * Link placeholders whose URL carries a single-use key.
 */
const KEY_LINK_PLACEHOLDERS = [
	'{activation_link}',
	'{password_reset_link}',
	'{reset_link}',
];

/**
 * Stop wpmu_welcome_user_notification() sending the network's welcome email.
 *
 * When an account is activated, core generates a random password and passes it
 * to that function, which puts it into the network's welcome_user_email
 * template. Core's default template has a PASSWORD line, so the password is
 * emailed in plain text.
 *
 * This plugin sends its own welcome email containing a one-time reset link
 * instead, so the network's copy is both redundant and a disclosure risk. The
 * user never needs the generated password: it stays unknown to everyone and is
 * replaced the moment they follow the reset link.
 *
 * Only applies when the plugin handles the activation. Other signups, such
 * as users added by an administrator on a site that does not use the plugin,
 * get core's email.
 *
 * @param int    $user_id  The new user's ID.
 * @param string $password The generated password. Deliberately unused.
 * @param array  $meta     Signup meta.
 * @return int|false The original value to let the email through, false to stop it.
 */
function suppress_network_welcome_email( $user_id, $password, $meta ) {
	if ( ! is_plugin_activation( meta_array( $meta ) ) ) {
		return $user_id;
	}

	/**
	 * Filter whether to suppress the network's welcome email.
	 *
	 * Only disable this if the network template is known to be free of the
	 * PASSWORD token and the duplicate email is wanted.
	 *
	 * @param bool $suppress Whether to suppress the network welcome email.
	 * @param int  $user_id  The new user's ID.
	 */
	$suppress = (bool) apply_filters( 'hm_user_activation_suppress_network_welcome_email', true, $user_id );

	return $suppress ? false : $user_id;
}

/**
 * Remove the generated password from the welcome email for a new site.
 *
 * Activating a site signup sends wpmu_welcome_notification(), whose default
 * template has a PASSWORD line. That email is the only one that confirms the
 * new site and its address, so it is kept and only the password is replaced
 * with a pointer to the reset page. Only applies when the plugin's activation
 * page is activating the signup.
 *
 * @param string $welcome_email Email body, with placeholders already replaced.
 * @param int    $blog_id       New site ID.
 * @param int    $user_id       User ID.
 * @param string $password      The generated password.
 * @return string
 */
function redact_site_welcome_password( $welcome_email, $blog_id, $user_id, $password ) {
	// Core passes "N/A" when a super admin creates a site for an existing user.
	if ( ! is_string( $welcome_email ) || ! is_string( $password ) || $password === '' || $password === 'N/A' || ! is_plugin_activation() ) {
		return $welcome_email;
	}

	if ( CompleteAccount\is_completing() ) {
		$notice = __( 'The password you chose when you activated your account.', 'hm-user-activation' );
	} else {
		/* translators: %s: password reset page URL */
		$notice = sprintf( __( 'Not sent by email. Set one at: %s', 'hm-user-activation' ), wp_lostpassword_url() );
	}

	return str_replace( $password, $notice, $welcome_email );
}

/**
 * Whether the plugin is handling this activation: its activation page is
 * activating the signup, or the signup came from the Registration Form block.
 *
 * @param array $meta Signup meta, if known.
 */
function is_plugin_activation( array $meta = [] ): bool {
	return Activation\is_activating() || CompleteAccount\is_completing() || Sites\is_form_signup( $meta );
}

/**
 * Intercepts the user-signup notification and sends our custom email instead.
 * Returning false prevents the default WP email. When the signup's site does
 * not use the plugin, the value is passed on so core sends its own email.
 *
 * @param string|false $user_login Username, or false when another filter stopped the email.
 * @param string       $user_email Address to send to.
 * @param string       $key        Activation key.
 * @param array        $meta       Signup meta.
 * @return string|false
 */
function intercept_user_notification( $user_login, string $user_email, string $key, $meta = [] ) {
	if ( ! $user_login || ! send_activation_email( $user_email, $key, (string) $user_login, meta_array( $meta ) ) ) {
		return $user_login;
	}

	return false;
}

/**
 * Intercepts the blog-signup notification and sends our custom email instead.
 *
 * @param string|false $domain     New site domain, or false when another filter stopped the email.
 * @param string       $path       New site path.
 * @param string       $title      New site title.
 * @param string       $user_login Username.
 * @param string       $user_email Address to send to.
 * @param string       $key        Activation key.
 * @param array        $meta       Signup meta.
 * @return string|false
 */
function intercept_blog_notification( $domain, string $path, string $title, string $user_login, string $user_email, string $key, $meta = [] ) {
	if ( ! $domain || ! send_activation_email( $user_email, $key, $user_login, meta_array( $meta ) ) ) {
		return $domain;
	}

	return false;
}

/**
 * Signup meta as an array. Some callers, such as the network Sign ups
 * screen's resend, pass it still serialized.
 *
 * @param mixed $meta Signup meta.
 */
function meta_array( $meta ): array {
	$meta = maybe_unserialize( $meta );

	return is_array( $meta ) ? $meta : [];
}

/**
 * Build and dispatch the activation email to the registering user.
 *
 * The email comes from the site the user is added to, or the current site
 * when the signup names none, and links to that site's activation page.
 *
 * @param string $user_email Address to send to.
 * @param string $key        Activation key.
 * @param string $username   Username being registered.
 * @param array  $meta       Signup meta.
 * @return bool False when that site has no published activation page, so nothing was sent.
 */
function send_activation_email( string $user_email, string $key, string $username, array $meta = [] ): bool {
	return (bool) Sites\on_site( Sites\signup_site_id( $meta ), static function () use ( $user_email, $key, $username ): bool {
		$page_url = Sites\activation_page_url();

		if ( ! $page_url ) {
			return false;
		}

		$placeholders = build_placeholders( [
			'{activation_link}' => add_query_arg( 'key', rawurlencode( $key ), $page_url ),
			'{username}'        => $username,
		] );

		$subject = get_option( 'hm_activation_email_subject' ) ?: default_activation_subject();
		$body    = get_option( 'hm_activation_email_body' ) ?: default_activation_body();

		wp_mail(
			$user_email,
			build_subject( $subject, $placeholders ),
			replace( $body, $placeholders ),
			build_headers()
		);

		return true;
	} );
}

/**
 * Login URL: the configured log in page if set, then a 1.0.0 log in URL the
 * upgrade could not turn into a page, then wp_login_url().
 *
 * @param int   $user_id Activated user ID, if known.
 * @param array $meta    Signup meta, if known.
 */
function get_login_url( int $user_id = 0, array $meta = [] ): string {
	$login_page_id = (int) get_option( 'hm_activation_login_page_id' );
	$login_url     = ( $login_page_id ? get_permalink( $login_page_id ) : false )
		?: (string) get_option( 'hm_activation_login_url' )
		?: wp_login_url();

	/**
	 * Filters the login URL used after activation and in the welcome email.
	 *
	 * @param string $login_url Login URL.
	 * @param int    $user_id   Activated user ID, or 0 if unknown.
	 * @param array  $meta      Signup meta.
	 */
	return (string) apply_filters( 'hm_user_activation_login_url', $login_url, $user_id, $meta );
}

/**
 * Send the post-activation welcome email.
 * No password is included; a password reset link is provided instead.
 *
 * @param int    $user_id        Newly activated user ID.
 * @param string $reset_url      Pre-generated password reset URL, if available.
 * @param bool   $password_setup False if the user doesn't need a password link. {password_reset_link}
 *                               becomes the login URL and the default body has no password step.
 * @param array  $meta           Signup meta.
 */
function send_welcome_email( int $user_id, string $reset_url = '', bool $password_setup = true, array $meta = [] ): void {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return;
	}

	$login_url = get_login_url( $user_id, $meta );

	$placeholders = build_placeholders( [
		'{username}'             => $user->user_login,
		'{first_name}'           => $user->first_name,
		'{last_name}'            => $user->last_name,
		'{display_name}'         => $user->display_name,
		'{nickname}'             => $user->nickname,
		'{login_url}'            => $login_url,
		'{password_reset_link}'  => $reset_url ?: ( $password_setup ? wp_lostpassword_url() : $login_url ),
	] );

	$subject = get_option( 'hm_activation_welcome_email_subject' ) ?: default_welcome_subject();
	$body    = get_option( 'hm_activation_welcome_email_body' ) ?: ( $password_setup ? default_welcome_body() : default_welcome_body_without_password() );

	wp_mail(
		$user->user_email,
		build_subject( $subject, $placeholders ),
		replace( $body, $placeholders ),
		build_headers()
	);
}

/**
 * Send a password reset email to the given user.
 */
function send_password_reset_email( \WP_User $user, string $key ): void {
	$reset_url = PasswordReset\build_reset_url( $key, $user->user_login );

	$placeholders = build_placeholders( [
		'{username}'   => $user->user_login,
		'{reset_link}' => $reset_url,
	] );

	$subject = get_option( 'hm_activation_reset_email_subject' ) ?: default_reset_subject();
	$body    = get_option( 'hm_activation_reset_email_body' ) ?: default_reset_body();

	wp_mail(
		$user->user_email,
		build_subject( $subject, $placeholders ),
		replace( $body, $placeholders ),
		build_headers()
	);
}

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

/**
 * Global from name, falling back to the site name.
 */
function from_name(): string {
	$name = (string) ( get_option( 'hm_activation_from_name' ) ?: get_bloginfo( 'name' ) );

	// Editable option going into a mail header: newlines would let further
	// headers be injected, and quotes would break out of the display name.
	return str_replace( '"', '', Security\sanitize_header_value( $name ) );
}

/**
 * Global from email, falling back to the admin email.
 */
function from_email(): string {
	$email = Security\sanitize_header_value( (string) get_option( 'hm_activation_from_email' ) );

	if ( ! is_email( $email ) ) {
		$email = (string) get_option( 'admin_email' );
	}

	return $email;
}

/**
 * Build the common placeholder map, merged with any extra placeholders.
 *
 * @param array<string,string> $extra
 * @return array<string,string>
 */
function build_placeholders( array $extra = [] ): array {
	$network = get_network();

	return array_merge(
		[
			'{site_name}'    => get_bloginfo( 'name' ),
			'{site_url}'     => home_url(),
			'{network_name}' => $network ? $network->site_name : get_bloginfo( 'name' ),
		],
		$extra
	);
}

/**
 * Replace placeholder tokens in a string.
 *
 * @param string               $text
 * @param array<string,string> $placeholders
 * @return string
 */
function replace( string $text, array $placeholders ): string {
	// Site admins can edit the templates, but the emails can go to any user on
	// the network. A key link must not be joined to template text, or a
	// template such as "https://example.com/?u={reset_link}" would send the key
	// to that host when clicked. A space keeps the two apart.
	foreach ( KEY_LINK_PLACEHOLDERS as $token ) {
		$text = (string) preg_replace( '/(?<=[^ \t\r\n])' . preg_quote( $token, '/' ) . '/', ' ' . $token, $text );
	}

	// One pass, so a value that contains or completes a placeholder, such as a
	// site title of "{reset_link}", is never itself replaced.
	return strtr( $text, $placeholders );
}

/**
 * Build RFC-compliant mail headers using the global from settings.
 */
function build_headers(): array {
	return [
		'Content-Type: text/plain; charset=UTF-8',
		sprintf( 'From: "%s" <%s>', from_name(), from_email() ),
	];
}

/**
 * Prepare a subject line for sending.
 *
 * Subjects come from editable options and are interpolated with placeholder
 * values, so strip anything that could inject a header before handing it over.
 *
 * @param string               $subject      Subject template.
 * @param array<string,string> $placeholders Placeholder values.
 */
function build_subject( string $subject, array $placeholders ): string {
	return Security\sanitize_header_value(
		wp_specialchars_decode( replace( $subject, $placeholders ) )
	);
}

// -------------------------------------------------------------------------
// Default email content (used when no custom value is saved)
// -------------------------------------------------------------------------

function default_activation_subject(): string {
	return __( 'Activate your account at {site_name}', 'hm-user-activation' );
}

function default_activation_body(): string {
	return implode( "\n\n", [
		__( 'Thank you for registering at {site_name}!', 'hm-user-activation' ),
		__( 'To activate your account, please click the link below:', 'hm-user-activation' ),
		'{activation_link}',
		__( 'If you did not create this account, you can safely ignore this email.', 'hm-user-activation' ),
		__( '{site_name}', 'hm-user-activation' ),
	] );
}

function default_welcome_subject(): string {
	return __( 'Welcome to {site_name} — your account is active', 'hm-user-activation' );
}

function default_welcome_body(): string {
	return implode( "\n\n", [
		__( 'Hi {display_name},', 'hm-user-activation' ),
		__( 'Your account at {site_name} has been successfully activated.', 'hm-user-activation' ),
		__( 'Your username is: {username}', 'hm-user-activation' ),
		__( 'Set your password: {password_reset_link}', 'hm-user-activation' ),
		__( 'You can log in at: {login_url}', 'hm-user-activation' ),
		__( '{site_name}', 'hm-user-activation' ),
	] );
}

function default_welcome_body_without_password(): string {
	return implode( "\n\n", [
		__( 'Hi {display_name},', 'hm-user-activation' ),
		__( 'Your account at {site_name} is ready.', 'hm-user-activation' ),
		__( 'Your username is: {username}', 'hm-user-activation' ),
		__( 'Log in at: {login_url}', 'hm-user-activation' ),
		__( '{site_name}', 'hm-user-activation' ),
	] );
}

function default_reset_subject(): string {
	return __( 'Reset your password for {site_name}', 'hm-user-activation' );
}

function default_reset_body(): string {
	return implode( "\n\n", [
		__( 'Hi {username},', 'hm-user-activation' ),
		__( 'Someone requested a password reset for your account at {site_name}.', 'hm-user-activation' ),
		__( 'To set a new password, click the link below:', 'hm-user-activation' ),
		'{reset_link}',
		__( 'If you did not request this, you can safely ignore this email. Your password will not change.', 'hm-user-activation' ),
		__( '{site_name}', 'hm-user-activation' ),
	] );
}
