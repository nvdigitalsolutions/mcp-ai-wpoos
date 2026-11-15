// ---------------------------------------------------------------------------
// animations.test.ts — the animated-effect inventory (2026-11-12).
//
// The pure half: CSS keyframe extraction, library signatures, the rebuild
// planner (finite/safe → CSS; infinite/JS → deferred), the CSS emitter and the
// source↔build diff. The browser probe itself is exercised by the live
// `analyze --animations` run, not this suite (no browser in unit tests).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	analyzeAnimationSignatures,
	animationHash,
	animationRebuildCss,
	detectAnimationLibraries,
	diffAnimations,
	extractKeyframes,
	planAnimationRebuild,
} from '../lib/animations.ts';
import { renderAnimationsGenerated, renderMotionDoc } from '../lib/scaffold.ts';
import type { ForgeAnimationFact, SiteProfile } from '../lib/types.ts';

const CSS = `
@keyframes pulse {
	0% { transform: scale(1); opacity: 1; }
	50% { transform: scale(1.05); opacity: 0.8; }
	100% { transform: scale(1); opacity: 1; }
}
@-webkit-keyframes spin {
	from { transform: rotate(0deg); }
	to { transform: rotate(360deg); }
}
@keyframes layout-shift {
	from { width: 10px; }
	to { width: 100px; }
}
`;

const fact = ( overrides: Partial< ForgeAnimationFact > = {} ): ForgeAnimationFact => ( {
	kind: 'css-keyframes',
	name: 'pulse',
	bandId: 'a1b2c3d',
	target: 'media',
	mediaIndex: 0,
	duration: '2s',
	easing: 'ease-in-out',
	iterations: '3',
	direction: 'normal',
	fill: 'both',
	properties: [ 'transform', 'opacity' ],
	keyframes: [
		{ offset: '0%', declarations: { transform: 'scale(1)', opacity: '1' } },
		{ offset: '100%', declarations: { transform: 'scale(1.05)', opacity: '1' } },
	],
	timeline: 'DocumentTimeline',
	...overrides,
} );

describe( 'extractKeyframes', () => {
	it( 'extracts named keyframes, including vendor-prefixed blocks', () => {
		const frames = extractKeyframes( CSS );
		expect( Object.keys( frames ).sort() ).toEqual( [ 'layout-shift', 'pulse', 'spin' ] );
		expect( frames.pulse ).toHaveLength( 3 );
		expect( frames.pulse[ 0 ] ).toEqual( { offset: '0%', declarations: { transform: 'scale(1)', opacity: '1' } } );
		expect( frames.spin[ 1 ] ).toEqual( { offset: 'to', declarations: { transform: 'rotate(360deg)' } } );
	} );

	it( 'returns an empty map for malformed / empty CSS', () => {
		expect( extractKeyframes( '' ) ).toEqual( {} );
		expect( extractKeyframes( 'not css {' ) ).toEqual( {} );
	} );
} );

describe( 'detectAnimationLibraries', () => {
	it( 'detects runtimes from script URLs, inline scripts and markup', () => {
		const result = detectAnimationLibraries(
			'<script src="https://cdn.example.com/gsap.min.js"></script>' +
				'<div data-aos="fade-up"></div>' +
				'<lottie-player src="x.json"></lottie-player>' +
				'<video autoplay loop muted></video>' +
				'<svg><animate attributeName="opacity" /></svg>'
		);
		expect( result.libraries ).toEqual( [ 'aos', 'gsap', 'lottie' ] );
		expect( result.video ).toBe( 1 );
		expect( result.smil ).toBe( 1 );
	} );

	it( 'does not mistake prose for a library', () => {
		expect( detectAnimationLibraries( '<p>We use GSAP and Lottie on other projects.</p>' ).libraries ).toEqual( [] );
	} );

	it( 'captures the static fallback asset/poster for Lottie, video and SMIL', () => {
		const result = analyzeAnimationSignatures(
			'<lottie-player src="anim.json" poster="poster.png"></lottie-player>' +
				'<video poster="video.jpg" src="clip.mp4"></video>' +
				'<svg><animate attributeName="opacity" /></svg>',
			[]
		);
		const lottie = result.facts.find( ( fact ) => fact.kind === 'library' && fact.name === 'lottie' );
		expect( lottie?.asset ).toBe( 'anim.json' );
		expect( lottie?.poster ).toBe( 'poster.png' );
		const video = result.facts.find( ( fact ) => fact.kind === 'video' );
		expect( video?.asset ).toBe( 'clip.mp4' );
		expect( video?.poster ).toBe( 'video.jpg' );
		expect( result.facts.some( ( fact ) => fact.kind === 'smil' ) ).toBe( true );
	} );
} );

