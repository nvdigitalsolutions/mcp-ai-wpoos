// ---------------------------------------------------------------------------
// llms.test.ts — the deterministic agent surfaces (Proposal 136 §4.4).
//
// The two rules worth pinning: the file's SHAPE is fixed by the llms.txt v2
// proposal (H1, blockquote, sections, Optional last), and every entry points at
// a markdown variant (`…/index.md`). No timestamps — two builds of the same
// content must be byte-identical.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { composeLlmsTxt, markdownFromHtml, markdownUrl } from '../lib/llms.ts';

const base = {
	name: 'Fixture Wares',
	summary: 'Hardware for coastal workshops.',
	origin: 'https://example.com',
};

describe( 'markdownUrl', () => {
	it( 'points at the index.md variant of a path', () => {
		expect( markdownUrl( 'https://example.com', '/about/' ) ).toBe( 'https://example.com/about/index.md' );
		expect( markdownUrl( 'https://example.com/', '/' ) ).toBe( 'https://example.com/index.md' );
	} );
} );

describe( 'composeLlmsTxt', () => {
	it( 'emits the H1 first and the blockquote second', () => {
		const text = composeLlmsTxt( { ...base } );
		const lines = text.split( '\n' ).filter( Boolean );

		expect( lines[ 0 ] ).toBe( '# Fixture Wares' );
		expect( lines[ 1 ] ).toBe( '> Hardware for coastal workshops.' );
	} );

	it( 'links every entry to its markdown variant', () => {
		const text = composeLlmsTxt( {
			...base,
			pages: [ { path: '/about/', title: 'About' } ],
			posts: [ { path: '/posts/1/hello/', title: 'Hello' } ],
		} );

		expect( text ).toContain( '- [About](https://example.com/about/index.md)' );
		expect( text ).toContain( '- [Hello](https://example.com/posts/1/hello/index.md)' );
	} );

	it( 'sorts entries by path so the file is stable', () => {
		const text = composeLlmsTxt( {
			...base,
			pages: [
				{ path: '/zulu/', title: 'Zulu' },
				{ path: '/alpha/', title: 'Alpha' },
			],
		} );

		expect( text.indexOf( 'Alpha' ) ).toBeLessThan( text.indexOf( 'Zulu' ) );
	} );

	it( 'omits empty sections and puts Optional last', () => {
		const text = composeLlmsTxt( {
			...base,
			pages: [ { path: '/about/', title: 'About' } ],
			optional: [ { path: '/legal/', title: 'Legal' } ],
		} );

		expect( text ).not.toContain( '## Products' );
		expect( text ).not.toContain( '## Posts' );
		expect( text.indexOf( '## Pages' ) ).toBeLessThan( text.indexOf( '## Optional' ) );
	} );

	it( 'clips a long note at a word boundary', () => {
		const text = composeLlmsTxt( {
			...base,
			pages: [ { path: '/about/', title: 'About', note: 'x '.repeat( 120 ) } ],
		} );
		const line = text.split( '\n' ).find( ( candidate ) => candidate.includes( '[About]' ) ) ?? '';

		expect( line.length ).toBeLessThan( 200 );
		expect( line.endsWith( '…' ) ).toBe( true );
	} );

	it( 'carries no timestamps', () => {
		const text = composeLlmsTxt( { ...base, pages: [ { path: '/about/', title: 'About' } ] } );
		expect( text ).not.toMatch( /\d{4}-\d{2}-\d{2}T/ );
	} );

	it( 'is deterministic across two identical calls', () => {
		const input = { ...base, pages: [ { path: '/about/', title: 'About' } ] };
		expect( composeLlmsTxt( input ) ).toBe( composeLlmsTxt( input ) );
	} );
} );

describe( 'markdownFromHtml', () => {
	it( 'projects headings, paragraphs, links and images', () => {
		const md = markdownFromHtml(
			'<h2>Title</h2><p>Some <strong>bold</strong> text and a <a href="/x">link</a>.</p><img src="/i.png" alt="Alt" />'
		);

		expect( md ).toContain( '## Title' );
		expect( md ).toContain( '**bold**' );
		expect( md ).toContain( '[link](/x)' );
		expect( md ).toContain( '![Alt](/i.png)' );
	} );

	it( 'projects a table as GFM', () => {
		const md = markdownFromHtml(
			'<table><thead><tr><th>Seat</th><th>Price</th></tr></thead><tbody><tr><td>One</td><td>$9</td></tr></tbody></table>'
		);

		expect( md ).toContain( '| Seat | Price |' );
		expect( md ).toContain( '| --- | --- |' );
		expect( md ).toContain( '| One | $9 |' );
	} );

	it( 'drops scripts and styles', () => {
		const md = markdownFromHtml( '<p>Keep</p><script>var secret = 1;</script><style>.a{}</style>' );

		expect( md ).toContain( 'Keep' );
		expect( md ).not.toContain( 'secret' );
		expect( md ).not.toContain( '.a' );
	} );

	it( 'reads a native accordion as a question and answer', () => {
		const md = markdownFromHtml(
			'<details name="faq"><summary>Do you ship?</summary><p>Yes.</p></details>'
		);

		expect( md ).toContain( '**Do you ship?**' );
		expect( md ).toContain( 'Yes.' );
	} );

	it( 'returns an empty string for empty input', () => {
		expect( markdownFromHtml( '' ) ).toBe( '' );
	} );
} );
