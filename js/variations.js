/**
 * Block variations and block bindings client-side registration for HM User Activation.
 *
 * Activation page variations (core/group):
 *  hm-user-activation/errors  — shown when activation fails.
 *  hm-user-activation/success — shown when activation succeeds.
 *
 * Password reset page variations (core/group):
 *  hm-user-activation/reset-errors          — shown when a reset action fails.
 *  hm-user-activation/reset-request-success — shown after a reset email is sent.
 *  hm-user-activation/reset-success         — shown after the password is changed.
 *
 * Plain ES5-compatible; no build step required.
 */
( function () {
	var registerBlockVariation = wp.blocks.registerBlockVariation;
	var __                     = wp.i18n.__;

	// -------------------------------------------------------------------------
	// Activation: Errors group variant
	// -------------------------------------------------------------------------
	registerBlockVariation( 'core/group', {
		name: 'hm-user-activation/errors',

		title: __( 'Activation Errors', 'hm-user-activation' ),

		description: __(
			'Wrapper shown only when account activation fails. The inner paragraph is bound to the activation error message.',
			'hm-user-activation'
		),

		scope: [ 'inserter' ],

		icon: 'admin-users',

		isActive: function ( blockAttributes ) {
			return (
				blockAttributes.metadata &&
				blockAttributes.metadata.variationName === 'hm-user-activation/errors'
			);
		},

		attributes: {
			metadata: {
				variationName: 'hm-user-activation/errors',
			},
		},

		innerBlocks: [
			[
				'core/paragraph',
				{
					placeholder: __( 'Activation error message…', 'hm-user-activation' ),
					metadata: {
						bindings: {
							content: {
								source: 'hm-user-activation/error-message',
							},
						},
					},
				},
			],
		],
	} );

	// -------------------------------------------------------------------------
	// Activation: Success group variant
	// -------------------------------------------------------------------------
	registerBlockVariation( 'core/group', {
		name: 'hm-user-activation/success',

		title: __( 'Activation Success', 'hm-user-activation' ),

		description: __(
			'Wrapper shown only when account activation succeeds. Displays the username and a button to set a password.',
			'hm-user-activation'
		),

		scope: [ 'inserter' ],

		icon: 'admin-users',

		isActive: function ( blockAttributes ) {
			return (
				blockAttributes.metadata &&
				blockAttributes.metadata.variationName === 'hm-user-activation/success'
			);
		},

		attributes: {
			metadata: {
				variationName: 'hm-user-activation/success',
			},
		},

		innerBlocks: [
			// Static welcome paragraph.
			[
				'core/paragraph',
				{
					content: __( 'Your account has been successfully activated.', 'hm-user-activation' ),
				},
			],

			// Bound paragraph — outputs "Your username is: …"
			[
				'core/paragraph',
				{
					metadata: {
						bindings: {
							content: {
								source: 'hm-user-activation/username-message',
							},
						},
					},
				},
			],

			// Button linking to the pre-generated password reset URL.
			[
				'core/buttons',
				{},
				[
					[
						'core/button',
						{
							text: __( 'Set your password', 'hm-user-activation' ),
							metadata: {
								bindings: {
									url: {
										source: 'hm-user-activation/reset-url',
									},
								},
							},
						},
					],
				],
			],
		],
	} );

	// -------------------------------------------------------------------------
	// Password reset: Errors group variant
	// -------------------------------------------------------------------------
	registerBlockVariation( 'core/group', {
		name: 'hm-user-activation/reset-errors',

		title: __( 'Password Reset Errors', 'hm-user-activation' ),

		description: __(
			'Wrapper shown only when a password reset action fails. The inner paragraph is bound to the reset error message.',
			'hm-user-activation'
		),

		scope: [ 'inserter' ],

		icon: 'admin-users',

		isActive: function ( blockAttributes ) {
			return (
				blockAttributes.metadata &&
				blockAttributes.metadata.variationName === 'hm-user-activation/reset-errors'
			);
		},

		attributes: {
			metadata: {
				variationName: 'hm-user-activation/reset-errors',
			},
		},

		innerBlocks: [
			[
				'core/paragraph',
				{
					placeholder: __( 'Password reset error message…', 'hm-user-activation' ),
					metadata: {
						bindings: {
							content: {
								source: 'hm-user-activation/reset-error-message',
							},
						},
					},
				},
			],
		],
	} );

	// -------------------------------------------------------------------------
	// Password reset: Request success group variant
	// -------------------------------------------------------------------------
	registerBlockVariation( 'core/group', {
		name: 'hm-user-activation/reset-request-success',

		title: __( 'Password Reset — Email Sent', 'hm-user-activation' ),

		description: __(
			'Wrapper shown after a password reset email has been successfully dispatched.',
			'hm-user-activation'
		),

		scope: [ 'inserter' ],

		icon: 'admin-users',

		isActive: function ( blockAttributes ) {
			return (
				blockAttributes.metadata &&
				blockAttributes.metadata.variationName === 'hm-user-activation/reset-request-success'
			);
		},

		attributes: {
			metadata: {
				variationName: 'hm-user-activation/reset-request-success',
			},
		},

		innerBlocks: [
			[
				'core/paragraph',
				{
					content: __(
						'If an account exists with that email address or username, you will receive a password reset link shortly. Please check your inbox.',
						'hm-user-activation'
					),
				},
			],
		],
	} );

	// -------------------------------------------------------------------------
	// Password reset: Success group variant
	// -------------------------------------------------------------------------
	registerBlockVariation( 'core/group', {
		name: 'hm-user-activation/reset-success',

		title: __( 'Password Reset — Password Set', 'hm-user-activation' ),

		description: __(
			'Wrapper shown after the user successfully sets a new password.',
			'hm-user-activation'
		),

		scope: [ 'inserter' ],

		icon: 'admin-users',

		isActive: function ( blockAttributes ) {
			return (
				blockAttributes.metadata &&
				blockAttributes.metadata.variationName === 'hm-user-activation/reset-success'
			);
		},

		attributes: {
			metadata: {
				variationName: 'hm-user-activation/reset-success',
			},
		},

		innerBlocks: [
			[
				'core/paragraph',
				{
					content: __( 'Your password has been set. You can now log in.', 'hm-user-activation' ),
				},
			],
		],
	} );
}() );
