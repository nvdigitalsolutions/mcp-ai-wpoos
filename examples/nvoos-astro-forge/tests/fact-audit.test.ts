// ---------------------------------------------------------------------------
// fact-audit.test.ts — facts baked into the profile must reach the build.
//
// jayainc's feature grid carried titleStyle/titleDivider/titleAlign and still
// rendered a plain heading: the component was never given the section theme.
// Every existing gate passed, because nothing compared facts to output.
//
// Proposal 136 §4.2 adds the band's KIND to the audited facts: every built band
// must carry a `data-section` value from the closed vocabulary, and a band that
// DECLARES a kind must render that exact kind.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { auditFacts } from '../lib/fact-audit.ts';
import type { ForgeSection } from '../lib/types.ts';

const BUILT = [
	'<div data-section="feature-grid" data-section-id="aaa" style="padding:0px;background:#f6f6f6">',
	'<section class="ds-section ds-body ds-body--center">',
	'<h2 class="ds-title" style="--ds-title-size:43px">Heading</h2>',
	'<span class="ds-title-divider"></span>',
	'</section></div>',
	'<div data-section="text" data-section-id="bbb"><h2>Plain</h2></div>',
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
			'<div data-section="text" data-section-id="aaa"><span class="ds-title-divider"></span></div>',
		].join( '' );

		expect( auditFacts( [ section( { titleDivider: divider() } ) ], html ) ).toEqual( [] );
	} );

	it( 'accepts a device-scoped padding rendered as a scoped <style> rule', () => {
		// A device-scoped map cannot go inline — DesignRenderer emits it as a
		// `[data-section-id]` rule ahead of the band, so the band's own slice has
		// no `padding:`. The fact is still rendered.
		const html = [
			'<style>[data-section-id="aaa"]{padding:125px 0px 125px 0px}' +
				'@media (max-width:1025px){[data-section-id="aaa"]{padding:100px 0px 100px 0px}}</style>',
			'<div data-section="text" data-section-id="aaa"><h2>Heading</h2></div>',
		].join( '' );

		expect(
			auditFacts(
				[ section( { padding: { desktop: '125px 0px 125px 0px', tablet: '100px 0px 100px 0px' } } ) ],
				html
			)
		).toEqual( [] );
	} );

	it( 'still reports a scoped padding that emits no scoped rule', () => {
		// The map is declared but the build rendered nothing for it.
		const html = '' +
			'<div data-section="text" data-section-id="aaa"><h2>Heading</h2></div>';

		const unrendered = auditFacts(
			[ section( { padding: { desktop: '125px 0px' } } ) ],
			html
		);

		expect( unrendered.map( ( item ) => item.fact ) ).toEqual( [ 'padding' ] );
	} );

	it( 'does not let an unrelated inline padding mask a missing scoped rule', () => {
		// A nested element carries `padding:` but the band's map emitted no
		// scoped rule — the fact is still unrendered.
		const html = '' +
			'<div data-section="text" data-section-id="aaa"><span style="padding:4px">x</span></div>';

		const unrendered = auditFacts(
			[ section( { padding: { desktop: '125px 0px', tablet: '100px 0px' } } ) ],
			html
		);

		expect( unrendered.map( ( item ) => item.fact ) ).toEqual( [ 'padding' ] );
	} );

	it( 'only inspects a band\'s own slice of the HTML', () => {
		// bbb renders a divider that aaa does not — aaa must still be reported.
		const html = [
			'<div data-section="text" data-section-id="aaa"><h2>Plain</h2></div>',
			'<div data-section="text" data-section-id="bbb"><span class="ds-title-divider"></span></div>',
		].join( '' );

		expect( auditFacts( [ section( { titleDivider: divider() } ) ], html ) ).toHaveLength( 1 );
	} );

	// -- Proposal 136 §4.2: the band's kind ------------------------------------

	it( 'accepts the declared kind when the band renders it', () => {
		const html = '<div data-section="pricing" data-section-id="aaa"><h2>Plans</h2></div>';
		expect( auditFacts( [ section( { kind: 'pricing' } ) ], html ) ).toEqual( [] );
	} );

	it( 'flags a kind mismatch between the profile and the build', () => {
		// The profile says pricing; the renderer emitted a feature grid.
		const html = '<div data-section="feature-grid" data-section-id="aaa"><h2>Plans</h2></div>';
		const unrendered = auditFacts( [ section( { kind: 'pricing' } ) ], html );

		expect( unrendered.map( ( item ) => item.fact ) ).toEqual( [ 'section kind' ] );
	} );

	it( 'flags an out-of-vocabulary kind', () => {
		const html = '<div data-section="sales-pitch" data-section-id="aaa"><h2>Plans</h2></div>';
		const unrendered = auditFacts( [ section( {} ) ], html );

		expect( unrendered ).toHaveLength( 1 );
		expect( unrendered[ 0 ].fact ).toContain( 'out of vocabulary' );
	} );

	it( 'flags a band that emits no kind at all', () => {
		const html = '<div data-section-id="aaa"><h2>Plans</h2></div>';
		const unrendered = auditFacts( [ section( {} ) ], html );

		expect( unrendered ).toEqual( [
			{ sectionId: 'aaa', fact: 'section kind', marker: 'data-section=' },
		] );
	} );
} );
