<?php
/**
 * Plugin Name: HM User Activation
 * Description: Handles user account activations within a multisite network site, replacing wp-activate.php behaviour. Provides customisable activation emails, an editable activation page with blocks, optional auto-login, and a post-activation welcome email.
 * Version: 1.0.0
 * Author: Human Made
 * Author URI: https://humanmade.com
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Text Domain: hm-user-activation
 * Domain Path: /languages
 */

namespace HM\UserActivation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HM_USER_ACTIVATION_VERSION', '1.0.0' );
define( 'HM_USER_ACTIVATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'HM_USER_ACTIVATION_URL', plugin_dir_url( __FILE__ ) );

// Bail on non-multisite — this plugin is multisite-only.
if ( ! is_multisite() ) {
	add_action( 'admin_notices', __NAMESPACE__ . '\\multisite_required_notice' );
	return;
}

require_once HM_USER_ACTIVATION_DIR . 'includes/class-activation.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/class-emails.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/class-admin-settings.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/class-page-setup.php';
require_once HM_USER_ACTIVATION_DIR . 'includes/class-block-bindings.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\\on_plugin_activation' );

/**
 * Plugin activation: create the draft activation page.
 */
function on_plugin_activation(): void {
	Page_Setup::create_activation_page();
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
Activation::init();
Emails::init();
Admin_Settings::init();
Block_Bindings::init();

// Register blocks and editor assets.
add_action( 'init', __NAMESPACE__ . '\\register_blocks' );

function register_blocks(): void {
	register_block_type( HM_USER_ACTIVATION_DIR . 'blocks/activation-form' );
}

add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\\enqueue_editor_assets' );

function enqueue_editor_assets(): void {
	$asset = require HM_USER_ACTIVATION_DIR . 'js/variations.asset.php';

	wp_enqueue_script(
		'hm-user-activation-variations',
		HM_USER_ACTIVATION_URL . 'js/variations.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_set_script_translations( 'hm-user-activation-variations', 'hm-user-activation' );
}
