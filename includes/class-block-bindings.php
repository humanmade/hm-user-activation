<?php
/**
 * Registers individual block bindings sources for activation data.
 *
 * Each value is its own named source so the editor shows a distinct, readable
 * label rather than a generic "Activation Data" entry with an opaque args key.
 *
 * Requires WordPress 6.5+.
 *
 * Sources:
 *  hm-user-activation/error-message    → "Activation: Error message"
 *  hm-user-activation/username         → "Activation: Username"
 *  hm-user-activation/password         → "Activation: Password"
 *  hm-user-activation/username-message → "Activation: Username (formatted)"
 *  hm-user-activation/password-message → "Activation: Password (formatted)"
 */

namespace HM\UserActivation;

class Block_Bindings {

	public static function init(): void {
		add_action( 'init', [ self::class, 'register_sources' ] );
	}

	public static function register_sources(): void {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			// Block bindings API requires WP 6.5.
			return;
		}

		$sources = [
			'hm-user-activation/error-message' => [
				'label' => __( 'Activation: Error message', 'hm-user-activation' ),
				'key'   => 'error_message',
			],
			'hm-user-activation/username' => [
				'label' => __( 'Activation: Username', 'hm-user-activation' ),
				'key'   => 'username',
			],
			'hm-user-activation/password' => [
				'label' => __( 'Activation: Password', 'hm-user-activation' ),
				'key'   => 'password',
			],
			'hm-user-activation/username-message' => [
				'label' => __( 'Activation: Username (formatted)', 'hm-user-activation' ),
				'key'   => 'username_message',
			],
			'hm-user-activation/password-message' => [
				'label' => __( 'Activation: Password (formatted)', 'hm-user-activation' ),
				'key'   => 'password_message',
			],
		];

		foreach ( $sources as $name => $config ) {
			register_block_bindings_source(
				$name,
				[
					'label'              => $config['label'],
					'get_value_callback' => static function () use ( $config ): ?string {
						return self::get_value( $config['key'] );
					},
				]
			);
		}
	}

	/**
	 * Shared value resolver — called by each source's closure.
	 *
	 * @param string $key One of: error_message, username, password, username_message, password_message.
	 * @return string|null
	 */
	public static function get_value( string $key ): ?string {
		switch ( $key ) {
			case 'error_message':
				return Activation::get_error_message() ?: null;

			case 'username':
				return Activation::get_username() ?: null;

			case 'password':
				return Activation::get_password() ?: null;

			case 'username_message':
				$username = Activation::get_username();
				if ( ! $username ) {
					return null;
				}
				/* translators: %s: the activated user's username */
				return sprintf( __( 'Your username is: %s', 'hm-user-activation' ), $username );

			case 'password_message':
				$password = Activation::get_password();
				if ( ! $password ) {
					return null;
				}
				/* translators: %s: the generated password */
				return sprintf( __( 'Your password is: %s', 'hm-user-activation' ), $password );

			default:
				return null;
		}
	}
}
