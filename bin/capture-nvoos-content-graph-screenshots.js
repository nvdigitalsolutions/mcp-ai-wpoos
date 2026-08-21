#!/usr/bin/env node
/**
 * Capture wp.org screenshots for the standalone nvoos-content-graph plugin.
 *
 * Targets the running QA WordPress container (http://localhost:8000) with
 * the plugin active, a seeded graph, and the [nvoos_graph] demo page.
 *
 * Outputs:
 *   plugins/nvoos-content-graph/.wordpress-org/assets/screenshot-{1..5}.png
 *
 * Usage:
 *   node bin/capture-nvoos-content-graph-screenshots.js
 */

const fs = require( 'fs' );
const path = require( 'path' );

const BASE_URL = process.env.WORDPRESS_URL || 'http://localhost:8000';
const ADMIN_USER = process.env.ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS || 'StrongPassword123!';
const EMBED_PAGE_ID = process.env.EMBED_PAGE_ID || '45';
const OUT_DIR = path.join( __dirname, '..', 'plugins', 'nvoos-content-graph', '.wordpress-org', 'assets' );

async function main() {
	const playwright = require( 'playwright' );
	const browser = await playwright.chromium.launch( { headless: true } );
	const context = await browser.newContext( {
		viewport: { width: 1440, height: 900 },
		deviceScaleFactor: 1,
	} );
	const page = await context.newPage();

	// The QA site boots a heavy plugin stack, so single-page responses can
	// take 20–60s. Give every navigation and selector a generous budget
	// instead of Playwright's 30s defaults.
	page.setDefaultTimeout( 120000 );
	page.setDefaultNavigationTimeout( 120000 );

	try {
		// ── Login ────────────────────────────────────────────────
		console.log( 'Logging in...' );
		await page.goto( `${ BASE_URL }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
		await page.fill( '#user_login', ADMIN_USER );
		await page.fill( '#user_pass', ADMIN_PASS );
		await Promise.all( [
			page.waitForURL( /wp-admin/ ),
			page.click( '#wp-submit' ),
		] );
		await page.waitForSelector( '#wpadminbar', { timeout: 60000 } );
		console.log( 'Logged in.' );

		// ── 1. Graph Explorer ───────────────────────────────────
		console.log( 'Capturing screenshot-1.png (Graph Explorer)...' );
		await page.goto( `${ BASE_URL }/wp-admin/admin.php?page=nvoos-content-graph`, { waitUntil: 'load' } );
		await page.waitForSelector( '#nvoos-content-graph-explorer canvas', { timeout: 60000 } );
		await page.waitForTimeout( 5000 ); // Let the fcose layout settle.
		await page.locator( '.nvoos-content-graph-explorer-wrap' ).screenshot( { path: path.join( OUT_DIR, 'screenshot-1.png' ) } );

		// ── 2. Settings page (General tab) ───────────────────────
		console.log( 'Capturing screenshot-2.png (Settings)...' );
		await page.goto( `${ BASE_URL }/wp-admin/admin.php?page=nvoos-content-graph&tab=general`, { waitUntil: 'load' } );
		await page.waitForSelector( 'h2.nav-tab-wrapper' );
		await page.locator( '.nvoos-content-graph-admin form' ).screenshot( { path: path.join( OUT_DIR, 'screenshot-2.png' ) } );

		// ── 3. Remote Sources tab ────────────────────────────────
		console.log( 'Capturing screenshot-3.png (Remote Sources)...' );
		await page.goto( `${ BASE_URL }/wp-admin/admin.php?page=nvoos-content-graph&tab=remote`, { waitUntil: 'load' } );
		await page.waitForSelector( '.nvoos-content-graph-remote-sources' );
		await page.locator( '.nvoos-content-graph-remote-sources' ).screenshot( { path: path.join( OUT_DIR, 'screenshot-3.png' ) } );

		// ── 4. Sources tab (CPT / CCT selection) ─────────────────
		console.log( 'Capturing screenshot-4.png (Sources)...' );
		await page.goto( `${ BASE_URL }/wp-admin/admin.php?page=nvoos-content-graph&tab=sources`, { waitUntil: 'load' } );
		await page.waitForSelector( 'table.widefat.striped' );
		await page.locator( '.nvoos-content-graph-admin' ).screenshot( { path: path.join( OUT_DIR, 'screenshot-4.png' ) } );

		// ── 5. Frontend shortcode embed ──────────────────────────
		console.log( 'Capturing screenshot-5.png (Frontend embed)...' );
		await page.goto( `${ BASE_URL }/?page_id=${ EMBED_PAGE_ID }`, { waitUntil: 'load' } );
		await page.waitForSelector( '.nvoos-content-graph-embed canvas', { timeout: 60000 } );
		await page.waitForTimeout( 5000 );
		await page.addStyleTag( { content: '#wpadminbar { display: none !important; }' } );
		await page.screenshot( { path: path.join( OUT_DIR, 'screenshot-5.png' ), fullPage: true } );

		console.log( '\nAll screenshots captured:' );
		for ( let i = 1; i <= 5; i++ ) {
			const file = path.join( OUT_DIR, `screenshot-${ i }.png` );
			console.log( `  ${ file } (${ ( fs.statSync( file ).size / 1024 ).toFixed( 1 ) } KB)` );
		}
	} catch ( err ) {
		console.error( 'CAPTURE FAILED:', err.message );
		process.exitCode = 1;
	} finally {
		await browser.close();
	}
}

fs.mkdirSync( OUT_DIR, { recursive: true } );
main();
