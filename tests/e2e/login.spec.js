const { test, expect } = require( './fixtures' );

// The login block renders nothing for signed-in users.
test.use( { storageState: { cookies: [], origins: [] } } );

const PASSWORD = 'correct-horse-battery-staple-9!';

test.describe( 'Login form block', () => {
	test( 'renders the login form with a link to the password reset page', async ( {
		page,
	} ) => {
		await page.goto( '/log-in/' );

		await expect( page.getByLabel( 'Username or email address' ) ).toBeVisible();
		await expect( page.getByLabel( 'Password', { exact: true } ) ).toBeVisible();
		await expect( page.getByLabel( 'Remember Me' ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Log In' } ) ).toBeVisible();

		const forgot = page.getByRole( 'link', {
			name: 'Forgot your password?',
		} );
		await expect( forgot ).toHaveAttribute(
			'href',
			/\/reset-your-password\/$/
		);
	} );

	test( 'logs in with a username', async ( { page, hmua } ) => {
		const user = await hmua.createUser( PASSWORD );

		await page.goto( '/log-in/' );
		await page.getByLabel( 'Username or email address' ).fill( user.login );
		await page.getByLabel( 'Password', { exact: true } ).fill( user.password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();

		await expect( page ).toHaveURL( /\/wp-admin\// );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	} );

	test( 'logs in with an email address', async ( { page, hmua } ) => {
		const user = await hmua.createUser( PASSWORD );

		await page.goto( '/log-in/' );
		await page.getByLabel( 'Username or email address' ).fill( user.email );
		await page.getByLabel( 'Password', { exact: true } ).fill( user.password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();

		await expect( page ).toHaveURL( /\/wp-admin\// );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	} );

	test( 'rejects a wrong password', async ( { page, hmua } ) => {
		const user = await hmua.createUser( PASSWORD );

		await page.goto( '/log-in/' );
		await page.getByLabel( 'Username or email address' ).fill( user.login );
		await page.getByLabel( 'Password', { exact: true } ).fill( 'not-the-password' );
		await page.getByRole( 'button', { name: 'Log In' } ).click();

		await expect( page.locator( '#login_error' ) ).toContainText(
			'password you entered'
		);
		await expect( page.locator( '#wpadminbar' ) ).toHaveCount( 0 );
	} );
} );
