#!/usr/bin/env node
/**
 * Hermetic smoke for the assembled nvoos-funiq-velocity mirror.
 *
 * Boots the Next standalone server (`server.js` at the repo root) against
 * the DATABASE_URI / PAYLOAD_SECRET envs and asserts the licensed app's
 * Payload contract:
 *
 *   GET /admin                 → 200 text/html, references /_next/static/
 *   GET /_next/static/<asset>  → 200 (first asset referenced by the admin)
 *   GET /api/products          → 200 application/json, {docs, totalDocs}
 *   GET /api/globals/banner    → 200
 *   GET /                      → 200
 *
 * Every check above was verified against Payload 3.64 / Next 15.4 with a
 * migrated PostgreSQL (2026-10-29). /api/products asserting the Payload
 * envelope ({docs, totalDocs}) is the parity-relevant gate: it is the exact
 * contract the licensed mobile app binds to.
 *
 * Env: PORT (default 4180), HOSTNAME (default 127.0.0.1), DATABASE_URI,
 * PAYLOAD_SECRET.
 *
 * Usage: node scripts/smoke.mjs [app-dir]     (default: cwd)
 *
 * No third-party dependencies — Node >= 20.9 only.
 */

import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { resolve } from 'node:path';

const appDir = resolve( process.argv[ 2 ] || process.cwd() );
const PORT = process.env.PORT || '4180';
const HOSTNAME = process.env.HOSTNAME || '127.0.0.1';
const BASE = `http://${ HOSTNAME }:${ PORT }`;

let failures = 0;
const ok = ( message ) => console.log( `[smoke] PASS ${ message }` );
const bad = ( message ) => {
	failures += 1;
	console.error( `[smoke] FAIL ${ message }` );
};

const serverPath = resolve( appDir, 'server.js' );
if ( ! existsSync( serverPath ) ) {
	console.error( `[smoke] ${ serverPath } not found — run assemble-mirror.mjs first.` );
	process.exit( 2 );
}

const child = spawn( process.execPath, [ serverPath ], {
	cwd: appDir,
	env: { ...process.env, PORT, HOSTNAME },
	stdio: [ 'ignore', 'pipe', 'pipe' ],
} );
let logs = '';
child.stdout.on( 'data', ( chunk ) => {
	logs += chunk;
} );
child.stderr.on( 'data', ( chunk ) => {
	logs += chunk;
} );

async function waitReady( timeoutMs = 60000 ) {
	const start = Date.now();
	while ( Date.now() - start < timeoutMs ) {
		try {
			const res = await fetch( `${ BASE }/admin`, { signal: AbortSignal.timeout( 3000 ) } );
			if ( res.status > 0 ) {
				return true;
			}
		} catch {
			// Not up yet.
		}
		await new Promise( ( resolveWait ) => setTimeout( resolveWait, 750 ) );
	}
	return false;
}

async function main() {
	if ( ! ( await waitReady() ) ) {
		bad( `server did not become ready on ${ BASE }/admin within 60s` );
		console.error( logs );
		child.kill( 'SIGTERM' );
		process.exit( 1 );
	}
	ok( `server ready on ${ BASE }` );

	// /admin — the Payload admin shell (the licensed backend's UI half).
	const admin = await fetch( `${ BASE }/admin` );
	const adminHtml = await admin.text();
	const adminContentType = admin.headers.get( 'content-type' ) || '';
	if ( admin.status === 200 && adminContentType.includes( 'text/html' ) ) {
		ok( '/admin → 200 text/html' );
	} else {
		bad( `/admin → ${ admin.status } ${ adminContentType }` );
	}

	// Admin static assets (proves .next/static shipped correctly).
	const assetMatch = adminHtml.match( /\/_next\/static\/[^"']+\.js/ );
	if ( assetMatch ) {
		const assetRes = await fetch( `${ BASE }${ assetMatch[ 0 ] }` );
		if ( assetRes.status === 200 ) {
			ok( `admin asset ${ assetMatch[ 0 ] } → 200` );
		} else {
			bad( `admin asset ${ assetMatch[ 0 ] } → ${ assetRes.status }` );
		}
	} else {
		bad( '/admin HTML references no /_next/static asset' );
	}

	// /api/products — the licensed mobile app's primary contract.
	const products = await fetch( `${ BASE }/api/products` );
	const productsBody = await products.text();
	const productsContentType = products.headers.get( 'content-type' ) || '';
	const envelopeOk =
		products.status === 200 &&
		productsContentType.includes( 'application/json' ) &&
		productsBody.includes( '"docs"' ) &&
		productsBody.includes( '"totalDocs"' );
	if ( envelopeOk ) {
		ok( '/api/products → 200 JSON {docs, totalDocs} (Payload envelope)' );
	} else {
		bad(
			`/api/products → ${ products.status } ${ productsContentType } ` +
			productsBody.slice( 0, 140 )
		);
	}

	// Remaining public surface.
	for ( const path of [ '/api/globals/banner', '/api/globals/carousel', '/api/categories', '/api/brands', '/api/colors', '/api/statuses', '/api/promocodes', '/' ] ) {
		const res = await fetch( `${ BASE }${ path }` );
		if ( res.status === 200 ) {
			ok( `${ path } → 200` );
		} else {
			bad( `${ path } → ${ res.status }` );
		}
	}

	child.kill( 'SIGTERM' );
	if ( failures > 0 ) {
		console.error( logs );
		console.error( `[smoke] ${ failures } check(s) failed` );
		process.exit( 1 );
	}
	console.log( '[smoke] all checks passed' );
	process.exit( 0 );
}

main().catch( ( error ) => {
	bad( String( error ) );
	console.error( logs );
	child.kill( 'SIGTERM' );
	process.exit( 1 );
} );
