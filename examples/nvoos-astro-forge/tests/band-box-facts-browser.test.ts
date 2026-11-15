// ---------------------------------------------------------------------------
// band-box-facts-browser.test.ts — `collectBandBoxFacts` against a hermetic
// fixture.
//
// The reported drift: monicarajiv's accommodations page pairs a
// `show-for-small` row with a `hide-for-small` one, and the banner inside also
// carries `hide-for-small`. The collector skipped scaffolding rows BEFORE
// recording their box, so the class only ever looked consistent at the
// banner's 420px pad and emitted `padding-top:420px` onto all six elements
// carrying it — the 770px band rendered 1540px.
//
// These tests pin the two halves of the rule: every node counts for
// CONSISTENCY (scaffolding included), and a class that appears ONLY on
// scaffolding still yields no fact at all.
//
// Chromium is a devDependency; when the binary is missing the suite SKIPS.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { chromium, type Browser } from 'playwright';

import { collectBandBoxFacts } from '../lib/geometry.ts';

const hasChromium = ( () => {
	try {
		return Boolean( chromium.executablePath() );
	} catch {
		return false;
	}
} )();

// `hide-for-small` sits on a row (0 pad) and on a banner (42px top pad) — the
// shared-class trap. `row-only` appears on the row alone. `card` is a module
// class with one consistent pad, so it must survive.
const FIXTURE = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
	*, *::before, *::after { box-sizing: border-box; }
	html, body { margin: 0; }
	.band { width: 1000px; margin: 0 auto; }
	.row, .card { display: flex; }
	.card { padding: 12px 0 12px 0; }
	.banner { padding-top: 42px; }
</style>
</head>
<body>
	<section class="band" data-section-id="abc" data-section="text">
		<div class="row hide-for-small row-only">
			<div class="card">
				<div class="banner hide-for-small"></div>
			</div>
		</div>
	</section>
</body>
</html>`;

describe.skipIf( ! hasChromium )( 'collectBandBoxFacts (hermetic browser probe)', () => {
	let server: Server;
	let browser: Browser;
	let facts: Record< string, { boxes: Record< string, { pad?: string } >; ambiguous?: string[] } > = {};

	beforeAll( async () => {
		server = createServer( ( _request, response ) => {
			response.writeHead( 200, { 'content-type': 'text/html; charset=utf-8' } );
			response.end( FIXTURE );
		} );
		await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		const address = server.address();
		const port = typeof address === 'object' && address ? address.port : 0;

		browser = await chromium.launch();
		const page = await browser.newPage( { viewport: { width: 1200, height: 900 } } );
		await page.goto( `http://127.0.0.1:${ port }/`, { waitUntil: 'load' } );
		facts = await page.evaluate( collectBandBoxFacts, { ids: [ 'abc' ], kind: 'built' as const } );
	}, 120000 );

	afterAll( async () => {
		await browser?.close();
		if ( server ) {
			await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
		}
	} );

	it( 'drops a class whose box disagrees between a row and a module', () => {
		// The row measures 0, the banner 42px. Recording either one would paint it
		// onto every instance of the class — the leak that doubled the band.
		expect( facts.abc.boxes[ 'hide-for-small' ]?.pad ).toBeUndefined();
	} );

	it( 'names the ambiguous class so a dropped rule is not silent', () => {
		// The drop is invisible in the rebuild — the band simply has no rule for the
		// class — so `analyze` reports the name and why instead of leaving the
		// operator to discover it as a height drift.
		expect( facts.abc.ambiguous ).toEqual( [ 'hide-for-small' ] );
	} );

	it( 'drops a class that only ever appears on scaffolding', () => {
		// `.row` is scaffolding: the template owns that box, not the measurement.
		expect( facts.abc.boxes[ 'row-only' ] ).toBeUndefined();
	} );

	it( 'keeps a module class whose box is consistent', () => {
		expect( facts.abc.boxes.card.pad ).toBe( '12px 0px 12px 0px' );
	} );
} );
