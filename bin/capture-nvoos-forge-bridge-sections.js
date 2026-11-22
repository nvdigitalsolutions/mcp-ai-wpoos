#!/usr/bin/env node
/**
 * Capture the two NEW wp.org listing screenshots for nvoos-forge-bridge:
 * the Stored redirects section (screenshot 3) and the managed-frontend
 * revalidate fields (screenshot 4).
 *
 * Screenshots 1 and 2 (full settings page, unconfigured/configured) come from
 * bin/capture-nvoos-forge-bridge-screenshots.js and are left untouched — that
 * script needs the plugin reset to the unconfigured state, while this one
 * captures scoped sections and is safe to run against a configured site.
 *
 * Prerequisites on the target site:
 *   - the plugin active, admin login available;
 *   - for the "masked credential" look, a saved revalidate URL/token and a
 *     saved export token (see WPORG-REVIEW-18POINT-SIGNOFF.md for the demo
 *     values used).
 *
 * Outputs:
 *   plugins/nvoos-forge-bridge/.wordpress-org/assets/screenshot-3.png
 *   plugins/nvoos-forge-bridge/.wordpress-org/assets/screenshot-4.png
 *
 * Usage:
 *   node bin/capture-nvoos-forge-bridge-sections.js
 *
 * Environment variables (all optional):
 *   WORDPRESS_URL - WordPress URL (default http://localhost:8000)
 *   ADMIN_USER    - admin username (default admin)
 *   ADMIN_PASS    - admin password (default password)
 */

const fs = require( 'fs' );
const path = require( 'path' );

const BASE_URL = process.env.WORDPRESS_URL || 'http://localhost:8000';
const ADMIN_USER = process.env.ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS || 'password';
const OUT_DIR = path.join(
	__dirname,
	'..',
	'plugins',
	'nvoos-forge-bridge',
	'.wordpress-org',
	'assets'
);
const SETTINGS_PATH = '/wp-admin/options-general.php?page=nvoos-forge-bridge';

const PAD = 10;

/**
 * Union of two bounding boxes, expanded by PAD and clamped to the viewport.
 *
 * @param {{x:number,y:number,width:number,height:number}} a Box A.
 * @param {{x:number,y:number,width:number,height:number}} b Box B.
 * @param {number}                                        vw Viewport width.
 * @return {{x:number,y:number,width:number,height:number}} Clip rect.
 */
function union( a, b, vw ) {
	const x = Math.max( 0, Math.min( a.x, b.x ) - PAD );
	const y = Math.max( 0, Math.min( a.y, b.y ) - PAD );
	const right = Math.min( vw, Math.max( a.x + a.width, b.x + b.width ) + PAD );
	const bottom = Math.max( a.y + a.height, b.y + b.height ) + PAD;
	return { x, y, width: right - x, height: bottom - y };
}

async function main() {
	const playwright = require( 'playwright' );
	const browser = await playwright.chromium.launch( { headless: true } );

	try {
		const context = await browser.newContext( {
			viewport: { width: 1440, height: 900 },
			deviceScaleFactor: 1,
		} );
		const page = await context.newPage();
		page.setDefaultTimeout( 240000 );
		page.setDefaultNavigationTimeout( 240000 );

		console.log( 'Logging in...' );
		await page.goto( BASE_URL + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
		await page.fill( '#user_login', ADMIN_USER );
		await page.fill( '#user_pass', ADMIN_PASS );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
			page.click( '#wp-submit' ),
		] );

		fs.mkdirSync( OUT_DIR, { recursive: true } );
		const viewport = page.viewportSize();

		console.log( 'Opening Settings -> Forge Bridge...' );
		await page.goto( BASE_URL + SETTINGS_PATH, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( 'h1:has-text("Forge Bridge")' );
		await page.waitForSelector( '#revalidate_url' );

		// ── Screenshot 3: Stored redirects section ───────────────
		console.log( 'Screenshot 3: Stored redirects section...' );
		const redirectsHeading = page.locator( 'h2', { hasText: 'Stored redirects' } ).first();
		const redirectsTable = redirectsHeading.locator( 'xpath=following-sibling::table[1]' );
		await redirectsHeading.evaluate( ( el ) =>
			el.scrollIntoView( { block: 'center', inline: 'nearest' } )
		);
		const redirectsClip = union(
			await redirectsHeading.boundingBox(),
			await redirectsTable.boundingBox(),
			viewport.width
		);
		await page.screenshot( {
			path: path.join( OUT_DIR, 'screenshot-3.png' ),
			clip: redirectsClip,
		} );

		// ── Screenshot 4: managed-frontend revalidate fields ─────
		console.log( 'Screenshot 4: revalidate fields...' );
		const revalidateUrlRow = page.locator( 'tr', { has: page.locator( '#revalidate_url' ) } ).first();
		const revalidateTokenRow = page
			.locator( 'tr', { has: page.locator( '#revalidate_token' ) } )
			.first();
		await revalidateTokenRow.evaluate( ( el ) =>
			el.scrollIntoView( { block: 'center', inline: 'nearest' } )
		);
		const revalidateClip = union(
			await revalidateUrlRow.boundingBox(),
			await revalidateTokenRow.boundingBox(),
			viewport.width
		);
		await page.screenshot( {
			path: path.join( OUT_DIR, 'screenshot-4.png' ),
			clip: revalidateClip,
		} );

		console.log( 'Done. Outputs:' );
		console.log( '  ' + path.join( OUT_DIR, 'screenshot-3.png' ) );
		console.log( '  ' + path.join( OUT_DIR, 'screenshot-4.png' ) );
	} finally {
		await browser.close();
	}
}

main().catch( ( err ) => {
	console.error( err );
	process.exit( 1 );
} );
