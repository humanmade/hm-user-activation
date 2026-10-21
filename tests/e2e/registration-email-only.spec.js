const { test: base, expect } = require( './fixtures' );

/**
 * Same fixtures, plus a way to make a registration page for one test.
 *
 * The page has the site's own registration page content, with the form block
 * swapped for the markup given, so the error and success groups are there.
 *
 * Every signup a test makes is removed afterwards (pass the username to
 * `hmua.track()`), and so is every page. The page is made the registration
 * page for this browser context only, through the test options cookie, so
 * specs running in parallel keep the site's own registration page.
 */
const test = base.extend( {
	hmua: async ( { hmua }, use ) => {
		const made = [];

		await use( {
			...hmua,
			track( login ) {
				made.push( login );
			},
		} );

		for ( const login of made ) {
			await hmua.cleanup( login );
		}
	},

	registrationPage: async ( { hmua, context }, use ) => {
		const ids = [];

		const option = await hmua.getOption( PAGE_OPTION );
		const { content: defaultContent } = await hmua.getPage( option.value );

		await use( async ( formBlock ) => {
			const content = defaultContent.replace( DEFAULT_FORM_BLOCK, formBlock );
			const slug = `hmua-reg-${ hmua.newIdentity().login }`;
			const id = await hmua.createPage( {
				title: 'Test registration',
				slug,
				content,
			} );

			ids.push( id );
			await hmua.useOptions( context, {
				[ PAGE_OPTION ]: id,
			} );

			return `/${ slug }/`;
		} );

		for ( const id of ids ) {
			await hmua.deletePage( id );
		}
	},
} );

const PAGE_OPTION = 'hm_activation_registration_page_id';
const DEFAULT_FORM_BLOCK = '<!-- wp:hm-user-activation/registration-form /-->';
const EMAIL_ONLY_FORM =
	'<!-- wp:hm-user-activation/registration-form {"showUsername":false} /-->';
const NO_FORM_BLOCK = '';
const ACTIVATION_SUBJECT = /Activate your account/;

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
 * The username a signup for this address should get: the local part with
 * everything but lowercase letters and numbers taken out.
 *
 * @param {string} email Email address.
 * @return {string} Expected username.
 */
function expectedLogin( email ) {
	return email.split( '@' )[ 0 ].toLowerCase().replace( /[^a-z0-9]/g, '' );
}

// Registration is for visitors who are not signed in.
test.use( { storageState: { cookies: [], origins: [] } } );

