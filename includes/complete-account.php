<?php
/**
 * "Complete your account" activation mode.
 *
 * When the hm_activation_complete_profile option is on, an activation link
 * does not activate the account. It shows a form where the user chooses a
 * password, and sites can add their own fields. The account is activated
 * only when that form is posted and valid. The user is then sent to the log
 * in page rather than logged in, so any login step a site enforces, such as
 * two-factor enrolment, still runs.
 */

namespace HM\UserActivation\CompleteAccount;

use HM\UserActivation\Activation;
use HM\UserActivation\Emails;
use HM\UserActivation\PasswordReset;
use HM\UserActivation\Resend;
use HM\UserActivation\Security;

/**
 * Query arg added to the log in URL after an account is completed.
 */
const QUERY_ARG = 'hm_activation_complete';

/**
 * POST fields used by the form itself, which are never passed to hooks.
 */
const FORM_FIELDS = [
	'pass1',
	'pass2',
	'activation_key',
	'_hm_complete_nonce',
	'_wp_http_referer',
];

function bootstrap(): void {
	add_filter( 'login_message', __NAMESPACE__ . '\\filter_login_message' );
}

/**
 * Whether the mode is on for the current site.
 */
function is_enabled(): bool {
	return (bool) get_option( 'hm_activation_complete_profile', false );
}

/**
 * Handle a request to the activation page in this mode.
 *
 * - POST from the completion form → validate and activate.
 * - POST from the key entry form  → show the completion form for that key.
 * - GET ?key=…                    → stash the key in the cookie and redirect,
 *                                   as in the default mode.
 * - Key cookie (GET or HEAD)      → show the completion form for that key.
 *
 * None of these activate except the completion form's POST.
 *
 * @param int $page_id Activation page ID.
 */
function maybe_process( int $page_id ): void {
	if ( ! empty( $_POST['_hm_complete_nonce'] ) ) {
		process_submission( $page_id );
		return;
	}

	if ( ! empty( $_POST['_hm_activation_nonce'] ) ) {
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_hm_activation_nonce'] ) ), 'hm_activation' ) ) {
			Activation\result( [
				'success'       => false,
				'error_code'    => 'nonce_failed',
				'error_message' => nonce_failed_message(),
			] );
			return;
		}

		$key = sanitize_text_field( wp_unslash( $_POST['activation_key'] ?? '' ) );

		if ( ! $key ) {
			Activation\result( [
				'success'       => false,
				'error_code'    => 'empty_key',
				'error_message' => __( 'Please enter your activation key.', 'hm-user-activation' ),
			] );
			return;
		}

		show_form( $key, $page_id );
		return;
	}

	$key = Activation\stash_url_key( $page_id );

	// Unlike the default mode the cookie is kept, so a reload shows the form
	// again. It is cleared once the key is used or found to be unusable.
	$method = strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) );
	if ( ! $key && in_array( $method, [ 'GET', 'HEAD' ], true ) ) {
		$key = Security\get_key_cookie( Security\ACTIVATION_COOKIE );
	}

	if ( $key ) {
		show_form( $key, $page_id );
	}
}

/**
 * Show the completion form for a key, or the error state when it can't be used.
 *
 * @param string $key     Activation key.
 * @param int    $page_id Activation page ID.
 */
function show_form( string $key, int $page_id ): void {
	$signup = check_key( $key );

	if ( is_wp_error( $signup ) ) {
		fail( $signup, $page_id );
		return;
	}

	state( [
		'key'    => $key,
		'signup' => $signup,
		'errors' => [],
		'fields' => [],
	] );
}

/**
 * Validate the completion form, then activate the account and set its password.
 *
 * The key comes from the form, not the key cookie, because a key typed into
 * the key entry form never had a cookie. It is checked again here, so the
 * form cannot activate a signup that is used, expired or unknown.
 *
 * @param int $page_id Activation page ID.
 */
