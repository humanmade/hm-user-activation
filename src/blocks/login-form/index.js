/**
 * hm-user-activation/login-form — editor view.
 *
 * The block is server-side rendered on the frontend; the editor shows a
 * representative placeholder with controls for its settings.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: function EditLoginForm( { attributes, setAttributes } ) {
		const blockProps = useBlockProps( {
			className: 'hm-login-form hm-login-form--editor',
			style: {
				border: '2px dashed #c8d0db',
				padding: '1.5em',
				background: '#f6f7f7',
			},
		} );

		return (
			<>
				<InspectorControls>
					<PanelBody
						title={ __(
							'Login form settings',
							'hm-user-activation'
						) }
						initialOpen={ true }
					>
						<ToggleControl
							label={ __(
								'Show "Remember me" checkbox',
								'hm-user-activation'
							) }
							checked={ attributes.rememberMe }
							onChange={ ( value ) =>
								setAttributes( { rememberMe: value } )
							}
						/>
						<TextControl
							label={ __(
								'Redirect URL after login',
								'hm-user-activation'
							) }
							help={ __(
								'Leave blank to redirect to the admin dashboard.',
								'hm-user-activation'
							) }
							value={ attributes.redirect }
							onChange={ ( value ) =>
								setAttributes( { redirect: value } )
							}
						/>
					</PanelBody>
				</InspectorControls>
				<div { ...blockProps }>
					<p style={ { fontWeight: 600, marginTop: 0 } }>
						{ __( 'Login Form', 'hm-user-activation' ) }
					</p>
					<p
						style={ {
							color: '#6b7280',
							fontSize: '0.875em',
							marginBottom: 0,
						} }
					>
						{ __(
							'Renders the WordPress login form with a "Forgot your password?" link pointing to the configured password reset page.',
							'hm-user-activation'
						) }
					</p>
				</div>
			</>
		);
	},

	save() {
		return null;
	},
} );
