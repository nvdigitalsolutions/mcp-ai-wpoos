// ---------------------------------------------------------------------------
// bands.ts — vendor-agnostic band (section) detection.
//
// WHY: `analyzeSections` in design.ts understands exactly ONE builder —
// Elementor (`elementor-top-section` / `elementor-column` / `elementor-widget-*`).
// A site on WPBakery, Divi, Bricks, Gutenberg or plain HTML yields ZERO bands,
// so the profile loses its design structure: no `design.sections`, nothing for
// `--geometry` to measure, and the build silently degrades to content-driven.
// (virtuallyjamaica.com, 2026-11-09: WPBakery + Brando → 0 bands.)
//
// HOW: the industry pattern for "support any tech" is a DECLARATIVE fingerprint
// registry rather than a chain of ifs — the Wappalyzer model (regex patterns
// kept as data) applied to DOM structure instead of tech detection. Three
// layers, tried in order, each a pure function of the DOM:
//
//   1. ADAPTERS  — a data table: one entry per builder, declaring its band /
//                  column class patterns and how to read a column width.
//                  Adding a builder is a row in BUILDER_ADAPTERS (or a
//                  profile-level override), never a code change.
//   2. SEMANTIC  — <main>'s element children, then top-level <section> nodes.
//   3. VISUAL    — VIPS-lite (Cai et al., MSR-TR-2003-79): a child becomes a
//                  band boundary when it differs from its parent in background
//                  (colour / image / gradient) or is separated by an <hr>,
//                  filtered by content density so a wrapper is not mistaken
//                  for a band.
//
// This module is intentionally dependency-light: htmlparser2 only (already a
// forge dependency), no CSS engine, no Playwright. Stylesheet-derived facts
// stay in design.ts — here we read inline styles, which is what non-Elementor
// builders actually emit for band backgrounds.
// ---------------------------------------------------------------------------

import { DomHandler, Parser } from 'htmlparser2';

/** The structural subset of htmlparser2's node model we rely on. */
export interface BandNode {
	type: string;
	name?: string;
	attribs?: Record< string, string >;
	children?: BandNode[];
}

export interface BandColumn {
	/** Percentage width (12-column grid converted to %, or an explicit lane). */
	width: number;
}

export interface BandCandidate {
	/** Stable, lowercase-hex id (>= 4 chars) — never collides with a vendor id. */
	id: string;
	/** The source anchor the chrome nav links to (#about, #contact…). */
	anchor?: string;
	/**
	 * The vendor's OWN DOM id when it is a valid CSS identifier but not a nav
	 * anchor — Flatsome's `section_150552065`. `isAnchorId` rejects it (an
	 * underscore is not a nav slug), yet it is the only stable handle the
	 * builder's scoped CSS and the browser expose, so `--geometry`, `--colors`
	 * and the band-colour regions can only address the band through it.
	 * Promoted into `ForgeSection.anchor` by `promoteOutlineBands`.
	 */
	domId?: string;
	bgColor?: string;
	bgImage?: string;
	bgGradient?: string;
	measure?: string;
	paddingX?: string;
	columns?: BandColumn[];
	/** Which layer produced this band: an adapter id, 'semantic' or 'visual'. */
	via: string;
}

/**
 * One presentation utility a builder's markup depends on (Bootstrap/Brando
 * `text-center`, `padding-four-all`, …).
 *
 * The sanitizer keeps the class and the builder's sheet never ships, so the
 * utility is INERT in a rebuild unless the forge re-states it. Values are
 * MEASURED off a live source of that builder, not guessed: a utility whose
 * value is unknown is better left out than approximated.
 */
export interface BuilderUtility {
	/** Declarations applied at every width. */
	base?: string;
	/** Declarations applied inside the template's TABLET range (≤1024px).
	 * Brando/Bootstrap name the scope in the class (`md-`, `sm-`) and often the
	 * value too (`xs-padding-40px-tb`) — a named value is data, not a guess. */
	tablet?: string;
	/** Declarations applied inside the template's MOBILE range (≤767px). */
	mobile?: string;
}

/** Class name → the utility it stands for. */
export type BuilderUtilities = Record< string, BuilderUtility >;

/**
 * One builder's DOM contract. Everything is data — the extractor never branches
 * on a builder name.
 */
