// ---------------------------------------------------------------------------
// sites.test.ts — Phase 3.4: the multi-site registry (add/list/use/remove,
// default handling, profile resolution). The two-site round-trip is the
// agency-demo shape the phase exit calls for, run hermetically in a temp dir.
// ---------------------------------------------------------------------------

import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { beforeEach, describe, expect, it } from 'vitest';

import { SitesError, SitesStore } from '../lib/sites.ts';

let dir: string;

beforeEach( async () => {
	dir = await mkdtemp( join( tmpdir(), 'forge-sites-' ) );
} );

async function profileFixture( name: string, url: string ) {
	const path = join( dir, `${ name }.json` );
	await writeFile(
		path,
		JSON.stringify( { url, name, collections: [ { slug: 'posts', restBase: 'wp/v2/posts', fields: [ 'id', 'title' ] } ] } ),
		'utf8'
	);
	return path;
}

describe( 'SitesStore', () => {
	it( 'two-site round trip: add → list → default → use → resolve → remove', async () => {
		const store = new SitesStore( dir );
		await profileFixture( 'alpha', 'https://alpha.example.com' );
		await profileFixture( 'beta', 'https://beta.example.com' );

		await store.add( 'alpha', { url: 'https://alpha.example.com/', profile: 'alpha.json' } );
		await store.add( 'beta', { url: 'https://beta.example.com', profile: 'beta.json' } );

		// First site added becomes the default.
		expect( await store.defaultName() ).toBe( 'alpha' );
		const listed = await store.list();
		expect( Object.keys( listed ).sort() ).toEqual( [ 'alpha', 'beta' ] );
		expect( listed.alpha.url ).toBe( 'https://alpha.example.com' );

		// Switch the default and resolve a profile through the registry.
		await store.setDefault( 'beta' );
		expect( await store.defaultName() ).toBe( 'beta' );
		const resolved = await store.resolve( 'beta' );
		expect( resolved.ref.url ).toBe( 'https://beta.example.com' );
		expect( resolved.profile.name ).toBe( 'beta' );

		// Removing the default falls back to the remaining site.
		await store.remove( 'beta' );
		expect( await store.defaultName() ).toBe( 'alpha' );
		await store.remove( 'alpha' );
		expect( await store.defaultName() ).toBeUndefined();
		expect( await store.list() ).toEqual( {} );
	} );

	it( 'persists across instances (registry survives process restarts)', async () => {
		const first = new SitesStore( dir );
		await profileFixture( 'alpha', 'https://alpha.example.com' );
		await first.add( 'alpha', { url: 'https://alpha.example.com', profile: 'alpha.json' } );

		const second = new SitesStore( dir );
		expect( await second.defaultName() ).toBe( 'alpha' );
		expect( ( await second.resolve( 'alpha' ) ).profile.url ).toBe( 'https://alpha.example.com' );
	} );

	it( 'validates names, URLs, and profile presence', async () => {
		const store = new SitesStore( dir );
		await expect( store.add( 'Bad Name!', { url: 'https://x.example.com', profile: 'p.json' } ) ).rejects.toThrow( SitesError );
		await expect( store.add( 'ok', { url: 'not-a-url', profile: 'p.json' } ) ).rejects.toThrow( SitesError );
		await expect( store.add( 'ok', { url: 'https://x.example.com', profile: '' } ) ).rejects.toThrow( SitesError );
		await expect( store.setDefault( 'ghost' ) ).rejects.toThrow( /Unknown site/ );
		await expect( store.remove( 'ghost' ) ).rejects.toThrow( /Unknown site/ );
	} );

	it( 'resolve fails cleanly for missing profiles and bad JSON', async () => {
		const store = new SitesStore( dir );
		await store.add( 'alpha', { url: 'https://alpha.example.com', profile: 'missing.json' } );
		await expect( store.resolve( 'alpha' ) ).rejects.toThrow( /Profile not found/ );

		await writeFile( join( dir, 'bad.json' ), '{not json', 'utf8' );
		await store.add( 'beta', { url: 'https://beta.example.com', profile: 'bad.json' } );
		await expect( store.resolve( 'beta' ) ).rejects.toThrow( /not valid JSON/ );
	} );

	it( 're-add keeps the original creation timestamp (update path)', async () => {
		const store = new SitesStore( dir );
		await profileFixture( 'alpha', 'https://alpha.example.com' );
		await store.add( 'alpha', { url: 'https://alpha.example.com', profile: 'alpha.json' } );
		const created = ( await store.list() ).alpha.created;
		await store.add( 'alpha', { url: 'https://alpha.example.com', profile: 'alpha.json' } );
		expect( ( await store.list() ).alpha.created ).toBe( created );
	} );

	it( 'the registry file contains no credentials', async () => {
		const store = new SitesStore( dir );
		await profileFixture( 'alpha', 'https://alpha.example.com' );
		await store.add( 'alpha', { url: 'https://alpha.example.com', profile: 'alpha.json' } );
		const raw = await readFile( join( dir, 'sites.json' ), 'utf8' );
		expect( raw ).not.toMatch( /ghp_/ );
		expect( raw ).not.toMatch( /password/i );
	} );
} );
