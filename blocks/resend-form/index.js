/**
 * hm-user-activation/resend-form — editor view.
 *
 * Plain ES5-compatible JS. The block is server-side rendered on the frontend;
 * the editor shows a placeholder.
 */
( function () {
	var blocks      = wp.blocks;
	var element     = wp.element;
	var blockEditor = wp.blockEditor;
	var i18n        = wp.i18n;

	var el            = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;
	var __            = i18n.__;

	blocks.registerBlockType( 'hm-user-activation/resend-form', {
		edit: function EditResendForm() {
			var blockProps = useBlockProps( {
				className: 'hm-resend-form hm-resend-form--editor',
				style: {
					border:     '2px dashed #c8d0db',
					padding:    '1.5em',
					background: '#f6f7f7',
				},
			} );

			return el(
				'div',
				blockProps,
				el( 'p', { style: { fontWeight: 600, marginTop: 0 } },
					__( 'Resend Activation Form', 'hm-user-activation' )
				),
				el( 'p', { style: { color: '#6b7280', fontSize: '0.875em', marginBottom: 0 } },
					__( 'Renders an email field so users can request a new activation link. Place it inside the Activation Errors group so expired links lead straight to it.', 'hm-user-activation' )
				)
			);
		},

		save: function () {
			return null;
		},
	} );
}() );
