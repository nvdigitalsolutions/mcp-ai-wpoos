// ---------------------------------------------------------------------------
// interactions.test.ts — measured hover / focus / active states, as emitted CSS.
//
// A rebuilt band is the source's own markup with the vendor sheet gone, so every
// state the builder expressed in CSS disappears: a CTA that darkens on hover, a
// button that presses in, a field that lights up on focus. `lib/interactions.ts`
// measures those states by forcing each pseudo-class in a real browser and
// diffing computed styles; these tests pin the two pure steps that decide what
// is emitted — the rest paint a state is relative to, and the delta itself.
//
// The filters matter more than they look: re-stating a state that REMOVES the
// focus ring is the classic accessibility failure (WCAG F78), and re-stating an
// invisible decoration colour is pure noise.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	childPaints,
	childPseudoDeclarations,
	childRestDeclarations,
	describeInteractionDeviation,
	diffInteractionStates,
	interactionChildSelector,
	interactionKey,
	interactionSelector,
	relativeTransform,
	restDeclarations,
	stateDeclarations,
} from '../lib/interactions.ts';
import { renderSectionsGenerated } from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';

/** A computed-style snapshot with the properties the module reads. */
function styles( overrides: Record< string, string > = {} ): Record< string, string > {
	return {
		color: 'rgb(0, 0, 0)',
		backgroundColor: 'rgba(0, 0, 0, 0)',
		backgroundImage: 'none',
		borderTopWidth: '0px',
		borderTopStyle: 'none',
		borderTopColor: 'rgb(0, 0, 0)',
		borderRightWidth: '0px',
		borderRightStyle: 'none',
		borderRightColor: 'rgb(0, 0, 0)',
		borderBottomWidth: '0px',
		borderBottomStyle: 'none',
		borderBottomColor: 'rgb(0, 0, 0)',
		borderLeftWidth: '0px',
		borderLeftStyle: 'none',
		borderLeftColor: 'rgb(0, 0, 0)',
		borderRadius: '0px',
		boxShadow: 'none',
		outlineColor: 'rgb(0, 0, 0)',
		outlineStyle: 'none',
		outlineWidth: '0px',
		outlineOffset: '0px',
		textDecorationLine: 'none',
		textDecorationColor: 'rgb(0, 0, 0)',
		textDecorationThickness: 'auto',
		opacity: '1',
		transform: 'none',
		filter: 'none',
		letterSpacing: 'normal',
		fontWeight: '400',
		textTransform: 'none',
		// Rest-only properties (`REST_LAYOUT_PROPERTIES`): `inline` is the UA
		// default for an anchor and is never emitted; no `fontSize` means an
		// unpainted link, whose type size must not be pinned.
		display: 'inline',
		...overrides,
	};
}

describe( 'interactionSelector', () => {
	it( 'builds a whole-token attribute selector from the sorted class set', () => {
		expect(
			interactionSelector( { tag: 'a', classes: [ 'btn', 'btn-small', 'button' ] } )
		).toBe( 'a[class~="btn"][class~="btn-small"][class~="button"]' );
	} );

	it( 'keeps the type on inputs so a field is not styled as a button', () => {
		expect( interactionSelector( { tag: 'input', classes: [], type: 'submit' } ) ).toBe(
			'input[type="submit"]'
		);
	} );

	it( 'skips a class token that would break out of the attribute value', () => {
		expect(
			interactionSelector( { tag: 'a', classes: [ 'ok', 'bad"]' ] } )
		).toBe( 'a[class~="ok"]' );
	} );

	it( 'falls back to the bare tag for an unclassed control', () => {
		expect( interactionSelector( { tag: 'button', classes: [] } ) ).toBe( 'button' );
	} );
} );

