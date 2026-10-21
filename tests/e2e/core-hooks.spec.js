const { test: base, expect } = require( './fixtures' );

/**
 * Same fixtures, but every signup and user a test makes is removed
 * afterwards, including those made through the registration form (pass their
 * username to `hmua.track()`).
 */
const test = base.extend( {
	hmua: async ( { hmua }, use ) => {
		const made = [];

		await use( {
			...hmua,
			track( login ) {
				made.push( login );
			},
			async createSignup( ...args ) {
				const signup = await hmua.createSignup( ...args );
				made.push( signup.login );
				return signup;
			},
		} );

		for ( const login of made ) {
			await hmua.cleanup( login );
		}
	},
} );

const REGISTRATION_URL = '/create-an-account/';
const ACTIVATE = '/activate-your-account/';
const ACTIVATION_SUBJECT = /Activate your account/;
const PASSWORD = 'core-hooks-password-9!';

/**
 * The registration block.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {import('@playwright/test').Locator} The block.
 */
function registrationForm( page ) {
	return page.locator( '.hm-registration-form' );
}

/**
 * Fill in the username and email address and submit the registration form.
 *
 * @param {import('@playwright/test').Page} page         Page showing the form.
 * @param {Object}                          identity     Values to submit.
 * @param {string}                          identity.login Username.
 * @param {string}                          identity.email Email address.
 */
async function register( page, { login, email } ) {
	const form = registrationForm( page );

	await form.getByLabel( 'Username' ).fill( login );
	await form.getByLabel( 'Email address' ).fill( email );
	await form.getByRole( 'button', { name: 'Register' } ).click();
}

// Both forms are for visitors who are not signed in.
test.use( { storageState: { cookies: [], origins: [] } } );

test.describe( 'Registration form with core hooks', () => {
	test.beforeEach( async ( { context, hmua } ) => {
		await hmua.setRegistration( 'user' );
		await hmua.useCoreFields( context );
	} );

	test( 'the fields render inside the form and can be prefilled from the URL', async ( {
		page,
	} ) => {
		await page.goto( REGISTRATION_URL );

		const form = registrationForm( page ).locator( 'form' );
		await expect( form.getByLabel( 'Company' ) ).toHaveValue( '' );
		await expect( form.getByLabel( 'I accept the terms' ) ).not.toBeChecked();
		await expect( form.locator( '#hmua-terms-error' ) ).toHaveCount( 0 );

		await page.goto( `${ REGISTRATION_URL }?hmua_company=Acme` );

		await expect( form.getByLabel( 'Company' ) ).toHaveValue( 'Acme' );
	} );

	test( "core's signup_user_init filter sets the form's defaults", async ( {
		page,
	} ) => {
		await page.goto( `${ REGISTRATION_URL }?hmua_user=fromfilter` );

		await expect(
			registrationForm( page ).getByLabel( 'Username' )
		).toHaveValue( 'fromfilter' );
	} );

	test( "a 'generic' error shows above the extra fields and stops the signup", async ( {
		page,
		hmua,
	} ) => {
		const identity = hmua.newIdentity();
		hmua.track( identity.login );

		await page.goto( REGISTRATION_URL );
		await page.getByLabel( 'Company' ).fill( 'generic' );
		await page.getByLabel( 'I accept the terms' ).check();
		await register( page, identity );

		await expect( page.locator( '#hm-registration-generic-error' ) ).toHaveText(
			'Test: a generic error.'
		);
		await expect(
			page.locator( '.hm-registration-success' )
		).toHaveCount( 0 );
		expect( ( await hmua.getSignupState( identity.login ) ).signup_exists ).toBe(
			false
		);
	} );

	test( 'an error added on core\'s validation filter stops the signup and keeps the values', async ( {
		page,
		hmua,
	} ) => {
		const identity = hmua.newIdentity();
		hmua.track( identity.login );

		await page.goto( REGISTRATION_URL );
		await page.getByLabel( 'Company' ).fill( 'Acme' );
		await register( page, identity );

		await expect( page.locator( '#hmua-terms-error' ) ).toHaveText(
			'Test: please accept the terms.'
		);
		await expect(
			page.locator( '.hm-registration-success' )
		).toHaveCount( 0 );

		const form = registrationForm( page );
		await expect( form.getByLabel( 'Username' ) ).toHaveValue( identity.login );
		await expect( form.getByLabel( 'Email address' ) ).toHaveValue(
			identity.email
		);
		await expect( form.getByLabel( 'Company' ) ).toHaveValue( 'Acme' );
		await expect( form.getByLabel( 'I accept the terms' ) ).not.toBeChecked();

		await page.waitForTimeout( 1500 );
		expect( ( await hmua.getSignupState( identity.login ) ).signup_exists ).toBe(
			false
		);
		expect( await hmua.mail.all( identity.email ) ).toHaveLength( 0 );
	} );

	test( 'the signup meta carries the fields to the new account', async ( {
		page,
		hmua,
	} ) => {
		const identity = hmua.newIdentity();
		hmua.track( identity.login );

		await page.goto( REGISTRATION_URL );
		await page.getByLabel( 'Company' ).fill( 'Acme' );
		await page.getByLabel( 'I accept the terms' ).check();
		await register( page, identity );

		await expect( page.locator( '.hm-registration-success' ) ).toContainText(
			`We have sent an activation link to ${ identity.email }.`
		);

		const state = await hmua.getSignupState( identity.login );
		expect( state.signup_exists ).toBe( true );
		expect( state.meta ).toMatchObject( {
			hmua_company: 'Acme',
			hmua_terms: '1',
		} );

		const email = await hmua.mail.waitFor( identity.email, {
			subject: ACTIVATION_SUBJECT,
		} );
		await page.goto( email.links.find( ( url ) => url.includes( 'key=' ) ) );
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();

		const user = await hmua.getUser( identity.login );
		expect( user.exists ).toBe( true );
		expect( user.core_fields ).toEqual( { company: 'Acme', terms: '1' } );
	} );
} );

