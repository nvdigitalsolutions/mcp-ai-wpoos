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

import { describe, expect, it } from 'vitest';

import { renderProxyHelperTs } from '../lib/managed-runtime.ts';

interface ProxyResult {
	status: number;
	body: string;
}

/** Run the emitted helper in a child process and report what it returned. */
async function callProxy( target: string, timeoutMs?: number ): Promise< ProxyResult > {
	const dir = await mkdtemp( path.join( os.tmpdir(), 'forge-proxy-' ) );
	await writeFile( path.join( dir, 'managed-proxy.ts' ), renderProxyHelperTs(), 'utf8' );
	await writeFile(
		path.join( dir, 'driver.mjs' ),
		[
			"import { proxyToWordPress } from './managed-proxy.ts';",
			"const response = await proxyToWordPress(new Request('https://public.example.com/api/x'), '/wp-json/x');",
			'process.stdout.write(JSON.stringify({ status: response.status, body: await response.text() }));',
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
		// Port 1 is never a CMS; the point is that this is a NETWORK fault, not
		// a visitor error and not an unhandled rejection.
		const result = await callProxy( 'http://127.0.0.1:1' );

		expect( result.status ).toBe( 502 );
		const body = JSON.parse( result.body ) as { code?: string; ok?: boolean };
		expect( body.ok ).toBe( false );
		expect( body.code ).toBe( 'upstream_unreachable' );
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
