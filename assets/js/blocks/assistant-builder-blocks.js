/**
 * WP MCP AI Assistant Builder Blocks
 *
 * Gutenberg block editor components for the Assistant Builder.
 * These blocks can be used both in the block editor and reused in admin pages.
 *
 * @package WP_MCP_AI
 */

/* global wp, wpMcpAiBlocks */

( function() {
	'use strict';

	const registerBlockType = wp.blocks.registerBlockType;
	const createElement = wp.element.createElement;
	const Fragment = wp.element.Fragment;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const SelectControl = wp.components.SelectControl;
	const ToggleControl = wp.components.ToggleControl;
	const TextControl = wp.components.TextControl;
	const Placeholder = wp.components.Placeholder;
	const __ = wp.i18n.__;

	/**
	 * Get assistant options for select control.
	 *
	 * @return {Array} Options array.
	 */
	function getAssistantOptions() {
		const options = [ { label: wpMcpAiBlocks.i18n.selectAssistant, value: '' } ];

		if ( wpMcpAiBlocks.assistants && wpMcpAiBlocks.assistants.length > 0 ) {
			wpMcpAiBlocks.assistants.forEach( function( assistant ) {
				options.push( {
					label: assistant.title,
					value: String( assistant.id )
				} );
			} );
		}

		return options;
	}

	/**
	 * AI Chat Block
	 */
	registerBlockType( 'wp-mcp-ai/chat', {
		title: __( 'AI Chat', 'wp-mcp-ai' ),
		description: __( 'Display an AI chat interface powered by WP oOS.', 'wp-mcp-ai' ),
		icon: 'format-chat',
		category: 'widgets',
		keywords: [ 'ai', 'chat', 'assistant', 'mcp' ],
		supports: {
			align: [ 'wide', 'full' ],
			anchor: true
		},

		edit: function( props ) {
			const attributes = props.attributes;
			const setAttributes = props.setAttributes;

			return createElement(
				Fragment,
				null,
				createElement(
					InspectorControls,
					null,
					createElement(
						PanelBody,
						{ title: __( 'Chat Settings', 'wp-mcp-ai' ) },
						createElement( SelectControl, {
							label: __( 'Assistant', 'wp-mcp-ai' ),
							value: String( attributes.assistantId || '' ),
							options: getAssistantOptions(),
							onChange: function( value ) {
								setAttributes( { assistantId: parseInt( value, 10 ) || 0 } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Allow Guests', 'wp-mcp-ai' ),
							checked: attributes.allowGuests,
							onChange: function( value ) {
								setAttributes( { allowGuests: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Save Transcripts', 'wp-mcp-ai' ),
							checked: attributes.saveTranscript,
							onChange: function( value ) {
								setAttributes( { saveTranscript: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Enable Streaming', 'wp-mcp-ai' ),
							checked: attributes.enableStreaming,
							onChange: function( value ) {
								setAttributes( { enableStreaming: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Allow Sensitive Tools', 'wp-mcp-ai' ),
							checked: attributes.allowSensitiveTools,
							onChange: function( value ) {
								setAttributes( { allowSensitiveTools: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Show Build Button', 'wp-mcp-ai' ),
							checked: attributes.showBuildButton,
							onChange: function( value ) {
								setAttributes( { showBuildButton: value } );
							}
						} )
					)
				),
				createElement(
					Placeholder,
					{
						icon: 'format-chat',
						label: __( 'AI Chat', 'wp-mcp-ai' ),
						instructions: __( 'This block will display an AI chat interface on the frontend.', 'wp-mcp-ai' )
					},
					createElement(
						'div',
						{ className: 'wp-mcp-ai-chat-block-preview' },
						createElement( 'p', null, attributes.assistantId
							? __( 'Selected Assistant ID: ', 'wp-mcp-ai' ) + attributes.assistantId
							: __( 'Using default assistant', 'wp-mcp-ai' )
						)
					)
				)
			);
		},

		save: function() {
			// Dynamic block - rendered by PHP.
			return null;
		}
	} );

	/**
	 * Assistant Selector Block
	 */
	registerBlockType( 'wp-mcp-ai/assistant-selector', {
		title: __( 'Assistant Selector', 'wp-mcp-ai' ),
		description: __( 'A dropdown to select from available AI assistants.', 'wp-mcp-ai' ),
		icon: 'admin-users',
		category: 'widgets',
		keywords: [ 'ai', 'assistant', 'selector', 'dropdown' ],

		edit: function( props ) {
			const attributes = props.attributes;
			const setAttributes = props.setAttributes;

			return createElement(
				Fragment,
				null,
				createElement(
					InspectorControls,
					null,
					createElement(
						PanelBody,
						{ title: __( 'Selector Settings', 'wp-mcp-ai' ) },
						createElement( SelectControl, {
							label: __( 'Default Assistant', 'wp-mcp-ai' ),
							value: String( attributes.defaultAssistantId || '' ),
							options: getAssistantOptions(),
							onChange: function( value ) {
								setAttributes( { defaultAssistantId: parseInt( value, 10 ) || 0 } );
							}
						} ),
						createElement( TextControl, {
							label: __( 'Label', 'wp-mcp-ai' ),
							value: attributes.label || '',
							onChange: function( value ) {
								setAttributes( { label: value } );
							},
							placeholder: wpMcpAiBlocks.i18n.assistantSelector
						} ),
						createElement( ToggleControl, {
							label: __( 'Show Start Button', 'wp-mcp-ai' ),
							checked: attributes.showStartButton,
							onChange: function( value ) {
								setAttributes( { showStartButton: value } );
							}
						} ),
						createElement( TextControl, {
							label: __( 'Start Button Text', 'wp-mcp-ai' ),
							value: attributes.startButtonText || '',
							onChange: function( value ) {
								setAttributes( { startButtonText: value } );
							},
							placeholder: wpMcpAiBlocks.i18n.startChat
						} )
					)
				),
				createElement(
					'div',
					{ className: 'wp-block-wp-mcp-ai-assistant-selector wp-mcp-ai-block-preview' },
					createElement( 'label', null, attributes.label || wpMcpAiBlocks.i18n.assistantSelector ),
					createElement( SelectControl, {
						value: String( attributes.defaultAssistantId || '' ),
						options: getAssistantOptions(),
						onChange: function() {}
					} ),
					attributes.showStartButton && createElement(
						'button',
						{ className: 'button button-primary', disabled: true },
						attributes.startButtonText || wpMcpAiBlocks.i18n.startChat
					)
				)
			);
		},

		save: function() {
			return null;
		}
	} );

	/**
	 * Tools Grid Block
	 */
	registerBlockType( 'wp-mcp-ai/tools-grid', {
		title: __( 'Tools Grid', 'wp-mcp-ai' ),
		description: __( 'Display a grid of available AI tools that users can enable/disable.', 'wp-mcp-ai' ),
		icon: 'admin-tools',
		category: 'widgets',
		keywords: [ 'ai', 'tools', 'grid', 'capabilities' ],

		edit: function( props ) {
			const attributes = props.attributes;
			const setAttributes = props.setAttributes;
			const toolGroups = wpMcpAiBlocks.toolGroups || [];
			let totalTools = 0;

			toolGroups.forEach( function( group ) {
				totalTools += group.tools ? group.tools.length : 0;
			} );

			return createElement(
				Fragment,
				null,
				createElement(
					InspectorControls,
					null,
					createElement(
						PanelBody,
						{ title: __( 'Grid Settings', 'wp-mcp-ai' ) },
						createElement( TextControl, {
							label: __( 'Title', 'wp-mcp-ai' ),
							value: attributes.title || '',
							onChange: function( value ) {
								setAttributes( { title: value } );
							},
							placeholder: wpMcpAiBlocks.i18n.availableTools
						} ),
						createElement( TextControl, {
							label: __( 'Description', 'wp-mcp-ai' ),
							value: attributes.description || '',
							onChange: function( value ) {
								setAttributes( { description: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Show Tool Descriptions', 'wp-mcp-ai' ),
							checked: attributes.showDescriptions,
							onChange: function( value ) {
								setAttributes( { showDescriptions: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Start Collapsed', 'wp-mcp-ai' ),
							checked: attributes.startCollapsed,
							onChange: function( value ) {
								setAttributes( { startCollapsed: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Show Select All/Deselect All', 'wp-mcp-ai' ),
							checked: attributes.showActions,
							onChange: function( value ) {
								setAttributes( { showActions: value } );
							}
						} )
					)
				),
				createElement(
					Placeholder,
					{
						icon: 'admin-tools',
						label: attributes.title || wpMcpAiBlocks.i18n.availableTools,
						instructions: __( 'Displays a grid of AI tools organized by category.', 'wp-mcp-ai' )
					},
					createElement(
						'div',
						{ className: 'wp-mcp-ai-tools-grid-preview' },
						createElement( 'p', null,
							toolGroups.length + ' ' + __( 'tool groups', 'wp-mcp-ai' ) + ', ' +
							totalTools + ' ' + __( 'total tools', 'wp-mcp-ai' )
						)
					)
				)
			);
		},

		save: function() {
			return null;
		}
	} );

	/**
	 * Assistant Builder Block
	 */
	registerBlockType( 'wp-mcp-ai/assistant-builder', {
		title: __( 'Assistant Builder', 'wp-mcp-ai' ),
		description: __( 'A complete interface for building new AI assistants with chat, tools, and configuration.', 'wp-mcp-ai' ),
		icon: 'hammer',
		category: 'widgets',
		keywords: [ 'ai', 'assistant', 'builder', 'create', 'tools' ],
		supports: {
			align: [ 'wide', 'full' ],
			anchor: true
		},

		edit: function( props ) {
			const attributes = props.attributes;
			const setAttributes = props.setAttributes;

			return createElement(
				Fragment,
				null,
				createElement(
					InspectorControls,
					null,
					createElement(
						PanelBody,
						{ title: __( 'Builder Settings', 'wp-mcp-ai' ) },
						createElement( SelectControl, {
							label: __( 'Default Assistant', 'wp-mcp-ai' ),
							value: String( attributes.defaultAssistantId || '' ),
							options: getAssistantOptions(),
							onChange: function( value ) {
								setAttributes( { defaultAssistantId: parseInt( value, 10 ) || 0 } );
							}
						} ),
						createElement( SelectControl, {
							label: __( 'Layout', 'wp-mcp-ai' ),
							value: attributes.layout || 'stacked',
							options: [
								{ label: __( 'Stacked (Vertical)', 'wp-mcp-ai' ), value: 'stacked' },
								{ label: __( 'Side by Side', 'wp-mcp-ai' ), value: 'side-by-side' }
							],
							onChange: function( value ) {
								setAttributes( { layout: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Show Assistant Selector', 'wp-mcp-ai' ),
							checked: attributes.showAssistantSelector,
							onChange: function( value ) {
								setAttributes( { showAssistantSelector: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Show Tools Grid', 'wp-mcp-ai' ),
							checked: attributes.showToolsGrid,
							onChange: function( value ) {
								setAttributes( { showToolsGrid: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Show Build Button', 'wp-mcp-ai' ),
							checked: attributes.showBuildButton,
							onChange: function( value ) {
								setAttributes( { showBuildButton: value } );
							}
						} )
					),
					createElement(
						PanelBody,
						{ title: __( 'Tools Settings', 'wp-mcp-ai' ), initialOpen: false },
						createElement( ToggleControl, {
							label: __( 'Start Tools Collapsed', 'wp-mcp-ai' ),
							checked: attributes.toolsCollapsed,
							onChange: function( value ) {
								setAttributes( { toolsCollapsed: value } );
							}
						} ),
						createElement( ToggleControl, {
							label: __( 'Show Tool Descriptions', 'wp-mcp-ai' ),
							checked: attributes.showToolDescriptions,
							onChange: function( value ) {
								setAttributes( { showToolDescriptions: value } );
							}
						} )
					),
					createElement(
						PanelBody,
						{ title: __( 'Chat Settings', 'wp-mcp-ai' ), initialOpen: false },
						createElement( ToggleControl, {
							label: __( 'Enable Streaming', 'wp-mcp-ai' ),
							checked: attributes.enableStreaming,
							onChange: function( value ) {
								setAttributes( { enableStreaming: value } );
							}
						} ),
						createElement( TextControl, {
							label: __( 'Chat Placeholder', 'wp-mcp-ai' ),
							value: attributes.chatPlaceholder || '',
							onChange: function( value ) {
								setAttributes( { chatPlaceholder: value } );
							},
							placeholder: wpMcpAiBlocks.i18n.chatPlaceholder
						} )
					)
				),
				createElement(
					Placeholder,
					{
						icon: 'hammer',
						label: __( 'Assistant Builder', 'wp-mcp-ai' ),
						instructions: __( 'This block provides a complete interface for building new AI assistants.', 'wp-mcp-ai' )
					},
					createElement(
						'div',
						{ className: 'wp-mcp-ai-builder-preview' },
						createElement( 'ul', null,
							attributes.showAssistantSelector && createElement( 'li', null, '✓ ' + __( 'Assistant Selector', 'wp-mcp-ai' ) ),
							attributes.showToolsGrid && createElement( 'li', null, '✓ ' + __( 'Tools Grid', 'wp-mcp-ai' ) ),
							createElement( 'li', null, '✓ ' + __( 'Chat Interface', 'wp-mcp-ai' ) ),
							attributes.showBuildButton && createElement( 'li', null, '✓ ' + __( 'Build Button', 'wp-mcp-ai' ) )
						),
						createElement( 'p', null, __( 'Layout:', 'wp-mcp-ai' ) + ' ' + ( attributes.layout || 'stacked' ) )
					)
				)
			);
		},

		save: function() {
			return null;
		}
	} );

} )();
