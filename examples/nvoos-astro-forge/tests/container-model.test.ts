// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// container-model.test.ts — Elementor's container vocabulary, named once.
//
// Elementor 4.x publishes a band as flex containers (`e-con` / `e-con-full` /
// `e-child`) and each CARD is itself a container; the pre-4.x model is the same
// idea under `.elementor-container` / `.elementor-column`, and a half-migrated
// builder carries both. Three consumers used to re-derive that vocabulary from
// class strings and disagreed — a card row grouped by ROWS (2 items for 5) and
// a comparison matrix's cells read as cards.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { parseDocument } from 'htmlparser2';

import {
	containerHeadingNode,
	containerRows,
	containerShape,
	isCardContainer,
	isContainerNode,
	isHeadingNode,
	isLegacyColumn,
	isLegacyRow,
} from '../lib/semantic.ts';

type Node = {
	type: string;
	name?: string;
	attribs?: Record< string, string >;
	children?: Node[];
};

const parse = ( html: string ): Node => parseDocument( html ) as unknown as Node;

const find = ( node: Node, predicate: ( candidate: Node ) => boolean ): Node | undefined => {
	if ( predicate( node ) ) {
		return node;
	}
	for ( const child of node.children ?? [] ) {
		const found = find( child, predicate );
		if ( found ) {
			return found;
		}
	}
	return undefined;
};

/** The first element node (the document root is not a `tag`). */
const el = ( html: string ): Node =>
	( parse( html ).children ?? [] ).find( ( child ) => child.type === 'tag' ) as Node;

const byClass = ( root: Node, token: string ): Node => {
	const node = find( root, ( candidate ) => ( candidate.attribs?.class ?? '' ).split( /\s+/ ).includes( token ) );
	if ( ! node ) {
		throw new Error( `no .${ token } in fixture` );
	}
	return node;
};

/** A card container exactly as Elementor 4.x publishes one. */
const CARD = ( id: string, title: string, bullet: string ): string =>
	`<div class="elementor-element elementor-element-${ id } e-con-full e-flex e-con e-child">` +
	`<h4 class="elementor-icon-box-title">${ title }</h4><ul><li>${ bullet }</li></ul></div>`;

describe( 'isContainerNode', () => {
	it( 'knows every container token in both models', () => {
		for ( const token of [ 'e-con', 'e-con-inner', 'e-con-full', 'e-con-boxed', 'e-child', 'elementor-column', 'elementor-container' ] ) {
			expect( isContainerNode( el( `<div class="${ token }"></div>` ) ) ).toBe( true );
		}
	} );

	it( 'does not treat a widget or a plain div as a container', () => {
		expect( isContainerNode( el( '<div class="elementor-widget elementor-widget-heading"></div>' ) ) ).toBe( false );
		expect( isContainerNode( el( '<div class="card"></div>' ) ) ).toBe( false );
	} );

	it( 'separates the legacy row and column', () => {
		const row = el( '<div class="elementor-container"></div>' );
		const column = el( '<div class="elementor-column elementor-col-50"></div>' );
		expect( isLegacyRow( row ) ).toBe( true );
		expect( isLegacyRow( column ) ).toBe( false );
		expect( isLegacyColumn( column ) ).toBe( true );
		expect( isLegacyColumn( row ) ).toBe( false );
	} );
} );

describe( 'containerShape', () => {
	it( 'ignores the per-element id so two cards share a shape', () => {
		const one = el( '<div class="elementor-element elementor-element-aaa111 e-con-full e-child"></div>' );
		const two = el( '<div class="elementor-element elementor-element-bbb222 e-con-full e-child"></div>' );
		expect( containerShape( one ) ).toBe( containerShape( two ) );
	} );

	it( 'ignores the legacy width class', () => {
		const half = el( '<div class="elementor-column elementor-col-50"></div>' );
		const third = el( '<div class="elementor-column elementor-col-33"></div>' );
		expect( containerShape( half ) ).toBe( containerShape( third ) );
	} );

	it( 'separates containers that really differ', () => {
		const boxed = el( '<div class="e-con-boxed e-child"></div>' );
		const full = el( '<div class="e-con-full e-child"></div>' );
		expect( containerShape( boxed ) ).not.toBe( containerShape( full ) );
	} );
} );

