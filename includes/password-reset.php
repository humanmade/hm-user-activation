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
 */
function maybe_process(): void {
	$page_id = (int) get_option( 'hm_activation_password_reset_page_id' );

	if ( ! $page_id || ! is_page( $page_id ) ) {
		return;
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

		$key   = sanitize_text_field( wp_unslash( $_POST['rp_key']   ?? '' ) );
		$login = sanitize_text_field( wp_unslash( $_POST['rp_login'] ?? '' ) );
		$pass1 = wp_unslash( $_POST['pass1'] ?? '' );
		$pass2 = wp_unslash( $_POST['pass2'] ?? '' );

		process_password_change( $key, $login, $pass1, $pass2 );
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
	}
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

	$user = str_contains( $email_or_login, '@' )
		? get_user_by( 'email', $email_or_login )
		: get_user_by( 'login', $email_or_login );

	// Always show success to avoid revealing whether the account exists.
	if ( $user ) {
		$key = get_password_reset_key( $user );
		if ( ! is_wp_error( $key ) ) {
			Emails\send_password_reset_email( $user, $key );
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
		result( [
			'success'       => false,
			'mode'          => 'reset',
			'error_code'    => $user->get_error_code(),
			'error_message' => $user->get_error_message(),
		] );
		return;
	}

	reset_password( $user, $pass1 );
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
