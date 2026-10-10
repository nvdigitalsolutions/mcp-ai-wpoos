/**
 * Chat Chart Recipes — schema-driven, HTML/CSS chart rendering for chat.
 *
 * Renders `nvoos-chart` fenced code blocks from assistant messages as
 * rhp-style (Reactive HTML Plots) charts built from plain HTML slats —
 * no SVG, no canvas, no third-party chart library.
 *
 * Security model (see docs/chat-chart-recipes-plan.md):
 *  - The assistant's JSON never flows through marked or DOMPurify.
 *  - All chart DOM is built with createElement/textContent and fixed class
 *    names; inline styles contain only computed numbers and our own
 *    `var(--chart-*)` references.
 *  - Invalid fences fall back to a standard escaped code block.
 *
 * Spec-identical ports exist at:
 *  - addons/pro/assets/spa-v2/src/components/shared/chartRecipes.ts
 *  - addons/chat-spa/src/api/chartRecipes.ts
 * Keep the schema, class structure and caps in sync across all three.
 *
 * @package WP_MCP_AI
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

'use strict';

const CHART_LANGUAGE = 'nvoos-chart';

const CHART_TYPE_LABELS = {
	bar: 'Bar chart',
	column: 'Column chart',
	line: 'Line chart',
	area: 'Area chart',
	dot: 'Scatter chart',
	donut: 'Donut chart',
	heatmap: 'Heatmap',
};

// Resource caps — prevent DOM blow-up from hostile model output.
const MAX_LABELS = 40;
const MAX_HEATMAP_ROWS = 20;
const MAX_SERIES = 12;
const MAX_POINTS = 120;

// Strict colour pattern: hex, rgb() or hsl() with numeric values only.
const COLOR_RE = /^(#[0-9a-f]{3,8}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)|hsl\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*\))$/i;

// The plot's fixed width:height ratio. Connector rotation math compensates
// for it so line/area segments stay geometrically accurate.
const PLOT_RATIO = 1.6;

/**
 * Escape HTML for the fallback code block.
 *
 * @param {string} text - Raw text.
 * @return {string} Escaped text.
 */
function escapeHtml( text ) {
	return String( text ).replace( /[&<>"']/g, function ( character ) {
		switch ( character ) {
			case '&':
				return '&amp;';
			case '<':
				return '&lt;';
			case '>':
				return '&gt;';
			case '"':
				return '&quot;';
			case '\'':
				return '&#39;';
			default:
				return character;
		}
	} );
}

/**
 * Create an element with a class and optional text (textContent only —
 * never innerHTML, so values are inert by construction).
 *
 * @param {string}  tag       - Tag name.
 * @param {string}  className - Class attribute value.
 * @param {string}  [text]    - Optional text content.
 * @return {HTMLElement} The element.
 */
function el( tag, className, text ) {
	const node = document.createElement( tag );
	if ( className ) {
		node.className = className;
	}
	if ( text !== undefined && text !== null ) {
		node.textContent = String( text );
	}
	return node;
}

/**
 * Split markdown text into segments, extracting `nvoos-chart` fences.
 *
 * Fences inside plain (non-chart) code fences are left untouched.
 * An unclosed chart fence consumes the rest of the text as its body.
 *
 * @param {string} text - Raw markdown.
 * @return {Array<{type: string, text?: string, code?: string}>} Segments.
 */
