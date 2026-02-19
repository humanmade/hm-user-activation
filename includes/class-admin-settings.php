<?php
/**
 * Admin settings page for HM User Activation.
 *
 * Registered under Settings → User Activation on each site.
 */

namespace HM\UserActivation;

class Admin_Settings {

	private const PAGE_SLUG    = 'hm-user-activation';
	private const OPTION_GROUP = 'hm_user_activation';

	public static function init(): void {
		add_action( 'admin_menu', [ self::class, 'add_settings_page' ] );
		add_action( 'admin_init', [ self::class, 'register_settings' ] );
	}

	public static function add_settings_page(): void {
		add_options_page(
			__( 'User Activation Settings', 'hm-user-activation' ),
			__( 'User Activation', 'hm-user-activation' ),
			'manage_options',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Settings registration
	// -------------------------------------------------------------------------

	public static function register_settings(): void {
		// --- General ---
		register_setting( self::OPTION_GROUP, 'hm_activation_page_id', [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		] );

		register_setting( self::OPTION_GROUP, 'hm_activation_auto_login', [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );

		register_setting( self::OPTION_GROUP, 'hm_activation_login_url', [
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => '',
		] );

		// --- Activation email ---
		$string_field = [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ];

		register_setting( self::OPTION_GROUP, 'hm_activation_email_from_name',  $string_field );
		register_setting( self::OPTION_GROUP, 'hm_activation_email_from', array_merge( $string_field, [ 'sanitize_callback' => 'sanitize_email' ] ) );
		register_setting( self::OPTION_GROUP, 'hm_activation_email_subject',    $string_field );
		register_setting( self::OPTION_GROUP, 'hm_activation_email_body', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
		] );

		// --- Welcome email ---
		register_setting( self::OPTION_GROUP, 'hm_activation_welcome_email_enabled', [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		] );
		register_setting( self::OPTION_GROUP, 'hm_activation_welcome_email_from_name',  $string_field );
		register_setting( self::OPTION_GROUP, 'hm_activation_welcome_email_from', array_merge( $string_field, [ 'sanitize_callback' => 'sanitize_email' ] ) );
		register_setting( self::OPTION_GROUP, 'hm_activation_welcome_email_subject',    $string_field );
		register_setting( self::OPTION_GROUP, 'hm_activation_welcome_email_body', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
		] );

		self::register_sections_and_fields();
	}

	private static function register_sections_and_fields(): void {

		// ---- General section ----
		add_settings_section(
			'hm_activation_general',
			__( 'General', 'hm-user-activation' ),
			null,
			self::PAGE_SLUG
		);

		add_settings_field(
			'hm_activation_page_id',
			__( 'Activation page', 'hm-user-activation' ),
			[ self::class, 'field_page_select' ],
			self::PAGE_SLUG,
			'hm_activation_general',
			[ 'label_for' => 'hm_activation_page_id' ]
		);

		add_settings_field(
			'hm_activation_auto_login',
			__( 'Auto-login on activation', 'hm-user-activation' ),
			[ self::class, 'field_checkbox' ],
			self::PAGE_SLUG,
			'hm_activation_general',
			[
				'label_for'   => 'hm_activation_auto_login',
				'option'      => 'hm_activation_auto_login',
				'description' => __( 'Automatically log users in after they activate their account.', 'hm-user-activation' ),
			]
		);

		add_settings_field(
			'hm_activation_login_url',
			__( 'Log in page URL', 'hm-user-activation' ),
			[ self::class, 'field_url' ],
			self::PAGE_SLUG,
			'hm_activation_general',
			[
				'label_for'   => 'hm_activation_login_url',
				'option'      => 'hm_activation_login_url',
				'placeholder' => wp_login_url(),
				'description' => __( 'Used as the <code>{login_url}</code> placeholder in the welcome email. Defaults to the WordPress login URL if left blank.', 'hm-user-activation' ),
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
			self::PAGE_SLUG
		);

		add_settings_field(
			'hm_activation_email_from_name',
			__( 'From name', 'hm-user-activation' ),
			[ self::class, 'field_text' ],
			self::PAGE_SLUG,
			'hm_activation_email',
			[
				'label_for'   => 'hm_activation_email_from_name',
				'option'      => 'hm_activation_email_from_name',
				'placeholder' => get_bloginfo( 'name' ),
			]
		);

		add_settings_field(
			'hm_activation_email_from',
			__( 'From email', 'hm-user-activation' ),
			[ self::class, 'field_email' ],
			self::PAGE_SLUG,
			'hm_activation_email',
			[
				'label_for'   => 'hm_activation_email_from',
				'option'      => 'hm_activation_email_from',
				'placeholder' => get_option( 'admin_email' ),
			]
		);

		add_settings_field(
			'hm_activation_email_subject',
			__( 'Subject', 'hm-user-activation' ),
			[ self::class, 'field_text' ],
			self::PAGE_SLUG,
			'hm_activation_email',
			[
				'label_for'   => 'hm_activation_email_subject',
				'option'      => 'hm_activation_email_subject',
				'placeholder' => Emails::default_activation_subject(),
				'class'       => 'large-text',
			]
		);

		add_settings_field(
			'hm_activation_email_body',
			__( 'Body', 'hm-user-activation' ),
			[ self::class, 'field_textarea' ],
			self::PAGE_SLUG,
			'hm_activation_email',
			[
				'label_for'   => 'hm_activation_email_body',
				'option'      => 'hm_activation_email_body',
				'placeholder' => Emails::default_activation_body(),
			]
		);

		// ---- Welcome email section ----
		add_settings_section(
			'hm_activation_welcome',
			__( 'Welcome email (sent on activation)', 'hm-user-activation' ),
			static function () {
				echo '<p>';
				esc_html_e( 'Optionally sent to the user after successful activation, containing their credentials.', 'hm-user-activation' );
				echo '</p><p><strong>';
				esc_html_e( 'Placeholders:', 'hm-user-activation' );
				echo '</strong> <code>{site_name}</code>, <code>{site_url}</code>, <code>{network_name}</code>, <code>{username}</code>, <code>{display_name}</code>,<code>{first_name}</code>, <code>{last_name}</code>, <code>{password}</code>, <code>{login_url}</code></p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'hm_activation_welcome_email_enabled',
			__( 'Send welcome email', 'hm-user-activation' ),
			[ self::class, 'field_checkbox' ],
			self::PAGE_SLUG,
			'hm_activation_welcome',
			[
				'label_for'   => 'hm_activation_welcome_email_enabled',
				'option'      => 'hm_activation_welcome_email_enabled',
				'description' => __( 'Send an email with login credentials after the account is activated.', 'hm-user-activation' ),
			]
		);

		add_settings_field(
			'hm_activation_welcome_email_from_name',
			__( 'From name', 'hm-user-activation' ),
			[ self::class, 'field_text' ],
			self::PAGE_SLUG,
			'hm_activation_welcome',
			[
				'label_for'   => 'hm_activation_welcome_email_from_name',
				'option'      => 'hm_activation_welcome_email_from_name',
				'placeholder' => get_bloginfo( 'name' ),
			]
		);

		add_settings_field(
			'hm_activation_welcome_email_from',
			__( 'From email', 'hm-user-activation' ),
			[ self::class, 'field_email' ],
			self::PAGE_SLUG,
			'hm_activation_welcome',
			[
				'label_for'   => 'hm_activation_welcome_email_from',
				'option'      => 'hm_activation_welcome_email_from',
				'placeholder' => get_option( 'admin_email' ),
			]
		);

		add_settings_field(
			'hm_activation_welcome_email_subject',
			__( 'Subject', 'hm-user-activation' ),
			[ self::class, 'field_text' ],
			self::PAGE_SLUG,
			'hm_activation_welcome',
			[
				'label_for'   => 'hm_activation_welcome_email_subject',
				'option'      => 'hm_activation_welcome_email_subject',
				'placeholder' => Emails::default_welcome_subject(),
				'class'       => 'large-text',
			]
		);

		add_settings_field(
			'hm_activation_welcome_email_body',
			__( 'Body', 'hm-user-activation' ),
			[ self::class, 'field_textarea' ],
			self::PAGE_SLUG,
			'hm_activation_welcome',
			[
				'label_for'   => 'hm_activation_welcome_email_body',
				'option'      => 'hm_activation_welcome_email_body',
				'placeholder' => Emails::default_welcome_body(),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Field renderers
	// -------------------------------------------------------------------------

	public static function field_page_select( array $args ): void {
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

	public static function field_checkbox( array $args ): void {
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

	public static function field_text( array $args ): void {
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

	public static function field_email( array $args ): void {
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

	public static function field_url( array $args ): void {
		$option = $args['option'];
		$value  = (string) get_option( $option );
		printf(
			'<input type="url" name="%s" id="%s" value="%s" placeholder="%s" class="regular-text">',
			esc_attr( $option ),
			esc_attr( $option ),
			esc_attr( $value ),
			esc_attr( $args['placeholder'] ?? '' )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', wp_kses( $args['description'], [ 'code' => [] ] ) );
		}
	}

	public static function field_textarea( array $args ): void {
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

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
