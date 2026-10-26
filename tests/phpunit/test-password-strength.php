<?php
/**
 * Tests for the password checks in PasswordReset\validate_new_password().
 *
 * @package HM\UserActivation\Tests
 */

use function HM\UserActivation\PasswordReset\validate_new_password;

class Test_Password_Strength extends WP_UnitTestCase {

	private const STRONG = 'correct horse battery staple 91';

	/**
	 * A user that has not been saved, as the Complete your account form builds for a signup.
	 */
	private function signup_user( string $login = 'newsignup', string $email = 'newsignup@example.net' ): WP_User {
		return new WP_User( (object) [
			'ID'         => 0,
			'user_login' => $login,
			'user_email' => $email,
		] );
	}

	/**
	 * Run the password through validate_new_password() and return the errors.
	 */
	private function validate( WP_User $user, string $password, ?string &$pass1 = null ): WP_Error {
		$pass1 = $password;
		$pass2 = $password;

		return validate_new_password( $user, $pass1, $pass2 );
	}

	public function tear_down(): void {
		remove_all_actions( 'check_passwords' );
		remove_all_filters( 'hm_user_activation_password_minimum_strength' );
		remove_all_filters( 'hm_user_activation_password_is_weak' );
		remove_all_filters( 'hm_user_activation_password_max_length' );

		parent::tear_down();
	}

	public function test_password_cleared_by_check_passwords_is_rejected(): void {
		add_action( 'check_passwords', static function ( $login, &$pass1, &$pass2 ) {
			$pass1 = null;
			$pass2 = null;
		}, 10, 3 );

		$user   = self::factory()->user->create_and_get();
		$errors = $this->validate( $user, 'password', $pass1 );

		$this->assertSame( [ 'weak_password' ], $errors->get_error_codes() );
		$this->assertIsString( $pass1 );
	}

	public function test_password_over_the_length_limit_is_rejected_before_any_check(): void {
		$called = 0;
		add_action( 'check_passwords', static function () use ( &$called ) {
			++$called;
		} );

		$errors = $this->validate( $this->signup_user(), str_repeat( 'a', 257 ) );

		$this->assertSame( [ 'password_too_long' ], $errors->get_error_codes() );
		$this->assertSame( 0, $called );
	}

	public function test_password_at_the_length_limit_is_checked(): void {
		$called = 0;
		add_action( 'check_passwords', static function () use ( &$called ) {
			++$called;
		} );

		$this->validate( $this->signup_user(), str_repeat( 'a', 256 ) );

		$this->assertSame( 1, $called );
	}

	public function test_max_length_filter_changes_the_limit(): void {
		add_filter( 'hm_user_activation_password_max_length', static fn() => 40 );

		$this->assertSame( [ 'password_too_long' ], $this->validate( $this->signup_user(), str_repeat( 'b', 41 ) )->get_error_codes() );
		$this->assertFalse( $this->validate( $this->signup_user(), self::STRONG )->has_errors() );

		remove_all_filters( 'hm_user_activation_password_max_length' );
		add_filter( 'hm_user_activation_password_max_length', static fn() => 1 );
		$this->assertSame( 8, HM\UserActivation\Passwords\max_length() );
	}

	public function test_long_strong_password_is_accepted(): void {
		$password = implode( ' ', array_fill( 0, 10, 'correct horse battery staple 91' ) );
		$password = substr( $password, 0, 200 );

		$this->assertFalse( $this->validate( $this->signup_user(), $password )->has_errors() );
	}

	public function test_empty_password_is_not_reported_as_weak(): void {
		$user   = self::factory()->user->create_and_get();
		$errors = $this->validate( $user, '   ' );

		$this->assertSame( [ 'empty_password' ], $errors->get_error_codes() );
	}

	public function test_weak_password_is_rejected_for_a_signup(): void {
		$errors = $this->validate( $this->signup_user(), 'password' );

		$this->assertSame( [ 'weak_password' ], $errors->get_error_codes() );
	}

	public function test_strong_password_is_accepted_for_a_signup(): void {
		$errors = $this->validate( $this->signup_user(), self::STRONG );

		$this->assertFalse( $errors->has_errors() );
	}

	public function test_minimum_strength_filter_raises_the_bar(): void {
		$password = 'tangerine moon';

		$this->assertFalse( $this->validate( $this->signup_user(), $password )->has_errors(), 'Accepted at the default minimum.' );

		add_filter( 'hm_user_activation_password_minimum_strength', static fn() => 4 );

		$this->assertSame( [ 'weak_password' ], $this->validate( $this->signup_user(), $password )->get_error_codes() );
	}

	public function test_minimum_strength_is_kept_between_0_and_4(): void {
		add_filter( 'hm_user_activation_password_minimum_strength', static fn() => 12 );
		$this->assertSame( 4, HM\UserActivation\Passwords\minimum_strength() );

		remove_all_filters( 'hm_user_activation_password_minimum_strength' );
		add_filter( 'hm_user_activation_password_minimum_strength', static fn() => -2 );
		$this->assertSame( 0, HM\UserActivation\Passwords\minimum_strength() );
	}

	public function test_is_weak_filter_can_reject_a_strong_password(): void {
		add_filter( 'hm_user_activation_password_is_weak', '__return_true' );

		$errors = $this->validate( $this->signup_user(), self::STRONG );

		$this->assertSame( [ 'weak_password' ], $errors->get_error_codes() );
	}

	public function test_is_weak_filter_receives_the_user_and_results(): void {
		$received = null;

		add_filter( 'hm_user_activation_password_is_weak', static function ( $is_weak, $password, $user, $results ) use ( &$received ) {
			$received = [ $password, $user->user_login, $results['score'] ?? null ];
			return $is_weak;
		}, 10, 4 );

		$this->validate( $this->signup_user( 'someone' ), self::STRONG );

		$this->assertSame( [ self::STRONG, 'someone', 4 ], $received );
	}

	public function test_password_containing_the_login_is_rejected(): void {
		$user = $this->signup_user( 'jessicasmith', 'jess@example.net' );

		$this->assertSame( [ 'weak_password' ], $this->validate( $user, 'jessicasmith1' )->get_error_codes() );
	}

	public function test_user_inputs_include_the_user_and_site(): void {
		$user = $this->signup_user( 'jessicasmith', 'jess.smith@example.net' );

		$inputs = HM\UserActivation\Passwords\user_inputs( $user );

		$this->assertContains( 'jessicasmith', $inputs );
		$this->assertContains( 'jess.smith@example.net', $inputs );
		$this->assertContains( 'jess.smith', $inputs );
		$this->assertContains( get_option( 'admin_email' ), $inputs );
		$this->assertSame( array_unique( $inputs ), $inputs );
		$this->assertNotContains( '', $inputs );
	}
}
