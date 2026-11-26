// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// region-colors.test.ts — parsing, CIEDE2000, and the region verdicts.
//
// The perceptual formula is pinned to Sharma/Wu/Dalal's published CIEDE2000
// test data: a regression in the maths is otherwise invisible (ΔE values still
// "look plausible").
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	applyBandSurfaces,
	applyChromeSurfaces,
	bandRegionSelector,
	builtBandSelectors,
	colorDeltaE,
	compareRegionColorModes,
	compareRegionColors,
	cssIdSelector,
	DEFAULT_COLOR_REGIONS,
	deltaE2000,
	GATED_INK_SHARE,
	GRADIENT_SURFACE_RE,
	idSelectors,
	inkMass,
	normalizeColorModes,
	normalizeCssColor,
	parseCssColor,
	srgbToLab,
	summarizeInk,
	unresolvedChromeRegions,
} from '../lib/region-colors.ts';
import type { ForgeSection, RegionColors } from '../lib/types.ts';

/** The single-scheme regions shape the per-mode comparer works on. */
type ForgeColorBaseline = Record< string, RegionColors >;

describe( 'parseCssColor', () => {
	it( 'parses short and long hex', () => {
		expect( parseCssColor( '#fff' ) ).toEqual( { r: 255, g: 255, b: 255, a: 1 } );
		expect( parseCssColor( '#2a2a2a' ) ).toEqual( { r: 42, g: 42, b: 42, a: 1 } );
	} );

	it( 'parses hex with alpha', () => {
		const parsed = parseCssColor( '#ff000080' );
		expect( parsed?.r ).toBe( 255 );
		expect( parsed?.a ).toBeCloseTo( 128 / 255, 5 );
	} );

	it( 'parses rgb()/rgba() in comma and space syntax', () => {
		expect( parseCssColor( 'rgb(254, 108, 97)' ) ).toEqual( { r: 254, g: 108, b: 97, a: 1 } );
		expect( parseCssColor( 'rgba(0 0 0 / 0.5)' ) ).toEqual( { r: 0, g: 0, b: 0, a: 0.5 } );
	} );

	it( 'treats transparent as zero alpha', () => {
		expect( parseCssColor( 'transparent' ) ).toEqual( { r: 0, g: 0, b: 0, a: 0 } );
	} );

	it( 'returns undefined for unparseable values', () => {
		expect( parseCssColor( 'linear-gradient(red, blue)' ) ).toBeUndefined();
	} );
} );

describe( 'normalizeCssColor', () => {
	it( 'canonicalises to #rrggbb', () => {
		expect( normalizeCssColor( 'rgb(254, 108, 97)' ) ).toBe( '#fe6c61' );
		expect( normalizeCssColor( '#FFF' ) ).toBe( '#ffffff' );
	} );

	it( 'collapses near-transparent to transparent', () => {
		expect( normalizeCssColor( 'rgba(0,0,0,0)' ) ).toBe( 'transparent' );
		expect( normalizeCssColor( 'rgba(255,255,255,0.2)' ) ).toBe( 'transparent' );
	} );

	it( 'keeps mostly-opaque surfaces as their colour', () => {
		expect( normalizeCssColor( 'rgba(255,255,255,0.9)' ) ).toBe( '#ffffff' );
	} );
} );

describe( 'CIEDE2000', () => {
	// Sharma, Wu & Dalal (2005) test data — pairs 1 and 2.
	it( 'matches the published reference pairs', () => {
		expect( deltaE2000( [ 50, 2.6772, -79.7751 ], [ 50, 0, -82.7485 ] ) ).toBeCloseTo( 2.0425, 4 );
		expect( deltaE2000( [ 50, 3.1571, -77.2803 ], [ 50, 0, -82.7485 ] ) ).toBeCloseTo( 2.8615, 4 );
	} );

	it( 'is 0 for identical colours', () => {
		expect( deltaE2000( [ 50, 10, -20 ], [ 50, 10, -20 ] ) ).toBe( 0 );
	} );

	it( 'maps sRGB endpoints to the expected L* range', () => {
		const white = srgbToLab( 255, 255, 255 );
		const black = srgbToLab( 0, 0, 0 );
		expect( white[ 0 ] ).toBeCloseTo( 100, 1 );
		expect( black[ 0 ] ).toBeCloseTo( 0, 3 );
		expect( colorDeltaE( '#ffffff', '#000000' )! ).toBeGreaterThan( 50 );
	} );

	it( 'refuses to compare transparent surfaces', () => {
		expect( colorDeltaE( 'transparent', '#ffffff' ) ).toBeUndefined();
	} );
} );

