/**
 * Chart Recipes — schema-driven, HTML/CSS chart rendering for chat.
 *
 * v2: renders `nvoos-chart` fenced code blocks from assistant messages as
 * rhp-style (Reactive HTML Plots) charts built from plain HTML slats —
 * no SVG, no canvas, no third-party chart library.
 *
 * v2 schema (backward compatible with v1):
 *   { "type", "title"?, "caption"?, "labels"?, "values"? | "series"?,
 *     "mode": "grouped|stacked|100"?, "direction": "horizontal|vertical"?,
 *     "sort": "asc|desc|none"?, "unit"?, "animate": true|"race"?,
 *     "table": true|false?, "data": {"items":[{label?,value?|values?}]}?,
 *     "target"?, "max"?, "charts": [{...spec}]? }
 *
 * Type families: bar/column/line/area/dot/donut/heatmap (v1) plus
 * histogram/box/strip/stem/violin/pie/waffle/unit/diverging/lollipop/
 * dumbbell/slope/bullet/pyramid/waterfall/candlestick/gantt/sparkline/
 * radial/dial/race/grid (v2).
 *
 * Security model (see docs/chat-chart-recipes-plan.md and
 * docs/chat-chart-recipes-enhancement-plan.md):
 *  - The assistant's JSON never flows through marked or DOMPurify.
 *  - All chart DOM is built with createElement/textContent and fixed class
 *    names; inline styles contain only computed numbers and our own
 *    `var(--chart-*)` references.
 *  - Invalid fences fall back to a standard escaped code block.
 *
 * Spec-identical ports exist at:
 *  - addons/pro/assets/spa-v2/src/components/shared/chartRecipes.ts
 *  - addons/chat-spa/src/api/chartRecipes.ts
 * Keep the schema, class structure and caps in sync across all three;
 * the shared conformance fixtures (assets/js/chart-fixtures.json) are the
 * drift gate.
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
	histogram: 'Histogram',
	box: 'Box plot',
	strip: 'Strip plot',
	stem: 'Stem plot',
	violin: 'Violin plot',
	pie: 'Pie chart',
	waffle: 'Waffle chart',
	unit: 'Unit chart',
	diverging: 'Diverging bar chart',
	lollipop: 'Lollipop chart',
	dumbbell: 'Dumbbell chart',
	slope: 'Slope chart',
	bullet: 'Bullet chart',
	pyramid: 'Population pyramid',
	waterfall: 'Waterfall chart',
	candlestick: 'Candlestick chart',
	gantt: 'Gantt chart',
	sparkline: 'Sparkline',
	radial: 'Radial bar chart',
	dial: 'Dial chart',
	race: 'Bar race chart',
	grid: 'Chart grid',
};

// Resource caps — prevent DOM blow-up from hostile model output.
const MAX_LABELS = 40;
const MAX_HEATMAP_ROWS = 20;
const MAX_SERIES = 12;
const MAX_POINTS = 120;
const MAX_WAFFLE_CELLS = 400;
const MAX_UNIT_CELLS = 60;
const MAX_RACE_LABELS = 30;
const MAX_GRID_CHARTS = 12;
const MAX_GANTT_ITEMS = 40;
const MAX_STR = 60;
const MAX_TITLE = 120;

// Strict colour pattern: hex, rgb() or hsl() with numeric values only.
const COLOR_RE = /^(#[0-9a-f]{3,8}|rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)|hsl\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*\))$/i;

// The plot's fixed width:height ratio. Connector rotation math compensates
// for it so line/area/slope segments stay geometrically accurate.
const PLOT_RATIO = 1.6;

/**
 * @typedef {'bar'|'column'|'line'|'area'|'dot'|'donut'|'heatmap'|'histogram'|'box'|'strip'|'stem'|'violin'|'pie'|'waffle'|'unit'|'diverging'|'lollipop'|'dumbbell'|'slope'|'bullet'|'pyramid'|'waterfall'|'candlestick'|'gantt'|'sparkline'|'radial'|'dial'|'race'|'grid'} ChartType
 * @typedef {'grouped'|'stacked'|'100'} ChartMode
 * @typedef {'horizontal'|'vertical'} ChartDirection
 * @typedef {Object} ChartSeries
 * @property {string|null} name
 * @property {number[]} values
 * @property {string|null} color
 * @typedef {Object} ChartSpec
 * @property {ChartType} type
 * @property {string|null} title
 * @property {string|null} caption
 * @property {string[]|null} labels
 * @property {ChartSeries[]} series
 * @property {ChartMode} mode
 * @property {ChartDirection|null} direction
 * @property {'asc'|'desc'|'none'} sort
 * @property {string|null} unit
 * @property {(boolean|'race')} animate
 * @property {boolean} table
 * @property {number|null} target
 * @property {number|null} max
 * @property {ChartSpec[]} charts
 * @typedef {{type:'markdown',text:string}|{type:'chart',code:string}} ChartSegment
 * @typedef {Object} ChartRecipeOptions
 * @property {string} [classPrefix]
 * @property {string} [codeBlockClass]
 * @typedef {{ok:true,spec:ChartSpec}|{ok:false}} ChartSpecResult
 */

/**
 * Escape HTML for the fallback code block.
 */
function escapeHtml( text ) {
	return String( text ).replace( /[&<>"']/g, ( character ) => {
		switch ( character ) {
			case '&':
				return '&amp;';
			case '<':
				return '&lt;';
			case '>':
				return '&gt;';
			case '"':
				return '&quot;';
			case "'":
				return '&#39;';
			default:
				return character;
		}
	} );
}

/**
 * Create an element with a class and optional text (textContent only —
 * never innerHTML, so values are inert by construction).
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
 */
function toLabels( arr, max ) {
	if ( ! Array.isArray( arr ) ) {
		return null;
	}
	const out = [];
	for ( let i = 0; i < arr.length && out.length < max; i++ ) {
		const raw = arr[ i ];
		if ( typeof raw === 'string' || typeof raw === 'number' ) {
			out.push( String( raw ).slice( 0, MAX_STR ) );
		}
	}
	return out;
}

/**
 * Parse a finite, optional number.
 */
function toOptionalNumber( raw ) {
	if ( typeof raw === 'number' && isFinite( raw ) ) {
		return raw;
	}
	if ( typeof raw === 'string' && raw.trim() !== '' ) {
		const n = Number( raw );
		return isFinite( n ) ? n : null;
	}
	return null;
}

/**
 * Optional single string, trimmed and capped.
 */
function toOptionalString( raw, max ) {
	return typeof raw === 'string' && raw.trim() ? raw.trim().slice( 0, max ) : null;
}

/**
 * Reorder series values (and labels) by the requested sort direction.
 * Sort key: the absolute total per index across all series.
 */
function applySort( spec ) {
	if ( spec.sort === 'none' ) {
		return;
	}
	let n = 0;
	spec.series.forEach( ( series ) => {
		if ( series.values.length > n ) {
			n = series.values.length;
		}
	} );
	if ( spec.labels && spec.labels.length > n ) {
		n = spec.labels.length;
	}
	if ( n <= 1 ) {
		return;
	}
	const totals = [];
	for ( let i = 0; i < n; i++ ) {
		let sum = 0;
		spec.series.forEach( ( series ) => {
			const v = series.values[ i ];
			if ( v !== undefined ) {
				sum += Math.abs( v );
			}
		} );
		totals.push( sum );
	}
	const order = Array.from( { length: n }, ( _, i ) => i );
	order.sort( ( a, b ) => ( spec.sort === 'asc' ? totals[ a ] - totals[ b ] : totals[ b ] - totals[ a ] ) );
	spec.series.forEach( ( series ) => {
		series.values = order
			.map( ( i ) => series.values[ i ] )
			.filter( ( v ) => v !== undefined );
	} );
	if ( spec.labels ) {
		spec.labels = order
			.map( ( i ) => spec.labels ? spec.labels[ i ] : undefined )
			.filter( ( l ) => l !== undefined );
	}
}

/**
 * Normalise a raw parsed JSON object into a validated ChartSpec.
 */
