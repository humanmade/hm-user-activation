/**
 * hm-user-activation/password-reset — editor view.
 *
 * Plain ES5-compatible JS. The block is server-side rendered on the frontend;
 * the editor shows a representative placeholder.
 */
( function () {
	var blocks      = wp.blocks;
	var element     = wp.element;
	var blockEditor = wp.blockEditor;
	var i18n        = wp.i18n;

	var el            = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;
	var __            = i18n.__;

	blocks.registerBlockType( 'hm-user-activation/password-reset', {
		edit: function EditPasswordResetForm() {
			var blockProps = useBlockProps( {
				className: 'hm-password-reset hm-password-reset--editor',
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
					__( 'Password Reset Form', 'hm-user-activation' )
				),
				el( 'p', { style: { color: '#6b7280', fontSize: '0.875em', marginBottom: 0 } },
					__( 'Shows a "request reset link" form, or a "set new password" form when a valid key is in the URL.', 'hm-user-activation' )
				)
			);
		},

		save: function () {
			return null;
		},
	} );
}() );
