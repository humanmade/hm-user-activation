const { test, expect } = require( './fixtures' );

const BLOCKS = [
	'hm-user-activation/form',
	'hm-user-activation/login-form',
	'hm-user-activation/password-reset',
	'hm-user-activation/registration-form',
	'hm-user-activation/resend-form',
];

const VARIATIONS = [
	'hm-user-activation/errors',
	'hm-user-activation/resent',
	'hm-user-activation/success',
	'hm-user-activation/registration-errors',
	'hm-user-activation/registration-success',
	'hm-user-activation/reset-errors',
	'hm-user-activation/reset-request-success',
	'hm-user-activation/reset-success',
];

test.describe( 'Block editor', () => {
	test( 'registers the blocks and variations and renders a block', async ( {
		page,
	} ) => {
		const errors = [];
		page.on( 'console', ( message ) => {
			if ( message.type() === 'error' ) {
				errors.push( message.text() );
			}
		} );
		page.on( 'pageerror', ( error ) => errors.push( error.message ) );

		await page.goto( '/wp-admin/post-new.php?post_type=page' );
		await page.waitForFunction( () =>
			Boolean( window.wp?.data?.select( 'core/block-editor' ) )
		);

		// Keep the welcome guide from covering the editor.
		await page.evaluate( () => {
			window.wp.data
				.dispatch( 'core/preferences' )
				.set( 'core/edit-post', 'welcomeGuide', false );
		} );

		const registered = await page.evaluate(
			( names ) =>
				Object.fromEntries(
					names.map( ( name ) => [
						name,
						!! window.wp.blocks.getBlockType( name ),
					] )
				),
			BLOCKS
		);
		for ( const name of BLOCKS ) {
			expect( registered[ name ], `${ name } is registered` ).toBe(
				true
			);
		}

		const variations = await page.evaluate( () =>
			window.wp.blocks
				.getBlockVariations( 'core/group' )
				.map( ( variation ) => variation.name )
		);
		for ( const name of VARIATIONS ) {
			expect( variations, `${ name } is registered` ).toContain( name );
		}

		await page.evaluate( () => {
			const { createBlock } = window.wp.blocks;
			window.wp.data
				.dispatch( 'core/block-editor' )
				.insertBlocks( [
					createBlock( 'hm-user-activation/form' ),
					createBlock( 'hm-user-activation/login-form' ),
				] );
		} );

		const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
		await expect(
			canvas.locator( '[data-type="hm-user-activation/form"]' )
		).toContainText( 'Activation Form' );
		await expect(
			canvas.locator( '[data-type="hm-user-activation/login-form"]' )
		).toContainText( 'Login Form' );
		await expect(
			canvas.getByText( 'This block has encountered an error' )
		).toHaveCount( 0 );
		await expect(
			canvas.getByText( "Your site doesn't include support" )
		).toHaveCount( 0 );

		expect( errors, 'no console errors' ).toEqual( [] );
	} );
} );
