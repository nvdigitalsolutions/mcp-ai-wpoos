// ---------------------------------------------------------------------------
// element-css-structural.test.ts — the CLASS-anchored fallback for a builder
// that re-mints element ids per render.
//
// Flatsome's UX Builder writes per-element rules as `#text-box-<random>`, and
// the id it writes in the page it RENDERS is not the id the REST content
// carries (three different id sets in one session on
// krishandramikasavethe.world, 2026-11-19). The class list is stable, so a
// WIDTH rule is re-anchored to it; anything else is left id-keyed on purpose.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { extractElementCss } from '../lib/element-css.ts';

const page = ( styleBody: string, body: string ): string =>
	`<html><head><style>${ styleBody }</style></head><body>${ body }</body></html>`;

describe( 'extractElementCss structural fallback', () => {
	it( 're-anchors a width rule to the element class list', () => {
		const rules = extractElementCss(
			page(
				'#text-box-7{width:34%}',
				'<div id="text-box-7" class="text-box banner-layer x50 y25"></div>'
			)
		);
		expect( rules.map( ( rule ) => rule.selector ) ).toEqual( [
			'#text-box-7',
			'div.text-box.banner-layer.x50.y25',
		] );
	} );

	it( 'keeps the @media wrapper on the re-anchored rule', () => {
		const rules = extractElementCss(
			page(
				'@media (min-width:850px){#text-box-7{width:34%}}',
				'<div id="text-box-7" class="text-box banner-layer"></div>'
			)
		);
		expect( rules ).toEqual( [
			{ media: '@media (min-width:850px)', selector: '#text-box-7', declarations: 'width:34%' },
			{
				media: '@media (min-width:850px)',
				selector: 'div.text-box.banner-layer',
				declarations: 'width:34%',
			},
		] );
	} );

	it( 'never re-anchors a non-size declaration', () => {
		// `padding-top:100%` loses to `is-full-height` in the source sheet; applied
		// structurally it would blow the band up.
		const rules = extractElementCss(
			page( '#banner-1{padding-top:100%}', '<div id="banner-1" class="banner is-full-height"></div>' )
		);
		expect( rules.map( ( rule ) => rule.selector ) ).toEqual( [ '#banner-1' ] );
	} );

	it( 'never re-anchors onto a shared class set', () => {
		const rules = extractElementCss(
			page(
				'#text-box-7{width:34%}',
				'<div id="text-box-7" class="text-box"></div><div class="text-box"></div>'
			)
		);
		expect( rules.map( ( rule ) => rule.selector ) ).toEqual( [ '#text-box-7' ] );
	} );

	it( 'leaves an unknown id alone', () => {
		const rules = extractElementCss(
			page( '#text-box-9{width:34%}', '<div id="text-box-7" class="text-box"></div>' )
		);
		expect( rules.map( ( rule ) => rule.selector ) ).toEqual( [ '#text-box-9' ] );
	} );
} );
