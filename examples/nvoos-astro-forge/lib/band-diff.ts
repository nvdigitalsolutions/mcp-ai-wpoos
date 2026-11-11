// ---------------------------------------------------------------------------
// band-diff.ts — WHERE a band's height delta comes from.
//
// `verify --geometry` reports that a band is 15px taller than its source. That
// is the *symptom*; the diagnosis is always "walk both band trees and compare
// the boxes", which is what a human does by hand every time (and what this
// session did four times with throwaway scripts).
//
// The model follows the industry's own attribution precedent rather than
// inventing one:
//
//  * The Layout Instability API (WICG; web.dev "Debug layout shifts") reports a
//    shift as `sources[]` of `{ node, previousRect, currentRect }`, capped at the
//    five LARGEST sources by impact. This module reports per-node
//    `{ source, built }` rects, ranked the same way and capped the same way.
//  * web.dev's causal guidance is encoded as hints: a large downward delta
//    usually means a subtree was INSERTED; a 1–2px delta usually means rounding
//    or a late web font; the element immediately PRECEDING a taller one is the
//    likeliest cause.
//  * Percy/Chromatic compare "the spatial positioning of elements by analysing
//    bounding boxes", and Cypress recommends element-scoped diffs over
//    full-page pixels — so this is text/JSON about boxes, never an image.
//
// Matching is by STRUCTURAL KEY (`parent>signature[ordinal]`) rather than tree
// edit distance: Zhang–Shasha is O(n²m²) and the rebuilt raw-HTML bands keep
// their source class names, so a keyed walk matches exactly where it can and
// reports `added`/`removed` where it cannot. No key → no false pairing.
//
// Determinism is the Playwright visual-testing recipe: fixed device scale, CSS
// animations/transitions killed, `document.fonts.ready` awaited, and each
// viewport measured on one page load.
// ---------------------------------------------------------------------------

import type { GeometryViewport } from './geometry.ts';

/** One node's box, in CSS pixels. */
export interface BoxNode {
	/** Structural key — `parent>signature[ordinal]`, stable across both trees. */
	key: string;
	/** `tag.class1.class2` with the classes sorted. */
	sig: string;
	tag: string;
	depth: number;
	w: number;
	h: number;
	/** padding top/right/bottom/left. */
	pad: [ number, number, number, number ];
	/** margin top/right/bottom/left. */
	mar: [ number, number, number, number ];
}

/** How one node differs between source and build. */
export interface BoxDelta {
	key: string;
	sig: string;
	status: 'matched' | 'added' | 'removed';
	source?: BoxNode;
	built?: BoxNode;
	/** built − source (0 when the node exists on one side only). */
	dH: number;
	dW: number;
	dPad: number;
	dMar: number;
	/** The web.dev cause category this delta falls into. */
	cause: string;
}

/** One band/viewport comparison. */
export interface BandDiffReport {
	bandId: string;
	viewport: string;
	/** The BAND element's own height (matches the geometry gate's reading). */
	sourceHeight: number;
	builtHeight: number;
	/** built − source at the band element. */
	heightDelta: number;
	/** The matched band SUBTREE's heights — the rebuild wraps the source section
	 * in its own chrome, so this excludes the wrapper's padding. */
	sourceContentHeight: number;
	builtContentHeight: number;
	/** built − source inside the wrapper. */
	contentDelta: number;
	/** What the rebuild's wrapper contributes: heightDelta − contentDelta. */
	wrapperDelta: number;
	nodeCount: number;
	/** True when the node cap was hit — the tree walk is incomplete. */
	truncated: boolean;
	/** Every differing node, document order. */
	nodes: BoxDelta[];
	/** The largest `|dH|` contributors, capped (Layout Instability's top-5 idea). */
	contributors: BoxDelta[];
	/** Root → leaf chain following the largest `|dH|` at each level. */
	hotPath: BoxDelta[];
	/** Human-readable causes, derived from the deltas. */
	hints: string[];
}

