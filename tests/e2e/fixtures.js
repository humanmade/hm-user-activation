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
 * @param {import('@playwright/test').APIRequestContext} request   Request context.
 * @param {string}                                       [baseURL] Site URL, for test cookies.
 * @return {Object} Mail and account helpers.
 */
function createHelpers(
	request,
	baseURL = process.env.WP_BASE_URL || 'http://localhost:8889'
) {
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
		 * @param {string} to                Recipient address.
		 * @param {Object} [options]         Options.
		 * @param {RegExp} [options.subject] Subject the email must match.
		 * @param {number} [options.count]   How many matching emails to wait for.
		 * @param {number} [options.timeout] Milliseconds to wait.
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
		 * @param {Object} [options]          Options.
		 * @param {Object} [options.meta]     Meta stored with the signup.
		 * @param {number} [options.ageHours] Backdate the signup by this many hours.
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
		 * Register a pending site signup, which sends the activation email.
		 *
		 * @param {Object} [options]       Options.
		 * @param {string} [options.login] Username to sign up with instead of a new one.
		 * @return {Promise<{login: string, email: string, key: string, path: string}>} The signup.
		 */
		async createBlogSignup( { login } = {} ) {
			const identity = this.newIdentity();
			const path = `/hmua-blog-${ uniqueId() }/`;
			const data = await getJson(
				await request.post( routeUrl( 'blog-signup' ), {
					form: {
						user_login: login || identity.login,
						user_email: identity.email,
						path,
					},
				} )
			);

			return {
				login: data.user_login,
				email: data.user_email,
				key: data.activation_key,
				path,
			};
		},

		/**
		 * Whether a signup is still waiting and whether its site and user exist.
		 *
		 * @param {string} login  Username the signup is for.
		 * @param {string} [path] Site path to look for.
		 * @return {Promise<{signup_exists: boolean, signup_active: boolean, user_exists: boolean, site_exists: boolean}>} State.
		 */
		async getSignupState( login, path = '' ) {
			return getJson(
				await request.get(
					routeUrl( 'signup-state', { user_login: login, path } )
				)
			);
		},

		/**
		 * Delete the signups, site and user a test made.
		 *
		 * @param {string} login  Username.
		 * @param {string} [path] Site path, if a site may have been made.
		 */
		async cleanup( login, path = '' ) {
			await getJson(
				await request.delete(
					routeUrl( 'signup-state', { user_login: login, path } )
				)
			);
		},

		/**
		 * Create an active user with a known password.
		 *
		 * @param {string}                       password Password to set.
		 * @param {'subscriber'|'administrator'} [role]   Role on the main site. Administrators are not super admins.
		 * @param {string}                       [login]  Username to use instead of a new one.
		 * @return {Promise<{login: string, email: string, password: string}>} The user.
		 */
		async createUser( password, role = 'subscriber', login ) {
			const identity = this.newIdentity();

			if ( login ) {
				identity.login = login;
			}

			await getJson(
				await request.post( routeUrl( 'user' ), {
					form: {
						user_login: identity.login,
						user_email: identity.email,
						user_pass: password,
						role,
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
		 * Read one of the plugin's options.
		 *
		 * @param {string} name Option name.
		 * @return {Promise<{exists: boolean, value: *}>} The stored value.
		 */
		async getOption( name ) {
			return getJson(
				await request.get( routeUrl( 'option', { name } ) )
			);
		},

		/**
		 * Delete one of the plugin's options.
		 *
		 * @param {string} name Option name.
		 */
		async deleteOption( name ) {
			await getJson(
				await request.delete( routeUrl( 'option', { name } ) )
			);
		},

		/**
		 * Create a page. The content is saved as given, with no filtering.
		 *
		 * @param {Object}            page          Page.
		 * @param {string}            page.title    Title.
		 * @param {string}            page.slug     Slug.
		 * @param {string}            page.content  Block markup.
		 * @param {'publish'|'draft'} [page.status] Status. Defaults to publish.
		 * @return {Promise<number>} Page id.
		 */
		async createPage( { title, slug, content, status = 'publish' } ) {
			const data = await getJson(
				await request.post( routeUrl( 'page' ), {
					form: { title, slug, content, status },
				} )
			);

			return data.id;
		},

		/**
		 * Read a page.
		 *
		 * @param {number|string} id Page id.
		 * @return {Promise<{id: number, status: string, link: string, content: string}>} The page.
		 */
		async getPage( id ) {
			return getJson(
				await request.get( routeUrl( 'page', { id: String( id ) } ) )
			);
		},

		/**
		 * Delete a page for good.
		 *
		 * @param {number|string} id Page id.
		 */
		async deletePage( id ) {
			await getJson(
				await request.delete( routeUrl( 'page', { id: String( id ) } ) )
			);
		},

		/**
		 * Report on the deprecated 1.0.0 classes.
		 *
		 * @return {Promise<Object>} Report from the helper route.
		 */
		async getShims() {
			return getJson( await request.get( routeUrl( 'shims' ) ) );
		},

		/**
		 * Set the network registration setting.
		 *
		 * @param {'none'|'user'|'blog'|'all'} value New setting.
		 */
		async setRegistration( value ) {
			await getJson(
				await request.post( routeUrl( 'registration' ), {
					form: { value },
				} )
			);
		},

		/**
		 * Publish or unpublish the page one of the plugin's options points at.
		 *
		 * @param {string}            option Page option, e.g. hm_activation_registration_page_id.
		 * @param {'publish'|'draft'} status New status.
		 */
		async setPageStatus( option, status ) {
			await getJson(
				await request.post( routeUrl( 'page-status' ), {
					form: { option, status },
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
			return getJson(
				await request.get( routeUrl( 'user', { login } ) )
			);
		},

		/**
		 * Override plugin options for every request one browser context makes.
		 * Other tests, and other browser contexts, keep the saved values.
		 *
		 * @param {import('@playwright/test').BrowserContext} context Browser context.
		 * @param {Object<string, string|number>}             options `hm_activation_` options.
		 */
		async useOptions( context, options ) {
			await context.addCookies( [
				{
					name: 'hmua_test_options',
					value: encodeURIComponent( JSON.stringify( options ) ),
					url: baseURL,
				},
			] );
		},

		/**
		 * Turn on the stand-in password policy for one browser context.
		 *
		 * @param {import('@playwright/test').BrowserContext} context   Browser context.
		 * @param {number}                                    minLength Shortest password allowed.
		 */
		async usePasswordPolicy( context, minLength ) {
			await context.addCookies( [
				{
					name: 'hmua_test_min_password',
					value: String( minLength ),
					url: baseURL,
				},
			] );
		},

		/**
		 * Add the test first name field to the "Complete your account" form
		 * for one browser context.
		 *
		 * @param {import('@playwright/test').BrowserContext} context Browser context.
		 */
		async useCompleteFields( context ) {
			await context.addCookies( [
				{
					name: 'hmua_test_complete_fields',
					value: '1',
					url: baseURL,
				},
			] );
		},
	};
}

/**
 * Extended test fixtures with mail capture and account helpers.
 */
const test = base.extend( {
	hmua: async ( { request, baseURL }, use ) => {
		await use( createHelpers( request, baseURL ) );
	},

	/**
	 * Change plugin options for one test. Every option touched is put back
	 * to its original value, or deleted if it did not exist, afterwards.
	 * @param root0
	 * @param root0.hmua
	 * @param use
	 */
	options: async ( { hmua }, use ) => {
		const originals = new Map();

		async function remember( name ) {
			if ( ! originals.has( name ) ) {
				originals.set( name, await hmua.getOption( name ) );
			}
		}

		await use( {
			/**
			 * Remember options that code under test may change or create.
			 *
			 * @param {...string} names Option names.
			 */
			async track( ...names ) {
				for ( const name of names ) {
					await remember( name );
				}
			},

			async set( name, value ) {
				await remember( name );
				await hmua.setOption( name, value );
			},

			async remove( name ) {
				await remember( name );
				await hmua.deleteOption( name );
			},

			get: ( name ) => hmua.getOption( name ),
		} );

		for ( const [ name, original ] of originals ) {
			if ( original.exists ) {
				await hmua.setOption( name, original.value );
			} else {
				await hmua.deleteOption( name );
			}
		}
	},
} );

module.exports = { test, expect, createHelpers, extractLinks };
