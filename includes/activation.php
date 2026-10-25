<?php
/**
 * Core activation processing logic.
 *
 * Intercepts the activation page request, validates the submitted key,
 * calls wpmu_activate_signup(), and stores the result for block bindings
 * and conditional block rendering.
 */

namespace HM\UserActivation\Activation;

use HM\UserActivation\CompleteAccount;
use HM\UserActivation\Emails;
use HM\UserActivation\PasswordReset;
use HM\UserActivation\Resend;
use HM\UserActivation\Security;
use HM\UserActivation\Sites;

function bootstrap(): void {
	// Redirect wp-activate.php requests to our activation page. This needs
	// init, because get_permalink() needs $wp_rewrite.
	add_action( 'init', __NAMESPACE__ . '\\maybe_redirect_wp_activate', 1 );

	// Process an activation form submission on the activation page.
	add_action( 'template_redirect', __NAMESPACE__ . '\\maybe_process_activation' );

	// Conditionally hide error/success group variants when irrelevant.
	add_filter( 'render_block', __NAMESPACE__ . '\\filter_block_visibility', 10, 2 );

	// Drop buttons bound to a URL that is empty after activation.
	add_filter( 'render_block', __NAMESPACE__ . '\\filter_empty_url_buttons', 10, 2 );
}

/**
 * Send a key on wp-activate.php to the activation page of the signup's own site.
 *
 * wp-activate.php defines WP_INSTALLING, so WordPress only loads mu-plugins
 * and network-activated plugins there. This runs only when the plugin is
 * loaded in one of those ways.
 *
 * A signup whose site has a published activation page is redirected there.
 * A Registration Form signup whose site has none is stopped. Any other
 * signup is left to core.
 */
function maybe_redirect_wp_activate(): void {
	global $pagenow;

	if ( ( $pagenow ?? '' ) !== 'wp-activate.php' ) {
		return;
	}

	// Core reads its cookie without checking the type, and wpdb::prepare()
	// turns a one-item array into its value, so a key sent as an array
	// cookie would reach core unchecked. A real key is always a string.
	$cookie = 'wp-activate-' . COOKIEHASH;
	if ( isset( $_COOKIE[ $cookie ] ) && ! is_string( $_COOKIE[ $cookie ] ) ) {
		unset( $_COOKIE[ $cookie ] );
	}

	// Core picks one of these keys by its own rules, so every one of them
	// is checked. Core only gets the request when none needs the plugin.
	$url = null;
	foreach ( get_wp_activate_keys() as $key ) {
		$url = get_wp_activate_redirect( $key );

		if ( is_wp_error( $url ) ) {
			clear_wp_activate_cookie();
			wp_die( esc_html( $url->get_error_message() ), esc_html__( 'Activation failed', 'hm-user-activation' ), [ 'response' => 400 ] );
		}

		if ( $url ) {
			break;
		}
	}

	if ( ! $url ) {
		return;
	}

	clear_wp_activate_cookie();
	nocache_headers();

	// A temporary redirect, not permanent: the URL carries a single-use key, and
	// a 301 would be stored in browser and proxy caches. The target is a
	// permalink we generated rather than user input, so wp_redirect() is used
	// here — wp_safe_redirect() would reject the other host on a subdomain
	// network and send the user to wp-admin instead.
	wp_redirect( $url, 302 );
	exit;
}

/**
 * Every key wp-activate.php could use: from the query string, a posted
 * form, and the cookie core keeps it in, in that order, without duplicates.
 *
 * @return string[]
 */