describe( 'isHeadingNode / containerHeadingNode', () => {
	it( 'accepts a real heading and an Elementor heading element', () => {
		expect( isHeadingNode( el( '<h3>Title</h3>' ) ) ).toBe( true );
		expect( isHeadingNode( el( '<div class="elementor-heading-title">Title</div>' ) ) ).toBe( true );
		expect( isHeadingNode( el( '<div class="elementor-icon-box-title">Title</div>' ) ) ).toBe( true );
		expect( isHeadingNode( el( '<p>Not a heading</p>' ) ) ).toBe( false );
	} );

	it( 'finds a heading nested inside the card', () => {
		const card = el( CARD( 'aaa111', 'Pilot Setup', 'Branding' ) );
		expect( containerHeadingNode( card )?.name ).toBe( 'h4' );
	} );
} );

describe( 'isCardContainer', () => {
	it( 'requires a heading AND a list', () => {
		expect( isCardContainer( el( CARD( 'aaa111', 'Pilot Setup', 'Branding' ) ) ) ).toBe( true );
		// A matrix CELL: a heading and nothing else.
		expect( isCardContainer( el( '<div class="e-con-full e-child"><h3>Student</h3></div>' ) ) ).toBe( false );
		// A list with no heading is not a titled card either.
		expect( isCardContainer( el( '<div class="e-con-full e-child"><ul><li>x</li></ul></div>' ) ) ).toBe( false );
	} );
} );

describe( 'containerRows', () => {
	const band = ( inner: string ): Node =>
		parse(
			`<div class="e-con e-parent"><div class="e-con-inner">` +
				`<div class="e-con-boxed e-child"><h2>Our Updates</h2></div>` +
				inner +
				`</div></div>`
		);

	it( 'groups sibling containers by shape, in document order', () => {
		const root = band( CARD( 'aaa111', 'Phase 1', 'a' ) + CARD( 'bbb222', 'Phase 2', 'b' ) + CARD( 'ccc333', 'Phase 3', 'c' ) );
		const rows = containerRows( root );
		// The intro row is a single child (no group) and the card row groups three.
		expect( rows.map( ( row ) => row.length ) ).toEqual( [ 3 ] );
		expect( rows[ 0 ].map( ( card ) => containerHeadingNode( card ) && ( card.attribs?.class ?? '' ) ) ).toHaveLength( 3 );
	} );

	it( 'never groups different shapes together', () => {
		const root = band(
			`<div class="e-con-full e-child"><h3>A</h3></div>` +
				`<div class="e-con e-child"><h3>B</h3><ul><li>x</li></ul></div>`
		);
		// One full + one boxed: no shape has two members.
		expect( containerRows( root ) ).toEqual( [] );
	} );

	it( 'does not treat the matrix cells as a card row', () => {
		const cells =
			`<div class="e-con-full e-child">` +
			[ 'Live ECA Catalogue', 'Dashboards' ]
				.map(
					( label ) =>
						`<div class="e-con-full e-child"><h4>${ label }</h4></div>` +
						`<div class="e-con-full e-child"><i class="e-fas-check"></i></div>`
				)
				.join( '' ) +
			`</div>`;
		const root = band( CARD( 'aaa111', 'Phase 1', 'a' ) + CARD( 'bbb222', 'Phase 2', 'b' ) + cells );
		const cardRows = containerRows( root ).filter( ( row ) => row.every( isCardContainer ) );
		// The two real cards group; the matrix's cell row is a ROW of containers
		// but not a row of CARDS, so it never becomes one.
		expect( cardRows.map( ( row ) => row.length ) ).toEqual( [ 2 ] );
	} );
} );
