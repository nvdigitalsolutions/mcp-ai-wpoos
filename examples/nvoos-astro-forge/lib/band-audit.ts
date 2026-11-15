// ---------------------------------------------------------------------------
// band-audit.ts — per-band verification when forging a site.
//
// WHY: the page-level gates answer "did the design language survive?" — a band
// can pass every one of them and still ship empty, mis-painted or with its
// declared facts unrendered (a dropped band is invisible to a 92 % text score).
// The industry guidance for visual and structural regressions is the same
// (Percy/BrowserStack, Playwright, Chromatic): capture and judge a SECTION at a
// time — component-scoped failures are smaller, faster and far more actionable
// than one full-page diff, and a per-section baseline is what makes "the words
// went missing" visible at all.
//
// WHAT: for every band the profile declares (Elementor sections, or the
// promoted vendor-agnostic outline) this audits, independently:
//
//   presence  the band rendered at all (by id, or positionally for outlines)
//   surface   it carries the baked background / photo / padding facts
//   content   it is not empty (visible text, media, controls)
//   facts     every declared band fact leaves its marker in the band's HTML
//   text      the band's source text survives (when `analyze --text` ran)
//   geometry  the band's box is within tolerance (when `analyze --geometry` ran)
//
// Each check is `pass | warn | fail | skip`. A check that cannot be evaluated
// is SKIPPED, never passed — silently widening a threshold to force green is
// the failure mode every visual-regression guide warns about. Band facts are
// the single source of truth (the design-token drift pattern: the committed
// profile is the baseline, the build is the candidate).
//
// Deterministic and browser-free: geometry/text read baselines already recorded
// by `analyze`; nothing here starts a browser.
// ---------------------------------------------------------------------------

import { auditFacts } from './fact-audit.ts';
import { DEFAULT_GEOMETRY_TOLERANCE, type GeometryTolerance } from './geometry.ts';
import { compareText, normalizeText, textFromHtml } from './text-parity.ts';
import type { ForgeGeometry, ForgeSection, ForgeTextBaseline } from './types.ts';

export type BandCheckStatus = 'pass' | 'warn' | 'fail' | 'skip';

/** One dimension of one band. */
export interface BandCheck {
	status: BandCheckStatus;
	/** Human-readable explanation (always set for warn/fail/skip). */
	detail?: string;
}

/** One band's verdict across every dimension. */
export interface BandVerdict {
	id: string;
	/** Position in the source band order (0-based). */
	index: number;
	anchor?: string;
	/** The built section kind (`hero`, `text`, `form`, …) when it rendered. */
	kind?: string;
	presence: BandCheck;
	surface: BandCheck;
	content: BandCheck;
	facts: BandCheck;
	text: BandCheck;
	geometry: BandCheck;
	/** Worst of the checks (fail > warn > pass; skip never escalates). */
	status: BandCheckStatus;
	/** Every non-pass detail, in check order. */
	notes: string[];
}

export interface BandAuditOptions {
	sections: ForgeSection[];
	builtHtml: string;
	/** Outline bands carry no ids in the rebuilt DOM — map them positionally. */
	positional?: boolean;
	/** The media localizer's source-URL → local-path map (Proposal 139). */
	localizedMedia?: Map< string, string >;
	text?: ForgeTextBaseline;
	geometry?: ForgeGeometry;
	/** Geometry viewport to judge (default `desktop`). */
	viewport?: string;
	geometryTolerance?: GeometryTolerance;
	/** Visible characters below which a populated band reads as empty. */
	minChars?: number;
}

export interface BandAuditReport {
	bands: BandVerdict[];
	summary: Record< BandCheckStatus, number >;
	/** True when bands were matched by position (no source ids exist). */
	positional: boolean;
}

interface BuiltBand {
	id: string;
	kind?: string;
	style: string;
	/** The band's own markup (from its wrapper to the next wrapper). */
	slice: string;
}

const STATUS_ORDER: BandCheckStatus[] = [ 'skip', 'pass', 'warn', 'fail' ];

/** The worst status of a set (skip is neutral). */
function worst( statuses: BandCheckStatus[] ): BandCheckStatus {
	return statuses.reduce< BandCheckStatus >( ( acc, status ) => {
		if ( status === 'skip' ) {
			return acc;
		}
		if ( acc === 'skip' ) {
			return status;
		}
		return STATUS_ORDER.indexOf( status ) > STATUS_ORDER.indexOf( acc ) ? status : acc;
	}, 'skip' );
}

