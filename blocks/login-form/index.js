/**
 * hm-user-activation/login-form — editor view.
 *
 * Plain ES5-compatible JS. The block is server-side rendered on the frontend;
 * the editor shows a representative placeholder with controls for its settings.
 */
( function () {
	var blocks      = wp.blocks;
	var element     = wp.element;
	var blockEditor = wp.blockEditor;
	var components  = wp.components;
	var i18n        = wp.i18n;

	var el                   = element.createElement;
	var useBlockProps        = blockEditor.useBlockProps;
	var InspectorControls    = blockEditor.InspectorControls;
	var __                   = i18n.__;
	var PanelBody            = components.PanelBody;
	var ToggleControl        = components.ToggleControl;
	var TextControl          = components.TextControl;

	blocks.registerBlockType( 'hm-user-activation/login-form', {
		edit: function EditLoginForm( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var blockProps = useBlockProps( {
				className: 'hm-login-form hm-login-form--editor',
				style: {
					border:     '2px dashed #c8d0db',
					padding:    '1.5em',
					background: '#f6f7f7',
				},
			} );

			return [
				el(
					InspectorControls,
					{ key: 'controls' },
					el(
						PanelBody,
						{ title: __( 'Login form settings', 'hm-user-activation' ), initialOpen: true },
						el( ToggleControl, {
							label:    __( 'Show "Remember me" checkbox', 'hm-user-activation' ),
							checked:  attributes.rememberMe,
							onChange: function ( value ) { setAttributes( { rememberMe: value } ); },
						} ),
						el( TextControl, {
							label:    __( 'Redirect URL after login', 'hm-user-activation' ),
							help:     __( 'Leave blank to redirect to the admin dashboard.', 'hm-user-activation' ),
							value:    attributes.redirect,
							onChange: function ( value ) { setAttributes( { redirect: value } ); },
						} )
					)
				),
				el(
					'div',
					Object.assign( {}, blockProps, { key: 'preview' } ),
					el( 'p', { style: { fontWeight: 600, marginTop: 0 } },
						__( 'Login Form', 'hm-user-activation' )
					),
					el( 'p', { style: { color: '#6b7280', fontSize: '0.875em', marginBottom: 0 } },
						__( 'Renders the WordPress login form with a "Forgot your password?" link pointing to the configured password reset page.', 'hm-user-activation' )
					)
				),
			];
		},

		save: function () {
			return null;
		},
	} );
}() );
