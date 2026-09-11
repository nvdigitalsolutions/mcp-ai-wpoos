/**
 * Workflow DAG Builder — vanilla JS SVG-based node graph editor.
 *
 * Reads window.mcpAiDagBuilder config injected by WP_MCP_AI_Admin_DAG_Builder
 * and renders a three-panel editor (palette | canvas | inspector) into
 * #mcp-ai-dag-builder-root.
 *
 * No external CDN dependencies.
 */
( function () {
	'use strict';

	/* -------------------------------------------------------------------------
	 * i18n helpers (use wp.i18n if available, else inline strings)
	 * ---------------------------------------------------------------------- */
	const __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ )
		? window.wp.i18n.__
		: function ( str ) { return str; };

	/* -------------------------------------------------------------------------
	 * String constants
	 * ---------------------------------------------------------------------- */
	const STR = {
		SAVE:            __( 'Save', 'mcp-ai-wpoos' ),
		EXPORT:          __( 'Export JSON', 'mcp-ai-wpoos' ),
		IMPORT:          __( 'Import JSON', 'mcp-ai-wpoos' ),
		RUN:             __( 'Run', 'mcp-ai-wpoos' ),
		PALETTE_TITLE:   __( 'Node Palette', 'mcp-ai-wpoos' ),
		INSPECTOR_TITLE: __( 'Inspector', 'mcp-ai-wpoos' ),
		NO_SELECTION:    __( 'Select a node to inspect it.', 'mcp-ai-wpoos' ),
		NODE_NAME:       __( 'Name', 'mcp-ai-wpoos' ),
		NODE_TYPE:       __( 'Type', 'mcp-ai-wpoos' ),
		NODE_CONFIG:     __( 'Config (JSON)', 'mcp-ai-wpoos' ),
		APPLY:           __( 'Apply', 'mcp-ai-wpoos' ),
		DELETE_NODE:     __( 'Delete Node', 'mcp-ai-wpoos' ),
		CANVAS_TITLE:    __( 'Canvas — click palette to add, drag to move, click to select', 'mcp-ai-wpoos' ),
		SAVED:           __( 'Workflow saved.', 'mcp-ai-wpoos' ),
		SAVE_FAILED:     __( 'Save failed.', 'mcp-ai-wpoos' ),
		RUN_DISABLED:    __( 'Workflow Engine V2 is disabled on this site.', 'mcp-ai-wpoos' ),
		NEW_WORKFLOW:    __( 'New Workflow', 'mcp-ai-wpoos' ),
		ENTER_NAME:      __( 'Enter workflow name:', 'mcp-ai-wpoos' ),
		CONNECT_HINT:    __( 'Shift+click a node to start a connection, then shift+click another to link them.', 'mcp-ai-wpoos' ),
	};

	/* -------------------------------------------------------------------------
	 * Node type definitions
	 * ---------------------------------------------------------------------- */
	const NODE_TYPES = [
		{ type: 'agent',     label: __( 'Agent', 'mcp-ai-wpoos' ),     color: '#2271b1' },
		{ type: 'tool',      label: __( 'Tool', 'mcp-ai-wpoos' ),      color: '#1e7e34' },
		{ type: 'condition', label: __( 'Condition', 'mcp-ai-wpoos' ), color: '#e67e22' },
		{ type: 'approval',  label: __( 'Approval', 'mcp-ai-wpoos' ),  color: '#c0392b' },
		{ type: 'loop',      label: __( 'Loop', 'mcp-ai-wpoos' ),      color: '#8e44ad' },
		{ type: 'parallel',  label: __( 'Parallel', 'mcp-ai-wpoos' ),  color: '#16a085' },
	];

	const NODE_TYPE_MAP = {};
	NODE_TYPES.forEach( function ( t ) { NODE_TYPE_MAP[ t.type ] = t; } );

	/* -------------------------------------------------------------------------
	 * State
	 * ---------------------------------------------------------------------- */
	const config      = window.mcpAiDagBuilder || {};
	const restUrl     = config.restUrl || '';
	const nonce       = config.nonce  || '';
	let workflowId  = parseInt( config.workflowId, 10 ) || 0;

	let nodes       = [];   // { id, type, label, x, y, config }
	let edges       = [];   // { id, from, to }
	let nextId      = 1;
	let selectedId  = null;
	let connectFrom = null; // node id waiting for shift+click target

	let svgEl, svgEdgesGroup, svgNodesGroup;
	let inspectorContent;

	/* -------------------------------------------------------------------------
	 * Bootstrap
	 * ---------------------------------------------------------------------- */
	document.addEventListener( 'DOMContentLoaded', function () {
		const root = document.getElementById( 'mcp-ai-dag-builder-root' );
		if ( ! root ) { return; }

		root.innerHTML = '';
		root.appendChild( buildEditor() );

		if ( workflowId > 0 ) {
			loadWorkflow( workflowId );
		} else {
			renderGraph();
		}
	} );

	/* -------------------------------------------------------------------------
	 * Build editor DOM
	 * ---------------------------------------------------------------------- */
	function buildEditor() {
		const wrap = el( 'div', { className: 'dag-editor-wrap' } );

		// Toolbar
		const toolbar = el( 'div', { className: 'dag-toolbar' } );
		const btnSave   = el( 'button', { className: 'button button-primary dag-btn-save', textContent: STR.SAVE } );
		const btnExport = el( 'button', { className: 'button dag-btn-export', textContent: STR.EXPORT } );
		const btnImport = el( 'button', { className: 'button dag-btn-import', textContent: STR.IMPORT } );
		const btnRun    = el( 'button', { className: 'button button-secondary dag-btn-run', textContent: STR.RUN } );
		const statusMsg = el( 'span', { className: 'dag-status-msg' } );

		btnSave.addEventListener( 'click', onSave );
		btnExport.addEventListener( 'click', onExport );
		btnImport.addEventListener( 'click', onImport );
		btnRun.addEventListener( 'click', onRun );

		toolbar.appendChild( btnSave );
		toolbar.appendChild( btnExport );
		toolbar.appendChild( btnImport );
		toolbar.appendChild( btnRun );
		toolbar.appendChild( statusMsg );
		wrap.appendChild( toolbar );

		// Body (palette | canvas | inspector)
		const body = el( 'div', { className: 'dag-body' } );

		// Palette
		const palette = el( 'div', { className: 'dag-palette' } );
		const pTitle   = el( 'h3', { textContent: STR.PALETTE_TITLE } );
		palette.appendChild( pTitle );
		NODE_TYPES.forEach( function ( nt ) {
			const btn = el( 'button', {
				className:   'dag-palette-btn',
				textContent: nt.label,
				title:       nt.type,
			} );
			btn.style.borderLeftColor = nt.color;
			btn.addEventListener( 'click', function () { addNode( nt.type ); } );
			palette.appendChild( btn );
		} );
		const hint = el( 'p', { className: 'dag-connect-hint', textContent: STR.CONNECT_HINT } );
		palette.appendChild( hint );
		body.appendChild( palette );

		// Canvas
		const canvasWrap = el( 'div', { className: 'dag-canvas-wrap' } );
		const canvasHint = el( 'p', { className: 'dag-canvas-hint', textContent: STR.CANVAS_TITLE } );
		svgEl = document.createElementNS( 'http://www.w3.org/2000/svg', 'svg' );
		svgEl.setAttribute( 'class', 'dag-canvas-svg' );
		svgEl.setAttribute( 'width', '100%' );
		svgEl.setAttribute( 'height', '600' );

		// Defs: arrowhead marker
		const defs   = document.createElementNS( 'http://www.w3.org/2000/svg', 'defs' );
		const marker = document.createElementNS( 'http://www.w3.org/2000/svg', 'marker' );
		marker.setAttribute( 'id', 'dag-arrow' );
		marker.setAttribute( 'markerWidth', '10' );
		marker.setAttribute( 'markerHeight', '7' );
		marker.setAttribute( 'refX', '10' );
		marker.setAttribute( 'refY', 3.5 );
		marker.setAttribute( 'orient', 'auto' );
		const poly = document.createElementNS( 'http://www.w3.org/2000/svg', 'polygon' );
		poly.setAttribute( 'points', '0 0, 10 3.5, 0 7' );
		poly.setAttribute( 'fill', '#888' );
		marker.appendChild( poly );
		defs.appendChild( marker );
		svgEl.appendChild( defs );

		svgEdgesGroup = document.createElementNS( 'http://www.w3.org/2000/svg', 'g' );
		svgEdgesGroup.setAttribute( 'class', 'dag-edges' );
		svgEl.appendChild( svgEdgesGroup );

		svgNodesGroup = document.createElementNS( 'http://www.w3.org/2000/svg', 'g' );
		svgNodesGroup.setAttribute( 'class', 'dag-nodes' );
		svgEl.appendChild( svgNodesGroup );

		canvasWrap.appendChild( canvasHint );
		canvasWrap.appendChild( svgEl );
		body.appendChild( canvasWrap );

		// Inspector
		const inspector = el( 'div', { className: 'dag-inspector' } );
		const iTitle    = el( 'h3', { textContent: STR.INSPECTOR_TITLE } );
		inspectorContent = el( 'div', { className: 'dag-inspector-content', textContent: STR.NO_SELECTION } );
		inspector.appendChild( iTitle );
		inspector.appendChild( inspectorContent );
		body.appendChild( inspector );

		wrap.appendChild( body );

		return wrap;
	}

	/* -------------------------------------------------------------------------
	 * Graph rendering
	 * ---------------------------------------------------------------------- */
	function renderGraph() {
		// Edges
		while ( svgEdgesGroup.firstChild ) { svgEdgesGroup.removeChild( svgEdgesGroup.firstChild ); }
		edges.forEach( function ( edge ) {
			const fromNode = findNode( edge.from );
			const toNode   = findNode( edge.to );
			if ( ! fromNode || ! toNode ) { return; }
			const line = document.createElementNS( 'http://www.w3.org/2000/svg', 'line' );
			line.setAttribute( 'x1', fromNode.x + 60 );
			line.setAttribute( 'y1', fromNode.y + 20 );
			line.setAttribute( 'x2', toNode.x );
			line.setAttribute( 'y2', toNode.y + 20 );
			line.setAttribute( 'stroke', '#888' );
			line.setAttribute( 'stroke-width', '2' );
			line.setAttribute( 'marker-end', 'url(#dag-arrow)' );
			svgEdgesGroup.appendChild( line );
		} );

		// Nodes
		while ( svgNodesGroup.firstChild ) { svgNodesGroup.removeChild( svgNodesGroup.firstChild ); }
		nodes.forEach( function ( node ) {
			renderNode( node );
		} );
	}

	function renderNode( node ) {
		const nt    = NODE_TYPE_MAP[ node.type ] || { color: '#888', label: node.type };
		const isSelected = node.id === selectedId;
		const isConnFrom = node.id === connectFrom;

		const g = document.createElementNS( 'http://www.w3.org/2000/svg', 'g' );
		g.setAttribute( 'transform', 'translate(' + node.x + ',' + node.y + ')' );
		g.setAttribute( 'class', 'dag-node' + ( isSelected ? ' dag-node--selected' : '' ) );
		g.setAttribute( 'data-node-id', node.id );
		g.style.cursor = 'pointer';

		const rect = document.createElementNS( 'http://www.w3.org/2000/svg', 'rect' );
		rect.setAttribute( 'width', 120 );
		rect.setAttribute( 'height', 40 );
		rect.setAttribute( 'rx', 6 );
		rect.setAttribute( 'fill', nt.color );
		rect.setAttribute( 'opacity', isConnFrom ? '0.6' : '1' );
		rect.setAttribute( 'stroke', isSelected ? '#fff' : 'none' );
		rect.setAttribute( 'stroke-width', isSelected ? '2' : '0' );
		g.appendChild( rect );

		const typeLabel = document.createElementNS( 'http://www.w3.org/2000/svg', 'text' );
		typeLabel.setAttribute( 'x', 60 );
		typeLabel.setAttribute( 'y', 14 );
		typeLabel.setAttribute( 'text-anchor', 'middle' );
		typeLabel.setAttribute( 'font-size', '10' );
		typeLabel.setAttribute( 'fill', 'rgba(255,255,255,0.75)' );
		typeLabel.textContent = node.type.toUpperCase();
		g.appendChild( typeLabel );

		const nameLabel = document.createElementNS( 'http://www.w3.org/2000/svg', 'text' );
		nameLabel.setAttribute( 'x', 60 );
		nameLabel.setAttribute( 'y', 30 );
		nameLabel.setAttribute( 'text-anchor', 'middle' );
		nameLabel.setAttribute( 'font-size', '12' );
		nameLabel.setAttribute( 'fill', '#fff' );
		nameLabel.setAttribute( 'font-weight', 'bold' );
		nameLabel.textContent = truncate( node.label, 14 );
		g.appendChild( nameLabel );

		// Drag & click
		makeDraggable( g, node );

		svgNodesGroup.appendChild( g );
	}

	/* -------------------------------------------------------------------------
	 * Drag logic
	 * ---------------------------------------------------------------------- */
	function makeDraggable( g, node ) {
		let dragging = false;
		let startX, startY, origX, origY;

		g.addEventListener( 'mousedown', function ( e ) {
			if ( e.shiftKey ) {
				handleShiftClick( node );
				return;
			}
			dragging = true;
			startX   = e.clientX;
			startY   = e.clientY;
			origX    = node.x;
			origY    = node.y;
			e.stopPropagation();
		} );

		document.addEventListener( 'mousemove', function ( e ) {
			if ( ! dragging ) { return; }
			node.x = origX + ( e.clientX - startX );
			node.y = origY + ( e.clientY - startY );
			renderGraph();
		} );

		document.addEventListener( 'mouseup', function () {
			if ( dragging ) {
				dragging = false;
				selectNode( node.id );
			}
		} );

		g.addEventListener( 'click', function ( e ) {
			if ( e.shiftKey ) { return; }
			selectNode( node.id );
			e.stopPropagation();
		} );
	}

	/* -------------------------------------------------------------------------
	 * Connection (shift+click)
	 * ---------------------------------------------------------------------- */
	function handleShiftClick( node ) {
		if ( connectFrom === null ) {
			connectFrom = node.id;
			renderGraph();
		} else {
			if ( connectFrom !== node.id ) {
				addEdge( connectFrom, node.id );
			}
			connectFrom = null;
			renderGraph();
		}
	}

	/* -------------------------------------------------------------------------
	 * Inspector
	 * ---------------------------------------------------------------------- */
	function selectNode( id ) {
		selectedId = id;
		renderGraph();
		renderInspector( findNode( id ) );
	}

	function renderInspector( node ) {
		if ( ! node ) {
			inspectorContent.textContent = STR.NO_SELECTION;
			return;
		}

		inspectorContent.innerHTML = '';

		// Name
		const nameGroup  = el( 'div', { className: 'dag-field-group' } );
		const nameLabel  = el( 'label', { textContent: STR.NODE_NAME } );
		const nameInput  = el( 'input', { type: 'text', value: node.label, className: 'dag-inspector-input' } );
		nameGroup.appendChild( nameLabel );
		nameGroup.appendChild( nameInput );
		inspectorContent.appendChild( nameGroup );

		// Type
		const typeGroup  = el( 'div', { className: 'dag-field-group' } );
		const typeLabel  = el( 'label', { textContent: STR.NODE_TYPE } );
		const typeSelect = el( 'select', { className: 'dag-inspector-select' } );
		NODE_TYPES.forEach( function ( nt ) {
			const opt = el( 'option', { value: nt.type, textContent: nt.label } );
			if ( nt.type === node.type ) { opt.selected = true; }
			typeSelect.appendChild( opt );
		} );
		typeGroup.appendChild( typeLabel );
		typeGroup.appendChild( typeSelect );
		inspectorContent.appendChild( typeGroup );

		// Config
		const cfgGroup   = el( 'div', { className: 'dag-field-group' } );
		const cfgLabel   = el( 'label', { textContent: STR.NODE_CONFIG } );
		const cfgArea    = el( 'textarea', {
			className: 'dag-inspector-textarea',
			value:     node.config ? JSON.stringify( node.config, null, 2 ) : '',
		} );
		cfgGroup.appendChild( cfgLabel );
		cfgGroup.appendChild( cfgArea );
		inspectorContent.appendChild( cfgGroup );

		// Apply
		const btnApply   = el( 'button', { className: 'button dag-btn-apply', textContent: STR.APPLY } );
		btnApply.addEventListener( 'click', function () {
			node.label = nameInput.value.trim() || node.label;
			node.type  = typeSelect.value;
			try {
				node.config = cfgArea.value.trim() ? JSON.parse( cfgArea.value ) : {};
			} catch ( e ) {
				node.config = {};
			}
			renderGraph();
			renderInspector( node );
		} );
		inspectorContent.appendChild( btnApply );

		// Delete
		const btnDelete  = el( 'button', { className: 'button dag-btn-delete', textContent: STR.DELETE_NODE } );
		btnDelete.addEventListener( 'click', function () {
			deleteNode( node.id );
		} );
		inspectorContent.appendChild( btnDelete );
	}

	/* -------------------------------------------------------------------------
	 * Data helpers
	 * ---------------------------------------------------------------------- */
	function addNode( type ) {
		const nt  = NODE_TYPE_MAP[ type ] || NODE_TYPES[0];
		const id  = 'n' + ( nextId++ );
		const svgRect = svgEl.getBoundingClientRect();
		nodes.push( {
			id:     id,
			type:   type,
			label:  nt.label + ' ' + id,
			x:      40 + ( Math.random() * Math.max( 10, svgRect.width - 200 ) ) | 0,
			y:      40 + ( Math.random() * 400 ) | 0,
			config: {},
		} );
		renderGraph();
		selectNode( id );
	}

	function addEdge( fromId, toId ) {
		// Prevent duplicates
		for ( let i = 0; i < edges.length; i++ ) {
			if ( edges[i].from === fromId && edges[i].to === toId ) { return; }
		}
		edges.push( { id: 'e' + ( nextId++ ), from: fromId, to: toId } );
	}

	function deleteNode( id ) {
		nodes  = nodes.filter( function ( n ) { return n.id !== id; } );
		edges  = edges.filter( function ( e ) { return e.from !== id && e.to !== id; } );
		selectedId = null;
		inspectorContent.textContent = STR.NO_SELECTION;
		renderGraph();
	}

	function findNode( id ) {
		for ( let i = 0; i < nodes.length; i++ ) {
			if ( nodes[i].id === id ) { return nodes[i]; }
		}
		return null;
	}

	function getGraph() {
		return {
			nodes: nodes.map( function ( n ) {
				return { id: n.id, type: n.type, label: n.label, x: n.x, y: n.y, config: n.config || {} };
			} ),
			edges: edges.map( function ( e ) {
				return { id: e.id, from: e.from, to: e.to };
			} ),
		};
	}

	function loadFromGraph( graph ) {
		nodes = ( graph.nodes || [] ).map( function ( n ) {
			return {
				id:     n.id     || ( 'n' + ( nextId++ ) ),
				type:   n.type   || 'tool',
				label:  n.label  || 'Node',
				x:      n.x      || 40,
				y:      n.y      || 40,
				config: n.config || {},
			};
		} );
		edges = ( graph.edges || [] ).map( function ( e ) {
			return { id: e.id || ( 'e' + ( nextId++ ) ), from: e.from, to: e.to };
		} );
		// Compute next free id
		const allIds = nodes.map( function ( n ) { return n.id; } ).concat(
			edges.map( function ( e ) { return e.id; } )
		);
		allIds.forEach( function ( rawId ) {
			const num = parseInt( String( rawId ).replace( /\D/g, '' ), 10 );
			if ( num >= nextId ) { nextId = num + 1; }
		} );
		renderGraph();
	}

	/* -------------------------------------------------------------------------
	 * REST API calls
	 * ---------------------------------------------------------------------- */
	function loadWorkflow( id ) {
		apiFetch( restUrl + '/' + id, 'GET', null, function ( data ) {
			if ( data && data.graph ) {
				loadFromGraph( data.graph );
			}
		} );
	}

	function onSave() {
		const graph = getGraph();
		const statusEl = document.querySelector( '.dag-status-msg' );

		if ( workflowId > 0 ) {
			apiFetch( restUrl + '/' + workflowId, 'PUT', { graph: graph }, function ( res ) {
				if ( res && res.message ) {
					showStatus( statusEl, res.message, false );
				}
			}, function () {
				showStatus( statusEl, STR.SAVE_FAILED, true );
			} );
		} else {
			const name = window.prompt( STR.ENTER_NAME, STR.NEW_WORKFLOW );
			if ( ! name ) { return; }
			apiFetch( restUrl, 'POST', { name: name, graph: graph }, function ( res ) {
				if ( res && res.id ) {
					workflowId = res.id;
					showStatus( statusEl, STR.SAVED, false );
					// Update URL without reload
					if ( window.history && window.history.replaceState ) {
						let url = window.location.href.replace( /([?&])workflow_id=\d+/, '' );
						url += ( url.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'workflow_id=' + res.id;
						window.history.replaceState( {}, '', url );
					}
				}
			}, function () {
				showStatus( statusEl, STR.SAVE_FAILED, true );
			} );
		}
	}

	function onExport() {
		if ( workflowId <= 0 ) {
			const g = getGraph();
			download( 'workflow.json', JSON.stringify( { graph: g }, null, 2 ) );
			return;
		}
		apiFetch( restUrl + '/' + workflowId + '/export', 'POST', null, function ( data ) {
			download( 'workflow-' + workflowId + '.json', JSON.stringify( data, null, 2 ) );
		} );
	}

	function onImport() {
		const input = document.createElement( 'input' );
		input.type = 'file';
		input.accept = '.json,application/json';
		input.addEventListener( 'change', function () {
			const file = input.files && input.files[0];
			if ( ! file ) { return; }
			const reader = new FileReader();
			reader.onload = function ( e ) {
				try {
					const data = JSON.parse( e.target.result );
					if ( data.graph ) {
						loadFromGraph( data.graph );
					}
				} catch ( err ) {
					// Silent: invalid JSON
				}
			};
			reader.readAsText( file );
		} );
		input.click();
	}

	function onRun() {
		if ( workflowId <= 0 ) {
			window.alert( STR.RUN_DISABLED );
			return;
		}
		apiFetch(
			restUrl + '/' + workflowId + '/execute',
			'POST',
			{},
			function ( res ) {
				const msg = ( res && res.message ) ? res.message : JSON.stringify( res );
				window.alert( msg );
			},
			function ( errMsg ) {
				window.alert( errMsg || STR.RUN_DISABLED );
			}
		);
	}

	/* -------------------------------------------------------------------------
	 * Utility
	 * ---------------------------------------------------------------------- */
	function apiFetch( url, method, body, onSuccess, onError ) {
		const opts = {
			method:  method || 'GET',
			headers: {
				'Content-Type':  'application/json',
				'X-WP-Nonce':    nonce,
			},
		};
		if ( body && method !== 'GET' ) {
			opts.body = JSON.stringify( body );
		}
		fetch( url, opts )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					if ( ! res.ok ) {
						const msg = ( data && data.message ) ? data.message : 'HTTP ' + res.status;
						if ( onError ) { onError( msg ); }
						return;
					}
					if ( onSuccess ) { onSuccess( data ); }
				} );
			} )
			.catch( function ( err ) {
				if ( onError ) { onError( err.message || String( err ) ); }
			} );
	}

	function el( tag, props ) {
		const e = document.createElement( tag );
		if ( props ) {
			Object.keys( props ).forEach( function ( k ) {
				if ( k === 'textContent' ) {
					e.textContent = props[k];
				} else if ( k === 'className' ) {
					e.className = props[k];
				} else {
					e.setAttribute( k, props[k] );
				}
			} );
		}
		return e;
	}

	function truncate( str, maxLen ) {
		if ( ! str ) { return ''; }
		return str.length > maxLen ? str.slice( 0, maxLen - 1 ) + '…' : str;
	}

	function download( filename, text ) {
		const a    = document.createElement( 'a' );
		a.href   = 'data:application/json;charset=utf-8,' + encodeURIComponent( text );
		a.download = filename;
		a.click();
	}

	function showStatus( el, msg, isError ) {
		if ( ! el ) { return; }
		el.textContent  = msg;
		el.style.color  = isError ? '#c0392b' : '#1e7e34';
		el.style.marginLeft = '12px';
		setTimeout( function () { el.textContent = ''; }, 4000 );
	}

} )();
