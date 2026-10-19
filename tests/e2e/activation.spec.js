const { test, expect } = require( './fixtures' );

// Activation is for visitors who are not signed in.
test.use( { storageState: { cookies: [], origins: [] } } );

const NEW_PASSWORD = 'correct-horse-battery-staple-9!';

test.describe( 'Activation', () => {
	test( 'a signup is sent an activation email linking to the activation page', async ( {
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		const email = await hmua.mail.waitFor( signup.email, {
			subject: /Activate your account/,
		} );

		expect( email.message ).toContain( 'Thank you for registering' );

		const link = new URL(
			email.links.find( ( url ) => url.includes( 'key=' ) )
		);
		expect( link.pathname ).toBe( '/activate-your-account/' );
		expect( link.searchParams.get( 'key' ) ).toBe( signup.key );
	} );

	test( 'the activation link activates the account', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );

		const email = await hmua.mail.waitFor( signup.email, {
			subject: /Activate your account/,
		} );
		await page.goto(
			email.links.find( ( url ) => url.includes( 'key=' ) )
		);

		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();
		await expect(
			page.getByText( `Your username is: ${ signup.login }` )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Set your password', exact: true } )
		).toBeVisible();

		// The key in the URL replaces the form and no error is shown.
		await expect( page.getByLabel( 'Activation key' ) ).toHaveCount( 0 );
		await expect( page.locator( '.hm-activation-errors' ) ).toHaveCount(
			0
		);

		const user = await hmua.getUser( signup.login );
		expect( user.exists ).toBe( true );
		expect( user.email ).toBe( signup.email );
	} );

	test( 'activation sends a welcome email with a password reset link', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();
		const activation = await hmua.mail.waitFor( signup.email, {
			subject: /Activate your account/,
		} );

		await page.goto(
			activation.links.find( ( url ) => url.includes( 'key=' ) )
		);
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();

		const welcome = await hmua.mail.waitFor( signup.email, {
			subject: /your account is active/,
		} );

		expect( welcome.message ).toContain(
			`Your username is: ${ signup.login }`
		);

		const resetLink = new URL(
			welcome.links.find( ( url ) => url.includes( 'reset-your-password' ) )
		);
		expect( resetLink.searchParams.get( 'login' ) ).toBe( signup.login );
		expect( resetLink.searchParams.get( 'key' ) ).toBeTruthy();

		expect(
			welcome.links.some( ( url ) => url.endsWith( '/log-in/' ) )
		).toBe( true );
	} );

	test( 'the user can set a password from the activation page and log in', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();
		const activation = await hmua.mail.waitFor( signup.email, {
			subject: /Activate your account/,
		} );

		await page.goto(
			activation.links.find( ( url ) => url.includes( 'key=' ) )
		);
		await page.getByRole( 'link', { name: 'Set your password', exact: true } ).click();

		await expect(
			page.getByRole( 'button', { name: 'Set password' } )
		).toBeVisible();

		await page.getByLabel( 'New password', { exact: true } ).fill( NEW_PASSWORD );
		await page.getByLabel( 'Confirm new password' ).fill( NEW_PASSWORD );
		await page.getByRole( 'button', { name: 'Set password' } ).click();

		await expect(
			page.getByText( 'Your password has been set. You can now log in.' )
		).toBeVisible();

		await page.goto( '/log-in/' );
		await page.getByLabel( 'Username or email address' ).fill( signup.login );
		await page.getByLabel( 'Password', { exact: true } ).fill( NEW_PASSWORD );
		await page.getByRole( 'button', { name: 'Log In' } ).click();

		await expect( page ).toHaveURL( /\/wp-admin\// );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	} );

	test( 'the activation form accepts a pasted key', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		await page.goto( '/activate-your-account/' );
		await page.getByLabel( 'Activation key' ).fill( signup.key );
		await page.getByRole( 'button', { name: 'Activate account' } ).click();

		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( true );
	} );

	test( 'the activation page shows the key form when no key is given', async ( {
		page,
	} ) => {
		await page.goto( '/activate-your-account/' );

		await expect( page.getByLabel( 'Activation key' ) ).toBeVisible();
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toHaveCount( 0 );
		await expect( page.locator( '.hm-activation-errors' ) ).toHaveCount(
			0
		);
	} );

	test( 'an invalid key shows an error', async ( { page } ) => {
		await page.goto( '/activate-your-account/?key=not-a-real-key' );

		await expect( page.locator( '.hm-activation-errors' ) ).toContainText(
			'Invalid activation key.'
		);
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toHaveCount( 0 );
	} );

	test( 'a key that was already used shows an error', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();
		const url = `/activate-your-account/?key=${ signup.key }`;

		await page.goto( url );
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();

		await page.goto( url );
		await expect( page.locator( '.hm-activation-errors' ) ).toContainText(
			'The user is already active.'
		);
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toHaveCount( 0 );
	} );

	test( 'the success state links to the log in page', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		await page.goto( `/activate-your-account/?key=${ signup.key }` );

		await expect(
			page
				.locator( '.hm-activation-success' )
				.getByRole( 'link', { name: 'Log in', exact: true } )
		).toHaveAttribute( 'href', /\/log-in\/$/ );
	} );

	test( 'skips the password step when the site says none is needed', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup( {
			meta: { skip_password_setup: true },
		} );

		await page.goto( `/activate-your-account/?key=${ signup.key }` );

		const success = page.locator( '.hm-activation-success' );
		await expect(
			success.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();
		await expect(
			success.getByRole( 'link', { name: 'Set your password' } )
		).toHaveCount( 0 );
		await expect(
			success.getByRole( 'link', { name: 'Log in', exact: true } )
		).toBeVisible();

		const welcome = await hmua.mail.waitFor( signup.email, {
			subject: /your account is active|account is ready/,
		} );
		expect( welcome.message ).toContain( 'Your account at' );
		expect( welcome.message ).toContain( 'is ready.' );
		expect(
			welcome.links.some( ( url ) => url.includes( 'reset-your-password' ) )
		).toBe( false );
	} );

	test( 'activation fires the activated action without internal signup meta', async ( {
		page,
		hmua,
	} ) => {
		const signup = await hmua.createSignup( {
			meta: { source: 'e2e' },
		} );

		await page.goto( `/activate-your-account/?key=${ signup.key }` );
		await expect(
			page.getByText( 'Your account has been successfully activated.' )
		).toBeVisible();

		const user = await hmua.getUser( signup.login );
		expect( user.activation_meta ).toMatchObject( { source: 'e2e' } );
		expect( user.activation_meta ).not.toHaveProperty(
			'hm_activation_resends'
		);
	} );

	test.fixme(
		'wp-activate.php redirects to the activation page keeping the key',
		async ( { page, hmua } ) => {
			// Currently a fatal error: get_permalink() runs on plugins_loaded
			// before $wp_rewrite exists, so wp-activate.php returns a 500.
			const signup = await hmua.createSignup();

			await page.goto( `/wp-activate.php?key=${ signup.key }` );

			await expect( page ).toHaveURL(
				new RegExp( `/activate-your-account/\\?key=${ signup.key }$` )
			);
			await expect(
				page.getByText( 'Your account has been successfully activated.' )
			).toBeVisible();
		}
	);

	test.fixme(
		'activation does not email the new password in plain text',
		async ( { page, hmua } ) => {
			// Currently WordPress core's own welcome email still goes out
			// alongside the plugin's one, and it contains the password.
			const signup = await hmua.createSignup();

			await page.goto( `/activate-your-account/?key=${ signup.key }` );
			await expect(
				page.getByText( 'Your account has been successfully activated.' )
			).toBeVisible();
			await hmua.mail.waitFor( signup.email, {
				subject: /your account is active/,
			} );

			const emails = await hmua.mail.all( signup.email );
			for ( const email of emails ) {
				expect( email.message ).not.toMatch( /^Password:/m );
			}
		}
	);
} );
