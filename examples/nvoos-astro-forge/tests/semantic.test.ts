// ---------------------------------------------------------------------------
// semantic.test.ts — the builder-structure classifier (Proposal 114 P2):
// Elementor widget sections, wp-block fallbacks, slot extraction, and the
// fallback ladder (component → text → drop).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { classifyHtml, SECTION_MAP } from '../lib/semantic.ts';

const SECTION = ( inner: string ) =>
	`<div class="elementor-section elementor-top-section"><div class="elementor-container"><div class="elementor-column"><div class="elementor-widget-wrap">${ inner }</div></div></div></div>`;

const WIDGET = ( name: string, inner: string ) =>
	`<div class="elementor-widget elementor-widget-${ name }"><div class="elementor-widget-container">${ inner }</div></div>`;

describe( 'classifyHtml — vendor-prefixed widget families (Phlox/Auxin)', () => {
	it( 'rebuilds aux_* widgets like the core Elementor ones', () => {
		const html = SECTION(
			WIDGET( 'aux_modern_heading', '<h2>Ship to South Florida</h2>' ) +
				WIDGET( 'aux_text', '<p>Highest quality of support and service.</p>' ) +
				WIDGET( 'aux_button', '<a href="/contact/">Get in touch</a>' )
		);
		const sections = classifyHtml( html );
		expect( sections[ 0 ].kind ).toBe( 'hero' );
		expect( sections[ 0 ].slots.title ).toBe( 'Ship to South Florida' );
		expect( sections[ 0 ].slots.body ).toContain( 'Highest quality of support and service.' );
		expect( sections[ 0 ].slots.links?.[ 0 ] ).toMatchObject( { text: 'Get in touch', href: '/contact/' } );
	} );

	// Proposal 117 §1 — the structured icon-text pattern (jayainc's live
	// markup): an aux_text widget whose header box carries an image or icon
	// plus a small heading + body is ONE card, not flat media + orphans.
	it( 'rebuilds aux_text image cards as a captioned feature grid', () => {
		const card = ( img: string, title: string, body: string ) =>
			`<div class="aux-widget-advanced-text"><div class="aux-text-widget-header"><div class="aux-ico-box aux-img-box"><img src="${ img }" alt="" /></div></div><div class="aux-text-inner"><h4>${ title }</h4><div class="widget-content"><p>${ body }</p></div></div></div>`;
		const html = SECTION(
			WIDGET( 'aux_text', card( 'a.jpg', 'Consumer Electronics', 'Cameras and audio.' ) ) +
				WIDGET( 'aux_text', card( 'b.jpg', 'Home Appliances', 'Kitchen gear.' ) )
		);
		const sections = classifyHtml( html );
		expect( sections[ 0 ].kind ).toBe( 'feature-grid' );
		expect( sections[ 0 ].slots.items ).toHaveLength( 2 );
		expect( sections[ 0 ].slots.items?.[ 0 ] ).toMatchObject( {
			title: 'Consumer Electronics',
			body: 'Cameras and audio.',
			media: { src: 'a.jpg' },
		} );
	} );

	it( 'rebuilds aux_text icon features as an icon list', () => {
		const feature = ( title: string, body: string ) =>
			`<div class="aux-widget-advanced-text"><div class="aux-text-widget-header"><div class="aux-ico-box"><span class="aux-ico auxicon-badge"></span></div></div><div class="aux-text-inner"><h4>${ title }</h4><div class="widget-content"><p>${ body }</p></div></div></div>`;
		const html = SECTION(
			WIDGET( 'aux_modern_heading', '<h2>Why Choose Us</h2><p>The relationships we have established.</p>' ) +
				WIDGET( 'aux_text', feature( 'Procurement', 'Best discounts.' ) ) +
				WIDGET( 'aux_text', feature( 'Export Services', 'All aspects of exports.' ) )
		);
		const sections = classifyHtml( html );
		expect( sections[ 0 ].kind ).toBe( 'icon-list' );
		expect( sections[ 0 ].slots.items ).toHaveLength( 2 );
		expect( sections[ 0 ].slots.items?.[ 0 ] ).toMatchObject( { title: 'Procurement', body: 'Best discounts.', icon: true } );
		// The section intro survives as body, not as a phantom item.
		expect( sections[ 0 ].slots.body ).toContain( 'The relationships we have established.' );
	} );

	it( 'carries the quote author line onto the testimonial item', () => {
		const html = SECTION(
			WIDGET( 'aux_blockquote', '<blockquote><p>Fast &amp; easy to work with.</p><div class="aux-author">Kumar Samtani</div></blockquote>' )
		);
		const sections = classifyHtml( html );
		expect( sections[ 0 ].kind ).toBe( 'testimonial' );
		expect( sections[ 0 ].slots.items?.[ 0 ] ).toMatchObject( { body: 'Fast & easy to work with.', author: 'Kumar Samtani' } );
	} );
} );

