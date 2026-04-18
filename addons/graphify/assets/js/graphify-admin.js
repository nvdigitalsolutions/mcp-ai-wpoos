/* jshint esversion: 6 */
/**
 * NV oOS Graphify — Admin Graph Explorer
 *
 * Handles the Cytoscape.js graph explorer on the Knowledge Graph admin page
 * and the "Rebuild Graph" button.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */
( function ( $ ) {
	'use strict';

	var cy = null;
	var config = window.nvoosGraphifyAdmin || {};

	var TYPE_COLORS = {
		post:         '#3498db',
		page:         '#2ecc71',
		term:         '#f39c12',
		topic:        '#9b59b6',
		entity:       '#e74c3c',
		person:       '#e67e22',
		place:        '#1abc9c',
		organization: '#2980b9',
		user:         '#c0392b',
		media:        '#7f8c8d'
	};

	/**
	 * Return colour for a given node type.
	 *
	 * @param  {string} type Node type.
	 * @return {string} Hex colour.
	 */
	function colorForType( type ) {
		return TYPE_COLORS[ type ] || '#95a5a6';
	}

	// -------------------------------------------------------------------------
	// Graph explorer
	// -------------------------------------------------------------------------

	/**
	 * Load nodes from the REST API and initialise Cytoscape.
	 *
	 * @return {void}
	 */
	function loadGraph() {
		var $container = $( '#nvoos-graphify-explorer' );
		if ( ! $container.length ) {
			return;
		}

		$container.html( '<p style="padding:20px;color:#888;">' + 'Loading graph…' + '</p>' );

		$.ajax( {
			url:     config.rest_url + '/nodes',
			method:  'GET',
			headers: { 'X-WP-Nonce': config.nonce },
			data:    { per_page: config.max_nodes || 300 }
		} ).done( function ( nodes ) {
			var nodeIds = {};
			var elements = [];

			$.each( nodes, function ( _, n ) {
				nodeIds[ n.node_id ] = true;
				elements.push( {
					data: {
						id:           n.node_id,
						label:        n.label,
						type:         n.type,
						degree:       parseInt( n.degree, 10 ) || 1,
						community_id: n.community_id || '',
						url:          n.url || '',
						color:        colorForType( n.type )
					}
				} );
			} );

			// Load edges (all nodes already in set).
			$.ajax( {
				url:     config.rest_url + '/nodes',
				method:  'GET',
				headers: { 'X-WP-Nonce': config.nonce },
				data:    { per_page: 1, page: 1 } // just to trigger — we use search endpoint for edges
			} ).always( function () {
				// We don't have a dedicated /edges endpoint; load them per-node lazily
				// (edges appear when a node is clicked). For the initial render, show nodes only.
				initCytoscape( $container, elements );
			} );
		} ).fail( function () {
			$container.html( '<p style="padding:20px;color:#c00;">Failed to load graph data. Ensure the graph has been built.</p>' );
		} );
	}

	/**
	 * Initialise the Cytoscape instance.
	 *
	 * @param {jQuery} $container Container element.
	 * @param {Array}  elements   Cytoscape element array.
	 * @return {void}
	 */
	function initCytoscape( $container, elements ) {
		$container.html( '' );

		if ( typeof window.cytoscape === 'undefined' ) {
			$container.html( '<p style="padding:20px;color:#c00;">Cytoscape.js did not load. Check your network connection.</p>' );
			return;
		}

		cy = window.cytoscape( {
			container: $container[ 0 ],
			elements:  elements,
			style: [
				{
					selector: 'node',
					style: {
						'label':            'data(label)',
						'font-size':        10,
						'color':            '#e0e0ff',
						'text-halign':      'center',
						'text-valign':      'bottom',
						'text-margin-y':    4,
						'background-color': 'data(color)',
						'width':            'mapData(degree, 0, 50, 12, 60)',
						'height':           'mapData(degree, 0, 50, 12, 60)',
						'border-width':     1,
						'border-color':     '#2a2a4a',
						'text-max-width':   80,
						'text-wrap':        'ellipsis'
					}
				},
				{
					selector: 'edge',
					style: {
						'width':        1,
						'line-color':   '#444',
						'opacity':      0.5,
						'curve-style':  'bezier',
						'target-arrow-shape': 'triangle',
						'target-arrow-color': '#444',
						'arrow-scale':  0.6
					}
				},
				{
					selector: ':selected',
					style: {
						'border-width': 3,
						'border-color': '#fff',
						'opacity':      1
					}
				},
				{
					selector: '.faded',
					style: { 'opacity': 0.15 }
				},
				{
					selector: '.highlighted',
					style: { 'opacity': 1 }
				}
			],
			layout: {
				name:          'fcose',
				animate:       true,
				animationDuration: 800,
				quality:       'default',
				nodeDimensionsIncludeLabels: true
			}
		} );

		bindCytoscapeEvents();
	}

	/**
	 * Bind Cytoscape interaction events.
	 *
	 * @return {void}
	 */
	function bindCytoscapeEvents() {
		if ( ! cy ) {
			return;
		}

		// Click background: clear selection.
		cy.on( 'tap', function ( e ) {
			if ( e.target === cy ) {
				cy.nodes().removeClass( 'faded highlighted' );
				cy.edges().removeClass( 'faded highlighted' );
				$( '#nvoos-graphify-sidebar' ).hide();
			}
		} );

		// Click node: show info and load neighbors.
		cy.on( 'tap', 'node', function ( e ) {
			var n = e.target;
			loadNodeDetails( n.id() );
		} );
	}

	/**
	 * Load a node's details and neighbors, show sidebar, highlight connections.
	 *
	 * @param {string} nodeId Node identifier.
	 * @return {void}
	 */
	function loadNodeDetails( nodeId ) {
		$.ajax( {
			url:     config.rest_url + '/nodes/' + encodeURIComponent( nodeId ),
			method:  'GET',
			headers: { 'X-WP-Nonce': config.nonce }
		} ).done( function ( data ) {
			var n     = data.node;
			var nbrs  = data.neighbors || [];
			var $sb   = $( '#nvoos-graphify-sidebar' );

			// Add neighbor nodes/edges to Cytoscape if not already present.
			$.each( nbrs, function ( _, nbr ) {
				if ( cy.$( '#' + nbr.node_id ).length === 0 ) {
					cy.add( {
						data: {
							id:     nbr.node_id,
							label:  nbr.label,
							type:   nbr.type,
							degree: 1,
							color:  colorForType( nbr.type ),
							url:    ''
						}
					} );
				}
				var edgeId = nodeId + '_' + nbr.node_id + '_' + nbr.relation;
				if ( cy.$( '#' + edgeId ).length === 0 ) {
					cy.add( {
						data: {
							id:     edgeId,
							source: nodeId,
							target: nbr.node_id
						}
					} );
				}
			} );

			// Fade all except this node and its neighborhood.
			cy.elements().addClass( 'faded' );
			var neighborhood = cy.$( '#' + nodeId ).closedNeighborhood();
			neighborhood.removeClass( 'faded' ).addClass( 'highlighted' );

			// Build sidebar HTML.
			var urlHtml  = n.url ? '<p><a href="' + n.url + '" target="_blank" rel="noopener">View post ↗</a></p>' : '';
			var nbrHtml  = '';
			$.each( nbrs.slice( 0, 10 ), function ( _, nbr ) {
				nbrHtml += '<li><strong>' + $( '<span>' ).text( nbr.label ).html() + '</strong> <em>' + $( '<span>' ).text( nbr.relation ).html() + '</em></li>';
			} );

			$sb.html(
				'<h3>' + $( '<span>' ).text( n.label ).html() + '</h3>' +
				'<p><em>' + $( '<span>' ).text( n.type ).html() + '</em> &bull; ' + parseInt( n.degree, 10 ) + ' connections</p>' +
				( n.community_id ? '<p>Community: <code>' + $( '<span>' ).text( n.community_id ).html() + '</code></p>' : '' ) +
				urlHtml +
				( nbrHtml ? '<h4>Neighbors</h4><ul>' + nbrHtml + '</ul>' : '' )
			).show();
		} );
	}

	// -------------------------------------------------------------------------
	// Toolbar controls
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '#nvoos-graphify-fit-btn', function () {
		if ( cy ) {
			cy.fit( undefined, 30 );
		}
	} );

	$( document ).on( 'click', '#nvoos-graphify-relayout-btn', function () {
		if ( cy ) {
			cy.layout( { name: 'fcose', animate: true } ).run();
		}
	} );

	$( document ).on( 'click', '#nvoos-graphify-export-png-btn', function () {
		if ( ! cy ) {
			return;
		}
		var png = cy.png( { output: 'blob', bg: '#0f0f1a', full: true, scale: 2 } );
		var a   = document.createElement( 'a' );
		a.href  = URL.createObjectURL( png );
		a.download = 'knowledge-graph.png';
		a.click();
	} );

	$( document ).on( 'input', '#nvoos-graphify-search', function () {
		if ( ! cy ) {
			return;
		}
		var q = $( this ).val().toLowerCase().trim();
		if ( ! q ) {
			cy.elements().removeClass( 'faded highlighted' );
			return;
		}
		cy.elements().addClass( 'faded' );
		cy.nodes().filter( function ( n ) {
			return n.data( 'label' ).toLowerCase().indexOf( q ) !== -1;
		} ).removeClass( 'faded' ).addClass( 'highlighted' );
	} );

	$( document ).on( 'change', '#nvoos-graphify-type-filter', function () {
		if ( ! cy ) {
			return;
		}
		var type = $( this ).val();
		if ( ! type ) {
			cy.elements().removeClass( 'faded highlighted' );
			return;
		}
		cy.elements().addClass( 'faded' );
		cy.nodes().filter( function ( n ) {
			return n.data( 'type' ) === type;
		} ).removeClass( 'faded' ).addClass( 'highlighted' );
	} );

	// -------------------------------------------------------------------------
	// Rebuild button
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '#nvoos-graphify-build-btn', function () {
		var $btn    = $( this );
		var $status = $( '#nvoos-graphify-build-status' );

		$btn.prop( 'disabled', true ).text( 'Building…' );
		$status.text( '' ).show();

		$.ajax( {
			url:    config.ajax_url,
			method: 'POST',
			data: {
				action:      'nvoos_graphify_build',
				nonce:       config.ajax_nonce,
				incremental: 0
			}
		} ).done( function ( response ) {
			if ( response.success ) {
				var d = response.data;
				$status.text( 'Done! ' + d.nodes_upserted + ' nodes, ' + d.edges_upserted + ' edges.' );
				// Reload graph explorer.
				loadGraph();
			} else {
				$status.text( 'Build failed.' );
			}
		} ).fail( function () {
			$status.text( 'Request failed.' );
		} ).always( function () {
			$btn.prop( 'disabled', false ).text( 'Rebuild Graph' );
		} );
	} );

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	$( function () {
		loadGraph();
	} );

}( jQuery ) );
