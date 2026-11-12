// ---------------------------------------------------------------------------
// motion.test.ts — the piece-level parallax CSS generator (Proposal 121 +
// the element pass). The source facts are Phlox's AvertaParallaxBox depth/
// origin/off and Elementor's motion_fx direction/speed/range/devices; these
// tests pin the mapping from facts to a scoped rule.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';

import {
	parallaxBandCss,
	parallaxEndpoints,
	parallaxMaxWidth,
	parallaxMinWidth,
	parallaxPieceCss,
	parallaxRangePx,
	parallaxSign,
} from '../templates/blog/src/lib/motion.ts';
import type { ParallaxPiece } from '../templates/blog/src/lib/behaviors.generated.ts';

describe( 'parallaxRangePx', () => {
	it( 'scales the existing speed × 24px contract by depth', () => {
		expect( parallaxRangePx( { depth: 0.1, speed: 2 } ) ).toBe( 48 );
		expect( parallaxRangePx( { depth: 0.05, speed: 2 } ) ).toBe( 24 );
		expect( parallaxRangePx( { depth: 0.2, speed: 1 } ) ).toBe( 48 );
	} );

	it( 'clamps to a subtle, bounded range', () => {
		expect( parallaxRangePx( { depth: 0.5, speed: 4 } ) ).toBe( 96 );
		expect( parallaxRangePx( { depth: 0.01, speed: 1 } ) ).toBe( 8 );
		expect( parallaxRangePx( { depth: 0 } ) ).toBe( 0 );
	} );

	it( 'falls back to the band speed then to 1', () => {
		expect( parallaxRangePx( {}, 2 ) ).toBe( 48 );
		expect( parallaxRangePx( {} ) ).toBe( 24 );
	} );
} );

describe( 'parallaxSign', () => {
	it( 'flips Phlox depth exactly like AvertaParallaxBox', () => {
		// The theme: `depth = depth < 0 ? 1 : -1`; the measured source rendered
		// depth -0.1 as translateY(+81.44px) and +0.1 as translateY(-81.44px).
		expect( parallaxSign( { depth: -0.1 } ) ).toBe( 1 );
		expect( parallaxSign( { depth: 0.1 } ) ).toBe( -1 );
	} );

	it( 'keeps Elementor direction: negative → positive translateY', () => {
		expect( parallaxSign( { direction: 'negative' } ) ).toBe( 1 );
		expect( parallaxSign( { direction: 'positive' } ) ).toBe( -1 );
		expect( parallaxSign( {} ) ).toBe( 1 );
	} );
} );

describe( 'parallaxEndpoints', () => {
	it( 'bottom travels 0 → +range for a negative depth', () => {
		expect( parallaxEndpoints( { depth: -0.1, origin: 'bottom', speed: 2 } ) ).toEqual( { from: 0, to: 48 } );
	} );

	it( 'bottom travels 0 → -range for a positive depth', () => {
		expect( parallaxEndpoints( { depth: 0.1, origin: 'bottom', speed: 2 } ) ).toEqual( { from: 0, to: -48 } );
	} );

	it( 'top is the mirror of bottom', () => {
		expect( parallaxEndpoints( { depth: -0.1, origin: 'top', speed: 2 } ) ).toEqual( { from: 0, to: -48 } );
		expect( parallaxEndpoints( { depth: 0.1, origin: 'top', speed: 2 } ) ).toEqual( { from: 0, to: 48 } );
	} );

	it( 'middle and Elementor-only pieces travel symmetrically', () => {
		expect( parallaxEndpoints( { depth: -0.1, origin: 'middle', speed: 2 } ) ).toEqual( { from: 48, to: -48 } );
		expect( parallaxEndpoints( { direction: 'negative', speed: 2 } ) ).toEqual( { from: 48, to: -48 } );
	} );
} );

describe( 'parallaxMinWidth / parallaxMaxWidth', () => {
	it( 'honours Phlox data-parallax-off (disabled at <= off)', () => {
		expect( parallaxMinWidth( { off: 1024 } ) ).toBe( 1025 );
	} );

	it( 'honours the Elementor device list', () => {
		expect( parallaxMinWidth( { devices: [ 'desktop' ] } ) ).toBe( 1025 );
		expect( parallaxMinWidth( { devices: [ 'desktop', 'tablet' ] } ) ).toBe( 768 );
		expect( parallaxMinWidth( { devices: [ 'desktop', 'tablet', 'mobile' ] } ) ).toBeUndefined();
		expect( parallaxMinWidth( { off: 1024, devices: [ 'desktop', 'tablet' ] } ) ).toBe( 1025 );
	} );

	it( 'maps a mobile-only effect to a max-width gate', () => {
		expect( parallaxMaxWidth( { devices: [ 'mobile' ] } ) ).toBe( 767 );
		expect( parallaxMaxWidth( { devices: [ 'desktop', 'mobile' ] } ) ).toBeUndefined();
	} );
} );

