// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// css-lint.test.ts — rules that are declared and do nothing.
//
// `.site-header--brand-center .site-nav-shell { grid-column: 1 }` was written to
// put the disclosure on the left and did nothing: the shell is
// `display: contents`, so it has no box to place. The rule looked correct and
// nothing failed (monicarajiv, 2026-11-20).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { classTokens, describeInertDeclarations, findInertDeclarations, subjectOf } from '../lib/css-lint.ts';

describe( 'subjectOf', () => {
	it( 'returns the last compound of a descendant selector', () => {
		expect( subjectOf( '.a .b' ) ).toBe( '.b' );
	} );

	it( 'returns the last compound across a child combinator', () => {
		expect( subjectOf( '.shell > .toggle' ) ).toBe( '.toggle' );
	} );

	it( 'ignores a space inside an attribute value', () => {
		// `[class~='a b']` contains a space that is NOT a combinator.
		expect( subjectOf( ".shell[class~='x'] > .toggle" ) ).toBe( '.toggle' );
	} );

	it( 'keeps a compound whole', () => {
		expect( subjectOf( '.a.b[data-x] .c' ) ).toBe( '.c' );
	} );
} );

describe( 'classTokens', () => {
	it( 'reads every class in the selector', () => {
		expect( classTokens( '.a.b[data-x] .c' ) ).toEqual( [ 'a', 'b', 'c' ] );
	} );

	it( 'returns nothing for an element-only selector', () => {
		expect( classTokens( 'nav a' ) ).toEqual( [] );
	} );
} );

describe( 'findInertDeclarations', () => {
	const contents = '.shell{display:contents}\n';

	it( 'flags a grid placement whose SUBJECT is box-less', () => {
		// The real defect.
		expect( findInertDeclarations( `${ contents }.brand-center .shell{grid-column:1}` ) ).toEqual( [
			{ selector: '.brand-center .shell', property: 'grid-column', contentsClass: 'shell' },
		] );
	} );

	it( 'does NOT flag a rule that styles a DESCENDANT', () => {
		// `.shell > .toggle { padding }` has a box: it pads the toggle.
		expect( findInertDeclarations( `${ contents }.shell > .toggle{padding:0 .5rem}` ) ).toEqual( [] );
	} );

	it( 'does not flag a rule with no box property', () => {
		expect( findInertDeclarations( `${ contents }.brand-center .shell{color:red}` ) ).toEqual( [] );
	} );

	it( 'accepts `display: contents` with a space', () => {
		expect(
			findInertDeclarations( '.shell{display: contents}\n.brand .shell{grid-row:1}' )
		).toHaveLength( 1 );
	} );

	it( 'ignores a declaration inside a comment', () => {
		expect(
			findInertDeclarations( '/* .shell{display:contents} */\n.brand .shell{grid-row:1}' )
		).toEqual( [] );
	} );

	it( 'walks into a media query rather than skipping it', () => {
		expect(
			findInertDeclarations( '.shell{display:contents}\n@media (min-width:1px){.brand .shell{grid-area:a}}' )
		).toEqual( [ { selector: '.brand .shell', property: 'grid-area', contentsClass: 'shell' } ] );
	} );

	it( 'reports one finding per rule however many box properties it carries', () => {
		expect( findInertDeclarations( `${ contents }.brand .shell{grid-column:1;grid-row:1}` ) ).toHaveLength( 1 );
	} );

	it( 'says nothing when no element is box-less', () => {
		expect( findInertDeclarations( '.brand .shell{grid-column:1}' ) ).toEqual( [] );
	} );
} );

describe( 'describeInertDeclarations', () => {
	it( 'stays quiet when nothing is inert', () => {
		expect( describeInertDeclarations( [] ) ).toBeUndefined();
	} );

	it( 'names the rule, the property and the box-less class', () => {
		const line = describeInertDeclarations( [
			{ selector: '.brand .shell', property: 'grid-column', contentsClass: 'shell' },
		] );
		expect( line ).toContain( '.brand .shell' );
		expect( line ).toContain( 'grid-column' );
		expect( line ).toContain( 'display:contents' );
	} );
} );