describe( 'compareRegionColors', () => {
	const source: ForgeColorBaseline = {
		body: { background: '#ffffff', text: '#2a2a2a' },
		header: { background: '#ffffff', text: '#2a2a2a' },
		footer: { background: '#ededed', text: '#2a2a2a' },
	};

	it( 'passes identical measurements', () => {
		const report = compareRegionColors( source, source );
		expect( report.deviations ).toEqual( [] );
		expect( report.compared ).toBe( 6 );
	} );

	it( 'fails a clearly different surface colour', () => {
		const built: ForgeColorBaseline = {
			...source,
			footer: { background: '#000000', text: '#2a2a2a' },
		};
		const report = compareRegionColors( source, built );

		expect( report.deviations ).toHaveLength( 1 );
		expect( report.deviations[ 0 ] ).toMatchObject( {
			region: 'footer',
			metric: 'background',
			severity: 'fail',
		} );
	} );

	it( 'honours custom thresholds', () => {
		const built: ForgeColorBaseline = {
			...source,
			body: { background: '#000000', text: '#2a2a2a' },
		};
		expect( compareRegionColors( source, built, { warnAt: 1, failAt: 200 } ).deviations[ 0 ].severity ).toBe( 'warn' );
	} );

	it( 'reports a region the build dropped', () => {
		const built: ForgeColorBaseline = { body: source.body, footer: source.footer };
		const report = compareRegionColors( source, built );

		expect( report.missing ).toEqual( [ 'header' ] );
	} );

	it( 'skips transparent surfaces instead of guessing the backdrop', () => {
		const built: ForgeColorBaseline = {
			...source,
			header: { background: 'transparent', text: '#2a2a2a' },
		};
		const report = compareRegionColors( source, built );

		expect( report.skipped ).toEqual( [ 'light.header.background' ] );
		expect( report.deviations ).toEqual( [] );
	} );
} );

describe( 'ink measurement', () => {
	it( 'weights a run of text by its rendered glyph area', () => {
		// Glyph area grows with the square of the size: 10 characters at 72px paint
		// more than 100 at 12px.
		expect( inkMass( 10, 72 ) ).toBeGreaterThan( inkMass( 100, 12 ) );
	} );

	it( 'picks the ink that carries the most text, not the most elements', () => {
		// The regression this metric exists for, using the masses measured on
		// virtuallyjamaica's `#services` (2026-11-12): a white lede + heading
		// (~202k) over four grey list items (~72k). Counting ELEMENTS made the four
		// grey `li`s beat the one white paragraph.
		const inks = summarizeInk( [
			{ color: 'rgb(255, 255, 255)', mass: 201786 },
			{ color: 'rgb(146, 146, 146)', mass: 71994 },
		] );

		expect( inks[ 0 ] ).toEqual( { color: '#ffffff', share: 0.74 } );
		expect( inks[ 1 ] ).toEqual( { color: '#929292', share: 0.26 } );
	} );

	it( 'merges a translucent copy of the dominant ink instead of splitting the vote', () => {
		// `rgba(255,255,255,0.7)` over a photo is white copy, not a second colour.
		const inks = summarizeInk( [
			{ color: 'rgba(255, 255, 255, 0.7)', mass: 46844 },
			{ color: 'rgb(255, 255, 255)', mass: 12250 },
		] );

		expect( inks ).toEqual( [ { color: '#ffffff', share: 1 } ] );
	} );

	it( 'drops invisible text and keeps the palette bounded', () => {
		const inks = summarizeInk( [
			{ color: 'transparent', mass: 900000 },
			{ color: 'rgb(0, 0, 0)', mass: 100 },
			{ color: 'rgb(20, 20, 20)', mass: 90 },
			{ color: 'rgb(40, 40, 40)', mass: 80 },
			{ color: 'rgb(60, 60, 60)', mass: 70 },
			{ color: 'rgb(80, 80, 80)', mass: 1 },
		] );

		expect( inks ).toHaveLength( 4 );
		expect( inks[ 0 ].color ).toBe( '#000000' );
		// `#505050` carries 0.3% — decoration, never gated.
		expect( inks.some( ( ink ) => ink.color === '#505050' ) ).toBe( false );
	} );

	it( 'reports no palette for a region that renders no text', () => {
		expect( summarizeInk( [] ) ).toEqual( [] );
	} );
} );

