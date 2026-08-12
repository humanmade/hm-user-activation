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

	// Only user signups belong here — leave site (blog) signups to core.
	if ( ! empty( $_GET['new'] ) || ! empty( $_POST['stage'] ) ) {
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

	$user_name  = sanitize_text_field( wp_unslash( $_POST['user_name'] ?? '' ) );
	$user_email = sanitize_text_field( wp_unslash( $_POST['user_email'] ?? '' ) );

	process( $user_name, $user_email );
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

	$validation = wpmu_validate_user_signup( $user_name, $user_email );

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
			'field_errors'  => $field_errors,
			'user_name'     => $user_name,
			'user_email'    => $user_email,
		] );
		return;
	}

	$user_name  = $validation['user_name'];
	$user_email = $validation['user_email'];

	/**
	 * Filter the meta stored against the signup.
	 *
	 * Mirrors core's add_signup_meta filter used by wp-signup.php so existing
	 * integrations keep working, then allows a plugin-specific override.
	 *
	 * @param array  $meta       Signup meta.
	 * @param string $user_name  Sanitised username.
	 * @param string $user_email Sanitised email address.
	 */
	$meta = apply_filters( 'add_signup_meta', [] );
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
	$setting = get_site_option( 'registration', 'none' );
	$enabled = in_array( $setting, [ 'user', 'all' ], true );

	/**
	 * Filter whether registration is open.
	 *
	 * @param bool   $enabled Whether registration is permitted.
	 * @param string $setting The network registration setting.
	 */
	return (bool) apply_filters( 'hm_user_activation_registration_enabled', $enabled, $setting );
}

// -------------------------------------------------------------------------
// Filters
// -------------------------------------------------------------------------

/**
 * Point wp_registration_url() at our registration page.
 */
function filter_register_url( string $url ): string {
	return page_url() ?: $url;
}

/**
 * Point the signup location (used by wp-login.php's register link) at our page.
 */
function filter_signup_location( string $url ): string {
	return page_url() ?: $url;
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
 * Permalink of the configured registration page, or an empty string.
 */
function page_url(): string {
	$page_id = (int) get_option( 'hm_activation_registration_page_id' );

	if ( ! $page_id ) {
		return '';
	}

	return get_permalink( $page_id ) ?: '';
}

/**
 * URL of the configured log in page, falling back to the WordPress login form.
 */
function login_url(): string {
	$page_id = (int) get_option( 'hm_activation_login_page_id' );
	$url     = $page_id ? get_permalink( $page_id ) : false;

	return $url ?: wp_login_url();
}

// -------------------------------------------------------------------------
// State — result() acts as both getter and setter.
// Call result( $value ) to set, result() to get.
// -------------------------------------------------------------------------

/**
 * @param array{success: bool, user_name?: string, user_email?: string, error_code?: string, error_message?: string, field_errors?: array<string,string>}|null $value
 * @return array{success: bool, user_name?: string, user_email?: string, error_code?: string, error_message?: string, field_errors?: array<string,string>}|null
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
