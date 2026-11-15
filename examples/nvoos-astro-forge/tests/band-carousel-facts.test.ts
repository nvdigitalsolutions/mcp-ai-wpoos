// ---------------------------------------------------------------------------
// band-carousel-facts.test.ts — a builder slider's slides-per-view.
//
// A slider's geometry lives in the vendor's JS options (`items: 5`,
// `margin: 0`, `responsive: { 834: { items: 2 } }`), so neither the markup nor
// the sanitized stylesheet knows how many slides are on screen. The shim had to
// guess, and `flex: 1 1 0` — right for a logo strip — squeezed virtuallyjamaica's
// seven-slide gallery into seven 129px columns at 1440, where the source shows
// five at 222px.
//
// These tests pin the declarations the renderer emits and the device merge.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { carouselBoxFacts, carouselDeclarations } from '../lib/geometry.ts';

describe( 'carouselDeclarations', () => {
	it( 'states the per-view count, the gutter and the resulting slide width', () => {
		expect( carouselDeclarations( { selector: 'owl-carousel', count: 7, perView: 5, gap: 0 }, false ) ).toBe(
			'--ds-carousel-per:5;--ds-carousel-gap:0px;--ds-carousel-item-flex:0 0 calc((100% - 4 * 0px) / 5)'
		);
	} );

	it( 'subtracts the gutters once per gap when the track has one', () => {
		expect( carouselDeclarations( { selector: 'c', count: 6, perView: 3, gap: 24 }, false ) ).toBe(
			'--ds-carousel-per:3;--ds-carousel-gap:24px;--ds-carousel-item-flex:0 0 calc((100% - 2 * 24px) / 3)'
		);
	} );

	it( 'makes the track SCROLL only on the base declaration', () => {
		// A media query that repeated the scroll behaviour would be noise: it is
		// the same at every viewport, and only the two numbers change.
		const base = carouselDeclarations( { selector: 'c', count: 7, perView: 5 }, true );
		expect( base ).toContain( 'overflow-x:auto' );
		expect( base ).toContain( 'scroll-snap-type:x mandatory' );
		expect( carouselDeclarations( { selector: 'c', count: 7, perView: 1 }, false ) ).not.toContain( 'overflow-x' );
	} );

	it( 'clamps a degenerate measurement to one slide and no negative gutter', () => {
		// `perView` 0 would divide by zero; a negative gap means the slides
		// overlap, which is not a lane and would invert the width math.
		expect( carouselDeclarations( { selector: 'c', count: 7, perView: 0, gap: -12 }, false ) ).toBe(
			'--ds-carousel-per:1;--ds-carousel-gap:0px;--ds-carousel-item-flex:0 0 calc((100% - 0 * 0px) / 1)'
		);
	} );

	it( 'renders nothing for a device with no fact', () => {
		expect( carouselDeclarations( undefined, true ) ).toBe( '' );
	} );
} );

describe( 'carouselBoxFacts', () => {
	it( 'emits the desktop fact as the base and a media query per differing device', () => {
		const box = carouselBoxFacts( {
			desktop: [ { selector: 'owl-carousel', count: 7, perView: 5, gap: 0, dots: 2 } ],
			tablet: [ { selector: 'owl-carousel', count: 7, perView: 2, gap: 0, dots: 4 } ],
			mobile: [ { selector: 'owl-carousel', count: 7, perView: 1, gap: 0, dots: 7 } ],
		} );

		expect( box[ 'owl-carousel' ].base ).toBe(
			'--ds-carousel-per:5;--ds-carousel-gap:0px;--ds-carousel-item-flex:0 0 calc((100% - 4 * 0px) / 5);overflow-x:auto;scroll-snap-type:x mandatory;overscroll-behavior-x:contain;scrollbar-width:none'
		);
		expect( box[ 'owl-carousel' ].tablet ).toBe(
			'--ds-carousel-per:2;--ds-carousel-gap:0px;--ds-carousel-item-flex:0 0 calc((100% - 1 * 0px) / 2)'
		);
		expect( box[ 'owl-carousel' ].mobile ).toBe(
			'--ds-carousel-per:1;--ds-carousel-gap:0px;--ds-carousel-item-flex:0 0 calc((100% - 0 * 0px) / 1)'
		);
	} );

	it( 'returns an empty map when the band measured no carousel', () => {
		expect( carouselBoxFacts( {} ) ).toEqual( {} );
	} );
} );
