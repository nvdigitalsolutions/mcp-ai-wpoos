// ---------------------------------------------------------------------------
// footer-brand-browser.test.ts — `measureFooterBrand` against a hermetic
// fixture.
//
// The reported drift: virtuallyjamaica's footer is an 8/4 row — social icons in
// a `text-left` column, the logo in a `text-right` column, both centred at
// mobile — and the rebuild stacked the logo under the icons on the left. The
// measurement has to be VISUAL (the union of the icon boxes, not the full-width
// wrapper) and per viewport, because the source re-centres at mobile.
//
// Chromium is a devDependency; when the binary is missing the suite SKIPS.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { chromium } from 'playwright';

import { measureFooterBrand } from '../lib/geometry.ts';
import type { ForgeFooterBrand } from '../lib/types.ts';

const hasChromium = ( () => {
	try {
		return Boolean( chromium.executablePath() );
	} catch {
		return false;
	}
} )();

const ICON = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle></svg>';

const FIXTURE_HTML = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
	*, *::before, *::after { box-sizing: border-box; }
	html, body { margin: 0; }
	footer { display: block; padding: 24px 0; }
	.footer-row {
		display: flex;
		align-items: center;
		justify-content: space-between;
		max-width: 1140px;
		margin: 0 auto;
		padding: 0 15px 24px;
		/* The source row's hairline above the copyright bar. */
		border-bottom: 1px solid rgb(204, 204, 204);
	}
	.socials { display: flex; gap: 18px; }
	.socials a { display: inline-flex; width: 24px; height: 24px; }
	.logo img { width: 200px; height: 40px; vertical-align: middle; }
	.copyright { text-align: center; padding-top: 24px; }
	@media (max-width: 767px) {
		/* The source's col-xs-12 columns stack and each is text-centre. */
		.footer-row { flex-direction: column; }
		.socials { justify-content: center; width: 100%; }
		.logo { text-align: center; width: 100%; }
	}
</style>
</head>
<body>
	<footer>
		<div class="footer-row">
			<div class="socials">
				<a href="#">${ ICON }</a>
				<a href="#">${ ICON }</a>
				<a href="#">${ ICON }</a>
			</div>
			<div class="logo"><img src="https://example.test/vj-logo.svg" alt="Logo"></div>
		</div>
		<p class="copyright">© 2026 Example</p>
	</footer>
</body>
</html>`;

/** A footer with no social icons has no brand row to align. */
const NO_SOCIALS_HTML = FIXTURE_HTML.replace( /<div class="socials">[\s\S]*?<\/div>\s*/u, '' );

describe.skipIf( ! hasChromium )( 'measureFooterBrand (hermetic browser probe)', () => {
	let server: Server;
	let brand: ForgeFooterBrand;
	let empty: ForgeFooterBrand;

	beforeAll( async () => {
		server = createServer( ( request, response ) => {
			const body = request.url?.startsWith( '/no-socials' ) ? NO_SOCIALS_HTML : FIXTURE_HTML;
			response.writeHead( 200, { 'content-type': 'text/html; charset=utf-8' } );
			response.end( body );
		} );
		await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		const address = server.address();
		const port = typeof address === 'object' && address ? address.port : 0;
		brand = await measureFooterBrand( { url: `http://127.0.0.1:${ port }/`, timeout: 30000 } );
		empty = await measureFooterBrand( { url: `http://127.0.0.1:${ port }/no-socials`, timeout: 30000 } );
	}, 120000 );

	afterAll( async () => {
		if ( server ) {
			await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
		}
	} );

	it( 'reads the logo at the END of the row on desktop', () => {
		expect( brand.desktop ).toEqual( { logo: 'end', socials: 'start' } );
	} );

	it( 'reads both groups centred at mobile', () => {
		expect( brand.mobile ).toEqual( { logo: 'center', socials: 'center' } );
	} );

	it( 'records the hairline above the copyright bar', () => {
		expect( brand.divider ).toBe( true );
	} );

	it( 'returns no fact when the footer has no social icons', () => {
		expect( empty ).toEqual( {} );
	} );
} );