describe( 'compareRegionColors with an ink palette', () => {
	const source: ForgeColorBaseline = {
		band: {
			background: '#ededed',
			text: '#ffffff',
			inks: [
				{ color: '#ffffff', share: 0.74 },
				{ color: '#929292', share: 0.26 },
			],
		},
	};

	it( 'passes when every significant ink survives', () => {
		const report = compareRegionColors( source, source );
		expect( report.deviations ).toEqual( [] );
		expect( report.compared ).toBe( 3 );
	} );

	it( 'fails a minority ink the build repainted', () => {
		// The dominant white is intact and the 26% grey list items turned white:
		// a single-colour baseline scored this a pass.
		const built: ForgeColorBaseline = {
			band: { background: '#ededed', text: '#ffffff', inks: [ { color: '#ffffff', share: 1 } ] },
		};
		const report = compareRegionColors( source, built );

		expect( report.deviations ).toHaveLength( 1 );
		expect( report.deviations[ 0 ] ).toMatchObject( {
			region: 'band',
			metric: 'text',
			expected: '#929292',
			actual: '#ffffff',
			share: 0.26,
			severity: 'fail',
		} );
	} );

	it( 'does not gate an ink below the share threshold', () => {
		// A caption is decoration: repainting it is typographic drift, not a
		// colour-system regression.
		const withCaption: ForgeColorBaseline = {
			band: {
				background: '#ededed',
				text: '#ffffff',
				inks: [
					{ color: '#ffffff', share: 0.88 },
					{ color: '#929292', share: 0.12 },
				],
			},
		};
		const built: ForgeColorBaseline = {
			band: { background: '#ededed', text: '#ffffff', inks: [ { color: '#ffffff', share: 1 } ] },
		};
		const report = compareRegionColors( withCaption, built );

		expect( report.deviations ).toEqual( [] );
		expect( report.compared ).toBe( 2 );
	} );

	it( 'falls back to the single `text` colour for a baseline recorded before palettes', () => {
		const legacy: ForgeColorBaseline = { band: { background: '#ededed', text: '#626262' } };
		const report = compareRegionColors( legacy, { band: { background: '#ededed', text: '#626262' } } );

		expect( report.deviations ).toEqual( [] );
		expect( report.compared ).toBe( 2 );
	} );

	it( 'gates at least one ink for every region with text', () => {
		// The dominant ink is always ≥ 1/4 of a bounded palette, so a region can
		// never slip through ungated.
		expect( GATED_INK_SHARE ).toBeLessThanOrEqual( 0.25 );
	} );
} );

describe( 'region selector ladder', () => {
	it( 'puts the bare `footer` tag last so a post-meta footer cannot win', () => {
		// WordPress themes emit `footer.entry-meta` inside the loop; with the tag
		// first it matched an EMPTY element and jayainc's real
		// `footer#sitefooter` was never measured.
		const footer = DEFAULT_COLOR_REGIONS.find( ( region ) => region.name === 'footer' );
		const ladder = footer?.selectors ?? [];

		expect( ladder[ ladder.length - 1 ] ).toBe( 'footer' );
		expect( ladder.indexOf( '.site-footer' ) ).toBeLessThan( ladder.length - 1 );
		expect( ladder ).toContain( 'footer:not(.entry-meta):not(.post-meta)' );
		expect( ladder ).toContain( '#sitefooter' );
	} );
} );

