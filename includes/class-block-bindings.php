<?php
/**
 * Deprecated Block_Bindings class from 1.0.0.
 *
 * Kept so code written against 1.0.0 keeps working. Its public static methods
 * call the namespaced functions that replaced them. Deprecation notices only
 * show when WP_DEBUG is on.
 */

namespace HM\UserActivation;

/**
 * Deprecated Block_Bindings class from 1.0.0.
 *
 * @deprecated 1.1.0 Use the functions in the HM\UserActivation\BlockBindings namespace.
 */
class Block_Bindings {

	public static function init(): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\BlockBindings\bootstrap()' );
		\HM\UserActivation\BlockBindings\bootstrap();
	}

	public static function register_sources(): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\BlockBindings\register_sources()' );
		\HM\UserActivation\BlockBindings\register_sources();
	}

	public static function get_value( string $key ): ?string {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\BlockBindings\get_binding_value()' );
		return \HM\UserActivation\BlockBindings\get_binding_value( $key );
	}
}
