<?php
/**
 * Test helpers for the end-to-end suite. Inert unless HMUA_TESTS is defined.
 *
 * Captures outgoing email instead of sending it, and exposes REST routes the
 * Playwright specs use to read that mail and to create signups and users.
 *
 * @package HM\UserActivation\Tests
 */

namespace HM\UserActivation\Tests;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'HMUA_TESTS' ) || ! HMUA_TESTS ) {
	return;
}

const MAIL_KEY_PREFIX = 'hmua_test_mail_';

/**
 * Store each email as its own network option so parallel workers cannot
 * overwrite each other, then report it as sent.
 */
add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, array $atts ) {
		$to = $atts['to'] ?? [];

		add_network_option(
			null,
			uniqid( MAIL_KEY_PREFIX, true ),
			[
				'to'      => array_values( array_map( 'strtolower', (array) $to ) ),
				'subject' => (string) ( $atts['subject'] ?? '' ),
				'message' => (string) ( $atts['message'] ?? '' ),
				'headers' => $atts['headers'] ?? [],
				'time'    => microtime( true ),
			]
		);

		return true;
	},
	10,
	2
);

/**
 * Lift the per-IP resend limit: every test request comes from one address.
 */
add_filter( 'hm_user_activation_resend_ip_limit', '__return_zero' );

/**
 * Let a signup opt out of the password step by carrying `skip_password_setup`
 * in its meta.
 */
add_filter(
	'hm_user_activation_needs_password_setup',
	static function ( bool $needs, int $user_id, array $meta ): bool {
		return empty( $meta['skip_password_setup'] ) ? $needs : false;
	},
	10,
	3
);

/**
 * Record the meta passed to the activated action against the user.
 */
add_action(
	'hm_user_activation_activated',
	static function ( int $user_id, array $meta ): void {
		update_user_meta( $user_id, 'hmua_test_activated_meta', $meta );
	},
	10,
	2
);

/**
 * The JSON object in a test cookie, or an empty array.
 *
 * Cookies are read raw while mu-plugins load and slashed afterwards, so both
 * forms are tried.
 *
 * @param string $name Cookie name.
 * @return array
 */
function cookie_json( string $name ): array {
	$raw = (string) ( $_COOKIE[ $name ] ?? '' );

	foreach ( [ $raw, stripslashes( $raw ) ] as $candidate ) {
		$data = json_decode( $candidate, true );

		if ( is_array( $data ) ) {
			return $data;
		}
	}

	return [];
}

/**
 * Per-request overrides for the plugin's options, from the
 * `hmua_test_options` cookie. A test can change a setting for its own browser
 * without affecting tests running in parallel.
 */
foreach ( cookie_json( 'hmua_test_options' ) as $hmua_option => $hmua_value ) {
	if ( strpos( (string) $hmua_option, 'hm_activation_' ) !== 0 ) {
		continue;
	}

	add_filter(
		'pre_option_' . $hmua_option,
		static function () use ( $hmua_value ) {
			return (string) $hmua_value;
		}
	);
}
unset( $hmua_option, $hmua_value );

/**
 * A stand-in password policy plugin: with the `hmua_test_min_password`
 * cookie set, reject shorter passwords. Like real policy plugins it reads the
 * password from $_POST, because validate_password_reset is not given it.
 */
add_action(
	'validate_password_reset',
	static function ( \WP_Error $errors ): void {
		$min = (int) ( $_COOKIE['hmua_test_min_password'] ?? 0 );

		if ( $min && strlen( wp_unslash( (string) ( $_POST['pass1'] ?? '' ) ) ) < $min ) {
			$errors->add( 'hmua_test_policy', sprintf( 'Test policy: use at least %d characters.', $min ) );
		}
	}
);

/**
 * Mark core's form hooks so specs can tell they fired.
 */
add_action(
	'lostpassword_form',
	static function (): void {
		echo '<input type="hidden" name="hmua_test_hook" value="lostpassword_form">';
	}
);

add_action(
	'resetpass_form',
	static function ( $user ): void {
		printf(
			'<input type="hidden" name="hmua_test_hook" value="resetpass_form:%s">',
			esc_attr( $user instanceof \WP_User ? $user->user_login : '' )
		);
	}
);

/**
 * A stand-in captcha: refuse reset requests for addresses starting with
 * `hmua-blocked`.
 */
add_action(
	'lostpassword_post',
	static function ( \WP_Error $errors ): void {
		$login = wp_unslash( (string) ( $_POST['user_login'] ?? '' ) );

		if ( strpos( $login, 'hmua-blocked' ) === 0 ) {
			$errors->add( 'hmua_test_blocked', 'Test hook: this request was blocked.' );
		}
	}
);

add_action(
	'rest_api_init',
	static function () {
		$open = '__return_true';

		register_rest_route( 'hmua-tests/v1', '/mail', [
			[
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\get_mail',
				'permission_callback' => $open,
			],
			[
				'methods'             => 'DELETE',
				'callback'            => __NAMESPACE__ . '\\delete_mail',
				'permission_callback' => $open,
			],
		] );

		register_rest_route( 'hmua-tests/v1', '/signup', [
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\create_signup',
			'permission_callback' => $open,
		] );

		register_rest_route( 'hmua-tests/v1', '/option', [
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\set_option',
			'permission_callback' => $open,
		] );

		register_rest_route( 'hmua-tests/v1', '/registration', [
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\set_registration',
			'permission_callback' => $open,
		] );

		register_rest_route( 'hmua-tests/v1', '/page-status', [
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\set_page_status',
			'permission_callback' => $open,
		] );

		register_rest_route( 'hmua-tests/v1', '/user', [
			[
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\get_user',
				'permission_callback' => $open,
			],
			[
				'methods'             => 'POST',
				'callback'            => __NAMESPACE__ . '\\create_user',
				'permission_callback' => $open,
			],
		] );
	}
);