/**
 * Re-root the BUILT tree onto the node that corresponds to the SOURCE root.
 *
 * The rebuild wraps a raw-HTML band in its own chrome (`.ds-section-wrap >
 * .ds-section > <the source section>`), so the two trees have different roots and
 * no key would ever match. Anchoring on the source root's signature — which the
 * sanitizer preserves — and re-keying that subtree onto the source root's key
 * makes the rest of the comparison a straight keyed walk.
 *
 * Pure. Returns undefined when the anchor cannot be found (the caller then falls
 * back to comparing from the top and says so).
 */
export function alignToSourceRoot( source: BoxNode[], built: BoxNode[] ): BoxNode[] | undefined {
	const sourceRoot = source[ 0 ];
	if ( ! sourceRoot ) {
		return undefined;
	}
	const anchor = built.find( ( node ) => node.sig === sourceRoot.sig );
	if ( ! anchor ) {
		return undefined;
	}
	const prefix = anchor.key;
	const sub = built.filter( ( node ) => node.key === prefix || node.key.startsWith( `${ prefix }>` ) );
	if ( sub.length === 0 ) {
		return undefined;
	}
	return sub.map( ( node ) => ( {
		...node,
		// Swap the anchor's key for the source root's, keeping every descendant's
		// relative suffix so the structural walk still lines up.
		key: node.key === prefix ? sourceRoot.key : `${ sourceRoot.key }${ node.key.slice( prefix.length ) }`,
		depth: node.depth - anchor.depth,
	} ) );
}

/** A 1\u20132px delta is rounding or a late font, not layout (web.dev). */
const SUB_PIXEL = 2;

/** The Layout Instability API caps its `sources` at five; so does this. */
export const DEFAULT_CONTRIBUTOR_LIMIT = 5;

/** How close a child's delta must be to its parent's to call the parent a carrier. */
const CARRIER_TOLERANCE = 2;

/** The class-name list is the identity of an element, so it is never trimmed. */

const round = ( value: number ): number => Math.round( value * 10 ) / 10;

/**
 * `tag.class1.class2` with the classes sorted — the stable identity of an
 * element across the source and the rebuild (both keep the builder's classes).
 * Module scope: Playwright serialises this into the page.
 */
export function boxSignature( tagName: string, className: string ): string {
	const classes = ( className ?? '' )
		.split( /\s+/ )
		.filter( Boolean )
		.sort();
	return [ ( tagName ?? '' ).toLowerCase(), ...classes ].join( '.' );
}

/**
 * The structural key of a child: its parent's key, its signature, and its
 * ordinal among SIBLINGS THAT SHARE THE SIGNATURE (so reordering two different
 * children does not break the match).
 */
export function boxKey( parentKey: string, sig: string, ordinal: number ): string {
	return parentKey ? `${ parentKey }>${ sig }[${ ordinal }]` : `${ sig }[${ ordinal }]`;
}

/**
 * Category for one delta. Ordered so the most CAUSAL explanation wins: a
 * subtree that appeared is an insertion whatever its size, and a node whose
 * padding changed is a spacing change even when its height moved.
 */
export function classifyDelta( delta: Pick< BoxDelta, 'status' | 'dH' | 'dW' | 'dPad' | 'dMar' > ): string {
	if ( delta.status === 'added' ) {
		return 'subtree inserted';
	}
	if ( delta.status === 'removed' ) {
		return 'subtree missing';
	}
	if ( ( delta.dPad !== 0 || delta.dMar !== 0 ) && delta.dH !== 0 ) {
		return 'spacing (padding/margin)';
	}
	if ( delta.dW !== 0 && delta.dH !== 0 ) {
		return 'size change';
	}
	if ( delta.dW !== 0 ) {
		return 'width only (reflow)';
	}
	if ( delta.dH !== 0 && Math.abs( delta.dH ) <= SUB_PIXEL ) {
		return 'sub-pixel rounding';
	}
	if ( delta.dH !== 0 ) {
		return 'height only';
	}
	return 'unchanged';
}

