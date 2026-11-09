// ---------------------------------------------------------------------------
// text-parity.ts — content parity, measured instead of assumed.
//
// `scoreParity` scores the design LANGUAGE and the fact gate proves declared
// band facts render; neither answers "did the rebuild keep the words?". This
// module extracts the visible text from the source and built HTML, normalises
// it, and compares w-shingles (Broder 1997; Manning/Schütze, Introduction to
// Information Retrieval §19.6 — the standard near-duplicate measure, also what
// duplicate-content QA tools use).
//
// Deliberate scope:
//  * Direction matters. `coverage` (how much of the SOURCE survives) is the
//    gate; `precision` and `jaccard` are reported for context. A rebuild that
//    keeps 99% of the source but adds copy is NOT a content regression, so
//    union-based Jaccard alone would mis-fire.
//  * Comparison is normalised and case-insensitive: builders apply
//    `text-transform` in CSS, so rendered casing differs while the DOM text
//    does not. NFKC + punctuation folding + whitespace collapse absorb the
//    rest (smart quotes, NBSP, zero-width joiners).
//  * Extraction skips script/style/noscript/template/svg/iframe/head and
//    `hidden`/`aria-hidden="true"` subtrees — the same surfaces a browser
//    never shows. `<img alt>` is opt-in because rebuilds often regenerate it.
//
// Pure and deterministic; htmlparser2 is already a forge dependency. The
// baseline shape lives in types.ts (`ForgeTextBaseline`) so `analyze --text`
// and `verify --text` share one contract, mirroring `geometry`.
// ---------------------------------------------------------------------------

import { DomHandler, Parser } from 'htmlparser2';

import type { ForgeTextBaseline, ForgeTextSection } from './types.ts';

export interface TextParityOptions {
	/** Shingle width in words. 3 is the common web-content default. */
	shingleSize?: number;
	/** coverage at/above this passes (default 0.9 — near-duplicate territory). */
	passAt?: number;
	/** coverage between warnAt and passAt warns; below warnAt fails. */
	warnAt?: number;
	/** How many missing/extra shingles to sample in the report. */
	sampleLimit?: number;
}

export interface TextParityReport {
	sourceShingles: number;
	builtShingles: number;
	shared: number;
	/** |∩| / |source| — the gate: how much of the source survives. */
	coverage: number;
	/** |∩| / |built| — how much of the build comes from the source. */
	precision: number;
	/** |∩| / |source ∪ built| — the symmetric near-duplicate measure. */
	jaccard: number;
	/** Sample source-side shingles missing from the build (actual omissions). */
	missing: string[];
	/** Sample build-side shingles absent from the source (added copy). */
	extra: string[];
	verdict: 'pass' | 'warn' | 'fail';
}

const SKIP_TAGS = new Set( [
	'script',
	'style',
	'noscript',
	'template',
	'svg',
	'iframe',
	'object',
	'head',
	'canvas',
	'video',
	'audio',
] );

/** Block-level tags — a boundary keeps "AlphaBeta" from collapsing to one token. */
const BLOCK_TAGS = new Set( [
	'address', 'article', 'aside', 'blockquote', 'br', 'dd', 'div', 'dl', 'dt',
	'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4',
	'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section',
	'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
] );

/** Punctuation that different editors/templates swap freely. */
const PUNCT_FOLD: Record< string, string > = {
	'\u2018': "'", '\u2019': "'", '\u201a': "'", '\u201b': "'", '\u2032': "'",
	'\u201c': '"', '\u201d': '"', '\u201e': '"', '\u201f': '"', '\u2033': '"',
	'\u2013': '-', '\u2014': '-', '\u2015': '-', '\u2212': '-',
	'\u2026': '...',
	'\u00a0': ' ', '\u1680': ' ', '\u2000': ' ', '\u2001': ' ', '\u2002': ' ',
	'\u2003': ' ', '\u2004': ' ', '\u2005': ' ', '\u2006': ' ', '\u2007': ' ',
	'\u2008': ' ', '\u2009': ' ', '\u200a': ' ', '\u202f': ' ', '\u205f': ' ',
	'\u200b': '', '\u200c': '', '\u200d': '', '\ufeff': '',
};

