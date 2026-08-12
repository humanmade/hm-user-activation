<?php
/**
 * Core activation processing logic.
 *
 * Intercepts the activation page request, validates the submitted key,
 * calls wpmu_activate_signup(), and stores the result for block bindings
 * and conditional block rendering.
 */

namespace HM\UserActivation\Activation;

use HM\UserActivation\Emails;
use HM\UserActivation\PasswordReset;
use HM\UserActivation\Resend;
use HM\UserActivation\Security;

function bootstrap(): void {
	// Redirect wp-activate.php requests to our activation page.
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\maybe_redirect_wp_activate', 1 );

	// Process an activation form submission on the activation page.
	add_action( 'template_redirect', __NAMESPACE__ . '\\maybe_process_activation' );

	// Conditionally hide error/success group variants when irrelevant.
	add_filter( 'render_block', __NAMESPACE__ . '\\filter_block_visibility', 10, 2 );

	// Drop buttons bound to a URL that is empty after activation.
	add_filter( 'render_block', __NAMESPACE__ . '\\filter_empty_url_buttons', 10, 2 );
}

/**
 * Redirect requests to the native wp-activate.php to our activation page.
 * Only fires when the plugin is active on the main site (where wp-activate.php loads plugins).
 */
function maybe_redirect_wp_activate(): void {
	global $pagenow;

	if ( ( $pagenow ?? '' ) !== 'wp-activate.php' ) {
		return;
	}

	$page_id = get_option( 'hm_activation_page_id' );
	if ( ! $page_id ) {
		return;
	}

	$key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
	$url = get_permalink( (int) $page_id );

	if ( ! $url ) {
		return;
	}

	if ( $key ) {
		$url = add_query_arg( 'key', rawurlencode( $key ), $url );
	}

	// A temporary redirect, not permanent: the URL carries a single-use key, and
	// a 301 would be stored in browser and proxy caches. The target is a
	// permalink we generated rather than user input, so wp_redirect() is used
	// here — wp_safe_redirect() would reject the other host on a subdomain
	// network and send the user to wp-admin instead.
	wp_redirect( $url, 302 );
	exit;
}

/**
 * Process an activation on the activation page.
 *
 * Three entry points:
 *  - POST        → process a manually submitted form (nonce verified).
 *  - GET ?key=…  → move the key into a scoped, HTTP-only cookie and redirect to
 *                  the clean permalink, so the key is not left in the URL.
 *  - Key cookie  → process the stashed key using the site's auto-login setting.
 */
function maybe_process_activation(): void {
	$page_id = (int) get_option( 'hm_activation_page_id' );

	if ( ! $page_id || ! is_page( $page_id ) ) {
		return;
	}

	// POST: manual form submission.
	if ( ! empty( $_POST['_hm_activation_nonce'] ) ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_hm_activation_nonce'] ) ), 'hm_activation' ) ) {
			result( [
				'success'       => false,
				'error_code'    => 'nonce_failed',
				'error_message' => __( 'Security check failed. Please refresh the page and try again.', 'hm-user-activation' ),
			] );
			return;
		}

		$key = sanitize_text_field( wp_unslash( $_POST['activation_key'] ?? '' ) );

		if ( ! $key ) {
			result( [
				'success'       => false,
				'error_code'    => 'empty_key',
				'error_message' => __( 'Please enter your activation key.', 'hm-user-activation' ),
			] );
			return;
		}

		process( $key, (bool) get_option( 'hm_activation_auto_login', false ) );
		return;
	}

	// GET: the key arrived in the URL. Stash it in a cookie and reload the page
	// without it, so the key stays out of browser history, Referer headers and
	// access logs, and cannot be shared by copying the address bar. Mirrors the
	// way core handles password reset keys on wp-login.php.
	if ( isset( $_GET['key'] ) ) {
		$key = sanitize_text_field( wp_unslash( $_GET['key'] ) );

		if ( $key ) {
			Security\set_key_cookie( Security\ACTIVATION_COOKIE, $key, $page_id );

			// Target is our own permalink, not user input — see the note in
			// maybe_redirect_wp_activate() on wp_redirect() vs wp_safe_redirect().
			wp_redirect( get_permalink( $page_id ) ?: home_url( '/' ), 302 );
			exit;
		}
	}

	// A key stashed from a previous request — process it now.
	$key = Security\get_key_cookie( Security\ACTIVATION_COOKIE );

	if ( $key ) {
		process( $key, (bool) get_option( 'hm_activation_auto_login', false ) );
	}
}