describe( 'unresolvedChromeRegions', () => {
	it( 'names a ladder that matched nothing, an empty element, and an ink-less one', () => {
		// jayainc's `footer` ladder matched WordPress's empty `footer.entry-meta`:
		// found, boxed, no text — every metric on it skipped, which read as a pass.
		const resolved = {
			body: { region: 'body', found: true, width: 1440, height: 8000, inkSamples: 40 },
			header: { region: 'header', found: true, width: 1440, height: 98, inkSamples: 9 },
			footer: { region: 'footer', found: true, selector: 'footer', width: 1440, height: 24, inkSamples: 0 },
		};
		expect( unresolvedChromeRegions( resolved ) ).toEqual( [ { region: 'footer', reason: 'no-ink' } ] );

		// A missing region is reported, never silently dropped.
		expect(
			unresolvedChromeRegions( {
				body: { region: 'body', found: true, width: 1440, height: 8000, inkSamples: 40 },
				header: { region: 'header', found: true, width: 1440, height: 0, inkSamples: 0 },
			} )
		).toEqual( [
			{ region: 'header', reason: 'empty' },
			{ region: 'footer', reason: 'missing' },
		] );
	} );

	it( 'says nothing about a fully resolved chrome', () => {
		const resolutions = Object.fromEntries(
			[ 'body', 'header', 'footer' ].map( ( region ) => [
				region,
				{ region, found: true, width: 1440, height: 100, inkSamples: 3 },
			] )
		);
		expect( unresolvedChromeRegions( resolutions ) ).toEqual( [] );
	} );
} );

describe( 'band surfaces (gradient / photo / flat)', () => {
	const gradient =
		'linear-gradient(rgb(4, 19, 43) 0%, rgb(8, 26, 67) 100%)';

	it( 'classifies the gradient forms the browser emits', () => {
		expect( GRADIENT_SURFACE_RE.test( gradient ) ).toBe( true );
		expect( GRADIENT_SURFACE_RE.test( 'radial-gradient(circle, #fff, #000)' ) ).toBe( true );
		expect( GRADIENT_SURFACE_RE.test( 'conic-gradient(#fff, #000)' ) ).toBe( true );
		expect( GRADIENT_SURFACE_RE.test( 'repeating-linear-gradient(45deg, #fff 0 10px, #000 10px 20px)' ) ).toBe( true );
		expect( GRADIENT_SURFACE_RE.test( 'url("https://example.com/hero.jpg")' ) ).toBe( false );
		expect( GRADIENT_SURFACE_RE.test( '#dd3333' ) ).toBe( false );
	} );

	it( 'writes a measured gradient into bgGradient, not bgColor', () => {
		const sections: ForgeSection[] = [ { id: 'a1', anchor: 'hero' } ];
		applyBandSurfaces( sections, () => ( { background: '#04132b', text: '#ffffff', image: gradient } ) );

		expect( sections[ 0 ].bgGradient ).toBe( gradient );
		expect( sections[ 0 ].bgColor ).toBeUndefined();
	} );

	it( 'writes a measured flat fill into bgColor', () => {
		const sections: ForgeSection[] = [ { id: 'a1' } ];
		applyBandSurfaces( sections, () => ( { background: '#dd3333', text: '#ffffff' } ) );

		expect( sections[ 0 ].bgColor ).toBe( '#dd3333' );
		expect( sections[ 0 ].bgGradient ).toBeUndefined();
	} );

	it( 'captures a photo url without wiring it (bgImage means hero)', () => {
		const sections: ForgeSection[] = [ { id: 'a1' } ];
		const photo = 'url("https://example.com/hero.jpg")';
		applyBandSurfaces( sections, () => ( { background: '#000000', text: '#ffffff', image: photo } ) );

		expect( sections[ 0 ].bgImage ).toBeUndefined();
		expect( sections[ 0 ].bgGradient ).toBeUndefined();
		expect( sections[ 0 ].bgColor ).toBe( '#000000' );
	} );

	it( 'never overwrites an operator-pinned surface', () => {
		const pinned: ForgeSection = { id: 'a1', bgColor: '#dd3333' };
		applyBandSurfaces(
			[ pinned ],
			() => ( { background: '#000000', text: '#ffffff', image: gradient } ),
			undefined,
			{ preserve: () => true }
		);

		expect( pinned.bgColor ).toBe( '#dd3333' );
		expect( pinned.bgGradient ).toBeUndefined();
	} );

	// The harvest reads DECLARATIONS and can read a rule for a child layer or a
	// variant the page never renders (eca.directory's magenta header, 2026-11-23).
	it( 'a measured surface beats a harvested one', () => {
		const section: ForgeSection = { id: 'a1', bgColor: '#d30c5c' };
		applyBandSurfaces( [ section ], () => ( { background: 'transparent', text: '#1a1e1d' } ) );
		expect( section.bgColor ).toBeUndefined();
	} );

	it( 'clears a harvested photo when the browser paints no image', () => {
		const section: ForgeSection = { id: 'a1', bgImage: 'https://example.com/bg-01.png' };
		applyBandSurfaces( [ section ], () => ( { background: 'transparent', text: '#1a1e1d' } ) );
		expect( section.bgImage ).toBeUndefined();
	} );

	it( 'leaves a band the pass could not measure untouched', () => {
		const section: ForgeSection = { id: 'a1', bgColor: '#dd3333' };
		applyBandSurfaces( [ section ], () => undefined );
		expect( section.bgColor ).toBe( '#dd3333' );
	} );

	it( 'skips a transparency reading (the body surface showing through)', () => {
		const sections: ForgeSection[] = [ { id: 'a1' } ];
		applyBandSurfaces( sections, () => ( { background: 'transparent', text: '#ffffff' } ) );
		expect( sections[ 0 ].bgColor ).toBeUndefined();
	} );

	it( 'treats a fill identical to the page body as transparent', () => {
		const sections: ForgeSection[] = [ { id: 'a1' } ];
		applyBandSurfaces( sections, () => ( { background: '#ffffff', text: '#000000' } ), '#ffffff' );
		expect( sections[ 0 ].bgColor ).toBeUndefined();
	} );

	it( 'leaves a band with no measured region untouched', () => {
		const sections: ForgeSection[] = [ { id: 'a1' } ];
		applyBandSurfaces( sections, () => undefined );
		expect( sections[ 0 ] ).toEqual( { id: 'a1' } );
	} );
} );