export interface BandAdapter {
	/** Adapter id, also recorded on each candidate as `via`. */
	id: string;
	/** Fingerprints proving the builder is present anywhere in the document. */
	detect: RegExp[];
	/** A band (row/layout section) container. */
	band: RegExp[];
	/** Column containers inside a band. */
	column?: RegExp[];
	/** Column width as a percentage, from the column's class list. */
	width?: ( classes: string[] ) => number | undefined;
	/** The band is an opaque widget (a slider, a map): never descend for columns. */
	widget?: boolean;
	/** Infer equal-width columns from the band's element children when no
	 *  column class matches (Bricks/Gutenberg/Oxygen emit flex without widths). */
	inferColumns?: boolean;
	/**
	 * Presentation utilities the builder's markup relies on. Only the ones a
	 * page actually uses are emitted (the analyzer records each band's class
	 * list), so this table can stay a complete vendor dictionary without
	 * costing bytes or leaking another builder's conventions into the build.
	 */
	utilities?: BuilderUtilities;
}

/** n/12 of the grid → percentage. Shared by the 12-column builders. */
const ofTwelve = ( classes: string[], re: RegExp ): number | undefined => {
	for ( const cls of classes ) {
		const match = re.exec( cls );
		if ( match ) {
			const span = Number( match[ 1 ] );
			if ( Number.isFinite( span ) && span > 0 && span <= 12 ) {
				return Math.round( ( span / 12 ) * 10000 ) / 100;
			}
		}
	}
	return undefined;
};

/**
 * Largest declared breakpoint → percentage. Builders emit several at once
 * (`vc_col-sm-12 vc_col-lg-6`, `large-6 medium-6 small-12`) and the desktop
 * value is the one we rebuild, so a naive first-match reads the mobile width.
 * `prefix` builds `<prefix>-<bp>-N`; omit it for plain `<bp>-N` (Flatsome).
 */
const ofBreakpoint = (
	classes: string[],
	order: string[],
	prefix?: string,
	grid = 12
): number | undefined => {
	for ( const bp of order ) {
		const re = prefix ? new RegExp( `^${ prefix }-${ bp }-(\\d+)$` ) : new RegExp( `^${ bp }-(\\d+)$` );
		for ( const cls of classes ) {
			const match = re.exec( cls );
			if ( match ) {
				const span = Number( match[ 1 ] );
				if ( Number.isFinite( span ) && span > 0 && span <= grid ) {
					return Math.round( ( span / grid ) * 10000 ) / 100;
				}
			}
		}
	}
	return undefined;
};

/** a/b fraction → percentage (Divi's `et_pb_column_1_2`). */
const ofFraction = ( classes: string[] ): number | undefined => {
	for ( const cls of classes ) {
		const match = /^et_pb_column_(\d+)_(\d+)$/.exec( cls );
		if ( match ) {
			const a = Number( match[ 1 ] );
			const b = Number( match[ 2 ] );
			if ( a > 0 && b > 0 ) {
				return Math.round( ( a / b ) * 10000 ) / 100;
			}
		}
	}
	return undefined;
};

/** Explicit inline width (a plain % or a flex-basis) → percentage. */
const inlineWidth = ( node: BandNode ): number | undefined => {
	const style = node.attribs?.style ?? '';
	const pct = /(?:^|;)\s*width\s*:\s*([\d.]+)%/.exec( style );
	if ( pct ) {
		return Math.round( Number( pct[ 1 ] ) * 100 ) / 100;
	}
	const basis = /flex-basis\s*:\s*([\d.]+)%/.exec( style );
	if ( basis ) {
		return Math.round( Number( basis[ 1 ] ) * 100 ) / 100;
	}
	return undefined;
};

/**
 * The utilities of `builderId` that this band actually uses — class → the
 * utility's declarations. Absent builder or no measured class list → empty, so a
 * profile recorded before the class capture emits nothing rather than a guess.
 */
