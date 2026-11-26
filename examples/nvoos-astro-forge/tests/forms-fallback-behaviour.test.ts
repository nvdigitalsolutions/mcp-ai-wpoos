// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// forms-fallback-behaviour.test.ts — the emitted fallback route, EXECUTED.
//
// The string assertions in managed-runtime.test.ts pin the shape; this drives
// the real route against a dead / failing / answering bridge with a stubbed
// provider, because the contract that matters is behavioural:
//
//   bridge 5xx / refused  → the enquiry is delivered through the provider
//   bridge 4xx            → the bridge answer reaches the visitor untouched
//
// The emitted files are laid out exactly as a generated project has them, and
// the provider is stubbed by intercepting `fetch` before the route loads.
// ---------------------------------------------------------------------------

import { spawn } from 'node:child_process';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import http from 'node:http';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { describe, expect, it } from 'vitest';

import { renderFormsProxyTs, renderProxyHelperTs } from '../lib/managed-runtime.ts';

const FORGE_ROOT = fileURLToPath( new URL( '..', import.meta.url ) );

/** Records every provider send and answers 201 without leaving the process. */
const PROVIDER_STUB = [
	'const realFetch = globalThis.fetch;',
	'globalThis.__mailCalls = [];',
	'globalThis.fetch = async (input, init) => {',
	'\tconst url = typeof input === "string" ? input : input.url;',
	'\tif (url.startsWith("https://api.brevo.com/")) {',
	'\t\tglobalThis.__mailCalls.push(JSON.parse(init.body));',
	'\t\treturn new Response(JSON.stringify({ messageId: "stub" }), { status: 201 });',
	'\t}',
	'\treturn realFetch(input, init);',
	'};',
].join( '\n' );

interface FallbackResult {
	status: number;
	body: string;
	mailCalls: Array< { textContent?: string; subject?: string } >;
}

/** Scaffold the emitted runtime into a temp project and submit one form. */
async function runFallback( target: string ): Promise< FallbackResult > {
	const root = await mkdtemp( path.join( os.tmpdir(), 'forge-fallback-' ) );
	for ( const dir of [ 'lib', 'forge', 'pages/api/forms' ] ) {
		await mkdir( path.join( root, dir ), { recursive: true } );
	}
	await writeFile( path.join( root, 'package.json' ), JSON.stringify( { type: 'module' } ), 'utf8' );
	await writeFile( path.join( root, 'lib', 'managed-proxy.ts' ), renderProxyHelperTs(), 'utf8' );
	await writeFile( path.join( root, 'forge', 'mail.ts' ), await readFile( path.join( FORGE_ROOT, 'lib', 'mail.ts' ), 'utf8' ), 'utf8' );
	await writeFile( path.join( root, 'forge', 'forms.ts' ), await readFile( path.join( FORGE_ROOT, 'lib', 'forms.ts' ), 'utf8' ), 'utf8' );
	await writeFile(
		path.join( root, 'pages', 'api', 'forms', 'submit.ts' ),
		// Vite resolves an extensionless TS import; Node needs the extension.
		renderFormsProxyTs( { fallback: 'direct' } ).replace(
			"from '../../../lib/managed-proxy'",
			"from '../../../lib/managed-proxy.ts'"
		),
		'utf8'
	);
	await writeFile( path.join( root, 'provider-stub.mjs' ), PROVIDER_STUB, 'utf8' );
	await writeFile(
		path.join( root, 'driver.mjs' ),
		[
			"import { ALL } from './pages/api/forms/submit.ts';",
			'const request = new Request("https://public.example.com/api/forms/submit", {',
			'\tmethod: "POST",',
			'\theaders: { "content-type": "application/json" },',
			'\tbody: JSON.stringify({',
			'\t\tform: "contact",',
			'\t\tfields: { name: "Ada", email: "ada@example.com", message: "Hi" },',
			'\t\thoneypot: "",',
			'\t\ttimestamp: Date.now() - 5000,',
			'\t}),',
			'});',
			'const response = await ALL({ request });',
			'process.stdout.write(JSON.stringify({ status: response.status, body: await response.text(), mailCalls: globalThis.__mailCalls }));',
		].join( '\n' ),
		'utf8'
	);

	const env: NodeJS.ProcessEnv = {
		...process.env,
		NVOOS_TARGET_SITE: target,
		ASTRO_SITE: 'https://public.example.com',
		NVOOS_PROXY_TIMEOUT_MS: '1000',
		MAIL_PROVIDER: 'brevo',
		MAIL_FROM: 'forms@example.com',
		MAIL_TO: 'info@example.com',
		BREVO_API_KEY: 'test-key',
	};

	return new Promise( ( resolve, reject ) => {
		const child = spawn(
			process.execPath,
			[
				'--experimental-strip-types',
				'--import',
				pathToFileURL( path.join( root, 'provider-stub.mjs' ) ).href,
				path.join( root, 'driver.mjs' ),
			],
			{ env }
		);
		let out = '';
		let err = '';
		child.stdout?.on( 'data', ( chunk ) => ( out += chunk ) );
		child.stderr?.on( 'data', ( chunk ) => ( err += chunk ) );
		child.on( 'close', ( code ) => {
			if ( code !== 0 ) {
				reject( new Error( `driver exited ${ code }: ${ err }` ) );
				return;
			}
			resolve( JSON.parse( out ) as FallbackResult );
		} );
	} );
}

