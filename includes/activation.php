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

function bootstrap(): void {
	// Redirect wp-activate.php requests to our activation page.
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\maybe_redirect_wp_activate', 1 );

	// Process an activation form submission on the activation page.
	add_action( 'template_redirect', __NAMESPACE__ . '\\maybe_process_activation' );

	// Conditionally hide error/success group variants when irrelevant.
	add_filter( 'render_block', __NAMESPACE__ . '\\filter_block_visibility', 10, 2 );
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

	wp_redirect( $url, 301 );
	exit;
}

/**
 * Process an activation on the activation page.
 *
 * Two entry points:
 *  - GET ?key=…  → auto-process immediately using the site default auto-login setting.
 *  - POST        → process a manually submitted form (nonce verified).
 */
function maybe_process_activation(): void {
	$page_id = (int) get_option( 'hm_activation_page_id' );

	if ( ! $page_id || ! is_page( $page_id ) ) {
		return;
	}

	// GET: key present in URL — activate immediately, no form needed.
	if ( isset( $_GET['key'] ) && ! isset( $_POST['_hm_activation_nonce'] ) ) {
		$key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		if ( $key ) {
			process( $key, (bool) get_option( 'hm_activation_auto_login', false ) );
		}
		return;
	}

	// POST: manual form submission.
	if ( empty( $_POST['_hm_activation_nonce'] ) ) {
		return;
	}

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

	$activation = wpmu_activate_signup( $key );

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

	if ( $auto_login ) {
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );
	}

	// Generate a password reset key so the user can set their own password.
	$reset_url = '';
	if ( $user ) {
		$reset_page_id = (int) get_option( 'hm_activation_password_reset_page_id' );
		if ( $reset_page_id ) {
			$reset_key = get_password_reset_key( $user );
			if ( ! is_wp_error( $reset_key ) ) {
				$reset_url = PasswordReset\build_reset_url( $reset_key, $user->user_login );
			}
		}
	}

	result( [
		'success'   => true,
		'user_id'   => $user_id,
		'username'  => $user ? $user->user_login : '',
		'reset_url' => $reset_url,
		'meta'      => $activation['meta'] ?? [],
	] );

	if ( (bool) get_option( 'hm_activation_welcome_email_enabled', true ) ) {
		Emails\send_welcome_email( $user_id, $reset_url );
	}
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

	$page_id = (int) get_option( 'hm_activation_page_id' );
	if ( ! $page_id || (int) get_queried_object_id() !== $page_id ) {
		return $block_content;
	}

	$variation = $parsed_block['attrs']['metadata']['variationName'] ?? '';

	if ( $variation === 'hm-user-activation/errors' && ! is_error() ) {
		return '';
	}

	if ( $variation === 'hm-user-activation/success' && ! is_success() ) {
		return '';
	}

	return $block_content;
}

// -------------------------------------------------------------------------
// State — result() acts as both getter and setter.
// Call result( $value ) to set, result() to get.
// -------------------------------------------------------------------------

/**
 * @param array{success: bool, user_id?: int, username?: string, reset_url?: string, meta?: array, error_message?: string, error_code?: string}|null $value
 * @return array{success: bool, user_id?: int, username?: string, reset_url?: string, meta?: array, error_message?: string, error_code?: string}|null
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