function process_submission( int $page_id ): void {
	$key    = sanitize_text_field( wp_unslash( $_POST['activation_key'] ?? '' ) );
	$signup = check_key( $key );

	if ( is_wp_error( $signup ) ) {
		fail( $signup, $page_id );
		return;
	}

	// Posted values are not trusted after a failed nonce, so the form is
	// shown again without them.
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_hm_complete_nonce'] ?? '' ) ), 'hm_activation_complete' ) ) {
		state( [
			'key'    => $key,
			'signup' => $signup,
			'errors' => [ nonce_failed_message() ],
			'fields' => [],
		] );
		return;
	}

	// Left slashed, as wp-login.php does. Core hashes passwords with the
	// request's slashes intact and wp_signon() checks them that way, so an
	// unslashed password containing a quote or backslash could never log in.
	// Extra fields are passed to sites raw and unslashed: validating and
	// sanitising them is the site's job, as they are the site's fields.
	// phpcs:disable HM.Security.ValidatedSanitizedInput, WordPress.Security.ValidatedSanitizedInput
	$pass1  = is_string( $_POST['pass1'] ?? null ) ? $_POST['pass1'] : '';
	$pass2  = is_string( $_POST['pass2'] ?? null ) ? $_POST['pass2'] : '';
	$fields = array_diff_key( wp_unslash( $_POST ), array_flip( FORM_FIELDS ) );
	// phpcs:enable HM.Security.ValidatedSanitizedInput, WordPress.Security.ValidatedSanitizedInput

	$meta   = signup_meta( $signup );
	$errors = PasswordReset\validate_new_password( signup_user( $signup ), $pass1, $pass2 );

	/**
	 * Filters the errors for the completion form, so a site can validate its own fields.
	 *
	 * Add errors to $errors and return it. Any error keeps the form on the
	 * page with the messages shown and the posted values passed back to
	 * hm_user_activation_complete_account_fields.
	 *
	 * @param \WP_Error $errors Errors found so far, including password errors.
	 * @param array     $fields Posted fields, unslashed and not sanitised, without the password fields.
	 * @param object    $signup Signup row.
	 * @param array     $meta   Signup meta.
	 */
	$filtered = apply_filters( 'hm_user_activation_complete_account_errors', $errors, $fields, $signup, $meta );
	if ( $filtered instanceof \WP_Error ) {
		$errors = $filtered;
	}

	if ( $errors->has_errors() ) {
		state( [
			'key'    => $key,
			'signup' => $signup,
			'errors' => $errors->get_error_messages(),
			'fields' => $fields,
		] );
		return;
	}

	Resend\clear_counter( $signup );

	$activation = wpmu_activate_signup( $key );

	if ( is_wp_error( $activation ) ) {
		fail( $activation, $page_id );
		return;
	}

	$user_id = (int) $activation['user_id'];

	// The same value and call that reset_password() uses, without the
	// password change email it sends to the site admin. A password set by a
	// wpmu_activate_user hook is replaced: the user's choice wins.
	wp_set_password( $pass1, $user_id );
	update_user_meta( $user_id, 'default_password_nag', false );

	Security\clear_key_cookie( Security\ACTIVATION_COOKIE, $page_id );

	$meta = (array) ( $activation['meta'] ?? [] );
	unset( $meta[ Resend\META_KEY ] );

	/**
	 * Fires after an account was activated from the completion form, before the welcome email.
	 *
	 * Use it to save the site's own fields, for example names or consent.
	 *
	 * @param int   $user_id Activated user ID.
	 * @param array $meta    Signup meta.
	 * @param array $fields  Posted fields, unslashed and not sanitised, without the password fields.
	 */
	do_action( 'hm_user_activation_complete_account', $user_id, $meta, $fields );

	// The password is set, so the welcome email has no set-password step.
	Activation\finish( $user_id, $meta, '', false );

	// The log in URL is the admin's setting or a filter, not user input, and
	// can be on another host of a subdomain network, so wp_safe_redirect()
	// would send the user to wp-admin instead.
	wp_redirect( add_query_arg( QUERY_ARG, '1', Emails\get_login_url( $user_id, $meta ) ), 303 );
	exit;
}

/**
 * The signup for a key, or an error when it can't be completed.
 *
 * Unknown and already used keys get the same message, so the form says
 * nothing about which keys exist.
 *
 * @param string $key Activation key.
 * @return object|\WP_Error
 */
function check_key( string $key ) {
	$signup = $key ? Activation\get_signup_by_key( $key ) : null;

	if ( ! $signup || (int) $signup->active ) {
		return new \WP_Error( 'invalid_key', __( 'This activation link is not valid. It may have been used already. Request a new one below.', 'hm-user-activation' ) );
	}

	if ( Activation\is_expired( $signup ) ) {
		return new \WP_Error( 'expired_key', __( 'This activation link has expired. Request a new one below.', 'hm-user-activation' ) );
	}

	return $signup;
}

/**
 * Show the activation error state, with the resend form, and drop the key cookie.
 *
 * @param \WP_Error $error   The error.
 * @param int       $page_id Activation page ID.
 */
function fail( \WP_Error $error, int $page_id ): void {
	Security\clear_key_cookie( Security\ACTIVATION_COOKIE, $page_id );

	Activation\result( [
		'success'       => false,
		'error_code'    => $error->get_error_code(),
		'error_message' => $error->get_error_message(),
	] );
}

/**
 * The signup's meta, without the plugin's own resend counter.
 *
 * @param object $signup Signup row.
 */
function signup_meta( object $signup ): array {
	$meta = maybe_unserialize( $signup->meta );
	$meta = is_array( $meta ) ? $meta : [];
	unset( $meta[ Resend\META_KEY ] );

	return $meta;
}

/**
 * A stand-in user for password hooks that run before the account exists.
 *
 * validate_password_reset expects a WP_User. This one has ID 0 and only the
 * login and email from the signup, so a policy plugin that reads user meta
 * or roles finds none.
 *
 * @param object $signup Signup row.
 */
function signup_user( object $signup ): \WP_User {
	return new \WP_User( (object) [
		'ID'         => 0,
		'user_login' => $signup->user_login,
		'user_email' => $signup->user_email,
	] );
}

function nonce_failed_message(): string {
	return __( 'Security check failed. Please refresh the page and try again.', 'hm-user-activation' );
}

/**
 * Show "Your account is ready" on wp-login.php after an account was completed.
 *
 * @param string $message Login message HTML.
 */
function filter_login_message( $message ) {
	if ( empty( $_GET[ QUERY_ARG ] ) ) {
		return $message;
	}

	return $message . '<p class="message">' . esc_html( ready_message() ) . '</p>';
}

function ready_message(): string {
	return __( 'Your account is ready. Log in.', 'hm-user-activation' );
}

// -------------------------------------------------------------------------
// State — state() acts as both getter and setter.
// -------------------------------------------------------------------------

/**
 * The completion form waiting on this request, if any.
 *
 * @param array{key: string, signup: object, errors: string[], fields: array}|null $value
 * @return array{key: string, signup: object, errors: string[], fields: array}|null
 */
function state( ?array $value = null ): ?array {
	static $state = null;
	if ( func_num_args() ) {
		$state = $value;
	}
	return $state;
}

function is_pending(): bool {
	return state() !== null;
}
