const { test, expect } = require( './fixtures' );

const REGISTRATION_URL = '/create-an-account/';
const PAGE_OPTION = 'hm_activation_registration_page_id';
const ACTIVATION_SUBJECT = /Activate your account/;

/**
 * Fill in and submit the registration form.
 *
 * @param {import('@playwright/test').Page} page       Page showing the form.
 * @param {Object}                          identity   Values to submit.
 * @param {string}                          identity.login Username.
 * @param {string}                          identity.email Email address.
 */
async function register( page, { login, email } ) {
	const form = registrationForm( page );

	await form.getByLabel( 'Username' ).fill( login );
	await form.getByLabel( 'Email address' ).fill( email );
	await form.getByRole( 'button', { name: 'Register' } ).click();
}

/**
 * The registration block, kept apart from the resend form's email field.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {import('@playwright/test').Locator} The block.
 */
function registrationForm( page ) {
	return page.locator( '.hm-registration-form' );
}

test.describe( 'Registration form', () => {
	// Registration is for visitors who are not signed in.
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.afterEach( async ( { hmua } ) => {
		await hmua.setRegistration( 'user' );
	} );

	test( 'shows the form with a log in link', async ( { page } ) => {
		await page.goto( REGISTRATION_URL );

		await expect( registrationForm( page ).getByLabel( 'Username' ) ).toBeVisible();
		await expect(
			registrationForm( page ).getByLabel( 'Email address' )
		).toBeVisible();
		await expect(
			page.getByText( 'Registration confirmation will be emailed to you.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Already have an account? Log in' } )
		).toHaveAttribute( 'href', /\/log-in\/$/ );

		await expect( page.locator( '.hm-registration-errors' ) ).toHaveCount( 0 );
		await expect( page.locator( '.hm-registration-success' ) ).toHaveCount( 0 );
		await expect( page.locator( '.hm-activation-resent' ) ).toHaveCount( 0 );
	} );

	test( 'registering sends an activation email and shows the confirmation', async ( {
		page,
		hmua,
	} ) => {
		const identity = hmua.newIdentity();

		await page.goto( REGISTRATION_URL );
		await register( page, identity );

		await expect( page.locator( '.hm-registration-success' ) ).toContainText(
			`We have sent an activation link to ${ identity.email }.`
		);
		await expect( page.getByLabel( 'Username' ) ).toHaveCount( 0 );
		await expect( page.locator( '.hm-registration-errors' ) ).toHaveCount( 0 );

		const email = await hmua.mail.waitFor( identity.email, {
			subject: ACTIVATION_SUBJECT,
		} );
		const link = new URL(
			email.links.find( ( url ) => url.includes( 'key=' ) )
		);
		expect( link.pathname ).toBe( '/activate-your-account/' );

		// Nothing is created until the link is used.
		expect( ( await hmua.getUser( identity.login ) ).exists ).toBe( false );
	} );

	test( 'the new account belongs to the site once activated', async ( {
		page,
		hmua,
	} ) => {
		const identity = hmua.newIdentity();

		await page.goto( REGISTRATION_URL );
		await register( page, identity );
		await expect( page.locator( '.hm-registration-success' ) ).toBeVisible();

		const email = await hmua.mail.waitFor( identity.email, {
			subject: ACTIVATION_SUBJECT,
		} );
		await page.goto( email.links.find( ( url ) => url.includes( 'key=' ) ) );
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();

		const user = await hmua.getUser( identity.login );
		expect( user.exists ).toBe( true );
		expect( user.email ).toBe( identity.email );
		expect( user.is_member ).toBe( true );
		expect( user.roles ).toEqual( [ 'subscriber' ] );
	} );

	test( 'a taken username is shown next to the field and the values are kept', async ( {
		page,
		hmua,
	} ) => {
		const existing = await hmua.createUser( 'existing-user-password-1!' );
		const { email } = hmua.newIdentity();

		await page.goto( REGISTRATION_URL );
		await register( page, { login: existing.login, email } );

		const username = page.getByLabel( 'Username' );
		await expect( page.locator( '#hm-user-name-error' ) ).toHaveText(
			'Sorry, that username already exists!'
		);
		await expect( username ).toHaveAttribute( 'aria-invalid', 'true' );
		await expect( username ).toHaveValue( existing.login );
		await expect(
			registrationForm( page ).getByLabel( 'Email address' )
		).toHaveValue( email );
		await expect( page.locator( '.hm-registration-errors' ) ).toContainText(
			'Sorry, that username already exists!'
		);

		await page.waitForTimeout( 1500 );
		expect( await hmua.mail.all( email ) ).toHaveLength( 0 );
	} );

	test( "core's HTML error for a registered email address shows as formatted text", async ( {
		page,
		hmua,
	} ) => {
		const existing = await hmua.createUser( 'existing-user-password-1!' );
		const { login } = hmua.newIdentity();

		await page.goto( REGISTRATION_URL );
		await register( page, { login, email: existing.email } );

		const error = page.locator( '#hm-user-email-error' );
		await expect( error ).toContainText(
			'This email address is already registered.'
		);
		await expect( error ).not.toContainText( '<strong>' );
		await expect( error.locator( 'strong' ) ).toHaveText( 'Error:' );
		await expect( error.getByRole( 'link', { name: 'Log in' } ) ).toBeVisible();
	} );

	test( 'a username that sanitising would change is rejected, not altered', async ( {
		page,
		hmua,
	} ) => {
		const identity = hmua.newIdentity();
		const submitted = `<b>${ identity.login }</b>`;

		await page.goto( REGISTRATION_URL );
		await register( page, { login: submitted, email: identity.email } );

		await expect( page.locator( '#hm-user-name-error' ) ).toContainText(
			'Usernames can only contain lowercase letters (a-z) and numbers.'
		);
		await expect( page.getByLabel( 'Username' ) ).toHaveValue( submitted );

		await page.waitForTimeout( 1500 );
		expect( await hmua.mail.all( identity.email ) ).toHaveLength( 0 );
	} );

	test( 'an address with a pending signup can ask for a new link here', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();
		await hmua.mail.waitFor( signup.email, { subject: ACTIVATION_SUBJECT } );
		const { login } = hmua.newIdentity();

		await page.goto( REGISTRATION_URL );
		await register( page, { login, email: signup.email } );

		await expect( page.locator( '#hm-user-email-error' ) ).toContainText(
			'That email address is pending activation'
		);

		const errors = page.locator( '.hm-registration-errors' );
		await errors.getByLabel( 'Email address' ).fill( signup.email );
		await errors
			.getByRole( 'button', { name: 'Send a new activation link' } )
			.click();

		await expect( page ).toHaveURL( /\/create-an-account\/\?hm_activation_resent=1/ );
		await expect( page.locator( '.hm-activation-resent' ) ).toContainText(
			'sent a new activation link'
		);
		await hmua.mail.waitFor( signup.email, {
			subject: ACTIVATION_SUBJECT,
			count: 2,
		} );
	} );

	test( 'a failed security check is reported and nothing is sent', async ( {
		page,
		hmua,
	} ) => {
		const identity = hmua.newIdentity();

		await page.goto( REGISTRATION_URL );
		await page
			.locator( 'input[name="_hm_register_nonce"]' )
			.evaluate( ( input ) => ( input.value = 'not-a-nonce' ) );
		await register( page, identity );

		await expect( page.locator( '.hm-registration-errors' ) ).toContainText(
			'Security check failed.'
		);

		await page.waitForTimeout( 1500 );
		expect( await hmua.mail.all( identity.email ) ).toHaveLength( 0 );
	} );

	test( 'the form is closed when the network allows no user accounts', async ( {
		page,
		hmua,
	} ) => {
		await hmua.setRegistration( 'none' );

		await page.goto( REGISTRATION_URL );

		await expect(
			page.getByText( 'Registration is not currently open.' )
		).toBeVisible();
		await expect( page.getByLabel( 'Username' ) ).toHaveCount( 0 );
	} );

	test( 'a form loaded before registration closed is refused', async ( {
		page,
		hmua,
	} ) => {
		const identity = hmua.newIdentity();

		await page.goto( REGISTRATION_URL );
		await hmua.setRegistration( 'blog' );
		await register( page, identity );

		await expect( page.locator( '.hm-registration-errors' ) ).toContainText(
			'Registration is not currently open.'
		);

		await page.waitForTimeout( 1500 );
		expect( await hmua.mail.all( identity.email ) ).toHaveLength( 0 );
	} );
} );

