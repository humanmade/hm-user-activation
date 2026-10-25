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

use HM\UserActivation\Sites;

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

	// phpcs:ignore WordPress.Security.NonceVerification -- Presence check only; core verifies its own form.
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

	if ( ! $page_id || ! is_page( $page_id ) || ! Sites\is_enabled() ) {
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

	$user_name  = is_string( $user_name ) ? $user_name : '';
	$user_email = is_string( $user_email ) ? $user_email : '';

	// Without a username field, any posted username is ignored. A form that
	// posts no username at all, such as one in a template with the field
	// turned off, gets a generated one too.
	if ( ! isset( $_POST['user_name'] ) || ! shows_username() ) {
		$user_name = generate_user_name( $user_email );
	}

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

	// Without a published activation page a signup could never be activated.
	if ( ! is_registration_enabled() || ! Sites\is_enabled() || ! Sites\activation_page_url() ) {
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
			'errors'        => $errors,
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
	 * Starts with add_to_blog and new_role so core's add_new_user_to_blog()
	 * makes the user a member of this site on activation, and the
	 * hm_user_activation marker so the plugin can tell its own signups from
	 * core and admin ones. Core's
	 * add_signup_meta filter is applied first, then a plugin-specific one.
	 *
	 * @param array  $meta       Signup meta.
	 * @param string $user_name  Sanitised username.
	 * @param string $user_email Sanitised email address.
	 */
	$meta = apply_filters( 'add_signup_meta', [
		'add_to_blog'       => get_current_blog_id(),
		'new_role'          => get_option( 'default_role' ),
		Sites\SIGNUP_MARKER => true,
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
 * Whether the registration form on the registration page asks for a username.
 *
 * Signups are processed before the page renders, so this reads the
 * showUsername attribute of the first Registration Form block in the page's
 * content, including inner blocks and synced patterns.
 */
function shows_username(): bool {
	$page_id = (int) get_option( 'hm_activation_registration_page_id' );
	$post    = $page_id ? get_post( $page_id ) : null;
	$block   = $post ? find_form_block( parse_blocks( $post->post_content ) ) : null;
	$show    = ! isset( $block['attrs']['showUsername'] ) || (bool) $block['attrs']['showUsername'];

	/**
	 * Filters whether the registration form asks for a username.
	 *
	 * When it doesn't, a username is made from the email address with
	 * generate_user_name(). Applied when the form renders and when it is posted.
	 *
	 * @param bool $show Defaults to the block's "Ask for a username" setting.
	 */
	return (bool) apply_filters( 'hm_user_activation_registration_show_username', $show );
}

/**
 * The first Registration Form block in a list of parsed blocks.
 *
 * Follows synced patterns that core would render: published, not password
 * protected, and each one once.
 *
 * @param array $blocks Parsed blocks.
 * @param int[] $seen   Synced pattern IDs already followed.
 * @return array|null
 */
function find_form_block( array $blocks, array &$seen = [] ): ?array {
	foreach ( $blocks as $block ) {
		if ( ( $block['blockName'] ?? '' ) === 'hm-user-activation/registration-form' ) {
			return $block;
		}

		$inner = $block['innerBlocks'] ?? [];

		if ( ( $block['blockName'] ?? '' ) === 'core/block' ) {
			$ref     = (int) ( $block['attrs']['ref'] ?? 0 );
			$pattern = $ref && ! in_array( $ref, $seen, true ) ? get_post( $ref ) : null;
			$seen[]  = $ref;
			$inner   = $pattern && $pattern->post_type === 'wp_block' && $pattern->post_status === 'publish' && empty( $pattern->post_password )
				? parse_blocks( $pattern->post_content )
				: [];
		}

		$found = $inner ? find_form_block( $inner, $seen ) : null;

		if ( $found ) {
			return $found;
		}
	}

	return null;
}

/**
 * Make a username from an email address that core's signup rules accept.
 *
 * Starts from the address's local part, keeps lowercase letters and
 * numbers, and adds "user" in front when that leaves fewer than four
 * characters or only numbers. A number, then a short random suffix, is added
 * until the name is not taken by an account or a signup and is allowed. It
 * never checks the address itself, so it says nothing about whether the
 * address has an account.
 *
 * @param string $user_email Email address the person signed up with.
 * @return string Username.
 */
function generate_user_name( string $user_email ): string {
	$local = strtolower( (string) strstr( sanitize_email( $user_email ), '@', true ) );
	$base  = substr( (string) preg_replace( '/[^a-z0-9]/', '', $local ), 0, 50 );

	if ( strlen( $base ) < 4 || ctype_digit( $base ) ) {
		$base = substr( 'user' . $base, 0, 50 );
	}

	$user_name = $base;

	for ( $i = 2; ! user_name_available( $user_name ) && $i < 20; $i++ ) {
		$suffix    = $i < 10 ? (string) $i : strtolower( wp_generate_password( 6, false ) );
		$user_name = $base . $suffix;
	}

	/**
	 * Filters the username made from an email address.
	 *
	 * The result still goes through wpmu_validate_user_signup(), so it must
	 * follow core's rules: 4 to 60 lowercase letters and numbers, not all numbers.
	 *
	 * @param string $user_name  Generated username.
	 * @param string $user_email Email address it was made from, as posted.
	 */
	return (string) apply_filters( 'hm_user_activation_generated_user_name', $user_name, $user_email );
}

/**
 * Whether a username is free: no account or signup has it, and the network
 * and illegal_user_logins allow it.
 *
 * @param string $user_name Username to check.
 */
function user_name_available( string $user_name ): bool {
	global $wpdb;

	if ( username_exists( $user_name ) ) {
		return false;
	}

	if ( $wpdb->get_var( $wpdb->prepare( "SELECT signup_id FROM {$wpdb->signups} WHERE user_login = %s LIMIT 1", $user_name ) ) ) {
		return false;
	}

	// Core uses this list when the network has no illegal_names option yet.
	$names = get_site_option( 'illegal_names' );
	$names = is_array( $names ) ? $names : [ 'www', 'web', 'root', 'admin', 'main', 'invite', 'administrator' ];

	/** This filter is documented in wp-includes/user.php */
	$illegal = array_merge( $names, (array) apply_filters( 'illegal_user_logins', [] ) );

	return ! in_array( $user_name, array_map( 'strtolower', array_map( 'strval', $illegal ) ), true );
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
 * not set or not published, or registration is off for this site.
 */
function page_url(): string {
	$page_id = (int) get_option( 'hm_activation_registration_page_id' );

	if ( ! $page_id || get_post_status( $page_id ) !== 'publish' || ! Sites\is_enabled() ) {
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
