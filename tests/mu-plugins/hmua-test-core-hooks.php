<?php
/**
 * Test stand-in for a third-party consent and profile field plugin.
 *
 * Uses only WordPress core hooks: no hm_user_activation_* hooks and no
 * functions from this plugin. Inert unless HMUA_TESTS is defined, and every
 * callback does nothing unless the `hmua_test_core_fields` cookie is set.
 *
 * @package HM\UserActivation\Tests
 */

namespace HM\UserActivation\Tests\CoreHooks;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'HMUA_TESTS' ) || ! HMUA_TESTS ) {
	return;
}

/**
 * Whether the test cookie asks for these fields.
 */
function enabled(): bool {
	return ! empty( $_COOKIE['hmua_test_core_fields'] );
}

/**
 * A submitted text value, unslashed and sanitised.
 *
 * @param string $key Field name.
 */
function posted( string $key ): string {
	return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
}

/**
 * Print the company and terms fields.
 *
 * @param string         $company Company value.
 * @param bool           $terms   Whether the terms box is checked.
 * @param \WP_Error|null $errors  Errors to show next to the terms box, if any.
 */
function print_fields( string $company, bool $terms, ?\WP_Error $errors = null ): void {
	printf(
		'<p><label for="hmua-company">Company</label> <input type="text" id="hmua-company" name="hmua_company" value="%s"></p>',
		esc_attr( $company )
	);

	$message = $errors ? $errors->get_error_message( 'hmua_terms' ) : '';

	printf(
		'<p><input type="checkbox" id="hmua-terms" name="hmua_terms" value="1"%s> <label for="hmua-terms">I accept the terms</label>%s</p>',
		checked( $terms, true, false ),
		$message ? sprintf( ' <span class="hmua-test-error" id="hmua-terms-error">%s</span>', esc_html( $message ) ) : ''
	);
}

/**
 * Registration form: show the fields, keeping what was submitted or a
 * `hmua_company` query arg.
 */
add_action(
	'signup_extra_fields',
	static function ( $errors ): void {
		if ( ! enabled() ) {
			return;
		}

		if ( isset( $_POST['hmua_company'] ) ) {
			$company = posted( 'hmua_company' );
		} elseif ( isset( $_GET['hmua_company'] ) ) {
			$company = sanitize_text_field( wp_unslash( (string) $_GET['hmua_company'] ) );
		} else {
			$company = '';
		}

		print_fields( $company, ! empty( $_POST['hmua_terms'] ), $errors instanceof \WP_Error ? $errors : null );
	}
);

/**
 * Registration form: the terms must be accepted.
 */
add_filter(
	'wpmu_validate_user_signup',
	static function ( $result ) {
		if ( enabled() && is_array( $result ) && isset( $_POST['user_name'] ) && empty( $_POST['hmua_terms'] ) ) {
			$result['errors']->add( 'hmua_terms', 'Test: please accept the terms.' );
		}

		// An error that belongs to no field, which core shows above the extra fields.
		if ( enabled() && is_array( $result ) && isset( $_POST['user_name'] ) && posted( 'hmua_company' ) === 'generic' ) {
			$result['errors']->add( 'generic', 'Test: a generic error.' );
		}

		return $result;
	}
);

/**
 * Registration form: suggest a username from the `hmua_user` query arg
 * through core's form defaults filter.
 */
add_filter(
	'signup_user_init',
	static function ( $defaults ) {
		if ( enabled() && is_array( $defaults ) && empty( $defaults['user_name'] ) && isset( $_GET['hmua_user'] ) ) {
			$defaults['user_name'] = sanitize_user( wp_unslash( (string) $_GET['hmua_user'] ), true );
		}

		return $defaults;
	}
);

/**
 * Registration form: keep the fields in the signup meta.
 */
add_filter(
	'add_signup_meta',
	static function ( $meta ) {
		if ( ! enabled() || ! is_array( $meta ) ) {
			return $meta;
		}

		if ( isset( $_POST['hmua_company'] ) ) {
			$meta['hmua_company'] = posted( 'hmua_company' );
		}

		if ( isset( $_POST['hmua_terms'] ) ) {
			$meta['hmua_terms'] = '1';
		}

		return $meta;
	}
);

/**
 * Activation: save the fields kept in the signup meta.
 */
add_action(
	'wpmu_activate_user',
	static function ( $user_id, $password, $meta ): void {
		if ( ! enabled() || ! is_array( $meta ) ) {
			return;
		}

		foreach ( [ 'hmua_company', 'hmua_terms' ] as $key ) {
			if ( isset( $meta[ $key ] ) ) {
				update_user_meta( (int) $user_id, $key, $meta[ $key ] );
			}
		}
	},
	10,
	3
);

/**
 * Complete your account form: show the fields, filled from the submission.
 */
add_action(
	'register_form',
	static function (): void {
		if ( enabled() ) {
			print_fields( posted( 'hmua_company' ), ! empty( $_POST['hmua_terms'] ) );
		}
	}
);

/**
 * Complete your account form: the terms must be accepted. The message names
 * the login and email it was given.
 */
add_filter(
	'registration_errors',
	static function ( $errors, $login = '', $email = '' ) {
		if ( enabled() && $errors instanceof \WP_Error && empty( $_POST['hmua_terms'] ) ) {
			$errors->add( 'hmua_terms', sprintf( 'Test: %s &lt;%s&gt; must accept the terms.', esc_html( $login ), esc_html( $email ) ) );
		}

		return $errors;
	},
	10,
	3
);

/**
 * Complete your account form: save the fields on the new user.
 */
add_action(
	'user_register',
	static function ( $user_id ): void {
		if ( ! enabled() || ( ! isset( $_POST['hmua_company'] ) && ! isset( $_POST['hmua_terms'] ) ) ) {
			return;
		}

		update_user_meta( (int) $user_id, 'hmua_company', posted( 'hmua_company' ) );
		update_user_meta( (int) $user_id, 'hmua_terms', empty( $_POST['hmua_terms'] ) ? '' : '1' );
	}
);
