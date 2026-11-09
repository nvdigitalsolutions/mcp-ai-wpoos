// ---------------------------------------------------------------------------
// readme.test.ts — the deploy-branch build report.
//
// The README ships on a branch that is force-pushed on every build, so a
// regression here is a silently-wrong provenance document on a client site.
// These tests pin the contract: provenance, facts, the do-not-edit warning,
// the band section (absent when unanalysed), and byte-determinism.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { composeSiteReadme } from '../lib/readme.ts';
import type { ReadmeInput } from '../lib/readme.ts';
import type { ContrastAuditPair } from '../lib/contrast.ts';

const pair = ( overrides: Partial< ContrastAuditPair > = {} ): ContrastAuditPair => ( {
	token: 'ink',
	background: 'surface',
	label: 'body text (ink on surface)',
	mode: 'light',
	required: 4.5,
	ratio: 15.5,
	pass: true,
	...overrides,
} );

const base = ( overrides: Partial< ReadmeInput > = {} ): ReadmeInput => ( {
	name: 'Jayainc',
	summary: 'A roofing and waterproofing contractor in Jamaica.',
	cmsUrl: 'https://jayainc.com/',
	publicOrigin: 'https://jayainc.com',
	generatedAt: '2026-11-08T12:25:44.134Z',
	generator: 'nvoos-astro-forge',
	builder: 'elementor',
	render: 'static',
	profilePath: 'sites/jayainc.json',
	counts: { pages: 12, posts: 4, products: 0 },
	collections: [ { slug: 'pages', kind: 'page', restBase: 'wp/v2/pages' } ],
	surfaces: { search: true, feed: true, sitemap: true, ogImages: true, markdown: true },
	tokens: { ink: '#111111', surface: '#ffffff' },
	concept: 'Sharp, high-contrast trade brand.',
	analysedAt: '2026-11-08T12:20:00.000Z',
	audit: { pairs: [ pair(), pair( { token: 'muted' } ) ], failures: [], unparsed: [] },
	...overrides,
} );

describe( 'composeSiteReadme — identity and warning', () => {
	it( 'opens with the site name and the summary as a blockquote', () => {
		const readme = composeSiteReadme( base() );
		expect( readme.startsWith( '# Jayainc\n' ) ).toBe( true );
		expect( readme ).toContain( '> A roofing and waterproofing contractor in Jamaica.' );
	} );

	it( 'states that the branch is a generated artifact and must not be hand-edited', () => {
		const readme = composeSiteReadme( base() );
		expect( readme ).toContain( 'Generated artifact — do not edit this branch by hand.' );
		expect( readme ).toContain( 'force-pushed' );
	} );

	it( 'omits the blockquote when there is no summary', () => {
		const readme = composeSiteReadme( base( { summary: '   ' } ) );
		expect( readme ).not.toContain( '\n> ' );
	} );

	it( 'collapses multi-line summaries onto one line', () => {
		const readme = composeSiteReadme( base( { summary: 'One line.\n  Another line.' } ) );
		expect( readme ).toContain( '> One line. Another line.' );
	} );
} );

describe( 'composeSiteReadme — provenance', () => {
	it( 'reports the generator, source CMS, public origin and site profile', () => {
		const readme = composeSiteReadme( base() );
		expect( readme ).toContain( '| Generator | `nvoos-astro-forge` |' );
		expect( readme ).toContain( '| Source CMS | `https://jayainc.com` |' );
		expect( readme ).toContain( '| Public origin | `https://jayainc.com` |' );
		expect( readme ).toContain( '| Site profile | `sites/jayainc.json` |' );
	} );

	it( 'includes the page builder only when one was detected', () => {
		expect( composeSiteReadme( base() ) ).toContain( '| Page builder | `elementor` |' );
		expect( composeSiteReadme( base( { builder: undefined } ) ) ).not.toContain( 'Page builder' );
	} );

	it( 'defaults the render mode to static', () => {
		expect( composeSiteReadme( base( { render: undefined } ) ) ).toContain( '| Render mode | `static` |' );
	} );

	it( 'falls back to the CMS origin when no public origin is set', () => {
		const readme = composeSiteReadme( base( { publicOrigin: undefined } ) );
		expect( readme ).toContain( '| Public origin | `https://jayainc.com` |' );
	} );
} );

