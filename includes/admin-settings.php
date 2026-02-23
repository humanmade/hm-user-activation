<?php
/**
 * Admin settings page for HM User Activation.
 *
 * Registered under Settings → User Activation on each site.
 */

namespace HM\UserActivation\Admin;

use HM\UserActivation\Emails;

const PAGE_SLUG    = 'hm-user-activation';
const OPTION_GROUP = 'hm_user_activation';

function bootstrap(): void {
	add_action( 'admin_menu', __NAMESPACE__ . '\\add_settings_page' );
	add_action( 'admin_init', __NAMESPACE__ . '\\register_settings' );
}

function add_settings_page(): void {
	add_options_page(
		__( 'User Activation Settings', 'hm-user-activation' ),
		__( 'User Activation', 'hm-user-activation' ),
		'manage_options',
		PAGE_SLUG,
		__NAMESPACE__ . '\\render_page'
	);
}

// -------------------------------------------------------------------------
// Settings registration
// -------------------------------------------------------------------------

function register_settings(): void {
	// --- General ---
	register_setting( OPTION_GROUP, 'hm_activation_page_id', [
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	] );

	register_setting( OPTION_GROUP, 'hm_activation_auto_login', [
		'type'              => 'boolean',
		'sanitize_callback' => 'rest_sanitize_boolean',
		'default'           => false,
	] );

	register_setting( OPTION_GROUP, 'hm_activation_login_page_id', [
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	] );

	register_setting( OPTION_GROUP, 'hm_activation_password_reset_page_id', [
		'type'              => 'integer',
		'sanitize_callback' => 'absint',
		'default'           => 0,
	] );

	// --- Global email sender ---
	$string_field = [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ];
	$email_field  = [ 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ];

	register_setting( OPTION_GROUP, 'hm_activation_from_name',  $string_field );
	register_setting( OPTION_GROUP, 'hm_activation_from_email', $email_field );

	// --- Activation email ---
	register_setting( OPTION_GROUP, 'hm_activation_email_subject', $string_field );
	register_setting( OPTION_GROUP, 'hm_activation_email_body', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
	] );

	// --- Welcome email ---
	register_setting( OPTION_GROUP, 'hm_activation_welcome_email_enabled', [
		'type'              => 'boolean',
		'sanitize_callback' => 'rest_sanitize_boolean',
		'default'           => true,
	] );
	register_setting( OPTION_GROUP, 'hm_activation_welcome_email_subject', $string_field );
	register_setting( OPTION_GROUP, 'hm_activation_welcome_email_body', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
	] );

	// --- Password reset email ---
	register_setting( OPTION_GROUP, 'hm_activation_reset_email_subject', $string_field );
	register_setting( OPTION_GROUP, 'hm_activation_reset_email_body', [
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_textarea_field',
	] );

	register_sections_and_fields();
}