describe( 'planAnimationRebuild', () => {
	it( 'rebuilds a finite, layout-safe CSS keyframe on a media target', () => {
		const [ planned ] = planAnimationRebuild( [ fact() ] );
		expect( planned.rebuild ).toBe( 'css' );
	} );

	it( 'rebuilds infinite motion only when the pause control is available', () => {
		const [ deferred ] = planAnimationRebuild( [ fact( { infinite: true, iterations: 'infinite' } ) ] );
		expect( deferred.rebuild ).toBe( 'deferred' );
		expect( deferred.note ).toContain( 'WCAG 2.2.2' );

		const [ rebuilt ] = planAnimationRebuild( [ fact( { infinite: true, iterations: 'infinite' } ) ], undefined, {
			allowInfinite: true,
		} );
		expect( rebuilt.rebuild ).toBe( 'css' );
		expect( rebuilt.note ).toContain( 'Pause motion' );
	} );

	it( 'defers non-compositor properties', () => {
		const [ planned ] = planAnimationRebuild( [ fact( { properties: [ 'width' ], keyframes: [ { offset: '0%', declarations: { width: '10px' } }, { offset: '100%', declarations: { width: '100px' } } ] } ) ] );
		expect( planned.rebuild ).toBe( 'deferred' );
		expect( planned.note ).toContain( 'width' );
	} );

	it( 'defers a control target (the rebuilt control is not the source node)', () => {
		const [ planned ] = planAnimationRebuild( [ fact( { target: 'control' } ) ] );
		expect( planned.rebuild ).toBe( 'deferred' );
	} );

	it( 'maps the scroll-behavior band to the existing reveal/parallax runtime', () => {
		const [ planned ] = planAnimationRebuild( [ fact( { timeline: 'ViewTimeline' } ) ], {
			headerSticky: false,
			entries: [ { id: 'a1b2c3d', kind: 'reveal', effect: 'fade-up' } ],
		} );
		expect( planned.rebuild ).toBe( 'mapped' );
	} );

	it( 'maps a document-timeline theme reveal on a reveal band', () => {
		// Phlox runs AuxFadeInUp from an IntersectionObserver class, not a scroll
		// timeline; the 121 pass already rebuilds that band as a view() reveal.
		const [ planned ] = planAnimationRebuild( [ fact( { name: 'AuxFadeInUp', timeline: 'DocumentTimeline' } ) ], {
			headerSticky: false,
			entries: [ { id: 'a1b2c3d', kind: 'reveal', effect: 'fade-up' } ],
		} );
		expect( planned.rebuild ).toBe( 'mapped' );
	} );

	it( 'maps a transition to the interaction-states pass', () => {
		const [ planned ] = planAnimationRebuild( [ fact( { kind: 'css-transition', name: 'color' } ) ] );
		expect( planned.rebuild ).toBe( 'mapped' );
	} );

	it( 'never vendors a JS runtime', () => {
		const [ planned ] = planAnimationRebuild( [ fact( { kind: 'library', name: 'gsap', library: 'gsap' } ) ] );
		expect( planned.rebuild ).toBe( 'deferred' );
		expect( planned.note ).toContain( 'never vendored' );
	} );

	it( 'keeps the static poster/asset in the deferred Lottie note', () => {
		const [ planned ] = planAnimationRebuild( [
			fact( { kind: 'library', name: 'lottie', library: 'lottie', asset: 'anim.json', poster: 'poster.png' } ),
		] );
		expect( planned.rebuild ).toBe( 'deferred' );
		expect( planned.note ).toContain( 'poster.png' );
	} );

	it( 'maps a button-like control animation to the rebuilt CTA', () => {
		const [ planned ] = planAnimationRebuild( [
			fact( { target: 'control', targetTag: 'a', targetClasses: [ 'aux-button', 'aux-call' ] } ),
		] );
		expect( planned.rebuild ).toBe( 'css' );
		expect( animationRebuildCss( [ planned ] ) ).toContain( '.ds-button' );
	} );

	it( 'defers a non-button control', () => {
		const [ planned ] = planAnimationRebuild( [ fact( { target: 'control', targetTag: 'div', targetClasses: [ 'wrapper' ] } ) ] );
		expect( planned.rebuild ).toBe( 'deferred' );
	} );

	it( 'maps a paint-only heading animation to the rebuilt title', () => {
		const [ planned ] = planAnimationRebuild( [
			fact( { target: 'text', targetTag: 'h2', properties: [ 'opacity' ] } ),
		] );
		expect( planned.rebuild ).toBe( 'css' );
		expect( animationRebuildCss( [ planned ] ) ).toContain( '.ds-title' );
	} );

	it( 'defers a layout-animated heading', () => {
		const [ planned ] = planAnimationRebuild( [
			fact( { target: 'text', targetTag: 'h2', properties: [ 'transform' ] } ),
		] );
		expect( planned.rebuild ).toBe( 'deferred' );
	} );
} );