describe( 'restDeclarations', () => {
	it( 're-states the source paint a hover delta is relative to', () => {
		const declarations = restDeclarations(
			styles( {
				color: 'rgb(226, 226, 226)',
				backgroundColor: 'rgb(221, 51, 51)',
				fontWeight: '600',
				textTransform: 'uppercase',
				borderTopWidth: '2px',
				borderTopStyle: 'solid',
				borderTopColor: 'rgb(221, 51, 51)',
				display: 'inline-block',
				fontSize: '11px',
			} ),
			'pointer'
		);

		expect( declarations ).toBe(
			'cursor:pointer;color:rgb(226, 226, 226);background-color:rgb(221, 51, 51);text-decoration-line:none;font-weight:600;text-transform:uppercase;display:inline-block;font-size:11px;border-top:2px solid rgb(221, 51, 51)'
		);
	} );

	it( 'states text-decoration-line even when the source has none', () => {
		// The UA default for `a[href]` is UNDERLINE, so `none` is a declaration the
		// rebuild NEEDS — omitting it drew a line through every builder CTA
		// (virtuallyjamaica's `.btn` rests on no underline).
		expect( restDeclarations( styles(), 'auto' ) ).toContain( 'text-decoration-line:none' );
		expect( restDeclarations( styles( { textDecorationLine: 'underline' } ), 'auto' ) ).toContain(
			'text-decoration-line:underline'
		);
	} );

	it( 'keeps a painted button inline-block so its padding box reserves space', () => {
		// Rendered `inline`, a 2px border and 5px vertical padding reserve nothing
		// and the box overlaps the line above.
		const declarations = restDeclarations(
			styles( { display: 'inline-block', backgroundColor: 'rgb(221, 51, 51)', fontSize: '11px' } ),
			'auto'
		);
		expect( declarations ).toContain( 'display:inline-block' );
		expect( declarations ).toContain( 'font-size:11px' );
	} );

	it( 'never pins the type size of an unpainted link', () => {
		// A bare text link inherits the band's responsive scale; freezing 11px here
		// would make it look like a button at every viewport.
		expect( restDeclarations( styles( { fontSize: '11px' } ), 'auto' ) ).not.toContain( 'font-size' );
	} );

	it( 'never emits the default inline display', () => {
		expect( restDeclarations( styles( { display: 'inline' } ), 'auto' ) ).not.toContain( 'display:' );
	} );

	it( 'drops the no-ops a computed style always reports', () => {
		// The control's INK always survives — it is the source's own colour —
		// everything else here is a browser default and must not be re-stated.
		expect( restDeclarations( styles(), 'auto' ) ).toBe( 'color:rgb(0, 0, 0);text-decoration-line:none' );
		expect( restDeclarations( styles( { borderRadius: '4px' } ), 'auto' ) ).toBe(
			'color:rgb(0, 0, 0);border-radius:4px;text-decoration-line:none'
		);
	} );

	it( 'never re-states a background image URL (the media gate owns that)', () => {
		expect(
			restDeclarations( styles( { backgroundImage: 'url("https://example.com/a.png")' } ), 'auto' )
		).toBe( 'color:rgb(0, 0, 0);text-decoration-line:none' );
		expect(
			restDeclarations( styles( { backgroundImage: 'linear-gradient(red, blue)' } ), 'auto' )
		).toBe( 'color:rgb(0, 0, 0);background-image:linear-gradient(red, blue);text-decoration-line:none' );
	} );

	it( 'emits a border side only when it is actually drawn', () => {
		expect(
			restDeclarations( styles( { borderLeftWidth: '1px', borderLeftStyle: 'solid' } ), 'auto' )
		).toBe( 'color:rgb(0, 0, 0);text-decoration-line:none;border-left:1px solid rgb(0, 0, 0)' );
	} );
} );

