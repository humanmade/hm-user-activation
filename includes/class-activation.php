<?php
/**
 * Deprecated Activation class from 1.0.0.
 *
 * Kept so code written against 1.0.0 keeps working. Its public static methods
 * call the namespaced functions that replaced them. Deprecation notices only
 * show when WP_DEBUG is on.
 */

namespace HM\UserActivation;

/**
 * Deprecated Activation class from 1.0.0.
 *
 * @deprecated 1.1.0 Use the functions in the HM\UserActivation\Activation namespace.
 */
class Activation {

	public static function init(): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Activation\bootstrap()' );
		\HM\UserActivation\Activation\bootstrap();
	}

	public static function maybe_redirect_wp_activate(): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Activation\maybe_redirect_wp_activate()' );
		\HM\UserActivation\Activation\maybe_redirect_wp_activate();
	}

	public static function maybe_process_activation(): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Activation\maybe_process_activation()' );
		\HM\UserActivation\Activation\maybe_process_activation();
	}

	public static function filter_block_visibility( string $block_content, array $parsed_block ): string {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Activation\filter_block_visibility()' );
		return \HM\UserActivation\Activation\filter_block_visibility( $block_content, $parsed_block );
	}

	/**
	 * The result without reset_url, so no live key is handed to old callers.
	 */
	public static function get_result(): ?array {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Activation\result()' );
		$result = \HM\UserActivation\Activation\result();
		if ( is_array( $result ) ) {
			unset( $result['reset_url'] );
		}
		return $result;
	}

	public static function is_success(): bool {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Activation\is_success()' );
		return \HM\UserActivation\Activation\is_success();
	}

	public static function is_error(): bool {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Activation\is_error()' );
		return \HM\UserActivation\Activation\is_error();
	}

	public static function get_error_message(): string {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Activation\get_error_message()' );
		return \HM\UserActivation\Activation\get_error_message();
	}

	public static function get_username(): string {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Activation\get_username()' );
		return \HM\UserActivation\Activation\get_username();
	}

	/**
	 * Always empty. The generated password is no longer kept or shown.
	 */
	public static function get_password(): string {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Activation\get_reset_url()' );
		return '';
	}
}
