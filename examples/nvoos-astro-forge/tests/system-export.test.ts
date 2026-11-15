// ---------------------------------------------------------------------------
// system-export.test.ts — the standalone-bundle core (Proposal 136 §4.3).
//
// scopeCss is the risky piece: Astro scopes by attribute, so a standalone
// stylesheet has to strip `[data-astro-cid-*]` and prefix every selector with
// one root class — WITHOUT splitting `:is(a, b)` or touching keyframe stops.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { composeSystemReadme, scopeCss } from '../lib/system-export.ts';

describe( 'scopeCss', () => {
	it( 'prefixes a rule with the root class', () => {
		expect( scopeCss( '.card{color:red}', 'acme' ) ).toBe( '.acme .card{color:red}' );
	} );

	it( 'splits a selector list on depth-0 commas', () => {
		expect( scopeCss( '.a, .b{color:red}', 'acme' ) ).toBe( '.acme .a, .acme .b{color:red}' );
	} );

	it( 'does not split a comma inside :is()', () => {
		expect( scopeCss( ':is(.a, .b){color:red}', 'acme' ) ).toBe( '.acme :is(.a, .b){color:red}' );
	} );

	it( 'recurses into @media', () => {
		expect( scopeCss( '@media (max-width:767px){.a{color:red}}', 'acme' ) ).toBe(
			'@media (max-width:767px){.acme .a{color:red}}'
		);
	} );

	it( 'recurses into @supports and @container', () => {
		expect( scopeCss( '@supports (display:grid){.a{display:grid}}', 'acme' ) ).toContain(
			'.acme .a{display:grid}'
		);
		expect( scopeCss( '@container (min-width:20rem){.a{color:red}}', 'acme' ) ).toContain(
			'.acme .a{color:red}'
		);
	} );

	it( 'leaves @keyframes stops and names untouched', () => {
		const css = '@keyframes slide{from{transform:none}to{transform:translateX(10px)}}';
		expect( scopeCss( css, 'acme' ) ).toBe( css );
	} );

	it( 'leaves @font-face untouched', () => {
		const css = '@font-face{font-family:"X";src:url(x.woff2)}';
		expect( scopeCss( css, 'acme' ) ).toBe( css );
	} );

	it( 'hoists @import verbatim', () => {
		expect( scopeCss( '@import url("x.css");\n.a{color:red}', 'acme' ) ).toBe(
			'@import url("x.css");\n.acme .a{color:red}'
		);
	} );

	it( 'strips Astro scoping attributes', () => {
		expect( scopeCss( '.a[data-astro-cid-abc123]{color:red}', 'acme' ) ).toBe( '.acme .a{color:red}' );
	} );

	it( 'maps :root and body onto the root class', () => {
		expect( scopeCss( ':root{--x:1px}', 'acme' ) ).toBe( '.acme{--x:1px}' );
		expect( scopeCss( 'body{margin:0}', 'acme' ) ).toBe( '.acme{margin:0}' );
	} );

	it( 'does not break on a brace inside a string', () => {
		expect( scopeCss( '.a::after{content:"}"}', 'acme' ) ).toBe( '.acme .a::after{content:"}"}' );
	} );

	it( 'is deterministic', () => {
		const css = '.a{color:red}@media (x){.b{color:blue}}';
		expect( scopeCss( css, 'acme' ) ).toBe( scopeCss( css, 'acme' ) );
	} );
} );

describe( 'composeSystemReadme', () => {
	const input = {
		name: 'Acme Wares',
		slug: 'acme',
		kinds: [ 'pricing', 'team', 'marquee' ],
		sourceUrl: 'https://example.com',
		analyzedAt: '2026-11-09',
		tokens: [ 'accent', 'surface' ],
	};

	it( 'names the files and the root class', () => {
		const readme = composeSystemReadme( input );
		expect( readme ).toContain( '| `tokens.css` |' );
		expect( readme ).toContain( '`acme.css`' );
		expect( readme ).toContain( '<div class="acme">' );
	} );

	it( 'documents the kinds it was given, sorted', () => {
		const readme = composeSystemReadme( input );
		expect( readme.indexOf( 'marquee' ) ).toBeLessThan( readme.indexOf( 'pricing' ) );
		expect( readme.indexOf( 'pricing' ) ).toBeLessThan( readme.indexOf( 'team' ) );
	} );

	it( 'carries the provenance block', () => {
		const readme = composeSystemReadme( input );
		expect( readme ).toContain( '## Extracted from' );
		expect( readme ).toContain( 'https://example.com' );
		expect( readme ).toContain( 'design LANGUAGE only' );
	} );

	it( 'omits optional sections that have no facts', () => {
		const readme = composeSystemReadme( input );
		expect( readme ).not.toContain( '## Contrast fixes' );
		expect( readme ).not.toContain( '## Motion contract' );
	} );

	it( 'emits the optional sections when facts exist', () => {
		const readme = composeSystemReadme( {
			...input,
			contrastFixes: [ '`muted` #7a7a7a → #555555 (4.29:1 → 7.8:1)' ],
			behaviors: [ { id: '5e78df1', kind: 'parallax' } ],
		} );
		expect( readme ).toContain( '## Contrast fixes' );
		expect( readme ).toContain( '## Motion contract' );
		expect( readme ).toContain( '| `5e78df1` | parallax |' );
	} );
} );
