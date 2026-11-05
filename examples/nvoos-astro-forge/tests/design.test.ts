// ---------------------------------------------------------------------------
// design.test.ts — the forge design-import module (Proposal 114 P1/P5):
// deterministic extraction, token mapping, contrast-fix suggestions, and the
// language-parity score the verify surface runs on.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	analyzeBuiltCss,
	analyzeDesignInput,
	colorDistance,
	designToTokens,
	lightnessOf,
	normalizeColor,
	scoreParity,
	suggestContrastFixes,
	stylesheetLinks,
} from '../lib/design.ts';
import type { ForgeDesign } from '../lib/types.ts';

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