describe( 'stateDeclarations', () => {
	it( 'keeps only the properties the state CHANGED', () => {
		const rest = styles( { color: 'rgb(254, 108, 97)' } );
		const hover = styles( { color: 'rgb(255, 255, 255)', backgroundColor: 'rgb(0, 0, 0)' } );

		expect( stateDeclarations( rest, hover ) ).toBe( 'color:rgb(255, 255, 255);background-color:rgb(0, 0, 0)' );
	} );

	it( 'returns nothing when the state changes nothing', () => {
		expect( stateDeclarations( styles(), styles() ) ).toBe( '' );
	} );

	it( 'refuses to reproduce a state that REMOVES the focus ring (WCAG F78)', () => {
		// The source's real habit: `a:hover { outline: 0 }`.
		const rest = styles( { outlineStyle: 'auto', outlineWidth: '3px' } );
		const hover = styles( { outlineStyle: 'auto', outlineWidth: '0px' } );

		expect( stateDeclarations( rest, hover ) ).toBe( '' );
	} );

	it( 'keeps a ring a state DRAWS, including its offset', () => {
		const rest = styles();
		const focus = styles( { outlineStyle: 'solid', outlineWidth: '2px', outlineOffset: '2px' } );

		expect( stateDeclarations( rest, focus ) ).toBe(
			'outline-style:solid;outline-width:2px;outline-offset:2px'
		);
	} );

	it( 'ignores a decoration COLOUR with no decoration LINE', () => {
		const rest = styles();
		const hover = styles( { textDecorationColor: 'rgb(255, 0, 0)' } );

		expect( stateDeclarations( rest, hover ) ).toBe( '' );
	} );

	it( 'keeps the decoration colour when the line is drawn', () => {
		const rest = styles();
		const hover = styles( { textDecorationLine: 'underline', textDecorationColor: 'rgb(255, 0, 0)' } );

		expect( stateDeclarations( rest, hover ) ).toBe(
			'text-decoration-line:underline;text-decoration-color:rgb(255, 0, 0)'
		);
	} );

	it( 'drops a state background image that points at a URL', () => {
		const hover = styles( { backgroundImage: 'url("https://example.com/hover.png")' } );

		expect( stateDeclarations( styles(), hover ) ).toBe( '' );
	} );
} );

