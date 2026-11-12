/**
 * Interaction-state measurement — hover, focus, focus-visible and active.
 *
 * Why this exists: a rebuilt band is the source's own sanitized markup, but the
 * vendor stylesheet never ships, so every STATE the builder expressed in CSS is
 * lost. The result is the flatness users notice first: a card that does not
 * lift, a button that does not darken, a link that does not underline, a nav item
 * that has no focus ring — all of it gone while the resting page looks right.
 * Stylesheets are not a faithful source (the rules live behind builder classes,
 * `:hover` groups and theme variables), so this is a MEASUREMENT: force each
 * state in a real browser and diff the computed style.
 *
 * Mechanism: the Chrome DevTools Protocol's `CSS.forcePseudoState` — the same
 * API the DevTools `:hov` panel drives. Forcing one state at a time and reading
 * `getComputedStyle` is exactly how a developer inspects it by hand, and it
 * needs no synthetic pointer events, no hover geometry and no flake.
 *
 * State set (industry convention, not guesswork):
 *   - `hover`        pointer over the control (`@media (hover: hover)` only —
 *                    a touch device must not keep a sticky hover).
 *   - `focus`        element focus; mostly form fields.
 *   - `focusVisible` keyboard focus. `:focus` is NOT a substitute:
 *                    `:focus-visible` is the modality-aware ring browsers have
 *                    used since 2022 (MDN), and it is what WCAG 2.4.7 asks for.
 *   - `active`       pressed. Forced as `hover + active`, because a source that
 *                    writes `a:hover:active` never matches `:active` alone.
 *
 * Accessibility baseline (WCAG 2.2): Focus Appearance (2.4.13) requires a
 * ≥2 CSS px indicator with a ≥3:1 change of contrast, Non-text Contrast (1.4.11)
 * a ≥3:1 adjacent contrast. A source usually has NEITHER, so the template also
 * ships a `:focus-visible` baseline (see global.css); this module records what
 * the source actually does and never "improves" a measured state.
 *
 * What is NOT captured, deliberately:
 *   - `:visited` — unreadable from script by design (privacy).
 *   - pseudo-elements (`::before`/`::after` hover glyph swaps) — the rebuild
 *     does not own those nodes; a marker that matters (an accordion `+`) is
 *     drawn by the template instead.
 *   - layout properties (width/height/margin) — a state that reflows the page is
 *     a source bug; re-stating it would re-create the shift.
 */

import type { Page } from 'playwright';
import type { ForgeInteractionChildFact, ForgeInteractionFact } from './types.ts';
import { loadChromium } from './geometry.ts';

/** The band heights and type are measured at this viewport; states match it. */
const INTERACTION_VIEWPORT = { width: 1440, height: 900 } as const;

/**
 * How many distinct signatures are probed per band.
 *
 * A signature is "tag + class set", so a band's 40 links collapse to a handful
 * of groups. The cap keeps a pathological band (a table of 300 uniquely-classed
 * links) from turning `analyze` into a minute of CDP round-trips.
 */
const MAX_SIGNATURES_PER_BAND = 16;

/**
 * Computed properties that express a state. Anything outside this list is noise:
 * a `:hover` rule that changes `font-family` or `display` is rare and usually a
 * bug, and re-stating resolved inherited values (`font-size`) would freeze
 * responsive type at the measured viewport.
 */
const PROBED_PROPERTIES = [
	'color',
	'backgroundColor',
	'backgroundImage',
	'borderTopWidth',
	'borderTopStyle',
	'borderTopColor',
	'borderRightWidth',
	'borderRightStyle',
	'borderRightColor',
	'borderBottomWidth',
	'borderBottomStyle',
	'borderBottomColor',
	'borderLeftWidth',
	'borderLeftStyle',
	'borderLeftColor',
	'borderRadius',
	'boxShadow',
	'outlineColor',
	'outlineStyle',
	'outlineWidth',
	'outlineOffset',
	'textDecorationLine',
	'textDecorationColor',
	'textDecorationThickness',
	'opacity',
	'transform',
	'filter',
	'letterSpacing',
	'fontWeight',
	'textTransform',
] as const;

/**
 * Read at REST only, never diffed across states.
 *
 * A state that changes `display` or `font-size` is rare and usually a layout
 * bug, so neither belongs in the state comparison. Their RESTING value is
 * still part of how a control looks, and both were missing from the emitted
 * rules: a builder button is `inline-block` (virtuallyjamaica's `.btn`), and
 * rendered `inline` its 2px border and vertical padding reserve no space, so
 * the box overlapped the neighbouring line instead of boxing the label.
 */
const REST_LAYOUT_PROPERTIES = [ 'display', 'fontSize' ] as const;

/** camelCase → kebab-case, for emitting a measured property as CSS. */
function kebab( property: string ): string {
	return property.replace( /[A-Z]/g, ( character ) => `-${ character.toLowerCase() }` );
}

/**
 * The states probed, and the pseudo-classes each one forces.
 *
 * `active` carries `hover` because the source's press rule is almost always
 * written `:hover:active`; forcing `:active` alone reads the resting value and
 * records no delta at all.
 */
const PROBE_STATES: Array< { key: 'hover' | 'focus' | 'focusVisible' | 'active'; force: string[] } > = [
	{ key: 'hover', force: [ 'hover' ] },
	{ key: 'focus', force: [ 'focus' ] },
	{ key: 'focusVisible', force: [ 'focus', 'focus-visible' ] },
	{ key: 'active', force: [ 'hover', 'active' ] },
];

export interface MeasureInteractionsOptions {
	/** The live source URL (or the served built site, with `kind: 'built'`). */
	url: string;
	/** The band ids to attribute the controls to. */
	ids: string[];
	/** Band id → the source selector that resolves the band (`#anchor`). */
	selectors?: Record< string, string >;
	/**
	 * Which side is being measured. The band-root ladder differs:
	 * `source` tries `.elementor-element-<id>` before the anchor (an Elementor
	 * band often has no anchor at all — jayainc: 5 of 9), `built` uses the
	 * `[data-section-id]` the scaffold emits. Defaults to `source`.
	 */
	kind?: 'source' | 'built';
	timeout?: number;
	/** Override for tests; defaults to MAX_SIGNATURES_PER_BAND. */
	maxSignatures?: number;
}