test.describe( 'Registration links', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test.afterEach( async ( { hmua } ) => {
		await hmua.setRegistration( 'user' );
		await hmua.setPageStatus( PAGE_OPTION, 'publish' );
	} );

	test( "wp-login.php's register link leads to the registration page", async ( {
		page,
	} ) => {
		await page.goto( '/wp-login.php?action=register' );

		await expect( page ).toHaveURL( /\/create-an-account\/$/ );
		await expect( page.getByLabel( 'Username' ) ).toBeVisible();
	} );

	test( 'wp-signup.php redirects here when only user accounts are allowed', async ( {
		page,
	} ) => {
		await page.goto( '/wp-signup.php' );

		await expect( page ).toHaveURL( /\/create-an-account\/$/ );
	} );

	test( 'wp-signup.php stays with core when sites are allowed too', async ( {
		page,
		hmua,
	} ) => {
		await hmua.setRegistration( 'all' );

		await page.goto( '/wp-signup.php' );

		await expect( page ).toHaveURL( /\/wp-signup\.php$/ );
		await expect( page.locator( '#setupform' ) ).toBeVisible();
	} );

	test( 'wp-signup.php stays with core when only sites are allowed', async ( {
		page,
		hmua,
	} ) => {
		await hmua.setRegistration( 'blog' );

		await page.goto( '/wp-signup.php' );

		await expect( page ).toHaveURL( /\/wp-signup\.php$/ );
	} );

	test( 'an unpublished registration page is never linked', async ( {
		page,
		hmua,
	} ) => {
		await hmua.setPageStatus( PAGE_OPTION, 'draft' );

		await page.goto( '/wp-login.php?action=register' );

		// Core's own signup page, not the draft's ?page_id= link.
		await expect( page ).toHaveURL( /\/wp-signup\.php$/ );
		await expect( page.locator( '#setupform' ) ).toBeVisible();
	} );
} );

