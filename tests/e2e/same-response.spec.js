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
			async createUser( ...args ) {
				const user = await hmua.createUser( ...args );
				made.push( user.login );
				return user;
			},
		} );

		for ( const login of made ) {
			await hmua.cleanup( login );
		}
	},
} );

const REGISTRATION_URL = '/create-an-account/';
const ACTIVATION_SUBJECT = /Activate your account/;
const ACCOUNT_SUBJECT = /You already have an account/;
const SAME_RESPONSE = { hm_activation_registration_hide_account_state: 1 };

/**
 * Fill in the username and email address and submit the registration form.
 *
 * @param {import('@playwright/test').Page} page         Page showing the form.
 * @param {Object}                          identity     Values to submit.
 * @param {string}                          identity.login Username.
 * @param {string}                          identity.email Email address.
 */
async function register( page, { login, email } ) {
	const form = page.locator( '.hm-registration-form' );

	await form.getByLabel( 'Username' ).fill( login );
	await form.getByLabel( 'Email address' ).fill( email );
	await form.getByRole( 'button', { name: 'Register' } ).click();
}

/**
 * The success text with the email address replaced by a placeholder.
 *
 * @param {import('@playwright/test').Page} page  Page showing the success group.
 * @param {string}                          email Address the text names.
 * @return {Promise<string>} Text, with whitespace collapsed.
 */
async function successText( page, email ) {
	const success = page.locator( '.hm-registration-success' );
	await expect( success ).toBeVisible();

	return ( await success.innerText() )
		.split( email )
		.join( '{email}' )
		.replace( /\s+/g, ' ' )
		.trim();
}

// The registration form is for visitors who are not signed in.
test.use( { storageState: { cookies: [], origins: [] } } );