/**
 * One candidate control, as the browser sees it. `probe` is the temporary
 * attribute value that maps this group back to a live element for the CDP pass.
 */
interface ProbeCandidate {
	bandId: string;
	probe: string;
	tag: string;
	classes: string[];
	type?: string;
	count: number;
	/** A real control (link/button/field) — its rest paint is re-stated even
	 * when it declares no state. A content BLOCK only earns a fact from a real
	 * state delta (see the push condition). */
	control: boolean;
	/** Can receive keyboard focus in reality. A forced `:focus-visible` on a
	 * plain `div` paints Chromium's UA ring, which is an artefact of the probe,
	 * not a source style — non-focusable candidates drop it. */
	focusable: boolean;
	/**
	 * Direct element children to diff while the control is forced (the overlay
	 * pattern). `index` is the child's position in `element.children`.
	 */
	children: Array<{ index: number; tag: string; classes: string[] }>;
}

/**
 * How many direct children per control are probed.
 *
 * Every builder overlay is the FIRST child of the anchor and a button rarely has
 * more than a wrapper plus a label, so four is generous. The cap keeps a
 * pathological control (a card with a dozen wrappers) from multiplying the pass.
 */
const MAX_CHILDREN_PER_CONTROL = 4;

/** The control's border box, in px, as the browser laid it out. */
type Box = { w: number; h: number };

/** Rest declarations the state deltas are measured against. */
type RestStyles = Record< string, string >;

/** Where a child sits, and what its `::after` paints. */
interface ChildRest {
	index: number;
	props: RestStyles;
	transition: string;
	hasText: boolean;
	box: Box;
	/** `position`, `top`, `left`, `width`, `height`, `transform` (computed). */
	layout: Record< string, string >;
	/** The `::after` slot an overlay fill usually lives in. */
	pseudo: { content: string; backgroundColor: string; backgroundImage: string; width: string; height: string };
}

/**
 * Collect one representative per (tag + class-set + input type) signature per
 * band, and tag each representative for the CDP lookup.
 *
 * Runs IN the page (Playwright serialises it), so it must not close over module
 * scope. Returns the signatures; the elements themselves are addressed by the
 * `data-forge-interaction-probe` attribute.
 */
function collectCandidates( input: {
	ids: string[];
	selectors?: Record< string, string >;
	kind: 'source' | 'built';
	max: number;
	maxChildren: number;
} ): ProbeCandidate[] {
	const INTERACTIVE =
		'a[href], button, summary, [role="button"], input:not([type="hidden"]), select, textarea';
	/**
	 * A CONTENT BLOCK that can carry a state of its own.
	 *
	 * Builder feature boxes, pricing columns, portfolio tiles and image cards
	 * express their hover on a plain `div`/`li`/`figure`: virtuallyjamaica's
	 * `.service-sub` turns solid black under the pointer, and the
	 * anchor/button-only candidate set missed it entirely, so the rebuilt card
	 * stayed flat. The predicate is STRUCTURAL — a block-level box of
	 * non-trivial size that holds real content — never a class-name guess.
	 * Blocks are probed only after every control (controls are the priority
	 * within the per-band signature budget), and a block whose forced states
	 * change nothing emits no fact.
	 */
	const isContentBlock = ( element: Element, band: Element ): boolean => {
		if ( ! ( element instanceof HTMLElement ) || element === band ) {
			return false;
		}
		if ( element.matches( INTERACTIVE ) ) {
			return false;
		}
		const tag = element.tagName.toLowerCase();
		if ( tag === 'html' || tag === 'body' || tag === 'main' || tag === 'nav' || tag === 'header' || tag === 'footer' ) {
			return false;
		}
		const style = getComputedStyle( element );
		const isBlockLevel =
			style.display.includes( 'block' ) || style.display.includes( 'flex' ) || style.display.includes( 'grid' );
		if ( ! isBlockLevel ) {
			return false;
		}
		const rect = element.getBoundingClientRect();
		if ( rect.width < 80 || rect.height < 40 ) {
			return false;
		}
		const text = ( element.textContent ?? '' ).trim();
		const hasMedia = element.querySelector( 'img, svg, picture, video' ) !== null;
		return text.length >= 10 || hasMedia;
	};
	const out: ProbeCandidate[] = [];
	let counter = 0;

	const pick = ( selector: string ): Element | null => {
		try {
			return document.querySelector( selector );
		} catch {
			return null;
		}
	};

	/**
	 * The band root — the SAME ladder `collectBandTypeFacts` walks, so a band
	 * measured for typography is measured for interaction states too. An Elementor
	 * band carries its id as a class and frequently has no anchor at all.
	 */
	const bandRoot = ( id: string ): Element | null => {
		const candidates =
			input.kind === 'built'
				? [ input.selectors?.[ id ], `[data-section-id="${ id }"]` ]
				: [ `.elementor-element-${ id }`, input.selectors?.[ id ] ];
		return (
			candidates
				.filter( ( selector ): selector is string => Boolean( selector ) )
				.map( pick )
				.find( ( node ): node is Element => Boolean( node ) ) ?? null
		);
	};

	const usable = ( element: Element ): boolean => {
		if ( ! ( element instanceof HTMLElement ) ) {
			return false;
		}
		const rect = element.getBoundingClientRect();
		if ( rect.width < 1 || rect.height < 1 ) {
			return false;
		}
		const style = getComputedStyle( element );
		return style.display !== 'none' && style.visibility !== 'hidden' && Number( style.opacity ) > 0;
	};

	for ( const bandId of input.ids ) {
		const band = bandRoot( bandId );
		if ( ! band ) {
			continue;
		}
		const groups = new Map< string, { element: Element; count: number; control: boolean } >();
		const collect = ( element: Element, control: boolean ): void => {
			if ( ! usable( element ) ) {
				return;
			}
			const tag = element.tagName.toLowerCase();
			const classes = [ ...element.classList ].sort();
			const type = tag === 'input' ? element.getAttribute( 'type' ) || 'text' : '';
			const signature = `${ tag }|${ type }|${ classes.join( '.' ) }`;
			const existing = groups.get( signature );
			if ( existing ) {
				existing.count += 1;
				existing.control = existing.control || control;
			} else {
				groups.set( signature, { element, count: 1, control } );
			}
		};
		// Controls first so they always win the per-band budget; then the content
		// blocks (a feature box, a card, a tile) that carry their own hover.
		for ( const element of band.querySelectorAll( INTERACTIVE ) ) {
			collect( element, true );
		}
		for ( const element of band.querySelectorAll( 'div, section, article, li, figure, a' ) ) {
			if ( isContentBlock( element, band ) ) {
				collect( element, false );
			}
		}
		const ordered = [ ...groups.entries() ]
			.sort( ( a, b ) =>
				Number( b[ 1 ].control ) - Number( a[ 1 ].control ) ||
				b[ 1 ].count - a[ 1 ].count ||
				a[ 0 ].localeCompare( b[ 0 ] )
			)
			.slice( 0, input.max );
		for ( const [ signature, group ] of ordered ) {
			const [ tag, type ] = signature.split( '|' );
			counter += 1;
			const probe = String( counter );
			group.element.setAttribute( 'data-forge-interaction-probe', probe );
			const focusable = group.element.matches(
				'a[href], button, input:not([type="hidden"]), select, textarea, summary, [tabindex], [contenteditable="true"]'
			);
			const children: ProbeCandidate[ 'children' ] = [];
			[ ...group.element.children ].slice( 0, input.maxChildren ).forEach( ( child, index ) => {
				// A nested control is its own probe; its states are not the parent's.
				if ( child.matches( INTERACTIVE ) ) {
					return;
				}
				children.push( {
					index,
					tag: child.tagName.toLowerCase(),
					classes: [ ...child.classList ].sort(),
				} );
			} );
			out.push( {
				bandId,
				probe,
				tag,
				classes: [ ...group.element.classList ].sort(),
				...( type ? { type } : {} ),
				count: group.count,
				control: group.control,
				focusable,
				children,
			} );
		}
	}
	return out;
}