const sumAbs = ( values: number[] ): number => values.reduce( ( total, value ) => total + Math.abs( value ), 0 );

/**
 * Diff two flattened box trees by structural key.
 *
 * Pure, so the matching rules are testable without a browser: a shared key
 * compares its boxes, a source-only key is `removed`, a built-only key is
 * `added`, and only differing nodes are returned.
 */
export function diffBoxTrees( source: BoxNode[], built: BoxNode[] ): BoxDelta[] {
	const sourceByKey = new Map( source.map( ( node ) => [ node.key, node ] ) );
	const builtByKey = new Map( built.map( ( node ) => [ node.key, node ] ) );
	const deltas: BoxDelta[] = [];
	// Document order from both sides: build a key order that respects the
	// source first, then appends anything the rebuild added. Inserted subtrees
	// therefore appear next to where they were inserted.
	const order: string[] = [];
	const seen = new Set< string >();
	for ( const node of source ) {
		order.push( node.key );
		seen.add( node.key );
	}
	for ( const node of built ) {
		if ( ! seen.has( node.key ) ) {
			order.push( node.key );
			seen.add( node.key );
		}
	}
	for ( const key of order ) {
		const from = sourceByKey.get( key );
		const to = builtByKey.get( key );
		const dH = from && to ? round( to.h - from.h ) : 0;
		const dW = from && to ? round( to.w - from.w ) : 0;
		const dPad = from && to ? round( sumAbs( to.pad.map( ( value, index ) => value - from.pad[ index ] ) ) ) : 0;
		const dMar = from && to ? round( sumAbs( to.mar.map( ( value, index ) => value - from.mar[ index ] ) ) ) : 0;
		const status: BoxDelta[ 'status' ] = from && to ? 'matched' : to ? 'added' : 'removed';
		// A one-sided node has no subtraction, so its HEIGHT stands in as the
		// impact proxy: a block that appears (or vanishes) shifts everything below
		// it by its own height. That is also how the Layout Instability API scores
		// a shift — by displacement, not by the element's size.
		const impactH = status === 'matched' ? round( ( to?.h ?? 0 ) - ( from?.h ?? 0 ) ) : status === 'added' ? round( to?.h ?? 0 ) : round( -( from?.h ?? 0 ) );
		const delta: BoxDelta = {
			key,
			sig: ( to ?? from )?.sig ?? '',
			status,
			...( from ? { source: from } : {}),
			...( to ? { built: to } : {}),
			dH: impactH,
			dW,
			dPad,
			dMar,
			cause: '',
		};
		// An inserted/removed subtree is always worth reporting; a matched node
		// only when one of its boxes actually moved.
		if ( status !== 'matched' || dH !== 0 || dW !== 0 || dPad !== 0 || dMar !== 0 ) {
			delta.cause = classifyDelta( delta );
			deltas.push( delta );
		}
	}
	return deltas;
}

/**
 * The largest contributors by impact — the same cap-and-rank the Layout
 * Instability API uses for `sources`, so a report never becomes a wall of nodes
 * nobody reads.
 *
 * Ancestors are suppressed when a CHILD's delta already accounts for theirs: a
 * wrapper whose height moved only because its content moved is a carrier, not a
 * cause, and listing it buries the real one (virtuallyjamaica's features band
 * reported `vc-column-innner-wrapper +1389px` whose child carried the same
 * +1389px).
 */
