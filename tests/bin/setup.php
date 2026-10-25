<?php
/**
 * Prepares a multisite test site for the end-to-end suite. Safe to re-run.
 *
 * Run through `wp eval-file` by tests/bin/setup-env.js.
 *
 * @package HM\UserActivation\Tests
 */

use HM\UserActivation\PageSetup;
use HM\UserActivation\Sites;

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

if ( ! is_multisite() ) {
	WP_CLI::error( 'Multisite is not enabled.' );
}

$rules = <<<'HTACCESS'
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]

RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]

RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
RewriteRule . index.php [L]
HTACCESS;

if ( false === file_put_contents( ABSPATH . '.htaccess', $rules . "\n" ) ) {
	WP_CLI::error( 'Could not write ' . ABSPATH . '.htaccess.' );
}

global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%/' );
flush_rewrite_rules( false );

update_option( 'blogname', 'HMUA Test Site' );

// A new network does not allow registration; the registration specs need it.
update_site_option( 'registration', 'user' );

// Turning registration on creates the draft pages.
update_option( Sites\OPTION, 1 );
PageSetup\create_pages();

$login_page_id = (int) get_option( 'hm_activation_login_page_id' );

if ( ! $login_page_id || ! get_post( $login_page_id ) ) {
	$login_page_id = wp_insert_post( [
		'post_title'   => 'Log In',
		'post_name'    => 'log-in',
		'post_content' => '<!-- wp:hm-user-activation/login-form /-->',
		'post_status'  => 'draft',
		'post_type'    => 'page',
	] );

	update_option( 'hm_activation_login_page_id', $login_page_id );
}

$page_ids = [
	(int) get_option( 'hm_activation_registration_page_id' ),
	(int) get_option( 'hm_activation_page_id' ),
	(int) get_option( 'hm_activation_password_reset_page_id' ),
	$login_page_id,
];

foreach ( $page_ids as $page_id ) {
	wp_update_post( [
		'ID'          => $page_id,
		'post_status' => 'publish',
	] );
}

// A second site that uses the plugin, and one that does not, for the network specs.
$network = get_network();

foreach ( [ 'hmua-second' => true, 'hmua-off' => false ] as $slug => $enabled ) {
	$site    = get_site_by_path( $network->domain, "/{$slug}/" );
	$site_id = $site && $site->path === "/{$slug}/" ? (int) $site->blog_id : wpmu_create_blog( $network->domain, "/{$slug}/", $slug, 1 );

	if ( is_wp_error( $site_id ) ) {
		WP_CLI::error( $site_id );
	}

	switch_to_blog( $site_id );
	$wp_rewrite->init();
	$wp_rewrite->set_permalink_structure( '/%postname%/' );
	flush_rewrite_rules( false );

	if ( $enabled ) {
		update_option( Sites\OPTION, 1 );
		PageSetup\create_pages();

		foreach ( [ 'hm_activation_registration_page_id', 'hm_activation_page_id', 'hm_activation_password_reset_page_id' ] as $option ) {
			wp_update_post( [
				'ID'          => (int) get_option( $option ),
				'post_status' => 'publish',
			] );
		}
	} else {
		delete_option( Sites\OPTION );
	}

	restore_current_blog();
	$wp_rewrite->init();
}

WP_CLI::success( 'Test site ready.' );
