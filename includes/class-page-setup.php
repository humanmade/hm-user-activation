<?php
/**
 * Deprecated Page_Setup class from 1.0.0.
 *
 * Kept so code written against 1.0.0 keeps working. Its public static methods
 * call the namespaced functions that replaced them. Deprecation notices only
 * show when WP_DEBUG is on.
 */

namespace HM\UserActivation;

/**
 * Deprecated Page_Setup class from 1.0.0.
 *
 * @deprecated 1.1.0 Use the functions in the HM\UserActivation\PageSetup namespace.
 */
class Page_Setup {

	public static function create_activation_page(): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\PageSetup\create_activation_page()' );
		\HM\UserActivation\PageSetup\create_activation_page();
	}
}