export function topContributors( deltas: BoxDelta[], limit = DEFAULT_CONTRIBUTOR_LIMIT ): BoxDelta[] {
	const changed = deltas.filter( ( delta ) => delta.dH !== 0 || delta.status !== 'matched' );
	const byParent = new Map< string, BoxDelta[] >();
	for ( const delta of changed ) {
		const cut = delta.key.lastIndexOf( '>' );
		const parent = cut === -1 ? '' : delta.key.slice( 0, cut );
		if ( ! byParent.has( parent ) ) {
			byParent.set( parent, [] );
		}
		( byParent.get( parent ) as BoxDelta[] ).push( delta );
	}
	const carriers = new Set< string >();
	for ( const delta of changed ) {
		const children = byParent.get( delta.key ) ?? [];
		if ( children.some( ( child ) => Math.abs( child.dH ) >= Math.abs( delta.dH ) - CARRIER_TOLERANCE ) ) {
			carriers.add( delta.key );
		}
	}
	return changed
		.filter( ( delta ) => ! carriers.has( delta.key ) )
		.sort( ( a, b ) => {
			const byHeight = Math.abs( b.dH ) - Math.abs( a.dH );
			if ( byHeight !== 0 ) {
				return byHeight;
			}
			const byWidth = Math.abs( b.dW ) - Math.abs( a.dW );
			return byWidth !== 0 ? byWidth : a.key.localeCompare( b.key );
		} )
		.slice( 0, limit );
}

/**
 * The root → leaf chain that follows the largest `|dH|` at every level — the
 * "most likely root cause" line web.dev's Thought Process describes. Its last
 * entry is the deepest node whose own delta explains most of the band's total.
 */
export function hotPath( deltas: BoxDelta[], limit = DEFAULT_CONTRIBUTOR_LIMIT ): BoxDelta[] {
	if ( deltas.length === 0 ) {
		return [];
	}
	const byKey = new Map( deltas.map( ( delta ) => [ delta.key, delta ] ) );
	const children = new Map< string, BoxDelta[] >();
	for ( const delta of deltas ) {
		const cut = delta.key.lastIndexOf( '>' );
		const parent = cut === -1 ? '' : delta.key.slice( 0, cut );
		if ( ! children.has( parent ) ) {
			children.set( parent, [] );
		}
		( children.get( parent ) as BoxDelta[] ).push( delta );
	}
	// Start from the shallowest node that actually moved (the band root is
	// usually byte-identical in size once the delta is attributed below it).
	const roots = [ ...children.keys() ]
		.filter( ( parent ) => ! byKey.has( parent ) )
		.flatMap( ( parent ) => children.get( parent ) ?? [] )
		.sort( ( a, b ) => Math.abs( b.dH ) - Math.abs( a.dH ) );
	let cursor = roots[ 0 ];
	const path: BoxDelta[] = [];
	const guard = new Set< string >();
	while ( cursor && ! guard.has( cursor.key ) && path.length < limit ) {
		guard.add( cursor.key );
		path.push( cursor );
		const next = ( children.get( cursor.key ) ?? [] )
			.filter( ( child ) => Math.abs( child.dH ) > 0 )
			.sort( ( a, b ) => Math.abs( b.dH ) - Math.abs( a.dH ) )[ 0 ];
		cursor = next;
	}
	return path;
}

/**
 * Turn a report's deltas into the handful of sentences a human would say.
 *
 * The rules are web.dev's: insertion shows up as a large downward delta, a 1–2px
 * delta is rounding or a font swap, and the element immediately preceding a
 * taller one is the likeliest culprit.
 */
