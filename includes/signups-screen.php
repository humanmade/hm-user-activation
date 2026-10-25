<?php
/**
 * Compatibility with the network admin "Sign ups" screen from the
 * stuttter/wp-user-signups plugin.
 *
 * That screen can activate any signup. A signup made by the Registration
 * Form block must be activated on its site's activation page instead, so the
 * user sets a password and fills in the Complete your account form. Deleting
 * and resending stay allowed. These filters only run when that plugin is
 * installed.
 */

namespace HM\UserActivation\SignupsScreen;

use HM\UserActivation\Sites;

function bootstrap(): void {
	// Hides the row action and refuses the single activate action.
	add_filter( 'wp_signups_map_meta_cap', __NAMESPACE__ . '\\map_meta_cap', 10, 4 );

	// The bulk action does not check the capability for each signup.
	add_filter( 'wp_signups_sanitize_signup_ids', __NAMESPACE__ . '\\filter_signup_ids' );
}

/**
 * Refuse activate_signup for a Registration Form signup, or when no signup is given.
 *
 * @param string[] $caps    Primitive capabilities.
 * @param string   $cap     Requested capability.
 * @param int      $user_id User ID.
 * @param array    $args    Arguments. The first is the signup ID.
 * @return string[]
 */
function map_meta_cap( $caps, $cap, $user_id, $args ) {
	if ( $cap !== 'activate_signup' ) {
		return $caps;
	}

	$signup_id = (int) ( $args[0] ?? 0 );

	if ( ! $signup_id || ! can_activate( $signup_id ) ) {
		return [ 'do_not_allow' ];
	}

	return $caps;
}

/**
 * Drop Registration Form signups from the IDs an activate request works on.
 *
 * @param int[]|int|false $signup_ids Requested signup IDs, or one ID.
 * @return int[]|int|false
 */
function filter_signup_ids( $signup_ids ) {
	if ( request_action() !== 'activate' ) {
		return $signup_ids;
	}

	return array_values( array_filter( array_map( 'intval', (array) $signup_ids ), __NAMESPACE__ . '\\can_activate' ) );
}

/**
 * The action the Sign ups screen will run, worked out the same way it does.
 */
function request_action(): string {
	$fields = [];

	foreach ( [ 'action', 'bulk_action', 'bulk_action2' ] as $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification -- Read only to match the screen's own choice; the screen checks its nonce.
		$fields[ $field ] = isset( $_REQUEST[ $field ] ) && is_string( $_REQUEST[ $field ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $field ] ) ) : '';
	}

	if ( $fields['action'] && ! $fields['bulk_action'] && ! $fields['bulk_action2'] ) {
		return $fields['action'];
	}

	foreach ( [ 'bulk_action', 'bulk_action2' ] as $field ) {
		if ( $fields[ $field ] && $fields[ $field ] !== '-1' ) {
			return $fields[ $field ];
		}
	}

	return '';
}

/**
 * Whether the Sign ups screen may activate a signup. False for a missing
 * signup and for one made by the Registration Form block.
 *
 * @param int $signup_id Signup ID.
 */
function can_activate( int $signup_id ): bool {
	global $wpdb;

	$meta = $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$wpdb->signups} WHERE signup_id = %d", $signup_id ) );

	if ( $meta === null ) {
		return false;
	}

	$meta = maybe_unserialize( $meta );

	return ! Sites\is_form_signup( is_array( $meta ) ? $meta : [] );
}
