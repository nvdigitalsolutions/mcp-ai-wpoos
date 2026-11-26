// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// parallax-browser.test.ts — the axis contract in a REAL browser (2026-11-17).
//
// The pure suites pin the facts and the emitted CSS text. This one proves the
// last link: that Chrome actually BINDS the emitted rule and moves the element
// on the axis we claim. Nothing here re-implements the renderer — the fixture
// is served with the template's own global.css plus the exact `parallaxBandCss()`
// output, so a regression in either artifact fails here.
//
// The fixture is hermetic (an ephemeral 127.0.0.1 server, inline SVG data URIs,
// no third-party request). Chromium is the devDependency the other browser
// suites already use; when the binary is missing the suite SKIPS rather than
// passing silently.
//
// Why the range maths matter: the band's rule is `animation-range: cover 0%
// cover 100%`, so progress is 0 when the carrier's top edge meets the viewport
// bottom and 1 when its bottom edge meets the viewport top. The fixture scrolls
// to just inside each end and reads the COMPUTED translate.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { readFileSync } from 'node:fs';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { chromium, type Browser, type Page } from 'playwright';

import { parallaxBandCss } from '../templates/blog/src/lib/motion.ts';
import type { ParallaxPiece, ScrollBehaviorEntry } from '../templates/blog/src/lib/behaviors.generated.ts';

const hasChromium = ( () => {
	try {
		return Boolean( chromium.executablePath() );
	} catch {
		return false;
	}
} )();

/** The stylesheet a scaffolded site actually ships. */
const GLOBAL_CSS = readFileSync(
	new URL( '../templates/blog/src/styles/global.css', import.meta.url ),
	'utf8'
);

const PIXEL = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8'%3E%3Crect width='8' height='8' fill='%23cccccc'/%3E%3C/svg%3E";

const SECTION_ID = 'abc1234';

/** One parallax band with the two media carriers the renderer targets. */
const band = ( id: string ): string =>
	`<section class="ds-parallax" data-section-id="${ id }" data-section="gallery">` +
	`<div class="ds-media"><img alt="" style="display:block;width:100%;height:420px" src="${ PIXEL }"></div>` +
	`<div class="ds-media"><img alt="" style="display:block;width:100%;height:420px" src="${ PIXEL }"></div>` +
	`</section>`;

