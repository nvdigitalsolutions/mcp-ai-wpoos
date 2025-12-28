/**
 * WP MCP AI Assistant Builder Blocks for Gutenberg
 *
 * Registers blocks for the AI Assistant Builder in the Gutenberg editor.
 *
 * @package WP_MCP_AI
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
	const toolGroups = data.toolGroups || [];
	const translations = data.i18n || {};

	/**
	 * Build assistant options for SelectControl.
	 *
	 * @return {Array} Options array.
	 */
	function getAssistantOptions() {
		const options = [ { value: 0, label: translations.selectAssistant || '— Select an assistant —' } ];
		assistants.forEach( function ( assistant ) {
			options.push( { value: assistant.id, label: assistant.title } );
		} );
		return options;
	}

	/**
	 * Format tool count display.
	 *
	 * @param {number} count Tool count.
	 * @return {string} Formatted string.
	 */
	function formatToolCount( count ) {
		return count + ' ' + ( translations.toolsSelected || 'tools selected' );
	}

	/* =========================================================================
	   Chat Block
	   ========================================================================= */

	blocks.registerBlockType( 'wp-mcp-ai/chat', {
		title: __( 'AI Chat', 'wp-mcp-ai' ),
		description: __( 'Display an AI chat interface powered by WP oOS.', 'wp-mcp-ai' ),
		icon: 'format-chat',
		category: 'wp-mcp-ai',
		keywords: [ 'ai', 'chat', 'assistant', 'conversation' ],
		attributes: {
			assistantId: { type: 'number', default: 0 },
			allowGuests: { type: 'boolean', default: false },
			saveTranscript: { type: 'boolean', default: true },
			enableStreaming: { type: 'boolean', default: true },
			allowSensitiveTools: { type: 'boolean', default: false },
			showBuildButton: { type: 'boolean', default: false },
			placeholder: { type: 'string', default: '' },
			template: { type: 'string', default: 'classic' }
		},
		supports: {
			align: [ 'wide', 'full' ],
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
					el( PanelBody, { title: __( 'Chat Settings', 'wp-mcp-ai' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Assistant', 'wp-mcp-ai' ),
							value: attributes.assistantId,
							options: getAssistantOptions(),
							onChange: function ( val ) {
								setAttributes( { assistantId: parseInt( val, 10 ) } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Allow Guests', 'wp-mcp-ai' ),
							help: __( 'Allow non-logged-in users to use the chat.', 'wp-mcp-ai' ),
							checked: attributes.allowGuests,
							onChange: function ( val ) {
								setAttributes( { allowGuests: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Save Transcript', 'wp-mcp-ai' ),
							checked: attributes.saveTranscript,
							onChange: function ( val ) {
								setAttributes( { saveTranscript: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Enable Streaming', 'wp-mcp-ai' ),
							checked: attributes.enableStreaming,
							onChange: function ( val ) {
								setAttributes( { enableStreaming: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Allow Sensitive Tools', 'wp-mcp-ai' ),
							help: __( 'Allow tools that modify content.', 'wp-mcp-ai' ),
							checked: attributes.allowSensitiveTools,
							onChange: function ( val ) {
								setAttributes( { allowSensitiveTools: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Build Button', 'wp-mcp-ai' ),
							help: __( 'Display a build button for assistant creation.', 'wp-mcp-ai' ),
							checked: attributes.showBuildButton,
							onChange: function ( val ) {
								setAttributes( { showBuildButton: val } );
							}
						} ),
						el( SelectControl, {
							label: __( 'Chat Template', 'wp-mcp-ai' ),
							value: attributes.template || 'classic',
							options: [
								{ label: __( 'Classic', 'wp-mcp-ai' ), value: 'classic' },
								{ label: __( 'Speech Bubbles', 'wp-mcp-ai' ), value: 'speech-bubbles' },
								{ label: __( 'Compact', 'wp-mcp-ai' ), value: 'compact' },
								{ label: __( 'Sidebar', 'wp-mcp-ai' ), value: 'sidebar' }
							],
							onChange: function ( val ) {
								setAttributes( { template: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Placeholder Text', 'wp-mcp-ai' ),
							value: attributes.placeholder,
							onChange: function ( val ) {
								setAttributes( { placeholder: val } );
							}
						} )
					)
				),
				el( 'div', { className: 'wp-mcp-ai-block-preview wp-mcp-ai-chat-block-preview' },
					el( 'p', {},
						el( 'strong', {}, __( 'AI Chat', 'wp-mcp-ai' ) ),
						selectedAssistant ? ' — ' + selectedAssistant.title : ''
					),
					el( 'p', {}, __( 'Chat interface will display on the frontend.', 'wp-mcp-ai' ) )
				)
			);
		},
		save: function () {
			// Dynamic block - rendered via PHP.
			return null;
		}
	} );

	/* =========================================================================
	   Assistant Selector Block
	   ========================================================================= */

	blocks.registerBlockType( 'wp-mcp-ai/assistant-selector', {
		title: __( 'Assistant Selector', 'wp-mcp-ai' ),
		description: __( 'A dropdown to select from available AI assistants.', 'wp-mcp-ai' ),
		icon: 'admin-users',
		category: 'wp-mcp-ai',
		keywords: [ 'ai', 'assistant', 'selector', 'dropdown' ],
		attributes: {
			defaultAssistantId: { type: 'number', default: 0 },
			label: { type: 'string', default: '' },
			showStartButton: { type: 'boolean', default: true },
			startButtonText: { type: 'string', default: '' }
		},
		supports: { anchor: true, html: false },
		edit: function ( props ) {
			const attributes = props.attributes;
			const setAttributes = props.setAttributes;

			return el( element.Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Selector Settings', 'wp-mcp-ai' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Default Assistant', 'wp-mcp-ai' ),
							value: attributes.defaultAssistantId,
							options: getAssistantOptions(),
							onChange: function ( val ) {
								setAttributes( { defaultAssistantId: parseInt( val, 10 ) } );
							}
						} ),
						el( TextControl, {
							label: __( 'Label', 'wp-mcp-ai' ),
							placeholder: __( 'Select an Assistant:', 'wp-mcp-ai' ),
							value: attributes.label,
							onChange: function ( val ) {
								setAttributes( { label: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Start Button', 'wp-mcp-ai' ),
							checked: attributes.showStartButton,
							onChange: function ( val ) {
								setAttributes( { showStartButton: val } );
							}
						} ),
						attributes.showStartButton && el( TextControl, {
							label: __( 'Start Button Text', 'wp-mcp-ai' ),
							placeholder: __( 'Start Chat', 'wp-mcp-ai' ),
							value: attributes.startButtonText,
							onChange: function ( val ) {
								setAttributes( { startButtonText: val } );
							}
						} )
					)
				),
				el( 'div', { className: 'wp-mcp-ai-block-preview' },
					el( 'label', {},
						attributes.label || translations.assistantSelector || __( 'Select an Assistant:', 'wp-mcp-ai' )
					),
					el( 'select', { disabled: true, style: { marginLeft: '10px', minWidth: '200px' } },
						el( 'option', {}, translations.selectAssistant || __( '— Select an assistant —', 'wp-mcp-ai' ) )
					),
					attributes.showStartButton && el( 'button', {
						className: 'button',
						disabled: true,
						style: { marginLeft: '10px' }
					}, attributes.startButtonText || __( 'Start Chat', 'wp-mcp-ai' ) )
				)
			);
		},
		save: function () {
			return null;
		}
	} );

	/* =========================================================================
	   Tools Grid Block
	   ========================================================================= */

	blocks.registerBlockType( 'wp-mcp-ai/tools-grid', {
		title: __( 'AI Tools Grid', 'wp-mcp-ai' ),
		description: __( 'Display a grid of available AI tools that users can enable/disable.', 'wp-mcp-ai' ),
		icon: 'admin-tools',
		category: 'wp-mcp-ai',
		keywords: [ 'ai', 'tools', 'grid', 'capabilities' ],
		attributes: {
			title: { type: 'string', default: '' },
			description: { type: 'string', default: '' },
			showDescriptions: { type: 'boolean', default: true },
			startCollapsed: { type: 'boolean', default: true },
			showActions: { type: 'boolean', default: true },
			selectedTools: { type: 'array', default: [] }
		},
		supports: { anchor: true, html: false },
		edit: function ( props ) {
			const attributes = props.attributes;
			const setAttributes = props.setAttributes;

			// Count total tools.
			const totalTools = toolGroups.reduce( function ( sum, group ) {
				return sum + group.tools.length;
			}, 0 );

			return el( element.Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Grid Settings', 'wp-mcp-ai' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Title', 'wp-mcp-ai' ),
							placeholder: __( 'Available Tools', 'wp-mcp-ai' ),
							value: attributes.title,
							onChange: function ( val ) {
								setAttributes( { title: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Description', 'wp-mcp-ai' ),
							value: attributes.description,
							onChange: function ( val ) {
								setAttributes( { description: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Tool Descriptions', 'wp-mcp-ai' ),
							checked: attributes.showDescriptions,
							onChange: function ( val ) {
								setAttributes( { showDescriptions: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Start Collapsed', 'wp-mcp-ai' ),
							checked: attributes.startCollapsed,
							onChange: function ( val ) {
								setAttributes( { startCollapsed: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Select/Deselect All', 'wp-mcp-ai' ),
							checked: attributes.showActions,
							onChange: function ( val ) {
								setAttributes( { showActions: val } );
							}
						} )
					)
				),
				el( 'div', { className: 'wp-mcp-ai-block-preview wp-mcp-ai-tools-grid-preview' },
					el( 'p', {},
						el( 'strong', {}, attributes.title || translations.availableTools || __( 'Available Tools', 'wp-mcp-ai' ) )
					),
					el( 'p', {}, totalTools + ' ' + __( 'tools in', 'wp-mcp-ai' ) + ' ' + toolGroups.length + ' ' + __( 'groups', 'wp-mcp-ai' ) ),
					attributes.selectedTools.length > 0 && el( 'p', {}, formatToolCount( attributes.selectedTools.length ) )
				)
			);
		},
		save: function () {
			return null;
		}
	} );

	/* =========================================================================
	   Knowledge Base Block
	   ========================================================================= */

	blocks.registerBlockType( 'wp-mcp-ai/knowledge-base', {
		title: __( 'Knowledge Base Upload', 'wp-mcp-ai' ),
		description: __( 'Upload files to include in an AI assistant\'s knowledge base.', 'wp-mcp-ai' ),
		icon: 'media-document',
		category: 'wp-mcp-ai',
		keywords: [ 'ai', 'knowledge', 'upload', 'files', 'documents' ],
		attributes: {
			title: { type: 'string', default: '' },
			description: { type: 'string', default: '' },
			allowedTypes: { type: 'string', default: '.pdf,.txt,.md,.doc,.docx,.csv,.json' },
			maxFiles: { type: 'number', default: 10 },
			maxFileSizeMB: { type: 'number', default: 10 },
			showPreview: { type: 'boolean', default: true },
			uploadedFileIds: { type: 'array', default: [] }
		},
		supports: { anchor: true, html: false },
		edit: function ( props ) {
			const attributes = props.attributes;
			const setAttributes = props.setAttributes;

			return el( element.Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Knowledge Base Settings', 'wp-mcp-ai' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Title', 'wp-mcp-ai' ),
							placeholder: __( 'Knowledge Base', 'wp-mcp-ai' ),
							value: attributes.title,
							onChange: function ( val ) {
								setAttributes( { title: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Description', 'wp-mcp-ai' ),
							value: attributes.description,
							onChange: function ( val ) {
								setAttributes( { description: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Allowed File Types', 'wp-mcp-ai' ),
							help: __( 'Comma-separated list of file extensions.', 'wp-mcp-ai' ),
							value: attributes.allowedTypes,
							onChange: function ( val ) {
								setAttributes( { allowedTypes: val } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Max Files', 'wp-mcp-ai' ),
							value: attributes.maxFiles,
							min: 1,
							max: 50,
							onChange: function ( val ) {
								setAttributes( { maxFiles: val } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Max File Size (MB)', 'wp-mcp-ai' ),
							value: attributes.maxFileSizeMB,
							min: 1,
							max: 100,
							onChange: function ( val ) {
								setAttributes( { maxFileSizeMB: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show File Preview', 'wp-mcp-ai' ),
							checked: attributes.showPreview,
							onChange: function ( val ) {
								setAttributes( { showPreview: val } );
							}
						} )
					)
				),
				el( 'div', { className: 'wp-mcp-ai-block-preview' },
					el( 'p', {},
						el( 'strong', {}, attributes.title || translations.knowledgeBase || __( 'Knowledge Base', 'wp-mcp-ai' ) )
					),
					el( 'p', {},
						__( 'Drop files here or click to upload', 'wp-mcp-ai' )
					),
					el( 'p', { style: { fontSize: '12px', color: '#666' } },
						__( 'Max', 'wp-mcp-ai' ) + ' ' + attributes.maxFiles + ' ' + __( 'files', 'wp-mcp-ai' ) + ', ' +
						attributes.maxFileSizeMB + 'MB ' + __( 'each', 'wp-mcp-ai' )
					)
				)
			);
		},
		save: function () {
			return null;
		}
	} );

	/* =========================================================================
	   Assistant Builder Block
	   ========================================================================= */

	blocks.registerBlockType( 'wp-mcp-ai/assistant-builder', {
		title: __( 'AI Assistant Builder', 'wp-mcp-ai' ),
		description: __( 'A complete interface for building new AI assistants.', 'wp-mcp-ai' ),
		icon: 'hammer',
		category: 'wp-mcp-ai',
		keywords: [ 'ai', 'assistant', 'builder', 'create', 'tools', 'chat' ],
		attributes: {
			showAssistantSelector: { type: 'boolean', default: true },
			showToolsGrid: { type: 'boolean', default: true },
			showKnowledgeBase: { type: 'boolean', default: true },
			showBuildButton: { type: 'boolean', default: true },
			defaultAssistantId: { type: 'number', default: 0 },
			layout: { type: 'string', default: 'stacked' },
			toolsCollapsed: { type: 'boolean', default: true },
			showToolDescriptions: { type: 'boolean', default: true },
			enableStreaming: { type: 'boolean', default: true },
			chatPlaceholder: { type: 'string', default: '' },
			allowedFileTypes: { type: 'string', default: '.pdf,.txt,.md,.doc,.docx,.csv,.json' },
			maxFiles: { type: 'number', default: 10 },
			maxFileSizeMB: { type: 'number', default: 10 }
		},
		supports: {
			align: [ 'wide', 'full' ],
			anchor: true,
			html: false
		},
		edit: function ( props ) {
			const attributes = props.attributes;
			const setAttributes = props.setAttributes;

			return el( element.Fragment, {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Layout', 'wp-mcp-ai' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Layout Style', 'wp-mcp-ai' ),
							value: attributes.layout,
							options: [
								{ value: 'stacked', label: __( 'Stacked', 'wp-mcp-ai' ) },
								{ value: 'side-by-side', label: __( 'Side by Side', 'wp-mcp-ai' ) }
							],
							onChange: function ( val ) {
								setAttributes( { layout: val } );
							}
						} )
					),
					el( PanelBody, { title: __( 'Components', 'wp-mcp-ai' ), initialOpen: true },
						el( ToggleControl, {
							label: __( 'Show Assistant Selector', 'wp-mcp-ai' ),
							checked: attributes.showAssistantSelector,
							onChange: function ( val ) {
								setAttributes( { showAssistantSelector: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Tools Grid', 'wp-mcp-ai' ),
							checked: attributes.showToolsGrid,
							onChange: function ( val ) {
								setAttributes( { showToolsGrid: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Knowledge Base', 'wp-mcp-ai' ),
							checked: attributes.showKnowledgeBase,
							onChange: function ( val ) {
								setAttributes( { showKnowledgeBase: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Build Button', 'wp-mcp-ai' ),
							checked: attributes.showBuildButton,
							onChange: function ( val ) {
								setAttributes( { showBuildButton: val } );
							}
						} )
					),
					el( PanelBody, { title: __( 'Assistant Selector', 'wp-mcp-ai' ), initialOpen: false },
						el( SelectControl, {
							label: __( 'Default Assistant', 'wp-mcp-ai' ),
							value: attributes.defaultAssistantId,
							options: getAssistantOptions(),
							onChange: function ( val ) {
								setAttributes( { defaultAssistantId: parseInt( val, 10 ) } );
							}
						} )
					),
					el( PanelBody, { title: __( 'Tools Grid', 'wp-mcp-ai' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Start Collapsed', 'wp-mcp-ai' ),
							checked: attributes.toolsCollapsed,
							onChange: function ( val ) {
								setAttributes( { toolsCollapsed: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Tool Descriptions', 'wp-mcp-ai' ),
							checked: attributes.showToolDescriptions,
							onChange: function ( val ) {
								setAttributes( { showToolDescriptions: val } );
							}
						} )
					),
					el( PanelBody, { title: __( 'Chat', 'wp-mcp-ai' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Enable Streaming', 'wp-mcp-ai' ),
							checked: attributes.enableStreaming,
							onChange: function ( val ) {
								setAttributes( { enableStreaming: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Chat Placeholder', 'wp-mcp-ai' ),
							placeholder: translations.chatPlaceholder || __( 'Describe the assistant you want to create...', 'wp-mcp-ai' ),
							value: attributes.chatPlaceholder,
							onChange: function ( val ) {
								setAttributes( { chatPlaceholder: val } );
							}
						} )
					),
					el( PanelBody, { title: __( 'Knowledge Base', 'wp-mcp-ai' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Allowed File Types', 'wp-mcp-ai' ),
							value: attributes.allowedFileTypes,
							onChange: function ( val ) {
								setAttributes( { allowedFileTypes: val } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Max Files', 'wp-mcp-ai' ),
							value: attributes.maxFiles,
							min: 1,
							max: 50,
							onChange: function ( val ) {
								setAttributes( { maxFiles: val } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Max File Size (MB)', 'wp-mcp-ai' ),
							value: attributes.maxFileSizeMB,
							min: 1,
							max: 100,
							onChange: function ( val ) {
								setAttributes( { maxFileSizeMB: val } );
							}
						} )
					)
				),
				el( 'div', { className: 'wp-mcp-ai-block-preview wp-mcp-ai-builder-preview' },
					el( 'p', {},
						el( 'strong', {}, __( 'AI Assistant Builder', 'wp-mcp-ai' ) ),
						' — ',
						attributes.layout === 'side-by-side' ? __( 'Side by Side', 'wp-mcp-ai' ) : __( 'Stacked', 'wp-mcp-ai' )
					),
					el( 'ul', {},
						attributes.showAssistantSelector && el( 'li', {}, '✓ ' + __( 'Assistant Selector', 'wp-mcp-ai' ) ),
						attributes.showToolsGrid && el( 'li', {}, '✓ ' + __( 'Tools Grid', 'wp-mcp-ai' ) ),
						attributes.showKnowledgeBase && el( 'li', {}, '✓ ' + __( 'Knowledge Base', 'wp-mcp-ai' ) ),
						attributes.showBuildButton && el( 'li', {}, '✓ ' + __( 'Build Button', 'wp-mcp-ai' ) )
					),
					el( 'p', { style: { color: '#666', fontSize: '12px' } },
						__( 'Complete builder interface will display on the frontend.', 'wp-mcp-ai' )
					)
				)
			);
		},
		save: function () {
			return null;
		}
	} );

}(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
) );
