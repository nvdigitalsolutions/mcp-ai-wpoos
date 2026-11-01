#!/usr/bin/env node
/**
 * Capture wp.org screenshots for the standalone nvoos-forge-bridge plugin.
 *
 * Targets the running QA WordPress container (http://localhost:8000) with
 * the plugin active (symlinked into wp-content/plugins and activated).
 *
 * Outputs:
 *   plugins/nvoos-forge-bridge/.wordpress-org/assets/screenshot-{1..2}.png
 *
 * Screenshots:
 *   1. Settings — Forge Bridge page, unconfigured (warning notice)
 *   2. Settings — configured + saved (success notice, masked credentials)
 *
 * Usage:
 *   node bin/capture-nvoos-forge-bridge-screenshots.js
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
const OUT_DIR = path.join( __dirname, '..', 'plugins', 'nvoos-forge-bridge', '.wordpress-org', 'assets' );
const SETTINGS_PATH = '/wp-admin/options-general.php?page=nvoos-forge-bridge';

async function main() {
	const playwright = require( 'playwright' );
	const browser = await playwright.chromium.launch( { headless: true } );

	try {
		const adminContext = await browser.newContext( {
			viewport: { width: 1440, height: 900 },
			deviceScaleFactor: 1,
		} );
		const page = await adminContext.newPage();

		// The QA site boots a heavy plugin stack, so single-page responses
		// can take 20-60s. Give every navigation a generous budget.
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

		// ── Screenshot 1: unconfigured state ─────────────────────
		console.log( 'Screenshot 1: unconfigured settings page...' );
		await page.goto( BASE_URL + SETTINGS_PATH, { waitUntil: 'domcontentloaded' } );
		// Wait for the real page chrome, not the admin shell skeleton.
		await page.waitForSelector( 'h1:has-text("Forge Bridge")', { timeout: 240000 } );
		await page.waitForSelector( '.notice-warning', { timeout: 240000 } );
		await page.screenshot( {
			path: path.join( OUT_DIR, 'screenshot-1.png' ),
			fullPage: true,
		} );

		// ── Configure + save ─────────────────────────────────────
		console.log( 'Configuring and saving...' );
		await page.check( '#rebuild_enabled' );
		await page.fill( '#rebuild_url', 'https://api.github.com/repos/OWNER/REPO/dispatches' );
		await page.fill( '#rebuild_token', 'demo-token-never-echoed' );
		await page.fill( '#rebuild_secret', 'demo-secret-never-echoed' );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
			page.click( '#submit' ),
		] );

		// ── Screenshot 2: configured + saved state ───────────────
		console.log( 'Screenshot 2: configured + saved...' );
		await page.waitForSelector( '.notice-success', { timeout: 240000 } );
		await page.waitForSelector( 'h1:has-text("Forge Bridge")', { timeout: 240000 } );
		await page.screenshot( {
			path: path.join( OUT_DIR, 'screenshot-2.png' ),
			fullPage: true,
		} );

		console.log( 'Done. Outputs:' );
		console.log( '  ' + path.join( OUT_DIR, 'screenshot-1.png' ) );
		console.log( '  ' + path.join( OUT_DIR, 'screenshot-2.png' ) );
	} finally {
		await browser.close();
	}
}

main().catch( ( err ) => {
	console.error( err );
	process.exit( 1 );
} );
