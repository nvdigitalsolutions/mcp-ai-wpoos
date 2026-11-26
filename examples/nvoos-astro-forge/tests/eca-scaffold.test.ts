// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// eca-scaffold.test.ts — the `eca` preset and its generated artifacts
// (Proposal 163 §P1).
//
// The preset is the gate: a profile without `eca` must produce byte-identical
// output to today's blog rebuild, so every assertion here is about the ECA
// branch specifically — what it adds, and what it refuses to duplicate.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { readFile } from 'node:fs/promises';

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
import { resolveContactBlock } from '../lib/scaffold.ts';
import { ecaRouteFiles, type ForgeFile } from '../lib/eca-ui.ts';

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

describe( 'resolveContactBlock — the contact band’s people', () => {
	const base = ( extra: Partial< SiteProfile > ): SiteProfile => ( {
		url: 'https://school.test',
		collections: [],
		...extra,
	} );

	it( 'derives the people from eca.contacts instead of a second copy', () => {
		// One source of truth: `/help/` renders the same list, so an address
		// changed in `eca.contacts` must not leave the home band stale.
		const block = resolveContactBlock(
			base( {
				eca: {
					contacts: [
						{ label: 'ECA Coordinator', role: 'Senior School programme', email: 'eca@school.test' },
						{ label: 'IT Support' },
					],
				},
			} )
		);
		expect( block?.people ).toEqual( [
			{ label: 'ECA Coordinator', role: 'Senior School programme', email: 'eca@school.test' },
			{ label: 'IT Support' },
		] );
		// The first reachable address is the single-email fallback.
		expect( block?.email ).toBe( 'eca@school.test' );
		expect( block?.links?.[ 0 ]?.href ).toBe( '/help/' );
	} );

	it( 'lets an explicit contact block win', () => {
		const block = resolveContactBlock(
			base( {
				contact: { heading: 'Speak to admissions', email: 'admissions@school.test' },
				eca: { contacts: [ { label: 'ECA Coordinator', email: 'eca@school.test' } ] },
			} )
		);
		expect( block ).toEqual( { heading: 'Speak to admissions', email: 'admissions@school.test' } );
	} );

	it( 'returns nothing when there is no one to name', () => {
		// An empty contact band is worse than none: never invent one.
		expect( resolveContactBlock( base( {} ) ) ).toBeUndefined();
		expect( resolveContactBlock( base( { eca: { contacts: [] } } ) ) ).toBeUndefined();
	} );
} );

describe( 'the generated help page', () => {
	it( 'opts the contact addresses out of Cloudflare email obfuscation', () => {
		// The zone rewrites a mailto anchor's href and its text, restoring both with
		// JS. On a help page every address would read "[email protected]" for a
		// no-JS parent, which is the opposite of the page's job.
		const help = ecaRouteFiles( {
			url: 'https://school.test',
			collections: [],
			eca: { contacts: [ { label: 'ECA Coordinator', email: 'eca@school.test' } ] },
		} ).find( ( file ) => file.path === 'src/pages/help/index.astro' );
		expect( help?.content ).toContain( '<!--email_off-->' );
		expect( help?.content ).toContain( '<!--/email_off-->' );
	} );
} );

describe( 'the committed ECA profile', () => {
	// The fleet builds `sites/eca.json` with no env overrides. A regression here
	// is silent — the build just spends 15s aborting — so the artifact is pinned.
	const load = async (): Promise< SiteProfile > =>
		JSON.parse(
			await readFile( new URL( '../sites/eca.json', import.meta.url ), 'utf8' )
		) as SiteProfile;

	it( 'declares a transport cap the source actually needs', async () => {
		const profile = await load();
		// eca.directory serialises 19 Elementor pages into one 1.23 MB response
		// that takes ~26s; the client's 15s default is the failure being prevented.
		expect( profile.fetch?.timeoutMs ).toBeGreaterThanOrEqual( 30_000 );
	} );

	it( 'declares its stages instead of relying on the fallback', async () => {
		const profile = await load();
		const stages = profile.eca?.stages ?? [];
		expect( stages.map( ( stage ) => stage.id ) ).toEqual( [
			'junior-school',
			'senior-school',
		] );
		// The warning path exists for a profile that says nothing; this one must
		// never reach it.
		expect( ecaScaffoldWarnings( profile ) ).toEqual( [] );
	} );

	it( 'keeps the CMS origin split and the manager-session bypass', async () => {
		const profile = await load();
		expect( profile.url ).toBe( 'https://cms.eca.directory' );
		expect( profile.deploy?.publicOrigin ).toBe( 'https://eca.directory' );
		expect( profile.deploy?.manage ).toBe( true );
		expect( profile.gatePublish?.session ).toEqual( {
			bypass: true,
			cookieName: 'nvoos_forge_session',
		} );
	} );
} );

