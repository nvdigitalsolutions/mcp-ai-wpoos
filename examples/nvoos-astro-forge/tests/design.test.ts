// ---------------------------------------------------------------------------
// design.test.ts — the forge design-import module (Proposal 114 P1/P5):
// deterministic extraction, token mapping, contrast-fix suggestions, and the
// language-parity score the verify surface runs on.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	analyzeBandContinuity,
	analyzeBuiltCss,
	analyzeChrome,
	analyzeDesignInput,
	analyzeScrollBehavior,
	analyzeSections,
	colorDistance,
	designToTokens,
	lightnessOf,
	normalizeColor,
	resolveSectionTheme,
	scoreParity,
	suggestContrastFixes,
	stylesheetLinks,
	verifyBandFlow,
} from '../lib/design.ts';
import type { ForgeDesign, ForgeSection } from '../lib/types.ts';

/** A minimal Elementor-ish CSS payload (the page-builder var surface). */
const ELEMENTOR_CSS = `
:root {
	--e-global-color-primary: #6ec1e4;
	--e-global-color-text: #7a7a7a;
	--e-global-typography-primary-font-family: "Rubik";
	--e-global-typography-text-font-family: "Roboto";
}
.elementor-section { padding: 20px; margin: 16px; border-radius: 5px; }
.elementor-widget-heading h2 { font-size: 28px; font-size: 40px; }
.elementor-widget-text-editor p { font-size: 16px; }
.box-shadow { box-shadow: 0 4px 12px rgba(0,0,0,0.2); }
`;

const DESIGN_HTML =
	'<div class="site-header"></div><a class="btn btn-primary">Buy</a><div class="card"></div><div class="elementor-widget-icon-box"></div>';

describe( 'normalizeColor / color math', () => {
	it( 'normalizes hex/rgb/hsl and rejects junk', () => {
		expect( normalizeColor( '#ABC' ) ).toBe( '#aabbcc' );
		expect( normalizeColor( 'rgb(255, 255, 255)' ) ).toBe( '#ffffff' );
		expect( normalizeColor( 'hsl(0, 100%, 50%)' ) ).toBe( '#ff0000' );
		expect( normalizeColor( 'linear-gradient(red, blue)' ) ).toBeUndefined();
	} );

	it( 'orders lightness (white > gray > black) and distances identical colors at 0', () => {
		expect( lightnessOf( '#ffffff' ) ).toBeGreaterThan( lightnessOf( '#7a7a7a' ) );
		expect( lightnessOf( '#7a7a7a' ) ).toBeGreaterThan( lightnessOf( '#000000' ) );
		expect( colorDistance( '#720eec', '#720eec' ) ).toBe( 0 );
		expect( colorDistance( '#000000', '#ffffff' ) ).toBeCloseTo( 1, 5 );
	} );
} );

describe( 'stylesheetLinks', () => {
	it( 'collects same-origin links only, capped, and ignores foreign hosts', () => {
		const html =
			'<link rel="stylesheet" href="/wp-content/themes/x/style.css">' +
			'<link rel="stylesheet" href="https://cdn.example.com/x.css">' +
			'<link rel="alternate" href="/feed">';
		expect( stylesheetLinks( html, 'https://shop.example.com' ) ).toEqual( [
			'https://shop.example.com/wp-content/themes/x/style.css',
		] );
	} );
} );

