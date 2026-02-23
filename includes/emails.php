<?php
/**
 * Email handling: activation email, welcome email, and password reset email.
 */

namespace HM\UserActivation\Emails;

use HM\UserActivation\PasswordReset;

function bootstrap(): void {
	// Replace the default user-signup activation email.
	add_filter( 'wpmu_signup_user_notification', __NAMESPACE__ . '\\intercept_user_notification', 10, 4 );

	// Replace the default blog-signup activation email.
	add_filter( 'wpmu_signup_blog_notification', __NAMESPACE__ . '\\intercept_blog_notification', 10, 7 );
}

/**
 * Intercepts the user-signup notification and sends our custom email instead.
 * Returning false prevents the default WP email.
 */
function intercept_user_notification( string $user_login, string $user_email, string $key, array $meta ): bool {
	send_activation_email( $user_email, $key, $user_login );
	return false;
}

/**
 * Intercepts the blog-signup notification and sends our custom email instead.
 */
function intercept_blog_notification( string $domain, string $path, string $title, string $user_login, string $user_email, string $key, array $meta ): bool {
	send_activation_email( $user_email, $key, $user_login );
	return false;
}

/**
 * Build and dispatch the activation email to the registering user.
 */
function send_activation_email( string $user_email, string $key, string $username ): void {
	$page_id = (int) get_option( 'hm_activation_page_id' );

	if ( $page_id ) {
		$activation_url = add_query_arg( 'key', rawurlencode( $key ), get_permalink( $page_id ) ?: home_url( '/' ) );
	} else {
		$activation_url = network_site_url( 'wp-activate.php?key=' . rawurlencode( $key ) );
	}

	$placeholders = build_placeholders( [
		'{activation_link}' => $activation_url,
		'{username}'        => $username,
	] );

	$subject = get_option( 'hm_activation_email_subject' ) ?: default_activation_subject();
	$body    = get_option( 'hm_activation_email_body' ) ?: default_activation_body();

	wp_mail(
		$user_email,
		wp_specialchars_decode( replace( $subject, $placeholders ) ),
		replace( $body, $placeholders ),
		build_headers()
	);
}

/**
 * Send the post-activation welcome email.
 * No password is included; a password reset link is provided instead.
 *
 * @param int    $user_id   Newly activated user ID.
 * @param string $reset_url Pre-generated password reset URL, if available.
 */
function send_welcome_email( int $user_id, string $reset_url = '' ): void {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return;
	}

	$login_page_id = (int) get_option( 'hm_activation_login_page_id' );
	$login_url     = $login_page_id ? get_permalink( $login_page_id ) : false;

	$placeholders = build_placeholders( [
		'{username}'             => $user->user_login,
		'{first_name}'           => $user->first_name,
		'{last_name}'            => $user->last_name,
		'{display_name}'         => $user->display_name,
		'{nickname}'             => $user->nickname,
		'{login_url}'            => $login_url ?: wp_login_url(),
		'{password_reset_link}'  => $reset_url ?: wp_lostpassword_url(),
	] );

	$subject = get_option( 'hm_activation_welcome_email_subject' ) ?: default_welcome_subject();
	$body    = get_option( 'hm_activation_welcome_email_body' ) ?: default_welcome_body();

	wp_mail(
		$user->user_email,
		wp_specialchars_decode( replace( $subject, $placeholders ) ),
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
		wp_specialchars_decode( replace( $subject, $placeholders ) ),
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
	return (string) ( get_option( 'hm_activation_from_name' ) ?: get_bloginfo( 'name' ) );
}

/**
 * Global from email, falling back to the admin email.
 */
function from_email(): string {
	return (string) ( get_option( 'hm_activation_from_email' ) ?: get_option( 'admin_email' ) );
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
	return str_replace( array_keys( $placeholders ), array_values( $placeholders ), $text );
}

/**
 * Build RFC-compliant mail headers using the global from settings.
 */
function build_headers(): array {
	return [
		'Content-Type: text/plain; charset=UTF-8',
		sprintf( 'From: %s <%s>', from_name(), from_email() ),
	];
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
