<?php
/**
 * Tests for Registration\generate_user_name().
 *
 * @package HM\UserActivation\Tests
 */

use function HM\UserActivation\Registration\generate_user_name;
use function HM\UserActivation\Registration\user_name_available;

class Test_Generate_User_Name extends WP_UnitTestCase {

	/**
	 * Create an account with the given login and a different email address.
	 */
	private function create_user( string $login ): int {
		return self::factory()->user->create( [
			'user_login' => $login,
			'user_email' => $login . '-account@example.com',
		] );
	}

	/**
	 * Whether core's signup validation has anything to say about a username.
	 */
	private function user_name_errors( string $user_name ): array {
		$result = wpmu_validate_user_signup( $user_name, 'new-address@example.com' );

		return $result['errors']->get_error_messages( 'user_name' );
	}

	public function test_plain_local_part(): void {
		$this->assertSame( 'jane', generate_user_name( 'jane@example.com' ) );
	}

	public function test_symbols_and_case_are_dropped(): void {
		$this->assertSame( 'janeoneiltag', generate_user_name( 'Jane.O-Neil+Tag@Example.com' ) );
	}

	public function test_short_local_part_gets_a_prefix(): void {
		$this->assertSame( 'userab', generate_user_name( 'ab@example.com' ) );
	}

	/**
	 * @dataProvider empty_or_invalid_emails
	 */
	public function test_empty_or_invalid_email( string $email ): void {
		$this->assertSame( 'user', generate_user_name( $email ) );
	}

	public function empty_or_invalid_emails(): array {
		return [
			'empty'      => [ '' ],
			'no at sign' => [ 'not-an-email' ],
			'no local'   => [ '@example.com' ],
			'only dots'  => [ '...@example.com' ],
		];
	}

	public function test_numeric_local_part_gets_a_prefix(): void {
		$this->assertSame( 'user12345', generate_user_name( '12345@example.com' ) );
	}

	public function test_long_local_part_is_cut_short(): void {
		$email = str_repeat( 'a', 70 ) . '@example.com';
		$name  = generate_user_name( $email );

		$this->assertLessThanOrEqual( 60, strlen( $name ) );
		$this->assertSame( [], $this->user_name_errors( $name ) );
	}

	public function test_long_local_part_with_collision_stays_valid(): void {
		$first = generate_user_name( str_repeat( 'a', 70 ) . '@example.com' );
		$this->create_user( $first );

		$name = generate_user_name( str_repeat( 'a', 70 ) . '@example.com' );

		$this->assertNotSame( $first, $name );
		$this->assertLessThanOrEqual( 60, strlen( $name ) );
		$this->assertSame( [], $this->user_name_errors( $name ) );
	}

	public function test_existing_user_adds_a_number(): void {
		$this->create_user( 'jane' );

		$this->assertSame( 'jane2', generate_user_name( 'jane@example.com' ) );
	}

	public function test_existing_signup_adds_a_number(): void {
		wpmu_signup_user( 'jane', 'someone-else@example.com' );

		$this->assertSame( 'jane2', generate_user_name( 'jane@example.com' ) );
	}

	public function test_numbers_are_tried_in_order(): void {
		$this->create_user( 'jane' );
		$this->create_user( 'jane2' );

		$this->assertSame( 'jane3', generate_user_name( 'jane@example.com' ) );
	}

	public function test_network_illegal_names_are_avoided(): void {
		update_site_option( 'illegal_names', [ 'jane' ] );

		$this->assertSame( 'jane2', generate_user_name( 'jane@example.com' ) );
	}

	public function test_core_default_illegal_names_are_used_without_the_option(): void {
		global $wpdb;

		// The test install has an admin account, which would also make the name unavailable.
		$admin = get_user_by( 'login', 'admin' );

		if ( $admin ) {
			$wpdb->update( $wpdb->users, [ 'user_login' => 'firstadmin' ], [ 'ID' => $admin->ID ] );
			clean_user_cache( $admin->ID );
		}

		$this->assertFalse( username_exists( 'admin' ) );

		delete_site_option( 'illegal_names' );

		$this->assertFalse( user_name_available( 'admin' ) );
		$this->assertTrue( user_name_available( 'jane' ) );
	}

	public function test_illegal_user_logins_filter_is_respected(): void {
		add_filter( 'illegal_user_logins', fn() => [ 'Jane' ] );

		$this->assertSame( 'jane2', generate_user_name( 'jane@example.com' ) );
	}

	public function test_random_suffix_when_numbers_are_taken(): void {
		$this->create_user( 'jane' );

		for ( $i = 2; $i <= 9; $i++ ) {
			$this->create_user( 'jane' . $i );
		}

		$name = generate_user_name( 'jane@example.com' );

		$this->assertMatchesRegularExpression( '/^jane[a-z0-9]{6}$/', $name );
		$this->assertSame( [], $this->user_name_errors( $name ) );
	}

	/**
	 * @dataProvider emails_for_validation
	 */
	public function test_result_passes_core_validation( string $email ): void {
		$this->assertSame( [], $this->user_name_errors( generate_user_name( $email ) ) );
	}

	public function emails_for_validation(): array {
		return [
			'plain'     => [ 'jane@example.com' ],
			'symbols'   => [ 'Jane.O-Neil+Tag@Example.com' ],
			'short'     => [ 'ab@example.com' ],
			'numeric'   => [ '12345@example.com' ],
			'empty'     => [ '' ],
			'invalid'   => [ 'not-an-email' ],
			'reserved'  => [ 'admin@example.com' ],
			'long'      => [ str_repeat( 'b', 80 ) . '@example.com' ],
		];
	}

	public function test_result_does_not_depend_on_the_email_having_an_account(): void {
		$without = generate_user_name( 'jane@example.com' );

		self::factory()->user->create( [
			'user_login' => 'someoneelse',
			'user_email' => 'jane@example.com',
		] );

		$this->assertSame( $without, generate_user_name( 'jane@example.com' ) );
	}

	public function test_filter_receives_name_and_email_and_its_value_is_used(): void {
		$args = [];

		add_filter( 'hm_user_activation_generated_user_name', function ( $name, $email ) use ( &$args ) {
			$args = [ $name, $email ];

			return 'chosenbyfilter';
		}, 10, 2 );

		$this->assertSame( 'chosenbyfilter', generate_user_name( 'Jane@Example.com' ) );
		$this->assertSame( [ 'jane', 'Jane@Example.com' ], $args );
	}
}
