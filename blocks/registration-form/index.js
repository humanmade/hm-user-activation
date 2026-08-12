/**
 * hm-user-activation/registration-form — editor view.
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

	var el                = element.createElement;
	var useBlockProps     = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var __                = i18n.__;
	var PanelBody         = components.PanelBody;
	var ToggleControl     = components.ToggleControl;

	blocks.registerBlockType( 'hm-user-activation/registration-form', {
		edit: function EditRegistrationForm( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;

			var blockProps = useBlockProps( {
				className: 'hm-registration-form hm-registration-form--editor',
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
						{ title: __( 'Registration form settings', 'hm-user-activation' ), initialOpen: true },
						el( ToggleControl, {
							label:    __( 'Show "confirmation will be emailed to you" notice', 'hm-user-activation' ),
							checked:  attributes.showEmailNotice,
							onChange: function ( value ) { setAttributes( { showEmailNotice: value } ); },
						} ),
						el( ToggleControl, {
							label:    __( 'Show log in link', 'hm-user-activation' ),
							help:     __( 'Links to the log in page configured in Settings → User Activation.', 'hm-user-activation' ),
							checked:  attributes.showLoginLink,
							onChange: function ( value ) { setAttributes( { showLoginLink: value } ); },
						} )
					)
				),
				el(
					'div',
					Object.assign( {}, blockProps, { key: 'preview' } ),
					el( 'p', { style: { fontWeight: 600, marginTop: 0 } },
						__( 'Registration Form', 'hm-user-activation' )
					),
					el( 'p', { style: { color: '#6b7280', fontSize: '0.875em', marginBottom: 0 } },
						__( 'Renders username and email fields. On submission the activation email is sent. Hidden once registration has succeeded, and for logged-in users.', 'hm-user-activation' )
					)
				),
			];
		},

		save: function () {
			return null;
		},
	} );
}() );
