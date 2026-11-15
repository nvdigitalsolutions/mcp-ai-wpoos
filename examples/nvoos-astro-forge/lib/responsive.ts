// ---------------------------------------------------------------------------
// responsive.ts — the source's responsive language (Proposal 115 §4).
//
// Breakpoint recognition: media-query widths harvested from the source CSS
// via css-tree (robust @media prelude parsing — regex cannot see nested
// conditions). Section column behavior: the industry-standard collapse
// (desktop N → tablet 2 → mobile 1), overridden by explicit per-breakpoint
// column classes captured from the markup (Elementor's elementor-col-* /
// -md-* / -sm-* convention). The forge rebuilds the behavior, never the CSS.
// ---------------------------------------------------------------------------

import { generate, parse, walk } from 'css-tree';

export interface Breakpoint {
	label: 'mobile' | 'tablet' | 'desktop';
	px: number;
}

/** The canonical defaults (Elementor's pair, padded with a small-phone tier).
 *  Used when the source CSS declares fewer than two breakpoints. */
export const DEFAULT_BREAKPOINTS: Breakpoint[] = [
	{ label: 'mobile', px: 480 },
	{ label: 'tablet', px: 767 },
	{ label: 'desktop', px: 1025 },
];

/** The three breakpoint labels, ascending width — the wireframe contract. */
export const BREAKPOINT_LABELS: Breakpoint['label'][] = [ 'mobile', 'tablet', 'desktop' ];

/**
 * Harvest the source's breakpoint widths from its CSS. Up to three values
 * (one per label slot, ascending); missing slots fall back to the canonical
 * defaults. `max-width` values win (clean desktop-first semantics);
 * `min-width` is only used when no max-width breakpoint exists. Fewer than
 * two widths in total ⇒ the source is not breakpoint-driven — the defaults.
 */
export function extractBreakpoints( css: string ): Breakpoint[] {
	const maxWidths = new Set<number>();
	const minWidths = new Set<number>();
	try {
		const ast = parse( css, { positions: false, parseValue: false } );
		walk( ast, ( node ) => {
			if ( node.type !== 'Atrule' || node.name.toLowerCase() !== 'media' || ! node.prelude ) {
				return;
			}
			let preludeText = '';
			try {
				// MediaQueryList nodes serialize via generate() — they carry no
				// `.value` (that is Raw-node territory).
				preludeText = generate( node.prelude );
			} catch {
				// Non-standard preludes degrade to the default set.
			}
			const re = /(max-width|min-width)\s*:\s*(\d+(?:\.\d+)?)px/gi;
			let match;
			while ( ( match = re.exec( preludeText ) ) ) {
				const px = Math.round( Number( match[ 2 ] ) );
				if ( match[ 1 ].toLowerCase() === 'max-width' ) {
					maxWidths.add( px );
				} else {
					minWidths.add( px );
				}
			}
		} );
	} catch {
		return DEFAULT_BREAKPOINTS;
	}
	const widths = maxWidths.size >= 2 ? [ ...maxWidths ] : minWidths.size >= 2 ? [ ...minWidths ] : [];
	if ( widths.length < 2 ) {
		return DEFAULT_BREAKPOINTS;
	}
	const sorted = widths.sort( ( a, b ) => a - b ).slice( -3 );
	// Largest → desktop, next → tablet, the rest collapse into mobile.
	const slots = [ ...sorted ];
	while ( slots.length < 3 ) {
		slots.unshift( DEFAULT_BREAKPOINTS[ 0 ].px );
	}
	return BREAKPOINT_LABELS.map( ( label, i ) => ( { label, px: slots[ i ] } ) );
}

/** One per-breakpoint column map (label → column count). */
export type ColumnMap = Record<string, number>;

/**
 * The standard collapse rule for a grid of `count` cells: desktop = up to 4
 * columns, tablet = 2, mobile = 1. Explicit per-breakpoint classes captured
 * from the source markup override (elementor-col-* / -md-* / -sm-*).
 */
export function inferColumns( count: number, explicit?: Partial<ColumnMap> ): { columns: ColumnMap; source: 'explicit' | 'inferred' } {
	const desktop = explicit?.desktop ?? Math.min( Math.max( count, 1 ), 4 );
	const tablet = explicit?.tablet ?? Math.min( Math.max( count, 1 ), 2 );
	const mobile = explicit?.mobile ?? 1;
	return {
		columns: { desktop, tablet, mobile },
		source: explicit && ( explicit.desktop || explicit.tablet || explicit.mobile ) ? 'explicit' : 'inferred',
	};
}

/**
 * Collect explicit per-breakpoint column classes from sanitized markup
 * (document level — a per-section span mapping is a documented follow-up).
 * Elementor convention: `elementor-col-<n>` = desktop, `elementor-md-<n>` =
 * tablet, `elementor-sm-<n>` = mobile; the most common value per bucket wins.
 */
export function explicitColumns( html: string ): Partial<ColumnMap> {
	const buckets: Record<'desktop' | 'tablet' | 'mobile', Record<string, number>> = {
		desktop: {},
		tablet: {},
		mobile: {},
	};
	const patterns: Array<[ keyof typeof buckets, RegExp ]> = [
		[ 'desktop', /elementor-col-(\d+)/gi ],
		[ 'tablet', /elementor-md-(\d+)/gi ],
		[ 'mobile', /elementor-sm-(\d+)/gi ],
	];
	for ( const [ key, re ] of patterns ) {
		let match;
		while ( ( match = re.exec( html ) ) ) {
			buckets[ key ][ match[ 1 ] ] = ( buckets[ key ][ match[ 1 ] ] ?? 0 ) + 1;
		}
	}
	const out: Partial<ColumnMap> = {};
	for ( const key of Object.keys( buckets ) as Array<keyof typeof buckets> ) {
		const entries = Object.entries( buckets[ key ] );
		if ( entries.length === 0 ) {
			continue;
		}
		entries.sort( ( a, b ) => b[ 1 ] - a[ 1 ] );
		out[ key ] = Number( entries[ 0 ][ 0 ] );
	}
	return out;
}