describe( 'classifyHtml — section ids (Proposal 117 §2 theming keys)', () => {
	it( 'carries the source Elementor section id on each rebuilt section', () => {
		const html =
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-a4ad586">' +
			'<div class="elementor-container"><div class="elementor-widget-wrap">' +
			WIDGET( 'heading', '<h2>Hero title</h2>' ) +
			'</div></div></section>' +
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-5e78df1">' +
			'<div class="elementor-container"><div class="elementor-widget-wrap">' +
			WIDGET( 'text-editor', '<p>Second band.</p>' ) +
			'</div></div></section>';
		const sections = classifyHtml( html );
		expect( sections[ 0 ].id ).toBe( 'a4ad586' );
		expect( sections[ 1 ].id ).toBe( '5e78df1' );
	} );
} );

describe( 'classifyHtml — wrapped Elementor documents (the wp-post root)', () => {
	it( 'splits sections nested under the data-elementor-type wrapper', () => {
		const html =
			'<div data-elementor-type="wp-post" class="elementor elementor-71">' +
			SECTION( WIDGET( 'heading', '<h2>Hero title</h2>' ) + WIDGET( 'text-editor', '<p>Hero copy.</p>' ) ) +
			SECTION( WIDGET( 'icon-box', '<h3>Wool</h3><p>Merino.</p>' ) + WIDGET( 'icon-box', '<h3>Linen</h3><p>Washed.</p>' ) ) +
			'</div>';
		const sections = classifyHtml( html );
		expect( sections.map( ( section ) => section.kind ) ).toEqual( [ 'hero', 'feature-grid' ] );
		expect( sections[ 1 ].slots.items ).toHaveLength( 2 );
	} );

	it( 'never splits a section nested inside another section', () => {
		const html =
			'<div class="elementor elementor-71">' +
			SECTION(
				WIDGET( 'heading', '<h2>Outer</h2>' ) +
					'<div class="elementor-section elementor-inner-section">' +
					WIDGET( 'text-editor', '<p>Inner content.</p>' ) +
					'</div>'
			) +
			'</div>';
		const sections = classifyHtml( html );
		expect( sections ).toHaveLength( 1 );
		expect( sections[ 0 ].slots.title ).toBe( 'Outer' );
	} );
} );

describe( 'classifyHtml — fallbacks', () => {
	it( 'wraps plain non-builder HTML as a single text section (the post-body path)', () => {
		const html = '<h2>Care</h2><p>Wash cold.</p>';
		const sections = classifyHtml( html );
		expect( sections ).toHaveLength( 1 );
		expect( sections[ 0 ].kind ).toBe( 'text' );
		expect( sections[ 0 ].html ).toBe( html );
	} );

	it( 'returns nothing for empty input', () => {
		expect( classifyHtml( '' ) ).toEqual( [] );
		expect( classifyHtml( '   ' ) ).toEqual( [] );
	} );

	it( 'drops recognized-but-empty builder scaffolding', () => {
		const html = SECTION( WIDGET( 'heading', '' ) + WIDGET( 'text-editor', '<div></div>' ) );
		expect( classifyHtml( html ) ).toEqual( [] );
	} );
} );

