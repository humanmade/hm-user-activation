const fs = require( 'fs' );
const path = require( 'path' );
const { test: base, expect } = require( './fixtures' );

// These specs change site-wide options, so playwright.config.js runs this
// file on its own after the rest. The `options` and `pages` fixtures put
// back every option and remove every page a test touched or the upgrade made.

const ACTIVATION_SUBJECT = /Activate your account/;
const WELCOME_SUBJECT = /your account is active/;

const DB_VERSION = 'hm_activation_db_version';
const RESET_PAGE = 'hm_activation_password_reset_page_id';
const LOGIN_PAGE = 'hm_activation_login_page_id';
const LOGIN_URL = 'hm_activation_login_url';
const WELCOME_BODY = 'hm_activation_welcome_email_body';
const FROM_NAME = 'hm_activation_from_name';
const FROM_EMAIL = 'hm_activation_from_email';
const LEGACY_SENDER_OPTIONS = [
	'hm_activation_email_from_name',
	'hm_activation_email_from',
	'hm_activation_welcome_email_from_name',
	'hm_activation_welcome_email_from',
];

/**
 * Every option the upgrade reads, changes, deletes or creates.
 */
const UPGRADE_OPTIONS = [
	DB_VERSION,
	RESET_PAGE,
	LOGIN_PAGE,
	LOGIN_URL,
	WELCOME_BODY,
	'hm_activation_welcome_email_subject',
	'hm_activation_page_id',
	FROM_NAME,
	FROM_EMAIL,
	...LEGACY_SENDER_OPTIONS,
];

const NO_LOGIN = { cookies: [], origins: [] };

/**
 * The activation page 1.0.0 shipped, with a custom paragraph and an embed in
 * the success group.
 */
const LEGACY_PAGE = fs.readFileSync(
	path.join( __dirname, 'fixtures', 'activation-page-1.0.0.html' ),
	'utf8'
);

const test = base.extend( {
	/**
	 * Create pages that are deleted after the test, along with the draft
	 * password reset page the upgrade makes.
	 */
	pages: async ( { hmua, options }, use ) => {
		const ids = [];
		const resetBefore = await options.get( RESET_PAGE );

		await use( {
			async create( page ) {
				const id = await hmua.createPage( page );
				ids.push( id );

				return id;
			},
		} );

		const resetAfter = await options.get( RESET_PAGE );

		if (
			resetAfter.exists &&
			( ! resetBefore.exists ||
				String( resetAfter.value ) !== String( resetBefore.value ) )
		) {
			ids.push( resetAfter.value );
		}

		for ( const id of ids ) {
			await hmua.deletePage( id );
		}
	},
} );

/**
 * Number of blocks bound to a binding source.
 *
 * @param {string} content Block markup.
 * @param {string} source  Source name without the plugin prefix.
 * @return {number} Count.
 */
function bindingCount( content, source ) {
	return content.split( `"source":"hm-user-activation/${ source }"` ).length - 1;
}

/**
 * The From header of an email.
 *
 * @param {Object} email Email from the mail helper.
 * @return {string} Header value.
 */
function fromHeader( email ) {
	return email.headers
		.find( ( header ) => header.startsWith( 'From:' ) )
		.replace( 'From: ', '' );
}

/**
 * Put a site in the state version 1.0.0 left it: old sender options, a log
 * in URL, a {password} placeholder, an activation page with the password
 * bindings and no password reset page. Nothing has been upgraded yet.
 *
 * @param {Object} fixtures         Test fixtures.
 * @param {Object} [seed]           Options.
 * @param {string} [seed.loginUrl]  Saved log in URL. Defaults to a new page's link.
 * @return {Promise<{activationId: number, loginUrl: string}>} What was created.
 */
