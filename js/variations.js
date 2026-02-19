/**
 * Block variations and block bindings client-side registration for HM User Activation.
 *
 * Registers two variations of core/group:
 *
 *  hm-user-activation/errors  — shown when activation fails. Contains a paragraph
 *                                bound to the error_message binding key.
 *
 *  hm-user-activation/success — shown when activation succeeds. Contains paragraphs
 *                                with the static welcome text plus bindings for
 *                                username_message and password_message.
 *
 * Plain ES5-compatible; no build step required.
 */
( function () {
	var registerBlockVariation = wp.blocks.registerBlockVariation;
	var __                     = wp.i18n.__;

	// -------------------------------------------------------------------------
	// Errors group variant
	// -------------------------------------------------------------------------
	registerBlockVariation( 'core/group', {
		name: 'hm-user-activation/errors',

		title: __( 'Activation Errors', 'hm-user-activation' ),

		description: __(
			'Wrapper shown only when account activation fails. The inner paragraph is bound to the activation error message.',
			'hm-user-activation'
		),

		/**
		 * Only show in the block inserter, not as a transform or in other contexts.
		 */
		scope: [ 'inserter' ],

		icon: 'admin-users',

		/**
		 * Mark a group block as this variation when its metadata.variationName matches.
		 */
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

		/**
		 * Default inner blocks template. The paragraph is bound to the error_message
		 * key from the hm-user-activation/activation-data bindings source.
		 */
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
	// Success group variant
	// -------------------------------------------------------------------------
	registerBlockVariation( 'core/group', {
		name: 'hm-user-activation/success',

		title: __( 'Activation Success', 'hm-user-activation' ),

		description: __(
			'Wrapper shown only when account activation succeeds. Inner paragraphs display a welcome message and the user\'s generated credentials via block bindings.',
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
					content: __( 'Your account has been successfully activated. Welcome!', 'hm-user-activation' ),
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

			// Bound paragraph — outputs "Your password is: …"
			[
				'core/paragraph',
				{
					metadata: {
						bindings: {
							content: {
								source: 'hm-user-activation/password-message',
							},
						},
					},
				},
			],
		],
	} );
}() );
