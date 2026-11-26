// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// product-3d-variant.test.ts — the additive `product-3d` template variant.
//
// Two contracts, one per half of the variant:
//   1. the SHIPPED resolver (templates/variants/product-3d/src/lib/
//      product-model.ts) is poster-first: no usable poster → no model, so a
//      page never renders an empty 3D box;
//   2. applyTemplateVariant OVERLAYS files onto a scaffolded tree without
//      deleting anything the base template owns.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { mkdir, mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import {
	applyTemplateVariant,
	isTemplateVariant,
	scaffold,
	TEMPLATE_VARIANTS,
	type TemplateVariant,
} from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';
import {
	isSiteAsset,
	resolveProductModel,
} from '../templates/variants/product-3d/src/lib/product-model.ts';

/** A catalogue document: a name, one image, and an authored model block. */
const productDoc = ( model3d: unknown ) => ( {
	name: 'Aurora Sofa',
	images: [ { src: '/media/aurora.jpg' } ],
	model3d,
} );

describe( 'resolveProductModel — the shipped variant resolver', () => {
	it( 'resolves a fully authored model', () => {
		expect(
			resolveProductModel( {
				name: 'Ignored',
				model3d: { src: '/models/a.glb', poster: '/models/a.svg', alt: 'A sofa', ar: true },
			} )
		).toEqual( { src: '/models/a.glb', poster: '/models/a.svg', alt: 'A sofa', ar: true } );
	} );

	it( 'falls back to the first catalogue image and the product name', () => {
		expect( resolveProductModel( productDoc( { src: '/models/a.glb' } ) ) ).toEqual( {
			src: '/models/a.glb',
			poster: '/media/aurora.jpg',
			alt: 'Aurora Sofa',
		} );
	} );

	it( 'returns null with no poster — poster-first means no empty box', () => {
		expect( resolveProductModel( { name: 'Aurora Sofa', model3d: { src: '/models/a.glb' } } ) ).toBeNull();
	} );

	it( 'returns null with no model, no source, or no accessible name', () => {
		expect( resolveProductModel( null ) ).toBeNull();
		expect( resolveProductModel( productDoc( undefined ) ) ).toBeNull();
		expect( resolveProductModel( productDoc( { poster: '/p.svg' } ) ) ).toBeNull();
		expect( resolveProductModel( { images: [ { src: '/i.jpg' } ], model3d: { src: '/m.glb' } } ) ).toBeNull();
	} );

	it( 'rejects every non-site asset scheme', () => {
		for ( const src of [ '//evil.test/a.glb', 'javascript:alert(1)', 'data:model/gltf-binary;base64,AA' ] ) {
			expect( resolveProductModel( productDoc( { src, poster: '/p.svg' } ) ) ).toBeNull();
		}
	} );

	it( 'accepts https and root-relative only', () => {
		expect( isSiteAsset( 'https://cdn.example.com/a.glb' ) ).toBe( true );
		expect( isSiteAsset( '/models/a.glb' ) ).toBe( true );
		expect( isSiteAsset( '//cdn.example.com/a.glb' ) ).toBe( false );
		expect( isSiteAsset( 42 ) ).toBe( false );
	} );
} );

describe( 'applyTemplateVariant — the additive overlay', () => {
	it( 'lists exactly the shipped variant ids', () => {
		expect( [ ...TEMPLATE_VARIANTS ] ).toEqual( [ 'product-3d', 'video-hero' ] );
		expect( isTemplateVariant( 'product-3d' ) ).toBe( true );
		expect( isTemplateVariant( 'carousel' ) ).toBe( false );
		expect( isTemplateVariant( undefined ) ).toBe( false );
	} );

	it( 'overlays the viewer without deleting template files', async () => {
		const target = await mkdtemp( join( tmpdir(), 'forge-variant-' ) );
		await mkdir( join( target, 'src', 'components' ), { recursive: true } );
		await writeFile( join( target, 'src', 'components', 'SmartImage.astro' ), 'base', 'utf8' );

		const written = await applyTemplateVariant( target, 'product-3d' );

		expect( written ).toContain( 'src/components/ModelViewer.astro' );
		expect( written ).toContain( 'src/lib/product-model.ts' );
		expect( written ).toContain( 'variant.json' );

		const viewer = await readFile( join( target, 'src', 'components', 'ModelViewer.astro' ), 'utf8' );
		expect( viewer ).toContain( '<model-viewer' );
		// Zero hydration: the generated project ships no framework runtime.
		expect( viewer ).not.toContain( 'client:' );

		// The base template's file survives — an overlay never prunes.
		expect( await readFile( join( target, 'src', 'components', 'SmartImage.astro' ), 'utf8' ) ).toBe( 'base' );
	} );

	it( 'fails loudly on an unknown variant', async () => {
		const target = await mkdtemp( join( tmpdir(), 'forge-variant-' ) );
		await expect( applyTemplateVariant( target, 'nope' as TemplateVariant ) ).rejects.toThrow(
			/not found/
		);
	} );
} );

describe( 'scaffold --variant product-3d', () => {
	it( 'lands the viewer in a real scaffolded project, next to the base template files', async () => {
		const root = await mkdtemp( join( tmpdir(), 'forge-variant-scaffold-' ) );
		const target = join( root, 'site' );
		const profile: SiteProfile = {
			url: 'https://shop.example.com/',
			name: 'Example Store',
			collections: [
				{ slug: 'posts', restBase: 'wp/v2/posts', fields: [ 'id', 'title' ] },
			],
		};

		await scaffold( {
			profile,
			targetDir: target,
			template: 'blog',
			preset: 'storefront',
			variant: 'product-3d',
		} );

		const viewer = await readFile( join( target, 'src', 'components', 'ModelViewer.astro' ), 'utf8' );
		expect( viewer ).toContain( '<model-viewer' );
		expect( viewer ).not.toContain( 'client:' );

		const resolver = await readFile( join( target, 'src', 'lib', 'product-model.ts' ), 'utf8' );
		expect( resolver ).toContain( 'resolveProductModel' );

		// The overlay never replaces the base template: its own component is intact.
		const base = await readFile( join( target, 'src', 'components', 'SmartImage.astro' ), 'utf8' );
		expect( base.length ).toBeGreaterThan( 0 );
	} );

	it( 'refuses the 3D variant on a preset that has no commerce surface', async () => {
		const root = await mkdtemp( join( tmpdir(), 'forge-variant-scaffold-' ) );
		const profile: SiteProfile = {
			url: 'https://shop.example.com/',
			name: 'Example Store',
			collections: [ { slug: 'posts', restBase: 'wp/v2/posts', fields: [ 'id' ] } ],
		};

		await expect(
			scaffold( {
				profile,
				targetDir: join( root, 'site' ),
				template: 'blog',
				preset: 'blog',
				variant: 'product-3d',
			} )
		).rejects.toThrow( /not compatible with the "blog" preset/ );
	} );
} );
