// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// page-settle.ts — wait until a source page's boxes have stopped moving.
//
// Every browser pass that MEASURES a live page needs the same precondition:
// fonts resolved (they change every heading's height), lazy images fetched
// (they change every band's height), and animations off (a mid-flight
// transition reports a colour/box the page never rests at).
//
// Extracted from `geometry.ts` when the COLOUR pass was found measuring the
// page at `load` with no settle: eca.directory's form fields and accent button
// still spanned the full 1440px band row, so the descendant-fill scan read a
// 53px-tall button as the band's surface and baked `#8764a0` over two
// transparent bands (2026-11-23). A measurement is only as good as the moment
// it is taken.
// ---------------------------------------------------------------------------

import type { Page } from 'playwright';

/**
 * Wait until the page's boxes have stopped moving: fonts loaded, every
 * viewport scrolled through so lazy images have fetched, and animations off.
 * Lazy image loading is the one thing that silently changes band height.
 */
export async function settle( page: Page ): Promise< void > {
	await page.addStyleTag( {
		content:
			'*,*::before,*::after{animation:none!important;transition:none!important;' +
			'scroll-behavior:auto!important}html{scroll-behavior:auto!important}',
	} );
	await page.evaluate( async () => {
		const doc = document as Document & { fonts?: { ready: Promise< unknown > } };
		if ( doc.fonts ) {
			await doc.fonts.ready;
		}
		const height = document.body.scrollHeight;
		for ( let y = 0; y < height; y += window.innerHeight ) {
			window.scrollTo( 0, y );
			await new Promise( ( resolve ) => setTimeout( resolve, 60 ) );
		}
		window.scrollTo( 0, 0 );
		await Promise.all(
			Array.from( document.images )
				.filter( ( image ) => ! image.complete )
				.map(
					( image ) =>
						new Promise( ( resolve ) => {
							image.addEventListener( 'load', resolve, { once: true } );
							image.addEventListener( 'error', resolve, { once: true } );
							setTimeout( resolve, 3000 );
						} )
				)
		);
	} );
}
