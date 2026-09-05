/* jshint esversion: 6 */
/**
 * NV oOS Content Graph — Admin Graph Explorer
 *
 * Handles the Cytoscape.js graph explorer on the Knowledge Graph admin page
 * (themes, icons, legend, minimap, zoom cluster, layout presets, edge
 * styling, keyboard navigation, export), the "Rebuild Graph" button, and
 * the Appearance tab's color pickers / presets.
 *
 * All visual decisions are delegated to the shared theme engine
 * (assets/js/content-graph-theme.js), fed by the `visual` config object
 * localized server-side (NvoosContentGraph\Visual\Tokens::visual_config()).
 *
 * @package NvoosContentGraph
 * @since   0.5.0
 */
( function ( $ ) {
	'use strict';

	var cy = null;
	var config = window.nvoosContentGraphAdmin || {};
	var theme = window.nvoosContentGraphTheme || null;
	var visual = config.visual || {};
	var tokens = {};
	var typesSeen = {};
	var maxDegree = 1;
	var communitiesSeen = {};
	var viewSaveTimer = null;
	var minimapDirty = false;
	var edgeCountTotal = 0;
	var edgeCountRendered = 0;

	var EDGE_BUDGET = 2000;    // max rendered edges (perf budget).
	var DENSITY_AT  = 500;     // auto-density above this many edges.

	/**
	 * Humanize a slug ("shop_order" -> "Shop order").
	 */
	function humanizeSlug( slug ) {
		if ( ! slug ) {
			return '';
		}
		var spaced = slug.toString().replace( /[-_]+/g, ' ' ).trim();
		return spaced.charAt( 0 ).toUpperCase() + spaced.slice( 1 );
	}

	/**
	 * Re-render the legend for the current color encoding.
	 */
	function renderLegend( nodeSet ) {
		var $legend = $( '#nvoos-content-graph-legend' );
		if ( ! $legend.length ) {
			return;
		}
		if ( ! visual.show_legend ) {
			$legend.hide();
			return;
		}

		var html = '<h4 class="nvoos-cg-legend-title">' + ( ( config.i18n && config.i18n.legend ) || 'Legend' ) + '</h4>';
		var mode = visual.color_by || 'type';

		if ( mode === 'type' ) {
			html += '<ul class="nvoos-cg-legend-list">';
			$.each( typesSeen, function ( type ) {
				var color = theme.colorForType( type, visual );
				var icon = visual.show_icons ? theme.iconDataUri( visual, type, color, tokens ) : '';
				html += '<li class="nvoos-cg-legend-row" data-filter-type="' +
					$( '<div/>' ).text( type ).html() + '" tabindex="0">' +
					'<span class="nvoos-cg-legend-swatch" style="background:' + color + '">' +
					( icon ? '<img src="' + icon + '" alt="" aria-hidden="true">' : '' ) +
					'</span><span class="nvoos-cg-legend-label">' +
					$( '<div/>' ).text( ( config.type_labels && config.type_labels[ type ] ) || humanizeSlug( type ) ).html() +
					'</span></li>';
			} );
			html += '</ul>';
		} else if ( mode === 'community' ) {
			html += '<ul class="nvoos-cg-legend-list">';
			$.each( communitiesSeen, function ( cid ) {
				var color = theme.communityColor( cid, visual );
				html += '<li class="nvoos-cg-legend-row" data-filter-community="' +
					$( '<div/>' ).text( cid ).html() + '" tabindex="0">' +
					'<span class="nvoos-cg-legend-swatch" style="background:' + color + '"></span>' +
					'<span class="nvoos-cg-legend-label">' + $( '<div/>' ).text( cid || '—' ).html() + '</span></li>';
			} );
			html += '</ul>';
		} else if ( mode === 'degree' ) {
			var ramp = visual.degree_ramp || [ '#440154', '#3b528b', '#21918c', '#5ec962', '#fde725' ];
			var stops = '';
			$.each( ramp, function ( _, c ) {
				stops += '<span class="nvoos-cg-legend-ramp-stop" style="background:' + c + '"></span>';
			} );
			html += '<p class="nvoos-cg-legend-ramp">' + stops + '</p>' +
				'<p class="nvoos-cg-legend-note">1 — ' + maxDegree + ' connections</p>';
		} else {
			html += '<p class="nvoos-cg-legend-note">' + tokens.accent + ' — single accent color</p>';
		}

		$legend.html( html ).removeAttr( 'hidden' );
	}

	/**
	 * Recompute colors/icons/shapes for every node after a color-mode or
	 * theme change, then restyle.
	 */
	function restyleNodes() {
		if ( ! cy ) {
			return;
		}
		cy.batch( function () {
			cy.nodes().forEach( function ( n ) {
				var d = n.data();
				var color = theme.nodeColor( visual, d, maxDegree );
				d.color = color;
				d.icon = visual.show_icons ? theme.iconDataUri( visual, d.type, color, tokens ) : '';
				d.shape = ( visual.node_shapes && visual.shape_map && visual.shape_map[ d.type ] ) || 'ellipse';
				n.data( d );
			} );
			cy.edges().forEach( function ( e ) {
				e.data( 'edgeColor', theme.edgeFamilyColor( e.data( 'relation' ), tokens, visual ) );
			} );
		} );
		cy.style( theme.buildStylesheet( visual, { cy: cy } ) );
		theme.applyChrome( document.querySelector( '#nvoos-content-graph-explorer' ), visual );
		renderLegend();
		markMinimapDirty();
	}

	/**
	 * Apply the current edge style preset to edge element data.
	 */
	function applyEdgeStyle( edgeStyle ) {
		if ( ! cy ) {
			return;
		}
		var t = tokens;
		var mode = edgeStyle || visual.edge_style || 'plain';
		if ( mode === 'auto' ) {
			mode = edgeCountRendered > DENSITY_AT ? 'density' : 'plain';
		}

		cy.batch( function () {
			cy.edges().forEach( function ( e ) {
				var d = e.data();
				var confidence = parseFloat( d.confidence ) || 1;
				switch ( mode ) {
					case 'arrows':
						d.curve = 'bezier';
						d.arrow = 'triangle';
						d.edgeWidth = 1;
						break;
					case 'tapered':
						d.curve = 'bezier';
						d.arrow = 'triangle';
						d.edgeWidth = Math.max( 1, Math.min( 4, 1 + confidence * 3 ) );
						break;
					case 'density':
						d.curve = 'haystack';
						d.arrow = 'none';
						d.edgeWidth = 1;
						break;
					case 'plain':
					default:
						d.curve = 'bezier';
						d.arrow = 'none';
						d.edgeWidth = 1;
						break;
				}
				e.data( d );
			} );
		} );
	}

	/**
	 * Apply the edge-label mode (off / hover / always).
	 */
	function applyEdgeLabels( mode ) {
		if ( ! cy ) {
			return;
		}
		mode = mode || visual.edge_labels || 'hover';
		cy.batch( function () {
			cy.edges().removeClass( 'edge-label-on' );
			cy.edges().forEach( function ( e ) {
				e.data( 'edgeLabel', mode === 'always' ? e.data( 'relation' ) : '' );
			} );
		} );
	}

	/**
	 * Repopulate the "type" filter dropdown from the types actually present.
	 */
	function populateTypeFilter() {
		var $select = $( '#nvoos-content-graph-type-filter' );
		if ( ! $select.length ) {
			return;
		}

		var labels = config.type_labels || {};
		var i18n = config.i18n || {};
		var allTypesLabel = i18n.all_types || 'All types';
		var previous = $select.val();

		var slugs = [];
		for ( var key in typesSeen ) {
			if ( Object.prototype.hasOwnProperty.call( typesSeen, key ) ) {
				slugs.push( key );
			}
		}

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

		if ( previous && typesSeen[ previous ] ) {
			$select.val( previous );
		}
	}

	/**
	 * Load nodes and edges from the REST API, then initialise Cytoscape.
	 */
	function loadGraph() {
		var $container = $( '#nvoos-content-graph-explorer' );
		if ( ! $container.length || ! theme ) {
			return;
		}

		$container.html( '<p style="padding:20px;color:#888;">' + ( ( config.i18n && config.i18n.loading ) || 'Loading graph…' ) + '</p>' );

		$.ajax( {
			url:     config.rest_url + '/nodes',
			method:  'GET',
			headers: { 'X-WP-Nonce': config.nonce },
			data:    { per_page: config.max_nodes || 300 }
		} ).done( function ( nodes ) {
			var nodeIds = {};
			var elements = [];

			typesSeen = {};
			maxDegree = 1;

			$.each( nodes, function ( _, n ) {
				nodeIds[ n.node_id ] = true;
				if ( n.type ) {
					typesSeen[ n.type ] = true;
				}
				var degree = parseInt( n.degree, 10 ) || 1;
				if ( degree > maxDegree ) {
					maxDegree = degree;
				}
				if ( n.community_id ) {
					communitiesSeen[ n.community_id ] = true;
				}

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
						degree:       degree,
						community_id: n.community_id || '',
						url:          n.url || '',
						agent_id:     props.agent_id || '',
						wing:         props.wing || '',
						room:         props.room || ''
					}
				} );
			} );

			// Load edges so relationship styling works up front.
			edgeCountRendered = 0;
			communitiesSeen = {};
			$.ajax( {
				url:     config.rest_url + '/edges',
				method:  'GET',
				headers: { 'X-WP-Nonce': config.nonce },
				data:    { per_page: EDGE_BUDGET, page: 1 }
			} ).done( function ( edges ) {
				edgeCountTotal = edges.length;
				$.each( edges, function ( _, e ) {
					if ( edgeCountRendered >= EDGE_BUDGET ) {
						return;
					}
					if ( ! nodeIds[ e.source_node_id ] || ! nodeIds[ e.target_node_id ] ) {
						return; // Never create orphan endpoints.
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
					edgeCountRendered++;
				} );
				populateTypeFilter();
				initCytoscape( $container, elements );
			} ).fail( function () {
				populateTypeFilter();
				initCytoscape( $container, elements );
			} );
		} ).fail( function () {
			$container.html( '<p style="padding:20px;color:#c00;">' + ( ( config.i18n && config.i18n.load_error ) || 'Failed to load graph data. Ensure the graph has been built.' ) + '</p>' );
		} );
	}

	/**
	 * Initialise the Cytoscape instance with theme-engine styling.
	 */
	function initCytoscape( $container, elements ) {
		$container.html( '' );

		if ( typeof window.cytoscape === 'undefined' ) {
			$container.html( '<p style="padding:20px;color:#c00;">' + ( ( config.i18n && config.i18n.cy_missing ) || 'Cytoscape.js did not load. Check your network connection.' ) + '</p>' );
			return;
		}

		tokens = theme.tokens( visual );

		// Resolve colors/icons/shapes/sizes up front (one pass, batched).
		$.each( elements, function ( _, el ) {
			var d = el.data;
			d.color = theme.nodeColor( visual, d, maxDegree );
			d.icon = visual.show_icons ? theme.iconDataUri( visual, d.type, d.color, tokens ) : '';
			d.shape = ( visual.node_shapes && visual.shape_map && visual.shape_map[ d.type ] ) || 'ellipse';
			d.size = theme.nodeSize( d.degree, maxDegree, visual );
			if ( d.source ) {
				d.edgeColor = theme.edgeFamilyColor( d.relation, tokens, visual );
			}
		} );

		cy = window.cytoscape( {
			container: $container[ 0 ],
			elements:  elements,
			style:     theme.buildStylesheet( visual, { cy: null } ), // placeholder, restyled below.
			// Perf knobs for large graphs (texture-on-viewport rendering,
			// 1:1 pixel ratio, edge culling outside the viewport).
			pixelRatio: 1,
			textureOnViewport: true,
			hideEdgesOnViewport: true,
			layout:    theme.layoutPresets( visual )[ 'fcose-balanced' ].options
		} );

		cy.style( theme.buildStylesheet( visual, { cy: cy } ) );

		applyEdgeStyle();
		applyEdgeLabels();

		theme.applyChrome( $container[ 0 ], visual );

		$container.find( '#nvoos-content-graph-legend' ).length === 0 && $container.prepend(
			'<div id="nvoos-content-graph-legend" class="nvoos-cg-legend" hidden></div>' +
			'<div id="nvoos-content-graph-minimap" class="nvoos-cg-minimap" hidden><canvas id="nvoos-content-graph-minimap-canvas" width="160" height="100"></canvas></div>'
		);

		renderLegend();
		initMinimap();
		bindCytoscapeEvents();
		restoreView();
	}

	/**
	 * Bind Cytoscape interaction events.
	 */
	function bindCytoscapeEvents() {
		if ( ! cy ) {
			return;
		}

		// Click background: clear selection and filters.
		cy.on( 'tap', function ( e ) {
			if ( e.target === cy ) {
				cy.elements().removeClass( 'faded highlighted' );
				cy.edges().removeClass( 'edge-label-on' );
				$( '#nvoos-content-graph-sidebar' ).hide();
			}
		} );

		// Click node: show info and highlight connections.
		cy.on( 'tap', 'node', function ( e ) {
			loadNodeDetails( e.target.id() );
		} );

		// Hover/tap edge: reveal relation label (hover mode).
		if ( ( visual.edge_labels || 'hover' ) === 'hover' ) {
			cy.on( 'mouseover', 'edge', function ( e ) { e.target.addClass( 'edge-label-on' ); } );
			cy.on( 'mouseout', 'edge', function ( e ) { e.target.removeClass( 'edge-label-on' ); } );
		}

		cy.on( 'zoom pan', function () {
			$( '#nvoos-content-graph-zoom-badge' ).text( Math.round( cy.zoom() * 100 ) + '%' );
			markMinimapDirty();
			scheduleViewSave();
		} );

		cy.on( 'render', markMinimapDirty );

		cy.on( 'layoutstop', function () {
			markMinimapDirty();
			// Only restore once (first layout).
			restorePendingView();
		} );
	}

	/**
	 * Load a node's details and neighbors, show sidebar, highlight connections.
	 */
	function loadNodeDetails( nodeId ) {
		$.ajax( {
			url:     config.rest_url + '/nodes/' + encodeURIComponent( nodeId ),
			method:  'GET',
			headers: { 'X-WP-Nonce': config.nonce }
		} ).done( function ( data ) {
			var n    = data.node;
			var nbrs = data.neighbors || [];
			var $sb  = $( '#nvoos-content-graph-sidebar' );

			// Add neighbor nodes/edges if not already present.
			$.each( nbrs, function ( _, nbr ) {
				if ( cy.$( '#' + nbr.node_id ).length === 0 ) {
					cy.add( {
						data: {
							id:     nbr.node_id,
							label:  nbr.label,
							type:   nbr.type,
							degree: 1,
							color:  theme.nodeColor( visual, { type: nbr.type, degree: 1 }, maxDegree ),
							icon:   visual.show_icons ? theme.iconDataUri( visual, nbr.type, null, tokens ) : '',
							shape:  ( visual.node_shapes && visual.shape_map && visual.shape_map[ nbr.type ] ) || 'ellipse',
							size:   theme.nodeSize( 1, maxDegree, visual ),
							url:    ''
						}
					} );
					if ( nbr.type ) {
						typesSeen[ nbr.type ] = true;
					}
				}
				var edgeId = 'e_' + nodeId + '_' + nbr.node_id + '_' + nbr.relation;
				if ( cy.$( '#' + edgeId ).length === 0 ) {
					cy.add( {
						data: {
							id:         edgeId,
							source:     nodeId,
							target:     nbr.node_id,
							relation:   nbr.relation,
							confidence: 1,
							edgeColor:  theme.edgeFamilyColor( nbr.relation, tokens, visual )
						}
					} );
				}
			} );

			applyEdgeStyle();
			applyEdgeLabels();

			// Fade all except this node and its neighborhood.
			cy.elements().addClass( 'faded' ).removeClass( 'highlighted' );
			var neighborhood = cy.$( '#' + nodeId ).closedNeighborhood();
			neighborhood.removeClass( 'faded' ).addClass( 'highlighted' );
			if ( ( visual.edge_labels || 'hover' ) === 'hover' ) {
				cy.$( '#' + nodeId ).connectedEdges().addClass( 'edge-label-on' );
			}

			var i18n = config.i18n || {};
			var safeUrl = ( n.url && /^https?:\/\//i.test( String( n.url ) ) ) ? String( n.url ) : '';
			var urlHtml = safeUrl ? '<p><a href="' + $( '<span>' ).text( safeUrl ).html() + '" target="_blank" rel="noopener noreferrer">' + ( i18n.view_post || 'View post ↗' ) + '</a></p>' : '';
			var nbrHtml = '';
			$.each( nbrs.slice( 0, 10 ), function ( _, nbr ) {
				nbrHtml += '<li><strong>' + $( '<span>' ).text( nbr.label ).html() + '</strong> <em>' + $( '<span>' ).text( nbr.relation ).html() + '</em></li>';
			} );

			$sb.html(
				'<h3>' + $( '<span>' ).text( n.label ).html() + '</h3>' +
				'<p><em>' + $( '<span>' ).text( n.type ).html() + '</em> &bull; ' + parseInt( n.degree, 10 ) + ' ' + ( i18n.connections || 'connections' ) + '</p>' +
				( n.community_id ? '<p>' + ( i18n.community || 'Community' ) + ': <code>' + $( '<span>' ).text( n.community_id ).html() + '</code></p>' : '' ) +
				urlHtml +
				( nbrHtml ? '<h4>' + ( i18n.neighbors || 'Neighbors' ) + '</h4><ul>' + nbrHtml + '</ul>' : '' )
			).show();
		} );
	}

	// -------------------------------------------------------------------------
	// Legend + minimap
	// -------------------------------------------------------------------------

	/**
	 * Legend row keyboard activation.
	 */
	$( document ).on( 'click keydown', '.nvoos-cg-legend-row', function ( e ) {
		if ( e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ' ) {
			return;
		}
		if ( ! cy ) {
			return;
		}
		var type = $( this ).data( 'filter-type' );
		var cid = $( this ).data( 'filter-community' );
		cy.elements().addClass( 'faded' ).removeClass( 'highlighted' );
		if ( type ) {
			cy.nodes().filter( function ( n ) { return n.data( 'type' ) === type; } ).removeClass( 'faded' ).addClass( 'highlighted' );
		} else if ( cid ) {
			cy.nodes().filter( function ( n ) { return n.data( 'community_id' ) === cid; } ).removeClass( 'faded' ).addClass( 'highlighted' );
		}
	} );

	function markMinimapDirty() {
		if ( minimapDirty ) {
			return;
		}
		minimapDirty = true;
		if ( window.requestAnimationFrame ) {
			window.requestAnimationFrame( function () {
				minimapDirty = false;
				drawMinimap();
			} );
		}
	}

	/**
	 * Initialise the minimap (hidden on small viewports).
	 */
	function initMinimap() {
		var $map = $( '#nvoos-content-graph-minimap' );
		if ( ! $map.length || ! cy ) {
			return;
		}
		if ( window.innerWidth < 768 || cy.nodes().length > 2000 ) {
			return;
		}
		$map.removeAttr( 'hidden' );
		drawMinimap();

		// Click / drag to pan the main view.
		var canvas = document.getElementById( 'nvoos-content-graph-minimap-canvas' );
		var dragging = false;
		var last = { x: 0, y: 0 };

		function toModel( evt ) {
			var rect = canvas.getBoundingClientRect();
			var fx = ( evt.clientX - rect.left ) / rect.width;
			var fy = ( evt.clientY - rect.top ) / rect.height;
			var extent = cy.extent();
			return {
				x: extent.x1 + fx * extent.w,
				y: extent.y1 + fy * extent.h
			};
		}

		canvas.addEventListener( 'pointerdown', function ( e ) {
			dragging = true;
			last = toModel( e );
			canvas.setPointerCapture( e.pointerId );
		} );
		canvas.addEventListener( 'pointermove', function ( e ) {
			if ( ! dragging ) {
				return;
			}
			var p = toModel( e );
			cy.panBy( { x: last.x - p.x, y: last.y - p.y } );
			last = p;
		} );
		canvas.addEventListener( 'pointerup', function () {
			dragging = false;
		} );
	}

	/**
	 * Draw the minimap: downscaled node positions + viewport rectangle.
	 */
	function drawMinimap() {
		var canvas = document.getElementById( 'nvoos-content-graph-minimap-canvas' );
		if ( ! canvas || ! cy ) {
			return;
		}
		var ctx = canvas.getContext( '2d' );
		var w = canvas.width;
		var h = canvas.height;
		var extent = cy.extent();

		ctx.clearRect( 0, 0, w, h );
		ctx.fillStyle = 'rgba(255,255,255,0.04)';
		ctx.fillRect( 0, 0, w, h );

		var nodes = cy.nodes();
		ctx.fillStyle = tokens.muted || '#8b8fa3';
		for ( var i = 0; i < nodes.length; i++ ) {
			var p = nodes[ i ].position();
			var x = ( p.x - extent.x1 ) / extent.w * w;
			var y = ( p.y - extent.y1 ) / extent.h * h;
			ctx.fillRect( x - 1, y - 1, 2.5, 2.5 );
		}

		// Viewport rectangle.
		var vp = cy.extent(); // model-space viewport
		var rect = {
			x: ( vp.x1 - extent.x1 ) / extent.w * w,
			y: ( vp.y1 - extent.y1 ) / extent.h * h,
			w: vp.w / extent.w * w,
			h: vp.h / extent.h * h
		};
		ctx.strokeStyle = tokens.accent || '#7c9ff2';
		ctx.lineWidth = 1.5;
		ctx.strokeRect( rect.x, rect.y, Math.max( 2, rect.w ), Math.max( 2, rect.h ) );
	}

	// -------------------------------------------------------------------------
	// Toolbar controls
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '#nvoos-content-graph-fit-btn', function () {
		if ( cy ) {
			cy.fit( undefined, 30 );
		}
	} );

	$( document ).on( 'click', '#nvoos-content-graph-zoom-in-btn', function () {
		if ( cy ) {
			cy.zoom( { level: cy.zoom() * 1.25, renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } } );
		}
	} );

	$( document ).on( 'click', '#nvoos-content-graph-zoom-out-btn', function () {
		if ( cy ) {
			cy.zoom( { level: cy.zoom() * 0.8, renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } } );
		}
	} );

	$( document ).on( 'click', '#nvoos-content-graph-fullscreen-btn', function () {
		var wrap = document.querySelector( '.nvoos-content-graph-explorer-wrap' );
		if ( ! wrap ) {
			return;
		}
		if ( document.fullscreenElement ) {
			document.exitFullscreen();
		} else if ( wrap.requestFullscreen ) {
			wrap.requestFullscreen();
		}
	} );

	$( document ).on( 'fullscreenchange', function () {
		if ( cy ) {
			cy.resize();
			cy.fit( undefined, 20 );
		}
	} );

	// Layout preset selector (populated from the theme engine).
	$( document ).on( 'change', '#nvoos-content-graph-layout-select', function () {
		var preset = theme.layoutPresets( visual )[ $( this ).val() ];
		if ( cy && preset ) {
			cy.layout( preset.options ).run();
		}
	} );

	// Color-by selector — live preview (persist via Appearance settings).
	$( document ).on( 'change', '#nvoos-content-graph-color-by', function () {
		visual.color_by = $( this ).val();
		restyleNodes();
	} );

	// Edge style selector — live preview.
	$( document ).on( 'change', '#nvoos-content-graph-edge-style', function () {
		visual.edge_style = $( this ).val();
		applyEdgeStyle();
	} );

	$( document ).on( 'click', '#nvoos-content-graph-export-png-btn', function () {
		if ( ! cy ) {
			return;
		}
		var bg = $( '#nvoos-content-graph-export-bg' ).val();
		var scale = parseInt( $( '#nvoos-content-graph-export-scale' ).val(), 10 ) || 2;
		var bgColor;
		if ( bg === 'transparent' ) {
			bgColor = 'transparent';
		} else if ( bg === 'white' ) {
			bgColor = '#ffffff';
		} else {
			bgColor = tokens.canvas || '#0f0f1a';
		}
		var png = cy.png( { output: 'blob', bg: bgColor, full: true, scale: scale } );
		var a = document.createElement( 'a' );
		a.href = URL.createObjectURL( png );
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
	// Keyboard navigation (arrow keys move focus, Enter opens details).
	// -------------------------------------------------------------------------

	$( document ).on( 'keydown', '#nvoos-content-graph-explorer', function ( e ) {
		if ( ! cy ) {
			return;
		}
		var focused = cy.nodes( ':selected' );
		var target = null;

		switch ( e.key ) {
			case 'ArrowUp':
			case 'ArrowDown':
			case 'ArrowLeft':
			case 'ArrowRight':
				e.preventDefault();
				target = nearestNode( focused, e.key );
				if ( target ) {
					cy.elements().removeClass( 'faded highlighted' );
					target.select();
					loadNodeDetails( target.id() );
				}
				break;
			case 'Enter':
			case ' ':
				if ( focused.length ) {
					e.preventDefault();
					loadNodeDetails( focused.id() );
				}
				break;
			case 'Escape':
				cy.elements().removeClass( 'faded highlighted' );
				cy.elements().unselect();
				$( '#nvoos-content-graph-sidebar' ).hide();
				break;
			case '+':
			case '=':
				e.preventDefault();
				cy.zoom( { level: cy.zoom() * 1.25 } );
				break;
			case '-':
				e.preventDefault();
				cy.zoom( { level: cy.zoom() * 0.8 } );
				break;
			case '0':
				cy.fit( undefined, 20 );
				break;
		}
	} );

	/**
	 * Find the node nearest (by screen distance) to the focused node in a
	 * cardinal direction. Falls back to the first visible node.
	 */
	function nearestNode( focused, direction ) {
		var origin = focused.length ? focused.position() : null;
		var best = null;
		var bestDist = Infinity;

		cy.nodes().forEach( function ( n ) {
			if ( focused.length && n.id() === focused.id() ) {
				return;
			}
			var p = n.position();
			var dx = p.x - ( origin ? origin.x : 0 );
			var dy = p.y - ( origin ? origin.y : 0 );
			var aligned = false;
			var dist = dx * dx + dy * dy;

			switch ( direction ) {
				case 'ArrowLeft':
					aligned = dx < 0 && Math.abs( dx ) >= Math.abs( dy );
					break;
				case 'ArrowRight':
					aligned = dx > 0 && Math.abs( dx ) >= Math.abs( dy );
					break;
				case 'ArrowUp':
					aligned = dy < 0 && Math.abs( dy ) > Math.abs( dx );
					break;
				case 'ArrowDown':
					aligned = dy > 0 && Math.abs( dy ) > Math.abs( dx );
					break;
			}

			if ( aligned && dist < bestDist ) {
				bestDist = dist;
				best = n;
			}
		} );

		if ( ! best && cy.nodes().length ) {
			best = cy.nodes()[ 0 ];
		}
		return best;
	}

	// -------------------------------------------------------------------------
	// View persistence (zoom/pan/layout per admin page, localStorage).
	// -------------------------------------------------------------------------

	var pendingView = null;

	function viewKey() {
		return 'nvoos-cg-view';
	}

	function scheduleViewSave() {
		if ( viewSaveTimer ) {
			clearTimeout( viewSaveTimer );
		}
		viewSaveTimer = setTimeout( function () {
			viewSaveTimer = null;
			if ( ! cy ) {
				return;
			}
			try {
				var view = {
					zoom: cy.zoom(),
					pan: cy.pan(),
					layout: $( '#nvoos-content-graph-layout-select' ).val()
				};
				window.localStorage.setItem( viewKey(), JSON.stringify( view ) );
			} catch ( e ) {
				// Storage unavailable — ignore.
			}
		}, 500 );
	}

	function restoreView() {
		try {
			var raw = window.localStorage.getItem( viewKey() );
			if ( raw ) {
				pendingView = JSON.parse( raw );
			}
		} catch ( e ) {
			pendingView = null;
		}
	}

	function restorePendingView() {
		if ( ! pendingView || ! cy ) {
			return;
		}
		var v = pendingView;
		pendingView = null;
		if ( v.layout && theme.layoutPresets( visual )[ v.layout ] ) {
			$( '#nvoos-content-graph-layout-select' ).val( v.layout );
		}
		if ( typeof v.zoom === 'number' ) {
			cy.zoom( { level: v.zoom } );
		}
		if ( v.pan && typeof v.pan.x === 'number' ) {
			cy.pan( v.pan );
		}
	}

	// -------------------------------------------------------------------------
	// Memory palace preset — Agent: X / Wing: Y (unchanged behavior).
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
		matched.connectedEdges().removeClass( 'faded' );
		matched.neighborhood().nodes().removeClass( 'faded' );

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
	// Appearance tab: color pickers + presets
	// -------------------------------------------------------------------------

	/**
	 * Initialise the Appearance tab (wpColorPicker + preset application).
	 */
	function initAppearanceTab() {
		if ( typeof $ !== 'function' ) {
			return;
		}

		var $colorFields = $( '.nvoos-cg-color-field' );
		if ( $colorFields.length && typeof $colorFields.wpColorPicker === 'function' ) {
			$colorFields.wpColorPicker( { defaultColor: false } );
		}

		$( document ).on( 'change', '[name="nvoos_content_graph_settings[visual_preset]"]', function () {
			var slug = $( this ).val();
			var presets = config.presets || {};
			if ( ! slug || ! presets[ slug ] ) {
				return;
			}
			var map = presets[ slug ].visual || {};
			$.each( map, function ( key, value ) {
				var $field = $( '[name="' + 'nvoos_content_graph_settings[' + key + ']' + '"]' );
				if ( ! $field.length ) {
					return;
				}
				if ( $field.is( ':checkbox' ) ) {
					$field.prop( 'checked', value ? true : false );
				} else if ( $field.hasClass( 'nvoos-cg-color-field' ) ) {
					if ( $field.data( 'wpColorPicker' ) && $field.iris ) {
						$field.iris( 'color', value );
					}
					$field.val( value );
				} else {
					$field.val( value );
				}
				$field.trigger( 'change' );
			} );
		} );
	}

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
		// Explorer.
		if ( $( '#nvoos-content-graph-explorer' ).length ) {
			// Populate the layout selector from the theme engine.
			var $layout = $( '#nvoos-content-graph-layout-select' );
			if ( $layout.length && theme ) {
				var html = '';
				$.each( theme.layoutPresets( visual ), function ( slug, preset ) {
					html += '<option value="' + slug + '">' + preset.label + '</option>';
				} );
				$layout.html( html );
			}

			// Live-preview controls start from the saved Appearance settings.
			$( '#nvoos-content-graph-color-by' ).val( visual.color_by || 'type' );
			$( '#nvoos-content-graph-edge-style' ).val( visual.edge_style || 'plain' );

			loadGraph();
		}

		// Appearance tab.
		if ( $( '.nvoos-cg-color-field' ).length ) {
			initAppearanceTab();
		}
	} );

}( jQuery ) );
