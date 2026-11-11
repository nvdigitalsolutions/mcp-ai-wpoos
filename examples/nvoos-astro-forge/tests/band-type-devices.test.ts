// ---------------------------------------------------------------------------
// band-type-devices.test.ts — per-device typography facts.
//
// A source scales its type per device: virtuallyjamaica's hero is 55px on
// desktop and 22px on a phone, its section titles 40 → 26, contact 35 → 24.
// Emitting the desktop size at every width made each rebuilt band taller than
// its source at 390px (features 1808 vs 1525, services 1633 vs 1281), which is
// what `verify --geometry` reported. These tests pin the merge and the emitted
// shape.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { attachDeviceOverrides, diffTypeFact } from '../lib/geometry.ts';
import { renderSectionsGenerated } from '../lib/scaffold.ts';
import type { BandTypeFact, SiteProfile } from '../lib/types.ts';

describe( 'diffTypeFact', () => {
	it( 'records only the properties that differ', () => {
		const diff = diffTypeFact(
			{ size: '40px', weight: '700', lineHeight: '45px' },
			{ size: '26px', weight: '700', lineHeight: '28px', align: 'center' }
		);

		expect( diff ).toEqual( { size: '26px', lineHeight: '28px', align: 'center' } );
	} );

	it( 'returns undefined when nothing changed or the device was not measured', () => {
		expect( diffTypeFact( { size: '40px' }, { size: '40px' } ) ).toBeUndefined();
		expect( diffTypeFact( { size: '40px' }, undefined ) ).toBeUndefined();
	} );

	it( 'records a RESET (centre on mobile, left on desktop) rather than dropping it', () => {
		const diff = diffTypeFact( { align: 'start' }, { align: 'center' } );
		expect( diff ).toEqual( { align: 'center' } );
	} );
} );

describe( 'attachDeviceOverrides', () => {
	it( 'attaches tablet and mobile overrides to the heading facts', () => {
		const desktop: { headings: Record< string, BandTypeFact > } = {
			headings: { h1: { size: '55px', lineHeight: '70px' } },
		};

		attachDeviceOverrides(
			desktop,
			{ headings: { h1: { size: '40px', lineHeight: '48px' } } },
			{ headings: { h1: { size: '22px', lineHeight: '22px' } } }
		);

		expect( desktop.headings.h1.tablet ).toEqual( { size: '40px', lineHeight: '48px' } );
		expect( desktop.headings.h1.mobile ).toEqual( { size: '22px', lineHeight: '22px' } );
	} );

	it( 'leaves a heading alone when the source never scales it', () => {
		const desktop: { headings: Record< string, BandTypeFact > } = {
			headings: { h6: { size: '13px', lineHeight: '23px' } },
		};

		attachDeviceOverrides(
			desktop,
			{ headings: { h6: { size: '13px', lineHeight: '23px' } } },
			{ headings: { h6: { size: '13px', lineHeight: '23px' } } }
		);

		expect( desktop.headings.h6.tablet ).toBeUndefined();
		expect( desktop.headings.h6.mobile ).toBeUndefined();
	} );

	it( 'attaches the body paragraph overrides too', () => {
		const desktop: { headings: Record< string, BandTypeFact >; body?: BandTypeFact } = {
			headings: {},
			body: { size: '24px', lineHeight: '28px' },
		};

		attachDeviceOverrides( desktop, { headings: {} }, { headings: {}, body: { size: '18px' } } );

		expect( desktop.body?.mobile ).toEqual( { size: '18px' } );
	} );

	it( 'is a no-op without device measurements', () => {
		const desktop: { headings: Record< string, BandTypeFact > } = {
			headings: { h3: { size: '40px' } },
		};

		attachDeviceOverrides( desktop );

		expect( desktop.headings.h3 ).toEqual( { size: '40px' } );
	} );
} );

describe( 'sections.generated.ts — device overrides', () => {
	const profile = {
		url: 'https://example.com',
		name: 'Fixture',
		collections: [],
		design: { sections: [ { id: 'a1b2c3d', bgColor: '#eeeeee' } ] },
		bandFacts: {
			a1b2c3d: {
				containerWidth: 1140,
				headings: {
					h1: { size: '55px', lineHeight: '70px', mobile: { size: '22px', lineHeight: '22px' } },
				},
				body: { size: '13px', lineHeight: '23px' },
			},
		},
	} as unknown as SiteProfile;

	it( 'nests the overrides inside the heading fact', () => {
		const code = renderSectionsGenerated( profile );

		expect( code ).toContain( "mobile: { size: '22px', lineHeight: '22px' }" );
		expect( code ).toContain( "size: '55px'" );
	} );

	it( 'emits no device key when the source does not scale', () => {
		const code = renderSectionsGenerated( {
			...profile,
			bandFacts: {
				a1b2c3d: {
					containerWidth: 1140,
					headings: { h6: { size: '13px', lineHeight: '23px' } },
				},
			},
		} as unknown as SiteProfile );

		expect( code ).not.toContain( 'mobile: {' );
		expect( code ).not.toContain( 'tablet: {' );
	} );
} );

describe( 'sections.generated.ts — measured ink', () => {
	// A builder writes a per-element colour as `style="color:… !important"` and
	// the sanitizer drops every `style` attribute, so the computed colour is the
	// only surviving copy of the source's ink. Without it the rebuild inherited
	// the template accent — virtuallyjamaica's accordion titles rendered
	// #fe6c61 where the source is #262626.
	const profile = {
		url: 'https://example.com',
		name: 'Fixture',
		collections: [],
		design: { sections: [ { id: 'a1b2c3d', bgColor: '#eeeeee' } ] },
		bandFacts: {
			a1b2c3d: {
				containerWidth: 1140,
				headings: { h4: { size: '13px', color: 'rgb(38, 38, 38)' } },
			},
		},
	} as unknown as SiteProfile;

	it( 'carries the heading colour into the band type facts', () => {
		const code = renderSectionsGenerated( profile );

		expect( code ).toContain( "color: 'rgb(38, 38, 38)'" );
		// `title` is the band's largest heading, so it carries the ink too.
		expect( code ).toContain( "title: { size: '13px', color: 'rgb(38, 38, 38)' }" );
	} );
} );

describe( 'diffTypeFact — ink', () => {
	it( 'records a colour the source changes per device', () => {
		expect( diffTypeFact( { color: 'rgb(38, 38, 38)' }, { color: 'rgb(255, 255, 255)' } ) ).toEqual( {
			color: 'rgb(255, 255, 255)',
		} );
	} );

	it( 'ignores an unchanged colour', () => {
		expect( diffTypeFact( { color: 'rgb(38, 38, 38)' }, { color: 'rgb(38, 38, 38)' } ) ).toBeUndefined();
	} );
} );