describe( 'classifyHtml — Elementor sections', () => {
	it( 'rebuilds a hero from heading + text + button in the first section', () => {
		const html =
			SECTION( WIDGET( 'heading', '<h2>Ship to South Florida</h2>' ) + WIDGET( 'text-editor', '<p>We offer a catalogue.</p>' ) + WIDGET( 'button', '<a href="#about">Read More</a>' ) ) +
			SECTION( WIDGET( 'text-editor', '<p>More content.</p>' ) );
		const sections = classifyHtml( html );
		expect( sections[ 0 ].kind ).toBe( 'hero' );
		expect( sections[ 0 ].slots.title ).toBe( 'Ship to South Florida' );
		expect( sections[ 0 ].slots.body ).toContain( 'We offer a catalogue.' );
		expect( sections[ 0 ].slots.links?.[ 0 ] ).toMatchObject( { text: 'Read More', href: '#about' } );
		// The later text-only section falls back to text with its raw HTML.
		expect( sections[ 1 ].kind ).toBe( 'text' );
	} );

	it( 'rebuilds icon-boxes as a feature grid', () => {
		const html = SECTION(
			WIDGET( 'icon-box', '<h3>Consumer Electronics</h3><p>Cameras and audio.</p>' ) +
				WIDGET( 'icon-box', '<h3>Healthcare</h3><p>Wellness products.</p>' )
		);
		const sections = classifyHtml( html );
		expect( sections[ 0 ].kind ).toBe( 'feature-grid' );
		expect( sections[ 0 ].slots.items ).toHaveLength( 2 );
		expect( sections[ 0 ].slots.items?.[ 0 ] ).toMatchObject( { title: 'Consumer Electronics', body: 'Cameras and audio.' } );
	} );

	it( 'rebuilds testimonials as quote sections', () => {
		const html = SECTION( WIDGET( 'testimonial', '<p>Fast &amp; easy to work with.</p><div>Kumar</div>' ) );
		const sections = classifyHtml( html );
		expect( sections[ 0 ].kind ).toBe( 'testimonial' );
		expect( sections[ 0 ].slots.items?.[ 0 ]?.body ).toBe( 'Fast & easy to work with.' );
	} );

	it( 'rebuilds an image wall as a logo cloud', () => {
		const html = SECTION(
			WIDGET( 'image', '<img src="/wp-content/uploads/a.png" alt="Alpha" />' ) +
				WIDGET( 'image', '<img src="/wp-content/uploads/b.png" alt="Beta" />' ) +
				WIDGET( 'image', '<img src="/wp-content/uploads/c.png" alt="Gamma" />' )
		);
		const sections = classifyHtml( html );
		expect( sections[ 0 ].kind ).toBe( 'logo-cloud' );
		expect( sections[ 0 ].slots.media ).toHaveLength( 3 );
		expect( sections[ 0 ].slots.media?.[ 0 ] ).toMatchObject( { src: '/wp-content/uploads/a.png', alt: 'Alpha' } );
	} );

	it( 'rebuilds heading + buttons as a CTA band', () => {
		const html = SECTION( WIDGET( 'heading', '<h2>Ready?</h2>' ) + WIDGET( 'button', '<a href="/contact/">Contact us</a>' ) );
		const sections = classifyHtml( html );
		expect( sections[ 0 ].kind ).toBe( 'cta' );
		expect( sections[ 0 ].slots.title ).toBe( 'Ready?' );
	} );

	it( 'rebuilds an image gallery', () => {
		const html = SECTION(
			WIDGET( 'image-gallery', '<img src="/wp-content/uploads/1.png" alt="One" /><img src="/wp-content/uploads/2.png" alt="Two" />' )
		);
		const sections = classifyHtml( html );
		expect( sections[ 0 ].kind ).toBe( 'gallery' );
		expect( sections[ 0 ].slots.media ).toHaveLength( 2 );
	} );
} );

describe( 'classifyHtml — wp-block fallback', () => {
	it( 'treats plain blocks as a text section (no builder sections detected)', () => {
		const html = '<h2 class="wp-block-heading">Heading</h2><p class="wp-block-paragraph">Body text.</p>';
		const sections = classifyHtml( html );
		expect( sections ).toHaveLength( 1 );
		expect( sections[ 0 ].kind ).toBe( 'text' );
		expect( sections[ 0 ].html ).toContain( 'Body text.' );
	} );
} );

