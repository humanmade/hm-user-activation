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
	// 2 matches Altis's default.
	$default = 2;

	if ( function_exists( 'Altis\\Security\\Passwords\\get_minimum_strength' ) ) {
		$default = (int) \Altis\Security\Passwords\get_minimum_strength();
	}

	/**
	 * Filters the lowest zxcvbn score a new password can have.
	 *
	 * The default is Altis's minimum strength setting when it is present, else 2.
	 * The value is kept between 0 and 4.
	 *
	 * @param int $strength Minimum score, from 0 (very weak) to 4 (very strong).
	 */
	$strength = (int) apply_filters( 'hm_user_activation_password_minimum_strength', $default );

	return max( 0, min( 4, $strength ) );
}

/**
 * The most characters a new password can have.
 */
function max_length(): int {
	/**
	 * Filters the most characters a new password can have.
	 *
	 * The length is counted on the plain password. Longer ones are rejected
	 * before check_passwords runs. Hooks get the slashed password, which can be
	 * up to twice as long. Raising the limit makes the strength checks of other
	 * plugins slower, as they often score the whole password. The value is at
	 * least 8.
	 *
	 * @param int $length Maximum length in characters. Default 72.
	 */
	return max( 8, (int) apply_filters( 'hm_user_activation_password_max_length', 72 ) );
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

	// Only the start is scored. zxcvbn-php takes minutes on a very long string.
	if ( class_exists( 'ZxcvbnPhp\\Zxcvbn' ) ) {
		$results = ( new \ZxcvbnPhp\Zxcvbn() )->passwordStrength( mb_substr( $password, 0, 100 ), user_inputs( $user ) );
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
