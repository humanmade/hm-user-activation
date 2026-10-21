<?php
/**
 * Tests for Registration\shows_username().
 *
 * @package HM\UserActivation\Tests
 */

use function HM\UserActivation\Registration\shows_username;

class Test_Shows_Username extends WP_UnitTestCase {

	private const BLOCK_OFF = '<!-- wp:hm-user-activation/registration-form {"showUsername":false} /-->';

	/**
	 * Make a page with this content and use it as the registration page.
	 */
	private function set_registration_page( string $content ): void {
		$page_id = self::factory()->post->create( [
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => $content,
		] );

		update_option( 'hm_activation_registration_page_id', $page_id );
	}

	public function test_true_without_a_registration_page(): void {
		delete_option( 'hm_activation_registration_page_id' );

		$this->assertTrue( shows_username() );
	}

	public function test_true_when_the_page_has_no_form_block(): void {
		$this->set_registration_page( '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' );

		$this->assertTrue( shows_username() );
	}

	public function test_false_when_the_block_turns_it_off(): void {
		$this->set_registration_page( self::BLOCK_OFF );

		$this->assertFalse( shows_username() );
	}

	public function test_true_when_the_block_turns_it_on(): void {
		$this->set_registration_page( '<!-- wp:hm-user-activation/registration-form {"showUsername":true} /-->' );

		$this->assertTrue( shows_username() );
	}

	public function test_true_when_the_attribute_is_missing(): void {
		$this->set_registration_page( '<!-- wp:hm-user-activation/registration-form /-->' );

		$this->assertTrue( shows_username() );
	}

	public function test_false_when_the_block_is_nested(): void {
		$this->set_registration_page(
			'<!-- wp:group --><div class="wp-block-group">' . self::BLOCK_OFF . '</div><!-- /wp:group -->'
		);

		$this->assertFalse( shows_username() );
	}

	public function test_false_when_the_block_is_in_a_synced_pattern(): void {
		$pattern_id = self::factory()->post->create( [
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_content' => self::BLOCK_OFF,
		] );

		$this->set_registration_page( '<!-- wp:block {"ref":' . $pattern_id . '} /-->' );

		$this->assertFalse( shows_username() );
	}

	public function test_the_first_form_block_wins(): void {
		$this->set_registration_page( self::BLOCK_OFF . '<!-- wp:hm-user-activation/registration-form /-->' );

		$this->assertFalse( shows_username() );
	}

	public function test_filter_can_turn_it_off(): void {
		$this->set_registration_page( '<!-- wp:hm-user-activation/registration-form /-->' );
		add_filter( 'hm_user_activation_registration_show_username', '__return_false' );

		$this->assertFalse( shows_username() );
	}

	public function test_filter_can_turn_it_on(): void {
		$this->set_registration_page( self::BLOCK_OFF );
		add_filter( 'hm_user_activation_registration_show_username', '__return_true' );

		$this->assertTrue( shows_username() );
	}

	public function test_filter_receives_the_block_setting(): void {
		$this->set_registration_page( self::BLOCK_OFF );
		$received = null;

		add_filter( 'hm_user_activation_registration_show_username', function ( $show ) use ( &$received ) {
			$received = $show;

			return $show;
		} );

		shows_username();

		$this->assertFalse( $received );
	}
}
