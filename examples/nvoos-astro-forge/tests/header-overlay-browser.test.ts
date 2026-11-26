// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// header-overlay-browser.test.ts — `measureStickyHeader` against a hermetic
// fixture.
//
// The reported drift: virtuallysrilanka's source header is `position: absolute`
// over a red hero — it floats over the first band and never pins. The
// measurement treated OUT OF FLOW as PINNED, so an absolute header fell into the
// `{ sticky: false }` early return and lost every resting fact: no `position`,
// no `restInk`, no `restHeight`. The rebuild therefore kept the header in the
// flow (band pushed down a header-height) and the white half of a white+grey SVG
// wordmark rendered on the near-white page background — "SRILANKA" vanished.
//
// The control below is the half of the contract that must NOT widen: a STATIC
// header stays `{ sticky: false }` whatever colour it measures.
//
// Chromium is a devDependency; when the binary is missing the suite SKIPS.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { chromium } from 'playwright';

import { measureStickyHeader } from '../lib/geometry.ts';
import type { ForgeStickyHeader } from '../lib/types.ts';

const hasChromium = ( () => {
	try {
		return Boolean( chromium.executablePath() );
	} catch {
		return false;
	}
} )();

/** A header + a band beneath it. `headerStyle` decides the resting position. */
const fixture = ( headerStyle: string ) => `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
	*, *::before, *::after { box-sizing: border-box; }
	html, body { margin: 0; background: rgb(247, 250, 252); }
	.site-header { ${ headerStyle } display: flex; align-items: center; height: 80px; padding: 0 24px; }
	.site-header nav { margin-left: auto; }
	.site-header nav a { color: rgb(255, 255, 255); text-decoration: none; margin-left: 16px; }
	.hero { background: rgb(221, 51, 51); height: 620px; }
	.after { height: 900px; }
</style>
</head>
<body>
	<header class="site-header">
		<a class="brand" href="/"><img src="/logo.svg" alt="Brand" width="140" height="40"></a>
		<nav><a href="/a">About</a><a href="/b">Contact</a></nav>
	</header>
	<section class="hero"><h1>Hero</h1></section>
	<div class="after"></div>
</body>
</html>`;

const LOGO_SVG =
	'<svg xmlns="http://www.w3.org/2000/svg" width="140" height="40"><rect width="140" height="40" fill="none"></rect></svg>';

const ROUTES: Record< string, string > = {
	'/': fixture( 'position: absolute; top: 0; left: 0; right: 0; background: transparent;' ),
	'/opaque': fixture( 'position: absolute; top: 0; left: 0; right: 0; background: rgb(255, 255, 255);' ),
	'/static': fixture( 'position: static; background: transparent;' ),
};

const serve = ( request: import( 'node:http' ).IncomingMessage, response: import( 'node:http' ).ServerResponse ) => {
	const path = ( request.url ?? '/' ).split( '?' )[ 0 ];
	if ( path === '/logo.svg' ) {
		response.writeHead( 200, { 'content-type': 'image/svg+xml' } );
		response.end( LOGO_SVG );
		return;
	}
	const body = ROUTES[ path ];
	response.writeHead( body ? 200 : 404, { 'content-type': 'text/html; charset=utf-8' } );
	response.end( body ?? 'not found' );
};

describe.skipIf( ! hasChromium )( 'measureStickyHeader — out-of-flow headers (hermetic browser probe)', () => {
	let server: Server;
	let overlay: ForgeStickyHeader;
	let opaque: ForgeStickyHeader;
	let stat: ForgeStickyHeader;

	beforeAll( async () => {
		server = createServer( serve );
		await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		const address = server.address();
		const port = typeof address === 'object' && address ? address.port : 0;
		const base = `http://127.0.0.1:${ port }`;
		overlay = await measureStickyHeader( { url: `${ base }/`, timeout: 30000 } );
		opaque = await measureStickyHeader( { url: `${ base }/opaque`, timeout: 30000 } );
		stat = await measureStickyHeader( { url: `${ base }/static`, timeout: 30000 } );
	}, 180000 );

	afterAll( async () => {
		if ( server ) {
			await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
		}
	} );

	it( 'records the resting POSITION of an absolute header even though it never pins', () => {
		expect( overlay.sticky ).toBe( false );
		expect( overlay.position ).toBe( 'absolute' );
	} );

	it( 'records the resting ink and height so the overlay can be themed', () => {
		expect( overlay.restInk ).toBe( 'rgb(255, 255, 255)' );
		expect( overlay.restHeight ).toBe( 80 );
	} );

	it( 'invents no pinned state for a header that does not pin', () => {
		expect( overlay.pinnedBg ).toBeUndefined();
		expect( overlay.pinnedHeight ).toBeUndefined();
		expect( overlay.shadow ).toBeUndefined();
		expect( overlay.bgChanged ).toBe( false );
		expect( overlay.heightChanged ).toBe( false );
	} );

	it( 'keeps a measured opaque surface on an out-of-flow header', () => {
		expect( opaque.position ).toBe( 'absolute' );
		expect( opaque.restBg ).toBe( 'rgb(255, 255, 255)' );
	} );

	it( 'leaves a STATIC header entirely unmeasured', () => {
		expect( stat ).toEqual( { sticky: false } );
	} );
} );
