<?php
/**
 * One-time upgrade of data saved by older versions.
 *
 * Runs once per site, in wp-admin, for a user who can manage options. The
 * hm_activation_db_version option records which upgrades a site has had.
 * Version 1.0.0 stored nothing network-wide, so there is nothing to upgrade
 * at network level.
 */

namespace HM\UserActivation\Upgrade;

use HM\UserActivation\PageSetup;

const DB_VERSION        = 1;
const DB_VERSION_OPTION = 'hm_activation_db_version';

/**
 * Binding sources 1.0.0 used to show the generated password.
 */
const PASSWORD_SOURCES = [
	'hm-user-activation/password',
	'hm-user-activation/password-message',
];

function bootstrap(): void {
	add_action( 'admin_init', __NAMESPACE__ . '\\maybe_upgrade' );
}

/**
 * Upgrade the current site when an administrator loads wp-admin.
 */
function maybe_upgrade(): void {
	if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	upgrade();
}

/**
 * Run any upgrades the current site has not had yet.
 *
 * Safe to run more than once. Each step only changes data still in the old form.
 */
function upgrade(): void {
	$version = (int) get_option( DB_VERSION_OPTION, 0 );

	if ( $version >= DB_VERSION ) {
		return;
	}

	// Leave the version alone when a step fails, so it is tried again.
	if ( $version < 1 && ! upgrade_from_1_0_0() ) {
		return;
	}

	update_option( DB_VERSION_OPTION, DB_VERSION );
}

/**
 * Move 1.0.0 settings and pages to their current form.
 *
 * @return bool False if a page could not be saved.
 */
function upgrade_from_1_0_0(): bool {
	migrate_sender_options();
	migrate_login_url();
	migrate_password_placeholder();
	$saved = migrate_password_bindings();

	// Only on sites that use the plugin, so unused sites don't get a draft page.
	$activation_page_id = (int) get_option( 'hm_activation_page_id' );
	if ( $activation_page_id && get_post( $activation_page_id ) ) {
		PageSetup\create_password_reset_page();
	}

	return $saved;
}

/**
 * Rename the 1.0.0 per-email sender options to the global sender options.
 *
 * 1.0.0 had a sender for the activation email and another for the welcome
 * email. The activation email sender is used first. A new option that is
 * already set is kept.
 */
function migrate_sender_options(): void {
	$renames = [
		'hm_activation_from_name'  => [ 'hm_activation_email_from_name', 'hm_activation_welcome_email_from_name' ],
		'hm_activation_from_email' => [ 'hm_activation_email_from', 'hm_activation_welcome_email_from' ],
	];

	foreach ( $renames as $option => $old_options ) {
		$value = (string) get_option( $option );

		foreach ( $old_options as $old_option ) {
			$old_value = get_option( $old_option );

			if ( $value === '' && is_string( $old_value ) && $old_value !== '' ) {
				update_option( $option, $old_value );
				$value = $old_value;
			}

			delete_option( $old_option );
		}
	}
}

/**
 * Turn the 1.0.0 log in URL into the log in page setting.
 *
 * A URL that is not a page on this site cannot be stored as a page, so it is
 * kept and Emails\get_login_url() still uses it.
 */
function migrate_login_url(): void {
	$url = get_option( 'hm_activation_login_url' );

	if ( $url === false ) {
		return;
	}

	$url = trim( (string) $url );

	if ( $url !== '' && ! (int) get_option( 'hm_activation_login_page_id' ) ) {
		$page_id = url_to_postid( $url );

		if ( $page_id && get_post_type( $page_id ) === 'page' ) {
			update_option( 'hm_activation_login_page_id', $page_id );
		} elseif ( untrailingslashit( $url ) !== untrailingslashit( wp_login_url() ) ) {
			return;
		}
	}

	delete_option( 'hm_activation_login_url' );
}

/**
 * Replace the 1.0.0 {password} placeholder in the welcome email.
 *
 * Passwords are no longer emailed, so it becomes the set-password link.
 */