export function splitChartSegments( text ) {
	const lines = String( text ).split( '\n' );
	const segments = [];
	const buffer = [];
	let inCodeFence = null;
	let i = 0;

	while ( i < lines.length ) {
		const line = lines[ i ];
		const fenceMatch = line.match( /^\s{0,3}(`{3,}|~{3,})[ \t]*([a-zA-Z0-9_+#.-]*)/ );

		// Inside a plain code fence: everything is content.
		if ( inCodeFence ) {
			buffer.push( line );
			if ( fenceMatch && fenceMatch[ 1 ][ 0 ] === inCodeFence.char && fenceMatch[ 1 ].length >= inCodeFence.len ) {
				inCodeFence = null;
			}
			i++;
			continue;
		}

		if ( fenceMatch && CHART_LANGUAGE === fenceMatch[ 2 ].toLowerCase() ) {
			const fenceChar = fenceMatch[ 1 ][ 0 ];
			const fenceLen = fenceMatch[ 1 ].length;
			const closeChar = fenceChar === '`' ? '\\`' : fenceChar;
			const closeRe = new RegExp( '^\\s{0,3}' + closeChar + '{' + fenceLen + ',}\\s*$' );
			const codeLines = [];
			let j = i + 1;
			let closed = false;
			while ( j < lines.length ) {
				if ( closeRe.test( lines[ j ] ) ) {
					closed = true;
					break;
				}
				codeLines.push( lines[ j ] );
				j++;
			}
			if ( buffer.length ) {
				segments.push( { type: 'markdown', text: buffer.join( '\n' ) } );
				buffer.length = 0;
			}
			segments.push( { type: 'chart', code: codeLines.join( '\n' ) } );
			i = closed ? j + 1 : j;
			continue;
		}

		buffer.push( line );
		if ( fenceMatch && fenceMatch[ 1 ] ) {
			inCodeFence = { char: fenceMatch[ 1 ][ 0 ], len: fenceMatch[ 1 ].length };
		}
		i++;
	}

	if ( buffer.length ) {
		segments.push( { type: 'markdown', text: buffer.join( '\n' ) } );
	}
	return segments;
}

/**
 * Coerce a value list to finite numbers.
 *
 * @param {*}      arr - Candidate array.
 * @param {number} max - Maximum entries.
 * @return {Array<number>|null} Numbers, or null when unusable.
 */
function toFiniteNumbers( arr, max ) {
	if ( ! Array.isArray( arr ) ) {
		return null;
	}
	const out = [];
	for ( let i = 0; i < arr.length && out.length < max; i++ ) {
		const raw = arr[ i ];
		if ( typeof raw === 'number' ) {
			if ( isFinite( raw ) ) {
				out.push( raw );
			}
		} else if ( typeof raw === 'string' && raw.trim() !== '' ) {
			const n = Number( raw );
			if ( isFinite( n ) ) {
				out.push( n );
			}
		}
	}
	return out;
}

/**
 * Coerce a label list to strings.
 *
 * @param {*}      arr - Candidate array.
 * @param {number} max - Maximum entries.
 * @return {Array<string>|null} Labels, or null when unusable.
 */
function toLabels( arr, max ) {
	if ( ! Array.isArray( arr ) ) {
		return null;
	}
	const out = [];
	for ( let i = 0; i < arr.length && out.length < max; i++ ) {
		const raw = arr[ i ];
		if ( typeof raw === 'string' || typeof raw === 'number' ) {
			out.push( String( raw ).slice( 0, 60 ) );
		}
	}
	return out;
}

/**
 * Parse and validate the JSON body of an `nvoos-chart` fence.
 *
 * @param {string} code - Fence body.
 * @return {{ok: true, spec: Object}|{ok: false}} Normalised spec or failure.
 */
export function parseChartSpec( code ) {
	let raw;
	try {
		raw = JSON.parse( code );
	} catch ( error ) {
		return { ok: false };
	}
	if ( ! raw || typeof raw !== 'object' || Array.isArray( raw ) ) {
		return { ok: false };
	}

	const type = typeof raw.type === 'string' ? raw.type.toLowerCase() : '';
	if ( ! Object.prototype.hasOwnProperty.call( CHART_TYPE_LABELS, type ) ) {
		return { ok: false };
	}

	const title = typeof raw.title === 'string' && raw.title.trim() ? raw.title.trim().slice( 0, 120 ) : null;
	const caption = typeof raw.caption === 'string' && raw.caption.trim() ? raw.caption.trim().slice( 0, 120 ) : null;

	let labels = toLabels( raw.labels, type === 'heatmap' ? MAX_HEATMAP_ROWS : MAX_LABELS );

	// Normalise series (values shorthand → single unnamed series).
	const series = [];
	let usableSeries = null;
	if ( Array.isArray( raw.series ) ) {
		usableSeries = raw.series.slice( 0, MAX_SERIES );
	} else if ( raw.values !== undefined ) {
		const values = toFiniteNumbers( raw.values, MAX_POINTS );
		if ( values ) {
			usableSeries = [ { values } ];
		}
	}
	if ( usableSeries ) {
		for ( let i = 0; i < usableSeries.length; i++ ) {
			const s = usableSeries[ i ];
			if ( ! s || typeof s !== 'object' ) {
				continue;
			}
			const values = toFiniteNumbers( s.values, MAX_POINTS );
			if ( ! values || values.length === 0 ) {
				continue;
			}
			const name = typeof s.name === 'string' && s.name.trim() ? s.name.trim().slice( 0, 60 ) : null;
			const color = typeof s.color === 'string' && COLOR_RE.test( s.color ) ? s.color : null;
			series.push( { name, values, color } );
		}
	}

	if ( series.length === 0 ) {
		return { ok: false };
	}

	// Donut: first series only, non-negative, labels aligned to values.
	if ( type === 'donut' ) {
		const first = series[ 0 ];
		first.values = first.values.map( function ( v ) {
			return v < 0 ? 0 : v;
		} );
		if ( labels ) {
			labels = labels.slice( 0, first.values.length );
		}
		if ( first.values.length === 0 ) {
			return { ok: false };
		}
		series.length = 1;
	}

	return { ok: true, spec: { type, title, caption, labels, series } };
}

/**
 * Format a number for display.
 *
 * @param {number} v - Value.
 * @return {string} Formatted value.
 */
function formatNumber( v ) {
	if ( typeof v !== 'number' || ! isFinite( v ) ) {
		return '—';
	}
	const abs = Math.abs( v );
	let out;
	if ( abs >= 100000 ) {
		out = Math.round( v ).toLocaleString( 'en-US' );
	} else if ( abs >= 100 ) {
		out = Math.round( v * 10 ) / 10 + '';
	} else if ( abs >= 1 ) {
		out = Math.round( v * 100 ) / 100 + '';
	} else {
		out = Math.round( v * 1000 ) / 1000 + '';
	}
	return out;
}

/**
 * Resolve a series colour: validated user colour or the internal palette.
 *
 * @param {number}      idx   - Series index.
 * @param {string|null} color - User-supplied colour (already validated).
 * @return {string} CSS colour value (safe — var() reference or validated literal).
 */
function seriesColor( idx, color ) {
	if ( color ) {
		return color;
	}
	return 'var(--chart-series-' + ( ( idx % 8 ) + 1 ) + ')';
}

/**
 * Apply a series colour to a node via the `--bar-color` custom property so
 * CSS (e.g. the negative-value override) can still re-style the element.
 *
 * @param {HTMLElement} node  - Target element.
 * @param {number}      idx   - Series index.
 * @param {string|null} color - User-supplied colour (already validated).
 */
function applySeriesColor( node, idx, color ) {
	node.style.setProperty( '--bar-color', seriesColor( idx, color ) );
}

/**
 * Largest absolute value across all series (scaling factor).
 *
 * @param {Object} spec - Normalised spec.
 * @return {number} Max absolute value.
 */
function maxAbsValue( spec ) {
	let max = 0;
	spec.series.forEach( function ( series ) {
		series.values.forEach( function ( v ) {
			const a = Math.abs( v );
			if ( a > max ) {
				max = a;
			}
		} );
	} );
	return max;
}

/**
 * Whether a legend is needed.
 *
 * @param {Object} spec - Normalised spec.
 * @return {boolean} True when a legend should be rendered.
 */
function needsLegend( spec ) {
	return spec.series.length > 1 || ( spec.series.length === 1 && !! spec.series[ 0 ].name );
}

/**
 * Build the legend row.
 *
 * @param {Object} spec   - Normalised spec.
 * @param {string} prefix - Class prefix.
 * @return {HTMLElement} Legend element.
 */
function buildLegend( spec, prefix ) {
	const legend = el( 'div', prefix + '-legend' );
	spec.series.forEach( function ( series, idx ) {
		const item = el( 'span', prefix + '-legend-item' );
		const swatch = el( 'span', prefix + '-legend-swatch' );
		applySeriesColor( swatch, idx, series.color );
		item.appendChild( swatch );
		item.appendChild( el( 'span', prefix + '-legend-label', series.name || '' ) );
		legend.appendChild( item );
	} );
	return legend;
}

/**
 * Build the x-axis label row shared by column/line/area/dot plots.
 *
 * @param {Object}       spec   - Normalised spec.
 * @param {string}       prefix - Class prefix.
 * @param {number}       n      - Number of label slots.
 * @return {HTMLElement} Axis element.
 */
function buildXAxis( spec, prefix, n ) {
	const axis = el( 'div', prefix + '-axis' );
	for ( let i = 0; i < n; i++ ) {
		axis.appendChild( el( 'span', prefix + '-axis-label', ( spec.labels && spec.labels[ i ] ) || '' ) );
	}
	return axis;
}

/**
 * Point-count used for the x mapping of plot charts.
 *
 * @param {Object} spec - Normalised spec.
 * @return {number} Number of x slots.
 */
function plotSlotCount( spec ) {
	let n = 1;
	spec.series.forEach( function ( series ) {
		if ( series.values.length > n ) {
			n = series.values.length;
		}
	} );
	if ( spec.labels && spec.labels.length > n ) {
		n = spec.labels.length;
	}
	return n;
}

/**
 * Build a horizontal bar body (center-baseline proportional slats).
 *
 * @param {Object} spec   - Normalised spec.
 * @param {string} prefix - Class prefix.
 * @return {HTMLElement} Body element.
 */
function buildBarBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--bar' );
	const maxAbs = maxAbsValue( spec );
	const n = plotSlotCount( spec );

	for ( let i = 0; i < n; i++ ) {
		const label = ( spec.labels && spec.labels[ i ] ) || '';
		spec.series.forEach( function ( series, sIdx ) {
			const v = series.values[ i ];
			if ( v === undefined ) {
				return;
			}
			const row = el( 'div', prefix + '-row' );
			const labelCell = el( 'div', prefix + '-row-label' );
			if ( sIdx === 0 ) {
				labelCell.textContent = label;
			} else if ( series.name ) {
				const swatch = el( 'span', prefix + '-row-swatch' );
				applySeriesColor( swatch, sIdx, series.color );
				labelCell.appendChild( swatch );
				labelCell.appendChild( el( 'span', prefix + '-row-series', series.name ) );
			}
			row.appendChild( labelCell );

			const track = el( 'div', prefix + '-track' );
			const frac = maxAbs > 0 ? v / maxAbs : 0;
			const bar = el( 'div', prefix + '-bar' + ( v < 0 ? ' ' + prefix + '-bar--negative' : '' ) );
			bar.style.left = ( 50 + Math.min( frac, 0 ) * 50 ).toFixed( 3 ) + '%';
			bar.style.width = ( Math.abs( frac ) * 50 ).toFixed( 3 ) + '%';
			applySeriesColor( bar, sIdx, series.color );
			track.appendChild( bar );

			const value = el( 'span', prefix + '-bar-value', formatNumber( v ) );
			const labelPos = Math.min( Math.max( 50 + frac * 50, 4 ), 96 );
			value.style.left = labelPos.toFixed( 3 ) + '%';
			value.style.transform = v < 0 ? 'translateX(calc(-100% - 6px))' : 'translateX(6px)';
			track.appendChild( value );

			row.appendChild( track );
			body.appendChild( row );
		} );
	}
	return body;
}

/**
 * Build a vertical column body (center-baseline bars per label group).
 *
 * @param {Object} spec   - Normalised spec.
 * @param {string} prefix - Class prefix.
 * @return {HTMLElement} Body element.
 */
function buildColumnBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--column' );
	const maxAbs = maxAbsValue( spec );
	const n = plotSlotCount( spec );
	const plot = el( 'div', prefix + '-col-plot' );
	const k = spec.series.length;
	const widthPct = Math.min( 70 / k, 40 );

	for ( let i = 0; i < n; i++ ) {
		const center = ( ( i + 0.5 ) / n * 100 ).toFixed( 3 );
		spec.series.forEach( function ( series, sIdx ) {
			const v = series.values[ i ];
			if ( v === undefined ) {
				return;
			}
			const frac = maxAbs > 0 ? v / maxAbs : 0;
			const offset = ( ( sIdx - ( k - 1 ) / 2 ) * widthPct ).toFixed( 3 );
			const bar = el( 'div', prefix + '-col-bar' + ( v < 0 ? ' ' + prefix + '-col-bar--negative' : '' ) );
			bar.style.left = center + '%';
			bar.style.marginLeft = offset + '%';
			bar.style.width = widthPct.toFixed( 3 ) + '%';
			applySeriesColor( bar, sIdx, series.color );
			if ( v >= 0 ) {
				bar.style.bottom = '50%';
				bar.style.height = ( frac * 50 ).toFixed( 3 ) + '%';
			} else {
				bar.style.top = '50%';
				bar.style.height = ( -frac * 50 ).toFixed( 3 ) + '%';
			}
			plot.appendChild( bar );

			const value = el( 'span', prefix + '-col-value', formatNumber( v ) );
			value.style.left = center + '%';
			value.style.marginLeft = offset + '%';
			value.style.width = widthPct.toFixed( 3 ) + '%';
			if ( v >= 0 ) {
				value.style.bottom = ( 50 + frac * 50 + 1 ).toFixed( 3 ) + '%';
			} else {
				value.style.top = ( 50 - frac * 50 + 1 ).toFixed( 3 ) + '%';
			}
			plot.appendChild( value );
		} );
	}
	body.appendChild( plot );
	body.appendChild( buildXAxis( spec, prefix, n ) );
	return body;
}

/**
 * Build a plot body for line, area and dot charts.
 *
 * @param {Object} spec   - Normalised spec.
 * @param {string} prefix - Class prefix.
 * @param {string} mode   - 'line' | 'area' | 'dot'.
 * @return {HTMLElement} Body element.
 */
function buildPlotBody( spec, prefix, mode ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--' + mode );
	const maxAbs = maxAbsValue( spec );
	const n = plotSlotCount( spec );
	const plot = el( 'div', prefix + '-plot' );

	spec.series.forEach( function ( series, sIdx ) {
		const layer = el( 'div', prefix + '-plot-series' );
		const values = series.values;
		let prevX = 0;
		for ( let i = 0; i < values.length; i++ ) {
			const v = values[ i ];
			const x = ( n > 1 ? ( i / ( n - 1 ) ) * 100 : 50 ).toFixed( 3 );
			let y = 50 - ( maxAbs > 0 ? v / maxAbs : 0 ) * 48;
			y = Math.min( Math.max( y, 2 ), 98 );

			if ( mode === 'area' ) {
				const fill = el( 'span', prefix + '-plot-fill' );
				fill.style.left = x + '%';
				fill.style.top = Math.min( y, 50 ).toFixed( 3 ) + '%';
				fill.style.height = Math.abs( y - 50 ).toFixed( 3 ) + '%';
				applySeriesColor( fill, sIdx, series.color );
				layer.appendChild( fill );
			}

			if ( i > 0 && mode !== 'dot' ) {
				const prev = values[ i - 1 ];
				let prevY = 50 - ( maxAbs > 0 ? prev / maxAbs : 0 ) * 48;
				prevY = Math.min( Math.max( prevY, 2 ), 98 );
				const dx = x - prevX;
				const dy = ( y - prevY ) / PLOT_RATIO;
				const length = Math.sqrt( dx * dx + dy * dy );
				if ( length > 0.01 ) {
					const angle = Math.atan2( dy, dx ) * 180 / Math.PI;
					const seg = el( 'span', prefix + '-plot-seg' );
					seg.style.left = prevX + '%';
					seg.style.top = prevY + '%';
					seg.style.width = length.toFixed( 3 ) + '%';
					seg.style.transform = 'rotate(' + angle.toFixed( 3 ) + 'deg)';
					applySeriesColor( seg, sIdx, series.color );
					layer.appendChild( seg );
				}
			}
			prevX = x;

			const dot = el( 'span', prefix + '-plot-dot' );
			dot.style.left = x + '%';
			dot.style.top = y.toFixed( 3 ) + '%';
			applySeriesColor( dot, sIdx, series.color );
			layer.appendChild( dot );
		}
		plot.appendChild( layer );
	} );

	body.appendChild( plot );
	body.appendChild( buildXAxis( spec, prefix, n ) );
	return body;
}

/**
 * Build a donut body (conic-gradient ring + hole + legend).
 *
 * @param {Object} spec   - Normalised spec.
 * @param {string} prefix - Class prefix.
 * @return {HTMLElement} Body element.
 */
function buildDonutBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--donut' );
	const series = spec.series[ 0 ];
	const total = series.values.reduce( function ( sum, v ) {
		return sum + v;
	}, 0 );
	const labels = spec.labels || [];

	const wrap = el( 'div', prefix + '-donut' );
	const ring = el( 'div', prefix + '-donut-ring' );
	if ( total > 0 ) {
		const stops = [];
		let acc = 0;
		series.values.forEach( function ( v, idx ) {
			const start = ( acc / total ) * 100;
			acc += v;
			const end = ( acc / total ) * 100;
			stops.push( seriesColor( idx, null ) + ' ' + start.toFixed( 3 ) + '% ' + end.toFixed( 3 ) + '%' );
		} );
		ring.style.background = 'conic-gradient(' + stops.join( ', ' ) + ')';
	} else {
		ring.style.background = 'var(--chart-border)';
	}
	const hole = el( 'div', prefix + '-donut-hole' );
	hole.appendChild( el( 'div', prefix + '-donut-total', formatNumber( total ) ) );
	ring.appendChild( hole );
	wrap.appendChild( ring );

	const legend = el( 'div', prefix + '-donut-legend' );
	series.values.forEach( function ( v, idx ) {
		const item = el( 'div', prefix + '-donut-legend-item' );
		const swatch = el( 'span', prefix + '-legend-swatch' );
		applySeriesColor( swatch, idx, null );
		item.appendChild( swatch );
		item.appendChild( el( 'span', prefix + '-donut-legend-label', labels[ idx ] || '' ) );
		item.appendChild( el( 'span', prefix + '-donut-legend-value', formatNumber( v ) ) );
		legend.appendChild( item );
	} );
	wrap.appendChild( legend );
	body.appendChild( wrap );
	return body;
}

/**
 * Build a heatmap body (CSS grid with opacity-scaled cells).
 *
 * @param {Object} spec   - Normalised spec.
 * @param {string} prefix - Class prefix.
 * @return {HTMLElement} Body element.
 */
function buildHeatmapBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--heatmap' );
	const maxAbs = maxAbsValue( spec );
	const cols = spec.series.length;
	const labels = spec.labels || [];
	const rows = Math.max( labels.length, spec.series[ 0 ].values.length );

	const grid = el( 'div', prefix + '-heat' );
	grid.style.gridTemplateColumns = 'auto repeat(' + cols + ', minmax(0, 1fr))';
	grid.appendChild( el( 'div', prefix + '-heat-corner' ) );
	spec.series.forEach( function ( series ) {
		grid.appendChild( el( 'div', prefix + '-heat-col-label', series.name || '' ) );
	} );

	for ( let r = 0; r < rows; r++ ) {
		grid.appendChild( el( 'div', prefix + '-heat-row-label', labels[ r ] || '' ) );
		for ( let c = 0; c < cols; c++ ) {
			const v = spec.series[ c ].values[ r ];
			const cell = el( 'div', prefix + '-heat-cell' + ( v < 0 ? ' ' + prefix + '-heat-cell--negative' : '' ) );
			const bg = el( 'span', prefix + '-heat-cell-bg' );
			bg.style.setProperty( '--v', ( maxAbs > 0 ? Math.abs( v ) / maxAbs : 0 ).toFixed( 3 ) );
			cell.appendChild( bg );
			cell.appendChild( el( 'span', prefix + '-heat-cell-text', formatNumber( v ) ) );
			grid.appendChild( cell );
		}
	}
	body.appendChild( grid );
	return body;
}

/**
 * Build the chart body for a normalised spec.
 *
 * @param {Object} spec   - Normalised spec.
 * @param {string} prefix - Class prefix.
 * @return {HTMLElement} Body element.
 */
function buildChartBody( spec, prefix ) {
	switch ( spec.type ) {
		case 'bar':
			return buildBarBody( spec, prefix );
		case 'column':
			return buildColumnBody( spec, prefix );
		case 'line':
		case 'area':
		case 'dot':
			return buildPlotBody( spec, prefix, spec.type );
		case 'donut':
			return buildDonutBody( spec, prefix );
		case 'heatmap':
			return buildHeatmapBody( spec, prefix );
		default:
			return el( 'div', prefix + '-body' );
	}
}

/**
 * Render an `nvoos-chart` fence body to safe chart HTML.
 *
 * Invalid fences fall back to a standard escaped code block.
 *
 * @param {string} code     - Fence body.
 * @param {Object} [options] - Overrides.
 * @param {string} [options.classPrefix]    - Chart class prefix.
 * @param {string} [options.codeBlockClass] - Fallback code block class.
 * @return {string} HTML.
 */
export function renderChartRecipe( code, options ) {
	const opts = options || {};
	const classPrefix = opts.classPrefix || 'wp-mcp-ai-chat__chart-recipe';
	const codeBlockClass = opts.codeBlockClass || 'wp-mcp-ai-chat__code-block';

	const parsed = parseChartSpec( code );
	if ( ! parsed.ok ) {
		return (
			'<pre class="' + codeBlockClass + '"><code class="language-' + CHART_LANGUAGE + '">' +
			escapeHtml( code || '' ) +
			'</code></pre>'
		);
	}

	const root = el( 'div', classPrefix );
	root.setAttribute( 'role', 'figure' );
	root.setAttribute( 'aria-label', parsed.spec.title || CHART_TYPE_LABELS[ parsed.spec.type ] );
	if ( parsed.spec.title ) {
		root.appendChild( el( 'div', classPrefix + '-title', parsed.spec.title ) );
	}
	if ( needsLegend( parsed.spec ) ) {
		root.appendChild( buildLegend( parsed.spec, classPrefix ) );
	}
	root.appendChild( buildChartBody( parsed.spec, classPrefix ) );
	if ( parsed.spec.caption ) {
		root.appendChild( el( 'div', classPrefix + '-caption', parsed.spec.caption ) );
	}
	return root.outerHTML;
}
