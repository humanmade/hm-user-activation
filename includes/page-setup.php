<?php
/**
 * Creates draft pages for activation and password reset on plugin activation.
 */

namespace HM\UserActivation\PageSetup;

/**
 * Create the activation page as a draft (only once).
 */
function create_activation_page(): void {
	$existing_id = (int) get_option( 'hm_activation_page_id' );

	if ( $existing_id && get_post( $existing_id ) ) {
		return;
	}

	$page_id = wp_insert_post( [
		'post_title'   => __( 'Activate Your Account', 'hm-user-activation' ),
		'post_content' => default_activation_page_content(),
		'post_status'  => 'draft',
		'post_type'    => 'page',
	] );

	if ( $page_id && ! is_wp_error( $page_id ) ) {
		update_option( 'hm_activation_page_id', $page_id );
	}
}

/**
 * Create the password reset page as a draft (only once).
 */
function create_password_reset_page(): void {
	$existing_id = (int) get_option( 'hm_activation_password_reset_page_id' );

	if ( $existing_id && get_post( $existing_id ) ) {
		return;
	}

	$page_id = wp_insert_post( [
		'post_title'   => __( 'Reset Your Password', 'hm-user-activation' ),
		'post_content' => default_password_reset_page_content(),
		'post_status'  => 'draft',
		'post_type'    => 'page',
	] );

	if ( $page_id && ! is_wp_error( $page_id ) ) {
		update_option( 'hm_activation_password_reset_page_id', $page_id );
	}
}

/**
 * Default block content for the activation page.
 *
 * Success group: shows username + a "Set your password" button bound to the
 * pre-generated reset URL stored in the activation result.
 */
function default_activation_page_content(): string {
	return <<<'BLOCKS'
<!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center">Activate Your Account</h1>
<!-- /wp:heading -->

<!-- wp:hm-user-activation/form /-->

<!-- wp:group {"metadata":{"variationName":"hm-user-activation/errors"},"className":"hm-activation-errors","style":{"border":{"radius":"4px"},"spacing":{"padding":{"top":"1rem","right":"1rem","bottom":"1rem","left":"1rem"}}}} -->
<div class="wp-block-group hm-activation-errors"><!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"hm-user-activation/error-message"}}}} -->
<p></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"metadata":{"variationName":"hm-user-activation/success"},"className":"hm-activation-success","style":{"border":{"radius":"4px"},"spacing":{"padding":{"top":"1rem","right":"1rem","bottom":"1rem","left":"1rem"}}}} -->
<div class="wp-block-group hm-activation-success"><!-- wp:paragraph -->
<p>Your account has been successfully activated.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"hm-user-activation/username-message"}}}} -->
<p></p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"url":{"source":"hm-user-activation/reset-url"}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Set your password</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:group -->
BLOCKS;
}

/**
 * Default block content for the password reset page.
 *
 * The password-reset block renders the appropriate form (request or set-password)
 * based on whether ?key= and ?login= are present in the URL. The three group
 * variants show feedback for each outcome and are hidden by default via PHP
 * render_block filtering.
 */
function default_password_reset_page_content(): string {
	return <<<'BLOCKS'
<!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center">Reset Your Password</h1>
<!-- /wp:heading -->

<!-- wp:hm-user-activation/password-reset /-->

<!-- wp:group {"metadata":{"variationName":"hm-user-activation/reset-errors"},"className":"hm-reset-errors","style":{"border":{"radius":"4px"},"spacing":{"padding":{"top":"1rem","right":"1rem","bottom":"1rem","left":"1rem"}}}} -->
<div class="wp-block-group hm-reset-errors"><!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"hm-user-activation/reset-error-message"}}}} -->
<p></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"metadata":{"variationName":"hm-user-activation/reset-request-success"},"className":"hm-reset-request-success","style":{"border":{"radius":"4px"},"spacing":{"padding":{"top":"1rem","right":"1rem","bottom":"1rem","left":"1rem"}}}} -->
<div class="wp-block-group hm-reset-request-success"><!-- wp:paragraph -->
<p>If an account exists with that email address or username, you will receive a password reset link shortly. Please check your inbox.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:group {"metadata":{"variationName":"hm-user-activation/reset-success"},"className":"hm-reset-success","style":{"border":{"radius":"4px"},"spacing":{"padding":{"top":"1rem","right":"1rem","bottom":"1rem","left":"1rem"}}}} -->
<div class="wp-block-group hm-reset-success"><!-- wp:paragraph -->
<p>Your password has been set. You can now log in.</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
BLOCKS;
}