/**
 * Normalise visible text: NFKC, fold web punctuation and invisible spaces,
 * collapse whitespace, lowercase. Case-insensitivity is correct here because
 * `text-transform` changes rendering, not the DOM text this compares.
 */
export function normalizeText( input: string ): string {
	let text = input.normalize( 'NFKC' );
	for ( const [ from, to ] of Object.entries( PUNCT_FOLD ) ) {
		text = text.split( from ).join( to );
	}
	return text.replace( /\s+/g, ' ' ).trim().toLowerCase();
}

/**
 * Words (letters/digits, keeping internal apostrophes and hyphens so
 * "don't" and "e-mail" stay one token). Input should be `normalizeText()`ed
 * first; doing it here would hide the caller's normalisation from tests.
 */
export function tokenize( text: string ): string[] {
	return text.match( /[\p{L}\p{N}]+(?:['-][\p{L}\p{N}]+)*/gu ) ?? [];
}

/**
 * Overlapping k-word shingles as a set. Documents shorter than k collapse to
 * their whole token string, so a two-word band still compares meaningfully.
 */
export function shingles( tokens: string[], size: number = 3 ): Set< string > {
	const width = Math.max( 1, size );
	if ( tokens.length === 0 ) {
		return new Set();
	}
	if ( tokens.length <= width ) {
		return new Set( [ tokens.join( ' ' ) ] );
	}
	const out = new Set< string >();
	for ( let index = 0; index + width <= tokens.length; index++ ) {
		out.add( tokens.slice( index, index + width ).join( ' ' ) );
	}
	return out;
}

function sample( values: string[], limit: number ): string[] {
	return values.slice( 0, Math.max( 0, limit ) );
}

/**
 * Compare two raw or normalised text samples. Pass `normalized: true` when the
 * inputs already went through `normalizeText()`; otherwise they are normalised
 * here (idempotent, so the flag is an optimisation, not a contract).
 */
export function compareText(
	source: string,
	built: string,
	options: TextParityOptions & { normalized?: boolean } = {}
): TextParityReport {
	const size = options.shingleSize ?? 3;
	const passAt = options.passAt ?? 0.9;
	const warnAt = options.warnAt ?? 0.75;
	const limit = options.sampleLimit ?? 5;
	const sourceText = options.normalized ? source : normalizeText( source );
	const builtText = options.normalized ? built : normalizeText( built );
	const a = shingles( tokenize( sourceText ), size );
	const b = shingles( tokenize( builtText ), size );
	let shared = 0;
	for ( const shingle of a ) {
		if ( b.has( shingle ) ) {
			shared++;
		}
	}
	const union = a.size + b.size - shared;
	const coverage = a.size === 0 ? 1 : shared / a.size;
	const precision = b.size === 0 ? 0 : shared / b.size;
	const jaccard = union === 0 ? 1 : shared / union;
	const missing = sample(
		[ ...a ].filter( ( shingle ) => ! b.has( shingle ) ),
		limit
	);
	const extra = sample(
		[ ...b ].filter( ( shingle ) => ! a.has( shingle ) ),
		limit
	);
	const verdict: TextParityReport[ 'verdict' ] =
		coverage >= passAt ? 'pass' : coverage >= warnAt ? 'warn' : 'fail';
	return {
		sourceShingles: a.size,
		builtShingles: b.size,
		shared,
		coverage,
		precision,
		jaccard,
		missing,
		extra,
		verdict,
	};
}

// -- HTML extraction ---------------------------------------------------------

interface HNode {
	type: string;
	name?: string;
	attribs?: Record< string, string >;
	children?: HNode[] | null;
	data?: string;
}

function childrenOf( node: HNode ): HNode[] {
	const children = node.children;
	if ( ! children ) {
		return [];
	}
	return Array.isArray( children ) ? children : Array.from( children as unknown as ArrayLike< HNode > );
}

function parseRoot( html: string ): HNode {
	const handler = new DomHandler();
	const parser = new Parser( handler, {
		decodeEntities: true,
		lowerCaseTags: true,
		lowerCaseAttributeNames: true,
	} );
	parser.write( html );
	parser.end();
	return handler.root as unknown as HNode;
}

function isSkipped( node: HNode ): boolean {
	if ( ! node.name || SKIP_TAGS.has( node.name ) ) {
		return Boolean( node.name );
	}
	const attribs = node.attribs ?? {};
	if ( attribs.hidden !== undefined || attribs[ 'aria-hidden' ] === 'true' ) {
		return true;
	}
	const style = ( attribs.style ?? '' ).toLowerCase();
	return style.includes( 'display:none' ) || style.includes( 'visibility:hidden' );
}

export interface ExtractOptions {
	/** Include `<img alt="…">` text (default false — rebuilds regenerate it). */
	includeAlt?: boolean;
}

function collectText( node: HNode, chunks: string[], options: ExtractOptions ): void {
	if ( node.type === 'text' ) {
		if ( node.data ) {
			chunks.push( node.data );
		}
		return;
	}
	if ( node.type !== 'tag' && node.type !== 'root' ) {
		return;
	}
	if ( node.type === 'tag' && isSkipped( node ) ) {
		return;
	}
	if ( node.type === 'tag' && node.name === 'img' && options.includeAlt ) {
		const alt = node.attribs?.alt?.trim();
		if ( alt ) {
			chunks.push( ` ${ alt } ` );
		}
		return;
	}
	const block = node.name ? BLOCK_TAGS.has( node.name ) : false;
	if ( block ) {
		chunks.push( '\n' );
	}
	for ( const child of childrenOf( node ) ) {
		collectText( child, chunks, options );
	}
	if ( block ) {
		chunks.push( '\n' );
	}
}

/** Normalised visible text of a whole HTML document. */
export function textFromHtml( html: string, options: ExtractOptions = {} ): string {
	if ( ! html.trim() ) {
		return '';
	}
	const chunks: string[] = [];
	collectText( parseRoot( html ), chunks, options );
	return normalizeText( chunks.join( '' ) );
}

/** The selector ladder a source band id may be findable under. */
function matchesSection( node: HNode, id: string ): boolean {
	const attribs = node.attribs ?? {};
	if ( attribs[ 'data-section-id' ] === id || attribs.id === id ) {
		return true;
	}
	const classes = ( attribs.class ?? '' ).split( /\s+/ );
	return classes.includes( `elementor-element-${ id }` ) || classes.includes( id );
}

function firstSectionNode( node: HNode, id: string ): HNode | undefined {
	if ( node.type === 'tag' && matchesSection( node, id ) ) {
		return node;
	}
	for ( const child of childrenOf( node ) ) {
		const found = firstSectionNode( child, id );
		if ( found ) {
			return found;
		}
	}
	return undefined;
}

/**
 * Per-band text from a document. Built pages expose `data-section-id`; source
 * pages may expose Elementor's class, a matching element id, or the id as a
 * class (WPBakery/Brando style). Bands with no locatable node are reported in
 * `missing` rather than failed — the page-level gate still covers their text.
 */
export function extractSectionTexts(
	html: string,
	ids: string[],
	options: ExtractOptions = {}
): { texts: Record< string, string >; missing: string[] } {
	const texts: Record< string, string > = {};
	const missing: string[] = [];
	if ( ! html.trim() ) {
		return { texts, missing: [ ...ids ] };
	}
	const root = parseRoot( html );
	for ( const id of ids ) {
		const node = firstSectionNode( root, id );
		if ( ! node ) {
			missing.push( id );
			continue;
		}
		const chunks: string[] = [];
		collectText( node, chunks, options );
		texts[ id ] = normalizeText( chunks.join( '' ) );
	}
	return { texts, missing };
}

/** Build the `analyze --text` baseline from the source homepage. */
export function textBaselineFromHtml(
	html: string,
	meta: { url: string; ids?: string[]; includeAlt?: boolean; capturedAt?: string }
): ForgeTextBaseline {
	const options: ExtractOptions = { includeAlt: meta.includeAlt === true };
	const ids = meta.ids ?? [];
	const extracted = extractSectionTexts( html, ids, options );
	const sections: ForgeTextSection[] = [];
	const skipped: string[] = [];
	for ( const id of ids ) {
		const text = extracted.texts[ id ];
		if ( ! text ) {
			skipped.push( id );
			continue;
		}
		sections.push( { id, text } );
	}
	return {
		capturedAt: meta.capturedAt ?? new Date().toISOString(),
		url: meta.url,
		page: textFromHtml( html, options ),
		sections,
		skipped,
	};
}