export function diffHints( deltas: BoxDelta[], delta: number ): string[] {
	const hints: string[] = [];
	const added = deltas.filter( ( entry ) => entry.status === 'added' && ( entry.built?.h ?? 0 ) > 20 );
	const removed = deltas.filter( ( entry ) => entry.status === 'removed' && ( entry.source?.h ?? 0 ) > 20 );
	if ( added.length > 0 ) {
		hints.push( `${ added.length } subtree(s) present in the build but not the source (largest +${ Math.round( added[ 0 ].built?.h ?? 0 ) }px) — usually an inserted/duplicated block.` );
	}
	if ( removed.length > 0 ) {
		hints.push( `${ removed.length } source subtree(s) missing from the build (largest −${ Math.round( removed[ 0 ].source?.h ?? 0 ) }px) — usually a dropped block.` );
	}
	const spacing = deltas.filter( ( entry ) => entry.cause === 'spacing (padding/margin)' );
	if ( spacing.length > 0 ) {
		const worst = spacing[ 0 ];
		hints.push( `Spacing differs on ${ spacing.length } node(s) (e.g. ${ worst.sig } ${ Math.round( worst.dPad ) }px of padding delta) — check the measured box facts.` );
	}
	const reflow = deltas.filter( ( entry ) => entry.cause === 'width only (reflow)' );
	if ( reflow.length > 0 ) {
		hints.push( `Width changed on ${ reflow.length } node(s) — text wraps at a different point, so height follows. Check the container measure / padding.` );
	}
	if ( delta !== 0 && deltas.length > 0 && deltas.every( ( entry ) => entry.status === 'matched' && Math.abs( entry.dH ) <= SUB_PIXEL ) ) {
		hints.push( `Every node is within ${ SUB_PIXEL }px \u2014 the gap is rounding or a late web font, not layout.` );
	}
	return hints;
}

/**
 * Assemble one report from the two flattened trees.
 *
 * Pure, so the whole report shape is testable without a browser.
 */
export function buildBandDiffReport(
	bandId: string,
	viewport: string,
	source: BoxNode[],
	built: BoxNode[],
	options: { limit?: number; truncated?: boolean } = {}
): BandDiffReport {
	const limit = options.limit ?? DEFAULT_CONTRIBUTOR_LIMIT;
	const sourceHeight = source.length > 0 ? source[ 0 ].h : 0;
	// The BAND element's height as captured — this is what the geometry gate
	// compares, so the header line must agree with it.
	const builtHeight = built.length > 0 ? built[ 0 ].h : 0;
	// The two trees have different roots (the rebuild wraps the source section),
	// so align before the keyed walk or every node reads as added/removed.
	const aligned = alignToSourceRoot( source, built );
	const target = aligned ?? built;
	const nodes = diffBoxTrees( source, target );
	const builtContentHeight = target.length > 0 ? target[ 0 ].h : 0;
	const heightDelta = round( builtHeight - sourceHeight );
	const contentDelta = round( builtContentHeight - sourceHeight );
	const wrapperDelta = round( heightDelta - contentDelta );
	const hints = diffHints( nodes, contentDelta );
	if ( ! aligned ) {
		hints.unshift( 'The band root could not be matched in the build — the comparison started from the top of each tree, so read the added/removed lines with care.' );
	}
	// The wrapper's own box is not part of the tree walk (the source has no
	// wrapper), so name it explicitly when it is part of the gap. On a band whose
	// total already matches, this is usually the measured `min-height` floor
	// holding the height while the CONTENT is short — a real fidelity signal the
	// gate cannot see, because the gate measures the band.
	if ( aligned && Math.abs( wrapperDelta ) > 1 ) {
		const direction = wrapperDelta > 0 ? 'adds' : 'removes';
		hints.unshift( `The band WRAPPER ${ direction } ${ Math.abs( Math.round( wrapperDelta ) ) }px beyond its content (band padding and/or the measured \`min-height\` floor); the content inside moved ${ contentDelta > 0 ? '+' : '' }${ Math.round( contentDelta ) }px.` );
	}
	return {
		bandId,
		viewport,
		sourceHeight,
		builtHeight,
		heightDelta,
		sourceContentHeight: sourceHeight,
		builtContentHeight,
		contentDelta,
		wrapperDelta,
		nodeCount: Math.min( source.length, target.length ),
		truncated: options.truncated === true,
		nodes,
		contributors: topContributors( nodes, limit ),
		hotPath: hotPath( nodes, limit ),
		hints,
	};
}

// ---------------------------------------------------------------------------
// The browser pass.
// ---------------------------------------------------------------------------