/**
 * Captured emails, oldest first, optionally limited to one recipient.
 */
function get_mail( \WP_REST_Request $request ): array {
	global $wpdb;

	$rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s ORDER BY meta_id ASC",
			$wpdb->esc_like( MAIL_KEY_PREFIX ) . '%'
		)
	);

	$to     = strtolower( (string) $request->get_param( 'to' ) );
	$emails = [];

	foreach ( $rows as $row ) {
		$mail = maybe_unserialize( $row );

		if ( ! is_array( $mail ) ) {
			continue;
		}

		if ( $to && ! in_array( $to, $mail['to'], true ) ) {
			continue;
		}

		$emails[] = $mail;
	}

	return $emails;
}

/**
 * Remove captured emails, optionally only those sent to one recipient.
 */
function delete_mail( \WP_REST_Request $request ): array {
	global $wpdb;

	$to      = strtolower( (string) $request->get_param( 'to' ) );
	$deleted = 0;

	$keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( MAIL_KEY_PREFIX ) . '%'
		)
	);

	foreach ( $keys as $key ) {
		$mail = get_network_option( null, $key );

		if ( $to && ( ! is_array( $mail ) || ! in_array( $to, $mail['to'], true ) ) ) {
			continue;
		}

		delete_network_option( null, $key );
		++$deleted;
	}

	return [ 'deleted' => $deleted ];
}

/**
 * Register a pending user signup, which sends the activation email.
 *
 * Optional `meta` is a JSON object stored with the signup. Optional
 * `age_hours` backdates the signup so its link looks that old.
 */
function create_signup( \WP_REST_Request $request ): array {
	global $wpdb;

	$user_login = sanitize_user( (string) $request->get_param( 'user_login' ), true );
	$user_email = sanitize_email( (string) $request->get_param( 'user_email' ) );

	$meta = json_decode( (string) $request->get_param( 'meta' ), true );

	wpmu_signup_user( $user_login, $user_email, is_array( $meta ) ? $meta : [] );

	$age_hours = (int) $request->get_param( 'age_hours' );

	if ( $age_hours ) {
		$wpdb->update(
			$wpdb->signups,
			[ 'registered' => gmdate( 'Y-m-d H:i:s', time() - $age_hours * HOUR_IN_SECONDS ) ],
			[ 'user_login' => $user_login ]
		);
	}

	return [
		'user_login'     => $user_login,
		'user_email'     => $user_email,
		'activation_key' => (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT activation_key FROM {$wpdb->signups} WHERE user_login = %s", $user_login )
		),
	];
}

/**
 * Set one of the plugin's options. Anything else is refused.
 */
function set_option( \WP_REST_Request $request ) {
	$name = (string) $request->get_param( 'name' );

	if ( strpos( $name, 'hm_activation_' ) !== 0 ) {
		return new \WP_Error( 'hmua_tests_option', 'Only hm_activation_ options can be set.', [ 'status' => 400 ] );
	}

	update_option( $name, $request->get_param( 'value' ) );

	return [ 'name' => $name ];
}

/**
 * Set the network's registration setting: none, user, blog or all.
 */
function set_registration( \WP_REST_Request $request ) {
	$value = (string) $request->get_param( 'value' );

	if ( ! in_array( $value, [ 'none', 'user', 'blog', 'all' ], true ) ) {
		return new \WP_Error( 'hmua_tests_registration', 'Use none, user, blog or all.', [ 'status' => 400 ] );
	}

	update_site_option( 'registration', $value );

	return [ 'registration' => $value ];
}

/**
 * Publish or unpublish the page stored in one of the plugin's page options.
 */
function set_page_status( \WP_REST_Request $request ) {
	$option = (string) $request->get_param( 'option' );
	$status = (string) $request->get_param( 'status' );

	if ( ! preg_match( '/^hm_activation_\w*page_id$/', $option ) || ! in_array( $status, [ 'publish', 'draft' ], true ) ) {
		return new \WP_Error( 'hmua_tests_page_status', 'Use an hm_activation_ page option and publish or draft.', [ 'status' => 400 ] );
	}

	$page_id = (int) get_option( $option );

	if ( ! $page_id || ! get_post( $page_id ) ) {
		return new \WP_Error( 'hmua_tests_page_status', 'No page is set for that option.', [ 'status' => 404 ] );
	}

	$updated = wp_update_post( [
		'ID'          => $page_id,
		'post_status' => $status,
	], true );

	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	return [ 'id' => $page_id, 'status' => $status ];
}

/**
 * Create an active user on the main site with a known password.
 *
 * @return array|\WP_Error
 */
function create_user( \WP_REST_Request $request ) {
	$user_id = wp_insert_user( [
		'user_login' => sanitize_user( (string) $request->get_param( 'user_login' ), true ),
		'user_email' => sanitize_email( (string) $request->get_param( 'user_email' ) ),
		'user_pass'  => (string) $request->get_param( 'user_pass' ),
		'role'       => 'subscriber',
	] );

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );

	return [ 'id' => $user_id ];
}

/**
 * Look up a user by login, reporting whether they exist and their roles.
 */
function get_user( \WP_REST_Request $request ): array {
	$user = get_user_by( 'login', (string) $request->get_param( 'login' ) );

	if ( ! $user ) {
		return [ 'exists' => false ];
	}

	return [
		'exists'          => true,
		'id'              => $user->ID,
		'email'           => $user->user_email,
		'roles'           => array_values( $user->roles ),
		'is_member'       => is_user_member_of_blog( $user->ID, get_current_blog_id() ),
		'activation_meta' => get_user_meta( $user->ID, 'hmua_test_activated_meta', true ) ?: null,
	];
}
