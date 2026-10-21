<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library and this plugin.
 *
 * Run it through `npm run test:php`, which uses the wp-env tests container
 * and the test library that wp-env provides.
 *
 * @package HM\UserActivation\Tests
 */

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';

if ( ! file_exists( $tests_dir . '/includes/bootstrap.php' ) ) {
	fwrite( STDERR, "The WordPress test library was not found in {$tests_dir}. Run the tests with `npm run test:php`.\n" );
	exit( 1 );
}

// wp-env's test config shares its database and the wp_ table prefix with the
// e2e site, and the test library drops every table with that prefix. Use a
// different prefix so the e2e site is left alone.
if ( ! getenv( 'WORDPRESS_TABLE_PREFIX' ) ) {
	putenv( 'WORDPRESS_TABLE_PREFIX=wptests_' );
}

if ( getenv( 'WORDPRESS_TABLE_PREFIX' ) === 'wp_' ) {
	fwrite( STDERR, "Refusing to run: the wp_ table prefix belongs to the e2e site.\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );

require_once $tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
	require dirname( __DIR__, 2 ) . '/hm-user-activation.php';
} );

require $tests_dir . '/includes/bootstrap.php';
