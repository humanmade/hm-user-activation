<?php
/**
 * Prepares a multisite test site for the end-to-end suite. Safe to re-run.
 *
 * Run through `wp eval-file` by tests/bin/setup-env.js.
 *
 * @package HM\UserActivation\Tests
 */

use HM\UserActivation\PageSetup;

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

PageSetup\create_activation_page();
PageSetup\create_password_reset_page();

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

WP_CLI::success( 'Test site ready.' );
