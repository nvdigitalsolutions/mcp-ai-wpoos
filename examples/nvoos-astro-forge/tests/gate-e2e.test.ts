// ---------------------------------------------------------------------------
// gate-e2e.test.ts — a GATED source forged end to end (Proposal 152).
//
// The unit suite proves the probe and the CLI prove the exit codes. This gate
// proves the actual outcome the feature exists for: a source that answers
// 503 + Retry-After on HTML (and reports itself gated on the bridge route) is
// analyzed WITH credentials, produces a design block, and scaffolds a project
// that carries that design — instead of scaffolding the coming-soon page.
//
// Hermetic: a child fixture server, no live site, no npm install.
// ---------------------------------------------------------------------------

import type { AddressInfo } from 'node:net';
import { spawn, spawnSync, type ChildProcess } from 'node:child_process';
import { createServer } from 'node:net';
import { mkdtemp, readFile, stat } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { rmTemp } from './helpers/tmp.ts';

const FORGE_ROOT = fileURLToPath( new URL( '..', import.meta.url ) );
const CLI = join( FORGE_ROOT, 'scripts', 'forge.mjs' );
const FIXTURE = join( FORGE_ROOT, 'scripts', 'wp-fixture-server.mjs' );

/** Ask the OS for a free port, then let the fixture bind it. */
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

async function waitForHealth( url: string, timeoutMs = 30_000 ): Promise< void > {
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		try {
			const response = await fetch( `${ url }/health` );
			if ( response.ok ) {
				await response.body?.cancel();
				return;
			}
		} catch {
			// Not up yet.
		}
		await new Promise( ( resolve ) => setTimeout( resolve, 150 ) );
	}
	throw new Error( `fixture did not become healthy at ${ url }` );
}

function runCli( args: string[], env: Record< string, string >, timeout = 60_000 ) {
	const result = spawnSync( process.execPath, [ CLI, ...args ], {
		cwd: FORGE_ROOT,
		encoding: 'utf8',
		timeout,
		env: { ...process.env, NVOOS_FORGE_ALLOW_LOOPBACK: '1', ...env },
	} );
	return { status: result.status, stdout: result.stdout ?? '', stderr: result.stderr ?? '' };
}

let fixture: ChildProcess | undefined;
let origin = '';
let workDir = '';

beforeAll( async () => {
	const port = await freePort();
	origin = `http://127.0.0.1:${ port }`;
	workDir = await mkdtemp( join( tmpdir(), 'forge-gate-e2e-' ) );

	fixture = spawn( process.execPath, [ FIXTURE, '--port', String( port ), '--quiet' ], {
		cwd: FORGE_ROOT,
		env: {
			...process.env,
			// The site's own mode AND the bridge's verdict — the pair the forge
			// must reconcile without ever reading the holding page.
			WP_FIXTURE_GATE: '503',
			WP_FIXTURE_BRIDGE_GATE: 'coming_soon',
			WP_FIXTURE_GATE_USER: 'forge',
			WP_FIXTURE_GATE_PASSWORD: 'secret',
		},
		stdio: 'ignore',
	} );
	await waitForHealth( origin );
}, 60_000 );

afterAll( async () => {
	fixture?.kill();
	if ( workDir ) {
		await rmTemp( workDir );
	}
} );

describe( 'a gated source forges end to end', () => {
	const profilePath = () => join( workDir, 'site.json' );

	it( 'drafts a scaffold-ready profile WITH credentials (design included)', () => {
		const run = runCli(
			[ 'analyze', origin, '--design', '--write', profilePath() ],
			{ WP_GATE_USERNAME: 'forge', WP_GATE_PASSWORD: 'secret' },
			120_000
		);
		expect( run.status, `analyze failed:\n${ run.stdout }\n${ run.stderr }` ).toBe( 0 );
		// The gate was opened, not merely probed.
		expect( run.stdout ).toContain( 'gate: open' );
		expect( run.stdout ).toContain( 'Design language extracted' );
	} );

	it( 'carries the design block and the content collections into the profile', async () => {
		const profile = JSON.parse( await readFile( profilePath(), 'utf8' ) ) as {
			url: string;
			collections: Array< { slug: string } >;
			design?: { concept?: string };
		};
		expect( profile.url ).toBe( origin );
		expect( profile.design?.concept ).toBeTruthy();
		expect( profile.collections.map( ( collection ) => collection.slug ) ).toEqual(
			expect.arrayContaining( [ 'posts', 'pages' ] )
		);
	} );

	it( 'scaffolds a project whose generated design came from the gated source', async () => {
		const target = join( workDir, 'frontend' );
		const run = runCli( [ profilePath(), '--target', target ], {}, 120_000 );
		expect( run.status, `scaffold failed:\n${ run.stdout }\n${ run.stderr }` ).toBe( 0 );

		const manifest = JSON.parse( await readFile( join( target, 'nvoos.json' ), 'utf8' ) ) as {
			url: string;
			collections: Array< { slug: string } >;
		};
		expect( manifest.url ).toBe( origin );
		expect( manifest.collections.length ).toBeGreaterThan( 0 );

		// The design block reached the generated project (not the template default).
		const tokens = await readFile( join( target, 'src/lib/design.generated.ts' ), 'utf8' );
		expect( tokens ).toContain( '--forge-' );

		// The content layer points at the CMS, not at the fixture module default.
		const config = await readFile( join( target, 'src/content.config.ts' ), 'utf8' );
		expect( config ).toContain( origin );
	}, 120_000 );

	it( 'REFUSES to analyze the same source without credentials', () => {
		const run = runCli(
			[ 'analyze', origin, '--design', '--write', join( workDir, 'blocked.json' ) ],
			{ WP_GATE_USERNAME: '', WP_GATE_PASSWORD: '', WP_GATE_COOKIE: '' },
			60_000
		);
		expect( run.status ).not.toBe( 0 );
		expect( run.stderr ).toContain( 'refusing to forge the holding page' );
		// The bridge's verdict is part of the evidence the operator sees.
		expect( `${ run.stdout }${ run.stderr }` ).toContain( 'bridge' );
	} );

	it( 'never shipped the holding page text into the generated project', async () => {
		const target = join( workDir, 'frontend' );
		const page = await readFile( join( target, 'src/pages/index.astro' ), 'utf8' ).catch( () => '' );
		expect( page.toLowerCase() ).not.toContain( 'coming soon' );
		// The fixture's real home copy is what the design pass consumed.
		await expect( stat( join( target, 'src/lib/design.generated.ts' ) ) ).resolves.toBeTruthy();
	} );
} );
