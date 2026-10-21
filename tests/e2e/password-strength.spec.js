const { test: base, expect } = require( './fixtures' );

/**
 * Same fixtures, but every signup and user a test makes through the helpers
 * is removed afterwards.
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

// Both forms are for visitors who are not signed in.
test.use( { storageState: { cookies: [], origins: [] } } );

const ACTIVATE = '/activate-your-account/';
const OLD_PASSWORD = 'old-password-for-tests-1!';
const STRONG = 'correct-Horse-battery-9-staple!';

/**
 * Request a reset link and return the link from the email.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {Object}                          hmua  Test helpers.
 * @param {string}                          email Address to request for.
 * @return {Promise<string>} The reset link.
 */
async function requestResetLink( page, hmua, email ) {
	await page.goto( '/reset-your-password/' );
	await page.getByLabel( 'Username or email address' ).fill( email );
	await page.getByRole( 'button', { name: 'Get new password' } ).click();

	await expect(
		page.getByText( 'you will receive a password reset link shortly' )
	).toBeVisible();

	const reset = await hmua.mail.waitFor( email, {
		subject: /Reset your password/,
	} );

	return reset.links.find( ( url ) => url.includes( 'key=' ) );
}

/**
 * The two forms that set a password.
 *
 * `open` puts the form on screen and `refused` asserts that the form's own
 * error for a refused password is showing and nothing was changed.
 */
const FORMS = [
	{
		name: 'Complete your account',
		submit: 'Activate account',
		pass1: '#hm-activation-pass1',
		pass2: '#hm-activation-pass2',
		async open( { page, context, hmua } ) {
			await hmua.useOptions( context, {
				hm_activation_complete_profile: 1,
			} );
			const signup = await hmua.createSignup();

			await page.goto( `${ ACTIVATE }?key=${ signup.key }` );

			return signup;
		},
		async refused( { page, hmua }, signup ) {
			await expect(
				page.locator( '.hm-activation-form__errors' )
			).toContainText( 'Test policy: use at least 12 characters.' );
			expect( ( await hmua.getUser( signup.login ) ).exists ).toBe(
				false
			);
		},
	},
	{
		name: 'Set new password',
		submit: 'Set password',
		pass1: '#hm-pass1',
		pass2: '#hm-pass2',
		async open( { page, hmua } ) {
			const user = await hmua.createUser( OLD_PASSWORD );

			await page.goto( await requestResetLink( page, hmua, user.email ) );

			return user;
		},
		async refused( { page } ) {
			await expect( page.locator( '.hm-reset-errors' ) ).toContainText(
				'Test policy: use at least 12 characters.'
			);
			await expect(
				page.getByText( 'Your password has been set.' )
			).toBeHidden();
		},
	},
];

for ( const form of FORMS ) {
	test.describe( `Password strength: ${ form.name }`, () => {
		test( 'shows the strength of the password and whether the two match', async ( {
			page,
			context,
			hmua,
		} ) => {
			await form.open( { page, context, hmua } );

			const result = page.locator( '.hm-password-strength' );
			// Empty until a password is typed, so it has no size to be "visible".
			await expect( result ).not.toHaveAttribute( 'hidden' );
			await expect( result ).toBeEmpty();
			await expect(
				page.locator( '.hm-password-toggle' )
			).toBeVisible();
			await expect( page.locator( form.pass1 ) ).toHaveAttribute(
				'aria-describedby',
				await result.getAttribute( 'id' )
			);

			await page.locator( form.pass1 ).fill( 'abc' );
			await expect( result ).toHaveText( 'Very weak' );
			await expect( result ).toHaveClass( /\bshort\b/ );

			// zxcvbn loads after the page, so the meter catches up.
			await page.locator( form.pass1 ).fill( STRONG );
			await expect( result ).toHaveText( 'Strong' );
			await expect( result ).toHaveClass( /\bstrong\b/ );

			await page.locator( form.pass2 ).fill( 'something-different' );
			await expect( result ).toHaveText( 'Mismatch' );
			await expect( result ).toHaveClass( /\bshort\b/ );
		} );

		test( 'the toggle shows and hides both passwords', async ( {
			page,
			context,
			hmua,
		} ) => {
			await form.open( { page, context, hmua } );

			const toggle = page.locator( '.hm-password-toggle' );
			await expect( toggle ).toHaveText( 'Show password' );

			await toggle.click();
			await expect( page.locator( form.pass1 ) ).toHaveAttribute(
				'type',
				'text'
			);
			await expect( page.locator( form.pass2 ) ).toHaveAttribute(
				'type',
				'text'
			);
			await expect( toggle ).toHaveText( 'Hide password' );

			await toggle.click();
			await expect( page.locator( form.pass1 ) ).toHaveAttribute(
				'type',
				'password'
			);
			await expect( page.locator( form.pass2 ) ).toHaveAttribute(
				'type',
				'password'
			);
			await expect( toggle ).toHaveText( 'Show password' );
		} );

		test( 'a weak password is still refused by the server', async ( {
			page,
			context,
			hmua,
		} ) => {
			await hmua.usePasswordPolicy( context, 12 );
			const made = await form.open( { page, context, hmua } );

			await page.locator( form.pass1 ).fill( 'short1!' );
			await page.locator( form.pass2 ).fill( 'short1!' );
			await page.getByRole( 'button', { name: form.submit } ).click();

			await form.refused( { page, hmua }, made );
			await expect( page.locator( form.pass1 ) ).toBeVisible();
		} );

		test( 'the filter turns the meter and toggle off', async ( {
			page,
			context,
			hmua,
		} ) => {
			await hmua.useNoStrengthMeter( context );
			await form.open( { page, context, hmua } );

			await expect( page.locator( form.pass1 ) ).toBeVisible();
			await expect( page.locator( '.hm-password-strength' ) ).toHaveCount(
				0
			);
			await expect( page.locator( '.hm-password-toggle' ) ).toHaveCount(
				0
			);
			await expect(
				page.locator( '#hm-user-activation-password-strength-js' )
			).toHaveCount( 0 );
			await expect( page.locator( form.pass1 ) ).not.toHaveAttribute(
				'aria-describedby'
			);
		} );
	} );
}

test.describe( 'Theme classes on the forms', () => {
	test( 'the login submit has the theme button class', async ( { page } ) => {
		await page.goto( '/log-in/' );

		await expect( page.locator( '#wp-submit' ) ).toHaveClass(
			/\bwp-element-button\b/
		);
	} );

	test( 'registration inputs and button have core classes', async ( {
		page,
	} ) => {
		await page.goto( '/create-an-account/' );

		const form = page.locator( '.hm-registration-form' );
		await expect( form.locator( '#hm-user-name' ) ).toHaveClass(
			/\binput\b/
		);
		await expect( form.locator( '#hm-user-email' ) ).toHaveClass(
			/\binput\b/
		);
		await expect(
			form.getByRole( 'button', { name: 'Register' } )
		).toHaveClass( /\bbutton\b.*\bwp-element-button\b/ );
	} );
} );
