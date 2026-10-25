const { test: base, expect, createHelpers } = require( './fixtures' );

const SECOND = '/hmua-second';
const OFF = '/hmua-off';
const ACTIVATE = '/activate-your-account/';
const REGISTER = '/create-an-account/';

/**
 * Same fixtures, plus helpers for the second site and the site that does not
 * use the plugin. Every signup and user a test makes is removed afterwards.
 */
const test = base.extend( {
	hmua: async ( { hmua }, use ) => {
		const made = [];

		await use( {
			...hmua,
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

	sites: async ( { request, baseURL }, use ) => {
		const second = createHelpers( request, baseURL, SECOND );
		const off = createHelpers( request, baseURL, OFF );

		await use( {
			second,
			off,
			secondId: (
				await (
					await request.get(
						`${ SECOND }/?rest_route=/hmua-tests/v1/site`
					)
				).json()
			).id,
			offId: (
				await (
					await request.get(
						`${ OFF }/?rest_route=/hmua-tests/v1/site`
					)
				).json()
			).id,
		} );
	},
} );

/**
 * Meta a Registration Form signup made on a site has.
 *
 * @param {number} siteId Site ID.
 * @return {Object} Signup meta.
 */
function formMeta( siteId ) {
	return {
		add_to_blog: siteId,
		new_role: 'subscriber',
		hm_user_activation: true,
	};
}

test.describe( 'wp-activate.php', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test( 'a key in the URL goes to the activation page of the signup’s own site', async ( {
		page,
		hmua,
		sites,
	} ) => {
		const signup = await hmua.createSignup( {
			meta: formMeta( sites.secondId ),
		} );

		await page.goto( `/wp-activate.php?key=${ signup.key }` );

		await expect( page ).toHaveURL( `${ SECOND }${ ACTIVATE }` );
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();

		const state = await hmua.getSignupState( signup.login );
		expect( state.signup_active ).toBe( true );
		expect( state.user_exists ).toBe( true );
	} );

	test( 'a posted key is redirected too, and does not activate there', async ( {
		request,
		hmua,
		sites,
	} ) => {
		const signup = await hmua.createSignup( {
			meta: formMeta( sites.secondId ),
		} );

		const response = await request.post( '/wp-activate.php', {
			form: { key: signup.key },
			maxRedirects: 0,
		} );

		expect( response.status() ).toBe( 302 );
		expect( response.headers().location ).toContain(
			`${ SECOND }${ ACTIVATE }?key=${ signup.key }`
		);

		const state = await hmua.getSignupState( signup.login );
		expect( state.signup_active ).toBe( false );
	} );

	test( 'a form signup on a site without the plugin is stopped', async ( {
		request,
		hmua,
		sites,
	} ) => {
		const signup = await hmua.createSignup( {
			meta: formMeta( sites.offId ),
		} );

		const response = await request.get(
			`/wp-activate.php?key=${ signup.key }`,
			{ maxRedirects: 0 }
		);

		expect( response.status() ).toBe( 400 );
		expect( await response.text() ).toContain(
			'This activation link cannot be used.'
		);

		const state = await hmua.getSignupState( signup.login );
		expect( state.signup_active ).toBe( false );
	} );

	test( 'a signup for no site is left to core', async ( { page, hmua } ) => {
		const signup = await hmua.createSignup();

		await page.goto( `/wp-activate.php?key=${ signup.key }` );

		expect( new URL( page.url() ).pathname ).toBe( '/wp-activate.php' );
		await expect( page.locator( 'body' ) ).toContainText(
			'Your account is now active'
		);
	} );
} );

test.describe( 'Keys from another site', () => {
	test.use( { storageState: { cookies: [], origins: [] } } );

	test( 'the activation page treats them as unknown and does not activate', async ( {
		page,
		hmua,
		sites,
	} ) => {
		const signup = await hmua.createSignup( {
			meta: formMeta( sites.secondId ),
		} );

		await page.goto( `${ ACTIVATE }?key=not-a-real-key` );
		const unknown = await page
			.locator( '.hm-activation-errors' )
			.textContent();

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );

		await expect( page.locator( '.hm-activation-errors' ) ).toContainText(
			'Invalid activation key.'
		);
		expect(
			await page.locator( '.hm-activation-errors' ).textContent()
		).toBe( unknown );

		const state = await hmua.getSignupState( signup.login );
		expect( state.signup_active ).toBe( false );
		expect( state.user_exists ).toBe( false );
	} );

	test( 'Complete your account treats them as unknown', async ( {
		page,
		context,
		hmua,
		sites,
	} ) => {
		await hmua.useOptions( context, { hm_activation_complete_profile: 1 } );

		const signup = await hmua.createSignup( {
			meta: formMeta( sites.secondId ),
		} );

		await page.goto( `${ ACTIVATE }?key=${ signup.key }` );

		await expect(
			page.locator( 'form.hm-activation-form__complete' )
		).toHaveCount( 0 );
		await expect( page.locator( 'body' ) ).toContainText(
			'This activation link is not valid.'
		);

		const state = await hmua.getSignupState( signup.login );
		expect( state.signup_active ).toBe( false );
	} );
} );

test.describe( 'Allow registration on this site', () => {
	/**
	 * Edit Site URL for a site.
	 *
	 * @param {number} id Site ID.
	 * @return {string} URL.
	 */
	const editSite = ( id ) => `/wp-admin/network/site-info.php?id=${ id }`;

	/**
	 * Tick or untick the checkbox on Edit Site and save.
	 *
	 * @param {import('@playwright/test').Page} page    Page.
	 * @param {number}                          id      Site ID.
	 * @param {boolean}                         checked Whether to tick it.
	 */
	async function saveSite( page, id, checked ) {
		await page.goto( editSite( id ) );
		await page
			.getByLabel( 'Allow registration on this site' )
			.setChecked( checked );
		await page.getByRole( 'button', { name: 'Save Changes' } ).click();
		await expect( page.locator( '#message' ) ).toContainText(
			'Site info updated.'
		);
	}

	test( 'a super admin turns a site on, which creates its draft pages and shows its settings screen', async ( {
		page,
		sites,
	} ) => {
		const settings = `${ OFF }/wp-admin/options-general.php?page=hm-user-activation`;

		try {
			const before = await page.goto( settings );
			expect( before.status() ).toBe( 403 );

			await saveSite( page, sites.offId, true );

			expect(
				(
					await sites.off.getOption(
						'hm_activation_allow_registration'
					)
				).value
			).toBe( '1' );

			for ( const option of [
				'hm_activation_page_id',
				'hm_activation_registration_page_id',
				'hm_activation_password_reset_page_id',
			] ) {
				const { value } = await sites.off.getOption( option );
				expect( Number( value ), option ).toBeGreaterThan( 0 );
			}

			await page.goto( settings );
			await expect( page.locator( 'h1' ) ).toHaveText(
				'User Activation Settings'
			);
		} finally {
			await saveSite( page, sites.offId, false );

			for ( const option of [
				'hm_activation_page_id',
				'hm_activation_registration_page_id',
				'hm_activation_password_reset_page_id',
			] ) {
				const { value } = await sites.off.getOption( option );
				if ( Number( value ) ) {
					await sites.off.deletePage( Number( value ) );
				}
				await sites.off.deleteOption( option );
			}
			await sites.off.deleteOption( 'hm_activation_allow_registration' );
		}

		await page.goto( settings );
		await expect( page.locator( 'body' ) ).toContainText(
			'Sorry, you are not allowed to access this page.'
		);
	} );

	test( 'turning a site off stops its registration form', async ( {
		page,
		browser,
		sites,
	} ) => {
		const visitor = await browser.newContext( {
			storageState: { cookies: [], origins: [] },
		} );
		const form = await visitor.newPage();

		try {
			await saveSite( page, sites.secondId, false );
			expect(
				(
					await sites.second.getOption(
						'hm_activation_allow_registration'
					)
				).value
			).toBe( '0' );

			// Logged-out nonces are the same on every site, so the main
			// site's form gives one the second site would accept.
			await form.goto( REGISTER );
			const nonce = await form
				.locator( 'input[name="_hm_register_nonce"]' )
				.inputValue();

			await form.goto( `${ SECOND }${ REGISTER }` );
			await expect( form.locator( '.hm-registration-form' ) ).toHaveCount(
				0
			);

			// A visitor posting to the page anyway gets no signup.
			const login = `hmuaoff${ Date.now() }`;
			const email = `${ login }@example.com`;
			await form.request.post( `${ SECOND }${ REGISTER }`, {
				form: {
					user_email: email,
					user_name: login,
					_hm_register_nonce: nonce,
				},
			} );
			const state = await sites.second.getSignupStateByEmail( email );
			expect( state.signup_exists ).toBe( false );
		} finally {
			await saveSite( page, sites.secondId, true );
			await visitor.close();
		}
	} );
} );