export function builderUtilitiesFor(
	builderId: string | undefined,
	classes: string[] | undefined
): Record< string, BuilderUtility > {
	if ( ! builderId || ! classes || classes.length === 0 ) {
		return {};
	}
	const adapter = BUILDER_ADAPTERS.find( ( candidate ) => candidate.id === builderId );
	if ( ! adapter?.utilities ) {
		return {};
	}
	const present = new Set( classes );
	const out: Record< string, BuilderUtility > = {};
	for ( const [ name, utility ] of Object.entries( adapter.utilities ) ) {
		if ( ( utility.base || utility.tablet || utility.mobile ) && present.has( name ) ) {
			out[ name ] = utility;
		}
	}
	return out;
}

/**
 * The registry. Order matters: the first adapter that yields at least one band
 * wins, so put the most specific builders first. `revolution-slider` is a
 * WIDGET adapter — a slider is a band in its own right (usually the hero), and
 * its inner markup is generated at runtime, so it must never be descended.
 */
export const BUILDER_ADAPTERS: BandAdapter[] = [
	{
		id: 'elementor',
		detect: [ /elementor-top-section/, /elementor-element-/ ],
		band: [ /^elementor-top-section$/ ],
		column: [ /^elementor-column$/ ],
		width: ( classes ) => ofTwelve( classes, /^elementor-col-(\d+)$/ ),
	},
	{
		id: 'wpbakery',
		// <div class="vc_row wpb_row vc_row-fluid"> … <div class="vc_col-sm-6 wpb_column vc_column_container">
		// BUT: themes rewrite this. Brando (virtuallyjamaica.com) emits 12
		// `wpb_column`s and ZERO `vc_row`s, so a row-only fingerprint misses the
		// builder entirely — detect on the column classes too.
		detect: [ /\bvc_row\b/, /\bwpb_row\b/, /\bwpb_column\b/, /\bvc_column_container\b/ ],
		band: [ /^vc_row$/, /^wpb_row$/, /^vc_row-fluid$/, /^vc_row-o-/ ],
		column: [ /^wpb_column$/, /^vc_column_container$/ ],
		// Prefer the LARGEST breakpoint: the markup carries sm/md/lg together
		// (`vc_col-sm-12 vc_col-lg-6`), and desktop is the base we rebuild.
		width: ( classes ) => ofBreakpoint( classes, [ 'lg', 'md', 'sm', 'xs' ], 'vc_col' ),
		// The Brando/Bootstrap presentation utilities the markup leans on. Values
		// MEASURED on the live virtuallyjamaica source (2026-11-10): `text-medium`
		// 14px/500/24, `text-small` 11px/500/20, `padding-four-all` 54.39px =
		// 3.4rem at the same value on a 340px and a 680px column (so a fixed rem,
		// not a percentage). Only utilities whose meaning is standard are listed —
		// the numeric margin scale is left out rather than approximated.
		utilities: {
			'text-center': { base: 'text-align:center' },
			'text-left': { base: 'text-align:left' },
			'text-right': { base: 'text-align:right' },
			'text-uppercase': { base: 'text-transform:uppercase' },
			'display-block': { base: 'display:block' },
			'display-inline-block': { base: 'display:inline-block' },
			'pull-right': { base: 'float:right' },
			'pull-left': { base: 'float:left' },
			'vertical-align-middle': { base: 'vertical-align:middle' },
			'alt-font': { base: 'font-family:var(--forge-font-display)' },
			'text-medium': { base: 'font-size:14px;font-weight:500;line-height:24px' },
			'text-small': { base: 'font-size:11px;font-weight:500;line-height:20px' },
			'font-weight-300': { base: 'font-weight:300' },
			'font-weight-400': { base: 'font-weight:400' },
			'font-weight-500': { base: 'font-weight:500' },
			'font-weight-600': { base: 'font-weight:600' },
			'font-weight-700': { base: 'font-weight:700' },
			'padding-four-all': { base: 'padding:3.4rem' },
			'no-padding': { base: 'padding:0' },
			'no-padding-lr': { base: 'padding-left:0;padding-right:0' },
			'no-padding-tb': { base: 'padding-top:0;padding-bottom:0' },
			'no-margin': { base: 'margin:0' },
			'no-border': { base: 'border:0' },
			// The responsive variants. Brando scopes these in the class name and often
			// states the value: `xs-padding-40px-tb` is 40px top/bottom below 767px,
			// `sm-padding-70px-tb` is 70px below 991px. Only the ones whose NAME is the
			// value are mapped — `xs-padding-thirteen-tb` uses the numeric scale and
			// stays out. Without these, a `padding-four-all` panel keeps its 3.4rem at
			// 390px and the band renders taller than its source (virtuallyjamaica's
			// services band: 1517 vs 1281, the remaining geometry failure).
			'xs-padding-40px-tb': { mobile: 'padding-top:40px;padding-bottom:40px' },
			'sm-padding-70px-tb': { tablet: 'padding-top:70px;padding-bottom:70px' },
			'md-text-center': { tablet: 'text-align:center' },
			'sm-text-center': { tablet: 'text-align:center' },
			'xs-text-center': { mobile: 'text-align:center' },
		},
	},
	{
		id: 'divi',
		// <div class="et_pb_section …"> <div class="et_pb_row"> <div class="et_pb_column et_pb_column_1_2">
		detect: [ /\bet_pb_section\b/, /\bet_pb_row\b/ ],
		band: [ /^et_pb_section$/ ],
		column: [ /^et_pb_column$/ ],
		width: ofFraction,
	},
	{
		id: 'beaver',
		detect: [ /\bfl-row\b/, /\bfl-col\b/ ],
		band: [ /^fl-row$/ ],
		column: [ /^fl-col$/ ],
		width: ( classes ) => ofTwelve( classes, /^fl-col-(?:xs|sm|md|lg)-(\d+)$/ ),
		inferColumns: true,
	},
	{
		id: 'bricks',
		detect: [ /\bbrxe-section\b/, /\bbrxe-container\b/ ],
		band: [ /^brxe-section$/, /^brxe-container$/ ],
		column: [ /^brxe-block$/, /^brxe-div$/ ],
		inferColumns: true,
	},
	{
		id: 'gutenberg',
		// wp-block-group / wp-block-cover are bands; wp-block-columns holds columns.
		detect: [ /\bwp-block-group\b/, /\bwp-block-columns\b/, /\bis-layout-constrained\b/ ],
		band: [ /^wp-block-group$/, /^wp-block-cover$/, /^wp-block-media-text$/ ],
		column: [ /^wp-block-column$/ ],
		inferColumns: true,
	},
	{
		id: 'oxygen',
		detect: [ /\bct-section\b/, /\bct-div-block\b/ ],
		band: [ /^ct-section$/ ],
		column: [ /^ct-div-block$/, /^ct-new-columns$/ ],
		inferColumns: true,
	},
	{
		id: 'flatsome',
		// Flatsome UX Builder: <div class="section …"><div class="row"><div class="col large-6 medium-6 small-12">.
		// The Detect set is what makes this safe: `section`/`row`/`col` are generic
		// class names, so the adapter only runs when a Flatsome-specific marker is
		// present (the ux-builder body/shortcode markers or section-content).
		detect: [ /\bux-builder\b/, /\bsection-content\b/, /\bflatsome\b/ ],
		band: [ /^section$/ ],
		column: [ /^col$/ ],
		width: ( classes ) => ofBreakpoint( classes, [ 'large', 'medium', 'small' ], undefined, 12 ),
	},
	{
		id: 'revolution-slider',
		// Slider Revolution: <div class="rev_slider_wrapper"><div id="rev_slider_1" class="rev_slider fullwidthabanner">,
		// and the v6 runtime's .rs-module wrapper. A slider IS the band (usually
		// the hero); its slides are generated at runtime, so do not descend.
		detect: [ /\brev_slider\b/, /\brevslider-initialised\b/, /\brs-module\b/ ],
		band: [ /^rev_slider_wrapper$/, /^rev_slider$/, /^rs-module$/ ],
		widget: true,
	},
];

