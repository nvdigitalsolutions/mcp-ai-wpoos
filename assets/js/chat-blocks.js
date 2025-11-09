/**
 * Gutenberg blocks for WP oOS Chat widgets.
 *
 * Registers chat-related blocks in the block editor.
 */

( function( blocks, element, editor, components ) {
	var el = element.createElement;
	var registerBlockType = blocks.registerBlockType;
	var InspectorControls = editor.InspectorControls;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var PanelBody = components.PanelBody;

	/**
	 * Register Chat Block (Main Widget).
	 */
	registerBlockType( 'wp-mcp-ai/chat', {
		title: 'WP oOS Chat',
		icon: 'format-chat',
		category: 'widgets',
		attributes: {
			assistant: {
				type: 'string',
				default: ''
			},
			allowGuests: {
				type: 'boolean',
				default: false
			},
			saveTranscript: {
				type: 'boolean',
				default: true
			},
			enableStreaming: {
				type: 'boolean',
				default: false
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, 'WP oOS Chat' ),
				el( 'p', {}, 'Chat interface will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Chat Intro Block.
	 */
	registerBlockType( 'wp-mcp-ai/chat-intro', {
		title: 'WP oOS Chat Intro',
		icon: 'info',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Welcome to WP oOS Chat'
			},
			description: {
				type: 'string',
				default: 'Start a conversation with your AI assistant to plan tasks, explore MCP tools, or keep track of ongoing projects.'
			},
			buttonText: {
				type: 'string',
				default: 'Open Chat'
			},
			buttonUrl: {
				type: 'string',
				default: ''
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h2', {}, props.attributes.title ),
				el( 'p', {}, props.attributes.description )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Chat FAQ Block.
	 */
	registerBlockType( 'wp-mcp-ai/chat-faq', {
		title: 'WP oOS Chat FAQ',
		icon: 'editor-help',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'How the chat works'
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h2', {}, props.attributes.title ),
				el( 'p', {}, 'FAQ items will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Chat Usage Timer Block.
	 */
	registerBlockType( 'wp-mcp-ai/chat-usage-timer', {
		title: 'Chat Usage Timer',
		icon: 'clock',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Chat Usage Timer'
			},
			assistantId: {
				type: 'string',
				default: ''
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'Chat usage statistics will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

} )( window.wp.blocks, window.wp.element, window.wp.editor, window.wp.components );