describe( 'chrome surfaces (measurement beats the stylesheet harvest)', () => {
	const measured = ( header: RegionColors, footer: RegionColors, body?: RegionColors ) => ( {
		light: {
			...( body ? { body } : {} ),
			header,
			footer,
		},
	} );

	it( 'replaces a harvested header/footer surface and ink with the measured ones', () => {
		// eca.directory: the harvest read `#d30c5c` for both bars (a customizer
		// colour nothing paints) and the link accent for the footer ink.
		const chrome = {
			headerBg: '#d30c5c',
			headerInk: '#8764a0',
			footerBarBg: '#d30c5c',
			footerInk: '#27104a',
			footerBarInk: '#8764a0',
		};
		const applied = applyChromeSurfaces(
			chrome,
			measured(
				{ background: '#f7f7f7', text: '#27104a', inks: [ { color: '#27104a', share: 0.59 }, { color: '#ffffff', share: 0.41 } ] },
				{ background: '#f7f7f7', text: '#747474', inks: [ { color: '#747474', share: 0.62 }, { color: '#27104a', share: 0.38 } ] }
			)
		);

		expect( chrome ).toEqual( {
			headerBg: '#f7f7f7',
			headerInk: '#27104a',
			footerBarBg: '#f7f7f7',
			footerInk: '#747474',
			footerBarInk: '#747474',
		} );
		expect( applied ).toContain( 'chrome.headerBg' );
		expect( applied ).toContain( 'chrome.footerBarInk' );
	} );

	it( 'gives a transparent header the BODY surface (the strip shows the page)', () => {
		const chrome: Record< string, string > = { headerBg: '#d30c5c' };
		applyChromeSurfaces(
			chrome,
			measured(
				{ background: 'transparent', text: '#1a1e1d' },
				{ background: 'transparent', text: '#1a1e1d' },
				{ background: '#f7fafc', text: '#1a1e1d' }
			)
		);

		expect( chrome.headerBg ).toBe( '#f7fafc' );
		expect( chrome.footerBarBg ).toBe( '#f7fafc' );
	} );

	it( 'keeps an operator-pinned fact', () => {
		const chrome: Record< string, string > = { headerBg: '#d30c5c', footerInk: '#27104a' };
		applyChromeSurfaces(
			chrome,
			measured(
				{ background: '#f7f7f7', text: '#27104a' },
				{ background: '#f7f7f7', text: '#747474', inks: [ { color: '#747474', share: 0.62 } ] }
			),
			{ preserve: ( path ) => path === 'chrome.headerBg' }
		);

		expect( chrome.headerBg ).toBe( '#d30c5c' );
		expect( chrome.footerInk ).toBe( '#747474' );
	} );

	it( 'leaves the harvest alone when a region could not be measured', () => {
		const chrome: Record< string, string > = { headerBg: '#04132b' };
		const applied = applyChromeSurfaces( chrome, { light: {} } );

		expect( chrome.headerBg ).toBe( '#04132b' );
		expect( applied ).toEqual( [] );
	} );

	it( 'takes the nav ink from the header palette when the harvest disagrees', () => {
		// A `--design`-only refresh re-reads the stylesheet and can land on
		// `#000000` while the painted nav is the header's dominant ink.
		const chrome: Record< string, string > = { navInk: '#000000' };
		applyChromeSurfaces(
			chrome,
			measured(
				{ background: '#f7f7f7', text: '#27104a', inks: [ { color: '#27104a', share: 0.59 }, { color: '#ffffff', share: 0.41 } ] },
				{ background: '#f7f7f7', text: '#747474' }
			)
		);

		expect( chrome.navInk ).toBe( '#27104a' );
	} );

	it( 'leaves a nav ink that the palette already carries', () => {
		const chrome: Record< string, string > = { navInk: '#27104a' };
		applyChromeSurfaces(
			chrome,
			measured(
				{ background: '#f7f7f7', text: '#27104a', inks: [ { color: '#27104a', share: 0.59 } ] },
				{ background: '#f7f7f7', text: '#747474' }
			)
		);

		expect( chrome.navInk ).toBe( '#27104a' );
	} );

	// eca.directory, measured on the live footer: 62% of the glyph area is
	// `#747474` (the link LISTS) and 38% is `#27104a` (the block TITLES and the
	// wordmark). The palette winner is therefore the WORDS, not the titles — and
	// `chrome.footerInk` paints the rebuilt H3s while `footerBrand.text.ink`
	// paints the list copy. Writing the winner into both slots greyed every
	// title and the colour gate reported both source inks as drift, because the
	// rebuild carried only one of them.
	it( 'splits a two-ink footer across the heading and text slots by ROLE', () => {
		const chrome: {
			footerInk?: string;
			footerBarInk?: string;
			footerBrand?: { text?: { ink?: string } };
		} = {};
		const applied = applyChromeSurfaces(
			chrome,
			measured(
				{ background: '#f7f7f7', text: '#27104a' },
				{
					background: '#f7f7f7',
					text: '#747474',
					inks: [ { color: '#747474', share: 0.62 }, { color: '#27104a', share: 0.38 } ],
					roles: { heading: '#27104a', body: '#747474' },
				}
			)
		);

		expect( chrome.footerInk ).toBe( '#27104a' );
		expect( chrome.footerBarInk ).toBe( '#747474' );
		expect( chrome.footerBrand?.text?.ink ).toBe( '#747474' );
		expect( applied ).toContain( 'chrome.footerBrand.text.ink' );
	} );

	it( 'leaves the footer text slot alone when one ink paints the whole footer', () => {
		const chrome: { footerInk?: string; footerBrand?: { text?: { ink?: string } } } = {};
		const applied = applyChromeSurfaces(
			chrome,
			measured(
				{ background: '#f7f7f7', text: '#27104a' },
				{
					background: '#f7f7f7',
					text: '#27104a',
					inks: [ { color: '#27104a', share: 1 } ],
					roles: { heading: '#27104a', body: '#27104a' },
				}
			)
		);

		expect( chrome.footerInk ).toBe( '#27104a' );
		expect( chrome.footerBrand?.text?.ink ).toBeUndefined();
		expect( applied ).not.toContain( 'chrome.footerBrand.text.ink' );
	} );

	it( 'keeps an operator-pinned footer text ink', () => {
		const chrome: { footerBrand?: { text?: { ink?: string } } } = { footerBrand: { text: { ink: '#111827' } } };
		applyChromeSurfaces(
			chrome,
			measured(
				{ background: '#f7f7f7', text: '#27104a' },
				{
					background: '#f7f7f7',
					text: '#747474',
					inks: [ { color: '#747474', share: 0.62 }, { color: '#27104a', share: 0.38 } ],
					roles: { heading: '#27104a', body: '#747474' },
				}
			),
			{ preserve: ( path ) => path === 'chrome.footerBrand.text.ink' }
		);

		expect( chrome.footerBrand?.text?.ink ).toBe( '#111827' );
	} );
} );

