// ---------------------------------------------------------------------------
// eca-scaffold.test.ts — the `eca` preset and its generated artifacts
// (Proposal 163 §P1).
//
// The preset is the gate: a profile without `eca` must produce byte-identical
// output to today's blog rebuild, so every assertion here is about the ECA
// branch specifically — what it adds, and what it refuses to duplicate.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	ECA_COLLECTION,
	ECA_PROFILE_DEFAULTS,
	applyPresetToProfile,
	presetCatalogue,
	PRESETS,
	suggestPreset,
} from '../lib/presets.ts';
import {
	deriveTaxonomyRestBase,
	ECA_CONFIG_PATH,
	ECA_VENDORED_PATH,
	ecaCollection,
	ecaScaffoldWarnings,
	renderEcaGenerated,
} from '../lib/eca-scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';

const profile = ( over: Partial< SiteProfile > = {} ): SiteProfile => ( {
	url: 'https://eca.directory',
	collections: [ { slug: 'posts', restBase: 'wp/v2/posts', fields: [ 'id' ] } ],
	...over,
} );

const discovered = ( slug: string, restBase: string, kind: 'post' | 'page' | 'product' ) => ( {
	slug,
	restBase,
	kind,
	count: 1,
	optional: false,
} );

const ECA_DISCOVERED = [ discovered( 'posts', 'wp/v2/posts', 'post' ), discovered( 'mcp_ai_eca', 'wp/v2/mcp_ai_eca', 'post' ) ];

describe( 'the eca preset', () => {
	it( 'is registered with a catalogue entry', () => {
		expect( PRESETS.eca.name ).toBe( 'eca' );
		expect( PRESETS.eca.render ).toBe( 'static' );
		// A catalogue is browsed by facet, never subscribed to.
		expect( PRESETS.eca.surfaces.feed ).toBe( false );
		expect( PRESETS.eca.surfaces.search ).toBe( true );
	} );

	it( 'appears in the guidance catalogue', () => {
		const entry = presetCatalogue().find( ( row ) => row.id === 'eca' );
		expect( entry ).toBeDefined();
		expect( entry?.bestFor ).toContain( 'extra-curricular' );
		expect( entry?.surfaces ).not.toContain( 'feed' );
	} );

	it( 'invents no variant — there is no measured activity layout to offer', () => {
		expect( PRESETS.eca.variants ).toEqual( [] );
	} );
} );

describe( 'suggestPreset', () => {
	it( 'detects the toolkit CPT from the REST base', () => {
		expect( suggestPreset( { woocommerce: false, collections: ECA_DISCOVERED } ) ).toBe( 'eca' );
	} );

	it( 'detects it from the slug when the base is rewritten', () => {
		expect(
			suggestPreset( { woocommerce: false, collections: [ discovered( 'mcp_ai_eca', 'mcp-ai/v1/eca', 'post' ) ] } ),
		).toBe( 'eca' );
	} );

	it( 'outranks commerce — a school with a shop is still an ECA site', () => {
		expect(
			suggestPreset( {
				woocommerce: true,
				collections: [ ...ECA_DISCOVERED, discovered( 'products', 'wc/v3/products', 'product' ) ],
			} ),
		).toBe( 'eca' );
	} );

	it( 'falls back to the existing precedence otherwise', () => {
		expect( suggestPreset( { woocommerce: true, collections: [ discovered( 'products', 'wc/v3/products', 'product' ) ] } ) ).toBe(
			'storefront',
		);
		expect( suggestPreset( { woocommerce: false, collections: [ discovered( 'posts', 'wp/v2/posts', 'post' ) ] } ) ).toBe( 'blog' );
	} );
} );