describe( 'analyzeSections / resolveSectionTheme (Proposal 117 §2)', () => {
	const SECTIONS_HTML =
		'<div class="elementor">' +
		'<section id="about" class="elementor-section elementor-top-section elementor-element elementor-element-a4ad586"><div class="elementor-background-overlay"></div><h2>Hero</h2></section>' +
		'<section class="elementor-section elementor-top-section elementor-element elementor-element-5e78df1"><h2>Products</h2></section>' +
		'<section class="elementor-section elementor-top-section elementor-element elementor-element-48ace0b"><h2>Logos</h2></section>' +
		'</div>';
	const SECTIONS_CSS = `
.elementor-element-a4ad586:not(.x), .elementor-element-a4ad586 > .elementor-element-populated { background-image: url("https://jayainc.com/wp-content/uploads/hero.jpg"); }
.elementor-element-a4ad586 .elementor-background-overlay { background-color: rgba(8, 26, 67, 0.9); }
.elementor-element-5e78df1 { background-image: linear-gradient(180deg, #ffffff 6%, rgb(4, 19, 43) 100%); padding: 100px 0px; }
.elementor-element-48ace0b { background-color: #f6f6f6; }
`;

	it( 'extracts ordered section backgrounds from html + css, keyed by id', () => {
		const sections = analyzeSections( { url: 'https://jayainc.com', html: SECTIONS_HTML, css: [ SECTIONS_CSS ] } );
		expect( sections ).toEqual( [
			{
				id: 'a4ad586',
				anchor: 'about',
				bgImage: 'https://jayainc.com/wp-content/uploads/hero.jpg',
				overlay: 'rgba(8, 26, 67, 0.9)',
			},
			{
				id: '5e78df1',
				bgGradient: 'linear-gradient(180deg, #ffffff 6%, rgb(4, 19, 43) 100%)',
				padding: '100px 0px',
			},
			{ id: '48ace0b', bgColor: '#f6f6f6' },
		] );
	} );

	it( 'resolves hero from a photo, dark from a navy gradient, light from near-white', () => {
		expect( resolveSectionTheme( { id: 'a', bgImage: 'x.jpg' } ) ).toBe( 'hero' );
		expect( resolveSectionTheme( { id: 'b', bgGradient: 'linear-gradient(180deg, #ffffff 6%, rgb(4, 19, 43) 100%)' } ) ).toBe( 'dark' );
		expect( resolveSectionTheme( { id: 'c', bgColor: '#f6f6f6' } ) ).toBe( 'light' );
		expect( resolveSectionTheme( { id: 'd', bgColor: '#e5e5e5' } ) ).toBe( 'alt' );
	} );

	it( 'drops the background-color underlay when a photo exists (the source renders it invisibly)', () => {
		// jayainc's hero: rgba(8,26,67,0.9) is UNDER jaya-3-1.jpg on the
		// source — painting it as the band color over-tinted the rebuild.
		const html = '<section class="elementor-section elementor-top-section elementor-element elementor-element-a4ad586"><h2>Hero</h2></section>';
		const css = '.elementor-element-a4ad586 { background-image: url("https://jayainc.com/photo.jpg"); background-color: rgba(8, 26, 67, 0.9); }';
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ css ] } );
		expect( sections ).toEqual( [ { id: 'a4ad586', bgImage: 'https://jayainc.com/photo.jpg' } ] );
	} );

	it( 'captures the boxed container measure + column gap (124 §3 facts)', () => {
		const html =
			'<section class="elementor-section elementor-top-section elementor-section-boxed elementor-element elementor-element-5e78df1">' +
			'<div class="elementor-container elementor-column-gap-wider"><h2>Products</h2></div>' +
			'</section>';
		const css = '.elementor-section.elementor-section-boxed > .elementor-container { max-width: 1140px; }';
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ css ] } );
		expect( sections ).toEqual( [ { id: '5e78df1', measure: '1140px', paddingX: '30px' } ] );
	} );

	it( 'falls back to the Elementor boxed default when no container rule exists', () => {
		const html =
			'<section class="elementor-section elementor-top-section elementor-section-boxed elementor-element elementor-element-48ace0b">' +
			'<div class="elementor-container"><h2>Logos</h2></div>' +
			'</section>';
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [] } );
		expect( sections[ 0 ] ).toMatchObject( { id: '48ace0b', measure: '1140px' } );
		expect( sections[ 0 ].paddingX ).toBeUndefined();
	} );

	it( 'ignores non-numeric container max-width overrides (the theme page-cover rule)', () => {
		const html =
			'<section class="elementor-section elementor-top-section elementor-section-boxed elementor-element elementor-element-a4ad586">' +
			'<div class="elementor-container"><h2>Hero</h2></div>' +
			'</section>';
		const css = [
			'.aux-page-cover .aux-page-cover-wrapper > .elementor-container { width: 100vw !important; max-width: none !important; }',
			'.elementor-section.elementor-section-boxed > .elementor-container { max-width: 1200px; }',
		].join( '\n' );
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ css ] } );
		expect( sections[ 0 ]?.measure ).toBe( '1200px' );
	} );

	it( 'captures section-level pull facts: negative margins and z-index (124 §4)', () => {
		const html =
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-5e78df1">' +
			'<div class="elementor-container"><h2>Products</h2></div>' +
			'</section>';
		const css = '.elementor-element-5e78df1 { background-color: #ffffff; margin-top: -80px; margin-bottom: 0px; z-index: 5; }';
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ css ] } );
		expect( sections[ 0 ] ).toMatchObject( { id: '5e78df1', marginTop: '-80px', zIndex: '5' } );
		// Zero margins are noise, not a pull fact.
		expect( sections[ 0 ].marginBottom ).toBeUndefined();
	} );

	it( 'captures the top-level column responsive facts (124 §6)', () => {
		const html =
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-fcc5e07">' +
			'<div class="elementor-container">' +
			'<div class="elementor-column elementor-col-50 elementor-md-50 elementor-sm-100 elementor-reverse-mobile"></div>' +
			'<div class="elementor-column elementor-col-50 elementor-hidden-desktop"></div>' +
			'</div>' +
			'</section>';
		const css = '.elementor-element-fcc5e07 { background-color: #e5e5e5; }';
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ css ] } );
		expect( sections[ 0 ]?.columns ).toEqual( [
			{ width: 50, widthTablet: 50, widthMobile: 100, reverseMobile: true },
			{ width: 50, hiddenDesktop: true },
		] );
	} );

	it( 'ignores inner-section columns (only the band grid counts, 124 §6)', () => {
		const html =
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-d5844b9">' +
			'<div class="elementor-container">' +
			'<div class="elementor-column elementor-col-100">' +
			'<div class="elementor-widget-wrap">' +
			'<section class="elementor-section elementor-inner-section"><div class="elementor-container"><div class="elementor-column elementor-col-50"></div></div></section>' +
			'</div></div>' +
			'</div>' +
			'</section>';
		const css = '.elementor-element-d5844b9 { background-color: #ffffff; }';
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ css ] } );
		// One top-level column — below the two-column threshold, so no facts.
		expect( sections[ 0 ]?.columns ).toBeUndefined();
	} );

	it( 'captures the split-hero layout + collage facts (125 §1 — jayainc about shape)', () => {
		const html =
			'<section id="about" class="elementor-section elementor-top-section elementor-element elementor-element-d5844b9">' +
			'<div class="elementor-container">' +
			'<div class="elementor-column elementor-col-50 elementor-element elementor-element-8f68b56">' +
			'<div class="elementor-widget-wrap">' +
			'<div class="elementor-element elementor-element-3b80cc6 elementor-widget elementor-widget-aux_modern_heading"><h2 class="aux-modern-heading-primary">At JAYAINC</h2><div class="aux-modern-heading-divider"></div></div>' +
			'<section class="elementor-section elementor-inner-section elementor-element elementor-element-4239539"><div class="elementor-container">' +
			'<div class="elementor-column elementor-col-50"><div class="elementor-widget-wrap"><div class="elementor-element elementor-element-900f3fe elementor-widget elementor-widget-text-editor"><p>one</p></div></div></div>' +
			'<div class="elementor-column elementor-col-50"><div class="elementor-widget-wrap"><div class="elementor-element elementor-element-8e3c14c elementor-widget elementor-widget-text-editor"><p>two</p></div></div></div>' +
			'</div></section>' +
			'<div class="elementor-element elementor-element-ff6efc3 elementor-widget elementor-widget-aux_button"><a>Our Products</a></div>' +
			'</div></div>' +
			'<div class="elementor-column elementor-col-50 elementor-element elementor-element-a2d36f3">' +
			'<div class="elementor-widget-wrap">' +
			'<div class="elementor-element elementor-element-3dafd39 elementor-widget elementor-widget-aux_simple_shape"><div class="aux-the-shape"></div></div>' +
			'<div class="elementor-element elementor-element-a82fe0f elementor-widget elementor-widget-aux_image"><div class="aux-media-image"><img src="ronan.jpg" alt="" /></div></div>' +
			'<div class="elementor-element elementor-element-37ebddc elementor-widget elementor-widget-aux_image"><div class="aux-media-image"><img src="elevate.jpg" alt="" /></div></div>' +
			'</div></div>' +
			'</div>' +
			'</section>';
		const css = [
			'.elementor-element-d5844b9 { background-image: linear-gradient(180deg, #e5e5e5 0%, #ffffff 100%); }',
			'.elementor-element-3b80cc6 .aux-modern-heading-divider { height: 6px; width: 125px; background-color: #ffcf0e; box-shadow: 0px 4px 8px 0px rgba(255,207,14,0.5); }',
			'.elementor-element-3b80cc6 .aux-modern-heading-primary { font-size: 43px; font-weight: 500; line-height: 57px; letter-spacing: -1.5px; }',
			'.elementor-element-3dafd39 .aux-the-shape { width: 412px; height: 450px; background-image: linear-gradient(39deg, #ffcf0e 0%, #ffb70e 100%); }',
			'.elementor-element-a82fe0f { position: absolute; top: -25%; left: 0px; }',
			'.elementor-element-a82fe0f .aux-media-image { box-shadow: 0px 3px 34px 0px rgba(0,0,0,0.16); }',
			'.elementor-element-37ebddc { position: absolute; top: 25%; right: -110px; }',
		].join( '\n' );
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ css ] } );
		expect( sections[ 0 ] ).toMatchObject( {
			id: 'd5844b9',
			anchor: 'about',
			layout: 'split',
			bodyColumns: 2,
			titleDivider: { width: '125px', height: '6px', background: '#ffcf0e', shadow: '0px 4px 8px 0px rgba(255,207,14,0.5)' },
			titleStyle: { size: '43px', weight: '500', lineHeight: '57px', letterSpacing: '-1.5px' },
			mediaShape: { width: '412px', height: '450px', background: 'linear-gradient(39deg, #ffcf0e 0%, #ffb70e 100%)' },
			mediaOffsets: [
				{ top: '-25%', left: '0px', shadow: '0px 3px 34px 0px rgba(0,0,0,0.16)' },
				{ top: '25%', right: '-110px' },
			],
		} );
	} );

	it( 'prefers the theme container var over a conflicting sheet rule (125 §3)', () => {
		// jayainc's sheets carry a 1900px .elementor-container rule that the
		// live site never renders — the per-breakpoint --container-max-width
		// declaration is the boxed truth (desktop first).
		const html =
			'<section class="elementor-section elementor-top-section elementor-section-boxed elementor-element elementor-element-5e78df1">' +
			'<div class="elementor-container"><h2>Products</h2></div>' +
			'</section>';
		const css = [
			':root { --container-max-width: 1140px; }',
			'.elementor-section.elementor-section-boxed > .elementor-container { max-width: 1900px; }',
		].join( '\n' );
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ css ] } );
		expect( sections[ 0 ]?.measure ).toBe( '1140px' );
	} );

	it( 'never splits a photo-background hero (the band itself is the media)', () => {
		// jayainc's hero: column 2 carries an EMPTY aux_image shell (a builder
		// leftover) — the photo is the section background. Split must not fire.
		const html =
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-a4ad586">' +
			'<div class="elementor-container">' +
			'<div class="elementor-column elementor-col-50"><div class="elementor-widget-wrap">' +
			'<div class="elementor-element elementor-element-9b25b26 elementor-widget elementor-widget-aux_modern_heading"><h2 class="aux-modern-heading-primary">Ship to South Florida</h2><div class="aux-modern-heading-divider"></div></div>' +
			'</div></div>' +
			'<div class="elementor-column elementor-col-50"><div class="elementor-widget-wrap">' +
			'<div class="elementor-element elementor-element-ae19d28 elementor-widget elementor-widget-aux_image"><div class="aux-media-image"></div></div>' +
			'</div></div>' +
			'</div>' +
			'</section>';
		const css = '.elementor-element-a4ad586 { background-image: url("https://jayainc.com/jaya-3-1.jpg"); }';
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ css ] } );
		expect( sections[ 0 ]?.layout ).toBeUndefined();
		expect( sections[ 0 ]?.bgImage ).toBe( 'https://jayainc.com/jaya-3-1.jpg' );
	} );

	it( 'adopts a nav anchor from an inner section (125 §6 — jayainc #products)', () => {
		// The nav target can live on an INNER section inside the band — the
		// rebuilt wrap must carry the id or the chrome nav dangles.
		const html =
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-5e78df1">' +
			'<div class="elementor-container"><div class="elementor-column"><div class="elementor-widget-wrap">' +
			'<section class="elementor-section elementor-inner-section" id="products"><div class="elementor-container"><div class="elementor-column"></div></div></section>' +
			'</div></div></div>' +
			'</section>';
		const css = '.elementor-element-5e78df1 { background-color: #ffffff; }';
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ css ] } );
		expect( sections[ 0 ]?.anchor ).toBe( 'products' );
	} );

	it( 'ignores internal auto ids when adopting anchors (df_/elementor prefixes)', () => {
		const html =
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-fd63522">' +
			'<div class="_df_book df-lite" id="df_2113"></div>' +
			'</section>';
		const css = '.elementor-element-fd63522 { background-color: #f6f6f6; }';
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ css ] } );
		expect( sections[ 0 ]?.anchor ).toBeUndefined();
	} );

	it( 'captures the flipbook catalog from the dflip shortcode (125 §4)', () => {
		// The PDF source lives ONLY in the widget script — the sanitizer
		// strips scripts, so the raw-homepage pass must carry it as a band
		// fact (jayainc's FIORI catalog below the logos band).
		const html =
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-fd63522">' +
			'<div class="elementor-container"><div class="elementor-column"><div class="elementor-widget-wrap">' +
			'<div class="elementor-element elementor-element-c33f204 elementor-widget elementor-widget-shortcode"><div class="elementor-widget-container"><div class="elementor-shortcode">' +
			'<div class="_df_book df-lite" id="df_2113" _slug="fiori-digital-catalogue" data-title="fiori-digital-catalogue" wpoptions="true"></div>' +
			'<script class="df-shortcode-script" type="application/javascript">window.option_df_2113 = {"outline":[],"backgroundColor":"#F6F6F6","height":"auto","source":"https:\\/\\/jayainc.com\\/wp-content\\/uploads\\/2021\\/01\\/FIORI-CATALOG-2021.pdf"}; if(window.DFLIP){window.DFLIP.parseBooks();}</script>' +
			'</div></div></div>' +
			'</div></div></div>' +
			'</section>';
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ '.elementor-element-fd63522 { background-color: #f6f6f6; }' ] } );
		expect( sections[ 0 ]?.catalog ).toEqual( {
			title: 'fiori-digital-catalogue',
			source: 'https://jayainc.com/wp-content/uploads/2021/01/FIORI-CATALOG-2021.pdf',
		} );
	} );

	it( 'ignores malformed dflip payloads (no source, no catalog fact)', () => {
		const html =
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-fd63522">' +
			'<div class="_df_book df-lite" id="df_9"></div>' +
			'<script class="df-shortcode-script">window.option_df_9 = {"broken";</script>' +
			'</section>';
		const sections = analyzeSections( { url: 'https://jayainc.com', html, css: [ '.elementor-element-fd63522 { background-color: #f6f6f6; }' ] } );
		expect( sections[ 0 ]?.catalog ).toBeUndefined();
	} );
} );

