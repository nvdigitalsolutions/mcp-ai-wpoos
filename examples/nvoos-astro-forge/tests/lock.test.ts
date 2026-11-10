import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
	computeLock,
	describeLock,
	isLocked,
	lockDrift,
	lockPath,
	readLock,
	removeLock,
	writeLock,
} from '../lib/lock.ts';
import { scaffold } from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';

let dir: string;

const PROFILE = {
	url: 'https://example.test',
	name: 'Example',
	collections: [ { slug: 'pages', restBase: 'wp/v2/pages', fields: [ 'id' ] } ],
} as unknown as SiteProfile;

async function seedProject( files: Record< string, string > ): Promise< void > {
	for ( const [ path, content ] of Object.entries( files ) ) {
		const full = join( dir, path );
		await mkdir( join( full, '..' ), { recursive: true } );
		await writeFile( full, content, 'utf8' );
	}
}

/**
 * The scaffold stages beside the target and renames it in; on Windows a
 * freshly-written temp dir intermittently throws EPERM/ENOTEMPTY on the swap
 * (documented in the skill — a retry succeeds). Retry, never wrap in a catch
 * that hides a real failure.
 */
async function scaffoldWithRetry( options: Parameters< typeof scaffold >[ 0 ] ) {
	for ( let attempt = 0; ; attempt++ ) {
		try {
			return await scaffold( options );
		} catch ( error ) {
			const transient = /EPERM|ENOTEMPTY|EBUSY/.test( String( error ) );
			if ( ! transient || attempt >= 2 ) {
				throw error;
			}
		}
	}
}

beforeEach( async () => {
	dir = await mkdtemp( join( tmpdir(), 'forge-lock-' ) );
} );

afterEach( async () => {
	await rm( dir, { recursive: true, force: true } );
} );

describe( 'lock records', () => {
	it( 'writes, reads and removes the handoff lock', async () => {
		expect( isLocked( dir ) ).toBe( false );
		expect( await readLock( dir ) ).toBeUndefined();

		const lock = await computeLock( dir, {
			site: 'virtuallyjamaica',
			source: 'https://virtuallyjamaica.com',
			generator: 'nvoos-astro-forge@0.2.0',
			by: 'Evan',
			reason: 'custom dev handoff',
		} );
		await writeLock( dir, lock );

		expect( isLocked( dir ) ).toBe( true );
		const read = await readLock( dir );
		expect( read?.lockedBy ).toBe( 'Evan' );
		expect( read?.reason ).toBe( 'custom dev handoff' );
		expect( read?.site ).toBe( 'virtuallyjamaica' );

		expect( await removeLock( dir ) ).toBe( true );
		expect( isLocked( dir ) ).toBe( false );
		expect( await removeLock( dir ) ).toBe( false );
	} );

	it( 'fails closed on a corrupt lock instead of treating it as unlocked', async () => {
		await writeFile( lockPath( dir ), '{ not json', 'utf8' );
		const lock = await readLock( dir );
		expect( lock?.reason ).toContain( 'unreadable' );
	} );

	it( 'fingerprints the generated-manifest file set', async () => {
		await seedProject( {
			'nvoos.generated.json': JSON.stringify( { files: { 'src/a.ts': 'x', 'src/b.ts': 'y' } } ),
			'src/a.ts': 'alpha',
			'src/b.ts': 'beta',
		} );
		const lock = await computeLock( dir, { site: 's', generator: 'g' } );
		expect( Object.keys( lock.fingerprint ).sort() ).toEqual( [ 'src/a.ts', 'src/b.ts' ] );
	} );

	it( 'reports edits, additions and removals since handoff', async () => {
		await seedProject( {
			'nvoos.generated.json': JSON.stringify( { files: { 'src/a.ts': 'x', 'src/b.ts': 'y' } } ),
			'src/a.ts': 'alpha',
			'src/b.ts': 'beta',
		} );
		const lock = await computeLock( dir, { site: 's', generator: 'g' } );
		await writeLock( dir, lock );

		await writeFile( join( dir, 'src/a.ts' ), 'alpha edited', 'utf8' );
		await rm( join( dir, 'src/b.ts' ) );

		const drift = await lockDrift( dir, lock );
		expect( drift.changed ).toEqual( [ 'src/a.ts' ] );
		expect( drift.removed ).toEqual( [ 'src/b.ts' ] );
	} );

	it( 'describes ownership in one line', () => {
		expect( describeLock( undefined ) ).toContain( 'forge-owned' );
		expect(
			describeLock( {
				version: 1,
				generator: 'g',
				site: 's',
				lockedAt: '2026-11-10T00:00:00.000Z',
				lockedBy: 'Evan',
				reason: 'handoff',
				fingerprint: {},
			} )
		).toContain( 'LOCKED' );
	} );
} );

describe( 'scaffold refuses a locked target', () => {
	it( 'refuses a profile marked locked in CI (no target lock file exists there)', async () => {
		// The CI runner scaffolds into a FRESH temp target, so `forge.lock.json`
		// is never present — the ownership record travels on the profile. Without
		// this guard a locked site would be silently re-forged on every push,
		// replacing the developer's work on the force-pushed mirror branch.
		const lockedProfile = {
			...PROFILE,
			locked: { at: '2026-11-10T00:00:00.000Z', by: 'Evan', reason: 'custom dev handoff' },
		} as unknown as SiteProfile;

		await expect(
			scaffold( { profile: lockedProfile, targetDir: dir } )
		).rejects.toThrow( /site profile is LOCKED for hand-editing/ );
		await expect( scaffold( { profile: lockedProfile, targetDir: dir } ) ).rejects.toThrow( /Evan/ );

		const result = await scaffoldWithRetry( { profile: lockedProfile, targetDir: dir, unlock: true } );
		expect( result.collections.length ).toBeGreaterThan( 0 );
	} );

	it( 'throws without unlock, proceeds with it', async () => {
		await seedProject( {
			'nvoos.json': JSON.stringify( { version: 1, generator: 'nvoos-astro-forge' } ),
			'forge.lock.json': JSON.stringify( {
				version: 1,
				generator: 'g',
				site: 's',
				lockedAt: '2026-11-10T00:00:00.000Z',
				lockedBy: 'Evan',
				reason: 'custom dev handoff',
				fingerprint: {},
			} ),
		} );

		await expect(
			scaffold( { profile: PROFILE, targetDir: dir, overwrite: true } )
		).rejects.toThrow( /LOCKED for hand-editing/ );

		// The lock record names the owner so the operator can act on it.
		await expect(
			scaffold( { profile: PROFILE, targetDir: dir, overwrite: true } )
		).rejects.toThrow( /Evan/ );

		// With the explicit unlock the re-forge proceeds (the guard is the only
		// thing standing between a regenerate and hand-written work).
		const result = await scaffoldWithRetry( { profile: PROFILE, targetDir: dir, overwrite: true, unlock: true } );
		expect( result.collections.length ).toBeGreaterThan( 0 );
	} );
} );
