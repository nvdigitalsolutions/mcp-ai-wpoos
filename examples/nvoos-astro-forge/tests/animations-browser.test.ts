// ---------------------------------------------------------------------------
// animations-browser.test.ts — the `document.getAnimations()` probe (2026-11-12).
//
// The pure inventory half is pinned in `animations.test.ts`; this suite proves
// the BROWSER half against a hermetic fixture page:
//
//   • one CSS `@keyframes` on a band media target (the rebuildable case),
//   • one `element.animate()` WAAPI animation (the deferral case),
//   • one IntersectionObserver-toggled reveal that is BELOW the fold at rest,
//     so it only appears in the mid-scroll collection pass,
//   • one scroll-driven animation (`animation-timeline: scroll(root)` with a
//     named `animation-range`), then the EMITTED rebuild CSS is served back and
//     re-measured — the round-trip that proves Chrome binds the longhand
//     timeline + range the emitter writes.
//
// Chromium is a devDependency (the axe e2e gate installs it in CI). When the
// browser binary is missing the suite SKIPS — it never silently passes as if it
// had run: the skip is reported by vitest.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { chromium } from 'playwright';

import { animationRebuildCss, countAnimations, diffAnimations, measureAnimations, planAnimationRebuild } from '../lib/animations.ts';
import type { ForgeAnimationFact, ForgeAnimationInventory } from '../lib/types.ts';

const hasChromium = ( () => {
	try {
		return Boolean( chromium.executablePath() );
	} catch {
		return false;
	}
} )();

const FIXTURE_CSS = `
	body { margin: 0; font-family: sans-serif; }
	.band { padding: 40px; }
	.spacer { height: 1600px; }

	/* The rebuildable case: a compositor-only keyframe on a band image. */
	@keyframes fixture-pulse {
		0% { opacity: 1; }
		100% { opacity: 0.6; }
	}
	.pulse-media { animation: fixture-pulse 2s ease-in-out 3 normal both; width: 64px; height: 64px; }

	/* The scroll-revealed case: applied only after the IO adds the class. */
	@keyframes fixture-reveal {
		from { opacity: 0; }
		to { opacity: 1; }
	}
	.reveal { opacity: 0; }
	.reveal.is-revealed { opacity: 1; animation: fixture-reveal 1.2s ease-out 1 normal both; }

	/* The scroll-driven case: the emitter writes exactly these longhands. */
	@keyframes fixture-scroll-fade {
		from { opacity: 0.4; }
		to { opacity: 1; }
	}
	.scroll-media {
		width: 64px;
		height: 64px;
		animation: fixture-scroll-fade linear both;
		animation-timeline: scroll(root);
		animation-range: cover 10% cover 90%;
	}
`;

const FIXTURE_HTML = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Animation probe fixture</title>
<style>${ FIXTURE_CSS }</style>
</head>
<body>
	<section class="band elementor-top-section elementor-element-a1b2c3d" data-section-id="a1b2c3d">
		<img class="pulse-media" alt="" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='64' height='64'%3E%3Crect width='64' height='64' fill='%23cccccc'/%3E%3C/svg%3E">
		<p class="waapi-copy">Script-driven animation</p>
	</section>
	<div class="spacer"></div>
	<section class="band elementor-top-section elementor-element-b2c3d4e" data-section-id="b2c3d4e">
		<h2 class="reveal" id="reveal-title">Revealed after scrolling</h2>
	</section>
	<section class="band elementor-top-section elementor-element-c3d4e5f" data-section-id="c3d4e5f">
		<img class="scroll-media" alt="" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='64' height='64'%3E%3Crect width='64' height='64' fill='%23bbbbbb'/%3E%3C/svg%3E">
	</section>
	<script>
		document.querySelector( '.waapi-copy' ).animate(
			[ { opacity: 0.25 }, { opacity: 1 } ],
			{ duration: 800, iterations: Infinity, id: 'fixture-waapi' }
		);
		new IntersectionObserver( ( entries, observer ) => {
			for ( const entry of entries ) {
				if ( entry.isIntersecting ) {
					entry.target.classList.add( 'is-revealed' );
					observer.disconnect();
				}
			}
		} ).observe( document.getElementById( 'reveal-title' ) );
	</script>
