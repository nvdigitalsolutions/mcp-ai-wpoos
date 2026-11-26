// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// managed-proxy-behaviour.test.ts — the emitted proxy helper, EXECUTED.
//
// managed-runtime.test.ts pins the emitted text; this runs it against a real
// dead or silent upstream, because the failure mode that matters is runtime:
// a hung CMS used to hold the worker for as long as the platform allowed, and
// a refused connection used to surface as an unhandled 500 (no try/catch).
//
// The child process is the point, not a convenience: `TARGET` is read from
// process.env at module load, and a generated project runs this file the same
// way (Node strips the types; there are no Astro imports in the helper).
// ---------------------------------------------------------------------------

import { spawn } from 'node:child_process';
import { mkdtemp, writeFile } from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { gzipSync } from 'node:zlib';

import { describe, expect, it } from 'vitest';

import { renderProxyHelperTs } from '../lib/managed-runtime.ts';

interface ProxyResult {
	status: number;
	body: string;
	headers: Record< string, string >;
}

/** Run the emitted helper in a child process and report what it returned. */
async function callProxy( target: string, timeoutMs?: number, headers?: Record< string, string > ): Promise< ProxyResult > {
	const dir = await mkdtemp( path.join( os.tmpdir(), 'forge-proxy-' ) );
	await writeFile( path.join( dir, 'managed-proxy.ts' ), renderProxyHelperTs(), 'utf8' );
	await writeFile(
		path.join( dir, 'driver.mjs' ),
		[
			"import { proxyToWordPress } from './managed-proxy.ts';",
			`const request = new Request('https://public.example.com/api/x', { headers: ${ JSON.stringify( headers ?? {} ) } });`,
			"const response = await proxyToWordPress(request, '/wp-json/x');",
			'process.stdout.write(JSON.stringify({ status: response.status, headers: Object.fromEntries(response.headers), body: await response.text() }));',
		].join( '\n' ),
		'utf8'
	);

	const env: NodeJS.ProcessEnv = {
		...process.env,
		NVOOS_TARGET_SITE: target,
		ASTRO_SITE: 'https://public.example.com',
	};
	if ( timeoutMs !== undefined ) {
		env.NVOOS_PROXY_TIMEOUT_MS = String( timeoutMs );
	}

	return new Promise( ( resolve, reject ) => {
		const child = spawn( process.execPath, [ '--experimental-strip-types', path.join( dir, 'driver.mjs' ) ], { env } );
		let out = '';
		let err = '';
		child.stdout?.on( 'data', ( chunk ) => ( out += chunk ) );
		child.stderr?.on( 'data', ( chunk ) => ( err += chunk ) );
		child.on( 'close', ( code ) => {
			if ( code !== 0 ) {
				reject( new Error( `driver exited ${ code }: ${ err }` ) );
				return;
			}
			resolve( JSON.parse( out ) as ProxyResult );
		} );
	} );
}