describe( 'compareRegionColors with a band image', () => {
	const gradient = 'linear-gradient(rgb(0, 0, 0), rgb(4, 19, 43))';

	it( 'fails when the source painted a gradient and the build did not', () => {
		const source: ForgeColorBaseline = { band: { background: '#04132b', text: '#ffffff', image: gradient } };
		const built: ForgeColorBaseline = { band: { background: '#04132b', text: '#ffffff' } };
		const report = compareRegionColors( source, built );

		expect( report.compared ).toBe( 3 );
		expect( report.deviations ).toEqual( [
			expect.objectContaining( { region: 'band', metric: 'image', severity: 'fail', actual: null, deltaE: null } ),
		] );
	} );

	it( 'ignores whitespace and quote differences in the same gradient', () => {
		const source: ForgeColorBaseline = { band: { background: '#000000', text: '#ffffff', image: gradient } };
		const built: ForgeColorBaseline = {
			band: { background: '#000000', text: '#ffffff', image: 'linear-gradient( rgb(0, 0, 0), rgb(4, 19, 43) )' },
		};
		const report = compareRegionColors( source, built );

		expect( report.deviations ).toEqual( [] );
	} );

	it( 'warns (does not fail) on a differing gradient', () => {
		const source: ForgeColorBaseline = { band: { background: '#000000', text: '#ffffff', image: gradient } };
		const built: ForgeColorBaseline = {
			band: { background: '#000000', text: '#ffffff', image: 'linear-gradient(rgb(0, 0, 0), rgb(255, 0, 0))' },
		};
		const report = compareRegionColors( source, built );

		expect( report.deviations[ 0 ] ).toMatchObject( { metric: 'image', severity: 'warn', deltaE: null } );
	} );

	it( 'does not compare a photo url (the localizer rewrites it)', () => {
		const source: ForgeColorBaseline = {
			band: { background: '#000000', text: '#ffffff', image: 'url("https://source.example/hero.jpg")' },
		};
		const built: ForgeColorBaseline = {
			band: { background: '#000000', text: '#ffffff', image: 'url("/media/abc-hero.jpg")' },
		};
		const report = compareRegionColors( source, built );

		expect( report.deviations ).toEqual( [] );
		expect( report.compared ).toBe( 2 );
	} );
} );

