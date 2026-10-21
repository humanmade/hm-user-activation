<?php
/**
 * Registration flow.
 *
 * Keeps user registration inside the site rather than sending visitors to the
 * network's wp-signup.php. Mirrors the structure of the activation and password
 * reset modules: a result() state store, group-variation visibility filtering,
 * and URL filters so any "register" links across the site point here.
 *
 * Registration itself is delegated to core's multisite signup functions —
 * wpmu_validate_user_signup() for validation and wpmu_signup_user() to create
 * the signup row — so the resulting activation email is the one this plugin
 * already customises via the wpmu_signup_user_notification filter.
 */

namespace HM\UserActivation\Registration;

use HM\UserActivation\Emails;
use HM\UserActivation\Resend;

function bootstrap(): void {
	// Redirect wp-signup.php requests to our registration page.
	add_action( 'before_signup_header', __NAMESPACE__ . '\\maybe_redirect_wp_signup' );

	// Process a registration form submission on the registration page.
	add_action( 'template_redirect', __NAMESPACE__ . '\\maybe_process' );

	// Conditionally hide error/success group variants when irrelevant.
	add_filter( 'render_block', __NAMESPACE__ . '\\filter_block_visibility', 10, 2 );

	// Point "register" links at our page.
	add_filter( 'register_url', __NAMESPACE__ . '\\filter_register_url' );
	add_filter( 'wp_signup_location', __NAMESPACE__ . '\\filter_signup_location' );
}

/**
 * Redirect requests to the network's wp-signup.php to our registration page.
 *
 * Fires on before_signup_header, which wp-signup.php runs before any output.
 * Note that wp-signup.php redirects to the main site first, so this only takes
 * effect where the plugin is active on the network's main site; in-site links
 * are handled by the register_url and wp_signup_location filters regardless.
 */
function maybe_redirect_wp_signup(): void {
	// A logged-in user on wp-signup.php is creating a site, not an account.
	if ( is_user_logged_in() ) {
		return;
	}

	// Only take over when the network allows user accounts and nothing else.
	// With 'all', wp-signup.php also offers site signups, so it stays with core.
	if ( active_signup() !== 'user' || ! is_registration_enabled() ) {
		return;
	}

	// Core's own form posts back to wp-signup.php with a stage. In same
	// response mode that form would still show core's errors, so it is
	// redirected too.
	// phpcs:ignore WordPress.Security.NonceVerification -- Presence check only; core verifies its own form.
	if ( ( ! empty( $_GET['new'] ) || ! empty( $_POST['stage'] ) ) && ! hides_account_state() ) {
		return;
	}

	$url = page_url();

	if ( ! $url ) {
		return;
	}

	wp_safe_redirect( $url, 302 );
	exit;
}

/**
 * Handle a registration form submission on the registration page.
 */
function maybe_process(): void {
	$page_id = (int) get_option( 'hm_activation_registration_page_id' );

	if ( ! $page_id || ! is_page( $page_id ) ) {
		return;
	}

	if ( empty( $_POST['_hm_register_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_hm_register_nonce'] ) ), 'hm_register' ) ) {
		result( [
			'success'       => false,
			'error_code'    => 'nonce_failed',
			'error_message' => __( 'Security check failed. Please refresh the page and try again.', 'hm-user-activation' ),
		] );
		return;
	}

	// Passed through unsanitised so wpmu_validate_user_signup() rejects input
	// that sanitising would change, rather than accepting a different value.
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$user_name  = wp_unslash( $_POST['user_name'] ?? '' );
	$user_email = wp_unslash( $_POST['user_email'] ?? '' );
	// phpcs:enable

	process(
		is_string( $user_name ) ? $user_name : '',
		is_string( $user_email ) ? $user_email : ''
	);
}

/**
 * Validate and create a user signup.
 *
 * Validation is delegated to wpmu_validate_user_signup(), which covers illegal
 * names, banned email domains, reserved usernames, already-registered users and
 * pending signups. On success wpmu_signup_user() stores the signup and triggers
 * the activation email.
 */
