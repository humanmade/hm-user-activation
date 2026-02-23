<?php
/**
 * Registers individual block bindings sources for activation and password reset data.
 *
 * Requires WordPress 6.5+.
 *
 * Sources:
 *  hm-user-activation/error-message        → "Activation: Error message"
 *  hm-user-activation/username             → "Activation: Username"
 *  hm-user-activation/username-message     → "Activation: Username (formatted)"
 *  hm-user-activation/reset-url            → "Activation: Password reset URL"
 *  hm-user-activation/reset-error-message  → "Password reset: Error message"
 */

namespace HM\UserActivation\BlockBindings;

use HM\UserActivation\Activation;
use HM\UserActivation\PasswordReset;

function bootstrap(): void {
	add_action( 'init', __NAMESPACE__ . '\\register_sources' );
}

function register_sources(): void {
	if ( ! function_exists( 'register_block_bindings_source' ) ) {
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
		'hm-user-activation/username-message' => [
			'label' => __( 'Activation: Username (formatted)', 'hm-user-activation' ),
			'key'   => 'username_message',
		],
		'hm-user-activation/reset-url' => [
			'label' => __( 'Activation: Password reset URL', 'hm-user-activation' ),
			'key'   => 'reset_url',
		],
		'hm-user-activation/reset-error-message' => [
			'label' => __( 'Password reset: Error message', 'hm-user-activation' ),
			'key'   => 'reset_error_message',
		],
	];

	foreach ( $sources as $name => $config ) {
		register_block_bindings_source(
			$name,
			[
				'label'              => $config['label'],
				'get_value_callback' => static function () use ( $config ): ?string {
					return get_binding_value( $config['key'] );
				},
			]
		);
	}
}

/**
 * Resolve a binding key to its current value.
 */
function get_binding_value( string $key ): ?string {
	switch ( $key ) {
		case 'error_message':
			return Activation\get_error_message() ?: null;

		case 'username':
			return Activation\get_username() ?: null;

		case 'username_message':
			$username = Activation\get_username();
			if ( ! $username ) {
				return null;
			}
			/* translators: %s: the activated user's username */
			return sprintf( __( 'Your username is: %s', 'hm-user-activation' ), $username );

		case 'reset_url':
			return Activation\get_reset_url() ?: null;

		case 'reset_error_message':
			return PasswordReset\get_error_message() ?: null;

		default:
			return null;
	}
}
