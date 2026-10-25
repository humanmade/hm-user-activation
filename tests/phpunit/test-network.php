<?php
/**
 * Tests for network activation: the wp-activate.php guard, the per-site
 * switch, cross-site keys and the Sign ups screen filters.
 *
 * @package HM\UserActivation\Tests
 */

use HM\UserActivation\Activation;
use HM\UserActivation\CompleteAccount;
use HM\UserActivation\Sites;

/**
 * Thrown by the wp_redirect filter so a test can read the redirect.
 */
class HMUA_Redirect_Exception extends Exception {}

class Test_Network extends WP_UnitTestCase {

	/**
	 * A site that uses the plugin, with a published activation page.
	 */
	private int $site_on;

	/**
	 * A site that does not use the plugin.
	 */
	private int $site_off;

	public function set_up(): void {
		parent::set_up();

		$this->site_on  = (int) self::factory()->blog->create();
		$this->site_off = (int) self::factory()->blog->create();

		update_blog_option( $this->site_on, Sites\OPTION, 1 );
		$this->publish_page( $this->site_on, 'hm_activation_page_id' );

		add_filter( 'wp_redirect', [ $this, 'throw_redirect' ] );
	}

	public function tear_down(): void {
		unset( $GLOBALS['pagenow'] );
		$_GET     = [];
		$_POST    = [];
		$_REQUEST = [];
		unset( $_COOKIE[ 'wp-activate-' . COOKIEHASH ] );

		parent::tear_down();
	}

	public function throw_redirect( string $location ): void {
		throw new HMUA_Redirect_Exception( $location );
	}

	/**
	 * Publish the page a site's option points at.
	 */
	private function publish_page( int $site_id, string $option ): void {
		switch_to_blog( $site_id );
		wp_update_post( [
			'ID'          => (int) get_option( $option ),
			'post_status' => 'publish',
		] );
		restore_current_blog();
	}