</body>
</html>`;

const byName = ( facts: ForgeAnimationFact[], name: string ) => facts.find( ( fact ) => fact.name === name );

describe.skipIf( ! hasChromium )( 'measureAnimations (hermetic browser probe)', () => {
	let server: Server;
	let inventory: ForgeAnimationInventory;
	let url = '';
	let rebuiltHtml = '';

	beforeAll( async () => {
		server = createServer( ( request, response ) => {
			const body = request.url?.startsWith( '/rebuilt' ) ? rebuiltHtml : FIXTURE_HTML;
			response.writeHead( 200, { 'content-type': 'text/html; charset=utf-8' } );
			response.end( body );
		} );
		await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
		const address = server.address();
		const port = typeof address === 'object' && address ? address.port : 0;
		url = `http://127.0.0.1:${ port }/`;

		// `html` is NOT the served document — it is the static-analysis input, so a
		// remote script tag here proves the static/browser merge without making the
		// browser fetch (or wait on) a third-party CDN.
		const staticHtml = `${ FIXTURE_HTML }<script src="https://cdn.example.com/gsap.min.js"></script>`;
		inventory = await measureAnimations( {
			url,
			html: staticHtml,
			css: [ FIXTURE_CSS ],
			timeout: 30000,
		} );
	}, 60000 );

	afterAll( async () => {
		if ( server ) {
			await new Promise< void >( ( resolve ) => server.close( () => resolve() ) );
		}
	} );

	it( 'probes the page without an error', () => {
		expect( inventory.error ).toBeUndefined();
		expect( inventory.facts.length ).toBeGreaterThan( 0 );
	} );

	it( 'measures a CSS keyframe on a band media target', () => {
		const pulse = byName( inventory.facts, 'fixture-pulse' );
		expect( pulse ).toBeDefined();
		expect( pulse?.kind ).toBe( 'css-keyframes' );
		expect( pulse?.bandId ).toBe( 'a1b2c3d' );
		expect( pulse?.target ).toBe( 'media' );
		expect( pulse?.mediaIndex ).toBe( 0 );
		expect( pulse?.duration ).toBe( '2000ms' );
		expect( pulse?.iterations ).toBe( '3' );
		expect( pulse?.infinite ).toBe( false );
		expect( pulse?.properties ).toEqual( [ 'opacity' ] );
		expect( pulse?.keyframes ?? [] ).toHaveLength( 2 );
	} );

	it( 'measures a WAAPI animation and normalises infinite iterations', () => {
		const waapi = byName( inventory.facts, 'fixture-waapi' );
		expect( waapi ).toBeDefined();
		expect( waapi?.kind ).toBe( 'waapi' );
		expect( waapi?.iterations ).toBe( 'infinite' );
		expect( waapi?.infinite ).toBe( true );
		expect( waapi?.timeline ).toBe( 'DocumentTimeline' );
		expect( waapi?.bandId ).toBe( 'a1b2c3d' );
	} );

	it( 'discovers an IntersectionObserver reveal on the scroll passes', () => {
		const reveal = byName( inventory.facts, 'fixture-reveal' );
		expect( reveal ).toBeDefined();
		expect( reveal?.kind ).toBe( 'css-keyframes' );
		expect( reveal?.bandId ).toBe( 'b2c3d4e' );
		expect( reveal?.target ).toBe( 'text' );
	} );

	it( 'captures a scroll-driven animation with its named range', () => {
		const scroll = byName( inventory.facts, 'fixture-scroll-fade' );
		expect( scroll ).toBeDefined();
		expect( scroll?.kind ).toBe( 'css-keyframes' );
		expect( scroll?.timeline ).toBe( 'ScrollTimeline' );
		expect( scroll?.bandId ).toBe( 'c3d4e5f' );
		expect( scroll?.rangeStart ?? '' ).toContain( 'cover' );
		expect( scroll?.rangeStart ?? '' ).toContain( '10' );
		expect( scroll?.rangeEnd ?? '' ).toContain( '90' );
	} );

	it( 'merges the static library signature with the browser facts', () => {
		expect( inventory.libraries ).toContain( 'gsap' );
		expect( inventory.facts.some( ( fact ) => fact.kind === 'library' && fact.library === 'gsap' ) ).toBe( true );
		expect( Object.keys( inventory.keyframes ?? {} ) ).toEqual(
			expect.arrayContaining( [ 'fixture-pulse', 'fixture-reveal', 'fixture-scroll-fade' ] )
		);
	} );

	it( 'plans the measured facts: compositor keyframes rebuild, WAAPI defers', () => {
		const planned = planAnimationRebuild( inventory.facts );
		const pulse = planned.find( ( fact ) => fact.name === 'fixture-pulse' );
		const waapi = planned.find( ( fact ) => fact.name === 'fixture-waapi' );
		const library = planned.find( ( fact ) => fact.kind === 'library' && fact.library === 'gsap' );

		expect( pulse?.rebuild ).toBe( 'css' );
		expect( waapi?.rebuild ).toBe( 'deferred' );
		expect( waapi?.note ).toContain( 'script-driven' );
		expect( library?.rebuild ).toBe( 'deferred' );
		expect( library?.note ).toContain( 'never vendored' );
	} );

	it( 'counts facts by kind and rebuild outcome', () => {
		const counts = countAnimations( planAnimationRebuild( inventory.facts ) );
		expect( counts[ 'css-keyframes' ] ).toBeGreaterThanOrEqual( 3 );
		expect( counts.waapi ).toBe( 1 );
		expect( counts[ 'rebuild:css' ] ).toBeGreaterThanOrEqual( 2 );
		expect( counts[ 'rebuild:deferred' ] ).toBeGreaterThanOrEqual( 2 );
	} );

	it( 'rebuilds the scroll-driven fact and Chrome binds the emitted longhands', async () => {
		const source = byName( inventory.facts, 'fixture-scroll-fade' ) as ForgeAnimationFact;
		const [ planned ] = planAnimationRebuild( [ source ] );
		expect( planned.rebuild ).toBe( 'css' );

		const css = animationRebuildCss( [ planned ] );
		expect( css ).toContain( 'animation-timeline:scroll(root)' );
		expect( css ).toContain( 'animation-range:cover 10% cover 90%' );
		expect( css ).not.toMatch( /animation:[^;}]*scroll\(/ );

		rebuiltHtml = `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><style>${ css }</style></head>
<body>
	<section class="elementor-top-section" data-section-id="c3d4e5f">
		<div class="ds-media">rebuilt media carrier</div>
	</section>
	<div style="height:1600px"></div>
</body></html>`;

		const rebuiltUrl = url.replace( /\/$/, '/rebuilt' );
		const rebuilt = await measureAnimations( { url: rebuiltUrl, html: rebuiltHtml, css: [ css ], timeout: 30000 } );
		expect( rebuilt.error ).toBeUndefined();

		const emittedName = /forge-anim-\w+/.exec( css )?.[ 0 ] ?? '';
		const measured = byName( rebuilt.facts, emittedName );
		expect( measured ).toBeDefined();
		expect( measured?.timeline ).toBe( 'ScrollTimeline' );
		expect( measured?.bandId ).toBe( 'c3d4e5f' );

		const diff = diffAnimations( [ planned ], rebuilt.facts, rebuiltHtml );
		expect( diff.missing ).toEqual( [] );
		expect( diff.matched ).toBe( 1 );
		expect( diff.timing ).toEqual( [] );
	}, 60000 );
} );

