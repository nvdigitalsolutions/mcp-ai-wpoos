/* jshint esversion: 6 */
/**
 * NV oOS Content Graph — Frontend Embed
 *
 * Powers the [nvoos_graph] shortcode and Gutenberg block on the frontend.
 * Loads nodes (and optionally edges) from the REST API and renders them
 * with Cytoscape.js using the shared visual theme engine, honoring the
 * Appearance settings plus per-embed shortcode attributes.
 *
 * @package NvoosContentGraph
 * @since   0.5.0
 */
( function ( $ ) {
	'use strict';

	var theme = window.nvoosContentGraphTheme || null;

	/**
	 * Initialise one graph embed instance.
	 */
	function initEmbed( config ) {
		var containerId = config.container;
		var $container  = $( '#' + containerId );
		if ( ! $container.length || ! theme ) {
			return;
		}

		var visual = config.visual || {};

		$container.html( '<p class="nvoos-content-graph-loading">Loading graph…</p>' );

		// Cytoscape and the fcose layout extension are enqueued as hard
		// dependencies of this script (see Shortcode.php), so no dynamic CDN
		// injection is required. We still guard for the rare case where
		// another plugin dequeues them.
		if ( typeof window.cytoscape === 'undefined' ) {
			$container.html( '<p class="nvoos-content-graph-error">Cytoscape.js not loaded.</p>' );
			return;
		}

		fetchAndRender( config, visual, $container );
	}

	/**
	 * Fetch nodes (and optionally edges) from REST API and render the graph.
	 */
	function fetchAndRender( config, visual, $container ) {
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
			var nodeIds = {};
			var elements = [];
			var maxDegree = 1;

			$.each( nodes, function ( _, n ) {
				nodeIds[ n.node_id ] = true;
				var degree = parseInt( n.degree, 10 ) || 1;
				if ( degree > maxDegree ) {
					maxDegree = degree;
				}
				elements.push( {
					data: {
						id:           n.node_id,
						label:        n.label,
						type:         n.type,
						degree:       degree,
						community_id: n.community_id || '',
						url:          n.url || ''
					}
				} );
			} );

			var finish = function () {
				renderGraph( config, visual, $container, elements, maxDegree );
			};

			if ( visual.show_edges ) {
				$.ajax( {
					url:     config.rest_url + '/edges',
					method:  'GET',
					headers: { 'X-WP-Nonce': config.nonce },
					data:    { per_page: 2000, page: 1 }
				} ).done( function ( edges ) {
					$.each( edges, function ( _, e ) {
						if ( ! nodeIds[ e.source_node_id ] || ! nodeIds[ e.target_node_id ] ) {
							return;
						}
						elements.push( {
							data: {
								id:         'e_' + e.id,
								source:     e.source_node_id,
								target:     e.target_node_id,
								relation:   e.relation || 'related_to',
								confidence: parseFloat( e.confidence ) || 1
							}
						} );
					} );
					finish();
				} ).fail( finish );
			} else {
				finish();
			}
		} ).fail( function () {
			$container.html( '<p class="nvoos-content-graph-error">Graph data unavailable.</p>' );
		} );
	}

	/**
	 * Render the Cytoscape graph inside the container using the theme engine.
	 */
	function renderGraph( config, visual, $container, elements, maxDegree ) {
		$container.html( '' );
		if ( typeof window.cytoscape === 'undefined' ) {
			$container.html( '<p class="nvoos-content-graph-error">Cytoscape.js not loaded.</p>' );
			return;
		}

		var tokens = theme.tokens( visual );

		// Resolve colors/icons/shapes/sizes up front.
		$.each( elements, function ( _, el ) {
			var d = el.data;
			d.color = theme.nodeColor( visual, d, maxDegree );
			d.icon = visual.show_icons ? theme.iconDataUri( visual, d.type, d.color, tokens ) : '';
			d.shape = ( visual.node_shapes && visual.shape_map && visual.shape_map[ d.type ] ) || 'ellipse';
			d.size = theme.nodeSize( d.degree, maxDegree, visual );
			if ( d.source ) {
				d.edgeColor = theme.edgeFamilyColor( d.relation, tokens, visual );
				d.edgeWidth = 1;
				d.curve = 'bezier';
				d.arrow = visual.edge_style === 'arrows' || visual.edge_style === 'tapered' ? 'triangle' : 'none';
				d.edgeLabel = visual.edge_labels === 'always' ? d.relation : '';
			}
		} );

		var cy = window.cytoscape( {
			container: $container[ 0 ],
			elements:  elements,
			style:     theme.buildStylesheet( visual, { cy: null } ),
			pixelRatio: 1,
			textureOnViewport: true,
			hideEdgesOnViewport: true,
			layout:    theme.layoutPresets( visual )[ 'fcose-balanced' ].options
		} );

		cy.style( theme.buildStylesheet( visual, { cy: cy } ) );
		theme.applyChrome( $container[ 0 ], visual );

		// Legend (client-side; the graph itself is JS-only, so a no-JS
		// legend fallback would have nothing to annotate).
		if ( visual.show_legend ) {
			renderLegend( $container, visual, elements, tokens );
		}

		// Minimal zoom cluster for touch/small screens.
		appendZoomCluster( $container, cy );

		// Click node: open URL or highlight.
		cy.on( 'tap', 'node', function ( e ) {
			var url = e.target.data( 'url' );
			// Only allow http(s) URLs to neutralise javascript: / data: schemes that
			// some browsers still execute via window.open().
			if ( url && /^https?:\/\//i.test( String( url ) ) ) {
				window.open( String( url ), '_blank', 'noopener,noreferrer' );
			}
		} );

		cy.on( 'tap', function ( e ) {
			if ( e.target === cy ) {
				cy.elements().removeClass( 'faded highlighted' );
			}
		} );
	}

	/**
	 * Render the legend inside the embed container.
	 */
	function renderLegend( $container, visual, elements, tokens ) {
		var types = {};
		var html = '<div class="nvoos-cg-legend nvoos-cg-legend-frontend"><ul class="nvoos-cg-legend-list">';

		$.each( elements, function ( _, el ) {
			if ( el.data.source ) {
				return; // edges
			}
			types[ el.data.type ] = true;
		} );

		$.each( types, function ( type ) {
			var color = theme.colorForType( type, visual );
			var icon = visual.show_icons ? theme.iconDataUri( visual, type, color, tokens ) : '';
			html += '<li class="nvoos-cg-legend-row">' +
				'<span class="nvoos-cg-legend-swatch" style="background:' + color + '">' +
				( icon ? '<img src="' + icon + '" alt="" aria-hidden="true">' : '' ) +
				'</span><span class="nvoos-cg-legend-label">' + $( '<div/>' ).text( type ).html() + '</span></li>';
		} );

		html += '</ul></div>';
		$container.prepend( html );
	}

	/**
	 * Append a small zoom cluster to the embed (absolute-positioned).
	 */
	function appendZoomCluster( $container, cy ) {
		var $cluster = $( '<div class="nvoos-cg-zoom-cluster nvoos-cg-zoom-cluster-frontend">' +
			'<button type="button" class="nvoos-cg-zoom-btn" data-zoom="out" aria-label="Zoom out">−</button>' +
			'<button type="button" class="nvoos-cg-zoom-btn" data-zoom="fit" aria-label="Fit">⤢</button>' +
			'<button type="button" class="nvoos-cg-zoom-btn" data-zoom="in" aria-label="Zoom in">+</button>' +
			'</div>' );
		$container.append( $cluster );

		$cluster.on( 'click', '.nvoos-cg-zoom-btn', function () {
			var action = $( this ).data( 'zoom' );
			if ( action === 'in' ) {
				cy.zoom( { level: cy.zoom() * 1.25, renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } } );
			} else if ( action === 'out' ) {
				cy.zoom( { level: cy.zoom() * 0.8, renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } } );
			} else {
				cy.fit( undefined, 20 );
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