describe( 'emitted proxy helper — runtime failure modes', () => {
	it( 'fails closed when no target is configured', async () => {
		const result = await callProxy( '' );

		expect( result.status ).toBe( 503 );
		expect( result.body ).toContain( 'NVOOS_TARGET_SITE is not configured.' );
	} );

	it( 'returns a typed 502 when the CMS refuses the connection', async () => {
		// A port that WAS listening and is now closed, so the failure is a real
		// NETWORK fault and not a visitor error or an unhandled rejection. (A
		// reserved port such as :1 never reaches a socket — undici rejects it as
		// a "bad port" — so it cannot prove the connect path.)
		const reserved = http.createServer();
		await new Promise< void >( ( resolve ) => reserved.listen( 0, '127.0.0.1', resolve ) );
		const bound = reserved.address();
		if ( ! bound || typeof bound === 'string' ) {
			throw new Error( 'reserved server failed to bind' );
		}
		const closedPort = bound.port;
		await new Promise< void >( ( resolve ) => reserved.close( () => resolve() ) );

		const result = await callProxy( `http://127.0.0.1:${ closedPort }` );

		expect( result.status ).toBe( 502 );
		const body = JSON.parse( result.body ) as { code?: string; ok?: boolean; detail?: string };
		expect( body.ok ).toBe( false );
		expect( body.code ).toBe( 'upstream_unreachable' );
		// The reason travels with the response: the body is the ONLY copy of it
		// an operator can see when an edge replaces the 5xx page (2026-11-26).
		expect( typeof body.detail ).toBe( 'string' );
		expect( body.detail?.length ?? 0 ).toBeGreaterThan( 0 );
		// Node puts "fetch failed" on `message` and the actionable code on
		// `cause`; the cause chain is the difference between a useless and a
		// diagnostic body.
		expect( body.detail ).toContain( '<-' );
		expect( body.detail ).toMatch( /ECONNREFUSED/ );
		// …and the target never travels back to the caller.
		expect( body.detail ).not.toContain( `127.0.0.1:${ closedPort }` );
	} );

	it( 'forwards only the CMS-facing headers, never the edge set', async () => {
		// The inbound request is edge-fronted: `cf-*`, `x-forwarded-*` and
		// `cdn-loop` describe the PROXY hop, and replaying them at the CMS looks
		// like a spoofed request (or a loop) to its own CDN.
		let seen: http.IncomingHttpHeaders = {};
		const server = http.createServer( ( request, response ) => {
			seen = request.headers;
			response.writeHead( 200, { 'content-type': 'application/json' } );
			response.end( '{}' );
		} );
		await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		const address = server.address();
		if ( ! address || typeof address === 'string' ) {
			throw new Error( 'fixture server failed to bind' );
		}

		try {
			const result = await callProxy( `http://127.0.0.1:${ address.port }`, undefined, {
				accept: 'text/html',
				'user-agent': 'forge-test',
				'cf-ray': 'a605c5018f2fb95e-CMB',
				'cf-connecting-ip': '203.0.113.9',
				'x-forwarded-for': '203.0.113.9',
				'cdn-loop': 'cloudflare',
			} );

			expect( result.status ).toBe( 200 );
			expect( seen.accept ).toBe( 'text/html' );
			expect( seen[ 'user-agent' ] ).toBe( 'forge-test' );
			// The same-origin browser request becomes a server-to-server call.
			expect( seen.origin ).toBe( 'https://public.example.com' );
			// None of the edge hop travels with it.
			expect( seen[ 'cf-ray' ] ).toBeUndefined();
			expect( seen[ 'cf-connecting-ip' ] ).toBeUndefined();
			expect( seen[ 'x-forwarded-for' ] ).toBeUndefined();
			expect( seen[ 'cdn-loop' ] ).toBeUndefined();
		} finally {
			await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
		}
	} );

	it( 'does not relay a Content-Encoding it has already decoded', async () => {
		// The CMS (or the CDN in front of it) compresses whatever it is asked for,
		// and the runtime decodes the body. What leaves the proxy must describe the
		// bytes it actually carries: `gzip` on a plain body makes a browser drop the
		// file entirely — eca's logo rendered 0px while curl showed 43 KB.
		const plain = '<svg xmlns="http://www.w3.org/2000/svg"></svg>';
		const gzipped = gzipSync( Buffer.from( plain, 'utf8' ) );
		let seenEncoding = '';
		const server = http.createServer( ( request, response ) => {
			seenEncoding = String( request.headers[ 'accept-encoding' ] ?? '' );
			response.writeHead( 200, {
				'content-type': 'image/svg+xml',
				'content-encoding': 'gzip',
				'content-length': String( gzipped.length ),
				'cache-control': 'public, max-age=31536000, immutable',
			} );
			response.end( gzipped );
		} );
		await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		const address = server.address();
		if ( ! address || typeof address === 'string' ) {
			throw new Error( 'fixture server failed to bind' );
		}

		try {
			const result = await callProxy( `http://127.0.0.1:${ address.port }` );

			expect( result.status ).toBe( 200 );
			expect( result.body ).toBe( plain );
			expect( result.headers[ 'content-encoding' ] ).toBeUndefined();
			expect( result.headers[ 'content-length' ] ).toBeUndefined();
			// The CMS's one-year immutable TTL is capped: this URL is not
			// content-hashed, so a long-lived copy outlives every fix.
			expect( result.headers[ 'cache-control' ] ).toBe( 'public, max-age=300' );
			// The hop asks for identity, so the common case needs no strip at all.
			expect( seenEncoding ).toBe( 'identity' );
		} finally {
			await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
		}
	} );

	it( 'returns a typed 504 when the CMS does not answer in time', async () => {
		// A socket that accepts and then says nothing is the case a timeout
		// exists for; without it the request hangs for the platform's limit.
		const sockets = new Set< import( 'node:net' ).Socket >();
		const server = http.createServer( () => {
			/* deliberately never respond */
		} );
		server.on( 'connection', ( socket ) => {
			sockets.add( socket );
			socket.on( 'close', () => sockets.delete( socket ) );
		} );
		await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		const address = server.address();
		if ( ! address || typeof address === 'string' ) {
			throw new Error( 'fixture server failed to bind' );
		}

		try {
			const result = await callProxy( `http://127.0.0.1:${ address.port }`, 1000 );

			expect( result.status ).toBe( 504 );
			const body = JSON.parse( result.body ) as { code?: string };
			expect( body.code ).toBe( 'upstream_timeout' );
		} finally {
			for ( const socket of sockets ) {
				socket.destroy();
			}
			await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
		}
	} );
} );