test.describe( 'Registration form without a username field', () => {
	test.beforeEach( async ( { hmua } ) => {
		await hmua.setRegistration( 'user' );
	} );

	test( 'asks only for an email address and makes the username from it', async ( {
		page,
		hmua,
		registrationPage,
	} ) => {
		const { email } = hmua.newIdentity();
		const login = expectedLogin( email );
		hmua.track( login );

		await page.goto( await registrationPage( EMAIL_ONLY_FORM ) );

		const form = registrationForm( page );
		await expect( form.getByLabel( 'Username' ) ).toHaveCount( 0 );
		await expect( form.locator( 'input[name="user_name"]' ) ).toHaveCount( 0 );
		await expect( form.getByLabel( 'Email address' ) ).toBeVisible();

		await form.getByLabel( 'Email address' ).fill( email );
		await form.getByRole( 'button', { name: 'Register' } ).click();

		await expect( page.locator( '.hm-registration-success' ) ).toContainText(
			`We have sent an activation link to ${ email }.`
		);
		await expect( page.locator( '.hm-registration-errors' ) ).toHaveCount( 0 );

		const state = await hmua.getSignupStateByEmail( email );
		expect( state.signup_exists ).toBe( true );
		expect( state.user_login ).toBe( login );

		await hmua.mail.waitFor( email, { subject: ACTIVATION_SUBJECT } );
	} );

	test( "keeps the other plugins' fields and their validation", async ( {
		page,
		hmua,
		context,
		registrationPage,
	} ) => {
		await hmua.useCoreFields( context );

		const { email } = hmua.newIdentity();
		hmua.track( expectedLogin( email ) );

		await page.goto( await registrationPage( EMAIL_ONLY_FORM ) );

		const form = registrationForm( page );
		await expect( form.getByLabel( 'Username' ) ).toHaveCount( 0 );
		await expect( form.getByLabel( 'Company' ) ).toBeVisible();
		await expect( form.getByLabel( 'I accept the terms' ) ).not.toBeChecked();

		await form.getByLabel( 'Email address' ).fill( email );
		await form.getByRole( 'button', { name: 'Register' } ).click();

		await expect( page.locator( '#hmua-terms-error' ) ).toHaveText(
			'Test: please accept the terms.'
		);
		await expect( page.locator( '.hm-registration-success' ) ).toHaveCount( 0 );
		expect( ( await hmua.getSignupStateByEmail( email ) ).signup_exists ).toBe(
			false
		);

		// The accepted terms go through, with the extra fields in the signup meta.
		await form.getByLabel( 'Company' ).fill( 'Acme' );
		await form.getByLabel( 'I accept the terms' ).check();
		await form.getByRole( 'button', { name: 'Register' } ).click();

		await expect( page.locator( '.hm-registration-success' ) ).toBeVisible();

		const state = await hmua.getSignupStateByEmail( email );
		expect( state.signup_exists ).toBe( true );
		expect( state.meta ).toMatchObject( { hmua_company: 'Acme', hmua_terms: '1' } );
	} );

	test( 'ignores a username posted by hand', async ( {
		page,
		hmua,
		registrationPage,
	} ) => {
		const { email } = hmua.newIdentity();
		const login = expectedLogin( email );
		const chosen = `${ login }chosen`;
		hmua.track( login );
		hmua.track( chosen );

		await page.goto( await registrationPage( EMAIL_ONLY_FORM ) );

		const form = registrationForm( page );
		await form.getByLabel( 'Email address' ).fill( email );
		await form.locator( 'form' ).evaluate( ( element, value ) => {
			const input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = 'user_name';
			input.value = value;
			element.appendChild( input );
		}, chosen );
		await form.getByRole( 'button', { name: 'Register' } ).click();

		await expect( page.locator( '.hm-registration-success' ) ).toBeVisible();

		const state = await hmua.getSignupStateByEmail( email );
		expect( state.user_login ).toBe( login );
		expect( state.user_login ).not.toBe( chosen );
		expect( ( await hmua.getSignupState( chosen ) ).signup_exists ).toBe( false );
	} );

	test( 'follows the filter when the block is rendered outside the page content', async ( {
		page,
		hmua,
		context,
		registrationPage,
	} ) => {
		await hmua.useRenderedRegistrationForm( context );
		await hmua.useHiddenUsername( context );

		const { email } = hmua.newIdentity();
		const login = expectedLogin( email );
		hmua.track( login );

		await page.goto( await registrationPage( NO_FORM_BLOCK ) );

		const form = registrationForm( page );
		await expect( form ).toHaveCount( 1 );
		await expect( form.getByLabel( 'Username' ) ).toHaveCount( 0 );

		await form.getByLabel( 'Email address' ).fill( email );
		await form.getByRole( 'button', { name: 'Register' } ).click();

		await expect( page.locator( '.hm-registration-success' ) ).toBeVisible();
		expect( ( await hmua.getSignupStateByEmail( email ) ).user_login ).toBe(
			login
		);
	} );

	test( 'generates a username for a form in a template that has the field off', async ( {
		page,
		hmua,
		context,
		registrationPage,
	} ) => {
		// No block in the page content and no filter: only the rendered block's own setting.
		await hmua.useRenderedRegistrationForm( context, { hidden: true } );

		const { email } = hmua.newIdentity();
		const login = expectedLogin( email );
		hmua.track( login );

		await page.goto( await registrationPage( NO_FORM_BLOCK ) );

		const form = registrationForm( page );
		await expect( form ).toHaveCount( 1 );
		await expect( form.getByLabel( 'Username' ) ).toHaveCount( 0 );

		await form.getByLabel( 'Email address' ).fill( email );
		await form.getByRole( 'button', { name: 'Register' } ).click();

		await expect( page.locator( '.hm-registration-success' ) ).toBeVisible();
		await expect( page.locator( '.hm-registration-errors' ) ).toHaveCount( 0 );
		expect( ( await hmua.getSignupStateByEmail( email ) ).user_login ).toBe(
			login
		);
	} );

	test( 'shows a username error with the generic errors when the field is hidden', async ( {
		page,
		hmua,
		context,
		registrationPage,
	} ) => {
		await hmua.useGeneratedUserName( context, 'ab' );

		const { email } = hmua.newIdentity();
		hmua.track( expectedLogin( email ) );

		await page.goto( await registrationPage( EMAIL_ONLY_FORM ) );

		const form = registrationForm( page );
		await form.getByLabel( 'Email address' ).fill( email );
		await form.getByRole( 'button', { name: 'Register' } ).click();

		await expect( page.locator( '#hm-registration-generic-error' ) ).toContainText(
			'Username must be at least 4 characters.'
		);
		await expect( page.locator( '.hm-registration-success' ) ).toHaveCount( 0 );
		await expect( form.locator( '#hm-user-name-error' ) ).toHaveCount( 0 );
		expect( ( await hmua.getSignupStateByEmail( email ) ).signup_exists ).toBe(
			false
		);
	} );

	test( 'asks for a username when the filter does not turn it off', async ( {
		page,
		hmua,
		context,
		registrationPage,
	} ) => {
		await hmua.useRenderedRegistrationForm( context );

		await page.goto( await registrationPage( NO_FORM_BLOCK ) );

		await expect(
			registrationForm( page ).getByLabel( 'Username' )
		).toBeVisible();
	} );

	test( 'the default form still shows the username error next to the field', async ( {
		page,
		hmua,
		registrationPage,
	} ) => {
		const { email } = hmua.newIdentity();
		hmua.track( expectedLogin( email ) );

		await page.goto( await registrationPage( DEFAULT_FORM_BLOCK ) );

		const form = registrationForm( page );
		await form.getByLabel( 'Email address' ).fill( email );

		// The browser would stop an empty required field, so post it empty by hand.
		await form.getByLabel( 'Username' ).evaluate( ( input ) => {
			input.required = false;
		} );
		await form.getByRole( 'button', { name: 'Register' } ).click();

		await expect( page.locator( '#hm-user-name-error' ) ).toContainText(
			'Please enter a username'
		);
		await expect( page.locator( '#hm-registration-generic-error' ) ).toHaveCount( 0 );
		expect( ( await hmua.getSignupStateByEmail( email ) ).signup_exists ).toBe(
			false
		);
	} );
} );