describe.skipIf( ! hasChromium )( 'reduced motion (WCAG 2.2.2 / 2.3.3)', () => {
	// The emitted CSS lives inside `prefers-reduced-motion: no-preference`, so
	// the BASE state must already be the finished state — a reader who asks for
	// less motion must never see the `from` frame (`opacity: 0`) as their page.
	const fact: ForgeAnimationFact = {
		kind: 'css-keyframes',
		name: 'fixture-fade-in',
		bandId: 'a1b2c3d',
		target: 'media',
		mediaIndex: 0,
		duration: '10s',
		easing: 'linear',
		iterations: '1',
		direction: 'normal',
		fill: 'both',
		properties: [ 'opacity' ],
		keyframes: [
			{ offset: '0%', declarations: { opacity: '0' } },
			{ offset: '100%', declarations: { opacity: '1' } },
		],
	};
	const css = animationRebuildCss( planAnimationRebuild( [ fact ] ) );
	const page = `<!doctype html><html><head><meta charset="utf-8"><style>${ css }</style></head>
<body><section class="elementor-top-section" data-section-id="a1b2c3d"><div class="ds-media">media</div></section></body></html>`;

	const sample = async ( reducedMotion: 'reduce' | 'no-preference' ) => {
		const browser = await chromium.launch();
		try {
			const context = await browser.newContext( { reducedMotion } );
			const tab = await context.newPage();
			await tab.setContent( page, { waitUntil: 'load' } );
			await tab.waitForTimeout( 250 );
			return await tab.evaluate( () => ( {
				animations: document.getAnimations().length,
				opacity: getComputedStyle( document.querySelector( '.ds-media' ) as Element ).opacity,
			} ) );
		} finally {
			await browser.close();
		}
	};

	it( 'keeps the finished state as the base when reduced motion is requested', async () => {
		const reduced = await sample( 'reduce' );
		expect( reduced.animations ).toBe( 0 );
		expect( reduced.opacity ).toBe( '1' );
	}, 60000 );

	it( 'runs the rebuild when the reader accepts motion', async () => {
		const full = await sample( 'no-preference' );
		expect( full.animations ).toBeGreaterThanOrEqual( 1 );
		expect( Number.parseFloat( full.opacity ) ).toBeLessThan( 1 );
	}, 60000 );
} );
