const { test, expect } = require( './fixtures' );

// Activation is for visitors who are not signed in.
test.use( { storageState: { cookies: [], origins: [] } } );

const ACTIVATE = '/activate-your-account/';
const PASSWORD = "Quote's \\back\\slash 1!";
const USED_OR_UNKNOWN =
	'This activation link is not valid. It may have been used already. Request a new one below.';

/**
 * Whether the browser holds a WordPress login cookie.
 *
 * @param {import('@playwright/test').BrowserContext} context Browser context.
 * @return {Promise<boolean>} True when logged in.
 */
async function hasLoginCookie( context ) {
	return ( await context.cookies() ).some( ( { name } ) =>
		name.startsWith( 'wordpress_logged_in_' )
	);
}

test.describe( 'Complete your account', () => {
	test.beforeEach( async ( { context, hmua } ) => {
		await hmua.useOptions( context, { hm_activation_complete_profile: 1 } );
	} );

	test( 'an activation link shows the form with the signup email and does not activate', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );

		// The key is moved out of the URL.
		expect( new URL( page.url() ).searchParams.has( 'key' ) ).toBe( false );

		const form = page.locator( 'form.hm-activation-form__complete' );
		await expect( form ).toBeVisible();
		await expect( page.locator( '#hm-activation-email' ) ).toHaveValue(
			signup.email
		);
		await expect( page.locator( '#hm-activation-email' ) ).toHaveJSProperty(
			'readOnly',
			true
		);
		await expect( page.locator( '#hm-activation-pass1' ) ).toHaveAttribute(
			'name',
			'pass1'
		);
		await expect( page.locator( '#hm-activation-pass2' ) ).toHaveAttribute(
			'name',
			'pass2'
		);
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeHidden();
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );

		// Reloading shows the form again and still does not activate.
		await page.reload();
		await expect( form ).toBeVisible();
		await expect( page.locator( '#hm-activation-email' ) ).toHaveValue(
			signup.email
		);
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );
	} );

	test( 'a password the policy refuses or that does not match keeps the form and the signup inactive', async ( {
		page,
		context,
		hmua,
	} ) => {
		await hmua.usePasswordPolicy( context, 12 );
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		await page.locator( '#hm-activation-pass1' ).fill( 'short1!' );
		await page.locator( '#hm-activation-pass2' ).fill( 'short1!' );
		await page.getByRole( 'button', { name: 'Activate account' } ).click();

		const form = page.locator( 'form.hm-activation-form__complete' );
		await expect(
			form.locator( '.hm-activation-form__errors' )
		).toContainText( 'Test policy: use at least 12 characters.' );
		await expect( form ).toBeVisible();
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );

		await page
			.locator( '#hm-activation-pass1' )
			.fill( 'long-enough-password-1!' );
		await page
			.locator( '#hm-activation-pass2' )
			.fill( 'long-enough-password-2!' );
		await page.getByRole( 'button', { name: 'Activate account' } ).click();

		await expect(
			form.locator( '.hm-activation-form__errors' )
		).toContainText( 'Passwords do not match' );
		await expect( form ).toBeVisible();
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );
	} );

	test( 'fields added by a site are validated and filled in again after an error', async ( {
		page,
		context,
		hmua,
	} ) => {
		await hmua.useCompleteFields( context );
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		await expect( page.getByLabel( 'First name' ) ).toHaveValue( '' );

		await page.locator( '#hm-activation-pass1' ).fill( 'password-one-9!' );
		await page.locator( '#hm-activation-pass2' ).fill( 'password-one-9!' );
		await page.getByRole( 'button', { name: 'Activate account' } ).click();

		const form = page.locator( 'form.hm-activation-form__complete' );
		await expect(
			form.locator( '.hm-activation-form__errors' )
		).toContainText( 'Test: first name is required.' );
		await expect( form ).toBeVisible();
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );

		await page.getByLabel( 'First name' ).fill( 'Ann' );
		await page.locator( '#hm-activation-pass1' ).fill( 'password-one-9!' );
		await page.locator( '#hm-activation-pass2' ).fill( 'password-two-9!' );
		await page.getByRole( 'button', { name: 'Activate account' } ).click();

		await expect(
			form.locator( '.hm-activation-form__errors' )
		).toContainText( 'Passwords do not match' );
		await expect( page.getByLabel( 'First name' ) ).toHaveValue( 'Ann' );
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );
	} );

	test( 'completing the form activates the account and sends the user to log in', async ( {
		page,
		context,
		hmua,
	} ) => {
		await hmua.useCompleteFields( context );
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		await page.getByLabel( 'First name' ).fill( 'Ann' );
		await page.locator( '#hm-activation-pass1' ).fill( PASSWORD );
		await page.locator( '#hm-activation-pass2' ).fill( PASSWORD );
		await page.getByRole( 'button', { name: 'Activate account' } ).click();

		await expect( page ).toHaveURL(
			/\/log-in\/\?hm_activation_complete=1/
		);
		await expect( page.locator( '.hm-login-form__notice' ) ).toHaveText(
			'Your account is ready. Log in.'
		);
		await expect( page.locator( '#wpadminbar' ) ).toHaveCount( 0 );
		expect( await hasLoginCookie( context ) ).toBe( false );

		const user = await hmua.getUser( signup.login );
		expect( user.exists ).toBe( true );
		expect( user.email ).toBe( signup.email );
		expect( user.first_name ).toBe( 'Ann' );
		expect( user.complete_keys ).toContain( 'first_name' );
		for ( const key of [ 'pass1', 'pass2', 'activation_key' ] ) {
			expect( user.complete_keys ).not.toContain( key );
		}

		// The welcome email points at the log in page, with no password step.
		const welcome = await hmua.mail.waitFor( signup.email, {
			subject: /your account is active/,
		} );
		expect( welcome.message ).not.toContain( 'action=rp' );
		expect( welcome.message ).not.toContain( 'reset-your-password' );
		expect(
			welcome.links.some( ( url ) => url.includes( '/log-in/' ) )
		).toBe( true );

		// The exact password, quote and backslashes included, logs in.
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( signup.email );
		await page.locator( '#user_pass' ).fill( PASSWORD );
		await page.locator( '#wp-submit' ).click();

		await expect( page ).toHaveURL( /\/wp-admin\// );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
		expect( await hasLoginCookie( context ) ).toBe( true );

		// The link is spent: it now shows the generic error and the resend form.
		await context.clearCookies( { name: /^wordpress_/ } );
		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		await expect( page.locator( '.hm-activation-errors' ) ).toContainText(
			USED_OR_UNKNOWN
		);
		await expect( page.locator( '.hm-resend-form' ) ).toBeVisible();
		await expect(
			page.locator( 'form.hm-activation-form__complete' )
		).toHaveCount( 0 );
	} );

	test( 'a used key shows the generic error and the resend form', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		await page.locator( '#hm-activation-pass1' ).fill( 'password-one-9!' );
		await page.locator( '#hm-activation-pass2' ).fill( 'password-one-9!' );
		await page.getByRole( 'button', { name: 'Activate account' } ).click();
		await expect( page ).toHaveURL( /hm_activation_complete=1/ );

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		await expect( page.locator( '.hm-activation-errors' ) ).toContainText(
			USED_OR_UNKNOWN
		);
		await expect( page.locator( '.hm-resend-form' ) ).toBeVisible();
		await expect(
			page.locator( 'form.hm-activation-form__complete' )
		).toHaveCount( 0 );
	} );

	test( 'an unknown key shows the same error', async ( { page } ) => {
		await page.goto( `${ ACTIVATE }?key=not-a-real-key` );

		await expect( page.locator( '.hm-activation-errors' ) ).toContainText(
			USED_OR_UNKNOWN
		);
		await expect( page.locator( '.hm-resend-form' ) ).toBeVisible();
		await expect(
			page.locator( 'form.hm-activation-form__complete' )
		).toHaveCount( 0 );
	} );

	test( 'an expired key says the link has expired', async ( {
		page,
		context,
		hmua,
	} ) => {
		await hmua.useOptions( context, {
			hm_activation_complete_profile: 1,
			hm_activation_link_lifetime: 1,
		} );
		const signup = await hmua.createSignup( { ageHours: 5 } );

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );

		await expect( page.locator( '.hm-activation-errors' ) ).toContainText(
			'This activation link has expired. Request a new one below.'
		);
		await expect( page.locator( '.hm-resend-form' ) ).toBeVisible();
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );
	} );
} );

test.describe( 'Complete your account turned off', () => {
	test( 'an activation link activates on GET as before', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );

		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();
		await expect(
			page.locator( 'form.hm-activation-form__complete' )
		).toHaveCount( 0 );
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( true );
	} );
} );
