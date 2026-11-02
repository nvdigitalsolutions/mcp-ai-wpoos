// ---------------------------------------------------------------------------
// presets.test.ts — the 104 §4.3 preset registry: descriptors, suggestion
// from a discovery report, and the collection-profile application.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { applyPresetToProfile, PRESETS, PRESET_NAMES, RENDER_MODES, suggestPreset } from '../lib/presets.ts';
import type { DiscoveryReport } from '../lib/discovery.ts';
import type { SiteProfile } from '../lib/types.ts';

const PROFILE: SiteProfile = {
	url: 'https://shop.example.com',
	name: 'Example Store',
	collections: [
		{ slug: 'posts', restBase: 'wp/v2/posts', fields: ['id'], embed: true },
		{ slug: 'pages', restBase: 'wp/v2/pages', fields: ['id'], embed: true },
		{
			slug: 'products',
			restBase: 'wc/v3/products',
			fields: ['id', 'name'],
			kind: 'product',
			authMode: 'wc-consumer',
		},
	],
};

function report( overrides: Partial<DiscoveryReport> ): Pick<DiscoveryReport, 'woocommerce' | 'collections'> {
	return {
		woocommerce: false,
		collections: [],
		...overrides,
	};
}

describe( 'PRESETS registry', () => {
	it( 'declares the three presets with template + render mode + surface toggles', () => {
		expect( PRESET_NAMES ).toEqual( ['blog', 'storefront', 'minimal'] );
		expect( RENDER_MODES ).toEqual( ['static', 'ssr'] );
		expect( PRESETS.blog.template ).toBe( 'blog' );
		expect( PRESETS.storefront.template ).toBe( 'blog' );
		expect( PRESETS.minimal.template ).toBe( 'blog' );
		expect( PRESETS.blog.surfaces.search ).toBe( true );
		expect( PRESETS.storefront.surfaces.search ).toBe( true );
		expect( PRESETS.minimal.surfaces.search ).toBe( false );
		// Feed/sitemap stay on for every shipped preset; OG cards stay off
		// (the default scaffold stays zero-dep for OG) and render is static.
		for ( const preset of PRESET_NAMES ) {
			expect( PRESETS[preset].surfaces.feed ).toBe( true );
			expect( PRESETS[preset].surfaces.sitemap ).toBe( true );
			expect( PRESETS[preset].surfaces.ogImages ).toBe( false );
			expect( PRESETS[preset].render ).toBe( 'static' );
		}
	} );
} );

describe( 'suggestPreset', () => {
	it( 'suggests storefront when WooCommerce is advertised', () => {
		expect( suggestPreset( report( { woocommerce: true } ) ) ).toBe( 'storefront' );
	} );

	it( 'suggests storefront when a product collection is detected without Woo', () => {
		expect(
			suggestPreset( report( { collections: [{ slug: 'products', restBase: 'wp/v2/product', kind: 'product', count: 3, optional: false }] } ) )
		).toBe( 'storefront' );
	} );

	it( 'suggests blog otherwise', () => {
		expect( suggestPreset( report( {} ) ) ).toBe( 'blog' );
		expect(
			suggestPreset(
				report( { collections: [{ slug: 'posts', restBase: 'wp/v2/posts', kind: 'post', count: 12, optional: false }] } )
			)
		).toBe( 'blog' );
	} );
} );

describe( 'applyPresetToProfile', () => {
	it( 'blog is the identity', () => {
		expect( applyPresetToProfile( PROFILE, 'blog' ) ).toEqual( PROFILE );
	} );

	it( 'minimal drops commerce collections but keeps posts/pages', () => {
		const out = applyPresetToProfile( PROFILE, 'minimal' );
		expect( out.collections.map( ( collection ) => collection.slug ) ).toEqual( ['posts', 'pages'] );
		// The original profile is untouched.
		expect( PROFILE.collections ).toHaveLength( 3 );
	} );

	it( 'storefront keeps an existing products collection untouched', () => {
		expect( applyPresetToProfile( PROFILE, 'storefront' ) ).toEqual( PROFILE );
	} );

	it( 'storefront adds the standard wc/v3 products collection when missing', () => {
		const blogOnly: SiteProfile = { ...PROFILE, collections: PROFILE.collections.slice( 0, 2 ) };
		const out = applyPresetToProfile( blogOnly, 'storefront' );
		expect( out.collections ).toHaveLength( 3 );
		const products = out.collections[2];
		expect( products.slug ).toBe( 'products' );
		expect( products.restBase ).toBe( 'wc/v3/products' );
		expect( products.kind ).toBe( 'product' );
		expect( products.authMode ).toBe( 'wc-consumer' );
		expect( products.optional ).toBe( true );
		expect( products.fields ).toContain( 'regular_price' );
	} );
} );