describe( 'parallaxPieceCss', () => {
	const piece = ( overrides: Partial< ParallaxPiece > = {} ): ParallaxPiece => ( { depth: -0.1, origin: 'bottom', speed: 2, ...overrides } );

	it( 'emits a scoped rule with the source endpoints and the off gate', () => {
		const css = parallaxPieceCss( 'a1b2c3d', 'hero', piece( { index: 0, off: 1024 } ), 0 );
		expect( css ).toContain( '@media (min-width:1025px)' );
		expect( css ).toContain( '[data-section-id="a1b2c3d"].ds-parallax .ds-media:nth-of-type(1)' );
		expect( css ).toContain( '--ds-parallax-from:0px' );
		expect( css ).toContain( '--ds-parallax-to:48px' );
	} );

	it( 'targets the icon-list split media and cards', () => {
		const css = parallaxPieceCss( 'a1b2c3d', 'icon-list', piece( { index: 0 } ), 0 );
		expect( css ).toContain( '.ds-icon-media img:nth-of-type(1)' );
		expect( css ).toContain( '.ds-card:nth-of-type(1)' );
	} );

	it( 'carries a non-default affected range as animation-range', () => {
		const css = parallaxPieceCss( 'a1b2c3d', 'hero', piece( { range: { start: 10, end: 90 } } ), 0 );
		expect( css ).toContain( 'animation-range:10% 90%' );
	} );

	it( 'does not restate the default 0-100 range', () => {
		const css = parallaxPieceCss( 'a1b2c3d', 'hero', piece( { range: { start: 0, end: 100 } } ), 0 );
		expect( css ).not.toContain( 'animation-range' );
	} );

	it( 'skips a background-type piece rather than faking a transform', () => {
		expect( parallaxPieceCss( 'a1b2c3d', 'hero', piece( { type: 'background' } ), 0 ) ).toBe( '' );
	} );

	it( 'uses the source media index, with the array position as fallback', () => {
		expect( parallaxPieceCss( 'a1b2c3d', 'hero', piece( { index: 3 } ), 0 ) ).toContain( 'nth-of-type(4)' );
		expect( parallaxPieceCss( 'a1b2c3d', 'hero', piece(), 2 ) ).toContain( 'nth-of-type(3)' );
	} );
} );

describe( 'parallaxBandCss', () => {
	it( 'emits one rule per piece and nothing for a piece-less band', () => {
		const css = parallaxBandCss(
			{ id: 'a1b2c3d', kind: 'hero' },
			{ id: 'a1b2c3d', kind: 'parallax', speed: 2, pieces: [ { index: 0, depth: -0.1, origin: 'bottom' }, { index: 1, depth: 0.1, origin: 'bottom' } ] }
		);
		expect( css.match( /nth-of-type/g ) ).toHaveLength( 2 );
		expect( css ).toContain( '--ds-parallax-from:0px' );
		expect( parallaxBandCss( { id: 'a1b2c3d', kind: 'hero' }, { id: 'a1b2c3d', kind: 'parallax', speed: 2 } ) ).toBe( '' );
		expect( parallaxBandCss( { id: 'a1b2c3d', kind: 'hero' }, { id: 'a1b2c3d', kind: 'reveal' } ) ).toBe( '' );
	} );
} );

describe( 'global.css motion contract', () => {
	const css = readFileSync( new URL( '../templates/blog/src/styles/global.css', import.meta.url ), 'utf8' );

	it( 'consumes the piece endpoints, not a hardcoded symmetric pair', () => {
		expect( css ).toContain( 'var(--ds-parallax-from' );
		expect( css ).toContain( 'var(--ds-parallax-to' );
		expect( css ).not.toContain( 'animation-name: ds-parallax-media-reverse' );
	} );

	it( 'keeps animation-timeline a LONGHAND (the minifier regression)', () => {
		expect( css ).toContain( 'animation-timeline: scroll(root)' );
		expect( css ).not.toMatch( /animation:[^;}]*scroll\(/ );
	} );

	it( 'keeps the intro copy full-width except in centered/hero bands', () => {
		expect( css ).toMatch( /\.ds-lede\s*\{\s*max-width:\s*100%/ );
		expect( css ).toMatch( /\.ds-body--center \.ds-lede/ );
		expect( css ).toMatch( /\.ds-section-wrap--hero \.ds-lede/ );
	} );

	it( 'paints hero body copy with the hero ink, not the muted content default', () => {
		expect( css ).toMatch( /\.ds-section-wrap--hero \.ds-text,\s*\.ds-section-wrap--hero \.ds-hero p\s*\{\s*color:\s*inherit/ );
	} );

	it( 'centers hero content VERTICALLY (the generic wrap is a column)', () => {
		// `.ds-section-wrap` later makes every wrap a column; without
		// justify-content the hero section sits at the top of its 724px band.
		expect( css ).toMatch( /\.ds-section-wrap--hero\s*\{[^}]*justify-content:\s*center/ );
	} );

	it( 'pauses generated motion from the WCAG 2.2.2 checkbox', () => {
		expect( css ).toContain( 'body:has(.motion-toggle input:checked)' );
		expect( css ).toContain( 'animation-play-state: paused' );
	} );
} );
