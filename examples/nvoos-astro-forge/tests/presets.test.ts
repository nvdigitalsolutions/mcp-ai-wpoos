// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// presets.test.ts — the 104 §4.3 preset registry: descriptors, suggestion
// from a discovery report, and the collection-profile application.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { applyPresetToProfile, DEFAULT_THEME_POLICY, isValidThemePolicy, PRESETS, PRESET_NAMES, RENDER_MODES, suggestedVariant, suggestPreset } from '../lib/presets.ts';
import { TEMPLATE_VARIANTS } from '../lib/scaffold.ts';
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
	it( 'declares every preset with template + render mode + surface toggles', () => {
		expect( PRESET_NAMES ).toEqual( [
			'blog',
			'storefront',
			'portfolio',
			'landing',
			'docs',
			// Proposal 163 — the first preset that carries an IA, not just a layout.
			'eca',
			'minimal',
		] );
		expect( RENDER_MODES ).toEqual( [ 'static', 'ssr' ] );
		for ( const preset of PRESET_NAMES ) {
			expect( PRESETS[ preset ].template, preset ).toBe( 'blog' );
			expect( PRESETS[ preset ].render, preset ).toBe( 'static' );
			// OG cards stay off in every preset: the default scaffold stays
			// zero-dep for OG (104 §4.8).
			expect( PRESETS[ preset ].surfaces.ogImages, preset ).toBe( false );
			// Every preset is sitemap-indexed — a site the crawlers cannot
			// enumerate is a site nobody finds.
			expect( PRESETS[ preset ].surfaces.sitemap, preset ).toBe( true );
		}
	} );

	it( 'gives each preset its own surface profile — that IS the preset', () => {
		expect( PRESETS.blog.surfaces ).toEqual( { search: true, feed: true, sitemap: true, ogImages: false } );
		expect( PRESETS.storefront.surfaces ).toEqual( { search: true, feed: true, sitemap: true, ogImages: false } );
		// A work catalogue is browsed by facet, not subscribed to.
		expect( PRESETS.portfolio.surfaces ).toEqual( { search: true, feed: false, sitemap: true, ogImages: false } );
		// One page: nothing to search, nothing to subscribe to.
		expect( PRESETS.landing.surfaces ).toEqual( { search: false, feed: false, sitemap: true, ogImages: false } );
		// Documentation is searched, not subscribed to.
		expect( PRESETS.docs.surfaces ).toEqual( { search: true, feed: false, sitemap: true, ogImages: false } );
		expect( PRESETS.minimal.surfaces ).toEqual( { search: false, feed: true, sitemap: true, ogImages: false } );
	} );

	it( 'declares the variant matrix (mirrors templates/variants/VARIANTS.md)', () => {
		expect( PRESETS.storefront.variants ).toEqual( [ 'product-3d', 'video-hero' ] );
		expect( PRESETS.portfolio.variants ).toEqual( [ 'video-hero' ] );
		expect( PRESETS.landing.variants ).toEqual( [ 'video-hero' ] );
		expect( PRESETS.blog.variants ).toEqual( [] );
		expect( PRESETS.docs.variants ).toEqual( [] );
		expect( PRESETS.minimal.variants ).toEqual( [] );
		// `product-3d` renders a PRODUCT's model, so commerce is the only fit.
		for ( const preset of PRESET_NAMES.filter( ( name ) => name !== 'storefront' ) ) {
			expect( PRESETS[ preset ].variants, preset ).not.toContain( 'product-3d' );
		}
	} );

	it( 'pins every declared variant to a shipped one', () => {
		// TEMPLATE_VARIANTS lives in scaffold.ts (importing it into presets.ts
		// would be a cycle), so this is the guard that keeps the two lists honest.
		const shipped = new Set<string>( TEMPLATE_VARIANTS );
		for ( const preset of PRESET_NAMES ) {
			for ( const variant of PRESETS[ preset ].variants ) {
				expect( shipped.has( variant ), `${ preset } → ${ variant }` ).toBe( true );
			}
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

	it( 'suggests portfolio for a work catalogue', () => {
		for ( const slug of [ 'portfolio', 'projects', 'case-studies', 'work' ] ) {
			expect(
				suggestPreset(
					report( { collections: [{ slug, restBase: `wp/v2/${ slug }`, kind: 'post', count: 8, optional: false }] } )
				),
				slug
			).toBe( 'portfolio' );
		}
	} );

	it( 'lets commerce win over a portfolio CPT — a shop with work is still a shop', () => {
		expect(
			suggestPreset(
				report( {
					woocommerce: true,
					collections: [{ slug: 'portfolio', restBase: 'wp/v2/portfolio', kind: 'post', count: 8, optional: false }],
				} )
			)
		).toBe( 'storefront' );
	} );

	it( 'never guesses landing or docs — those are operator intent', () => {
		const everyVerdict = new Set(
			[
				report( {} ),
				report( { woocommerce: true } ),
				report( { collections: [{ slug: 'portfolio', restBase: 'wp/v2/portfolio', kind: 'post', count: 1, optional: false }] } ),
			].map( ( probe ) => suggestPreset( probe ) )
		);
		expect( everyVerdict.has( 'landing' ) ).toBe( false );
		expect( everyVerdict.has( 'docs' ) ).toBe( false );
	} );
} );

describe( 'suggestedVariant', () => {
	it( 'offers the FIRST declared variant, and nothing where none applies', () => {
		// Storefront leads with the commerce variant; the persuasion presets
		// lead with the hero.
		expect( suggestedVariant( 'storefront' ) ).toBe( 'product-3d' );
		expect( suggestedVariant( 'portfolio' ) ).toBe( 'video-hero' );
		expect( suggestedVariant( 'landing' ) ).toBe( 'video-hero' );
		for ( const preset of [ 'blog', 'docs', 'minimal' ] as const ) {
			expect( suggestedVariant( preset ), preset ).toBeNull();
		}
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

	it( 'portfolio prunes commerce and adds an optional projects collection', () => {
		const blogOnly: SiteProfile = { ...PROFILE, collections: PROFILE.collections.slice( 0, 2 ) };
		const out = applyPresetToProfile( blogOnly, 'portfolio' );

		expect( out.collections.map( ( collection ) => collection.slug ) ).toEqual( [ 'posts', 'pages', 'projects' ] );
		const projects = out.collections[2];
		expect( projects.restBase ).toBe( 'wp/v2/projects' );
		// Optional: a site that advertises the preset without the CPT still builds.
		expect( projects.optional ).toBe( true );
	} );

	it( 'portfolio keeps a real portfolio CPT and does not add a duplicate', () => {
		const withPortfolio: SiteProfile = {
			...PROFILE,
			collections: [
				...PROFILE.collections.slice( 0, 2 ),
				{ slug: 'portfolio', restBase: 'wp/v2/portfolio', fields: [ 'id' ] },
			],
		};
		const out = applyPresetToProfile( withPortfolio, 'portfolio' );
		expect( out.collections.map( ( collection ) => collection.slug ) ).toEqual( [ 'posts', 'pages', 'portfolio' ] );
	} );

	it( 'landing prunes commerce and sets the one-pager shape', () => {
		const out = applyPresetToProfile( PROFILE, 'landing' );
		expect( out.collections.map( ( collection ) => collection.slug ) ).toEqual( [ 'posts', 'pages' ] );
		expect( out.shape ).toBe( 'one-pager' );
	} );

	it( 'portfolio keeps a declared case-studies CPT and invents nothing', () => {
		// The MadVisions-style split (work items + case studies) is DETECTED, not
		// invented: a preset must never add a second 404-tolerant collection.
		const withCases: SiteProfile = {
			...PROFILE,
			collections: [
				...PROFILE.collections.slice( 0, 2 ),
				{ slug: 'case-studies', restBase: 'wp/v2/case-studies', fields: [ 'id' ] },
			],
		};
		const out = applyPresetToProfile( withCases, 'portfolio' );
		expect( out.collections.map( ( collection ) => collection.slug ) ).toEqual( [
			'posts',
			'pages',
			'case-studies',
		] );
	} );

	it( 'docs prunes commerce and PINS the multi-page shape', () => {
		const out = applyPresetToProfile( PROFILE, 'docs' );
		expect( out.collections.map( ( collection ) => collection.slug ) ).toEqual( [ 'posts', 'pages' ] );
		// Docs chrome follows the page hierarchy, so a source that happens to
		// look like a one-pager must not win the shape decision.
		expect( out.shape ).toBe( 'multi-page' );
	} );
} );

describe( 'theme policy (Proposal 105 §4)', () => {
	it( 'defaults to auto', () => {
		expect( DEFAULT_THEME_POLICY ).toBe( 'auto' );
	} );

	it( 'accepts the enum values', () => {
		expect( isValidThemePolicy( 'auto' ) ).toBe( true );
		expect( isValidThemePolicy( 'light' ) ).toBe( true );
		expect( isValidThemePolicy( 'dark' ) ).toBe( true );
	} );

	it( 'accepts a brand slug matching the a-z0-9- regex', () => {
		expect( isValidThemePolicy( 'brand:lenas-chair' ) ).toBe( true );
		expect( isValidThemePolicy( 'brand:brand-x-2' ) ).toBe( true );
	} );

	it( 'rejects malformed brand slugs, unknown values and non-strings', () => {
		expect( isValidThemePolicy( 'brand:' ) ).toBe( false );
		expect( isValidThemePolicy( 'brand:Lenas' ) ).toBe( false );
		expect( isValidThemePolicy( 'brand:foo_bar' ) ).toBe( false );
		expect( isValidThemePolicy( 'brand:foo bar' ) ).toBe( false );
		expect( isValidThemePolicy( 'sepia' ) ).toBe( false );
		expect( isValidThemePolicy( '' ) ).toBe( false );
		expect( isValidThemePolicy( undefined ) ).toBe( false );
		expect( isValidThemePolicy( 42 ) ).toBe( false );
	} );
} );

describe( 'applyPresetToProfile — extra collections are content, not commerce', () => {
	const profile = (): SiteProfile => ( {
		url: 'https://example.com',
		name: 'Example',
		collections: [
			{ slug: 'posts', restBase: 'wp/v2/posts', fields: [ 'id' ], embed: false },
			{ slug: 'pages', restBase: 'wp/v2/pages', fields: [ 'id' ], embed: false },
			{ slug: 'products', restBase: 'wc/v3/products', fields: [ 'id' ], embed: false },
			{ slug: 'portfolio', restBase: 'wp/v2/portfolio', fields: [ 'id' ], embed: false },
		],
	} );

	it( 'drops commerce but keeps an arbitrary CPT under every preset', () => {
		for ( const preset of PRESET_NAMES ) {
			const slugs = applyPresetToProfile( profile(), preset ).collections.map( ( collection ) => collection.slug );
			expect( slugs, preset ).toContain( 'portfolio' );
		}
	} );

	it( 'still drops products for the minimal preset', () => {
		const slugs = applyPresetToProfile( profile(), 'minimal' ).collections.map( ( collection ) => collection.slug );
		expect( slugs ).not.toContain( 'products' );
		expect( slugs ).toEqual( [ 'posts', 'pages', 'portfolio' ] );
	} );
} );
