<?php
/**
 * Server-side render for hm-user-activation/login-form.
 *
 * Wraps wp_login_form() with block-editor-controlled settings.
 * The "Forgot your password?" link uses wp_lostpassword_url(), which is
 * automatically filtered to the configured password reset page.
 *
 * Nothing is rendered for already-logged-in users.
 */

if ( is_user_logged_in() ) {
	return;
}

$remember  = isset( $attributes['rememberMe'] ) ? (bool) $attributes['rememberMe'] : true;
$redirect  = ! empty( $attributes['redirect'] ) ? esc_url_raw( $attributes['redirect'] ) : admin_url();

$form = wp_login_form( [
	'echo'           => false,
	'remember'       => $remember,
	'redirect'       => $redirect,
	'label_username' => __( 'Username or email address', 'hm-user-activation' ),
] );

$forgot_link = sprintf(
	'<p class="hm-login-form__forgot"><a href="%s">%s</a></p>',
	esc_url( wp_lostpassword_url() ),
	esc_html__( 'Forgot your password?', 'hm-user-activation' )
);

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'hm-login-form' ] );

echo '<div ' . $wrapper_attributes . '>' . $form . $forgot_link . '</div>';
