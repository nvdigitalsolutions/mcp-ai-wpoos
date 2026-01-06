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

	blocks.registerBlockType( 'mcp-ai-wpoos/chat', {
		title: __( 'AI Chat', 'mcp-ai-wpoos' ),
		description: __( 'Display an AI chat interface powered by WP oOS.', 'mcp-ai-wpoos' ),
		icon: 'format-chat',
		category: 'mcp-ai-wpoos',
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
						el( ToggleControl, {
							label: __( 'Show Build Button', 'mcp-ai-wpoos' ),
							help: __( 'Display a build button for assistant creation.', 'mcp-ai-wpoos' ),
							checked: attributes.showBuildButton,
							onChange: function ( val ) {
								setAttributes( { showBuildButton: val } );
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
						} ),
						el( TextControl, {
							label: __( 'Placeholder Text', 'mcp-ai-wpoos' ),
							value: attributes.placeholder,
							onChange: function ( val ) {
								setAttributes( { placeholder: val } );
							}
						} )
					)
				),
				el( 'div', { className: 'wp-mcp-ai-block-preview wp-mcp-ai-chat-block-preview' },
					el( 'p', {},
						el( 'strong', {}, __( 'AI Chat', 'mcp-ai-wpoos' ) ),
						selectedAssistant ? ' — ' + selectedAssistant.title : ''
					),
					el( 'p', {}, __( 'Chat interface will display on the frontend.', 'mcp-ai-wpoos' ) )
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

	blocks.registerBlockType( 'mcp-ai-wpoos/assistant-selector', {
		title: __( 'Assistant Selector', 'mcp-ai-wpoos' ),
		description: __( 'A dropdown to select from available AI assistants.', 'mcp-ai-wpoos' ),
		icon: 'admin-users',
		category: 'mcp-ai-wpoos',
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
					el( PanelBody, { title: __( 'Selector Settings', 'mcp-ai-wpoos' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Default Assistant', 'mcp-ai-wpoos' ),
							value: attributes.defaultAssistantId,
							options: getAssistantOptions(),
							onChange: function ( val ) {
								setAttributes( { defaultAssistantId: parseInt( val, 10 ) } );
							}
						} ),
						el( TextControl, {
							label: __( 'Label', 'mcp-ai-wpoos' ),
							placeholder: __( 'Select an Assistant:', 'mcp-ai-wpoos' ),
							value: attributes.label,
							onChange: function ( val ) {
								setAttributes( { label: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Start Button', 'mcp-ai-wpoos' ),
							checked: attributes.showStartButton,
							onChange: function ( val ) {
								setAttributes( { showStartButton: val } );
							}
						} ),
						attributes.showStartButton && el( TextControl, {
							label: __( 'Start Button Text', 'mcp-ai-wpoos' ),
							placeholder: __( 'Start Chat', 'mcp-ai-wpoos' ),
							value: attributes.startButtonText,
							onChange: function ( val ) {
								setAttributes( { startButtonText: val } );
							}
						} )
					)
				),
				el( 'div', { className: 'wp-mcp-ai-block-preview' },
					el( 'label', {},
						attributes.label || translations.assistantSelector || __( 'Select an Assistant:', 'mcp-ai-wpoos' )
					),
					el( 'select', { disabled: true, style: { marginLeft: '10px', minWidth: '200px' } },
						el( 'option', {}, translations.selectAssistant || __( '— Select an assistant —', 'mcp-ai-wpoos' ) )
					),
					attributes.showStartButton && el( 'button', {
						className: 'button',
						disabled: true,
						style: { marginLeft: '10px' }
					}, attributes.startButtonText || __( 'Start Chat', 'mcp-ai-wpoos' ) )
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

	blocks.registerBlockType( 'mcp-ai-wpoos/tools-grid', {
		title: __( 'AI Tools Grid', 'mcp-ai-wpoos' ),
		description: __( 'Display a grid of available AI tools that users can enable/disable.', 'mcp-ai-wpoos' ),
		icon: 'admin-tools',
		category: 'mcp-ai-wpoos',
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
					el( PanelBody, { title: __( 'Grid Settings', 'mcp-ai-wpoos' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Title', 'mcp-ai-wpoos' ),
							placeholder: __( 'Available Tools', 'mcp-ai-wpoos' ),
							value: attributes.title,
							onChange: function ( val ) {
								setAttributes( { title: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Description', 'mcp-ai-wpoos' ),
							value: attributes.description,
							onChange: function ( val ) {
								setAttributes( { description: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Tool Descriptions', 'mcp-ai-wpoos' ),
							checked: attributes.showDescriptions,
							onChange: function ( val ) {
								setAttributes( { showDescriptions: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Start Collapsed', 'mcp-ai-wpoos' ),
							checked: attributes.startCollapsed,
							onChange: function ( val ) {
								setAttributes( { startCollapsed: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Select/Deselect All', 'mcp-ai-wpoos' ),
							checked: attributes.showActions,
							onChange: function ( val ) {
								setAttributes( { showActions: val } );
							}
						} )
					)
				),
				el( 'div', { className: 'wp-mcp-ai-block-preview wp-mcp-ai-tools-grid-preview' },
					el( 'p', {},
						el( 'strong', {}, attributes.title || translations.availableTools || __( 'Available Tools', 'mcp-ai-wpoos' ) )
					),
					el( 'p', {}, totalTools + ' ' + __( 'tools in', 'mcp-ai-wpoos' ) + ' ' + toolGroups.length + ' ' + __( 'groups', 'mcp-ai-wpoos' ) ),
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

	blocks.registerBlockType( 'mcp-ai-wpoos/knowledge-base', {
		title: __( 'Knowledge Base Upload', 'mcp-ai-wpoos' ),
		description: __( 'Upload files to include in an AI assistant\'s knowledge base.', 'mcp-ai-wpoos' ),
		icon: 'media-document',
		category: 'mcp-ai-wpoos',
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
					el( PanelBody, { title: __( 'Knowledge Base Settings', 'mcp-ai-wpoos' ), initialOpen: true },
						el( TextControl, {
							label: __( 'Title', 'mcp-ai-wpoos' ),
							placeholder: __( 'Knowledge Base', 'mcp-ai-wpoos' ),
							value: attributes.title,
							onChange: function ( val ) {
								setAttributes( { title: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Description', 'mcp-ai-wpoos' ),
							value: attributes.description,
							onChange: function ( val ) {
								setAttributes( { description: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Allowed File Types', 'mcp-ai-wpoos' ),
							help: __( 'Comma-separated list of file extensions.', 'mcp-ai-wpoos' ),
							value: attributes.allowedTypes,
							onChange: function ( val ) {
								setAttributes( { allowedTypes: val } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Max Files', 'mcp-ai-wpoos' ),
							value: attributes.maxFiles,
							min: 1,
							max: 50,
							onChange: function ( val ) {
								setAttributes( { maxFiles: val } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Max File Size (MB)', 'mcp-ai-wpoos' ),
							value: attributes.maxFileSizeMB,
							min: 1,
							max: 100,
							onChange: function ( val ) {
								setAttributes( { maxFileSizeMB: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show File Preview', 'mcp-ai-wpoos' ),
							checked: attributes.showPreview,
							onChange: function ( val ) {
								setAttributes( { showPreview: val } );
							}
						} )
					)
				),
				el( 'div', { className: 'wp-mcp-ai-block-preview' },
					el( 'p', {},
						el( 'strong', {}, attributes.title || translations.knowledgeBase || __( 'Knowledge Base', 'mcp-ai-wpoos' ) )
					),
					el( 'p', {},
						__( 'Drop files here or click to upload', 'mcp-ai-wpoos' )
					),
					el( 'p', { style: { fontSize: '12px', color: '#666' } },
						__( 'Max', 'mcp-ai-wpoos' ) + ' ' + attributes.maxFiles + ' ' + __( 'files', 'mcp-ai-wpoos' ) + ', ' +
						attributes.maxFileSizeMB + 'MB ' + __( 'each', 'mcp-ai-wpoos' )
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

	blocks.registerBlockType( 'mcp-ai-wpoos/assistant-builder', {
		title: __( 'AI Assistant Builder', 'mcp-ai-wpoos' ),
		description: __( 'A complete interface for building new AI assistants.', 'mcp-ai-wpoos' ),
		icon: 'hammer',
		category: 'mcp-ai-wpoos',
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
					el( PanelBody, { title: __( 'Layout', 'mcp-ai-wpoos' ), initialOpen: true },
						el( SelectControl, {
							label: __( 'Layout Style', 'mcp-ai-wpoos' ),
							value: attributes.layout,
							options: [
								{ value: 'stacked', label: __( 'Stacked', 'mcp-ai-wpoos' ) },
								{ value: 'side-by-side', label: __( 'Side by Side', 'mcp-ai-wpoos' ) }
							],
							onChange: function ( val ) {
								setAttributes( { layout: val } );
							}
						} )
					),
					el( PanelBody, { title: __( 'Components', 'mcp-ai-wpoos' ), initialOpen: true },
						el( ToggleControl, {
							label: __( 'Show Assistant Selector', 'mcp-ai-wpoos' ),
							checked: attributes.showAssistantSelector,
							onChange: function ( val ) {
								setAttributes( { showAssistantSelector: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Tools Grid', 'mcp-ai-wpoos' ),
							checked: attributes.showToolsGrid,
							onChange: function ( val ) {
								setAttributes( { showToolsGrid: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Knowledge Base', 'mcp-ai-wpoos' ),
							checked: attributes.showKnowledgeBase,
							onChange: function ( val ) {
								setAttributes( { showKnowledgeBase: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Build Button', 'mcp-ai-wpoos' ),
							checked: attributes.showBuildButton,
							onChange: function ( val ) {
								setAttributes( { showBuildButton: val } );
							}
						} )
					),
					el( PanelBody, { title: __( 'Assistant Selector', 'mcp-ai-wpoos' ), initialOpen: false },
						el( SelectControl, {
							label: __( 'Default Assistant', 'mcp-ai-wpoos' ),
							value: attributes.defaultAssistantId,
							options: getAssistantOptions(),
							onChange: function ( val ) {
								setAttributes( { defaultAssistantId: parseInt( val, 10 ) } );
							}
						} )
					),
					el( PanelBody, { title: __( 'Tools Grid', 'mcp-ai-wpoos' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Start Collapsed', 'mcp-ai-wpoos' ),
							checked: attributes.toolsCollapsed,
							onChange: function ( val ) {
								setAttributes( { toolsCollapsed: val } );
							}
						} ),
						el( ToggleControl, {
							label: __( 'Show Tool Descriptions', 'mcp-ai-wpoos' ),
							checked: attributes.showToolDescriptions,
							onChange: function ( val ) {
								setAttributes( { showToolDescriptions: val } );
							}
						} )
					),
					el( PanelBody, { title: __( 'Chat', 'mcp-ai-wpoos' ), initialOpen: false },
						el( ToggleControl, {
							label: __( 'Enable Streaming', 'mcp-ai-wpoos' ),
							checked: attributes.enableStreaming,
							onChange: function ( val ) {
								setAttributes( { enableStreaming: val } );
							}
						} ),
						el( TextControl, {
							label: __( 'Chat Placeholder', 'mcp-ai-wpoos' ),
							placeholder: translations.chatPlaceholder || __( 'Describe the assistant you want to create...', 'mcp-ai-wpoos' ),
							value: attributes.chatPlaceholder,
							onChange: function ( val ) {
								setAttributes( { chatPlaceholder: val } );
							}
						} )
					),
					el( PanelBody, { title: __( 'Knowledge Base', 'mcp-ai-wpoos' ), initialOpen: false },
						el( TextControl, {
							label: __( 'Allowed File Types', 'mcp-ai-wpoos' ),
							value: attributes.allowedFileTypes,
							onChange: function ( val ) {
								setAttributes( { allowedFileTypes: val } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Max Files', 'mcp-ai-wpoos' ),
							value: attributes.maxFiles,
							min: 1,
							max: 50,
							onChange: function ( val ) {
								setAttributes( { maxFiles: val } );
							}
						} ),
						el( RangeControl, {
							label: __( 'Max File Size (MB)', 'mcp-ai-wpoos' ),
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
						el( 'strong', {}, __( 'AI Assistant Builder', 'mcp-ai-wpoos' ) ),
						' — ',
						attributes.layout === 'side-by-side' ? __( 'Side by Side', 'mcp-ai-wpoos' ) : __( 'Stacked', 'mcp-ai-wpoos' )
					),
					el( 'ul', {},
						attributes.showAssistantSelector && el( 'li', {}, '✓ ' + __( 'Assistant Selector', 'mcp-ai-wpoos' ) ),
						attributes.showToolsGrid && el( 'li', {}, '✓ ' + __( 'Tools Grid', 'mcp-ai-wpoos' ) ),
						attributes.showKnowledgeBase && el( 'li', {}, '✓ ' + __( 'Knowledge Base', 'mcp-ai-wpoos' ) ),
						attributes.showBuildButton && el( 'li', {}, '✓ ' + __( 'Build Button', 'mcp-ai-wpoos' ) )
					),
					el( 'p', { style: { color: '#666', fontSize: '12px' } },
						__( 'Complete builder interface will display on the frontend.', 'mcp-ai-wpoos' )
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