describe( 'colour schemes (light/dark)', () => {
	const light = {
		body: { background: '#ffffff', text: '#2a2a2a' },
		header: { background: '#ffffff', text: '#2a2a2a' },
	};
	const dark = {
		body: { background: '#111111', text: '#f5f5f5' },
		header: { background: '#0a0a0a', text: '#ffffff' },
	};

	it( 'compares each mode independently', () => {
		const source = { light, dark };
		const built = { light, dark: { ...dark, header: { background: '#ffffff', text: '#ffffff' } } };
		const report = compareRegionColorModes( source, built );

		expect( report.deviations ).toHaveLength( 1 );
		expect( report.deviations[ 0 ] ).toMatchObject( {
			mode: 'dark',
			region: 'header',
			metric: 'background',
		} );
	} );

	it( 'reports a scheme the build dropped entirely', () => {
		const report = compareRegionColorModes( { light, dark }, { light } );
		expect( report.missing ).toContain( 'dark (scheme)' );
	} );

	it( 'treats a legacy flat baseline as light-only', () => {
		expect( normalizeColorModes( light ) ).toEqual( { light } );
		expect( normalizeColorModes( { light, dark } ) ).toEqual( { light, dark } );
	} );

	it( 'does not gate a scheme the source never implemented', () => {
		// Source: dark identical to light (no prefers-color-scheme support).
		// The build adds a dark theme — an enhancement, not a regression.
		const report = compareRegionColorModes( { light, dark: light }, { light, dark } );

		expect( report.unimplemented ).toContain( 'dark' );
		expect( report.deviations ).toEqual( [] );
		expect( report.missing ).toEqual( [] );
	} );

	it( 'gates dark when the source does define it', () => {
		const report = compareRegionColorModes( { light, dark }, { light } );
		expect( report.unimplemented ).toEqual( [] );
	} );
} );

