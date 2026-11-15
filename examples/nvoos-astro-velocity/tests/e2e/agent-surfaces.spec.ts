// ---------------------------------------------------------------------------
// agent-surfaces.spec.ts — the agent routes really serve (Proposal 136 §4.4).
//
// The unit tests pin the COMPOSERS; this proves the routes exist on the real
// server with the right content types, and that the page advertises them.
// ---------------------------------------------------------------------------

import { expect, test } from '@playwright/test';

const PRODUCT = '/products/1/aurora-3-seater-sofa/';

test( 'llms.txt serves the v2 shape and links the markdown variants', async ( { request } ) => {
	const response = await request.get( '/llms.txt' );

	expect( response.status() ).toBe( 200 );
	expect( response.headers()[ 'content-type' ] ).toContain( 'text/plain' );

	const body = await response.text();
	expect( body.startsWith( '# ' ) ).toBe( true );
	expect( body ).toContain( '\n> ' );
	// The variants exist now, so the index links them (never a 404).
	expect( body ).toContain( 'index.md' );
} );

test( 'a product markdown variant serves with its price', async ( { request } ) => {
	const response = await request.get( `${ PRODUCT.replace( /\/$/, '' ) }/index.md` );

	expect( response.status() ).toBe( 200 );
	expect( response.headers()[ 'content-type' ] ).toContain( 'text/markdown' );

	const body = await response.text();
	expect( body.startsWith( '# ' ) ).toBe( true );
	expect( body ).toContain( '**Price:**' );
} );

test( 'the product page advertises its markdown alternate and llms.txt', async ( { page } ) => {
	await page.goto( PRODUCT );

	await expect( page.locator( 'link[rel="describedby"][href="/llms.txt"]' ) ).toHaveCount( 1 );
	await expect( page.locator( 'link[rel="alternate"][type="text/markdown"]' ) ).toHaveCount( 1 );
} );
