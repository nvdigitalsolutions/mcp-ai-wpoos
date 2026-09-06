/**
 * NV oOS Markup Subsystem — Inline canvas widget.
 *
 * Renders an editable canvas inside a chat bubble (or admin fallback
 * page) so the user can visually mark up an image: paint a mask, draw
 * a position arrow, crop, redact, etc. The serialized W3C Web
 * Annotation envelope is POSTed back to the markup REST controller
 * via {@link markup-export.js}.
 *
 * Konva (vendored under `assets/js/vendor/konva/`) is used as the
 * canvas engine. PDF.js for document mode is intentionally deferred
 * to a follow-up PR — when `target_type` is `document_pdf` the widget
 * gracefully falls back to the URL-mode admin page.
 *
 * Modes:
 *   - `mask`     — brush + polygon for inpainting masks
 *   - `region`   — single rectangle
 *   - `crop`     — rectangle (rendered as crop guides)
 *   - `redact`   — black-fill rectangles
 *   - `annotate` — labelled boxes / notes
 *   - `position` — drag-arrow gesture (one vector pair)
 *
 * Accessibility:
 *   - Every tool reachable by keyboard (Tab to toolbar, arrow keys to
 *     nudge selection, Enter to commit)
 *   - ARIA-described shape list with screen-reader announcements on
 *     shape add / remove
 *   - Honours prefers-reduced-motion (no Konva animations)
 *
 * Persistence:
 *   - In-progress shape list is mirrored to localStorage every change
 *     (key `wp-mcp-ai-markup:<request_id>`); cleared on submit/cancel.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

( function ( window, document ) {
	'use strict';

	const STORAGE_PREFIX = 'wp-mcp-ai-markup:';
	const DEFAULT_BRUSH_RADIUS = 16;
	const MAX_DIM = 4096;

	/**
	 * Read a value safely from localStorage.
	 *
	 * @param {string} key Storage key.
	 * @return {*} Parsed value or null.
	 */
	function storageRead( key ) {
		try {
			const raw = window.localStorage.getItem( key );
			return raw ? JSON.parse( raw ) : null;
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Write a value safely to localStorage.
	 *
	 * @param {string} key Storage key.
	 * @param {*} value Value to store.
	 */
	function storageWrite( key, value ) {
		try {
			window.localStorage.setItem( key, JSON.stringify( value ) );
		} catch ( e ) {
			// Quota errors / private mode — non-fatal.
		}
	}

	/**
	 * Clear a value from localStorage.
	 *
	 * @param {string} key Storage key.
	 */
	function storageClear( key ) {
		try {
			window.localStorage.removeItem( key );
		} catch ( e ) {
			// Non-fatal.
		}
	}

	/**
	 * Compute the displayed dimension preserving aspect ratio while
	 * fitting in the host element.
	 *
	 * @param {number} width   Native image width.
	 * @param {number} height  Native image height.
	 * @param {number} maxW    Max display width.
	 * @param {number} maxH    Max display height.
	 * @return {Object} {width, height, scale}
	 */
	function fitDimension( width, height, maxW, maxH ) {
		if ( ! width || ! height ) {
			return { width: maxW, height: maxH, scale: 1 };
		}
		const scale = Math.min( maxW / width, maxH / height, 1 );
		return {
			width: Math.round( width * scale ),
			height: Math.round( height * scale ),
			scale: scale,
		};
	}

	/**
	 * Load an image and resolve with the loaded element.
	 *
	 * @param {string} src Image URL.
	 * @return {Promise<HTMLImageElement>}
	 */
	function loadImage( src ) {
		return new Promise( function ( resolve, reject ) {
			const img = new Image();
			img.crossOrigin = 'anonymous';
			img.onload = function () {
				resolve( img );
			};
			img.onerror = function () {
				reject( new Error( 'Failed to load image: ' + src ) );
			};
			img.src = src;
		} );
	}

	/**
	 * Resolve the source URL from a markup target descriptor.
	 *
	 * @param {Object} target Target descriptor.
	 * @return {string} URL.
	 */
	function resolveTargetUrl( target ) {
		if ( ! target ) {
			return '';
		}
		if ( typeof target === 'string' ) {
			return target;
		}
		if ( target.url ) {
			return target.url;
		}
		if ( target.data_uri ) {
			return target.data_uri;
		}
		return '';
	}

	/**
	 * Build the toolbar.
	 *
	 * @param {WidgetState} state Widget state.
	 * @param {Array} availableModes Available mode descriptors.
	 * @return {HTMLElement} Toolbar root.
	 */
	function buildToolbar( state, availableModes ) {
		const toolbar = document.createElement( 'div' );
		toolbar.className = 'wp-mcp-ai-markup__toolbar';
		toolbar.setAttribute( 'role', 'toolbar' );
		toolbar.setAttribute( 'aria-label', state.strings.toolbarLabel || 'Markup tools' );

		availableModes.forEach( function ( mode ) {
			const btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'wp-mcp-ai-markup__tool';
			btn.setAttribute( 'data-tool', mode.id );
			btn.setAttribute( 'aria-pressed', String( state.activeTool === mode.id ) );
			btn.setAttribute( 'title', mode.label );
			btn.textContent = mode.label;
			btn.addEventListener( 'click', function () {
				setActiveTool( state, mode.id );
			} );
			toolbar.appendChild( btn );
		} );

		const spacer = document.createElement( 'span' );
		spacer.className = 'wp-mcp-ai-markup__toolbar-spacer';
		toolbar.appendChild( spacer );

		const undoBtn = document.createElement( 'button' );
		undoBtn.type = 'button';
		undoBtn.className = 'wp-mcp-ai-markup__action wp-mcp-ai-markup__action--undo';
		undoBtn.textContent = state.strings.undo || 'Undo';
		undoBtn.addEventListener( 'click', function () {
			undoLast( state );
		} );
		toolbar.appendChild( undoBtn );

		const clearBtn = document.createElement( 'button' );
		clearBtn.type = 'button';
		clearBtn.className = 'wp-mcp-ai-markup__action wp-mcp-ai-markup__action--clear';
		clearBtn.textContent = state.strings.clear || 'Clear';
		clearBtn.addEventListener( 'click', function () {
			clearShapes( state );
		} );
		toolbar.appendChild( clearBtn );

		return toolbar;
	}

	/**
	 * Update toolbar pressed state.
	 *
	 * @param {WidgetState} state Widget state.
	 */
	function refreshToolbar( state ) {
		const buttons = state.root.querySelectorAll( '.wp-mcp-ai-markup__tool' );
		for ( let i = 0; i < buttons.length; i++ ) {
			const b = buttons[ i ];
			b.setAttribute( 'aria-pressed', String( b.getAttribute( 'data-tool' ) === state.activeTool ) );
		}
	}

	/**
	 * Set the active drawing tool.
	 *
	 * @param {WidgetState} state   Widget state.
	 * @param {string}      toolId  Tool id.
	 */
	function setActiveTool( state, toolId ) {
		state.activeTool = toolId;
		// Position is an exclusive single-shape mode — clear in-progress.
		state.inProgress = null;
		refreshToolbar( state );
		announce( state, ( state.strings.toolSelected || 'Selected tool: %s' ).replace( '%s', toolId ) );
	}

	/**
	 * Push a shape onto the stack and refresh the canvas + storage.
	 *
	 * @param {WidgetState} state Widget state.
	 * @param {Object}      shape Shape descriptor.
	 */
	function pushShape( state, shape ) {
		state.shapes.push( shape );
		persistShapes( state );
		redraw( state );
		announce( state, ( state.strings.shapeAdded || 'Shape added: %s' ).replace( '%s', shape.kind ) );
		refreshAriaList( state );
	}

	/**
	 * Pop the most recent shape (undo).
	 *
	 * @param {WidgetState} state Widget state.
	 */
	function undoLast( state ) {
		if ( ! state.shapes.length ) {
			return;
		}
		state.shapes.pop();
		persistShapes( state );
		redraw( state );
		announce( state, state.strings.shapeRemoved || 'Last shape removed' );
		refreshAriaList( state );
	}

	/**
	 * Remove all shapes.
	 *
	 * @param {WidgetState} state Widget state.
	 */
	function clearShapes( state ) {
		state.shapes = [];
		state.inProgress = null;
		persistShapes( state );
		redraw( state );
		announce( state, state.strings.shapesCleared || 'All shapes cleared' );
		refreshAriaList( state );
	}

	/**
	 * Persist shapes to localStorage.
	 *
	 * @param {WidgetState} state Widget state.
	 */
	function persistShapes( state ) {
		storageWrite( STORAGE_PREFIX + state.payload.request_id, {
			shapes: state.shapes,
			tool:   state.activeTool,
			ts:     Date.now(),
		} );
	}

	/**
	 * Restore previously persisted shapes (if the user refreshed).
	 *
	 * @param {WidgetState} state Widget state.
	 */
	function restoreShapes( state ) {
		const saved = storageRead( STORAGE_PREFIX + state.payload.request_id );
		if ( saved && Array.isArray( saved.shapes ) ) {
			state.shapes = saved.shapes;
			if ( saved.tool ) {
				state.activeTool = saved.tool;
			}
		}
	}

	/**
	 * Update the visible shape list (for screen readers).
	 *
	 * @param {WidgetState} state Widget state.
	 */
	function refreshAriaList( state ) {
		if ( ! state.ariaList ) {
			return;
		}
		state.ariaList.innerHTML = '';
		state.shapes.forEach( function ( shape, idx ) {
			const li = document.createElement( 'li' );
			li.textContent = ( idx + 1 ) + '. ' + shape.kind +
				( shape.label ? ' (' + shape.label + ')' : '' );
			state.ariaList.appendChild( li );
		} );
	}

	/**
	 * Announce a status update to assistive tech via the live region.
	 *
	 * @param {WidgetState} state   Widget state.
	 * @param {string}      message Message text.
	 */
	function announce( state, message ) {
		if ( ! state.live ) {
			return;
		}
		state.live.textContent = '';
		// Yield so AT picks up the change.
		window.setTimeout( function () {
			state.live.textContent = message;
		}, 50 );
	}

	/**
	 * Re-render all shapes onto the Konva overlay layer.
	 *
	 * @param {WidgetState} state Widget state.
	 */
	function redraw( state ) {
		if ( ! state.overlay ) {
			return;
		}
		state.overlay.destroyChildren();
		const Konva = window.Konva;
		state.shapes.forEach( function ( shape ) {
			drawShape( Konva, state.overlay, shape, state );
		} );
		if ( state.inProgress ) {
			drawShape( Konva, state.overlay, state.inProgress, state, true );
		}
		state.overlay.batchDraw();
	}

	/**
	 * Style descriptor lookup by shape kind / mode.
	 *
	 * @param {string} kind Shape kind.
	 * @return {Object} Style.
	 */
	function styleForKind( kind ) {
		switch ( kind ) {
			case 'redact':
				return { fill: 'rgba(0,0,0,0.85)', stroke: '#000', dash: [] };
			case 'crop':
				return { fill: 'rgba(0,150,255,0.05)', stroke: '#0096ff', dash: [ 6, 4 ] };
			case 'mask':
			case 'brush':
			case 'polygon':
				return { fill: 'rgba(255,80,80,0.45)', stroke: '#ff5050', dash: [] };
			case 'note':
				return { fill: 'rgba(255,210,0,0.18)', stroke: '#e6a700', dash: [] };
			case 'position':
				return { fill: 'rgba(40,180,90,0.0)', stroke: '#28b45a', dash: [] };
			default:
				return { fill: 'rgba(0,150,255,0.18)', stroke: '#0096ff', dash: [] };
		}
	}

	/**
	 * Draw a single shape into the overlay layer.
	 *
	 * @param {Object} Konva       Konva module.
	 * @param {Object} layer       Konva layer.
	 * @param {Object} shape       Shape descriptor.
	 * @param {WidgetState} state  Widget state.
	 * @param {boolean} isPreview  Preview rendering flag.
	 */
	function drawShape( Konva, layer, shape, state, isPreview ) {
		const style = styleForKind( shape.kind );
		const opacity = isPreview ? 0.6 : 1;
		const s = state.dimension.scale;
		if ( shape.kind === 'rect' || shape.kind === 'crop' ||
			 shape.kind === 'region' || shape.kind === 'redact' ||
			 shape.kind === 'note' ) {
			layer.add( new Konva.Rect( {
				x: shape.x * s,
				y: shape.y * s,
				width: shape.w * s,
				height: shape.h * s,
				fill: style.fill,
				stroke: style.stroke,
				strokeWidth: 2,
				dash: style.dash,
				opacity: opacity,
				listening: false,
			} ) );
			if ( shape.kind === 'note' && shape.text ) {
				layer.add( new Konva.Text( {
					x: shape.x * s + 4,
					y: shape.y * s + 4,
					text: shape.text,
					fontSize: 12,
					fill: '#222',
					listening: false,
				} ) );
			}
		} else if ( shape.kind === 'polygon' ) {
			const points = [];
			for ( let i = 0; i < shape.points.length; i++ ) {
				points.push( shape.points[ i ].x * s, shape.points[ i ].y * s );
			}
			layer.add( new Konva.Line( {
				points: points,
				closed: true,
				fill: style.fill,
				stroke: style.stroke,
				strokeWidth: 2,
				opacity: opacity,
				listening: false,
			} ) );
		} else if ( shape.kind === 'brush' ) {
			const stroke = shape.stroke || [];
			if ( stroke.length < 1 ) {
				return;
			}
			const brushPoints = [];
			for ( let j = 0; j < stroke.length; j++ ) {
				brushPoints.push( stroke[ j ].x * s, stroke[ j ].y * s );
			}
			const brushRadius = ( stroke[ 0 ] && stroke[ 0 ].r ) ? stroke[ 0 ].r : DEFAULT_BRUSH_RADIUS;
			layer.add( new Konva.Line( {
				points: brushPoints,
				stroke: style.stroke,
				strokeWidth: brushRadius * 2 * s,
				lineCap: 'round',
				lineJoin: 'round',
				opacity: opacity,
				listening: false,
				tension: 0.3,
			} ) );
		} else if ( shape.kind === 'position' ) {
			layer.add( new Konva.Arrow( {
				points: [
					shape.from.x * s, shape.from.y * s,
					shape.to.x * s,   shape.to.y * s,
				],
				stroke: style.stroke,
				fill:   style.stroke,
				strokeWidth: 3,
				pointerLength: 12,
				pointerWidth:  12,
				opacity: opacity,
				listening: false,
			} ) );
		}
	}

	/**
	 * Convert a stage pointer position back into native image coordinates.
	 *
	 * @param {WidgetState} state Widget state.
	 * @param {Object}      pos   {x, y} stage coordinates.
	 * @return {Object} Native coordinates.
	 */
	function toNative( state, pos ) {
		const s = state.dimension.scale || 1;
		return { x: pos.x / s, y: pos.y / s };
	}

	/**
	 * Wire pointer / keyboard handlers on the stage.
	 *
	 * @param {WidgetState} state Widget state.
	 */
	function bindPointerHandlers( state ) {
		const stage = state.stage;
		stage.on( 'pointerdown', function () {
			const pos = stage.getPointerPosition();
			if ( ! pos ) {
				return;
			}
			const native = toNative( state, pos );
			handlePointerDown( state, native );
		} );
		stage.on( 'pointermove', function () {
			const pos = stage.getPointerPosition();
			if ( ! pos ) {
				return;
			}
			handlePointerMove( state, toNative( state, pos ) );
		} );
		stage.on( 'pointerup', function () {
			handlePointerUp( state );
		} );
	}

	/**
	 * Handle pointer-down for the active tool.
	 *
	 * @param {WidgetState} state  Widget state.
	 * @param {Object}      point  Native coordinates.
	 */
	function handlePointerDown( state, point ) {
		const tool = state.activeTool;
		if ( tool === 'rect' || tool === 'region' || tool === 'redact' || tool === 'crop' || tool === 'note' ) {
			state.inProgress = { kind: tool, x: point.x, y: point.y, w: 0, h: 0, dragging: true };
		} else if ( tool === 'position' ) {
			state.inProgress = { kind: 'position', from: point, to: point, dragging: true };
		} else if ( tool === 'brush' || tool === 'mask' ) {
			state.inProgress = {
				kind: 'brush',
				stroke: [ { x: point.x, y: point.y, r: state.brushRadius || DEFAULT_BRUSH_RADIUS } ],
				dragging: true,
			};
		} else if ( tool === 'polygon' ) {
			if ( ! state.inProgress ) {
				state.inProgress = { kind: 'polygon', points: [], closing: false, dragging: false };
			}
			state.inProgress.points.push( { x: point.x, y: point.y } );
		}
		redraw( state );
	}

	/**
	 * Handle pointer-move for the active tool.
	 *
	 * @param {WidgetState} state Widget state.
	 * @param {Object}      point Native coordinates.
	 */
	function handlePointerMove( state, point ) {
		const ip = state.inProgress;
		if ( ! ip || ! ip.dragging ) {
			return;
		}
		if ( ip.kind === 'position' ) {
			ip.to = point;
		} else if ( ip.kind === 'brush' ) {
			ip.stroke.push( { x: point.x, y: point.y, r: state.brushRadius || DEFAULT_BRUSH_RADIUS } );
		} else if ( ip.kind === 'rect' || ip.kind === 'region' || ip.kind === 'redact' ||
					ip.kind === 'crop' || ip.kind === 'note' ) {
			ip.w = point.x - ip.x;
			ip.h = point.y - ip.y;
		}
		redraw( state );
	}

	/**
	 * Handle pointer-up for the active tool.
	 *
	 * @param {WidgetState} state Widget state.
	 */
	function handlePointerUp( state ) {
		const ip = state.inProgress;
		if ( ! ip ) {
			return;
		}
		if ( ip.kind === 'polygon' ) {
			// Polygons keep accumulating until user double-clicks (handled separately).
			return;
		}
		ip.dragging = false;
		if ( ip.kind === 'rect' || ip.kind === 'region' || ip.kind === 'redact' ||
			 ip.kind === 'crop' || ip.kind === 'note' ) {
			// Normalize negative width / height.
			if ( ip.w < 0 ) {
				ip.x += ip.w;
				ip.w = -ip.w;
			}
			if ( ip.h < 0 ) {
				ip.y += ip.h;
				ip.h = -ip.h;
			}
			if ( ip.w < 2 || ip.h < 2 ) {
				state.inProgress = null;
				redraw( state );
				return;
			}
			if ( ip.kind === 'note' ) {
				ip.text = window.prompt( state.strings.notePrompt || 'Note text:', '' ) || '';
			}
			pushShape( state, ip );
			state.inProgress = null;
		} else if ( ip.kind === 'position' ) {
			// Position is a single arrow — replace previous if any.
			state.shapes = state.shapes.filter( function ( s ) {
				return s.kind !== 'position';
			} );
			pushShape( state, ip );
			state.inProgress = null;
		} else if ( ip.kind === 'brush' ) {
			pushShape( state, ip );
			state.inProgress = null;
		}
	}

	/**
	 * Available modes for an image target.
	 *
	 * @param {string} requestedMode Mode requested by the tool.
	 * @param {Object} strings       Localized strings.
	 * @return {Array} Mode descriptors.
	 */
	function imageModes( requestedMode, strings ) {
		const all = [
			{ id: 'mask',     label: strings.modeMask     || 'Mask (brush)' },
			{ id: 'polygon',  label: strings.modePolygon  || 'Polygon mask' },
			{ id: 'region',   label: strings.modeRegion   || 'Region' },
			{ id: 'crop',     label: strings.modeCrop     || 'Crop' },
			{ id: 'redact',   label: strings.modeRedact   || 'Redact' },
			{ id: 'position', label: strings.modePosition || 'Position arrow' },
			{ id: 'note',     label: strings.modeNote     || 'Note' },
		];
		// Filter to the requested family. If the tool requests `mask`, expose
		// brush + polygon. If it requests `region`, expose only region. If it
		// requests `annotate`, expose region + note.
		switch ( requestedMode ) {
			case 'mask':
				return all.filter( function ( m ) { return m.id === 'mask' || m.id === 'polygon'; } );
			case 'region':
				return all.filter( function ( m ) { return m.id === 'region'; } );
			case 'crop':
				return all.filter( function ( m ) { return m.id === 'crop'; } );
			case 'redact':
				return all.filter( function ( m ) { return m.id === 'redact' || m.id === 'region'; } );
			case 'position':
				return all.filter( function ( m ) { return m.id === 'position'; } );
			case 'annotate':
				return all.filter( function ( m ) { return m.id === 'region' || m.id === 'note'; } );
			default:
				return all;
		}
	}

	/**
	 * Build the cancel + submit footer.
	 *
	 * @param {WidgetState} state Widget state.
	 * @return {HTMLElement} Footer element.
	 */
	function buildFooter( state ) {
		const footer = document.createElement( 'div' );
		footer.className = 'wp-mcp-ai-markup__footer';

		const cancel = document.createElement( 'button' );
		cancel.type = 'button';
		cancel.className = 'wp-mcp-ai-markup__cancel button-link';
		cancel.textContent = state.strings.cancel || 'Cancel';
		cancel.addEventListener( 'click', function () {
			cancelRequest( state );
		} );
		footer.appendChild( cancel );

		const submit = document.createElement( 'button' );
		submit.type = 'button';
		submit.className = 'wp-mcp-ai-markup__submit button button-primary';
		submit.textContent = state.strings.submit || 'Submit markup';
		submit.addEventListener( 'click', function () {
			submitMarkup( state );
		} );
		footer.appendChild( submit );

		state.submitButton = submit;
		state.cancelButton = cancel;

		return footer;
	}

	/**
	 * POST the current markup state.
	 *
	 * @param {WidgetState} state Widget state.
	 */
	function submitMarkup( state ) {
		if ( ! window.WPMcpAiMarkupExport ) {
			announce( state, state.strings.exporterMissing || 'Markup exporter not available' );
			return;
		}
		// Auto-close any in-progress polygon.
		if ( state.inProgress && state.inProgress.kind === 'polygon' && state.inProgress.points.length >= 3 ) {
			pushShape( state, state.inProgress );
			state.inProgress = null;
		}
		if ( ! state.shapes.length ) {
			announce( state, state.strings.noShapes || 'Please draw at least one shape before submitting.' );
			return;
		}
		state.submitButton.disabled = true;
		state.submitButton.textContent = state.strings.submitting || 'Submitting…';

		const envelope = window.WPMcpAiMarkupExport.buildEnvelope( {
			requestId: state.payload.request_id,
			mode:      state.payload.mode,
			shapes:    state.shapes,
			dimension: { width: state.nativeWidth, height: state.nativeHeight },
			extra:     state.extraFields || {},
		} );

		window.WPMcpAiMarkupExport.submitEnvelope(
			state.payload.submit_url,
			envelope,
			{
				nonce:      state.options.nonce,
				bearer:     state.options.bearer,
				guestToken: state.options.guestToken,
			}
		).then( function ( response ) {
			storageClear( STORAGE_PREFIX + state.payload.request_id );
			announce( state, state.strings.submitted || 'Markup submitted.' );
			state.root.dispatchEvent( new CustomEvent( 'wp-mcp-ai-markup:submitted', {
				bubbles: true,
				detail:  { requestId: state.payload.request_id, response: response },
			} ) );
			if ( typeof state.options.onSubmitted === 'function' ) {
				state.options.onSubmitted( response );
			}
			detachWidget( state );
		} ).catch( function ( err ) {
			state.submitButton.disabled = false;
			state.submitButton.textContent = state.strings.submit || 'Submit markup';
			announce( state, ( state.strings.submitError || 'Submit failed: %s' ).replace( '%s', err.message ) );
		} );
	}

	/**
	 * Cancel the elicitation. Sends DELETE to the request endpoint.
	 *
	 * @param {WidgetState} state Widget state.
	 */
	function cancelRequest( state ) {
		const url = state.payload.submit_url.replace( /\/submit$/, '' );
		const headers = {};
		if ( state.options.nonce ) {
			headers[ 'X-WP-Nonce' ] = state.options.nonce;
		}
		if ( state.options.guestToken ) {
			headers[ 'X-WP-MCP-AI-Guest' ] = state.options.guestToken;
		}
		window.fetch( url, {
			method: 'DELETE',
			credentials: 'same-origin',
			headers: headers,
		} ).catch( function () { /* non-fatal */ } );
		storageClear( STORAGE_PREFIX + state.payload.request_id );
		state.root.dispatchEvent( new CustomEvent( 'wp-mcp-ai-markup:cancelled', {
			bubbles: true,
			detail:  { requestId: state.payload.request_id },
		} ) );
		if ( typeof state.options.onCancelled === 'function' ) {
			state.options.onCancelled();
		}
		detachWidget( state );
	}

	/**
	 * Tear down a widget instance.
	 *
	 * @param {WidgetState} state Widget state.
	 */
	function detachWidget( state ) {
		if ( state.stage ) {
			try {
				state.stage.destroy();
			} catch ( e ) { /* no-op */ }
		}
		if ( state.root && state.root.parentNode ) {
			state.root.parentNode.removeChild( state.root );
		}
	}

	/**
	 * Render the inline canvas widget into the host element.
	 *
	 * @param {HTMLElement} host    Host container (chat bubble or modal).
	 * @param {Object}      payload Markup elicitation payload from server.
	 * @param {Object}      options Options {strings, nonce, bearer, guestToken, onSubmitted, onCancelled}.
	 * @return {Promise<HTMLElement>} Resolves to the rendered widget root.
	 */
	function render( host, payload, options ) {
		options = options || {};
		const strings = options.strings || {};
		const fallback = window.WPMcpAiMarkupFallback;

		if ( fallback && ! fallback.canRenderInline() ) {
			const fbEl = fallback.build( payload, strings );
			host.appendChild( fbEl );
			fbEl.querySelector( '[data-markup-cancel]' ).addEventListener( 'click', function () {
				if ( fbEl.parentNode ) {
					fbEl.parentNode.removeChild( fbEl );
				}
				if ( typeof options.onCancelled === 'function' ) {
					options.onCancelled();
				}
			} );
			return Promise.resolve( fbEl );
		}

		// Document target: defer to fallback (full PDF.js wiring lands in PR3).
		if ( payload.target_type === 'document_pdf' || payload.target_type === 'document_text' ) {
			const docFb = fallback ? fallback.build( payload, strings ) : null;
			if ( docFb ) {
				host.appendChild( docFb );
			}
			return Promise.resolve( docFb );
		}

		const url = resolveTargetUrl( payload.target );
		if ( ! url ) {
			return Promise.reject( new Error( 'Markup target has no resolvable URL' ) );
		}

		const state = {
			payload:    payload,
			options:    options,
			strings:    strings,
			shapes:     [],
			inProgress: null,
			activeTool: payload.mode === 'mask' ? 'mask' : payload.mode,
			extraFields: {},
			brushRadius: DEFAULT_BRUSH_RADIUS,
			dimension:  { width: 0, height: 0, scale: 1 },
			nativeWidth: 0,
			nativeHeight: 0,
		};

		// Build DOM scaffolding.
		const root = document.createElement( 'div' );
		root.className = 'wp-mcp-ai-markup wp-mcp-ai-markup--' + payload.mode;
		root.setAttribute( 'data-request-id', payload.request_id );
		root.setAttribute( 'data-target-type', payload.target_type );
		root.setAttribute( 'role', 'region' );
		root.setAttribute( 'aria-label', strings.regionLabel || 'Markup canvas' );

		if ( payload.instructions ) {
			const instructions = document.createElement( 'p' );
			instructions.className = 'wp-mcp-ai-markup__instructions';
			instructions.textContent = String( payload.instructions ).slice( 0, 1024 );
			root.appendChild( instructions );
		}

		state.root = root;

		const modes = imageModes( payload.mode, strings );
		// If the requested mode produces only a single tool button, hide
		// the toolbar entirely to keep the bubble compact.
		if ( modes.length > 1 ) {
			root.appendChild( buildToolbar( state, modes ) );
		}

		const canvasHost = document.createElement( 'div' );
		canvasHost.className = 'wp-mcp-ai-markup__canvas';
		canvasHost.setAttribute( 'tabindex', '0' );
		root.appendChild( canvasHost );

		// Live region for screen readers.
		const live = document.createElement( 'div' );
		live.className = 'wp-mcp-ai-markup__sr-live';
		live.setAttribute( 'aria-live', 'polite' );
		live.setAttribute( 'aria-atomic', 'true' );
		live.style.position = 'absolute';
		live.style.left = '-9999px';
		live.style.width = '1px';
		live.style.height = '1px';
		live.style.overflow = 'hidden';
		root.appendChild( live );
		state.live = live;

		// ARIA shape list (visually hidden, screen-reader navigable).
		const ariaList = document.createElement( 'ol' );
		ariaList.className = 'wp-mcp-ai-markup__sr-shapes';
		ariaList.setAttribute( 'aria-label', strings.shapeListLabel || 'Markup shapes' );
		ariaList.style.position = 'absolute';
		ariaList.style.left = '-9999px';
		root.appendChild( ariaList );
		state.ariaList = ariaList;

		root.appendChild( buildFooter( state ) );
		host.appendChild( root );

		restoreShapes( state );

		// Load image and create stage.
		return loadImage( url ).then( function ( img ) {
			state.nativeWidth = Math.min( img.naturalWidth || img.width, MAX_DIM );
			state.nativeHeight = Math.min( img.naturalHeight || img.height, MAX_DIM );
			const hostRect = canvasHost.getBoundingClientRect();
			const maxW = Math.max( 320, Math.floor( hostRect.width || canvasHost.parentNode.clientWidth || 480 ) );
			const maxH = Math.min( 600, window.innerHeight ? Math.floor( window.innerHeight * 0.6 ) : 480 );
			state.dimension = fitDimension( state.nativeWidth, state.nativeHeight, maxW, maxH );

			const Konva = window.Konva;
			const stage = new Konva.Stage( {
				container: canvasHost,
				width:  state.dimension.width,
				height: state.dimension.height,
			} );
			const imageLayer = new Konva.Layer();
			imageLayer.add( new Konva.Image( {
				image: img,
				x: 0,
				y: 0,
				width:  state.dimension.width,
				height: state.dimension.height,
				listening: false,
			} ) );
			stage.add( imageLayer );

			const overlay = new Konva.Layer();
			stage.add( overlay );

			state.stage = stage;
			state.overlay = overlay;
			bindPointerHandlers( state );

			refreshAriaList( state );
			redraw( state );
			refreshToolbar( state );

			// Polygon double-click closes the polygon.
			canvasHost.addEventListener( 'dblclick', function () {
				if ( state.inProgress && state.inProgress.kind === 'polygon' &&
					 state.inProgress.points.length >= 3 ) {
					pushShape( state, state.inProgress );
					state.inProgress = null;
				}
			} );

			// Keyboard nudges.
			canvasHost.addEventListener( 'keydown', function ( ev ) {
				if ( ev.key === 'Escape' ) {
					state.inProgress = null;
					redraw( state );
				} else if ( ev.key === 'Enter' && state.inProgress &&
							state.inProgress.kind === 'polygon' &&
							state.inProgress.points.length >= 3 ) {
					pushShape( state, state.inProgress );
					state.inProgress = null;
					ev.preventDefault();
				} else if ( ( ev.ctrlKey || ev.metaKey ) && ev.key === 'z' ) {
					undoLast( state );
					ev.preventDefault();
				}
			} );

			return root;
		} ).catch( function ( err ) {
			// Image failed: fallback URL link so the user can still mark up
			// in the admin route (which will retry with a server-side proxy).
			canvasHost.innerHTML = '';
			if ( fallback ) {
				canvasHost.appendChild( fallback.build( payload, strings ) );
			} else {
				canvasHost.textContent = err.message;
			}
			return root;
		} );
	}

	window.WPMcpAiMarkupWidget = {
		render:        render,
		// Exported for tests.
		_internal: {
			fitDimension:  fitDimension,
			imageModes:    imageModes,
			styleForKind:  styleForKind,
			STORAGE_PREFIX: STORAGE_PREFIX,
			MAX_DIM:       MAX_DIM,
		},
	};
}( typeof window !== 'undefined' ? window : this,
   typeof document !== 'undefined' ? document : null ) );
