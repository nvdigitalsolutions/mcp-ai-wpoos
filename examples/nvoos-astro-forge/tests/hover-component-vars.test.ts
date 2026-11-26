// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// hover-component-vars.test.ts — the measured hover repaint reaches the REBUILT
// component.
//
// `bandFacts[*].interactions` records what a source control did on hover, keyed
// to the SOURCE's classes. A design-rebuilt band does not carry those classes,
// so the scoped interaction rules matched nothing: virtuallysrilanka reported 16
// of 28 expected states missing — its buttons never turned black and its four
// wifi tiles never took their dark slab.
//
// The values are real measurements; these pin the mapping onto the components.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { hoverComponentVars } from '../lib/hover-vars.ts';

describe( 'hoverComponentVars — a control that repaints itself', () => {
	it( 'maps the measured button repaint onto the .ds-button variables', () => {
		const vars = hoverComponentVars( [
			{
				transition: '0.3s',
				hover:
					'color:rgb(255, 255, 255);background-color:rgb(0, 0, 0);border-top-color:rgb(255, 255, 255);box-shadow:rgba(0, 0, 0, 0.1) 0px 15px 25px -7px',
			},
		] );
		expect( vars ).toContain( '--ds-btn-bg-hover:rgb(0, 0, 0)' );
		expect( vars ).toContain( '--ds-btn-ink-hover:rgb(255, 255, 255)' );
		expect( vars ).toContain( '--ds-btn-shadow-hover:rgba(0, 0, 0, 0.1) 0px 15px 25px -7px' );
		expect( vars ).toContain( '--ds-btn-transition:0.3s' );
		// The measured fill replaces the generic brightness shift.
		expect( vars ).toContain( '--ds-btn-filter-hover:none' );
	} );

	it( 'takes the last declaration of a property, not the first', () => {
		const vars = hoverComponentVars( [ { hover: 'background-color:rgb(1, 1, 1);background-color:rgb(2, 2, 2)' } ] );
		expect( vars ).toContain( '--ds-btn-bg-hover:rgb(1, 1, 1)' );
	} );
} );

describe( 'hoverComponentVars — a child that repaints the tile', () => {
	it( 'maps a wrapper hover onto the .ds-card variables', () => {
		const vars = hoverComponentVars( [
			{
				children: [
					{
						selector: 'div[class~="elementor-widget-container"]',
						transition: 'background 0.3s, box-shadow 0.3s',
						hover: 'background-color:rgb(0, 0, 0)',
					},
				],
			},
		] );
		expect( vars ).toContain( '--ds-card-bg-hover:rgb(0, 0, 0)' );
		expect( vars ).toContain( '--ds-card-transition:background 0.3s, box-shadow 0.3s' );
		// The parent itself declares no repaint, so the button stays untouched.
		expect( vars.some( ( v ) => v.startsWith( '--ds-btn-bg-hover' ) ) ).toBe( false );
	} );

	it( 'falls back to the active state when only that one paints', () => {
		const vars = hoverComponentVars( [ { children: [ { active: 'background-color:rgb(3, 3, 3)' } ] } ] );
		expect( vars ).toContain( '--ds-card-bg-hover:rgb(3, 3, 3)' );
	} );
} );

describe( 'hoverComponentVars — nothing recorded', () => {
	it( 'returns no variables so the stylesheet fallback still applies', () => {
		expect( hoverComponentVars( undefined ) ).toEqual( [] );
		expect( hoverComponentVars( [] ) ).toEqual( [] );
		expect( hoverComponentVars( [ {} ] ) ).toEqual( [] );
	} );
} );