// ---------------------------------------------------------------------------
// Which CMS pages the preset takes over (Proposal 163 §P4).
//
// A builder's listing page is a DATA VIEW: its rows come from the record set,
// so replaying its widgets is always wrong. The claim must be DERIVED from what
// the site says — its slug, its title, the shape of its band — never from a
// hardcoded slug list.
// ---------------------------------------------------------------------------
describe( 'ecaDataPages — which pages become data views', () => {
	const profile = ( over: Record< string, unknown > = {} ): never =>
		( {
			url: 'https://eca.example',
			name: 'ECA',
			collections: [],
			inventory: {
				shape: 'multi-page',
				navAnchors: [],
				navPages: [],
				covered: [ '/', '/eca-days/', '/select-list/', '/junior-school-list/', '/tuesday/' ],
				titles: {
					'/eca-days/': 'Monday- Junior School',
					'/select-list/': 'Select List',
					'/junior-school-list/': 'Junior School List',
					'/tuesday/': 'Tuesday activities',
				},
				excluded: [],
				missing: [],
				danglingAnchors: [],
				analyzedAt: '2026-11-25T00:00:00.000Z',
			},
			pageBands: {
				'junior-school-list': {
					measuredAt: '2026-11-25T00:00:00.000Z',
					sourceHash: 'x',
					sections: [ { id: 'aaa', columns: new Array( 30 ).fill( { width: 3 } ) } ],
					geometry: {},
					bandFacts: {},
				},
			},
			...over,
		} ) as never;

	it( 'claims a weekday slug as that day', async () => {
		const { ecaDataPages } = await import( '../lib/eca-ui.ts' );
		const pages = ecaDataPages( profile() );
		expect( pages.find( ( page ) => page.slug === 'tuesday' )?.day ).toBe( 'Tuesday' );
	} );

	it( 'claims a page whose TITLE names a weekday, whatever its slug says', async () => {
		// `eca-days` is titled "Monday- Junior School" and lists the Monday
		// activities — its slug says nothing, so only the title can decide.
		const { ecaDataPages } = await import( '../lib/eca-ui.ts' );
		const page = ecaDataPages( profile() ).find( ( entry ) => entry.slug === 'eca-days' );
		expect( page?.day ).toBe( 'Monday' );
		expect( page?.title ).toBe( 'Monday- Junior School' );
	} );

	it( 'claims a -list page only when its band actually renders rows, and never a chooser', async () => {
		const { ecaDataPages } = await import( '../lib/eca-ui.ts' );
		const pages = ecaDataPages( profile() );
		expect( pages.map( ( page ) => page.slug ) ).toContain( 'junior-school-list' );
		expect( pages.map( ( page ) => page.slug ) ).not.toContain( 'select-list' );
	} );
} );

describe( 'ECA structured data', () => {
	// The listing must be eligible for Google's Course carousel: an ItemList
	// whose entries are Course objects carrying name + provider, and a detail
	// Course whose CourseInstance survives a timetable with no explicit days.
	const files = (): ForgeFile[] =>
		ecaRouteFiles( {
			url: 'https://school.test',
			collections: [],
			eca: { basePath: '/activities', schoolName: 'Test School' },
		} );

	it( 'emits an ItemList of Course items on the catalogue page', () => {
		const index = files().find( ( file ) => file.content.includes( 'const listed = records.slice( 0, 50 );' ) );
		expect( index, 'the catalogue page should build a capped listing' ).toBeTruthy();

		expect( index?.content ).toContain( 'numberOfItems: listed.length' );
		expect( index?.content ).toContain( "item: {\n\t\t\t\t'@type': 'Course'" );
		expect( index?.content ).toContain( "provider: {\n\t\t\t\t\t'@type': 'Organization'" );
		// The old shape counted every record but only listed 50 of them.
		expect( index?.content ).not.toContain( 'numberOfItems: records.length' );
	} );

	it( 'keeps the CourseInstance when only a start time is published', () => {
		const detail = files().find( ( file ) => file.content.includes( 'const hasInstance =' ) );
		expect( detail, 'the activity page should derive hasInstance' ).toBeTruthy();

		expect( detail?.content ).toContain(
			"const hasInstance = record.schedule.days.length > 0 || Boolean( record.schedule.start );"
		);
		expect( detail?.content ).toContain( '...( hasInstance' );
		expect( detail?.content ).not.toContain( '...( days' );
		// byDay is omitted rather than emitted empty.
		expect( detail?.content ).toContain( 'record.schedule.days.length > 0\n' );
	} );

	it( 'gives the Offer a URL for eligibility', () => {
		const detail = files().find( ( file ) => file.content.includes( 'const hasInstance =' ) );
		expect( detail?.content ).toContain( "priceCurrency: record.pricing.currency ?? 'LKR', url: canonical" );
	} );
} );