function process( string $user_name, string $user_email ): void {
	static $processed = false;
	if ( $processed ) {
		return;
	}
	$processed = true;

	if ( is_user_logged_in() ) {
		result( [
			'success'       => false,
			'error_code'    => 'already_logged_in',
			'error_message' => __( 'You are already logged in.', 'hm-user-activation' ),
		] );
		return;
	}

	if ( ! is_registration_enabled() ) {
		result( [
			'success'       => false,
			'error_code'    => 'registration_closed',
			'error_message' => __( 'Registration is not currently open.', 'hm-user-activation' ),
		] );
		return;
	}

	// In same response mode, the errors that say whether the email address is
	// known are taken out before any other validation hook sees them.
	$email_state = '';
	$hide_state  = static function ( $result ) use ( &$email_state ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}
		$email_state = strip_account_state( $result );
		return $result;
	};

	$hide = hides_account_state();
	if ( $hide ) {
		add_filter( 'wpmu_validate_user_signup', $hide_state, PHP_INT_MIN );
	}

	$validation = wpmu_validate_user_signup( $user_name, $user_email );

	if ( $hide ) {
		remove_filter( 'wpmu_validate_user_signup', $hide_state, PHP_INT_MIN );
	}

	/** @var \WP_Error $errors */
	$errors = $validation['errors'];

	if ( $errors->has_errors() ) {
		// A single field can carry more than one message (e.g. an invalid *and*
		// already-taken username), so keep them all against their field.
		$field_errors = [];
		foreach ( $errors->get_error_codes() as $code ) {
			$field_errors[ $code ] = implode( ' ', $errors->get_error_messages( $code ) );
		}

		result( [
			'success'       => false,
			'error_code'    => $errors->get_error_code(),
			'error_message' => implode( ' ', array_values( $field_errors ) ),
			'errors'        => $errors,
			'field_errors'  => $field_errors,
			'user_name'     => $user_name,
			'user_email'    => $user_email,
		] );
		return;
	}

	$user_name  = $validation['user_name'];
	$user_email = $validation['user_email'];

	// The address already has an account or a pending signup. Respond as if a
	// signup was made, and tell the address's owner by email instead.
	if ( $email_state ) {
		if ( $email_state === 'account' ) {
			$user = get_user_by( 'email', $user_email );
			if ( $user ) {
				send_existing_account_email( $user );
			}
		} elseif ( ! Resend\ip_limit_reached() ) {
			Resend\resend( $user_email );
		}

		result( [
			'success'    => true,
			'user_name'  => $user_name,
			'user_email' => $user_email,
		] );
		return;
	}

	/**
	 * Filter the meta stored against the signup.
	 *
	 * Starts with add_to_blog and new_role so core's add_new_user_to_blog()
	 * makes the user a member of this site on activation. Core's
	 * add_signup_meta filter is applied first, then a plugin-specific one.
	 *
	 * @param array  $meta       Signup meta.
	 * @param string $user_name  Sanitised username.
	 * @param string $user_email Sanitised email address.
	 */
	$meta = apply_filters( 'add_signup_meta', [
		'add_to_blog' => get_current_blog_id(),
		'new_role'    => get_option( 'default_role' ),
	] );
	$meta = apply_filters( 'hm_user_activation_signup_meta', $meta, $user_name, $user_email );

	wpmu_signup_user( $user_name, $user_email, $meta );

	/**
	 * Fires after a signup has been created through the registration block.
	 *
	 * @param string $user_name  Sanitised username.
	 * @param string $user_email Sanitised email address.
	 * @param array  $meta       Signup meta.
	 */
	do_action( 'hm_user_activation_registered', $user_name, $user_email, $meta );

	result( [
		'success'    => true,
		'user_name'  => $user_name,
		'user_email' => $user_email,
	] );
}

/**
 * Whether user registration is open on this network.
 *
 * The network setting lives at Network Admin → Settings → Registration
 * Settings; 'user' and 'all' both permit user accounts to be created.
 */
function is_registration_enabled(): bool {
	$setting = active_signup();
	$enabled = in_array( $setting, [ 'user', 'all' ], true );

	/**
	 * Filter whether registration is open.
	 *
	 * @param bool   $enabled Whether registration is permitted.
	 * @param string $setting The network registration setting.
	 */
	return (bool) apply_filters( 'hm_user_activation_registration_enabled', $enabled, $setting );
}

/**
 * The network registration setting after core's wpmu_active_signup filter:
 * 'none', 'user', 'blog' or 'all'.
 */
