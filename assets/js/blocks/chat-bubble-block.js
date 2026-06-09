/**
 * WP MCP AI Chat Bubble Block for Gutenberg
 *
 * Registers the floating chat bubble block in the Gutenberg editor.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

( function ( blocks, element, blockEditor, components, i18n ) {
	'use strict';

	const el = element.createElement;
	const __ = i18n.__;
	const InspectorControls = blockEditor.InspectorControls;
	const PanelBody = components.PanelBody;
	const SelectControl = components.SelectControl;
	const ToggleControl = components.ToggleControl;
	const TextControl = components.TextControl;
	const RangeControl = components.RangeControl;

	// Get localized data.
	const data = window.wpMcpAiBlocks || {};
	const assistants = data.assistants || [];

	/**
	 * Build assistant options for SelectControl.
	 *
	 * @return {Array} Options array.
	 */
	function getAssistantOptions() {
		const options = [ { value: 0, label: '— Select an assistant —' } ];
		assistants.forEach( function ( assistant ) {
			options.push( { value: assistant.id, label: assistant.title } );
		} );
		return options;
	}

	blocks.registerBlockType( 'mcp-ai-wpoos/chat-bubble', {
		apiVersion: 3,
		title: __( 'AI Chat Bubble', 'mcp-ai-wpoos' ),
		description: __( 'Display a floating chat bubble powered by WP oOS.', 'mcp-ai-wpoos' ),
		icon: 'format-chat',
		category: 'mcp-ai-wpoos',
		keywords: [ 'ai', 'chat', 'bubble', 'floating', 'assistant' ],
		attributes: {
			assistantId: { type: 'number', default: 0 },
			allowGuests: { type: 'boolean', default: false },
			saveTranscript: { type: 'boolean', default: true },
			enableStreaming: { type: 'boolean', default: true },
			allowSensitiveTools: { type: 'boolean', default: false },
			template: { type: 'string', default: 'compact' },
			bubblePosition: { type: 'string', default: 'bottom-right' },
			bubbleSize: { type: 'string', default: 'medium' },
			bubbleAnimation: { type: 'string', default: 'bounce' },
			bubbleTooltip: { type: 'string', default: '' },
			notificationBadge: { type: 'boolean', default: false },
			autoOpenDelay: { type: 'number', default: 0 },
			rememberState: { type: 'boolean', default: false },
			panelTitle: { type: 'string', default: 'Chat with AI' },
			panelWidth: { type: 'number', default: 400 },
			panelHeight: { type: 'number', default: 550 },
			bubbleColor: { type: 'string', default: '#4f46e5' },
			bubbleTextColor: { type: 'string', default: '#ffffff' },
			headerBackground: { type: 'string', default: '' },
			headerTextColor: { type: 'string', default: '#ffffff' }
		},
		supports: {
			anchor: true,
			html: false
		},
		edit: function ( props ) {
			const attributes = props.attributes;
			const setAttributes = props.setAttributes;

			const selectedAssistant = assistants.find( function ( a ) {
				return a.id === attributes.assistantId;
			} );

			return el( element.Fragment, {},
				el( InspectorControls, {},

					// Chat Settings.
					el( PanelBody, { title: __( 'Chat Settings', 'mcp-ai-wpoos' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Assistant', 'mcp-ai-wpoos' ),
							value: attributes.assistantId,
							options: getAssistantOptions(),
							onChange: function ( val ) {
								setAttributes( { assistantId: parseInt( val, 10 ) } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Allow Guests', 'mcp-ai-wpoos' ),
							help: __( 'Allow non-logged-in users to use the chat.', 'mcp-ai-wpoos' ),
							checked: attributes.allowGuests,
							onChange: function ( val ) {
								setAttributes( { allowGuests: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Save Transcript', 'mcp-ai-wpoos' ),
							checked: attributes.saveTranscript,
							onChange: function ( val ) {
								setAttributes( { saveTranscript: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Enable Streaming', 'mcp-ai-wpoos' ),
							checked: attributes.enableStreaming,
							onChange: function ( val ) {
								setAttributes( { enableStreaming: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Allow Sensitive Tools', 'mcp-ai-wpoos' ),
							help: __( 'Allow tools that modify content.', 'mcp-ai-wpoos' ),
							checked: attributes.allowSensitiveTools,
							onChange: function ( val ) {
								setAttributes( { allowSensitiveTools: val } );
							}
						} ),
						el( SelectControl, {
							label: __( 'Chat Template', 'mcp-ai-wpoos' ),
							value: attributes.template || 'classic',
							options: [
								{ label: __( 'Classic', 'mcp-ai-wpoos' ), value: 'classic' },
								{ label: __( 'Speech Bubbles', 'mcp-ai-wpoos' ), value: 'speech-bubbles' },
								{ label: __( 'Compact', 'mcp-ai-wpoos' ), value: 'compact' },
								{ label: __( 'Sidebar', 'mcp-ai-wpoos' ), value: 'sidebar' }
							],
							onChange: function ( val ) {
								setAttributes( { template: val } );
							}
						} )
					),

					// Bubble Settings.
					el( PanelBody, { title: __( 'Bubble Settings', 'mcp-ai-wpoos' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Position', 'mcp-ai-wpoos' ),
							value: attributes.bubblePosition,
							options: [
								{ label: __( 'Bottom Right', 'mcp-ai-wpoos' ), value: 'bottom-right' },
								{ label: __( 'Bottom Left', 'mcp-ai-wpoos' ), value: 'bottom-left' },
								{ label: __( 'Top Right', 'mcp-ai-wpoos' ), value: 'top-right' },
								{ label: __( 'Top Left', 'mcp-ai-wpoos' ), value: 'top-left' }
							],
							onChange: function ( val ) {
								setAttributes( { bubblePosition: val } );
							}
						} ),
						el( SelectControl, {
							label: __( 'Size', 'mcp-ai-wpoos' ),
							value: attributes.bubbleSize,
							options: [
								{ label: __( 'Small', 'mcp-ai-wpoos' ), value: 'small' },
								{ label: __( 'Medium', 'mcp-ai-wpoos' ), value: 'medium' },
								{ label: __( 'Large', 'mcp-ai-wpoos' ), value: 'large' }
							],
							onChange: function ( val ) {
								setAttributes( { bubbleSize: val } );
							}
						} ),
						el( SelectControl, {
							label: __( 'Animation', 'mcp-ai-wpoos' ),
							value: attributes.bubbleAnimation,
							options: [
								{ label: __( 'Bounce', 'mcp-ai-wpoos' ), value: 'bounce' },
								{ label: __( 'Pulse', 'mcp-ai-wpoos' ), value: 'pulse' },
								{ label: __( 'None', 'mcp-ai-wpoos' ), value: 'none' }
							],
							onChange: function ( val ) {
								setAttributes( { bubbleAnimation: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Tooltip Text', 'mcp-ai-wpoos' ),
							value: attributes.bubbleTooltip,
							onChange: function ( val ) {
								setAttributes( { bubbleTooltip: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Notification Badge', 'mcp-ai-wpoos' ),
							help: __( 'Show a notification badge on the bubble.', 'mcp-ai-wpoos' ),
							checked: attributes.notificationBadge,
							onChange: function ( val ) {
								setAttributes( { notificationBadge: val } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Auto-Open Delay (seconds)', 'mcp-ai-wpoos' ),
							value: attributes.autoOpenDelay,
							min: 0,
							max: 60,
							onChange: function ( val ) {
								setAttributes( { autoOpenDelay: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Remember State', 'mcp-ai-wpoos' ),
							help: __( 'Remember open/closed state across page loads.', 'mcp-ai-wpoos' ),
							checked: attributes.rememberState,
							onChange: function ( val ) {
								setAttributes( { rememberState: val } );
							}
						} )
					),

					// Panel Settings.
					el( PanelBody, { title: __( 'Panel Settings', 'mcp-ai-wpoos' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Panel Title', 'mcp-ai-wpoos' ),
							value: attributes.panelTitle,
							onChange: function ( val ) {
								setAttributes( { panelTitle: val } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Panel Width (px)', 'mcp-ai-wpoos' ),
							value: attributes.panelWidth,
							min: 300,
							max: 600,
							onChange: function ( val ) {
								setAttributes( { panelWidth: val } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Panel Height (px)', 'mcp-ai-wpoos' ),
							value: attributes.panelHeight,
							min: 400,
							max: 800,
							onChange: function ( val ) {
								setAttributes( { panelHeight: val } );
							}
						} )
					),

					// Colors.
					el( PanelBody, { title: __( 'Colors', 'mcp-ai-wpoos' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Bubble Color', 'mcp-ai-wpoos' ),
							help: __( 'Hex color code (e.g. #4f46e5).', 'mcp-ai-wpoos' ),
							value: attributes.bubbleColor,
							onChange: function ( val ) {
								setAttributes( { bubbleColor: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Bubble Text Color', 'mcp-ai-wpoos' ),
							help: __( 'Hex color code (e.g. #ffffff).', 'mcp-ai-wpoos' ),
							value: attributes.bubbleTextColor,
							onChange: function ( val ) {
								setAttributes( { bubbleTextColor: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Header Background', 'mcp-ai-wpoos' ),
							help: __( 'Hex color code for the chat panel header.', 'mcp-ai-wpoos' ),
							value: attributes.headerBackground,
							onChange: function ( val ) {
								setAttributes( { headerBackground: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Header Text Color', 'mcp-ai-wpoos' ),
							help: __( 'Hex color code (e.g. #ffffff).', 'mcp-ai-wpoos' ),
							value: attributes.headerTextColor,
							onChange: function ( val ) {
								setAttributes( { headerTextColor: val } );
							}
						} )
					)
				),

				// Block preview.
				el( 'div', { className: 'wp-mcp-ai-block-preview wp-mcp-ai-chat-bubble-block-preview' },
					el( 'div', { style: { display: 'flex', alignItems: 'center', gap: '12px' } },
						el( 'span', {
							style: {
								width: '48px',
								height: '48px',
								borderRadius: '50%',
								background: attributes.bubbleColor || '#4f46e5',
								display: 'flex',
								alignItems: 'center',
								justifyContent: 'center',
								color: attributes.bubbleTextColor || '#fff',
								fontSize: '20px'
							}
						}, '💬' ),
						el( 'div', {},
							el( 'strong', {}, __( 'AI Chat Bubble', 'mcp-ai-wpoos' ) ),
							el( 'br' ),
							el( 'small', {}, attributes.bubblePosition + ' · ' + attributes.bubbleSize ),
							selectedAssistant ? el( 'small', {}, ' · ' + selectedAssistant.title ) : null
						)
					),
					el( 'p', { style: { marginTop: '8px', opacity: 0.6, fontSize: '12px' } },
						__( 'A floating chat bubble will appear on the frontend.', 'mcp-ai-wpoos' )
					)
				)
			);
		},
		save: function () {
			// Dynamic block - rendered via PHP.
			return null;
		}
	} );
} ( wp.blocks, wp.element, wp.blockEditor, wp.components, wp.i18n ) );