test.describe( 'Same response for known addresses', () => {
	test.beforeEach( async ( { context, hmua } ) => {
		await hmua.setRegistration( 'user' );
		await hmua.useOptions( context, SAME_RESPONSE );
	} );

	test( 'existing, pending and new addresses get the same confirmation', async ( {
		page,
		hmua,
	} ) => {
		const account = await hmua.createUser( 'existing-user-password-1!' );
		const pending = await hmua.createSignup();
		const fresh = hmua.newIdentity();
		const texts = [];

		for ( const email of [ account.email, pending.email, fresh.email ] ) {
			const identity = { login: hmua.newIdentity().login, email };
			hmua.track( identity.login );

			await page.goto( REGISTRATION_URL );
			await register( page, identity );

			texts.push( await successText( page, email ) );
			await expect( page.locator( '.hm-registration-errors' ) ).toHaveCount(
				0
			);

			// Only a new address gets a signup.
			expect(
				( await hmua.getSignupState( identity.login ) ).signup_exists
			).toBe( email === fresh.email );
		}

		expect( texts[ 0 ] ).toContain( 'We have sent an activation link to {email}.' );
		expect( texts[ 1 ] ).toBe( texts[ 0 ] );
		expect( texts[ 2 ] ).toBe( texts[ 0 ] );
	} );

	test( 'an existing account is told once an hour', async ( { page, hmua } ) => {
		const account = await hmua.createUser( 'existing-user-password-1!' );

		await page.goto( REGISTRATION_URL );
		await register( page, {
			login: hmua.newIdentity().login,
			email: account.email,
		} );
		await expect( page.locator( '.hm-registration-success' ) ).toBeVisible();

		const notice = await hmua.mail.waitFor( account.email, {
			subject: ACCOUNT_SUBJECT,
		} );
		expect( notice.links.some( ( url ) => url.includes( '/log-in/' ) ) ).toBe(
			true
		);

		await page.goto( REGISTRATION_URL );
		await register( page, {
			login: hmua.newIdentity().login,
			email: account.email,
		} );
		await expect( page.locator( '.hm-registration-success' ) ).toBeVisible();

		await page.waitForTimeout( 1500 );
		const notices = ( await hmua.mail.all( account.email ) ).filter(
			( email ) => ACCOUNT_SUBJECT.test( email.subject )
		);
		expect( notices ).toHaveLength( 1 );
	} );

	test( 'a pending signup gets its activation email again', async ( {
		page,
		hmua,
	} ) => {
		const pending = await hmua.createSignup();
		await hmua.mail.waitFor( pending.email, { subject: ACTIVATION_SUBJECT } );

		await page.goto( REGISTRATION_URL );
		await register( page, {
			login: hmua.newIdentity().login,
			email: pending.email,
		} );
		await expect( page.locator( '.hm-registration-success' ) ).toBeVisible();

		await hmua.mail.waitFor( pending.email, {
			subject: ACTIVATION_SUBJECT,
			count: 2,
		} );
	} );

	test( 'only the fixable error shows for an existing address, and the same text follows once fixed', async ( {
		page,
		context,
		hmua,
	} ) => {
		await hmua.useCoreFields( context );
		const account = await hmua.createUser( 'existing-user-password-1!' );
		const fresh = hmua.newIdentity();
		const identity = { login: hmua.newIdentity().login, email: account.email };
		hmua.track( identity.login );
		hmua.track( fresh.login );

		await page.goto( REGISTRATION_URL );
		await register( page, identity );

		await expect( page.locator( '#hmua-terms-error' ) ).toHaveText(
			'Test: please accept the terms.'
		);
		await expect( page.getByText( /This email address is already registered/i ) ).toHaveCount( 0 );
		await expect( page.locator( '#hm-user-email-error' ) ).toHaveCount( 0 );
		await expect( page.locator( '.hm-registration-success' ) ).toHaveCount( 0 );

		await page.waitForTimeout( 1500 );
		expect( await hmua.mail.all( account.email ) ).toHaveLength( 0 );

		await page.getByLabel( 'I accept the terms' ).check();
		await page
			.locator( '.hm-registration-form' )
			.getByRole( 'button', { name: 'Register' } )
			.click();
		const known = await successText( page, account.email );
		expect( known ).toContain( 'We have sent an activation link to {email}.' );

		await page.goto( REGISTRATION_URL );
		await page.getByLabel( 'I accept the terms' ).check();
		await register( page, fresh );
		expect( await successText( page, fresh.email ) ).toBe( known );

		await hmua.mail.waitFor( account.email, { subject: ACCOUNT_SUBJECT } );
	} );

	test( 'a username given with a known address is reserved like one given with a new address', async ( {
		page,
		hmua,
	} ) => {
		const account = await hmua.createUser( 'existing-user-password-1!' );

		/**
		 * Register a username with a new address, then again with a second new
		 * address, and return what the second attempt shows.
		 *
		 * @param {string} login First address's username.
		 * @param {string} email Address for the first attempt.
		 * @return {Promise<{summary: string, inline: string}>} Error texts, with usernames and addresses replaced.
		 */
		async function probe( login, email ) {
			const second = hmua.newIdentity();
			hmua.track( login );

			await page.goto( REGISTRATION_URL );
			await register( page, { login, email } );
			await expect( page.locator( '.hm-registration-success' ) ).toBeVisible();

			await page.goto( REGISTRATION_URL );
			await register( page, { login, email: second.email } );

			const inline = page.locator( '#hm-user-name-error' );
			await expect( inline ).toBeVisible();
			await expect( page.locator( '.hm-registration-success' ) ).toHaveCount( 0 );

			const clean = ( text ) =>
				text
					.split( login )
					.join( '{login}' )
					.split( second.email )
					.join( '{email}' )
					.replace( /\s+/g, ' ' )
					.trim();

			return {
				summary: clean(
					await page.locator( '.hm-registration-errors' ).innerText()
				),
				inline: clean( await inline.innerText() ),
			};
		}

		const forNewAddress = await probe(
			hmua.newIdentity().login,
			hmua.newIdentity().email
		);
		const forKnownAddress = await probe(
			hmua.newIdentity().login,
			account.email
		);

		expect( forNewAddress.inline ).toContain( 'currently reserved' );
		expect( forKnownAddress ).toEqual( forNewAddress );
	} );

	test( 'a banned email domain gives the same errors for known and unknown addresses', async ( {
		page,
		hmua,
	} ) => {
		const taken = await hmua.createUser( 'existing-user-password-1!' );
		const known = hmua.newIdentity();
		const knownEmail = known.email.replace( 'example.com', 'hmua-banned.test' );
		const unknownEmail = hmua
			.newIdentity()
			.email.replace( 'example.com', 'hmua-banned.test' );
		await hmua.createUser(
			'existing-user-password-1!',
			'subscriber',
			known.login,
			knownEmail
		);

		await hmua.banEmailDomain( 'hmua-banned.test' );

		try {
			const texts = [];

			for ( const email of [ knownEmail, unknownEmail ] ) {
				await page.goto( REGISTRATION_URL );
				await register( page, { login: taken.login, email } );
				await expect( page.locator( '#hm-user-name-error' ) ).toBeVisible();

				texts.push(
					( await page.locator( '.hm-registration-errors' ).innerText() )
						.split( email )
						.join( '{email}' )
						.replace( /\s+/g, ' ' )
						.trim()
				);
			}

			expect( texts[ 0 ] ).toContain( 'You cannot use that email address' );
			expect( texts[ 1 ] ).toBe( texts[ 0 ] );
		} finally {
			await hmua.unbanEmailDomain();
		}
	} );

	test( "core's sign-up form posts for a known address go to the registration page", async ( {
		context,
		hmua,
	} ) => {
		const account = await hmua.createUser( 'existing-user-password-1!' );

		const response = await context.request.post( '/wp-signup.php', {
			form: {
				stage: 'validate-user-signup',
				signup_for: 'user',
				user_name: hmua.newIdentity().login,
				user_email: account.email,
			},
			maxRedirects: 0,
		} );

		expect( response.status() ).toBe( 302 );
		expect( response.headers().location ).toMatch( /\/create-an-account\/$/ );
	} );

	test( 'username and email errors the person can fix still show', async ( {
		page,
		hmua,
	} ) => {
		const taken = await hmua.createUser( 'existing-user-password-1!' );
		const other = await hmua.createUser( 'existing-user-password-1!' );
		const fresh = hmua.newIdentity();
		hmua.track( fresh.login );

		await page.goto( REGISTRATION_URL );
		await register( page, { login: taken.login, email: other.email } );

		await expect( page.locator( '#hm-user-name-error' ) ).toHaveText(
			'Sorry, that username already exists!'
		);
		await expect( page.locator( '#hm-user-email-error' ) ).toHaveCount( 0 );
		await expect( page.locator( '.hm-registration-success' ) ).toHaveCount( 0 );

		// Valid for the browser, but not for WordPress.
		await page.goto( REGISTRATION_URL );
		await register( page, { login: fresh.login, email: 'nobody@invalid' } );

		await expect( page.locator( '#hm-user-email-error' ) ).toContainText(
			'valid email address'
		);
		await expect( page.locator( '.hm-registration-success' ) ).toHaveCount( 0 );
	} );
} );

