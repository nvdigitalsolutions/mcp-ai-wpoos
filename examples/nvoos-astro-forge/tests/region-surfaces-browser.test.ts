// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// region-surfaces-browser.test.ts — P5 (pseudo surfaces) and P6 (ancestor
// gradients) against a hermetic fixture page.
//
// Both rules exist because the colour gate measured the WRONG layer:
//
//   • P5: a builder paints a band's surface on `::before`/`::after` (a hero's
//     overlay, a tinted `::after`). Computed styles do not chain through
//     pseudos, so the band read as transparent and the gate fell through to
//     the page canvas.
//   • P6: an inner-section anchor (`#products` is an Elementor
//     `inner-section`) has no surface of its own; its parent band does, and
//     that surface is a GRADIENT as often as a colour. The ancestor walk used
//     to accept only `background-color` and stepped past the gradient band.
//
// Chromium is a devDependency; when the binary is missing the suite SKIPS.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { chromium } from 'playwright';

import { measureRegionColorsDetailed } from '../lib/region-colors.ts';
import type { ForgeColorModes } from '../lib/types.ts';

const hasChromium = ( () => {
	try {
		return Boolean( chromium.executablePath() );
	} catch {
		return false;
	}
} )();

const FIXTURE_HTML = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
	html, body { margin: 0; background: rgb(255, 255, 255); }
	.band { position: relative; min-height: 200px; }
	#inner { min-height: 120px; }
	/* P5: the surface is a positioned overlay pseudo. */
	#pseudo-band::before {
		content: '';
		position: absolute;
		inset: 0;
		background: linear-gradient(rgb(10, 20, 30) 0%, rgb(20, 30, 40) 100%);
	}
	/* A static pseudo is decoration, never the band surface. */
	#static-band::before {
		content: '';
		display: block;
		height: 6px;
		background: rgb(200, 0, 0);
	}
	/* The overlay paints ABOVE the element's own colour. */
	#own-band { background: rgb(255, 255, 255); }
	#own-band::after {
		content: '';
		position: absolute;
		inset: 0;
		background: rgb(0, 64, 128);
	}
	/* A full-width CONTROL inside a transparent band is CONTENT, not a surface. */
	#control-band { min-height: 250px; }
	#control-band button { display: block; width: 100%; height: 53px; border: 0; background: rgb(135, 100, 160); }
	/* A full-bleed FILL child IS the band surface. */
	#fill-band { min-height: 200px; }
	#fill-band .fill { height: 200px; background: rgb(0, 128, 0); }
</style>
</head>
<body>
	<!-- P6: an inner section with no surface inside a gradient band. -->
	<div id="gradient-band" class="band" style="background: linear-gradient(rgb(10,20,30) 0%, rgb(20,30,40) 100%)">
		<div id="inner" class="band"></div>
	</div>
	<div id="pseudo-band" class="band"></div>
	<div id="static-band" class="band"></div>
	<div id="own-band" class="band"></div>
	<div id="control-band" class="band"><button type="button">Try Demo</button></div>
	<div id="fill-band" class="band"><div class="fill"></div></div>
</body>
</html>`;

const REGIONS = [
	{ name: 'inner', selectors: [ '#inner' ], descendantFill: true },
	{ name: 'gradient-band', selectors: [ '#gradient-band' ], descendantFill: true },
	{ name: 'pseudo-band', selectors: [ '#pseudo-band' ], descendantFill: true },
	{ name: 'pseudo-band-plain', selectors: [ '#pseudo-band' ] },
	{ name: 'static-band', selectors: [ '#static-band' ], descendantFill: true },
	{ name: 'own-band', selectors: [ '#own-band' ], descendantFill: true },
	{ name: 'control-band', selectors: [ '#control-band' ], descendantFill: true },
	{ name: 'fill-band', selectors: [ '#fill-band' ], descendantFill: true },
];

describe.skipIf( ! hasChromium )( 'band surfaces (pseudo + ancestor gradients)', () => {
	let server: Server;
	let modes: ForgeColorModes;

	beforeAll( async () => {
		server = createServer( ( request, response ) => {
			response.writeHead( 200, { 'content-type': 'text/html; charset=utf-8' } );
			response.end( FIXTURE_HTML );
		} );
		await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		const address = server.address();
		const port = typeof address === 'object' && address ? address.port : 0;
		const measured = await measureRegionColorsDetailed( {
			url: `http://127.0.0.1:${ port }/`,
			modes: [ 'light' ],
			regions: REGIONS,
			effective: true,
			timeout: 30000,
		} );
		modes = measured.modes;
	}, 60000 );

	afterAll( async () => {
		if ( server ) {
			await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
		}
	} );

	it( 'P6 — resolves an inner section to its ancestor band gradient', () => {
		const inner = modes.light[ 'inner' ];

		expect( inner.image ).toContain( 'linear-gradient' );
		expect( inner.image ).toContain( 'rgb(10, 20, 30)' );
	} );

	it( 'P6 — still resolves the gradient band itself', () => {
		expect( modes.light[ 'gradient-band' ].image ).toContain( 'linear-gradient' );
	} );

	it( 'P5 — resolves a positioned ::before overlay as the surface', () => {
		const band = modes.light[ 'pseudo-band' ];

		expect( band.image ).toContain( 'linear-gradient' );
		expect( band.image ).toContain( 'rgb(20, 30, 40)' );
	} );

	it( 'P5 — applies to a plain (non-descendant-fill) region too', () => {
		expect( modes.light[ 'pseudo-band-plain' ].image ).toContain( 'linear-gradient' );
	} );

	it( 'P5 — ignores a static pseudo: a divider bar is not the band', () => {
		const band = modes.light[ 'static-band' ];

		expect( band.image ).toBeUndefined();
		expect( band.background ).toBe( 'transparent' );
	} );

	it( 'P5 — a positioned overlay paints above the element’s own colour', () => {
		expect( modes.light[ 'own-band' ].background ).toBe( '#004080' );
	} );

	it( 'ignores a full-width control: a button is content, not the band surface', () => {
		// eca.directory read #8764a0 for two transparent bands this way: the
		// accent button spanned the band row before the layout settled.
		expect( modes.light[ 'control-band' ].background ).toBe( 'transparent' );
	} );

	it( 'accepts a full-bleed child fill as the band surface', () => {
		expect( modes.light[ 'fill-band' ].background ).toBe( '#008000' );
	} );
} );