describe( 'applyPresetToProfile( eca )', () => {
	it( 'adds the activities collection', () => {
		const applied = applyPresetToProfile( profile(), 'eca' );
		const activities = applied.collections.find( ( collection ) => collection.slug === 'activities' );
		expect( activities?.restBase ).toBe( 'wp/v2/mcp_ai_eca' );
		expect( activities?.basePath ).toBe( '/activities' );
	} );

	it( 'requests `meta` — without it the whole schedule is dropped', () => {
		const activities = applyPresetToProfile( profile(), 'eca' ).collections.find( ( collection ) => collection.slug === 'activities' );
		expect( activities?.fields ).toContain( 'meta' );
		expect( activities?.fields ).toContain( 'mcp_ai_eca_category' );
	} );

	it( 'prunes commerce', () => {
		const applied = applyPresetToProfile(
			profile( {
				collections: [
					{ ...ECA_COLLECTION },
					{ slug: 'products', restBase: 'wc/v3/products', fields: [] },
				],
			} ),
			'eca',
		);
		expect( applied.collections.some( ( collection ) => collection.slug === 'products' ) ).toBe( false );
	} );

	it( 'repairs a discovered collection instead of duplicating it', () => {
		const applied = applyPresetToProfile(
			profile( { collections: [ { slug: 'eca', restBase: 'wp/v2/mcp_ai_eca', basePath: '/eca', fields: [ 'id', 'title' ] } ] } ),
			'eca',
		);
		expect( applied.collections ).toHaveLength( 1 );
		const repaired = applied.collections[ 0 ];
		// The discovered list has no `meta` — the preset must add it or the whole
		// schedule and price disappear without an error.
		expect( repaired.fields ).toContain( 'meta' );
		expect( repaired.fields ).toContain( 'mcp_ai_eca_category' );
		// Operator order is preserved, new fields appended.
		expect( repaired.fields.slice( 0, 2 ) ).toEqual( [ 'id', 'title' ] );
		// The rebuilt IA owns the path.
		expect( repaired.basePath ).toBe( '/activities' );
	} );

	it( 'defaults the site block', () => {
		const applied = applyPresetToProfile( profile(), 'eca' );
		expect( applied.eca?.basePath ).toBe( '/activities' );
		expect( applied.eca?.stages?.map( ( stage ) => stage.id ) ).toEqual( [ 'junior-school', 'senior-school' ] );
		expect( applied.eca?.days?.[ 0 ] ).toBe( 'Monday' );
		expect( applied.eca?.booking?.mode ).toBe( 'external' );
	} );

	it( 'keeps the operator overrides', () => {
		const applied = applyPresetToProfile( profile( { eca: { basePath: '/clubs', currency: 'GBP' } } ), 'eca' );
		expect( applied.eca?.basePath ).toBe( '/clubs' );
		expect( applied.eca?.currency ).toBe( 'GBP' );
		// Structural defaults still fill in.
		expect( applied.eca?.stages ).toHaveLength( 2 );
	} );
} );

describe( 'deriveTaxonomyRestBase', () => {
	it( 'derives the toolkit taxonomy from the CPT base', () => {
		expect( deriveTaxonomyRestBase( 'wp/v2/mcp_ai_eca' ) ).toBe( 'wp/v2/mcp_ai_eca_category' );
	} );

	it( 'refuses a non-wp/v2 base', () => {
		expect( deriveTaxonomyRestBase( 'wc/v3/products' ) ).toBe( '' );
		expect( deriveTaxonomyRestBase( '' ) ).toBe( '' );
	} );
} );

describe( 'ecaCollection', () => {
	it( 'prefers the profile entry so its field list survives', () => {
		const declared = { slug: 'activities', restBase: 'wp/v2/mcp_ai_eca', fields: [ 'id', 'slug', 'meta' ] };
		expect( ecaCollection( profile( { collections: [ declared ] } ) ) ).toEqual( declared );
	} );

	it( 'falls back to the preset definition', () => {
		expect( ecaCollection( profile() ) ).toEqual( ECA_COLLECTION );
	} );
} );

