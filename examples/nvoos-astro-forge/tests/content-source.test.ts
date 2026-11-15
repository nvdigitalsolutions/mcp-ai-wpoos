// ---------------------------------------------------------------------------
// content-source.test.ts — which snapshot a scaffold is asked to copy.
//
// The rule is small but load-bearing: `materialized` (CMS gone) and
// `snapshot` (CMS stays, snapshot is the net) resolve to the SAME directory,
// which is why a bridged site can opt into the fallback without moving files.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { resolveContentDir, resolveMediaDir } from '../lib/content-source.ts';

const PROFILE = 'sites/example.json';

describe( 'resolveContentDir', () => {
	it( 'resolves the snapshot beside a materialized profile', () => {
		expect( resolveContentDir( { content: { source: 'materialized' }, profilePath: PROFILE } ) ).toBe(
			'sites/example.content'
		);
	} );

	it( 'resolves the same directory for a bridged snapshot', () => {
		expect( resolveContentDir( { content: { snapshot: true }, profilePath: PROFILE } ) ).toBe(
			'sites/example.content'
		);
	} );

	it( 'ignores a plain WordPress profile', () => {
		expect( resolveContentDir( { content: { source: 'wordpress' }, profilePath: PROFILE } ) ).toBeUndefined();
		expect( resolveContentDir( { profilePath: PROFILE } ) ).toBeUndefined();
	} );

	it( 'still copies a snapshot for a no-fetch site', () => {
		// `source: 'none'` means "do not fetch a CMS", not "ignore local files".
		expect( resolveContentDir( { content: { source: 'none', snapshot: true }, profilePath: PROFILE } ) ).toBe(
			'sites/example.content'
		);
	} );

	it( 'lets --content override either mode', () => {
		expect(
			resolveContentDir( { content: { snapshot: true }, profilePath: PROFILE, explicit: '/tmp/snapshot' } )
		).toBe( '/tmp/snapshot' );
		expect(
			resolveContentDir( { content: { source: 'materialized' }, profilePath: PROFILE, explicit: '/tmp/snapshot' } )
		).toBe( '/tmp/snapshot' );
	} );

	it( 'returns nothing when the profile path is unknown', () => {
		// A programmatic scaffold with no `sites/<name>.json` has nothing to
		// derive from; the caller passes `contentDir` explicitly instead.
		expect( resolveContentDir( { content: { snapshot: true } } ) ).toBeUndefined();
	} );
} );

describe( 'resolveMediaDir', () => {
	it( 'resolves the snapshot beside the profile when media.snapshot is on', () => {
		expect( resolveMediaDir( { media: { snapshot: true }, profilePath: PROFILE } ) ).toBe( 'sites/example.media' );
	} );

	it( 'ignores a profile that keeps its CMS authoritative', () => {
		expect( resolveMediaDir( { media: { localize: true }, profilePath: PROFILE } ) ).toBeUndefined();
		expect( resolveMediaDir( { profilePath: PROFILE } ) ).toBeUndefined();
	} );

	it( 'lets --media-snapshot override the derived path', () => {
		expect(
			resolveMediaDir( { media: { snapshot: true }, profilePath: PROFILE, explicit: '/tmp/media' } )
		).toBe( '/tmp/media' );
	} );

	it( 'returns nothing when the profile path is unknown', () => {
		expect( resolveMediaDir( { media: { snapshot: true } } ) ).toBeUndefined();
	} );
} );
