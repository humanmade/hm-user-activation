/**
 * hm-user-activation/password-reset — editor view.
 *
 * The block is server-side rendered on the frontend; the editor shows a representative placeholder.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';
import './style.css';

registerBlockType( metadata.name, {
	edit: function EditPasswordResetForm() {
		const blockProps = useBlockProps( {
			className: 'hm-password-reset hm-password-reset--editor',
			style: {
				border: '2px dashed #c8d0db',
				padding: '1.5em',
				background: '#f6f7f7',
			},
		} );

		return (
			<div { ...blockProps }>
				<p style={ { fontWeight: 600, marginTop: 0 } }>
					{ __( 'Password Reset Form', 'hm-user-activation' ) }
				</p>
				<p
					style={ {
						color: '#6b7280',
						fontSize: '0.875em',
						marginBottom: 0,
					} }
				>
					{ __(
						'Shows a "request reset link" form, or a "set new password" form when a valid key is in the URL.',
						'hm-user-activation'
					) }
				</p>
			</div>
		);
	},

	save() {
		return null;
	},
} );
