// ---------------------------------------------------------------------------
// video-hero-variant.test.ts — the additive `video-hero` template variant.
//
// The hero's whole job is a fast first paint, so the contract under test is
// mostly about what the variant REFUSES to do: play for a reduced-motion or
// metered visitor, or render at all without a poster and an MP4.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { mkdtemp, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { applyTemplateVariant, scaffold, TEMPLATE_VARIANTS } from '../lib/scaffold.ts';
import { PRESETS } from '../lib/presets.ts';
import type { SiteProfile } from '../lib/types.ts';
import {
	isSiteAsset,
	resolveHeroVideo,
	shouldAutoplay,
} from '../templates/variants/video-hero/src/lib/hero-video.ts';

const BLOCK = { poster: '/media/hero.jpg', mp4: '/media/hero.mp4' };

describe( 'resolveHeroVideo — the shipped variant resolver', () => {
	it( 'resolves a poster + MP4 hero', () => {
		expect( resolveHeroVideo( { ...BLOCK, alt: 'The workshop at sunrise' } ) ).toEqual( {
			poster: '/media/hero.jpg',
			mp4: '/media/hero.mp4',
			alt: 'The workshop at sunrise',
		} );
	} );

	it( 'offers the WebM sibling first when present', () => {
		expect( resolveHeroVideo( { ...BLOCK, webm: '/media/hero.webm', alt: 'A' } ) ).toMatchObject( {
			webm: '/media/hero.webm',
		} );
	} );

	it( 'requires the MP4 — a WebM-only hero fails on Safari', () => {
		expect( resolveHeroVideo( { poster: '/media/hero.jpg', webm: '/media/hero.webm', alt: 'A' } ) ).toBeNull();
	} );

	it( 'requires a poster — a hero with no still is the empty box we refuse to ship', () => {
		expect( resolveHeroVideo( { mp4: '/media/hero.mp4', alt: 'A' } ) ).toBeNull();
	} );

	it( 'falls back to the band title for the accessible name', () => {
		expect( resolveHeroVideo( { ...BLOCK, title: 'Workshop' } )?.alt ).toBe( 'Workshop' );
		expect( resolveHeroVideo( { ...BLOCK, alt: '   ', title: 'Workshop' } )?.alt ).toBe( 'Workshop' );
		expect( resolveHeroVideo( { ...BLOCK } ) ).toBeNull();
	} );

	it( 'rejects every non-site asset scheme', () => {
		for ( const bad of [ '//evil.test/h.jpg', 'javascript:alert(1)', 'data:video/mp4;base64,AA' ] ) {
			expect( resolveHeroVideo( { poster: bad, mp4: '/media/hero.mp4', alt: 'A' } ), bad ).toBeNull();
			expect( resolveHeroVideo( { poster: '/media/hero.jpg', mp4: bad, alt: 'A' } ), bad ).toBeNull();
		}
		expect( isSiteAsset( 'https://cdn.example.com/h.mp4' ) ).toBe( true );
		expect( isSiteAsset( '//cdn.example.com/h.mp4' ) ).toBe( false );
	} );
} );

describe( 'shouldAutoplay — the motion and data gate', () => {
	it( 'plays by default', () => {
		expect( shouldAutoplay() ).toBe( true );
		expect( shouldAutoplay( { effectiveType: '4g' } ) ).toBe( true );
		expect( shouldAutoplay( { effectiveType: '3g' } ) ).toBe( true );
	} );

	it( 'never plays for a visitor who asked for less motion', () => {
		expect( shouldAutoplay( { reducedMotion: true } ) ).toBe( false );
	} );

	it( 'never plays on Save-Data', () => {
		expect( shouldAutoplay( { saveData: true } ) ).toBe( false );
	} );

	it( 'never plays on 2G', () => {
		expect( shouldAutoplay( { effectiveType: '2g' } ) ).toBe( false );
		expect( shouldAutoplay( { effectiveType: 'slow-2g' } ) ).toBe( false );
		expect( shouldAutoplay( { effectiveType: 'SLOW-2G' } ) ).toBe( false );
	} );
} );

describe( 'the variant overlay + compatibility', () => {
	it( 'ships in TEMPLATE_VARIANTS', () => {
		expect( [ ...TEMPLATE_VARIANTS ] ).toEqual( [ 'product-3d', 'video-hero' ] );
	} );

	it( 'is declared on exactly the persuasion presets', () => {
		expect( PRESETS.storefront.variants ).toContain( 'video-hero' );
		expect( PRESETS.portfolio.variants ).toContain( 'video-hero' );
		expect( PRESETS.landing.variants ).toContain( 'video-hero' );
		for ( const preset of [ 'blog', 'docs', 'minimal' ] as const ) {
			expect( PRESETS[ preset ].variants, preset ).not.toContain( 'video-hero' );
		}
	} );

	it( 'overlays the hero without touching the base template', async () => {
		const target = await mkdtemp( join( tmpdir(), 'forge-video-hero-' ) );

		const written = await applyTemplateVariant( target, 'video-hero' );

		expect( written ).toContain( 'src/components/VideoHero.astro' );
		expect( written ).toContain( 'src/lib/hero-video.ts' );

		const component = await readFile( join( target, 'src', 'components', 'VideoHero.astro' ), 'utf8' );
		// Deferred media + the LCP poster are the whole point.
		expect( component ).toContain( 'preload="none"' );
		expect( component ).toContain( 'fetchpriority="high"' );
		expect( component ).not.toContain( 'client:' );
	} );

	it( 'scaffolds into a landing project', async () => {
		const root = await mkdtemp( join( tmpdir(), 'forge-video-hero-scaffold-' ) );
		const target = join( root, 'site' );
		const profile: SiteProfile = {
			url: 'https://land.example.com/',
			name: 'Landing Example',
			collections: [ { slug: 'pages', restBase: 'wp/v2/pages', fields: [ 'id', 'title' ] } ],
		};

		await scaffold( {
			profile,
			targetDir: target,
			template: 'blog',
			preset: 'landing',
			variant: 'video-hero',
		} );

		const resolver = await readFile( join( target, 'src', 'lib', 'hero-video.ts' ), 'utf8' );
		expect( resolver ).toContain( 'shouldAutoplay' );
	} );

	it( 'refuses the variant on a preset it does not fit', async () => {
		const root = await mkdtemp( join( tmpdir(), 'forge-video-hero-scaffold-' ) );
		const profile: SiteProfile = {
			url: 'https://blog.example.com/',
			name: 'Blog Example',
			collections: [ { slug: 'posts', restBase: 'wp/v2/posts', fields: [ 'id' ] } ],
		};

		await expect(
			scaffold( {
				profile,
				targetDir: join( root, 'site' ),
				template: 'blog',
				preset: 'blog',
				variant: 'video-hero',
			} )
		).rejects.toThrow( /not compatible with the "blog" preset/ );
	} );
} );