describe( 'analyzeBandContinuity / verifyBandFlow (124 §7)', () => {
	it( 'reports the source seams where adjacent bands do not meet', () => {
		const sections: ForgeSection[] = [
			{ id: 'a', bgGradient: 'linear-gradient(180deg, #e5e5e5 0%, #ffffff 100%)' },
			{ id: 'b', bgGradient: 'linear-gradient(180deg, #ffffff 6%, #04132b 100%)' },
			{ id: 'c', bgColor: '#e5e5e5' },
		];
		const seams = analyzeBandContinuity( sections );
		// a→b meets white-to-white; b→c is the navy→gray hard cut.
		expect( seams ).toHaveLength( 1 );
		expect( seams[ 0 ] ).toMatchObject( { fromId: 'b', toId: 'c', fromEdge: '#04132b', toEdge: '#e5e5e5' } );
	} );

	it( 'verifies the built band flow against the source bands', () => {
		const sections: ForgeSection[] = [
			{ id: 'a4ad586', bgImage: 'https://x.example/hero.jpg' },
			{ id: '5e78df1', bgGradient: 'linear-gradient(180deg, #ffffff 6%, #04132b 100%)', padding: '100px 0px 100px 0px' },
			{ id: 'dff3f1a', bgColor: '#e5e5e5' },
		];
		const html =
			'<div data-section-id="a4ad586" class="ds-section-wrap" style="background-image:url(\'https://x.example/hero.jpg\')"></div>' +
			'<div data-section-id="5e78df1" class="ds-section-wrap" style="background:linear-gradient(180deg, #ffffff 6%, #04132b 100%);padding:100px 0px 100px 0px"></div>';
		const report = verifyBandFlow( sections, html );
		expect( report.dropped ).toEqual( [ 'dff3f1a' ] );
		expect( report.mismatched ).toEqual( [] );
	} );

	it( 'flags built bands whose background drifted from the baked theme', () => {
		const html = '<div data-section-id="5e78df1" style="background:linear-gradient(180deg, #ffffff 6%, #111111 100%)"></div>';
		const report = verifyBandFlow( [ { id: '5e78df1', bgGradient: 'linear-gradient(180deg, #ffffff 6%, #04132b 100%)' } ], html );
		expect( report.dropped ).toEqual( [] );
		expect( report.mismatched ).toHaveLength( 1 );
	} );
} );

