const {
	test: base,
	expect,
} = require( '@wordpress/e2e-test-utils-playwright' );

const ROUTE = 'hmua-tests/v1';

/**
 * REST URL for a test helper route.
 *
 * Uses `rest_route` so it works whatever the permalink structure.
 *
 * @param {string} path   Route below the helper namespace.
 * @param {Object} params Query parameters.
 * @return {string} Relative URL.
 */
function routeUrl( path, params = {} ) {
	const query = new URLSearchParams( {
		rest_route: `/${ ROUTE }/${ path }`,
		...params,
	} );

	return `/?${ query.toString() }`;
}

/**
 * Pause for a number of milliseconds.
 *
 * @param {number} ms Duration.
 * @return {Promise<void>} Resolves after the delay.
 */
function sleep( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

/**
 * Random suffix for unique usernames and addresses.
 *
 * @return {string} Lowercase alphanumeric string.
 */
function uniqueId() {
	return `${ Date.now().toString( 36 ) }${ Math.random()
		.toString( 36 )
		.slice( 2, 8 ) }`;
}

/**
 * Pull every URL out of an email body.
 *
 * @param {string} message Email body.
 * @return {string[]} URLs in order of appearance.
 */
function extractLinks( message ) {
	return ( message.match( /https?:\/\/[^\s<>"]+/g ) || [] ).map( ( url ) =>
		url.replace( /[.,;:!?)]+$/, '' )
	);
}

/**
 * Helpers around the test-only mu-plugin in tests/mu-plugins.
 *
 * @param {import('@playwright/test').APIRequestContext} request Request context.
 * @return {Object} Mail and account helpers.
 */
function createHelpers( request ) {
	async function getJson( response ) {
		expect(
			response.ok(),
			`${ response.url() } returned ${ response.status() }`
		).toBe( true );

		return response.json();
	}

	const mail = {
		/**
		 * Captured emails, oldest first.
		 *
		 * @param {string} [to] Only emails sent to this address.
		 * @return {Promise<Object[]>} Emails.
		 */
		async all( to ) {
			return getJson(
				await request.get( routeUrl( 'mail', to ? { to } : {} ) )
			);
		},

		/**
		 * Forget captured emails.
		 *
		 * @param {string} [to] Only forget emails sent to this address.
		 */
		async reset( to ) {
			await getJson(
				await request.delete( routeUrl( 'mail', to ? { to } : {} ) )
			);
		},

		/**
		 * Wait for an email to an address, optionally matching its subject.
		 *
		 * @param {string}  to                Recipient address.
		 * @param {Object}  [options]         Options.
		 * @param {RegExp}  [options.subject] Subject the email must match.
		 * @param {number}  [options.count]   How many matching emails to wait for.
		 * @param {number}  [options.timeout] Milliseconds to wait.
		 * @return {Promise<Object>} The newest matching email, with `links`.
		 */
		async waitFor( to, { subject, count = 1, timeout = 10000 } = {} ) {
			const deadline = Date.now() + timeout;

			do {
				const matches = ( await mail.all( to ) ).filter(
					( email ) => ! subject || subject.test( email.subject )
				);

				if ( matches.length >= count ) {
					const email = matches[ matches.length - 1 ];
					return { ...email, links: extractLinks( email.message ) };
				}

				await sleep( 250 );
			} while ( Date.now() < deadline );

			throw new Error(
				`No email to ${ to }${
					subject ? ` matching ${ subject }` : ''
				} arrived within ${ timeout }ms`
			);
		},
	};

	return {
		mail,

		/**
		 * A username and email address no other test will use.
		 *
		 * @return {{login: string, email: string}} Fresh identity.
		 */
		newIdentity() {
			const id = uniqueId();

			return { login: `hmua${ id }`, email: `hmua-${ id }@example.com` };
		},

		/**
		 * Register a pending signup, which sends the activation email.
		 *
		 * @param {Object}  [options]           Options.
		 * @param {Object}  [options.meta]      Meta stored with the signup.
		 * @param {number}  [options.ageHours]  Backdate the signup by this many hours.
		 * @return {Promise<{login: string, email: string, key: string}>} The signup.
		 */
		async createSignup( { meta, ageHours } = {} ) {
			const identity = this.newIdentity();
			const data = await getJson(
				await request.post( routeUrl( 'signup' ), {
					form: {
						user_login: identity.login,
						user_email: identity.email,
						...( meta && { meta: JSON.stringify( meta ) } ),
						...( ageHours && { age_hours: String( ageHours ) } ),
					},
				} )
			);

			return { ...identity, key: data.activation_key };
		},

		/**
		 * Create an active user with a known password.
		 *
		 * @param {string} password Password to set.
		 * @return {Promise<{login: string, email: string, password: string}>} The user.
		 */
		async createUser( password ) {
			const identity = this.newIdentity();

			await getJson(
				await request.post( routeUrl( 'user' ), {
					form: {
						user_login: identity.login,
						user_email: identity.email,
						user_pass: password,
					},
				} )
			);

			return { ...identity, password };
		},

		/**
		 * Set one of the plugin's options.
		 *
		 * @param {string} name  Option name.
		 * @param {string} value New value.
		 */
		async setOption( name, value ) {
			await getJson(
				await request.post( routeUrl( 'option' ), {
					form: { name, value: String( value ) },
				} )
			);
		},

		/**
		 * Look a user up by login.
		 *
		 * @param {string} login Username.
		 * @return {Promise<Object>} `{ exists, ... }`.
		 */
		async getUser( login ) {
			return getJson( await request.get( routeUrl( 'user', { login } ) ) );
		},
	};
}

/**
 * Extended test fixtures with mail capture and account helpers.
 */
const test = base.extend( {
	hmua: async ( { request }, use ) => {
		await use( createHelpers( request ) );
	},
} );

module.exports = { test, expect, createHelpers, extractLinks };
