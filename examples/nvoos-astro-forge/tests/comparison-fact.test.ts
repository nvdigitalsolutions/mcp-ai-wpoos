// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// comparison-fact.test.ts — the analyzer-measured COMPARISON MATRIX.
//
// A builder publishes a role/feature matrix as a grid of containers whose value
// cells hold an ICON and no text. The build-time classifier can never see it:
// `sanitizeHtml` strips every `<svg>`, so the check marks are gone before
// classification runs (eca.directory's "Compare User Roles": 52 SVGs raw, 0
// sanitised, classified as a 3-card feature grid).
//
// The fact is therefore recorded at ANALYZE time, against the raw HTML, and as
// TEXT (✓ / ✗) so nothing downstream depends on an icon font.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { analyzeSections, comparisonFactsById } from '../lib/design.ts';

const section = ( inner: string ): string =>
	'<div class="elementor">' +
	'<section class="elementor-section elementor-top-section elementor-element elementor-element-c0a1b2c">' +
	`<div class="elementor-container"><div class="elementor-column elementor-col-100">${ inner }</div></div>` +
	'</section>' +
	'</div>';

/** The source's shape: a heading beside a grid of equal rows of equal cells. */
const MATRIX = section(
	'<div class="elementor-widget-heading"><h5 class="elementor-heading-title">Compare User Roles</h5></div>' +
		'<div class="matrix">' +
		'<div class="row"><div class="cell"></div><div class="cell"><div class="t">Student</div></div><div class="cell"><div class="t">Parent</div></div><div class="cell"><div class="t">Teacher</div></div></div>' +
		'<div class="row"><div class="cell"><div class="t">Live ECA Catalogue</div></div><div class="cell"><svg class="e-font-icon-svg e-fas-check"></svg></div><div class="cell"><svg class="e-font-icon-svg e-fas-check"></svg></div><div class="cell"><svg class="e-font-icon-svg e-fas-check"></svg></div></div>' +
		'<div class="row"><div class="cell"><div class="t">Enrolment &amp; Allocation</div></div><div class="cell"><svg class="e-font-icon-svg e-fas-check"></svg></div><div class="cell"><svg class="e-font-icon-svg e-fas-times"></svg></div><div class="cell"><svg class="e-font-icon-svg e-fas-check"></svg></div></div>' +
		'<div class="row"><div class="cell"><div class="t">Dashboards</div></div><div class="cell"><svg class="e-font-icon-svg e-fas-check"></svg></div><div class="cell"><svg class="e-font-icon-svg e-fas-check"></svg></div><div class="cell"><svg class="e-font-icon-svg e-fas-check"></svg></div></div>' +
		'</div>'
);