describe( 'analyzeDesignInput', () => {
	const design = analyzeDesignInput( { url: 'https://shop.example.com', html: DESIGN_HTML, css: [ ELEMENTOR_CSS ] } );

	it( 'assigns confident roles from the builder CSS vars (fail-loud + pool)', () => {
		expect( design.palette.roles.accent ).toBe( '#6ec1e4' );
		expect( design.palette.roles.text ).toBe( '#7a7a7a' );
		expect( design.palette.confidence.accent ).toBe( 'high' );
		expect( design.palette.unassigned.length ).toBeGreaterThan( 0 );
	} );

	it( 'extracts typography, scale, spacing, radii and shadows', () => {
		expect( design.typography.display ).toBe( 'rubik' );
		expect( design.typography.body ).toBe( 'roboto' );
		expect( design.typography.scalePx ).toContain( 40 );
		expect( design.typography.scalePx ).toContain( 16 );
		expect( design.spacing.scalePx ).toContain( 16 );
		expect( design.radii.valuesPx ).toContain( 5 );
		expect( design.shadows.count ).toBeGreaterThan( 0 );
	} );

	it( 'detects the component vocabulary and ships the adaptation brief', () => {
		expect( design.components.button ).toBeGreaterThan( 0 );
		expect( design.components.card ).toBeGreaterThan( 0 );
		expect( design.adaptation.keep.length ).toBeGreaterThan( 0 );
		expect( design.adaptation.replace.length ).toBeGreaterThan( 0 );
		expect( design.adaptation.remix.length ).toBeGreaterThan( 0 );
		expect( design.adaptation.attribution ).toContain( 'Source: https://shop.example.com' );
	} );

	it( 'defaults the concept to a deterministic summary', () => {
		// #6ec1e4 has hue ~203 → the cool bucket.
		expect( design.concept ).toContain( 'cool' );
	} );

	it( 'prefers the dominant CTA color over an untouched builder default primary (jayainc)', () => {
		const css = [
			':root { --e-global-color-primary: #6ec1e4; }',
			'a.button.aux-mikado-yellow { background-color: rgb(252, 205, 15); }',
			'a.button.aux-mikado-yellow.aux-fill:hover { background-color: rgb(223, 180, 3); }',
			'button.aux-mikado-yellow.aux-outline { background-color: transparent; }',
		].join( '\n' );
		const withCta = analyzeDesignInput( { url: 'https://jayainc.com', html: DESIGN_HTML, css: [ css ] } );
		expect( withCta.palette.roles.accent ).toBe( '#fccd0f' );
		expect( withCta.palette.confidence.accent ).toBe( 'high' );
	} );

	it( 'keeps a customized builder primary when the CSS carries no CTA color', () => {
		const css = ':root { --e-global-color-primary: #2b90d9; }';
		const customized = analyzeDesignInput( { url: 'https://x.example.com', html: DESIGN_HTML, css: [ css ] } );
		expect( customized.palette.roles.accent ).toBe( '#2b90d9' );
	} );

	it( 'extracts the CTA fill from the actual button elements (jayainc: yellow buttons, cyan accents)', () => {
		const html =
			'<div class="elementor-widget-button">' +
			'<a class="aux-button aux-mikado-yellow aux-curve">Read More</a>' +
			'<a class="aux-button aux-mikado-yellow aux-curve">Our Products</a>' +
			'<a class="aux-button aux-mikado-yellow aux-curve">ALL SERVICES</a>' +
			'</div>';
		const css = [
			'a.aux-button.aux-mikado-yellow { background-image: linear-gradient(98deg, rgb(255, 207, 14) 0%, rgb(255, 183, 14) 100%); color: white; }',
			':root { --e-global-color-primary: #6ec1e4; }',
			'.aux-ac-btn1 { background-color: rgb(27, 176, 206); }',
		].join( '\n' );
		const withCta = analyzeDesignInput( { url: 'https://jayainc.com', html, css: [ css ] } );
		// Element coverage: all three buttons wear the yellow gradient, so it
		// wins the CTA role even though the cyan rule exists in the CSS.
		expect( withCta.palette.roles.cta ).toBe( 'linear-gradient(98deg, rgb(255, 207, 14) 0%, rgb(255, 183, 14) 100%)' );
		const tokens = designToTokens( withCta );
		expect( tokens.cta ).toContain( 'linear-gradient' );
		expect( tokens[ 'cta-ink' ] ).toBe( '#111111' ); // dark ink on the yellow fill
	} );

	it( 'harvests the source button shape (padding + pill radius)', () => {
		const html = '<div><a class="aux-button aux-mikado-yellow aux-curve">Read More</a></div>';
		const css = [
			'a.aux-button.aux-mikado-yellow { background-color: rgb(252, 205, 15); padding: 14px 43px; border-radius: 1600px; }',
		].join( '\n' );
		const shaped = analyzeDesignInput( { url: 'https://jayainc.com', html, css: [ css ] } );
		const tokens = designToTokens( shaped );
		expect( tokens[ 'cta-pad' ] ).toBe( '14px 43px' );
		expect( tokens[ 'cta-radius' ] ).toBe( '999px' ); // a pill is a pill
	} );

	it( 'normalizes rem/em font sizes into the scale at the 16px root (117 §5)', () => {
		const css = [
			'.hero h2 { font-size: 4.5rem; }',
			'.section-title { font-size: 2.6875rem; }',
			'.card h4 { font-size: 1.75em; }',
			'p { font-size: 13px; }',
		].join( '\n' );
		const withRem = analyzeDesignInput( { url: 'https://x.example.com', html: DESIGN_HTML, css: [ css ] } );
		expect( withRem.typography.scalePx ).toContain( 72 );
		expect( withRem.typography.scalePx ).toContain( 43 );
		expect( withRem.typography.scalePx ).toContain( 28 );
		expect( withRem.typography.scalePx ).toContain( 13 );
	} );

	it( 'ranks the scale from the top so the display size becomes scale-h1', () => {
		const tokens = designToTokens( {
			...design,
			typography: { display: 'rubik', scalePx: [ 10, 11, 12, 13, 15, 16, 18, 28, 43, 72 ] },
		} );
		expect( tokens[ 'scale-h1' ] ).toBe( '72px' );
		expect( tokens[ 'scale-h2' ] ).toBe( '43px' );
		expect( tokens[ 'scale-h3' ] ).toBe( '28px' );
		expect( tokens[ 'scale-body' ] ).toBe( '13px' );
		expect( tokens[ 'scale-small' ] ).toBe( '10px' );
	} );

	it( 'survives a dense builder harvest (jayainc: 52 sizes) without body becoming a display size', () => {
		// The real jayainc harvest: a dense 52-value ladder. A bottom/top
		// 10-step slice kept only [8,9.6,10,11,64,72,80,82,96,128] — body
		// ranked to 64px. The band-aware ladder keeps each band's
		// representative instead.
		const dense = analyzeDesignInput( {
			url: 'https://jayainc.com',
			html: DESIGN_HTML,
			css: [
				[ 8, 9.6, 10, 11, 12, 12.8, 13, 13.6, 13.8, 14, 15, 16, 17.6, 18, 18.4, 20, 21, 22, 23, 23.2, 24, 25.6, 26, 27, 28, 29, 30, 31, 32, 35, 35.7, 36, 38, 38.4, 40, 42, 43, 45, 46.2, 48, 53, 54, 54.4, 55, 56, 62, 64, 72, 80, 82, 96, 128 ]
					.map( ( px ) => `.x${ px } { font-size: ${ px }px; }` )
					.join( '\n' ),
			],
		} );
		// The trimmed ladder keeps a representative of each band.
		expect( dense.typography.scalePx ).toContain( 13 ); // the body size
		expect( dense.typography.scalePx ).toContain( 128 ); // the banner size
		expect( dense.typography.scalePx.length ).toBeLessThanOrEqual( 10 );
		const tokens = designToTokens( dense );
		expect( tokens[ 'scale-body' ] ).toBe( '13px' ); // NEVER 64px
		expect( tokens[ 'scale-small' ] ).toBe( '10px' ); // NEVER 8px (micro size)
		expect( tokens[ 'scale-h1' ] ).not.toBe( '128px' ); // the banner is not the hero
		const h1 = parseFloat( tokens[ 'scale-h1' ] );
		expect( h1 ).toBeGreaterThanOrEqual( 60 );
		expect( h1 ).toBeLessThanOrEqual( 100 ); // the hero band
		expect( parseFloat( tokens[ 'scale-h2' ] ) ).toBeGreaterThanOrEqual( 36 );
		expect( parseFloat( tokens[ 'scale-h3' ] ) ).toBeGreaterThanOrEqual( 24 );
	} );

	it( 'keeps the spacing rhythm on a dense margin/padding harvest (never 104px space-4)', () => {
		// The real jayainc spacing harvest: 46 values. A bottom/top slice
		// kept [0,1,2,3] + [101,104,125,130,140,150] — space-4 baked as
		// 104px. Band representatives keep the rhythm.
		const dense = analyzeDesignInput( {
			url: 'https://jayainc.com',
			html: DESIGN_HTML,
			css: [
				[ 0.0001, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 18, 20, 24, 25, 28, 30, 32, 35, 36, 40, 48, 53, 54, 56, 62, 64, 72, 80, 82, 96, 101, 104, 125, 130, 140, 150 ]
					.map( ( px ) => `.x${ px } { margin: ${ px }px; }` )
					.join( '\n' ),
			],
		} );
		const tokens = designToTokens( dense );
		const s4 = parseFloat( tokens[ 'space-4' ] );
		const s5 = parseFloat( tokens[ 'space-5' ] );
		expect( s4 ).toBeGreaterThanOrEqual( 24 );
		expect( s4 ).toBeLessThanOrEqual( 40 ); // the lg band — never 104px
		expect( s5 ).toBeGreaterThanOrEqual( 48 );
		expect( s5 ).toBeLessThanOrEqual( 96 ); // the xl band
		expect( parseFloat( tokens[ 'space-3' ] ) ).toBeGreaterThanOrEqual( 14 );
	} );
} );

