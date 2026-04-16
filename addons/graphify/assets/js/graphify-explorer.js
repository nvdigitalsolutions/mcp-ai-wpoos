/**
 * NV oOS Graphify — Graph Explorer
 *
 * Interactive knowledge graph visualization using Cytoscape.js.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

/* global nvoosGraphifyConfig, cytoscape */
( function () {
	'use strict';

	// Community color palette.
	var PALETTE = [
		'#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7',
		'#DDA0DD', '#98D8C8', '#F7DC6F', '#BB8FCE', '#85C1E9',
	];

	var cy = null;
	var labelsVisible = true;
	var communityMap = {};

	/**
	 * Make a REST API request.
	 *
	 * @param {string} endpoint Relative endpoint path.
	 * @return {Promise<Object>} Parsed JSON response.
	 */
	function apiRequest( endpoint ) {
		return fetch( nvoosGraphifyConfig.restUrl + '/' + endpoint, {
			headers: {
				'X-WP-Nonce': nvoosGraphifyConfig.nonce,
			},
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Fetch all pages of a paginated endpoint.
	 *
	 * @param {string} endpoint Base endpoint (e.g. 'nodes').
	 * @param {number} perPage  Items per page.
	 * @return {Promise<Array>} All items concatenated.
	 */
	function fetchAllPages( endpoint, perPage ) {
		var allItems = [];
		var page = 1;

		function fetchPage() {
			var sep = endpoint.indexOf( '?' ) !== -1 ? '&' : '?';
			return apiRequest( endpoint + sep + 'per_page=' + perPage + '&page=' + page )
				.then( function ( data ) {
					var items = data.items || [];
					allItems = allItems.concat( items );

					if ( items.length >= perPage && page < ( data.pages || 1 ) ) {
						page++;
						return fetchPage();
					}
					return allItems;
				} );
		}

		return fetchPage();
	}

	/**
	 * Get color for a community ID.
	 *
	 * @param {number|string|null} communityId Community identifier.
	 * @return {string} Hex color.
	 */
	function getCommunityColor( communityId ) {
		if ( communityId === null || communityId === undefined || communityId === '' ) {
			return '#555570';
		}
		return PALETTE[ parseInt( communityId, 10 ) % PALETTE.length ];
	}

	/**
	 * Get edge line style based on confidence level.
	 *
	 * @param {string} confidence Confidence label.
	 * @return {string} Cytoscape line style.
	 */
	function getEdgeLineStyle( confidence ) {
		if ( confidence === 'INFERRED' ) {
			return 'dashed';
		}
		if ( confidence === 'AMBIGUOUS' ) {
			return 'dotted';
		}
		return 'solid';
	}

	/**
	 * Truncate a label for display.
	 *
	 * @param {string} label Full label.
	 * @param {number} max   Maximum length.
	 * @return {string} Truncated string.
	 */
	function truncateLabel( label, max ) {
		if ( ! label ) {
			return '';
		}
		if ( label.length <= max ) {
			return label;
		}
		return label.substring( 0, max - 1 ) + '\u2026';
	}

	/**
	 * Build community legend in the sidebar.
	 *
	 * @param {Object} communities Map of community_id → { label, count, color }.
	 */
	function buildLegend( communities ) {
		var container = document.getElementById( 'nvoos-graphify-community-legend' );
		if ( ! container ) {
			return;
		}

		// Keep the h3.
		var heading = container.querySelector( 'h3' );
		container.innerHTML = '';
		if ( heading ) {
			container.appendChild( heading );
		}

		var keys = Object.keys( communities );
		keys.sort( function ( a, b ) {
			return ( communities[ b ].count || 0 ) - ( communities[ a ].count || 0 );
		} );

		keys.forEach( function ( cid ) {
			var comm = communities[ cid ];
			var item = document.createElement( 'div' );
			item.className = 'nvoos-graphify-community-legend-item';
			item.setAttribute( 'data-community', cid );

			var dot = document.createElement( 'span' );
			dot.className = 'nvoos-graphify-legend-dot';
			dot.style.backgroundColor = comm.color;

			var label = document.createElement( 'span' );
			label.className = 'nvoos-graphify-legend-label';
			label.textContent = truncateLabel( comm.label, 30 );

			var count = document.createElement( 'span' );
			count.className = 'nvoos-graphify-legend-count';
			count.textContent = comm.count;

			item.appendChild( dot );
			item.appendChild( label );
			item.appendChild( count );

			// Click to toggle visibility.
			item.addEventListener( 'click', function () {
				var isHidden = item.classList.contains( 'nvoos-graphify-hidden' );
				item.classList.toggle( 'nvoos-graphify-hidden' );

				if ( cy ) {
					var selector = '[community = "' + cid + '"]';
					var nodes = cy.nodes( selector );
					var connectedEdges = nodes.connectedEdges();
					if ( isHidden ) {
						nodes.style( 'display', 'element' );
						connectedEdges.style( 'display', 'element' );
					} else {
						nodes.style( 'display', 'none' );
						connectedEdges.style( 'display', 'none' );
					}
				}
			} );

			container.appendChild( item );
		} );
	}

	/**
	 * Show node details in the sidebar.
	 *
	 * @param {Object} nodeData Cytoscape node data object.
	 */
	function showNodeInfo( nodeData ) {
		var panel = document.getElementById( 'nvoos-graphify-node-info' );
		if ( ! panel ) {
			return;
		}

		var html = '<h4>' + escapeHtml( nodeData.label || nodeData.id ) + '</h4>';

		html += '<div class="nvoos-graphify-meta-row">' +
			'<span class="nvoos-graphify-meta-label">Type</span>' +
			'<span class="nvoos-graphify-meta-value">' + escapeHtml( nodeData.type || '' ) + '</span>' +
			'</div>';

		html += '<div class="nvoos-graphify-meta-row">' +
			'<span class="nvoos-graphify-meta-label">Degree</span>' +
			'<span class="nvoos-graphify-meta-value">' + ( nodeData.degree || 0 ) + '</span>' +
			'</div>';

		html += '<div class="nvoos-graphify-meta-row">' +
			'<span class="nvoos-graphify-meta-label">Community</span>' +
			'<span class="nvoos-graphify-meta-value">' + ( nodeData.community !== null ? nodeData.community : '\u2014' ) + '</span>' +
			'</div>';

		if ( nodeData.source_url ) {
			html += '<div class="nvoos-graphify-meta-row">' +
				'<a href="' + escapeHtml( nodeData.source_url ) + '" target="_blank">View Source &rarr;</a>' +
				'</div>';
		}

		// Fetch neighbors via API.
		apiRequest( 'nodes/' + encodeURIComponent( nodeData.id ) )
			.then( function ( data ) {
				if ( data && data.neighbors && data.neighbors.length > 0 ) {
					html += '<div class="nvoos-graphify-neighbors-title">Neighbors (' + data.neighbors.length + ')</div>';
					var maxShow = Math.min( data.neighbors.length, 15 );
					for ( var i = 0; i < maxShow; i++ ) {
						var n = data.neighbors[ i ];
						var neighborLabel = n.neighbor_label || n.target_node_id || n.source_node_id || '';
						html += '<div class="nvoos-graphify-neighbor-item">' +
							escapeHtml( neighborLabel ) +
							' <span style="color:#555570">(' + escapeHtml( n.relation || '' ) + ')</span>' +
							'</div>';
					}
					if ( data.neighbors.length > maxShow ) {
						html += '<div class="nvoos-graphify-neighbor-item" style="color:#6b6b80">' +
							'+ ' + ( data.neighbors.length - maxShow ) + ' more\u2026</div>';
					}
				}
				panel.innerHTML = html;
			} )
			.catch( function () {
				panel.innerHTML = html;
			} );

		panel.innerHTML = html;
	}

	/**
	 * Escape HTML entities.
	 *
	 * @param {string} str Raw string.
	 * @return {string} Escaped string.
	 */
	function escapeHtml( str ) {
		if ( ! str ) {
			return '';
		}
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	/**
	 * Run the Cytoscape layout.
	 */
	function runLayout() {
		if ( ! cy ) {
			return;
		}

		var layoutName = 'cose';
		// Use fcose if available.
		if ( cy.layout && typeof cy.constructor.prototype.layout === 'function' ) {
			try {
				cy.layout( { name: 'fcose' } );
				layoutName = 'fcose';
			} catch ( e ) {
				layoutName = 'cose';
			}
		}

		cy.layout( {
			name: layoutName,
			animate: true,
			animationDuration: 500,
			nodeRepulsion: 8000,
			idealEdgeLength: 80,
			gravity: 0.25,
			numIter: 1000,
			padding: 40,
		} ).run();
	}

	/**
	 * Initialize the explorer.
	 */
	function initExplorer() {
		var container = document.getElementById( 'nvoos-graphify-explorer' );
		if ( ! container ) {
			return;
		}

		if ( typeof cytoscape === 'undefined' ) {
			container.innerHTML = '<div style="padding:40px;color:#ef4444;">Cytoscape.js library not loaded.</div>';
			return;
		}

		var maxNodes = nvoosGraphifyConfig.maxNodes || 2000;

		// Fetch nodes and edges in parallel.
		Promise.all( [
			fetchAllPages( 'nodes', 100 ),
			fetchAllPages( 'edges', 100 ),
		] ).then( function ( results ) {
			var rawNodes = results[ 0 ] || [];
			var rawEdges = results[ 1 ] || [];

			// Enforce max nodes limit.
			var truncated = false;
			if ( rawNodes.length > maxNodes ) {
				// Already sorted by degree DESC from API.
				rawNodes = rawNodes.slice( 0, maxNodes );
				truncated = true;
			}

			if ( truncated ) {
				var sidebar = document.getElementById( 'nvoos-graphify-sidebar' );
				if ( sidebar ) {
					var warn = document.createElement( 'div' );
					warn.className = 'nvoos-graphify-warning';
					warn.textContent = 'Showing top ' + maxNodes + ' nodes by degree. ' +
						rawNodes.length + ' of ' + results[ 0 ].length + ' total nodes displayed.';
					sidebar.insertBefore( warn, sidebar.firstChild.nextSibling );
				}
			}

			// Build node ID set for edge filtering.
			var nodeIdSet = {};
			rawNodes.forEach( function ( n ) {
				nodeIdSet[ n.node_id ] = true;
			} );

			// Convert to Cytoscape elements.
			var elements = [];

			rawNodes.forEach( function ( n ) {
				var cid = n.community_id !== null && n.community_id !== undefined ? String( n.community_id ) : '';
				var color = getCommunityColor( n.community_id );

				if ( ! communityMap[ cid ] && cid !== '' ) {
					communityMap[ cid ] = {
						label: n.label || 'Community ' + cid,
						count: 0,
						color: color,
					};
				}
				if ( communityMap[ cid ] ) {
					communityMap[ cid ].count++;
				}

				elements.push( {
					group: 'nodes',
					data: {
						id: n.node_id,
						label: n.label || n.node_id,
						type: n.node_type || '',
						community: cid,
						degree: parseInt( n.degree, 10 ) || 0,
						source_url: n.source_url || '',
						color: color,
					},
				} );
			} );

			rawEdges.forEach( function ( e ) {
				// Only include edges where both nodes exist.
				if ( ! nodeIdSet[ e.source_node_id ] || ! nodeIdSet[ e.target_node_id ] ) {
					return;
				}
				elements.push( {
					group: 'edges',
					data: {
						source: e.source_node_id,
						target: e.target_node_id,
						relation: e.relation || '',
						confidence: e.confidence || 'EXTRACTED',
						confidence_score: parseFloat( e.confidence_score ) || 1.0,
					},
				} );
			} );

			// Initialize Cytoscape.
			cy = cytoscape( {
				container: container,
				elements: elements,
				style: [
					{
						selector: 'node',
						style: {
							'background-color': 'data(color)',
							'label': labelsVisible ? 'data(label)' : '',
							'width': 'mapData(degree, 0, 50, 20, 60)',
							'height': 'mapData(degree, 0, 50, 20, 60)',
							'font-size': '10px',
							'color': '#e0e0e0',
							'text-outline-color': '#0a0a14',
							'text-outline-width': 2,
							'text-valign': 'bottom',
							'text-margin-y': 4,
							'text-max-width': '80px',
							'text-wrap': 'ellipsis',
							'overlay-opacity': 0,
						},
					},
					{
						selector: 'node:selected',
						style: {
							'border-width': 3,
							'border-color': '#ffffff',
						},
					},
					{
						selector: 'edge',
						style: {
							'width': 1,
							'line-color': 'rgba(255,255,255,0.15)',
							'target-arrow-color': 'rgba(255,255,255,0.15)',
							'target-arrow-shape': 'triangle',
							'arrow-scale': 0.6,
							'curve-style': 'bezier',
						},
					},
					{
						selector: 'edge[confidence = "INFERRED"]',
						style: {
							'line-style': 'dashed',
							'line-dash-pattern': [ 6, 3 ],
						},
					},
					{
						selector: 'edge[confidence = "AMBIGUOUS"]',
						style: {
							'line-style': 'dotted',
							'line-dash-pattern': [ 2, 4 ],
							'opacity': 0.5,
						},
					},
					{
						selector: 'edge[confidence = "EXTRACTED"]',
						style: {
							'line-style': 'solid',
						},
					},
				],
				layout: {
					name: 'cose',
					animate: false,
					nodeRepulsion: 8000,
					idealEdgeLength: 80,
					gravity: 0.25,
					numIter: 1000,
					padding: 40,
				},
				wheelSensitivity: 0.3,
				minZoom: 0.1,
				maxZoom: 5,
			} );

			// Node click.
			cy.on( 'tap', 'node', function ( evt ) {
				showNodeInfo( evt.target.data() );
			} );

			// Click background to deselect.
			cy.on( 'tap', function ( evt ) {
				if ( evt.target === cy ) {
					var panel = document.getElementById( 'nvoos-graphify-node-info' );
					if ( panel ) {
						panel.innerHTML = '<p class="nvoos-graphify-placeholder">Click a node to see details.</p>';
					}
				}
			} );

			// Build legend.
			buildLegend( communityMap );

			// Bind toolbar.
			bindToolbar();
			bindSearch();

		} ).catch( function ( err ) {
			container.innerHTML = '<div style="padding:40px;color:#ef4444;">Failed to load graph data: ' +
				escapeHtml( err.message || 'Unknown error' ) + '</div>';
		} );
	}

	/**
	 * Bind toolbar button events.
	 */
	function bindToolbar() {
		var fitBtn = document.getElementById( 'nvoos-graphify-fit-btn' );
		var labelsBtn = document.getElementById( 'nvoos-graphify-labels-btn' );
		var relayoutBtn = document.getElementById( 'nvoos-graphify-relayout-btn' );

		if ( fitBtn ) {
			fitBtn.addEventListener( 'click', function () {
				if ( cy ) {
					cy.fit( null, 40 );
				}
			} );
		}

		if ( labelsBtn ) {
			labelsBtn.addEventListener( 'click', function () {
				labelsVisible = ! labelsVisible;
				if ( cy ) {
					cy.style()
						.selector( 'node' )
						.style( 'label', labelsVisible ? 'data(label)' : '' )
						.update();
				}
				labelsBtn.textContent = labelsVisible ? 'Labels' : 'Labels (off)';
			} );
		}

		if ( relayoutBtn ) {
			relayoutBtn.addEventListener( 'click', function () {
				runLayout();
			} );
		}
	}

	/**
	 * Bind search input behavior.
	 */
	function bindSearch() {
		var searchInput = document.getElementById( 'nvoos-graphify-search-input' );
		if ( ! searchInput || ! cy ) {
			return;
		}

		var debounceTimer = null;

		searchInput.addEventListener( 'input', function () {
			if ( debounceTimer ) {
				clearTimeout( debounceTimer );
			}

			debounceTimer = setTimeout( function () {
				var query = searchInput.value.trim().toLowerCase();

				if ( ! query ) {
					// Reset all node styles.
					cy.nodes().style( 'opacity', 1 );
					cy.edges().style( 'opacity', 1 );
					return;
				}

				// Dim all, highlight matches.
				cy.nodes().style( 'opacity', 0.15 );
				cy.edges().style( 'opacity', 0.05 );

				var matched = cy.nodes().filter( function ( node ) {
					var label = ( node.data( 'label' ) || '' ).toLowerCase();
					return label.indexOf( query ) !== -1;
				} );

				matched.style( 'opacity', 1 );
				matched.connectedEdges().style( 'opacity', 0.6 );
				matched.neighborhood( 'node' ).style( 'opacity', 0.5 );

				// Zoom to first match.
				if ( matched.length > 0 ) {
					cy.animate( {
						fit: { eles: matched, padding: 80 },
						duration: 400,
					} );
				}
			}, 250 );
		} );
	}

	// Boot on DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initExplorer );
	} else {
		initExplorer();
	}
} )();