/** A fixture bridge that answers `status` for any request. */
async function bridge( status: number, body: string ): Promise< { origin: string; close: () => Promise< void > } > {
	const server = http.createServer( ( req, res ) => {
		req.resume();
		res.writeHead( status, { 'content-type': 'application/json' } );
		res.end( body );
	} );
	await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
	const address = server.address();
	if ( ! address || typeof address === 'string' ) {
		throw new Error( 'fixture server failed to bind' );
	}
	return {
		origin: `http://127.0.0.1:${ address.port }`,
		close: () =>
			new Promise< void >( ( resolve ) => {
				server.closeAllConnections();
				server.close( () => resolve() );
			} ),
	};
}

describe( 'emitted bridge route — direct fallback', () => {
	it( 'delivers through the provider when the bridge refuses the connection', async () => {
		// Port 1 never answers: the CMS is down.
		const result = await runFallback( 'http://127.0.0.1:1' );

		expect( result.status ).toBe( 200 );
		expect( JSON.parse( result.body ) ).toEqual( { ok: true } );
		// Exactly one send — a fallback must not duplicate the enquiry.
		expect( result.mailCalls ).toHaveLength( 1 );
		expect( result.mailCalls[ 0 ].textContent ).toContain( 'name: Ada' );
	} );

	it( 'delivers through the provider when the bridge answers 500', async () => {
		const fixture = await bridge( 500, JSON.stringify( { code: 'boom' } ) );
		try {
			const result = await runFallback( fixture.origin );

			expect( result.status ).toBe( 200 );
			expect( result.mailCalls ).toHaveLength( 1 );
		} finally {
			await fixture.close();
		}
	} );

	it( 'passes a 4xx through untouched', async () => {
		// A validation rejection already reached WordPress: re-sending it would
		// hide the error from the visitor and duplicate a payload the bridge
		// deliberately refused.
		const fixture = await bridge( 400, JSON.stringify( { code: 'validation', message: 'Email required' } ) );
		try {
			const result = await runFallback( fixture.origin );

			expect( result.status ).toBe( 400 );
			expect( result.body ).toContain( 'Email required' );
			expect( result.mailCalls ).toHaveLength( 0 );
		} finally {
			await fixture.close();
		}
	} );

	it( 'passes a 501 through untouched (bridge present, no handler)', async () => {
		const fixture = await bridge( 501, JSON.stringify( { code: 'no_handler' } ) );
		try {
			const result = await runFallback( fixture.origin );

			expect( result.status ).toBe( 501 );
			expect( result.mailCalls ).toHaveLength( 0 );
		} finally {
			await fixture.close();
		}
	} );
} );