describe( 'composeSiteReadme — facts and design', () => {
	it( 'renders the page, post and product counts', () => {
		const readme = composeSiteReadme( base() );
		expect( readme ).toContain( '| Pages | 12 |' );
		expect( readme ).toContain( '| Posts | 4 |' );
		expect( readme ).toContain( '| Products | 0 |' );
	} );

	it( 'describes the collections with their REST base', () => {
		expect( composeSiteReadme( base() ) ).toContain( '`pages` (wp/v2/pages)' );
	} );

	it( 'summarises the contrast audit as passed over total', () => {
		expect( composeSiteReadme( base() ) ).toContain( '**2/2** token pairs meet WCAG 2.2' );
	} );

	it( 'lists failing pairs in a table when the audit has failures', () => {
		const failing = pair( { label: 'muted on surface', pass: false, ratio: 3.3 } );
		const audit = { pairs: [ pair(), failing ], failures: [ failing ], unparsed: [] };
		const readme = composeSiteReadme( base( { audit } ) );
		expect( readme ).toContain( '**1/2** token pairs meet WCAG 2.2' );
		expect( readme ).toContain( '| muted on surface | light | 3.30 | 4.5:1 |' );
	} );

	it( 'renders attribution when the adaptation carries a licensing note', () => {
		const readme = composeSiteReadme(
			base( { adaptation: { licensingNote: 'Adapted, not copied.', attribution: [ 'Original brand © Jayainc.' ] } } ),
		);
		expect( readme ).toContain( '### Attribution' );
		expect( readme ).toContain( 'Adapted, not copied.' );
		expect( readme ).toContain( '- Original brand © Jayainc.' );
	} );
} );

describe( 'composeSiteReadme — band structure', () => {
	it( 'omits the band section entirely when the source was not analysed', () => {
		expect( composeSiteReadme( base() ) ).not.toContain( '## Band structure' );
	} );

	it( 'lists each detected band with its anchor, column count and detection layer', () => {
		const readme = composeSiteReadme(
			base( {
				bands: [
					{ id: 'header', anchor: 'home', columns: 2, via: 'semantic' },
					{ id: 'about', anchor: 'about', via: 'visual' },
				],
			} ),
		);
		expect( readme ).toContain( '2 vertical bands were detected on the source page with the `elementor` adapter.' );
		expect( readme ).toContain( '| 1 | header | `#home` | 2 | semantic |' );
		expect( readme ).toContain( '| 2 | about | `#about` |  | visual |' );
	} );

	it( 'escapes a pipe in a band id so the table does not break', () => {
		const readme = composeSiteReadme( base( { bands: [ { id: 'a|b', via: 'semantic' } ] } ) );
		expect( readme ).toContain( 'a\\|b' );
	} );
} );

describe( 'composeSiteReadme — surfaces and tracking', () => {
	it( 'lists the shipped artifacts', () => {
		const readme = composeSiteReadme( base() );
		for ( const path of [ 'sitemap.xml', 'feed.xml', 'robots.txt', 'search/', 'design-audit.json', 'tokens.dtcg.json', 'llms.txt' ] ) {
			expect( readme ).toContain( `\`${ path }\`` );
		}
	} );

	it( 'drops a surface the manifest disables', () => {
		const readme = composeSiteReadme( base( { surfaces: { sitemap: false, feed: false, search: false, markdown: false } } ) );
		expect( readme ).not.toContain( '`sitemap.xml`' );
		expect( readme ).not.toContain( '`llms.txt`' );
		expect( readme ).toContain( '`robots.txt`' );
	} );

	it( 'says so when no tracking is enabled', () => {
		expect( composeSiteReadme( base( { tracking: { enabled: false, vendors: [] } } ) ) ).toContain(
			'No analytics or marketing tags are enabled on this build.',
		);
	} );

	it( 'lists enabled vendors with the consent mode', () => {
		const readme = composeSiteReadme( base( { tracking: { enabled: true, vendors: [ 'gtm' ], consentMode: 'advanced' } } ) );
		expect( readme ).toContain( '1 consent-gated vendor(s): `gtm`.' );
		expect( readme ).toContain( 'Consent mode: `advanced`.' );
	} );
} );

describe( 'composeSiteReadme — rebuild instructions', () => {
	it( 'derives the target directory from the profile slug', () => {
		const readme = composeSiteReadme( base() );
		expect( readme ).toContain( 'node scripts/forge.mjs sites/jayainc.json --target ../jayainc-frontend' );
		expect( readme ).toContain( 'ASTRO_SITE=https://jayainc.com npm run build' );
		expect( readme ).toContain( '`site: jayainc`' );
	} );

	it( 'derives a slug from the name when there is no profile path', () => {
		const readme = composeSiteReadme( base( { profilePath: undefined, name: 'Virtually Jamaica' } ) );
		expect( readme ).toContain( 'sites/virtually-jamaica.json' );
		expect( readme ).toContain( '../virtually-jamaica-frontend' );
	} );
} );

describe( 'composeSiteReadme — determinism', () => {
	it( 'produces byte-identical output for identical input', () => {
		expect( composeSiteReadme( base() ) ).toBe( composeSiteReadme( base() ) );
	} );

	it( 'produces output for a minimal input without throwing', () => {
		const readme = composeSiteReadme( { name: 'Minimal', cmsUrl: 'https://example.com/' } );
		expect( readme.startsWith( '# Minimal\n' ) ).toBe( true );
		expect( readme.endsWith( '\n' ) ).toBe( true );
	} );
} );