describe( 'classifyHtml — forms (Proposal 114 §3.9)', () => {
	const FORM = ( inner: string ) => `<form class="elementor-form">${ inner }</form>`;

	it( 'maps a single search field onto the search kind (the /search/ surface)', () => {
		const html = '<p>Find things.</p>' + FORM( '<input type="search" name="s" placeholder="Search…" />' );
		const sections = classifyHtml( html );
		expect( sections.map( ( section ) => section.kind ) ).toEqual( [ 'text', 'form' ] );
		const form = sections[ 1 ];
		expect( form.form?.kind ).toBe( 'search' );
		expect( form.form?.fields[ 0 ] ).toMatchObject( { name: 's', type: 'search', placeholder: 'Search…' } );
	} );

	it( 'detects search by input name, form role, and the per-site searchForms list', () => {
		expect( classifyHtml( FORM( '<input type="text" name="query" />' ) )[ 0 ].form?.kind ).toBe( 'search' );
		expect( classifyHtml( '<form role="search"><input type="text" name="anything" /></form>' )[ 0 ].form?.kind ).toBe( 'search' );
		expect( classifyHtml( FORM( '<input type="text" name="find" />' ), { searchForms: [ 'find' ] } )[ 0 ].form?.kind ).toBe( 'search' );
	} );

	it( 'classifies an Elementor form: hidden form_id carrier, labels, fields, select, submit label', () => {
		const html = SECTION(
			WIDGET( 'heading', '<h2>Contact</h2>' ) +
				WIDGET(
					'form',
					FORM(
						'<input type="hidden" name="form_id" value="abc123" />' +
							'<input type="hidden" name="post_id" value="42" />' +
							'<label for="f-name">Name</label>' +
							'<input type="text" name="form_fields[name]" id="f-name" required="required" />' +
							'<label for="f-msg">Message</label>' +
							'<textarea name="form_fields[msg]" id="f-msg" rows="6"></textarea>' +
							'<select name="form_fields[topic]"><option value="a" selected="selected">Alpha</option></select>' +
							'<button type="submit">Send</button>'
					)
				)
		);
		const sections = classifyHtml( html );
		expect( sections ).toHaveLength( 1 );
		expect( sections[ 0 ].kind ).toBe( 'form' );
		expect( sections[ 0 ].slots.title ).toBe( 'Contact' );
		expect( sections[ 0 ].form ).toMatchObject( { kind: 'submit', handler: 'elementor', formId: 'abc123' } );
		expect( sections[ 0 ].form?.fields ).toHaveLength( 3 );
		expect( sections[ 0 ].form?.fields[ 0 ] ).toMatchObject( { name: 'form_fields[name]', label: 'Name', required: true } );
		expect( sections[ 0 ].form?.fields[ 1 ] ).toMatchObject( { type: 'textarea', rows: '6', label: 'Message' } );
		expect( sections[ 0 ].form?.fields[ 2 ] ).toMatchObject( { type: 'select' } );
		expect( sections[ 0 ].form?.fields[ 2 ]?.options ).toHaveLength( 1 );
		expect( sections[ 0 ].form?.submitLabel ).toBe( 'Send' );
	} );

	it( 'classifies a JetFormBuilder form from its hidden ID carrier', () => {
		const html = FORM(
			'<input type="hidden" name="_jet_engine_booking_form_id" value="7" />' +
				'<label>Email</label>' +
				'<input type="email" name="email" required="required" />'
		);
		const sections = classifyHtml( html );
		expect( sections ).toHaveLength( 1 );
		expect( sections[ 0 ].kind ).toBe( 'form' );
		expect( sections[ 0 ].form ).toMatchObject( { kind: 'submit', handler: 'jetformbuilder', formId: '7' } );
		expect( sections[ 0 ].form?.fields[ 0 ] ).toMatchObject( { type: 'email', name: 'email', label: 'Email', required: true } );
	} );

	it( 'classifies a Contact Form 7 form from its _wpcf7 carrier', () => {
		const html = FORM( '<input type="hidden" name="_wpcf7" value="12" /><input type="text" name="your-name" />' );
		const sections = classifyHtml( html );
		expect( sections[ 0 ].form ).toMatchObject( { kind: 'submit', handler: 'wpcf7', formId: '12' } );
	} );

	it( 'degrades an unidentified form to a cta band with its region links — never a dead endpoint', () => {
		const html =
			'<div><p>Prefer email? <a href="mailto:hi@example.com">Write to us</a></p>' +
			FORM( '<input type="text" name="email" />' ) +
			'</div>';
		const sections = classifyHtml( html );
		expect( sections ).toHaveLength( 1 );
		expect( sections[ 0 ].kind ).toBe( 'form' );
		expect( sections[ 0 ].form?.kind ).toBe( 'cta' );
		expect( sections[ 0 ].slots.links?.[ 0 ] ).toMatchObject( { text: 'Write to us', href: 'mailto:hi@example.com' } );
	} );

	it( 'drops forms with nothing to rebuild (no fields, no id, no region content)', () => {
		expect( classifyHtml( FORM( '' ) ) ).toEqual( [] );
		expect( classifyHtml( FORM( '<input type="text" name="email" />' ) ) ).toEqual( [] );
	} );
} );