/** One probed control at rest, plus its children. */
interface RestEntry {
	props: RestStyles;
	cursor: string;
	transition: string;
	box: Box;
	children: ChildRest[];
}

/**
 * Read the rest computed values for every probed element, plus its children.
 *
 * Runs IN the page (Playwright serialises it), so it must not close over module
 * scope; every constant it needs arrives in `input`.
 */
function readRestStyles( input: { targets: Array< { probe: string; children: number[] } >; properties: string[] } ): Record<
	string,
	RestEntry
> {
	const kebab = ( property: string ): string =>
		property.replace( /[A-Z]/g, ( character ) => `-${ character.toLowerCase() }` );
	const readProps = ( element: Element ): RestStyles => {
		const style = getComputedStyle( element );
		const props: RestStyles = {};
		for ( const property of input.properties ) {
			props[ property ] = style.getPropertyValue( kebab( property ) );
		}
		return props;
	};
	const boxOf = ( element: Element ): Box => {
		// LAYOUT box, not the client rect: the overlay rests at `scale(0)`, so its
		// getBoundingClientRect() is 0×0 and every ratio built from it collapsed.
		const layout = element as Element & { offsetWidth?: number; offsetHeight?: number };
		if ( typeof layout.offsetWidth === 'number' && layout.offsetWidth > 0 ) {
			return { w: layout.offsetWidth, h: layout.offsetHeight ?? layout.offsetWidth };
		}
		const rect = element.getBoundingClientRect();
		return { w: rect.width, h: rect.height };
	};
	const out: Record< string, RestEntry > = {};
	for ( const target of input.targets ) {
		const element = document.querySelector( `[data-forge-interaction-probe="${ target.probe }"]` );
		if ( ! ( element instanceof HTMLElement ) ) {
			continue;
		}
		const style = getComputedStyle( element );
		const children: ChildRest[] = [];
		for ( const index of target.children ) {
			const child = element.children[ index ];
			if ( ! child ) {
				continue;
			}
			const childStyle = getComputedStyle( child );
			const after = getComputedStyle( child, '::after' );
			children.push( {
				index,
				props: readProps( child ),
				transition: childStyle.transition,
				hasText: ( child.textContent ?? '' ).trim().length > 0,
				box: boxOf( child ),
				layout: {
					position: childStyle.position,
					top: childStyle.top,
					left: childStyle.left,
					width: childStyle.width,
					height: childStyle.height,
					transform: childStyle.transform,
				},
				pseudo: {
					content: after.content,
					backgroundColor: after.backgroundColor,
					backgroundImage: after.backgroundImage,
					width: after.width,
					height: after.height,
				},
			} );
		}
		out[ target.probe ] = {
			props: readProps( element ),
			cursor: style.cursor,
			transition: style.transition,
			box: boxOf( element ),
			children,
		};
	}
	return out;
}

/** Read one element's current computed values (after a forced state). */
function readStateStyles( input: {
	probe: string;
	children: number[];
	properties: string[];
} ): { props: RestStyles; children: Array< { index: number; props: RestStyles } > } | null {
	const element = document.querySelector( `[data-forge-interaction-probe="${ input.probe }"]` );
	if ( ! ( element instanceof HTMLElement ) ) {
		return null;
	}
	const readProps = ( node: Element ): RestStyles => {
		const style = getComputedStyle( node );
		const props: RestStyles = {};
		for ( const property of input.properties ) {
			props[ property ] = style.getPropertyValue(
				property.replace( /[A-Z]/g, ( character ) => `-${ character.toLowerCase() }` )
			);
		}
		return props;
	};
	const children: Array< { index: number; props: RestStyles } > = [];
	for ( const index of input.children ) {
		const child = element.children[ index ];
		if ( child ) {
			children.push( { index, props: readProps( child ) } );
		}
	}
	return { props: readProps( element ), children };
}

/** Drop every temporary probe attribute. */
function clearProbes(): void {
	for ( const element of document.querySelectorAll( '[data-forge-interaction-probe]' ) ) {
		element.removeAttribute( 'data-forge-interaction-probe' );
	}
}

