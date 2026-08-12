<?php
/**
 * Password reset flow.
 *
 * Handles both the "request a reset link" and "set a new password" forms
 * on the configured password reset page. Mirrors the activation module's
 * structure: a result() state store, group-variation visibility filtering,
 * and a lostpassword_url filter so any "forgot password?" links across the
 * site point here automatically.
 */

namespace HM\UserActivation\PasswordReset;

use HM\UserActivation\Emails;
use HM\UserActivation\Security;

function bootstrap(): void {
	add_action( 'template_redirect', __NAMESPACE__ . '\\maybe_process' );
	add_filter( 'lostpassword_url', __NAMESPACE__ . '\\filter_lostpassword_url', 10, 2 );
	add_filter( 'render_block', __NAMESPACE__ . '\\filter_block_visibility', 10, 2 );
}

// -------------------------------------------------------------------------
// Request processing
// -------------------------------------------------------------------------

/**
 * Handle form submissions on the password reset page.
 *
 * A key arriving as ?key=…&login=… is moved into a scoped, HTTP-only cookie and
 * the page is reloaded without it, so the key never appears in browser history,
 * Referer headers or access logs. The cookie is then the authority for the rest
 * of the flow; the form's hidden field is only cross-checked against it. This is
 * the same approach core takes for wp-login.php?action=rp.
 */
function maybe_process(): void {
	$page_id = (int) get_option( 'hm_activation_password_reset_page_id' );

	if ( ! $page_id || ! is_page( $page_id ) ) {
		return;
	}

	// Move a key out of the URL and reload.
	if ( isset( $_GET['key'], $_GET['login'] ) ) {
		$key   = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		$login = sanitize_text_field( wp_unslash( $_GET['login'] ) );

		if ( $key && $login ) {
			Security\set_key_cookie( Security\RESET_COOKIE, $login . ':' . $key, $page_id );

			// Target is our own permalink rather than user input, so
			// wp_safe_redirect()'s host allow-list is not needed here.
			wp_redirect( get_permalink( $page_id ) ?: home_url( '/' ), 302 );
			exit;
		}
	}

	if ( ! empty( $_POST['_hm_reset_nonce'] ) ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_hm_reset_nonce'] ) ), 'hm_reset' ) ) {
			result( [
				'success'       => false,
				'mode'          => 'reset',
				'error_code'    => 'nonce_failed',
				'error_message' => __( 'Security check failed. Please refresh the page and try again.', 'hm-user-activation' ),
			] );
			return;
		}

		$pending = pending_credentials();

		// The cookie is the source of truth. The posted key must match it, so a
		// form from another session cannot be replayed against this browser.
		if (
			! $pending
			|| ! hash_equals( $pending['key'], sanitize_text_field( wp_unslash( $_POST['rp_key'] ?? '' ) ) )
		) {
			Security\clear_key_cookie( Security\RESET_COOKIE, $page_id );
			result( [
				'success'       => false,
				'mode'          => 'reset',
				'error_code'    => 'invalid_key',
				'error_message' => invalid_key_message(),
			] );
			return;
		}

		$pass1 = wp_unslash( $_POST['pass1'] ?? '' );
		$pass2 = wp_unslash( $_POST['pass2'] ?? '' );

		process_password_change( $pending['key'], $pending['login'], $pass1, $pass2 );
		return;
	}

	if ( ! empty( $_POST['_hm_reset_request_nonce'] ) ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_hm_reset_request_nonce'] ) ), 'hm_reset_request' ) ) {
			result( [
				'success'       => false,
				'mode'          => 'request',
				'error_code'    => 'nonce_failed',
				'error_message' => __( 'Security check failed. Please refresh the page and try again.', 'hm-user-activation' ),
			] );
			return;
		}

		$user_login = sanitize_text_field( wp_unslash( $_POST['user_login'] ?? '' ) );
		process_reset_request( $user_login );
		return;
	}

	// Validate a stashed key up front so we don't render a form that cannot
	// work, and so an expired link explains itself.
	$pending = pending_credentials();

	if ( $pending ) {
		$user = check_password_reset_key( $pending['key'], $pending['login'] );

		if ( is_wp_error( $user ) ) {
			Security\clear_key_cookie( Security\RESET_COOKIE, $page_id );
			result( [
				'success'       => false,
				'mode'          => 'reset',
				'error_code'    => 'invalid_key',
				'error_message' => invalid_key_message(),
			] );
		}
	}
}