describe( 'interaction states — verify comparison', () => {
	const sourceFact = {
		tag: 'a',
		classes: [ 'btn', 'btn-small' ],
		count: 1,
		base: 'cursor:pointer',
		hover: 'color:rgb(255, 255, 255)',
		focusVisible: 'color:rgb(255, 255, 255)',
		active: 'box-shadow:rgba(0, 0, 0, 0.125) 0px 3px 5px 0px inset',
	};

	it( 'keys a control by tag, type and the sorted class set', () => {
		expect( interactionKey( { tag: 'a', classes: [ 'btn-small', 'btn' ] } ) ).toBe( 'a||btn.btn-small' );
		expect( interactionKey( { tag: 'input', classes: [], type: 'submit' } ) ).toBe( 'input|submit|' );
	} );

	it( 'passes when the rebuilt control keeps every state the source declared', () => {
		expect(
			diffInteractionStates( { band1: [ sourceFact ] }, { band1: [ sourceFact ] } )
		).toEqual( [] );
	} );

	it( 'fails the state the rebuild lost, once per state', () => {
		// The rebuild kept the rest paint and the focus ring, lost hover + press.
		const built = [
			{
				tag: 'a',
				classes: [ 'btn', 'btn-small' ],
				count: 1,
				base: 'cursor:pointer',
				focusVisible: 'color:rgb(255, 255, 255)',
			},
		];
		const deviations = diffInteractionStates( { band1: [ sourceFact ] }, { band1: built } );

		expect( deviations.every( ( deviation ) => deviation.kind === 'state-missing' ) ).toBe( true );
		expect( deviations.every( ( deviation ) => deviation.severity === 'fail' ) ).toBe( true );
		expect( deviations.map( ( deviation ) => deviation.state ).sort() ).toEqual( [ 'active', 'hover' ] );
		expect( deviations[ 0 ].selector ).toBe( 'a[class~="btn"][class~="btn-small"]' );
	} );

	it( 'warns once per state KIND a re-rendered band loses entirely', () => {
		const deviations = diffInteractionStates( { band1: [ sourceFact ] }, { band1: [] } );

		expect( deviations.map( ( deviation ) => deviation.kind ) ).toEqual( [
			'control-missing',
			'control-missing',
			'control-missing',
		] );
		expect( deviations.map( ( deviation ) => deviation.state ).sort() ).toEqual( [
			'active',
			'focusVisible',
			'hover',
		] );
		expect( deviations.every( ( deviation ) => deviation.severity === 'warn' ) ).toBe( true );
	} );

	it( 'stays silent when a re-rendered band still exposes the same state kinds', () => {
		// The contact band is rebuilt as the template form: different signatures,
		// but the band still has a focus treatment, so nothing was LOST.
		const templateField = {
			tag: 'button',
			classes: [ 'ds-button' ],
			hover: 'background:rgb(0, 0, 0)',
			focusVisible: 'outline:2px solid rgb(254, 108, 97);outline-offset:1px',
			active: 'transform:translateY(1px)',
		};
		const deviations = diffInteractionStates( { band1: [ sourceFact ] }, { band1: [ templateField ] } );

		expect( deviations ).toEqual( [] );
	} );

	it( 'fails a rebuilt control with no keyboard focus indicator', () => {
		const unfocusable = { tag: 'button', classes: [ 'owl-dot' ], base: 'cursor:pointer' };
		const deviations = diffInteractionStates( {}, { band2: [ unfocusable ] } );

		expect( deviations ).toEqual( [
			{
				sectionId: 'band2',
				selector: 'button[class~="owl-dot"]',
				kind: 'focus-indicator-missing',
				severity: 'fail',
			},
		] );
	} );

	it( 'does not compare a signature the source never had', () => {
		// A control only the BUILD has (a template addition) with its own focus
		// ring is not a parity deviation — only the source's declarations are owed.
		const extra = {
			tag: 'a',
			classes: [ 'wp-block-button__link' ],
			hover: 'color:rgb(0, 0, 0)',
			focusVisible: 'outline-offset:2px',
		};
		expect( diffInteractionStates( { band1: [ sourceFact ] }, { band1: [ sourceFact, extra ] } ) ).toEqual( [] );
	} );

	it( 'describes a finding in the scorecard\u0027s voice', () => {
		expect(
			describeInteractionDeviation( {
				sectionId: 'aaa1111',
				selector: 'a',
				kind: 'state-missing',
				state: 'hover',
				severity: 'fail',
			} )
		).toBe( 'band aaa1111: "hover" state lost on a' );
		expect(
			describeInteractionDeviation( {
				sectionId: 'aaa1111',
				selector: 'button',
				kind: 'focus-indicator-missing',
				severity: 'fail',
			} )
		).toBe( 'band aaa1111: no keyboard focus indicator \u2014 button' );
	} );
} );

