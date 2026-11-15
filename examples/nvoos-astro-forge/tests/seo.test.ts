// ---------------------------------------------------------------------------
// seo.test.ts — the template's kind-typed JSON-LD builder (Proposal 104
// §4.6): shapes per kind, offer wiring, breadcrumbs, and the `<` escape.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { breadcrumbList, composeDocumentTitle, jsonLdFor, jsonLdGraph, renderJsonLd } from '../templates/blog/src/lib/seo.ts';

describe( 'jsonLdFor — WebPage', () => {
	it( 'emits the typed document and drops absent fields', () => {
		const doc = jsonLdFor( 'WebPage', { url: 'https://example.com/about/' } );
		expect( doc ).toEqual( {
			'@context': 'https://schema.org',
			'@type': 'WebPage',
			url: 'https://example.com/about/',
		} );
	} );
} );

describe( 'jsonLdFor — BlogPosting', () => {
	it( 'emits the post fields the template renders', () => {
		const doc = jsonLdFor( 'BlogPosting', {
			url: 'https://example.com/posts/1/hello/',
			headline: 'Hello',
			description: 'A hello post.',
			datePublished: '2026-10-01',
			dateModified: '2026-10-02',
			author: 'Ada',
			image: 'https://example.com/a.png',
		} );
		expect( doc ).toEqual( {
			'@context': 'https://schema.org',
			'@type': 'BlogPosting',
			headline: 'Hello',
			description: 'A hello post.',
			datePublished: '2026-10-01',
			dateModified: '2026-10-02',
			url: 'https://example.com/posts/1/hello/',
			author: { '@type': 'Person', name: 'Ada' },
			image: 'https://example.com/a.png',
		} );
	} );

	it( 'omits the author and image when absent', () => {
		const doc = jsonLdFor( 'BlogPosting', { headline: 'Bare' } );
		expect( doc ).toEqual( {
			'@context': 'https://schema.org',
			'@type': 'BlogPosting',
			headline: 'Bare',
		} );
	} );
} );

describe( 'jsonLdFor — Article (the BlogPosting base shape, 106 parity)', () => {
	it( 'emits the article fields and drops absent ones', () => {
		const doc = jsonLdFor( 'Article', {
			headline: 'An article',
			description: 'About things.',
			datePublished: '2026-10-01',
			url: 'https://example.com/posts/1/an-article/',
		} );
		expect( doc ).toEqual( {
			'@context': 'https://schema.org',
			'@type': 'Article',
			headline: 'An article',
			description: 'About things.',
			datePublished: '2026-10-01',
			url: 'https://example.com/posts/1/an-article/',
		} );
	} );
} );

describe( 'jsonLdFor — Product', () => {
	it( 'emits the commerce fields with a typed Offer', () => {
		const doc = jsonLdFor( 'Product', {
			name: 'Sweater',
			image: 'https://example.com/sweater.png',
			description: 'Warm.',
			sku: 'SW-1',
			offers: {
				price: '120.00',
				priceCurrency: 'USD',
				availability: 'https://schema.org/InStock',
				url: 'https://example.com/products/1/sweater/',
			},
		} );
		expect( doc ).toEqual( {
			'@context': 'https://schema.org',
			'@type': 'Product',
			name: 'Sweater',
			image: 'https://example.com/sweater.png',
			description: 'Warm.',
			sku: 'SW-1',
			offers: {
				'@type': 'Offer',
				price: '120.00',
				priceCurrency: 'USD',
				availability: 'https://schema.org/InStock',
				url: 'https://example.com/products/1/sweater/',
			},
		} );
	} );

	it( 'drops the offer when the source has no price', () => {
		const doc = jsonLdFor( 'Product', { name: 'Freebie' } );
		expect( doc ).toEqual( {
			'@context': 'https://schema.org',
			'@type': 'Product',
			name: 'Freebie',
		} );
	} );
} );

describe( 'breadcrumbList', () => {
	it( 'numbers the trail and references URLs by @id', () => {
		const doc = breadcrumbList( [
			{ name: 'Home', url: 'https://example.com/' },
			{ name: 'Products', url: 'https://example.com/products/' },
		] );
		expect( doc ).toEqual( {
			'@context': 'https://schema.org',
			'@type': 'BreadcrumbList',
			itemListElement: [
				{ '@type': 'ListItem', position: 1, name: 'Home', item: { '@id': 'https://example.com/' } },
				{ '@type': 'ListItem', position: 2, name: 'Products', item: { '@id': 'https://example.com/products/' } },
			],
		} );
	} );
} );