describe( 'designToTokens', () => {
	const design: ForgeDesign = {
		mode: 'rebuild',
		source: { url: 'https://shop.example.com', analyzedAt: '2026-11-05T00:00:00.000Z' },
		concept: 'cool accent over light neutrals',
		palette: {
			roles: { accent: '#6ec1e4', text: '#444444', surface: '#ffffff', 'field.border': '#cccccc' },
			unassigned: [ '#ffcf0e', '#242424' ],
			confidence: { accent: 'high', text: 'medium' },
		},
		typography: { display: 'Rubik', body: 'Roboto', scalePx: [ 14, 16, 20, 28, 40 ] },
		spacing: { scalePx: [ 4, 8, 16, 24, 48 ] },
		radii: { valuesPx: [ 4, 8 ] },
		shadows: { count: 2 },
		components: {},
		modes: { dark: 'auto', contrast: 'auto' },
		adaptation: { keep: [], replace: [], remix: [], licensingNote: '', attribution: [] },
	};

	it( 'maps roles onto the --forge-* keys with luminance-derived accent ink', () => {
		const tokens = designToTokens( design );
		expect( tokens.accent ).toBe( '#6ec1e4' );
		expect( tokens.ink ).toBe( '#444444' );
		expect( tokens.surface ).toBe( '#ffffff' );
		expect( tokens[ 'line-strong' ] ).toBe( '#cccccc' );
		expect( tokens[ 'accent-ink' ] ).toBe( '#111111' ); // light accent → dark ink
		expect( tokens[ 'font-display' ] ).toContain( 'Rubik' );
		expect( tokens.font ).toContain( 'Roboto' );
		expect( tokens[ 'scale-h1' ] ).toBe( '40px' );
		expect( tokens[ 'space-5' ] ).toBe( '48px' );
	} );
} );

describe( 'suggestContrastFixes', () => {
	it( 'suggests a passing color from the source\'s own unassigned pool (the #7a7a7a case)', () => {
		const design: ForgeDesign = {
			mode: 'rebuild',
			source: { url: 'https://jayainc.example', analyzedAt: '2026-11-05T00:00:00.000Z' },
			concept: 'cool accent over light neutrals',
			palette: {
				roles: { accent: '#6ec1e4', text: '#7a7a7a', surface: '#ffffff' },
				// The source's own neutrals include the AA-passing fix.
				unassigned: [ '#444444', '#3d3d3d', '#ffcf0e' ],
				confidence: { accent: 'high', text: 'high' },
			},
			typography: { scalePx: [ 16, 20 ] },
			spacing: { scalePx: [ 8, 16 ] },
			radii: { valuesPx: [ 4 ] },
			shadows: { count: 0 },
			components: {},
			modes: { dark: 'auto', contrast: 'auto' },
			adaptation: { keep: [], replace: [], remix: [], licensingNote: '', attribution: [] },
		};
		const fixes = suggestContrastFixes( design, [
			{ token: 'ink', label: 'body text (ink on surface)', ratio: 4.29, background: '#ffffff', required: 4.5 },
		] );
		expect( fixes ).toHaveLength( 1 );
		expect( fixes[ 0 ].suggested ).toBe( '#3d3d3d' ); // darkest candidate in the pool wins
		expect( fixes[ 0 ].suggestedRatio ).toBeGreaterThanOrEqual( 4.5 );
	} );

	it( 'leaves no suggestion when the pool cannot fix the pair', () => {
		const design: ForgeDesign = {
			mode: 'rebuild',
			source: { url: 'https://x.example', analyzedAt: '2026-11-05T00:00:00.000Z' },
			concept: 'c',
			palette: { roles: { text: '#ffffff' }, unassigned: [ '#eeeeee' ], confidence: {} },
			typography: { scalePx: [ 16 ] },
			spacing: { scalePx: [ 8 ] },
			radii: { valuesPx: [ 4 ] },
			shadows: { count: 0 },
			components: {},
			modes: { dark: 'auto', contrast: 'auto' },
			adaptation: { keep: [], replace: [], remix: [], licensingNote: '', attribution: [] },
		};
		const fixes = suggestContrastFixes( design, [
			{ token: 'ink', label: 'body text', ratio: 1, background: '#ffffff', required: 4.5 },
		] );
		expect( fixes[ 0 ].suggested ).toBeUndefined();
	} );
} );

