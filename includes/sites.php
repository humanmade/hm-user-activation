<?php
/**
 * Which sites on the network use the plugin.
 *
 * The plugin is network-activated, so it loads on every site. A super admin
 * turns it on for a site under Network Admin → Sites → Edit Site, which
 * stores the hm_activation_allow_registration option on that site. On a site
 * where it is off, the plugin does nothing.
 */

namespace HM\UserActivation\Sites;

use HM\UserActivation\PageSetup;

/**
 * Per-site option that turns the plugin on for a site. Stored as 1 or 0.
 */
const OPTION = 'hm_activation_allow_registration';

/**
 * Hidden field that shows the Edit Site form included the checkbox.
 */
const FORM_FIELD = 'hm_activation_allow_registration_field';

/**
 * Signup meta key that marks a signup made by the Registration Form block.
 */
const SIGNUP_MARKER = 'hm_user_activation';

function bootstrap(): void {
	// The checkbox on Network Admin → Sites → Edit Site → Info.
	add_action( 'network_site_info_form', __NAMESPACE__ . '\\render_site_field' );
	add_action( 'load-site-info.php', __NAMESPACE__ . '\\save_site_field' );

	// Create the draft pages when a site is turned on, from any source.
	add_action( 'add_option_' . OPTION, __NAMESPACE__ . '\\on_option_added', 10, 2 );
	add_action( 'update_option_' . OPTION, __NAMESPACE__ . '\\on_option_updated', 10, 2 );

	add_action( 'admin_notices', __NAMESPACE__ . '\\network_activation_notice' );
}

/**
 * Whether registration, and so the plugin, is on for a site.
 *
 * When the option has never been saved, a site with a published registration
 * page counts as on, so sites set up with 1.x keep working.
 *
 * @param int $site_id Site ID. Defaults to the current site.
 */
function is_enabled( int $site_id = 0 ): bool {
	return (bool) on_site( $site_id, static function (): bool {
		$value = get_option( OPTION, null );

		if ( $value !== null ) {
			return (bool) $value;
		}

		$page_id = (int) get_option( 'hm_activation_registration_page_id' );

		return $page_id && get_post_status( $page_id ) === 'publish';
	} );
}

/**
 * Permalink of a site's published activation page, or '' when the site is
 * off or has no published activation page.
 *
 * @param int $site_id Site ID. Defaults to the current site.
 */
function activation_page_url( int $site_id = 0 ): string {
	return (string) on_site( $site_id, static function (): string {
		if ( ! is_enabled() ) {
			return '';
		}

		$page_id = (int) get_option( 'hm_activation_page_id' );

		if ( ! $page_id || get_post_status( $page_id ) !== 'publish' ) {
			return '';
		}

		return get_permalink( $page_id ) ?: '';
	} );
}

/**
 * Run a callback on another site and return its result.
 *
 * Rewrite rules are reloaded on both sides of the switch, so permalinks
 * follow the target site's permalink structure.
 *
 * @param int      $site_id  Site ID, or 0 for the current site.
 * @param callable $callback Callback to run.
 * @return mixed The callback's return value, or null when the site does not exist.
 */
function on_site( int $site_id, callable $callback ) {
	global $wp_rewrite;

	if ( ! $site_id || $site_id === get_current_blog_id() ) {
		return $callback();
	}

	if ( ! get_site( $site_id ) ) {
		return null;
	}

	switch_to_blog( $site_id );
	$wp_rewrite->init();

	try {
		return $callback();
	} finally {
		restore_current_blog();
		$wp_rewrite->init();
	}
}

/**
 * A signup row's meta as an array.
 *
 * @param object $signup Signup row.
 */
function signup_meta( object $signup ): array {
	$meta = maybe_unserialize( $signup->meta );

	return is_array( $meta ) ? $meta : [];
}

/**
 * The site a signup's user is added to on activation, or 0 for none.
 *
 * @param array $meta Signup meta.
 */
