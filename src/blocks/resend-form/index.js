/**
 * hm-user-activation/resend-form — editor view.
 *
 * The block is server-side rendered on the frontend; the editor shows a placeholder.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

registerBlockType( metadata.name, {
	edit: function EditResendForm() {
		const blockProps = useBlockProps( {
			className: 'hm-resend-form hm-resend-form--editor',
			style: {
				border: '2px dashed #c8d0db',
				padding: '1.5em',
				background: '#f6f7f7',
			},
		} );

		return (
			<div { ...blockProps }>
				<p style={ { fontWeight: 600, marginTop: 0 } }>
					{ __( 'Resend Activation Form', 'hm-user-activation' ) }
				</p>
				<p
					style={ {
						color: '#6b7280',
						fontSize: '0.875em',
						marginBottom: 0,
					} }
				>
					{ __(
						'Renders an email field so users can request a new activation link. Place it inside the Activation Errors group so expired links lead straight to it.',
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
