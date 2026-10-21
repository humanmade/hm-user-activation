const { test, expect } = require( './fixtures' );

// Uses the administrator session saved by global-setup.js.

const SETTINGS_URL = '/wp-admin/options-general.php?page=hm-user-activation';

test.describe( 'Admin settings', () => {
	test( 'renders the settings page', async ( { page } ) => {
		await page.goto( SETTINGS_URL );

		await expect(
			page.getByRole( 'heading', { name: 'User Activation Settings' } )
		).toBeVisible();
		await expect( page.getByLabel( 'Activation page' ) ).toBeVisible();
		await expect( page.getByLabel( 'Log in page' ) ).toBeVisible();
		await expect( page.getByLabel( 'Password reset page' ) ).toBeVisible();
		await expect( page.getByLabel( 'From name' ) ).toBeVisible();
		await expect( page.getByLabel( 'From email' ) ).toBeVisible();
		await expect(
			page.getByRole( 'checkbox', { name: /Send a welcome email/ } )
		).toBeChecked();
		await expect(
			page.getByRole( 'checkbox', { name: /Automatically log users in/ } )
		).not.toBeChecked();
		await expect( page.getByLabel( 'Activation page' ) ).not.toHaveValue(
			'0'
		);
		await expect(
			page.getByRole( 'checkbox', {
				name: /Same response for known addresses/,
			} )
		).not.toBeChecked();
	} );

	test( 'saves a setting', async ( { page } ) => {
		const fromName = `Tests ${ Date.now() }`;

		await page.goto( SETTINGS_URL );
		const field = page.getByLabel( 'From name' );
		const original = await field.inputValue();

		try {
			await field.fill( fromName );
			await page.getByRole( 'button', { name: 'Save Changes' } ).click();

			await expect( page.locator( '#setting-error-settings_updated' ) ).toContainText(
				'Settings saved.'
			);

			await page.goto( SETTINGS_URL );
			await expect( page.getByLabel( 'From name' ) ).toHaveValue( fromName );
		} finally {
			await page.goto( SETTINGS_URL );
			await page.getByLabel( 'From name' ).fill( original );
			await page.getByRole( 'button', { name: 'Save Changes' } ).click();
			await expect( page.locator( '#setting-error-settings_updated' ) ).toBeVisible();
		}
	} );
} );
