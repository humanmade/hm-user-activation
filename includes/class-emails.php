<?php
/**
 * Deprecated Emails class from 1.0.0.
 *
 * Kept so code written against 1.0.0 keeps working. Its public static methods
 * call the namespaced functions that replaced them. Deprecation notices only
 * show when WP_DEBUG is on.
 */

namespace HM\UserActivation;

/**
 * Deprecated Emails class from 1.0.0.
 *
 * @deprecated 1.1.0 Use the functions in the HM\UserActivation\Emails namespace.
 */
class Emails {

	public static function init(): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Emails\bootstrap()' );
		\HM\UserActivation\Emails\bootstrap();
	}

	public static function intercept_user_notification( string $user_login, string $user_email, string $key, array $meta ): bool {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Emails\intercept_user_notification()' );
		return \HM\UserActivation\Emails\intercept_user_notification( $user_login, $user_email, $key, $meta );
	}

	public static function intercept_blog_notification( string $domain, string $path, string $title, string $user_login, string $user_email, string $key, array $meta ): bool {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Emails\intercept_blog_notification()' );
		return \HM\UserActivation\Emails\intercept_blog_notification( $domain, $path, $title, $user_login, $user_email, $key, $meta );
	}

	public static function send_activation_email( string $user_email, string $key, string $username ): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Emails\send_activation_email()' );
		\HM\UserActivation\Emails\send_activation_email( $user_email, $key, $username );
	}

	/**
	 * The password is ignored. The email carries a set-password link instead.
	 */
	public static function send_welcome_email( int $user_id, string $password ): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Emails\send_welcome_email()' );
		\HM\UserActivation\Emails\send_welcome_email( $user_id );
	}

	public static function default_activation_subject(): string {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Emails\default_activation_subject()' );
		return \HM\UserActivation\Emails\default_activation_subject();
	}

	public static function default_activation_body(): string {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Emails\default_activation_body()' );
		return \HM\UserActivation\Emails\default_activation_body();
	}

	public static function default_welcome_subject(): string {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Emails\default_welcome_subject()' );
		return \HM\UserActivation\Emails\default_welcome_subject();
	}

	public static function default_welcome_body(): string {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Emails\default_welcome_body()' );
		return \HM\UserActivation\Emails\default_welcome_body();
	}
}
