/* eslint-disable no-console */
const { chromium, request } = require( '@playwright/test' );
const { createHelpers } = require( './fixtures' );

/**
 * Global setup to clear captured mail, log in to WordPress and save the
 * authentication state.
 */
module.exports = async () => {
	const baseURL = process.env.WP_BASE_URL || 'http://localhost:8889';
	const username = process.env.WP_USERNAME || 'admin';
	const password = process.env.WP_PASSWORD || 'password';

	const api = await request.newContext( { baseURL } );
	await createHelpers( api ).mail.reset();
	await api.dispose();

	console.log( `Logging in to WordPress at ${ baseURL }...` );

	const browser = await chromium.launch();
	const page = await browser.newPage( { baseURL } );

	await page.goto( `${ baseURL }/wp-login.php` );
	await page.fill( '#user_login', username );
	await page.fill( '#user_pass', password );
	await page.click( '#wp-submit' );

	try {
		await Promise.race( [
			page.waitForURL( '**/wp-admin/**', { timeout: 15000 } ),
			page.waitForSelector( '#wpadminbar', { timeout: 15000 } ),
		] );
	} catch ( error ) {
		console.error(
			`Failed to log in to WordPress. Landed on: ${ page.url() }`
		);
		await browser.close();
		throw error;
	}

	await page.context().storageState( { path: '.auth/wordpress.json' } );

	await browser.close();

	console.log( 'WordPress authentication state saved successfully.' );
};
