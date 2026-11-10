// ---------------------------------------------------------------------------
// deploy-config.test.ts — Phase 3.3: emitted deploy/rebuild workflows +
// the fail-closed dispatch verifier, executed for real in a subprocess.
// ---------------------------------------------------------------------------

import { createHmac } from 'node:crypto';
import { execFile } from 'node:child_process';
import { mkdir, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, relative } from 'node:path';
import { promisify } from 'node:util';

import { beforeEach, describe, expect, it } from 'vitest';

import { DEFAULT_EVENT_TYPE, emitDeployConfig, resolveSiteUrl } from '../lib/deploy-config.ts';

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

	it( 'reports correct relative paths for a dot-slash-prefixed target', async () => {
		// `forge deploy-config --target ./out` passes the dot-slash form — the
		// reported paths must not lose their leading characters.
		const local = join( process.cwd(), `.tmp-deploy-${ Date.now() }` );
		await mkdir( local, { recursive: true } );
		try {
			const { files } = await emitDeployConfig( `./${ relative( process.cwd(), local ) }`, { mode: 'github-pages' } );
			expect( files.sort() ).toEqual( [
				'.github/workflows/deploy.yml',
				'.github/workflows/rebuild.yml',
				'scripts/verify-dispatch.mjs',
			] );
		} finally {
			await rm( local, { recursive: true, force: true } );
		}
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

	it( 'emits the shared-mirror branch-per-site steps for velocity', async () => {
		const { files } = await emitDeployConfig( dir, {
			mode: 'velocity',
			mirrorRepo: 'nvdigitalsolutions/nvoos-forge-sites',
			siteBranch: 'site/jayainc',
		} );
		expect( files.sort() ).toEqual( [
			'.github/workflows/deploy.yml',
			'.github/workflows/rebuild.yml',
			'scripts/mirror-push.mjs',
			'scripts/verify-dispatch.mjs',
		] );
		const { deploy } = await emitted();
		expect( deploy ).toContain( 'node scripts/mirror-push.mjs' );
		expect( deploy ).toContain( 'SITE_BRANCH: site/jayainc' );
		expect( deploy ).not.toContain( 'mirror-tmp' );
		const script = await readFile( join( dir, 'scripts', 'mirror-push.mjs' ), 'utf8' );
		expect( script ).toContain( 'SITE_BRANCH' );
		expect( script ).toContain( "'checkout', '--orphan'" );
		expect( script ).toContain( "'push', '--force'" );
		expect( script ).toContain( 'mirror-push: attempt' );
		// Velocity runs the deploy tree's `npm run build` through `sh -c`,
		// where an unquoted `(` is a syntax error — the whole deploy failed
		// with `sh: 1: Syntax error: "(" unexpected`.
		expect( script ).toContain( ' "process.exit(0)"' );
		expect( script ).not.toContain( 'node -e process.exit(0)' );
		expect( script ).toContain( 'Atomics.wait' );
		expect( script ).not.toMatch( /ghp_/ );
		// Velocity-compat (2026-11-06): Velocity requires a build command —
		// the mirror ships a no-op package.json so npm install + build succeed
		// on the prebuilt branch (Output Directory = repo root).
		expect( script ).toContain( 'Velocity-compat' );
		expect( script ).toContain( "join( tmp, 'package.json' )" );
		expect( script ).toContain( 'nvoos-forge-site' );
	} );

	it( 'rejects a half-set shared mirror config', async () => {
		await expect( emitDeployConfig( dir, { mode: 'velocity', mirrorRepo: 'o/r' } ) ).rejects.toThrow( /mirrorRepo and siteBranch/ );
		await expect( emitDeployConfig( dir, { mode: 'velocity', siteBranch: 'site/x' } ) ).rejects.toThrow( /mirrorRepo and siteBranch/ );
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

	// -- Proposal 135: the two-tier deploy model ----------------------------

	const MIRROR = { mode: 'velocity' as const, mirrorRepo: 'nvdigitalsolutions/nvoos-forge-sites', siteBranch: 'site/test' };

	it( 'defaults to the static runtime and records it in deploy.json', async () => {
		await emitDeployConfig( dir, MIRROR );
		const script = await readFile( join( dir, 'scripts', 'mirror-push.mjs' ), 'utf8' );
		expect( script ).toContain( "process.env.RUNTIME ?? 'static'" );
		expect( script ).toContain( "writeFileSync( join( tmp, 'deploy.json' )" );
		expect( script ).toContain( 'proposal: 135' );
		expect( script ).toContain( 'nvoos-forge-site' );
	} );

	it( 'bakes RUNTIME into the shared-mirror step', async () => {
		await emitDeployConfig( dir, { ...MIRROR, runtime: 'managed' } );
		const { deploy } = await emitted();
		expect( deploy ).toContain( 'RUNTIME: managed' );
	} );

	it( 'managed emits a real start command and the deploy manifest', async () => {
		const { files } = await emitDeployConfig( dir, { ...MIRROR, runtime: 'managed' } );
		expect( files ).toContain( 'scripts/mirror-push.mjs' );
		const script = await readFile( join( dir, 'scripts', 'mirror-push.mjs' ), 'utf8' );
		expect( script ).toContain( "process.env.RUNTIME ?? 'managed'" );
		expect( script ).toContain( "start: 'node server.js'" );
		expect( script ).toContain( 'managedPkg' );
		expect( script ).toContain( "entry: managed ? 'server.js' : null" );
	} );

	it( 'managed assembles the deploy tree before the mirror push', async () => {
		await emitDeployConfig( dir, { ...MIRROR, runtime: 'managed' } );
		const { deploy } = await emitted();
		expect( deploy ).toContain( 'node scripts/sync-deploy-tree.mjs' );
		expect( deploy ).toContain( 'SOURCE_DIR: .deploy' );
	} );

	it( 'static does not assemble a deploy tree', async () => {
		await emitDeployConfig( dir, MIRROR );
		const { deploy } = await emitted();
		expect( deploy ).not.toContain( 'sync-deploy-tree.mjs' );
		expect( deploy ).not.toContain( 'SOURCE_DIR: .deploy' );
	} );

	it( 'rejects an unknown runtime before writing anything', async () => {
		await expect( emitDeployConfig( dir, { ...MIRROR, runtime: 'bogus' as 'static' } ) ).rejects.toThrow( /Invalid deploy runtime/ );
	} );

	describe( 'emitted mirror-push.mjs (runtime contract)', () => {
		async function runPush( extraEnv: Record<string, string> ) {
			const script = join( dir, 'scripts', 'mirror-push.mjs' );
			return execFileAsync( 'node', [ script ], {
				env: {
					...process.env,
					MIRROR_URL: 'https://github.com/example/mirror.git',
					MIRROR_TOKEN: 'x',
					SITE_BRANCH: 'site/test',
					...extraEnv,
				},
			} );
		}

		it( 'skips (exit 0) when the mirror secrets are unset — even for managed', async () => {
			await emitDeployConfig( dir, MIRROR );
			const { stdout } = await runPush( { MIRROR_URL: '', MIRROR_TOKEN: '', RUNTIME: 'managed' } );
			expect( stdout ).toContain( 'skipping mirror deploy' );
		} );

		it( 'refuses to push a managed tree without server.js', async () => {
			await emitDeployConfig( dir, MIRROR );
			await expect( runPush( { RUNTIME: 'managed', SOURCE_DIR: dir } ) ).rejects.toThrow( /server\.js is missing/ );
		} );

		it( 'rejects an unknown RUNTIME from the environment', async () => {
			await emitDeployConfig( dir, MIRROR );
			await expect( runPush( { RUNTIME: 'edge', SOURCE_DIR: dir } ) ).rejects.toThrow( /invalid RUNTIME/ );
		} );

		it( 'refuses a managed tree whose manifest declares no dependencies', async () => {
			// The 2026-11-10 freeze: a dependency-free manifest makes Velocity's
			// `npm install` a no-op, `node server.js` throws
			// ERR_MODULE_NOT_FOUND, the release never promotes, and the app keeps
			// serving an older build while the job stays green.
			await emitDeployConfig( dir, MIRROR );
			const tree = join( dir, 'tree' );
			await mkdir( tree, { recursive: true } );
			await writeFile( join( tree, 'server.js' ), '// entry\n' );
			await writeFile( join( tree, 'package.json' ), JSON.stringify( { name: 'x', scripts: { start: 'node server.js' } } ) );
			await expect( runPush( { RUNTIME: 'managed', SOURCE_DIR: tree } ) ).rejects.toThrow(
				/declares no runtime dependencies/
			);
		} );

		it( 'refuses a managed tree with no manifest at all', async () => {
			await emitDeployConfig( dir, MIRROR );
			const tree = join( dir, 'tree-nopkg' );
			await mkdir( tree, { recursive: true } );
			await writeFile( join( tree, 'server.js' ), '// entry\n' );
			await expect( runPush( { RUNTIME: 'managed', SOURCE_DIR: tree } ) ).rejects.toThrow( /carries no package.json/ );
		} );

		it( 'guards the manifest before the clone (no network for a tree that cannot boot)', async () => {
			await emitDeployConfig( dir, { ...MIRROR, runtime: 'managed' } );
			const script = await readFile( join( dir, 'scripts', 'mirror-push.mjs' ), 'utf8' );
			expect( script ).toContain( 'readFileSync' );
			expect( script.indexOf( 'declares no runtime dependencies' ) ).toBeLessThan( script.indexOf( "'clone'" ) );
		} );

		it( 'normalizes the emitted manifest instead of replacing it', async () => {
			await emitDeployConfig( dir, { ...MIRROR, runtime: 'managed' } );
			const script = await readFile( join( dir, 'scripts', 'mirror-push.mjs' ), 'utf8' );
			expect( script ).toContain( 'managedPkg.scripts ?? {}' );
			expect( script ).toContain( 'managedPkg.engines ?? {}' );
		} );
	} );
} );

describe( 'resolveSiteUrl (Proposal 135)', () => {
	it( 'prefers deploy.publicOrigin over the CMS url', () => {
		expect(
			resolveSiteUrl( { url: 'https://cms.client.com/', deploy: { publicOrigin: 'https://client.com/' } } )
		).toBe( 'https://client.com' );
	} );

	it( 'falls back to the profile url (single-origin installs)', () => {
		expect( resolveSiteUrl( { url: 'https://client.com/' } ) ).toBe( 'https://client.com' );
	} );

	it( 'lets an explicit override win', () => {
		expect(
			resolveSiteUrl(
				{ url: 'https://cms.client.com', deploy: { publicOrigin: 'https://client.com' } },
				'https://staging.client.com/'
			)
		).toBe( 'https://staging.client.com' );
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
