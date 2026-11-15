// ---------------------------------------------------------------------------
// structured-data.test.ts — Proposal 136 §4.5.
//
// Two halves:
//   1. the band node builders (OfferCatalog / Person) in the template's seo.ts
//   2. the `verify --seo --structured` gate in lib/seo-audit.ts
//
// The gate exists because Google's own guidance requires structured data to
// match the visible text: a price in the graph that nobody can see is a
// mismatch, and a price only in the graph is an offer nobody can buy.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { auditSeo, auditStructuredData } from '../lib/seo-audit.ts';
import { bandNodes, jsonLdGraph, offerCatalogNode } from '../templates/blog/src/lib/seo.ts';

const JSONLD = ( payload: string ) =>
	`<script type="application/ld+json">${ payload }</script>`;

const page = ( body: string, jsonLd: string ) =>
	`<!doctype html><html lang="en"><head><title>Plans — Fixture</title>${ JSONLD( jsonLd ) }</head><body>${ body }</body></html>`;

describe( 'bandNodes — OfferCatalog', () => {
	it( 'emits a catalogue for a fully priced band', () => {
		const node = offerCatalogNode( {
			id: 'a1b2c3d',
			kind: 'pricing',
			pricing: { plans: [ { name: 'Starter', price: '$29/mo' }, { name: 'Pro', price: '$98/mo' } ] },
		} );

		expect( node ).toMatchObject( {
			'@type': 'OfferCatalog',
			'@id': '#/pricing-a1b2c3d',
			itemListElement: [
				{ '@type': 'Offer', name: 'Starter', price: '$29/mo' },
				{ '@type': 'Offer', name: 'Pro', price: '$98/mo' },
			],
		} );
	} );

	it( 'emits nothing when a plan has no visible price', () => {
		expect(
			offerCatalogNode( {
				kind: 'pricing',
				pricing: { plans: [ { name: 'Starter', price: '$29/mo' }, { name: 'Enterprise', price: '' } ] },
			} )
		).toBeUndefined();
	} );

	it( 'emits nothing for a non-pricing band', () => {
		expect( offerCatalogNode( { kind: 'feature-grid' } ) ).toBeUndefined();
	} );
} );

describe( 'bandNodes — Person', () => {
	it( 'wires each member to the Organization', () => {
		const nodes = bandNodes(
			[ { kind: 'team', team: { people: [ { name: 'Ada', role: 'CEO' }, { name: 'Grace' } ] } } ],
			'https://example.com/#organization'
		);

		expect( nodes ).toHaveLength( 2 );
		expect( nodes[ 0 ] ).toMatchObject( {
			'@type': 'Person',
			name: 'Ada',
			jobTitle: 'CEO',
			worksFor: { '@id': 'https://example.com/#organization' },
		} );
		// A member without a role gets no fabricated jobTitle.
		expect( nodes[ 1 ] ).not.toHaveProperty( 'jobTitle' );
	} );
} );

describe( 'jsonLdGraph — band node placement', () => {
	it( 'places bands between the site entities and the page node', () => {
		const graph = jsonLdGraph( {
			site: { url: 'https://example.com', name: 'Fixture' },
			page: { '@type': 'WebPage', name: 'Home' },
			extraNodes: [ { '@type': 'OfferCatalog', name: 'Plans' } ],
		} );

		expect( graph[ '@graph' ].map( ( node ) => node[ '@type' ] ) ).toEqual( [
			'Organization',
			'WebSite',
			'OfferCatalog',
			'WebPage',
		] );
	} );
} );

describe( 'auditStructuredData', () => {
	it( 'passes when the price is visible in the pricing band', () => {
		const html = page(
			'<div data-section="pricing" data-section-id="aaa"><p>Starter $29/mo</p></div>',
			'{"@context":"https://schema.org","@graph":[{"@type":"OfferCatalog","itemListElement":[{"@type":"Offer","price":"$29/mo"}]}]}'
		);

		expect( auditStructuredData( html ) ).toEqual( [] );
	} );

	it( 'flags a price that exists only in the JSON-LD', () => {
		const html = page(
			'<div data-section="pricing" data-section-id="aaa"><p>Starter $29/mo</p></div>',
			'{"@context":"https://schema.org","@graph":[{"@type":"Offer","price":"$98/mo"}]}'
		);

		const findings = auditStructuredData( html );
		expect( findings ).toHaveLength( 1 );
		expect( findings[ 0 ] ).toMatchObject( { code: 'structured-fact-not-visible', severity: 'fail' } );
		expect( findings[ 0 ].message ).toContain( '$98/mo' );
	} );

	it( 'does not accept a price that only appears outside a pricing band', () => {
		// The price is on the page, but not in the band that claims the offer.
		const html = page(
			'<p>Our best plan costs $98/mo.</p><div data-section="pricing" data-section-id="aaa"><p>Contact us</p></div>',
			'{"@context":"https://schema.org","@graph":[{"@type":"Offer","price":"$98/mo"}]}'
		);

		expect( auditStructuredData( html ) ).toHaveLength( 1 );
	} );

	it( 'ignores URLs and type names — they are not facts', () => {
		const html = page(
			'<div data-section="text" data-section-id="aaa"><p>Nothing numeric here.</p></div>',
			'{"@context":"https://schema.org","@graph":[{"@type":"WebPage","url":"https://example.com/2026/11/09/post/"}]}'
		);

		expect( auditStructuredData( html ) ).toEqual( [] );
	} );

	it( 'is off unless asked for', () => {
		const html = page(
			'<div data-section="pricing" data-section-id="aaa"><p>Starter $29/mo</p></div>',
			'{"@context":"https://schema.org","@graph":[{"@type":"Offer","price":"$98/mo"}]}'
		);

		const base = auditSeo( html, { siteName: 'Fixture', pathname: '/' } );
		const strict = auditSeo( html, { siteName: 'Fixture', pathname: '/', structured: true } );

		expect( base.some( ( finding ) => finding.code === 'structured-fact-not-visible' ) ).toBe( false );
		expect( strict.some( ( finding ) => finding.code === 'structured-fact-not-visible' ) ).toBe( true );
	} );
} );