/**
 * Credentials stashed from a reset link, or null when there are none.
 *
 * @return array{login: string, key: string}|null
 */
function pending_credentials(): ?array {
	$cookie = Security\get_key_cookie( Security\RESET_COOKIE );

	if ( ! $cookie || ! str_contains( $cookie, ':' ) ) {
		return null;
	}

	[ $login, $key ] = explode( ':', $cookie, 2 );

	if ( ! $login || ! $key ) {
		return null;
	}

	return [
		'login' => $login,
		'key'   => $key,
	];
}

/**
 * A single message for every unusable key.
 *
 * Core distinguishes invalid from expired keys; collapsing them means a caller
 * probing keys learns nothing about which part was wrong.
 */
function invalid_key_message(): string {
	return __( 'This password reset link is no longer valid. Please request a new one.', 'hm-user-activation' );
}

/**
 * Send a password reset email to the user identified by email or username.
 * Always reports success to avoid user enumeration.
 */
function process_reset_request( string $email_or_login ): void {
	if ( ! $email_or_login ) {
		result( [
			'success'       => false,
			'mode'          => 'request',
			'error_code'    => 'empty_login',
			'error_message' => __( 'Please enter your username or email address.', 'hm-user-activation' ),
		] );
		return;
	}

	// Cap requests per client: this endpoint sends mail to an address the caller
	// supplies, so without a limit it can be used to flood an inbox.
	if ( Security\is_rate_limited( 'reset_request', 5, 15 * MINUTE_IN_SECONDS ) ) {
		result( [
			'success'       => false,
			'mode'          => 'request',
			'error_code'    => 'rate_limited',
			'error_message' => Security\rate_limit_message(),
		] );
		return;
	}

	$user = str_contains( $email_or_login, '@' )
		? get_user_by( 'email', $email_or_login )
		: get_user_by( 'login', $email_or_login );

	$errors = new \WP_Error();

	/**
	 * Fires before a reset link is sent, matching core's hook so existing
	 * integrations — logging, captchas, extra validation — keep working. Errors
	 * added here stop the request, as they do on wp-login.php.
	 *
	 * @param \WP_Error      $errors Error collector.
	 * @param \WP_User|false $user   The user found for the submitted value.
	 */
	do_action( 'lostpassword_post', $errors, $user );

	if ( $errors->has_errors() ) {
		result( [
			'success'       => false,
			'mode'          => 'request',
			'error_code'    => $errors->get_error_code(),
			'error_message' => implode( ' ', $errors->get_error_messages() ),
		] );
		return;
	}

	// Always show success to avoid revealing whether the account exists.
	if ( $user ) {
		/**
		 * Core's filter for policies that forbid resets for certain accounts,
		 * honoured here so those users cannot be reset through this page either.
		 *
		 * @param bool $allow   Whether to allow the password to be reset.
		 * @param int  $user_id The user ID.
		 */
		$allow = apply_filters( 'allow_password_reset', true, $user->ID );

		if ( $allow && ! is_wp_error( $allow ) ) {
			$key = get_password_reset_key( $user );
			if ( ! is_wp_error( $key ) ) {
				Emails\send_password_reset_email( $user, $key );
			}
		}
	}

	result( [ 'success' => true, 'mode' => 'request' ] );
}

/**
 * Validate a reset key and update the user's password.
 */
