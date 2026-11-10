// ---------------------------------------------------------------------------
// deploy-tree.test.ts — the emitted sync-deploy-tree.mjs, executed for real.
//
// The managed deploy tree is what Velocity boots, so its runtime manifest is
// load-bearing: the Astro node adapter externalizes its runtime, and a manifest
// without `dependencies` makes `npm install` a no-op. The 2026-11-10 freeze
// (Virtually Jamaica served a day-old artifact through five green mirror
// pushes) was exactly that, so these tests pin the manifest and the closure
// check that refuses an under-declared tree.
// ---------------------------------------------------------------------------

import { execFile } from 'node:child_process';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';

import { afterEach, describe, expect, it } from 'vitest';

import { renderSyncDeployTree } from '../lib/managed-runtime.ts';

const execFileAsync = promisify( execFile );

let dir: string;

afterEach( async () => {
	if ( dir ) {
		await rm( dir, { recursive: true, force: true } );
	}
} );

interface Fixture {
	/** The project manifest written at the tree root. */
	pkg: Record< string, unknown >;
	/** Packages present in the build's node_modules, with their own manifest. */
	installed: Record< string, { dependencies?: Record< string, string > } >;
	/** The bare specifiers the built server output imports. */
	imports: string[];
	/** Copy a package-lock.json into the project root. */
	lock?: boolean;
}

async function fixture( options: Fixture ): Promise< string > {
	dir = await mkdtemp( join( tmpdir(), 'forge-tree-' ) );
	await mkdir( join( dir, 'dist', 'server' ), { recursive: true } );
	await mkdir( join( dir, 'scripts' ), { recursive: true } );
	await writeFile( join( dir, 'package.json' ), JSON.stringify( options.pkg, null, 2 ) );
	await writeFile( join( dir, 'server.js' ), "import './dist/server/entry.mjs';\n" );
	await writeFile(
		join( dir, 'dist', 'server', 'entry.mjs' ),
		options.imports.map( ( specifier, index ) => `import mod${ index } from '${ specifier }';` ).join( '\n' ) + '\n'
	);
	await writeFile( join( dir, 'scripts', 'sync-deploy-tree.mjs' ), renderSyncDeployTree() );

	for ( const [ name, manifest ] of Object.entries( options.installed ) ) {
		const target = join( dir, 'node_modules', name );
		await mkdir( target, { recursive: true } );
		await writeFile( join( target, 'package.json' ), JSON.stringify( { name, version: '1.0.0', ...manifest } ) );
	}

	if ( options.lock ) {
		await writeFile( join( dir, 'package-lock.json' ), JSON.stringify( { lockfileVersion: 3, packages: {} } ) );
	}

	return dir;
}

function run( cwd: string ) {
	return execFileAsync( 'node', [ join( cwd, 'scripts', 'sync-deploy-tree.mjs' ) ], { cwd } );
}

describe( 'sync-deploy-tree (managed deploy tree)', () => {
	it( 'carries the project dependencies into the published manifest', async () => {
		const cwd = await fixture( {
			pkg: { name: 'site', dependencies: { astro: '^7.4.4' } },
			installed: { astro: { dependencies: { cookie: '^0.7.0' } }, cookie: {} },
			imports: [ 'astro/app', 'cookie' ],
		} );

		await run( cwd );

		const emitted = JSON.parse( await readFile( join( cwd, '.deploy', 'package.json' ), 'utf8' ) );
		expect( emitted.dependencies ).toEqual( { astro: '^7.4.4' } );
		expect( emitted.scripts ).toEqual( { build: 'node -e "process.exit(0)"', start: 'node server.js' } );
		expect( emitted.engines ).toEqual( { node: '>=22.23.4' } );
	} );

	it( 'copies the lockfile so the Velocity install is reproducible', async () => {
		const cwd = await fixture( {
			pkg: { name: 'site', dependencies: { astro: '^7.4.4' } },
			installed: { astro: {} },
			imports: [ 'astro/app' ],
			lock: true,
		} );

		await run( cwd );

		await expect( readFile( join( cwd, '.deploy', 'package-lock.json' ), 'utf8' ) ).resolves.toContain( 'lockfileVersion' );
	} );

	it( 'refuses a project that declares no dependencies', async () => {
		const cwd = await fixture( { pkg: { name: 'site' }, installed: {}, imports: [] } );

		await expect( run( cwd ) ).rejects.toMatchObject( {
			stderr: expect.stringContaining( 'package.json declares no dependencies' ),
		} );
	} );

	it( 'refuses a tree whose build imports a package outside the declared closure', async () => {
		// `left-pad` is installed in the build tree and imported by the output,
		// but reachable from no declared dependency — Velocity would never
		// install it, and the entry would die at import time.
		const cwd = await fixture( {
			pkg: { name: 'site', dependencies: { astro: '^7.4.4' } },
			installed: { astro: {}, 'left-pad': {} },
			imports: [ 'astro/app', 'left-pad' ],
		} );

		await expect( run( cwd ) ).rejects.toMatchObject( {
			stderr: expect.stringContaining( 'the build imports packages the manifest does not declare' ),
		} );
	} );

	it( 'keeps transitive runtime imports legal (astro → cookie)', async () => {
		const cwd = await fixture( {
			pkg: { name: 'site', dependencies: { astro: '^7.4.4' } },
			installed: {
				astro: { dependencies: { cookie: '^0.7.0', devalue: '^5.0.0' } },
				cookie: {},
				devalue: {},
			},
			imports: [ 'astro/app', 'cookie', 'devalue' ],
		} );

		await expect( run( cwd ) ).resolves.toBeDefined();
	} );

	it( 'ignores node builtins and the astro: virtual modules', async () => {
		const cwd = await fixture( {
			pkg: { name: 'site', dependencies: { astro: '^7.4.4' } },
			installed: { astro: {} },
			imports: [ 'node:fs', 'node:path', 'astro:assets', 'astro/app' ],
		} );

		await expect( run( cwd ) ).resolves.toBeDefined();
		await expect( readFile( join( cwd, '.deploy', 'server.js' ), 'utf8' ) ).resolves.toContain( 'dist/server/entry.mjs' );
	} );
} );