/**
 * Run wpmu_activate_signup() and store the result.
 */
function process( string $key, bool $auto_login ): void {
	static $processed = false;
	if ( $processed ) {
		return;
	}
	$processed = true;

	$page_id = (int) get_option( 'hm_activation_page_id' );

	$signup = get_signup_by_key( $key );
	if ( $signup && ! (int) $signup->active && is_expired( $signup ) ) {
		result( [
			'success'       => false,
			'error_code'    => 'expired_key',
			'error_message' => __( 'This activation link has expired. Request a new one below.', 'hm-user-activation' ),
		] );
		return;
	}

	if ( $signup ) {
		Resend\clear_counter( $signup );
	}

	$activation = wpmu_activate_signup( $key );

	if ( is_wp_error( $activation ) ) {
		result( [
			'success'       => false,
			'error_code'    => $activation->get_error_code(),
			'error_message' => $activation->get_error_message(),
		] );
		return;
	}

	// The key is spent — drop the cookie so a later visit to the page cannot
	// replay it, and so the success state is not rebuilt from a stale secret.
	Security\clear_key_cookie( Security\ACTIVATION_COOKIE, $page_id );

	$user_id = (int) $activation['user_id'];
	$user    = get_user_by( 'id', $user_id );

	if ( $auto_login && $user ) {
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		// Let security, audit and two-factor plugins see the login.
		do_action( 'wp_login', $user->user_login, $user );
	}

	$meta = (array) ( $activation['meta'] ?? [] );
	unset( $meta[ Resend\META_KEY ] );

	// Core activates with a generated password. If the stored hash no longer matches it, something has set a real one.
	$needs_password_setup = ! $user
		|| empty( $activation['password'] )
		|| wp_check_password( $activation['password'], $user->user_pass, $user_id );

	/**
	 * Filters whether the new user needs to set a password.
	 *
	 * The default is true while the user still has the password generated
	 * during activation, and false once something else has set one, for
	 * example a hook on wpmu_activate_user. No password reset key or URL is
	 * generated when this is false.
	 *
	 * Returning false for a user who only has the generated password leaves
	 * them unable to log in until they use "lost password". Returning true
	 * for a user who already has a password just sends them through a
	 * harmless reset.
	 *
	 * @param bool  $needs   Whether to generate a password reset link.
	 * @param int   $user_id Activated user ID.
	 * @param array $meta    Signup meta.
	 */
	$needs_password_setup = (bool) apply_filters( 'hm_user_activation_needs_password_setup', $needs_password_setup, $user_id, $meta );

	// Generate a password reset key so the user can set their own password.
	$reset_url = '';
	if ( $user && $needs_password_setup ) {
		$reset_page_id = (int) get_option( 'hm_activation_password_reset_page_id' );
		if ( $reset_page_id ) {
			$reset_key = get_password_reset_key( $user );
			if ( ! is_wp_error( $reset_key ) ) {
				$reset_url = PasswordReset\build_reset_url( $reset_key, $user->user_login );
			}
		}
	}

	$login_url = Emails\get_login_url( $user_id, $meta );

	result( [
		'success'   => true,
		'user_id'   => $user_id,
		'username'  => $user ? $user->user_login : '',
		'reset_url' => $reset_url,
		'login_url' => $login_url,
		'meta'      => $meta,
	] );

	/**
	 * Filters whether to send the welcome email.
	 *
	 * @param bool  $send    Whether to send it. Defaults to the welcome email setting.
	 * @param int   $user_id Activated user ID.
	 * @param array $meta    Signup meta.
	 */
	$send_welcome = (bool) apply_filters(
		'hm_user_activation_send_welcome_email',
		(bool) get_option( 'hm_activation_welcome_email_enabled', true ),
		$user_id,
		$meta
	);

	if ( $send_welcome ) {
		Emails\send_welcome_email( $user_id, $reset_url, $needs_password_setup, $meta );
	}

	/**
	 * Fires after an account has been activated.
	 *
	 * @param int   $user_id Activated user ID.
	 * @param array $meta    Signup meta.
	 */
	do_action( 'hm_user_activation_activated', $user_id, $meta );
}