describe( 'renderEcaGenerated', () => {
	const output = renderEcaGenerated( profile( { eca: { currency: 'LKR', timezone: 'Asia/Colombo' } } ) );

	it( 'names the artifact and its provenance', () => {
		expect( ECA_CONFIG_PATH ).toBe( 'src/lib/eca.generated.ts' );
		expect( ECA_VENDORED_PATH ).toBe( 'src/forge/eca.ts' );
		expect( output ).toContain( 'eca.generated.ts — generated by nvoos-astro-forge' );
	} );

	it( 'imports the vendored normaliser', () => {
		expect( output ).toContain( "from '../forge/eca.ts'" );
	} );

	it( 'exports the config, path, origins and taxonomy base', () => {
		expect( output ).toContain( 'export const ecaConfig: ForgeEca =' );
		expect( output ).toContain( 'export const ecaBasePath = "/activities";' );
		expect( output ).toContain( 'export const ecaSourceOrigin = "https://eca.directory";' );
		expect( output ).toContain( 'export const ecaRestBase = "wp/v2/mcp_ai_eca";' );
		expect( output ).toContain( 'export const ecaTaxonomyRestBase = "wp/v2/mcp_ai_eca_category";' );
	} );

	it( 'bakes the operator site facts into the config literal', () => {
		expect( output ).toContain( '"currency": "LKR"' );
		expect( output ).toContain( '"timezone": "Asia/Colombo"' );
		expect( output ).toContain( '"id": "junior-school"' );
	} );

	it( 'emits a cached taxonomy reader', () => {
		expect( output ).toContain( 'export function ecaTerms()' );
		expect( output ).toContain( 'if ( ! termsPromise )' );
	} );

	it( 'renders the operator basePath, not the default', () => {
		const custom = renderEcaGenerated( profile( { eca: { basePath: '/clubs' } } ) );
		expect( custom ).toContain( 'export const ecaBasePath = "/clubs";' );
	} );

	it( 'normalises a sloppy basePath', () => {
		expect( renderEcaGenerated( profile( { eca: { basePath: 'clubs/' } } ) ) ).toContain( 'export const ecaBasePath = "/clubs";' );
	} );

	it( 'derives the taxonomy base from the profile collection', () => {
		const custom = renderEcaGenerated(
			profile( { collections: [ { slug: 'activities', restBase: 'wp/v2/eca_activity', fields: [ 'id' ] } ] } ),
		);
		expect( custom ).toContain( 'export const ecaTaxonomyRestBase = "wp/v2/eca_activity_category";' );
	} );
} );

describe( 'ecaScaffoldWarnings', () => {
	it( 'says nothing when the profile is not an ECA site', () => {
		expect( ecaScaffoldWarnings( profile() ) ).toEqual( [] );
	} );

	it( 'warns about every fact the forge cannot infer', () => {
		const warnings = ecaScaffoldWarnings( profile( { eca: { ...ECA_PROFILE_DEFAULTS } } ) );
		expect( warnings.some( ( line ) => line.includes( 'eca.terms' ) ) ).toBe( true );
		expect( warnings.some( ( line ) => line.includes( 'eca.timezone' ) ) ).toBe( true );
		expect( warnings.some( ( line ) => line.includes( 'eca.currency' ) ) ).toBe( true );
		expect( warnings.some( ( line ) => line.includes( 'urlTemplate' ) ) ).toBe( true );
	} );

	it( 'is silent once the site facts are supplied', () => {
		const warnings = ecaScaffoldWarnings(
			profile( {
				eca: {
					...ECA_PROFILE_DEFAULTS,
					terms: [ { id: 'michaelmas-2026', label: 'Michaelmas 2026', start: '2026-09-01', end: '2026-12-11' } ],
					timezone: 'Asia/Colombo',
					currency: 'LKR',
					booking: { mode: 'external', urlTemplate: 'https://school.test/join/{slug}' },
				},
			} ),
		);
		expect( warnings ).toEqual( [] );
	} );

	it( 'warns when the stage list is emptied on purpose', () => {
		const warnings = ecaScaffoldWarnings( profile( { eca: { stages: [] } } ) );
		expect( warnings.some( ( line ) => line.includes( 'eca.stages' ) ) ).toBe( true );
	} );
} );