async function seedLegacySite( { hmua, options, pages }, { loginUrl } = {} ) {
	await options.track( ...UPGRADE_OPTIONS );

	await options.remove( DB_VERSION );
	await options.remove( RESET_PAGE );
	await options.remove( LOGIN_PAGE );
	await options.remove( FROM_NAME );
	await options.remove( FROM_EMAIL );

	await options.set( 'hm_activation_email_from_name', 'Legacy Sender' );
	await options.set( 'hm_activation_email_from', 'legacy-sender@example.com' );
	await options.set( 'hm_activation_welcome_email_from_name', 'Legacy Welcome' );
	await options.set(
		'hm_activation_welcome_email_from',
		'legacy-welcome@example.com'
	);
	await options.set( WELCOME_BODY, 'Hi {display_name}\nPassword: {password}' );

	if ( ! loginUrl ) {
		const loginId = await pages.create( {
			title: 'Legacy login',
			slug: `legacy-login-${ Date.now() }`,
			content: '<!-- wp:hm-user-activation/login-form /-->',
		} );
		loginUrl = ( await hmua.getPage( loginId ) ).link;
	}

	await options.set( LOGIN_URL, loginUrl );

	const activationId = await pages.create( {
		title: 'Legacy activation',
		slug: `legacy-activation-${ Date.now() }`,
		content: LEGACY_PAGE,
	} );
	await options.set( 'hm_activation_page_id', activationId );

	return { activationId, loginUrl };
}

/**
 * Load wp-admin, which is where the upgrade runs.
 *
 * @param {import('@playwright/test').Page} page Page signed in as a user.
 * @param {string}                          [url] Admin URL.
 */
async function loadAdmin( page, url = '/wp-admin/' ) {
	await page.goto( url );
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
}

/**
 * Sign in to a new browser context, so it does not share the admin session.
 *
 * @param {import('@playwright/test').Browser} browser Browser.
 * @param {string}                             baseURL Site URL.
 * @param {Object}                             user    `{ login, password }`.
 * @return {Promise<import('@playwright/test').Page>} Page signed in as the user.
 */
async function signInAs( browser, baseURL, user ) {
	const context = await browser.newContext( {
		baseURL,
		storageState: NO_LOGIN,
	} );
	const page = await context.newPage();

	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( user.login );
	await page.locator( '#user_pass' ).fill( user.password );
	await page.locator( '#wp-submit' ).click();
	await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

	return page;
}

/**
 * Activate a fresh signup on the activation page in a logged out browser.
 *
 * @param {import('@playwright/test').Browser} browser  Browser.
 * @param {string}                             baseURL  Site URL.
 * @param {Object}                             hmua     Test helpers.
 * @param {Function}                           [check]  Called with the page once activated.
 * @return {Promise<Object>} The signup.
 */
async function activate( browser, baseURL, hmua, check ) {
	const signup = await hmua.createSignup();
	const email = await hmua.mail.waitFor( signup.email, {
		subject: ACTIVATION_SUBJECT,
	} );
	const context = await browser.newContext( {
		baseURL,
		storageState: NO_LOGIN,
	} );

	try {
		const page = await context.newPage();
		await page.goto( email.links.find( ( url ) => url.includes( 'key=' ) ) );
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();

		if ( check ) {
			await check( page );
		}
	} finally {
		await context.close();
	}

	return signup;
}

