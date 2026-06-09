/**
 * NV oOS Chat SPA — Gutenberg Block Editor
 *
 * Minimal editor script that registers the block with a clean placeholder
 * so it appears in the Gutenberg block inserter and inspector.
 *
 * The SPA itself is too heavy for the editor — we show a simple preview
 * instead, rendered server-side by the render_callback.
 *
 * @since 0.7.0
 */

( function ( wp ) {
	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor
		? wp.blockEditor.InspectorControls
		: null;
	var __ = wp.i18n.__;

	var blockJson = {
		apiVersion: 2,
		name: 'nvoos/chat-spa',
		title: __( 'NV oOS Chat', 'nvoos-chat-spa' ),
		category: 'widgets',
		icon: 'format-chat',
		description: __(
			'Embeds the NV oOS React chat SPA.',
			'nvoos-chat-spa'
		),
		textdomain: 'nvoos-chat-spa',
		attributes: {
			assistant_id: { type: 'integer', default: 0 },
			theme: { type: 'string', default: 'auto' },
			height: { type: 'string', default: '' },
			guest: { type: 'boolean', default: false },
		},
		supports: { html: false },
	};

	registerBlockType( blockJson.name, {
		apiVersion: blockJson.apiVersion,
		title: blockJson.title,
		category: blockJson.category,
		icon: blockJson.icon,
		description: blockJson.description,
		attributes: blockJson.attributes,
		supports: blockJson.supports,
		edit: function ( props ) {
			var assistantId = props.attributes.assistant_id;
			var placeholderText = assistantId
				? __(
					'NV oOS Chat SPA — Assistant #%d. The chat will appear on the front end.',
					'nvoos-chat-spa'
				).replace( '%d', assistantId )
				: __(
					'NV oOS Chat SPA — Set an Assistant ID in the block sidebar. The chat will appear on the front end.',
					'nvoos-chat-spa'
				);

			var controls = InspectorControls
				? el( InspectorControls, null, el( 'p', null, placeholderText ) )
				: null;

			return el(
				'div',
				{
					className: 'nvoos-chat-spa-block-placeholder',
					style: {
						padding: '20px',
						background: '#f0f0f1',
						border: '1px dashed #c3c4c7',
						borderRadius: '4px',
						textAlign: 'center',
						color: '#3c434a',
					},
				},
				el( 'span', {
					className: 'dashicons dashicons-format-chat',
					style: {
						fontSize: '24px',
						width: '24px',
						height: '24px',
						marginBottom: '8px',
						display: 'inline-block',
					},
				} ),
				el( 'p', { style: { margin: '8px 0 0' } }, placeholderText ),
				controls
			);
		},
		save: function () {
			return null; // Dynamic block — server-rendered.
		},
	} );
} )( window.wp );