/**
 * Flatten one band's box tree at the CURRENT viewport.
 *
 * Module scope on purpose: Playwright serialises the function, so it can close
 * over nothing. The band-root ladder is repeated from `collectBandTypeFacts`
 * for the same reason (a source anchor can sit on a `wpb_column`).
 */
function collectBoxTree( input: {
	ids: string[];
	kind: 'built' | 'source';
	selectors?: Record< string, string >;
} ): Record< string, { nodes: BoxNode[]; truncated: boolean } > {
	const pick = ( selector: string ): Element | null => {
		try {
			return document.querySelector( selector );
		} catch {
			return null;
		}
	};
	const COLUMN = /(^|\s)(wpb_column|vc_column_container)(\s|$)/;
	const ROW = /(^|\s)(row|vc_row|wpb_row)(\s|$)|brando[_-](inner[_-])?row/;
	const num = ( value: string ): number => {
		const parsed = parseFloat( value );
		return Number.isFinite( parsed ) ? Math.round( parsed * 10 ) / 10 : 0;
	};
	const signature = ( node: Element ): string =>
		[ node.tagName.toLowerCase(), ...( node.getAttribute( 'class' ) ?? '' ).split( /\s+/ ).filter( Boolean ).sort() ].join( '.' );
	const out: Record< string, { nodes: BoxNode[]; truncated: boolean } > = {};
	// Guards against a pathological tree (a 5000-node band is already unusual).
	// Declared INSIDE this function: Playwright ships only this source into the
	// page, where module scope does not exist.
	const MAX_NODES = 5000;
	const MAX_DEPTH = 24;
	for ( const id of input.ids ) {
		const candidates =
			input.kind === 'built'
				? [ `[data-section-id="${ id }"]` ]
				: [ `.elementor-element-${ id }`, input.selectors?.[ id ] ].filter(
						( value ): value is string => Boolean( value )
				  );
		let root = candidates.map( pick ).find( ( node ): node is Element => Boolean( node ) ) ?? null;
		if ( input.kind === 'source' && root && COLUMN.test( root.getAttribute( 'class' ) ?? '' ) ) {
			let row: Element | null = null;
			for ( let up = root.parentElement; up; up = up.parentElement ) {
				if ( up.tagName === 'SECTION' ) {
					root = up;
					row = null;
					break;
				}
				if ( ! row && ROW.test( up.getAttribute( 'class' ) ?? '' ) ) {
					row = up;
				}
			}
			if ( row ) {
				root = row;
			}
		}
		if ( ! root ) {
			continue;
		}
		const nodes: BoxNode[] = [];
		let truncated = false;
		const walk = ( node: Element, parentKey: string, depth: number ): void => {
			if ( nodes.length >= MAX_NODES ) {
				truncated = true;
				return;
			}
			if ( depth > MAX_DEPTH ) {
				truncated = true;
				return;
			}
			const style = getComputedStyle( node );
			// A hidden subtree has no box and would only add noise.
			if ( style.display === 'none' ) {
				return;
			}
			const sig = signature( node );
			// Ordinal among siblings that share the signature — rebasing key, so
			// moving a node past a DIFFERENT sibling keeps its key.
			let ordinal = 0;
			for ( let sibling = node.previousElementSibling; sibling; sibling = sibling.previousElementSibling ) {
				if ( signature( sibling ) === sig ) {
					ordinal++;
				}
			}
			const key = parentKey ? `${ parentKey }>${ sig }[${ ordinal }]` : `${ sig }[${ ordinal }]`;
			const rect = node.getBoundingClientRect();
			nodes.push( {
				key,
				sig,
				tag: node.tagName.toLowerCase(),
				depth,
				w: num( String( rect.width ) ),
				h: num( String( rect.height ) ),
				pad: [ num( style.paddingTop ), num( style.paddingRight ), num( style.paddingBottom ), num( style.paddingLeft ) ],
				mar: [ num( style.marginTop ), num( style.marginRight ), num( style.marginBottom ), num( style.marginLeft ) ],
			} );
			for ( const child of Array.from( node.children ) ) {
				walk( child, key, depth + 1 );
			}
		};
		walk( root, '', 0 );
		out[ id ] = { nodes, truncated };
	}
	return out;
}

