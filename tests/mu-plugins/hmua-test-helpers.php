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
 * With the `hmua_test_force_password_setup` cookie set, say every account
 * needs to set a password.
 */
add_filter(
	'hm_user_activation_needs_password_setup',
	static function ( bool $needs ): bool {
		return empty( $_COOKIE['hmua_test_force_password_setup'] ) ? $needs : true;
	},
	20
);

/**
 * With the `hmua_test_activate_sets_password` cookie set, give each activated
 * user a password, as a site's own activation hook might.
 */
add_action(
	'wpmu_activate_user',
	static function ( $user_id ): void {
		if ( ! empty( $_COOKIE['hmua_test_activate_sets_password'] ) ) {
			wp_set_password( 'hook-set-password', (int) $user_id );
		}
	}
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
 * With the `hmua_test_no_strength_meter` cookie set, turn off the password
 * strength meter and show/hide toggle.
 */
add_filter(
	'hm_user_activation_password_strength_meter',
	static function ( bool $enabled ): bool {
		return empty( $_COOKIE['hmua_test_no_strength_meter'] ) ? $enabled : false;
	}
);

/**
 * A stand-in for a site adding its own field to the "Complete your account"
 * form: with the `hmua_test_complete_fields` cookie set, ask for a first name,
 * require it, and save it. The keys of the fields the action receives are
 * recorded in user meta so specs can check what was passed.
 */
add_action(
	'hm_user_activation_complete_account_fields',
	static function ( $signup, $meta, $fields ): void {
		if ( empty( $_COOKIE['hmua_test_complete_fields'] ) ) {
			return;
		}

		printf(
			'<p><label for="hmua-first-name">First name</label> <input type="text" id="hmua-first-name" name="first_name" value="%s"></p>',
			esc_attr( (string) ( $fields['first_name'] ?? '' ) )
		);
	},
	10,
	3
);

add_filter(
	'hm_user_activation_complete_account_errors',
	static function ( $errors, $fields ) {
		if ( ! empty( $_COOKIE['hmua_test_complete_fields'] ) && trim( (string) ( $fields['first_name'] ?? '' ) ) === '' ) {
			$errors->add( 'hmua_test_first_name', 'Test: first name is required.' );
		}

		return $errors;
	},
	10,
	2
);

add_action(
	'hm_user_activation_complete_account',
	static function ( int $user_id, array $meta, array $fields ): void {
		if ( empty( $_COOKIE['hmua_test_complete_fields'] ) ) {
			return;
		}

		update_user_meta( $user_id, 'first_name', sanitize_text_field( (string) ( $fields['first_name'] ?? '' ) ) );
		update_user_meta( $user_id, 'hmua_test_complete_field_keys', array_keys( $fields ) );
	},
	10,
	3
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
 * A stand-in captcha: with the `hmua_test_block_reset` cookie set, refuse
 * every reset request.
 */
add_action(
	'lostpassword_post',
	static function ( \WP_Error $errors ): void {
		if ( ! empty( $_COOKIE['hmua_test_block_reset'] ) ) {
			$errors->add( 'hmua_test_blocked', 'Test hook: this request was blocked.' );
		}
	}
);

/**
 * Let tests turn off the lost password link rewrite with an option.
 */
add_filter(
	'hm_user_activation_filter_lostpassword_url',
	static function ( bool $filter ): bool {
		return get_option( 'hm_activation_test_filter_lostpassword', '1' ) === '0' ? false : $filter;
	}
);

/**
 * Let tests change the reset link used when there is no published reset page.
 */
add_filter(
	'hm_user_activation_reset_url_fallback',
	static function ( string $url ): string {
		return get_option( 'hm_activation_test_reset_fallback' ) ?: $url;
	}
);

/**
 * Record what the link lifetime filter was given.
 */
add_filter(
	'hm_user_activation_link_lifetime',
	static function ( $hours, $signup = null ) {
		update_option(
			'hm_activation_test_lifetime_args',
			[
				'argument_count' => func_num_args(),
				'is_object'      => is_object( $signup ),
				'user_login'     => is_object( $signup ) ? $signup->user_login : null,
			]
		);

		return $hours;
	},
	10,
	2
);

/**
 * Record whether the activation result was already set when the welcome
 * email filter ran.
 */
add_filter(
	'hm_user_activation_send_welcome_email',
	static function ( $send ) {
		update_option(
			'hm_activation_test_welcome_filter',
			[ 'result_is_success' => \HM\UserActivation\Activation\is_success() ]
		);

		return $send;
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

		register_rest_route( 'hmua-tests/v1', '/blog-signup', [
			'methods'             => 'POST',
			'callback'            => __NAMESPACE__ . '\\create_blog_signup',
			'permission_callback' => $open,
		] );

		register_rest_route( 'hmua-tests/v1', '/signup-state', [
			[
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\get_signup_state',
				'permission_callback' => $open,
			],
			[
				'methods'             => 'DELETE',
				'callback'            => __NAMESPACE__ . '\\cleanup_signup',
				'permission_callback' => $open,
			],
		] );

		register_rest_route( 'hmua-tests/v1', '/option', [
			[
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\get_option_value',
				'permission_callback' => $open,
			],
			[
				'methods'             => 'POST',
				'callback'            => __NAMESPACE__ . '\\set_option',
				'permission_callback' => $open,
			],
			[
				'methods'             => 'DELETE',
				'callback'            => __NAMESPACE__ . '\\delete_option_value',
				'permission_callback' => $open,
			],
		] );

		register_rest_route( 'hmua-tests/v1', '/page', [
			[
				'methods'             => 'GET',
				'callback'            => __NAMESPACE__ . '\\get_page',
				'permission_callback' => $open,
			],
			[
				'methods'             => 'POST',
				'callback'            => __NAMESPACE__ . '\\create_page',
				'permission_callback' => $open,
			],
			[
				'methods'             => 'DELETE',
				'callback'            => __NAMESPACE__ . '\\delete_page',
				'permission_callback' => $open,
			],
		] );

		register_rest_route( 'hmua-tests/v1', '/shims', [
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\check_shims',
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
 * Register a pending site signup, which sends the activation email.
 *
 * `path` is the new site's path in a subdirectory network, such as
 * `/hmua-blog-abc/`.
 *
 * @return array|\WP_Error
 */
function create_blog_signup( \WP_REST_Request $request ) {
	global $wpdb;

	$path = (string) $request->get_param( 'path' );

	if ( is_subdomain_install() || ! is_test_path( $path ) ) {
		return new \WP_Error( 'hmua_tests_blog', 'Use a /hmua-blog-… path on a subdirectory network.', [ 'status' => 400 ] );
	}

	$user_login = sanitize_user( (string) $request->get_param( 'user_login' ), true );
	$user_email = sanitize_email( (string) $request->get_param( 'user_email' ) );

	wpmu_signup_blog( get_network()->domain, $path, 'Test site', $user_login, $user_email );

	return [
		'user_login'     => $user_login,
		'user_email'     => $user_email,
		'path'           => $path,
		'activation_key' => (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT activation_key FROM {$wpdb->signups} WHERE user_login = %s AND path = %s", $user_login, $path )
		),
	];
}

/**
 * Whether a path is one the helpers may create or delete a site at.
 */
function is_test_path( string $path ): bool {
	return (bool) preg_match( '#^/hmua-blog-[a-z0-9]+/$#', $path );
}

/**
 * Whether a signup is still waiting, and whether a site and user exist.
 * `path` is optional and only needed to look for a site.
 *
 * @return array|\WP_Error
 */
function get_signup_state( \WP_REST_Request $request ) {
	global $wpdb;

	$user_login = sanitize_user( (string) $request->get_param( 'user_login' ), true );
	$path       = (string) $request->get_param( 'path' );

	$signup = $wpdb->get_row(
		$wpdb->prepare( "SELECT active, meta FROM {$wpdb->signups} WHERE user_login = %s ORDER BY signup_id DESC", $user_login )
	);

	return [
		'signup_exists' => (bool) $signup,
		'signup_active' => $signup ? (bool) (int) $signup->active : false,
		'meta'          => $signup ? ( maybe_unserialize( $signup->meta ) ?: null ) : null,
		'user_exists'   => (bool) username_exists( $user_login ),
		'site_exists'   => $path && (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE domain = %s AND path = %s", get_network()->domain, $path )
		),
	];
}

/**
 * Remove the signups, site and user a test made. Only `hmua` usernames and
 * `/hmua-blog-…` paths are touched.
 *
 * @return array|\WP_Error
 */
function cleanup_signup( \WP_REST_Request $request ) {
	global $wpdb;

	$user_login = sanitize_user( (string) $request->get_param( 'user_login' ), true );
	$path       = (string) $request->get_param( 'path' );

	if ( strpos( $user_login, 'hmua' ) !== 0 || ( $path && ! is_test_path( $path ) ) ) {
		return new \WP_Error( 'hmua_tests_cleanup', 'Only hmua users and /hmua-blog-… paths can be removed.', [ 'status' => 400 ] );
	}

	require_once ABSPATH . 'wp-admin/includes/ms.php';
	require_once ABSPATH . 'wp-admin/includes/user.php';

	$wpdb->delete( $wpdb->signups, [ 'user_login' => $user_login ] );

	$blog_id = $path ? (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT blog_id FROM {$wpdb->blogs} WHERE domain = %s AND path = %s", get_network()->domain, $path )
	) : 0;

	if ( $blog_id > 1 ) {
		wpmu_delete_blog( $blog_id, true );
	}

	$user = get_user_by( 'login', $user_login );

	if ( $user ) {
		wpmu_delete_user( $user->ID );
	}

	return [ 'cleaned' => true ];
}

/**
 * Whether an option name is one the helpers may touch.
 */
function is_plugin_option( string $name ): bool {
	return strpos( $name, 'hm_activation_' ) === 0;
}

/**
 * Read one of the plugin's options, reporting whether it exists.
 *
 * @return array|\WP_Error
 */
function get_option_value( \WP_REST_Request $request ) {
	$name = (string) $request->get_param( 'name' );

	if ( ! is_plugin_option( $name ) ) {
		return new \WP_Error( 'hmua_tests_option', 'Only hm_activation_ options can be read.', [ 'status' => 400 ] );
	}

	$missing = new \stdClass();
	$value   = get_option( $name, $missing );

	return [
		'exists' => $value !== $missing,
		'value'  => $value === $missing ? null : $value,
	];
}

/**
 * Set one of the plugin's options. Anything else is refused.
 *
 * @return array|\WP_Error
 */
function set_option( \WP_REST_Request $request ) {
	$name = (string) $request->get_param( 'name' );

	if ( ! is_plugin_option( $name ) ) {
		return new \WP_Error( 'hmua_tests_option', 'Only hm_activation_ options can be set.', [ 'status' => 400 ] );
	}

	update_option( $name, $request->get_param( 'value' ) );

	return [ 'name' => $name ];
}

/**
 * Delete one of the plugin's options. Anything else is refused.
 *
 * @return array|\WP_Error
 */
function delete_option_value( \WP_REST_Request $request ) {
	$name = (string) $request->get_param( 'name' );

	if ( ! is_plugin_option( $name ) ) {
		return new \WP_Error( 'hmua_tests_option', 'Only hm_activation_ options can be deleted.', [ 'status' => 400 ] );
	}

	delete_option( $name );

	return [ 'name' => $name ];
}

/**
 * Create a page and return its id. The status defaults to published.
 *
 * Content is saved as sent, as it would be by an author who can post
 * unfiltered HTML.
 *
 * @return array|\WP_Error
 */
function create_page( \WP_REST_Request $request ) {
	kses_remove_filters();

	$id = wp_insert_post(
		wp_slash( [
			'post_title'   => (string) $request->get_param( 'title' ),
			'post_name'    => (string) $request->get_param( 'slug' ),
			'post_content' => (string) $request->get_param( 'content' ),
			'post_status'  => (string) ( $request->get_param( 'status' ) ?: 'publish' ),
			'post_type'    => 'page',
		] ),
		true
	);

	kses_init_filters();

	return is_wp_error( $id ) ? $id : [ 'id' => $id ];
}

/**
 * A page's status, link and content.
 *
 * @return array|\WP_Error
 */
function get_page( \WP_REST_Request $request ) {
	$page = get_post( (int) $request->get_param( 'id' ) );

	if ( ! $page || $page->post_type !== 'page' ) {
		return new \WP_Error( 'hmua_tests_page', 'No such page.', [ 'status' => 404 ] );
	}

	return [
		'id'      => $page->ID,
		'status'  => $page->post_status,
		'link'    => get_permalink( $page ),
		'content' => $page->post_content,
	];
}

/**
 * Delete a page for good.
 */
function delete_page( \WP_REST_Request $request ): array {
	$id = (int) $request->get_param( 'id' );

	return [ 'deleted' => get_post_type( $id ) === 'page' && (bool) wp_delete_post( $id, true ) ];
}

/**
 * Report on the deprecated 1.0.0 classes: whether they exist, what a
 * result built by 1.1 looks like through them, and whether they warn.
 */
function check_shims(): array {
	$deprecated = [];

	add_action(
		'deprecated_function_run',
		static function ( $function_name ) use ( &$deprecated ) {
			$deprecated[] = $function_name;
		}
	);
	add_filter( 'deprecated_function_trigger_error', '__return_false' );

	$exists = [];

	foreach ( [ 'Activation', 'Emails', 'Block_Bindings', 'Page_Setup', 'Admin_Settings' ] as $class ) {
		$exists[ $class ] = class_exists( 'HM\\UserActivation\\' . $class );
	}

	$report = [ 'classes' => $exists ];

	if ( $exists['Activation'] ) {
		\HM\UserActivation\Activation\result( [
			'success'   => true,
			'user_id'   => 1,
			'username'  => 'someone',
			'reset_url' => 'https://example.com/reset',
		] );

		$result = \HM\UserActivation\Activation::get_result();

		$report['result_keys']       = array_keys( $result );
		$report['is_success']        = \HM\UserActivation\Activation::is_success();
		$report['username']          = \HM\UserActivation\Activation::get_username();
		$report['password']          = \HM\UserActivation\Activation::get_password();
		$report['deprecated_called'] = $deprecated;

		\HM\UserActivation\Activation\result( null );
	}

	return $report;
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
 * Create an active user on the main site with a known password. The role is
 * subscriber unless `role` asks for a site administrator, who is not a super
 * admin.
 *
 * @return array|\WP_Error
 */
function create_user( \WP_REST_Request $request ) {
	$role = (string) ( $request->get_param( 'role' ) ?: 'subscriber' );

	if ( ! in_array( $role, [ 'subscriber', 'administrator' ], true ) ) {
		return new \WP_Error( 'hmua_tests_role', 'Use subscriber or administrator.', [ 'status' => 400 ] );
	}

	$user_id = wp_insert_user( [
		'user_login' => sanitize_user( (string) $request->get_param( 'user_login' ), true ),
		'user_email' => sanitize_email( (string) $request->get_param( 'user_email' ) ),
		'user_pass'  => (string) $request->get_param( 'user_pass' ),
		'role'       => $role,
	] );

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	add_user_to_blog( get_current_blog_id(), $user_id, $role );

	return [ 'id' => $user_id ];
}

/**
 * Look up a user by login, reporting whether they exist, their roles and test meta.
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
		'first_name'      => (string) get_user_meta( $user->ID, 'first_name', true ),
		'complete_keys'   => get_user_meta( $user->ID, 'hmua_test_complete_field_keys', true ) ?: null,
		'core_fields'     => [
			'company' => (string) get_user_meta( $user->ID, 'hmua_company', true ),
			'terms'   => (string) get_user_meta( $user->ID, 'hmua_terms', true ),
		],
	];
}
