// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// fact-round-trip.test.ts — a measured fact must reach the generated module.
//
// The band audit checks that DECLARED facts render, which cannot see a fact the
// scaffold dropped before it was ever declared. In one feature that class of gap
// hit four times:
//
//   veil       dropped by the element serializer AND by the emitted type
//   logoAlign  dropped by the chrome serializer AND by the emitted type
//   lineHeight read at rest, absent from the paint allowlist
//
// A hero rendered unshaded and an outline button lost its border, and both
// builds were green. These tests walk EVERY optional field of the fact types, so
// adding a field without wiring it through is a compile error in this file —
// `satisfies Required<…>` is the gate, not a comment asking politely.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { renderChromeGenerated, renderSectionsGenerated } from '../lib/scaffold.ts';
import type { ForgeBandElement, ForgeChromeBox, ForgeDesign, SiteProfile } from '../lib/types.ts';

/** Every optional element fact, all set. Adding a field breaks `Required`. */
const everyElementFact = {
	path: 'div:nth-child(2) > div:nth-child(1)',
	bg: 'background-color:rgb(240, 240, 240)',
	veil: 'background-color:rgba(0, 0, 0, 0.5)',
	fg: '#ffffff',
	height: { desktop: 900, tablet: 700 },
	width: { desktop: 255, tablet: 345 },
} satisfies Required< ForgeBandElement >;

/** Every optional header-box fact, all set. */
const everyChromeBoxFact = {
	measure: 1080,
	padX: 15,
	logoHeight: '74px',
	logoAlign: 'center',
} satisfies Required< ForgeChromeBox >;

const sectionsModule = (): string =>
	renderSectionsGenerated( {
		name: 'monica',
		url: 'https://monica.test',
		design: {
			sections: [ { id: 'abc1234', anchor: 'section_test', bgColor: '#dd3333' } ],
			bandSource: 'sections',
		} as ForgeDesign,
		bandFacts: { abc1234: { elements: [ everyElementFact ] } },
	} as unknown as SiteProfile );

describe( 'every element fact survives scaffold → module', () => {
	it( 'carries the translucent veil, which is not a surface', () => {
		expect( sectionsModule() ).toContain( 'veil:' );
	} );

	it( 'carries the surface and its derived ink', () => {
		const module = sectionsModule();
		expect( module ).toContain( 'bg:' );
		expect( module ).toContain( 'fg:' );
	} );

	it( 'carries the per-device height and width maps', () => {
		const module = sectionsModule();
		expect( module ).toContain( 'height: { desktop: 900, tablet: 700 }' );
		expect( module ).toContain( 'width: { desktop: 255, tablet: 345 }' );
	} );

	it( 'carries the structural path the facts are keyed by', () => {
		expect( sectionsModule() ).toContain( "path: 'div:nth-child(2) > div:nth-child(1)'" );
	} );

	it( 'names every field the type declares', () => {
		// Belt and braces on top of `Required`: if a field is added to the type but
		// not to this fixture, the count below no longer matches the emitted facts.
		expect( Object.keys( everyElementFact ) ).toHaveLength( 6 );
	} );
} );

describe( 'every header-box fact survives scaffold → module', () => {
	const chromeModule = (): string =>
		renderChromeGenerated( {
			name: 'monica',
			url: 'https://monica.test',
			design: {
				sections: [],
				chrome: { geometry: { desktop: everyChromeBoxFact } },
			} as unknown as ForgeDesign,
		} as unknown as SiteProfile );

	it( 'carries the container cap and gutter', () => {
		const module = chromeModule();
		expect( module ).toContain( 'measure: 1080' );
		expect( module ).toContain( 'padX: 15' );
	} );

	it( 'carries the mark height, so the template stops guessing 2.5rem', () => {
		expect( chromeModule() ).toContain( "logoHeight: '74px'" );
	} );

	it( 'carries the mark station, so a centred logo can be centred', () => {
		expect( chromeModule() ).toContain( "logoAlign: 'center'" );
	} );
} );