describe( 'scoreParity + analyzeBuiltCss', () => {
	const source = analyzeDesignInput( { url: 'https://shop.example.com', html: '', css: [ ELEMENTOR_CSS ] } );

	it( 'scores a faithful rebuilt bundle high (roles + type survive the rebuild)', () => {
		// The built CSS the scaffold emits: the applied --forge-* token layer.
		const builtCss = `
			:root {
				--forge-accent: #6ec1e4;
				--forge-ink: #7a7a7a;
				--forge-surface: #ffffff;
				--forge-font-display: 'Rubik', system-ui;
				--forge-font: 'Roboto', system-ui;
				--forge-scale-h1: 40px;
				--forge-scale-body: 16px;
				--forge-space-lg: 16px;
			}
			body { font-family: var(--forge-font); }
		`;
		const built = analyzeBuiltCss( builtCss, 'https://shop.example.com' );
		const report = scoreParity( source, built, 0.6 );
		expect( report.metrics.roleDistance ).toBe( 0 );
		expect( report.metrics.familyOverlap ).toBe( 1 );
		expect( report.metrics.conceptMatch ).toBe( 1 );
		expect( report.pass ).toBe( true );
	} );

	it( 'fails a rebuilt bundle whose language diverged (different roles + type)', () => {
		const builtCss = `
			:root {
				--forge-accent: #ff0000;
				--forge-ink: #000000;
				--forge-surface: #ffffff;
				--forge-font-display: 'Georgia', serif;
			}
		`;
		const built = analyzeBuiltCss( builtCss, 'https://shop.example.com' );
		const report = scoreParity( source, built, 0.6 );
		expect( report.pass ).toBe( false );
		expect( report.metrics.roleDistance ).toBeGreaterThan( 0.35 );
		expect( report.metrics.familyOverlap ).toBe( 0 );
	} );

	it( 'honours the threshold', () => {
		const built = analyzeBuiltCss( ':root { --forge-accent: #6ec1e4; }', 'https://shop.example.com' );
		expect( scoreParity( source, built, 0.4 ).pass ).toBe( true );
		expect( scoreParity( source, built, 1 ).pass ).toBe( false );
	} );
} );

