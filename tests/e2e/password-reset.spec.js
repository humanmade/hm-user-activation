const { test, expect } = require( './fixtures' );

// Password reset is for visitors who are not signed in.
test.use( { storageState: { cookies: [], origins: [] } } );

const OLD_PASSWORD = 'old-password-for-tests-1!';
const NEW_PASSWORD = 'correct-horse-battery-staple-9!';

/**
 * Request a reset link and return the link from the email.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {Object}                          hmua  Test helpers.
 * @param {string}                          email Address to request for.
 * @return {Promise<string>} The reset link.
 */
async function requestResetLink( page, hmua, email ) {
	await page.goto( '/reset-your-password/' );
	await page.getByLabel( 'Username or email address' ).fill( email );
	await page.getByRole( 'button', { name: 'Get new password' } ).click();

	await expect(
		page.getByText( 'you will receive a password reset link shortly' )
	).toBeVisible();

	const reset = await hmua.mail.waitFor( email, {
		subject: /Reset your password/,
	} );

	return reset.links.find( ( url ) => url.includes( 'key=' ) );
}

test.describe( 'Password reset block', () => {
	test( 'shows the request form by default', async ( { page } ) => {
		await page.goto( '/reset-your-password/' );

		await expect( page.getByLabel( 'Username or email address' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Get new password' } )
		).toBeVisible();
		await expect( page.getByLabel( 'New password' ) ).toHaveCount( 0 );
	} );

	test( 'emails a reset link to a known address', async ( { page, hmua } ) => {
		const user = await hmua.createUser( OLD_PASSWORD );

		const link = new URL( await requestResetLink( page, hmua, user.email ) );

		expect( link.pathname ).toBe( '/reset-your-password/' );
		expect( link.searchParams.get( 'login' ) ).toBe( user.login );
	} );

	test( 'accepts a username as well as an email address', async ( {
		page,
		hmua,
	} ) => {
		const user = await hmua.createUser( OLD_PASSWORD );

		await page.goto( '/reset-your-password/' );
		await page.getByLabel( 'Username or email address' ).fill( user.login );
		await page.getByRole( 'button', { name: 'Get new password' } ).click();

		await hmua.mail.waitFor( user.email, {
			subject: /Reset your password/,
		} );
	} );

	test( 'looks the same for an unknown address and sends nothing', async ( {
		page,
		hmua,
	} ) => {
		const { email } = hmua.newIdentity();

		await page.goto( '/reset-your-password/' );
		await page.getByLabel( 'Username or email address' ).fill( email );
		await page.getByRole( 'button', { name: 'Get new password' } ).click();

		await expect(
			page.getByText( 'you will receive a password reset link shortly' )
		).toBeVisible();
		expect( await hmua.mail.all( email ) ).toHaveLength( 0 );
	} );

	test( 'sets a new password from the emailed link and logs in with it', async ( {
		page,
		hmua,
	} ) => {
		const user = await hmua.createUser( OLD_PASSWORD );
		const link = await requestResetLink( page, hmua, user.email );

		await page.goto( link );
		await page.getByLabel( 'New password', { exact: true } ).fill( NEW_PASSWORD );
		await page.getByLabel( 'Confirm new password' ).fill( NEW_PASSWORD );
		await page.getByRole( 'button', { name: 'Set password' } ).click();

		await expect(
			page.getByText( 'Your password has been set. You can now log in.' )
		).toBeVisible();

		await page.goto( '/log-in/' );
		await page.getByLabel( 'Username or email address' ).fill( user.login );
		await page.getByLabel( 'Password', { exact: true } ).fill( NEW_PASSWORD );
		await page.getByRole( 'button', { name: 'Log In' } ).click();

		await expect( page ).toHaveURL( /\/wp-admin\// );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	} );

	test( 'rejects mismatched passwords', async ( { page, hmua } ) => {
		const user = await hmua.createUser( OLD_PASSWORD );
		const link = await requestResetLink( page, hmua, user.email );

		await page.goto( link );
		await page.getByLabel( 'New password', { exact: true } ).fill( NEW_PASSWORD );
		await page.getByLabel( 'Confirm new password' ).fill( 'something-else-1!' );
		await page.getByRole( 'button', { name: 'Set password' } ).click();

		await expect( page.locator( '.hm-reset-errors' ) ).toContainText(
			'Passwords do not match.'
		);
	} );

	test( 'rejects an invalid reset key', async ( { page, hmua } ) => {
		const user = await hmua.createUser( OLD_PASSWORD );

		await page.goto(
			`/reset-your-password/?key=not-a-real-key&login=${ user.login }`
		);

		await expect( page.locator( '.hm-reset-errors' ) ).toContainText(
			'This password reset link is no longer valid.'
		);
		await expect( page.getByLabel( 'Username or email address' ) ).toBeVisible();
		await expect( page.getByLabel( 'New password' ) ).toHaveCount( 0 );
	} );

	test( 'the reset link moves the key out of the URL', async ( {
		page,
		hmua,
	} ) => {
		const user = await hmua.createUser( OLD_PASSWORD );
		const link = await requestResetLink( page, hmua, user.email );

		await page.goto( link );

		await expect( page ).toHaveURL( /\/reset-your-password\/$/ );
		await expect(
			page.getByLabel( 'New password', { exact: true } )
		).toBeVisible();
		await expect(
			page.locator(
				`input[name="hmua_test_hook"][value="resetpass_form:${ user.login }"]`
			)
		).toHaveCount( 1 );
	} );

	test( 'the request form fires lostpassword_form', async ( { page } ) => {
		await page.goto( '/reset-your-password/' );

		await expect(
			page.locator(
				'input[name="hmua_test_hook"][value="lostpassword_form"]'
			)
		).toHaveCount( 1 );
	} );

	test( 'errors from lostpassword_post are shown and nothing is sent', async ( {
		page,
		hmua,
	} ) => {
		const address = `hmua-blocked-${ hmua.newIdentity().login }@example.com`;

		await page.goto( '/reset-your-password/' );
		await page.getByLabel( 'Username or email address' ).fill( address );
		await page.getByRole( 'button', { name: 'Get new password' } ).click();

		await expect( page.locator( '.hm-reset-errors' ) ).toContainText(
			'Test hook: this request was blocked.'
		);
		await expect(
			page.getByText( 'you will receive a password reset link shortly' )
		).toHaveCount( 0 );
		expect( await hmua.mail.all( address ) ).toHaveLength( 0 );
	} );

	test( 'rejects a password that is only spaces', async ( { page, hmua } ) => {
		const user = await hmua.createUser( OLD_PASSWORD );
		const link = await requestResetLink( page, hmua, user.email );

		await page.goto( link );
		await page.getByLabel( 'New password', { exact: true } ).fill( '    ' );
		await page.getByLabel( 'Confirm new password' ).fill( '    ' );
		await page.getByRole( 'button', { name: 'Set password' } ).click();

		await expect( page.locator( '.hm-reset-errors' ) ).toContainText(
			'It cannot be only spaces.'
		);
	} );

	test( 'a password with quotes and a backslash can log in', async ( {
		page,
		hmua,
	} ) => {
		const password = 'It\'s a "quoted" \\ pass-9!';
		const user = await hmua.createUser( OLD_PASSWORD );
		const link = await requestResetLink( page, hmua, user.email );

		await page.goto( link );
		await page.getByLabel( 'New password', { exact: true } ).fill( password );
		await page.getByLabel( 'Confirm new password' ).fill( password );
		await page.getByRole( 'button', { name: 'Set password' } ).click();

		await expect(
			page.getByText( 'Your password has been set. You can now log in.' )
		).toBeVisible();

		await page.goto( '/log-in/' );
		await page.getByLabel( 'Username or email address' ).fill( user.login );
		await page.getByLabel( 'Password', { exact: true } ).fill( password );
		await page.getByRole( 'button', { name: 'Log In' } ).click();

		await expect( page ).toHaveURL( /\/wp-admin\// );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	} );

	test.describe( 'Password policy', () => {
		test( 'a policy plugin can reject a password and the form stays', async ( {
			page,
			hmua,
		} ) => {
			await hmua.usePasswordPolicy( page.context(), 12 );
			const user = await hmua.createUser( OLD_PASSWORD );
			const link = await requestResetLink( page, hmua, user.email );

			await page.goto( link );
			await page.getByLabel( 'New password', { exact: true } ).fill( 'short-pw1!' );
			await page.getByLabel( 'Confirm new password' ).fill( 'short-pw1!' );
			await page.getByRole( 'button', { name: 'Set password' } ).click();

			await expect( page.locator( '.hm-reset-errors' ) ).toContainText(
				'Test policy: use at least 12 characters.'
			);
			await expect(
				page.getByLabel( 'New password', { exact: true } )
			).toBeVisible();

			await page.getByLabel( 'New password', { exact: true } ).fill( NEW_PASSWORD );
			await page.getByLabel( 'Confirm new password' ).fill( NEW_PASSWORD );
			await page.getByRole( 'button', { name: 'Set password' } ).click();

			await expect(
				page.getByText( 'Your password has been set. You can now log in.' )
			).toBeVisible();
		} );

		test( 'the policy sees the password without surrounding spaces', async ( {
			page,
			hmua,
		} ) => {
			const padded = 'Passw0rd!' + '    ';

			await hmua.usePasswordPolicy( page.context(), 12 );
			const user = await hmua.createUser( OLD_PASSWORD );
			const link = await requestResetLink( page, hmua, user.email );

			await page.goto( link );
			await page.getByLabel( 'New password', { exact: true } ).fill( padded );
			await page.getByLabel( 'Confirm new password' ).fill( padded );
			await page.getByRole( 'button', { name: 'Set password' } ).click();

			await expect( page.locator( '.hm-reset-errors' ) ).toContainText(
				'Test policy: use at least 12 characters.'
			);
		} );
	} );
} );