// ---------------------------------------------------------------------------
// Selector safety. A CSS identifier cannot start with a digit, so `#6daaaef9`
// throws in `querySelector` — and one such id aborted every colour comparison
// on the page (eca.directory, 2026-11-24). Builders mint hash ids constantly,
// so these helpers are the single place that decision is made.
// ---------------------------------------------------------------------------
describe( 'idSelectors', () => {
	it( 'prefers #id when the id is a legal CSS identifier', () => {
		expect( idSelectors( 'about' ) ).toEqual( [ '#about', '[id="about"]' ] );
		expect( idSelectors( 'about-us' ) ).toEqual( [ '#about-us', '[id="about-us"]' ] );
		expect( idSelectors( '_band' ) ).toEqual( [ '#_band', '[id="_band"]' ] );
	} );

	it( 'falls back to an attribute selector for a digit-leading id', () => {
		expect( idSelectors( '6daaaef9' ) ).toEqual( [ '[id="6daaaef9"]' ] );
	} );

	it( 'returns no selector for an empty id', () => {
		expect( idSelectors( '' ) ).toEqual( [] );
		expect( idSelectors( undefined as unknown as string ) ).toEqual( [] );
	} );

	it( 'escapes a quote so the attribute selector stays well formed', () => {
		expect( idSelectors( 'a"b' ) ).toEqual( [ '[id="a\\"b"]' ] );
	} );
} );

describe( 'cssIdSelector', () => {
	it( 'never returns an invalid identifier selector', () => {
		expect( cssIdSelector( '6daaaef9' ) ).toBe( '[id="6daaaef9"]' );
		expect( cssIdSelector( 'about' ) ).toBe( '#about' );
	} );

	it( 'returns an empty string when there is nothing to address', () => {
		expect( cssIdSelector( '' ) ).toBe( '' );
	} );
} );

describe( 'bandRegionSelector', () => {
	const section = ( patch: Partial< ForgeSection > ): ForgeSection =>
		( { id: 'b1', ...patch } ) as ForgeSection;

	it( 'addresses an anchored band by its id', () => {
		expect( bandRegionSelector( section( { anchor: 'about' } ) ) ).toBe( '#about' );
	} );

	it( 'escapes a digit-leading anchor', () => {
		expect( bandRegionSelector( section( { anchor: '6daaaef9' } ) ) ).toBe( '[id="6daaaef9"]' );
	} );

	it( 'falls back to the Elementor element class', () => {
		expect( bandRegionSelector( section( { vendorId: '4c649f2e' } ) ) ).toBe(
			'.elementor-element-4c649f2e'
		);
	} );

	it( 'returns undefined when the band has no handle at all', () => {
		expect( bandRegionSelector( section( { anchor: '', vendorId: '' } ) ) ).toBeUndefined();
	} );
} );

describe( 'builtBandSelectors', () => {
	it( 'addresses a rebuilt band by its marker first', () => {
		// The scaffold emits `data-section-id` and no `id`; the marker is the
		// only handle that actually resolves in the built document.
		expect( builtBandSelectors( '6daaaef9' )[ 0 ] ).toBe( '[data-section-id="6daaaef9"]' );
	} );

	it( 'keeps the id selectors as a fallback, escaped when needed', () => {
		expect( builtBandSelectors( 'about' ) ).toEqual( [
			'[data-section-id="about"]',
			'#about',
			'[id="about"]',
		] );
		expect( builtBandSelectors( '6daaaef9' ) ).toEqual( [
			'[data-section-id="6daaaef9"]',
			'[id="6daaaef9"]',
		] );
	} );

	it( 'returns no selector for an empty id', () => {
		expect( builtBandSelectors( '' ) ).toEqual( [] );
	} );
} );