/** A fixture page: spacer → band → spacer, so the band can traverse the viewport. */
function fixture( piece: ParallaxPiece ): string {
	const entry: ScrollBehaviorEntry = { id: SECTION_ID, kind: 'parallax', pieces: [ piece ] };
	const emitted = parallaxBandCss( { id: SECTION_ID, kind: 'gallery' }, entry );

	return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Parallax axis fixture</title>
<style>${ GLOBAL_CSS }</style>
<style>${ emitted }</style>
</head>
<body>
	<div style="height:1200px">above</div>
	${ band( SECTION_ID ) }
	<div style="height:2000px">below</div>
</body>
</html>`;
}

const FIXTURES: Record< string, string > = {
	horizontal: fixture( { axis: 'x', speed: 2, direction: 'positive' } ),
	vertical: fixture( { depth: 0.1, origin: 'bottom' } ),
};

type Translate = { x: number; y: number; raw: string };

/** Scroll instantly (never `smooth`), settle two frames, then read the value. */
async function translateAt( page: Page, scrollY: number ): Promise< Translate > {
	await page.evaluate( ( y ) => window.scrollTo( { top: y, behavior: 'instant' as ScrollBehavior } ), scrollY );
	await page.evaluate(
		() => new Promise< void >( ( resolve ) => requestAnimationFrame( () => requestAnimationFrame( () => resolve() ) ) )
	);

	return page.evaluate( () => {
		const el = document.querySelector( '.ds-media' ) as HTMLElement;
		const raw = window.getComputedStyle( el ).translate;
		const parts = raw === 'none' ? [] : raw.trim().split( /\s+/ );
		const px = ( value: string | undefined ) => ( value ? Number.parseFloat( value ) : 0 );
		return { x: px( parts[ 0 ] ), y: px( parts[ 1 ] ), raw };
	} );
}

/** The scroll positions just INSIDE each end of the `cover 0% → 100%` range. */
async function rangeEnds( page: Page ): Promise< { start: number; end: number } > {
	const geometry = await page.evaluate( () => {
		const el = document.querySelector( '.ds-media' ) as HTMLElement;
		const rect = el.getBoundingClientRect();
		return { top: rect.top + window.scrollY, height: rect.height, viewport: window.innerHeight };
	} );

	return {
		start: Math.max( 0, Math.round( geometry.top - geometry.viewport ) + 8 ),
		end: Math.round( geometry.top + geometry.height ) - 8,
	};
}

describe.skipIf( ! hasChromium )( 'parallax axis in a real browser', () => {
	let server: Server;
	let browser: Browser;
	let page: Page;
	let url = '';

	beforeAll( async () => {
		server = createServer( ( request, response ) => {
			const key = ( request.url ?? '/' ).replace( /^\//, '' ) || 'horizontal';
			const body = FIXTURES[ key ] ?? FIXTURES.horizontal;
			response.writeHead( 200, { 'content-type': 'text/html; charset=utf-8' } );
			response.end( body );
		} );
		await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		const address = server.address();
		const port = typeof address === 'object' && address ? address.port : 0;
		url = `http://127.0.0.1:${ port }/`;

		browser = await chromium.launch();
		page = await browser.newPage( { viewport: { width: 900, height: 720 } } );
	}, 90000 );

	afterAll( async () => {
		await browser?.close();
		if ( server ) {
			await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
		}
	} );

	it( 'runs on a browser that supports scroll-driven animation (else this suite is vacuous)', async () => {
		await page.goto( `${ url }horizontal` );
		const supported = await page.evaluate( () => CSS.supports( 'animation-timeline', 'scroll()' ) );
		expect( supported, 'Chromium without `animation-timeline: scroll()` cannot prove the binding' ).toBe( true );
	} );

	it( 'moves a HORIZONTAL piece on X and leaves Y frozen', async () => {
		await page.goto( `${ url }horizontal` );
		const ends = await rangeEnds( page );

		const start = await translateAt( page, ends.start );
		const end = await translateAt( page, ends.end );

		// The binding proof: the emitted rule reaches the computed style.
		expect( start.raw, 'the piece rule did not bind at all' ).not.toBe( 'none' );
		expect(
			Math.abs( end.x - start.x ),
			`translate did not move on X (start ${ start.raw } → end ${ end.raw })`
		).toBeGreaterThan( 8 );

		// The regression this file exists for: a horizontal source rendered as a
		// vertical drift. Y must stay pinned at 0 at BOTH ends.
		expect( Math.abs( start.y ) ).toBeLessThan( 0.5 );
		expect( Math.abs( end.y ) ).toBeLessThan( 0.5 );
	}, 30000 );

	it( 'moves a VERTICAL piece on Y and leaves X at zero (the unchanged contract)', async () => {
		await page.goto( `${ url }vertical` );
		const ends = await rangeEnds( page );

		const start = await translateAt( page, ends.start );
		const end = await translateAt( page, ends.end );

		expect(
			Math.abs( end.y - start.y ),
			`translate did not move on Y (start ${ start.raw } → end ${ end.raw })`
		).toBeGreaterThan( 8 );
		expect( Math.abs( start.x ) ).toBeLessThan( 0.5 );
		expect( Math.abs( end.x ) ).toBeLessThan( 0.5 );
	}, 30000 );

	it( 'does not animate at all under prefers-reduced-motion: reduce', async () => {
		const reduced = await browser.newContext( { reducedMotion: 'reduce', viewport: { width: 900, height: 720 } } );
		const reducedPage = await reduced.newPage();
		try {
			await reducedPage.goto( `${ url }horizontal` );
			const ends = await rangeEnds( reducedPage );

			const start = await translateAt( reducedPage, ends.start );
			const end = await translateAt( reducedPage, ends.end );

			expect( start.raw === 'none' || ( start.x === 0 && start.y === 0 ) ).toBe( true );
			expect( end.x ).toBe( 0 );
			expect( end.y ).toBe( 0 );
		} finally {
			await reduced.close();
		}
	}, 30000 );
} );
