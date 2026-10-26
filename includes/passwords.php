<?php
/**
 * Server-side password strength check.
 *
 * Scores new passwords with zxcvbn-php when it is installed, so weak passwords
 * are rejected on the set-password forms even when no other plugin does it.
 */

namespace HM\UserActivation\Passwords;

/**
 * The lowest zxcvbn score (0 to 4) a new password can have.
 */
function minimum_strength(): int {
	// 3 is the score core's own meter treats as no longer weak.
	$default = 3;

	if ( function_exists( 'Altis\\Security\\Passwords\\get_minimum_strength' ) ) {
		$default = (int) \Altis\Security\Passwords\get_minimum_strength();
	}

	/**
	 * Filters the lowest zxcvbn score a new password can have.
	 *
	 * The default is Altis's minimum strength setting when it is present, else 3.
	 * The value is kept between 0 and 4.
	 *
	 * @param int $strength Minimum score, from 0 (very weak) to 4 (very strong).
	 */
	$strength = (int) apply_filters( 'hm_user_activation_password_minimum_strength', $default );

	return max( 0, min( 4, $strength ) );
}

/**
 * Words a password should not contain, taken from the user and the site.
 *
 * Mirrors the list core's password meter builds in the browser.
 *
 * @param \WP_User $user The user the password is for. May be unsaved.
 * @return string[]
 */
function user_inputs( \WP_User $user ): array {
	$inputs = [];

	foreach ( [ 'user_login', 'user_email', 'first_name', 'last_name', 'nickname', 'display_name', 'user_url', 'description' ] as $field ) {
		if ( isset( $user->$field ) && is_string( $user->$field ) ) {
			$inputs[] = $user->$field;
		}
	}

	if ( $user->user_email && str_contains( $user->user_email, '@' ) ) {
		$inputs[] = strstr( $user->user_email, '@', true );
	}

	$inputs[] = get_bloginfo( 'name' );
	$inputs[] = get_option( 'admin_email' );

	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( is_string( $host ) ) {
		$inputs = array_merge( $inputs, preg_split( '/[^a-z0-9]+/i', $host ) ?: [] );
	}

	$inputs = array_filter( array_map( 'trim', array_filter( $inputs, 'is_string' ) ), 'strlen' );

	return array_values( array_unique( $inputs ) );
}

/**
 * Whether a new password is too weak.
 *
 * @param string   $password The new password.
 * @param \WP_User $user     The user the password is for. May be unsaved.
 */
function is_weak( string $password, \WP_User $user ): bool {
	$is_weak = false;
	$results = null;

	if ( class_exists( 'ZxcvbnPhp\\Zxcvbn' ) ) {
		$results = ( new \ZxcvbnPhp\Zxcvbn() )->passwordStrength( $password, user_inputs( $user ) );
		$is_weak = $results['score'] < minimum_strength();
	}

	/**
	 * Filters whether a new password is too weak.
	 *
	 * Runs on the Complete your account and Password Reset forms. A site that
	 * does not install zxcvbn-php can use it to supply its own check.
	 *
	 * @param bool          $is_weak  Whether the score is below the minimum. False when zxcvbn-php is not installed.
	 * @param string        $password The new password.
	 * @param \WP_User      $user     The user the password is for. For a new signup this is an unsaved user with ID 0.
	 * @param array|null    $results  The zxcvbn result, with a `score` from 0 to 4. Null when zxcvbn-php is not installed.
	 */
	return (bool) apply_filters( 'hm_user_activation_password_is_weak', $is_weak, $password, $user, $results );
}
