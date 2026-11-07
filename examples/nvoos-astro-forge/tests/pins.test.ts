// ---------------------------------------------------------------------------
// pins.test.ts — the operator-pin guard.
//
// `analyze --write` refreshes the analyzed `design` block wholesale, which is
// how jayainc lost its `5e78df1` marginTop and the catalogue band's bottom
// padding across regenerates. `profile.pins` declares those facts operator-
// owned and `mergeProfileDraft` re-applies them.
//
// These tests hold both halves of that promise: the merge really does re-apply
// pins, and the committed jayainc profile really does satisfy every pin it
// declares.
// ---------------------------------------------------------------------------

import { readFile } from 'node:fs/promises';

import { describe, expect, it } from 'vitest';

import { mergeProfileDraft } from '../lib/discovery.ts';
import { applyDesignPins, findDroppedPins } from '../lib/pins.ts';
import type { ForgeDesign, SiteProfile } from '../lib/types.ts';

/** Minimal design block — the pin code only needs `sections` + `chrome`. */
function designWith( sections: Array< Record< string, unknown > >, extra: Record< string, unknown > = {} ): ForgeDesign {
	return { sections, ...extra } as unknown as ForgeDesign;
}

function sectionAt( design: ForgeDesign | undefined, index: number ): Record< string, unknown > {
	return ( design?.sections as unknown as Array< Record< string, unknown > > )[ index ];
}

describe( 'applyDesignPins (operator-owned design facts)', () => {
	it( 'restores a section fact the fresh design dropped', () => {
		const fresh = designWith( [ { id: '5e78df1', padding: '100px 0px 100px 0px' } ] );

		const pinned = applyDesignPins( fresh, [ { path: 'sections.5e78df1.marginTop', value: '170px' } ] );

		expect( sectionAt( pinned, 0 ).marginTop ).toBe( '170px' );
		expect( sectionAt( pinned, 0 ).padding ).toBe( '100px 0px 100px 0px' );
	} );

	it( 'never mutates the analyzed design', () => {
		const fresh = designWith( [ { id: '5e78df1' } ] );

		applyDesignPins( fresh, [ { path: 'sections.5e78df1.marginTop', value: '170px' } ] );

		expect( sectionAt( fresh, 0 ).marginTop ).toBeUndefined();
	} );

	it( 'resolves section pins by id, not by array position', () => {
		const fresh = designWith( [ { id: 'aaa' }, { id: '5e78df1', marginTop: '0px' } ] );

		const pinned = applyDesignPins( fresh, [ { path: 'sections.5e78df1.marginTop', value: '170px' } ] );

		expect( sectionAt( pinned, 0 ).marginTop ).toBeUndefined();
		expect( sectionAt( pinned, 1 ).marginTop ).toBe( '170px' );
	} );

	it( 'pins chrome facts outside the sections array', () => {
		const fresh = designWith( [], { chrome: { footerInk: 'rgb(0, 0, 0)' } } );

		const pinned = applyDesignPins( fresh, [
			{ path: 'chrome.footerInk', value: 'rgba(255, 255, 255, 0.7)' },
		] );

		expect( ( pinned?.chrome as Record< string, unknown > ).footerInk ).toBe( 'rgba(255, 255, 255, 0.7)' );
	} );

	it( 'skips a pin whose section is gone instead of inventing one', () => {
		const fresh = designWith( [ { id: 'other' } ] );
		const pins = [ { path: 'sections.5e78df1.marginTop', value: '170px' } ];

		expect( () => applyDesignPins( fresh, pins ) ).not.toThrow();
		expect( ( applyDesignPins( fresh, pins )?.sections as unknown[] ).length ).toBe( 1 );
		expect( findDroppedPins( fresh, pins ) ).toHaveLength( 1 );
	} );
} );

describe( 'findDroppedPins (the drift report)', () => {
	it( 'reports nothing when every pin is satisfied', () => {
		const design = designWith( [ { id: '5e78df1', marginTop: '170px' } ] );

		expect( findDroppedPins( design, [ { path: 'sections.5e78df1.marginTop', value: '170px' } ] ) ).toEqual( [] );
	} );

	it( 'reports a pin whose value drifted', () => {
		const design = designWith( [ { id: '5e78df1', marginTop: '0px' } ] );
		const dropped = findDroppedPins( design, [ { path: 'sections.5e78df1.marginTop', value: '170px' } ] );

		expect( dropped.map( ( pin ) => pin.path ) ).toEqual( [ 'sections.5e78df1.marginTop' ] );
	} );

	it( 'compares object pins structurally, ignoring key order', () => {
		const design = designWith( [
			{ id: 's', titleDivider: { width: '125px', height: '6px', background: '#ffcf0e' } },
		] );

		const dropped = findDroppedPins( design, [
			{ path: 'sections.s.titleDivider', value: { background: '#ffcf0e', height: '6px', width: '125px' } },
		] );

		expect( dropped ).toEqual( [] );
	} );

	it( 'treats a missing design block as every pin dropped', () => {
		expect( findDroppedPins( undefined, [ { path: 'chrome.footerInk', value: 'x' } ] ) ).toHaveLength( 1 );
	} );
} );

describe( 'mergeProfileDraft keeps operator pins across a regenerate (118 §3)', () => {
	it( 're-applies pinned section facts over the refreshed design', () => {
		const existing = {
			url: 'https://jayainc.com',
			collections: [ { slug: 'posts', source: 'wp/v2/posts' } ],
			pins: [ { path: 'sections.5e78df1.marginTop', value: '170px' } ],
			design: designWith( [ { id: '5e78df1', marginTop: '170px' } ] ),
		} as unknown as SiteProfile;

		// A regenerate re-extracts the band and reports margin-top: 0px.
		const fresh = {
			url: 'https://jayainc.com',
			collections: [],
			design: designWith( [ { id: '5e78df1', marginTop: '0px' } ] ),
		} as unknown as SiteProfile;

		const merged = mergeProfileDraft( existing, fresh );

		expect( sectionAt( merged.design, 0 ).marginTop ).toBe( '170px' );
		expect( findDroppedPins( merged.design, merged.pins ) ).toEqual( [] );
		// The operator's collections still survive the merge.
		expect( merged.collections ).toHaveLength( 1 );
	} );

	it( 'leaves the design untouched when no pins are declared', () => {
		const existing = { url: 'https://example.com', collections: [], design: designWith( [ { id: 'a' } ] ) } as unknown as SiteProfile;
		const fresh = { url: 'https://example.com', collections: [], design: designWith( [ { id: 'a', marginTop: '0px' } ] ) } as unknown as SiteProfile;

		const merged = mergeProfileDraft( existing, fresh );

		expect( sectionAt( merged.design, 0 ).marginTop ).toBe( '0px' );
	} );
} );

describe( 'sites/jayainc.json satisfies its own pin ledger', () => {
	it( 'carries every declared operator pin (fails when a regenerate drops one)', async () => {
		const raw = await readFile( new URL( '../sites/jayainc.json', import.meta.url ), 'utf8' );
		const profile = JSON.parse( raw ) as SiteProfile;

		expect( profile.pins?.length ?? 0 ).toBeGreaterThan( 0 );
		expect( findDroppedPins( profile.design, profile.pins ).map( ( pin ) => pin.path ) ).toEqual( [] );
	} );
} );
