// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// gate-cli.test.ts — `forge gate <profile>` (Proposal 152): exit codes,
// JSON output and the fail-closed analyze path.
//
// Spawns the real CLI against the debug fixture, so the command surface is
// pinned end to end without a live site.
// ---------------------------------------------------------------------------

import type { AddressInfo } from 'node:net';
import type { Server } from 'node:http';
import { spawn } from 'node:child_process';
import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest';

import { createWpFixtureServer } from '../scripts/wp-fixture-server.mjs';

const FORGE_ROOT = fileURLToPath( new URL( '..', import.meta.url ) );
const CLI = join( FORGE_ROOT, 'scripts', 'forge.mjs' );

const savedEnv = new Map< string, string | undefined >();
const GATE_ENV = [ 'WP_FIXTURE_GATE', 'WP_GATE_USERNAME', 'WP_GATE_PASSWORD', 'WP_GATE_COOKIE', 'WP_GATE_MODE', 'WP_GATE_QUERY' ] as const;

let server: Server;
let base: string;
let profilePath: string;

function runCli( args: string[], env: Record< string, string > = {} ): Promise< { status: number | null; stdout: string; stderr: string } > {
	// ASYNC spawn: the fixture server lives in THIS process, so a spawnSync
	// would block the event loop and the child could never reach it.
	return new Promise( ( resolve ) => {
		const child = spawn( process.execPath, [ CLI, ...args ], {
			cwd: FORGE_ROOT,
			// The fixture is loopback: the CLI's documented dev exemption must be on.
			env: { ...process.env, NVOOS_FORGE_ALLOW_LOOPBACK: '1', ...env },
		} );
		let stdout = '';
		let stderr = '';
		child.stdout.on( 'data', ( chunk ) => {
			stdout += chunk;
		} );
		child.stderr.on( 'data', ( chunk ) => {
			stderr += chunk;
		} );
		const timer = setTimeout( () => child.kill(), 90_000 );
		child.on( 'close', ( status ) => {
			clearTimeout( timer );
			resolve( { status, stdout, stderr } );
		} );
	} );
}

beforeAll( async () => {
	for ( const key of GATE_ENV ) {
		savedEnv.set( key, process.env[ key ] );
	}
	server = createWpFixtureServer( 0 ) as unknown as Server;
	await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', () => resolve() ) );
	const { port } = server.address() as AddressInfo;
	base = `http://127.0.0.1:${ port }`;
	const dir = await mkdtemp( join( tmpdir(), 'forge-gate-' ) );
	profilePath = join( dir, 'site.json' );
	await writeFile(
		profilePath,
		JSON.stringify( { url: base, collections: [ { slug: 'pages', restBase: 'wp/v2/pages', fields: [] } ] }, null, '\t' ),
		'utf8'
	);
} );

afterEach( () => {
	for ( const key of GATE_ENV ) {
		const value = savedEnv.get( key );
		if ( value === undefined ) {
			delete process.env[ key ];
		} else {
			process.env[ key ] = value;
		}
	}
} );

afterAll( async () => {
	await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
} );

/** The fixture runs in THIS process, so its mode comes from OUR env — not the child's. */
async function withFixture< T >( mode: string, run: () => Promise< T > ): Promise< T > {
	const previous = process.env.WP_FIXTURE_GATE;
	process.env.WP_FIXTURE_GATE = mode;
	try {
		return await run();
	} finally {
		if ( previous === undefined ) {
			delete process.env.WP_FIXTURE_GATE;
		} else {
			process.env.WP_FIXTURE_GATE = previous;
		}
	}
}

describe( 'forge gate', () => {
	it( 'exits 0 and reports open for a public fixture', async () => {
		const run = await withFixture( 'off', () => runCli( [ 'gate', profilePath, '--json' ], { WP_GATE_MODE: '' } ) );
		expect( run.status ).toBe( 0 );
		const payload = JSON.parse( run.stdout.trim() ) as { state: string };
		expect( payload.state ).toBe( 'open' );
	} );

	it( 'exits 3 with gated + remediation for a 503 gate and no credentials', async () => {
		const run = await withFixture( '503', () =>
			runCli( [ 'gate', profilePath, '--json' ], {
				WP_GATE_USERNAME: '',
				WP_GATE_PASSWORD: '',
				WP_GATE_COOKIE: '',
			} )
		);
		expect( run.status ).toBe( 3 );
		const payload = JSON.parse( run.stdout.trim() ) as { state: string; retryAfter: number; remediation: string[] };
		expect( payload.state ).toBe( 'gated' );
		expect( payload.retryAfter ).toBe( 600 );
		expect( payload.remediation.length ).toBeGreaterThan( 0 );
	} );

	it( 'exits 0 when the credentials unlock the gate', async () => {
		const run = await withFixture( '503', () =>
			runCli( [ 'gate', profilePath, '--json' ], {
				WP_GATE_USERNAME: 'forge',
				WP_GATE_PASSWORD: 'secret',
			} )
		);
		expect( run.status ).toBe( 0 );
		const payload = JSON.parse( run.stdout.trim() ) as { state: string; mechanism: string[] };
		expect( payload.state ).toBe( 'open' );
		expect( payload.mechanism ).toContain( 'session' );
	} );

	it( 'exits 4 for an edge (basic auth) gate', async () => {
		const run = await withFixture( 'edge', () => runCli( [ 'gate', profilePath, '--json' ] ) );
		expect( run.status ).toBe( 4 );
		const payload = JSON.parse( run.stdout.trim() ) as { state: string };
		expect( payload.state ).toBe( 'edge-auth' );
	} );

	it( 'prints the remediation ladder without a --json flag', async () => {
		const run = await withFixture( 'holding', () => runCli( [ 'gate', profilePath ] ) );
		expect( run.status ).toBe( 3 );
		expect( run.stdout ).toContain( 'remediation:' );
		expect( run.stdout ).toContain( 'gate:' );
	} );

	it( 'refuses to analyze a gated source without --allow-blocked', async () => {
		const run = await withFixture( '503', () =>
			runCli( [ 'analyze', base, '--design' ], {
				WP_GATE_USERNAME: '',
				WP_GATE_PASSWORD: '',
				WP_GATE_COOKIE: '',
			} )
		);
		expect( run.status ).not.toBe( 0 );
		expect( run.stderr ).toContain( 'refusing to forge the holding page' );
	} );

	it( 'continues with --allow-blocked and says so', async () => {
		const run = await withFixture( '503', () =>
			runCli( [ 'analyze', base, '--design', '--allow-blocked' ], {
				WP_GATE_USERNAME: '',
				WP_GATE_PASSWORD: '',
				WP_GATE_COOKIE: '',
			} )
		);
		expect( run.status ).toBe( 0 );
		expect( `${ run.stdout }${ run.stderr }` ).toContain( '--allow-blocked' );
		expect( run.stdout ).toContain( 'draft site.json' );
	} );
} );
