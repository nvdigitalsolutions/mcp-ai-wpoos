// ---------------------------------------------------------------------------
// fact-audit.test.ts — facts baked into the profile must reach the build.
//
// jayainc's feature grid carried titleStyle/titleDivider/titleAlign and still
// rendered a plain heading: the component was never given the section theme.
// Every existing gate passed, because nothing compared facts to output.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { auditFacts } from '../lib/fact-audit.ts';
import type { ForgeSection } from '../lib/types.ts';

const BUILT = [
	'<div data-section-id="aaa" style="padding:0px;background:#f6f6f6">',
	'<section class="ds-section ds-body ds-body--center">',
	'<h2 class="ds-title" style="--ds-title-size:43px">Heading</h2>',
	'<span class="ds-title-divider"></span>',
	'</section></div>',
	'<div data-section-id="bbb"><h2>Plain</h2></div>',
].join( '' );

/** The source divider fact (all three fields are required on the wire type). */
const divider = (): NonNullable< ForgeSection[ 'titleDivider' ] > => ( {
	width: '125px',
	height: '6px',
	background: '#ffcf0e',
} );

const section = ( overrides: Partial< ForgeSection > ): ForgeSection =>
	( { id: 'aaa', ...overrides } as ForgeSection );

describe( 'auditFacts', () => {
	it( 'passes when every declared fact leaves its marker', () => {
		const unrendered = auditFacts(
			[
				section( {
					bgColor: '#f6f6f6',
					padding: '0px 0px 100px 0px',
					titleAlign: 'center',
					titleStyle: { size: '43px' },
					titleDivider: divider(),
				} ),
			],
			BUILT
		);

		expect( unrendered ).toEqual( [] );
	} );

	it( 'reports each fact a band declares but does not render', () => {
		const unrendered = auditFacts(
			[ section( { titleDivider: divider(), titleStyle: { size: '43px' }, titleAlign: 'center' } ) ],
			BUILT.replace( /<span class="ds-title-divider"><\/span>/, '' )
				.replace( ' ds-body--center', '' )
				.replace( ' style="--ds-title-size:43px"', '' )
		);

		expect( unrendered.map( ( item ) => item.fact ).sort() ).toEqual( [
			'titleAlign: center',
			'titleDivider',
			'titleStyle',
		] );
	} );

	it( 'audits only the facts a band actually declares', () => {
		// No titleAlign/titleStyle/divider — only the padding it does declare.
		expect( auditFacts( [ section( { padding: '10px 0' } ) ], BUILT ) ).toEqual( [] );
	} );

	it( 'skips a band that is absent from the build (the band-flow gate owns that)', () => {
		expect( auditFacts( [ section( { id: 'zzz', titleDivider: divider() } ) ], BUILT ) ).toEqual( [] );
	} );

	it( 'ignores a responsive <style> block that names the section id', () => {
		// The responsive-margin CSS is `[data-section-id="aaa"]{…}` — a bare
		// attribute search would slice that stylesheet instead of the element.
		const html = [
			'<style>[data-section-id="aaa"]{margin-top:0px}</style>',
			'<div data-section-id="aaa"><span class="ds-title-divider"></span></div>',
		].join( '' );

		expect( auditFacts( [ section( { titleDivider: divider() } ) ], html ) ).toEqual( [] );
	} );

	it( 'only inspects a band\'s own slice of the HTML', () => {
		// bbb renders a divider that aaa does not — aaa must still be reported.
		const html = [
			'<div data-section-id="aaa"><h2>Plain</h2></div>',
			'<div data-section-id="bbb"><span class="ds-title-divider"></span></div>',
		].join( '' );

		expect( auditFacts( [ section( { titleDivider: divider() } ) ], html ) ).toHaveLength( 1 );
	} );
} );
