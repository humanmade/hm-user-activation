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

// Keep the post-login destination on this site, so a block attribute cannot be
// used to bounce authenticated users off to another host.
$redirect = wp_validate_redirect( $redirect, admin_url() );

$form = wp_login_form( [
	'echo'           => false,
	'remember'       => $remember,
	'redirect'       => $redirect,
	'label_username' => __( 'Username or email address', 'hm-user-activation' ),
] );

// Give the submit the theme's button class, as the core login/out block does.
$processor = new WP_HTML_Tag_Processor( $form );
while ( $processor->next_tag( 'input' ) ) {
	if ( 'submit' === $processor->get_attribute( 'type' ) && 'wp-submit' === $processor->get_attribute( 'name' ) ) {
		$processor->add_class( wp_theme_get_element_class_name( 'button' ) );
		break;
	}
}
$form = $processor->get_updated_html();

$forgot_link = sprintf(
	'<p class="hm-login-form__forgot"><a href="%s">%s</a></p>',
	esc_url( wp_lostpassword_url() ),
	esc_html__( 'Forgot your password?', 'hm-user-activation' )
);

// Shown after "Complete your account" sends the user here.
$notice = empty( $_GET[ HM\UserActivation\CompleteAccount\QUERY_ARG ] ) ? '' : sprintf(
	'<p class="hm-login-form__notice" role="status">%s</p>',
	esc_html( HM\UserActivation\CompleteAccount\ready_message() )
);

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'hm-login-form' ] );

echo '<div ' . $wrapper_attributes . '>' . $notice . $form . $forgot_link . '</div>'; // phpcs:ignore HM.Security.EscapeOutput.OutputNotEscaped -- wp_login_form() and get_block_wrapper_attributes() return escaped markup
