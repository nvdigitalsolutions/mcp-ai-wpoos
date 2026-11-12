// ---------------------------------------------------------------------------
// carousel-facts-browser.test.ts — `collectBandCarouselFacts` against a
// hermetic fixture.
//
// The reported drift: virtuallyjamaica's hardware gallery is a FIVE-up paged
// slider at 1440 and ONE-up at 390, and the rebuild rendered all seven slides as
// an equal split of the track (129px each at 1440, the whole row 132px tall
// against the source's 218px). The count lives in the vendor's JS options
// (`items: 5`, `responsive: { 834: { items: 2 } }`), so the only faithful source
// is the rendered geometry.
//
// This pins the measurement half: slides-per-view from the track/slide ratio,
// the gutter from the slides themselves, the dot count from the source's own
// pagination, and the unique-class selector the renderer scopes rules to.
//
// Chromium is a devDependency; when the binary is missing the suite SKIPS.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { chromium, type Browser } from 'playwright';

import { collectBandCarouselFacts } from '../lib/geometry.ts';
import type { ForgeBandCarouselFact } from '../lib/types.ts';

const hasChromium = ( () => {
	try {
		return Boolean( chromium.executablePath() );
	} catch {
		return false;
	}
} )();

const SLIDE = ( n: number ): string =>
	`<div class="slide"><img src="/slide-${ n }.svg" alt="Slide ${ n }" width="400" height="300"></div>`;

const DOTS = ( n: number ): string => `<div class="owl-dots">${ '<button class="owl-dot"></button>'.repeat( n ) }</div>`;

/** A five-up slider: the track is 1000px and each slide is 20% of it. */
const FIVE_UP = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
	*, *::before, *::after { box-sizing: border-box; }
	html, body { margin: 0; }
	.band { width: 1000px; margin: 0 auto; }
	.owl-carousel { display: flex; flex-wrap: nowrap; overflow: hidden; }
	.slide { flex: 0 0 20%; }
	.slide img { display: block; width: 100%; height: auto; }
</style>
</head>
<body>
	<section class="band" data-section-id="abc" data-section="text">
		<div class="post-body">
			<div class="gallery-carousel owl-carousel">
				${ [ 1, 2, 3, 4, 5, 6, 7 ].map( SLIDE ).join( '' ) }
			</div>
			${ DOTS( 2 ) }
		</div>
	</section>
</body>
</html>`;

/** The same markup at mobile: one slide fills the track. */
const ONE_UP = FIVE_UP.replace( '.slide { flex: 0 0 20%; }', '.slide { flex: 0 0 100%; }' ).replace(
	DOTS( 2 ),
	DOTS( 7 )
);

const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#ddd"/></svg>';

const serve = ( request: import( 'node:http' ).IncomingMessage, response: import( 'node:http' ).ServerResponse ): void => {
	if ( request.url?.startsWith( '/slide-' ) ) {
		response.writeHead( 200, { 'content-type': 'image/svg+xml' } );
		response.end( SVG );
		return;
	}
	response.writeHead( 200, { 'content-type': 'text/html; charset=utf-8' } );
	response.end( request.url?.startsWith( '/one-up' ) ? ONE_UP : FIVE_UP );
};

describe.skipIf( ! hasChromium )( 'collectBandCarouselFacts (hermetic browser probe)', () => {
	let server: Server;
	let browser: Browser;
	let url = '';
	let fiveUp: ForgeBandCarouselFact[] | undefined;
	let oneUp: ForgeBandCarouselFact[] | undefined;
	let noCarousel: Record< string, ForgeBandCarouselFact[] >;

	beforeAll( async () => {
		server = createServer( serve );
		await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		const address = server.address();
		const port = typeof address === 'object' && address ? address.port : 0;
		url = `http://127.0.0.1:${ port }`;

		browser = await chromium.launch();
		const page = await browser.newPage( { viewport: { width: 1200, height: 900 } } );

		await page.goto( `${ url }/`, { waitUntil: 'load' } );
		fiveUp = ( await page.evaluate( collectBandCarouselFacts, { ids: [ 'abc' ], kind: 'built' as const } ) ).abc;

		await page.goto( `${ url }/one-up`, { waitUntil: 'load' } );
		oneUp = ( await page.evaluate( collectBandCarouselFacts, { ids: [ 'abc' ], kind: 'built' as const } ) ).abc;

		// A band id that is not on the page costs one band, never the run.
		noCarousel = await page.evaluate( collectBandCarouselFacts, { ids: [ 'missing' ], kind: 'built' as const } );
	}, 120000 );

	afterAll( async () => {
		await browser?.close();
		if ( server ) {
			await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
		}
	} );

	it( 'counts the slides and the slides-per-view the track actually shows', () => {
		expect( fiveUp ).toEqual( [
			{ selector: 'gallery-carousel', count: 7, perView: 5, gap: 0, dots: 2 },
		] );
	} );

	it( 're-measures per viewport: the same markup is one-up at mobile', () => {
		expect( oneUp?.[ 0 ] ).toMatchObject( { count: 7, perView: 1, dots: 7 } );
	} );

	it( 'returns nothing for a band that carries no carousel', () => {
		expect( noCarousel ).toEqual( {} );
	} );
} );
