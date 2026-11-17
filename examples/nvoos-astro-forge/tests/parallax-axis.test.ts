// ---------------------------------------------------------------------------
// parallax-axis.test.ts — the axis contract, END TO END.
//
// The unit tests cover detection (design.test.ts) and rendering (motion.test.ts)
// separately. This file exists because the original defect lived BETWEEN the
// links: the analyzer read `motion_fx_translateX_*`, the renderer only ever
// wrote a Y channel, and nothing asserted that the axis survived the generated
// facts. A per-link test cannot catch that — only the chain can.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { analyzeScrollBehavior } from '../lib/design.ts';
import { renderBehaviorsGenerated } from '../lib/scaffold.ts';
import { parallaxAxis, parallaxBandCss } from '../templates/blog/src/lib/motion.ts';
import type { SiteProfile } from '../lib/types.ts';

/** An Elementor section whose only motion is a HORIZONTAL translate. */
const HORIZONTAL_SETTINGS =
	'&quot;motion_fx_motion_fx_scrolling&quot;:&quot;yes&quot;,&quot;motion_fx_translateX_effect&quot;:&quot;yes&quot;,&quot;motion_fx_translateX_speed&quot;:{&quot;unit&quot;:&quot;px&quot;,&quot;size&quot;:2,&quot;sizes&quot;:[]}';

const HTML =
	'<section class="elementor-section elementor-top-section elementor-element elementor-element-abc1234">' +
	`<div class="elementor-element elementor-element-def5678" data-settings="{${HORIZONTAL_SETTINGS}}"><img src="/x.jpg" alt=""></div>` +
	'</section>';

const pass = analyzeScrollBehavior( { url: 'https://example.com', html: HTML, css: [] } );
const entry = pass.entries.find( ( behavior ) => behavior.kind === 'parallax' );
const generated = renderBehaviorsGenerated( {
	url: 'https://example.com',
	name: 'Fixture',
	collections: [],
	design: { behavior: pass },
} as unknown as SiteProfile );

describe( 'a horizontal source survives the whole pipeline', () => {
	it( '1. the analyzer records the axis on the piece', () => {
		expect( entry?.id ).toBe( 'abc1234' );
		expect( entry?.pieces?.[ 0 ]?.axis ).toBe( 'x' );
	} );

	it( '2. the generated facts carry the axis value', () => {
		expect( generated ).toContain( "axis: 'x'" );
	} );

	it( '3. the generated INTERFACE declares it (a generated project must typecheck)', () => {
		// The repo's checked-in stub is not what a customer builds from — the
		// renderer writes this interface. Without it, `piece.axis` in the
		// template's motion.ts is a TS error in every scaffolded site.
		expect( generated ).toContain( "axis?: 'x' | 'y';" );
	} );

	it( '4. the renderer resolves the axis and writes the X channel', () => {
		expect( entry && parallaxAxis( entry.pieces?.[ 0 ] ?? {} ) ).toBe( 'x' );

		const css = parallaxBandCss( { id: 'abc1234', kind: 'gallery' }, entry );
		expect( css ).toContain( '--ds-parallax-x-from:' );
		expect( css ).toContain( '--ds-parallax-x-to:' );
	} );

	it( '5. the Y channel is frozen so the piece cannot drift diagonally', () => {
		const css = parallaxBandCss( { id: 'abc1234', kind: 'gallery' }, entry );
		expect( css ).toContain( '--ds-parallax-from:0px' );
		expect( css ).toContain( '--ds-parallax-to:0px' );
	} );

	it( '6. a vertical source still produces the untouched Y-only output', () => {
		// The compatibility promise: pre-existing sites regenerate byte-identically.
		const verticalHtml =
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-abc9876">' +
			'<div class="aux-parallax-piece elementor-element elementor-element-aaa1111" data-parallax-depth="0.1" data-parallax-origin="bottom"><img src="/y.jpg" alt=""></div>' +
			'</section>';
		const vertical = analyzeScrollBehavior( { url: 'https://example.com', html: verticalHtml, css: [] } );
		const verticalEntry = vertical.entries.find( ( behavior ) => behavior.kind === 'parallax' );

		expect( verticalEntry?.pieces?.[ 0 ]?.axis ).toBeUndefined();
		expect( parallaxAxis( verticalEntry?.pieces?.[ 0 ] ?? {} ) ).toBe( 'y' );

		const css = parallaxBandCss( { id: 'abc9876', kind: 'gallery' }, verticalEntry );
		expect( css ).toContain( '--ds-parallax-from:' );
		expect( css ).not.toContain( '--ds-parallax-x-' );
	} );
} );
