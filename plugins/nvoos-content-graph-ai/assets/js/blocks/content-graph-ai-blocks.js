/**
 * NV oOS Content Graph AI — Block editor registrations.
 *
 * Registers the server-rendered chat-family blocks in the Gutenberg
 * inserter (nvoos-content-graph-ai/chat and nvoos-content-graph-ai/
 * chat-bubble). Rendering is server-side; the edit components show a
 * preview card with the block's key attributes.
 *
 * @since 1.1.0
 */
(function (wp) {
	'use strict';

	const el = wp.element.createElement;
	const registerBlockType = wp.blocks.registerBlockType;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const ToggleControl = wp.components.ToggleControl;
	const TextControl = wp.components.TextControl;
	const SelectControl = wp.components.SelectControl;
	const RangeControl = wp.components.RangeControl;
	const __ = wp.i18n.__;

	/**
	 * Preview card for the server-rendered blocks.
	 *
	 * @param {Object} props Block props.
	 * @return {Object} Element.
	 */
	function PreviewCard(props) {
		return el(
			'div',
			{ className: 'nvoos-cg-block-preview' },
			el('span', { className: 'dashicons dashicons-format-chat' }),
			el('span', null, props.title || __('AI chat surface', 'nvoos-content-graph-ai'))
		);
	}

	// ─── Chat block ─────────────────────────────────────────────────

	registerBlockType('nvoos-content-graph-ai/chat', {
		apiVersion: 3,
		title: __('AI Chat', 'nvoos-content-graph-ai'),
		icon: 'format-chat',
		category: 'nvoos-content-graph-ai',
		description: __('Display an AI chat interface powered by NV oOS Content Graph.', 'nvoos-content-graph-ai'),
		keywords: ['ai', 'chat', 'assistant', 'conversation', 'graph'],
		attributes: {
			assistantId: { type: 'number', default: 0 },
			allowGuests: { type: 'boolean', default: false },
			provider: { type: 'string', default: '' },
			model: { type: 'string', default: '' },
			height: { type: 'string', default: '500px' },
			showCost: { type: 'boolean', default: true },
			placeholder: { type: 'string', default: '' },
		},
		supports: { anchor: true, html: false },
		edit: function (props) {
			const attributes = props.attributes;
			const setAttributes = props.setAttributes;

			return el(
				'div',
				{ className: 'nvoos-cg-block-preview-wrap' },
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Chat settings', 'nvoos-content-graph-ai'), initialOpen: true },
						el(ToggleControl, {
							label: __('Allow guests', 'nvoos-content-graph-ai'),
							checked: attributes.allowGuests,
							onChange: function (value) {
								setAttributes({ allowGuests: value });
							},
						}),
						el(ToggleControl, {
							label: __('Show cost', 'nvoos-content-graph-ai'),
							checked: attributes.showCost,
							onChange: function (value) {
								setAttributes({ showCost: value });
							},
						}),
						el(TextControl, {
							label: __('Provider (optional)', 'nvoos-content-graph-ai'),
							value: attributes.provider,
							onChange: function (value) {
								setAttributes({ provider: value });
							},
						}),
						el(TextControl, {
							label: __('Model (optional)', 'nvoos-content-graph-ai'),
							value: attributes.model,
							onChange: function (value) {
								setAttributes({ model: value });
							},
						}),
						el(TextControl, {
							label: __('Placeholder', 'nvoos-content-graph-ai'),
							value: attributes.placeholder,
							onChange: function (value) {
								setAttributes({ placeholder: value });
							},
						})
					)
				),
				el(PreviewCard, { title: __('AI Chat', 'nvoos-content-graph-ai') })
			);
		},
		save: function () {
			return null; // Server-rendered.
		},
	});

	// ─── Chat bubble block ──────────────────────────────────────────

	registerBlockType('nvoos-content-graph-ai/chat-bubble', {
		apiVersion: 3,
		title: __('AI Chat Bubble', 'nvoos-content-graph-ai'),
		icon: 'format-status',
		category: 'nvoos-content-graph-ai',
		description: __('Display a floating chat bubble that opens an AI chat panel.', 'nvoos-content-graph-ai'),
		keywords: ['ai', 'chat', 'bubble', 'floating', 'widget'],
		attributes: {
			assistantId: { type: 'number', default: 0 },
			allowGuests: { type: 'boolean', default: false },
			bubblePosition: { type: 'string', default: 'bottom-right' },
			bubbleSize: { type: 'string', default: 'medium' },
			bubbleAnimation: { type: 'string', default: 'bounce' },
			bubbleTooltip: { type: 'string', default: '' },
			panelTitle: { type: 'string', default: 'Chat with AI' },
			panelWidth: { type: 'number', default: 400 },
			panelHeight: { type: 'number', default: 550 },
			autoOpenDelay: { type: 'number', default: 0 },
			rememberState: { type: 'boolean', default: false },
			notificationBadge: { type: 'boolean', default: false },
			bubbleColor: { type: 'string', default: '#4f46e5' },
			bubbleTextColor: { type: 'string', default: '#ffffff' },
			headerBackground: { type: 'string', default: '' },
			headerTextColor: { type: 'string', default: '#ffffff' },
		},
		supports: { anchor: true, html: false },
		edit: function (props) {
			const attributes = props.attributes;
			const setAttributes = props.setAttributes;

			return el(
				'div',
				{ className: 'nvoos-cg-block-preview-wrap' },
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Bubble appearance', 'nvoos-content-graph-ai'), initialOpen: true },
						el(SelectControl, {
							label: __('Position', 'nvoos-content-graph-ai'),
							value: attributes.bubblePosition,
							options: [
								{ label: __('Bottom right', 'nvoos-content-graph-ai'), value: 'bottom-right' },
								{ label: __('Bottom left', 'nvoos-content-graph-ai'), value: 'bottom-left' },
								{ label: __('Top right', 'nvoos-content-graph-ai'), value: 'top-right' },
								{ label: __('Top left', 'nvoos-content-graph-ai'), value: 'top-left' },
							],
							onChange: function (value) {
								setAttributes({ bubblePosition: value });
							},
						}),
						el(SelectControl, {
							label: __('Size', 'nvoos-content-graph-ai'),
							value: attributes.bubbleSize,
							options: [
								{ label: __('Small', 'nvoos-content-graph-ai'), value: 'small' },
								{ label: __('Medium', 'nvoos-content-graph-ai'), value: 'medium' },
								{ label: __('Large', 'nvoos-content-graph-ai'), value: 'large' },
							],
							onChange: function (value) {
								setAttributes({ bubbleSize: value });
							},
						}),
						el(SelectControl, {
							label: __('Animation', 'nvoos-content-graph-ai'),
							value: attributes.bubbleAnimation,
							options: [
								{ label: __('Bounce', 'nvoos-content-graph-ai'), value: 'bounce' },
								{ label: __('Pulse', 'nvoos-content-graph-ai'), value: 'pulse' },
								{ label: __('None', 'nvoos-content-graph-ai'), value: 'none' },
							],
							onChange: function (value) {
								setAttributes({ bubbleAnimation: value });
							},
						}),
						el(TextControl, {
							label: __('Panel title', 'nvoos-content-graph-ai'),
							value: attributes.panelTitle,
							onChange: function (value) {
								setAttributes({ panelTitle: value });
							},
						}),
						el(RangeControl, {
							label: __('Panel width (px)', 'nvoos-content-graph-ai'),
							value: attributes.panelWidth,
							min: 260,
							max: 800,
							onChange: function (value) {
								setAttributes({ panelWidth: value });
							},
						}),
						el(RangeControl, {
							label: __('Panel height (px)', 'nvoos-content-graph-ai'),
							value: attributes.panelHeight,
							min: 320,
							max: 900,
							onChange: function (value) {
								setAttributes({ panelHeight: value });
							},
						})
					),
					el(
						PanelBody,
						{ title: __('Behaviour', 'nvoos-content-graph-ai'), initialOpen: false },
						el(ToggleControl, {
							label: __('Allow guests', 'nvoos-content-graph-ai'),
							checked: attributes.allowGuests,
							onChange: function (value) {
								setAttributes({ allowGuests: value });
							},
						}),
						el(ToggleControl, {
							label: __('Remember open state', 'nvoos-content-graph-ai'),
							checked: attributes.rememberState,
							onChange: function (value) {
								setAttributes({ rememberState: value });
							},
						}),
						el(ToggleControl, {
							label: __('Notification badge', 'nvoos-content-graph-ai'),
							checked: attributes.notificationBadge,
							onChange: function (value) {
								setAttributes({ notificationBadge: value });
							},
						}),
						el(TextControl, {
							label: __('Auto-open delay (ms)', 'nvoos-content-graph-ai'),
							type: 'number',
							value: attributes.autoOpenDelay,
							onChange: function (value) {
								setAttributes({ autoOpenDelay: Number(value) || 0 });
							},
						})
					)
				),
				el(PreviewCard, { title: __('AI Chat Bubble', 'nvoos-content-graph-ai') })
			);
		},
		save: function () {
			return null; // Server-rendered.
		},
	});
})(window.wp);
