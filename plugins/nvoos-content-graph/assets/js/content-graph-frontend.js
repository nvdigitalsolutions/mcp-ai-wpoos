/* jshint esversion: 6 */
/**
 * NV oOS Content Graph — Frontend Embed
 *
 * Powers the [nvoos_content_graph] shortcode and Gutenberg block on the frontend.
 * Loads nodes from the REST API and renders them with Cytoscape.js (CDN).
 *
 * @package NvoosContentGraph
 * @since   0.5.0
 */
( function ( $ ) {
	'use strict';

	var TYPE_COLORS = {
		post:   '#3498db',
		page:   '#2ecc71',
		term:   '#f39c12',
		topic:  '#9b59b6',
		entity: '#e74c3c',
		user:   '#c0392b',
		media:  '#7f8c8d'
	};

	/**
	 * Initialise one graph embed instance.
	 *
	 * @param {Object} config nvoosContentGraphData_{containerId} object.
	 * @return {void}
	 */
	function initEmbed( config ) {
		var containerId = config.container;
		var $container  = $( '#' + containerId );
		if ( ! $container.length ) {
			return;
		}

		$container.html( '<p class="nvoos-content-graph-loading">Loading graph…</p>' );

		// Cytoscape and the fcose layout extension are now enqueued as
		// hard dependencies of this script (see class-nvoos-content-graph.php),
		// so no dynamic CDN injection is required. We still guard for the
		// extremely rare case where another plugin dequeues them.
		function doLoad() {
			if ( typeof window.cytoscape === 'undefined' ) {
				$container.html( '<p class="nvoos-content-graph-error">Cytoscape.js not loaded.</p>' );
				return;
			}
			fetchAndRender( config, $container );
		}

		doLoad();
	}

	/**
	 * Fetch nodes from REST API and render graph.
	 *
	 * @param {Object} config
	 * @param {jQuery} $container
	 * @return {void}
	 */
	function fetchAndRender( config, $container ) {
		var params = { per_page: config.max_nodes || 300 };
		if ( config.community_id ) {
			params.community_id = config.community_id;
		}

		$.ajax( {
			url:     config.rest_url + '/nodes',
			method:  'GET',
			headers: { 'X-WP-Nonce': config.nonce },
			data:    params
		} ).done( function ( nodes ) {
			var elements = [];
			$.each( nodes, function ( _, n ) {
				elements.push( {
					data: {
						id:     n.node_id,
						label:  n.label,
						type:   n.type,
						degree: parseInt( n.degree, 10 ) || 1,
						url:    n.url || '',
						color:  TYPE_COLORS[ n.type ] || '#95a5a6'
					}
				} );
			} );

			renderGraph( config, $container, elements );
		} ).fail( function () {
			$container.html( '<p class="nvoos-content-graph-error">Graph data unavailable.</p>' );
		} );
	}

	/**
	 * Render the Cytoscape graph inside the container.
	 *
	 * @param {Object} config
	 * @param {jQuery} $container
	 * @param {Array}  elements
	 * @return {void}
	 */
	function renderGraph( config, $container, elements ) {
		$container.html( '' );
		if ( typeof window.cytoscape === 'undefined' ) {
			$container.html( '<p class="nvoos-content-graph-error">Cytoscape.js not loaded.</p>' );
			return;
		}

		var cy = window.cytoscape( {
			container: $container[ 0 ],
			elements:  elements,
			style: [
				{
					selector: 'node',
					style: {
						'label':            'data(label)',
						'font-size':        11,
						'color':            '#333',
						'text-halign':      'center',
						'text-valign':      'bottom',
						'text-margin-y':    4,
						'background-color': 'data(color)',
						'width':            'mapData(degree, 0, 30, 14, 50)',
						'height':           'mapData(degree, 0, 30, 14, 50)',
						'text-max-width':   90,
						'text-wrap':        'ellipsis'
					}
				},
				{
					selector: 'edge',
					style: {
						'width':        1,
						'line-color':   '#ccc',
						'opacity':      0.6,
						'curve-style':  'bezier',
						'target-arrow-shape': 'triangle',
						'target-arrow-color': '#ccc',
						'arrow-scale':  0.5
					}
				},
				{
					selector: ':selected',
					style: {
						'border-width': 3,
						'border-color': '#333'
					}
				}
			],
			layout: { name: 'fcose', animate: true }
		} );

		// Click node: open URL or highlight.
		cy.on( 'tap', 'node', function ( e ) {
			var url = e.target.data( 'url' );
			// Only allow http(s) URLs to neutralise javascript: / data: schemes that
			// some browsers still execute via window.open().
			if ( url && /^https?:\/\//i.test( String( url ) ) ) {
				window.open( String( url ), '_blank', 'noopener,noreferrer' );
			}
		} );
	}

	// -------------------------------------------------------------------------
	// Init all embeds on the page.
	// -------------------------------------------------------------------------
	$( function () {
		$( '[id^="nvoos-content-graph-"]' ).each( function () {
			var rawId = $( this ).attr( 'id' );
			// Each container has a matching nvoosContentGraphData_{id_with_underscores} object.
			var dataKey = 'nvoosContentGraphData_' + rawId.replace( /-/g, '_' );
			if ( window[ dataKey ] ) {
				initEmbed( window[ dataKey ] );
			}
		} );
	} );

}( jQuery ) );
