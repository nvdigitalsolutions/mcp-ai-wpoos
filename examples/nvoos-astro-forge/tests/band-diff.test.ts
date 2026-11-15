// ---------------------------------------------------------------------------
// band-diff.test.ts — the "where does the height delta come from" report.
//
// The model is the Layout Instability API's attribution: per-node
// source/built rects, the largest sources capped and ranked (web.dev "Debug
// layout shifts"), plus the causal hints that article gives (an inserted
// subtree shows as a large downward delta; 1–2px is rounding or a font swap).
// These tests pin the matching, the ranking and the hints without a browser.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	alignToSourceRoot,
	buildBandDiffReport,
	boxKey,
	boxSignature,
	classifyDelta,
	diffBoxTrees,
	diffHints,
	hotPath,
	topContributors,
	type BoxNode,
} from '../lib/band-diff.ts';

const node = ( key: string, sig: string, h: number, over: Partial< BoxNode > = {} ): BoxNode => ( {
	key,
	sig,
	tag: sig.split( '.' )[ 0 ],
	depth: 1,
	w: 360,
	h,
	pad: [ 0, 0, 0, 0 ],
	mar: [ 0, 0, 0, 0 ],
	...over,
} );

describe( 'boxSignature', () => {
	it( 'sorts the classes so class order never changes the identity', () => {
		expect( boxSignature( 'DIV', 'row  equal-height ' ) ).toBe( 'div.equal-height.row' );
	} );

	it( 'keeps hash classes (Brando\'s per-module ids are stable across a rebuild)', () => {
		expect( boxSignature( 'div', 'brando_column_1526550966-2-18 wpb_column' ) ).toBe(
			'div.brando_column_1526550966-2-18.wpb_column'
		);
	} );

	it( 'handles an element with no class', () => {
		expect( boxSignature( 'H3', '' ) ).toBe( 'h3' );
	} );
} );

describe( 'boxKey', () => {
	it( 'prefixes the parent key and the sibling ordinal', () => {
		expect( boxKey( 'div.row[0]', 'div.col', 1 ) ).toBe( 'div.row[0]>div.col[1]' );
		expect( boxKey( '', 'div.row', 0 ) ).toBe( 'div.row[0]' );
	} );
} );

describe( 'classifyDelta', () => {
	it( 'calls an inserted subtree an insertion whatever its size', () => {
		expect( classifyDelta( { status: 'added', dH: 40, dW: 0, dPad: 0, dMar: 0 } ) ).toBe( 'subtree inserted' );
		expect( classifyDelta( { status: 'removed', dH: 0, dW: 0, dPad: 0, dMar: 0 } ) ).toBe( 'subtree missing' );
	} );

	it( 'prefers spacing over size when padding moved', () => {
		expect( classifyDelta( { status: 'matched', dH: 44, dW: 0, dPad: 30, dMar: 0 } ) ).toBe( 'spacing (padding/margin)' );
	} );

	it( 'separates a size change from a width-only reflow', () => {
		expect( classifyDelta( { status: 'matched', dH: 20, dW: 10, dPad: 0, dMar: 0 } ) ).toBe( 'size change' );
		expect( classifyDelta( { status: 'matched', dH: 0, dW: 32, dPad: 0, dMar: 0 } ) ).toBe( 'width only (reflow)' );
	} );

	it( 'calls a 1–2px delta sub-pixel rounding, as web.dev does', () => {
		expect( classifyDelta( { status: 'matched', dH: 1, dW: 0, dPad: 0, dMar: 0 } ) ).toBe( 'sub-pixel rounding' );
		expect( classifyDelta( { status: 'matched', dH: 9, dW: 0, dPad: 0, dMar: 0 } ) ).toBe( 'height only' );
	} );
} );

describe( 'diffBoxTrees', () => {
	it( 'matches by structural key and reports only the nodes that moved', () => {
		const source = [ node( 'div.band[0]', 'div.band', 1000 ), node( 'div.band[0]>div.row[0]', 'div.row', 400 ) ];
		const built = [ node( 'div.band[0]', 'div.band', 1015 ), node( 'div.band[0]>div.row[0]', 'div.row', 415 ) ];

		const deltas = diffBoxTrees( source, built );

		expect( deltas ).toHaveLength( 2 );
		expect( deltas[ 0 ] ).toMatchObject( { key: 'div.band[0]', status: 'matched', dH: 15 } );
		expect( deltas[ 1 ] ).toMatchObject( { key: 'div.band[0]>div.row[0]', dH: 15 } );
	} );

	it( 'reports an inserted subtree and a missing one', () => {
		const source = [ node( 'div.band[0]', 'div.band', 1000 ) ];
		const built = [
			node( 'div.band[0]', 'div.band', 1000 ),
			node( 'div.band[0]>div.extra[0]', 'div.extra', 80 ),
		];

		const added = diffBoxTrees( source, built );
		expect( added ).toHaveLength( 1 );
		expect( added[ 0 ] ).toMatchObject( { status: 'added', cause: 'subtree inserted' } );

		const removed = diffBoxTrees( built, source );
		expect( removed ).toHaveLength( 1 );
		expect( removed[ 0 ] ).toMatchObject( { status: 'removed', cause: 'subtree missing' } );
	} );

	it( 'returns nothing when every box agrees', () => {
		const tree = [ node( 'div.band[0]', 'div.band', 900 ) ];
		expect( diffBoxTrees( tree, tree ) ).toEqual( [] );
	} );

	it( 'detects a padding-only change', () => {
		const source = [ node( 'div.band[0]', 'div.band', 100, { pad: [ 0, 0, 0, 0 ] } ) ];
		const built = [ node( 'div.band[0]', 'div.band', 100, { pad: [ 0, 15, 0, 15 ] } ) ];
		const [ delta ] = diffBoxTrees( source, built );
		// A padding change with no height change is still worth reporting.
		expect( delta.dPad ).toBe( 30 );
		expect( delta.dH ).toBe( 0 );
	} );
} );

