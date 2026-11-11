// ---------------------------------------------------------------------------
// class-audit.test.ts — the "inert class" report.
//
// The audit exists because four separate hand fixes on virtuallyjamaica
// (text-center, text-uppercase, padding-four-all, the accordion's collapsed
// panel) were all the same root cause: a class the sanitizer kept, that no
// stylesheet reached. These tests pin the matching rules — especially the
// attribute-selector forms, where a naive `.name` scan would cry wolf.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { auditClassCoverage, classSelectorsFromCss } from '../lib/class-audit.ts';

// The template's own wrapper classes are styled in global.css; the fixture CSS
// below names them so a test isolates the SOURCE class it is asking about.
const WRAPPERS = '.design-sections{}.ds-section-wrap{}.post-body{}.ds-section{}';

const page = ( bandClass: string ): string =>
	`<html><head></head><body><div class="design-sections"><div data-section-id="a1b2c3d" data-section="text" class="ds-section-wrap"><div class="post-body ds-section"><section class="${ bandClass }"><p class="text-medium">Hi</p></section></div></div></div></body></html>`;

describe( 'classSelectorsFromCss', () => {
	it( 'reads class names out of selectors, including .post-body .text-medium', () => {
		const { names } = classSelectorsFromCss( '.post-body .text-medium{font-size:14px}' );
		expect( names.has( 'post-body' ) ).toBe( true );
		expect( names.has( 'text-medium' ) ).toBe( true );
	} );

	it( 'reads the attribute-selector forms the shim uses', () => {
		const { attributeMatchers } = classSelectorsFromCss(
			".x img[class*='brando_image_']{display:block}[class~='vc_col-lg-6']{grid-column:span 6}"
		);
		expect( attributeMatchers ).toContainEqual( { operator: '*=', token: 'brando_image_' } );
		expect( attributeMatchers ).toContainEqual( { operator: '~=', token: 'vc_col-lg-6' } );
	} );

	it( 'cannot be fooled by dots or braces inside declarations and strings', () => {
		const css = '.real{content:".fake{";background:url(data:image/svg+xml;base64,PHN2Zz4=)}';
		const { names } = classSelectorsFromCss( css );
		expect( names.has( 'real' ) ).toBe( true );
		expect( names.has( 'fake' ) ).toBe( false );
	} );

	it( 'sees rules nested inside @media blocks', () => {
		const { names } = classSelectorsFromCss( '@media (width>767px){.text-center{text-align:center}}' );
		expect( names.has( 'text-center' ) ).toBe( true );
	} );
} );

describe( 'auditClassCoverage', () => {
	it( 'reports a class no rule reaches, with its band and count', () => {
		const report = auditClassCoverage( page( 'wpb_column' ), `${ WRAPPERS }.post-body .text-medium{font-size:14px}` );

		expect( report.unresolved.map( ( entry ) => entry.name ) ).toEqual( [ 'wpb_column' ] );
		const column = report.unresolved.find( ( entry ) => entry.name === 'wpb_column' );
		expect( column?.bands ).toEqual( [ 'a1b2c3d' ] );
		expect( column?.count ).toBe( 1 );
	} );

	it( 'treats an attribute-selector family as resolved', () => {
		const report = auditClassCoverage(
			page( 'brando_image_1526553791-2-35' ),
			`${ WRAPPERS }.post-body .text-medium{font-size:14px}.ds-section-wrap .post-body img[class*='brando_image_']{display:block}`
		);

		expect( report.unresolved ).toHaveLength( 0 );
		expect( report.used ).toBe( report.resolved );
	} );

	it( 'treats a whole-token attribute match as resolved and a near-miss as not', () => {
		const report = auditClassCoverage(
			page( 'vc_col-lg-6 vc_col-lg-66' ),
			`${ WRAPPERS }.post-body .text-medium{font-size:14px}[class~='vc_col-lg-6']{grid-column:span 6}`
		);

		const names = report.unresolved.map( ( entry ) => entry.name );
		expect( names ).not.toContain( 'vc_col-lg-6' );
		expect( names ).toContain( 'vc_col-lg-66' );
	} );

	it( 'is deterministic: most-used first, then alphabetical', () => {
		const html = `<div class="design-sections"><div data-section-id="a1" class="wrap"><div class="zeta zeta alpha">x</div></div><div data-section-id="b2" class="wrap"><div class="beta zeta">y</div></div></div>`;
		const report = auditClassCoverage( html, '.design-sections{} .wrap{}' );

		expect( report.unresolved.map( ( entry ) => entry.name ) ).toEqual( [ 'zeta', 'alpha', 'beta' ] );
		expect( report.unresolved[ 0 ].bands ).toEqual( [ 'a1', 'b2' ] );
	} );

	it( 'falls back to the whole page when the build has no bands', () => {
		const report = auditClassCoverage( '<div class="lonely">x</div>', '' );

		expect( report.unresolved ).toHaveLength( 1 );
		expect( report.unresolved[ 0 ] ).toMatchObject( { name: 'lonely', count: 1, bands: [ '(page)' ] } );
	} );

	it( "reads the page's own inline <style> blocks (the per-band rules)", () => {
		const html = `<html><head><style>[data-section-id="a1b2c3d"] [class~="collapse"][class~="panel-collapse"]:not([class~="in"]){display:none}</style></head><body><div class="design-sections"><div data-section-id="a1b2c3d" class="wrap"><div class="post-body collapse panel-collapse">y</div></div></div></body></html>`;
		const report = auditClassCoverage( html, '.design-sections{}.wrap{}.post-body{}' );

		expect( report.unresolved.map( ( entry ) => entry.name ) ).not.toContain( 'collapse' );
		expect( report.unresolved.map( ( entry ) => entry.name ) ).not.toContain( 'panel-collapse' );
	} );
} );