/**
 * Rest-state paint that a state delta is relative to.
 *
 * A measured hover delta is only meaningful ON TOP of the source's resting
 * look: virtuallyjamaica's CTA rests on a white pill with dark ink and hovers
 * to transparent + white ink. Emitting the hover half alone onto a rebuild whose
 * button lost its white pill (the vendor sheet is gone) produced white text on
 * a light band. These are the declarations that make a control LOOK like the
 * control, and they are measured rather than inferred because the look lives in
 * builder classes (`btn-small-white-background`) the rebuild never ships.
 *
 * `padding` is deliberately absent: the measured box facts already carry it.
 * `font-size` is read at rest (see `REST_LAYOUT_PROPERTIES`) but only emitted for
 * a control that paints — see `restDeclarations`.
 */
const REST_PAINT_PROPERTIES = [
	'color',
	'backgroundColor',
	'backgroundImage',
	'borderRadius',
	'textDecorationLine',
	'textDecorationColor',
	'fontWeight',
	'letterSpacing',
	'textTransform',
	'display',
	'fontSize',
] as const;

/** Border sides, emitted as a shorthand only when the side is actually drawn. */
const BORDER_SIDES = [ 'Top', 'Right', 'Bottom', 'Left' ] as const;

/**
 * Build the rest-state declaration set for a control: the source's own paint,
 * pointer affordance and (separately) its easing.
 *
 * Skips the no-ops a computed style always reports — a transparent background,
 * a `none` gradient, a zero-width `none` border, the default `400` weight — so
 * the emitted CSS stays a description of the control rather than of the
 * browser's defaults.
 */
export function restDeclarations( rest: RestStyles, cursor: string ): string {
	const declarations: string[] = [];
	if ( cursor && cursor !== 'auto' ) {
		declarations.push( `cursor:${ cursor }` );
	}
	// A control that PAINTS (a fill or a drawn border) is a real button, and its
	// own type size is part of the look. A bare text link is not: pinning its
	// size here would freeze responsive copy at the measured viewport, which is
	// why `font-size` is absent from the state probes entirely.
	const painted = [ 'Top', 'Right', 'Bottom', 'Left' ].some( ( side ) => {
		const width = rest[ `border${ side }Width` ];
		const style = rest[ `border${ side }Style` ];
		return Boolean( width && width !== '0px' && style && style !== 'none' );
	} ) || Boolean( rest.backgroundColor && rest.backgroundColor !== 'rgba(0, 0, 0, 0)' );
	for ( const property of REST_PAINT_PROPERTIES ) {
		const value = rest[ property ];
		if ( ! value ) {
			continue;
		}
		if ( property === 'backgroundColor' && value === 'rgba(0, 0, 0, 0)' ) {
			continue;
		}
		if ( property === 'backgroundImage' && ( value === 'none' || value.includes( 'url(' ) ) ) {
			continue;
		}
		// NOT skipped when `none`: the UA default for `a[href]` is UNDERLINE, so a
		// source button that rests at `none` needs the declaration to stop the
		// rebuild drawing one (virtuallyjamaica's `.btn` rests on no underline and
		// the rebuild showed a line through both CTAs).
		if ( property === 'textDecorationColor' && ( rest.textDecorationLine ?? 'none' ) === 'none' ) {
			continue;
		}
		if ( property === 'fontWeight' && value === '400' ) {
			continue;
		}
		if ( property === 'letterSpacing' && value === 'normal' ) {
			continue;
		}
		if ( property === 'textTransform' && value === 'none' ) {
			continue;
		}
		if ( property === 'borderRadius' && value === '0px' ) {
			continue;
		}
		// `inline` is the UA default for an anchor, so stating it changes nothing
		// and a button that computes `inline-block` must keep its padding box.
		if ( property === 'display' && value === 'inline' ) {
			continue;
		}
		if ( property === 'fontSize' && ! painted ) {
			continue;
		}
		declarations.push( `${ kebab( property ) }:${ value }` );
	}
	for ( const side of BORDER_SIDES ) {
		const width = rest[ `border${ side }Width` ];
		const style = rest[ `border${ side }Style` ];
		const color = rest[ `border${ side }Color` ];
		if ( ! width || width === '0px' || ! style || style === 'none' ) {
			continue;
		}
		declarations.push( `border-${ side.toLowerCase() }:${ width } ${ style } ${ color }` );
	}
	return declarations.join( ';' );
}

/**
 * Whether a child can show its own state at all.
 *
 * A child that paints nothing itself and has no text only becomes visible
 * through a PSEUDO-ELEMENT fill (`.aux-overlay::after { background: … }`). When
 * that pseudo-element is reproducible the child is kept; when it is not, the
 * transform would animate a zero-size span forever, so the child is skipped and
 * counted instead of silently faked.
 */
export function childPaints( props: RestStyles, hasText: boolean, pseudo = '' ): boolean {
	if ( hasText || pseudo ) {
		return true;
	}
	if ( props.backgroundColor && props.backgroundColor !== 'rgba(0, 0, 0, 0)' ) {
		return true;
	}
	if ( props.backgroundImage && props.backgroundImage !== 'none' ) {
		return true;
	}
	if ( props.boxShadow && props.boxShadow !== 'none' ) {
		return true;
	}
	return BORDER_SIDES.some( ( side ) => {
		const width = props[ `border${ side }Width` ];
		const style = props[ `border${ side }Style` ];
		return Boolean( width && width !== '0px' && style && style !== 'none' );
	} );
}

/** px → a percentage of a reference length, rounded to one decimal. */
function percentOf( value: number, reference: number ): string {
	if ( ! Number.isFinite( value ) || reference <= 0 ) {
		return '0%';
	}
	return `${ Math.round( ( value / reference ) * 1000 ) / 10 }%`;
}

/**
 * Is a computed `transform` the identity?
 *
 * `none` and an identity `matrix()` describe "no movement". A scroll-in
 * animation that finished between the rest read and the state read leaves
 * exactly this value, and re-stating it as a hover effect is noise (jayainc's
 * `.aux-animated` sections and `.elementor-container` each produced a phantom
 * hover this way).
 */
export function isIdentityTransform( value: string ): boolean {
	if ( ! value || value === 'none' ) {
		return true;
	}
	const matrix = /^matrix\(([^)]*)\)$/.exec( value );
	if ( ! matrix ) {
		return false;
	}
	const parts = matrix[ 1 ].split( ',' ).map( ( part ) => Number.parseFloat( part.trim() ) );
	const [ a, b, c, d, e, f ] = parts;
	return parts.length === 6 && a === 1 && b === 0 && c === 0 && d === 1 && e === 0 && f === 0;
}