function normaliseSpec( obj ) {
	const type = typeof obj.type === 'string' ? obj.type.toLowerCase() : '';
	if ( ! Object.prototype.hasOwnProperty.call( CHART_TYPE_LABELS, type ) ) {
		return null;
	}
	const typed = type;

	const title = toOptionalString( obj.title, MAX_TITLE );
	const caption = toOptionalString( obj.caption, MAX_TITLE );

	let labels = toLabels( obj.labels, type === 'heatmap' ? MAX_HEATMAP_ROWS : MAX_LABELS );

	// Normalise series (values shorthand → single unnamed series).
	const series = [];
	let usableSeries = null;
	if ( Array.isArray( obj.series ) ) {
		usableSeries = obj.series.slice( 0, MAX_SERIES );
	} else if ( typeof obj.data === 'object' && obj.data !== null && Array.isArray( obj.data.items ) ) {
		// `data.items` — alternate input for named rows:
		//   {label, value}    → one unnamed series + labels
		//   {label, values[]} → one named series per item
		const items = obj.data.items;
		const itemSeries = [];
		const itemLabels = [];
		const sharedValues = [];
		for ( let i = 0; i < items.length && itemLabels.length < MAX_LABELS; i++ ) {
			const item = items[ i ];
			if ( ! item || typeof item !== 'object' ) {
				continue;
			}
			const io = item;
			const label = toOptionalString( io.label, MAX_STR );
			if ( Array.isArray( io.values ) ) {
				itemSeries.push( { name: label, values: io.values } );
			} else if ( io.value !== undefined ) {
				itemLabels.push( label || '' );
				sharedValues.push( io.value );
			}
		}
		if ( sharedValues.length ) {
			itemSeries.unshift( { values: sharedValues } );
		}
		if ( itemLabels.length ) {
			labels = itemLabels;
		}
		if ( itemSeries.length ) {
			usableSeries = itemSeries;
		}
	} else if ( obj.values !== undefined ) {
		const values = toFiniteNumbers( obj.values, MAX_POINTS );
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
			const so = s;
			const values = toFiniteNumbers( so.values, MAX_POINTS );
			if ( ! values || values.length === 0 ) {
				continue;
			}
			const name = toOptionalString( so.name, MAX_STR );
			const color = typeof so.color === 'string' && COLOR_RE.test( so.color ) ? so.color : null;
			series.push( { name, values, color } );
		}
	}

	if ( series.length === 0 ) {
		return null;
	}

	// Optional v2 keys.
	let mode = 'grouped';
	if ( obj.mode === 'stacked' || obj.mode === '100' ) {
		mode = obj.mode;
	}
	const direction =
		obj.direction === 'horizontal' || obj.direction === 'vertical' ? obj.direction : null;
	const sort = obj.sort === 'asc' || obj.sort === 'desc' ? obj.sort : 'none';
	const unit = toOptionalString( obj.unit, 20 );
	let animate = false;
	if ( obj.animate === 'race' ) {
		animate = 'race';
	} else if ( obj.animate === true ) {
		animate = true;
	}
	const table = obj.table !== false;
	const target = toOptionalNumber( obj.target );
	const max = toOptionalNumber( obj.max );

	const spec = {
		type: typed,
		title,
		caption,
		labels,
		series,
		mode,
		direction,
		sort,
		unit,
		animate,
		table,
		target,
		max,
		charts: [],
	};

	// Grid: parse sub-charts (recursively; grid-of-grids is not allowed).
	if ( typed === 'grid' ) {
		if ( ! Array.isArray( obj.charts ) ) {
			return null;
		}
		const children = [];
		for ( let i = 0; i < obj.charts.length && children.length < MAX_GRID_CHARTS; i++ ) {
			const child = obj.charts;
			const rawChild = child[ i ];
			if ( ! rawChild || typeof rawChild !== 'object' || Array.isArray( rawChild ) ) {
				continue;
			}
			const childSpec = normaliseSpec( rawChild );
			if ( childSpec && childSpec.type !== 'grid' ) {
				children.push( childSpec );
			}
		}
		if ( children.length === 0 ) {
			return null;
		}
		spec.charts = children;
		return spec;
	}

	// Race: caps + final frame is sorted descending client-side.
	if ( typed === 'race' ) {
		if ( labels ) {
			labels = labels.slice( 0, MAX_RACE_LABELS );
		}
		spec.series.forEach( ( s ) => {
			s.values = s.values.slice( 0, MAX_RACE_LABELS );
		} );
		spec.sort = 'desc';
	}

	// Gantt: each series item must be a [start, end] pair.
	if ( typed === 'gantt' ) {
		const ganttSeries = [];
		for ( const s of spec.series.slice( 0, MAX_GANTT_ITEMS ) ) {
			if ( s.values.length === 2 && s.values[ 1 ] >= s.values[ 0 ] ) {
				ganttSeries.push( s );
			}
		}
		if ( ganttSeries.length === 0 ) {
			return null;
		}
		spec.series = ganttSeries;
	}

	// Paired series types keep exactly two series.
	if ( typed === 'dumbbell' || typed === 'slope' || typed === 'pyramid' ) {
		if ( spec.series.length < 2 ) {
			return null;
		}
		spec.series = spec.series.slice( 0, 2 );
	}

	// Bullet: single series + optional target.
	if ( typed === 'bullet' ) {
		spec.series = spec.series.slice( 0, 1 );
	}

	// Dial: single value + optional max.
	if ( typed === 'dial' ) {
		spec.series = spec.series.slice( 0, 1 );
		spec.series[ 0 ].values = spec.series[ 0 ].values.slice( 0, 1 );
	}

	// Arc types (donut/pie/radial) and unit/waffle: first series only,
	// non-negative, labels aligned to values.
	if ( typed === 'donut' || typed === 'pie' || typed === 'radial' || typed === 'waffle' || typed === 'unit' ) {
		const first = spec.series[ 0 ];
		first.values = first.values.map( ( v ) => ( v < 0 ? 0 : v ) );
		if ( labels ) {
			labels = labels.slice( 0, first.values.length );
		}
		spec.labels = labels;
		if ( first.values.length === 0 ) {
			return null;
		}
		spec.series = [ first ];
	}

	applySort( spec );
	return spec;
}

/**
 * Parse and validate the JSON body of an `nvoos-chart` fence.
 */
export function parseChartSpec( code ) {
	let raw;
	try {
		raw = JSON.parse( code );
	} catch {
		return { ok: false };
	}
	if ( ! raw || typeof raw !== 'object' || Array.isArray( raw ) ) {
		return { ok: false };
	}
	const spec = normaliseSpec( raw );
	return spec ? { ok: true, spec } : { ok: false };
}

/**
 * Format a number for display.
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
 */
function applySeriesColor( node, idx, color ) {
	node.style.setProperty( '--bar-color', seriesColor( idx, color ) );
}

/**
 * Largest absolute value across all series (scaling factor).
 */
