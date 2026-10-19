<?php
/**
 * Resend a pending activation email.
 *
 * Provides resend() and handles the resend form block submission. The form
 * always shows the same response so it can't be used to find out which
 * email addresses have a pending registration.
 */

namespace HM\UserActivation\Resend;

use HM\UserActivation\Emails;

function bootstrap(): void {
	add_action( 'template_redirect', __NAMESPACE__ . '\\maybe_process' );
}

/**
 * Handle a resend form submission.
 */
function maybe_process(): void {
	if ( empty( $_POST['_hm_resend_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_hm_resend_nonce'] ) ), 'hm_activation_resend' ) ) {
		response( 'nonce_failed' );
		return;
	}

	$email = sanitize_email( wp_unslash( $_POST['resend_email'] ?? '' ) );
	if ( is_email( $email ) ) {
		resend( $email );
	}

	response( 'sent' );
}

/**
 * Replace the activation key of the newest pending user signup for an email
 * address and send a fresh activation email. The old key stops working.
 *
 * @param string $email Email address the user signed up with.
 * @return bool True if a pending signup was found and the email was sent.
 */
function resend( string $email ): bool {
	global $wpdb;

	$signup = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->signups} WHERE user_email = %s AND active = 0 AND domain = '' ORDER BY registered DESC LIMIT 1",
			$email
		)
	);

	if ( ! $signup ) {
		return false;
	}

	/**
	 * Filters whether an activation email can be resent for a signup.
	 *
	 * @param bool   $can_resend Whether to resend. Default true.
	 * @param object $signup     The pending row from the signups table.
	 */
	if ( ! apply_filters( 'hm_user_activation_can_resend', true, $signup ) ) {
		return false;
	}

	$key = substr( md5( time() . wp_rand() . $signup->user_email ), 0, 16 );

	$updated = $wpdb->update(
		$wpdb->signups,
		[
			'activation_key' => $key,
			'registered'     => current_time( 'mysql', true ),
		],
		[ 'signup_id' => $signup->signup_id ]
	);

	if ( ! $updated ) {
		return false;
	}

	Emails\send_activation_email( $signup->user_email, $key, $signup->user_login, (array) maybe_unserialize( $signup->meta ) );

	return true;
}

// -------------------------------------------------------------------------
// State — response() acts as both getter and setter.
// -------------------------------------------------------------------------

/**
 * Get or set the response for the current request: 'sent', 'nonce_failed' or ''.
 */
function response( ?string $value = null ): string {
	static $response = '';
	if ( $value !== null ) {
		$response = $value;
	}
	return $response;
}

function has_response(): bool {
	return response() !== '';
}

/**
 * Message to show for the current response.
 */
function get_response_message(): string {
	switch ( response() ) {
		case 'sent':
			return __( "If there's a pending registration for that address, we've sent a new activation link.", 'hm-user-activation' );

		case 'nonce_failed':
			return __( 'Security check failed. Please refresh the page and try again.', 'hm-user-activation' );

		default:
			return '';
	}
}