test.describe( 'Same response mode off', () => {
	test( "core's error for a registered email address is unchanged", async ( {
		page,
		hmua,
	} ) => {
		await hmua.setRegistration( 'user' );
		const account = await hmua.createUser( 'existing-user-password-1!' );

		await page.goto( REGISTRATION_URL );
		await register( page, {
			login: hmua.newIdentity().login,
			email: account.email,
		} );

		await expect( page.locator( '#hm-user-email-error' ) ).toContainText(
			'This email address is already registered.'
		);
		await expect( page.locator( '.hm-registration-success' ) ).toHaveCount( 0 );

		await page.waitForTimeout( 1500 );
		expect( await hmua.mail.all( account.email ) ).toHaveLength( 0 );
	} );

	test( "core's sign-up form keeps showing its own error for a registered address", async ( {
		request,
		hmua,
	} ) => {
		await hmua.setRegistration( 'user' );
		const account = await hmua.createUser( 'existing-user-password-1!' );

		const response = await request.post( '/wp-signup.php', {
			form: {
				stage: 'validate-user-signup',
				signup_for: 'user',
				user_name: hmua.newIdentity().login,
				user_email: account.email,
			},
			maxRedirects: 0,
		} );

		expect( response.status() ).toBe( 200 );
		expect( await response.text() ).toContain( 'already registered' );
	} );
} );