/**
 * Activation link lifetime in hours. 0 means links never expire.
 */
function get_link_lifetime(): int {
	/**
	 * Filters the activation link lifetime.
	 *
	 * @param int $hours Lifetime in hours. 0 means links never expire.
	 */
	return max( 0, (int) apply_filters( 'hm_user_activation_link_lifetime', (int) get_option( 'hm_activation_link_lifetime', 0 ) ) );
}

/**
 * Look up a signup row by its activation key.
 */
function get_signup_by_key( string $key ): ?object {
	global $wpdb;

	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->signups} WHERE activation_key = %s", $key ) );
}

/**
 * Whether a signup was registered longer ago than the activation link lifetime.
 */
function is_expired( object $signup ): bool {
	$lifetime = get_link_lifetime();
	if ( ! $lifetime ) {
		return false;
	}

	$registered = strtotime( $signup->registered . ' UTC' );

	return $registered && ( time() - $registered ) > $lifetime * HOUR_IN_SECONDS;
}

/**
 * Hide activation group variants when their condition is not met.
 * Only acts on the configured activation page.
 *
 * @param string $block_content Rendered block HTML.
 * @param array  $parsed_block  Parsed block data.
 * @return string
 */
function filter_block_visibility( string $block_content, array $parsed_block ): string {
	if ( $parsed_block['blockName'] !== 'core/group' ) {
		return $block_content;
	}

	$variation = $parsed_block['attrs']['metadata']['variationName'] ?? '';

	// The resend block works on any page, so its confirmation is gated everywhere.
	if ( $variation === 'hm-user-activation/resent' && ( ! Resend\was_resent() || is_success() || is_error() ) ) {
		return '';
	}

	$page_id = (int) get_option( 'hm_activation_page_id' );
	if ( ! $page_id || (int) get_queried_object_id() !== $page_id ) {
		return $block_content;
	}

	if ( $variation === 'hm-user-activation/errors' && ! is_error() ) {
		return '';
	}

	if ( $variation === 'hm-user-activation/success' && ! is_success() ) {
		return '';
	}

	return $block_content;
}

/**
 * After a successful activation, hide buttons whose bound URL is empty,
 * for example a "Set your password" button when no reset link was generated.
 *
 * @param string $block_content Rendered block HTML.
 * @param array  $parsed_block  Parsed block data.
 * @return string
 */
function filter_empty_url_buttons( string $block_content, array $parsed_block ): string {
	if ( $parsed_block['blockName'] !== 'core/button' || ! is_success() ) {
		return $block_content;
	}

	$source = $parsed_block['attrs']['metadata']['bindings']['url']['source'] ?? '';

	if ( $source === 'hm-user-activation/reset-url' && ! get_reset_url() ) {
		return '';
	}

	if ( $source === 'hm-user-activation/login-url' && ! get_login_url() ) {
		return '';
	}

	return $block_content;
}

// -------------------------------------------------------------------------
// State — result() acts as both getter and setter.
// Call result( $value ) to set, result() to get.
// -------------------------------------------------------------------------

/**
 * @param array{success: bool, user_id?: int, username?: string, reset_url?: string, login_url?: string, meta?: array, error_message?: string, error_code?: string}|null $value
 * @return array{success: bool, user_id?: int, username?: string, reset_url?: string, login_url?: string, meta?: array, error_message?: string, error_code?: string}|null
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

function get_username(): string {
	return is_success() ? ( result()['username'] ?? '' ) : '';
}

function get_reset_url(): string {
	return is_success() ? ( result()['reset_url'] ?? '' ) : '';
}

function get_login_url(): string {
	return is_success() ? ( result()['login_url'] ?? '' ) : '';
}
