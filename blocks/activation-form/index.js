/**
 * hm-user-activation/form — editor view.
 *
 * Plain ES5-compatible JS that uses the global wp.* objects so no build step is
 * required. The block is server-side rendered on the frontend; the editor
 * shows a representative placeholder.
 */
( function () {
	var blocks      = wp.blocks;
	var element     = wp.element;
	var blockEditor = wp.blockEditor;
	var i18n        = wp.i18n;

	var el             = element.createElement;
	var useBlockProps  = blockEditor.useBlockProps;
	var __             = i18n.__;

	blocks.registerBlockType( 'hm-user-activation/form', {
		/**
		 * Editor view — shows a non-interactive preview of the form.
		 */
		edit: function EditActivationForm() {
			var blockProps = useBlockProps( {
				className: 'hm-activation-form hm-activation-form--editor',
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
					__( 'Activation Form', 'hm-user-activation' )
				),
				el( 'p', { style: { color: '#6b7280', fontSize: '0.875em', marginBottom: 0 } },
					__( 'Renders the activation key input, and a submit button. Hidden when activation has already succeeded.', 'hm-user-activation' )
				)
			);
		},

		/**
		 * Block is server-side rendered — save returns null.
		 */
		save: function () {
			return null;
		},
	} );
}() );
