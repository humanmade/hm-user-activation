<?php
/**
 * Deprecated Admin_Settings class from 1.0.0.
 *
 * Kept so code written against 1.0.0 keeps working. Its public static methods
 * call the namespaced functions that replaced them. Deprecation notices only
 * show when WP_DEBUG is on.
 */

namespace HM\UserActivation;

/**
 * Deprecated Admin_Settings class from 1.0.0.
 *
 * @deprecated 1.1.0 Use the functions in the HM\UserActivation\Admin namespace.
 */
class Admin_Settings {

	public static function init(): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Admin\bootstrap()' );
		\HM\UserActivation\Admin\bootstrap();
	}

	public static function add_settings_page(): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Admin\add_settings_page()' );
		\HM\UserActivation\Admin\add_settings_page();
	}

	public static function register_settings(): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Admin\register_settings()' );
		\HM\UserActivation\Admin\register_settings();
	}

	public static function field_page_select( array $args ): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Admin\field_page_select()' );
		\HM\UserActivation\Admin\field_page_select( $args );
	}

	public static function field_checkbox( array $args ): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Admin\field_checkbox()' );
		\HM\UserActivation\Admin\field_checkbox( $args );
	}

	public static function field_text( array $args ): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Admin\field_text()' );
		\HM\UserActivation\Admin\field_text( $args );
	}

	public static function field_email( array $args ): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Admin\field_email()' );
		\HM\UserActivation\Admin\field_email( $args );
	}

	public static function field_url( array $args ): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Admin\field_text()' );
		$option = $args['option'];
		printf(
			'<input type="url" name="%s" id="%s" value="%s" placeholder="%s" class="regular-text">',
			esc_attr( $option ),
			esc_attr( $option ),
			esc_attr( (string) get_option( $option ) ),
			esc_attr( $args['placeholder'] ?? '' )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', wp_kses( $args['description'], [ 'code' => [] ] ) );
		}
	}

	public static function field_textarea( array $args ): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Admin\field_textarea()' );
		\HM\UserActivation\Admin\field_textarea( $args );
	}

	public static function render_page(): void {
		_deprecated_function( __METHOD__, '1.1.0', 'HM\UserActivation\Admin\render_page()' );
		\HM\UserActivation\Admin\render_page();
	}
}
