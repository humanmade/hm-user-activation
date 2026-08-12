<?php
/**
 * Shared hardening helpers for the registration, activation, login and
 * password reset pages.
 *
 * These pages handle single-use secrets (activation keys, password reset keys)
 * and render account details, so they need the same treatment core gives
 * wp-login.php, wp-signup.php and wp-activate.php:
 *
 *  - Secrets are never left in URLs. A key arriving in a query string is moved
 *    into a short-lived, HTTP-only cookie and the browser is redirected to the
 *    clean permalink, keeping the key out of browser history, Referer headers,
 *    proxy logs and copy-pasted links. This mirrors core's password reset flow.
 *  - Responses are never cached, publicly or privately, and are not indexed.
 *  - Submissions are rate limited per client so keys cannot be brute forced and
 *    the forms cannot be used to send mail in bulk.
 */

namespace HM\UserActivation\Security;

/**
 * Cookie name prefixes. The COOKIEHASH suffix is added at runtime so installs
 * sharing a domain do not collide, exactly as core does for its own cookies.
 */
const ACTIVATION_COOKIE = 'hm-activate';
const RESET_COOKIE      = 'hm-resetpass';

function bootstrap(): void {
	// Never cache or index pages that carry account state.
	add_action( 'template_redirect', __NAMESPACE__ . '\\protect_sensitive_pages', 0 );
	add_action( 'wp_head', __NAMESPACE__ . '\\sensitive_page_meta', 1 );
}

// -------------------------------------------------------------------------
// Cache and indexing protection
// -------------------------------------------------------------------------

/**
 * IDs of the pages this plugin treats as sensitive.
 *
 * @return int[]
 */
function sensitive_page_ids(): array {
	$ids = [
		(int) get_option( 'hm_activation_registration_page_id' ),
		(int) get_option( 'hm_activation_page_id' ),
		(int) get_option( 'hm_activation_password_reset_page_id' ),
		(int) get_option( 'hm_activation_login_page_id' ),
	];

	return array_values( array_filter( $ids ) );
}

/**
 * Whether the current request is for one of those pages.
 */
function is_sensitive_page(): bool {
	if ( ! is_page() ) {
		return false;
	}

	return in_array( (int) get_queried_object_id(), sensitive_page_ids(), true );
}

/**
 * Stop sensitive pages being cached or indexed.
 *
 * The activation success state renders a one-time password reset link and the
 * account's username, so a full-page cache storing that response would serve
 * one user's credentials to the next visitor. DONOTCACHEPAGE is respected by
 * Batcache and the common page caching plugins; nocache_headers() covers
 * browsers and shared proxies.
 */
function protect_sensitive_pages(): void {
	if ( ! is_sensitive_page() ) {
		return;
	}

	nocache_headers();

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	if ( function_exists( 'wp_robots_sensitive_page' ) ) {
		add_filter( 'wp_robots', 'wp_robots_sensitive_page' );
	}
}

/**
 * Mirror core's wp_sensitive_page_meta() output on our pages.
 */
function sensitive_page_meta(): void {
	if ( ! is_sensitive_page() ) {
		return;
	}

	echo "<meta name='referrer' content='strict-origin-when-cross-origin' />\n";

	// Older installs, or themes not using wp_robots(), still need the tag.
	if ( ! function_exists( 'wp_robots_sensitive_page' ) ) {
		echo "<meta name='robots' content='noindex,noarchive' />\n";
	}
}

// -------------------------------------------------------------------------
// Key cookies — keep single-use secrets out of URLs
// -------------------------------------------------------------------------

/**
 * Full cookie name for a prefix.
 */
function cookie_name( string $prefix ): string {
	return $prefix . '-' . COOKIEHASH;
}

/**
 * Store a secret in a session cookie scoped to a single page.
 *
 * @param string $prefix  One of the *_COOKIE constants.
 * @param string $value   Value to store.
 * @param int    $page_id Page the cookie is scoped to.
 */
function set_key_cookie( string $prefix, string $value, int $page_id ): void {
	$name = cookie_name( $prefix );

	// A theme printing output before template_redirect would make this a PHP
	// warning rather than a cookie; the flow falls back to the key-entry form.
	if ( headers_sent() ) {
		return;
	}

	setcookie( $name, $value, [
		'expires'  => 0,
		'path'     => cookie_path( $page_id ),
		'domain'   => COOKIE_DOMAIN ?: '',
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	] );

	// Make it readable within this request too.
	$_COOKIE[ $name ] = $value;
}

