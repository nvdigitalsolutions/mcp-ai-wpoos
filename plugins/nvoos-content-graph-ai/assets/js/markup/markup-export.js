/**
 * NV oOS Markup Subsystem — W3C Web Annotation exporter.
 *
 * Serializes an in-memory shape list (rectangles, polygons, point pairs,
 * brush strokes) to the canonical W3C Web Annotation Data Model envelope
 * accepted by the server-side validator and rasterizer.
 *
 *   https://www.w3.org/TR/annotation-model/
 *
 * The envelope shape mirrors what `WP_MCP_AI_Markup_Validator` accepts;
 * see `docs/markup-subsystem.md` for the schema.
 *
 * The module is plain ES5-compatible vanilla JavaScript so it can be
 * loaded directly via `wp_enqueue_script` without a build step,
 * matching the rest of the chat client surface.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

( function ( window ) {
	'use strict';

	const ANNO_CTX = 'http://www.w3.org/ns/anno.jsonld';
	const MAX_SHAPES = 64;
	const MAX_SVG_BYTES = 8 * 1024;

	/**
	 * Clamp a numeric value to [0, max].
	 *
	 * @param {number} value Value to clamp.
	 * @param {number} max   Upper bound.
	 * @return {number} Clamped value.
	 */
	function clamp( value, max ) {
		const num = Number( value );
		if ( ! isFinite( num ) ) {
			return 0;
		}
		if ( num < 0 ) {
			return 0;
		}
		if ( num > max ) {
			return max;
		}
		return num;
	}

	/**
	 * Round a coordinate to a single decimal place to keep payload size
	 * predictable while preserving sub-pixel accuracy.
	 *
	 * @param {number} value Coordinate.
	 * @return {number} Rounded value.
	 */
	function round1( value ) {
		return Math.round( Number( value ) * 10 ) / 10;
	}

	/**
	 * Build a `FragmentSelector` (Media Fragments URI) for a rectangle.
	 *
	 * @param {Object} rect      Rectangle {x,y,w,h} in pixel coordinates.
	 * @param {Object} dimension Source media dimension {width,height}.
	 * @return {Object} W3C selector.
	 */
	function rectFragmentSelector( rect, dimension ) {
		const x = clamp( rect.x, dimension.width );
		const y = clamp( rect.y, dimension.height );
		const w = clamp( rect.w, dimension.width - x );
		const h = clamp( rect.h, dimension.height - y );
		return {
			type:  'FragmentSelector',
			conformsTo: 'http://www.w3.org/TR/media-frags/',
			value: 'xywh=pixel:' + round1( x ) + ',' + round1( y ) + ',' + round1( w ) + ',' + round1( h ),
		};
	}

	/**
	 * Build a sanitized `SvgSelector` for a polygon shape.
	 *
	 * The SVG body is intentionally minimal — only `<svg>` and `<polygon>`
	 * are emitted so the server-side `wp_kses` allowlist accepts the
	 * payload without modification.
	 *
	 * @param {Array<{x:number,y:number}>} points    Polygon vertices.
	 * @param {Object}                     dimension Source media size.
	 * @return {Object|null} W3C selector or null if invalid.
	 */
	function polygonSvgSelector( points, dimension ) {
		if ( ! Array.isArray( points ) || points.length < 3 ) {
			return null;
		}
		const pts = points.map( function ( p ) {
			return round1( clamp( p.x, dimension.width ) ) + ',' + round1( clamp( p.y, dimension.height ) );
		} ).join( ' ' );
		const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' +
			Math.max( 1, dimension.width ) + ' ' + Math.max( 1, dimension.height ) +
			'"><polygon points="' + pts + '" /></svg>';
		if ( svg.length > MAX_SVG_BYTES ) {
			return null;
		}
		return { type: 'SvgSelector', value: svg };
	}

	/**
	 * Convert a brush stroke (poly-line of brush dabs) to an SVG path.
	 *
	 * @param {Array<{x:number,y:number,r:number}>} stroke    Stroke points with radii.
	 * @param {Object}                              dimension Source media size.
	 * @return {Object|null} W3C selector or null if invalid.
	 */
	function brushSvgSelector( stroke, dimension ) {
		if ( ! Array.isArray( stroke ) || stroke.length < 1 ) {
			return null;
		}
		const radius = Math.max( 1, Number( stroke[ 0 ].r ) || 8 );
		const d = stroke.map( function ( p, i ) {
			return ( i === 0 ? 'M' : 'L' ) +
				round1( clamp( p.x, dimension.width ) ) + ' ' +
				round1( clamp( p.y, dimension.height ) );
		} ).join( ' ' );
		const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' +
			Math.max( 1, dimension.width ) + ' ' + Math.max( 1, dimension.height ) +
			'"><path d="' + d + '" stroke-width="' + ( radius * 2 ) +
			'" stroke="#000" fill="none" stroke-linecap="round" stroke-linejoin="round" /></svg>';
		if ( svg.length > MAX_SVG_BYTES ) {
			return null;
		}
		return { type: 'SvgSelector', value: svg };
	}

	/**
	 * Build a position vector body (move-from-A-to-B gesture).
	 *
	 * @param {Object} from      Source point.
	 * @param {Object} to        Destination point.
	 * @param {Object} dimension Source media size.
	 * @return {Object} Annotation body.
	 */
	function positionBody( from, to, dimension ) {
		return {
			type: 'TextualBody',
			purpose: 'tagging',
			value: 'position',
			vector: {
				from: { x: round1( clamp( from.x, dimension.width ) ), y: round1( clamp( from.y, dimension.height ) ) },
				to:   { x: round1( clamp( to.x, dimension.width ) ),   y: round1( clamp( to.y, dimension.height ) ) },
				normalized: false,
			},
		};
	}

	/**
	 * Convert a shape descriptor to one or more W3C annotations.
	 *
	 * @param {Object} shape     Shape descriptor.
	 * @param {Object} dimension Source media size.
	 * @return {Object|null} W3C annotation or null if shape is invalid.
	 */
	function shapeToAnnotation( shape, dimension ) {
		if ( ! shape || typeof shape !== 'object' ) {
			return null;
		}
		const purpose = shape.purpose || 'highlighting';
		const anno = {
			type: 'Annotation',
			motivation: shape.motivation || purpose,
			body: shape.body || { type: 'TextualBody', value: shape.label || '' },
		};
		if ( shape.kind === 'rect' || shape.kind === 'crop' || shape.kind === 'region' || shape.kind === 'redact' ) {
			anno.target = { selector: rectFragmentSelector( shape, dimension ) };
			if ( shape.kind === 'redact' ) {
				anno.motivation = 'redacting';
			}
			if ( shape.kind === 'crop' ) {
				anno.motivation = 'editing';
			}
			return anno;
		}
		if ( shape.kind === 'polygon' ) {
			const sel = polygonSvgSelector( shape.points, dimension );
			if ( ! sel ) {
				return null;
			}
			anno.target = { selector: sel };
			return anno;
		}
		if ( shape.kind === 'brush' ) {
			const bsel = brushSvgSelector( shape.stroke, dimension );
			if ( ! bsel ) {
				return null;
			}
			anno.target = { selector: bsel };
			return anno;
		}
		if ( shape.kind === 'position' ) {
			anno.body = positionBody( shape.from, shape.to, dimension );
			anno.motivation = 'editing';
			return anno;
		}
		if ( shape.kind === 'note' ) {
			anno.target = { selector: rectFragmentSelector( shape, dimension ) };
			anno.body = { type: 'TextualBody', value: String( shape.text || '' ).slice( 0, 1024 ) };
			anno.motivation = 'commenting';
			return anno;
		}
		return null;
	}

	/**
	 * Build the full W3C Web Annotation envelope.
	 *
	 * @param {Object} options Export options.
	 * @param {string} options.requestId Markup request ID.
	 * @param {string} options.mode      Markup mode (mask|region|crop|...).
	 * @param {Array}  options.shapes    Shapes to include.
	 * @param {Object} options.dimension {width,height} of source media.
	 * @param {Object} [options.extra]   Extra schema fields.
	 * @return {Object} Validation-ready payload.
	 */
	function buildEnvelope( options ) {
		const shapes = Array.isArray( options.shapes ) ? options.shapes.slice( 0, MAX_SHAPES ) : [];
		const dim = options.dimension || { width: 0, height: 0 };
		const items = [];
		for ( let i = 0; i < shapes.length; i++ ) {
			const anno = shapeToAnnotation( shapes[ i ], dim );
			if ( anno ) {
				items.push( anno );
			}
		}
		return {
			markup: {
				'@context': ANNO_CTX,
				type:       'AnnotationCollection',
				request_id: options.requestId,
				mode:       options.mode,
				dimension:  { width: Math.round( dim.width ), height: Math.round( dim.height ) },
				items:      items,
			},
			extra: options.extra || {},
		};
	}

	/**
	 * POST the envelope to the markup submit endpoint.
	 *
	 * @param {string} submitUrl REST submit URL.
	 * @param {Object} envelope  Built envelope.
	 * @param {Object} options   Auth options {nonce, bearer}.
	 * @return {Promise<Object>} Server response.
	 */
	function submitEnvelope( submitUrl, envelope, options ) {
		const headers = { 'Content-Type': 'application/json' };
		options = options || {};
		if ( options.nonce ) {
			headers[ 'X-WP-Nonce' ] = options.nonce;
		}
		if ( options.bearer ) {
			headers.Authorization = 'Bearer ' + options.bearer;
		}
		if ( options.guestToken ) {
			headers[ 'X-WP-MCP-AI-Guest' ] = options.guestToken;
		}
		return window.fetch( submitUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     headers,
			body:        JSON.stringify( envelope ),
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					const msg = data && data.message ? data.message : 'Markup submit failed (' + response.status + ')';
					const err = new Error( msg );
					err.status = response.status;
					err.code = data && data.code ? data.code : 'submit_failed';
					throw err;
				}
				return data;
			} );
		} );
	}

	window.WPMcpAiMarkupExport = {
		buildEnvelope:  buildEnvelope,
		submitEnvelope: submitEnvelope,
		shapeToAnnotation: shapeToAnnotation,
		// Exposed for test harnesses.
		_internal: {
			rectFragmentSelector: rectFragmentSelector,
			polygonSvgSelector:   polygonSvgSelector,
			brushSvgSelector:     brushSvgSelector,
			positionBody:         positionBody,
			MAX_SHAPES:           MAX_SHAPES,
			MAX_SVG_BYTES:        MAX_SVG_BYTES,
		},
	};
}( typeof window !== 'undefined' ? window : this ) );