describe( 'SECTION_MAP', () => {
	it( 'maps every kind onto a component + label (the data mapping, 114 §3.4)', () => {
		for ( const entry of Object.values( SECTION_MAP ) ) {
			expect( entry.component.length ).toBeGreaterThan( 0 );
			expect( entry.label.length ).toBeGreaterThan( 0 );
		}
		expect( SECTION_MAP.hero.component ).toBe( 'Hero' );
		expect( SECTION_MAP[ 'feature-grid' ].component ).toBe( 'FeatureGrid' );
		expect( SECTION_MAP.text.component ).toBe( 'TextBlock' );
	} );
} );

describe( 'learned vocabulary (Proposal 116)', () => {
	const FUTURE = 'futuristic-thing';

	it( 'captures unknown widget content instead of dropping it', () => {
		const html = SECTION(
			`<div class="elementor-widget elementor-widget-${FUTURE}"><div class="elementor-widget-container"><h2>Next-gen section</h2><p>Some body copy.</p></div></div>`
		);
		const sections = classifyHtml( html );
		expect( sections.length ).toBeGreaterThan( 0 );
		const section = sections[ 0 ];
		expect( section.confidence ).toBe( 'low' ); // shape decided, not a name
		expect( section.slots.title ).toBe( 'Next-gen section' );
		expect( section.slots.body ).toContain( 'Some body copy.' );
	} );

	it( 'profile widgetRoles overrides win over the seeded defaults', () => {
		const html = SECTION(
			[
				`<div class="elementor-widget elementor-widget-${FUTURE}"><div class="elementor-widget-container"><h3>Card one</h3><p>First.</p></div></div>`,
				`<div class="elementor-widget elementor-widget-${FUTURE}"><div class="elementor-widget-container"><h3>Card two</h3><p>Second.</p></div></div>`,
			].join( '' )
		);
		const sections = classifyHtml( html, { widgetRoles: { [ FUTURE ]: { kind: 'feature' } } } );
		const section = sections[ 0 ];
		expect( section.slots.items ).toHaveLength( 2 );
		expect( section.kind ).toBe( 'feature-grid' );
		expect( section.confidence ).toBe( 'high' );
	} );

	it( 'structured Gutenberg blocks enter the section pipeline via the learned gate', () => {
		const html = [
			'<div class="wp-block-columns">',
			'<div class="wp-block-column"><h2 class="wp-block-heading">Column heading</h2><p class="wp-block-paragraph">Body text.</p></div>',
			'</div>',
		].join( '' );
		const sections = classifyHtml( html );
		expect( sections.length ).toBeGreaterThan( 0 );
		expect( sections[ 0 ].slots.title ).toBe( 'Column heading' );
	} );

	it( 'embed-only posts keep the raw post-body path (no content loss)', () => {
		const html = '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper"><iframe src="https://example.com/e"></iframe></div></figure>';
		const sections = classifyHtml( html );
		expect( sections ).toHaveLength( 1 );
		expect( sections[ 0 ].kind ).toBe( 'text' );
	} );

	it( 'unknown blocks classify as content and never empty the document', () => {
		const html = [
			'<div class="wp-block-brand-new-block">',
			'<h2>Shiny new block</h2><p>With some words.</p>',
			'</div>',
		].join( '' );
		const sections = classifyHtml( html );
		expect( sections.length ).toBeGreaterThan( 0 );
		expect( sections[ 0 ].slots.title ).toBe( 'Shiny new block' );
	} );
} );