const classList = ( node: BandNode ): string[] =>
	( node.attribs?.class ?? '' ).split( /\s+/ ).filter( Boolean );

const childElements = ( node: BandNode ): BandNode[] =>
	( node.children ?? [] ).filter( ( child ) => child.type === 'tag' );

const textLength = ( node: BandNode ): number => {
	let total = 0;
	const stack: BandNode[] = [ node ];
	while ( stack.length > 0 ) {
		const current = stack.pop() as BandNode;
		if ( current.type === 'text' ) {
			total += ( current as unknown as { data?: string } ).data?.trim().length ?? 0;
		}
		for ( const child of current.children ?? [] ) {
			stack.push( child );
		}
	}
	return total;
};

const hasMedia = ( node: BandNode ): boolean => {
	const stack: BandNode[] = [ node ];
	while ( stack.length > 0 ) {
		const current = stack.pop() as BandNode;
		if ( current.type === 'tag' ) {
			if ( current.name === 'img' || current.name === 'video' || current.name === 'picture' ) {
				return true;
			}
			const style = current.attribs?.style ?? '';
			if ( /background(?:-image)?\s*:\s*[^;]*(?:url\(|gradient)/i.test( style ) ) {
				return true;
			}
		}
		for ( const child of current.children ?? [] ) {
			stack.push( child );
		}
	}
	return false;
};

/** Inline background facts — what non-Elementor builders actually emit. */
export function inlineBackground( node: BandNode ): Pick< BandCandidate, 'bgColor' | 'bgImage' | 'bgGradient' > {
	const style = node.attribs?.style ?? '';
	const out: Pick< BandCandidate, 'bgColor' | 'bgImage' | 'bgGradient' > = {};

	const image = /background-image\s*:\s*([^;]+)/i.exec( style )?.[ 1 ]?.trim();
	if ( image ) {
		if ( /gradient\(/i.test( image ) ) {
			out.bgGradient = image;
		} else {
			const url = /url\(\s*['"]?([^'")]+)/i.exec( image )?.[ 1 ];
			if ( url ) {
				out.bgImage = url;
			}
		}
	}

	const shorthand = /background\s*:\s*([^;]+)/i.exec( style )?.[ 1 ]?.trim();
	if ( shorthand && ! out.bgGradient ) {
		if ( /gradient\(/i.test( shorthand ) ) {
			out.bgGradient = shorthand;
		} else {
			// `background: url(x.jpg) no-repeat` — the shorthand often carries the
			// image and no colour at all (Brando's full-screen bands). Without
			// this the band reads as "no background" and drops out of the visual
			// band set entirely (virtuallyjamaica's #contact, 2026-11-09).
			const shorthandUrl = /url\(\s*['"]?([^'")]+)/i.exec( shorthand )?.[ 1 ];
			if ( shorthandUrl && ! out.bgImage ) {
				out.bgImage = shorthandUrl;
			}
			const colour = /(#[0-9a-f]{3,8}|rgba?\([^)]*\))/i.exec( shorthand )?.[ 1 ];
			if ( colour ) {
				out.bgColor = colour;
			}
		}
	}

	const colour = /background-color\s*:\s*([^;]+)/i.exec( style )?.[ 1 ]?.trim();
	if ( colour && colour !== 'transparent' ) {
		out.bgColor = colour;
	}

	return out;
}

/** A nav-target anchor: a plain lowercase slug, never an internal/auto id. */
const isAnchorId = ( id: string | undefined ): id is string =>
	Boolean( id ) && /^[a-z][a-z0-9-]{1,31}$/.test( id as string ) && ! /^(?:elementor|aux-|df_|nvoos|rev_slider)/.test( id as string );

/** Ids every theme gives a site-level landmark — never a band handle. */
const RESERVED_DOM_IDS = /^(?:main|content|page|wrapper|primary|header|footer|top|sidebar|site|container|app)$/i;

/**
 * The band node's own `id` when it is a valid CSS identifier but NOT a nav
 * anchor (see `BandCandidate.domId`). A nav slug is handled by `anchorOf` /
 * `anchorWithin`; this only picks up the ids those deliberately reject.
 */
const domIdOf = ( node: BandNode ): string | undefined => {
	const id = node.attribs?.id?.trim();
	if ( ! id || RESERVED_DOM_IDS.test( id ) ) {
		return undefined;
	}
	if ( ! /^[A-Za-z][A-Za-z0-9_:-]{1,63}$/.test( id ) ) {
		return undefined;
	}
	return isAnchorId( id ) ? undefined : id;
};

const anchorOf = ( node: BandNode ): string | undefined => {
	const id = node.attribs?.id;
	return id && isAnchorId( id ) ? id : undefined;
};

/**
 * The band's nav anchor, PROMOTING a column-carried one to the band.
 *
 * Builder sources frequently put the anchor on the inner column rather than the
 * row: virtuallyjamaica's `#wifi` sits on a `<div class="wpb_column …">` inside
 * the band, so reading the band node's own id alone lost it — and `analyze
 * --design` then dropped it from the profile while the nav still linked
 * `#wifi` (2026-11-11). The BOX pass has always promoted a column anchor (see
 * reference/band-geometry.md §3); the band extractor has to agree with it or the
 * two disagree about which sections are addressable.
 *
 * ONLY a column is promoted. A band's other ids are panel internals
 * (`accordion-one-link-1`) — they pass the slug test but must never become nav
 * targets, so the column class is the gate.
 */
const COLUMN_CLASS = /(^|\s)(wpb_column|vc_column_container)(\s|$)/;
const anchorWithin = ( node: BandNode ): string | undefined => {
	const walk = ( current: BandNode ): string | undefined => {
		for ( const child of childElements( current ) ) {
			const childAnchor = anchorOf( child );
			if ( childAnchor && COLUMN_CLASS.test( child.attribs?.class ?? '' ) ) {
				return childAnchor;
			}
			const nested = walk( child );
			if ( nested ) {
				return nested;
			}
		}
		return undefined;
	};
	return walk( node );
};

/**
 * djb2 → 7 lowercase hex chars: stable across runs, never a vendor id.
 *
 * The seed is STRUCTURAL (adapter, tag, classes, the band's ordinal in document
 * order) and deliberately excludes the band's text: live sites carry dynamic
 * copy — a countdown timer, a rotated testimonial — and a text-seeded id moved
 * by 4 bytes between two analyses of the same page, orphaning every
 * `bandFacts`/`geometry`/`colors` key and every operator pin keyed by it
 * (monicarajiv.com, 2026-11-11). Flatsome even re-mints its own section ids
 * per render, so structure is the only stable identity.
 */
export const stableBandId = ( seed: string ): string => {
	let hash = 5381;
	for ( let i = 0; i < seed.length; i += 1 ) {
		hash = ( ( hash << 5 ) + hash + seed.charCodeAt( i ) ) | 0;
	}
	return ( hash >>> 0 ).toString( 16 ).padStart( 7, '0' ).slice( -7 );
};

const columnsOf = ( band: BandNode, adapter: BandAdapter ): BandColumn[] | undefined => {
	if ( adapter.widget ) {
		return undefined;
	}

	const widths = ( matched: { node: BandNode; width?: number }[] ): BandColumn[] => {
		if ( matched.every( ( entry ) => entry.width !== undefined ) ) {
			return matched.map( ( entry ) => ( { width: entry.width as number } ) );
		}
		// Column containers without a readable width (Bricks/Gutenberg flex):
		// split evenly across the columns the builder actually emitted.
		const width = Math.round( ( 100 / matched.length ) * 100 ) / 100;
		return matched.map( () => ( { width } ) );
	};

	// Document order matters (a 2/3 + 1/3 band reads left-to-right), so this
	// walk is recursive rather than a LIFO stack.
	const collect = ( candidate: BandAdapter ): { node: BandNode; width?: number }[] => {
		const matched: { node: BandNode; width?: number }[] = [];
		const walk = ( node: BandNode ): void => {
			for ( const child of childElements( node ) ) {
				const isColumn = candidate.column?.some( ( re ) =>
					classList( child ).some( ( cls ) => re.test( cls ) )
				);
				if ( isColumn ) {
					matched.push( {
						node: child,
						width: candidate.width?.( classList( child ) ) ?? inlineWidth( child ),
					} );
					continue; // nested columns belong to their own band level
				}
				walk( child );
			}
		};
		walk( band );
		return matched;
	};

	if ( adapter.column ) {
		const own = collect( adapter );
		if ( own.length >= 2 ) {
			return widths( own );
		}
	}

	// Column detection is ORTHOGONAL to band detection: a theme can rewrite the
	// row wrapper out of existence while keeping the builder's column classes.
	// Brando does exactly that (12 `wpb_column`s, zero `vc_row`), so bands found
	// by the semantic/visual layers still get measured columns here.
	for ( const other of BUILDER_ADAPTERS ) {
		if ( ! other.column || other.widget || other.id === adapter.id ) {
			continue;
		}
		const found = collect( other );
		if ( found.length >= 2 ) {
			return widths( found );
		}
	}

	// No column containers at all: infer an even split only where the adapter
	// says the builder lays out columns structurally.
	if ( adapter.inferColumns ) {
		const children = childElements( band ).filter(
			( child ) => textLength( child ) > 0 || hasMedia( child )
		);
		if ( children.length >= 2 && children.length <= 6 ) {
			const width = Math.round( ( 100 / children.length ) * 100 ) / 100;
			return children.map( () => ( { width } ) );
		}
	}
	return undefined;
};

const isBandNode = ( node: BandNode, adapter: BandAdapter ): boolean =>
	adapter.band.some( ( re ) => classList( node ).some( ( cls ) => re.test( cls ) ) );

/** Depth-first collection of band nodes, skipping nested bands of the same kind. */
const collectBands = ( root: BandNode, adapter: BandAdapter, out: BandNode[] = [] ): BandNode[] => {
	for ( const child of childElements( root ) ) {
		if ( isBandNode( child, adapter ) ) {
			out.push( child );
			if ( ! adapter.widget ) {
				continue; // a band's nested bands are its content, not new bands
			}
		}
		collectBands( child, adapter, out );
	}
	return out;
};

const docMentions = ( html: string, adapter: BandAdapter ): boolean =>
	adapter.detect.some( ( re ) => re.test( html ) );

const toCandidate = ( node: BandNode, adapter: BandAdapter, index = 0 ): BandCandidate | undefined => {
	const text = textLength( node );
	const media = hasMedia( node );
	const bg = inlineBackground( node );

	// Content density (VIPS' Doc/block filter, simplified): an empty wrapper is
	// not a band. Keep anything with text, media or its own background.
	if ( text < 24 && ! media && ! bg.bgImage && ! bg.bgGradient && ! bg.bgColor ) {
		return undefined;
	}

	const classes = classList( node ).join( '.' );
	const candidate: BandCandidate = {
		// Structure, not content — see `stableBandId`.
		id: stableBandId( `${ adapter.id }:${ node.name ?? '' }:${ classes }:${ index }` ),
		via: adapter.id,
		...bg,
	};

	const anchor = anchorOf( node ) ?? anchorWithin( node );
	if ( anchor ) {
		candidate.anchor = anchor;
	}
	const domId = domIdOf( node );
	if ( domId ) {
		candidate.domId = domId;
	}

	const columns = columnsOf( node, adapter );
	if ( columns ) {
		candidate.columns = columns;
	}

	const paddingX = inlinePaddingX( node );
	if ( paddingX ) {
		candidate.paddingX = paddingX;
	}
	const measure = inlineMeasure( node );
	if ( measure ) {
		candidate.measure = measure;
	}

	return candidate;
};

const inlinePaddingX = ( node: BandNode ): string | undefined => {
	const style = node.attribs?.style ?? '';
	const padding = /padding\s*:\s*([^;]+)/i.exec( style )?.[ 1 ]?.trim();
	if ( ! padding ) {
		return undefined;
	}
	const parts = padding.split( /\s+/ );
	// shorthand: top [right bottom left] — the horizontal value
	return parts.length >= 2 ? parts[ 1 ] : undefined;
};

const inlineMeasure = ( node: BandNode ): string | undefined => {
	const style = node.attribs?.style ?? '';
	return /max-width\s*:\s*([^;]+)/i.exec( style )?.[ 1 ]?.trim();
};

/**
 * Layer 2 + 3: semantic landmarks, then a VIPS-lite visual pass.
 * Exported so the plan's follow-up phases (title styles, measure) can build on
 * the same boundaries.
 */
export function semanticBands( root: BandNode ): BandCandidate[] {
	const candidates: BandCandidate[] = [];
	const synthetic: BandAdapter = {
		id: 'semantic',
		detect: [],
		band: [ /^section$/, /^header$/, /^footer$/, /^article$/ ],
	};

	const main = findFirst( root, ( node ) => node.name === 'main' );
	const scope = main ?? root;

	// <main>'s direct element children — the most reliable signal, but only when
	// there are at least two: a lone wrapper is not a band list, and claiming it
	// here would stop the VIPS-lite pass from ever running.
	const fromMain = childElements( scope )
		.map( ( child, index ) => toCandidate( child, synthetic, index ) )
		.filter( ( candidate ): candidate is BandCandidate => Boolean( candidate ) );
	if ( fromMain.length >= 2 ) {
		return fromMain;
	}

	// Otherwise any top-level landmark elements.
	const landmarks = collectBands( root, synthetic )
		.map( ( node, index ) => toCandidate( node, synthetic, index ) )
		.filter( ( candidate ): candidate is BandCandidate => Boolean( candidate ) );
	return landmarks;
}

/** VIPS-lite: siblings that visually differ from the container are bands. */
export function visualBands( root: BandNode ): BandCandidate[] {
	const synthetic: BandAdapter = { id: 'visual', detect: [], band: [ /./ ] };

	// Walk the whole tree and keep the LARGEST set of sibling elements that each
	// carry their own background and content. Top-down, so on a tie the
	// shallowest (outermost) container wins — that is the band list, not a
	// fragment of it.
	let best: BandNode[] = [];
	const visit = ( node: BandNode ): void => {
		const withBackground = childElements( node ).filter( ( child ) => {
			const bg = inlineBackground( child );
			const paints = Boolean( bg.bgColor || bg.bgImage || bg.bgGradient );
			return paints && ( textLength( child ) > 24 || hasMedia( child ) );
		} );
		if ( withBackground.length > best.length ) {
			best = withBackground;
		}
		for ( const child of childElements( node ) ) {
			visit( child );
		}
	};
	visit( root );

	return best
		.map( ( node, index ) => toCandidate( node, synthetic, index ) )
		.filter( ( candidate ): candidate is BandCandidate => Boolean( candidate ) );
}

function findFirst( node: BandNode, predicate: ( node: BandNode ) => boolean ): BandNode | undefined {
	for ( const child of childElements( node ) ) {
		if ( predicate( child ) ) {
			return child;
		}
		const found = findFirst( child, predicate );
		if ( found ) {
			return found;
		}
	}
	return undefined;
}

export interface ExtractBandsOptions {
	/** Extra adapters, tried before the built-ins (profile-level extensibility). */
	extra?: BandAdapter[];
	/** Restrict to specific built-in adapter ids, in that order. */
	only?: string[];
	/** Skip the semantic/visual fallbacks (strict "builder only" mode). */
	strict?: boolean;
}

/**
 * Extract bands from a document. Tries adapters in declaration order, then the
 * semantic landmark pass, then VIPS-lite. Always returns a stable array (empty
 * when the page genuinely has no distinguishable bands).
 */
export function extractBandsFromHtml(
	html: string,
	options: ExtractBandsOptions = {}
): { bands: BandCandidate[]; adapter?: string; builder?: string } {
	const handler = new DomHandler( undefined, {} );
	const parser = new Parser( handler, { lowerCaseTags: true, lowerCaseAttributeNames: true } );
	parser.write( html );
	parser.end();

	const root = handler.root as unknown as BandNode;

	const adapters = [ ...( options.extra ?? [] ), ...BUILDER_ADAPTERS ].filter(
		( adapter ) => ! options.only || options.only.includes( adapter.id )
	);

	// The first adapter whose fingerprint matches is the site's BUILDER, even
	// when it yields no bands — Brando strips the row wrapper, so WPBakery is
	// still the builder while the bands come from the visual layer.
	let builder: string | undefined;

	for ( const adapter of adapters ) {
		if ( ! docMentions( html, adapter ) ) {
			continue;
		}
		builder = builder ?? adapter.id;
		const nodes = collectBands( root, adapter );
		const bands = nodes
			.map( ( node, index ) => toCandidate( node, adapter, index ) )
			.filter( ( candidate ): candidate is BandCandidate => Boolean( candidate ) );
		if ( bands.length > 0 ) {
			return { bands, adapter: adapter.id, builder };
		}
	}

	if ( options.strict ) {
		return { bands: [], builder };
	}

	const semantic = semanticBands( root );
	if ( semantic.length > 0 ) {
		return { bands: semantic, adapter: 'semantic', builder };
	}

	return { bands: visualBands( root ), adapter: 'visual', builder };
}
