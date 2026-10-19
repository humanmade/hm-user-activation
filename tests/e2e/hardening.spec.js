const { test, expect } = require( './fixtures' );

// These checks are about what an anonymous visitor receives.
test.use( { storageState: { cookies: [], origins: [] } } );

/**
 * The Set-Cookie header values of a response.
 *
 * @param {import('@playwright/test').APIResponse} response Response.
 * @return {string[]} One string per cookie.
 */
function setCookies( response ) {
	return response
		.headersArray()
		.filter( ( { name } ) => name.toLowerCase() === 'set-cookie' )
		.map( ( { value } ) => value );
}

test.describe( 'Hardening', () => {
	test( 'the activation link moves the key into a scoped, HTTP-only cookie', async ( {
		request,
	} ) => {
		const response = await request.get(
			'/activate-your-account/?key=hmua-test-key',
			{ maxRedirects: 0 }
		);

		expect( response.status() ).toBe( 302 );
		expect( response.headers().location ).toMatch(
			/\/activate-your-account\/$/
		);

		const cookie = setCookies( response ).find( ( value ) =>
			/^wp-hm-activate-[0-9a-f]+=hmua-test-key;/.test( value )
		);
		expect( cookie ).toBeTruthy();
		expect( cookie ).toMatch( /;\s*HttpOnly/i );
		expect( cookie ).toMatch( /;\s*SameSite=Lax/i );
		expect( cookie ).toMatch( /;\s*path=\/activate-your-account\//i );
	} );

	test( 'the reset link moves the key into a scoped, HTTP-only cookie', async ( {
		request,
	} ) => {
		const response = await request.get(
			'/reset-your-password/?key=hmua-test-key&login=someone',
			{ maxRedirects: 0 }
		);

		expect( response.status() ).toBe( 302 );
		expect( response.headers().location ).toMatch(
			/\/reset-your-password\/$/
		);

		const cookie = setCookies( response ).find( ( value ) =>
			/^wp-hm-resetpass-[0-9a-f]+=/.test( value )
		);
		expect( cookie ).toBeTruthy();
		expect( cookie ).toMatch( /;\s*HttpOnly/i );
		expect( cookie ).toMatch( /;\s*SameSite=Lax/i );
		expect( cookie ).toMatch( /;\s*path=\/reset-your-password\//i );
	} );

	test( "the plugin's pages are not cached or indexed", async ( {
		request,
	} ) => {
		for ( const path of [
			'/activate-your-account/',
			'/reset-your-password/',
			'/log-in/',
		] ) {
			const response = await request.get( path );

			expect( response.ok(), path ).toBe( true );
			expect( response.headers()[ 'cache-control' ], path ).toContain(
				'no-store'
			);
			expect( await response.text(), path ).toMatch(
				/<meta name=['"]robots['"] content=['"][^'"]*noindex/
			);
		}
	} );

	test( 'the activation cookie is only read on GET requests', async ( {
		request,
		hmua,
	} ) => {
		const signup = await hmua.createSignup();

		const redirect = await request.get(
			`/activate-your-account/?key=${ signup.key }`,
			{ maxRedirects: 0 }
		);
		const cookie = setCookies( redirect ).find( ( value ) =>
			value.startsWith( 'wp-hm-activate-' )
		);
		expect( cookie ).toBeTruthy();
		const name = cookie.split( '=' )[ 0 ];

		const put = await request.fetch( '/activate-your-account/', {
			method: 'PUT',
			headers: { Cookie: `${ name }=${ signup.key }` },
		} );
		expect( put.ok() ).toBe( true );
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( false );

		await request.get( '/activate-your-account/', {
			headers: { Cookie: `${ name }=${ signup.key }` },
		} );
		expect( ( await hmua.getUser( signup.login ) ).exists ).toBe( true );
	} );
} );
