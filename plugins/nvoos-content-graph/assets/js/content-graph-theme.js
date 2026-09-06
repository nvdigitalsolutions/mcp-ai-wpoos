/* jshint esversion: 6 */
/**
 * NV oOS Content Graph — Visual Theme Engine
 *
 * Shared by the admin explorer and the front-end embed. Consumes the
 * `visual` config object delivered by PHP (NvoosContentGraph\Visual\Tokens)
 * and produces:
 *   - resolved theme tokens (dark/light/auto/admin)
 *   - per-type colors/icons (curated palette, user overrides, and a
 *     deterministic algorithmic fallback for unknown types)
 *   - the Cytoscape.js stylesheet
 *   - chrome CSS custom properties (--nvoos-cg-*)
 *   - layout presets and edge-family colors
 *
 * The contrast math mirrors Tokens::ensure_contrast() in PHP so type
 * colors always meet WCAG 2.2 SC 1.4.11 (>= 3:1) against the canvas.
 *
 * @package NvoosContentGraph
 * @since   1.0.4
 */
( function () {
	'use strict';

	var MIN_CONTRAST = 3.0;

	// Per-canvas cache for corrected type colors.
	var colorCache = {};

	/**
	 * djb2-ish string hash -> 0..1 fraction.
	 */
	function hash01( str ) {
		var h = 5381;
		var s = String( str || '' );
		for ( var i = 0; i < s.length; i++ ) {
			h = ( ( h << 5 ) + h + s.charCodeAt( i ) ) >>> 0;
		}
		return ( h % 100000 ) / 100000;
	}

	/**
	 * Hash a type slug to a stable hue (0..359, snapped to a 24-step wheel).
	 */
	function hashHue( str ) {
		return Math.round( ( hash01( str ) * 24 ) ) * 15 % 360;
	}

	/**
	 * Parse #rgb / #rrggbb into [r,g,b] 0..255 or null.
	 */
	function hexToRgb( hex ) {
		var h = String( hex || '' ).replace( /^#/, '' ).trim();
		if ( h.length === 3 ) {
			h = h.charAt( 0 ) + h.charAt( 0 ) + h.charAt( 1 ) + h.charAt( 1 ) + h.charAt( 2 ) + h.charAt( 2 );
		}
		if ( ! /^[0-9a-f]{6}$/i.test( h ) ) {
			return null;
		}
		return [
			parseInt( h.substr( 0, 2 ), 16 ),
			parseInt( h.substr( 2, 2 ), 16 ),
			parseInt( h.substr( 4, 2 ), 16 )
		];
	}

	/**
	 * [r,g,b] 0..255 -> #rrggbb.
	 */
	function rgbToHex( rgb ) {
		var out = '#';
		for ( var i = 0; i < 3; i++ ) {
			var v = Math.max( 0, Math.min( 255, Math.round( rgb[ i ] ) ) );
			out += ( '0' + v.toString( 16 ) ).slice( -2 );
		}
		return out;
	}

	/**
	 * WCAG 2.2 relative luminance of a hex color.
	 */
	function luminance( hex ) {
		var rgb = hexToRgb( hex );
		if ( ! rgb ) {
			return 0;
		}
		var lin = [];
		for ( var i = 0; i < 3; i++ ) {
			var s = rgb[ i ] / 255;
			lin.push( s <= 0.04045 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 ) );
		}
		return 0.2126 * lin[ 0 ] + 0.7152 * lin[ 1 ] + 0.0722 * lin[ 2 ];
	}

	/**
	 * WCAG contrast ratio between two hex colors.
	 */
	function contrastRatio( a, b ) {
		var la = luminance( a );
		var lb = luminance( b );
		var hi = la >= lb ? la : lb;
		var lo = la >= lb ? lb : la;
		return ( hi + 0.05 ) / ( lo + 0.05 );
	}

	/**
	 * [r,g,b] -> [h,s,l] (0..1 each).
	 */
	function rgbToHsl( rgb ) {
		var r = rgb[ 0 ] / 255;
		var g = rgb[ 1 ] / 255;
		var b = rgb[ 2 ] / 255;
		var maxC = Math.max( r, g, b );
		var minC = Math.min( r, g, b );
		var l = ( maxC + minC ) / 2;
		var h;
		var s;

		if ( maxC === minC ) {
			return [ 0, 0, l ];
		}
		var d = maxC - minC;
		s = l > 0.5 ? d / ( 2 - maxC - minC ) : d / ( maxC + minC );
		if ( maxC === r ) {
			h = ( g - b ) / d + ( g < b ? 6 : 0 );
		} else if ( maxC === g ) {
			h = ( b - r ) / d + 2;
		} else {
			h = ( r - g ) / d + 4;
		}
		return [ h / 6, s, l ];
	}

	/**
	 * [h,s,l] (0..1) -> [r,g,b] 0..255.
	 */
	function hslToRgb( hsl ) {
		var h = hsl[ 0 ] - Math.floor( hsl[ 0 ] );
		var s = hsl[ 1 ];
		var l = hsl[ 2 ];
		var r;
		var g;
		var b;

		if ( s <= 0.0001 ) {
			r = g = b = Math.round( l * 255 );
			return [ r, g, b ];
		}

		var q = l < 0.5 ? l * ( 1 + s ) : l + s - l * s;
		var p = 2 * l - q;

		function hue2rgb( t ) {
			if ( t < 0 ) { t += 1; }
			if ( t > 1 ) { t -= 1; }
			if ( t < 1 / 6 ) { return p + ( q - p ) * 6 * t; }
			if ( t < 1 / 2 ) { return q; }
			if ( t < 2 / 3 ) { return p + ( q - p ) * ( 2 / 3 - t ) * 6; }
			return p;
		}

		return [
			Math.round( hue2rgb( h + 1 / 3 ) * 255 ),
			Math.round( hue2rgb( h ) * 255 ),
			Math.round( hue2rgb( h - 1 / 3 ) * 255 )
		];
	}

	/**
	 * Mirror of Tokens::ensure_contrast() — adjusts lightness until the
	 * color meets the minimum ratio against the canvas.
	 */
	function ensureContrast( hex, canvas, min ) {
		min = min || MIN_CONTRAST;
		var rgb = hexToRgb( hex );
		if ( ! rgb ) {
			return ensureContrast( '#7c9ff2', canvas, min );
		}
		if ( contrastRatio( rgbToHex( rgb ), canvas ) >= min ) {
			return rgbToHex( rgb );
		}
		var hsl = rgbToHsl( rgb );
		var step = luminance( canvas ) < 0.5 ? 0.015 : -0.015;
		for ( var i = 0; i < 40; i++ ) {
			hsl[ 2 ] += step;
			if ( hsl[ 2 ] <= 0.02 || hsl[ 2 ] >= 0.98 ) {
				break;
			}
			var next = rgbToHex( hslToRgb( hsl ) );
			if ( contrastRatio( next, canvas ) >= min ) {
				return next;
			}
		}
		return rgbToHex( hslToRgb( hsl ) );
	}

	/**
	 * Resolve visual.theme to a concrete 'dark' | 'light'.
	 */
	function resolveTheme( visual ) {
		var theme = ( visual && visual.theme ) || 'dark';
		if ( theme === 'auto' ) {
			if ( window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches ) {
				return 'dark';
			}
			return 'light';
		}
		if ( theme === 'admin' ) {
			var bodyClass = ( document.body && document.body.className ) || '';
			var darkSchemes = /admin-color-(midnight|coffee|ectoplasm|blue)/;
			if ( darkSchemes.test( bodyClass ) ) {
				return 'dark';
			}
			if ( /admin-color-/.test( bodyClass ) ) {
				return 'light';
			}
			// Not in wp-admin: fall back to the OS preference.
			if ( window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches ) {
				return 'dark';
			}
			return 'light';
		}
		return theme === 'light' ? 'light' : 'dark';
	}

	/**
	 * Resolved token map for a visual config.
	 */
	function tokensFor( visual ) {
		var themes = ( visual && visual.themes ) || {};
		return themes[ resolveTheme( visual ) ] || themes.dark || {};
	}

	/**
	 * Deterministic algorithmic color for an unknown type slug.
	 */
	function fallbackColor( type, visual ) {
		var t = tokensFor( visual );
		var hue = hashHue( type );
		var dark = resolveTheme( visual ) === 'dark';
		var sat = dark ? 0.62 : 0.55;
		var lit = dark ? 0.52 : 0.42;
		var base = rgbToHex( hslToRgb( [ hue / 360, sat, lit ] ) );
		return ensureContrast( base, t.canvas );
	}

	/**
	 * Resolved node color for a type (override > palette > algorithmic).
	 */
	function colorForType( type, visual ) {
		var canvas = tokensFor( visual ).canvas;
		var key = type + '|' + canvas;
		if ( colorCache[ key ] ) {
			return colorCache[ key ];
		}

		var overrides = ( visual && visual.type_colors ) || {};
		var palette = ( visual && visual.type_palette ) || {};
		var base = overrides[ type ] || palette[ type ];

		if ( ! base ) {
			base = fallbackColor( type, visual );
		}

		var resolved = ensureContrast( base, canvas );
		if ( Object.keys( colorCache ).length > 500 ) {
			colorCache = {};
		}
		colorCache[ key ] = resolved;
		return resolved;
	}

	/**
	 * Deterministic community color (stable per community id).
	 */
	function communityColor( communityId, visual ) {
		var palette = ( visual && visual.community_palette ) || [ '#7c9ff2' ];
		var idx = Math.floor( hash01( communityId ) * palette.length ) % palette.length;
		return ensureContrast( palette[ idx ], tokensFor( visual ).canvas );
	}

	/**
	 * Sequential degree ramp color.
	 */
	function degreeColor( degree, maxDegree, visual ) {
		var ramp = ( visual && visual.degree_ramp ) || [ '#440154', '#3b528b', '#21918c', '#5ec962', '#fde725' ];
		var max = Math.max( 1, maxDegree || 1 );
		var idx = Math.min( ramp.length - 1, Math.floor( ( degree / max ) * ramp.length ) );
		return ensureContrast( ramp[ idx ], tokensFor( visual ).canvas );
	}

	/**
	 * Single dispatcher: node color per visual.color_by.
	 */
	function nodeColor( visual, data, maxDegree ) {
		var mode = ( visual && visual.color_by ) || 'type';
		switch ( mode ) {
			case 'community':
				return communityColor( data.community_id || data.id || '', visual );
			case 'degree':
				return degreeColor( data.degree || 0, maxDegree || 1, visual );
			case 'monochrome':
				return tokensFor( visual ).accent;
			case 'type':
			default:
				return colorForType( data.type || 'entity', visual );
		}
	}

	/**
	 * Icon slug for a type (override > curated map > '').
	 */
	function iconForType( type, visual ) {
		var overrides = ( visual && visual.type_icons ) || {};
		var map = ( visual && visual.type_icon_map ) || {};
		return overrides[ type ] || map[ type ] || '';
	}

	/**
	 * Monogram (first letter) for a type — the non-color fallback encoding.
	 */
	function monogramFor( type ) {
		var slug = String( type || '' ).replace( /[-_]+/g, ' ' ).trim();
		return slug.charAt( 0 ).toUpperCase();
	}

	/**
	 * XML-escape a string for inline SVG.
	 */
	function xmlEscape( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&apos;' );
	}

	/**
	 * Build an SVG data URI for a glyph or monogram.
	 */
	function iconDataUri( visual, type, nodeBg, tokens ) {
		var registry = ( window.nvoosContentGraphIcons && window.nvoosContentGraphIcons.catalog ) || {};
		var slug = iconForType( type, visual );
		var glyph = registry[ slug ];
		var mode = ( visual && visual.icon_mode ) || 'filled';
		var strokeWidth = mode === 'high' ? 2.6 : 1.8;

		// Glyph color: the type color on outline nodes (colored stroke on a
		// neutral node), white on filled nodes (best contrast).
		var stroke = mode === 'outline' ? ( nodeBg || tokens.node_label ) : '#ffffff';
		var inner;

		if ( glyph ) {
			inner = '<path fill="none" stroke="' + stroke + '" stroke-width="' + strokeWidth +
				'" stroke-linecap="round" stroke-linejoin="round" d="' + xmlEscape( glyph.d ) + '"/>';
		} else {
			var letter = monogramFor( type );
			inner = '<text x="12" y="12.5" text-anchor="middle" dominant-baseline="central" ' +
				'font-family="sans-serif" font-size="10" font-weight="700" fill="' + stroke + '">' +
				xmlEscape( letter ) + '</text>';
		}

		var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">' + inner + '</svg>';
		return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( svg );
	}

	/**
	 * Edge family token for a relation string.
	 */
	function edgeFamilyColor( relation, tokens, visual ) {
		var families = ( visual && visual.edge_families ) || {};
		var rel = String( relation || '' ).toLowerCase();
		var tokenMap = {
			hierarchical: 'edge_hierarchy',
			similarity: 'edge_similarity',
			reference: 'edge_reference',
			authorship: 'edge_authorship'
		};
		for ( var family in tokenMap ) {
			if ( ! Object.prototype.hasOwnProperty.call( tokenMap, family ) ) {
				continue;
			}
			var patterns = families[ family ] || [];
			for ( var j = 0; j < patterns.length; j++ ) {
				if ( rel.indexOf( patterns[ j ] ) !== -1 ) {
					return tokens[ tokenMap[ family ] ] || tokens.edge;
				}
			}
		}
		return tokens.edge;
	}

	/**
	 * Build the Cytoscape stylesheet for a visual config.
	 *
	 * @param {Object} visual Visual config.
	 * @param {Object} ctx    { cy: instance } — style functions close over
	 *                        cy for zoom-aware label density.
	 * @return {Array} Cytoscape style array.
	 */
	function buildStylesheet( visual, ctx ) {
		var t = tokensFor( visual );
		var minZoom = ( visual && typeof visual.min_label_zoom === 'number' ) ? visual.min_label_zoom : 0.35;
		var fontSize = ( visual && visual.label_font_size ) || 10;

		var labelFn = function ( el ) {
			var cy = ctx && ctx.cy;
			if ( cy && minZoom > 0 && cy.zoom() < minZoom ) {
				return '';
			}
			return el.data( 'label' ) || '';
		};

		return [
			{
				selector: 'node',
				style: {
					'label': labelFn,
					'font-size': fontSize,
					'color': t.node_label,
					'text-halign': 'center',
					'text-valign': 'bottom',
					'text-margin-y': 4,
					'text-max-width': 90,
					'text-wrap': 'ellipsis',
					'background-color': 'data(color)',
					'background-image': 'data(icon)',
					'background-fit': 'none',
					'background-width': '56%',
					'background-height': '56%',
					'background-position-x': '50%',
					'background-position-y': '50%',
					'shape': 'data(shape)',
					'width': 'data(size)',
					'height': 'data(size)',
					'border-width': 1,
					'border-color': t.border
				}
			},
			{
				selector: 'edge',
				style: {
					'width': 'data(edgeWidth)',
					'line-color': 'data(edgeColor)',
					'opacity': 0.55,
					'curve-style': 'data(curve)',
					'target-arrow-shape': 'data(arrow)',
					'target-arrow-color': 'data(edgeColor)',
					'arrow-scale': 0.7,
					'label': 'data(edgeLabel)',
					'font-size': 8,
					'color': t.edge_label,
					'text-background-color': t.canvas,
					'text-background-opacity': 0.85,
					'text-background-padding': '2px',
					'text-rotation': 'autorotate',
					'text-wrap': 'ellipsis',
					'text-max-width': 70
				}
			},
			{
				// Hover/selection mode edge labels (class toggled by the app).
				selector: 'edge.edge-label-on',
				style: {
					'label': 'data(relation)'
				}
			},
			{
				selector: ':selected',
				style: {
					'border-width': 3,
					'border-color': t.selection,
					'opacity': 1
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
		];
	}

	/**
	 * Apply theme tokens to the container as CSS custom properties so the
	 * legend, minimap, toolbar and sidebar chrome match the canvas.
	 */
	function applyChrome( container, visual ) {
		if ( ! container ) {
			return;
		}
		var t = tokensFor( visual );
		var map = {
			'--nvoos-cg-canvas': t.canvas,
			'--nvoos-cg-surface': t.surface,
			'--nvoos-cg-border': t.border,
			'--nvoos-cg-label': t.node_label,
			'--nvoos-cg-muted': t.muted,
			'--nvoos-cg-accent': t.accent,
			'--nvoos-cg-edge': t.edge
		};
		for ( var key in map ) {
			if ( Object.prototype.hasOwnProperty.call( map, key ) ) {
				container.style.setProperty( key, map[ key ] );
			}
		}
	}

	/**
	 * Layout presets for the explorer toolbar.
	 */
	function layoutPresets( visual ) {
		var anim = ! ( visual && visual.anim_enabled === false ) && ! reducedMotion();
		return {
			'fcose-balanced': {
				label: 'Force — balanced',
				options: { name: 'fcose', animate: anim, animationDuration: 800, quality: 'default', nodeDimensionsIncludeLabels: true }
			},
			'fcose-compact': {
				label: 'Force — compact',
				options: { name: 'fcose', animate: anim, animationDuration: 800, quality: 'default', nodeSeparation: 50 }
			},
			'circle': {
				label: 'Circle',
				options: { name: 'circle', animate: anim }
			},
			'grid': {
				label: 'Grid',
				options: { name: 'grid', animate: anim }
			},
			'concentric': {
				label: 'Concentric',
				options: { name: 'concentric', animate: anim, minNodeSpacing: 30 }
			},
			'breadthfirst': {
				label: 'Breadth-first',
				options: { name: 'breadthfirst', animate: anim, directed: true }
			}
		};
	}

	/**
	 * OS reduced-motion preference.
	 */
	function reducedMotion() {
		return !!( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	/**
	 * Node size from degree with a sqrt ramp (hubs dominate less than a
	 * linear map).
	 */
	function nodeSize( degree, maxDegree, visual ) {
		var min = ( visual && visual.size_min ) || 12;
		var max = ( visual && visual.size_max ) || 60;
		var maxDeg = Math.max( 1, maxDegree || 1 );
		var frac = Math.sqrt( Math.max( 0, degree || 0 ) / maxDeg );
		return Math.round( min + ( max - min ) * frac );
	}

	window.nvoosContentGraphTheme = {
		resolveTheme: resolveTheme,
		tokens: tokensFor,
		luminance: luminance,
		contrastRatio: contrastRatio,
		ensureContrast: ensureContrast,
		hashHue: hashHue,
		colorForType: colorForType,
		communityColor: communityColor,
		degreeColor: degreeColor,
		nodeColor: nodeColor,
		iconForType: iconForType,
		monogramFor: monogramFor,
		iconDataUri: iconDataUri,
		edgeFamilyColor: edgeFamilyColor,
		buildStylesheet: buildStylesheet,
		applyChrome: applyChrome,
		layoutPresets: layoutPresets,
		reducedMotion: reducedMotion,
		nodeSize: nodeSize
	};
}() );
