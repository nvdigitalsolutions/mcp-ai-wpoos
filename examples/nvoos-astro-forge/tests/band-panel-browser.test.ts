// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// band-panel-browser.test.ts — `collectBandPanelFacts` against a hermetic
// fixture.
//
// The reported drift: virtuallysrilanka's "How it Works" band puts its four icon
// items on a #dd3333 slab that fills only the RIGHT HALF of the band (720 of
// 1440) and centres the band's eyebrow and title ON that slab. The lane pass
// never saw it — the builder paints the slab on the column's inner wrapper and
// the sibling column collapses to a 1px spacer, so the row read as one lane —
// and the rebuild painted a full-bleed slab with every heading 360px left of
// the source.
//
// Chromium is a devDependency; when the binary is missing the suite SKIPS.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { chromium } from 'playwright';

import { collectBandPanelFacts } from '../lib/geometry.ts';

const hasChromium = ( () => {
	try {
		return Boolean( chromium.executablePath() );
	} catch {
		return false;
	}
} )();

/**
 * Three bands, one shape each: a HALF slab (the source), a full-bleed slab (no
 * box is needed — the rebuild already paints that), and two slabs (per-column
 * panels, which the flat rebuilt markup still cannot address).
 */
const FIXTURE_HTML = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
	*, *::before, *::after { box-sizing: border-box; }
	html, body { margin: 0; }
	.half, .full, .pair { display: block; position: relative; width: 1440px; height: 800px; }
	.half { background-image: url( '/bg.jpg' ); background-size: cover; }
	/* Elementor's shape: a 50% column whose inner wrapper carries the paint and
	 * a sibling that collapses to a hairline. */
	.half .col { position: absolute; top: 0; right: 0; width: 720px; height: 800px; }
	.half .col-inner { width: 100%; height: 100%; background-color: rgb(221, 51, 51); }
	.half .spacer { position: absolute; top: 400px; left: 0; width: 720px; height: 1px; }
	.full .stage { width: 100%; height: 100%; background-color: rgb(8, 26, 67); }
	.pair { display: flex; }
	.pair .lane { width: 432px; height: 800px; background-color: rgb(221, 51, 51); }
	.pair .lane.photo { background-color: rgb(10, 10, 10); }
	.pair .lane.black { width: 571px; background-color: rgb(0, 0, 0); }
</style>
</head>
<body>
	<section class="half" data-section-id="half"><div class="spacer"></div><div class="col"><div class="col-inner"></div></div></section>
	<section class="full" data-section-id="full"><div class="stage"></div></section>
	<section class="pair" data-section-id="pair"><div class="lane"></div><div class="lane photo"></div><div class="lane black"></div></section>
</body>
</html>`;

const serveFixture = ( _request: import( 'node:http' ).IncomingMessage, response: import( 'node:http' ).ServerResponse ) => {
	response.writeHead( 200, { 'content-type': 'text/html; charset=utf-8' } );
	response.end( FIXTURE_HTML );
};

describe.skipIf( ! hasChromium )( 'collectBandPanelFacts (hermetic browser probe)', () => {
	let server: Server;
	let panels: Awaited< ReturnType< typeof collectBandPanelFacts > >;

	beforeAll( async () => {
		server = createServer( serveFixture );
		await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		const address = server.address();
		const port = typeof address === 'object' && address ? address.port : 0;
		const browser = await chromium.launch();
		try {
			const page = await browser.newPage( { viewport: { width: 1440, height: 900 } } );
			await page.goto( `http://127.0.0.1:${ port }/`, { waitUntil: 'load', timeout: 30000 } );
			panels = await page.evaluate( collectBandPanelFacts, {
				ids: [ 'half', 'full', 'pair' ],
				kind: 'built',
			} as never );
		} finally {
			await browser.close();
		}
	}, 120000 );

	afterAll( async () => {
		if ( server ) {
			await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
		}
	} );

	it( 'records the slab’s own width and offset when it fills PART of the band', () => {
		expect( panels.half ).toEqual( {
			bg: 'rgb(221, 51, 51)',
			width: 720,
			bandWidth: 1440,
			x: 720,
		} );
	} );

	it( 'records nothing for a full-bleed slab (the rebuild already paints that)', () => {
		expect( panels.full ).toBeUndefined();
	} );

	it( 'records nothing when more than one slab is painted (per-column panels)', () => {
		// Three lanes with three different surfaces is the services band: the flat
		// rebuilt markup cannot address them positionally, and inventing one slab
		// would paint two of them wrongly.
		expect( panels.pair ).toBeUndefined();
	} );
} );
