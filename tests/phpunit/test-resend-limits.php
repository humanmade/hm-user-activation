<?php
/**
 * Tests for the limits on Resend\resend() and Resend\ip_limit_reached().
 *
 * @package HM\UserActivation\Tests
 */

use function HM\UserActivation\Resend\ip_limit_reached;
use function HM\UserActivation\Resend\resend;

class Test_Resend_Limits extends WP_UnitTestCase {

	private const EMAIL = 'resend-me@example.com';

	public function set_up(): void {
		parent::set_up();

		wpmu_signup_user( 'resendme', self::EMAIL );
	}

	/**
	 * Turn off the gap between resends and the lifetime cap, so only the
	 * hourly limit is left.
	 */
	private function isolate_hourly_limit(): void {
		add_filter( 'hm_user_activation_resend_interval', '__return_zero' );
		add_filter( 'hm_user_activation_resend_limit', '__return_zero' );
	}

	/**
	 * The resend counter stored with the signup.
	 */
	private function counter(): array {
		global $wpdb;

		$meta = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$wpdb->signups} WHERE user_email = %s", self::EMAIL ) ) );

		return $meta['hm_activation_resends'] ?? [];
	}

	/**
	 * Change parts of the stored resend counter.
	 */
	private function update_counter( array $changes ): void {
		global $wpdb;

		$meta = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$wpdb->signups} WHERE user_email = %s", self::EMAIL ) ) );

		$meta['hm_activation_resends'] = array_merge( $meta['hm_activation_resends'] ?? [], $changes );

		$wpdb->update( $wpdb->signups, [ 'meta' => maybe_serialize( $meta ) ], [ 'user_email' => self::EMAIL ] );
	}

	public function test_three_resends_an_hour_by_default(): void {
		$this->isolate_hourly_limit();

		$this->assertTrue( resend( self::EMAIL ) );
		$this->assertTrue( resend( self::EMAIL ) );
		$this->assertTrue( resend( self::EMAIL ) );
		$this->assertFalse( resend( self::EMAIL ) );
	}

	public function test_the_window_starts_with_the_first_resend(): void {
		$this->isolate_hourly_limit();

		resend( self::EMAIL );
		$first = $this->counter();

		$this->assertEqualsWithDelta( time(), $first['window'], 5 );
		$this->assertSame( 1, $first['window_count'] );

		$start = time() - 1800;
		$this->update_counter( [ 'window' => $start ] );
		resend( self::EMAIL );
		$second = $this->counter();

		$this->assertSame( $start, $second['window'] );
		$this->assertSame( 2, $second['window_count'] );
	}

	public function test_allowed_again_after_the_window_ends(): void {
		$this->isolate_hourly_limit();

		for ( $i = 0; $i < 3; $i++ ) {
			resend( self::EMAIL );
		}

		$this->assertFalse( resend( self::EMAIL ) );

		$this->update_counter( [ 'window' => time() - HOUR_IN_SECONDS - 60 ] );

		$this->assertTrue( resend( self::EMAIL ) );
		$this->assertSame( 1, $this->counter()['window_count'] );
	}

	public function test_still_limited_inside_the_window(): void {
		$this->isolate_hourly_limit();

		for ( $i = 0; $i < 3; $i++ ) {
			resend( self::EMAIL );
		}

		$this->update_counter( [ 'window' => time() - HOUR_IN_SECONDS + 60 ] );

		$this->assertFalse( resend( self::EMAIL ) );
	}

	public function test_zero_turns_the_hourly_limit_off(): void {
		$this->isolate_hourly_limit();
		add_filter( 'hm_user_activation_resend_hourly_limit', '__return_zero' );

		for ( $i = 0; $i < 6; $i++ ) {
			$this->assertTrue( resend( self::EMAIL ), "Resend {$i}" );
		}
	}

	public function test_filter_value_sets_the_limit(): void {
		$this->isolate_hourly_limit();
		add_filter( 'hm_user_activation_resend_hourly_limit', fn() => 2 );

		$this->assertTrue( resend( self::EMAIL ) );
		$this->assertTrue( resend( self::EMAIL ) );
		$this->assertFalse( resend( self::EMAIL ) );
	}

	public function test_hourly_filter_receives_the_signup(): void {
		$args = [];

		add_filter( 'hm_user_activation_resend_hourly_limit', function ( $limit, $signup = null ) use ( &$args ) {
			$args = [ $limit, $signup ];

			return $limit;
		}, 10, 2 );

		resend( self::EMAIL );

		$this->assertSame( 3, $args[0] );
		$this->assertIsObject( $args[1] );
		$this->assertSame( 'resendme', $args[1]->user_login );
		$this->assertSame( self::EMAIL, $args[1]->user_email );
	}

	public function test_the_five_minute_gap_still_applies(): void {
		$this->assertTrue( resend( self::EMAIL ) );
		$this->assertFalse( resend( self::EMAIL ) );

		$this->update_counter( [ 'last' => time() - 10 * MINUTE_IN_SECONDS ] );

		$this->assertTrue( resend( self::EMAIL ) );
	}

	public function test_the_lifetime_cap_of_five_still_applies(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( resend( self::EMAIL ), "Resend {$i}" );

			// Get past the gap and the hourly window for the next one.
			$this->update_counter( [
				'last'   => time() - 10 * MINUTE_IN_SECONDS,
				'window' => time() - 2 * HOUR_IN_SECONDS,
			] );
		}

		$this->assertFalse( resend( self::EMAIL ) );
		$this->assertSame( 5, $this->counter()['count'] );
	}

	public function test_the_ip_limit_is_off_by_default(): void {
		remove_all_filters( 'hm_user_activation_resend_ip_limit' );

		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertFalse( ip_limit_reached(), "Request {$i}" );
		}
	}

	public function test_the_ip_limit_still_works_when_a_site_turns_it_on(): void {
		add_filter( 'hm_user_activation_resend_ip_limit', fn() => 2 );

		$this->assertFalse( ip_limit_reached() );
		$this->assertFalse( ip_limit_reached() );
		$this->assertTrue( ip_limit_reached() );
	}
}