describe( 'topContributors', () => {
	it( 'ranks by |dH| and caps the list (the Layout Instability top-5 idea)', () => {
		const source = [ node( 'a[0]', 'a', 0 ), node( 'b[0]', 'b', 0 ), node( 'c[0]', 'c', 0 ) ];
		const built = [ node( 'a[0]', 'a', 5 ), node( 'b[0]', 'b', 60 ), node( 'c[0]', 'c', -30 ) ];

		const top = topContributors( diffBoxTrees( source, built ), 2 );

		expect( top ).toHaveLength( 2 );
		expect( top[ 0 ].sig ).toBe( 'b' );
		expect( top[ 1 ].sig ).toBe( 'c' );
	} );

	it( 'never ranks an unchanged matched node', () => {
		const tree = [ node( 'a[0]', 'a', 10 ) ];
		expect( topContributors( diffBoxTrees( tree, tree ) ) ).toEqual( [] );
	} );
} );

describe( 'hotPath', () => {
	it( 'follows the largest child delta at every level', () => {
		const source = [
			node( 'div.band[0]', 'div.band', 100 ),
			node( 'div.band[0]>div.row[0]', 'div.row', 60 ),
			node( 'div.band[0]>div.row[0]>div.col[0]', 'div.col', 30 ),
			node( 'div.band[0]>div.row[0]>div.col[1]', 'div.col', 30 ),
		];
		const built = [
			node( 'div.band[0]', 'div.band', 148 ),
			node( 'div.band[0]>div.row[0]', 'div.row', 108 ),
			node( 'div.band[0]>div.row[0]>div.col[0]', 'div.col', 30 ),
			node( 'div.band[0]>div.row[0]>div.col[1]', 'div.col', 78 ),
		];

		const path = hotPath( diffBoxTrees( source, built ) );

		expect( path.map( ( entry ) => entry.sig ) ).toEqual( [ 'div.band', 'div.row', 'div.col' ] );
		// The second column is the one that grew.
		expect( path[ 2 ].key.endsWith( 'col[1]' ) ).toBe( true );
	} );

	it( 'returns an empty path when nothing differs', () => {
		const tree = [ node( 'a[0]', 'a', 10 ) ];
		expect( hotPath( diffBoxTrees( tree, tree ) ) ).toEqual( [] );
	} );
} );

describe( 'diffHints', () => {
	it( 'explains an inserted subtree', () => {
		const source = [ node( 'a[0]', 'a', 100 ) ];
		const built = [ node( 'a[0]', 'a', 100 ), node( 'a[0]>div.dup[0]', 'div.dup', 60 ) ];
		const hints = diffHints( diffBoxTrees( source, built ), 60 );
		expect( hints.join( ' ' ) ).toContain( 'present in the build but not the source' );
	} );

	it( 'flags spacing and reflow separately', () => {
		const source = [ node( 'a[0]', 'a', 100, { pad: [ 0, 0, 0, 0 ] } ), node( 'b[0]', 'b', 50 ) ];
		const built = [ node( 'a[0]', 'a', 130, { pad: [ 0, 15, 0, 15 ] } ), node( 'b[0]', 'b', 50, { w: 300 } ) ];
		const hints = diffHints( diffBoxTrees( source, built ), 30 ).join( ' ' );
		expect( hints ).toContain( 'Spacing differs' );
		expect( hints ).toContain( 'Width changed' );
	} );

	it( 'says a sub-2px band gap is rounding or a font swap', () => {
		const source = [ node( 'a[0]', 'a', 100 ) ];
		const built = [ node( 'a[0]', 'a', 101 ) ];
		const hints = diffHints( diffBoxTrees( source, built ), 1 ).join( ' ' );
		expect( hints ).toContain( 'rounding or a late web font' );
	} );
} );

