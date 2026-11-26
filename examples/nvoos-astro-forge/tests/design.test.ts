// SPDX-License-Identifier: MIT
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
	applyMeasuredHeaderActions,
	colorDistance,
	designToTokens,
	lightnessOf,
	normalizeColor,
	resolveSectionTheme,
	scoreParity,
	sourceHasThemeToggle,
	suggestContrastFixes,
	stylesheetLinks,
	verifyBandFlow,
} from '../lib/design.ts';
import type { ForgeChrome, ForgeDesign, ForgeSection } from '../lib/types.ts';

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
		expect( resolveSectionTheme( { id: 'c', bgColor: '#f6f6f6' } ) ).toBe( 'light' );
		expect( resolveSectionTheme( { id: 'd', bgColor: '#e5e5e5' } ) ).toBe( 'alt' );
	} );

	it( 'resolves a VERTICAL gradient from the stop its content sits on', () => {
		// jayainc's products band: white at the top where the 43px heading sits
		// (source ink rgb(36,36,36)), navy at the bottom. Classifying by the
		// terminal stop painted the title white-on-white.
		expect( resolveSectionTheme( { id: 'b', bgGradient: 'linear-gradient(180deg, #ffffff 6%, rgb(4, 19, 43) 100%)' } ) ).toBe( 'light' );
		// A navy-top sweep is dark, whichever spelling the source used.
		expect( resolveSectionTheme( { id: 'e', bgGradient: 'linear-gradient(to bottom, #04132b 0%, #ffffff 100%)' } ) ).toBe( 'dark' );
		expect( resolveSectionTheme( { id: 'f', bgGradient: 'linear-gradient(rgb(4, 19, 43), #ffffff)' } ) ).toBe( 'dark' );
		// `0deg` points UP, so the TOP stop is the LAST colour.
		expect( resolveSectionTheme( { id: 'g', bgGradient: 'linear-gradient(0deg, #ffffff 0%, #04132b 100%)' } ) ).toBe( 'dark' );
		// A horizontal sweep has no single content side — the terminal stop stays.
		expect( resolveSectionTheme( { id: 'h', bgGradient: 'linear-gradient(90deg, #ffffff 0%, #04132b 100%)' } ) ).toBe( 'dark' );
		// A radial gradient keeps the old heuristic too.
		expect( resolveSectionTheme( { id: 'i', bgGradient: 'radial-gradient(circle, #ffffff 0%, #04132b 100%)' } ) ).toBe( 'dark' );
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

	it( 'splits a container hero (e-con-inner / e-child, ≥3.16 flexbox)', () => {
		// Container-only sources (eca.directory) have no `.elementor-container`
		// and no `.elementor-column` — the legacy detector never split them.
		const html =
			'<div class="elementor-element elementor-element-c0f1e00 e-con e-parent">' +
			'<div class="e-con-inner">' +
			'<div class="elementor-element elementor-element-c0f1e01 e-con e-child">' +
			'<div class="elementor-element elementor-element-c0f1e02 elementor-widget elementor-widget-heading"><h2>Every child, every chance</h2></div>' +
			'<div class="elementor-element elementor-element-c0f1e03 elementor-widget elementor-widget-text-editor"><p>105 activities across seven strands.</p></div>' +
			'</div>' +
			'<div class="elementor-element elementor-element-c0f1e04 e-con e-child">' +
			'<div class="elementor-element elementor-element-c0f1e05 elementor-widget elementor-widget-image"><img src="https://eca.example/hero.jpg" alt="" /></div>' +
			'</div>' +
			'</div></div>';
		const sections = analyzeSections( { url: 'https://eca.example', html, css: [ '' ] } );
		expect( sections[ 0 ]?.id ).toBe( 'c0f1e00' );
		expect( sections[ 0 ]?.layout ).toBe( 'split' );
	} );

	it( 'counts a container hero body columns from its nested container', () => {
		const html =
			'<div class="elementor-element elementor-element-c0f2e00 e-con e-parent"><div class="e-con-inner">' +
			'<div class="elementor-element elementor-element-c0f2e01 e-con e-child">' +
			'<div class="elementor-element elementor-element-c0f2e02 elementor-widget elementor-widget-heading"><h2>About us</h2></div>' +
			'<div class="elementor-element elementor-element-c0f2e03 e-con e-child"><div class="e-con-inner">' +
			'<div class="elementor-element elementor-element-c0f2e04 e-con e-child"><p>one</p></div>' +
			'<div class="elementor-element elementor-element-c0f2e05 e-con e-child"><p>two</p></div>' +
			'</div></div>' +
			'</div>' +
			'<div class="elementor-element elementor-element-c0f2e06 e-con e-child">' +
			'<div class="elementor-element elementor-element-c0f2e07 elementor-widget elementor-widget-image"><img src="https://eca.example/about.jpg" alt="" /></div>' +
			'</div>' +
			'</div></div>';
		const sections = analyzeSections( { url: 'https://eca.example', html, css: [ '' ] } );
		expect( sections[ 0 ]?.layout ).toBe( 'split' );
		expect( sections[ 0 ]?.bodyColumns ).toBe( 2 );
	} );

	it( 'treats a titled icon-box as container copy, not media', () => {
		const html =
			'<div class="elementor-element elementor-element-c0f3e00 e-con e-parent"><div class="e-con-inner">' +
			'<div class="elementor-element elementor-element-c0f3e01 e-con e-child">' +
			'<div class="elementor-element elementor-element-c0f3e02 elementor-widget elementor-widget-icon-box">' +
			'<div class="elementor-icon-box-wrapper"><div class="elementor-icon-box-content"><h3 class="elementor-icon-box-title">A world of choice</h3><p class="elementor-icon-box-description">Seven strands.</p></div></div>' +
			'</div></div>' +
			'<div class="elementor-element elementor-element-c0f3e03 e-con e-child">' +
			'<div class="elementor-element elementor-element-c0f3e04 elementor-widget elementor-widget-image"><img src="https://eca.example/choice.jpg" alt="" /></div>' +
			'</div>' +
			'</div></div>';
		const sections = analyzeSections( { url: 'https://eca.example', html, css: [ '' ] } );
		expect( sections[ 0 ]?.layout ).toBe( 'split' );
	} );

	it( 'accepts a container lane background photo as the media column', () => {
		// An image-less lane with its own background URL is still the media side.
		const html =
			'<div class="elementor-element elementor-element-c0f4e00 e-con e-parent"><div class="e-con-inner">' +
			'<div class="elementor-element elementor-element-c0f4e01 e-con e-child">' +
			'<div class="elementor-element elementor-element-c0f4e02 elementor-widget elementor-widget-heading"><h2>Join us</h2></div>' +
			'</div>' +
			'<div class="elementor-element elementor-element-c0f4e03 e-con e-child" style="background-image:url(https://eca.example/photo.jpg)"></div>' +
			'</div></div>';
		const sections = analyzeSections( { url: 'https://eca.example', html, css: [ '' ] } );
		expect( sections[ 0 ]?.layout ).toBe( 'split' );
	} );

	it( 'ignores bands inside the header/footer chrome (they are not page content)', () => {
		// Elementor renders its header/footer templates into the homepage, but the
		// REST page body the build uses EXCLUDES them. Counting those bands as
		// content made the profile carry 9 bands where the build renders 7, and
		// every positional audit compared band N with band N+2.
		const html =
			'<header class="elementor elementor-86 elementor-location-header">' +
			'<div class="elementor-element elementor-element-44d664a5 e-con e-parent"><div class="e-con-inner"><h2>Menu</h2></div></div>' +
			'</header>' +
			'<div class="elementor-element elementor-element-6daaaef9 e-con e-parent"><div class="e-con-inner"><h2>Content</h2></div></div>' +
			'<footer class="elementor elementor-95 elementor-location-footer">' +
			'<div class="elementor-element elementor-element-14eecb83 e-con e-parent"><div class="e-con-inner"><h2>Footer</h2></div></div>' +
			'</footer>';
		// A band is only a SECTION when it carries a fact (a surface, measure,
		// columns…), so give each one a background rule.
		const css = [
			'.elementor-element-44d664a5 { background-color: #eeeeee; }',
			'.elementor-element-6daaaef9 { background-color: #ffffff; }',
			'.elementor-element-14eecb83 { background-color: #dddddd; }',
		].join( '\n' );
		const sections = analyzeSections( { url: 'https://eca.example', html, css: [ css ] } );

		expect( sections.map( ( section ) => section.id ) ).toEqual( [ '6daaaef9' ] );
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

	it( 'reads bands whose attributes are emitted in the renderer order', () => {
		// The real renderer emits data-section BEFORE data-section-id, with the
		// style last (the scoped-CSS output). A positional regex expecting
		// data-section-id straight after `<div` matches nothing, so every band
		// is reported as dropped.
		const html =
			'<div data-section="feature-grid" data-section-id="5e78df1" id="products" class="ds-section-wrap ds-section-wrap--dark" style="padding:100px 0px 100px 0px;background:linear-gradient(180deg, #ffffff 6%, #04132b 100%)"></div>';
		const report = verifyBandFlow(
			[ { id: '5e78df1', bgGradient: 'linear-gradient(180deg, #ffffff 6%, #04132b 100%)', padding: '100px 0px 100px 0px' } ],
			html
		);
		expect( report.dropped ).toEqual( [] );
		expect( report.mismatched ).toEqual( [] );
	} );

	it( 'keeps the outermost wrapper when a nested element repeats the band id', () => {
		const html =
			'<div data-section="hero" data-section-id="a" style="background-image:url(\'https://x.example/hero.jpg\')">' +
			'<div data-section="hero" data-section-id="a" class="ds-inner"></div></div>';
		const report = verifyBandFlow( [ { id: 'a', bgImage: 'https://x.example/hero.jpg' } ], html );
		expect( report.mismatched ).toEqual( [] );
	} );

	it( 'accepts a localized band image (Proposal 139)', () => {
		// The localizer rewrites the source URL to /media/<hash>-<slug>.<ext>;
		// the report it writes is the map that keeps this gate meaningful.
		const expected = 'https://cms.example/wp-content/uploads/2018/11/hero.jpg';
		const html = '<div data-section-id="a4ad586" style="background-image:url(\'/media/6023da595fed-hero.jpg\')"></div>';
		const localized = new Map( [ [ expected, '/media/6023da595fed-hero.jpg' ] ] );

		expect( verifyBandFlow( [ { id: 'a4ad586', bgImage: expected } ], html, localized ).mismatched ).toEqual( [] );
		// Without the map the same build is a genuine mismatch.
		expect( verifyBandFlow( [ { id: 'a4ad586', bgImage: expected } ], html ).mismatched ).toHaveLength( 1 );
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

	it( 'prefers the measured reading size over the px ladder (bodyPx fact)', () => {
		// A source that inherits its body size (relative 100%/1rem) leaves 16px
		// out of the harvested ladder, so the band heuristic picks 13px.
		const noisy = { display: 'rubik', scalePx: [ 1, 8, 8.8, 13, 29, 48, 80, 128 ] };
		expect( designToTokens( { ...design, typography: noisy } )[ 'scale-body' ] ).toBe( '13px' );
		expect( designToTokens( { ...design, typography: { ...noisy, bodyPx: 16 } } )[ 'scale-body' ] ).toBe( '16px' );
	} );

	it( 'ignores an implausible bodyPx and falls back to the ladder', () => {
		const noisy = { display: 'rubik', scalePx: [ 1, 8, 8.8, 13, 29, 48, 80, 128 ] };
		expect( designToTokens( { ...design, typography: { ...noisy, bodyPx: 48 } } )[ 'scale-body' ] ).toBe( '13px' );
		expect( designToTokens( { ...design, typography: { ...noisy, bodyPx: 4 } } )[ 'scale-body' ] ).toBe( '13px' );
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

describe( 'sourceHasThemeToggle — is the theme control the SOURCE has, not ours', () => {
	it( 'returns false for a page that never mentions a toggle', () => {
		expect( sourceHasThemeToggle( '<html><body><header><nav><a href="/">Home</a></nav></header></body></html>' ) ).toBe( false );
	} );

	it( 'recognises the common toggle namings', () => {
		const shapes = [
			'<button data-theme-toggle></button>',
			'<button class="theme-toggle"></button>',
			'<button class="dark-mode-switch"></button>',
			'<a class="color-mode-toggle" href="#">x</a>',
			'<button aria-label="Dark mode"></button>',
			'<button>Colour scheme</button>',
			'<div class="wp-dark-mode-switch"></div>',
		];
		for ( const shape of shapes ) {
			expect( sourceHasThemeToggle( `<body>${ shape }</body>` ), shape ).toBe( true );
		}
	} );

	it( 'does NOT mistake a layout class for a toggle', () => {
		for ( const shape of [
			'<header class="theme-header"></header>',
			'<div class="color-mode-banner"></div>',
			'<p>We mention dark mode in passing here, at some length.</p>',
		] ) {
			expect( sourceHasThemeToggle( `<body>${ shape }</body>` ), shape ).toBe( false );
		}
	} );

	it( 'ignores a long paragraph that happens to name a colour scheme', () => {
		const text = 'Our colour scheme choices are described at length in this paragraph of body copy.';
		expect( sourceHasThemeToggle( `<body><p>${ text }</p></body>` ) ).toBe( false );
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

	it( 'associates a footer logo with the first block when they share a column (brand column)', () => {
		// jayainc's subfooter: the logo is the FIRST widget of column 1, directly
		// above the intro paragraph (logo y=5803, paragraph y=5900, same
		// `.aux-widget-area`). Rendering it as a standalone strip above the grid
		// gave it a section of its own.
		const html =
			'<aside class="aux-subfooter"><div class="aux-row">' +
			'<div class="aux-widget-area aux-1-2"><img class="wp-image-1" src="/footer-logo.png" alt=""><p>Your needs are our top priority and we guarantee the highest quality of support.</p></div>' +
			'<div class="aux-widget-area aux-1-4"><h3>Products</h3><p>Consumer Electronics</p></div>' +
			'<div class="aux-widget-area aux-1-4"><h3>Contact</h3><p>Phone: +1 (305) 500-9319</p></div>' +
			'</div></aside>' +
			'<footer id="sitefooter"><small>© 2026 Site</small></footer>';
		const chrome = analyzeChrome( { url: 'https://jayainc.com', html, css: [] } );
		expect( chrome?.footerLogo?.src ).toContain( 'footer-logo.png' );
		expect( chrome?.footerLogo?.blockIndex ).toBe( 0 );
	} );

	it( 'leaves the logo standalone when it does not share the first block’s column', () => {
		const html =
			'<aside class="aux-subfooter"><img src="/footer-logo.png" alt="">' +
			'<div class="aux-row"><div class="aux-widget-area aux-1-2"><p>Intro copy long enough to become a block item.</p></div>' +
			'<div class="aux-widget-area aux-1-4"><h3>Products</h3><p>Consumer Electronics</p></div></div></aside>' +
			'<footer id="sitefooter"><small>© 2026 Site</small></footer>';
		const chrome = analyzeChrome( { url: 'https://jayainc.com', html, css: [] } );
		expect( chrome?.footerLogo?.src ).toContain( 'footer-logo.png' );
		expect( chrome?.footerLogo?.blockIndex ).toBeUndefined();
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

	it( 'resolves a rem body size against the html root rule', () => {
		// `1.125rem` ends in "em", and the anchored `em` pattern declines it — so
		// before the `rem` branch this declaration resolved against NOTHING and the
		// chain fell through to the element resolution the function avoids
		// (monicarajiv, 2026-11-21).
		const html = '<header id="site-header"><nav><a href="#about" class="aux-item-content">About</a></nav></header>';
		const css = [ 'html { font-size: 16px; }', 'body { font-size: 1.125rem; }' ].join( '\n' );
		const styled = analyzeChrome( { url: 'https://x.example.com', html, css: [ css ] } );
		expect( styled?.chromeSize ).toBe( '18px' );
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

	it( 'never invents a mobile disclosure the source does not ship', () => {
		// jayainc's header carries no hamburger; the rebuild must not add one.
		expect( chrome?.navToggle ).toBeUndefined();
	} );

	it( 'reads a builder hamburger and its accessible name', () => {
		// virtuallyjamaica's Brando header: Bootstrap 3's navbar-toggle, whose
		// name is its visible text. The rebuild has to ship a disclosure too, or
		// six links wrap over three rows at 390px.
		const html =
			'<header id="site-header">' +
			'<nav><a href="#about">About</a><a href="#contact">Contact</a></nav>' +
			'<button type="button" class="navbar-toggle collapsed">Toggle navigation</button>' +
			'</header>';
		const withToggle = analyzeChrome( { url: 'https://x.example.com', html, css: [] } );

		expect( withToggle?.navToggle?.label ).toBe( 'Toggle navigation' );
		// Visibility is a browser fact — never guessed from the markup.
		expect( withToggle?.navToggle?.visible ).toBeUndefined();
	} );

	it( 'prefers the aria-label over the button text', () => {
		const html =
			'<header id="site-header">' +
			'<nav><a href="#about">About</a></nav>' +
			'<button type="button" class="menu-toggle" aria-label="Open menu"><span class="bars"></span></button>' +
			'</header>';
		const withToggle = analyzeChrome( { url: 'https://x.example.com', html, css: [] } );

		expect( withToggle?.navToggle?.label ).toBe( 'Open menu' );
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
	const MOTION_SETTINGS =
		'&quot;motion_fx_motion_fx_scrolling&quot;:&quot;yes&quot;,&quot;motion_fx_translateY_effect&quot;:&quot;yes&quot;,&quot;motion_fx_translateY_direction&quot;:&quot;negative&quot;,&quot;motion_fx_translateY_speed&quot;:{&quot;unit&quot;:&quot;px&quot;,&quot;size&quot;:2,&quot;sizes&quot;:[]},&quot;motion_fx_translateY_affectedRange&quot;:{&quot;unit&quot;:&quot;%&quot;,&quot;size&quot;:&quot;&quot;,&quot;sizes&quot;:{&quot;start&quot;:10,&quot;end&quot;:90}},&quot;motion_fx_devices&quot;:[&quot;desktop&quot;,&quot;tablet&quot;,&quot;mobile&quot;]';
	const BEHAVIOR_HTML =
		'<header id="site-header">' +
		'<img class="aux-logo-sticky" src="/logo.svg" alt="">' +
		'</header>' +
		'<section class="elementor-section elementor-top-section elementor-element elementor-element-a4ad586"><h2>Hero</h2></section>' +
		// A bare Phlox container is NOT motion: AvertaParallaxBox returns when it
		// has no `aux-parallax-piece` targets (jayainc has 24 of these markers).
		'<section class="elementor-section elementor-top-section elementor-element elementor-element-8888888">' +
		'<div class="aux-parallax-section elementor-column elementor-element elementor-element-ba4e001"></div>' +
		'</section>' +
		// A real moving collage: a top section containing an INNER section whose
		// pieces must roll up to the TOP section id (9999999), not the inner one.
		'<section class="elementor-section elementor-top-section elementor-element elementor-element-9999999">' +
		'<div class="elementor-section elementor-inner-section elementor-element elementor-element-1a2b3c4">' +
		'<div class="aux-parallax-section elementor-column elementor-element elementor-element-ab8540a">' +
		'<div class="aux-parallax-piece elementor-element elementor-element-a10001" data-parallax-origin="bottom" data-parallax-depth="-0.1" data-parallax-off="1024" data-settings="{' +
		MOTION_SETTINGS +
		'}"><img src="/a.jpg" alt=""></div>' +
		'<div class="aux-parallax-piece elementor-element elementor-element-a10002" data-parallax-origin="bottom" data-parallax-depth="0.1" data-parallax-off="1024"><img src="/b.jpg" alt=""></div>' +
		'</div>' +
		'</div>' +
		'<div class="aux-parallax-piece elementor-element elementor-element-a10003" data-parallax-depth="0.1"><img src="/c.jpg" alt=""></div>' +
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

	it( 'does NOT treat a bare aux-parallax-section container as parallax', () => {
		// The container is a candidate; the theme only activates it when it finds
		// `aux-parallax-piece` targets. jayainc has 24 containers, 2 real pieces.
		expect( pass.entries.some( ( behavior ) => behavior.id === '8888888' ) ).toBe( false );
	} );

	it( 'rolls the inner-section pieces up to the top-level band with their facts', () => {
		const rolled = pass.entries.find( ( behavior ) => behavior.id === '9999999' );
		expect( rolled?.kind ).toBe( 'parallax' );
		expect( rolled?.speed ).toBe( 2 );
		expect( pass.entries.some( ( behavior ) => behavior.id === '1a2b3c4' ) ).toBe( false );
		expect( pass.entries.some( ( behavior ) => behavior.id === 'ab8540a' ) ).toBe( false );
		expect( rolled?.pieces ).toHaveLength( 3 );
	} );

	it( 'captures Phlox depth/origin/off + Elementor direction/speed/range/devices per piece', () => {
		const entry = pass.entries.find( ( behavior ) => behavior.id === '9999999' );
		const first = entry?.pieces?.[ 0 ];
		expect( first ).toMatchObject( {
			id: 'a10001',
			src: '/a.jpg',
			index: 0,
			depth: -0.1,
			origin: 'bottom',
			off: 1024,
			direction: 'negative',
			speed: 2,
			range: { start: 10, end: 90 },
			devices: [ 'desktop', 'tablet', 'mobile' ],
		} );
		const second = entry?.pieces?.[ 1 ];
		expect( second ).toMatchObject( { id: 'a10002', src: '/b.jpg', index: 1, depth: 0.1, origin: 'bottom', off: 1024 } );
		expect( second?.direction ).toBeUndefined();
	} );

	it( 'records a HORIZONTAL motion_fx piece as axis x', () => {
		// Pre-2026-11 this collapsed into a VERTICAL drift: the analyzer read the
		// translateX settings but never recorded which axis produced them.
		const html =
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-7777777">' +
			'<div class="elementor-element elementor-element-5555555" data-settings="{&quot;motion_fx_motion_fx_scrolling&quot;:&quot;yes&quot;,&quot;motion_fx_translateX_effect&quot;:&quot;yes&quot;,&quot;motion_fx_translateX_direction&quot;:&quot;negative&quot;,&quot;motion_fx_translateX_speed&quot;:{&quot;unit&quot;:&quot;px&quot;,&quot;size&quot;:3,&quot;sizes&quot;:[]}}"><img src="/x.jpg" alt=""></div>' +
			'</section>';

		const horizontal = analyzeScrollBehavior( { url: 'https://jayainc.com', html, css: [] } );
		const piece = horizontal.entries.find( ( entry ) => entry.kind === 'parallax' )?.pieces?.[ 0 ];

		expect( piece ).toMatchObject( { axis: 'x', direction: 'negative', speed: 3 } );
	} );

	it( 'prefers the VERTICAL reading when both axes are enabled', () => {
		// Keeping translateY first is what leaves every pre-existing site's
		// generated facts byte-identical.
		const html =
			'<section class="elementor-section elementor-top-section elementor-element elementor-element-7777778">' +
			'<div class="elementor-element elementor-element-5555556" data-settings="{&quot;motion_fx_motion_fx_scrolling&quot;:&quot;yes&quot;,&quot;motion_fx_translateY_effect&quot;:&quot;yes&quot;,&quot;motion_fx_translateY_speed&quot;:{&quot;unit&quot;:&quot;px&quot;,&quot;size&quot;:1,&quot;sizes&quot;:[]},&quot;motion_fx_translateX_effect&quot;:&quot;yes&quot;,&quot;motion_fx_translateX_speed&quot;:{&quot;unit&quot;:&quot;px&quot;,&quot;size&quot;:4,&quot;sizes&quot;:[]}}"><img src="/y.jpg" alt=""></div>' +
			'</section>';

		const both = analyzeScrollBehavior( { url: 'https://jayainc.com', html, css: [] } );
		const piece = both.entries.find( ( entry ) => entry.kind === 'parallax' )?.pieces?.[ 0 ];

		expect( piece?.axis ).toBeUndefined();
		expect( piece?.speed ).toBe( 1 );
	} );

	it( 'maps the aux reveal effect onto the canonical vocabulary', () => {
		const entry = pass.entries.find( ( behavior ) => behavior.id === '1111111' );
		expect( entry ).toEqual( { id: '1111111', kind: 'reveal', effect: 'fade-up' } );
	} );

	it( 'never drops an unmapped reveal effect — it degrades to fade', () => {
		const entry = pass.entries.find( ( behavior ) => behavior.id === '2222222' );
		expect( entry ).toEqual( { id: '2222222', kind: 'reveal', effect: 'fade' } );
	} );

	it( 'reads the Elementor motion_fx scrolling payload as a piece with its speed', () => {
		const entry = pass.entries.find( ( behavior ) => behavior.id === '3333333' );
		expect( entry?.kind ).toBe( 'parallax' );
		expect( entry?.speed ).toBe( 2 );
		expect( entry?.pieces ).toHaveLength( 1 );
		expect( entry?.pieces?.[ 0 ] ).toMatchObject( { id: '3333333', speed: 2 } );
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

describe( 'analyzeSections — the band MARK STRIP (ForgeSection.logos)', () => {
	// A builder's image carousel publishes each logo as a `background-image`
	// DIV, so no `<img>`-reading classifier can see the wall, and the inline
	// `style` carrying the URL never survives sanitisation. The fact has to be
	// measured against the raw source HTML.
	const carouselHtml = ( extra = '' ) =>
		'<div class="elementor">' +
		'<section id="hardware" class="elementor-section elementor-top-section elementor-element elementor-element-a5d0ddc">' +
		'<h2>Supported hardware</h2>' +
		extra +
		'<div class="elementor-carousel-image" style="background-image:url(\'https://src.test/1.png\')"></div>' +
		'<div class="elementor-carousel-image" style="background-image:url(\'https://src.test/2.png\')"></div>' +
		'<div class="elementor-carousel-image" style="background-image:url(\'https://src.test/3.png\')"></div>' +
		'<div class="elementor-carousel-image" style="background-image:url(\'https://src.test/1.png\')"></div>' +
		'</section>' +
		'</div>';

	const sectionOf = ( html: string ) =>
		analyzeSections( { url: 'https://src.test', html, css: [] } ).find( ( s ) => s.id === 'a5d0ddc' );

	it( 'records the background-image slides of a logo wall, deduped', () => {
		expect( sectionOf( carouselHtml() )?.logos ).toEqual( [
			{ src: 'https://src.test/1.png' },
			{ src: 'https://src.test/2.png' },
			{ src: 'https://src.test/3.png' },
		] );
	} );

	it( 'records <img> marks too, keeping the alt', () => {
		const html =
			'<div class="elementor">' +
			'<section id="hardware" class="elementor-section elementor-top-section elementor-element elementor-element-a5d0ddc">' +
			'<img src="https://src.test/a.png" alt="TP-Link" /><img src="https://src.test/b.png" alt="Linksys" /><img src="https://src.test/c.png" />' +
			'</section></div>';
		expect( sectionOf( html )?.logos ).toEqual( [
			{ src: 'https://src.test/a.png', alt: 'TP-Link' },
			{ src: 'https://src.test/b.png', alt: 'Linksys' },
			{ src: 'https://src.test/c.png' },
		] );
	} );

	it( 'ignores a band that carries prose (a copy panel is not a mark strip)', () => {
		expect( sectionOf( carouselHtml( '<p>Choose the access point that works best.</p>' ) )?.logos ).toBeUndefined();
	} );

	it( 'ignores fewer than three marks (one illustration is not a logo wall)', () => {
		const html =
			'<div class="elementor">' +
			'<section id="hardware" class="elementor-section elementor-top-section elementor-element elementor-element-a5d0ddc">' +
			'<img src="https://src.test/a.png" /><img src="https://src.test/b.png" />' +
			'</section></div>';
		expect( sectionOf( html )?.logos ).toBeUndefined();
	} );

	it( 'drops non-http marks instead of emitting them', () => {
		const html =
			'<div class="elementor">' +
			'<section id="hardware" class="elementor-section elementor-top-section elementor-element elementor-element-a5d0ddc">' +
			'<img src="data:image/png;base64,AAAA" /><img src="/relative.png" />' +
			'<img src="https://src.test/a.png" /><img src="https://src.test/b.png" /><img src="https://src.test/c.png" />' +
			'</section></div>';
		expect( sectionOf( html )?.logos ).toEqual( [
			{ src: 'https://src.test/a.png' },
			{ src: 'https://src.test/b.png' },
			{ src: 'https://src.test/c.png' },
		] );
	} );
} );

describe( 'analyzeSections — per-column PANEL surfaces (ForgeSectionColumn)', () => {
	// The source paints a coloured panel with `var(--e-global-color-accent)` on the
	// column's populated wrapper — a stylesheet the rebuild never ships. The band
	// fact has to carry the LITERAL, or the panel cannot be painted at all.
	const band = ( columns: string ) =>
		'<div class="elementor">' +
		'<section id="wifi" class="elementor-section elementor-top-section elementor-element elementor-element-8d6ec26">' +
		'<div class="elementor-container">' +
		columns +
		'</div></section></div>';

	const column = ( id: string ) =>
		`<div class="elementor-column elementor-col-50 elementor-element elementor-element-${ id }">` +
		'<div class="elementor-widget-wrap elementor-element-populated"><h2>Copy</h2></div></div>';

	const ROOT = ':root{ --e-global-color-accent: #dd3333; }';
	const accentRule = ( id: string ) =>
		`.elementor-5609 .elementor-element.elementor-element-${ id } > .elementor-widget-wrap, ` +
		`.elementor-5609 .elementor-element.elementor-element-${ id } > .elementor-element-populated { background-color: var( --e-global-color-accent ); }`;

	const sectionOf = ( html: string, css: string ) =>
		analyzeSections( { url: 'https://src.test', html, css: [ css ] } ).find( ( s ) => s.id === '8d6ec26' );

	it( 'bakes the resolved column surface and derives the panel ink', () => {
		const found = sectionOf( band( column( '1111111' ) + column( '2222222' ) ), ROOT + accentRule( '2222222' ) );

		expect( found?.columns?.[ 1 ].bgColor ).toBe( '#dd3333' );
		expect( found?.columns?.[ 0 ].bgColor ).toBeUndefined();
		expect( found?.panel ).toEqual( { bg: '#dd3333', ink: '#ffffff' } );
	} );

	it( 'records a photo column as a surface and then refuses the uniform panel', () => {
		const html = band(
			'<div class="elementor-column elementor-col-50 elementor-element elementor-element-1111111">' +
				'<div class="elementor-widget-wrap elementor-element-populated" style="background-image:url(https://src.test/phone.jpg)"><h2>Copy</h2></div></div>' +
				column( '2222222' )
		);
		const found = sectionOf( html, accentRule( '2222222' ) );

		expect( found?.columns?.[ 0 ].bgImage ).toBe( 'https://src.test/phone.jpg' );
		// A photo column beside a coloured one is a SPLIT panel, not a slab.
		expect( found?.panel ).toBeUndefined();
	} );

	it( 'refuses the panel when two columns carry DIFFERENT surfaces', () => {
		const html = band( column( '1111111' ) + column( '2222222' ) + column( '3333333' ) );
		const css = ROOT + accentRule( '2222222' ) + '.elementor-element-3333333 > .elementor-element-populated { background-color: #000000; }';
		const found = sectionOf( html, css );

		expect( found?.columns?.[ 1 ].bgColor ).toBe( '#dd3333' );
		expect( found?.columns?.[ 2 ].bgColor ).toBe( '#000000' );
		// Per-column panels are not paintable on the flat rebuilt markup.
		expect( found?.panel ).toBeUndefined();
	} );
} );

describe( 'analyzeChrome — footer LINES that carry LINKS (virtuallysrilanka)', () => {
	// A Kadence footer publishes the © statement as ONE paragraph with only the
	// credit anchored. Flattening the line to its text printed the credit as
	// dead copy — the rebuild could not tell the line had ever been a link.
	const HTML =
		'<footer id="colophon" class="site-footer">' +
		'<div class="site-middle-footer-wrap"><div class="site-footer-row site-footer-row-columns-3">' +
		'<div class="site-footer-middle-section-1"><div class="footer-widget-area footer-social">' +
		'<a href="" aria-label="Facebook" class="social-button"><svg></svg></a>' +
		'<a href="" aria-label="Twitter" class="social-button"><svg></svg></a>' +
		'</div></div>' +
		'<div class="site-footer-middle-section-3">' +
		'<section id="media_image-3" class="widget widget_media_image">' +
		'<img src="https://src.test/wp-content/uploads/2019/01/VJ-Sri-Lanka-Logo-V5-outline.svg" class="image wp-image-5511 attachment-full size-full" alt="" />' +
		'</section></div></div></div>' +
		'<div class="site-bottom-footer-wrap"><div class="footer-html-inner"><p>' +
		'&copy; 2026 VIRTUALLY SRI LANKA IS POWERED BY ' +
		'<a class="gray-text" href="http://www.nvdigitalsolutions.com/" target="_blank" rel="noopener">NV DIGITAL SOLUTIONS.</a>' +
		'</p></div></div>' +
		'</footer>';

	const chrome = analyzeChrome( { url: 'https://virtuallysrilanka.com', html: HTML, css: [] } );

	it( 'keeps the line a string and records its anchor separately', () => {
		expect( chrome?.footerBlocks ).toEqual( [
			{ items: [ '© 2026 VIRTUALLY SRI LANKA IS POWERED BY NV DIGITAL SOLUTIONS.' ] },
		] );
		expect( chrome?.footerLinks ).toEqual( [
			{ text: 'NV DIGITAL SOLUTIONS.', href: 'http://www.nvdigitalsolutions.com/' },
		] );
	} );

	it( 'takes the footer mark from a media widget INSIDE the <footer>', () => {
		// The mark carries no logo class (`image wp-image-5511 …`) and this footer
		// has no footer-widget <aside>, so the aside-scoped pool never saw it.
		expect( chrome?.footerLogo?.src ).toContain( 'VJ-Sri-Lanka-Logo-V5-outline.svg' );
	} );

	it( 'does not treat an href-less social badge as a footer LINE link', () => {
		expect( chrome?.footerLinks?.every( ( link ) => link.href.length > 0 ) ).toBe( true );
	} );

	it( 'ignores a fragment anchor and a link outside every captured line', () => {
		const html =
			'<footer class="site-footer">' +
			'<p>© 2026 Site</p>' +
			'<a href="#top">Back to top</a>' +
			'<a href="/privacy/">Privacy</a>' +
			'</footer>';
		const found = analyzeChrome( { url: 'https://example.test', html, css: [] } );
		// `Back to top` is a fragment; `Privacy` is its own element, never a
		// suffix of the © line.
		expect( found?.footerLinks ).toBeUndefined();
	} );
} );

describe( 'design facts prefer the site\'s own sheets over plugin widget UI', () => {
	// eca.directory: a logged-in page loads the NV oOS chat widget's 149 KB
	// stylesheet before the Kadence/Elementor kit sheets. Harvesting it turned
	// the palette Catppuccin (`text` #cdd6f4 on a `surface` #1e1e2e) and the
	// body face into a monospace leftover — a plugin's UI theme is not the
	// site's design language.
	const KIT = `
:root {
	--e-global-color-primary: #8764a0;
	--e-global-color-secondary: #27104a;
	--e-global-color-text: #747474;
	--e-global-typography-primary-font-family: "Epilogue";
	--e-global-typography-text-font-family: "Inter";
}
body { font-family: "Inter", sans-serif; color: #747474; background: #ffffff; font-size: 16px; }
h1, h2, h3 { font-family: "Epilogue", sans-serif; font-size: 48px; }
`;
	const CHAT = `
:root {
	--wp-mcp-ai-color-text: #cdd6f4;
	--wp-mcp-ai-color-surface: #1e1e2e;
	--wp-mcp-ai-color-accent: #89b4fa;
}
body { font-family: "Star", monospace; }
`;

	it( 'resolves the kit palette and type, not the plugin sheet', () => {
		const design = analyzeDesignInput( {
			url: 'https://eca.example',
			html: DESIGN_HTML,
			css: [ CHAT, KIT ],
			designCss: [ KIT ],
		} );
		expect( design.palette.roles.surface ).toBe( '#ffffff' );
		expect( design.palette.roles.text ).toBe( '#747474' );
		expect( design.palette.roles.accent ).toBe( '#8764a0' );
		expect( design.typography.body ).toBe( 'inter' );
		expect( design.typography.display ).toBe( 'epilogue' );
	} );

	it( 'still reads a plugin-only design when no design sheets exist', () => {
		const design = analyzeDesignInput( { url: 'https://eca.example', html: DESIGN_HTML, css: [ CHAT ] } );
		expect( design.palette.roles.text ).toBe( '#cdd6f4' );
	} );
} );

describe( 'inline widget CSS is not design CSS', () => {
	it( 'drops a component-scoped inline block from the design facts', () => {
		const CHAT_INLINE = `
.wp-mcp-ai-chat { --wp-mcp-ai-color-text: #cdd6f4; --wp-mcp-ai-color-surface: #1e1e2e; font-family: "Star", monospace; }
.wp-mcp-ai-chat__bubble { color: var(--wp-mcp-ai-color-text); }
.wp-mcp-ai-chat__form { background: var(--wp-mcp-ai-color-surface); }
`;
		const KIT = `:root { --e-global-color-primary: #8764a0; --e-global-color-text: #747474; --e-global-typography-text-font-family: "Inter"; }
body { font-family: "Inter", sans-serif; color: #747474; }`;
		// The fetch layer performs this split; the analyzer must honour it.
		const design = analyzeDesignInput( {
			url: 'https://eca.example',
			html: DESIGN_HTML,
			css: [ CHAT_INLINE, KIT ],
			designCss: [ KIT ],
			inlineCss: [],
		} );
		expect( design.palette.roles.text ).toBe( '#747474' );
		expect( design.typography.body ).toBe( 'inter' );
	} );
} );

describe( 'icon fonts are never a type pairing', () => {
	it( 'skips Kadence/WooCommerce `star` and Flatsome `fl-icons`', () => {
		const css = `
body { font-family: "Inter", sans-serif; }
h1, h2 { font-family: "Epilogue", sans-serif; }
.star-rating { font-family: "star"; }
.fl-icons { font-family: "fl-icons"; }
.e-eicon-star::before { font-family: eicons; }
`;
		const design = analyzeDesignInput( { url: 'https://x.example', html: DESIGN_HTML, css: [ css ] } );
		expect( design.typography.body ).toBe( 'inter' );
		expect( design.typography.display ).toBe( 'epilogue' );
		expect( JSON.stringify( design.typography ) ).not.toContain( 'star' );
	} );
} );

describe( 'font tokens are never read as families', () => {
	it( 'ignores --…-font-weight values and only reads --…-font-family', () => {
		const css = `
:root {
	--e-global-typography-text-font-family: "Inter";
	--e-global-typography-accent-font-family: "Epilogue";
	--e-global-typography-accent-font-weight: normal;
	--e-global-typography-text-font-style: italic;
}
body { font-family: "Inter", sans-serif; }
`;
		const design = analyzeDesignInput( { url: 'https://x.example', html: DESIGN_HTML, css: [ css ] } );
		expect( design.typography.body ).toBe( 'inter' );
		const families = JSON.stringify( design.typography );
		expect( families ).not.toContain( 'normal' );
		expect( families ).not.toContain( 'italic' );
	} );
} );

describe( 'a descendant rule is not a body fact', () => {
	it( 'ignores body.woocommerce … .star-rating when reading the body face', () => {
		const css = `
body { font-family: "Inter", sans-serif; color: #747474; }
body.woocommerce div.product .star-rating { font-family: "star"; }
body.single-post .entry-title { font-family: "star"; }
`;
		const design = analyzeDesignInput( { url: 'https://x.example', html: DESIGN_HTML, css: [ css ] } );
		expect( design.typography.body ).toBe( 'inter' );
	} );
} );

describe( 'a code face is never the accent', () => {
	it( 'skips a theme code stack (Courier/Monaco/Menlo)', () => {
		const css = `
body { font-family: "Inter", sans-serif; }
h1, h2, h3 { font-family: "Epilogue", sans-serif; }
code, pre { font-family: "Courier 10 Pitch", Courier, monospace; }
`;
		const design = analyzeDesignInput( { url: 'https://x.example', html: DESIGN_HTML, css: [ css ] } );
		expect( design.typography.body ).toBe( 'inter' );
		expect( design.typography.display ).toBe( 'epilogue' );
		expect( design.typography.accent ).not.toBe( 'courier 10 pitch' );
	} );
} );

describe( 'analyzeChrome — labelled header CTA pills (eca.directory)', () => {
	// The source header carries two <a class="elementor-button"> controls
	// ("Try Demo" / "Sign In") beside the nav. Only icon actions were modelled,
	// so a rebuild dropped the site's primary conversion controls.
	const CSS = `
:root { --e-global-color-primary: #8764a0; --e-global-color-secondary: #27104a; }
.elementor-button { display: inline-block; padding: 12px 24px; border-radius: 100px; font-size: 16px; font-weight: 500; }
.elementor-button-link { background-color: var(--e-global-color-primary); color: #ffffff; }
.elementor-button.elementor-size-sm { background-color: var(--e-global-color-secondary); }
`;
	const HTML =
		'<header id="site-header">' +
		'<img class="custom-logo" src="/logo.svg" alt="ECA Directory">' +
		'<nav><a href="/">Home</a><a href="/about-us/">About Us</a></nav>' +
		'<a class="elementor-button elementor-button-link elementor-size-sm" href="#">Try Demo</a>' +
		'<a class="elementor-button elementor-button-link elementor-size-sm" href="/login">Sign In</a>' +
		'</header>' +
		'<footer id="site-footer"><small>© 2026 ECA Directory</small></footer>';
	const chrome = analyzeChrome( { url: 'https://eca.example', html: HTML, css: [ CSS ] } );

	it( 'captures the pills as text actions, not icon links', () => {
		expect( chrome?.headerActions?.map( ( action ) => action.text ) ).toEqual( [ 'Try Demo', 'Sign In' ] );
		expect( chrome?.headerActions?.[ 1 ]?.href ).toBe( '/login' );
	} );

	it( 'resolves the fill through the cascade (the later size-sm rule wins)', () => {
		expect( chrome?.headerActions?.[ 0 ]?.fill?.toLowerCase() ).toContain( '#27104a' );
		expect( chrome?.headerActions?.[ 0 ]?.ink?.toLowerCase() ).toContain( '#ffffff' );
	} );

	it( 'carries the pill box (padding + radius + type)', () => {
		expect( chrome?.headerActions?.[ 0 ]?.style ).toEqual( {
			padding: '12px 24px',
			radius: '100px',
			size: '16px',
			weight: '500',
		} );
	} );

	it( 'prefers the flat pill colour over a translucent hover shade', () => {
		// Elementor paints every button `background-color: #8764a0` plus
		// `background-image: linear-gradient(0deg, rgba(0,0,0,.35), transparent
		// 75%)` (its hover shade). Taking the gradient as the fill shipped a
		// see-through pill with a 35%-black veil.
		const html =
			'<header id="site-header"><nav><a href="/">Home</a></nav>' +
			'<a class="elementor-button" href="#">Try Demo</a></header>';
		const css = `
.elementor-button { background-color: #8764a0; color: #fff;
  background-image: linear-gradient(0deg, rgba(0,0,0,.35), transparent 75%); }
`;
		const found = analyzeChrome( { url: 'https://eca.example', html, css: [ css ] } );

		expect( found?.headerActions?.[ 0 ]?.fill ).toBe( '#8764a0' );
	} );

	it( 'still takes an OPAQUE gradient as the fill (a real gradient button)', () => {
		const html =
			'<header id="site-header"><nav><a href="/">Home</a></nav>' +
			'<a class="aux-button" href="tel:+9411234">Call Us</a></header>';
		const css = `.aux-button { background-image: linear-gradient(180deg, #ffcf0e 0%, #ffb70e 100%); color: #242424; }`;
		const found = analyzeChrome( { url: 'https://eca.example', html, css: [ css ] } );

		expect( found?.call?.bg ).toContain( 'linear-gradient' );
	} );

	it( 'resolves each pill against its enclosing widget (per-widget colours)', () => {
		// Elementor scopes a button's colour to `.elementor-element-<widget id>`;
		// without that token both pills resolved the same generic rule and
		// eca.directory's accent "Try Demo" came back as its dark "Sign In" colour.
		const html =
			'<header id="site-header">' +
			'<div class="elementor-element elementor-element-aa1111"><a class="elementor-button" href="#">Try Demo</a></div>' +
			'<div class="elementor-element elementor-element-bb2222"><a class="elementor-button" href="/login">Sign In</a></div>' +
			'</header>';
		const css = `
.elementor-button { background-color: #000000; color: #ffffff; }
.elementor-element-bb2222 .elementor-button { background-color: #27104a; }
.elementor-element-aa1111 .elementor-button { background-color: #8764a0; }
`;
		const found = analyzeChrome( { url: 'https://eca.example', html, css: [ css ] } );

		expect( found?.headerActions?.[ 0 ]?.fill ).toBe( '#8764a0' );
		expect( found?.headerActions?.[ 1 ]?.fill ).toBe( '#27104a' );
	} );

	it( 'ignores a sibling rule that shares only the header wrapper class', () => {
		// The live markup: both pills sit under `header.elementor-86`, and Sign
		// In's rule is `.elementor-86 .elementor-element.elementor-element-<id>
		// .elementor-button`. Scoring any shared context token let that rule win
		// for the OTHER pill too.
		const html =
			'<header id="site-header" class="elementor elementor-86 elementor-location-header">' +
			'<div class="elementor-element elementor-element-aa1111"><a class="elementor-button" href="#">Try Demo</a></div>' +
			'<div class="elementor-element elementor-element-bb2222"><a class="elementor-button" href="/login">Sign In</a></div>' +
			'</header>';
		const css = `
.elementor-kit-21 .elementor-button { background-color: #8764a0; color: #ffffff; }
.elementor-86 .elementor-element.elementor-element-bb2222 .elementor-button { background-color: #27104a; }
`;
		const found = analyzeChrome( { url: 'https://eca.example', html, css: [ css ] } );

		expect( found?.headerActions?.[ 0 ]?.fill ).toBe( '#8764a0' );
		expect( found?.headerActions?.[ 1 ]?.fill ).toBe( '#27104a' );
	} );

	it( 'overwrites the harvested paint with the browser’s computed facts', () => {
		// The harvest resolves a builder var against the first declaration it
		// finds; the browser resolves the cascade. eca.directory's accent pill read
		// `#d9534f` (a foreign palette entry).
		const chrome: ForgeChrome = {
			headerActions: [
				{ label: 'Try Demo', text: 'Try Demo', href: '#', fill: '#d9534f', ink: '#ffffff' },
				{ label: 'Sign In', text: 'Sign In', href: '/login', fill: '#27104a', ink: '#ffffff' },
			],
		};
		const applied = applyMeasuredHeaderActions( chrome, [
			{
				text: 'Try Demo',
				fill: 'rgb(135, 100, 160)',
				ink: 'rgb(255, 255, 255)',
				style: { padding: '25px 50px', radius: '100px', size: '15px', weight: '400' },
			},
		] );

		expect( applied ).toBe( true );
		expect( chrome.headerActions?.[ 0 ].fill ).toBe( 'rgb(135, 100, 160)' );
		expect( chrome.headerActions?.[ 0 ].style ).toMatchObject( { size: '15px', weight: '400' } );
		// An unmatched pill keeps what the harvest found.
		expect( chrome.headerActions?.[ 1 ].fill ).toBe( '#27104a' );
	} );

	it( 'creates a pill the harvest missed entirely', () => {
		const chrome: Record< string, unknown > = {};
		const applied = applyMeasuredHeaderActions( chrome, [
			{ text: 'Try Demo', href: '#', fill: '#8764a0', ink: '#ffffff' },
		] );

		expect( applied ).toBe( true );
		expect( chrome.headerActions ).toEqual( [
			{ label: 'Try Demo', text: 'Try Demo', href: '#', fill: '#8764a0', ink: '#ffffff' },
		] );
	} );

	it( 'leaves the harvest alone when nothing was measured', () => {
		const chrome = { headerActions: [ { label: 'Try Demo', text: 'Try Demo', fill: '#d9534f' } ] };

		expect( applyMeasuredHeaderActions( chrome, [] ) ).toBe( false );
		expect( chrome.headerActions?.[ 0 ].fill ).toBe( '#d9534f' );
	} );
} );
