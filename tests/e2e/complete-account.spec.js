const { test: base, expect } = require( './fixtures' );

/**
 * Same fixtures, but every signup, site and user a test makes through the
 * helpers is removed afterwards.
 */
const test = base.extend( {
	hmua: async ( { hmua }, use ) => {
		const made = [];

		await use( {
			...hmua,
			async createSignup( ...args ) {
				const signup = await hmua.createSignup( ...args );
				made.push( [ signup.login ] );
				return signup;
			},
			async createBlogSignup( ...args ) {
				const signup = await hmua.createBlogSignup( ...args );
				made.push( [ signup.login, signup.path ] );
				return signup;
			},
			async createUser( ...args ) {
				const user = await hmua.createUser( ...args );
				made.push( [ user.login ] );
				return user;
			},
		} );

		for ( const [ login, path ] of made ) {
			await hmua.cleanup( login, path );
		}
	},
} );

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
		await hmua.useOptions( context, {
			hm_activation_complete_profile: 1,
			hm_activation_auto_login: 1,
		} );
		const signup = await hmua.createSignup( {
			meta: { source: 'complete-test' },
		} );

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
		// hm_user_activation_activated fired with the signup meta.
		expect( user.activation_meta ).toMatchObject( {
			source: 'complete-test',
		} );
		expect( user.complete_keys ).toContain( 'first_name' );
		for ( const key of [ 'pass1', 'pass2', 'activation_key' ] ) {
			expect( user.complete_keys ).not.toContain( key );
		}

		// The log in screen shows the same notice.
		await page.goto( '/wp-login.php?hm_activation_complete=1' );
		await expect(
			page.getByText( 'Your account is ready. Log in.' )
		).toBeVisible();
		expect( await hasLoginCookie( context ) ).toBe( false );

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

	test( 'a site signup for a username that already has an account shows the generic error', async ( {
		page,
		context,
		hmua,
	} ) => {
		const other = await hmua.createSignup();
		const signup = await hmua.createBlogSignup();
		const existing = await hmua.createUser(
			'original-password-9!',
			'subscriber',
			signup.login
		);

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );

		await expect( page.locator( '.hm-activation-errors' ) ).toContainText(
			USED_OR_UNKNOWN
		);
		await expect( page.locator( '.hm-resend-form' ) ).toBeVisible();
		await expect(
			page.locator( 'form.hm-activation-form__complete' )
		).toHaveCount( 0 );

		// Post the completion form directly, with a valid nonce from another
		// signup's form.
		await page.goto( `${ ACTIVATE }?key=${ other.key }` );
		const nonce = await page
			.locator( 'input[name="_hm_complete_nonce"]' )
			.inputValue();
		const response = await context.request.post( ACTIVATE, {
			form: {
				_hm_complete_nonce: nonce,
				activation_key: signup.key,
				pass1: 'attacker-password-9!',
				pass2: 'attacker-password-9!',
			},
		} );
		expect( await response.text() ).toContain( USED_OR_UNKNOWN );

		const state = await hmua.getSignupState( signup.login, signup.path );
		expect( state.signup_active ).toBe( false );
		expect( state.site_exists ).toBe( false );

		// The existing account keeps its own password.
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( existing.login );
		await page.locator( '#user_pass' ).fill( 'attacker-password-9!' );
		await page.locator( '#wp-submit' ).click();
		await expect( page.locator( '#login_error' ) ).toBeVisible();

		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( existing.login );
		await page.locator( '#user_pass' ).fill( existing.password );
		await page.locator( '#wp-submit' ).click();
		await expect( page ).toHaveURL( /\/wp-admin\// );
	} );

	test( 'the password chosen in the form wins over one set by an activation hook', async ( {
		page,
		context,
		hmua,
	} ) => {
		await hmua.useActivationHookPassword( context );
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		await page
			.locator( '#hm-activation-pass1' )
			.fill( 'chosen-password-9!' );
		await page
			.locator( '#hm-activation-pass2' )
			.fill( 'chosen-password-9!' );
		await page.getByRole( 'button', { name: 'Activate account' } ).click();

		await expect( page ).toHaveURL(
			/\/log-in\/\?hm_activation_complete=1/
		);
		await expect( page.locator( '.hm-login-form__notice' ) ).toHaveText(
			'Your account is ready. Log in.'
		);

		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( signup.login );
		await page.locator( '#user_pass' ).fill( 'hook-set-password' );
		await page.locator( '#wp-submit' ).click();
		await expect( page.locator( '#login_error' ) ).toBeVisible();

		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( signup.login );
		await page.locator( '#user_pass' ).fill( 'chosen-password-9!' );
		await page.locator( '#wp-submit' ).click();
		await expect( page ).toHaveURL( /\/wp-admin\// );
	} );

	test( 'a key typed into the key form shows the completion form without activating', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		await page.goto( ACTIVATE );
		await page.locator( '#hm-activation-key' ).fill( signup.key );
		await page.getByRole( 'button', { name: 'Activate account' } ).click();

		await expect(
			page.locator( 'form.hm-activation-form__complete' )
		).toBeVisible();
		await expect( page.locator( '#hm-activation-email' ) ).toHaveValue(
			signup.email
		);
		await expect( page.locator( '#hm-activation-key' ) ).toHaveCount( 0 );
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );
	} );

	test( 'a completion form with a changed key shows the generic error', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		await page
			.locator( 'input[name="activation_key"]' )
			.evaluate( ( input ) => {
				input.value = 'not-the-key';
			} );
		await page.locator( '#hm-activation-pass1' ).fill( 'password-one-9!' );
		await page.locator( '#hm-activation-pass2' ).fill( 'password-one-9!' );
		await page.getByRole( 'button', { name: 'Activate account' } ).click();

		await expect( page.locator( '.hm-activation-errors' ) ).toContainText(
			USED_OR_UNKNOWN
		);
		await expect( page.locator( '.hm-resend-form' ) ).toBeVisible();
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );
		expect(
			( await hmua.getSignupState( signup.login ) ).signup_active
		).toBe( false );
	} );

	test( 'a failed security check keeps the form and does not fill the fields in again', async ( {
		page,
		context,
		hmua,
	} ) => {
		await hmua.useCompleteFields( context );
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		await page.getByLabel( 'First name' ).fill( 'Ann' );
		await page.locator( '#hm-activation-pass1' ).fill( 'password-one-9!' );
		await page.locator( '#hm-activation-pass2' ).fill( 'password-one-9!' );
		await page
			.locator( 'input[name="_hm_complete_nonce"]' )
			.evaluate( ( input ) => {
				input.value = 'badnonce';
			} );
		await page.getByRole( 'button', { name: 'Activate account' } ).click();

		const form = page.locator( 'form.hm-activation-form__complete' );
		await expect(
			form.locator( '.hm-activation-form__errors' )
		).toContainText( 'Security check failed' );
		await expect( form ).toBeVisible();
		await expect( page.getByLabel( 'First name' ) ).toHaveValue( '' );
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );
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

test.describe( 'Site signup for an existing account', () => {
	test( 'a site signup for a username that already has an account does not log the visitor in', async ( {
		page,
		context,
		hmua,
	} ) => {
		await hmua.useOptions( context, { hm_activation_auto_login: 1 } );
		const signup = await hmua.createBlogSignup();
		await hmua.createUser(
			'original-password-9!',
			'subscriber',
			signup.login
		);

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		expect( await hasLoginCookie( context ) ).toBe( false );

		await page.goto( '/' );
		await expect( page.locator( '#wpadminbar' ) ).toHaveCount( 0 );
		expect( await hasLoginCookie( context ) ).toBe( false );
	} );

	test( 'a site signup for an existing account gets no set-password link even when a filter asks for one', async ( {
		page,
		context,
		hmua,
	} ) => {
		await hmua.useForcedPasswordSetup( context );
		const signup = await hmua.createBlogSignup();
		const existing = await hmua.createUser(
			'original-password-9!',
			'subscriber',
			signup.login
		);

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );

		await expect( page.locator( '.hm-activation-errors' ) ).toHaveCount(
			0
		);
		// The site's navigation links to the reset page, so look inside the
		// success group.
		const success = page.locator( '.hm-activation-success' );
		await expect( success ).toBeVisible();
		await expect(
			success.getByRole( 'link', { name: 'Set your password' } )
		).toHaveCount( 0 );
		await expect(
			success.locator(
				'a[href*="action=rp"], a[href*="reset-your-password"]'
			)
		).toHaveCount( 0 );
		await expect( page.locator( 'a[href*="action=rp"]' ) ).toHaveCount( 0 );

		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( existing.login );
		await page.locator( '#user_pass' ).fill( existing.password );
		await page.locator( '#wp-submit' ).click();
		await expect( page ).toHaveURL( /\/wp-admin\// );
	} );
} );
