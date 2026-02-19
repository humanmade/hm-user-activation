<?php
/**
 * Core activation processing logic.
 *
 * Intercepts the activation page request, validates the submitted key,
 * calls wpmu_activate_signup(), and stores the result for block bindings
 * and conditional block rendering.
 */

namespace HM\UserActivation;

class Activation {

	/**
	 * Stores the result of the current activation attempt, or null if none.
	 *
	 * @var array{success: bool, user_id?: int, username?: string, password?: string, meta?: array, error_message?: string, error_code?: string}|null
	 */
	private static ?array $result = null;

	/** Prevent double-processing within a single request. */
	private static bool $processed = false;

	public static function init(): void {
		// Redirect wp-activate.php requests to our activation page.
		// This fires early enough in wp-activate.php's load cycle.
		add_action( 'plugins_loaded', [ self::class, 'maybe_redirect_wp_activate' ], 1 );

		// Process an activation form submission on the activation page.
		add_action( 'template_redirect', [ self::class, 'maybe_process_activation' ] );

		// Conditionally hide error/success group variants when irrelevant.
		add_filter( 'render_block', [ self::class, 'filter_block_visibility' ], 10, 2 );
	}

	/**
	 * Redirect requests to the native wp-activate.php to our activation page.
	 * Only fires when the plugin is active on the main site (where wp-activate.php loads plugins).
	 */
	public static function maybe_redirect_wp_activate(): void {
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
	 *  - POST        → process a manually submitted form (nonce verified, user-chosen auto-login).
	 */
	public static function maybe_process_activation(): void {
		$page_id = (int) get_option( 'hm_activation_page_id' );

		if ( ! $page_id || ! is_page( $page_id ) ) {
			return;
		}

		// GET: key present in URL — activate immediately, no form needed.
		if ( isset( $_GET['key'] ) && ! isset( $_POST['_hm_activation_nonce'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
			if ( $key ) {
				$auto_login = (bool) get_option( 'hm_activation_auto_login', false );
				self::process( $key, $auto_login );
			}
			return;
		}

		// POST: manual form submission.
		if ( empty( $_POST['_hm_activation_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_hm_activation_nonce'] ) ), 'hm_activation' ) ) {
			self::$result = [
				'success'       => false,
				'error_code'    => 'nonce_failed',
				'error_message' => __( 'Security check failed. Please refresh the page and try again.', 'hm-user-activation' ),
			];
			return;
		}

		$key = sanitize_text_field( wp_unslash( $_POST['activation_key'] ?? '' ) );

		if ( ! $key ) {
			self::$result = [
				'success'       => false,
				'error_code'    => 'empty_key',
				'error_message' => __( 'Please enter your activation key.', 'hm-user-activation' ),
			];
			return;
		}

		$auto_login = (bool) get_option( 'hm_activation_auto_login', false );

		self::process( $key, $auto_login );
	}

	/**
	 * Run wpmu_activate_signup() and store the result.
	 */
	private static function process( string $key, bool $auto_login ): void {
		if ( self::$processed ) {
			return;
		}
		self::$processed = true;

		$activation = wpmu_activate_signup( $key );

		if ( is_wp_error( $activation ) ) {
			self::$result = [
				'success'       => false,
				'error_code'    => $activation->get_error_code(),
				'error_message' => $activation->get_error_message(),
			];
			return;
		}

		$user_id  = (int) $activation['user_id'];
		$password = (string) $activation['password'];
		$user     = get_user_by( 'id', $user_id );

		self::$result = [
			'success'  => true,
			'user_id'  => $user_id,
			'username' => $user ? $user->user_login : '',
			'password' => $password,
			'meta'     => $activation['meta'] ?? [],
		];

		if ( $auto_login ) {
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id );
		}

		$send_welcome = (bool) get_option( 'hm_activation_welcome_email_enabled', true );
		if ( $send_welcome ) {
			Emails::send_welcome_email( $user_id, $password );
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
	public static function filter_block_visibility( string $block_content, array $parsed_block ): string {
		if ( $parsed_block['blockName'] !== 'core/group' ) {
			return $block_content;
		}

		$page_id = (int) get_option( 'hm_activation_page_id' );
		if ( ! $page_id || (int) get_queried_object_id() !== $page_id ) {
			return $block_content;
		}

		$variation = $parsed_block['attrs']['metadata']['variationName'] ?? '';

		if ( $variation === 'hm-user-activation/errors' && ! self::is_error() ) {
			return '';
		}

		if ( $variation === 'hm-user-activation/success' && ! self::is_success() ) {
			return '';
		}

		return $block_content;
	}

	// -------------------------------------------------------------------------
	// State accessors used by block bindings and render.php
	// -------------------------------------------------------------------------

	public static function get_result(): ?array {
		return self::$result;
	}

	public static function is_success(): bool {
		return isset( self::$result['success'] ) && self::$result['success'] === true;
	}

	public static function is_error(): bool {
		return isset( self::$result['success'] ) && self::$result['success'] === false;
	}

	public static function get_error_message(): string {
		return self::is_error() ? ( self::$result['error_message'] ?? '' ) : '';
	}

	public static function get_username(): string {
		return self::is_success() ? ( self::$result['username'] ?? '' ) : '';
	}

	public static function get_password(): string {
		return self::is_success() ? ( self::$result['password'] ?? '' ) : '';
	}
}
