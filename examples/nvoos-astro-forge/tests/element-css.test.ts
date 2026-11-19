// ---------------------------------------------------------------------------
// element-css.test.ts — recovering a builder's PER-ELEMENT <style> rules.
//
// Flatsome/UX Builder writes each element's own layout in a `<style>` block
// (`#gap-x{padding-top:30px}`, `#text-box-x{width:20%}`). The sanitizer drops
// `<style>`, so without this pass the rebuild re-derives the box from class
// conventions and loses it — gaps collapsed, a chevron rendered 1×1, a button
// row jammed to the left edge. These tests pin both halves: what is recovered,
// and what the safety gate refuses.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { extractElementCss, renderElementCss } from '../lib/element-css.ts';

const page = ( styleBody: string ): string =>
	`<html><head><style>${ styleBody }</style></head><body>
		<section class="section"><div id="col-1" class="col"><div id="text-box-2" class="text-box"></div></div></section>
		<div id="logo"></div><div id="main"></div><div id="wrapper"></div>
	</body></html>`;

describe( 'extractElementCss', () => {
	it( 'recovers an element-family id rule', () => {
		const rules = extractElementCss( page( '#gap-9 { padding-top: 30px }' ) );
		expect( rules ).toEqual( [ { selector: '#gap-9', declarations: 'padding-top:30px' } ] );
	} );

	it( 'keeps a descendant selector inside the element family', () => {
		const rules = extractElementCss( page( '#col-1 > .col-inner { margin: 180px 0 0 }' ) );
		expect( rules[ 0 ].selector ).toBe( '#col-1 > .col-inner' );
	} );

	it( 'keeps an @media wrapper so the device value survives', () => {
		const rules = extractElementCss(
			page( '@media (min-width:550px){#section_7{padding-top:100px}}' )
		);
		expect( rules ).toEqual( [
			{
				media: '@media (min-width:550px)',
				selector: '#section_7',
				declarations: 'padding-top:100px',
			},
		] );
	} );

	it( 'ignores CHROME ids — the rebuild owns its header and footer', () => {
		const rules = extractElementCss(
			page( '#logo img{max-height:248px}#main{background:#000}#wrapper{width:100%}' )
		);
		expect( rules ).toEqual( [] );
	} );

	it( 'ignores ids outside the element families', () => {
		const rules = extractElementCss( page( '#random-id{width:10px}' ) );
		expect( rules ).toEqual( [] );
	} );

	it( 'drops an unsafe value instead of the whole rule', () => {
		const rules = extractElementCss(
			page( '#gap-9{background-image:url(https://x/y.png);padding-top:30px}' )
		);
		expect( rules[ 0 ].declarations ).toBe( 'padding-top:30px' );
	} );

	it( 'drops javascript: and expression() values', () => {
		const rules = extractElementCss(
			page( '#gap-9{width:expression(alert(1));height:1px;background:javascript:x}' )
		);
		expect( rules[ 0 ].declarations ).toBe( 'height:1px' );
	} );

	it( 'strips !important — the rebuild out-ranks by scope, not by force', () => {
		const rules = extractElementCss( page( '#gap-9{padding-top:30px!important}' ) );
		expect( rules[ 0 ].declarations ).toBe( 'padding-top:30px' );
	} );

	it( 'refuses properties that are not layout, type or paint', () => {
		const rules = extractElementCss(
			page( '#gap-9{content:"x";animation:spin 1s;padding-top:30px}' )
		);
		expect( rules[ 0 ].declarations ).toBe( 'padding-top:30px' );
	} );

	it( 'drops the whole rule when nothing survives the gate', () => {
		const rules = extractElementCss( page( '#gap-9{animation:spin 1s}' ) );
		expect( rules ).toEqual( [] );
	} );

	it( 'keeps only the element part of a selector list', () => {
		const rules = extractElementCss(
			page( '#text-box-2, #logo, .other{width:20%}' )
		);
		expect( rules[ 0 ].selector ).toBe( '#text-box-2' );
	} );

	it( 'deduplicates a rule a page repeats verbatim', () => {
		const rules = extractElementCss(
			page( '#gap-9{padding-top:30px}#gap-9{padding-top:30px}' )
		);
		expect( rules ).toHaveLength( 1 );
	} );

	it( 'returns nothing for a page with no inline style', () => {
		expect( extractElementCss( '<html><body><div id="gap-9"></div></body></html>' ) ).toEqual( [] );
	} );
} );

describe( 'renderElementCss', () => {
	it( 'scopes every selector to a rebuilt band', () => {
		const css = renderElementCss( [ { selector: '#gap-9', declarations: 'padding-top:30px' } ] );
		expect( css ).toContain( '.ds-section-wrap #gap-9{padding-top:30px}' );
	} );

	it( 'scopes both halves of a selector list', () => {
		const css = renderElementCss( [ { selector: '#a-1, #b-2', declarations: 'width:10px' } ] );
		expect( css ).toContain( '.ds-section-wrap #a-1,.ds-section-wrap #b-2{width:10px}' );
	} );

	it( 'scopes inside an @media block', () => {
		const css = renderElementCss( [
			{ media: '@media (min-width: 550px)', selector: '#gap-9', declarations: 'padding-top:10px' },
		] );
		expect( css ).toContain( '@media (min-width: 550px){.ds-section-wrap #gap-9{padding-top:10px}}' );
	} );

	it( 'emits a comment-only file when nothing was recovered', () => {
		const css = renderElementCss( undefined );
		expect( css ).toContain( 'No builder element rules' );
		expect( css ).not.toContain( '{' );
	} );
} );
