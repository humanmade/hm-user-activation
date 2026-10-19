/* eslint-disable no-console */
const { execFileSync } = require( 'child_process' );

const PLUGIN_DIR = '/var/www/html/wp-content/plugins/hm-user-activation';

/**
 * Run a WP-CLI command in the wp-env tests container.
 *
 * @param {string[]} args Arguments passed to `wp`.
 * @return {string} Command output.
 */
function wp( args ) {
	return execFileSync(
		'npx',
		[ 'wp-env', 'run', 'tests-cli', 'wp', ...args ],
		{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] }
	);
}

/**
 * Whether a WP-CLI command exits successfully.
 *
 * @param {string[]} args Arguments passed to `wp`.
 * @return {boolean} True on exit 0.
 */
function succeeds( args ) {
	try {
		wp( args );
		return true;
	} catch ( error ) {
		return false;
	}
}

/**
 * Turn the single site wp-env installs into a subdirectory network, activate
 * the plugin network-wide and publish the pages the specs rely on.
 *
 * The plugin does nothing on a single site, so it has to be activated after
 * the conversion for its activation hook to create the pages. It is
 * network-activated because WordPress only loads network-active plugins on
 * wp-activate.php, which the plugin needs in order to redirect that request.
 */
function setup() {
	if ( ! succeeds( [ 'core', 'is-installed', '--network' ] ) ) {
		console.log( 'Converting the site to a multisite network...' );
		wp( [ 'core', 'multisite-convert', '--title=HMUA Test Network' ] );
	}

	console.log( 'Activating the plugin...' );
	wp( [ 'plugin', 'activate', 'hm-user-activation', '--network' ] );

	console.log( 'Preparing pages and permalinks...' );
	wp( [ 'eval-file', `${ PLUGIN_DIR }/tests/bin/setup.php` ] );

	console.log( 'Test environment is ready.' );
}

setup();
