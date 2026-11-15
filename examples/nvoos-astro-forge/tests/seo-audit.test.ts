// ---------------------------------------------------------------------------
// seo-audit.test.ts — the `verify --seo` gate (Proposal 131 §2.6).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { auditSeo, scanSeoHtml } from '../lib/seo-audit.ts';

const CLEAN = `<!doctype html><html lang="en"><head>
<title>Contact — Fixture Wares</title>
<meta name="description" content="Reach the studio." />
<link rel="canonical" href="https://example.com/contact/" />
<meta name="robots" content="index, follow" />
<meta property="og:title" content="Contact" />
<meta property="og:type" content="website" />
<meta property="og:url" content="https://example.com/contact/" />
<meta name="twitter:card" content="summary" />
<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"Fixture Wares"}]}</script>
</head><body>ok</body></html>`;

const codes = ( findings: ReturnType< typeof auditSeo > ) => findings.map( ( finding ) => finding.code );

describe( 'scanSeoHtml', () => {
	it( 'collects title, metas, canonical, json-ld and lang', () => {
		const facts = scanSeoHtml( CLEAN );
		expect( facts.title ).toBe( 'Contact — Fixture Wares' );
		expect( facts.metas.description ).toBe( 'Reach the studio.' );
		expect( facts.metas[ 'og:title' ] ).toBe( 'Contact' );
		expect( facts.canonical ).toBe( 'https://example.com/contact/' );
		expect( facts.jsonLd ).toHaveLength( 1 );
		expect( facts.lang ).toBe( 'en' );
	} );
} );

describe( 'auditSeo — clean page', () => {
	it( 'returns no findings', () => {
		expect( auditSeo( CLEAN, { siteName: 'Fixture Wares', pathname: '/contact/' } ) ).toEqual( [] );
	} );
} );

describe( 'auditSeo — titles', () => {
	it( 'fails a double-branded title', () => {
		const html = CLEAN.replace(
			'<title>Contact — Fixture Wares</title>',
			'<title>Contact Fixture Wares — Fixture Wares</title>'
		);
		expect( codes( auditSeo( html, { siteName: 'Fixture Wares', pathname: '/contact/' } ) ) ).toContain(
			'title-double-brand'
		);
	} );

	it( 'fails when the title is missing', () => {
		const html = CLEAN.replace( /<title>[^<]*<\/title>/, '' );
		expect( codes( auditSeo( html, { siteName: 'Fixture Wares', pathname: '/contact/' } ) ) ).toContain(
			'title-missing'
		);
	} );
} );

describe( 'auditSeo — robots and canonical', () => {
	it( 'fails a noindex homepage', () => {
		const html = CLEAN.replace( 'content="index, follow"', 'content="noindex, follow"' );
		expect( codes( auditSeo( html, { siteName: 'Fixture Wares', pathname: '/' } ) ) ).toContain(
			'homepage-noindex'
		);
		// A noindex inner page is legitimate.
		expect( codes( auditSeo( html, { siteName: 'Fixture Wares', pathname: '/contact/' } ) ) ).not.toContain(
			'homepage-noindex'
		);
	} );

	it( 'warns when the canonical is missing or mismatched', () => {
		const missing = CLEAN.replace( /<link rel="canonical"[^>]*>/, '' );
		expect( codes( auditSeo( missing, { siteName: 'Fixture Wares', pathname: '/contact/' } ) ) ).toContain(
			'canonical-missing'
		);
		expect(
			codes(
				auditSeo( CLEAN, {
					siteName: 'Fixture Wares',
					pathname: '/contact/',
					expectedUrl: 'https://example.com/elsewhere/',
				} )
			)
		).toContain( 'canonical-mismatch' );
	} );

	it( 'warns when no robots directive is declared', () => {
		const html = CLEAN.replace( /<meta name="robots"[^>]*>/, '' );
		expect( codes( auditSeo( html, { siteName: 'Fixture Wares', pathname: '/contact/' } ) ) ).toContain(
			'robots-missing'
		);
	} );
} );

describe( 'auditSeo — social head', () => {
	it( 'warns on each missing Open Graph tag and the Twitter card', () => {
		const html = CLEAN.replace( /<meta property="og:title"[^>]*>/, '' ).replace(
			/<meta name="twitter:card"[^>]*>/,
			''
		);
		const found = codes( auditSeo( html, { siteName: 'Fixture Wares', pathname: '/contact/' } ) );
		expect( found ).toContain( 'og-incomplete' );
		expect( found ).toContain( 'twitter-card-missing' );
	} );
} );

describe( 'auditSeo — JSON-LD', () => {
	it( 'fails invalid JSON-LD', () => {
		const html = CLEAN.replace( /\{"@context"[^<]*\}/, '{not json' );
		expect( codes( auditSeo( html, { siteName: 'Fixture Wares', pathname: '/contact/' } ) ) ).toContain(
			'jsonld-invalid'
		);
	} );

	it( 'warns when JSON-LD is not wired into a graph', () => {
		const html = CLEAN.replace(
			'{"@context":"https://schema.org","@graph":[{"@type":"Organization","name":"Fixture Wares"}]}',
			'{"@context":"https://schema.org","@type":"WebPage"}'
		);
		expect( codes( auditSeo( html, { siteName: 'Fixture Wares', pathname: '/contact/' } ) ) ).toContain(
			'jsonld-graph-missing'
		);
	} );
} );

describe( 'auditSeo — document basics', () => {
	it( 'warns on a missing html lang', () => {
		const html = CLEAN.replace( '<html lang="en">', '<html>' );
		expect( codes( auditSeo( html, { siteName: 'Fixture Wares', pathname: '/contact/' } ) ) ).toContain(
			'html-lang-missing'
		);
	} );
} );
