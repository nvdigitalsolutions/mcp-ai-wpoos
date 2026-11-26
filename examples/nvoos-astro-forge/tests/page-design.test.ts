// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// page-design.test.ts — merging a page's design facts onto its measured bands.
//
// The homepage is measured twice (browser + design); sub-pages only got the
// browser half, so their bands carried an id and a column list. This pass
// merges the design facts BY ID. Two rules are load-bearing and pinned here:
//
//  * the browser pass OWNS `id` / `vendorId` / `columns` — the design
//    extraction may never replace a measured lane list;
//  * unmatched work is REPORTED, never guessed (an unknown id is appended and
//    listed; a page section the extractor did not name is listed too).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { analyzeSections } from '../lib/design.ts';
import { laneImageResolver, laneImageUrls, mergePageDesign } from '../lib/page-design.ts';
import type { ForgePageBands, ForgeSection } from '../lib/types.ts';

const entry = (): ForgePageBands =>
	( {
		url: 'https://eca.example/about-us/',
		measuredAt: '2026-11-25T00:00:00.000Z',
		sourceHash: 'x',
		builder: 'elementor',
		sections: [
			{ id: 'aaa1111', vendorId: 'aaa1111', columns: [ { width: 100 }, { width: 100 } ] },
			{ id: 'bbb2222', vendorId: 'bbb2222' },
		],
		geometry: {},
		bandFacts: {},
	} as unknown as ForgePageBands );

const fact = ( id: string, extra: Record< string, unknown > = {} ): ForgeSection =>
	( { id, ...extra } as unknown as ForgeSection );

describe( 'mergePageDesign', () => {
	it( 'adds surfaces onto the measured section', () => {
		const merged = mergePageDesign( entry(), [ fact( 'aaa1111', { bgImage: 'https://x/bg.png' } ) ] );
		const section = merged.entry.sections?.find( ( s ) => s.id === 'aaa1111' );
		expect( section?.bgImage ).toBe( 'https://x/bg.png' );
		expect( merged.matched ).toBe( 1 );
	} );

	it( 'never lets the design pass replace a MEASURED lane list', () => {
		const merged = mergePageDesign( entry(), [
			fact( 'aaa1111', { columns: [ { width: 50 }, { width: 50 } ], bgColor: '#eee' } ),
		] );
		const section = merged.entry.sections?.find( ( s ) => s.id === 'aaa1111' );
		expect( section?.columns ).toEqual( [ { width: 100 }, { width: 100 } ] );
		expect( section?.bgColor ).toBe( '#eee' );
	} );

	it( 'never writes an EMPTY lane list over the measured layout', () => {
		const merged = mergePageDesign( entry(), [ fact( 'aaa1111', { columns: [], bgColor: '#eee' } ) ] );
		const section = merged.entry.sections?.find( ( s ) => s.id === 'aaa1111' );
		expect( section?.columns ).toEqual( [ { width: 100 }, { width: 100 } ] );
	} );

	it( 'drops an empty lane list from a fact for a band with no measured lanes', () => {
		const merged = mergePageDesign( entry(), [ fact( 'bbb2222', { columns: [], bgColor: '#eee' } ) ] );
		const section = merged.entry.sections?.find( ( s ) => s.id === 'bbb2222' );
		expect( section?.columns ).toBeUndefined();
	} );

	it( 'appends a fact for an id the page never listed, and reports it', () => {
		const merged = mergePageDesign( entry(), [ fact( 'ccc3333', { bgImage: 'https://x/p.png' } ) ] );
		expect( merged.added ).toEqual( [ 'ccc3333' ] );
		expect( merged.entry.sections?.map( ( s ) => s.id ) ).toContain( 'ccc3333' );
	} );

	it( 'reports a page section the extractor did not name', () => {
		const merged = mergePageDesign( entry(), [ fact( 'aaa1111', { bgColor: '#eee' } ) ] );
		expect( merged.unnamed ).toEqual( [ 'bbb2222' ] );
	} );

	it( 'does not mutate the input entry', () => {
		const original = entry();
		const before = JSON.stringify( original );
		mergePageDesign( original, [ fact( 'aaa1111', { bgColor: '#eee' } ) ] );
		expect( JSON.stringify( original ) ).toBe( before );
	} );

	it( 'gives a measured PHOTO lane the URL from the page CSS', () => {
		// The browser records `image: true`; the URL only exists in the stylesheet,
		// so without this the lane had nothing to paint and was dropped.
		const withLane = entry();
		( withLane as unknown as { bandFacts: Record< string, unknown > } ).bandFacts = {
			aaa1111: {
				columns: {
					desktop: [
						{ selector: 'elementor-element-5d560df1', image: true },
						{ selector: 'elementor-element-54da4677' },
					],
				},
			},
		};
		const css = '.elementor-element-5d560df1{background-image:url("https://x/lane.png");background-size:cover}';
		const merged = mergePageDesign( withLane, [ fact( 'aaa1111', { bgImage: 'https://x/band.png' } ) ], css );
		const columns = merged.entry.sections?.find( ( s ) => s.id === 'aaa1111' )?.columns;
		expect( columns?.[ 0 ]?.bgImage ).toBe( 'https://x/lane.png' );
		// A lane with no measured photo is left alone.
		expect( columns?.[ 1 ]?.bgImage ).toBeUndefined();
	} );
} );

