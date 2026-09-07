#!/usr/bin/env node
/**
 * Capture wp.org screenshots for the standalone nvoos-docs-hub plugin.
 *
 * Targets the running QA WordPress container (http://localhost:8000) with
 * the plugin active, a remote repository configured under
 * Settings → NV oOS Docs Hub, a completed documentation rebuild, and a
 * published page containing the [nvoos_docs] shortcode.
 *
 * Outputs:
 *   addons/docs-hub/.wordpress-org/assets/screenshot-{1..4}.png
 *
 * Screenshots:
 *   1. Settings — documentation index status + rebuild panel
 *   2. Settings — remote repository row + GitHub file/folder tree picker
 *   3. Frontend [nvoos_docs] embed — sidebar, content, TOC
 *   4. Frontend [nvoos_docs] embed — full-text search
 *
 * Usage:
 *   node bin/capture-nvoos-docs-hub-screenshots.js
 *
 * Environment variables (all optional):
 *   WORDPRESS_URL  - WordPress URL (default http://localhost:8000)
 *   ADMIN_USER     - admin username (default admin)
 *   ADMIN_PASS     - admin password (default password)
 *   DOCS_PAGE_PATH - frontend embed page path (default /docs-hub-test/)
 */

const fs = require( 'fs' );
const path = require( 'path' );

const BASE_URL = process.env.WORDPRESS_URL || 'http://localhost:8000';
const ADMIN_USER = process.env.ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS || 'password';
const DOCS_PAGE_PATH = process.env.DOCS_PAGE_PATH || '/docs-hub-test/';
const OUT_DIR = path.join( __dirname, '..', 'addons', 'docs-hub', '.wordpress-org', 'assets' );

