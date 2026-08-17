/* jshint esversion: 6 */
/**
 * NV oOS Content Graph — Admin Graph Explorer
 *
 * Handles the Cytoscape.js graph explorer on the Knowledge Graph admin page
 * and the "Rebuild Graph" button.
 *
 * @package NvoosContentGraph
 * @since   0.5.0
 */
( function ( $ ) {
	'use strict';

	var cy = null;
	var config = window.nvoosContentGraphAdmin || {};

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
		media:        '#7f8c8d',
		memory:       '#f1c40f',
		agent:        '#16a085',
		wing:         '#8e44ad',
		room:         '#27ae60'
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

	/**
	 * Convert a slug (e.g. "shop_order") into a humanised label
	 * ("Shop order"). Used as a fallback when no localized label is
	 * provided for a node type.
	 *
	 * @param  {string} slug Type slug.
	 * @return {string} Humanised label.
	 */
	function humanizeSlug( slug ) {
		if ( ! slug ) {
			return '';
		}
		var spaced = slug.toString().replace( /[-_]+/g, ' ' ).trim();
		return spaced.charAt( 0 ).toUpperCase() + spaced.slice( 1 );
	}

	/**
	 * Repopulate the Graph Explorer's "type" filter dropdown so it reflects
	 * the node types actually present in the loaded graph (including
	 * custom post types and JetEngine CCTs). The previously selected value
	 * is preserved when possible.
	 *
	 * @param  {Object} typesSeen Map of {type: true} for every type observed
	 *                            in the loaded nodes.
	 * @return {void}
	 */
	function populateTypeFilter( typesSeen ) {
		var $select = $( '#nvoos-content-graph-type-filter' );
		if ( ! $select.length ) {
			return;
		}

		var labels        = ( config && config.type_labels ) || {};
		var i18n          = ( config && config.i18n ) || {};
		var allTypesLabel = i18n.all_types || 'All types';
		var previous      = $select.val();

		var slugs = [];
		for ( var key in typesSeen ) {
			if ( Object.prototype.hasOwnProperty.call( typesSeen, key ) ) {
				slugs.push( key );
			}
		}

		// Sort alphabetically by display label so the dropdown is stable.
		slugs.sort( function ( a, b ) {
			var la = labels[ a ] || humanizeSlug( a );
			var lb = labels[ b ] || humanizeSlug( b );
			return la.localeCompare( lb );
		} );

		var html = '<option value="">' + $( '<div/>' ).text( allTypesLabel ).html() + '</option>';
		$.each( slugs, function ( _, slug ) {
			var label = labels[ slug ] || humanizeSlug( slug );
			html += '<option value="' + $( '<div/>' ).text( slug ).html() + '">' +
				$( '<div/>' ).text( label ).html() + '</option>';
		} );

		$select.html( html );

		// Preserve previous selection if still applicable.
		if ( previous && typesSeen[ previous ] ) {
			$select.val( previous );
		}
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
		var $container = $( '#nvoos-content-graph-explorer' );
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
			var typesSeen = {};

			$.each( nodes, function ( _, n ) {
				nodeIds[ n.node_id ] = true;
				if ( n.type ) {
					typesSeen[ n.type ] = true;
				}
				// Parse properties JSON so the memory-palace preset can match
				// without first requiring edges to be loaded for each node.
				var props = {};
				if ( n.properties ) {
					if ( typeof n.properties === 'string' ) {
						try { props = JSON.parse( n.properties ); } catch ( e ) { props = {}; }
					} else if ( typeof n.properties === 'object' ) {
						props = n.properties;
					}
				}
				elements.push( {
					data: {
						id:           n.node_id,
						label:        n.label,
						type:         n.type,
						degree:       parseInt( n.degree, 10 ) || 1,
						community_id: n.community_id || '',
						url:          n.url || '',
						color:        colorForType( n.type ),
						agent_id:     props.agent_id || '',
						wing:         props.wing || '',
						room:         props.room || ''
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
				populateTypeFilter( typesSeen );
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
				$( '#nvoos-content-graph-sidebar' ).hide();
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
			var $sb   = $( '#nvoos-content-graph-sidebar' );

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
			// Only allow http(s) URLs to neutralise javascript: / data: / vbscript:.
			var safeUrl = ( n.url && /^https?:\/\//i.test( String( n.url ) ) ) ? String( n.url ) : '';
			var urlHtml = safeUrl ? '<p><a href="' + $( '<span>' ).text( safeUrl ).html() + '" target="_blank" rel="noopener noreferrer">View post ↗</a></p>' : '';
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

	$( document ).on( 'click', '#nvoos-content-graph-fit-btn', function () {
		if ( cy ) {
			cy.fit( undefined, 30 );
		}
	} );

	$( document ).on( 'click', '#nvoos-content-graph-relayout-btn', function () {
		if ( cy ) {
			cy.layout( { name: 'fcose', animate: true } ).run();
		}
	} );

	$( document ).on( 'click', '#nvoos-content-graph-export-png-btn', function () {
		if ( ! cy ) {
			return;
		}
		var png = cy.png( { output: 'blob', bg: '#0f0f1a', full: true, scale: 2 } );
		var a   = document.createElement( 'a' );
		a.href  = URL.createObjectURL( png );
		a.download = 'knowledge-graph.png';
		a.click();
	} );

	$( document ).on( 'input', '#nvoos-content-graph-search', function () {
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

	$( document ).on( 'change', '#nvoos-content-graph-type-filter', function () {
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
	// Memory palace preset — Agent: X / Wing: Y
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '#nvoos-content-graph-memory-preset-btn', function () {
		if ( ! cy ) {
			return;
		}
		var agent = $.trim( $( '#nvoos-content-graph-agent-filter' ).val() || '' );
		var wing  = $.trim( $( '#nvoos-content-graph-wing-filter' ).val() || '' );

		if ( ! agent && ! wing ) {
			cy.elements().removeClass( 'faded highlighted' );
			return;
		}

		// Match memory nodes whose properties carry the agent_id and/or wing.
		// Comparison is case-insensitive and trimmed to be forgiving.
		var norm = function ( s ) {
			return ( s || '' ).toString().toLowerCase().trim();
		};
		var agentN = norm( agent );
		var wingN  = norm( wing );

		cy.elements().addClass( 'faded' ).removeClass( 'highlighted' );

		var matched = cy.nodes().filter( function ( n ) {
			if ( n.data( 'type' ) !== 'memory' ) {
				return false;
			}
			if ( agentN && norm( n.data( 'agent_id' ) ) !== agentN ) {
				return false;
			}
			if ( wingN && norm( n.data( 'wing' ) ) !== wingN ) {
				return false;
			}
			return true;
		} );

		matched.removeClass( 'faded' ).addClass( 'highlighted' );
		// Light up immediate neighbourhood (when edges are loaded for the node).
		matched.connectedEdges().removeClass( 'faded' );
		matched.neighborhood().nodes().removeClass( 'faded' );

		// Light up the wing/agent anchor nodes so the palace anatomy is visible.
		cy.nodes().filter( function ( n ) {
			var t = n.data( 'type' );
			if ( wingN && t === 'wing' && norm( n.data( 'label' ) ) === wingN ) {
				return true;
			}
			if ( agentN && t === 'agent' && norm( n.data( 'agent_id' ) ) === agentN ) {
				return true;
			}
			return false;
		} ).removeClass( 'faded' ).addClass( 'highlighted' );
	} );

	$( document ).on( 'click', '#nvoos-content-graph-memory-clear-btn', function () {
		if ( ! cy ) {
			return;
		}
		$( '#nvoos-content-graph-agent-filter' ).val( '' );
		$( '#nvoos-content-graph-wing-filter' ).val( '' );
		cy.elements().removeClass( 'faded highlighted' );
	} );

	// -------------------------------------------------------------------------
	// Rebuild button
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '#nvoos-content-graph-build-btn', function () {
		var $btn    = $( this );
		var $status = $( '#nvoos-content-graph-build-status' );

		$btn.prop( 'disabled', true ).text( 'Building…' );
		$status.text( '' ).show();

		$.ajax( {
			url:    config.ajax_url,
			method: 'POST',
			data: {
				action:      'nvoos_content_graph_build',
				nonce:       config.ajax_nonce,
				incremental: 0
			}
		} ).done( function ( response ) {
			if ( response.success ) {
				var d = response.data || {};
				var msg = 'Done! ' + ( d.nodes_upserted || 0 ) + ' nodes, ' + ( d.edges_upserted || 0 ) + ' edges.';

				// Per-source detection breakdown — surfaces zero-detection
				// problems (e.g. JetEngine CCTs not appearing) directly in
				// the admin UI so users don't need to read DB meta.
				var parts = [];
				if ( typeof d.posts_detected !== 'undefined' ) {
					parts.push( 'posts: ' + ( d.posts_detected || 0 ) );
				}
				if ( typeof d.ccts_detected !== 'undefined' ) {
					parts.push( 'CCTs: ' + ( d.ccts_detected || 0 ) );
				}
				if ( typeof d.terms_detected !== 'undefined' ) {
					parts.push( 'terms: ' + ( d.terms_detected || 0 ) );
				}
				if ( typeof d.users_detected !== 'undefined' ) {
					parts.push( 'users: ' + ( d.users_detected || 0 ) );
				}
				if ( typeof d.media_detected !== 'undefined' ) {
					parts.push( 'media: ' + ( d.media_detected || 0 ) );
				}
				if ( parts.length ) {
					msg += ' Detected — ' + parts.join( ', ' ) + '.';
				}
				if ( d.ccts_skipped_reason ) {
					msg += ' CCTs skipped: ' + d.ccts_skipped_reason + '.';
				}

				$status.text( msg );
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
