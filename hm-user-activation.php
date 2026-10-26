<?php
/**
 * Plugin Name: HM User Activation
 * Description: Handles user account activations within a multisite network site, replacing wp-activate.php behaviour. Provides customisable activation emails, an editable activation page with blocks, optional auto-login, a post-activation welcome email, and a full password reset flow.
 * Version: 2.1.0
 * Author: Human Made
 * Author URI: https://humanmade.com
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: hm-user-activation
 * Domain Path: /languages
 * Network: true
 */

namespace HM\UserActivation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HM_USER_ACTIVATION_VERSION', '2.1.0' );
define( 'HM_USER_ACTIVATION_FILE', __FILE__ );
define( 'HM_USER_ACTIVATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'HM_USER_ACTIVATION_URL', plugin_dir_url( __FILE__ ) );

// Bail on non-multisite — this plugin is multisite-only.
if ( ! is_multisite() ) {
	add_action( 'admin_notices', __NAMESPACE__ . '\\multisite_required_notice' );
	return;
}

require_once HM_USER_ACTIVATION_DIR . 'includes/sites.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/security.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/registration.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/activation.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/complete-account.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/emails.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/resend.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/passwords.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/password-reset.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/admin-settings.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/page-setup.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/block-bindings.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/upgrade.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/signups-screen.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/class-activation.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/class-emails.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/class-block-bindings.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/class-page-setup.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/class-admin-settings.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\\on_plugin_activation' );

/**
 * Plugin activation.
 *
 * Network activation changes nothing on any site. Pages are created when a
 * site is turned on. Activating on a single site, as 1.x did, upgrades any
 * data an older version left behind on that site.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 */
function on_plugin_activation( bool $network_wide = false ): void {
	if ( $network_wide || ! Sites\is_enabled() ) {
		return;
	}

	Upgrade\upgrade();
}

/**
 * Show a notice if the plugin is activated on a non-multisite install.
 */
function multisite_required_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'HM User Activation requires WordPress Multisite.', 'hm-user-activation' )
	);
}

// Bootstrap.
Sites\bootstrap();
Security\bootstrap();
Registration\bootstrap();
Activation\bootstrap();
CompleteAccount\bootstrap();
Emails\bootstrap();
Resend\bootstrap();
PasswordReset\bootstrap();
Admin\bootstrap();
BlockBindings\bootstrap();
Upgrade\bootstrap();
SignupsScreen\bootstrap();

// Register blocks and editor assets.
add_action( 'init', __NAMESPACE__ . '\\register_blocks' );
add_action( 'init', __NAMESPACE__ . '\\register_password_strength_script' );

/**
 * Register the blocks on sites that use the plugin.
 */
function register_blocks(): void {
	if ( ! Sites\is_enabled() ) {
		return;
	}

	if ( ! file_exists( HM_USER_ACTIVATION_DIR . 'build/blocks' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\\build_missing_notice' );
		add_action( 'network_admin_notices', __NAMESPACE__ . '\\build_missing_notice' );
		return;
	}

	register_block_type( HM_USER_ACTIVATION_DIR . 'build/blocks/registration-form' );
	register_block_type( HM_USER_ACTIVATION_DIR . 'build/blocks/activation-form' );
	register_block_type( HM_USER_ACTIVATION_DIR . 'build/blocks/password-reset' );
	register_block_type( HM_USER_ACTIVATION_DIR . 'build/blocks/login-form' );
	register_block_type( HM_USER_ACTIVATION_DIR . 'build/blocks/resend-form' );
}

/**
 * Register the script behind the password strength meter. The set-password forms enqueue it.
 */
function register_password_strength_script(): void {
	$asset_file = HM_USER_ACTIVATION_DIR . 'build/password-strength/index.asset.php';

	// A source checkout has no build yet, so there is nothing to register.
	if ( ! file_exists( $asset_file ) || ! Sites\is_enabled() ) {
		return;
	}

	$asset = require $asset_file;

	wp_register_script(
		'hm-user-activation-password-strength',
		HM_USER_ACTIVATION_URL . 'build/password-strength/index.js',
		array_merge( $asset['dependencies'], [ 'password-strength-meter' ] ),
		$asset['version'],
		true
	);
}

/**
 * Tell admins that the blocks are missing because the plugin has not been built.
 */
function build_missing_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'HM User Activation has no build/ directory, so its blocks are not registered. Install a release, or run npm run build in the plugin directory.', 'hm-user-activation' )
	);
}

add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_editor_assets' );

function enqueue_editor_assets(): void {
	$asset_file = HM_USER_ACTIVATION_DIR . 'build/variations/index.asset.php';

	// A source checkout has no build yet, so there is nothing to enqueue.
	if ( ! file_exists( $asset_file ) || ! Sites\is_enabled() ) {
		return;
	}

	$asset = require $asset_file;

	wp_enqueue_script(
		'hm-user-activation-variations',
		HM_USER_ACTIVATION_URL . 'build/variations/index.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_set_script_translations( 'hm-user-activation-variations', 'hm-user-activation' );
}