/**
 * `matrix(a,b,c,d,e,f)` → `translate(tx%, ty%) scale(sx, sy)`.
 *
 * An overlay's measured transform is a px translate of half its own box, which
 * only lands correctly at the width it was measured at. Re-expressing the
 * translate as a percentage of the CHILD's box is exactly equivalent and
 * survives every breakpoint. A rotated/skewed matrix is kept verbatim — the
 * decomposition would be a lie.
 */
export function relativeTransform( matrix: string, box: Box ): string {
	const match = /matrix\(\s*(-?[\d.]+),\s*(-?[\d.]+),\s*(-?[\d.]+),\s*(-?[\d.]+),\s*(-?[\d.]+),\s*(-?[\d.]+)\s*\)/.exec(
		matrix
	);
	if ( ! match || box.w <= 0 || box.h <= 0 ) {
		return matrix;
	}
	const [ a, b, c, d, e, f ] = match.slice( 1 ).map( Number );
	if ( b !== 0 || c !== 0 ) {
		return matrix;
	}
	return `translate(${ percentOf( e, box.w ) }, ${ percentOf( f, box.h ) }) scale(${ a }, ${ d })`;
}

/**
 * The child's REST layout, expressed relative to the control it fills.
 *
 * The source's computed geometry is px (`width:234.672px; top:26.5px`), measured
 * at one viewport. Percentages of the control's box are the same layout for the
 * overlay pattern (absolute child, centred, sized as a share of the button) and
 * stay correct when the button reflows.
 */
export function childRestDeclarations(
	layout: Record< string, string >,
	box: Box,
	controlBox: Box
): string {
	const declarations: string[] = [];
	if ( layout.position && layout.position !== 'static' ) {
		declarations.push( `position:${ layout.position }` );
		for ( const side of [ 'top', 'left' ] as const ) {
			const value = Number.parseFloat( layout[ side ] ?? '' );
			const reference = side === 'top' ? controlBox.h : controlBox.w;
			if ( Number.isFinite( value ) && reference > 0 ) {
				declarations.push( `${ side }:${ percentOf( value, reference ) }` );
			}
		}
		if ( box.w > 0 && controlBox.w > 0 ) {
			declarations.push( `width:${ percentOf( box.w, controlBox.w ) }` );
		}
		if ( box.h > 0 && controlBox.h > 0 ) {
			declarations.push( `height:${ percentOf( box.h, controlBox.h ) }` );
		}
	}
	if ( layout.transform && layout.transform !== 'none' ) {
		declarations.push( `transform:${ relativeTransform( layout.transform, box ) }` );
	}
	return declarations.join( ';' );
}

/**
 * The `::after` slot an overlay fill usually lives in (`content:""`, background,
 * size as a share of the child). Returns `''` when there is no reproducible
 * pseudo-element (no content, or a non-literal one such as `attr()`).
 */
