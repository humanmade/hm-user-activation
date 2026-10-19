<?php
/**
 * Disables the admin email confirmation screen in E2E tests.
 *
 * Without this, WordPress can redirect an admin login to
 * `wp-login.php?action=confirm_admin_email` instead of wp-admin, which would
 * break the Playwright global setup.
 *
 * @package HM\UserActivation\Tests
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'admin_email_check_interval', '__return_zero' );