/**
 * Read a stored secret, or an empty string when absent.
 */
function get_key_cookie( string $prefix ): string {
	$name = cookie_name( $prefix );

	if ( empty( $_COOKIE[ $name ] ) ) {
		return '';
	}

	return sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
}

/**
 * Delete a stored secret.
 */
function clear_key_cookie( string $prefix, int $page_id ): void {
	$name = cookie_name( $prefix );

	unset( $_COOKIE[ $name ] );

	if ( headers_sent() ) {
		return;
	}

	setcookie( $name, ' ', [
		'expires'  => time() - YEAR_IN_SECONDS,
		'path'     => cookie_path( $page_id ),
		'domain'   => COOKIE_DOMAIN ?: '',
		'secure'   => is_ssl(),
		'httponly' => true,
		'samesite' => 'Lax',
	] );
}

/**
 * Path a key cookie is scoped to — the page's own path, so the secret is not
 * sent with every request to the site.
 */
function cookie_path( int $page_id ): string {
	$permalink = $page_id ? get_permalink( $page_id ) : '';
	$path      = $permalink ? wp_parse_url( $permalink, PHP_URL_PATH ) : '';

	return $path ?: '/';
}

// -------------------------------------------------------------------------
// Rate limiting
// -------------------------------------------------------------------------

/**
 * Count an attempt and report whether the client has exceeded the limit.
 *
 * The window slides on every attempt, including blocked ones, so hammering the
 * endpoint keeps the client locked out rather than letting it retry the moment
 * the first window lapses.
 *
 * @param string $action Identifier for the thing being limited.
 * @param int    $limit  Attempts permitted within the window.
 * @param int    $window Window length in seconds.
 * @return bool True when the client is over the limit and should be refused.
 */
function is_rate_limited( string $action, int $limit, int $window ): bool {
	/**
	 * Filter the number of attempts permitted within the window.
	 *
	 * Return 0 or less to disable rate limiting for this action.
	 *
	 * @param int    $limit  Attempts permitted.
	 * @param string $action Action identifier.
	 */
	$limit = (int) apply_filters( 'hm_user_activation_rate_limit', $limit, $action );

	if ( $limit <= 0 ) {
		return false;
	}

	/**
	 * Filter the rate limit window in seconds.
	 *
	 * @param int    $window Window length.
	 * @param string $action Action identifier.
	 */
	$window = (int) apply_filters( 'hm_user_activation_rate_limit_window', $window, $action );

	$transient = 'hm_ua_rl_' . md5( $action . '|' . client_fingerprint() );
	$attempts  = (int) get_transient( $transient ) + 1;

	set_transient( $transient, $attempts, max( 60, $window ) );

	return $attempts > $limit;
}

/**
 * An opaque, hashed identifier for the current client.
 *
 * REMOTE_ADDR only: forwarded-for headers are attacker controlled unless the
 * stack is known to rewrite them, and the value is hashed so raw addresses are
 * not written to the options table.
 */
function client_fingerprint(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

	/**
	 * Filter the client identifier used for rate limiting.
	 *
	 * Sites behind a trusted proxy can substitute the real client address here.
	 *
	 * @param string $ip Client address.
	 */
	$ip = (string) apply_filters( 'hm_user_activation_client_ip', $ip );

	return wp_hash( $ip );
}

/**
 * The message shown whenever a client is rate limited.
 *
 * Deliberately identical for every action so it reveals nothing about whether
 * the submitted username, email or key was valid.
 */
function rate_limit_message(): string {
	return __( 'Too many attempts. Please wait a few minutes and try again.', 'hm-user-activation' );
}

// -------------------------------------------------------------------------
// Mail header sanitisation
// -------------------------------------------------------------------------

/**
 * Make a value safe to interpolate into a mail header.
 *
 * The from name and subject come from editable options, so a value containing
 * newlines would otherwise allow additional headers to be injected.
 */
function sanitize_header_value( string $value ): string {
	$value = preg_replace( '/[\r\n\t]+/', ' ', $value );
	$value = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value );

	return trim( (string) $value );
}