	/**
	 * Create a pending signup and return its row.
	 */
	private function signup( array $meta = [] ): object {
		global $wpdb;

		$login = 'hmua' . wp_generate_password( 8, false );
		$login = strtolower( $login );

		wpmu_signup_user( $login, $login . '@example.com', $meta );

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->signups} WHERE user_login = %s", $login ) );
	}

	/**
	 * Meta a Registration Form signup made on a site has.
	 */
	private function form_meta( int $site_id ): array {
		return [
			'add_to_blog'        => $site_id,
			'new_role'           => 'subscriber',
			Sites\SIGNUP_MARKER => true,
		];
	}

	/**
	 * Run the wp-activate.php guard and return where it redirected, or '' if it did not.
	 */
	private function run_guard(): string {
		$GLOBALS['pagenow'] = 'wp-activate.php';

		try {
			Activation\maybe_redirect_wp_activate();
		} catch ( HMUA_Redirect_Exception $redirect ) {
			return $redirect->getMessage();
		}

		return '';
	}

	/**
	 * The activation page URL on the site that uses the plugin, with a key.
	 */
	private function expected_url( string $key ): string {
		return add_query_arg( 'key', $key, Sites\activation_page_url( $this->site_on ) );
	}

	public function test_guard_redirects_a_get_key_to_the_signups_own_site(): void {
		$signup      = $this->signup( $this->form_meta( $this->site_on ) );
		$_GET['key'] = $signup->activation_key;

		$this->assertNotSame( '', Sites\activation_page_url( $this->site_on ) );
		$this->assertSame( $this->expected_url( $signup->activation_key ), $this->run_guard() );
	}

	public function test_guard_redirects_a_posted_key(): void {
		$signup       = $this->signup( $this->form_meta( $this->site_on ) );
		$_POST['key'] = $signup->activation_key;

		$this->assertSame( $this->expected_url( $signup->activation_key ), $this->run_guard() );
	}

	public function test_guard_redirects_a_key_in_cores_cookie(): void {
		$signup = $this->signup( $this->form_meta( $this->site_on ) );
		$_COOKIE[ 'wp-activate-' . COOKIEHASH ] = $signup->activation_key;

		$this->assertSame( $this->expected_url( $signup->activation_key ), $this->run_guard() );
	}

	public function test_guard_reads_the_query_string_before_the_form(): void {
		$first        = $this->signup( $this->form_meta( $this->site_on ) );
		$second       = $this->signup( $this->form_meta( $this->site_on ) );
		$_GET['key']  = $first->activation_key;
		$_POST['key'] = $second->activation_key;

		$this->assertSame( $this->expected_url( $first->activation_key ), $this->run_guard() );
	}

	public function test_guard_redirects_an_admin_signup_for_a_site_that_uses_the_plugin(): void {
		$signup      = $this->signup( [ 'add_to_blog' => $this->site_on, 'new_role' => 'subscriber' ] );
		$_GET['key'] = $signup->activation_key;

		$this->assertSame( $this->expected_url( $signup->activation_key ), $this->run_guard() );
	}

	public function test_guard_stops_a_form_signup_whose_site_has_no_activation_page(): void {
		$signup      = $this->signup( $this->form_meta( $this->site_off ) );
		$_GET['key'] = $signup->activation_key;

		$error = Activation\get_wp_activate_redirect( $signup->activation_key );
		$this->assertWPError( $error );

		$this->expectException( WPDieException::class );
		$this->run_guard();
	}

	public function test_guard_stops_a_form_signup_whose_activation_page_is_a_draft(): void {
		switch_to_blog( $this->site_on );
		wp_update_post( [
			'ID'          => (int) get_option( 'hm_activation_page_id' ),
			'post_status' => 'draft',
		] );
		restore_current_blog();

		$signup = $this->signup( $this->form_meta( $this->site_on ) );

		$this->assertWPError( Activation\get_wp_activate_redirect( $signup->activation_key ) );
	}

	public function test_guard_leaves_a_signup_with_no_site_to_core(): void {
		$signup      = $this->signup();
		$_GET['key'] = $signup->activation_key;

		$this->assertNull( Activation\get_wp_activate_redirect( $signup->activation_key ) );
		$this->assertSame( '', $this->run_guard() );
	}

	public function test_guard_leaves_an_admin_signup_for_a_site_without_the_plugin_to_core(): void {
		$signup      = $this->signup( [ 'add_to_blog' => $this->site_off, 'new_role' => 'subscriber' ] );
		$_GET['key'] = $signup->activation_key;

		$this->assertSame( '', $this->run_guard() );
	}

	public function test_guard_leaves_an_unknown_key_to_core(): void {
		$_GET['key'] = 'not-a-real-key';

		$this->assertSame( '', $this->run_guard() );
	}

	public function test_guard_only_runs_on_wp_activate(): void {
		$signup      = $this->signup( $this->form_meta( $this->site_on ) );
		$_GET['key'] = $signup->activation_key;

		$GLOBALS['pagenow'] = 'index.php';
		Activation\maybe_redirect_wp_activate();

		$this->assertTrue( true, 'No redirect away from wp-activate.php.' );
	}

	public function test_complete_account_refuses_a_key_from_another_site(): void {
		$other   = $this->signup( $this->form_meta( $this->site_on ) );
		$unknown = CompleteAccount\check_key( 'not-a-real-key' );
		$error   = CompleteAccount\check_key( $other->activation_key );

		$this->assertWPError( $error );
		$this->assertSame( $unknown->get_error_code(), $error->get_error_code() );
		$this->assertSame( $unknown->get_error_message(), $error->get_error_message() );
	}

	public function test_complete_account_accepts_a_key_for_this_site(): void {
		$here = $this->signup( $this->form_meta( get_current_blog_id() ) );

		$this->assertIsObject( CompleteAccount\check_key( $here->activation_key ) );
	}

	public function test_complete_account_accepts_a_key_with_no_site(): void {
		$signup = $this->signup();

		$this->assertIsObject( CompleteAccount\check_key( $signup->activation_key ) );
	}

	public function test_a_site_is_off_by_default(): void {
		$this->assertFalse( Sites\is_enabled( $this->site_off ) );
	}

	public function test_a_never_saved_site_with_a_published_registration_page_is_on(): void {
		switch_to_blog( $this->site_off );
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_option( 'hm_activation_registration_page_id', $page_id );
		restore_current_blog();

		$this->assertTrue( Sites\is_enabled( $this->site_off ) );
	}

	public function test_a_never_saved_site_with_a_draft_registration_page_is_off(): void {
		switch_to_blog( $this->site_off );
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'draft' ] );
		update_option( 'hm_activation_registration_page_id', $page_id );
		restore_current_blog();

		$this->assertFalse( Sites\is_enabled( $this->site_off ) );
	}

	public function test_a_saved_off_value_wins_over_a_published_registration_page(): void {
		switch_to_blog( $this->site_off );
		$page_id = self::factory()->post->create( [ 'post_type' => 'page', 'post_status' => 'publish' ] );
		update_option( 'hm_activation_registration_page_id', $page_id );
		update_option( Sites\OPTION, 0 );
		restore_current_blog();

		$this->assertSame( 0, (int) get_blog_option( $this->site_off, Sites\OPTION ) );
		$this->assertFalse( Sites\is_enabled( $this->site_off ) );
	}

	public function test_turning_a_site_on_creates_its_draft_pages(): void {
		update_blog_option( $this->site_off, Sites\OPTION, 1 );

		switch_to_blog( $this->site_off );
		foreach ( [ 'hm_activation_page_id', 'hm_activation_registration_page_id', 'hm_activation_password_reset_page_id' ] as $option ) {
			$this->assertSame( 'draft', get_post_status( (int) get_option( $option ) ), $option );
		}
		restore_current_blog();
	}

	public function test_turning_a_site_on_again_does_not_duplicate_pages(): void {
		switch_to_blog( $this->site_on );
		$page_id = (int) get_option( 'hm_activation_page_id' );
		update_option( Sites\OPTION, 0 );
		update_option( Sites\OPTION, 1 );
		$this->assertSame( $page_id, (int) get_option( 'hm_activation_page_id' ) );
		restore_current_blog();
	}

	public function test_network_activation_creates_no_pages(): void {
		switch_to_blog( $this->site_off );
		$pages = count( get_pages( [ 'post_status' => 'publish,draft' ] ) );

		HM\UserActivation\on_plugin_activation( true );

		$this->assertSame( $pages, count( get_pages( [ 'post_status' => 'publish,draft' ] ) ) );
		$this->assertFalse( get_option( 'hm_activation_page_id' ) );
		$this->assertNull( get_option( Sites\OPTION, null ) );
		restore_current_blog();
	}

	/**
	 * Post the Edit Site form as a super admin.
	 */
	private function save_site_form( int $site_id, bool $checked ): void {
		$user_id = self::factory()->user->create();
		grant_super_admin( $user_id );
		wp_set_current_user( $user_id );

		$_POST[ Sites\FORM_FIELD ] = '1';
		if ( $checked ) {
			$_POST[ Sites\OPTION ] = '1';
		}
		$_REQUEST['action']   = 'update-site';
		$_REQUEST['id']       = (string) $site_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'edit-site' );

		do_action( 'load-site-info.php' );
	}

	public function test_the_edit_site_form_turns_a_site_on_and_off(): void {
		$this->save_site_form( $this->site_off, true );
		$this->assertSame( 1, (int) get_blog_option( $this->site_off, Sites\OPTION ) );
		$this->assertTrue( Sites\is_enabled( $this->site_off ) );

		unset( $_POST[ Sites\OPTION ] );
		do_action( 'load-site-info.php' );
		$this->assertSame( 0, (int) get_blog_option( $this->site_off, Sites\OPTION ) );
		$this->assertFalse( Sites\is_enabled( $this->site_off ) );
	}

	public function test_a_form_without_the_field_leaves_the_option_alone(): void {
		$this->save_site_form( $this->site_on, false );
		unset( $_POST[ Sites\FORM_FIELD ] );
		update_blog_option( $this->site_on, Sites\OPTION, 1 );

		do_action( 'load-site-info.php' );

		$this->assertSame( 1, (int) get_blog_option( $this->site_on, Sites\OPTION ) );
	}

	public function test_the_edit_site_form_needs_manage_sites(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_POST[ Sites\FORM_FIELD ] = '1';
		$_POST[ Sites\OPTION ]     = '1';
		$_REQUEST['action']        = 'update-site';
		$_REQUEST['id']            = (string) $this->site_off;
		$_REQUEST['_wpnonce']      = wp_create_nonce( 'edit-site' );

		do_action( 'load-site-info.php' );

		$this->assertNull( get_blog_option( $this->site_off, Sites\OPTION, null ) );
	}

	public function test_the_edit_site_form_needs_its_nonce(): void {
		$this->save_site_form( $this->site_off, true );
		update_blog_option( $this->site_off, Sites\OPTION, 0 );
		$_REQUEST['_wpnonce'] = 'bad';

		$this->expectException( WPDieException::class );
		do_action( 'load-site-info.php' );
	}

	public function test_signups_screen_cannot_activate_a_form_signup(): void {
		$form  = $this->signup( $this->form_meta( $this->site_on ) );
		$admin = $this->signup( [ 'add_to_blog' => $this->site_on, 'new_role' => 'subscriber' ] );
		$caps  = [ 'activate_signup' ];

		$this->assertSame( [ 'do_not_allow' ], apply_filters( 'wp_signups_map_meta_cap', $caps, 'activate_signup', 1, [ $form->signup_id ] ) );
		$this->assertSame( [ 'do_not_allow' ], apply_filters( 'wp_signups_map_meta_cap', $caps, 'activate_signup', 1, [] ) );
		$this->assertSame( [ 'do_not_allow' ], apply_filters( 'wp_signups_map_meta_cap', $caps, 'activate_signup', 1, [ 999999 ] ) );
		$this->assertSame( $caps, apply_filters( 'wp_signups_map_meta_cap', $caps, 'activate_signup', 1, [ $admin->signup_id ] ) );
		$this->assertSame( [ 'delete_signup' ], apply_filters( 'wp_signups_map_meta_cap', [ 'delete_signup' ], 'delete_signup', 1, [ $form->signup_id ] ) );
		$this->assertSame( [ 'resend_signup' ], apply_filters( 'wp_signups_map_meta_cap', [ 'resend_signup' ], 'resend_signup', 1, [ $form->signup_id ] ) );
	}

	public function test_signups_screen_bulk_activate_skips_form_signups(): void {
		$form  = (int) $this->signup( $this->form_meta( $this->site_on ) )->signup_id;
		$admin = (int) $this->signup()->signup_id;

		$_REQUEST = [ 'action' => 'activate' ];
		$this->assertSame( [ $admin ], apply_filters( 'wp_signups_sanitize_signup_ids', [ $form, $admin ] ) );

		$_REQUEST = [ 'action' => '-1', 'bulk_action' => 'activate' ];
		$this->assertSame( [ $admin ], apply_filters( 'wp_signups_sanitize_signup_ids', [ $form, $admin ] ) );

		$_REQUEST = [ 'action' => '-1', 'bulk_action' => '-1', 'bulk_action2' => 'activate' ];
		$this->assertSame( [], apply_filters( 'wp_signups_sanitize_signup_ids', $form ) );

		$_REQUEST = [ 'action' => 'delete' ];
		$this->assertSame( [ $form, $admin ], apply_filters( 'wp_signups_sanitize_signup_ids', [ $form, $admin ] ) );

		$_REQUEST = [ 'action' => 'activate', 'bulk_action' => 'resend' ];
		$this->assertSame( [ $form, $admin ], apply_filters( 'wp_signups_sanitize_signup_ids', [ $form, $admin ] ) );
	}

	/**
	 * Run a callback and return the body of the last email it sent.
	 */
	private function last_mail( callable $callback ): string {
		$body    = '';
		$capture = static function ( $short_circuit, array $atts ) use ( &$body ) {
			$body = (string) $atts['message'];
			return $short_circuit;
		};

		add_filter( 'pre_wp_mail', $capture, 1, 2 );
		$callback();
		remove_filter( 'pre_wp_mail', $capture, 1 );

		return $body;
	}

	public function test_activation_email_comes_from_the_signups_site(): void {
		$body = $this->last_mail( fn() => $this->signup( $this->form_meta( $this->site_on ) ) );

		$this->assertStringContainsString( Sites\activation_page_url( $this->site_on ) . '?key=', $body );
		$this->assertStringNotContainsString( 'wp-activate.php', $body );
	}

	public function test_core_sends_the_email_for_a_site_without_the_plugin(): void {
		$body = $this->last_mail( fn() => $this->signup( [ 'add_to_blog' => $this->site_off, 'new_role' => 'subscriber' ] ) );

		$this->assertStringContainsString( 'wp-activate.php?key=', $body );
	}

	public function test_network_welcome_email_is_only_suppressed_for_the_plugins_signups(): void {
		$this->assertSame( 5, apply_filters( 'wpmu_welcome_user_notification', 5, 'password', [ 'add_to_blog' => $this->site_off ] ) );
		$this->assertFalse( apply_filters( 'wpmu_welcome_user_notification', 5, 'password', $this->form_meta( $this->site_on ) ) );
	}

	public function test_site_welcome_password_is_only_redacted_by_the_activation_page(): void {
		$this->assertSame( 'Password: secret', apply_filters( 'update_welcome_email', 'Password: secret', 2, 1, 'secret', 'Title', [] ) );

		Activation\is_activating( true );
		$redacted = apply_filters( 'update_welcome_email', 'Password: secret', 2, 1, 'secret', 'Title', [] );
		Activation\is_activating( false );

		$this->assertStringNotContainsString( 'secret', $redacted );
	}
}
