/**
 * NV oOS Graphify — Interactive Graph Explorer
 *
 * @package NV_oOS_Graphify
 * @since   0.2.0
 */
( function( $ ) {
	'use strict';

	// Main explorer object
	var GraphifyExplorer = {
		cy: null,
		container: null,
		data: null,

		// 10-color community palette (matching Graphify's)
		communityColors: [
			'#1f77b4', '#ff7f0e', '#2ca02c', '#d62728', '#9467bd',
			'#8c564b', '#e377c2', '#7f7f7f', '#bcbd22', '#17becf',
		],

		init: function() {
			this.container = document.getElementById( 'nvoos-graphify-explorer-canvas' );
			if ( ! this.container ) {
				return;
			}

			this.data = window.nvoos_graphify_explorer || {};
			if ( ! this.data.nodes || ! this.data.edges ) {
				return;
			}

			this.initCytoscape();
			this.initSidebar();
			this.initSearch();
			this.initFilters();
			this.initControls();
		},

		initCytoscape: function() {
			var self = this;

			this.cy = cytoscape( {
				container: this.container,
				elements: {
					nodes: this.data.nodes,
					edges: this.data.edges,
				},
				style: [
					{
						selector: 'node',
						style: {
							'label': '',
							'background-color': function( ele ) {
								var cid = ele.data( 'community' ) || 0;
								return self.communityColors[ cid % 10 ];
							},
							'width': 'mapData(degree, 0, 50, 20, 60)',
							'height': 'mapData(degree, 0, 50, 20, 60)',
							'font-size': '11px',
							'color': '#e0e0e0',
							'text-outline-width': 2,
							'text-outline-color': '#0f0f1a',
							'text-valign': 'bottom',
							'text-margin-y': 5,
							'border-width': 0,
							'border-color': '#00d4ff',
							'transition-property': 'border-width, opacity',
							'transition-duration': '0.15s',
						},
					},
					{
						selector: 'node:active',
						style: {
							'overlay-opacity': 0,
						},
					},
					{
						selector: 'node.highlighted',
						style: {
							'border-width': 3,
							'border-color': '#00d4ff',
						},
					},
					{
						selector: 'node.faded',
						style: {
							'opacity': 0.15,
						},
					},
					{
						selector: 'node.search-match',
						style: {
							'border-width': 3,
							'border-color': '#ffcc00',
						},
					},
					{
						selector: 'edge',
						style: {
							'width': 1,
							'line-color': '#555',
							'target-arrow-color': '#555',
							'target-arrow-shape': 'triangle',
							'curve-style': 'bezier',
							'opacity': 0.5,
						},
					},
					{
						selector: 'edge[confidence = "INFERRED"]',
						style: {
							'line-style': 'dashed',
						},
					},
					{
						selector: 'edge[confidence = "AMBIGUOUS"]',
						style: {
							'line-style': 'dotted',
						},
					},
					{
						selector: 'edge.highlighted',
						style: {
							'line-color': '#00d4ff',
							'target-arrow-color': '#00d4ff',
							'opacity': 1,
							'width': 2,
						},
					},
					{
						selector: 'edge.faded',
						style: {
							'opacity': 0.08,
						},
					},
					{
						selector: 'edge:active',
						style: {
							'overlay-opacity': 0,
						},
					},
				],
				layout: {
					name: 'fcose',
					animate: true,
					randomize: true,
					idealEdgeLength: 120,
					nodeRepulsion: 5000,
					edgeElasticity: 0.45,
					nestingFactor: 0.1,
					gravity: 0.25,
					numIter: 2500,
					padding: 30,
				},
				userZoomingEnabled: true,
				userPanningEnabled: true,
				boxSelectionEnabled: false,
				minZoom: 0.1,
				maxZoom: 5,
			} );

			// Tap on node
			this.cy.on( 'tap', 'node', function( evt ) {
				self.selectNode( evt.target );
			} );

			// Tap on background
			this.cy.on( 'tap', function( evt ) {
				if ( evt.target === self.cy ) {
					self.clearSelection();
				}
			} );

			// Mouseover node — show label
			this.cy.on( 'mouseover', 'node', function( evt ) {
				var node = evt.target;
				var label = node.data( 'label' ) || '';
				if ( label.length > 20 ) {
					label = label.substring( 0, 20 ) + '\u2026';
				}
				node.style( 'label', label );
			} );

			this.cy.on( 'mouseout', 'node', function( evt ) {
				var node = evt.target;
				if ( ! node.hasClass( 'highlighted' ) ) {
					node.style( 'label', '' );
				}
			} );

			// Double-click node — open source URL
			this.cy.on( 'dbltap', 'node', function( evt ) {
				var url = evt.target.data( 'source_url' );
				if ( url ) {
					window.open( url, '_blank' );
				}
			} );

			// Mouseover edge
			this.cy.on( 'mouseover', 'edge', function( evt ) {
				evt.target.style( { 'line-color': '#888', 'target-arrow-color': '#888' } );
			} );

			this.cy.on( 'mouseout', 'edge', function( evt ) {
				if ( ! evt.target.hasClass( 'highlighted' ) ) {
					evt.target.style( { 'line-color': '#555', 'target-arrow-color': '#555' } );
				}
			} );
		},

		selectNode: function( node ) {
			this.clearSelection();

			node.addClass( 'highlighted' );
			var label = node.data( 'label' ) || '';
			if ( label.length > 20 ) {
				label = label.substring( 0, 20 ) + '\u2026';
			}
			node.style( 'label', label );

			var neighborhood = node.neighborhood();
			neighborhood.addClass( 'highlighted' );
			this.cy.elements().not( neighborhood ).not( node ).addClass( 'faded' );

			this.showNodeInfo( node );
		},

		clearSelection: function() {
			this.cy.elements().removeClass( 'highlighted faded' );
			this.cy.nodes().style( 'label', '' );
			$( '#nvoos-graphify-node-info' ).html( '<p class="nvoos-graphify-sidebar-empty">Click a node to view details.</p>' );
		},

		showNodeInfo: function( node ) {
			var d = node.data();
			var neighbors = node.neighborhood( 'node' );
			var html = '';

			html += '<div class="nvoos-graphify-node-info">';
			html += '<div class="node-title">' + this.escHtml( d.label || d.id ) + '</div>';

			html += '<dl class="node-meta">';
			html += '<dt>Type</dt><dd>' + this.escHtml( d.type || 'unknown' ) + '</dd>';
			html += '<dt>Community</dt><dd>' + ( d.community !== undefined ? d.community : '—' ) + '</dd>';
			html += '<dt>Degree</dt><dd>' + ( d.degree !== undefined ? d.degree : node.degree() ) + '</dd>';
			if ( d.source_url ) {
				html += '<dt>URL</dt><dd><a href="' + this.escHtml( d.source_url ) + '" target="_blank" style="color:#00d4ff;">View &rarr;</a></dd>';
			}
			html += '</dl>';

			if ( d.edit_url ) {
				html += '<a class="nvoos-graphify-open-wp" href="' + this.escHtml( d.edit_url ) + '" target="_blank">Open in WordPress</a>';
			}

			if ( neighbors.length > 0 ) {
				html += '<h4 style="color:#888;font-size:11px;text-transform:uppercase;margin:14px 0 6px;">Neighbors (' + neighbors.length + ')</h4>';
				html += '<ul class="node-neighbors">';
				var self = this;
				neighbors.forEach( function( n ) {
					var edge = node.edgesWith( n );
					var relation = edge.length > 0 ? ( edge[ 0 ].data( 'relation' ) || '' ) : '';
					html += '<li data-node-id="' + self.escHtml( n.id() ) + '">';
					html += self.escHtml( n.data( 'label' ) || n.id() );
					if ( relation ) {
						html += '<span class="neighbor-relation">(' + self.escHtml( relation ) + ')</span>';
					}
					html += '</li>';
				} );
				html += '</ul>';
			}

			html += '</div>';
			$( '#nvoos-graphify-node-info' ).html( html );

			// Clicking neighbor focuses it
			var explorer = this;
			$( '#nvoos-graphify-node-info .node-neighbors li' ).on( 'click', function() {
				var nid = $( this ).data( 'node-id' );
				var target = explorer.cy.getElementById( nid );
				if ( target.length ) {
					explorer.cy.animate( { center: { eles: target }, zoom: 2 }, { duration: 300 } );
					explorer.selectNode( target );
				}
			} );
		},

		initSidebar: function() {
			$( '#nvoos-graphify-node-info' ).html( '<p class="nvoos-graphify-sidebar-empty">Click a node to view details.</p>' );
		},

		initSearch: function() {
			var self = this;
			$( '#nvoos-graphify-search-input' ).on( 'input', function() {
				var query = $( this ).val().toLowerCase().trim();
				self.cy.nodes().removeClass( 'search-match' );

				if ( ! query ) {
					return;
				}

				self.cy.nodes().forEach( function( node ) {
					var label = ( node.data( 'label' ) || '' ).toLowerCase();
					if ( label.indexOf( query ) !== -1 ) {
						node.addClass( 'search-match' );
					}
				} );
			} );
		},

		initFilters: function() {
			var self = this;

			// Node type filters
			$( '.nvoos-graphify-filter-type' ).on( 'change', function() {
				self.applyFilters();
			} );

			// Community filter
			$( '#nvoos-graphify-filter-community' ).on( 'change', function() {
				self.applyFilters();
			} );

			// Confidence filter
			$( '.nvoos-graphify-filter-confidence' ).on( 'change', function() {
				self.applyFilters();
			} );
		},

		applyFilters: function() {
			var activeTypes = [];
			$( '.nvoos-graphify-filter-type:checked' ).each( function() {
				activeTypes.push( $( this ).val() );
			} );

			var communityFilter = $( '#nvoos-graphify-filter-community' ).val();

			var activeConfidences = [];
			$( '.nvoos-graphify-filter-confidence:checked' ).each( function() {
				activeConfidences.push( $( this ).val() );
			} );

			// Filter nodes
			this.cy.nodes().forEach( function( node ) {
				var show = true;
				var nodeType = node.data( 'type' ) || '';
				var nodeCommunity = node.data( 'community' );

				if ( activeTypes.length > 0 && activeTypes.indexOf( nodeType ) === -1 ) {
					show = false;
				}
				if ( communityFilter && communityFilter !== '' && String( nodeCommunity ) !== communityFilter ) {
					show = false;
				}

				if ( show ) {
					node.style( 'display', 'element' );
				} else {
					node.style( 'display', 'none' );
				}
			} );

			// Filter edges by confidence
			if ( activeConfidences.length > 0 ) {
				this.cy.edges().forEach( function( edge ) {
					var conf = edge.data( 'confidence' ) || 'EXTRACTED';
					if ( activeConfidences.indexOf( conf ) !== -1 ) {
						edge.style( 'display', 'element' );
					} else {
						edge.style( 'display', 'none' );
					}
				} );
			} else {
				this.cy.edges().style( 'display', 'element' );
			}
		},

		initControls: function() {
			var self = this;

			$( '#nvoos-graphify-zoom-in' ).on( 'click', function() {
				self.cy.zoom( { level: self.cy.zoom() * 1.3, renderedPosition: { x: self.cy.width() / 2, y: self.cy.height() / 2 } } );
			} );

			$( '#nvoos-graphify-zoom-out' ).on( 'click', function() {
				self.cy.zoom( { level: self.cy.zoom() / 1.3, renderedPosition: { x: self.cy.width() / 2, y: self.cy.height() / 2 } } );
			} );

			$( '#nvoos-graphify-fit' ).on( 'click', function() {
				self.cy.fit( undefined, 30 );
			} );

			$( '#nvoos-graphify-relayout' ).on( 'click', function() {
				self.cy.layout( {
					name: 'fcose',
					animate: true,
					randomize: false,
					idealEdgeLength: 120,
					nodeRepulsion: 5000,
					edgeElasticity: 0.45,
					numIter: 2500,
					padding: 30,
				} ).run();
			} );

			$( '#nvoos-graphify-export-png' ).on( 'click', function() {
				var png = self.cy.png( { full: true, scale: 2, bg: '#0f0f1a' } );
				var a = document.createElement( 'a' );
				a.href = png;
				a.download = 'graphify-export.png';
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
			} );
		},

		escHtml: function( str ) {
			var div = document.createElement( 'div' );
			div.appendChild( document.createTextNode( str ) );
			return div.innerHTML;
		},
	};

	$( document ).ready( function() {
		GraphifyExplorer.init();
	} );
} )( jQuery );
