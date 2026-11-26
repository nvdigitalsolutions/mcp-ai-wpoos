// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// band-background-box.test.ts — a band image carries its BOX.
//
// Elementor declares all three beside the URL:
//   background-image:url(bg-01.png); background-position:top center;
//   background-repeat:no-repeat;    background-size:cover;
// Dropping them painted eca.directory's halftone at NATIVE scale over two bands
// (2026-11-25). The extraction is pinned here; the renderer applies them.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { analyzeSections } from '../lib/design.ts';

const section = ( inner = '<h2>Band</h2>' ): string =>
	'<div class="elementor">' +
	'<section class="elementor-section elementor-top-section elementor-element elementor-element-a1b2c3d">' +
	`<div class="elementor-container"><div class="elementor-column elementor-col-100">${ inner }</div></div>` +
	'</section>' +
	'</div>';

const CSS = [
	'.elementor-159 .elementor-element.elementor-element-a1b2c3d::before{' +
		'background-image:url("https://eca.example/bg-01.png");' +
		'background-position:top center;background-repeat:no-repeat;background-size:cover}',
];

describe( 'band background box', () => {
	it( 'records size, repeat and position beside the image', () => {
		const [ band ] = analyzeSections( { url: 'https://eca.example', html: section(), css: CSS } );
		expect( band.bgImage ).toBe( 'https://eca.example/bg-01.png' );
		expect( band.bgSize ).toBe( 'cover' );
		expect( band.bgRepeat ).toBe( 'no-repeat' );
		expect( band.bgPosition ).toBe( 'top center' );
	} );

	it( 'records the LAYER a band image is painted on', () => {
		const [ band ] = analyzeSections( {
			url: 'https://eca.example',
			html: section(),
			css: [
				'.elementor-element-a1b2c3d{--overlay-opacity:0.2}',
				'.elementor-element-a1b2c3d::before{background-image:url("https://eca.example/bg.png");background-size:cover;mix-blend-mode:lighten}',
			],
		} );
		expect( band.bgLayerOpacity ).toBe( 0.2 );
		expect( band.bgBlendMode ).toBe( 'lighten' );
	} );

	it( 'keeps the band COLOUR under a layer image', () => {
		// The colour is the base a faded layer sits on, not an underlay beneath an
		// opaque photo. Dropping it left white text on white.
		const [ band ] = analyzeSections( {
			url: 'https://eca.example',
			html: section(),
			css: [
				':root{--brand: #27104A}',
				'.elementor-element-a1b2c3d{--overlay-opacity:0.2;background-color:var(--brand)}',
				'.elementor-element-a1b2c3d::before{background-image:url("https://eca.example/bg.png")}',
			],
		} );
		expect( band.bgColor ).toBe( '#27104A' );
	} );

	it( 'leaves the box absent when the source declares none', () => {
		// The renderer's default then applies; fabricating a value here would hide
		// a band whose source really does paint at native scale.
		const [ band ] = analyzeSections( {
			url: 'https://eca.example',
			html: section(),
			css: [ '.elementor-element-a1b2c3d{background-image:url("https://eca.example/x.png")}' ],
		} );
		expect( band.bgImage ).toBe( 'https://eca.example/x.png' );
		expect( band.bgSize ).toBeUndefined();
		expect( band.bgRepeat ).toBeUndefined();
		expect( band.bgPosition ).toBeUndefined();
	} );
} );