describe( 'renderJsonLd', () => {
	it( 'serializes and escapes < (the inert-script rule)', () => {
		const doc = { '@type': 'WebPage', name: 'a < b' };
		expect( renderJsonLd( doc ) ).toBe( '{"@type":"WebPage","name":"a \\u003c b"}' );
		expect( renderJsonLd( doc ) ).not.toContain( '<' );
	} );
} );

describe( 'composeDocumentTitle (131 §2.1)', () => {
	it( 'appends the site name when the page title does not carry it', () => {
		expect( composeDocumentTitle( 'About Us', 'Fixture Wares' ) ).toBe( 'About Us — Fixture Wares' );
	} );

	it( 'never double-brands a title that already ends with the site name', () => {
		expect( composeDocumentTitle( 'Contact Fixture Wares', 'Fixture Wares' ) ).toBe( 'Contact Fixture Wares' );
		expect( composeDocumentTitle( 'Contact - Fixture Wares', 'Fixture Wares' ) ).toBe( 'Contact - Fixture Wares' );
		expect( composeDocumentTitle( 'Contact | Fixture Wares', 'Fixture Wares' ) ).toBe( 'Contact | Fixture Wares' );
		expect( composeDocumentTitle( 'Contact : Fixture Wares', 'Fixture Wares' ) ).toBe( 'Contact : Fixture Wares' );
	} );

	it( 'leaves the bare site name and the empty title alone', () => {
		expect( composeDocumentTitle( 'Fixture Wares', 'Fixture Wares' ) ).toBe( 'Fixture Wares' );
		expect( composeDocumentTitle( '', 'Fixture Wares' ) ).toBe( 'Fixture Wares' );
		expect( composeDocumentTitle( '   ', 'Fixture Wares' ) ).toBe( 'Fixture Wares' );
	} );

	it( 'still appends when the brand appears only in the middle', () => {
		expect( composeDocumentTitle( 'Fixture Wares shipping policy', 'Fixture Wares' ) ).toBe(
			'Fixture Wares shipping policy — Fixture Wares'
		);
	} );
} );

describe( 'jsonLdGraph (131 §2.3)', () => {
	const page = jsonLdFor( 'BlogPosting', { headline: 'Hello', url: 'https://example.com/posts/1/hello/' } );

	it( 'wires Organization + WebSite + the page with @id references', () => {
		const graph = jsonLdGraph( {
			site: { url: 'https://example.com', name: 'Example', logo: 'https://example.com/logo.png' },
			page,
		} );
		expect( graph[ '@context' ] ).toBe( 'https://schema.org' );
		expect( graph[ '@graph' ] ).toHaveLength( 3 );
		const [ organization, website, node ] = graph[ '@graph' ];
		expect( organization ).toMatchObject( {
			'@type': 'Organization',
			'@id': 'https://example.com/#organization',
			name: 'Example',
			logo: { '@type': 'ImageObject', url: 'https://example.com/logo.png' },
		} );
		expect( website ).toMatchObject( {
			'@type': 'WebSite',
			'@id': 'https://example.com/#website',
			publisher: { '@id': 'https://example.com/#organization' },
		} );
		expect( node ).toMatchObject( {
			'@type': 'BlogPosting',
			headline: 'Hello',
			isPartOf: { '@id': 'https://example.com/#website' },
		} );
		// The page's own @context never leaks into the graph.
		expect( node ).not.toHaveProperty( '@context' );
	} );

	it( 'adds the SearchAction when a search URL is configured', () => {
		const graph = jsonLdGraph( {
			site: { url: 'https://example.com', name: 'Example', searchUrl: 'https://example.com/search/' },
			page,
		} );
		const website = graph[ '@graph' ][ 1 ] as { potentialAction?: { target?: { urlTemplate?: string } } };
		expect( website.potentialAction?.target?.urlTemplate ).toBe(
			'https://example.com/search/?q={search_term_string}'
		);
	} );

	it( 'includes breadcrumbs without a nested @context', () => {
		const graph = jsonLdGraph( {
			site: { url: 'https://example.com', name: 'Example' },
			page,
			breadcrumbs: [
				{ name: 'Home', url: 'https://example.com/' },
				{ name: 'Posts', url: 'https://example.com/posts/' },
			],
		} );
		expect( graph[ '@graph' ] ).toHaveLength( 4 );
		const crumbs = graph[ '@graph' ][ 2 ];
		expect( crumbs ).toMatchObject( { '@type': 'BreadcrumbList' } );
		expect( crumbs ).not.toHaveProperty( '@context' );
	} );
} );
