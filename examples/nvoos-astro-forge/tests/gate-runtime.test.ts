// SPDX-License-Identifier: MIT
/**
 * @fileoverview Pins the emitted front controller by booting it on a real socket.
 *
 * Fixtures are temp projects and signed cookies; no live CMS is required.
 */
// ---------------------------------------------------------------------------
// gate-runtime.test.ts — the emitted front controller, served for real.
//
// The scaffold ships THREE generated modules, so a unit test that only greps
// them proves nothing. This boots `server.js` on a loopback port with a stub
// Astro entry and asserts the request ladder end to end:
//
//   gate (503 / coming-soon / bypass / manager session) → static → Astro
//
// The manager session is signed HERE with the `manage.v1.` recipe, which also
// pins the contract between forge-gate/runtime.mjs and lib/manage-session.ts.
// ---------------------------------------------------------------------------

import { createHmac } from 'node:crypto';
import type { AddressInfo } from 'node:net';
import { spawn, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { renderFrontController, renderGateRuntime, renderGateStatic } from '../lib/gate-runtime.ts';

const GATE_SECRET = 'gate-secret';
const PUSH_TOKEN = 'push-token';
const MANAGED_SECRET = 'manage-secret';
const COOKIE = 'nvoos_forge_session';
const BYPASS_COOKIE = 'nvoos_gate';

let dir = '';
let server: ChildProcess | undefined;
let base = '';

function freePort(): Promise< number > {
	return new Promise( ( resolve, reject ) => {
		const probe = createServer();
		probe.on( 'error', reject );
		probe.listen( 0, '127.0.0.1', () => {
			const { port } = probe.address() as AddressInfo;
			probe.close( () => resolve( port ) );
		} );
	} );
}

/** `<exp>.<sig>` — the bypass token the runtime accepts. */
function bypassToken( ttl = 600 ): string {
	const exp = Math.floor( Date.now() / 1000 ) + ttl;
	const sig = createHmac( 'sha256', GATE_SECRET ).update( `bypass.v1.${ exp }` ).digest( 'hex' );
	return `${ exp }.${ sig }`;
}

/** `exp.uid.base64url(name).hmac` — the /manage session the gate verifies. */
function manageSession( over: { exp?: number; secret?: string; uid?: string } = {} ): string {
	const exp = over.exp ?? Math.floor( Date.now() / 1000 ) + 3600;
	const uid = over.uid ?? '7';
	const name = Buffer.from( 'Manager', 'utf8' ).toString( 'base64url' );
	const payload = `${ exp }.${ uid }.${ name }`;
	const sig = createHmac( 'sha256', over.secret ?? MANAGED_SECRET )
		.update( `manage.v1.${ payload }` )
		.digest( 'hex' );
	return `${ payload }.${ sig }`;
}

async function waitForListening( child: ChildProcess, timeoutMs = 20_000 ): Promise< void > {
	const deadline = Date.now() + timeoutMs;
	let output = '';
	while ( Date.now() < deadline ) {
		if ( child.exitCode !== null ) {
			throw new Error( `server exited early: ${ output }` );
		}
		const ready = await new Promise< boolean >( ( resolve ) => {
			const onData = ( chunk: Buffer ): void => {
				output += String( chunk );
				child.stdout?.off( 'data', onData );
				resolve( true );
			};
			child.stdout?.once( 'data', onData );
			setTimeout( () => {
				child.stdout?.off( 'data', onData );
				resolve( false );
			}, 100 );
		} );
		if ( ready && output.includes( 'listening' ) ) {
			return;
		}
		if ( ready ) {
			// Keep waiting for the banner.
			continue;
		}
		if ( output.includes( 'listening' ) ) {
			return;
		}
	}
	throw new Error( `server did not listen: ${ output }` );
}

beforeAll( async () => {
	dir = await mkdtemp( path.join( tmpdir(), 'forge-gate-runtime-' ) );
	const port = await freePort();
	base = `http://127.0.0.1:${ port }`;

	// The artifact tree the front controller expects.
	await mkdir( path.join( dir, 'forge-gate' ), { recursive: true } );
	await mkdir( path.join( dir, 'dist', 'client', 'coming-soon' ), { recursive: true } );
	await mkdir( path.join( dir, 'dist', 'client', 'maintenance' ), { recursive: true } );
	await mkdir( path.join( dir, 'dist', 'client', '_astro' ), { recursive: true } );
	await mkdir( path.join( dir, 'dist', 'server' ), { recursive: true } );

	await writeFile( path.join( dir, 'forge-gate', 'runtime.mjs' ), renderGateRuntime(), 'utf8' );
	await writeFile( path.join( dir, 'forge-gate', 'static.mjs' ), renderGateStatic(), 'utf8' );
	await writeFile( path.join( dir, 'server.js' ), renderFrontController(), 'utf8' );

	await writeFile( path.join( dir, 'dist', 'client', 'index.html' ), '<html>REAL SITE</html>', 'utf8' );
	await writeFile( path.join( dir, 'dist', 'client', 'robots.txt' ), 'User-agent: *', 'utf8' );
	// A prerendered page: build.format 'directory' writes this and ONLY this,
	// so /about-us has no route of its own (WordPress 301s the un-slashed form).
	await mkdir( path.join( dir, 'dist', 'client', 'about-us' ), { recursive: true } );
	await writeFile(
		path.join( dir, 'dist', 'client', 'about-us', 'index.html' ),
		'<html>ABOUT PAGE</html>',
		'utf8'
	);
	await writeFile(
		path.join( dir, 'dist', 'client', 'coming-soon', 'index.html' ),
		'<html>COMING SOON PAGE</html>',
		'utf8'
	);
	await writeFile(
		path.join( dir, 'dist', 'client', 'maintenance', 'index.html' ),
		'<html>MAINTENANCE PAGE</html>',
		'utf8'
	);
	await writeFile( path.join( dir, 'dist', 'client', '_astro', 'app.js' ), 'console.log(1)', 'utf8' );
	await writeFile(
		path.join( dir, 'dist', 'server', 'entry.mjs' ),
		'export function handler(req, res) {\n' +
			"\tres.statusCode = 200;\n" +
			"\tres.setHeader('content-type', 'text/html; charset=utf-8');\n" +
			"\tconst xfh = req.headers['x-forwarded-host'] || '';\n" +
			"\tres.end('<html>APP HANDLER xfh=' + xfh + '</html>');\n" +
			'}\n',
		'utf8'
	);

	await writeFile(
		path.join( dir, 'gate.generated.json' ),
		JSON.stringify(
			{
				mode: 'maintenance',
				source: 'bridge',
				plugin: 'elementor',
				retryAfter: 900,
				checkedAt: new Date().toISOString(),
				honored: true,
				policy: 'replace',
				page: 'maintenance',
				comingSoonIndexable: false,
				session: { bypass: true, path: '/wp-json/nvoos-commerce/v1/auth/me', cookieName: COOKIE },
				bypass: { cookieName: BYPASS_COOKIE, ttlSeconds: 600 },
				managed: { enabled: false, pollSeconds: 60, maxStalenessSeconds: 900 },
				retryAfterSeconds: 600,
				honor: 'auto',
				trust: 'bridge',
			},
			null,
			'\t'
		),
		'utf8'
	);

	server = spawn( process.execPath, [ path.join( dir, 'server.js' ) ], {
		cwd: dir,
		env: {
			...process.env,
			PORT: String( port ),
			HOST: '127.0.0.1',
			NVOOS_FORGE_GATE_SECRET: GATE_SECRET,
			NVOOS_MANAGE_SECRET: MANAGED_SECRET,
			NVOOS_REVALIDATE_TOKEN: PUSH_TOKEN,
			NVOOS_TARGET_SITE: '',
			ASTRO_SITE: 'https://example.com',
		},
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
	await waitForListening( server );
} );

afterAll( async () => {
	if ( server ) {
		const child = server;
		const exited = new Promise< void >( ( resolve ) => child.once( 'exit', () => resolve() ) );
		child.kill();
		// Windows keeps the cwd locked until the process is really gone.
		await Promise.race( [ exited, new Promise( ( resolve ) => setTimeout( resolve, 5_000 ) ) ] );
	}
	if ( dir ) {
		await rm( dir, { recursive: true, force: true, maxRetries: 10, retryDelay: 100 } ).catch(
			() => undefined
		);
	}
} );

describe( 'the emitted front controller', () => {
	it( 'answers an anonymous HTML request with 503 + Retry-After + no-store', async () => {
		const response = await fetch( `${ base }/`, { redirect: 'manual' } );

		expect( response.status ).toBe( 503 );
		expect( response.headers.get( 'retry-after' ) ).toBe( '900' );
		expect( response.headers.get( 'cache-control' ) ).toContain( 'no-store' );
		expect( response.headers.get( 'x-robots-tag' ) ).toBe( 'noindex, nofollow' );
		expect( response.headers.get( 'vary' ) ).toContain( 'cookie' );
		expect( response.headers.get( 'x-nvoos-gate' ) ).toBe( 'maintenance' );
		expect( await response.text() ).toContain( 'MAINTENANCE PAGE' );
	} );

	it( 'leaves the allowlist alone (assets, discovery files, the API)', async () => {
		const robots = await fetch( `${ base }/robots.txt` );
		expect( robots.status ).toBe( 200 );
		expect( robots.headers.get( 'content-type' ) ).toContain( 'text/plain' );
		expect( await robots.text() ).toContain( 'User-agent' );

		// `/api/` is allowlisted → the gate passes it to the app.
		const api = await fetch( `${ base }/api/health` );
		expect( api.status ).toBe( 200 );
		expect( await api.text() ).toContain( 'APP HANDLER' );

		// `/manage` is allowlisted exactly; its slashed form must resolve to the
		// same sign-in surface instead of the holding page, or a gated site is
		// unenterable.
		const manage = await fetch( `${ base }/manage/` );
		expect( manage.status ).toBe( 200 );
		expect( await manage.text() ).toContain( 'APP HANDLER' );

		const asset = await fetch( `${ base }/_astro/app.js` );
		expect( asset.status ).toBe( 200 );
		expect( asset.headers.get( 'cache-control' ) ).toContain( 'immutable' );
	} );

	it( 'accepts a signed push and switches to coming-soon', async () => {
		const push = await fetch( `${ base }/api/gate`, {
			method: 'POST',
			headers: { authorization: `Bearer ${ PUSH_TOKEN }`, 'content-type': 'application/json' },
			body: JSON.stringify( { mode: 'coming_soon' } ),
		} );
		expect( push.status ).toBe( 200 );
		expect( ( await push.json() ) as { ok: boolean; mode: string } ).toMatchObject( {
			ok: true,
			mode: 'coming_soon',
		} );

		const response = await fetch( `${ base }/` );
		expect( response.status ).toBe( 200 );
		expect( response.headers.get( 'x-robots-tag' ) ).toBe( 'noindex, nofollow' );
		expect( await response.text() ).toContain( 'COMING SOON PAGE' );
	} );

	it( 'refuses an unsigned push', async () => {
		const push = await fetch( `${ base }/api/gate`, {
			method: 'POST',
			headers: { authorization: 'Bearer wrong', 'content-type': 'application/json' },
			body: JSON.stringify( { mode: 'off' } ),
		} );
		expect( push.status ).toBe( 401 );
		await push.body?.cancel();

		// Still coming-soon — the forged push changed nothing.
		const response = await fetch( `${ base }/` );
		expect( await response.text() ).toContain( 'COMING SOON PAGE' );
	} );

	it( 'exchanges a query token for a host-only cookie', async () => {
		const response = await fetch( `${ base }/?nvoos-gate=${ encodeURIComponent( bypassToken() ) }`, {
			redirect: 'manual',
		} );

		expect( response.status ).toBe( 302 );
		expect( response.headers.get( 'location' ) ).toBe( '/' );
		const cookies = response.headers.getSetCookie();
		expect( cookies.length ).toBe( 1 );
		expect( cookies[ 0 ] ).toContain( `${ BYPASS_COOKIE }=` );
		expect( cookies[ 0 ] ).toContain( 'HttpOnly' );
		expect( cookies[ 0 ] ).toContain( 'SameSite=Lax' );
		// Host-only: the CMS's domain never appears.
		expect( cookies[ 0 ].toLowerCase() ).not.toContain( 'domain=' );
		await response.body?.cancel();
	} );

	it( 'serves the real site to a bypass cookie, never cacheable or indexable', async () => {
		const response = await fetch( `${ base }/`, {
			headers: { cookie: `${ BYPASS_COOKIE }=${ encodeURIComponent( bypassToken() ) }` },
		} );

		expect( response.status ).toBe( 200 );
		expect( await response.text() ).toContain( 'REAL SITE' );
		expect( response.headers.get( 'x-nvoos-gate' ) ).toBe( 'bypass-link' );
		expect( response.headers.get( 'x-robots-tag' ) ).toBe( 'noindex, nofollow' );
		expect( response.headers.get( 'cache-control' ) ).toContain( 'no-store' );
	} );

	it( 'serves the real site to a verified manager session, with no CMS round-trip', async () => {
		const response = await fetch( `${ base }/`, {
			headers: { cookie: `${ COOKIE }=${ encodeURIComponent( manageSession() ) }` },
		} );

		expect( response.status ).toBe( 200 );
		expect( await response.text() ).toContain( 'REAL SITE' );
		expect( response.headers.get( 'x-nvoos-gate' ) ).toBe( 'bypass-manage' );
		expect( response.headers.get( 'cache-control' ) ).toContain( 'no-store' );
	} );

	it( 'rejects a tampered or expired manager session', async () => {
		// Tampered: one hex digit changed.
		const valid = manageSession();
		const tampered = valid.slice( 0, -1 ) + ( valid.endsWith( 'a' ) ? 'b' : 'a' );
		const first = await fetch( `${ base }/`, {
			headers: { cookie: `${ COOKIE }=${ encodeURIComponent( tampered ) }` },
		} );
		expect( await first.text() ).toContain( 'COMING SOON PAGE' );

		// Expired.
		const expired = manageSession( { exp: Math.floor( Date.now() / 1000 ) - 10 } );
		const second = await fetch( `${ base }/`, {
			headers: { cookie: `${ COOKIE }=${ encodeURIComponent( expired ) }` },
		} );
		expect( await second.text() ).toContain( 'COMING SOON PAGE' );

		// Signed with the wrong secret.
		const foreign = manageSession( { secret: 'not-the-secret' } );
		const third = await fetch( `${ base }/`, {
			headers: { cookie: `${ COOKIE }=${ encodeURIComponent( foreign ) }` },
		} );
		expect( await third.text() ).toContain( 'COMING SOON PAGE' );
	} );

	it( 'refuses a forged bypass token', async () => {
		const response = await fetch( `${ base }/?nvoos-gate=1.deadbeef`, { redirect: 'manual' } );
		expect( response.status ).toBe( 403 );
		expect( response.headers.getSetCookie().length ).toBe( 0 );
		await response.body?.cancel();
	} );

	it( 'canonicalises a prerendered page to its slashed URL', async () => {
		// Without this redirect /about-us matches no route on a managed build and
		// 404s — the exact regression against the WordPress site it replaces.
		const response = await fetch( `${ base }/about-us?utm=x`, {
			headers: { cookie: `${ BYPASS_COOKIE }=${ encodeURIComponent( bypassToken() ) }` },
			redirect: 'manual',
		} );

		expect( response.status ).toBe( 301 );
		expect( response.headers.get( 'location' ) ).toBe( '/about-us/?utm=x' );
		// The gate's no-store must survive the redirect.
		expect( response.headers.get( 'cache-control' ) ).toContain( 'no-store' );
		await response.body?.cancel();

		const followed = await fetch( `${ base }/about-us/`, {
			headers: { cookie: `${ BYPASS_COOKIE }=${ encodeURIComponent( bypassToken() ) }` },
		} );
		expect( followed.status ).toBe( 200 );
		expect( await followed.text() ).toContain( 'ABOUT PAGE' );
	} );

	it( 'leaves a genuinely missing path to the app, never redirects it', async () => {
		const response = await fetch( `${ base }/not-a-page`, {
			headers: { cookie: `${ BYPASS_COOKIE }=${ encodeURIComponent( bypassToken() ) }` },
			redirect: 'manual',
		} );

		expect( response.status ).toBe( 200 );
		expect( await response.text() ).toContain( 'APP HANDLER' );
	} );

	it( 'keeps a client host that is one of the app own names', async () => {
		// Astro compares the browser Origin with Astro.url, which the pin feeds.
		// Pinning EVERY request to the apex made a www visitor unrepresentable.
		const apex = await fetch( `${ base }/api/health`, {
			headers: { 'x-forwarded-host': 'example.com, edge' },
		} );
		expect( await apex.text() ).toContain( 'xfh=example.com' );

		const www = await fetch( `${ base }/api/health`, {
			headers: { 'x-forwarded-host': 'www.example.com' },
		} );
		expect( await www.text() ).toContain( 'xfh=www.example.com' );

		const foreign = await fetch( `${ base }/api/health`, {
			headers: { 'x-forwarded-host': 'evil.test' },
		} );
		expect( await foreign.text() ).toContain( 'xfh=example.com' );
	} );

	it( 'never serves outside dist/client', async () => {
		// The traversal is the interesting case: whatever answers (the gate page or
		// the app), it must never be a file from the project root.
		for ( const attempt of [ '/../gate.generated.json', '/%2e%2e/gate.generated.json', '/..%2fpackage.json' ] ) {
			const response = await fetch( `${ base }${ attempt }` );
			const body = await response.text();
			expect( body ).not.toContain( 'nvoos_forge_session' );
			expect( body ).not.toContain( '"managed"' );
			expect( response.status ).toBeLessThan( 500 );
		}
	} );

	it( 'treats a placeholder gate page as missing, not as an error', async () => {
		// The build always emits both pages; if one is stripped, the runtime
		// must still gate with its built-in copy rather than 500.
		const push = await fetch( `${ base }/api/gate`, {
			method: 'POST',
			headers: { authorization: `Bearer ${ PUSH_TOKEN }`, 'content-type': 'application/json' },
			body: JSON.stringify( { mode: 'maintenance' } ),
		} );
		await push.body?.cancel();
		await rm( path.join( dir, 'dist', 'client', 'maintenance' ), { recursive: true, force: true } );

		const response = await fetch( `${ base }/` );
		expect( response.status ).toBe( 503 );
		expect( await response.text() ).toContain( 'Down for maintenance' );
	} );
} );