/** The built page's band wrappers, in document order, with their own markup. */
export function builtBandBlocks( html: string ): BuiltBand[] {
	const starts = [ ...html.matchAll( /<div\b[^>]*\sdata-section-id="([^"]*)"[^>]*>/gi ) ];
	return starts.map( ( match, index ) => {
		const tag = match[ 0 ];
		const next = starts[ index + 1 ];
		const end = next?.index ?? html.length;
		return {
			id: match[ 1 ],
			kind: /\bdata-section="([^"]*)"/i.exec( tag )?.[ 1 ],
			style: /\bstyle="([^"]*)"/i.exec( tag )?.[ 1 ] ?? '',
			slice: html.slice( match.index ?? 0, end ),
		};
	} );
}

/** Visible text characters in a band's markup (scripts/styles/tags removed). */
function visibleChars( html: string ): number {
	const stripped = html
		.replace( /<script\b[\s\S]*?<\/script>/gi, ' ' )
		.replace( /<style\b[\s\S]*?<\/style>/gi, ' ' )
		.replace( /<[^>]+>/g, ' ' )
		.replace( /&[a-z#0-9]+;/gi, ' ' );
	return normalizeText( stripped ).replace( /\s+/g, '' ).length;
}

/** Media and control elements a band can carry instead of copy. */
function mediaCount( html: string ): number {
	return (
		( html.match( /<img\b/gi ) ?? [] ).length +
		( html.match( /<picture\b/gi ) ?? [] ).length +
		( html.match( /<svg\b/gi ) ?? [] ).length +
		( html.match( /<video\b/gi ) ?? [] ).length +
		( html.match( /<iframe\b/gi ) ?? [] ).length +
		( html.match( /<form\b/gi ) ?? [] ).length
	);
}

/**
 * The band's visible text, for the per-band text comparison.
 *
 * Parsed with the SAME extractor as the baseline (`textFromHtml`), not a tag
 * regex: a regex leaves entities undecoded (`Monica &amp; rajiv`), which breaks
 * every 3-gram that crosses the ampersand and reported an 80% coverage on bands
 * whose text is byte-identical (monicarajiv.com, 2026-11-11).
 */
function bandText( html: string ): string {
	return textFromHtml( html );
}

function normalizeBg( value: string | undefined ): string {
	return ( value ?? '' ).replace( /\s+/g, ' ' ).trim().toLowerCase();
}

/** A band padding as comparable text (a device map compares on desktop). */
function paddingText( value: ForgeSection[ 'padding' ] ): string {
	return typeof value === 'string' ? value : ( value?.desktop ?? '' );
}

/** Read an inline-style property from a wrapper's `style` attribute. */
function styleValue( style: string, property: string ): string | undefined {
	const match = new RegExp( `(?:^|;)${ property }:\\s*([^";]+)`, 'i' ).exec( style );
	return match?.[ 1 ].trim();
}

/**
 * Drop every `@media` block, brace-matched, leaving the top-level (desktop)
 * declarations. A regex cannot do this safely because media blocks nest rules.
 */
function withoutMediaBlocks( css: string ): string {
	let out = '';
	let index = 0;
	while ( index < css.length ) {
		const at = css.indexOf( '@media', index );
		if ( at < 0 ) {
			out += css.slice( index );
			break;
		}
		out += css.slice( index, at );
		const open = css.indexOf( '{', at );
		if ( open < 0 ) {
			break;
		}
		let depth = 0;
		let cursor = open;
		for ( ; cursor < css.length; cursor++ ) {
			if ( css[ cursor ] === '{' ) {
				depth++;
			} else if ( css[ cursor ] === '}' ) {
				depth--;
				if ( depth === 0 ) {
					cursor++;
					break;
				}
			}
		}
		index = cursor;
	}
	return out;
}

/**
 * Declarations the build emits in SCOPED CSS rather than inline.
 *
 * A per-device value (a padding/margin/height map) cannot be inlined: an inline
 * declaration outranks the media queries that carry the tablet and mobile
 * values. `responsiveDeviceCss` therefore emits
 * `[data-section-id="<id>"]{prop:value}` — desktop first, then the two
 * media-scoped overrides.
 *
 * Reading only the wrap's `style` attribute reported such a band as
 * `padding none`, which is the jayainc `059421c` failure: the build carried
 * `[data-section-id="059421c"]{padding:125px 0px 125px 0px}` while the audit
 * looked at the inline attribute and saw nothing.
 *
 * First occurrence per (id, property) wins. `@media` blocks are stripped first,
 * so a media-scoped override can never be read as the desktop value — the
 * scoped rules are compared at the desktop viewport, which is what the band
 * fact records (`paddingText` takes the map's `desktop`).
 */
function scopedDeclarations( html: string ): Map< string, Map< string, string > > {
	const byId = new Map< string, Map< string, string > >();
	for ( const block of html.matchAll( /<style\b[^>]*>([\s\S]*?)<\/style>/gi ) ) {
		for ( const rule of withoutMediaBlocks( block[ 1 ] ).matchAll( /\[data-section-id="([^"]+)"\]\s*\{([^}]*)\}/g ) ) {
			const declarations = byId.get( rule[ 1 ] ) ?? new Map< string, string >();
			for ( const declaration of rule[ 2 ].split( ';' ) ) {
				const colon = declaration.indexOf( ':' );
				if ( colon < 1 ) {
					continue;
				}
				const property = declaration.slice( 0, colon ).trim().toLowerCase();
				if ( ! declarations.has( property ) ) {
					declarations.set( property, declaration.slice( colon + 1 ).trim() );
				}
			}
			byId.set( rule[ 1 ], declarations );
		}
	}
	return byId;
}

/**
 * Audit every band of a forged site. Pure: profile facts in, verdicts out.
 */
export function auditBands( options: BandAuditOptions ): BandAuditReport {
	const {
		sections,
		builtHtml,
		positional = false,
		localizedMedia,
		text,
		geometry,
		viewport = 'desktop',
		geometryTolerance = DEFAULT_GEOMETRY_TOLERANCE,
		minChars = 12,
	} = options;

	const built = builtBandBlocks( builtHtml );
	const byId = new Map( built.filter( ( band ) => band.id ).map( ( band ) => [ band.id, band ] ) );
	// Per-device values live in scoped CSS, not the wrap's style attribute.
	const scopedById = scopedDeclarations( builtHtml );
	// `analyze --text` stores per-band baselines as an array; index them by id.
	const textById = new Map( ( text?.sections ?? [] ).map( ( section ) => [ section.id, section.text ] ) );
	// Facts resolve by the source section id even when the wrapper rendered
	// with a different (positional) id, so the marker audit stays truthful.
	const facts = auditFacts( sections, builtHtml );
	const factsById = new Map< string, string[] >();
	for ( const finding of facts ) {
		const list = factsById.get( finding.sectionId ) ?? [];
		list.push( finding.fact );
		factsById.set( finding.sectionId, list );
	}

	const bands: BandVerdict[] = sections.map( ( section, index ) => {
		const id = section.id ?? `${ positional ? 'band' : 'section' }-${ index }`;
		const wrap = positional ? built[ index ] : section.id ? byId.get( section.id ) : built[ index ];
		const notes: string[] = [];

		// -- presence ------------------------------------------------------
		let presence: BandCheck;
		if ( ! wrap ) {
			presence = { status: 'fail', detail: positional ? `no band rendered at position ${ index }` : `band ${ id } missing from the build` };
		} else {
			presence = { status: 'pass', detail: `rendered as ${ wrap.kind ?? 'section' }` };
		}
		if ( presence.detail && presence.status !== 'pass' ) {
			notes.push( presence.detail );
		}

		// -- surface -------------------------------------------------------
		const expectedBg =
			section.bgGradient ??
			( section.bgColor && section.bgColor !== 'transparent' ? normalizeBg( section.bgColor ) : undefined );
		const expectedImage = section.bgImage;
		const expectedPadding = paddingText( section.padding );
		let surface: BandCheck;
		if ( ! expectedBg && ! expectedImage && ! expectedPadding ) {
			surface = { status: 'skip', detail: 'band declares no surface facts' };
		} else if ( ! wrap ) {
			surface = { status: 'skip', detail: 'band did not render' };
		} else {
			const problems: string[] = [];
			// Inline first, then the build's scoped rule for this band — the
			// channel a per-device value is emitted into.
			const declared = ( property: string ): string | undefined =>
				styleValue( wrap.style, property ) ?? scopedById.get( wrap.id )?.get( property );
			if ( expectedBg && normalizeBg( declared( 'background' ) ) !== expectedBg ) {
				problems.push( `background ${ declared( 'background' ) ?? 'none' } ≠ ${ expectedBg }` );
			}
			if ( expectedImage ) {
				const actual = declared( 'background-image' )?.replace( /^url\(['"]?|['"]?\)$/g, '' );
				const mirrored = actual !== undefined && localizedMedia?.get( expectedImage ) === actual;
				if ( actual !== expectedImage && ! mirrored ) {
					problems.push( `photo ${ actual ?? 'none' } ≠ ${ expectedImage }` );
				}
			}
			if ( expectedPadding ) {
				const actual = declared( 'padding' );
				if ( ( actual ?? '' ).replace( /\s+/g, ' ' ) !== expectedPadding.replace( /\s+/g, ' ' ) ) {
					problems.push( `padding ${ actual ?? 'none' } ≠ ${ expectedPadding }` );
				}
			}
			surface = problems.length === 0 ? { status: 'pass' } : { status: 'fail', detail: problems.join( '; ' ) };
			if ( problems.length > 0 ) {
				notes.push( ...problems );
			}
		}

		// -- content -------------------------------------------------------
		let content: BandCheck;
		if ( ! wrap ) {
			content = { status: 'skip', detail: 'band did not render' };
		} else {
			const chars = visibleChars( wrap.slice );
			const media = mediaCount( wrap.slice );
			// A photo band with no copy is legitimate — the surface IS the band.
			// A band the profile declares a SPACER is empty by design.
			if ( chars >= minChars || media > 0 || ( expectedImage && chars === 0 ) ) {
				content = { status: 'pass', detail: `${ chars } chars, ${ media } media/controls` };
			} else if ( section.spacer ) {
				content = { status: 'pass', detail: `spacer band — empty by design (${ chars } chars, ${ media } media)` };
			} else {
				const detail = `empty band (${ chars } chars, ${ media } media)`;
				content = { status: 'fail', detail };
				notes.push( detail );
			}
		}

		// -- facts ---------------------------------------------------------
		const unrendered = section.id ? factsById.get( section.id ) ?? [] : [];
		const factsCheck: BandCheck =
			unrendered.length === 0
				? { status: 'pass' }
				: { status: 'fail', detail: `unrendered fact(s): ${ unrendered.join( ', ' ) }` };
		if ( unrendered.length > 0 ) {
			notes.push( factsCheck.detail as string );
		}

		// -- text (needs `analyze --text`) ---------------------------------
		let textCheck: BandCheck;
		const baseline = section.id ? textById.get( section.id ) : undefined;
		if ( baseline === undefined ) {
			textCheck = { status: 'skip', detail: 'no per-band text baseline' };
		} else if ( ! wrap ) {
			textCheck = { status: 'skip', detail: 'band did not render' };
		} else {
			const report = compareText( baseline, bandText( wrap.slice ), {
				passAt: 0.9,
				warnAt: 0.75,
			} );
			textCheck = {
				status: report.verdict,
				detail: `coverage ${ ( report.coverage * 100 ).toFixed( 1 ) }%`,
			};
			if ( report.verdict !== 'pass' ) {
				notes.push( `text ${ textCheck.detail } (${ report.missing.slice( 0, 3 ).join( ' | ' ) })` );
			}
		}

		// -- geometry (needs `analyze --geometry`) -------------------------
		let geometryCheck: BandCheck;
		const box = section.id ? geometry?.[ section.id ]?.[ viewport ] : undefined;
		if ( ! box ) {
			geometryCheck = { status: 'skip', detail: `no ${ viewport } geometry baseline` };
		} else if ( ! wrap ) {
			geometryCheck = { status: 'skip', detail: 'band did not render' };
		} else {
			// Static HTML cannot be measured; geometry is judged by the
			// browser-backed `verify --geometry` gate. This check only records
			// the baseline so the report is complete per band.
			geometryCheck = { status: 'skip', detail: `baseline ${ Math.round( box.height ) }px (run verify --geometry to diff)` };
		}

		return {
			id,
			index,
			anchor: section.anchor,
			kind: wrap?.kind,
			presence,
			surface,
			content,
			facts: factsCheck,
			text: textCheck,
			geometry: geometryCheck,
			status: worst( [ presence, surface, content, factsCheck, textCheck, geometryCheck ].map( ( check ) => check.status ) ),
			notes,
		};
	} );

	const summary: Record< BandCheckStatus, number > = { pass: 0, warn: 0, fail: 0, skip: 0 };
	for ( const band of bands ) {
		summary[ band.status ]++;
	}
	return { bands, summary, positional };
}

/** A compact console table — one row per band. */
export function formatBandReport( report: BandAuditReport ): string {
	const icon: Record< BandCheckStatus, string > = { pass: '✓', warn: '!', fail: '✗', skip: '·' };
	const label = ( band: BandVerdict ) =>
		band.anchor ? `#${ band.anchor }` : band.id === '' ? '(unnamed)' : band.id;
	const rows = report.bands.map( ( band ) => {
		const cells = [ band.presence, band.surface, band.content, band.facts, band.text, band.geometry ]
			.map( ( check ) => icon[ check.status ] )
			.join( ' ' );
		const detail = band.notes.length > 0 ? `  — ${ band.notes[ 0 ] }` : '';
		return `  ${ icon[ band.status ] } ${ String( band.index + 1 ).padStart( 2 ) }. ${ label( band ).padEnd( 18 ) } ${ cells }${ detail }`;
	} );
	const { summary } = report;
	const header = `  legend: presence surface content facts text geometry`;
	const totals = `${ summary.pass } pass · ${ summary.warn } warn · ${ summary.fail } fail · ${ summary.skip } skip`;
	return [ header, ...rows, `  ${ totals }${ report.positional ? ' (positional: outline bands have no source ids)' : '' }` ].join( '\n' );
}