function process_password_change( string $key, string $login, string $pass1, string $pass2 ): void {
	if ( ! $pass1 ) {
		result( [
			'success'       => false,
			'mode'          => 'reset',
			'error_code'    => 'empty_password',
			'error_message' => __( 'Please enter a new password.', 'hm-user-activation' ),
		] );
		return;
	}

	if ( $pass1 !== $pass2 ) {
		result( [
			'success'       => false,
			'mode'          => 'reset',
			'error_code'    => 'password_mismatch',
			'error_message' => __( 'Passwords do not match. Please try again.', 'hm-user-activation' ),
		] );
		return;
	}

	$user = check_password_reset_key( $key, $login );

	if ( is_wp_error( $user ) ) {
		Security\clear_key_cookie( Security\RESET_COOKIE, (int) get_option( 'hm_activation_password_reset_page_id' ) );
		result( [
			'success'       => false,
			'mode'          => 'reset',
			'error_code'    => 'invalid_key',
			'error_message' => invalid_key_message(),
		] );
		return;
	}

	// Give password policy plugins their say, as wp-login.php does.
	$errors = new \WP_Error();

	/**
	 * Fires before the new password is stored.
	 *
	 * @param \WP_Error         $errors Error collector.
	 * @param \WP_User|\WP_Error $user   The user resetting their password.
	 */
	do_action( 'validate_password_reset', $errors, $user );

	if ( $errors->has_errors() ) {
		result( [
			'success'       => false,
			'mode'          => 'reset',
			'error_code'    => $errors->get_error_code(),
			'error_message' => implode( ' ', $errors->get_error_messages() ),
		] );
		return;
	}

	// reset_password() clears the reset key and destroys the user's existing
	// sessions, so a stolen cookie cannot outlive the password change.
	reset_password( $user, $pass1 );

	// The key is spent — remove it so the form cannot be resubmitted.
	Security\clear_key_cookie( Security\RESET_COOKIE, (int) get_option( 'hm_activation_password_reset_page_id' ) );

	result( [ 'success' => true, 'mode' => 'reset' ] );
}

// -------------------------------------------------------------------------
// Filters
// -------------------------------------------------------------------------

/**
 * Redirect "forgot password?" links to our custom reset page.
 */
function filter_lostpassword_url( string $url, string $redirect ): string {
	$page_id = (int) get_option( 'hm_activation_password_reset_page_id' );
	if ( ! $page_id ) {
		return $url;
	}
	return get_permalink( $page_id ) ?: $url;
}

/**
 * Show/hide group variants on the password reset page.
 */
function filter_block_visibility( string $block_content, array $parsed_block ): string {
	if ( $parsed_block['blockName'] !== 'core/group' ) {
		return $block_content;
	}

	$page_id = (int) get_option( 'hm_activation_password_reset_page_id' );
	if ( ! $page_id || (int) get_queried_object_id() !== $page_id ) {
		return $block_content;
	}

	$variation = $parsed_block['attrs']['metadata']['variationName'] ?? '';

	if ( $variation === 'hm-user-activation/reset-errors' && ! is_error() ) {
		return '';
	}

	if ( $variation === 'hm-user-activation/reset-request-success' && ! ( is_success() && get_mode() === 'request' ) ) {
		return '';
	}

	if ( $variation === 'hm-user-activation/reset-success' && ! ( is_success() && get_mode() === 'reset' ) ) {
		return '';
	}

	return $block_content;
}

// -------------------------------------------------------------------------
// URL helper
// -------------------------------------------------------------------------

/**
 * Build a password reset URL pointing to our custom page (or wp-login.php as fallback).
 */
function build_reset_url( string $key, string $login ): string {
	$page_id = (int) get_option( 'hm_activation_password_reset_page_id' );
	$base    = $page_id ? ( get_permalink( $page_id ) ?: '' ) : network_site_url( 'wp-login.php?action=rp' );

	return add_query_arg( [
		'key'   => rawurlencode( $key ),
		'login' => rawurlencode( $login ),
	], $base );
}

// -------------------------------------------------------------------------
// State — result() acts as both getter and setter.
// -------------------------------------------------------------------------

/**
 * @param array{success: bool, mode: string, error_code?: string, error_message?: string}|null $value
 * @return array{success: bool, mode: string, error_code?: string, error_message?: string}|null
 */
function result( ?array $value = null ): ?array {
	static $result = null;
	if ( func_num_args() ) {
		$result = $value;
	}
	return $result;
}

function is_success(): bool {
	$r = result();
	return isset( $r['success'] ) && $r['success'] === true;
}

function is_error(): bool {
	$r = result();
	return isset( $r['success'] ) && $r['success'] === false;
}

function get_error_message(): string {
	return is_error() ? ( result()['error_message'] ?? '' ) : '';
}

function get_mode(): string {
	return result()['mode'] ?? '';
}
