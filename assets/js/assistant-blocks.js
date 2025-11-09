/**
 * Gutenberg blocks for WP oOS Assistant widgets.
 *
 * Registers assistant-related blocks in the block editor.
 */

( function( blocks, element, editor, components ) {
	var el = element.createElement;
	var registerBlockType = blocks.registerBlockType;
	var InspectorControls = editor.InspectorControls;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var PanelBody = components.PanelBody;

	/**
	 * Register Assistant Defaults Block.
	 */
	registerBlockType( 'wp-mcp-ai/assistant-defaults', {
		title: 'Assistant Defaults',
		icon: 'admin-settings',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Assistant model defaults'
			},
			assistantId: {
				type: 'string',
				default: ''
			},
			showSystemPrompt: {
				type: 'boolean',
				default: true
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'Assistant default settings will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Assistant Base Knowledge Block.
	 */
	registerBlockType( 'wp-mcp-ai/assistant-base-knowledge', {
		title: 'Assistant Knowledge Base',
		icon: 'book',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Assistant knowledge base'
			},
			assistantId: {
				type: 'string',
				default: ''
			},
			showSizes: {
				type: 'boolean',
				default: true
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'Assistant knowledge base files will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Assistant Prompt Shortcuts Block.
	 */
	registerBlockType( 'wp-mcp-ai/assistant-prompt-shortcuts', {
		title: 'Assistant Prompt Shortcuts',
		icon: 'shortcode',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Assistant prompt shortcuts'
			},
			assistantId: {
				type: 'string',
				default: ''
			},
			showDescriptions: {
				type: 'boolean',
				default: true
			},
			showPrompt: {
				type: 'boolean',
				default: false
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'Prompt shortcuts will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Assistant Tools Block.
	 */
	registerBlockType( 'wp-mcp-ai/assistant-tools', {
		title: 'Assistant Tools',
		icon: 'admin-tools',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Available assistant tools'
			},
			assistantId: {
				type: 'string',
				default: ''
			},
			showDescriptions: {
				type: 'boolean',
				default: true
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'Assistant tools will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

} )( window.wp.blocks, window.wp.element, window.wp.editor, window.wp.components );
