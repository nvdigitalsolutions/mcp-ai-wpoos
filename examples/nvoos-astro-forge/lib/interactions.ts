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
import type { ForgeInteractionFact } from './types.ts';
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
	/** The live source URL. */
	url: string;
	/** The band ids to attribute the controls to. */
	ids: string[];
	/** Band id → the source selector that resolves the band (`#anchor`). */
	selectors?: Record< string, string >;
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
}

/** Rest declarations the state deltas are measured against. */
type RestStyles = Record< string, string >;

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
	max: number;
} ): ProbeCandidate[] {
	const INTERACTIVE =
		'a[href], button, summary, [role="button"], input:not([type="hidden"]), select, textarea';
	const out: ProbeCandidate[] = [];
	let counter = 0;

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
		const selector = input.selectors?.[ bandId ];
		const band = selector ? document.querySelector( selector ) : null;
		if ( ! band ) {
			continue;
		}
		const groups = new Map< string, { element: Element; count: number } >();
		for ( const element of band.querySelectorAll( INTERACTIVE ) ) {
			if ( ! usable( element ) ) {
				continue;
			}
			const tag = element.tagName.toLowerCase();
			const classes = [ ...element.classList ].sort();
			const type = tag === 'input' ? element.getAttribute( 'type' ) || 'text' : '';
			const signature = `${ tag }|${ type }|${ classes.join( '.' ) }`;
			const existing = groups.get( signature );
			if ( existing ) {
				existing.count += 1;
			} else {
				groups.set( signature, { element, count: 1 } );
			}
		}
		const ordered = [ ...groups.entries() ]
			.sort( ( a, b ) => b[ 1 ].count - a[ 1 ].count || a[ 0 ].localeCompare( b[ 0 ] ) )
			.slice( 0, input.max );
		for ( const [ signature, group ] of ordered ) {
			const [ tag, type ] = signature.split( '|' );
			counter += 1;
			const probe = String( counter );
			group.element.setAttribute( 'data-forge-interaction-probe', probe );
			out.push( {
				bandId,
				probe,
				tag,
				classes: [ ...group.element.classList ].sort(),
				...( type ? { type } : {} ),
				count: group.count,
			} );
		}
	}
	return out;
}

/** Read the rest computed values for every probed element, plus cursor/transition. */
function readRestStyles( input: { probes: string[]; properties: string[] } ): Record<
	string,
	{ props: RestStyles; cursor: string; transition: string }
> {
	const out: Record< string, { props: RestStyles; cursor: string; transition: string } > = {};
	for ( const probe of input.probes ) {
		const element = document.querySelector( `[data-forge-interaction-probe="${ probe }"]` );
		if ( ! ( element instanceof HTMLElement ) ) {
			continue;
		}
		const style = getComputedStyle( element );
		const props: RestStyles = {};
		for ( const property of input.properties ) {
			props[ property ] = style.getPropertyValue(
				property.replace( /[A-Z]/g, ( character ) => `-${ character.toLowerCase() }` )
			);
		}
		out[ probe ] = { props, cursor: style.cursor, transition: style.transition };
	}
	return out;
}

/** Read one element's current computed values (after a forced state). */
function readStateStyles( input: { probe: string; properties: string[] } ): RestStyles | null {
	const element = document.querySelector( `[data-forge-interaction-probe="${ input.probe }"]` );
	if ( ! ( element instanceof HTMLElement ) ) {
		return null;
	}
	const style = getComputedStyle( element );
	const props: RestStyles = {};
	for ( const property of input.properties ) {
		props[ property ] = style.getPropertyValue(
			property.replace( /[A-Z]/g, ( character ) => `-${ character.toLowerCase() }` )
		);
	}
	return props;
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
 * `font-size` and `padding` are deliberately absent: the band typography and
 * the measured box facts already carry them, and freezing a desktop size here
 * would pin a control that the source scales responsively.
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
		if ( property === 'textDecorationLine' && value === 'none' ) {
			continue;
		}
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
): Promise< Record< string, ForgeInteractionFact[] > > {
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
			max: options.maxSignatures ?? MAX_SIGNATURES_PER_BAND,
		} );
		if ( candidates.length === 0 ) {
			return {};
		}

		// Rest state INCLUDING `transition` — the transition killer below is what
		// makes the forced states read their target value instead of a frame of
		// interpolation, but it also erases the property we want to record.
		const rest = await page.evaluate( readRestStyles, {
			probes: candidates.map( ( candidate ) => candidate.probe ),
			properties: [ ...PROBED_PROPERTIES ],
		} );

		await page.addStyleTag( {
			content:
				'*,*::before,*::after{transition-duration:0s!important;transition-delay:0s!important;animation-duration:0s!important;animation-delay:0s!important}',
		} );

		const cdp = await context.newCDPSession( page );
		await cdp.send( 'DOM.enable' );
		await cdp.send( 'CSS.enable' );
		const { root } = await cdp.send( 'DOM.getDocument', { depth: 0 } );

		const facts: Record< string, ForgeInteractionFact[] > = {};
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
				for ( const state of PROBE_STATES ) {
					await cdp.send( 'CSS.forcePseudoState', { nodeId, forcedPseudoClasses: state.force } );
					const styles = await page.evaluate( readStateStyles, {
						probe: candidate.probe,
						properties: [ ...PROBED_PROPERTIES ],
					} );
					if ( ! styles ) {
						continue;
					}
					const declarations = stateDeclarations( restEntry.props, styles );
					if ( declarations ) {
						fact[ state.key ] = declarations;
					}
				}
				await cdp.send( 'CSS.forcePseudoState', { nodeId, forcedPseudoClasses: [] } );
				// A source styles `:focus` and `:focus-visible` identically far more
				// often than not (browsers already apply `:focus-visible` to text
				// fields on click), so emitting both only doubles the CSS. Keep the
				// keyboard-aware one.
				if ( fact.focus && fact.focus === fact.focusVisible ) {
					delete fact.focus;
				}
				if ( fact.base || fact.transition || fact.hover || fact.focus || fact.focusVisible || fact.active ) {
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
		return facts;
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
		}
		for ( const fact of facts ) {
			const declared = COMPARED_INTERACTION_STATES.filter( ( state ) => Boolean( fact[ state ] ) );
			// A control the source gives no state to (an owl dot, a bare link) is
			// not part of the interaction contract — nothing to verify.
			if ( declared.length === 0 ) {
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
		}
	}
	// The keyboard indicator is checked on EVERY rebuilt control, not only the
	// ones the source styled: the template's baseline is the only thing standing
	// between a source with no focus styles and an unusable keyboard page.
	for ( const [ sectionId, facts ] of Object.entries( built ) ) {
		for ( const fact of facts ) {
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
	switch ( deviation.kind ) {
		case 'state-missing':
			return `band ${ deviation.sectionId }: "${ deviation.state }" state lost on ${ deviation.selector }`;
		case 'control-missing':
			return `band ${ deviation.sectionId }: "${ deviation.state }" states lost with a control the build re-rendered or the source generated in JS \u2014 ${ deviation.selector }`;
		default:
			return `band ${ deviation.sectionId }: no keyboard focus indicator — ${ deviation.selector }`;
	}
}
