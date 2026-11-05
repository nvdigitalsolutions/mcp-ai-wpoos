// ---------------------------------------------------------------------------
// responsive.test.ts — Proposal 115 §4: breakpoint extraction (css-tree),
// the 4 → 2 → 1 collapse rule, and explicit Elementor column classes.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { DEFAULT_BREAKPOINTS, explicitColumns, extractBreakpoints, inferColumns } from '../lib/responsive.ts';

describe( 'extractBreakpoints', () => {
	it( 'harvests the canonical Elementor pair and pads the mobile slot', () => {
		const css = [
			'@media (max-width: 767px) { .a { color: red; } }',
			'@media (min-width: 768px) and (max-width: 1025px) { .b { color: blue; } }',
		].join( '\n' );
		expect( extractBreakpoints( css ) ).toEqual( [
			{ label: 'mobile', px: 480 },
			{ label: 'tablet', px: 767 },
			{ label: 'desktop', px: 1025 },
		] );
	} );

	it( 'uses three real widths when the source declares them', () => {
		const css = [
			'@media (max-width: 360px) { }',
			'@media (max-width: 768px) { }',
			'@media (max-width: 1200px) { }',
		].join( '\n' );
		expect( extractBreakpoints( css ) ).toEqual( [
			{ label: 'mobile', px: 360 },
			{ label: 'tablet', px: 768 },
			{ label: 'desktop', px: 1200 },
		] );
	} );

	it( 'falls back to the defaults when the source has no breakpoints', () => {
		expect( extractBreakpoints( '.a { color: red; }' ) ).toEqual( DEFAULT_BREAKPOINTS );
	} );

	it( 'degrades to defaults on unparseable CSS', () => {
		expect( extractBreakpoints( 'this is not css {{{' ) ).toEqual( DEFAULT_BREAKPOINTS );
	} );
} );

describe( 'inferColumns', () => {
	it( 'collapses 8 cells 4 → 2 → 1 (inferred)', () => {
		const { columns, source } = inferColumns( 8 );
		expect( columns ).toEqual( { desktop: 4, tablet: 2, mobile: 1 } );
		expect( source ).toBe( 'inferred' );
	} );

	it( 'shrinks the desktop slot for small grids and floors at 1', () => {
		expect( inferColumns( 2 ).columns.desktop ).toBe( 2 );
		expect( inferColumns( 0 ).columns ).toEqual( { desktop: 1, tablet: 1, mobile: 1 } );
	} );

	it( 'explicit per-breakpoint classes override the rule', () => {
		const { columns, source } = inferColumns( 8, { desktop: 3, tablet: 2, mobile: 2 } );
		expect( columns ).toEqual( { desktop: 3, tablet: 2, mobile: 2 } );
		expect( source ).toBe( 'explicit' );
	} );
} );

describe( 'explicitColumns', () => {
	it( 'reads the elementor col / md / sm classes', () => {
		const html = [
			'<div class="elementor-col-33">',
			'<div class="elementor-md-50">',
			'<div class="elementor-sm-100"></div></div></div>',
		].join( '' );
		expect( explicitColumns( html ) ).toEqual( { desktop: 33, tablet: 50, mobile: 100 } );
	} );

	it( 'returns an empty map without column classes', () => {
		expect( explicitColumns( '<div class="plain"></div>' ) ).toEqual( {} );
	} );
} );
