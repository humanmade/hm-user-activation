/**
 * hm-user-activation/form — editor view.
 *
 * The block is server-side rendered on the frontend; the editor shows a representative placeholder.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';
import './style.css';

registerBlockType( metadata.name, {
	edit: function EditActivationForm() {
		const blockProps = useBlockProps( {
			className: 'hm-activation-form hm-activation-form--editor',
			style: {
				border: '2px dashed #c8d0db',
				padding: '1.5em',
				background: '#f6f7f7',
			},
		} );

		return (
			<div { ...blockProps }>
				<p style={ { fontWeight: 600, marginTop: 0 } }>
					{ __( 'Activation Form', 'hm-user-activation' ) }
				</p>
				<p
					style={ {
						color: '#6b7280',
						fontSize: '0.875em',
						marginBottom: 0,
					} }
				>
					{ __(
						'Renders the activation key input, and a submit button. Hidden when activation has already succeeded.',
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