describe( 'overlay children — child effects, relative to the control', () => {
	// jayainc's `aux-button`: a 196x53 pill whose `.aux-overlay` slot is a
	// 234.67px square, centred, scaled to 0 at rest and to 1 on hover, with the
	// dark fill on `.aux-overlay::after`.
	const controlBox = { w: 196.7, h: 52.9 };
	const childBox = { w: 234.672, h: 234.672 };
	const layout = {
		position: 'absolute',
		top: '26.5px',
		left: '97.7812px',
		width: '234.672px',
		height: '234.672px',
		transform: 'matrix(0, 0, 0, 0, -117.336, -117.336)',
	};

	it( 're-expresses a matrix translate as a share of the child box', () => {
		expect( relativeTransform( 'matrix(0, 0, 0, 0, -117.336, -117.336)', childBox ) ).toBe(
			'translate(-50%, -50%) scale(0, 0)'
		);
		expect( relativeTransform( 'matrix(1, 0, 0, 1, -117.336, -117.336)', childBox ) ).toBe(
			'translate(-50%, -50%) scale(1, 1)'
		);
	} );

	it( 'keeps a rotated matrix verbatim instead of lying about it', () => {
		const rotated = 'matrix(0.7071, 0.7071, -0.7071, 0.7071, 10, 20)';
		expect( relativeTransform( rotated, childBox ) ).toBe( rotated );
	} );

	it( 'expresses the child layout as percentages of the CONTROL', () => {
		expect( childRestDeclarations( layout, childBox, controlBox ) ).toBe(
			'position:absolute;top:50.1%;left:49.7%;width:119.3%;height:443.6%;transform:translate(-50%, -50%) scale(0, 0)'
		);
	} );

	it( 'leaves a static child alone (nothing to reproduce)', () => {
		expect(
			childRestDeclarations(
				{ position: 'static', top: 'auto', left: 'auto', width: 'auto', height: 'auto', transform: 'none' },
				childBox,
				controlBox
			)
		).toBe( '' );
	} );

	it( 're-states the pseudo-element fill that makes the overlay visible', () => {
		expect(
			childPseudoDeclarations(
				{
					content: '""',
					backgroundColor: 'rgb(223, 180, 3)',
					backgroundImage: 'none',
					width: '234.672px',
					height: '234.672px',
				},
				childBox
			)
		).toBe( 'content:"";background-color:rgb(223, 180, 3);width:100%;height:100%' );
	} );

	it( 'ignores a pseudo-element with no content, or a non-literal one', () => {
		const base = { backgroundColor: 'rgb(1, 2, 3)', backgroundImage: 'none', width: '1px', height: '1px' };
		expect( childPseudoDeclarations( { ...base, content: 'none' }, childBox ) ).toBe( '' );
		expect( childPseudoDeclarations( { ...base, content: 'attr(data-fill)' }, childBox ) ).toBe( '' );
	} );

	it( 'skips a child that paints nothing and has no reproducible pseudo', () => {
		const bare = styles();
		expect( childPaints( bare, false ) ).toBe( false );
		expect( childPaints( bare, false, 'content:""' ) ).toBe( true );
		expect( childPaints( styles( { backgroundColor: 'rgb(0, 0, 0)' } ), false ) ).toBe( true );
		expect( childPaints( bare, true ) ).toBe( true );
	} );

	it( 'selects a classed child by token and an unclassed one by position', () => {
		expect( interactionChildSelector( { tag: 'span', classes: [ 'aux-overlay' ], index: 0 } ) ).toBe(
			'span[class~="aux-overlay"]'
		);
		expect( interactionChildSelector( { tag: 'span', classes: [], index: 1 } ) ).toBe( ':nth-child(2)' );
	} );
} );

describe( 'sections.generated.ts — interaction facts', () => {
	it( 'bakes the escaped selector and the state declarations per band', () => {
		const profile = {
			url: 'https://example.com',
			design: {
				sections: [ { id: 'aaa1111', anchor: 'about' } ],
				builders: [],
			},
			bandFacts: {
				aaa1111: {
					interactions: [
						{
							tag: 'a',
							classes: [ 'btn', 'btn-primary' ],
							count: 2,
							base: 'cursor:pointer;background-color:rgb(221, 51, 51)',
							transition: '0.2s ease-in-out',
							hover: 'color:rgb(255, 255, 255)',
							focusVisible: 'outline-offset:2px',
							active: 'box-shadow:rgba(0, 0, 0, 0.125) 0px 3px 5px 0px inset',
						},
					],
				},
			},
		} as unknown as SiteProfile;

		const file = renderSectionsGenerated( profile );

		expect( file ).toContain( "selector: 'a[class~=\"btn\"][class~=\"btn-primary\"]'" );
		expect( file ).toContain( 'count: 2' );
		expect( file ).toContain( "hover: 'color:rgb(255, 255, 255)'" );
		expect( file ).toContain( 'focusVisible: \'outline-offset:2px\'' );
		expect( file ).toContain( "transition: '0.2s ease-in-out'" );
	} );

	it( 'omits the key entirely when the source declares no state', () => {
		const profile = {
			url: 'https://example.com',
			design: { sections: [ { id: 'aaa1111', anchor: 'about' } ], builders: [] },
		} as unknown as SiteProfile;

		expect( renderSectionsGenerated( profile ) ).not.toContain( 'interactions:' );
	} );
} );
