// ---------------------------------------------------------------------------
// llms.test.ts — the deterministic agent surfaces (Proposal 136 §4.4).
//
// The two rules worth pinning: the file's SHAPE is fixed by the llms.txt v2
// proposal (H1, blockquote, sections, Optional last), and every entry points at
// a markdown variant (`…/index.md`). No timestamps — two builds of the same
// content must be byte-identical.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { composeLlmsTxt, markdownFromHtml, markdownFromSource, markdownUrl } from '../lib/llms.ts';

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

describe( 'markdownFromSource', () => {
	it( 'strips the frontmatter fence', () => {
		const md = markdownFromSource( '---\ntitle: Hello\ndraft: false\n---\n\nProse.\n' );
		expect( md ).toBe( 'Prose.\n' );
	} );

	it( 'strips ESM statements, including a multi-line import', () => {
		const md = markdownFromSource(
			[
				"import FaqAccordion from '../../components/blocks/FaqAccordion.astro';",
				'import {',
				'  one,',
				'} from \'./x\';',
				'export const meta = { a: 1 };',
				'',
				'Prose stays.',
			].join( '\n' )
		);

		expect( md ).toContain( 'Prose stays.' );
		expect( md ).not.toContain( 'import' );
		expect( md ).not.toContain( 'export' );
		expect( md ).not.toContain( 'from' );
	} );

	it( 'keeps prose, headings, bullets and inline code', () => {
		const md = markdownFromSource( '## Care\n\n- Point `WP_SITE_URL` at a site.\n- Add `draft: true`.\n' );

		expect( md ).toContain( '## Care' );
		expect( md ).toContain( '- Point `WP_SITE_URL` at a site.' );
		expect( md ).toContain( '- Add `draft: true`.' );
	} );

	it( 'drops a props-only component without eating the prose', () => {
		const md = markdownFromSource(
			'Before <FaqAccordion heading="Shipping" items={[{ question: \'Q\', answer: \'A\' }]} /> after.\n'
		);

		expect( md ).toContain( 'Before' );
		expect( md ).toContain( 'after.' );
		expect( md ).not.toContain( 'FaqAccordion' );
	} );

	it( 'drops a multi-line self-closing component', () => {
		const md = markdownFromSource(
			['Keep me.', '', '<Tabs', '  heading="Care"', '  items={[{ title: \'Wash\' }]}', '/>', '', 'And me.'].join(
				'\n'
			)
		);

		expect( md ).toContain( 'Keep me.' );
		expect( md ).toContain( 'And me.' );
		expect( md ).not.toContain( 'Tabs' );
	} );

	it( 'drops HTML comments and leaves autolinks intact', () => {
		const md = markdownFromSource( '<!-- internal note -->\n\nSee <https://example.com> for details.\n' );

		expect( md ).not.toContain( 'internal note' );
		expect( md ).toContain( '<https://example.com>' );
	} );

	it( 'returns an empty string when the source carries no prose', () => {
		expect( markdownFromSource( '' ) ).toBe( '' );
		expect( markdownFromSource( '   \n\n' ) ).toBe( '' );
		expect( markdownFromSource( '---\ntitle: x\n---\n' ) ).toBe( '' );
		expect( markdownFromSource( "import x from './y';\n" ) ).toBe( '' );
	} );

	it( 'is deterministic', () => {
		const source = '## A\n\nText.\n';
		expect( markdownFromSource( source ) ).toBe( markdownFromSource( source ) );
	} );
} );