/**
 * Measure the box diff for the given bands at the given viewports.
 *
 * Fail-soft: an unreachable source returns an empty array rather than throwing —
 * `verify` must still report the geometry gate when the live site is down (the
 * hermetic e2e runs with no network at all).
 */
export async function measureBandDiff( options: {
	sourceUrl: string;
	builtUrl: string;
	bands: Array<{ id: string; anchor?: string; viewports: string[] }>;
	viewports: GeometryViewport[];
	limit?: number;
	timeout?: number;
} ): Promise< BandDiffReport[] > {
	const { loadChromium } = await import( './geometry.ts' );
	const chromium = await loadChromium();
	const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
	try {
		const context = await browser.newContext( { deviceScaleFactor: 1 } );
		const sourcePage = await context.newPage();
		const builtPage = await context.newPage();
		const settlePage = async ( page: import('playwright').Page, url: string ): Promise< boolean > => {
			try {
				await page.goto( url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
			} catch {
				return false;
			}
			// The Playwright visual-testing recipe: animations off, fonts ready,
			// every inline image fetched (a lazy image changes a band's height).
			await page.addStyleTag( {
				content:
					'*,*::before,*::after{animation:none!important;transition:none!important;' +
					'scroll-behavior:auto!important}html{scroll-behavior:auto!important}',
			} );
			await page.evaluate( async () => {
				const doc = document as Document & { fonts?: { ready: Promise< unknown > } };
				if ( doc.fonts ) {
					await doc.fonts.ready;
				}
				const height = document.body.scrollHeight;
				for ( let y = 0; y < height; y += window.innerHeight ) {
					window.scrollTo( 0, y );
					await new Promise( ( resolve ) => setTimeout( resolve, 30 ) );
				}
				window.scrollTo( 0, 0 );
				await new Promise( ( resolve ) => setTimeout( resolve, 120 ) );
			} );
			return true;
		};

		const sourceOk = await settlePage( sourcePage, options.sourceUrl );
		if ( ! sourceOk ) {
			return [];
		}
		if ( ! ( await settlePage( builtPage, options.builtUrl ) ) ) {
			return [];
		}

		const reports: BandDiffReport[] = [];
		const selectors = Object.fromEntries(
			options.bands.filter( ( band ) => band.anchor ).map( ( band ) => [ band.id, `#${ band.anchor }` ] )
		);
		for ( const viewport of options.viewports ) {
			const wanted = options.bands
				.filter( ( band ) => band.viewports.includes( viewport.name ) )
				.map( ( band ) => band.id );
			if ( wanted.length === 0 ) {
				continue;
			}
			const size = { width: viewport.width, height: viewport.height };
			await sourcePage.setViewportSize( size );
			await builtPage.setViewportSize( size );
			// A resize re-runs every media query; give both layouts a frame.
			await sourcePage.waitForTimeout( 350 );
			await builtPage.waitForTimeout( 350 );
			const treeArg = { ids: wanted, selectors };
			const sourceTrees = await sourcePage.evaluate( collectBoxTree, {
				...treeArg,
				kind: 'source' as const,
			} );
			const builtTrees = await builtPage.evaluate( collectBoxTree, {
				...treeArg,
				kind: 'built' as const,
			} );
			for ( const id of wanted ) {
				const source = sourceTrees[ id ];
				const built = builtTrees[ id ];
				if ( ! source || ! built || source.nodes.length === 0 || built.nodes.length === 0 ) {
					continue;
				}
				reports.push(
					buildBandDiffReport( id, viewport.name, source.nodes, built.nodes, {
						limit: options.limit,
						truncated: source.truncated || built.truncated,
					} )
				);
			}
		}
		return reports;
	} finally {
		await browser.close();
	}
}