export function childPseudoDeclarations(
	pseudo: { content: string; backgroundColor: string; backgroundImage: string; width: string; height: string },
	box: Box
): string {
	const content = ( pseudo.content ?? '' ).trim();
	if ( ! content || content === 'none' || content === 'normal' ) {
		return '';
	}
	if ( ! /^(["']).*\1$/.test( content ) ) {
		return '';
	}
	const declarations = [ `content:${ content }` ];
	if ( pseudo.backgroundColor && pseudo.backgroundColor !== 'rgba(0, 0, 0, 0)' ) {
		declarations.push( `background-color:${ pseudo.backgroundColor }` );
	}
	if ( pseudo.backgroundImage && pseudo.backgroundImage !== 'none' && ! pseudo.backgroundImage.includes( 'url(' ) ) {
		declarations.push( `background-image:${ pseudo.backgroundImage }` );
	}
	const width = Number.parseFloat( pseudo.width ?? '' );
	if ( Number.isFinite( width ) && box.w > 0 ) {
		declarations.push( `width:${ percentOf( width, box.w ) }` );
	}
	const height = Number.parseFloat( pseudo.height ?? '' );
	if ( Number.isFinite( height ) && box.h > 0 ) {
		declarations.push( `height:${ percentOf( height, box.h ) }` );
	}
	return declarations.join( ';' );
}

/**
 * Turn one state's computed values into a declaration string, keeping only what
 * actually CHANGED from rest.
 *
 * Values are the browser's own computed strings (`rgb(…)`, `0px`, `none`), so
 * they are safe to re-state verbatim. `background-image` is dropped when it
 * points at a URL: a hover state that hotlinks a remote asset is exactly what
 * the media gate exists to prevent, and gradients are kept.
 */
export function stateDeclarations( rest: RestStyles, state: RestStyles ): string {
	const declarations: string[] = [];
	// An outline is only visible when it has a width AND a style. Comparing the
	// two sides of that pair is what separates "the source draws a ring here"
	// from "the source changes a colour nobody can see".
	const outlineDrawn = ( map: RestStyles ): boolean =>
		Boolean( map.outlineStyle && map.outlineStyle !== 'none' && map.outlineWidth && map.outlineWidth !== '0px' );
	const stateOutline = outlineDrawn( state );
	for ( const property of PROBED_PROPERTIES ) {
		const after = state[ property ];
		if ( after === undefined || after === rest[ property ] ) {
			continue;
		}
		if ( property === 'backgroundImage' && after.includes( 'url(' ) ) {
			continue;
		}
		// An IDENTITY transform or full opacity is "the appear animation finished",
		// not a state effect. A builder's scroll-in animation moves `opacity`/`transform`
		// between the two reads (their resting value was mid-animation), which painted
		// phantom hover rules onto jayainc's `.aux-animated` sections and
		// `.elementor-container`. A real state effect changes something else as well.
		if ( property === 'transform' && isIdentityTransform( after ) ) {
			continue;
		}
		if ( property === 'opacity' && Number( after ) === 1 ) {
			continue;
		}
		// A state that REMOVES (or does not draw) the focus ring is not an effect
		// to reproduce — it is the classic focus-visible failure (WCAG F78 /
		// SC 2.4.7). Keeping it would let a hovered control lose the keyboard
		// ring the baseline grants, and a colour on an undrawn ring is invisible.
		if ( property.startsWith( 'outline' ) && ! stateOutline ) {
			continue;
		}
		// A decoration COLOUR with no decoration LINE paints nothing on screen.
		if ( property === 'textDecorationColor' ) {
			const line = state.textDecorationLine ?? rest.textDecorationLine;
			if ( ! line || line === 'none' ) {
				continue;
			}
		}
		declarations.push( `${ kebab( property ) }:${ after }` );
	}
	return declarations.join( ';' );
}

/**
 * Build the CSS selector for a measured signature.
 *
 * `[class~="…"]` (whole-token match) instead of `.…` because a builder class may
 * contain characters an identifier cannot (Brando's `brando_column_1526-2-31`),
 * and the attribute form needs no escaping. Tokens are sorted, so the emitted
 * selector is stable across runs.
 */
export function interactionSelector( fact: Pick< ForgeInteractionFact, 'tag' | 'classes' | 'type' > ): string {
	const parts = [ fact.tag ];
	if ( fact.type && ( fact.tag === 'input' || fact.tag === 'button' ) ) {
		parts.push( `[type="${ fact.type.replace( /["\\]/g, '' ) }"]` );
	}
	for ( const token of fact.classes ) {
		if ( ! token || /["\\\]]/.test( token ) ) {
			continue;
		}
		parts.push( `[class~="${ token }"]` );
	}
	return parts.join( '' );
}

/**
 * The selector for a control's CHILD, emitted after `> `.
 *
 * A classled child gets the same tag + whole-token form as a control. An
 * unclassed one falls back to `:nth-child(n)` — a bare `> span` would also catch
 * a sibling the source never styled, and the child's index is stable because the
 * parent's subtree is not mutated between the rest read and the state pass.
 */
export function interactionChildSelector( child: {
	tag: string;
	classes: string[];
	index: number;
} ): string {
	const classes = child.classes.filter( ( token ) => token && !/["\\\]]/.test( token ) );
	if ( classes.length === 0 ) {
		return `:nth-child(${ child.index + 1 })`;
	}
	return [ child.tag, ...classes.map( ( token ) => `[class~="${ token }"]` ) ].join( '' );
}

/** Wait for fonts + a paint before measuring. */
async function settle( page: Page ): Promise< void > {
	await page.evaluate( async () => {
		const doc = document as Document & { fonts?: { ready: Promise< unknown > } };
		if ( doc.fonts ) {
			await doc.fonts.ready;
		}
	} );
	await page.waitForTimeout( 300 );
}

/**
 * Measure the interaction states of every band's controls.
 *
 * FAIL-SOFT by design: `analyze` already treats the browser passes as optional,
 * and a band with no interactive element simply records nothing.
 */
export async function measureInteractions(
	options: MeasureInteractionsOptions
): Promise< { facts: Record< string, ForgeInteractionFact[] >; skippedChildStates: number } > {
	const chromium = await loadChromium();
	const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
	try {
		const context = await browser.newContext( {
			deviceScaleFactor: 1,
			reducedMotion: 'no-preference',
		} );
		const page = await context.newPage();
		await page.setViewportSize( { ...INTERACTION_VIEWPORT } );
		await page.goto( options.url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
		await settle( page );

		const candidates = await page.evaluate( collectCandidates, {
			ids: options.ids,
			selectors: options.selectors,
			kind: options.kind ?? 'source',
			max: options.maxSignatures ?? MAX_SIGNATURES_PER_BAND,
			maxChildren: MAX_CHILDREN_PER_CONTROL,
		} );
		if ( candidates.length === 0 ) {
			return { facts: {}, skippedChildStates: 0 };
		}

		// Kill ANIMATIONS before the rest read. A builder's scroll-in/appear animation
		// (`aux-appear-watch-animation`, Elementor's motion effects) keeps moving
		// `opacity`/`transform` while the probes run, so the rest read and the state
		// read disagree and every animated section emits a phantom hover. Transitions
		// stay alive here: the source's easing is itself a fact.
		await page.addStyleTag( {
			content: '*,*::before,*::after{animation-duration:0s!important;animation-delay:0s!important}',
		} );
		// Rest state INCLUDING `transition` — the transition killer below is what
		// makes the forced states read their target value instead of a frame of
		// interpolation, but it also erases the property we want to record.
		const rest = await page.evaluate( readRestStyles, {
			targets: candidates.map( ( candidate ) => ( {
				probe: candidate.probe,
				children: candidate.children.map( ( child ) => child.index ),
			} ) ),
			properties: [ ...PROBED_PROPERTIES, ...REST_LAYOUT_PROPERTIES ],
		} );

		await page.addStyleTag( {
			content:
				'*,*::before,*::after{transition-duration:0s!important;transition-delay:0s!important}',
		} );

		const cdp = await context.newCDPSession( page );
		await cdp.send( 'DOM.enable' );
		await cdp.send( 'CSS.enable' );
		const { root } = await cdp.send( 'DOM.getDocument', { depth: 0 } );

		const facts: Record< string, ForgeInteractionFact[] > = {};
		let skippedChildStates = 0;
		try {
			for ( const candidate of candidates ) {
				const restEntry = rest[ candidate.probe ];
				if ( ! restEntry ) {
					continue;
				}
				const { nodeId } = await cdp.send( 'DOM.querySelector', {
					nodeId: root.nodeId,
					selector: `[data-forge-interaction-probe="${ candidate.probe }"]`,
				} );
				if ( ! nodeId ) {
					continue;
				}
				const fact: ForgeInteractionFact = {
					tag: candidate.tag,
					classes: candidate.classes,
					...( candidate.type ? { type: candidate.type } : {} ),
					count: candidate.count,
					// Negative only: a non-focusable card has no keyboard state, so the
					// verify gate must not demand a focus ring the source never had.
					...( candidate.focusable ? {} : { focusable: false } ),
				};
				// Re-state the source's own resting paint, pointer affordance and
				// easing. A cursor is not a state and a transition is the timing
				// BETWEEN states; the rest paint is what the state deltas are relative
				// to (without it a hover delta paints onto a different-looking shell).
				const base = restDeclarations( restEntry.props, restEntry.cursor );
				if ( base ) {
					fact.base = base;
				}
				if ( restEntry.transition && restEntry.transition !== 'all 0s ease 0s' ) {
					fact.transition = restEntry.transition;
				}
				const childFacts = new Map< string, ForgeInteractionChildFact >();
				for ( const state of PROBE_STATES ) {
					await cdp.send( 'CSS.forcePseudoState', { nodeId, forcedPseudoClasses: state.force } );
					const styles = await page.evaluate( readStateStyles, {
						probe: candidate.probe,
						children: candidate.children.map( ( child ) => child.index ),
						properties: [ ...PROBED_PROPERTIES ],
					} );
					if ( ! styles ) {
						continue;
					}
					const declarations = stateDeclarations( restEntry.props, styles.props );
					if ( declarations ) {
						fact[ state.key ] = declarations;
					}
					// The overlay pattern: the control itself reports nothing while a child
					// repaints. Recorded against the child, keyed by its selector, with the
					// rest layout/paint the state needs in order to be visible.
					for ( const childState of styles.children ) {
						const meta = candidate.children.find( ( child ) => child.index === childState.index );
						const childRest = restEntry.children.find( ( child ) => child.index === childState.index );
						if ( ! meta || ! childRest ) {
							continue;
						}
						const rawDeclarations = stateDeclarations( childRest.props, childState.props );
						if ( ! rawDeclarations ) {
							continue;
						}
						// A px transform of half the child's own box becomes the relative
						// form the rebuild can apply at every width.
						const childDeclarations = rawDeclarations.replace(
							/transform:(matrix\([^)]*\))/g,
							( _match, matrix: string ) => `transform:${ relativeTransform( matrix, childRest.box ) }`
						);
						const pseudo = childPseudoDeclarations( childRest.pseudo, childRest.box );
						// Not reproducible: the child paints nothing and has no pseudo-element
						// fill to reproduce — count it instead of emitting a no-op.
						if ( ! childPaints( childRest.props, childRest.hasText, pseudo ) ) {
							skippedChildStates += 1;
							continue;
						}
						const selector = interactionChildSelector( meta );
						const entry = childFacts.get( selector ) ?? { selector };
						if ( childRest.transition && childRest.transition !== 'all 0s ease 0s' ) {
							entry.transition = childRest.transition;
						}
						const base = childRestDeclarations( childRest.layout, childRest.box, restEntry.box );
						if ( base ) {
							entry.base = base;
						}
						if ( pseudo ) {
							entry.pseudo = pseudo;
						}
						entry[ state.key ] = childDeclarations;
						childFacts.set( selector, entry );
					}
				}
				await cdp.send( 'CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] } );
				// A forced `:focus-visible` on a non-focusable element paints Chromium's
				// default ring — an artefact of the probe (every wrapper div measured it),
				// not a source style. A real control always keeps its focus states.
				if ( ! candidate.focusable ) {
					delete fact.focus;
					delete fact.focusVisible;
				}
				// A source styles `:focus` and `:focus-visible` identically far more
				// often than not (browsers already apply `:focus-visible` to text
				// fields on click), so emitting both only doubles the CSS. Keep the
				// keyboard-aware one.
				if ( fact.focus && fact.focus === fact.focusVisible ) {
					delete fact.focus;
				}
				if ( childFacts.size > 0 ) {
					fact.children = [ ...childFacts.values() ].sort( ( a, b ) =>
						a.selector.localeCompare( b.selector )
					);
				}
				// A CONTROL's rest paint is a fact on its own (a builder button is
				// `inline-block` with a border; without `display` the box collapses).
				// A content BLOCK earns a fact only from a REAL state delta — its rest
				// paint and inherited transition describe nothing worth re-stating.
				const hasState = Boolean( fact.hover || fact.focus || fact.focusVisible || fact.active || fact.children );
				if ( hasState || ( candidate.control && ( fact.base || fact.transition ) ) ) {
					( facts[ candidate.bandId ] ??= [] ).push( fact );
				}
			}
		} finally {
			await page.evaluate( clearProbes ).catch( () => undefined );
		}

		// A stable order makes the generated file diffable across runs.
		for ( const list of Object.values( facts ) ) {
			list.sort( ( a, b ) => interactionSelector( a ).localeCompare( interactionSelector( b ) ) );
		}
		return { facts, skippedChildStates };
	} finally {
		await browser.close();
	}
}

/** The states a rebuild is expected to keep. */
export const COMPARED_INTERACTION_STATES = [ 'hover', 'focus', 'focusVisible', 'active' ] as const;

/** One compared state name. */
export type InteractionStateKey = ( typeof COMPARED_INTERACTION_STATES )[ number ];

/**
 * Count the states a band's facts carry — used by `analyze`'s summary line.
 */
export function countInteractionStates( facts: Record< string, ForgeInteractionFact[] > ): number {
	let total = 0;
	for ( const list of Object.values( facts ) ) {
		for ( const fact of list ) {
			for ( const key of COMPARED_INTERACTION_STATES ) {
				if ( fact[ key ] ) {
					total += 1;
				}
			}
			for ( const child of fact.children ?? [] ) {
				for ( const key of COMPARED_INTERACTION_STATES ) {
					if ( child[ key ] ) {
						total += 1;
					}
				}
			}
		}
	}
	return total;
}

/** A per-band expectation: the profile's `bandFacts[id].interactions`, keyed by id. */
export type InteractionBaseline = Record< string, ForgeInteractionFact[] >;

/**
 * What `verify` found wrong with the rebuilt controls.
 *
 * `state-missing` is the parity failure: the source declared the state, the
 * rebuild emits it, so an empty delta means the emitted rule lost the cascade
 * (a vendor selector with higher specificity, a missing class).
 *
 * `control-missing` is a WARN about a state KIND the band lost entirely: the
 * expected signature is absent because the band was re-rendered through a design
 * component, or because the source generated the control with JavaScript (owl
 * dots, sliders). A signature change that still exposes the state (the template
 * form's own focus rule) is NOT a deviation — this gate compares behaviour, not
 * markup.
 *
 * `focus-indicator-missing` is the accessibility failure: WCAG 2.4.7 requires a
 * visible indicator on every keyboard-focusable control, and the template's
 * baseline is what supplies it on a source that has none.
 */
export interface InteractionDeviation {
	sectionId: string;
	selector: string;
	kind: 'control-missing' | 'state-missing' | 'focus-indicator-missing';
	/** The state that went missing (`state-missing` only). */
	state?: InteractionStateKey;
	/** The control's CHILD that carried the state, when it was an overlay. */
	child?: string;
	severity: 'fail' | 'warn';
}

/**
 * The signature two facts must share to be compared: tag + input type + the
 * sorted class set. Hashed builder classes are stable per element, so this
 * identifies the same control on both sides.
 */
export function interactionKey(
	fact: Pick< ForgeInteractionFact, 'tag' | 'classes' | 'type' >
): string {
	return `${ fact.tag }|${ fact.type ?? '' }|${ [ ...fact.classes ].sort().join( '.' ) }`;
}

/**
 * Compare the source's measured states with the rebuilt page's.
 *
 * Presence-only on purpose: comparing declaration STRINGS breaks as soon as the
 * rebuild legitimately differs (the template adds a focus ring the source never
 * had, a band's ink is pinned). What must hold is that a control the source
 * animated still animates, and that every control is focusable-by-sight.
 */
export function diffInteractionStates(
	expected: InteractionBaseline,
	built: InteractionBaseline
): InteractionDeviation[] {
	const deviations: InteractionDeviation[] = [];
	for ( const [ sectionId, facts ] of Object.entries( expected ) ) {
		const builtFacts = built[ sectionId ] ?? [];
		const builtByKey = new Map(
			builtFacts.map( ( fact ) => [ interactionKey( fact ), fact ] )
		);
		// Which state kinds the rebuilt band still exposes anywhere. A band whose
		// markup was replaced by a design component keeps its behaviour on
		// DIFFERENT signatures (the template form's `.ds-input:focus`), so the
		// comparison follows the state, not the original class list.
		const builtStates = new Set< InteractionStateKey >();
		for ( const fact of builtFacts ) {
			for ( const state of COMPARED_INTERACTION_STATES ) {
				if ( fact[ state ] ) {
					builtStates.add( state );
				}
			}
			for ( const child of fact.children ?? [] ) {
				for ( const state of COMPARED_INTERACTION_STATES ) {
					if ( child[ state ] ) {
						builtStates.add( state );
					}
				}
			}
		}
		for ( const fact of facts ) {
			const declared = COMPARED_INTERACTION_STATES.filter( ( state ) => Boolean( fact[ state ] ) );
			const childContracts = ( fact.children ?? [] )
				.map( ( child ) => ( {
					child,
					states: COMPARED_INTERACTION_STATES.filter( ( state ) => Boolean( child[ state ] ) ),
				} ) )
				.filter( ( contract ) => contract.states.length > 0 );
			// A control the source gives no state to (an owl dot, a bare link) is
			// not part of the interaction contract — nothing to verify.
			if ( declared.length === 0 && childContracts.length === 0 ) {
				continue;
			}
			const selector = interactionSelector( fact );
			const match = builtByKey.get( interactionKey( fact ) );
			if ( ! match ) {
				for ( const state of declared ) {
					if ( builtStates.has( state ) ) {
						continue;
					}
					deviations.push( {
						sectionId,
						selector,
						kind: 'control-missing',
						state,
						severity: 'warn',
					} );
				}
				for ( const contract of childContracts ) {
					for ( const state of contract.states ) {
						if ( builtStates.has( state ) ) {
							continue;
						}
						deviations.push( {
							sectionId,
							selector,
							kind: 'control-missing',
							state,
							child: contract.child.selector,
							severity: 'warn',
						} );
					}
				}
				continue;
			}
			for ( const state of declared ) {
				if ( ! match[ state ] ) {
					deviations.push( {
						sectionId,
						selector,
						kind: 'state-missing',
						state,
						severity: 'fail',
					} );
				}
			}
			// The overlay half: the control exists, so a state its child drew and
			// no longer draws is a real loss (the child is not re-rendered markup —
			// it is inside the control the rebuild kept).
			for ( const contract of childContracts ) {
				const builtChild = ( match.children ?? [] ).find(
					( child ) => child.selector === contract.child.selector
				);
				for ( const state of contract.states ) {
					if ( builtChild?.[ state ] ) {
						continue;
					}
					deviations.push( {
						sectionId,
						selector,
						kind: 'state-missing',
						state,
						child: contract.child.selector,
						severity: 'fail',
					} );
				}
			}
		}
	}
	// The keyboard indicator is checked on EVERY rebuilt control, not only the
	// ones the source styled: the template's baseline is the only thing standing
	// between a source with no focus styles and an unusable keyboard page. A
	// measured non-focusable content block (a feature card whose hover the source
	// styled) is exempt: it has no keyboard state to indicate.
	for ( const [ sectionId, facts ] of Object.entries( built ) ) {
		for ( const fact of facts ) {
			if ( fact.focusable === false ) {
				continue;
			}
			if ( ! fact.focus && ! fact.focusVisible ) {
				deviations.push( {
					sectionId,
					selector: interactionSelector( fact ),
					kind: 'focus-indicator-missing',
					severity: 'fail',
				} );
			}
		}
	}
	const rank = ( deviation: InteractionDeviation ): number =>
		deviation.severity === 'fail' ? 0 : 1;
	return deviations.sort(
		( a, b ) =>
			rank( a ) - rank( b ) ||
			a.sectionId.localeCompare( b.sectionId ) ||
			a.selector.localeCompare( b.selector ) ||
			( a.state ?? '' ).localeCompare( b.state ?? '' )
	);
}

/**
 * The human line for one deviation — shared by the CLI and the tests so the
 * wording cannot drift from the finding.
 */
export function describeInteractionDeviation( deviation: InteractionDeviation ): string {
	const child = deviation.child ? ` > ${ deviation.child }` : '';
	switch ( deviation.kind ) {
		case 'state-missing':
			return `band ${ deviation.sectionId }: "${ deviation.state }" state lost on ${ deviation.selector }${ child }`;
		case 'control-missing':
			return `band ${ deviation.sectionId }: "${ deviation.state }" states lost with a control the build re-rendered or the source generated in JS \u2014 ${ deviation.selector }${ child }`;
		default:
			return `band ${ deviation.sectionId }: no keyboard focus indicator \u2014 ${ deviation.selector }`;
	}
}