describe( 'animationRebuildCss', () => {
	it( 'emits a scoped keyframe rule gated on prefers-reduced-motion', () => {
		const planned = planAnimationRebuild( [ fact() ] );
		const css = animationRebuildCss( planned );
		expect( css ).toContain( '@keyframes forge-anim-' );
		expect( css ).toContain( '@media (prefers-reduced-motion: no-preference)' );
		expect( css ).toContain( '[data-section-id="a1b2c3d"] .ds-media:nth-of-type(1)' );
		expect( css ).toContain( 'animation-name:forge-anim-' );
		expect( css ).not.toContain( 'animation:' );
	} );

	it( 'keeps a scroll timeline as a separate longhand', () => {
		const planned = planAnimationRebuild( [
			fact( { timeline: 'ScrollTimeline', rangeStart: 'cover 10%', rangeEnd: 'cover 90%' } ),
		] );
		const css = animationRebuildCss( planned );
		expect( css ).toContain( 'animation-timeline:scroll(root)' );
		expect( css ).toContain( 'animation-range:cover 10% cover 90%' );
		expect( css ).not.toMatch( /animation:[^;}]*scroll\(/ );
	} );

	it( 'never writes a malformed range offset into the CSS', () => {
		// Chrome exposes `Animation.rangeStart` as a dictionary; an older collector
		// stringified it to `[object Object]` and the emitter copied that straight
		// into the rule (invalid CSS, silently ignored by the browser).
		const planned = planAnimationRebuild( [
			fact( { timeline: 'ScrollTimeline', rangeStart: '[object Object]', rangeEnd: '[object Object]' } ),
		] );
		const css = animationRebuildCss( planned );
		expect( css ).not.toContain( '[object' );
		expect( css ).not.toContain( 'animation-range:' );
	} );

	it( 'emits nothing for deferred facts', () => {
		const planned = planAnimationRebuild( [ fact( { infinite: true, iterations: 'infinite' } ) ] );
		expect( animationRebuildCss( planned ) ).toBe( '' );
	} );
} );

describe( 'diffAnimations', () => {
	it( 'matches a CSS-rebuilt fact by its generated keyframe name', () => {
		const source = planAnimationRebuild( [ fact() ] );
		const generated = `forge-anim-${ animationHash( 'a1b2c3d', 'pulse', 'media', 0 ) }`;
		const built: ForgeAnimationFact[] = [ fact( { name: generated } ) ];
		expect( diffAnimations( source, built ).missing ).toEqual( [] );
	} );

	it( 'reports a missing rebuilt animation', () => {
		const source = planAnimationRebuild( [ fact() ] );
		expect( diffAnimations( source, [] ).missing ).toHaveLength( 1 );
	} );

	it( 'ignores deferred facts in the missing set', () => {
		const source = planAnimationRebuild( [ fact( { infinite: true, iterations: 'infinite' } ) ] );
		expect( diffAnimations( source, [] ).missing ).toEqual( [] );
	} );
} );

describe( 'generated report + module', () => {
	const profile = ( facts: ForgeAnimationFact[] ): SiteProfile =>
		( {
			url: 'https://example.com',
			name: 'Fixture',
			collections: [],
			animations: { facts: planAnimationRebuild( facts ) },
		} as unknown as SiteProfile );

	it( 'bakes the safe CSS rebuild into animations.generated.ts', () => {
		const generated = renderAnimationsGenerated( profile( [ fact() ] ) );
		expect( generated ).toContain( 'export const animationCss = ' );
		expect( generated ).toContain( '@keyframes forge-anim-' );
		expect( generated ).toContain( 'prefers-reduced-motion' );
		expect( generated ).toContain( 'export const hasInfiniteMotion = false' );
	} );

	it( 'flags infinite motion and emits its iteration count', () => {
		const generated = renderAnimationsGenerated(
			profile( [ fact( { name: 'spin', infinite: true, iterations: 'infinite' } ) ] )
		);
		expect( generated ).toContain( 'export const hasInfiniteMotion = true' );
		expect( generated ).toContain( 'animation-iteration-count:infinite' );
	} );

	it( 'writes the animated-effects table with the deferred reason', () => {
		const doc = renderMotionDoc(
			profile( [ fact( { kind: 'library', name: 'gsap', library: 'gsap' } ) ] )
		);
		expect( doc ).toContain( '## Animated effects' );
		expect( doc ).toContain( '| `a1b2c3d` | gsap | media #1 |' );
		expect( doc ).toContain( 'deferred' );
		expect( doc ).toContain( 'never vendored' );
	} );

	it( 'labels an infinite rebuild as the pause-control path', () => {
		const doc = renderMotionDoc( profile( [ fact( { name: 'spin', infinite: true, iterations: 'infinite' } ) ] ) );
		expect( doc ).toContain( '| `a1b2c3d` | spin | media #1 |' );
		expect( doc ).toContain( 'CSS keyframes' );
		expect( doc ).toContain( 'Pause motion' );
	} );

	it( 'summarizes per-page inventories separately', () => {
		const input = profile( [ fact() ] );
		input.pageAnimations = { about: { facts: [ fact() ] } };
		const doc = renderMotionDoc( input );
		expect( doc ).toContain( '## Per-page animated effects' );
		expect( doc ).toContain( '| `/about/` | 1 |' );
	} );
} );