async function main() {
	const playwright = require( 'playwright' );
	const browser = await playwright.chromium.launch( { headless: true } );

	try {
		// ── Admin context (login) ─────────────────────────────────
		const adminContext = await browser.newContext( {
			viewport: { width: 1440, height: 900 },
			deviceScaleFactor: 1,
		} );
		const page = await adminContext.newPage();

		// The QA site boots a heavy plugin stack, so single-page
		// responses can take 20-60s. Give every navigation and
		// selector a generous budget instead of Playwright defaults.
		page.setDefaultTimeout( 240000 );
		page.setDefaultNavigationTimeout( 240000 );

		console.log( 'Logging in...' );
		await page.goto( BASE_URL + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
		await page.fill( '#user_login', ADMIN_USER );
		await page.fill( '#user_pass', ADMIN_PASS );
		await Promise.all( [
			page.waitForURL( /wp-admin/ ),
			page.click( '#wp-submit' ),
		] );
		await page.waitForSelector( '#wpadminbar', { timeout: 120000 } );
		console.log( 'Logged in.' );

		// ── 1. Documentation index status + rebuild panel ────────
		// Clip the settings content area (`.wrap`) to viewport height so the
		// shot excludes the WP admin rail and shows the page title, the
		// status card, and the rebuild controls at listing-friendly size.
		console.log( 'Capturing screenshot-1.png (index status + rebuild panel)...' );
		await page.goto(
			BASE_URL + '/wp-admin/options-general.php?page=nvoos-docs-hub',
			{ waitUntil: 'load' }
		);
		await page.waitForSelector( '#nvoos-docs-hub-rebuild-panel', { timeout: 120000 } );
		await page.waitForTimeout( 2000 ); // Let status text settle.
		const wrap = page.locator( '.wrap' );
		const wrapBox = await wrap.boundingBox();
		// Clip just below the status card: title + status card + the broken-link
		// card header, but not its red broken-link table rows (which read
		// negatively in a listing asset).
		const statusCard = page.locator( 'div.card', { has: page.locator( '#nvoos-docs-hub-rebuild-panel' ) } );
		const statusBox = await statusCard.boundingBox();
		await page.screenshot( {
			path: path.join( OUT_DIR, 'screenshot-1.png' ),
			clip: {
				x: Math.round( wrapBox.x ),
				y: Math.round( wrapBox.y ),
				width: Math.round( wrapBox.width ),
				height: Math.round( statusBox.y - wrapBox.y + statusBox.height + 130 ),
			},
		} );

		// ── 2. Remote repositories + file/folder tree picker ─────
		console.log( 'Capturing screenshot-2.png (remote repos + tree picker)...' );
		await page.click( '.nvoos-dh-browse-btn' );
		const pickerTree = page.locator( '.nvoos-dh-picker-tree' );
		await pickerTree.waitFor( { state: 'visible', timeout: 120000 } );
		await pickerTree.locator( 'input[type="checkbox"]' ).first().waitFor( { timeout: 120000 } );
		await page.waitForTimeout( 1000 );
		const repoRow = page.locator( '.nvoos-dh-remote-repo-row' ).first();
		await repoRow.screenshot( { path: path.join( OUT_DIR, 'screenshot-2.png' ) } );
		await adminContext.close();

		// ── Frontend context (guest — public access) ─────────────
		const guestContext = await browser.newContext( {
			viewport: { width: 1440, height: 900 },
			deviceScaleFactor: 1,
		} );
		const front = await guestContext.newPage();
		front.setDefaultTimeout( 240000 );
		front.setDefaultNavigationTimeout( 240000 );

		// ── 3. Frontend embed — sidebar, content, TOC ────────────
		// The default theme constrains page content to ~620px, which drops
		// the app below its sidebar/TOC breakpoints. Widen the theme's
		// layout CSS for the capture so the full three-pane embed renders.
		console.log( 'Capturing screenshot-3.png (frontend embed)...' );
		await front.goto( BASE_URL + DOCS_PAGE_PATH, { waitUntil: 'load' } );
		await front.addStyleTag( {
			content: ':root { --wp--style--global--content-size: 1240px !important; --wp--style--global--wide-size: 1400px !important; }',
		} );
		await front.waitForSelector( '.nvoos-docs-hub-root .dh-sidebar', { timeout: 120000 } );
		// Wait for the document content to finish loading (the QA site is slow
		// and the REST page fetch can lag several seconds behind the SPA shell).
		await front.waitForFunction(
			() => {
				const el = document.querySelector( '.nvoos-docs-hub-root .dh-content' );
				return !! el && el.textContent.trim().length > 50;
			},
			{ timeout: 240000 }
		);
		await front.waitForSelector( '.nvoos-docs-hub-root .dh-toc-area', { timeout: 60000 } );
		await front.waitForTimeout( 1000 ); // Let TOC anchors + layout settle.
		await front.locator( '.nvoos-docs-hub-root[data-theme]' ).scrollIntoViewIfNeeded();
		await front.waitForTimeout( 500 );
		await front.screenshot( {
			path: path.join( OUT_DIR, 'screenshot-3.png' ),
		} );

		// ── 4. Frontend embed — full-text search ─────────────────
		// Viewport-only: the search dropdown is position:absolute and a
		// fullPage capture of the whole docs app can exceed Chromium's
		// max texture size ("Unable to capture screenshot").
		console.log( 'Capturing screenshot-4.png (full-text search)...' );
		await front.locator( '.nvoos-docs-hub-root[data-theme]' ).scrollIntoViewIfNeeded();
		await front.fill( '.dh-search-input', 'rebuild' );
		await front.waitForSelector( '.dh-search-result-title', { timeout: 120000 } );
		await front.waitForTimeout( 1000 );
		await front.screenshot( {
			path: path.join( OUT_DIR, 'screenshot-4.png' ),
		} );
		await guestContext.close();

		console.log( '\nAll screenshots captured:' );
		for ( let i = 1; i <= 4; i++ ) {
			const file = path.join( OUT_DIR, 'screenshot-' + i + '.png' );
			console.log( '  ' + file + ' (' + ( fs.statSync( file ).size / 1024 ).toFixed( 1 ) + ' KB)' );
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
