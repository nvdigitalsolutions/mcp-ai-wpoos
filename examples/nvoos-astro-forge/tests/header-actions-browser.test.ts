// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// header-actions-browser.test.ts — the header CTA pills as the BROWSER paints
// them (`measureHeaderActions`).
//
// The stylesheet harvest reads declarations: it resolves a builder var against
// the first declaration it finds and can see a rule for a DIFFERENT element.
// eca.directory's accent “Try Demo” came back `#d9534f` (a foreign Bootstrap
// red) beside a correctly-resolved dark “Sign In”, so the pill paint is now a
// measured fact like the nav's type and the footer's size.
//
// Chromium is a devDependency; when the binary is missing the suite SKIPS.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { chromium } from 'playwright';

import { measureHeaderActions } from '../lib/geometry.ts';
import type { ForgeMeasuredHeaderAction } from '../lib/types.ts';

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
	html, body { margin: 0; font-family: sans-serif; }
	header { display: flex; gap: 12px; align-items: center; background: rgb(247, 247, 247); padding: 20px 30px; }
	.nav-link { color: rgb(39, 16, 74); text-decoration: none; }
	/* A real pill: flat accent fill. */
	#accent { background-color: rgb(135, 100, 160); color: rgb(255, 255, 255);
		padding: 12px 24px; border-radius: 100px; font-size: 16px; font-weight: 500;
		text-decoration: none; }
	/* Elementor's shape: a flat colour plus a TRANSLUCENT hover shade. */
	#shaded { background-color: rgb(135, 100, 160); color: rgb(255, 255, 255);
		background-image: linear-gradient(0deg, rgba(0,0,0,.35), transparent 75%);
		padding: 12px 24px; border-radius: 100px; text-decoration: none; }
	/* A genuine gradient button (opaque stops). */
	#gradient { background-image: linear-gradient(180deg, rgb(255, 207, 14) 0%, rgb(255, 183, 14) 100%);
		color: rgb(36, 36, 36); padding: 12px 24px; border-radius: 100px; text-decoration: none; }
	/* Hidden controls are skipped — a builder hides conversion pills on phones. */
	#hidden { display: none; background: rgb(0, 0, 0); color: #fff; }
	/* A pill the source hides ONLY on phones (Elementor hidden-mobile). */
	@media (max-width: 767px) {
		#shaded { display: none; }
	}
</style>
</head>
<body>
	<header id="masthead">
		<a class="nav-link" href="/">Home</a>
		<a id="accent" class="elementor-button" href="#">Try Demo</a>
		<a id="shaded" class="elementor-button btn" href="/shaded">Shaded</a>
		<a id="gradient" class="aux-button" href="/gradient">Gradient</a>
		<a id="hidden" class="elementor-button" href="/hidden">Hidden</a>
	</header>
</body>
</html>`;

describe.skipIf( ! hasChromium )( 'measureHeaderActions — the pills as computed', () => {
	let server: Server;
	let actions: ForgeMeasuredHeaderAction[];

	beforeAll( async () => {
		server = createServer( ( request, response ) => {
			response.writeHead( 200, { 'content-type': 'text/html; charset=utf-8' } );
			response.end( FIXTURE_HTML );
		} );
		await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		const address = server.address();
		const port = typeof address === 'object' && address ? address.port : 0;
		actions = await measureHeaderActions( { url: `http://127.0.0.1:${ port }/`, timeout: 30000 } );
	}, 60000 );

	afterAll( async () => {
		if ( server ) {
			await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
		}
	} );

	it( 'measures the labelled controls in DOM order and stops at two', () => {
		expect( actions.map( ( action ) => action.text ) ).toEqual( [ 'Try Demo', 'Shaded' ] );
		expect( actions[ 1 ].href ).toBe( '/shaded' );
	} );

	it( 'reads the computed fill and ink', () => {
		expect( actions[ 0 ].fill ).toBe( '#8764a0' );
		expect( actions[ 0 ].ink ).toBe( '#ffffff' );
	} );

	it( 'ignores a translucent hover shade and keeps the flat colour', () => {
		expect( actions[ 1 ].fill ).toBe( '#8764a0' );
	} );

	it( 'carries the pill box', () => {
		expect( actions[ 0 ].style ).toMatchObject( {
			padding: '12px 24px',
			radius: '100px',
			size: '16px',
			weight: '500',
		} );
	} );

	it( 'records a pill the source hides on phones', () => {
		// Showing it at 390px pushed the rebuild's header row past the viewport
		// (eca.directory's “Try De…”).
		expect( actions[ 1 ].hideMobile ).toBe( true );
		expect( actions[ 0 ].hideMobile ).toBeUndefined();
	} );
} );
