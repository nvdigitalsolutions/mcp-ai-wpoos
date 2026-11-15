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

describe( 'sections.generated.ts — title level', () => {
	// The source's heading LEVEL is a content fact. virtuallyjamaica's contact
	// band carries a single `<h3>`; the component used to render every band
	// title as `<h2>`, so the rebuilt outline differed from the source.
	const profile = {
		url: 'https://example.com',
		name: 'Fixture',
		collections: [],
		design: { sections: [ { id: 'eef1693', bgImage: 'https://example.com/bg.jpg' } ] },
		bandFacts: {
			eef1693: {
				containerWidth: 1140,
				headings: { h3: { size: '35px', lineHeight: '40px' } },
			},
		},
	} as unknown as SiteProfile;

	it( 'carries the tag of the largest heading as titleLevel', () => {
		const code = renderSectionsGenerated( profile );

		expect( code ).toContain( "titleLevel: 'h3'" );
	} );

	it( 'picks the level of the LARGEST heading, not the first one emitted', () => {
		const code = renderSectionsGenerated( {
			...profile,
			bandFacts: {
				eef1693: {
					containerWidth: 1140,
					headings: { h6: { size: '13px' }, h2: { size: '43px' } },
				},
			},
		} as unknown as SiteProfile );

		expect( code ).toContain( "titleLevel: 'h2'" );
		// `title` is the largest heading's typography; they must agree.
		expect( code ).toContain( "title: { size: '43px' }, titleLevel: 'h2'" );
	} );
} );

describe( 'sections.generated.ts — full-viewport bands', () => {
	// A band measured at exactly the viewport height at every capture breakpoint
	// is a full-screen stage. The captured px is that device's viewport, so it
	// must not be re-emitted as a fixed floor: `100vh` + the `fullViewport` flag
	// let the renderer restore the height chain the vendor sheet supplied.
	const bandGeometry = ( height: number ) => ( {
		desktop: { height, width: 1440, paddingTop: 0, paddingBottom: 0, paddingLeft: 0, paddingRight: 0, marginTop: 0, marginBottom: 0 },
		tablet: { height, width: 834, paddingTop: 0, paddingBottom: 0, paddingLeft: 0, paddingRight: 0, marginTop: 0, marginBottom: 0 },
		mobile: { height, width: 390, paddingTop: 0, paddingBottom: 0, paddingLeft: 0, paddingRight: 0, marginTop: 0, marginBottom: 0 },
	} );
	const profile = ( desktop: number, tablet: number, mobile: number ) =>
		( {
			url: 'https://example.com',
			name: 'Fixture',
			collections: [],
			design: { sections: [ { id: '45f5e88', bgColor: '#dd3333' } ] },
			geometry: {
				'45f5e88': {
					desktop: { ...bandGeometry( desktop ).desktop },
					tablet: { ...bandGeometry( tablet ).tablet },
					mobile: { ...bandGeometry( mobile ).mobile },
				},
			},
		} ) as unknown as SiteProfile;

	it( 'emits 100vh when the band matches every capture viewport', () => {
		const code = renderSectionsGenerated( profile( 900, 1112, 844 ) );

		expect( code ).toContain( 'fullViewport: true' );
		expect( code ).toContain( "minHeight: '100vh'" );
	} );

	it( 'keeps the measured pixels for a tall (non-full-screen) band', () => {
		const code = renderSectionsGenerated( profile( 1800, 2200, 1600 ) );

		expect( code ).not.toContain( 'fullViewport: true' );
		expect( code ).toContain( "desktop: '1800px'" );
	} );

	it( 'an operator pin can force the flag back off', () => {
		const base = profile( 900, 1112, 844 );
		const code = renderSectionsGenerated( {
			...base,
			design: { sections: [ { id: '45f5e88', bgColor: '#dd3333', fullViewport: false } ] },
		} as unknown as SiteProfile );

		expect( code ).not.toContain( 'fullViewport: true' );
		expect( code ).toContain( "desktop: '900px'" );
	} );

	it( 'carries the source\u2019s centring device: true, measured false, and absent', () => {
		const base = profile( 900, 1112, 844 );
		const withFact = ( value: boolean | undefined ) =>
			renderSectionsGenerated( {
				...base,
				bandFacts: { '45f5e88': { containerWidth: 1140, centeredContent: value } },
			} as unknown as SiteProfile );

		expect( withFact( true ) ).toContain( 'centered: true' );
		expect( withFact( false ) ).toContain( 'centered: false' );
		// Absent stays absent: an older profile keeps the template default.
		expect( withFact( undefined ) ).not.toContain( 'centered:' );
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