function active_signup(): string {
	/** This filter is documented in wp-signup.php */
	return (string) apply_filters( 'wpmu_active_signup', get_site_option( 'registration', 'none' ) );
}

/**
 * Whether the form responds the same way whether or not an email address
 * already has an account or a pending signup.
 */
function hides_account_state(): bool {
	/**
	 * Filters whether the registration form hides which email addresses have an account or a pending signup.
	 *
	 * @param bool $hide Defaults to the "Same response for known addresses" setting.
	 */
	return (bool) apply_filters(
		'hm_user_activation_registration_hide_account_state',
		(bool) get_option( 'hm_activation_registration_hide_account_state', false )
	);
}

/**
 * Take the errors that say whether an email address is known out of a
 * wpmu_validate_user_signup() result.
 *
 * These are core's "already registered" and "pending activation" errors for
 * the email address, and its "reserved" error for a username held by a
 * pending signup. The last one matters because a new address reserves the
 * username it signed up with and a known address does not, so a second
 * signup with that username would tell them apart. In this mode two pending
 * signups can share a username, and the first to activate gets it.
 *
 * Runs first on the wpmu_validate_user_signup filter, so every error so far
 * is core's. Core makes these checks last for each field, in this order, so
 * their messages are the last ones under user_email and user_name. The checks
 * are repeated here to know how many to take out, and the rest are left in
 * place so the response does not change. Messages are not compared, because
 * core's wording changes between versions.
 *
 * @param array $result Validation result. Its errors are changed in place.
 * @return string 'account' or 'pending' for a known email address, or ''.
 */
function strip_account_state( array &$result ): string {
	global $wpdb;

	$errors = $result['errors'] ?? null;
	if ( ! $errors instanceof \WP_Error ) {
		return '';
	}

	$email = (string) ( $result['user_email'] ?? '' );
	$login = (string) ( $result['user_name'] ?? '' );

	// Core has already deleted signups older than two days, so a row left here
	// is one it reported.
	$account  = $email !== '' && email_exists( $email );
	$pending  = $email !== '' && $wpdb->get_var(
		$wpdb->prepare( "SELECT signup_id FROM {$wpdb->signups} WHERE user_email = %s LIMIT 1", $email )
	);
	$reserved = $login !== '' && $wpdb->get_var(
		$wpdb->prepare( "SELECT signup_id FROM {$wpdb->signups} WHERE user_login = %s LIMIT 1", $login )
	);

	drop_last_errors( $errors, 'user_email', (int) $account + (int) (bool) $pending );
	drop_last_errors( $errors, 'user_name', (int) (bool) $reserved );

	if ( $account ) {
		return 'account';
	}

	return $pending ? 'pending' : '';
}

/**
 * Remove the last messages for an error code, keeping the order of the rest.
 *
 * @param \WP_Error $errors Errors to change.
 * @param string    $code   Error code.
 * @param int       $count  How many messages to remove from the end.
 */
function drop_last_errors( \WP_Error $errors, string $code, int $count ): void {
	if ( $count < 1 || empty( $errors->errors[ $code ] ) ) {
		return;
	}

	array_splice( $errors->errors[ $code ], -min( $count, count( $errors->errors[ $code ] ) ) );

	if ( ! $errors->errors[ $code ] ) {
		$errors->remove( $code );
	}
}

/**
 * Tell an account's owner that someone tried to register with its email
 * address. Sent at most once per interval for each address.
 *
 * @param \WP_User $user The account with the address.
 */
function send_existing_account_email( \WP_User $user ): void {
	/**
	 * Filters the minimum time between "you already have an account" emails to one address.
	 *
	 * @param int $seconds Default one hour. 0 turns the limit off.
	 */
	$interval = max( 0, (int) apply_filters( 'hm_user_activation_existing_account_email_interval', HOUR_IN_SECONDS ) );
	$throttle = 'hmua_account_notice_' . md5( strtolower( $user->user_email ) );

	if ( $interval && get_site_transient( $throttle ) ) {
		return;
	}

	/**
	 * Filters the "you already have an account" email.
	 *
	 * @param array|false $email {
	 *     The email, or false to send nothing.
	 *
	 *     @type string   $to      Recipient.
	 *     @type string   $subject Subject.
	 *     @type string   $message Plain text body.
	 *     @type string[] $headers Mail headers.
	 * }
	 * @param \WP_User    $user  The account with the address.
	 */
	$email = apply_filters( 'hm_user_activation_existing_account_email', Emails\existing_account_email( $user ), $user );
	if ( ! is_array( $email ) || empty( $email['to'] ) ) {
		return;
	}

	if ( $interval ) {
		set_site_transient( $throttle, 1, $interval );
	}

	wp_mail( $email['to'], (string) ( $email['subject'] ?? '' ), (string) ( $email['message'] ?? '' ), $email['headers'] ?? [] );
}