describe( 'comparison fact (analyze time)', () => {
	it( 'records columns, rows and the matrix own caption', () => {
		const [ band ] = analyzeSections( { url: 'https://eca.example', html: MATRIX, css: [] } );
		expect( band.comparison ).toEqual( {
			columns: [ 'Student', 'Parent', 'Teacher' ],
			rows: [
				{ label: 'Live ECA Catalogue', values: [ '✓', '✓', '✓' ] },
				{ label: 'Enrolment & Allocation', values: [ '✓', '✗', '✓' ] },
				{ label: 'Dashboards', values: [ '✓', '✓', '✓' ] },
			],
			caption: 'Compare User Roles',
		} );
	} );

	it( 'refuses a tick list — a cell carrying text AND a mark is not a value cell', () => {
		const html = section(
			'<div class="grid">' +
				'<div class="row"><div class="cell"></div><div class="cell">Student</div><div class="cell">Parent</div><div class="cell">Teacher</div></div>' +
				'<div class="row"><div class="cell">Live catalogue</div><div class="cell">Included <svg class="e-fas-check"></svg></div><div class="cell">Included <svg class="e-fas-check"></svg></div><div class="cell">Included <svg class="e-fas-check"></svg></div></div>' +
				'<div class="row"><div class="cell">Dashboards</div><div class="cell">Included <svg class="e-fas-check"></svg></div><div class="cell">Included <svg class="e-fas-check"></svg></div><div class="cell">Included <svg class="e-fas-check"></svg></div></div>' +
				'</div>'
		);
		const [ band ] = analyzeSections( { url: 'https://eca.example', html, css: [] } );
		expect( band?.comparison ).toBeUndefined();
	} );

	it( 'refuses a two-column grid (a spec list is not a matrix)', () => {
		const html = section(
			'<div class="grid">' +
				'<div class="row"><div class="cell"></div><div class="cell">Student</div></div>' +
				'<div class="row"><div class="cell">Catalogue</div><div class="cell"><svg class="e-fas-check"></svg></div></div>' +
				'<div class="row"><div class="cell">Dashboards</div><div class="cell"><svg class="e-fas-check"></svg></div></div>' +
				'</div>'
		);
		const [ band ] = analyzeSections( { url: 'https://eca.example', html, css: [] } );
		expect( band?.comparison ).toBeUndefined();
	} );

	it( 'refuses an ambiguous cell that carries both a check and a cross', () => {
		const html = section(
			'<div class="grid">' +
				'<div class="row"><div class="cell"></div><div class="cell">Student</div><div class="cell">Parent</div><div class="cell">Teacher</div></div>' +
				'<div class="row"><div class="cell">Catalogue</div><div class="cell"><svg class="e-fas-check"></svg><svg class="e-fas-times"></svg></div><div class="cell"><svg class="e-fas-check"></svg></div><div class="cell"><svg class="e-fas-check"></svg></div></div>' +
				'<div class="row"><div class="cell">Dashboards</div><div class="cell"><svg class="e-fas-check"></svg></div><div class="cell"><svg class="e-fas-check"></svg></div><div class="cell"><svg class="e-fas-check"></svg></div></div>' +
				'</div>'
		);
		const [ band ] = analyzeSections( { url: 'https://eca.example', html, css: [] } );
		expect( band?.comparison ).toBeUndefined();
	} );

	it( 'records the fact BY SECTION ID, so the OUTLINE band model sees it too', () => {
		// eca.directory's profile is `bandSource: outline` — the section pass keeps
		// fewer bands than the vendor-agnostic outline, so its section list is
		// discarded and the outline bands carry no comparison of their own.
		const facts = comparisonFactsById( MATRIX );
		expect( facts[ 'c0a1b2c' ]?.rows ).toHaveLength( 3 );
		expect( facts[ 'c0a1b2c' ]?.caption ).toBe( 'Compare User Roles' );
	} );
} );

describe( 'comparison visibility facts', () => {
	// eca.directory's matrix row is `elementor-hidden-mobile`: the source shows a
	// 3-column table on desktop and NOTHING on a phone. The class survives
	// sanitisation, the stylesheet that gave it meaning does not — so the fact is
	// recorded with the structure and the renderer emits the rule.
	const grid = ( classes: string ): string =>
		section(
			`<div class="${ classes }">` +
				'<div class="row"><div class="cell"></div><div class="cell">Student</div><div class="cell">Parent</div><div class="cell">Teacher</div></div>' +
				'<div class="row"><div class="cell">Catalogue</div><div class="cell"><svg class="e-fas-check"></svg></div><div class="cell"><svg class="e-fas-check"></svg></div><div class="cell"><svg class="e-fas-check"></svg></div></div>' +
				'<div class="row"><div class="cell">Dashboards</div><div class="cell"><svg class="e-fas-check"></svg></div><div class="cell"><svg class="e-fas-check"></svg></div><div class="cell"><svg class="e-fas-check"></svg></div></div>' +
				'</div>'
		);

	it( 'records the devices the source hides the matrix at', () => {
		const [ band ] = analyzeSections( {
			url: 'https://eca.example',
			html: grid( 'matrix elementor-hidden-mobile' ),
			css: [],
		} );
		expect( band.comparison?.hiddenAt ).toEqual( [ 'mobile' ] );
		// The same fact rides the generic channel.
		expect( band.structure?.[ 0 ]?.data?.hiddenAt ).toEqual( [ 'mobile' ] );
	} );

	it( 'maps the extra Elementor breakpoints onto the ends the template renders', () => {
		const [ band ] = analyzeSections( {
			url: 'https://eca.example',
			html: grid( 'matrix elementor-hidden-tablet_extra elementor-hidden-mobile_extra' ),
			css: [],
		} );
		expect( band.comparison?.hiddenAt ).toEqual( [ 'tablet', 'mobile' ] );
	} );

	it( 'records no visibility fact for a matrix the source shows everywhere', () => {
		const [ band ] = analyzeSections( { url: 'https://eca.example', html: grid( 'matrix' ), css: [] } );
		expect( band.comparison?.hiddenAt ).toBeUndefined();
	} );
} );