function signup_site_id( array $meta ): int {
	return (int) ( $meta['add_to_blog'] ?? 0 );
}

/**
 * Whether a signup was made by the Registration Form block.
 *
 * @param array $meta Signup meta.
 */
function is_form_signup( array $meta ): bool {
	return ! empty( $meta[ SIGNUP_MARKER ] );
}

/**
 * Whether a signup adds its user to a site other than the current one.
 *
 * @param object $signup Signup row.
 */
function is_other_site_signup( object $signup ): bool {
	$site_id = signup_site_id( signup_meta( $signup ) );

	return $site_id && $site_id !== get_current_blog_id();
}

/**
 * Show the "Allow registration on this site" checkbox on the Edit Site screen.
 *
 * @param int $site_id Site being edited.
 */
function render_site_field( $site_id ): void {
	if ( ! current_user_can( 'manage_sites' ) ) {
		return;
	}
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'User Activation', 'hm-user-activation' ); ?></th>
			<td>
				<input type="hidden" name="<?php echo esc_attr( FORM_FIELD ); ?>" value="1">
				<label>
					<input type="checkbox" name="<?php echo esc_attr( OPTION ); ?>" value="1"<?php checked( is_enabled( (int) $site_id ) ); ?>>
					<?php esc_html_e( 'Allow registration on this site', 'hm-user-activation' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'People can create an account on this site with HM User Activation. Draft registration, activation and password reset pages are created the first time this is turned on. The site\'s administrators finish the setup under Settings → User Activation.', 'hm-user-activation' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Save the checkbox when the Edit Site Info form is saved.
 *
 * Runs as the screen loads, before core saves the rest of the form, and
 * checks the same nonce core does.
 */
function save_site_field(): void {
	// phpcs:disable WordPress.Security.NonceVerification -- Checked below, once the request is known to be this form.
	$action  = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	$site_id = isset( $_REQUEST['id'] ) && is_string( $_REQUEST['id'] ) ? absint( wp_unslash( $_REQUEST['id'] ) ) : 0;
	$enabled = ! empty( $_POST[ OPTION ] );

	if ( $action !== 'update-site' || empty( $_POST[ FORM_FIELD ] ) ) {
		return;
	}
	// phpcs:enable

	if ( ! $site_id || ! current_user_can( 'manage_sites' ) || ! get_site( $site_id ) ) {
		return;
	}

	check_admin_referer( 'edit-site' );

	update_blog_option( $site_id, OPTION, $enabled ? 1 : 0 );
}

/**
 * Create the draft pages when the option is first saved as on.
 *
 * @param string $option Option name.
 * @param mixed  $value  New value.
 */
function on_option_added( $option, $value ): void {
	if ( $value ) {
		PageSetup\create_pages();
	}
}

/**
 * Create the draft pages when the option changes from off to on.
 *
 * @param mixed $old_value Previous value.
 * @param mixed $value     New value.
 */
function on_option_updated( $old_value, $value ): void {
	if ( ! $old_value && $value ) {
		PageSetup\create_pages();
	}
}

/**
 * Ask super admins to network-activate the plugin when a site activated it on its own.
 *
 * A plugin in neither list was loaded by code, such as an mu-plugin, which
 * also loads on wp-activate.php, so it gets no notice.
 */
function network_activation_notice(): void {
	if ( ! current_user_can( 'manage_network_plugins' ) ) {
		return;
	}

	$basename = plugin_basename( HM_USER_ACTIVATION_FILE );

	if ( ! in_array( $basename, (array) get_option( 'active_plugins', [] ), true ) ) {
		return;
	}

	if ( isset( ( (array) get_site_option( 'active_sitewide_plugins', [] ) )[ $basename ] ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html__( 'HM User Activation is active on this site only. Network-activate it, so it can stop activation links being used on wp-activate.php. Then turn it on for each site under Network Admin → Sites → Edit Site.', 'hm-user-activation' )
	);
}
