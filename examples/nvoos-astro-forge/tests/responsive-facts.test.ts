// ---------------------------------------------------------------------------
// responsive-facts.test.ts — device-scoped facts must not flatten.
//
// The flat extractor reads top-level rules only, so a band declared flush on
// desktop and pulled on tablet/mobile came back as "no pull" (or as whichever
// single value an operator then pinned by hand — jayainc's products band).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { analyzeSections } from '../lib/design.ts';

const HTML = `<div class="elementor-section elementor-top-section elementor-element elementor-element-abc123 elementor-section-boxed"><div class="elementor-container"></div></div>`;

const sections = ( css: string ) =>
	analyzeSections( { url: 'https://example.com', html: HTML, css: [ css ] } );

describe( 'device-scoped section facts', () => {
	it( 'captures a pull that differs per device as a map', () => {
		const [ band ] = sections(
			[
				'.elementor-element-abc123{margin-top:0px;padding:100px 0}',
				'@media (max-width:1024px){.elementor-element-abc123{margin-top:170px}}',
				'@media (max-width:767px){.elementor-element-abc123{margin-top:100px}}',
			].join( '\n' )
		);

		expect( band.marginTop ).toEqual( { desktop: '0px', tablet: '170px', mobile: '100px' } );
	} );

	it( 'leaves a single-value pull a plain string', () => {
		const [ band ] = sections(
			[
				'.elementor-element-abc123{margin-top:40px;padding:100px 0}',
				'@media (max-width:1024px){.elementor-element-abc123{color:red}}',
			].join( '\n' )
		);

		expect( band.marginTop ).toBe( '40px' );
	} );

	it( 'ignores a scoped value that repeats the desktop one', () => {
		const [ band ] = sections(
			[
				'.elementor-element-abc123{margin-top:40px;padding:100px 0}',
				'@media (max-width:1024px){.elementor-element-abc123{margin-top:40px}}',
			].join( '\n' )
		);

		expect( band.marginTop ).toBe( '40px' );
	} );

	it( 'captures a bottom pull the same way', () => {
		const [ band ] = sections(
			[
				'.elementor-element-abc123{padding:100px 0}',
				'@media (max-width:1024px){.elementor-element-abc123{margin-bottom:-60px}}',
			].join( '\n' )
		);

		expect( band.marginBottom ).toEqual( { tablet: '-60px' } );
	} );

	it( 'does not mistake another band for this one', () => {
		const [ band ] = sections(
			[
				'.elementor-element-abc123{padding:100px 0}',
				'@media (max-width:1024px){.elementor-element-def456{margin-top:170px}}',
			].join( '\n' )
		);

		expect( band.marginTop ).toBeUndefined();
	} );
} );
