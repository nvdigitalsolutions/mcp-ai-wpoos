// ---------------------------------------------------------------------------
// band-geometry.test.ts — the measured source box drives the rebuilt band.
//
// Band height and vertical rhythm are the two facts a builder source cannot
// express in the markup the rebuild receives: Brando declares them through
// classes (`full-screen`, `padding-four-all`, `no-padding-tb`) whose stylesheet
// never ships, and through an inline `min-height` the sanitizer drops. The
// browser measurement is the only faithful source, so these tests pin how it
// becomes CSS — and that an absent measurement stays absent.
// ---------------------------------------------------------------------------

import { mkdtemp, mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { builtStaticRoot } from '../lib/geometry.ts';
import { renderSectionsGenerated } from '../lib/scaffold.ts';
import type { BandGeometry, ForgeDesign, ForgeGeometry, SiteProfile } from '../lib/types.ts';

const band = ( overrides: Partial< BandGeometry > = {} ): BandGeometry => ( {
	height: 900,
	width: 1440,
	paddingTop: 130,
	paddingBottom: 130,
	paddingLeft: 0,
	paddingRight: 0,
	marginTop: 0,
	marginBottom: 0,
	...overrides,
} );

/** A one-band profile carrying the geometry under test. */
const profileWith = ( geometry?: ForgeGeometry ): SiteProfile =>
	( {
		name: 'vj',
		url: 'https://virtuallyjamaica.test',
		design: {
			sections: [ { id: 'abc1234', anchor: 'about', bgColor: '#ededed' } ],
			bandSource: 'sections',
		} as ForgeDesign,
		...( geometry ? { geometry } : {} ),
	} as unknown as SiteProfile );

const desktop = ( overrides: Partial< BandGeometry > = {} ) => ( {
	desktop: band( overrides ),
	tablet: band( overrides ),
	mobile: band( overrides ),
} );

/** The emitted `sections` array only — the module's interface declares every
 *  optional field, so a negative assertion has to ignore the type block. */
const entriesOf = ( module: string ): string =>
	module.slice( module.indexOf( 'export const sections: SectionTheme[] = [' ) );

describe( 'band padding comes from the measured box', () => {
	it( 'emits a plain shorthand when every viewport agrees', () => {
		const module = renderSectionsGenerated( profileWith( { abc1234: desktop() } ) );
		expect( module ).toContain( "padding: '130px 0px 130px 0px'" );
	} );

	it( 'emits a per-device map when the source tightens below desktop', () => {
		const module = renderSectionsGenerated(
			profileWith( {
				abc1234: {
					desktop: band( { paddingTop: 130, paddingBottom: 130 } ),
					tablet: band( { paddingTop: 80, paddingBottom: 80 } ),
					mobile: band( { paddingTop: 50, paddingBottom: 50 } ),
				},
			} )
		);
		expect( module ).toContain(
			"padding: { desktop: '130px 0px 130px 0px', tablet: '80px 0px 80px 0px', mobile: '50px 0px 50px 0px' }"
		);
	} );

	it( 'records a FLUSH band as 0 rather than dropping the fact', () => {
		// The hero band is `no-padding-tb`: rendering it with the template's own
		// 32px rhythm was a real source mismatch, and an absent value cannot
		// distinguish "measured zero" from "never measured".
		const module = renderSectionsGenerated(
			profileWith( {
				abc1234: {
					desktop: band( { paddingTop: 0, paddingBottom: 0 } ),
					tablet: band( { paddingTop: 0, paddingBottom: 0 } ),
					mobile: band( { paddingTop: 0, paddingBottom: 0 } ),
				},
			} )
		);
		expect( module ).toContain( "padding: '0'" );
	} );

	it( 'carries the horizontal padding the source actually declares', () => {
		// virtuallyjamaica's hardware band is `padding-ten-lr` (144px each side).
		const module = renderSectionsGenerated(
			profileWith( { abc1234: desktop( { paddingLeft: 144, paddingRight: 144 } ) } )
		);
		expect( module ).toContain( "padding: '130px 144px 130px 144px'" );
	} );

	it( 'emits no padding when the profile carries no geometry', () => {
		const module = renderSectionsGenerated( profileWith() );
		expect( entriesOf( module ) ).not.toContain( 'padding:' );
	} );

	it( 'lets an explicit profile fact win over the measurement', () => {
		const profile = profileWith( { abc1234: desktop() } );
		( profile.design as ForgeDesign ).sections = [
			{ id: 'abc1234', anchor: 'about', bgColor: '#ededed', padding: '40px 0' },
		];
		expect( renderSectionsGenerated( profile ) ).toContain( "padding: '40px 0'" );
	} );
} );

describe( 'band height comes from the measured box', () => {
	it( 'emits a plain min-height when every viewport agrees', () => {
		const module = renderSectionsGenerated(
			profileWith( {
				abc1234: {
					desktop: band( { height: 649 } ),
					tablet: band( { height: 649 } ),
					mobile: band( { height: 649 } ),
				},
			} )
		);
		expect( module ).toContain( "minHeight: '649px'" );
	} );

	it( 'emits a per-device map when the band stacks taller below desktop', () => {
		const module = renderSectionsGenerated(
			profileWith( {
				abc1234: {
					desktop: band( { height: 872 } ),
					tablet: band( { height: 1445 } ),
					mobile: band( { height: 1281 } ),
				},
			} )
		);
		expect( module ).toContain(
			"minHeight: { desktop: '872px', tablet: '1445px', mobile: '1281px' }"
		);
	} );

	it( 'ignores a strip too short to be a band', () => {
		// Content decides a 100px logo rail; pinning it would fight the rebuild's
		// own type scale.
		const module = renderSectionsGenerated(
			profileWith( {
				abc1234: {
					desktop: band( { height: 100 } ),
					tablet: band( { height: 100 } ),
					mobile: band( { height: 100 } ),
				},
			} )
		);
		expect( entriesOf( module ) ).not.toContain( 'minHeight' );
	} );

	it( 'emits no height when the profile carries no geometry', () => {
		expect( entriesOf( renderSectionsGenerated( profileWith() ) ) ).not.toContain( 'minHeight' );
	} );

	it( 'mirrors a sub-threshold height when the band has no content at all', () => {
		// jayainc's dff3f1a is a lone Elementor `widget-spacer`: 70px tall, no
		// heading, no body, no media, no control. Its measured height IS its
		// content, so the strip threshold must not swallow it — it rendered 0px
		// and shifted every band below it.
		const profile = profileWith( { abc1234: desktop( { height: 70 } ) } ) as unknown as SiteProfile & {
			bandFacts: unknown;
		};
		profile.bandFacts = { abc1234: { containerWidth: 1140, headings: {}, classes: [] } };

		expect( renderSectionsGenerated( profile ) ).toContain( "minHeight: '70px'" );
	} );

	it( 'keeps the threshold for a short band that does have content', () => {
		// A 70px logo rail still belongs to the rebuild's type scale.
		const profile = profileWith( { abc1234: desktop( { height: 70 } ) } ) as unknown as SiteProfile & {
			bandFacts: unknown;
		};
		profile.bandFacts = {
			abc1234: { containerWidth: 1140, headings: { h6: { size: '13px' } } },
		};

		expect( entriesOf( renderSectionsGenerated( profile ) ) ).not.toContain( 'minHeight' );
	} );

	it( 'treats a band whose only content is a control as content', () => {
		const profile = profileWith( { abc1234: desktop( { height: 70 } ) } ) as unknown as SiteProfile & {
			bandFacts: unknown;
		};
		profile.bandFacts = {
			abc1234: {
				containerWidth: 1140,
				interactions: [ { tag: 'a', classes: [], base: 'color:red' } ],
			},
		};

		expect( entriesOf( renderSectionsGenerated( profile ) ) ).not.toContain( 'minHeight' );
	} );
} );

describe( 'builtStaticRoot picks the tree that actually serves the page', () => {
	it( 'prefers dist/client for a server-output build', async () => {
		const dist = await mkdtemp( join( tmpdir(), 'forge-dist-' ) );
		await mkdir( join( dist, 'client' ) );
		await mkdir( join( dist, 'server' ) );
		await writeFile( join( dist, 'client', 'index.html' ), '<html></html>' );
		await writeFile( join( dist, 'server', 'entry.mjs' ), '// ssr' );
		await expect( builtStaticRoot( dist ) ).resolves.toBe( join( dist, 'client' ) );
	} );

	it( 'falls back to dist for a flat static build', async () => {
		const dist = await mkdtemp( join( tmpdir(), 'forge-dist-' ) );
		await writeFile( join( dist, 'index.html' ), '<html></html>' );
		await expect( builtStaticRoot( dist ) ).resolves.toBe( dist );
	} );
} );
