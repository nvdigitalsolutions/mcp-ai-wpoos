// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// band-lane-facts.test.ts — a container-mode band records its LANES.
//
// Elementor 4 publishes a card row as sibling `e-con` elements, not
// `.elementor-column`s. The lane read only understood the legacy model, so on
// every container-built site the band recorded NO lane facts at all — which
// silently disabled the per-device contract (visibility, width overrides) even
// though `ForgeSectionColumn` has carried those fields for releases.
//
// The pass is EXPLICIT-ONLY by design: an implicit `--width` is the desktop
// split, and applying it per device squeezes the rebuilt grid. So a band with
// no stated per-device intent emits nothing — pinned here, because that
// negative is the difference between a fact and a guess.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { analyzeSections } from '../lib/design.ts';

const CARD = ( id: string, title: string, extra = '' ): string =>
	`<div class="e-con e-con-full elementor-element elementor-element-${ id }${ extra }">` +
	`<div class="elementor-widget-heading"><h3>${ title }</h3></div>` +
	`<div class="elementor-widget-icon-list"><ul><li>Monday</li><li>Tuesday</li></ul></div>` +
	'</div>';

const band = ( lanes: string ): string =>
	'<div class="elementor">' +
	// A background keeps the band in the fact list, so these tests isolate the
	// LANE pass: `analyzeSections` drops a band that states no fact at all.
	'<section class="elementor-section elementor-top-section elementor-element elementor-element-a4ad586" style="background-color:#eeeeee">' +
	`<div class="e-con e-con-full">${ lanes }</div>` +
	'</section>' +
	'</div>';

const analyze = ( lanes: string, css: string[] = [] ) =>
	analyzeSections( {
		url: 'https://eca.example',
		html: band( lanes ),
		css,
	} )[ 0 ];

describe( 'container-mode lane facts', () => {
	it( 'records a lane the source hides on phones', () => {
		const section = analyze(
			CARD( '1111111', "Lion's Mane" ) +
				CARD( '2222222', 'Chess Club', ' elementor-hidden-mobile' ) +
				CARD( '3333333', 'Robotics' )
		);
		expect( section.columns ).toHaveLength( 3 );
		expect( section.columns?.[ 1 ] ).toEqual( { hiddenMobile: true } );
		// Document order is the contract the renderer addresses lanes by.
		expect( section.columns?.[ 0 ] ).toEqual( {} );
		expect( section.columns?.[ 2 ] ).toEqual( {} );
	} );

	it( 'records a media-scoped width override (the container `--width`)', () => {
		const section = analyze(
			CARD( '1111111', 'Lion' ) + CARD( '2222222', 'Chess' ) + CARD( '3333333', 'Robotics' ),
			[
				'@media (max-width: 767px){.elementor-123 .elementor-element.elementor-element-3333333{--width:100%}}',
			]
		);
		expect( section.columns?.[ 2 ] ).toEqual( { widthMobile: 100 } );
	} );

	it( 'emits NOTHING when the band states no per-device intent', () => {
		// Three equal lanes = an implicit desktop split, not a responsive fact.
		const section = analyze(
			CARD( '1111111', 'Lion' ) + CARD( '2222222', 'Chess' ) + CARD( '3333333', 'Robotics' )
		);
		expect( section.columns ).toBeUndefined();
	} );

	it( 'ignores a single-lane band', () => {
		const section = analyze( CARD( '1111111', 'Lion', ' elementor-hidden-mobile' ) );
		expect( section.columns ).toBeUndefined();
	} );
} );