test.describe( 'Signed-in users', () => {
	// Uses the administrator session saved by global-setup.js.

	test.afterEach( async ( { hmua } ) => {
		await hmua.setRegistration( 'user' );
	} );

	test( 'the form is not shown to a signed-in user', async ( { page } ) => {
		await page.goto( REGISTRATION_URL );

		await expect( page.getByLabel( 'Username' ) ).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', { name: 'Register' } )
		).toHaveCount( 0 );
	} );

	test( '"Add New Site" in My Sites still goes to wp-signup.php', async ( {
		page,
		hmua,
	} ) => {
		await hmua.setRegistration( 'all' );

		await page.goto( '/wp-admin/my-sites.php' );

		await expect(
			page.getByRole( 'link', { name: 'Add New Site' } )
		).toHaveAttribute( 'href', /\/wp-signup\.php$/ );
	} );

	test( 'the settings page has the registration page and warns when registration is off', async ( {
		page,
		hmua,
	} ) => {
		const settingsUrl =
			'/wp-admin/options-general.php?page=hm-user-activation';
		const warning =
			'User registration is currently disabled for this network';

		await page.goto( settingsUrl );
		await expect( page.getByLabel( 'Registration page' ) ).not.toHaveValue(
			'0'
		);
		await expect( page.getByText( warning ) ).toHaveCount( 0 );

		await hmua.setRegistration( 'none' );
		await page.goto( settingsUrl );
		await expect( page.getByText( warning ) ).toBeVisible();
	} );
} );
