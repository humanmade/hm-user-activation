<?php
/**
 * Creates the draft activation page on plugin activation.
 */

namespace HM\UserActivation;

class Page_Setup {

	/**
	 * Create the activation page as a draft (only once).
	 * Skips creation if a page ID is already saved and the post exists.
	 */
	public static function create_activation_page(): void {
		$existing_id = (int) get_option( 'hm_activation_page_id' );

		if ( $existing_id && get_post( $existing_id ) ) {
			return;
		}

		$page_id = wp_insert_post( [
			'post_title'   => __( 'Activate Your Account', 'hm-user-activation' ),
			'post_content' => self::default_page_content(),
			'post_status'  => 'draft',
			'post_type'    => 'page',
		] );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'hm_activation_page_id', $page_id );
		}
	}

	/**
	 * The default block content for the activation page.
	 *
	 * Includes:
	 *  - hm-user-activation/form block (the key input + auto-login checkbox)
	 *  - core/group (hm-user-activation/errors variant) with bound error paragraph
	 *  - core/group (hm-user-activation/success variant) with bound username/password paragraphs
	 */
	private static function default_page_content(): string {
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
<p>Your account has been successfully activated. Welcome!</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"hm-user-activation/username-message"}}}} -->
<p></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"hm-user-activation/password-message"}}}} -->
<p></p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->
BLOCKS;
	}
}
