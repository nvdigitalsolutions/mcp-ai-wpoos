// ---------------------------------------------------------------------------
// seo-meta.test.ts — the loader's SEO head extraction (Proposal 131 §2.2):
// Rank Math flat fields first, then Yoast's `yoast_head_json`, with the
// robots normalization both plugins need.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { extractSeoMeta, normalizeRobots } from '../lib/wp-loader.ts';

describe( 'normalizeRobots', () => {
	it( 'normalizes the Rank Math array shape', () => {
		expect( normalizeRobots( [ 'noindex', 'follow' ] ) ).toBe( 'noindex, follow' );
		expect( normalizeRobots( [] ) ).toBeUndefined();
	} );

	it( 'normalizes the Yoast object shape, deduped', () => {
		expect(
			normalizeRobots( {
				index: 'index',
				follow: 'follow',
				'max-image-preview': 'max-image-preview:large',
				'max-snippet': 'max-snippet:-1',
			} )
		).toBe( 'index, follow, max-image-preview:large, max-snippet:-1' );
		expect( normalizeRobots( { index: 'index', follow: 'follow' } ) ).toBe( 'index, follow' );
	} );

	it( 'passes a string through and ignores junk', () => {
		expect( normalizeRobots( 'noindex,nofollow' ) ).toBe( 'noindex, nofollow' );
		expect( normalizeRobots( undefined ) ).toBeUndefined();
		expect( normalizeRobots( 42 ) ).toBeUndefined();
	} );
} );

describe( 'extractSeoMeta — Rank Math', () => {
	it( 'maps the flat fields, including canonical, robots and social', () => {
		const seo = extractSeoMeta( {
			rank_math_title: 'Rank title',
			rank_math_description: 'Rank description',
			rank_math_canonical_url: 'https://example.com/canonical/',
			rank_math_robots: [ 'index', 'follow' ],
			rank_math_facebook_title: 'OG title',
			rank_math_facebook_description: 'OG description',
			rank_math_facebook_image: 'https://example.com/og.png',
			rank_math_twitter_title: 'TW title',
			rank_math_twitter_description: 'TW description',
			rank_math_twitter_image: 'https://example.com/tw.png',
		} );
		expect( seo ).toEqual( {
			title: 'Rank title',
			description: 'Rank description',
			canonical: 'https://example.com/canonical/',
			robots: 'index, follow',
			ogTitle: 'OG title',
			ogDescription: 'OG description',
			ogImage: 'https://example.com/og.png',
			twitterTitle: 'TW title',
			twitterDescription: 'TW description',
			twitterImage: 'https://example.com/tw.png',
		} );
	} );

	it( 'rejects a non-URL social image (an attachment ID)', () => {
		const seo = extractSeoMeta( { rank_math_facebook_image: '12345' } );
		expect( seo.ogImage ).toBeUndefined();
	} );
} );

describe( 'extractSeoMeta — Yoast fallback', () => {
	it( 'maps title/description/robots/og/twitter from yoast_head_json', () => {
		const seo = extractSeoMeta( {
			yoast_head_json: {
				title: 'Yoast title',
				description: 'Yoast description',
				canonical: 'https://example.com/yoast/',
				robots: { index: 'index', follow: 'follow' },
				og_title: 'Yoast OG',
				og_description: 'Yoast OG description',
				og_type: 'article',
				og_site_name: 'Example',
				og_image: [ { url: 'https://example.com/care.png', width: 1200, height: 630 } ],
				twitter_card: 'summary_large_image',
			},
		} );
		expect( seo ).toEqual( {
			title: 'Yoast title',
			description: 'Yoast description',
			canonical: 'https://example.com/yoast/',
			robots: 'index, follow',
			ogTitle: 'Yoast OG',
			ogDescription: 'Yoast OG description',
			ogImage: 'https://example.com/care.png',
			ogType: 'article',
			ogSiteName: 'Example',
			twitterCard: 'summary_large_image',
		} );
	} );

	it( 'tolerates a JSON-string yoast_head_json', () => {
		const seo = extractSeoMeta( { yoast_head_json: JSON.stringify( { title: 'Stringified' } ) } );
		expect( seo.title ).toBe( 'Stringified' );
	} );

	it( 'falls back to og_description when no description exists', () => {
		const seo = extractSeoMeta( { yoast_head_json: { og_description: 'OG only' } } );
		expect( seo.description ).toBe( 'OG only' );
	} );
} );

describe( 'extractSeoMeta — precedence and absence', () => {
	it( 'prefers Rank Math over Yoast', () => {
		const seo = extractSeoMeta( {
			rank_math_title: 'Rank wins',
			yoast_head_json: { title: 'Yoast loses' },
		} );
		expect( seo.title ).toBe( 'Rank wins' );
	} );

	it( 'returns an empty object with no SEO plugin (never a crash)', () => {
		expect( extractSeoMeta( {} ) ).toEqual( {} );
		expect( extractSeoMeta( { title: { rendered: 'No SEO' } } ) ).toEqual( {} );
	} );
} );
