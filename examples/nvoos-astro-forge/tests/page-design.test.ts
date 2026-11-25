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

import { mergePageDesign } from '../lib/page-design.ts';
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
} );
