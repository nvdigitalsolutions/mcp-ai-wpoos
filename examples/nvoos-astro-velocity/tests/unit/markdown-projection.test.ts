// ---------------------------------------------------------------------------
// markdown-projection.test.ts — Portable Text → markdown (Proposal 136 §4.4).
//
// Every allowlisted block type must have an explicit projection: nothing is
// silently dropped (the 107 D3 discipline), and the GFM table/ordered list are
// structural, not text flattens.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { markdownFromPortableText, textFromHtml } from '../../src/lib/llms';

describe( 'markdownFromPortableText', () => {
	it( 'projects standard blocks, marks and links', () => {
		const md = markdownFromPortableText( [
			{ _type: 'block', style: 'h2', children: [ { _type: 'span', text: 'Title', marks: [] } ], markDefs: [] },
			{
				_type: 'block',
				style: 'normal',
				children: [
					{ _type: 'span', text: 'Bold', marks: [ 'strong' ] },
					{ _type: 'span', text: ' and a ', marks: [] },
					{ _type: 'span', text: 'link', marks: [ 'k1' ] },
				],
				markDefs: [ { _key: 'k1', _type: 'link', href: '/x' } ],
			},
		] );

		expect( md ).toContain( '## Title' );
		expect( md ).toContain( '**Bold** and a [link](/x)' );
	} );

	it( 'projects a testimonial as a blockquote with its author', () => {
		const md = markdownFromPortableText( [ { _type: 'testimonial', quote: 'Lovely.', author: 'Ada' } ] );
		expect( md ).toContain( '> Lovely.' );
		expect( md ).toContain( '— Ada' );
	} );

	it( 'projects an faq as a question and answer', () => {
		const md = markdownFromPortableText( [ { _type: 'faq', question: 'Ship?', answer: 'Yes.' } ] );
		expect( md ).toContain( '**Ship?**' );
		expect( md ).toContain( 'Yes.' );
	} );

	it( 'projects a comparison as a GFM table', () => {
		const md = markdownFromPortableText( [
			{
				_type: 'comparison',
				caption: 'Plans',
				columns: [ 'Starter', 'Pro' ],
				rows: [ { label: 'Seats', values: [ '1', '10' ] } ],
			},
		] );

		expect( md ).toContain( '| | Starter | Pro |' );
		expect( md ).toContain( '| --- | --- | --- |' );
		expect( md ).toContain( '| **Seats** | 1 | 10 |' );
	} );

	it( 'escapes a pipe inside a table cell', () => {
		const md = markdownFromPortableText( [
			{ _type: 'comparison', columns: [ 'A', 'B' ], rows: [ { label: 'x|y', values: [ '1', '2' ] } ] },
		] );
		expect( md ).toContain( 'x\\|y' );
	} );

	it( 'projects steps as an ordered list', () => {
		const md = markdownFromPortableText( [
			{ _type: 'steps', items: [ { title: 'Brief' }, { title: 'Build', body: 'We build.' } ] },
		] );
		expect( md ).toContain( '1. **Brief**' );
		expect( md ).toContain( '2. **Build** — We build.' );
	} );

	it( 'projects team members as bullets', () => {
		const md = markdownFromPortableText( [
			{ _type: 'team', people: [ { name: 'Ada', role: 'CEO' }, { name: 'Grace' } ] },
		] );
		expect( md ).toContain( '- **Ada** — CEO' );
		expect( md ).toContain( '- **Grace**' );
	} );

	it( 'recurses into tabs', () => {
		const md = markdownFromPortableText( [
			{
				_type: 'tabs',
				tabs: [
					{
						title: 'Specs',
						blocks: [ { _type: 'block', style: 'normal', children: [ { _type: 'span', text: 'Fast.', marks: [] } ], markDefs: [] } ],
					},
				],
			},
		] );
		expect( md ).toContain( '### Specs' );
		expect( md ).toContain( 'Fast.' );
	} );

	it( 'keeps product grid ids as facts rather than inventing names', () => {
		const md = markdownFromPortableText( [ { _type: 'productGrid', ids: [ 3, 9 ] } ] );
		expect( md ).toContain( '**Featured products:** 3, 9' );
	} );

	it( 'says so rather than dropping an unknown type', () => {
		const md = markdownFromPortableText( [ { _type: 'mystery' } ] );
		expect( md ).toContain( '[unrendered block: mystery]' );
	} );

	it( 'returns a trailing newline and no triple blanks', () => {
		const md = markdownFromPortableText( [
			{ _type: 'block', style: 'normal', children: [ { _type: 'span', text: 'One', marks: [] } ], markDefs: [] },
			{ _type: 'block', style: 'normal', children: [ { _type: 'span', text: 'Two', marks: [] } ], markDefs: [] },
		] );
		expect( md.endsWith( '\n' ) ).toBe( true );
		expect( md ).not.toContain( '\n\n\n' );
	} );
} );

describe( 'textFromHtml — the legacy path', () => {
	it( 'keeps paragraph breaks and strips markup', () => {
		const text = textFromHtml( '<p>One <strong>two</strong></p><p>Three</p>' );
		expect( text ).toContain( 'One two' );
		expect( text ).toContain( 'Three' );
		expect( text ).not.toContain( '<p>' );
		expect( text ).toMatch( /One two\n\nThree/ );
	} );

	it( 'drops scripts and styles', () => {
		const text = textFromHtml( '<p>Keep</p><script>var x = 1;</script><style>.a{}</style>' );
		expect( text ).toContain( 'Keep' );
		expect( text ).not.toContain( 'var x' );
		expect( text ).not.toContain( '.a{' );
	} );

	it( 'decodes the common entities', () => {
		expect( textFromHtml( '<p>A &amp; B &lt;c&gt;&nbsp;d</p>' ) ).toContain( 'A & B <c> d' );
	} );

	it( 'returns an empty string for empty input', () => {
		expect( textFromHtml( '' ) ).toBe( '' );
	} );
} );