test.describe( 'Complete your account with core hooks', () => {
	test.beforeEach( async ( { context, hmua } ) => {
		await hmua.useOptions( context, { hm_activation_complete_profile: 1 } );
		await hmua.useCoreFields( context );
	} );

	/**
	 * Fill in matching passwords and submit the completion form.
	 *
	 * @param {import('@playwright/test').Page} page Page showing the form.
	 */
	async function submitWithPassword( page ) {
		await page.locator( '#hm-activation-pass1' ).fill( PASSWORD );
		await page.locator( '#hm-activation-pass2' ).fill( PASSWORD );
		await page.getByRole( 'button', { name: 'Activate account' } ).click();
	}

	test( 'the fields from register_form render in the form', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );

		const form = page.locator( 'form.hm-activation-form__complete' );
		await expect( form.getByLabel( 'Company' ) ).toHaveValue( '' );
		await expect( form.getByLabel( 'I accept the terms' ) ).not.toBeChecked();
	} );

	test( 'registration_errors gets the login and email and keeps the form and signup inactive', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		await page.getByLabel( 'Company' ).fill( 'Acme' );
		await submitWithPassword( page );

		const form = page.locator( 'form.hm-activation-form__complete' );
		await expect(
			form.locator( '.hm-activation-form__errors' )
		).toContainText(
			`Test: ${ signup.login } <${ signup.email }> must accept the terms.`
		);
		await expect( form ).toBeVisible();
		await expect( form.getByLabel( 'Company' ) ).toHaveValue( 'Acme' );
		await expect( page.locator( '#hm-activation-pass1' ) ).toHaveValue( '' );
		await expect( page.locator( '#hm-activation-pass2' ) ).toHaveValue( '' );

		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );
		expect(
			( await hmua.getSignupState( signup.login ) ).signup_active
		).toBe( false );
	} );

	test( 'user_register saves the fields when the account is made', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		await page.getByLabel( 'Company' ).fill( 'Acme' );
		await page.getByLabel( 'I accept the terms' ).check();
		await submitWithPassword( page );

		await expect( page ).toHaveURL( /hm_activation_complete=1/ );

		const user = await hmua.getUser( signup.login );
		expect( user.exists ).toBe( true );
		expect( user.core_fields ).toEqual( { company: 'Acme', terms: '1' } );
		expect(
			( await hmua.getSignupState( signup.login ) ).signup_active
		).toBe( true );
	} );

	test( 'a failed security check does not fill the fields in again', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );
		await page.getByLabel( 'Company' ).fill( 'Acme' );
		await page.getByLabel( 'I accept the terms' ).check();
		await page
			.locator( 'input[name="_hm_complete_nonce"]' )
			.evaluate( ( input ) => {
				input.value = 'badnonce';
			} );
		await submitWithPassword( page );

		const form = page.locator( 'form.hm-activation-form__complete' );
		await expect(
			form.locator( '.hm-activation-form__errors' )
		).toContainText( 'Security check failed' );
		await expect( form ).toBeVisible();
		await expect( form.getByLabel( 'Company' ) ).toHaveValue( '' );
		await expect( form.getByLabel( 'I accept the terms' ) ).not.toBeChecked();
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );
	} );
} );
