// ---------------------------------------------------------------------------
// kinds.test.ts — the six structural section kinds (Proposal 136 §4.1).
//
// Each kind is decided by EVIDENCE, never by a class token alone. The negative
// cases below are the point: a band that merely mentions a price must stay a
// feature grid, and a two-column table is not a comparison band.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { classifyHtml } from '../lib/semantic.ts';

const SECTION = ( inner: string ) =>
	`<div class="elementor-section elementor-top-section"><div class="elementor-container"><div class="elementor-column"><div class="elementor-widget-wrap">${ inner }</div></div></div></div>`;

const WIDGET = ( name: string, inner: string ) =>
	`<div class="elementor-widget elementor-widget-${ name }"><div class="elementor-widget-container">${ inner }</div></div>`;

const planCard = ( name: string, price: string, featured = false ): string =>
	`<div class="price-card${ featured ? ' featured' : '' }"><h3>${ name }</h3><div class="price">${ price }</div><ul><li>Feature A</li><li>Feature B</li></ul><a href="/buy">Choose</a></div>`;

const stat = ( value: string, label: string ): string =>
	`<div class="stat"><div class="counter">${ value }</div><p class="counter-label">${ label }</p></div>`;

describe( 'classifyHtml — structural kinds (136)', () => {
	it( 'classifies two priced plans as a pricing band', () => {
		const html = SECTION(
			WIDGET( 'price-table', planCard( 'Starter', '$29/mo' ) + planCard( 'Pro', '$98/mo', true ) )
		);
		const [ section ] = classifyHtml( html );

		expect( section.kind ).toBe( 'pricing' );
		expect( section.pricing?.plans ).toHaveLength( 2 );
		expect( section.pricing?.plans[ 0 ] ).toMatchObject( {
			name: 'Starter',
			price: '$29/mo',
			features: [ 'Feature A', 'Feature B' ],
			cta: { label: 'Choose', href: '/buy' },
		} );
		expect( section.pricing?.plans[ 1 ].featured ).toBe( true );
	} );

	it( 'does not classify a feature grid that mentions a price once', () => {
		const html = SECTION(
			WIDGET( 'icon-box', '<h3>Affordable</h3><p>Plans from $29 a month.</p>' ) +
				WIDGET( 'icon-box', '<h3>Fast</h3><p>Same-day delivery.</p>' )
		);
		const [ section ] = classifyHtml( html );

		expect( section.kind ).toBe( 'feature-grid' );
		expect( section.pricing ).toBeUndefined();
	} );

	it( 'reads the struck-through price as the original, not the current price', () => {
		const html = SECTION(
			WIDGET(
				'price-table',
				`<div class="price-card"><h3>Starter</h3><p class="price"><del>$39/mo</del> $29/mo</p><ul><li>A</li></ul></div>` +
					planCard( 'Pro', '$98/mo' )
			)
		);
		const [ section ] = classifyHtml( html );

		expect( section.kind ).toBe( 'pricing' );
		expect( section.pricing?.plans[ 0 ] ).toMatchObject( { price: '$29/mo', salePrice: '$39/mo' } );
	} );

	it( 'classifies value/label pairs as a stats band', () => {
		const html = SECTION(
			WIDGET( 'counter-group', stat( '98%', 'Client retention' ) + stat( '12k', 'Orders shipped' ) )
		);
		const [ section ] = classifyHtml( html );

		expect( section.kind ).toBe( 'stats' );
		expect( section.stats?.items ).toHaveLength( 2 );
		expect( section.stats?.items[ 0 ] ).toMatchObject( { value: '98', unit: '%', label: 'Client retention' } );
	} );

	it( 'classifies an ordered process list as a steps band', () => {
		const html = SECTION(
			WIDGET(
				'text-editor',
				'<h2>How we work</h2><ol><li><h3>Brief</h3><p>We listen.</p></li><li><h3>Build</h3></li></ol>'
			)
		);
		const [ section ] = classifyHtml( html );

		expect( section.kind ).toBe( 'steps' );
		expect( section.steps?.items ).toMatchObject( [
			{ title: 'Brief', body: 'We listen.' },
			{ title: 'Build' },
		] );
	} );

	it( 'does not classify an unmarked ordered list of prose as steps', () => {
		// No step/process class and no headings inside the items — this is a
		// list of points, not a sequence.
		const html = SECTION(
			WIDGET( 'text-editor', '<h2>Notes</h2><ol><li>Bring a coat.</li><li>Arrive early.</li></ol>' )
		);
		const [ section ] = classifyHtml( html );

		expect( section.kind ).not.toBe( 'steps' );
	} );

	it( 'classifies portrait figures as a team band and splits name from role', () => {
		const person = ( name: string, role: string ) =>
			`<figure><img src="${ name }.jpg" alt="" /><figcaption><span class="name">${ name }</span><span class="role">${ role }</span></figcaption></figure>`;
		const html = SECTION( WIDGET( 'team-member', person( 'Ada', 'CEO' ) + person( 'Grace', 'CTO' ) ) );
		const [ section ] = classifyHtml( html );

		expect( section.kind ).toBe( 'team' );
		expect( section.team?.people ).toMatchObject( [
			{ name: 'Ada', role: 'CEO', photo: 'Ada.jpg' },
			{ name: 'Grace', role: 'CTO', photo: 'Grace.jpg' },
		] );
	} );

	it( 'classifies a repeated named track as a marquee band', () => {
		const html = SECTION(
			WIDGET(
				'logo-marquee',
				'<div class="logo-marquee"><span>Acme</span><span>Beta</span><span>Gamma</span><span>Delta</span></div>'
			)
		);
		const [ section ] = classifyHtml( html );

		expect( section.kind ).toBe( 'marquee' );
		expect( section.marquee?.items.map( ( item ) => item.label ) ).toEqual( [ 'Acme', 'Beta', 'Gamma', 'Delta' ] );
	} );

	it( 'does not classify an ordinary logo cloud as a marquee', () => {
		const html = SECTION(
			WIDGET( 'image-gallery', '<img src="a.png" alt="" /><img src="b.png" alt="" /><img src="c.png" alt="" />' )
		);
		const [ section ] = classifyHtml( html );

		expect( section.kind ).toBe( 'logo-cloud' );
		expect( section.marquee ).toBeUndefined();
	} );

	it( 'classifies a labelled comparison table as a comparison band', () => {
		const html = SECTION(
			WIDGET(
				'text-editor',
				'<table><caption>Plans</caption><thead><tr><td></td><th>Starter</th><th>Pro</th></tr></thead>' +
					'<tbody><tr><th>Seats</th><td>1</td><td>10</td></tr><tr><th>Support</th><td>Email</td><td>Priority</td></tr></tbody></table>'
			)
		);
		const [ section ] = classifyHtml( html );

		expect( section.kind ).toBe( 'comparison' );
		expect( section.comparison ).toMatchObject( {
			columns: [ 'Starter', 'Pro' ],
			rows: [
				{ label: 'Seats', values: [ '1', '10' ] },
				{ label: 'Support', values: [ 'Email', 'Priority' ] },
			],
			caption: 'Plans',
		} );
	} );

	it( 'does not classify a two-column table as a comparison band', () => {
		// Ambiguous: a two-column table may be a plain spec list.
		const html = SECTION(
			WIDGET(
				'text-editor',
				'<h2>Specifications</h2><table><tbody><tr><th>Height</th><td>10cm</td></tr><tr><th>Width</th><td>20cm</td></tr></tbody></table>'
			)
		);
		const [ section ] = classifyHtml( html );

		expect( section.kind ).not.toBe( 'comparison' );
	} );

	it( 'keeps the structural kinds out of the way of ordinary documents', () => {
		const html = SECTION(
			WIDGET( 'heading', '<h2>About us</h2>' ) + WIDGET( 'text-editor', '<p>We ship hardware.</p>' )
		);
		const [ section ] = classifyHtml( html );

		expect( section.kind ).toBe( 'hero' );
		expect( section.pricing ).toBeUndefined();
		expect( section.stats ).toBeUndefined();
		expect( section.steps ).toBeUndefined();
		expect( section.team ).toBeUndefined();
		expect( section.marquee ).toBeUndefined();
		expect( section.comparison ).toBeUndefined();
	} );
} );