function migrate_password_placeholder(): void {
	foreach ( [ 'hm_activation_welcome_email_subject', 'hm_activation_welcome_email_body' ] as $option ) {
		$value = get_option( $option );

		if ( ! is_string( $value ) || ! str_contains( $value, '{password}' ) ) {
			continue;
		}

		$value = str_replace(
			[ 'Password: {password}', '{password}' ],
			[ 'Set your password: {password_reset_link}', '{password_reset_link}' ],
			$value
		);

		update_option( $option, $value );
	}
}

/**
 * Update pages that use the 1.0.0 password bindings.
 *
 * In each page or synced pattern, the first block bound to a password source
 * becomes the "Set your password" and "Log in" buttons, and any others are
 * removed. Everything else on the page is kept. Pages that already have a
 * set-password button only lose the password blocks.
 *
 * @return bool False if a page could not be saved.
 */
function migrate_password_bindings(): bool {
	global $wpdb;

	$saved    = true;
	$post_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( 'page', 'wp_block' ) AND post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) AND post_content LIKE %s",
		'%' . $wpdb->esc_like( '"source":"hm-user-activation/password' ) . '%'
	) );

	foreach ( $post_ids as $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			continue;
		}

		$buttons = null;
		if ( ! str_contains( $post->post_content, '"source":"hm-user-activation/reset-url"' ) ) {
			$login   = ! str_contains( $post->post_content, '"source":"hm-user-activation/login-url"' );
			$buttons = parse_blocks( PageSetup\activation_buttons_content( $login ) )[0];
		}

		$blocks = parse_blocks( $post->post_content );
		$root   = replace_password_blocks(
			[
				'innerBlocks'  => $blocks,
				'innerContent' => array_fill( 0, count( $blocks ), null ),
			],
			$buttons
		);

		$content = serialize_blocks( $root['innerBlocks'] );
		if ( $content === $post->post_content ) {
			continue;
		}

		// Keep the 1.0.0 content, which may not have a revision yet.
		wp_save_post_revision( $post->ID );

		// The content is already on the site, so skip kses: on multisite it
		// would strip markup a site admin cannot add but a super admin did.
		$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}

		$result = wp_update_post( wp_slash( [
			'ID'           => $post->ID,
			'post_content' => $content,
		] ), true );

		if ( $kses ) {
			kses_init_filters();
		}

		if ( is_wp_error( $result ) || ! $result ) {
			$saved = false;
		}
	}

	return $saved;
}

/**
 * Replace or remove the password-bound blocks inside a block, at any depth.
 *
 * @param array      $block   Parsed block.
 * @param array|null $buttons Parsed block to put in place of the first match. Set to null once used.
 * @return array The block with its inner blocks and inner content updated.
 */
function replace_password_blocks( array $block, ?array &$buttons ): array {
	$inner_blocks  = [];
	$inner_content = [];
	$index         = 0;

	foreach ( $block['innerContent'] as $chunk ) {
		if ( $chunk !== null ) {
			$inner_content[] = $chunk;
			continue;
		}

		$inner_block = $block['innerBlocks'][ $index++ ];

		if ( ! is_password_block( $inner_block ) ) {
			$inner_blocks[]  = replace_password_blocks( $inner_block, $buttons );
			$inner_content[] = null;
			continue;
		}

		if ( $buttons ) {
			$inner_blocks[]  = $buttons;
			$inner_content[] = null;
			$buttons         = null;
			continue;
		}

		// Drop the block and the line break before it.
		$last = end( $inner_content );
		if ( is_string( $last ) && trim( $last ) === '' ) {
			array_pop( $inner_content );
		}
	}

	$block['innerBlocks']  = $inner_blocks;
	$block['innerContent'] = $inner_content;

	return $block;
}

/**
 * Whether a parsed block has an attribute bound to a 1.0.0 password source.
 */
function is_password_block( array $block ): bool {
	foreach ( (array) ( $block['attrs']['metadata']['bindings'] ?? [] ) as $binding ) {
		if ( is_array( $binding ) && in_array( $binding['source'] ?? '', PASSWORD_SOURCES, true ) ) {
			return true;
		}
	}

	return false;
}
