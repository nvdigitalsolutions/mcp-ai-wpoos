// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// surface-coverage.test.ts — the gate that asks "did the page PAINT it?".
//
// Every gate before this one checked that a fact was PRESENT. eca.directory's
// about-us and contact-us lost every band image and the scorecard stayed green:
// the fact was emitted, the CSS was invalid, the browser discarded it.
//
// A band image and a panel are never a page default, so they are the two
// signals this gate fails on. A band COLOUR is deliberately not checked — a
// band may legitimately share the page background and paint nothing.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { auditSurfaces, collectSurfaceExpectations, paintedSurface } from '../lib/surface-coverage.ts';
import type { SiteProfile } from '../lib/types.ts';

const profile = ( sections: Array< Record< string, unknown > >, pages: Record< string, unknown > = {} ): SiteProfile =>
	( {
		url: 'https://eca.example',
		design: { sections },
		pageBands: pages,
	} as unknown as SiteProfile );

/** A built band wrapper; `style` is what the renderer wrote inline. */
const band = ( id: string, style = '', classes = 'ds-section-wrap' ): string =>
	`<div data-section-id="${ id }" class="${ classes }" style="${ style }"></div>`;

describe( 'paintedSurface', () => {
	it( 'sees an inline background image', () => {
		const painted = paintedSurface( band( 'aaa', "background-image:url('/media/x.webp')" ), 'aaa' );
		expect( painted?.image ).toBe( true );
	} );

	it( 'sees a LAYER image in a scoped ::before rule', () => {
		const html = `<style>[data-section-id="aaa"]::before{background-image:url('/media/x.webp');opacity:0.2}</style>${ band( 'aaa' ) }`;
		expect( paintedSurface( html, 'aaa' )?.image ).toBe( true );
	} );

	it( 'reads the LAST occurrence — the scoped rules come first', () => {
		const html = `<style>[data-section-id="aaa"]{background-image:url('/x.webp')}</style>${ band( 'aaa' ) }`;
		// The wrapper itself paints nothing; the scoped rule is the layer, which counts.
		expect( paintedSurface( html, 'aaa' )?.image ).toBe( true );
	} );

	it( 'sees a panel class and a panel token', () => {
		expect( paintedSurface( band( 'aaa', '', 'ds-section-wrap ds-section-wrap--panel' ), 'aaa' )?.panel ).toBe( true );
		expect( paintedSurface( band( 'aaa', '--ds-panel-bg:rgb(1,2,3)' ), 'aaa' )?.panel ).toBe( true );
	} );

	it( 'returns undefined for a band the page does not contain', () => {
		expect( paintedSurface( band( 'aaa' ), 'zzz' ) ).toBeUndefined();
	} );
} );

describe( 'collectSurfaceExpectations', () => {
	it( 'collects images and panels, and ignores a band with neither', () => {
		const expectations = collectSurfaceExpectations(
			profile( [
				{ id: 'aaa', bgImage: 'https://x/a.png' },
				{ id: 'bbb', panel: { bg: '#000', ink: '#fff' } },
				{ id: 'ccc', bgColor: '#eee' },
			] )
		);
		expect( expectations.map( ( e ) => e.id ) ).toEqual( [ 'aaa', 'bbb' ] );
	} );

	it( 'covers sub-pages as well as the home', () => {
		const expectations = collectSurfaceExpectations(
			profile( [ { id: 'aaa', bgImage: 'x' } ], { 'about-us': { sections: [ { id: 'bbb', bgImage: 'y' } ] } } )
		);
		expect( expectations.map( ( e ) => `${ e.page }:${ e.id }` ) ).toEqual( [ '/:aaa', 'about-us:bbb' ] );
	} );
} );

describe( 'auditSurfaces', () => {
	it( 'FAILS a declared image the build does not paint', () => {
		const report = auditSurfaces( profile( [ { id: 'aaa', bgImage: 'https://x/a.png' } ] ), () => band( 'aaa' ) );
		expect( report.gaps ).toEqual( [ { page: '/', id: 'aaa', missing: [ 'background-image' ] } ] );
	} );

	it( 'passes when the image is painted', () => {
		const report = auditSurfaces( profile( [ { id: 'aaa', bgImage: 'x' } ] ), () =>
			band( 'aaa', "background-image:url('/media/x.webp')" )
		);
		expect( report.gaps ).toEqual( [] );
	} );

	it( 'reports a page missing from the build rather than failing it', () => {
		const report = auditSurfaces(
			profile( [], { 'about-us': { sections: [ { id: 'bbb', bgImage: 'y' } ] } } ),
			( page ) => ( page === '/' ? '' : undefined )
		);
		expect( report.gaps ).toEqual( [] );
		expect( report.unreadable ).toEqual( [ 'about-us' ] );
	} );
} );