function maxAbsValue( spec ) {
	let max = 0;
	spec.series.forEach( ( series ) => {
		series.values.forEach( ( v ) => {
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
 */
function needsLegend( spec ) {
	return spec.series.length > 1 || ( spec.series.length === 1 && !! spec.series[ 0 ].name );
}

/**
 * Build the legend row.
 */
function buildLegend( spec, prefix ) {
	const legend = el( 'div', prefix + '-legend' );
	spec.series.forEach( ( series, idx ) => {
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
 * Build the x-axis label row shared by column/plot charts.
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
 */
function plotSlotCount( spec ) {
	let n = 1;
	spec.series.forEach( ( series ) => {
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
 * Tag a node with an animation delay when the spec requests entry animation.
 * Delay is a computed integer — safe for inline styles.
 */
function maybeAnimate( spec, node, idx ) {
	if ( spec.animate ) {
		node.style.animationDelay = ( idx * 40 ).toFixed( 0 ) + 'ms';
	}
}

/**
 * Per-type data-table columns. `null` = generic (label × series).
 */
function tableColumns( spec ) {
	switch ( spec.type ) {
		case 'candlestick':
			return [ '', 'Open', 'High', 'Low', 'Close' ];
		case 'box':
			return [ '', 'Min', 'Q1', 'Median', 'Q3', 'Max' ];
		case 'gantt':
			return [ 'Task', 'Start', 'End' ];
		case 'race':
			return [ 'Rank', 'Label', 'Value' ];
		case 'waterfall':
			return [ '', 'Value', 'Cumulative' ];
		case 'dumbbell':
			return [ '', 'Start', 'End' ];
		case 'slope':
			return [ '', 'Before', 'After' ];
		case 'bullet':
			return [ '', 'Value', 'Target' ];
		case 'dial':
			return [ 'Value', 'Max' ];
		default:
			return null;
	}
}

/**
 * Build the accessible data table (SC 1.1.1) for a chart. The table is a
 * machine-readable alternative to the graphical encoding. All text goes in
 * through textContent.
 */
function buildDataTable( spec, prefix ) {
	const details = el( 'details', prefix + '-table' );
	const summary = el( 'summary', prefix + '-table-summary', 'Data table' );
	details.appendChild( summary );

	const scroll = el( 'div', prefix + '-table-scroll' );
	const table = el( 'table', prefix + '-table-grid' );
	const thead = el( 'thead', '' );
	const tbody = el( 'tbody', '' );
	const headRow = el( 'tr', '' );

	const cols = tableColumns( spec );
	const labels = spec.labels || [];
	const rows = [];

	if ( spec.type === 'gantt' ) {
		spec.series.forEach( ( s ) => {
			rows.push( [ s.name || '', formatNumber( s.values[ 0 ] ), formatNumber( s.values[ 1 ] ) ] );
		} );
	} else if ( spec.type === 'race' ) {
		const ranked = raceRanked( spec );
		ranked.forEach( ( entry, i ) => {
			rows.push( [ String( i + 1 ), entry.label, formatNumber( entry.total ) ] );
		} );
	} else if ( spec.type === 'waterfall' ) {
		let cum = 0;
		spec.series[ 0 ].values.forEach( ( v, i ) => {
			cum += v;
			rows.push( [ labels[ i ] || '', formatNumber( v ), formatNumber( cum ) ] );
		} );
	} else if ( spec.type === 'dial' ) {
		rows.push( [ formatNumber( spec.series[ 0 ].values[ 0 ] ), formatNumber( spec.max !== null ? spec.max : spec.series[ 0 ].values[ 0 ] ) ] );
	} else if ( spec.type === 'grid' ) {
		spec.charts.forEach( ( child ) => {
			const data = child.series.map( ( s ) => ( s.name ? s.name + ': ' : '' ) + s.values.map( formatNumber ).join( ', ' ) ).join( ' | ' );
			rows.push( [ child.title || CHART_TYPE_LABELS[ child.type ], data ] );
		} );
	} else if ( spec.type === 'box' ) {
		spec.series.forEach( ( s, _sIdx ) => {
			for ( let i = 0; i + 4 < s.values.length; i += 5 ) {
				rows.push( [
					s.name || labels[ i / 5 ] || '',
					formatNumber( s.values[ i ] ),
					formatNumber( s.values[ i + 1 ] ),
					formatNumber( s.values[ i + 2 ] ),
					formatNumber( s.values[ i + 3 ] ),
					formatNumber( s.values[ i + 4 ] ),
				] );
			}
		} );
	} else if ( spec.type === 'candlestick' ) {
		spec.series[ 0 ].values.forEach( ( _v, i ) => {
			const idx = i * 4;
			rows.push( [
				labels[ i ] || '',
				formatNumber( spec.series[ 0 ].values[ idx ] ),
				formatNumber( spec.series[ 0 ].values[ idx + 1 ] ),
				formatNumber( spec.series[ 0 ].values[ idx + 2 ] ),
				formatNumber( spec.series[ 0 ].values[ idx + 3 ] ),
			] );
		} );
	} else {
		// Generic: label × series.
		const n = Math.max( plotSlotCount( spec ), labels.length );
		for ( let i = 0; i < n; i++ ) {
			const row = [ labels[ i ] || '' ];
			spec.series.forEach( ( s ) => {
				const v = s.values[ i ];
				row.push( v === undefined ? '' : formatNumber( v ) );
			} );
			rows.push( row );
		}
	}

	if ( cols ) {
		cols.forEach( ( c ) => headRow.appendChild( el( 'th', '', c ) ) );
	} else if ( spec.type !== 'grid' ) {
		headRow.appendChild( el( 'th', '', '' ) );
		spec.series.forEach( ( s ) => {
			headRow.appendChild( el( 'th', '', s.name || 'Value' ) );
		} );
	} else {
		headRow.appendChild( el( 'th', '', 'Chart' ) );
		headRow.appendChild( el( 'th', '', 'Data' ) );
	}
	thead.appendChild( headRow );
	rows.forEach( ( row ) => {
		const tr = el( 'tr', '' );
		row.forEach( ( cell ) => tr.appendChild( el( 'td', '', cell ) ) );
		tbody.appendChild( tr );
	} );
	table.appendChild( thead );
	table.appendChild( tbody );
	scroll.appendChild( table );
	details.appendChild( scroll );
	return details;
}

/**
 * Ranked entries for the race final frame (label + total, desc).
 */
function raceRanked( spec ) {
	const labels = spec.labels || [];
	const out = [];
	for ( let i = 0; i < labels.length; i++ ) {
		let total = 0;
		spec.series.forEach( ( s ) => {
			const v = s.values[ i ];
			if ( v !== undefined ) {
				total += v;
			}
		} );
		out.push( { label: labels[ i ], total } );
	}
	out.sort( ( a, b ) => b.total - a.total );
	return out;
}

/**
 * Build a horizontal bar body. Handles grouped / stacked / 100 modes and
 * the v1 center-baseline behaviour for negative values.
 */
function buildBarBody( spec, prefix ) {
	const centerBaseline = spec.series.some( ( series ) =>
		series.values.some( ( v ) => v < 0 )
	);
	const body = el(
		'div',
		prefix + '-body ' +
		( centerBaseline ? prefix + '-body--bar-center ' : '' ) +
		prefix + '-body--bar' +
		' ' + prefix + '-body--' + spec.mode +
		' ' + prefix + '-body--horizontal'
	);
	const maxAbs = maxAbsValue( spec );
	// Mixed-sign bars scale each side against its own maximum so the
	// longest bar fills half the track on both sides of the baseline.
	let maxPos = 0;
	let maxNeg = 0;
	if ( centerBaseline ) {
		spec.series.forEach( ( series ) => {
			series.values.forEach( ( v ) => {
				if ( v > 0 ) {
					maxPos = Math.max( maxPos, v );
				} else if ( v < 0 ) {
					maxNeg = Math.max( maxNeg, -v );
				}
			} );
		} );
	}
	const n = plotSlotCount( spec );

	if ( spec.mode === 'stacked' || spec.mode === '100' ) {
		for ( let i = 0; i < n; i++ ) {
			const label = ( spec.labels && spec.labels[ i ] ) || '';
			const row = el( 'div', prefix + '-row ' + prefix + '-row--first' );
			const labelCell = el( 'div', prefix + '-row-label' );
			labelCell.textContent = label;
			row.appendChild( labelCell );
			const track = el( 'div', prefix + '-track' );
			const present = spec.series.map( ( s ) => ( s.values[ i ] === undefined ? 0 : Math.max( 0, s.values[ i ] ) ) );
			const total = present.reduce( ( sum, v ) => sum + v, 0 );
			let acc = 0;
			spec.series.forEach( ( series, sIdx ) => {
				const v = present[ sIdx ];
				if ( total <= 0 || v <= 0 ) {
					return;
				}
				const frac = v / total;
				const seg = el( 'div', prefix + '-stack-seg' );
				seg.style.left = ( acc / total * 100 ).toFixed( 3 ) + '%';
				seg.style.width = ( frac * 100 ).toFixed( 3 ) + '%';
				applySeriesColor( seg, sIdx, series.color );
				maybeAnimate( spec, seg, i * spec.series.length + sIdx );
				track.appendChild( seg );
				acc += v;
			} );
			const value = el( 'span', prefix + '-bar-value', total ? formatNumber( total ) : '0' );
			value.style.left = Math.min( 96, Math.max( 2, 100 ) ).toFixed( 3 ) + '%';
			value.style.transform = 'translateX(6px)';
			track.appendChild( value );
			row.appendChild( track );
			body.appendChild( row );
		}
		return body;
	}

	for ( let i = 0; i < n; i++ ) {
		const label = ( spec.labels && spec.labels[ i ] ) || '';
		spec.series.forEach( ( series, sIdx ) => {
			const v = series.values[ i ];
			if ( v === undefined ) {
				return;
			}
			const row = el(
				'div',
				prefix + '-row' + ( sIdx === 0 ? ' ' + prefix + '-row--first' : ' ' + prefix + '-row--continuation' )
			);
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
			if ( centerBaseline ) {
				const sideMax = v < 0 ? maxNeg : maxPos;
				const sideFrac = sideMax > 0 ? Math.abs( v ) / sideMax : 0;
				bar.style.left = ( v < 0 ? 50 - sideFrac * 50 : 50 ).toFixed( 3 ) + '%';
				bar.style.width = ( sideFrac * 50 ).toFixed( 3 ) + '%';
			} else {
				bar.style.left = '0%';
				bar.style.width = ( Math.max( frac, 0 ) * 100 ).toFixed( 3 ) + '%';
			}
			applySeriesColor( bar, sIdx, series.color );
			maybeAnimate( spec, bar, i * spec.series.length + sIdx );
			track.appendChild( bar );

			const value = el( 'span', prefix + '-bar-value', formatNumber( v ) );
			const labelPos = centerBaseline
				? Math.min( Math.max( ( v < 0 ? 50 - Math.abs( v ) / ( maxNeg || 1 ) * 50 : 50 + Math.abs( v ) / ( maxPos || 1 ) * 50 ), 4 ), 96 )
				: Math.min( Math.max( frac * 100, 2 ), 96 );
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
 * Build a vertical column body. Handles grouped / stacked / 100 modes.
 * Grouped bars get a real inter-series gap (v2 fix: v1 bars touched).
 */
function buildColumnBody( spec, prefix ) {
	const body = el(
		'div',
		prefix + '-body ' + prefix + '-body--column' +
		' ' + prefix + '-body--' + spec.mode +
		' ' + prefix + '-body--vertical'
	);
	const maxAbs = maxAbsValue( spec );
	const n = plotSlotCount( spec );
	const plot = el( 'div', prefix + '-col-plot' );
	const k = spec.series.length;
	const widthPct = k === 1 ? 40 : Math.min( 60 / k, 36 );
	const stepPct = k === 1 ? 40 : Math.min( 70 / k, 40 );

	if ( spec.mode === 'stacked' || spec.mode === '100' ) {
		let maxTotal = 0;
		for ( let i = 0; i < n; i++ ) {
			let total = 0;
			spec.series.forEach( ( s ) => {
				const v = s.values[ i ];
				if ( v !== undefined ) {
					total += Math.max( 0, v );
				}
			} );
			if ( total > maxTotal ) {
				maxTotal = total;
			}
		}
		for ( let i = 0; i < n; i++ ) {
			const center = ( ( i + 0.5 ) / n * 100 ).toFixed( 3 );
			let total = 0;
			spec.series.forEach( ( s ) => {
				const v = s.values[ i ];
				if ( v !== undefined ) {
					total += Math.max( 0, v );
				}
			} );
			let acc = 0;
			spec.series.forEach( ( series, sIdx ) => {
				const raw = series.values[ i ];
				const v = raw === undefined ? 0 : Math.max( 0, raw );
				if ( total <= 0 || v <= 0 ) {
					return;
				}
				const heightPct = spec.mode === '100'
					? ( v / total ) * 100
					: maxTotal > 0 ? ( v / maxTotal ) * 100 : 0;
				const bar = el( 'div', prefix + '-col-bar' );
				bar.style.left = center + '%';
				bar.style.marginLeft = ( -widthPct / 2 ).toFixed( 3 ) + '%';
				bar.style.width = widthPct.toFixed( 3 ) + '%';
				bar.style.bottom = ( 50 - ( acc / ( spec.mode === '100' ? total : maxTotal ) ) * ( spec.mode === '100' ? 100 : 50 ) ).toFixed( 3 ) + '%';
				bar.style.height = heightPct.toFixed( 3 ) + '%';
				applySeriesColor( bar, sIdx, series.color );
				maybeAnimate( spec, bar, i * k + sIdx );
				plot.appendChild( bar );
				if ( heightPct >= 4 ) {
					const value = el( 'span', prefix + '-col-value', formatNumber( raw ) );
					value.style.left = center + '%';
					value.style.width = widthPct.toFixed( 3 ) + '%';
					value.style.bottom = ( 50 - ( acc / ( spec.mode === '100' ? total : maxTotal ) ) * ( spec.mode === '100' ? 100 : 50 ) + 1 ).toFixed( 3 ) + '%';
					plot.appendChild( value );
				}
				acc += v;
			} );
		}
		body.appendChild( plot );
		body.appendChild( buildXAxis( spec, prefix, n ) );
		return body;
	}

	for ( let i = 0; i < n; i++ ) {
		const center = ( ( i + 0.5 ) / n * 100 ).toFixed( 3 );
		spec.series.forEach( ( series, sIdx ) => {
			const v = series.values[ i ];
			if ( v === undefined ) {
				return;
			}
			const frac = maxAbs > 0 ? v / maxAbs : 0;
			const offset = ( ( sIdx - ( k - 1 ) / 2 ) * stepPct ).toFixed( 3 );
			const bar = el( 'div', prefix + '-col-bar' + ( v < 0 ? ' ' + prefix + '-col-bar--negative' : '' ) );
			bar.style.left = center + '%';
			bar.style.marginLeft = offset + '%';
			bar.style.width = widthPct.toFixed( 3 ) + '%';
			applySeriesColor( bar, sIdx, series.color );
			maybeAnimate( spec, bar, i * k + sIdx );
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
			value.style.width = stepPct.toFixed( 3 ) + '%';
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
 * Build a plot body for line, area, dot and sparkline charts.
 */
function buildPlotBody( spec, prefix, mode ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--' + mode );
	const maxAbs = maxAbsValue( spec );
	const n = plotSlotCount( spec );
	const plot = el( 'div', prefix + '-plot' + ( mode === 'sparkline' ? ' ' + prefix + '-spark' : '' ) );

	spec.series.forEach( ( series, sIdx ) => {
		const layer = el( 'div', prefix + '-plot-series' );
		const values = series.values;
		let prevXNum = 0;
		let prevYNum = 0;
		for ( let i = 0; i < values.length; i++ ) {
			const v = values[ i ];
			const xNum = n > 1 ? ( i / ( n - 1 ) ) * 100 : 50;
			const x = xNum.toFixed( 3 );
			let yNum = 50 - ( maxAbs > 0 ? v / maxAbs : 0 ) * 48;
			yNum = Math.min( Math.max( yNum, 2 ), 98 );
			const y = yNum.toFixed( 3 );

			if ( mode === 'area' ) {
				const fill = el( 'span', prefix + '-plot-fill' );
				fill.style.left = x + '%';
				fill.style.top = Math.min( yNum, 50 ).toFixed( 3 ) + '%';
				fill.style.height = Math.abs( yNum - 50 ).toFixed( 3 ) + '%';
				applySeriesColor( fill, sIdx, series.color );
				layer.appendChild( fill );
			}

			if ( i > 0 && mode !== 'dot' ) {
				const dx = xNum - prevXNum;
				const dy = ( yNum - prevYNum ) / PLOT_RATIO;
				const length = Math.sqrt( dx * dx + dy * dy );
				if ( length > 0.01 ) {
					const angle = ( Math.atan2( dy, dx ) * 180 ) / Math.PI;
					const seg = el( 'span', prefix + '-plot-seg' );
					seg.style.left = prevXNum.toFixed( 3 ) + '%';
					seg.style.top = prevYNum.toFixed( 3 ) + '%';
					seg.style.width = length.toFixed( 3 ) + '%';
					seg.style.transform = 'rotate(' + angle.toFixed( 3 ) + 'deg)';
					applySeriesColor( seg, sIdx, series.color );
					maybeAnimate( spec, seg, i * spec.series.length + sIdx );
					layer.appendChild( seg );
				}
			}
			prevXNum = xNum;
			prevYNum = yNum;

			const dot = el( 'span', prefix + '-plot-dot' );
			dot.style.left = x + '%';
			dot.style.top = y + '%';
			applySeriesColor( dot, sIdx, series.color );
			maybeAnimate( spec, dot, i * spec.series.length + sIdx );
			dot.appendChild( el( 'span', prefix + '-plot-dot-value', formatNumber( v ) ) );
			layer.appendChild( dot );
		}
		plot.appendChild( layer );
	} );

	body.appendChild( plot );
	if ( mode !== 'sparkline' ) {
		body.appendChild( buildXAxis( spec, prefix, n ) );
	}
	return body;
}

/**
 * Build an arc body: donut (ring + hole), pie (full circle) or radial
 * (ring with gaps between segments).
 */
function buildArcBody( spec, prefix, kind ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--' + kind );
	const series = spec.series[ 0 ];
	const total = series.values.reduce( ( sum, v ) => sum + v, 0 );
	const labels = spec.labels || [];

	const wrap = el( 'div', prefix + '-' + kind );
	const ring = el( 'div', prefix + '-' + kind + '-ring' );
	if ( total > 0 ) {
		const stops = [];
		let acc = 0;
		series.values.forEach( ( v, idx ) => {
			const start = ( acc / total ) * 100;
			acc += v;
			const end = ( acc / total ) * 100;
			stops.push( seriesColor( idx, null ) + ' ' + start.toFixed( 3 ) + '% ' + end.toFixed( 3 ) + '%' );
			if ( kind === 'radial' && idx < series.values.length - 1 ) {
				// Transparent gap between radial segments.
				const gapEnd = ( acc / total ) * 100 + 2;
				stops.push( 'transparent ' + end.toFixed( 3 ) + '% ' + Math.min( gapEnd, 100 ).toFixed( 3 ) + '%' );
			}
		} );
		ring.style.background = 'conic-gradient(' + stops.join( ', ' ) + ')';
	} else {
		ring.style.background = 'var(--chart-border)';
	}
	if ( kind !== 'pie' ) {
		const hole = el( 'div', prefix + '-donut-hole' );
		hole.appendChild( el( 'div', prefix + '-donut-total', formatNumber( total ) ) );
		ring.appendChild( hole );
	}
	wrap.appendChild( ring );

	const legend = el( 'div', prefix + '-donut-legend' );
	series.values.forEach( ( v, idx ) => {
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
	spec.series.forEach( ( series ) => {
		grid.appendChild( el( 'div', prefix + '-heat-col-label', series.name || '' ) );
	} );

	for ( let r = 0; r < rows; r++ ) {
		grid.appendChild( el( 'div', prefix + '-heat-row-label', labels[ r ] || '' ) );
		for ( let c = 0; c < cols; c++ ) {
			const raw = spec.series[ c ].values[ r ];
			const v = typeof raw === 'number' ? raw : 0;
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
 * Histogram: single series as full-width column bars.
 */
function buildHistogramBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--histogram' );
	const maxAbs = maxAbsValue( spec );
	const values = spec.series[ 0 ].values;
	const n = values.length;
	const plot = el( 'div', prefix + '-col-plot' );
	const widthPct = Math.min( 88 / n, 96 );

	values.forEach( ( v, i ) => {
		const frac = maxAbs > 0 ? v / maxAbs : 0;
		const bar = el( 'div', prefix + '-col-bar' );
		bar.style.left = ( ( i + 0.5 ) / n * 100 ).toFixed( 3 ) + '%';
		bar.style.marginLeft = ( -widthPct / 2 ).toFixed( 3 ) + '%';
		bar.style.width = widthPct.toFixed( 3 ) + '%';
		bar.style.bottom = '50%';
		bar.style.height = ( Math.max( frac, 0 ) * 50 ).toFixed( 3 ) + '%';
		applySeriesColor( bar, 0, spec.series[ 0 ].color );
		maybeAnimate( spec, bar, i );
		plot.appendChild( bar );
	} );
	body.appendChild( plot );
	body.appendChild( buildXAxis( spec, prefix, n ) );
	return body;
}

/**
 * Box plot: each label consumes five values (min, q1, median, q3, max).
 */
function buildBoxBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--box' );
	const maxAbs = maxAbsValue( spec );
	const labels = spec.labels || [];

	spec.series.forEach( ( series, sIdx ) => {
		for ( let i = 0; i + 4 < series.values.length; i += 5 ) {
			const min = series.values[ i ];
			const q1 = series.values[ i + 1 ];
			const med = series.values[ i + 2 ];
			const q3 = series.values[ i + 3 ];
			const max = series.values[ i + 4 ];
			const row = el( 'div', prefix + '-box-item' );
			row.appendChild( el( 'div', prefix + '-row-label', sIdx === 0 ? labels[ i / 5 ] || '' : series.name || '' ) );
			const track = el( 'div', prefix + '-track' );
			const toPct = ( v ) => ( 50 - ( maxAbs > 0 ? v / maxAbs : 0 ) * 50 ).toFixed( 3 ) + '%';
			const wick = el( 'div', prefix + '-box-wick' );
			wick.style.top = toPct( max );
			wick.style.height = ( maxAbs > 0 ? ( ( max - min ) / maxAbs ) * 50 : 0 ).toFixed( 3 ) + '%';
			applySeriesColor( wick, sIdx, series.color );
			track.appendChild( wick );
			const box = el( 'div', prefix + '-box-body' );
			box.style.top = toPct( q3 );
			box.style.height = ( maxAbs > 0 ? ( ( q3 - q1 ) / maxAbs ) * 50 : 0 ).toFixed( 3 ) + '%';
			applySeriesColor( box, sIdx, series.color );
			track.appendChild( box );
			const median = el( 'div', prefix + '-box-median' );
			median.style.top = toPct( med );
			track.appendChild( median );
			track.appendChild( el( 'span', prefix + '-box-min', formatNumber( min ) ) );
			track.appendChild( el( 'span', prefix + '-box-max', formatNumber( max ) ) );
			row.appendChild( track );
			body.appendChild( row );
		}
	} );
	return body;
}

/**
 * Strip plot: dots along a value axis per label.
 */
function buildStripBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--strip' );
	const maxAbs = maxAbsValue( spec );
	const labels = spec.labels || [];

	spec.series.forEach( ( series, sIdx ) => {
		for ( let i = 0; i < series.values.length; i++ ) {
			const v = series.values[ i ];
			const row = el( 'div', prefix + '-strip-item' );
			row.appendChild( el( 'div', prefix + '-row-label', sIdx === 0 ? labels[ i ] || '' : series.name || '' ) );
			const track = el( 'div', prefix + '-track' );
			const dot = el( 'span', prefix + '-strip-dot' );
			const pos = 50 + ( maxAbs > 0 ? v / maxAbs : 0 ) * 50;
			dot.style.left = Math.min( Math.max( pos, 2 ), 98 ).toFixed( 3 ) + '%';
			applySeriesColor( dot, sIdx, series.color );
			maybeAnimate( spec, dot, i );
			dot.appendChild( el( 'span', prefix + '-plot-dot-value', formatNumber( v ) ) );
			track.appendChild( dot );
			row.appendChild( track );
			body.appendChild( row );
		}
	} );
	return body;
}

/**
 * Stem plot: vertical stems with value dots (lollipop-style, no bars).
 */
function buildStemBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--stem' );
	const maxAbs = maxAbsValue( spec );
	const n = plotSlotCount( spec );
	const plot = el( 'div', prefix + '-col-plot' );

	for ( let i = 0; i < n; i++ ) {
		const center = ( ( i + 0.5 ) / n * 100 ).toFixed( 3 );
		spec.series.forEach( ( series, sIdx ) => {
			const v = series.values[ i ];
			if ( v === undefined ) {
				return;
			}
			const frac = maxAbs > 0 ? v / maxAbs : 0;
			const stem = el( 'div', prefix + '-stem-bar' );
			stem.style.left = center + '%';
			if ( v >= 0 ) {
				stem.style.bottom = '50%';
				stem.style.height = ( frac * 50 ).toFixed( 3 ) + '%';
			} else {
				stem.style.top = '50%';
				stem.style.height = ( -frac * 50 ).toFixed( 3 ) + '%';
			}
			applySeriesColor( stem, sIdx, series.color );
			maybeAnimate( spec, stem, i * spec.series.length + sIdx );
			plot.appendChild( stem );
			const dot = el( 'span', prefix + '-stem-dot' );
			dot.style.left = center + '%';
			const top = 50 - frac * 50;
			dot.style.top = Math.min( Math.max( top, 2 ), 98 ).toFixed( 3 ) + '%';
			applySeriesColor( dot, sIdx, series.color );
			dot.appendChild( el( 'span', prefix + '-plot-dot-value', formatNumber( v ) ) );
			plot.appendChild( dot );
		} );
	}
	body.appendChild( plot );
	body.appendChild( buildXAxis( spec, prefix, n ) );
	return body;
}

/**
 * Violin plot — CSS-only approximation: tapered shape per label spanning
 * the sample range with a median dot.
 */
function buildViolinBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--violin' );
	const labels = spec.labels || [];

	// Distribution per series, clipped to the per-series cap.
	const distributions = [];
	spec.series.forEach( ( series, sIdx ) => {
		const values = [];
		for ( let i = 0; i < series.values.length && values.length < 100; i++ ) {
			if ( series.values[ i ] !== undefined ) {
				values.push( series.values[ i ] );
			}
		}
		if ( values.length ) {
			distributions.push( { name: series.name || labels[ sIdx ] || '', color: series.color, values } );
		}
	} );

	distributions.forEach( ( dist, sIdx ) => {
		const values = dist.values.slice().sort( ( a, b ) => a - b );
		const n = values.length;
		const lo = values[ 0 ];
		const hi = values[ n - 1 ];

		// Silverman bandwidth, robust for small samples.
		let mean = 0;
		values.forEach( ( v ) => {
			mean += v;
		} );
		mean /= n;
		let variance = 0;
		values.forEach( ( v ) => {
			variance += ( v - mean ) * ( v - mean );
		} );
		const sd = n > 1 ? Math.sqrt( variance / ( n - 1 ) ) : 0;
		const bw = Math.max(
			1.06 * ( sd > 0 ? sd : 0 ) * Math.pow( n, -0.2 ),
			hi > lo ? ( hi - lo ) * 0.03 : 0,
			1e-9
		);

		// Density at GRID points across [lo, hi].
		const GRID = 24;
		const densities = [];
		let maxD = 0;
		for ( let g = 0; g < GRID; g++ ) {
			const x = lo + ( ( hi - lo ) * g ) / ( GRID - 1 );
			let d = 0;
			values.forEach( ( v ) => {
				const u = ( x - v ) / bw;
				d += Math.exp( -0.5 * u * u );
			} );
			densities.push( d );
			if ( d > maxD ) {
				maxD = d;
			}
		}

		const row = el( 'div', prefix + '-violin-item' );
		row.appendChild( el( 'div', prefix + '-row-label', dist.name ) );
		const track = el( 'div', prefix + '-track' );
		track.classList.add( prefix + '-track--center' );
		const shape = el( 'div', prefix + '-violin-shape' );
		applySeriesColor( shape, sIdx, dist.color );

		// Mirror each grid slice as a symmetric pair of half-slices.
		for ( let g = 0; g < GRID; g++ ) {
			const f = maxD > 0 ? Math.max( densities[ g ] / maxD, 0.04 ) : 0.5;
			const y0 = ( g / GRID ) * 100;
			const h = 100 / GRID;
			const x = lo + ( ( hi - lo ) * g ) / ( GRID - 1 );
			const pos = 50 + ( hi > lo ? ( ( x - lo ) / ( hi - lo ) - 0.5 ) * 100 : 0 );
			[ -1, 1 ].forEach( ( side ) => {
				const half = el( 'div', prefix + '-violin-half' );
				half.style.height = h.toFixed( 3 ) + '%';
				half.style.top = y0.toFixed( 3 ) + '%';
				half.style.left = ( side < 0 ? pos - f * 50 : pos ).toFixed( 3 ) + '%';
				half.style.width = ( f * 50 ).toFixed( 3 ) + '%';
				shape.appendChild( half );
			} );
		}
		track.appendChild( shape );

		const median = el( 'span', prefix + '-violin-dot' );
		const med = n % 2 ? values[ ( n - 1 ) / 2 ] : ( values[ n / 2 - 1 ] + values[ n / 2 ] ) / 2;
		median.style.left = ( hi > lo ? 50 + ( ( med - lo ) / ( hi - lo ) - 0.5 ) * 100 : 50 ).toFixed( 3 ) + '%';
		median.appendChild( el( 'span', prefix + '-plot-dot-value', formatNumber( med ) ) );
		track.appendChild( median );
		track.appendChild( el( 'span', prefix + '-box-min', formatNumber( lo ) ) );
		track.appendChild( el( 'span', prefix + '-box-max', formatNumber( hi ) ) );
		row.appendChild( track );
		body.appendChild( row );
	} );
	return body;
}

/**
 * Waffle chart: grid of unit cells coloured by segment.
 */
function buildWaffleBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--waffle' );
	const values = spec.series[ 0 ].values;
	const total = values.reduce( ( sum, v ) => sum + v, 0 );
	const grid = el( 'div', prefix + '-waffle' );
	if ( total <= 0 ) {
		body.appendChild( grid );
		return body;
	}
	const scale = Math.min( 1, MAX_WAFFLE_CELLS / total );
	const counts = values.map( ( v ) => Math.round( v * scale ) );
	let idx = 0;
	counts.forEach( ( count, segIdx ) => {
		for ( let c = 0; c < count && idx < MAX_WAFFLE_CELLS; c++ ) {
			const cell = el( 'span', prefix + '-waffle-cell' );
			applySeriesColor( cell, segIdx, null );
			maybeAnimate( spec, cell, idx );
			grid.appendChild( cell );
			idx++;
		}
	} );
	body.appendChild( grid );
	return body;
}

/**
 * Unit chart: rows of unit cells with real values in labels.
 */
function buildUnitBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--unit' );
	const maxAbs = maxAbsValue( spec );
	const labels = spec.labels || [];
	const unitName = spec.unit || '';

	spec.series.forEach( ( series, sIdx ) => {
		series.values.forEach( ( v, i ) => {
			const row = el( 'div', prefix + '-unit-item' );
			row.appendChild( el( 'div', prefix + '-row-label', sIdx === 0 ? labels[ i ] || '' : series.name || '' ) );
			const track = el( 'div', prefix + '-track' );
			const count = v <= 0 || maxAbs <= 0 ? 0 : Math.max( 1, Math.round( ( v / maxAbs ) * MAX_UNIT_CELLS ) );
			for ( let c = 0; c < count; c++ ) {
				const cell = el( 'span', prefix + '-unit-cell' );
				applySeriesColor( cell, sIdx, series.color );
				maybeAnimate( spec, cell, i * MAX_UNIT_CELLS + c );
				track.appendChild( cell );
			}
			track.appendChild( el( 'span', prefix + '-bar-value', formatNumber( v ) + ( unitName ? ' ' + unitName : '' ) ) );
			row.appendChild( track );
			body.appendChild( row );
		} );
	} );
	return body;
}

/**
 * Diverging bar chart: one mixed-sign series (center baseline) or two series
 * (first diverges left, second right).
 */
function buildDivergingBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--diverging' );
	const maxAbs = maxAbsValue( spec );
	const labels = spec.labels || [];
	const n = plotSlotCount( spec );

	for ( let i = 0; i < n; i++ ) {
		const row = el( 'div', prefix + '-row ' + prefix + '-row--first' );
		const labelCell = el( 'div', prefix + '-row-label' );
		labelCell.textContent = labels[ i ] || '';
		row.appendChild( labelCell );
		const track = el( 'div', prefix + '-track ' + prefix + '-track--center' );

		const addBar = ( v, sIdx ) => {
			if ( v === undefined || v === 0 ) {
				return;
			}
			const frac = maxAbs > 0 ? v / maxAbs : 0;
			const bar = el( 'div', prefix + '-bar' + ( v < 0 ? ' ' + prefix + '-bar--negative' : '' ) );
			bar.style.left = ( 50 + Math.min( frac, 0 ) * 50 ).toFixed( 3 ) + '%';
			bar.style.width = ( Math.abs( frac ) * 50 ).toFixed( 3 ) + '%';
			applySeriesColor( bar, sIdx, spec.series[ sIdx ].color );
			maybeAnimate( spec, bar, i * spec.series.length + sIdx );
			track.appendChild( bar );
			const value = el( 'span', prefix + '-bar-value', formatNumber( v ) );
			value.style.left = Math.min( Math.max( 50 + frac * 50, 4 ), 96 ).toFixed( 3 ) + '%';
			value.style.transform = v < 0 ? 'translateX(calc(-100% - 6px))' : 'translateX(6px)';
			track.appendChild( value );
		};

		if ( spec.series.length === 2 ) {
			const left = spec.series[ 0 ].values[ i ];
			const right = spec.series[ 1 ].values[ i ];
			addBar( left === undefined ? 0 : -Math.abs( left ), 0 );
			addBar( right === undefined ? 0 : Math.abs( right ), 1 );
		} else {
			addBar( spec.series[ 0 ].values[ i ], 0 );
		}
		row.appendChild( track );
		body.appendChild( row );
	}
	return body;
}

/**
 * Lollipop chart: vertical stems with dots and values.
 */
function buildLollipopBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--lollipop' );
	const maxAbs = maxAbsValue( spec );
	const n = plotSlotCount( spec );
	const plot = el( 'div', prefix + '-col-plot' );
	const k = spec.series.length;
	const stepPct = Math.min( 70 / k, 40 );

	for ( let i = 0; i < n; i++ ) {
		const center = ( ( i + 0.5 ) / n * 100 ).toFixed( 3 );
		spec.series.forEach( ( series, sIdx ) => {
			const v = series.values[ i ];
			if ( v === undefined ) {
				return;
			}
			const frac = maxAbs > 0 ? v / maxAbs : 0;
			const offset = ( ( sIdx - ( k - 1 ) / 2 ) * stepPct ).toFixed( 3 );
			const stem = el( 'div', prefix + '-lolli-stem' );
			stem.style.left = center + '%';
			stem.style.marginLeft = offset + '%';
			if ( v >= 0 ) {
				stem.style.bottom = '50%';
				stem.style.height = ( frac * 50 ).toFixed( 3 ) + '%';
			} else {
				stem.style.top = '50%';
				stem.style.height = ( -frac * 50 ).toFixed( 3 ) + '%';
			}
			applySeriesColor( stem, sIdx, series.color );
			maybeAnimate( spec, stem, i * k + sIdx );
			plot.appendChild( stem );
			const dot = el( 'span', prefix + '-lolli-dot' );
			dot.style.left = center + '%';
			dot.style.marginLeft = offset + '%';
			const top = 50 - frac * 50;
			dot.style.top = Math.min( Math.max( top, 2 ), 98 ).toFixed( 3 ) + '%';
			applySeriesColor( dot, sIdx, series.color );
			plot.appendChild( dot );
			const value = el( 'span', prefix + '-col-value', formatNumber( v ) );
			value.style.left = center + '%';
			value.style.marginLeft = offset + '%';
			value.style.width = stepPct.toFixed( 3 ) + '%';
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
 * Dumbbell chart: two series (start/end) connected per label.
 */
function buildDumbbellBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--dumbbell' );
	const maxAbs = maxAbsValue( spec );
	const labels = spec.labels || [];
	const a = spec.series[ 0 ];
	const b = spec.series[ 1 ];
	const n = plotSlotCount( spec );

	for ( let i = 0; i < n; i++ ) {
		const va = a.values[ i ];
		const vb = b.values[ i ];
		if ( va === undefined || vb === undefined ) {
			continue;
		}
		const row = el( 'div', prefix + '-dumb-item' );
		row.appendChild( el( 'div', prefix + '-row-label', labels[ i ] || '' ) );
		const track = el( 'div', prefix + '-track' );
		const x1 = 50 + ( maxAbs > 0 ? va / maxAbs : 0 ) * 50;
		const x2 = 50 + ( maxAbs > 0 ? vb / maxAbs : 0 ) * 50;
		const line = el( 'div', prefix + '-dumb-line' );
		line.style.left = Math.min( x1, x2 ).toFixed( 3 ) + '%';
		line.style.width = Math.abs( x2 - x1 ).toFixed( 3 ) + '%';
		track.appendChild( line );
		[ [ x1, 0, a.color ], [ x2, 1, b.color ] ].forEach( ( pair ) => {
			const dot = el( 'span', prefix + '-dumb-dot' );
			dot.style.left = pair[ 0 ].toFixed( 3 ) + '%';
			applySeriesColor( dot, pair[ 1 ], pair[ 2 ] );
			maybeAnimate( spec, dot, i * 2 + pair[ 1 ] );
			track.appendChild( dot );
		} );
		const endLabel = el( 'span', prefix + '-bar-value', formatNumber( va ) + ' → ' + formatNumber( vb ) );
		endLabel.style.left = Math.min( x2 + 1, 95 ).toFixed( 3 ) + '%';
		track.appendChild( endLabel );
		row.appendChild( track );
		body.appendChild( row );
	}
	return body;
}

/**
 * Slope chart: two series (before/after) with a rotated connector.
 */
function buildSlopeBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--slope' );
	const maxAbs = maxAbsValue( spec );
	const n = plotSlotCount( spec );
	const plot = el( 'div', prefix + '-col-plot' );
	const widthPct = Math.min( 60 / 2, 36 );
	const stepPct = Math.min( 70 / 2, 40 );

	for ( let i = 0; i < n; i++ ) {
		const center = ( ( i + 0.5 ) / n * 100 );
		const positions = [];
		spec.series.forEach( ( series, sIdx ) => {
			const v = series.values[ i ];
			if ( v === undefined ) {
				return;
			}
			const frac = maxAbs > 0 ? v / maxAbs : 0;
			const offset = ( sIdx - 0.5 ) * stepPct;
			const x = center + offset;
			const y = 50 - frac * 50;
			positions.push( { x, y } );
			const bar = el( 'div', prefix + '-col-bar' );
			bar.style.left = x.toFixed( 3 ) + '%';
			bar.style.marginLeft = ( -widthPct / 2 ).toFixed( 3 ) + '%';
			bar.style.width = widthPct.toFixed( 3 ) + '%';
			bar.style.bottom = '50%';
			bar.style.height = ( Math.max( frac, 0 ) * 50 ).toFixed( 3 ) + '%';
			applySeriesColor( bar, sIdx, series.color );
			maybeAnimate( spec, bar, i * 2 + sIdx );
			plot.appendChild( bar );
		} );
		if ( positions.length === 2 ) {
			const dx = positions[ 1 ].x - positions[ 0 ].x;
			const dy = ( positions[ 1 ].y - positions[ 0 ].y ) / PLOT_RATIO;
			const length = Math.sqrt( dx * dx + dy * dy );
			if ( length > 0.01 ) {
				const angle = ( Math.atan2( dy, dx ) * 180 ) / Math.PI;
				const seg = el( 'span', prefix + '-slope-line' );
				seg.style.left = positions[ 0 ].x.toFixed( 3 ) + '%';
				seg.style.top = positions[ 0 ].y.toFixed( 3 ) + '%';
				seg.style.width = length.toFixed( 3 ) + '%';
				seg.style.transform = 'rotate(' + angle.toFixed( 3 ) + 'deg)';
				plot.appendChild( seg );
			}
		}
	}
	body.appendChild( plot );
	body.appendChild( buildXAxis( spec, prefix, n ) );
	return body;
}

/**
 * Bullet chart: value bar against an optional maximum with a target marker.
 */
function buildBulletBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--bullet' );
	const labels = spec.labels || [];
	const series = spec.series[ 0 ];
	const maxAbs = spec.max !== null && spec.max > 0 ? spec.max : maxAbsValue( spec );
	const target = spec.target;

	series.values.forEach( ( v, i ) => {
		const row = el( 'div', prefix + '-bullet-item' );
		row.appendChild( el( 'div', prefix + '-row-label', labels[ i ] || '' ) );
		const track = el( 'div', prefix + '-track ' + prefix + '-track--center' );
		const bar = el( 'div', prefix + '-bullet-bar' );
		bar.style.left = '50%';
		bar.style.width = ( Math.max( v, 0 ) / maxAbs * 50 ).toFixed( 3 ) + '%';
		applySeriesColor( bar, 0, series.color );
		maybeAnimate( spec, bar, i );
		track.appendChild( bar );
		if ( target !== null ) {
			const marker = el( 'div', prefix + '-bullet-target' );
			marker.style.left = ( 50 + ( target / maxAbs ) * 50 ).toFixed( 3 ) + '%';
			track.appendChild( marker );
		}
		track.appendChild( el( 'span', prefix + '-bar-value', formatNumber( v ) ) );
		row.appendChild( track );
		body.appendChild( row );
	} );
	return body;
}

/**
 * Population pyramid: two series mirrored around a center label axis.
 */
function buildPyramidBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--pyramid' );
	const maxAbs = maxAbsValue( spec );
	const labels = spec.labels || [];
	const n = plotSlotCount( spec );

	for ( let i = 0; i < n; i++ ) {
		const row = el( 'div', prefix + '-row ' + prefix + '-row--first' );
		const track = el( 'div', prefix + '-track ' + prefix + '-track--center' );
		const label = el( 'span', prefix + '-pyr-label', labels[ i ] || '' );
		track.appendChild( label );

		const left = spec.series[ 0 ].values[ i ];
		const right = spec.series[ 1 ].values[ i ];
		if ( left !== undefined ) {
			const frac = maxAbs > 0 ? left / maxAbs : 0;
			const bar = el( 'div', prefix + '-bar ' + prefix + '-pyr-left' );
			bar.style.left = ( 50 - Math.max( frac, 0 ) * 50 ).toFixed( 3 ) + '%';
			bar.style.width = ( Math.max( frac, 0 ) * 50 ).toFixed( 3 ) + '%';
			applySeriesColor( bar, 0, spec.series[ 0 ].color );
			maybeAnimate( spec, bar, i * 2 );
			track.appendChild( bar );
		}
		if ( right !== undefined ) {
			const frac = maxAbs > 0 ? right / maxAbs : 0;
			const bar = el( 'div', prefix + '-bar ' + prefix + '-pyr-right' );
			bar.style.left = '50%';
			bar.style.width = ( Math.max( frac, 0 ) * 50 ).toFixed( 3 ) + '%';
			applySeriesColor( bar, 1, spec.series[ 1 ].color );
			maybeAnimate( spec, bar, i * 2 + 1 );
			track.appendChild( bar );
		}
		row.appendChild( track );
		body.appendChild( row );
	}
	return body;
}

/**
 * Waterfall chart: cumulative column bars with connectors.
 */
function buildWaterfallBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--waterfall' );
	const values = spec.series[ 0 ].values;
	const n = values.length;
	const plot = el( 'div', prefix + '-col-plot' );
	const widthPct = Math.min( 70 / n, 40 );
	const stepPct = widthPct;

	// Cumulative totals + scale.
	const cum = [];
	let cumMax = 0;
	let cumSum = 0;
	values.forEach( ( v ) => {
		cumSum += v;
		cum.push( cumSum );
		cumMax = Math.max( cumMax, Math.abs( cumSum ) );
	} );
	cumMax = Math.max( cumMax, Math.abs( values[ 0 ] ) );
	if ( cumMax <= 0 ) {
		cumMax = 1;
	}

	const tops = [];
	values.forEach( ( v, i ) => {
		const base = i === 0 ? 0 : cum[ i - 1 ];
		const end = cum[ i ];
		const center = ( ( i + 0.5 ) / n ) * 100;
		const bar = el( 'div', prefix + '-wf-bar' + ( end >= base ? ' ' + prefix + '-wf-bar--up' : ' ' + prefix + '-wf-bar--down' ) );
		bar.style.left = center.toFixed( 3 ) + '%';
		bar.style.marginLeft = ( -widthPct / 2 ).toFixed( 3 ) + '%';
		bar.style.width = widthPct.toFixed( 3 ) + '%';
		bar.style.bottom = ( 50 + ( Math.min( base, end ) / cumMax ) * 50 ).toFixed( 3 ) + '%';
		bar.style.height = ( Math.abs( end - base ) / cumMax * 50 ).toFixed( 3 ) + '%';
		applySeriesColor( bar, i % 8, spec.series[ 0 ].color );
		maybeAnimate( spec, bar, i );
		plot.appendChild( bar );
		const topY = 50 - ( Math.max( base, end ) / cumMax ) * 50;
		tops.push( { x: center, y: topY } );
		const value = el( 'span', prefix + '-col-value', formatNumber( v ) );
		value.style.left = center.toFixed( 3 ) + '%';
		value.style.width = stepPct.toFixed( 3 ) + '%';
		value.style.bottom = ( 50 + ( Math.max( base, end ) / cumMax ) * 50 + 1 ).toFixed( 3 ) + '%';
		plot.appendChild( value );
	} );

	// Connectors between consecutive bar tops.
	for ( let i = 0; i + 1 < tops.length; i++ ) {
		const dx = tops[ i + 1 ].x - tops[ i ].x;
		const dy = ( tops[ i + 1 ].y - tops[ i ].y ) / PLOT_RATIO;
		const length = Math.sqrt( dx * dx + dy * dy );
		if ( length > 0.01 ) {
			const angle = ( Math.atan2( dy, dx ) * 180 ) / Math.PI;
			const seg = el( 'span', prefix + '-wf-connector' );
			seg.style.left = tops[ i ].x.toFixed( 3 ) + '%';
			seg.style.top = tops[ i ].y.toFixed( 3 ) + '%';
			seg.style.width = length.toFixed( 3 ) + '%';
			seg.style.transform = 'rotate(' + angle.toFixed( 3 ) + 'deg)';
			plot.appendChild( seg );
		}
	}

	body.appendChild( plot );
	body.appendChild( buildXAxis( spec, prefix, n ) );
	return body;
}

/**
 * Candlestick chart: four values per label (open, high, low, close).
 */
function buildCandlestickBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--candlestick' );
	const values = spec.series[ 0 ].values;
	const n = Math.floor( values.length / 4 );
	const plot = el( 'div', prefix + '-col-plot' );

	let maxAbs = 0;
	for ( let i = 0; i < n; i++ ) {
		const idx = i * 4;
		maxAbs = Math.max( maxAbs, Math.abs( values[ idx + 1 ] ), Math.abs( values[ idx + 2 ] ) );
	}
	if ( maxAbs <= 0 ) {
		maxAbs = 1;
	}

	for ( let i = 0; i < n; i++ ) {
		const idx = i * 4;
		const open = values[ idx ];
		const high = values[ idx + 1 ];
		const low = values[ idx + 2 ];
		const close = values[ idx + 3 ];
		const center = ( ( i + 0.5 ) / n ) * 100;

		const wick = el( 'div', prefix + '-candle-wick' );
		wick.style.left = center.toFixed( 3 ) + '%';
		wick.style.bottom = ( 50 + ( low / maxAbs ) * 50 ).toFixed( 3 ) + '%';
		wick.style.height = ( ( high - low ) / maxAbs * 50 ).toFixed( 3 ) + '%';
		plot.appendChild( wick );

		const up = close >= open;
		const bodyBar = el( 'div', prefix + '-candle-body ' + ( up ? prefix + '-candle-up' : prefix + '-candle-down' ) );
		bodyBar.style.left = center.toFixed( 3 ) + '%';
		bodyBar.style.bottom = ( 50 + ( Math.min( open, close ) / maxAbs ) * 50 ).toFixed( 3 ) + '%';
		bodyBar.style.height = Math.max( ( Math.abs( close - open ) / maxAbs ) * 50, 1 ).toFixed( 3 ) + '%';
		maybeAnimate( spec, bodyBar, i );
		plot.appendChild( bodyBar );

		const value = el( 'span', prefix + '-col-value', formatNumber( close ) );
		value.style.left = center.toFixed( 3 ) + '%';
		value.style.width = '40%';
		value.style.bottom = ( 50 + ( high / maxAbs ) * 50 + 1 ).toFixed( 3 ) + '%';
		plot.appendChild( value );
	}
	body.appendChild( plot );
	body.appendChild( buildXAxis( spec, prefix, n ) );
	return body;
}

/**
 * Gantt chart: series items of [start, end] pairs along a shared timeline.
 */
function buildGanttBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--gantt' );
	const items = spec.series;
	let t0 = Infinity;
	let t1 = -Infinity;
	items.forEach( ( s ) => {
		t0 = Math.min( t0, s.values[ 0 ] );
		t1 = Math.max( t1, s.values[ 1 ] );
	} );
	const span = t1 > t0 ? t1 - t0 : 1;

	items.forEach( ( series, idx ) => {
		const start = series.values[ 0 ];
		const end = series.values[ 1 ];
		const row = el( 'div', prefix + '-gantt-item' );
		row.appendChild( el( 'div', prefix + '-row-label', series.name || '' ) );
		const track = el( 'div', prefix + '-track' );
		const bar = el( 'div', prefix + '-gantt-bar' );
		bar.style.left = ( ( start - t0 ) / span * 100 ).toFixed( 3 ) + '%';
		bar.style.width = Math.max( ( end - start ) / span * 100, 1 ).toFixed( 3 ) + '%';
		applySeriesColor( bar, idx, series.color );
		maybeAnimate( spec, bar, idx );
		track.appendChild( bar );
		const value = el( 'span', prefix + '-bar-value', formatNumber( start ) + '–' + formatNumber( end ) );
		value.style.left = Math.min( ( ( end - t0 ) / span * 100 ) + 1, 95 ).toFixed( 3 ) + '%';
		value.style.top = '-4px';
		value.style.transform = 'translateX(-100%)';
		track.appendChild( value );
		row.appendChild( track );
		body.appendChild( row );
	} );

	const axis = el( 'div', prefix + '-gantt-axis' );
	axis.appendChild( el( 'span', prefix + '-gantt-axis-label', formatNumber( t0 ) ) );
	axis.appendChild( el( 'span', prefix + '-gantt-axis-label', formatNumber( t1 ) ) );
	body.appendChild( axis );
	return body;
}

/**
 * Dial chart: semicircular gauge of value vs max.
 */
function buildDialBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--dial' );
	const value = spec.series[ 0 ].values[ 0 ] || 0;
	const max = spec.max !== null && spec.max > 0 ? spec.max : value > 0 ? value : 1;
	const frac = Math.min( Math.max( value / max, 0 ), 1 );

	const wrap = el( 'div', prefix + '-dial' );
	const clip = el( 'div', prefix + '-dial-clip' );
	const ring = el( 'div', prefix + '-dial-ring' );
	ring.style.background =
		'conic-gradient(var(--chart-series-1) 0% ' + ( frac * 100 ).toFixed( 3 ) + '%, var(--chart-surface) ' + ( frac * 100 ).toFixed( 3 ) + '% 100%)';
	clip.appendChild( ring );
	wrap.appendChild( clip );
	const valueLabel = el( 'div', prefix + '-dial-value', formatNumber( value ) );
	wrap.appendChild( valueLabel );
	wrap.appendChild( el( 'div', prefix + '-dial-max', formatNumber( max ) ) );
	body.appendChild( wrap );
	return body;
}

/**
 * Race chart: static final frame (sorted descending) with ranks.
 * Animated replay is a progressive enhancement of the hosting surface —
 * the static frame is the always-correct fallback.
 */
function buildRaceBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--race' );
	body.setAttribute( 'data-nvoos-race', '1' );
	const ranked = raceRanked( spec );
	const maxTotal = ranked.reduce( ( max, entry ) => Math.max( max, entry.total ), 0 );

	ranked.forEach( ( entry, i ) => {
		const row = el( 'div', prefix + '-row ' + prefix + '-row--first' );
		const labelCell = el( 'div', prefix + '-row-label' );
		labelCell.appendChild( el( 'span', prefix + '-race-rank', String( i + 1 ) ) );
		labelCell.appendChild( el( 'span', prefix + '-race-label', entry.label ) );
		row.appendChild( labelCell );
		const track = el( 'div', prefix + '-track' );
		const bar = el( 'div', prefix + '-bar' );
		bar.style.left = '0%';
		bar.style.width = ( maxTotal > 0 ? ( entry.total / maxTotal ) * 100 : 0 ).toFixed( 3 ) + '%';
		applySeriesColor( bar, i % 8, null );
		maybeAnimate( spec, bar, i );
		track.appendChild( bar );
		const value = el( 'span', prefix + '-bar-value', formatNumber( entry.total ) );
		value.style.left = Math.min( Math.max( maxTotal > 0 ? ( entry.total / maxTotal ) * 100 : 0, 2 ), 96 ).toFixed( 3 ) + '%';
		value.style.transform = 'translateX(6px)';
		track.appendChild( value );
		row.appendChild( track );
		body.appendChild( row );
	} );
	return body;
}

/**
 * Grid (small multiples): each child spec rendered with its own title.
 */
function buildGridBody( spec, prefix ) {
	const body = el( 'div', prefix + '-body ' + prefix + '-body--grid' );
	const grid = el( 'div', prefix + '-grid' );
	spec.charts.forEach( ( child, idx ) => {
		const item = el( 'div', prefix + '-grid-item' );
		if ( child.title ) {
			item.appendChild( el( 'div', prefix + '-grid-item-title', child.title ) );
		}
		const childWithTable = { ...child, table: false };
		item.appendChild( buildChartBody( childWithTable, prefix ) );
		maybeAnimate( spec, item, idx );
		grid.appendChild( item );
	} );
	body.appendChild( grid );
	return body;
}

/**
 * Build the chart body for a normalised spec.
 */
function buildChartBody( spec, prefix ) {
	// Bar / column can swap orientation via `direction`.
	if ( spec.type === 'bar' || spec.type === 'column' ) {
		const horizontal = spec.direction === 'horizontal' || ( spec.direction === null && spec.type === 'bar' );
		return horizontal ? buildBarBody( spec, prefix ) : buildColumnBody( spec, prefix );
	}
	switch ( spec.type ) {
		case 'line':
		case 'area':
		case 'dot':
			return buildPlotBody( spec, prefix, spec.type );
		case 'sparkline':
			return buildPlotBody( spec, prefix, 'sparkline' );
		case 'donut':
		case 'pie':
		case 'radial':
			return buildArcBody( spec, prefix, spec.type );
		case 'heatmap':
			return buildHeatmapBody( spec, prefix );
		case 'histogram':
			return buildHistogramBody( spec, prefix );
		case 'box':
			return buildBoxBody( spec, prefix );
		case 'strip':
			return buildStripBody( spec, prefix );
		case 'stem':
			return buildStemBody( spec, prefix );
		case 'violin':
			return buildViolinBody( spec, prefix );
		case 'waffle':
			return buildWaffleBody( spec, prefix );
		case 'unit':
			return buildUnitBody( spec, prefix );
		case 'diverging':
			return buildDivergingBody( spec, prefix );
		case 'lollipop':
			return buildLollipopBody( spec, prefix );
		case 'dumbbell':
			return buildDumbbellBody( spec, prefix );
		case 'slope':
			return buildSlopeBody( spec, prefix );
		case 'bullet':
			return buildBulletBody( spec, prefix );
		case 'pyramid':
			return buildPyramidBody( spec, prefix );
		case 'waterfall':
			return buildWaterfallBody( spec, prefix );
		case 'candlestick':
			return buildCandlestickBody( spec, prefix );
		case 'gantt':
			return buildGanttBody( spec, prefix );
		case 'dial':
			return buildDialBody( spec, prefix );
		case 'race':
			return buildRaceBody( spec, prefix );
		case 'grid':
			return buildGridBody( spec, prefix );
		default:
			return el( 'div', prefix + '-body' );
	}
}

/**
 * Render an `nvoos-chart` fence body to safe chart HTML.
 *
 * Invalid fences fall back to a standard escaped code block.
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

	const spec = parsed.spec;
	const root = el( 'div', classPrefix + ( spec.animate ? ' ' + classPrefix + '-animate' : '' ) );
	root.setAttribute( 'role', 'figure' );
	root.setAttribute( 'aria-label', spec.title || CHART_TYPE_LABELS[ spec.type ] );
	if ( spec.animate ) {
		root.classList.add( classPrefix + '-animate' );
	}
	if ( spec.title ) {
		root.appendChild( el( 'div', classPrefix + '-title', spec.title ) );
	}
	if ( spec.type !== 'grid' && needsLegend( spec ) ) {
		root.appendChild( buildLegend( spec, classPrefix ) );
	}
	root.appendChild( buildChartBody( spec, classPrefix ) );
	if ( spec.type !== 'grid' && spec.table ) {
		root.appendChild( buildDataTable( spec, classPrefix ) );
	}
	if ( spec.caption ) {
		root.appendChild( el( 'div', classPrefix + '-caption', spec.caption ) );
	}
	return root.outerHTML;
}