describe( 'laneImageUrls', () => {
	it( 'indexes the first declaration per element token', () => {
		const urls = laneImageUrls(
			'.a .elementor-element-5d560df1{background-image:url("https://x/a.png")}' +
				'.elementor-element-5d560df1:hover{background-image:url("https://x/b.png")}'
		);
		expect( urls.get( 'elementor-element-5d560df1' ) ).toBe( 'https://x/a.png' );
	} );
} );

describe( 'laneImageResolver', () => {
	// Elementor can put a lane's photo on an absolutely-positioned CHILD, so the
	// lane's own token has no rule. The browser still sees one painted box.
	const nested =
		'<div class="elementor-element elementor-element-5d560df1">' +
		'<div class="e-con-inner">' +
		'<div class="elementor-element elementor-element-1f589703 elementor-absolute"></div>' +
		'</div>' +
		'</div>';

	it( 'resolves a lane photo painted by a descendant', () => {
		const resolve = laneImageResolver(
			nested,
			'.elementor-element-1f589703{background-image:url("https://x/photo.png")}'
		);
		expect( resolve( 'elementor-element-5d560df1' ) ).toBe( 'https://x/photo.png' );
	} );

	it( 'prefers the lane own paint over a descendant', () => {
		const resolve = laneImageResolver(
			nested,
			'.elementor-element-5d560df1{background-image:url("https://x/own.png")}' +
				'.elementor-element-1f589703{background-image:url("https://x/photo.png")}'
		);
		expect( resolve( 'elementor-element-5d560df1' ) ).toBe( 'https://x/own.png' );
	} );

	it( 'makes a relative URL absolute against the page', () => {
		const resolve = laneImageResolver(
			nested,
			'.elementor-element-1f589703{background-image:url("/wp-content/uploads/p.png")}',
			'https://eca.example/about-us/'
		);
		expect( resolve( 'elementor-element-5d560df1' ) ).toBe( 'https://eca.example/wp-content/uploads/p.png' );
	} );

	it( 'leaves a lane with no photo unresolved', () => {
		const resolve = laneImageResolver( nested, '.elementor-element-54da4677{color:red}' );
		expect( resolve( 'elementor-element-54da4677' ) ).toBeUndefined();
	} );

	it( 'fills the measured photo lane through the merge, not just the map', () => {
		const withLane = entry();
		( withLane as unknown as { bandFacts: Record< string, unknown > } ).bandFacts = {
			aaa1111: {
				columns: { desktop: [ { selector: 'elementor-element-5d560df1', image: true } ] },
			},
		};
		const css = '.elementor-element-1f589703{background-image:url("https://x/photo.png")}';
		const merged = mergePageDesign(
			withLane,
			[ fact( 'aaa1111', { bgImage: 'https://x/band.png' } ) ],
			css,
			nested
		);
		const columns = merged.entry.sections?.find( ( s ) => s.id === 'aaa1111' )?.columns;
		expect( columns?.[ 0 ]?.bgImage ).toBe( 'https://x/photo.png' );
	} );
} );

describe( 'analyzeSections includeBare', () => {
	it( 'keeps a band that states no fact when asked', () => {
		const html =
			'<div class="elementor">' +
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-a1b2c3d"><p>bare</p></section>' +
			'</div>';
		expect( analyzeSections( { url: 'https://x', html, css: [] } ) ).toHaveLength( 0 );
		expect( analyzeSections( { url: 'https://x', html, css: [], includeBare: true } ) ).toHaveLength( 1 );
	} );
} );