test.describe( 'Upgrading from 1.0.0', () => {
	test( 'front-end and AJAX requests do not run the upgrade', async ( {
		page,
		request,
		playwright,
		baseURL,
		hmua,
		options,
		pages,
	} ) => {
		await seedLegacySite( { hmua, options, pages } );

		await page.goto( '/' );

		const ajax = '/wp-admin/admin-ajax.php';
		await request.post( ajax, { form: { action: 'heartbeat' } } );

		const anonymous = await playwright.request.newContext( {
			baseURL,
			storageState: NO_LOGIN,
		} );
		await anonymous.post( ajax, { form: { action: 'heartbeat' } } );
		await anonymous.dispose();

		expect( ( await options.get( DB_VERSION ) ).exists ).toBe( false );
		expect(
			( await options.get( 'hm_activation_email_from_name' ) ).exists
		).toBe( true );
		expect( ( await options.get( LOGIN_URL ) ).exists ).toBe( true );
	} );

	test( 'loading wp-admin as an admin upgrades the site', async ( {
		page,
		hmua,
		options,
		pages,
	} ) => {
		const { activationId, loginUrl } = await seedLegacySite( {
			hmua,
			options,
			pages,
		} );

		await loadAdmin( page );

		expect( ( await options.get( FROM_NAME ) ).value ).toBe( 'Legacy Sender' );
		expect( ( await options.get( FROM_EMAIL ) ).value ).toBe(
			'legacy-sender@example.com'
		);
		for ( const name of LEGACY_SENDER_OPTIONS ) {
			expect( ( await options.get( name ) ).exists, name ).toBe( false );
		}

		const loginPage = await options.get( LOGIN_PAGE );
		expect( loginPage.exists ).toBe( true );
		expect( ( await hmua.getPage( loginPage.value ) ).link ).toBe( loginUrl );
		expect( ( await options.get( LOGIN_URL ) ).exists ).toBe( false );

		const body = ( await options.get( WELCOME_BODY ) ).value;
		expect( body ).toContain( 'Set your password: {password_reset_link}' );
		expect( body ).not.toContain( '{password}' );

		expect( Number( ( await options.get( DB_VERSION ) ).value ) ).toBe( 1 );

		const { content } = await hmua.getPage( activationId );
		expect( content ).not.toContain( 'hm-user-activation/password' );
		expect( bindingCount( content, 'reset-url' ) ).toBe( 1 );
		expect( bindingCount( content, 'login-url' ) ).toBe( 1 );
		expect( content ).toContain( 'Custom content stays.' );
		expect( content ).toContain(
			'<iframe src="https://example.com/embed"></iframe>'
		);
		expect( content ).toContain( 'Welcome!' );

		const reset = await options.get( RESET_PAGE );
		expect( reset.exists ).toBe( true );

		const resetPage = await hmua.getPage( reset.value );
		expect( resetPage.status ).toBe( 'draft' );
		expect( resetPage.content ).toContain( 'wp:hm-user-activation/password-reset' );
	} );

	test( 'does not run again once the site is upgraded', async ( {
		page,
		hmua,
		options,
		pages,
	} ) => {
		const { activationId } = await seedLegacySite( { hmua, options, pages } );

		await loadAdmin( page );
		const upgraded = await hmua.getPage( activationId );

		await options.set( 'hm_activation_email_from_name', 'Old again' );
		await options.set( FROM_NAME, 'Kept' );

		await loadAdmin( page );

		expect( ( await options.get( FROM_NAME ) ).value ).toBe( 'Kept' );
		expect( ( await options.get( 'hm_activation_email_from_name' ) ).value ).toBe(
			'Old again'
		);
		expect( ( await hmua.getPage( activationId ) ).content ).toBe(
			upgraded.content
		);
	} );

	test( 'uses the welcome email sender when there is no activation email sender', async ( {
		page,
		hmua,
		options,
		pages,
	} ) => {
		await seedLegacySite( { hmua, options, pages } );
		await options.remove( 'hm_activation_email_from_name' );
		await options.remove( 'hm_activation_email_from' );

		await loadAdmin( page );

		expect( ( await options.get( FROM_NAME ) ).value ).toBe( 'Legacy Welcome' );
		expect( ( await options.get( FROM_EMAIL ) ).value ).toBe(
			'legacy-welcome@example.com'
		);
		for ( const name of LEGACY_SENDER_OPTIONS ) {
			expect( ( await options.get( name ) ).exists, name ).toBe( false );
		}
	} );

	test( 'keeps a log in URL that is not a page on the site and still uses it', async ( {
		page,
		browser,
		baseURL,
		hmua,
		options,
		pages,
	} ) => {
		const loginUrl = 'https://login.example.com/';
		await seedLegacySite( { hmua, options, pages }, { loginUrl } );
		await options.set( WELCOME_BODY, 'Log in at {login_url}' );

		await loadAdmin(
			page,
			'/wp-admin/options-general.php?page=hm-user-activation'
		);

		expect( ( await options.get( LOGIN_URL ) ).value ).toBe( loginUrl );
		expect( ( await options.get( LOGIN_PAGE ) ).exists ).toBe( false );
		await expect( page.getByText( 'saved by version 1.0.0' ) ).toBeVisible();

		const signup = await activate( browser, baseURL, hmua );
		const welcome = await hmua.mail.waitFor( signup.email, {
			subject: WELCOME_SUBJECT,
		} );
		expect( welcome.links ).toContain( loginUrl );
	} );

	test( 'drops a log in URL that is the WordPress log in URL', async ( {
		page,
		baseURL,
		hmua,
		options,
		pages,
	} ) => {
		await seedLegacySite(
			{ hmua, options, pages },
			{ loginUrl: `${ baseURL }/wp-login.php` }
		);

		await loadAdmin( page );

		expect( ( await options.get( LOGIN_URL ) ).exists ).toBe( false );
		expect( ( await options.get( LOGIN_PAGE ) ).exists ).toBe( false );
	} );

	test( 'removes the password text from a page that already has a set-password button', async ( {
		page,
		hmua,
		options,
		pages,
	} ) => {
		await seedLegacySite( { hmua, options, pages } );

		const id = await pages.create( {
			title: 'Has button',
			slug: `has-button-${ Date.now() }`,
			content: `<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"url":{"source":"hm-user-activation/reset-url"}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Set your password</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"hm-user-activation/password-message"}}}} -->
<p></p>
<!-- /wp:paragraph -->`,
		} );

		await loadAdmin( page );

		const { content } = await hmua.getPage( id );
		expect( content ).not.toContain( 'hm-user-activation/password' );
		expect( bindingCount( content, 'reset-url' ) ).toBe( 1 );
	} );

	test( 'also runs for a site admin who is not a super admin, without stripping the page', async ( {
		browser,
		baseURL,
		hmua,
		options,
		pages,
	} ) => {
		const { activationId } = await seedLegacySite( { hmua, options, pages } );
		const admin = await hmua.createUser( 'site-admin-password-1!', 'administrator' );
		const page = await signInAs( browser, baseURL, admin );

		try {
			await loadAdmin( page );

			expect( Number( ( await options.get( DB_VERSION ) ).value ) ).toBe( 1 );

			const { content } = await hmua.getPage( activationId );
			expect( content ).toContain(
				'<iframe src="https://example.com/embed"></iframe>'
			);
			expect( content ).not.toContain( 'hm-user-activation/password' );
		} finally {
			await page.context().close();
		}
	} );

	test( 'does not run for a user who cannot manage options', async ( {
		browser,
		baseURL,
		hmua,
		options,
		pages,
	} ) => {
		await seedLegacySite( { hmua, options, pages } );
		const subscriber = await hmua.createUser( 'subscriber-password-1!' );
		const page = await signInAs( browser, baseURL, subscriber );

		try {
			await loadAdmin( page, '/wp-admin/profile.php' );

			expect( ( await options.get( DB_VERSION ) ).exists ).toBe( false );
		} finally {
			await page.context().close();
		}
	} );

	test( 'activation on the upgraded page links to core set-password while the reset page is a draft', async ( {
		page,
		browser,
		baseURL,
		hmua,
		options,
		pages,
	} ) => {
		const { loginUrl } = await seedLegacySite( { hmua, options, pages } );

		await loadAdmin( page );

		const signup = await activate( browser, baseURL, hmua, async ( activated ) => {
			const success = activated.locator( '.hm-activation-success' );

			await expect(
				success.getByRole( 'link', { name: 'Set your password', exact: true } )
			).toHaveAttribute( 'href', /wp-login\.php\?action=rp/ );
			await expect(
				success.getByRole( 'link', { name: 'Log in', exact: true } )
			).toHaveAttribute( 'href', loginUrl );
			expect( await activated.content() ).not.toContain( 'Your password is' );
		} );

		const sender = '"Legacy Sender" <legacy-sender@example.com>';

		const activation = await hmua.mail.waitFor( signup.email, {
			subject: ACTIVATION_SUBJECT,
		} );
		expect( fromHeader( activation ) ).toBe( sender );

		const welcome = await hmua.mail.waitFor( signup.email, {
			subject: WELCOME_SUBJECT,
		} );
		expect( fromHeader( welcome ) ).toBe( sender );
		expect( welcome.message ).not.toContain( '{password}' );
		expect(
			welcome.links.some( ( url ) => url.includes( 'wp-login.php?action=rp' ) )
		).toBe( true );
	} );
} );

