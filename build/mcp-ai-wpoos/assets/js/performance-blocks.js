/**
 * Gutenberg blocks for WP oOS Performance Monitoring.
 *
 * Registers performance monitoring blocks in the block editor.
 */

( function( blocks, element, editor, components ) {
	const el = element.createElement;
	const registerBlockType = blocks.registerBlockType;
	const InspectorControls = editor.InspectorControls;
	const TextControl = components.TextControl;
	const SelectControl = components.SelectControl;
	const ToggleControl = components.ToggleControl;
	const PanelBody = components.PanelBody;

	/**
	 * Register Performance Test Runner Block.
	 */
	registerBlockType( 'wp-mcp-ai/performance-test-runner', {
		title: 'Performance Test Runner',
		icon: 'performance',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Performance Test Runner'
			},
			enabledTests: {
				type: 'array',
				default: [ 'stress', 'security', 'speed', 'optimization' ]
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'Performance test runner will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Performance Metrics Block.
	 */
	registerBlockType( 'wp-mcp-ai/performance-metrics', {
		title: 'Performance Metrics',
		icon: 'dashboard',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Performance Metrics'
			},
			component: {
				type: 'string',
				default: ''
			},
			timePeriod: {
				type: 'string',
				default: '-24 hours'
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'Performance metrics will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register System Health Status Block.
	 */
	registerBlockType( 'wp-mcp-ai/system-health-status', {
		title: 'System Health Status',
		icon: 'heart',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'System Health Status'
			},
			showBreakdown: {
				type: 'boolean',
				default: true
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'System health status will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Test Results Table Block.
	 */
	registerBlockType( 'wp-mcp-ai/test-results-table', {
		title: 'Test Results Table',
		icon: 'list-view',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Test Results'
			},
			testType: {
				type: 'string',
				default: ''
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
				el( 'p', {}, 'Test results table will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Performance Recommendations Block.
	 */
	registerBlockType( 'wp-mcp-ai/performance-recommendations', {
		title: 'Performance Recommendations',
		icon: 'lightbulb',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Performance Recommendations'
			},
			severity: {
				type: 'string',
				default: 'all'
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
				el( 'p', {}, 'AI recommendations will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

	/**
	 * Register Performance Trends Block.
	 */
	registerBlockType( 'wp-mcp-ai/performance-trends', {
		title: 'Performance Trends',
		icon: 'chart-line',
		category: 'widgets',
		attributes: {
			title: {
				type: 'string',
				default: 'Performance Trends'
			},
			component: {
				type: 'string',
				default: 'rest_api'
			},
			timePeriod: {
				type: 'string',
				default: '-7 days'
			}
		},
		edit: function( props ) {
			return el(
				'div',
				{ className: props.className },
				el( 'h3', {}, props.attributes.title ),
				el( 'p', {}, 'Performance trends chart will be displayed here.' )
			);
		},
		save: function() {
			return null; // Rendered server-side
		}
	} );

} )( window.wp.blocks, window.wp.element, window.wp.editor, window.wp.components );