describe( 'alignToSourceRoot', () => {
	// The rebuild wraps a raw-HTML band in its own chrome, so the built root is
	// `.ds-section-wrap > .ds-section > <source section>`.
	const source = [
		node( 'section.brando_row_1[0]', 'section.brando_row_1', 1000 ),
		node( 'section.brando_row_1[0]>div.container[0]', 'div.container', 800 ),
		node( 'section.brando_row_1[0]>div.container[0]>div.row[0]', 'div.row', 700 ),
	];
	const built = [
		node( 'div.ds-section-wrap[0]', 'div.ds-section-wrap', 1020, { depth: 0 } ),
		node( 'div.ds-section-wrap[0]>div.ds-section[0]', 'div.ds-section', 1020, { depth: 1 } ),
		node( 'div.ds-section-wrap[0]>div.ds-section[0]>section.brando_row_1[0]', 'section.brando_row_1', 1015, { depth: 2 } ),
		node( 'div.ds-section-wrap[0]>div.ds-section[0]>section.brando_row_1[0]>div.container[0]', 'div.container', 815, { depth: 3 } ),
		node( 'div.ds-section-wrap[0]>div.ds-section[0]>section.brando_row_1[0]>div.container[0]>div.row[0]', 'div.row', 715, { depth: 4 } ),
	];

	it( 're-roots the built subtree onto the source root key and depth', () => {
		const aligned = alignToSourceRoot( source, built );

		expect( aligned ).toBeDefined();
		expect( aligned?.map( ( entry ) => entry.key ) ).toEqual( source.map( ( entry ) => entry.key ) );
		expect( aligned?.map( ( entry ) => entry.depth ) ).toEqual( [ 0, 1, 2 ] );
	} );

	it( 'lets the keyed walk match every node and report the real deltas', () => {
		// Pass the RAW built tree — `buildBandDiffReport` aligns it internally.
		const report = buildBandDiffReport( 'band', 'mobile', source, built );

		// The band element is 1020 built vs 1000 source, but 20 of those px are
		// the rebuild's WRAPPER — the content inside it only moved 15.
		expect( report.heightDelta ).toBe( 20 );
		expect( report.contentDelta ).toBe( 15 );
		expect( report.wrapperDelta ).toBe( 5 );
		expect( report.nodes.every( ( entry ) => entry.status === 'matched' ) ).toBe( true );
		// The chain the geometry gate should point at.
		expect( report.hotPath.map( ( entry ) => entry.sig ) ).toEqual( [
			'section.brando_row_1',
			'div.container',
			'div.row',
		] );
	} );

	it( 'returns undefined when the source root is nowhere in the build', () => {
		expect( alignToSourceRoot( source, [ node( 'div.other[0]', 'div.other', 10 ) ] ) ).toBeUndefined();
	} );

	it( 'says so in the hints when alignment fails', () => {
		const report = buildBandDiffReport( 'band', 'mobile', source, [ node( 'div.other[0]', 'div.other', 10 ) ] );
		expect( report.hints[ 0 ] ).toContain( 'could not be matched' );
	} );
} );

describe( 'topContributors — carrier suppression', () => {
	it( 'suppresses an ancestor whose child already carries its delta', () => {
		// A wrapper that only moved because its content moved is a carrier, not a
		// cause — reporting it buries the real node.
		const source = [ node( 'a[0]', 'a', 0 ), node( 'a[0]>b[0]', 'b', 0 ) ];
		const built = [ node( 'a[0]', 'a', 60 ), node( 'a[0]>b[0]', 'b', 60 ) ];

		const top = topContributors( diffBoxTrees( source, built ) );

		expect( top ).toHaveLength( 1 );
		expect( top[ 0 ].sig ).toBe( 'b' );
	} );

	it( 'keeps an ancestor when its children do NOT explain it', () => {
		const source = [ node( 'a[0]', 'a', 0 ), node( 'a[0]>b[0]', 'b', 0 ) ];
		const built = [ node( 'a[0]', 'a', 100 ), node( 'a[0]>b[0]', 'b', 40 ) ];

		const top = topContributors( diffBoxTrees( source, built ) );

		expect( top.map( ( entry ) => entry.sig ) ).toEqual( [ 'a', 'b' ] );
	} );
} );

describe( 'buildBandDiffReport', () => {
	it( 'assembles the height delta, contributors, hot path and hints', () => {
		const source = [ node( 'div.band[0]', 'div.band', 1525 ), node( 'div.band[0]>div.row[0]', 'div.row', 1400 ) ];
		const built = [ node( 'div.band[0]', 'div.band', 1540 ), node( 'div.band[0]>div.row[0]', 'div.row', 1415 ) ];

		const report = buildBandDiffReport( '860a86e', 'mobile', source, built );

		expect( report ).toMatchObject( {
			bandId: '860a86e',
			viewport: 'mobile',
			sourceHeight: 1525,
			builtHeight: 1540,
			heightDelta: 15,
			contentDelta: 15,
			wrapperDelta: 0,
			truncated: false,
		} );
		// The band's delta is fully carried by its row, so only the row survives.
		expect( report.contributors ).toHaveLength( 1 );
		expect( report.hotPath[ 0 ].sig ).toBe( 'div.band' );
	} );

	it( 'carries the truncation flag through', () => {
		const tree = [ node( 'a[0]', 'a', 10 ) ];
		expect( buildBandDiffReport( 'x', 'desktop', tree, tree, { truncated: true } ).truncated ).toBe( true );
	} );
} );
