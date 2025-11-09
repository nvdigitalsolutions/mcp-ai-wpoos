/**
 * Gutenberg blocks for WP oOS Dashboard widgets.
 *
 * Registers dashboard-related blocks in the block editor.
 */

( function( blocks, element, editor, components ) {
	var el = element.createElement;
	var registerBlockType = blocks.registerBlockType;
	var InspectorControls = editor.InspectorControls;
	var TextControl = components.TextControl;
	var RangeControl = components.RangeControl;
	var PanelBody = components.PanelBody;

	/**
	 * Register Dashboard Tool Matrix Block.
	 */
	registerBlockType( 'wp-mcp-ai/dashboard-tool-matrix', {
		title: 'Dashboard Tool Matrix',
		icon: 'grid-view',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Tool Matrix'
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'Tool matrix will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Dashboard User Capability Block.
	 */
	registerBlockType( 'wp-mcp-ai/dashboard-user-capability', {
		title: 'User Capabilities',
		icon: 'admin-users',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'User Capabilities'
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'User capability information will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Dashboard User Files Block.
	 */
	registerBlockType( 'wp-mcp-ai/dashboard-user-files', {
		title: 'User Files',
		icon: 'media-default',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'User Files'
			},
			limit: {
				type: 'number',
				default: 10
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'User files will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Dashboard User Chats Block.
	 */
	registerBlockType( 'wp-mcp-ai/dashboard-user-chats', {
		title: 'Recent Chats',
		icon: 'format-chat',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Recent Chats'
			},
			limit: {
				type: 'number',
				default: 5
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'Recent chat transcripts will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Dashboard Theme Preview Block.
	 */
	registerBlockType( 'wp-mcp-ai/dashboard-theme-preview', {
		title: 'Theme Preview',
		icon: 'admin-appearance',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Theme Preview'
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'Theme color preview will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Dashboard Provider Links Block.
	 */
	registerBlockType( 'wp-mcp-ai/dashboard-provider-links', {
		title: 'AI Provider Links',
		icon: 'admin-links',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'AI Provider Links'
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'AI provider links will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Dashboard Activity Feed Block.
	 */
	registerBlockType( 'wp-mcp-ai/dashboard-activity-feed', {
		title: 'Recent Activity',
		icon: 'update',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Recent Activity'
			},
			limit: {
				type: 'number',
				default: 10
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'Recent activity feed will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

} )( window.wp.blocks, window.wp.element, window.wp.editor, window.wp.components );