function get_wp_activate_keys(): array {
	$cookie = 'wp-activate-' . COOKIEHASH;

	// phpcs:disable WordPress.Security.NonceVerification -- wp-activate.php takes the key without a nonce.
	$values = [
		isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '',
		isset( $_POST['key'] ) && is_string( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '',
		isset( $_COOKIE[ $cookie ] ) && is_string( $_COOKIE[ $cookie ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) : '',
	];
	// phpcs:enable

	return array_values( array_unique( array_filter( $values ) ) );
}

/**
 * Where a key on wp-activate.php should go.
 *
 * @param string $key Activation key.
 * @return string|\WP_Error|null The activation page URL with the key, an
 *                               error when the signup cannot be activated
 *                               anywhere, or null to leave it to core.
 */
function get_wp_activate_redirect( string $key ) {
	$signup = get_signup_by_key( $key );
	if ( ! $signup ) {
		return null;
	}

	$meta    = Sites\signup_meta( $signup );
	$site_id = Sites\signup_site_id( $meta );
	$url     = $site_id ? Sites\activation_page_url( $site_id ) : '';

	if ( $url ) {
		return add_query_arg( 'key', rawurlencode( $key ), $url );
	}

	if ( Sites\is_form_signup( $meta ) ) {
		return new \WP_Error( 'activation_unavailable', __( 'This activation link cannot be used. Contact the site you signed up on for help.', 'hm-user-activation' ) );
	}

	return null;
}

/**
 * Drop core's wp-activate.php key cookie, which core scopes to that path.
 */
function clear_wp_activate_cookie(): void {
	$name = 'wp-activate-' . COOKIEHASH;

	if ( ! isset( $_COOKIE[ $name ] ) || headers_sent() ) {
		return;
	}

	[ $path ] = explode( '?', wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only used as the cookie path, as core does.

	setcookie( $name, ' ', time() - YEAR_IN_SECONDS, $path, COOKIE_DOMAIN, is_ssl(), true );
}

/**
 * Process an activation on the activation page.
 *
 * Three entry points:
 *  - POST        → process a manually submitted form (nonce verified).
 *  - GET ?key=…  → move the key into a scoped, HTTP-only cookie and redirect to
 *                  the clean permalink, so the key is not left in the URL.
 *  - Key cookie  → clear it, then process the stashed key using the site's
 *                  auto-login setting.
 */
function maybe_process_activation(): void {
	$page_id = (int) get_option( 'hm_activation_page_id' );

	if ( ! $page_id || ! is_page( $page_id ) || ! Sites\is_enabled() ) {
		return;
	}

	// In this mode a key only shows the form, and the form's POST activates.
	if ( CompleteAccount\is_enabled() ) {
		CompleteAccount\maybe_process( $page_id );
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

	$key = stash_url_key( $page_id );
	if ( $key ) {
		process( $key, (bool) get_option( 'hm_activation_auto_login', false ) );
		return;
	}

	// A key stashed from a previous request. It is read once and dropped
	// whatever the outcome, as core's wp-activate.php does, so a failed key is
	// not replayed on every later visit to the page. Only GET and HEAD are
	// redirected to the canonical, rate limited URL, so only they read it.
	$method = strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) );
	$key    = in_array( $method, [ 'GET', 'HEAD' ], true ) ? Security\get_key_cookie( Security\ACTIVATION_COOKIE ) : '';

	if ( $key ) {
		Security\clear_key_cookie( Security\ACTIVATION_COOKIE, $page_id );
		process( $key, (bool) get_option( 'hm_activation_auto_login', false ) );
	}
}

/**
 * Move a key in the URL into the key cookie and reload the page without it.
 *
 * This keeps the key out of browser history, Referer headers and access logs,
 * and stops it being shared by copying the address bar. Mirrors the way core
 * handles password reset keys on wp-login.php.
 *
 * @param int $page_id Activation page ID.
 * @return string The key when headers were already sent, so there is no cookie
 *                or redirect and this request has to use it. '' when the URL
 *                has no key.
 */
function stash_url_key( int $page_id ): string {
	$key = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );

	if ( ! $key || ! Security\set_key_cookie( Security\ACTIVATION_COOKIE, $key, $page_id ) ) {
		return $key;
	}

	// Target is our own permalink, not user input — see the note in
	// maybe_redirect_wp_activate() on wp_redirect() vs wp_safe_redirect().
	wp_redirect( get_permalink( $page_id ) ?: home_url( '/' ), 302 );
	exit;
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

	$signup = get_signup_by_key( $key );

	// A signup for another site is treated as unknown, so the page shows
	// nothing about it and its user is never added through this site.
	if ( $signup && Sites\is_other_site_signup( $signup ) ) {
		result( [
			'success'       => false,
			'error_code'    => 'invalid_key',
			'error_message' => invalid_key_message(),
		] );
		return;
	}

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

	// Core activates a site signup for an existing account with the same
	// username and returns that account, so the key holder must not be
	// logged in as it.
	$existing_user = $signup && username_exists( $signup->user_login );

	is_activating( true );
	$activation = wpmu_activate_signup( $key );
	is_activating( false );

	if ( is_wp_error( $activation ) ) {
		result( [
			'success'       => false,
			'error_code'    => $activation->get_error_code(),
			'error_message' => $activation->get_error_message(),
		] );
		return;
	}

	$user_id = (int) $activation['user_id'];
	$user    = get_user_by( 'id', $user_id );

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

	// An existing account keeps its own password, and its reset link must not
	// be shown to whoever holds this key.
	if ( $existing_user ) {
		$needs_password_setup = false;
	}

	// Generate a password reset key so the user can set their own password.
	// Uses the password reset page once it is published, otherwise core's wp-login.php.
	$reset_url = '';
	if ( $user && $needs_password_setup ) {
		$reset_key = get_password_reset_key( $user );
		if ( ! is_wp_error( $reset_key ) ) {
			$reset_url = PasswordReset\build_reset_url( $reset_key, $user->user_login );
		}
	}

	finish( $user_id, $meta, $reset_url, $needs_password_setup );

	// Log in last, so a wp_login handler that exits (two-factor plugins do)
	// cannot stop the welcome email. Never replace a session that is already
	// open: following someone else's activation link must not sign the visitor
	// out of their own account and into that one.
	if ( $auto_login && $user && ! $existing_user && ! is_user_logged_in() ) {
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		// Let security, audit and two-factor plugins see the login.
		do_action( 'wp_login', $user->user_login, $user );
	}
}

/**
 * Store a successful activation, send the welcome email and fire the activated action.
 *
 * @param int    $user_id              Activated user ID.
 * @param array  $meta                 Signup meta.
 * @param string $reset_url            Set-password link, or '' when there is none.
 * @param bool   $needs_password_setup Whether the user still has to set a password.
 */
function finish( int $user_id, array $meta, string $reset_url, bool $needs_password_setup ): void {
	$user = get_user_by( 'id', $user_id );

	result( [
		'success'   => true,
		'user_id'   => $user_id,
		'username'  => $user ? $user->user_login : '',
		'reset_url' => $reset_url,
		'login_url' => Emails\get_login_url( $user_id, $meta ),
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
 *
 * @param object|null $signup The signup row being checked, if known.
 */
function get_link_lifetime( ?object $signup = null ): int {
	/**
	 * Filters the activation link lifetime.
	 *
	 * @param int         $hours  Lifetime in hours. 0 means links never expire.
	 * @param object|null $signup The signup row being checked, or null if unknown.
	 */
	return max( 0, (int) apply_filters( 'hm_user_activation_link_lifetime', (int) get_option( 'hm_activation_link_lifetime', 0 ), $signup ) );
}

/**
 * Whether the activation page is activating a signup right now.
 *
 * @param bool|null $value Pass a value to set it.
 */
function is_activating( ?bool $value = null ): bool {
	static $activating = false;
	if ( $value !== null ) {
		$activating = $value;
	}
	return $activating;
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
	$lifetime = get_link_lifetime( $signup );
	if ( ! $lifetime ) {
		return false;
	}

	$registered = strtotime( $signup->registered . ' UTC' );

	return $registered && ( time() - $registered ) > $lifetime * HOUR_IN_SECONDS;
}

/**
 * Core's message for an unknown activation key.
 */
function invalid_key_message(): string {
	return __( 'Invalid activation key.' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- Core's own string, so it matches an unknown key exactly.
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
