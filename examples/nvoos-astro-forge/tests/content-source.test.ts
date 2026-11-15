// ---------------------------------------------------------------------------
// content-source.test.ts — which snapshot a scaffold is asked to copy.
//
// The rule is small but load-bearing: `materialized` (CMS gone) and
// `snapshot` (CMS stays, snapshot is the net) resolve to the SAME directory,
// which is why a bridged site can opt into the fallback without moving files.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	isMaterializedContent,
	resolveContentDir,
	resolveMediaDir,
	withMaterializedDefaults,
} from '../lib/content-source.ts';
import type { SiteProfile } from '../lib/types.ts';

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

describe( 'materialized defaults (the CMS is gone)', () => {
	const PROFILE_WITH_COLLECTIONS: SiteProfile = {
		url: 'https://site.example.com',
		collections: [
			{ slug: 'posts', restBase: 'wp/v2/posts', fields: [ 'id' ] },
			{ slug: 'pages', restBase: 'wp/v2/pages', fields: [ 'id' ], optional: true },
		],
	};

	it( 'recognises only content.source materialized', () => {
		expect( isMaterializedContent( { content: { source: 'materialized' } } ) ).toBe( true );
		expect( isMaterializedContent( { content: { snapshot: true } } ) ).toBe( false );
		expect( isMaterializedContent( { content: { source: 'wordpress' } } ) ).toBe( false );
		expect( isMaterializedContent( {} ) ).toBe( false );
	} );

	it( 'marks every collection optional, without mutating the profile', () => {
		const next = withMaterializedDefaults( {
			...PROFILE_WITH_COLLECTIONS,
			content: { source: 'materialized' },
		} );

		expect( next.collections.every( ( collection ) => collection.optional ) ).toBe( true );
		// The caller's profile is untouched.
		expect( PROFILE_WITH_COLLECTIONS.collections[ 0 ].optional ).toBeUndefined();
	} );

	it( 'returns the same profile when the CMS still lives', () => {
		// `snapshot: true` keeps WordPress authoritative — it must NOT inherit the
		// CMS-less defaults.
		expect( withMaterializedDefaults( PROFILE_WITH_COLLECTIONS ) ).toBe( PROFILE_WITH_COLLECTIONS );
		const withSnapshot = { ...PROFILE_WITH_COLLECTIONS, content: { snapshot: true } };
		expect( withMaterializedDefaults( withSnapshot ) ).toBe( withSnapshot );
	} );
} );