test.describe( 'Hooks kept from the 1.0.0 work', () => {
	// These are for visitors who are not signed in.
	test.use( { storageState: NO_LOGIN } );

	/**
	 * The set-password link in the welcome email after a fresh activation.
	 *
	 * @param {import('@playwright/test').Browser} browser Browser.
	 * @param {string}                             baseURL Site URL.
	 * @param {Object}                             hmua    Test helpers.
	 * @return {Promise<{link: URL, login: string}>} The link and the new user.
	 */
	async function welcomeResetLink( browser, baseURL, hmua ) {
		const signup = await activate( browser, baseURL, hmua );
		const welcome = await hmua.mail.waitFor( signup.email, {
			subject: WELCOME_SUBJECT,
		} );

		return {
			link: new URL( welcome.links.find( ( url ) => url.includes( 'key=' ) ) ),
			login: signup.login,
		};
	}

	test( 'set-password links use a published password reset page', async ( {
		browser,
		baseURL,
		hmua,
	} ) => {
		const { link } = await welcomeResetLink( browser, baseURL, hmua );

		expect( link.pathname ).toBe( '/reset-your-password/' );
	} );

	test( 'set-password links use wp-login.php when no reset page is set', async ( {
		browser,
		baseURL,
		hmua,
		options,
	} ) => {
		await options.remove( RESET_PAGE );

		const { link, login } = await welcomeResetLink( browser, baseURL, hmua );

		expect( link.pathname ).toBe( '/wp-login.php' );
		expect( link.searchParams.get( 'action' ) ).toBe( 'rp' );
		expect( link.searchParams.get( 'login' ) ).toBe( login );
	} );

	test( 'set-password links use wp-login.php when the reset page is a draft', async ( {
		browser,
		baseURL,
		hmua,
	} ) => {
		await hmua.setPageStatus( RESET_PAGE, 'draft' );

		try {
			const { link } = await welcomeResetLink( browser, baseURL, hmua );

			expect( link.pathname ).toBe( '/wp-login.php' );
			expect( link.searchParams.get( 'action' ) ).toBe( 'rp' );
		} finally {
			await hmua.setPageStatus( RESET_PAGE, 'publish' );
		}
	} );

	test( 'the reset URL fallback filter changes the base', async ( {
		browser,
		baseURL,
		hmua,
		options,
	} ) => {
		await options.remove( RESET_PAGE );
		await options.set(
			'hm_activation_test_reset_fallback',
			'https://example.com/custom-reset/'
		);

		const { link, login } = await welcomeResetLink( browser, baseURL, hmua );

		expect( link.origin + link.pathname ).toBe(
			'https://example.com/custom-reset/'
		);
		expect( link.searchParams.get( 'login' ) ).toBe( login );
	} );

	test( 'lost password links point at a published reset page', async ( {
		page,
	} ) => {
		await page.goto( '/log-in/' );

		await expect(
			page.getByRole( 'link', { name: 'Forgot your password?' } )
		).toHaveAttribute( 'href', /\/reset-your-password\/$/ );
	} );

	test( 'lost password links are left alone when the filter returns false', async ( {
		page,
		options,
	} ) => {
		await options.set( 'hm_activation_test_filter_lostpassword', '0' );

		await page.goto( '/log-in/' );

		await expect(
			page.getByRole( 'link', { name: 'Forgot your password?' } )
		).toHaveAttribute( 'href', /wp-login\.php\?action=lostpassword/ );
	} );

	test( 'lost password links are left alone when the reset page is a draft', async ( {
		page,
		hmua,
	} ) => {
		await hmua.setPageStatus( RESET_PAGE, 'draft' );

		try {
			await page.goto( '/log-in/' );

			await expect(
				page.getByRole( 'link', { name: 'Forgot your password?' } )
			).toHaveAttribute( 'href', /wp-login\.php\?action=lostpassword/ );
		} finally {
			await hmua.setPageStatus( RESET_PAGE, 'publish' );
		}
	} );

	test( 'the link lifetime filter receives the signup', async ( {
		browser,
		baseURL,
		hmua,
		options,
	} ) => {
		await options.remove( 'hm_activation_test_lifetime_args' );

		const signup = await activate( browser, baseURL, hmua );

		const { value } = await options.get( 'hm_activation_test_lifetime_args' );
		expect( value ).toMatchObject( {
			argument_count: 2,
			is_object: true,
			user_login: signup.login,
		} );
	} );

	test( 'the welcome email filter runs after the result is set', async ( {
		browser,
		baseURL,
		hmua,
		options,
	} ) => {
		await options.remove( 'hm_activation_test_welcome_filter' );

		await activate( browser, baseURL, hmua );

		const { value } = await options.get( 'hm_activation_test_welcome_filter' );
		expect( value ).toEqual( { result_is_success: true } );
	} );

	test( 'the 1.0.0 classes exist as shims that never expose a reset link or password', async ( {
		hmua,
	} ) => {
		const shims = await hmua.getShims();

		expect( shims.classes ).toEqual( {
			Activation: true,
			Emails: true,
			Block_Bindings: true,
			Page_Setup: true,
			Admin_Settings: true,
		} );
		expect( shims.is_success ).toBe( true );
		expect( shims.username ).toBe( 'someone' );
		expect( shims.result_keys ).toEqual(
			expect.arrayContaining( [ 'success', 'user_id', 'username' ] )
		);
		expect( shims.result_keys ).not.toContain( 'reset_url' );
		expect( shims.password ).toBe( '' );
		expect( shims.deprecated_called.length ).toBeGreaterThan( 0 );
	} );
} );