// -------------------------------------------------------------------------
// Filters
// -------------------------------------------------------------------------

/**
 * Point wp_registration_url() at our registration page.
 */
function filter_register_url( string $url ): string {
	return ( ! is_user_logged_in() ? page_url() : '' ) ?: $url;
}

/**
 * Point the signup location at our page for logged-out visitors.
 *
 * Core also uses this filter for the "Add New Site" link in My Sites, which
 * logged-in users need to keep reaching wp-signup.php.
 */
function filter_signup_location( string $url ): string {
	return ( ! is_user_logged_in() ? page_url() : '' ) ?: $url;
}

/**
 * Show/hide group variants on the registration page.
 */
function filter_block_visibility( string $block_content, array $parsed_block ): string {
	if ( $parsed_block['blockName'] !== 'core/group' ) {
		return $block_content;
	}

	$page_id = (int) get_option( 'hm_activation_registration_page_id' );
	if ( ! $page_id || (int) get_queried_object_id() !== $page_id ) {
		return $block_content;
	}

	$variation = $parsed_block['attrs']['metadata']['variationName'] ?? '';

	if ( $variation === 'hm-user-activation/registration-errors' && ! is_error() ) {
		return '';
	}

	if ( $variation === 'hm-user-activation/registration-success' && ! is_success() ) {
		return '';
	}

	return $block_content;
}

// -------------------------------------------------------------------------
// URL helper
// -------------------------------------------------------------------------

/**
 * Permalink of the configured registration page, or an empty string if it is
 * not set or not published.
 */
function page_url(): string {
	$page_id = (int) get_option( 'hm_activation_registration_page_id' );

	if ( ! $page_id || get_post_status( $page_id ) !== 'publish' ) {
		return '';
	}

	return get_permalink( $page_id ) ?: '';
}

// -------------------------------------------------------------------------
// State — result() acts as both getter and setter.
// Call result( $value ) to set, result() to get.
// -------------------------------------------------------------------------

/**
 * @param array{success: bool, user_name?: string, user_email?: string, error_code?: string, error_message?: string, errors?: \WP_Error, field_errors?: array<string,string>}|null $value
 * @return array{success: bool, user_name?: string, user_email?: string, error_code?: string, error_message?: string, errors?: \WP_Error, field_errors?: array<string,string>}|null
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

/**
 * Error message for a single field, for rendering inline in the form.
 *
 * @param string $field One of the WP_Error codes returned by
 *                      wpmu_validate_user_signup(), e.g. user_name or user_email.
 */
function get_field_error( string $field ): string {
	if ( ! is_error() ) {
		return '';
	}

	return result()['field_errors'][ $field ] ?? '';
}

/**
 * Validation errors from the last submission, for the signup_extra_fields action.
 */
function get_errors(): \WP_Error {
	$errors = result()['errors'] ?? null;

	return $errors instanceof \WP_Error ? $errors : new \WP_Error();
}

/**
 * Submitted username, for repopulating the form after a validation failure.
 */
function get_submitted_user_name(): string {
	return is_error() ? ( result()['user_name'] ?? '' ) : '';
}

/**
 * Submitted email address, for repopulating the form after a validation failure.
 */
function get_submitted_user_email(): string {
	return is_error() ? ( result()['user_email'] ?? '' ) : '';
}

/**
 * Email address the activation link was sent to, after a successful signup.
 */
function get_user_email(): string {
	return is_success() ? ( result()['user_email'] ?? '' ) : '';
}

/**
 * Username created by a successful signup.
 */
function get_user_name(): string {
	return is_success() ? ( result()['user_name'] ?? '' ) : '';
}
