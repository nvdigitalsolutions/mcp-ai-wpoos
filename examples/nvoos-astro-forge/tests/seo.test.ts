// ---------------------------------------------------------------------------
// seo.test.ts — the template's kind-typed JSON-LD builder (Proposal 104
// §4.6): shapes per kind, offer wiring, breadcrumbs, and the `<` escape.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { breadcrumbList, jsonLdFor, renderJsonLd } from '../templates/blog/src/lib/seo.ts';

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
			datePublished: '2026-10-01',
			dateModified: '2026-10-02',
			author: 'Ada',
			image: 'https://example.com/a.png',
		} );
		expect( doc ).toEqual( {
			'@context': 'https://schema.org',
			'@type': 'BlogPosting',
			headline: 'Hello',
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