function register_sections_and_fields(): void {

	// ---- General section ----
	add_settings_section(
		'hm_activation_general',
		__( 'General', 'hm-user-activation' ),
		null,
		PAGE_SLUG
	);

	add_settings_field(
		'hm_activation_page_id',
		__( 'Activation page', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_page_select',
		PAGE_SLUG,
		'hm_activation_general',
		[ 'label_for' => 'hm_activation_page_id' ]
	);

	add_settings_field(
		'hm_activation_auto_login',
		__( 'Auto-login on activation', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_checkbox',
		PAGE_SLUG,
		'hm_activation_general',
		[
			'label_for'   => 'hm_activation_auto_login',
			'option'      => 'hm_activation_auto_login',
			'description' => __( 'Automatically log users in after they activate their account.', 'hm-user-activation' ),
		]
	);

	add_settings_field(
		'hm_activation_login_page_id',
		__( 'Log in page', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_login_page_select',
		PAGE_SLUG,
		'hm_activation_general',
		[ 'label_for' => 'hm_activation_login_page_id' ]
	);

	add_settings_field(
		'hm_activation_password_reset_page_id',
		__( 'Password reset page', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_password_reset_page_select',
		PAGE_SLUG,
		'hm_activation_general',
		[ 'label_for' => 'hm_activation_password_reset_page_id' ]
	);

	// ---- Email sender section ----
	add_settings_section(
		'hm_activation_sender',
		__( 'Email sender', 'hm-user-activation' ),
		static function () {
			echo '<p>';
			esc_html_e( 'Applied to all emails sent by this plugin.', 'hm-user-activation' );
			echo '</p>';
		},
		PAGE_SLUG
	);

	add_settings_field(
		'hm_activation_from_name',
		__( 'From name', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_text',
		PAGE_SLUG,
		'hm_activation_sender',
		[
			'label_for'   => 'hm_activation_from_name',
			'option'      => 'hm_activation_from_name',
			'placeholder' => get_bloginfo( 'name' ),
		]
	);

	add_settings_field(
		'hm_activation_from_email',
		__( 'From email', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_email',
		PAGE_SLUG,
		'hm_activation_sender',
		[
			'label_for'   => 'hm_activation_from_email',
			'option'      => 'hm_activation_from_email',
			'placeholder' => get_option( 'admin_email' ),
		]
	);

	// ---- Activation email section ----
	add_settings_section(
		'hm_activation_email',
		__( 'Activation email', 'hm-user-activation' ),
		static function () {
			echo '<p>';
			esc_html_e( 'Sent to users after they register. Replaces the default WordPress activation email.', 'hm-user-activation' );
			echo '</p><p><strong>';
			esc_html_e( 'Placeholders:', 'hm-user-activation' );
			echo '</strong> <code>{site_name}</code>, <code>{site_url}</code>, <code>{network_name}</code>, <code>{username}</code>, <code>{activation_link}</code></p>';
		},
		PAGE_SLUG
	);

	add_settings_field(
		'hm_activation_email_subject',
		__( 'Subject', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_text',
		PAGE_SLUG,
		'hm_activation_email',
		[
			'label_for'   => 'hm_activation_email_subject',
			'option'      => 'hm_activation_email_subject',
			'placeholder' => Emails\default_activation_subject(),
			'class'       => 'large-text',
		]
	);

	add_settings_field(
		'hm_activation_email_body',
		__( 'Body', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_textarea',
		PAGE_SLUG,
		'hm_activation_email',
		[
			'label_for'   => 'hm_activation_email_body',
			'option'      => 'hm_activation_email_body',
			'placeholder' => Emails\default_activation_body(),
		]
	);

	// ---- Welcome email section ----
	add_settings_section(
		'hm_activation_welcome',
		__( 'Welcome email (sent on activation)', 'hm-user-activation' ),
		static function () {
			echo '<p>';
			esc_html_e( 'Optionally sent to the user after successful activation.', 'hm-user-activation' );
			echo '</p><p><strong>';
			esc_html_e( 'Placeholders:', 'hm-user-activation' );
			echo '</strong> <code>{site_name}</code>, <code>{site_url}</code>, <code>{network_name}</code>, <code>{username}</code>, <code>{display_name}</code>, <code>{first_name}</code>, <code>{last_name}</code>, <code>{login_url}</code>, <code>{password_reset_link}</code></p>';
		},
		PAGE_SLUG
	);

	add_settings_field(
		'hm_activation_welcome_email_enabled',
		__( 'Send welcome email', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_checkbox',
		PAGE_SLUG,
		'hm_activation_welcome',
		[
			'label_for'   => 'hm_activation_welcome_email_enabled',
			'option'      => 'hm_activation_welcome_email_enabled',
			'description' => __( 'Send a welcome email after the account is activated.', 'hm-user-activation' ),
		]
	);

	add_settings_field(
		'hm_activation_welcome_email_subject',
		__( 'Subject', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_text',
		PAGE_SLUG,
		'hm_activation_welcome',
		[
			'label_for'   => 'hm_activation_welcome_email_subject',
			'option'      => 'hm_activation_welcome_email_subject',
			'placeholder' => Emails\default_welcome_subject(),
			'class'       => 'large-text',
		]
	);

	add_settings_field(
		'hm_activation_welcome_email_body',
		__( 'Body', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_textarea',
		PAGE_SLUG,
		'hm_activation_welcome',
		[
			'label_for'   => 'hm_activation_welcome_email_body',
			'option'      => 'hm_activation_welcome_email_body',
			'placeholder' => Emails\default_welcome_body(),
		]
	);

	// ---- Password reset email section ----
	add_settings_section(
		'hm_activation_reset_email',
		__( 'Password reset email', 'hm-user-activation' ),
		static function () {
			echo '<p>';
			esc_html_e( 'Sent when a user requests a password reset via the password reset page.', 'hm-user-activation' );
			echo '</p><p><strong>';
			esc_html_e( 'Placeholders:', 'hm-user-activation' );
			echo '</strong> <code>{site_name}</code>, <code>{site_url}</code>, <code>{network_name}</code>, <code>{username}</code>, <code>{reset_link}</code></p>';
		},
		PAGE_SLUG
	);

	add_settings_field(
		'hm_activation_reset_email_subject',
		__( 'Subject', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_text',
		PAGE_SLUG,
		'hm_activation_reset_email',
		[
			'label_for'   => 'hm_activation_reset_email_subject',
			'option'      => 'hm_activation_reset_email_subject',
			'placeholder' => Emails\default_reset_subject(),
			'class'       => 'large-text',
		]
	);

	add_settings_field(
		'hm_activation_reset_email_body',
		__( 'Body', 'hm-user-activation' ),
		__NAMESPACE__ . '\\field_textarea',
		PAGE_SLUG,
		'hm_activation_reset_email',
		[
			'label_for'   => 'hm_activation_reset_email_body',
			'option'      => 'hm_activation_reset_email_body',
			'placeholder' => Emails\default_reset_body(),
		]
	);
}

// -------------------------------------------------------------------------
// Field renderers
// -------------------------------------------------------------------------

function field_page_select( array $args ): void {
	$value = (int) get_option( 'hm_activation_page_id' );

	wp_dropdown_pages( [
		'name'             => 'hm_activation_page_id',
		'id'               => 'hm_activation_page_id',
		'selected'         => $value,
		'show_option_none' => __( '— Select a page —', 'hm-user-activation' ),
	] );

	if ( $value ) {
		printf(
			' <a href="%s" target="_blank">%s</a> &middot; <a href="%s" target="_blank">%s</a>',
			esc_url( get_edit_post_link( $value ) ),
			esc_html__( 'Edit page', 'hm-user-activation' ),
			esc_url( get_permalink( $value ) ),
			esc_html__( 'View page', 'hm-user-activation' )
		);
	}
}

function field_login_page_select( array $args ): void {
	$value = (int) get_option( 'hm_activation_login_page_id' );

	wp_dropdown_pages( [
		'name'              => 'hm_activation_login_page_id',
		'id'                => 'hm_activation_login_page_id',
		'selected'          => $value,
		'show_option_none'  => __( '— Use default (WordPress login) —', 'hm-user-activation' ),
		'option_none_value' => '0',
	] );

	if ( $value ) {
		printf(
			' <a href="%s" target="_blank">%s</a> &middot; <a href="%s" target="_blank">%s</a>',
			esc_url( get_edit_post_link( $value ) ),
			esc_html__( 'Edit page', 'hm-user-activation' ),
			esc_url( get_permalink( $value ) ),
			esc_html__( 'View page', 'hm-user-activation' )
		);
	}

	printf(
		'<p class="description">%s</p>',
		wp_kses( __( 'Used as the <code>{login_url}</code> placeholder in the welcome email. Defaults to the WordPress login URL if none selected.', 'hm-user-activation' ), [ 'code' => [] ] )
	);
}

function field_password_reset_page_select( array $args ): void {
	$value = (int) get_option( 'hm_activation_password_reset_page_id' );

	wp_dropdown_pages( [
		'name'             => 'hm_activation_password_reset_page_id',
		'id'               => 'hm_activation_password_reset_page_id',
		'selected'         => $value,
		'show_option_none' => __( '— Select a page —', 'hm-user-activation' ),
	] );

	if ( $value ) {
		printf(
			' <a href="%s" target="_blank">%s</a> &middot; <a href="%s" target="_blank">%s</a>',
			esc_url( get_edit_post_link( $value ) ),
			esc_html__( 'Edit page', 'hm-user-activation' ),
			esc_url( get_permalink( $value ) ),
			esc_html__( 'View page', 'hm-user-activation' )
		);
	}

	printf(
		'<p class="description">%s</p>',
		esc_html__( 'Page containing the password reset block. Used for "forgot password?" links and as the destination after account activation.', 'hm-user-activation' )
	);
}

function field_checkbox( array $args ): void {
	$option = $args['option'];
	$value  = (bool) get_option( $option );
	printf(
		'<label><input type="checkbox" name="%s" id="%s" value="1"%s> %s</label>',
		esc_attr( $option ),
		esc_attr( $option ),
		checked( $value, true, false ),
		isset( $args['description'] ) ? esc_html( $args['description'] ) : ''
	);
}

function field_text( array $args ): void {
	$option = $args['option'];
	$value  = (string) get_option( $option );
	printf(
		'<input type="text" name="%s" id="%s" value="%s" placeholder="%s" class="%s">',
		esc_attr( $option ),
		esc_attr( $option ),
		esc_attr( $value ),
		esc_attr( $args['placeholder'] ?? '' ),
		esc_attr( $args['class'] ?? 'regular-text' )
	);
}

function field_email( array $args ): void {
	$option = $args['option'];
	$value  = (string) get_option( $option );
	printf(
		'<input type="email" name="%s" id="%s" value="%s" placeholder="%s" class="regular-text">',
		esc_attr( $option ),
		esc_attr( $option ),
		esc_attr( $value ),
		esc_attr( $args['placeholder'] ?? '' )
	);
}

function field_textarea( array $args ): void {
	$option = $args['option'];
	$value  = (string) get_option( $option );
	printf(
		'<textarea name="%s" id="%s" rows="8" class="large-text" placeholder="%s">%s</textarea>',
		esc_attr( $option ),
		esc_attr( $option ),
		esc_attr( $args['placeholder'] ?? '' ),
		esc_textarea( $value )
	);
}

// -------------------------------------------------------------------------
// Page render
// -------------------------------------------------------------------------

function render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( OPTION_GROUP );
			do_settings_sections( PAGE_SLUG );
			submit_button();
			?>
		</form>
	</div>
	<?php
}