describe( 'analyzeChrome (Proposal 120 §2)', () => {
	const CHROME_HTML =
		// jayainc's shape: the topbar is a document-level SIBLING above the header.
		'<link rel="icon" href="/wp-content/uploads/cropped-jaya-inc-icon-32x32.png">' +
		'<link rel="stylesheet" id="auxin-front-icon-css" href="/wp-content/themes/phlox-pro/css/auxin-icon.css?ver=5.17.20">' +
		'<div id="top-header" class="aux-top-header">' +
		'<p>123 Harbour Street, Kingston</p>' +
		'<p>(876) 555-0100</p>' +
		'</div>' +
		'<header id="site-header" class="aux-master-header">' +
		'<div class="brand-block">' +
		'<img class="image1" src="/wp-content/uploads/JAYAINC-LOGO-V1-wht.svg" alt="">' +
		'<img class="image2" src="/wp-content/uploads/JAYAINC-LOGO-V1-blue.svg" alt="Jaya Inc">' +
		'<p>Distributors of quality building materials since 1985</p>' +
		'</div>' +
		'<nav>' +
		'<a href="#about">About</a>' +
		'<a href="#products">Products</a>' +
		'<a href="#services">Services</a>' +
		'<a href="#contact">Contact</a>' +
		'</nav>' +
		'<a href="tel:+18765550100" class="aux-call">CALL US</a>' +
		'</header>' +
		'<footer id="sitefooter">' +
		'<p>Jaya Inc supplies plumbing, electrical and hardware lines island-wide.</p>' +
		'<h3>Products</h3>' +
		'<ul><li>PVC Pipes</li><li>Copper Fittings</li></ul>' +
		'<h3>Contact</h3>' +
		'<table><tr><td>123 Harbour Street, Kingston</td></tr></table>' +
		'<small>© 2026 Jaya Inc. All rights reserved.</small>' +
		'</footer>';

	const chrome = analyzeChrome( { url: 'https://jayainc.com', html: CHROME_HTML, css: [] } );

	it( 'reads the topbar lines from the document-level sibling (never header-scoped)', () => {
		expect( chrome?.topbar ).toEqual( [ '123 Harbour Street, Kingston', '(876) 555-0100' ] );
	} );

	it( 'falls back to the paired dark variant when no surface resolves (124 §1)', () => {
		expect( chrome?.logo?.src ).toContain( 'JAYAINC-LOGO-V1-blue.svg' );
		expect( chrome?.logo?.alt ).toBe( 'Jaya Inc' );
	} );

	it( 'assigns the header logo variant by WCAG contrast against the header background (124 §1)', () => {
		const html =
			'<header id="site-header">' +
			'<img class="custom-logo aux-logo-image1" src="/wht-logo.svg" alt="Site">' +
			'<img class="custom-logo aux-logo-image2" src="/blue-logo.svg" alt="Site">' +
			'</header>';
		const css = [ '#site-header { background-color: rgb(4, 19, 43); }' ];
		const styled = analyzeChrome( {
			url: 'https://jayainc.com',
			html,
			css,
			logoColors: {
				'https://jayainc.com/wht-logo.svg': [ '#ffffff' ],
				'https://jayainc.com/blue-logo.svg': [ '#09162b' ],
			},
		} );
		// White on navy = ~15.5:1; the navy variant sits at ~1.07:1 — the
		// contrast picks the white logo, not the paired dark one.
		expect( styled?.logo?.src ).toContain( 'wht-logo.svg' );
	} );

	it( 'captures the :before overlay gradient as the effective footer background + the subfooter ink (124 §1)', () => {
		const html = '<aside class="aux-subfooter"><img class="custom-logo" src="/footer-logo.png" alt=""><p>Contact us</p></aside>' +
			'<footer id="sitefooter"><small>© 2026 Site</small></footer>';
		const css = [
			'.aux-subfooter { background-color: rgb(243, 243, 243); }',
			'.aux-subfooter:before { background-image: linear-gradient(107deg, #081a43 0%, #071230 100%); opacity: 0.92; }',
			'.aux-subfooter p { color: rgba(255, 255, 255, 0.7); }',
		].join( '\n' );
		const styled = analyzeChrome( { url: 'https://jayainc.com', html, css: [ css ] } );
		// The overlay paints ON TOP of the flat base — the navy gradient is
		// the effective fill, never the #f3f3f3 under it.
		expect( styled?.footerBg ).toBe( 'linear-gradient(107deg, #081a43 0%, #071230 100%)' );
		expect( styled?.footerInk ).toBe( '#ffffff' );
	} );

	it( 'assigns the footer logo variant by contrast against the footer surface (124 §1)', () => {
		const html =
			'<header id="site-header">' +
			'<img class="custom-logo aux-logo-image1" src="/wht-logo.svg" alt="Site">' +
			'<img class="custom-logo aux-logo-image2" src="/blue-logo.svg" alt="Site">' +
			'</header>' +
			'<aside class="aux-subfooter"><img src="/footer-logo.png" alt=""><p>Contact us</p></aside>' +
			'<footer id="sitefooter"><small>© 2026 Site</small></footer>';
		const css = [ '.aux-subfooter { background-color: rgb(4, 19, 43); }' ];
		const styled = analyzeChrome( {
			url: 'https://jayainc.com',
			html,
			css,
			logoColors: {
				'https://jayainc.com/wht-logo.svg': [ '#ffffff' ],
				'https://jayainc.com/blue-logo.svg': [ '#09162b' ],
				'https://jayainc.com/footer-logo.png': [ '#ffffff' ],
			},
		} );
		// Dark footer: the white variants clear it; the navy one does not.
		expect( styled?.footerLogo?.src ).toContain( 'footer-logo.png' );
	} );

	it( 'takes the longest header paragraph as the tagline (excluding topbar lines)', () => {
		expect( chrome?.tagline ).toBe( 'Distributors of quality building materials since 1985' );
	} );

	it( 'collects the labeled nav links and skips tel:/mailto', () => {
		expect( chrome?.nav ).toEqual( [
			{ label: 'About', href: '#about' },
			{ label: 'Products', href: '#products' },
			{ label: 'Services', href: '#services' },
			{ label: 'Contact', href: '#contact' },
		] );
	} );

	it( 'extracts the tel: call button', () => {
		expect( chrome?.call ).toEqual( { label: 'CALL US', href: 'tel:+18765550100' } );
	} );

	it( 'reads the call button colors from its class rules (the header CTA wears its own color)', () => {
		const html = '<header id="site-header"><a href="tel:+18765550100" class="aux-button aux-mikado-yellow aux-ac-btn1">CALL US</a></header>';
		const css = [
			'a.button.aux-mikado-yellow { background-color: rgb(252, 205, 15); color: white; }',
			'.aux-ac-btn1 { background-image: linear-gradient(98deg, rgb(255, 207, 14) 0%, rgb(255, 183, 14) 100%); }',
		].join( '\n' );
		const styled = analyzeChrome( { url: 'https://jayainc.com', html, css: [ css ] } );
		expect( styled?.call?.bg ).toBe( 'linear-gradient(98deg, rgb(255, 207, 14) 0%, rgb(255, 183, 14) 100%)' );
		expect( styled?.call?.ink ).toBe( '#ffffff' );
	} );

	it( 'resolves the chrome typography/shape facts through the specificity cascade (123)', () => {
		const html =
			'<header id="site-header" class="site-header-section aux-header-light">' +
			'<nav><a href="#about" class="aux-item-content">About</a></nav>' +
			'<a href="tel:+18765550100" class="aux-button aux-large aux-mikado-yellow aux-curve aux-ac-btn1">CALL US</a>' +
			'</header>' +
			'<footer id="sitefooter" class="aux-site-footer"><h3 class="widget-title">Products</h3><small>© 2026</small></footer>';
		const css = [
			'body { font-family: Rubik; font-size: 100%; font-weight: 300; }',
			'.aux-header-light { background-color: rgb(4, 19, 43); color: rgb(255, 255, 255); }',
			// A base gray rule EARLIER in the sheet — the later header-scoped
			// white rule must win for the nav ink.
			'.aux-top-header .aux-menu-depth-0 > .aux-item-content { color: rgb(80, 80, 80); }',
			'.site-header-section.aux-header-light .aux-menu-depth-0 > .aux-item-content { color: rgb(255, 255, 255); font-size: 16px; }',
			// The generic large size loses to the header-scoped large size.
			'a.button.aux-large { padding: 20px 35px; font-weight: 700; }',
			'.site-header-section .aux-button.aux-large { padding: 14px 43px; }',
			'.aux-mikado-yellow { color: rgb(36, 36, 36); font-weight: 400; text-transform: uppercase; }',
			'a.button.aux-curve { border-radius: 100em; }',
			'.aux-ac-btn1 { background-image: linear-gradient(98deg, rgb(255, 207, 14) 0%, rgb(255, 183, 14) 100%); }',
			'.aux-site-footer { background-color: rgba(8, 26, 67, 0.9); color: rgba(255, 255, 255, 0.7); }',
			'.aux-subfooter .widget-title { font-weight: 500; font-size: 29px; text-transform: capitalize; }',
		].join( '\n' );
		const styled = analyzeChrome( { url: 'https://jayainc.com', html, css: [ css ] } );
		expect( styled?.headerBg ).toBe( '#04132b' );
		expect( styled?.headerInk ).toBe( '#ffffff' );
		expect( styled?.navStyle ).toEqual( { size: '16px' } );
		expect( styled?.navInk ).toBe( '#ffffff' );
		expect( styled?.call?.ink ).toBe( '#242424' );
		expect( styled?.call?.style ).toMatchObject( { padding: '14px 43px', radius: '100em', weight: '400', transform: 'uppercase' } );
		expect( styled?.footerBarBg ).toBe( 'rgba(8, 26, 67, 0.9)' ); // alpha survives
		expect( styled?.footerBarInk ).toBe( 'rgba(255, 255, 255, 0.7)' ); // alpha preserved
		expect( styled?.footerHeadingStyle ).toEqual( { weight: '500', size: '29px', transform: 'capitalize' } );
		expect( styled?.chromeSize ).toBe( '16px' );
	} );

	it( 'resolves the chrome size from the body/html rules when the elements carry none (123)', () => {
		const html = '<header id="site-header"><nav><a href="#about" class="aux-item-content">About</a></nav></header>';
		const css = [ 'html { font-size: 16px; }', 'body { font-size: 100%; }' ].join( '\n' );
		const styled = analyzeChrome( { url: 'https://x.example.com', html, css: [ css ] } );
		// 100% of the html root rule — NEVER the rebuilt content body scale.
		expect( styled?.chromeSize ).toBe( '16px' );
	} );

	it( 'scopes footer blocks between consecutive headings with an intro block', () => {
		expect( chrome?.footerBlocks ).toEqual( [
			{ items: [ 'Jaya Inc supplies plumbing, electrical and hardware lines island-wide.' ] },
			{ title: 'Products', items: [ 'PVC Pipes', 'Copper Fittings' ] },
			{ title: 'Contact', items: [ '123 Harbour Street, Kingston' ] },
		] );
	} );

	it( 'reads the copyright from the small element', () => {
		expect( chrome?.copyright ).toBe( '© 2026 Jaya Inc. All rights reserved.' );
	} );

	it( 'captures the favicon link as an absolute URL', () => {
		expect( chrome?.favicon ).toBe( 'https://jayainc.com/wp-content/uploads/cropped-jaya-inc-icon-32x32.png' );
	} );

	it( 'captures the source icon-font stylesheet (never the favicon rel=icon)', () => {
		expect( chrome?.iconCss ).toBe( 'https://jayainc.com/wp-content/themes/phlox-pro/css/auxin-icon.css?ver=5.17.20' );
	} );

	it( 'returns undefined when the source exposes no recognizable chrome', () => {
		expect( analyzeChrome( { url: 'https://x.example.com', html: '<div><p>plain page</p></div>', css: [] } ) ).toBeUndefined();
	} );
} );

