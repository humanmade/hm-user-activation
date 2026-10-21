/**
 * hm-user-activation/registration-form — editor view.
 *
 * The block is server-side rendered on the frontend; the editor shows a
 * representative placeholder with controls for its settings.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';
import './style.css';

registerBlockType( metadata.name, {
	edit: function EditRegistrationForm( { attributes, setAttributes } ) {
		const blockProps = useBlockProps( {
			className: 'hm-registration-form hm-registration-form--editor',
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
							'Registration form settings',
							'hm-user-activation'
						) }
						initialOpen={ true }
					>
						<ToggleControl
							label={ __(
								'Ask for a username',
								'hm-user-activation'
							) }
							help={ __(
								'When off, a username is made from the email address.',
								'hm-user-activation'
							) }
							checked={ attributes.showUsername }
							onChange={ ( value ) =>
								setAttributes( { showUsername: value } )
							}
						/>
						<ToggleControl
							label={ __(
								'Show "confirmation will be emailed to you" notice',
								'hm-user-activation'
							) }
							checked={ attributes.showEmailNotice }
							onChange={ ( value ) =>
								setAttributes( { showEmailNotice: value } )
							}
						/>
						<ToggleControl
							label={ __(
								'Show log in link',
								'hm-user-activation'
							) }
							help={ __(
								'Links to the log in page configured in Settings → User Activation.',
								'hm-user-activation'
							) }
							checked={ attributes.showLoginLink }
							onChange={ ( value ) =>
								setAttributes( { showLoginLink: value } )
							}
						/>
					</PanelBody>
				</InspectorControls>
				<div { ...blockProps }>
					<p style={ { fontWeight: 600, marginTop: 0 } }>
						{ __( 'Registration Form', 'hm-user-activation' ) }
					</p>
					<p
						style={ {
							color: '#6b7280',
							fontSize: '0.875em',
							marginBottom: 0,
						} }
					>
						{ attributes.showUsername
							? __(
									'Renders username and email fields. On submission the activation email is sent. Hidden once registration has succeeded, and for logged-in users.',
									'hm-user-activation'
							  )
							: __(
									'Renders an email field. On submission the activation email is sent. Hidden once registration has succeeded, and for logged-in users.',
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
