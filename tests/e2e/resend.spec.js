const { test, expect } = require( './fixtures' );

// Resending is for visitors who are not signed in.
test.use( { storageState: { cookies: [], origins: [] } } );

const ACTIVATION_SUBJECT = /Activate your account/;
const CONFIRMATION = /sent a new activation link/;

/**
 * The activation key in an activation email's link.
 *
 * @param {Object} email Email returned by the mail helper.
 * @return {string} Activation key.
 */
function keyFrom( email ) {
	const link = email.links.find( ( url ) => url.includes( 'key=' ) );

	return new URL( link ).searchParams.get( 'key' );
}

/**
 * Submit the resend form on the activation page.
 *
 * @param {import('@playwright/test').Page} page  Page already showing the form.
 * @param {string}                          email Address to send to.
 */
async function requestNewLink( page, email ) {
	await page.getByLabel( 'Email address' ).fill( email );
	await page
		.getByRole( 'button', { name: 'Send a new activation link' } )
		.click();
}

test.describe( 'Expired activation links', () => {
	test.afterEach( async ( { hmua } ) => {
		await hmua.setOption( 'hm_activation_link_lifetime', 0 );
	} );

	test( 'an expired link shows an error and the resend form', async ( {
		page,
		hmua,
	} ) => {
		await hmua.setOption( 'hm_activation_link_lifetime', 24 );
		const signup = await hmua.createSignup( { ageHours: 48 } );

		await page.goto( `/activate-your-account/?key=${ signup.key }` );

		await expect( page.locator( '.hm-activation-errors' ) ).toContainText(
			'This activation link has expired.'
		);
		await expect( page.getByLabel( 'Email address' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Send a new activation link' } )
		).toBeVisible();

		// The account is not activated by an expired link.
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );
	} );

	test( 'a link inside its lifetime still activates', async ( {
		page,
		hmua,
	} ) => {
		await hmua.setOption( 'hm_activation_link_lifetime', 24 );
		const signup = await hmua.createSignup( { ageHours: 1 } );

		await page.goto( `/activate-your-account/?key=${ signup.key }` );

		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();
	} );

	test( 'requesting a new link for an expired signup sends a fresh working link', async ( {
		page,
		hmua,
	} ) => {
		await hmua.setOption( 'hm_activation_link_lifetime', 24 );
		const signup = await hmua.createSignup( { ageHours: 48 } );

		await page.goto( `/activate-your-account/?key=${ signup.key }` );
		await requestNewLink( page, signup.email );

		await expect( page ).toHaveURL( /hm_activation_resent=1/ );
		await expect( page.locator( '.hm-activation-resent' ) ).toContainText(
			CONFIRMATION
		);

		const fresh = await hmua.mail.waitFor( signup.email, {
			subject: ACTIVATION_SUBJECT,
			count: 2,
		} );
		const freshKey = keyFrom( fresh );
		expect( freshKey ).not.toBe( signup.key );

		// The old key is gone and the new one works.
		await page.goto( `/activate-your-account/?key=${ signup.key }` );
		await expect( page.locator( '.hm-activation-errors' ) ).toBeVisible();

		await page.goto( fresh.links.find( ( url ) => url.includes( 'key=' ) ) );
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( true );
	} );
} );

test.describe( 'Resend form', () => {
	test( 'an invalid key offers the resend form', async ( { page } ) => {
		await page.goto( '/activate-your-account/?key=not-a-real-key' );

		await expect( page.locator( '.hm-activation-errors' ) ).toContainText(
			'Invalid activation key.'
		);
		await expect( page.getByLabel( 'Email address' ) ).toBeVisible();
		await expect( page.locator( '.hm-activation-resent' ) ).toHaveCount( 0 );
	} );

	test( 'resending while the link is valid sends the same link again', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();
		await hmua.mail.waitFor( signup.email, { subject: ACTIVATION_SUBJECT } );

		await page.goto( '/activate-your-account/?key=not-a-real-key' );
		await requestNewLink( page, signup.email );

		await expect( page ).toHaveURL( /hm_activation_resent=1/ );
		await expect( page.locator( '.hm-activation-resent' ) ).toContainText(
			CONFIRMATION
		);
		await expect( page.locator( '.hm-activation-errors' ) ).toHaveCount( 0 );

		const resent = await hmua.mail.waitFor( signup.email, {
			subject: ACTIVATION_SUBJECT,
			count: 2,
		} );
		expect( keyFrom( resent ) ).toBe( signup.key );

		// Reloading the confirmation does not send a third email.
		await page.reload();
		await expect( page.locator( '.hm-activation-resent' ) ).toBeVisible();
		await page.waitForTimeout( 1500 );
		expect( await hmua.mail.all( signup.email ) ).toHaveLength( 2 );
	} );

	test( 'a second resend straight away sends nothing', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		await page.goto( '/activate-your-account/?key=not-a-real-key' );
		await requestNewLink( page, signup.email );
		await hmua.mail.waitFor( signup.email, {
			subject: ACTIVATION_SUBJECT,
			count: 2,
		} );

		await page.goto( '/activate-your-account/?key=not-a-real-key' );
		await requestNewLink( page, signup.email );

		// The visitor sees the same confirmation either way.
		await expect( page.locator( '.hm-activation-resent' ) ).toContainText(
			CONFIRMATION
		);
		await page.waitForTimeout( 1500 );
		expect( await hmua.mail.all( signup.email ) ).toHaveLength( 2 );
	} );

	test( 'an unknown address gets the same confirmation and no email', async ( {
		page,
		hmua,
	} ) => {
		const { email } = hmua.newIdentity();

		await page.goto( '/activate-your-account/?key=not-a-real-key' );
		await requestNewLink( page, email );

		await expect( page ).toHaveURL( /hm_activation_resent=1/ );
		await expect( page.locator( '.hm-activation-resent' ) ).toContainText(
			CONFIRMATION
		);

		await page.waitForTimeout( 1500 );
		expect( await hmua.mail.all( email ) ).toHaveLength( 0 );
	} );

	test( 'an already activated address gets the same confirmation and no email', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();
		await page.goto( `/activate-your-account/?key=${ signup.key }` );
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();
		await hmua.mail.reset( signup.email );

		await page.goto( '/activate-your-account/?key=not-a-real-key' );
		await requestNewLink( page, signup.email );

		await expect( page.locator( '.hm-activation-resent' ) ).toContainText(
			CONFIRMATION
		);
		await page.waitForTimeout( 1500 );
		expect( await hmua.mail.all( signup.email ) ).toHaveLength( 0 );
	} );

	test( 'there is no per-address limit by default', async ( {
		page,
		hmua,
	} ) => {
		// The old default was 10 requests an hour from one address.
		const signups = [];

		try {
			for ( let i = 0; i < 12; i++ ) {
				signups.push( await hmua.createSignup() );
			}

			for ( const signup of signups ) {
				await page.goto( '/activate-your-account/?key=not-a-real-key' );
				await requestNewLink( page, signup.email );
				await expect( page ).toHaveURL( /hm_activation_resent=1/ );
			}

			const last = signups[ signups.length - 1 ];
			await hmua.mail.waitFor( last.email, {
				subject: ACTIVATION_SUBJECT,
				count: 2,
			} );
		} finally {
			for ( const signup of signups ) {
				await hmua.cleanup( signup.login );
			}
		}
	} );
} );
