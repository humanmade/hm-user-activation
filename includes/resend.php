<?php
/**
 * Resend a pending activation email.
 *
 * Provides resend() and handles the resend form block submission. The form
 * always redirects to the same confirmation so it can't be used to find out
 * which email addresses have a pending registration. Redirecting also stops a
 * page refresh from sending the email again.
 */

namespace HM\UserActivation\Resend;

use HM\UserActivation\Activation;
use HM\UserActivation\Emails;

const QUERY_ARG = 'hm_activation_resent';
const META_KEY  = 'hm_activation_resends';

function bootstrap(): void {
	add_action( 'template_redirect', __NAMESPACE__ . '\\maybe_process' );
}

/**
 * Handle a resend form submission, then redirect back to the page with a flag.
 *
 * Every outcome gets the same redirect. The email is sent after the response
 * so timing doesn't show whether a signup exists.
 */
function maybe_process(): void {
	if ( empty( $_POST['_hm_resend_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_hm_resend_nonce'] ) ), 'hm_activation_resend' ) ) {
		Activation\result( [
			'success'       => false,
			'error_code'    => 'nonce_failed',
			'error_message' => __( 'Security check failed. Please refresh the page and try again.', 'hm-user-activation' ),
		] );
		return;
	}

	$email = sanitize_email( wp_unslash( $_POST['resend_email'] ?? '' ) );
	if ( is_email( $email ) && ! ip_limit_reached() ) {
		resend( $email, true );
	}

	wp_safe_redirect( add_query_arg( QUERY_ARG, '1', get_page_url() ) );
	exit;
}

/**
 * Count a resend request from this IP and report whether it is over the hourly limit.
 */
function ip_limit_reached(): bool {
	/**
	 * Filters how many resend requests one IP address can make per hour.
	 *
	 * @param int $limit Requests per hour. Default 10. 0 turns the limit off.
	 */
	$limit = max( 0, (int) apply_filters( 'hm_user_activation_resend_ip_limit', 10 ) );
	if ( ! $limit ) {
		return false;
	}

	/**
	 * Filters the client IP address used for the resend limit.
	 *
	 * Sites behind a proxy or load balancer that doesn't restore the visitor's
	 * address should return the real client IP here.
	 *
	 * @param string $ip Client IP address. Default REMOTE_ADDR.
	 */
	$ip = (string) apply_filters( 'hm_user_activation_client_ip', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );

	$name  = 'hm_resend_ip_' . substr( hash( 'sha256', $ip ), 0, 32 );
	$data  = get_site_transient( $name );
	$until = is_array( $data ) ? (int) $data['until'] : time() + HOUR_IN_SECONDS;
	$count = is_array( $data ) ? (int) $data['count'] : 0;

	set_site_transient( $name, [ 'count' => $count + 1, 'until' => $until ], max( 1, $until - time() ) );

	return $count >= $limit;
}

/**
 * URL of the current page without query args, for the form action and redirect.
 */
function get_page_url(): string {
	if ( is_singular() ) {
		$url = get_permalink( get_queried_object_id() );
		if ( $url ) {
			return $url;
		}
	}

	$home = wp_parse_url( home_url() );
	$host = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	$path = (string) strtok( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), '?' );

	return esc_url_raw( $host . '/' . ltrim( $path, '/' ) );
}

/**
 * Send the activation email for the newest pending signup for an email
 * address again.
 *
 * While the link is still valid the same key is kept, so a stranger can't
 * invalidate someone else's link. Once it has expired a new key is made and
 * the lifetime restarts. Refuses once the signup has been resent the maximum
 * number of times, or if it was resent less than the resend interval ago.
 *
 * @param string $email Email address the user signed up with.
 * @param bool   $defer Send the email on shutdown, after the response.
 * @return bool True if a pending signup was found and the email was sent or queued.
 */
function resend( string $email, bool $defer = false ): bool {
	global $wpdb;

	$signup = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->signups} WHERE user_email = %s AND active = 0 ORDER BY registered DESC LIMIT 1",
			$email
		)
	);

	if ( ! $signup ) {
		return false;
	}

	/**
	 * Filters whether an activation email can be resent for a signup.
	 *
	 * @param bool   $can_resend Whether to resend. Default true.
	 * @param object $signup     The pending row from the signups table.
	 */
	if ( ! apply_filters( 'hm_user_activation_can_resend', true, $signup ) ) {
		return false;
	}

	$meta = maybe_unserialize( $signup->meta );
	$meta = is_array( $meta ) ? $meta : [];
	$info = is_array( $meta[ META_KEY ] ?? null ) ? $meta[ META_KEY ] : [];
	$sent = (int) ( $info['count'] ?? 0 );
	$last = (int) ( $info['last'] ?? 0 );

	/**
	 * Filters how many times an activation email can be resent for one signup.
	 *
	 * @param int $limit Maximum resends. Default 5. 0 turns the limit off.
	 */
	$limit = max( 0, (int) apply_filters( 'hm_user_activation_resend_limit', 5 ) );
	if ( $limit && $sent >= $limit ) {
		return false;
	}

	/**
	 * Filters the minimum time between resends for a signup.
	 *
	 * @param int $seconds Seconds since the last resend. Default 300.
	 */
	$interval = max( 0, (int) apply_filters( 'hm_user_activation_resend_interval', 5 * MINUTE_IN_SECONDS ) );
	if ( $interval && $last && ( time() - $last ) < $interval ) {
		return false;
	}

	$meta[ META_KEY ] = [
		'count' => $sent + 1,
		'last'  => time(),
	];

	$data  = [ 'meta' => maybe_serialize( $meta ) ];
	$where = [
		'signup_id' => $signup->signup_id,
		'active'    => 0,
		'meta'      => $signup->meta,
	];
	$key   = $signup->activation_key;

	if ( Activation\is_expired( $signup ) ) {
		$key                     = bin2hex( random_bytes( 8 ) );
		$data['activation_key']  = $key;
		$data['registered']      = current_time( 'mysql', true );
		$where['activation_key'] = $signup->activation_key;
	}

	if ( ! $wpdb->update( $wpdb->signups, $data, $where ) ) {
		return false;
	}

	$send = static function () use ( $signup, $key ): void {
		Emails\send_activation_email( $signup->user_email, $key, $signup->user_login );
	};

	if ( ! $defer ) {
		$send();
		return true;
	}

	add_action( 'shutdown', static function () use ( $send ): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		$send();
	} );

	return true;
}

/**
 * Remove the resend counter from a pending signup's stored meta, so core
 * doesn't copy it into user meta or site options on activation.
 */
function clear_counter( object $signup ): void {
	global $wpdb;

	$meta = maybe_unserialize( $signup->meta );
	if ( ! is_array( $meta ) || ! isset( $meta[ META_KEY ] ) ) {
		return;
	}

	unset( $meta[ META_KEY ] );

	$wpdb->update(
		$wpdb->signups,
		[ 'meta' => maybe_serialize( $meta ) ],
		[
			'activation_key' => $signup->activation_key,
			'active'         => 0,
		]
	);
}

/**
 * Whether the current request is the redirect that follows a resend request.
 */
function was_resent(): bool {
	return isset( $_GET[ QUERY_ARG ] );
}