describe( 'analyzeScrollBehavior (Proposal 121)', () => {
	const BEHAVIOR_HTML =
		'<header id="site-header">' +
		'<img class="aux-logo-sticky" src="/logo.svg" alt="">' +
		'</header>' +
		'<section class="elementor-section elementor-top-section elementor-element elementor-element-a4ad586"><h2>Hero</h2></section>' +
		'<section class="elementor-section elementor-top-section elementor-element elementor-element-9999999">' +
		'<div class="aux-parallax-section elementor-column elementor-element elementor-element-ab8540a"></div>' +
		'</section>' +
		'<div class="elementor-element elementor-element-1111111 aux-appear-watch-animation aux-fade-in-up"><h3>Reveal up</h3></div>' +
		'<div class="elementor-element elementor-element-2222222 aux-appear-watch-animation aux-unknown-effect"><h3>Reveal unknown</h3></div>' +
		'<div class="elementor-element elementor-element-3333333" data-settings="{&quot;motion_fx_motion_fx_scrolling&quot;:&quot;yes&quot;,&quot;motion_fx_translateY_effect&quot;:&quot;yes&quot;,&quot;motion_fx_translateY_speed&quot;:{&quot;unit&quot;:&quot;px&quot;,&quot;size&quot;:2,&quot;sizes&quot;:[]}}"><h3>Motion parallax</h3></div>' +
		'<div class="elementor-element elementor-element-4444444"><h3>Sticky widget</h3></div>';
	const BEHAVIOR_CSS =
		'.elementor-element-4444444 { position: sticky; top: 20px; }';

	const pass = analyzeScrollBehavior( { url: 'https://jayainc.com', html: BEHAVIOR_HTML, css: [ BEHAVIOR_CSS ] } );

	it( 'flags the sticky header from the header logo class', () => {
		expect( pass.headerSticky ).toBe( true );
	} );

	it( 'classifies the theme parallax column and rolls it up to its top-level section', () => {
		// The parallax column (ab8540a) lives INSIDE a top-level section — the
		// rebuilt renderer only wraps top-level sections, so the behavior must
		// attach to the containing section id (9999999).
		const rolled = pass.entries.find( ( behavior ) => behavior.id === '9999999' );
		expect( rolled?.kind ).toBe( 'parallax' );
		expect( pass.entries.some( ( behavior ) => behavior.id === 'ab8540a' ) ).toBe( false );
	} );

	it( 'maps the aux reveal effect onto the canonical vocabulary', () => {
		const entry = pass.entries.find( ( behavior ) => behavior.id === '1111111' );
		expect( entry ).toEqual( { id: '1111111', kind: 'reveal', effect: 'fade-up' } );
	} );

	it( 'never drops an unmapped reveal effect — it degrades to fade', () => {
		const entry = pass.entries.find( ( behavior ) => behavior.id === '2222222' );
		expect( entry ).toEqual( { id: '2222222', kind: 'reveal', effect: 'fade' } );
	} );

	it( 'reads the Elementor motion_fx scrolling payload as parallax with its speed', () => {
		const entry = pass.entries.find( ( behavior ) => behavior.id === '3333333' );
		expect( entry ).toEqual( { id: '3333333', kind: 'parallax', speed: 2 } );
	} );

	it( 'classifies a sticky CSS rule scoped to the element id', () => {
		const entry = pass.entries.find( ( behavior ) => behavior.id === '4444444' );
		expect( entry ).toEqual( { id: '4444444', kind: 'sticky' } );
	} );

	it( 'returns an empty pass for a static page', () => {
		const empty = analyzeScrollBehavior( { url: 'https://x.example.com', html: '<div><p>static</p></div>', css: [] } );
		expect( empty.headerSticky ).toBe( false );
		expect( empty.entries ).toEqual( [] );
	} );
} );

describe( 'analyzeChrome — site-node ranking + footer widgets (jayainc shape)', () => {
	// The live jayainc shape: a post's entry-meta footer precedes the real
	// sitefooter, and the footer columns live in a subfooter ASIDE while the
	// <footer> element itself holds only the copyright.
	const HTML =
		'<article>' +
		'<header class="entry-header"><h1>A post</h1></header>' +
		'<footer class="entry-meta"><p>Posted in News</p></footer>' +
		'</article>' +
		'<header id="site-header">' +
		'<img class="image2" src="/logo.svg" alt="Site">' +
		'<nav><a href="#about">About</a></nav>' +
		'</header>' +
		'<aside class="subfooter aux-subfooter">' +
		'<div><img src="/footer-logo.png" alt="Site"><p>We serve the whole island.</p></div>' +
		'<section><h3>Products</h3><ul><li>Pipes</li><li>Valves</li></ul></section>' +
		'<section><h3>Contact</h3><table><tr><td>123 Harbour St</td></tr></table></section>' +
		'</aside>' +
		'<footer id="sitefooter"><small>© 2026 Site</small></footer>';

	const chrome = analyzeChrome( { url: 'https://jayainc.com', html: HTML, css: [ '.aux-subfooter { background-color: rgb(243, 243, 243); }' ] } );

	it( 'ignores the stray entry-meta footer and entry-header', () => {
		expect( chrome?.logo?.src ).toContain( '/logo.svg' );
		expect( chrome?.nav ).toEqual( [ { label: 'About', href: '#about' } ] );
	} );

	it( 'merges the subfooter widget aside into the footer blocks', () => {
		expect( chrome?.footerBlocks ).toEqual( [
			{ items: [ 'We serve the whole island.' ] },
			{ title: 'Products', items: [ 'Pipes', 'Valves' ] },
			{ title: 'Contact', items: [ '123 Harbour St' ] },
		] );
	} );

	it( 'reads the copyright strictly from the footer element', () => {
		expect( chrome?.copyright ).toBe( '© 2026 Site' );
	} );

	it( 'captures the footer brand logo and the footer background', () => {
		expect( chrome?.footerLogo?.src ).toBe( '/footer-logo.png' );
		expect( chrome?.footerBg ).toBe( '#f3f3f3' );
	} );
} );
