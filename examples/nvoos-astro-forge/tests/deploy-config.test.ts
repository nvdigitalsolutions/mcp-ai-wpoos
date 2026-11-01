// ---------------------------------------------------------------------------
// deploy-config.test.ts — Phase 3.3: emitted deploy/rebuild workflows +
// the fail-closed dispatch verifier, executed for real in a subprocess.
// ---------------------------------------------------------------------------

import { createHmac } from 'node:crypto';
import { execFile } from 'node:child_process';
import { mkdtemp, readFile, readdir } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';

import { beforeEach, describe, expect, it } from 'vitest';

import { DEFAULT_EVENT_TYPE, emitDeployConfig } from '../lib/deploy-config.ts';

const execFileAsync = promisify( execFile );

let dir: string;

beforeEach( async () => {
	dir = await mkdtemp( join( tmpdir(), 'forge-deploy-' ) );
} );

async function emitted() {
	const deploy = await readFile( join( dir, '.github', 'workflows', 'deploy.yml' ), 'utf8' );
	const rebuild = await readFile( join( dir, '.github', 'workflows', 'rebuild.yml' ), 'utf8' );
	const verify = await readFile( join( dir, 'scripts', 'verify-dispatch.mjs' ), 'utf8' );
	return { deploy, rebuild, verify };
}

describe( 'emitDeployConfig', () => {
	it( 'emits the three-file set for github-pages', async () => {
		const { files } = await emitDeployConfig( dir, { mode: 'github-pages' } );
		expect( files.sort() ).toEqual( [
			'.github/workflows/deploy.yml',
			'.github/workflows/rebuild.yml',
			'scripts/verify-dispatch.mjs',
		] );
		const { deploy, rebuild } = await emitted();
		expect( deploy ).toContain( 'actions/configure-pages@v5' );
		expect( deploy ).toContain( 'actions/deploy-pages@v4' );
		expect( rebuild ).toContain( `types: [${ DEFAULT_EVENT_TYPE }]` );
		expect( rebuild ).toContain( 'uses: ./.github/workflows/deploy.yml' );
	} );

	it( 'emits the wrangler steps for cloudflare-pages with the project name', async () => {
		await emitDeployConfig( dir, { mode: 'cloudflare-pages', cloudflareProject: 'customer-storefront' } );
		const { deploy } = await emitted();
		expect( deploy ).toContain( 'cloudflare/wrangler-action@v3' );
		expect( deploy ).toContain( '--project-name=customer-storefront' );
	} );

	it( 'emits the connected-repo mirror for velocity', async () => {
		await emitDeployConfig( dir, { mode: 'velocity' } );
		const { deploy } = await emitted();
		expect( deploy ).toContain( 'VELOCITY_MIRROR_URL' );
		expect( deploy ).toContain( 'VELOCITY_MIRROR_TOKEN' );
		expect( deploy ).toContain( 'mirror-tmp' );
	} );

	it( 'bakes the site URL into the build env and customizes the event type', async () => {
		await emitDeployConfig( dir, { mode: 'github-pages', siteUrl: 'https://store.example.com', eventType: 'custom-event' } );
		const { deploy, rebuild } = await emitted();
		expect( deploy ).toContain( 'ASTRO_SITE: https://store.example.com' );
		expect( rebuild ).toContain( 'types: [custom-event]' );
	} );

	it( 'emits no secrets by construction', async () => {
		await emitDeployConfig( dir, { mode: 'velocity' } );
		const { deploy, rebuild, verify } = await emitted();
		for ( const content of [ deploy, rebuild, verify ] ) {
			expect( content ).not.toMatch( /ghp_/ );
			expect( content ).not.toMatch( /NVOOS_FORGE_VAULT_KEY=/ );
		}
	} );

	it( 're-emit overwrites (the deploy-config update path)', async () => {
		await emitDeployConfig( dir, { mode: 'github-pages' } );
		await emitDeployConfig( dir, { mode: 'cloudflare-pages', cloudflareProject: 'v2' } );
		const { deploy } = await emitted();
		expect( deploy ).toContain( 'wrangler-action' );
		expect( deploy ).not.toContain( 'configure-pages' );
	} );
} );

describe( 'emitted verify-dispatch.mjs (fail-closed contract)', () => {
	const SECRET = 'shared-hmac-secret';

	function signedPayload( overrides: Record<string, unknown> = {} ) {
		const time = Math.floor( Date.now() / 1000 );
		const nonce = 'a1b2c3d4e5f6';
		const signature = createHmac( 'sha256', SECRET ).update( `${ time }.${ nonce }` ).digest( 'hex' );
		return JSON.stringify( {
			type: DEFAULT_EVENT_TYPE,
			time,
			nonce,
			signature,
			...overrides,
		} );
	}

	async function run( payload: string, secret: string = SECRET, extra: string[] = [] ) {
		const script = join( dir, 'scripts', 'verify-dispatch.mjs' );
		return execFileAsync( 'node', [ script, ...extra ], {
			env: { ...process.env, FORGE_WEBHOOK_SECRET: secret, DISPATCH_PAYLOAD: payload },
		} );
	}

	it( 'accepts a fresh, correctly signed dispatch', async () => {
		await emitDeployConfig( dir, { mode: 'github-pages' } );
		const { stdout } = await run( signedPayload() );
		expect( stdout ).toContain( 'verified' );
	} );

	it( 'rejects a signature mismatch', async () => {
		await emitDeployConfig( dir, { mode: 'github-pages' } );
		await expect( run( signedPayload( { signature: 'deadbeef' } ) ) ).rejects.toThrow();
	} );

	it( 'rejects a stale dispatch', async () => {
		await emitDeployConfig( dir, { mode: 'github-pages' } );
		const stale = Math.floor( Date.now() / 1000 ) - 3600;
		const nonce = 'a1b2c3d4e5f6';
		const signature = createHmac( 'sha256', SECRET ).update( `${ stale }.${ nonce }` ).digest( 'hex' );
		await expect(
			run( JSON.stringify( { type: DEFAULT_EVENT_TYPE, time: stale, nonce, signature } ) )
		).rejects.toThrow( /old/ );
	} );

	it( 'rejects an unexpected event type and a missing secret', async () => {
		await emitDeployConfig( dir, { mode: 'github-pages' } );
		await expect( run( signedPayload( { type: 'something-else' } ) ) ).rejects.toThrow( /unexpected event type/ );
		await expect( run( signedPayload(), '' ) ).rejects.toThrow( /not set/ );
	} );

	it( 'rejects a payload with missing proof fields', async () => {
		await emitDeployConfig( dir, { mode: 'github-pages' } );
		await expect( run( '{}' ) ).rejects.toThrow( /unexpected event type/ );
		await expect( run( JSON.stringify( { type: DEFAULT_EVENT_TYPE } ) ) ).rejects.toThrow( /missing/ );
	} );

	it( 'rejects non-JSON payloads', async () => {
		await emitDeployConfig( dir, { mode: 'github-pages' } );
		await expect( run( 'not-json{' ) ).rejects.toThrow( /not valid JSON/ );
	} );
} );
