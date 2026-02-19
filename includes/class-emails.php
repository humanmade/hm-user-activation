<?php
/**
 * Email handling: replaces default activation email and sends optional welcome email.
 */

namespace HM\UserActivation;

class Emails {

	public static function init(): void {
		// Replace the default user-signup activation email.
		add_filter( 'wpmu_signup_user_notification', [ self::class, 'intercept_user_notification' ], 10, 4 );

		// Replace the default blog-signup activation email.
		add_filter( 'wpmu_signup_blog_notification', [ self::class, 'intercept_blog_notification' ], 10, 7 );
	}

	/**
	 * Intercepts the user-signup notification and sends our custom email instead.
	 * Returning a falsy value prevents the default WP email.
	 *
	 * @param string $user_login
	 * @param string $user_email
	 * @param string $key
	 * @param array  $meta
	 * @return false Always false to suppress default.
	 */
	public static function intercept_user_notification( string $user_login, string $user_email, string $key, array $meta ): bool {
		self::send_activation_email( $user_email, $key, $user_login );
		return false;
	}

	/**
	 * Intercepts the blog-signup notification and sends our custom email instead.
	 *
	 * @param string $domain
	 * @param string $path
	 * @param string $title
	 * @param string $user_login
	 * @param string $user_email
	 * @param string $key
	 * @param array  $meta
	 * @return false Always false to suppress default.
	 */
	public static function intercept_blog_notification( string $domain, string $path, string $title, string $user_login, string $user_email, string $key, array $meta ): bool {
		self::send_activation_email( $user_email, $key, $user_login );
		return false;
	}

	/**
	 * Build and dispatch the activation email to the registering user.
	 */
	public static function send_activation_email( string $user_email, string $key, string $username ): void {
		$page_id = (int) get_option( 'hm_activation_page_id' );

		if ( $page_id ) {
			$activation_url = add_query_arg( 'key', rawurlencode( $key ), get_permalink( $page_id ) ?: home_url( '/' ) );
		} else {
			// Fall back to native wp-activate.php if no page is configured yet.
			$activation_url = network_site_url( 'wp-activate.php?key=' . rawurlencode( $key ) );
		}

		$placeholders = self::build_placeholders(
			[
				'{activation_link}' => $activation_url,
				'{username}'        => $username,
			]
		);

		$subject     = get_option( 'hm_activation_email_subject' ) ?: self::default_activation_subject();
		$body        = get_option( 'hm_activation_email_body' ) ?: self::default_activation_body();
		$from_name   = get_option( 'hm_activation_email_from_name' ) ?: get_bloginfo( 'name' );
		$from_email  = get_option( 'hm_activation_email_from' ) ?: get_option( 'admin_email' );

		wp_mail(
			$user_email,
			wp_specialchars_decode( self::replace( $subject, $placeholders ) ),
			self::replace( $body, $placeholders ),
			self::build_headers( $from_name, $from_email )
		);
	}

	/**
	 * Send the post-activation welcome email containing the user's credentials.
	 */
	public static function send_welcome_email( int $user_id, string $password ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		$placeholders = self::build_placeholders(
			[
				'{username}' => $user->user_login,
				'{password}' => $password,
				'{login_url}' => get_option( 'hm_activation_login_url' ) ?: wp_login_url(),
				'{first_name}' => $user->first_name,
				'{last_name}' => $user->last_name,
				'{display_name}' => $user->display_name,
				'{nickname}' => $user->nickname,
			]
		);

		$subject     = get_option( 'hm_activation_welcome_email_subject' ) ?: self::default_welcome_subject();
		$body        = get_option( 'hm_activation_welcome_email_body' ) ?: self::default_welcome_body();
		$from_name   = get_option( 'hm_activation_welcome_email_from_name' ) ?: get_bloginfo( 'name' );
		$from_email  = get_option( 'hm_activation_welcome_email_from' ) ?: get_option( 'admin_email' );

		wp_mail(
			$user->user_email,
			wp_specialchars_decode( self::replace( $subject, $placeholders ) ),
			self::replace( $body, $placeholders ),
			self::build_headers( $from_name, $from_email )
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the common placeholder map, merged with any extra placeholders.
	 *
	 * @param array<string,string> $extra
	 * @return array<string,string>
	 */
	private static function build_placeholders( array $extra = [] ): array {
		$network = get_network();

		return array_merge(
			[
				'{site_name}'    => get_bloginfo( 'name' ),
				'{site_url}'     => home_url(),
				'{network_name}' => $network ? $network->site_name : get_bloginfo( 'name' ),
			],
			$extra
		);
	}

	/**
	 * Replace placeholder tokens in a string.
	 *
	 * @param string               $text
	 * @param array<string,string> $placeholders
	 * @return string
	 */
	private static function replace( string $text, array $placeholders ): string {
		return str_replace( array_keys( $placeholders ), array_values( $placeholders ), $text );
	}

	/**
	 * Build RFC-compliant mail headers array.
	 */
	private static function build_headers( string $from_name, string $from_email ): array {
		return [
			'Content-Type: text/plain; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		];
	}

	// -------------------------------------------------------------------------
	// Default email content (used when no custom value is saved)
	// -------------------------------------------------------------------------

	public static function default_activation_subject(): string {
		/* translators: %s: site name placeholder token */
		return sprintf( __( 'Activate your account at {site_name}', 'hm-user-activation' ) );
	}

	public static function default_activation_body(): string {
		return implode( "\n\n", [
			__( 'Thank you for registering at {site_name}!', 'hm-user-activation' ),
			__( 'To activate your account, please click the link below:', 'hm-user-activation' ),
			'{activation_link}',
			__( 'If you did not create this account, you can safely ignore this email.', 'hm-user-activation' ),
			__( '{site_name}', 'hm-user-activation' ),
		] );
	}

	public static function default_welcome_subject(): string {
		return __( 'Welcome to {site_name} — your account is active', 'hm-user-activation' );
	}

	public static function default_welcome_body(): string {
		return implode( "\n\n", [
			__( 'Hi {display_name},', 'hm-user-activation' ),
			__( 'Your account at {site_name} has been successfully activated.', 'hm-user-activation' ),
			__( 'Here are your login details:', 'hm-user-activation' ),
			implode( "\n", [
				__( 'Username: {username}', 'hm-user-activation' ),
				__( 'Password: {password}', 'hm-user-activation' ),
			] ),
			__( 'You can log in at: {login_url}', 'hm-user-activation' ),
			__( '{site_name}', 'hm-user-activation' ),
		] );
	}
}
