// ---------------------------------------------------------------------------
// animations.ts — the source's animated-effect inventory (2026-11-12).
//
// The 121 pass covers the scroll behaviours a builder declares in classes /
// motion JSON (reveal, parallax, sticky). It cannot see everything else a site
// animates: CSS `@keyframes`, transitions, script-driven Web Animations,
// GSAP/Lottie/Swiper-style runtimes, autoplaying video, SMIL.
//
// This module is the deterministic inventory:
//   - static: `@keyframes` blocks + library/markup signatures from the HTML/CSS
//     the analyzer already fetched;
//   - measured: `document.getAnimations()` in a real browser at rest and after
//     scrolling (IntersectionObserver-triggered classes), which is the only
//     standards-based way to see CSS *and* script-driven Web Animations;
//   - rebuild: finite CSS keyframes on a band/media target are re-emitted as
//     scoped CSS. Infinite motion is NOT auto-rebuilt (WCAG 2.2.2 needs a
//     pause/stop control); JS runtimes are never vendored.
//
// Industry anchors:
//   - `document.getAnimations()` / `Animation.effect.getKeyframes()` —
//     <https://developer.mozilla.org/en-US/docs/Web/API/Document/getAnimations>,
//     <https://developer.mozilla.org/en-US/docs/Web/API/KeyframeEffect/getKeyframes>
//   - `prefers-reduced-motion` — <https://developer.mozilla.org/en-US/docs/Web/CSS/@media/prefers-reduced-motion>
//   - WCAG 2.2.2 Pause, Stop, Hide —
//     <https://www.w3.org/WAI/WCAG22/Understanding/pause-stop-hide.html>
//   - CSS scroll-driven animations (the timeline longhand rule) —
//     <https://developer.mozilla.org/en-US/docs/Web/CSS/CSS_scroll-driven_animations>
//
// Node type-stripping safe: no enums, no namespaces, no parameter properties.
// ---------------------------------------------------------------------------

import { generate, parse, walk } from 'css-tree';

import type {
	ForgeAnimationFact,
	ForgeAnimationInventory,
	ForgeAnimationStep,
	ScrollBehaviorPass,
} from './types.ts';

const MAX_KEYFRAMES = 60;
const MAX_KEYFRAME_STEPS = 40;
const MAX_DECLARATIONS_PER_STEP = 30;
const MAX_FACTS = 240;
const MAX_ANIMATION_DECLARATIONS = 240;

/** Properties a scoped CSS rebuild may animate without layout jank. */
const SAFE_ANIMATION_PROPERTIES = new Set( [
	'opacity',
	'transform',
	'translate',
	'scale',
	'rotate',
	'filter',
	'backdrop-filter',
	'background-position',
	'background-color',
	'background-image',
	'color',
	'border-color',
	'box-shadow',
	'text-shadow',
	'outline-color',
	'stroke',
	'fill',
] );

interface AnimationSignature {
	name: string;
	script?: RegExp;
	content?: RegExp;
	markup?: RegExp;
}

/**
 * Script/markup fingerprints. `content` is matched ONLY against inline script
 * text (never the whole document — prose mentioning "motion" is not a library),
 * `script` against `<script src>` URLs, `markup` against the HTML (attributes /
 * library class names).
 */
const ANIMATION_SIGNATURES: AnimationSignature[] = [
	{ name: 'gsap', script: /(?:^|[/.-])gsap(?:[.-]|$)|scrolltrigger/, content: /\bgsap\b|scrolltrigger/ },
	{ name: 'lottie', script: /lottie|bodymovin/, content: /lottie\.loadAnimation|bodymovin\.loadAnimation|new\s+lottieplayer/i, markup: /<lottie-player|data-lottie|class=["'][^"']*\blottie\b/ },
	{ name: 'anime', script: /anime(?:\.min)?\.js/, content: /\banime\s*\(/ },
	{ name: 'motion', script: /motion(?:-one)?(?:\.min)?\.js|framer-motion/, content: /\bmotion\s*\(|framer-motion/ },
	{ name: 'rive', script: /rive(?:-canvas|\.min)?\.js|@rive-app/, content: /\brive\b/, markup: /<canvas[^>]+data-rive|class=["'][^"']*\brive\b/ },
	{ name: 'three', script: /three(?:\.module|\.min)?\.js|three@/, content: /\bthree\./ },
	{ name: 'pixi', script: /pixi(?:\.min)?\.js|pixi\.js/, content: /\bpixi\./ },
	{ name: 'swiper', script: /swiper(?:\.min)?\.js|swiper@/, content: /\bnew\s+swiper\b|swiper\(/, markup: /class=["'][^"']*\bswiper\b/ },
	{ name: 'splide', script: /splide(?:\.min)?\.js|@splidejs/, content: /\bnew\s+splide\b/, markup: /class=["'][^"']*\bsplide\b/ },
	{ name: 'aos', script: /aos(?:\.min)?\.js|aos@/, content: /\baos\.init\b/, markup: /\bdata-aos\b/ },
	{ name: 'wow', script: /wow(?:\.min)?\.js|animate\.css|animate\.min\.css/, content: /\bnew\s+wow\b/, markup: /class=["'][^"']*\bwow\b/ },
	{ name: 'locomotive-scroll', script: /locomotive-scroll|locomotive\.scroll/, content: /\blocomotivescroll\b/, markup: /\bdata-scroll\b/ },
	{ name: 'rellax', script: /rellax(?:\.min)?\.js/, content: /\brellax\b/, markup: /\bdata-rellax-speed\b/ },
	{ name: 'vivus', script: /vivus(?:\.min)?\.js/, content: /\bnew\s+vivus\b/ },
	{ name: 'typed', script: /typed(?:\.min)?\.js|typed\.js/, content: /\bnew\s+typed\b/, markup: /\bdata-typed\b/ },
	{ name: 'countup', script: /countup(?:\.min)?\.js|countup\.js/, content: /\bcountup\b/, markup: /\bdata-count(?:=|["'])/ },
	{ name: 'odometer', script: /odometer(?:\.min)?\.js/, content: /\bodometer\b/, markup: /class=["'][^"']*\bodometer\b/ },
	{ name: 'vanilla-tilt', script: /vanilla-tilt(?:\.min)?\.js|tilt\.js/, content: /\bvanillatilt\b/, markup: /\bdata-tilt\b/ },
];

/** One declared `animation` / `animation-name` rule (static fallback/report). */
export interface ForgeAnimationDeclaration {
	selector: string;
	property: string;
	value: string;
}

/**
 * Extract the source's `@keyframes` blocks (name → steps). Vendor-prefixed
 * blocks are accepted (`@-webkit-keyframes`); the first declaration of a name
 * wins, matching the cascade for an identical name. Bounded.
 */
export function extractKeyframes( css: string ): Record< string, ForgeAnimationStep[] > {
	const out: Record< string, ForgeAnimationStep[] > = {};
	if ( ! css.trim() ) {
		return out;
	}
	let ast;
	try {
		ast = parse( css, { positions: false, parseValue: false } );
	} catch {
		return out;
	}
	let count = 0;
	walk( ast, ( node ) => {
		if ( count >= MAX_KEYFRAMES ) {
			return;
		}
		if ( node.type !== 'Atrule' || ! /^(?:-\w+-)?keyframes$/i.test( node.name ) || ! node.prelude || ! node.block ) {
			return;
		}
		let name = '';
		try {
			name = generate( node.prelude ).trim().replace( /^['"]|['"]$/g, '' );
		} catch {
			return;
		}
		if ( ! name || out[ name ] ) {
			return;
		}
		const steps: ForgeAnimationStep[] = [];
		node.block.children.forEach( ( child ) => {
			if ( child.type !== 'Rule' ) {
				return;
			}
			let offset = '';
			try {
				offset = generate( child.prelude ).trim();
			} catch {
				return;
			}
			if ( ! offset ) {
				return;
			}
			const declarations: Record< string, string > = {};
			child.block.children.forEach( ( declaration ) => {
				if ( declaration.type !== 'Declaration' ) {
					return;
				}
				let value = '';
				try {
					value = generate( declaration.value ).trim();
				} catch {
					return;
				}
				if ( value ) {
					declarations[ declaration.property.toLowerCase() ] = declaration.important ? `${ value } !important` : value;
				}
			} );
			const keys = Object.keys( declarations );
			if ( keys.length === 0 ) {
				return;
			}
			const bounded: Record< string, string > = {};
			for ( const key of keys.slice( 0, MAX_DECLARATIONS_PER_STEP ) ) {
				bounded[ key ] = declarations[ key ];
			}
			steps.push( { offset, declarations: bounded } );
		} );
		if ( steps.length > 0 ) {
			out[ name ] = steps.slice( 0, MAX_KEYFRAME_STEPS );
			count++;
		}
	} );
	return out;
}

/** The source's declared animation rules, for the report when no browser runs. */
export function extractAnimationDeclarations( css: string ): ForgeAnimationDeclaration[] {
	const out: ForgeAnimationDeclaration[] = [];
	if ( ! css.trim() ) {
		return out;
	}
	let ast;
	try {
		ast = parse( css, { positions: false, parseValue: false } );
	} catch {
		return out;
	}
	walk( ast, ( node ) => {
		if ( out.length >= MAX_ANIMATION_DECLARATIONS || node.type !== 'Rule' ) {
			return;
		}
		let selector = '';
		try {
			selector = generate( node.prelude ).trim();
		} catch {
			return;
		}
		node.block.children.forEach( ( declaration ) => {
			if ( out.length >= MAX_ANIMATION_DECLARATIONS || declaration.type !== 'Declaration' ) {
				return;
			}
			const property = declaration.property.toLowerCase();
			if ( property !== 'animation' && property !== 'animation-name' && property !== 'transition' && property !== 'transition-property' ) {
				return;
			}
			let value = '';
			try {
				value = generate( declaration.value ).trim();
			} catch {
				return;
			}
			if ( value ) {
				out.push( { selector, property, value } );
			}
		} );
	} );
	return out;
}

/** Detect animation runtimes and media effects from the source markup. */
export function detectAnimationLibraries( html: string ): {
	libraries: string[];
	video: number;
	smil: number;
} {
	const scripts: string[] = [];
	const srcs: string[] = [];
	const scriptRe = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
	let match: RegExpExecArray | null;
	while ( ( match = scriptRe.exec( html ) ) !== null ) {
		const attrs = match[ 1 ] ?? '';
		const src = /\bsrc\s*=\s*["']([^"']+)["']/i.exec( attrs )?.[ 1 ];
		if ( src ) {
			srcs.push( src.toLowerCase() );
		}
		const body = ( match[ 2 ] ?? '' ).trim().toLowerCase();
		if ( body ) {
			scripts.push( body );
		}
	}
	const scriptText = `${ srcs.join( ' ' ) }\n${ scripts.join( '\n' ) }`;
	const markup = html.toLowerCase();
	const libraries: string[] = [];
	for ( const signature of ANIMATION_SIGNATURES ) {
		const matched =
			( signature.script !== undefined && signature.script.test( srcs.join( ' ' ) ) ) ||
			( signature.content !== undefined && signature.content.test( scripts.join( '\n' ) ) ) ||
			( signature.markup !== undefined && signature.markup.test( markup ) );
		if ( matched ) {
			libraries.push( signature.name );
		}
	}
	const video = ( html.match( /<video\b/gi ) ?? [] ).length;
	const smil = ( html.match( /<(?:animate|animateTransform|animateMotion)\b/gi ) ?? [] ).length;
	return { libraries: [ ...new Set( libraries ) ].sort(), video, smil };
}

/** The static half of the inventory (HTML/CSS only). */
export function analyzeAnimationSignatures(
	html: string,
	css: string[]
): {
	libraries: string[];
	keyframes: Record< string, ForgeAnimationStep[] >;
	declarations: ForgeAnimationDeclaration[];
	facts: ForgeAnimationFact[];
	video: number;
	smil: number;
} {
	const signatures = detectAnimationLibraries( html );
	const cssText = css.join( '\n' );
	return {
		...signatures,
		keyframes: extractKeyframes( cssText ),
		declarations: extractAnimationDeclarations( cssText ),
		facts: staticAnimationFacts( html, signatures.libraries, signatures.video, signatures.smil ),
	};
}

/** The first capture of a regex in the HTML, or undefined. */
function firstCapture( html: string, regex: RegExp ): string | undefined {
	const match = regex.exec( html );
	return match?.[ 1 ]?.trim() || undefined;
}

/**
 * Static facts for effects a browser cannot expose as a Web Animation: library
 * runtimes (Lottie/Rive/GSAP...), `<video>` and SMIL. These are NEVER rebuilt;
 * the fact carries the source asset/poster so the operator has the static
 * fallback and the reason is explicit in MOTION.md.
 */
export function staticAnimationFacts(
	html: string,
	libraries: string[],
	videoCount: number,
	smilCount: number
): ForgeAnimationFact[] {
	const facts: ForgeAnimationFact[] = [];
	const lottieAsset = firstCapture( html, /(?:data-animation-path|data-src|data-lottie-src|src)=["']([^"']+\.json(?:\?[^"']*)?)["']/i );
	const lottiePoster = firstCapture( html, /\bposter=["']([^"']+)["']/i );
	const riveAsset = firstCapture( html, /["']([^"']+\.riv(?:\?[^"']*)?)["']/i );
	const videoPoster = firstCapture( html, /<video\b[^>]*\bposter=["']([^"']+)["']/i );
	const videoSource =
		firstCapture( html, /<video\b[^>]*\bsrc=["']([^"']+)["']/i ) ??
		firstCapture( html, /<source\b[^>]*\bsrc=["']([^"']+)["']/i );

	for ( const library of libraries ) {
		const fact: ForgeAnimationFact = {
			kind: 'library',
			name: library,
			library,
			target: 'other',
		};
		if ( library === 'lottie' ) {
			if ( lottieAsset ) {
				fact.asset = lottieAsset;
			}
			if ( lottiePoster ) {
				fact.poster = lottiePoster;
			}
		} else if ( library === 'rive' && riveAsset ) {
			fact.asset = riveAsset;
		}
		facts.push( fact );
	}

	for ( let index = 0; index < videoCount; index++ ) {
		facts.push( {
			kind: 'video',
			name: 'video',
			target: 'other',
			...( videoSource ? { asset: videoSource } : {} ),
			...( videoPoster ? { poster: videoPoster } : {} ),
		} );
	}
	for ( let index = 0; index < smilCount; index++ ) {
		facts.push( { kind: 'smil', name: 'smil', target: 'other' } );
	}
	return facts;
}

/** The browser probe's options. */
export interface MeasureAnimationsOptions {
	url: string;
	html?: string;
	css?: string[];
	timeout?: number;
	maxFacts?: number;
}

type PlaywrightChromium = typeof import( 'playwright' ).chromium;

async function loadChromium(): Promise< PlaywrightChromium > {
	try {
		const playwright = await import( 'playwright' );
		return playwright.chromium;
	} catch {
		throw new Error( 'Animation measurement needs Playwright. Install it in the forge (npm i -D playwright).' );
	}
}

async function settle( page: import( 'playwright' ).Page ): Promise< void > {
	await page.evaluate( async () => {
		const doc = document as Document & { fonts?: { ready: Promise< unknown > } };
		if ( doc.fonts ) {
			await doc.fonts.ready;
		}
	} );
	await page.waitForTimeout( 300 );
}

/**
 * The in-page collector. Self-contained (Playwright serializes the function);
 * no outer references.
 */
function collectAnimations( options: { maxFacts: number } ): Array< Omit< ForgeAnimationFact, 'rebuild' | 'note' > > {
	const escapeClass = ( value: string ): string => {
		if ( typeof CSS !== 'undefined' && typeof CSS.escape === 'function' ) {
			return CSS.escape( value );
		}
		return value.replace( /[^\w-]/g, '\\$&' );
	};
	const selectorOf = ( element: Element ): string => {
		if ( element.id ) {
			return `#${ element.id.replace( /[^\w-]/g, '\\$&' ) }`;
		}
		const dataId = element.getAttribute( 'data-id' );
		if ( dataId ) {
			return `[data-id="${ dataId.replace( /"/g, '\\"' ) }"]`;
		}
		const classes = Array.from( element.classList ).slice( 0, 3 ).map( escapeClass );
		return `${ element.tagName.toLowerCase() }${ classes.map( ( name ) => `.${ name }` ).join( '' ) }`;
	};
	const bandOf = ( element: Element ): Element | undefined => {
		const section = element.closest( '.elementor-top-section' ) ?? element.closest( '[data-section-id]' );
		return section ?? undefined;
	};
	const bandIdOf = ( band: Element | undefined ): string | undefined => {
		if ( ! band ) {
			return undefined;
		}
		const explicit = band.getAttribute( 'data-section-id' ) ?? band.getAttribute( 'data-id' );
		if ( explicit ) {
			return explicit;
		}
		const match = /elementor-element-([0-9a-f]{4,})/.exec( band.className );
		return match?.[ 1 ];
	};
	const targetKindOf = ( element: Element, band: Element | undefined ): ForgeAnimationFact[ 'target' ] => {
		if ( ! band ) {
			return 'other';
		}
		if ( element === band ) {
			return 'band';
		}
		const tag = element.tagName.toLowerCase();
		if ( tag === 'img' || element.closest( '.aux-media-image' ) !== null ) {
			return 'media';
		}
		if ( [ 'a', 'button', 'input', 'select', 'textarea', 'label', 'summary' ].includes( tag ) ) {
			return 'control';
		}
		if ( /^h[1-6]$/.test( tag ) || [ 'p', 'span', 'li', 'blockquote', 'figcaption' ].includes( tag ) ) {
			return 'text';
		}
		return 'other';
	};
	const mediaIndexOf = ( element: Element, band: Element | undefined ): number | undefined => {
		if ( ! band || element.tagName.toLowerCase() !== 'img' ) {
			return undefined;
		}
		const images = Array.from( band.querySelectorAll( 'img' ) );
		const index = images.findIndex( ( image ) => image === element );
		return index >= 0 ? index : undefined;
	};
	// `Animation.rangeStart` / `rangeEnd` are TimelineRangeOffset DICTIONARIES
	// (`{ rangeName, offset: CSSUnitValue }`), not strings — String() yields
	// `[object Object]`, which once reached the emitted CSS verbatim. Serialize
	// the pair the way CSS writes it (`cover 10%`).
	const rangeOffsetText = ( value: unknown ): string => {
		if ( typeof value === 'string' ) {
			const text = value.trim();
			return /^(?:normal|auto)$/i.test( text ) ? '' : text;
		}
		if ( ! value || typeof value !== 'object' ) {
			return '';
		}
		const entry = value as { rangeName?: unknown; offset?: unknown };
		const name =
			typeof entry.rangeName === 'string' && entry.rangeName && entry.rangeName !== 'normal' ? entry.rangeName : '';
		const raw = entry.offset;
		let offset = '';
		if ( typeof raw === 'string' ) {
			offset = raw.trim();
		} else if ( raw && typeof raw === 'object' ) {
			const numeric = raw as { value?: unknown; unit?: unknown };
			if ( typeof numeric.value === 'number' && typeof numeric.unit === 'string' ) {
				offset = numeric.unit === 'percent' ? `${ numeric.value }%` : `${ numeric.value }${ numeric.unit }`;
			} else {
				const serialized = String( raw );
				offset = serialized.includes( '[object' ) ? '' : serialized.trim();
			}
		}
		if ( ! name && ( ! offset || /^(?:normal|auto)$/i.test( offset ) ) ) {
			return '';
		}
		return [ name, offset ].filter( Boolean ).join( ' ' ).trim();
	};
	const facts: Array< Omit< ForgeAnimationFact, 'rebuild' | 'note' > > = [];
	const seen = new Set< string >();
	for ( const animation of document.getAnimations() ) {
		if ( facts.length >= options.maxFacts ) {
			break;
		}
		const effect = animation.effect;
		if ( ! effect || ! ( effect instanceof KeyframeEffect ) || ! effect.target ) {
			continue;
		}
		const target = effect.target;
		const host = target.nodeType === 1 ? ( target as Element ) : ( ( target as unknown as { element?: Element } ).element ?? undefined );
		if ( ! host ) {
			continue;
		}
		const timing = effect.getTiming();
		const rawKeyframes = effect.getKeyframes();
		const keyframes: ForgeAnimationStep[] = [];
		for ( const frame of rawKeyframes.slice( 0, 20 ) ) {
			const declarations: Record< string, string > = {};
			for ( const [ key, value ] of Object.entries( frame ) ) {
				if ( key === 'offset' || key === 'computedOffset' || key === 'easing' || key === 'composite' ) {
					continue;
				}
				if ( typeof value !== 'string' && typeof value !== 'number' ) {
					continue;
				}
				const property = key.replace( /[A-Z]/g, ( letter ) => `-${ letter.toLowerCase() }` );
				declarations[ property ] = String( value );
			}
			if ( Object.keys( declarations ).length === 0 ) {
				continue;
			}
			const offset =
				typeof ( frame as { computedOffset?: number } ).computedOffset === 'number'
					? `${ Math.round( ( frame as { computedOffset: number } ).computedOffset * 100 ) }%`
					: String( ( frame as { offset?: string | number } ).offset ?? '' );
			keyframes.push( { offset, declarations } );
		}
		if ( keyframes.length === 0 ) {
			continue;
		}
		const band = bandOf( host );
		const bandId = bandIdOf( band );
		const type = animation.constructor.name;
		const kind =
			type === 'CSSAnimation' ? 'css-keyframes' : type === 'CSSTransition' ? 'css-transition' : 'waapi';
		const transition = animation as Animation & { transitionProperty?: string; animationName?: string; rangeStart?: string; rangeEnd?: string };
		const name = String( transition.animationName ?? transition.transitionProperty ?? animation.id ?? type );
		const timeline = animation.timeline?.constructor?.name ?? 'DocumentTimeline';
		const iterations = timing.iterations === Infinity ? 'infinite' : String( timing.iterations );
		const properties = [ ...new Set( keyframes.flatMap( ( frame ) => Object.keys( frame.declarations ) ) ) ].slice( 0, 20 );
		const fact: Omit< ForgeAnimationFact, 'rebuild' | 'note' > = {
			kind,
			name,
			...( bandId ? { bandId } : {} ),
			target: targetKindOf( host, band ),
			...( mediaIndexOf( host, band ) !== undefined ? { mediaIndex: mediaIndexOf( host, band ) } : {} ),
			selector: selectorOf( host ),
			targetTag: host.tagName.toLowerCase(),
			targetClasses: Array.from( host.classList ).slice( 0, 6 ),
			duration: typeof timing.duration === 'number' ? `${ timing.duration }ms` : String( timing.duration ),
			delay: typeof timing.delay === 'number' ? `${ timing.delay }ms` : String( timing.delay ),
			easing: String( timing.easing ?? '' ),
			iterations,
			direction: String( timing.direction ?? '' ),
			fill: String( timing.fill ?? '' ),
			playState: animation.playState,
			timeline,
			...( rangeOffsetText( transition.rangeStart ) ? { rangeStart: rangeOffsetText( transition.rangeStart ) } : {} ),
			...( rangeOffsetText( transition.rangeEnd ) ? { rangeEnd: rangeOffsetText( transition.rangeEnd ) } : {} ),
			properties,
			keyframes: keyframes.slice( 0, 20 ),
			infinite: timing.iterations === Infinity,
		};
		const key = `${ fact.kind }:${ fact.name }:${ fact.bandId ?? '' }:${ fact.target ?? '' }:${ fact.selector ?? '' }:${ fact.timeline ?? '' }`;
		if ( seen.has( key ) ) {
			continue;
		}
		seen.add( key );
		facts.push( fact );
	}
	return facts;
}

/**
 * Measure the source's animations in a real browser at rest, mid-scroll and at
 * the bottom (the scroll positions reveal IntersectionObserver-triggered CSS
 * classes). Fail-soft: a browser/policy failure returns `error`, never throws.
 */
export async function measureAnimations( options: MeasureAnimationsOptions ): Promise< ForgeAnimationInventory > {
	const staticFacts = analyzeAnimationSignatures( options.html ?? '', options.css ?? [] );
	let chromium: PlaywrightChromium;
	try {
		chromium = await loadChromium();
		} catch ( error ) {
		return {
			facts: staticFacts.facts,
			libraries: staticFacts.libraries,
			keyframes: staticFacts.keyframes,
			video: staticFacts.video,
			smil: staticFacts.smil,
			error: error instanceof Error ? error.message : String( error ),
		};
	}
	try {
		const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
		try {
			const context = await browser.newContext( { deviceScaleFactor: 1, reducedMotion: 'no-preference' } );
			const page = await context.newPage();
			await page.setViewportSize( { width: 1440, height: 900 } );
			await page.goto( options.url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
			await settle( page );
			const maxFacts = options.maxFacts ?? MAX_FACTS;
			const collected = await page.evaluate( collectAnimations, { maxFacts } );
			const facts = [ ...staticFacts.facts, ...collected ];
			// Scroll once to trigger IO/AOS-style classes, then collect again.
			await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight * 0.5 ) );
			await page.waitForTimeout( 700 );
			facts.push( ...( await page.evaluate( collectAnimations, { maxFacts } ) ) );
			await page.evaluate( () => window.scrollTo( 0, document.documentElement.scrollHeight ) );
			await page.waitForTimeout( 700 );
			facts.push( ...( await page.evaluate( collectAnimations, { maxFacts } ) ) );
			await page.evaluate( () => window.scrollTo( 0, 0 ) );
			// Dedupe by the stable key. Scroll positions produce duplicate frames of
			// the same effect; the key keeps the first (rest) measurement.
			const unique = new Map< string, ForgeAnimationFact >();
			for ( const fact of facts ) {
				const key = `${ fact.kind }:${ fact.name }:${ fact.bandId ?? '' }:${ fact.target ?? '' }:${ fact.selector ?? '' }:${ fact.timeline ?? '' }`;
				if ( ! unique.has( key ) ) {
					unique.set( key, fact );
				}
			}
			return {
				facts: [ ...unique.values() ].slice( 0, maxFacts ),
				libraries: staticFacts.libraries,
				keyframes: staticFacts.keyframes,
				counts: countAnimations( [ ...unique.values() ] ),
				video: staticFacts.video,
				smil: staticFacts.smil,
			};
		} finally {
			await browser.close();
		}
	} catch ( error ) {
		return {
			facts: staticFacts.facts,
			libraries: staticFacts.libraries,
			keyframes: staticFacts.keyframes,
			video: staticFacts.video,
			smil: staticFacts.smil,
			error: error instanceof Error ? error.message : String( error ),
		};
	}
}

/** Count facts by kind and rebuild outcome. */
export function countAnimations( facts: ForgeAnimationFact[] ): Record< string, number > {
	const counts: Record< string, number > = {};
	for ( const fact of facts ) {
		counts[ fact.kind ] = ( counts[ fact.kind ] ?? 0 ) + 1;
		if ( fact.rebuild ) {
			counts[ `rebuild:${ fact.rebuild }` ] = ( counts[ `rebuild:${ fact.rebuild }` ] ?? 0 ) + 1;
		}
	}
	return counts;
}

/** Animation names that are the theme's reveal vocabulary (Phlox `AuxFadeInUp`,
 * `aux-fade-in-up`, AOS/wow/animate). A reveal band is already rebuilt by the
 * CSS-native 121 runtime even though the SOURCE may run it on the document
 * timeline (a class toggled by IntersectionObserver, not a scroll timeline). */
const REVEAL_ANIMATION_NAME_RE = /(?:fade|scale|mask|slide|zoom|blur|rotate|reveal|appear|animate__)/i;

/** The bands the scroll-behavior pass already animates. */
function behaviorBands( behavior?: ScrollBehaviorPass ): Set< string > {
	const bands = new Set< string >();
	for ( const entry of behavior?.entries ?? [] ) {
		if ( entry.id && ( entry.kind === 'reveal' || entry.kind === 'parallax' || entry.kind === 'sticky' ) ) {
			bands.add( entry.id );
		}
	}
	return bands;
}

/** Planner switches. `allowInfinite` is opt-in: the template renders a
 * page-level Pause motion control when the generated module says it needs one
 * (WCAG 2.2.2), so a site WITH that control may rebuild infinite motion. */
export interface PlanAnimationOptions {
	allowInfinite?: boolean;
}

/**
 * Assign a rebuild outcome to every measured fact. Mutates copies, returns the
 * decided list. The rules are deliberately conservative:
 *   - a reveal/parallax band is already rebuilt by the CSS-native 121 runtime;
 *   - a transition is already rebuilt by the interaction-states pass;
 *   - infinite motion is NOT auto-rebuilt unless `allowInfinite` is set (the
 *     template renders a pause control in that case);
 *   - a JS runtime is never vendored;
 *   - only finite, layout-safe CSS keyframes on a band/media target are emitted.
 */
export function planAnimationRebuild(
	facts: ForgeAnimationFact[],
	behavior?: ScrollBehaviorPass,
	options: PlanAnimationOptions = {}
): ForgeAnimationFact[] {
	const bands = behaviorBands( behavior );
	return facts.map( ( fact ) => {
		const next: ForgeAnimationFact = { ...fact, keyframes: fact.keyframes ? [ ...fact.keyframes ] : undefined };
		if ( next.kind === 'css-transition' ) {
			next.rebuild = 'mapped';
			next.note = 'interaction-states pass (hover/focus/press)';
			return next;
		}
		if ( next.kind === 'library' ) {
			next.rebuild = 'deferred';
			const fallback = next.poster ? `static poster: ${ next.poster }` : next.asset ? `asset: ${ next.asset }` : '';
			next.note = `library runtime (${ next.library ?? next.name }) — never vendored${ fallback ? `; ${ fallback }` : '' }`;
			return next;
		}
		if ( next.kind === 'waapi' ) {
			next.rebuild = 'deferred';
			next.note = 'script-driven Web Animation — no vendor JS in the template';
			return next;
		}
		if ( next.kind === 'video' || next.kind === 'smil' ) {
			next.rebuild = 'deferred';
			next.note = next.poster
				? `static poster: ${ next.poster }`
				: next.asset
					? `static source: ${ next.asset }`
					: 'static fallback (poster/first frame) — the runtime is not shipped';
			return next;
		}
		if ( next.bandId && bands.has( next.bandId ) && ( next.timeline !== 'DocumentTimeline' || REVEAL_ANIMATION_NAME_RE.test( next.name ) ) ) {
			next.rebuild = 'mapped';
			next.note = 'scroll-behavior pass (reveal/parallax)';
			return next;
		}
		if ( next.infinite || next.iterations === 'infinite' ) {
			if ( ! options.allowInfinite ) {
				next.rebuild = 'deferred';
				next.note = 'infinite motion — WCAG 2.2.2 needs a pause/stop control; not auto-rebuilt';
				return next;
			}
		}
		if ( ! next.bandId ) {
			next.rebuild = 'deferred';
			next.note = 'target does not map to a rebuilt band carrier';
			return next;
		}
		// Conservative control/text mapping: only a button-like control or a
		// heading with paint-only properties maps to the rebuilt equivalent. A
		// generic div or a layout-animated heading stays deferred.
		const buttonLike =
			next.target === 'control' &&
			( next.targetTag === 'a' || next.targetTag === 'button' ) &&
			( next.targetClasses ?? [] ).some( ( name ) => /button|btn|cta|aux-button|aux-call/i.test( name ) );
		const titleLike =
			next.target === 'text' &&
			/^h[1-6]$/.test( next.targetTag ?? '' ) &&
			( next.properties ?? [] ).every( ( property ) => [ 'opacity', 'color', 'background-color' ].includes( property ) );
		if ( next.target !== 'band' && next.target !== 'media' && ! buttonLike && ! titleLike ) {
			next.rebuild = 'deferred';
			next.note = 'target does not map to a rebuilt band/media/control carrier';
			return next;
		}
		if ( ! next.keyframes || next.keyframes.length < 2 ) {
			next.rebuild = 'deferred';
			next.note = 'no computed keyframes to rebuild';
			return next;
		}
		const unsafe = ( next.properties ?? [] ).filter( ( property ) => ! SAFE_ANIMATION_PROPERTIES.has( property ) );
		if ( unsafe.length > 0 ) {
			next.rebuild = 'deferred';
			next.note = `non-compositor property (${ unsafe.slice( 0, 4 ).join( ', ' ) })`;
			return next;
		}
		next.rebuild = 'css';
		next.note = next.infinite
			? 'CSS keyframe rebuild — infinite motion with the page Pause motion control'
			: next.target === 'control'
				? 'CSS keyframe rebuild (control)'
				: next.target === 'text'
					? 'CSS keyframe rebuild (title)'
					: next.timeline === 'ScrollTimeline' || next.timeline === 'ViewTimeline'
						? 'CSS scroll-driven rebuild'
						: 'CSS keyframe rebuild';
		return next;
	} );
}

/** A stable hash for the generated keyframes name. */
export function animationHash( bandId: string, name: string, target: string, mediaIndex?: number ): string {
	const input = `${ bandId }|${ name }|${ target }|${ mediaIndex ?? '' }`;
	let hash = 5381;
	for ( let index = 0; index < input.length; index++ ) {
		hash = ( ( hash << 5 ) + hash + input.charCodeAt( index ) ) >>> 0;
	}
	return hash.toString( 36 );
}

/** The media carriers a rebuilt band renders, in source media order. */
function mediaCarriers( scoped: string, ordinal: number ): string[] {
	const nth = ordinal + 1;
	return [
		`${ scoped } .ds-media:nth-of-type(${ nth })`,
		`${ scoped } .ds-icon-media img:nth-of-type(${ nth })`,
		`${ scoped } .ds-card:nth-of-type(${ nth })`,
		`${ scoped } .ds-gallery img:nth-of-type(${ nth })`,
	];
}

/** The carriers a fact targets in the rebuilt band. */
function targetCarriers( scoped: string, fact: ForgeAnimationFact ): string[] {
	if ( fact.target === 'band' ) {
		return [ scoped ];
	}
	if ( fact.target === 'control' ) {
		return [ `${ scoped } .ds-button`, `${ scoped } .call-button` ];
	}
	if ( fact.target === 'text' ) {
		return [ `${ scoped } .ds-title`, `${ scoped } h1`, `${ scoped } h2`, `${ scoped } h3` ];
	}
	return mediaCarriers( scoped, fact.mediaIndex ?? 0 );
}

/** A range offset the emitter can write verbatim (`cover 10%`, `entry`, `40%`). */
function validRangeOffset( value?: string ): boolean {
	const text = ( value ?? '' ).trim();
	if ( ! text || text.includes( '[' ) || /^(?:normal|auto)$/i.test( text ) ) {
		return false;
	}
	return /^(?:[a-z-]+(?:\s+[-0-9.]+%?)?|[-0-9.]+%)$/i.test( text );
}

/**
 * Emit scoped CSS for the finite, layout-safe keyframe facts. Infinite motion
 * is allowed only when the caller planned it with `allowInfinite` (the page
 * Pause motion control exists in that case). JS runtimes never reach here.
 *
 * `animation-timeline` stays a LONGHAND — the minifier regression from 121
 * applies here too. `prefers-reduced-motion` is the outer gate, so the page is
 * complete at rest for a reader who asks for less motion.
 */
export function animationRebuildCss( facts: ForgeAnimationFact[] ): string {
	return facts
		.filter( ( fact ) => fact.rebuild === 'css' && fact.bandId && fact.keyframes && fact.keyframes.length > 0 )
		.map( ( fact ) => {
			const hash = animationHash( fact.bandId as string, fact.name, fact.target ?? '', fact.mediaIndex );
			const steps = ( fact.keyframes ?? [] )
				.map( ( step ) => {
					const declarations = Object.entries( step.declarations )
						.map( ( [ property, value ] ) => `${ property }:${ value }` )
						.join( ';' );
					return `${ step.offset || '0%' }{${ declarations }}`;
				} )
				.join( '' );
			const scoped = `[data-section-id="${ fact.bandId }"]`;
			const selectors = targetCarriers( scoped, fact );
			const declarations = [
				`animation-name:forge-anim-${ hash }`,
				`animation-duration:${ fact.duration ?? '1s' }`,
				fact.easing ? `animation-timing-function:${ fact.easing }` : '',
				fact.delay ? `animation-delay:${ fact.delay }` : '',
				fact.iterations ? `animation-iteration-count:${ fact.iterations }` : '',
				fact.direction ? `animation-direction:${ fact.direction }` : '',
				`animation-fill-mode:${ fact.fill && fact.fill !== 'none' ? fact.fill : 'both' }`,
				fact.timeline === 'ScrollTimeline' ? 'animation-timeline:scroll(root)' : '',
				fact.timeline === 'ViewTimeline' ? 'animation-timeline:view()' : '',
				fact.rangeStart && validRangeOffset( fact.rangeStart )
					? `animation-range:${ fact.rangeStart }${ fact.rangeEnd && validRangeOffset( fact.rangeEnd ) ? ` ${ fact.rangeEnd }` : '' }`
					: '',
			]
				.filter( Boolean )
				.join( ';' );
			return `@keyframes forge-anim-${ hash }{${ steps }}@media (prefers-reduced-motion: no-preference){${ selectors.join( ',' ) }{${ declarations }}}`;
		} )
		.join( '' );
}

/** The expected built key for a CSS-rebuilt fact (the generated name). */
function expectedBuiltKey( fact: ForgeAnimationFact ): string {
	if ( ! fact.bandId ) {
		return '';
	}
	const hash = animationHash( fact.bandId, fact.name, fact.target ?? '', fact.mediaIndex );
	return `css-keyframes:forge-anim-${ hash }:${ fact.bandId }:${ fact.target ?? '' }`;
}

/** A timing deviation between a source CSS fact and its built animation. */
export interface AnimationTimingMismatch {
	fact: ForgeAnimationFact;
	property: 'duration' | 'iterations' | 'easing';
	expected: string;
	actual: string;
}

/** Parse a CSS time (`1.2s`, `1200ms`) to ms; undefined when not a time. */
function durationMs( value: string | undefined ): number | undefined {
	if ( ! value || value === 'auto' ) {
		return undefined;
	}
	const match = /^(-?\d+(?:\.\d+)?)(ms|s)$/.exec( value.trim() );
	if ( ! match ) {
		return undefined;
	}
	const amount = Number.parseFloat( match[ 1 ] );
	return match[ 2 ] === 's' ? amount * 1000 : amount;
}

/** Compare the timing a source fact asked for with what the build computed. */
function timingMismatches( source: ForgeAnimationFact, built: ForgeAnimationFact ): AnimationTimingMismatch[] {
	const out: AnimationTimingMismatch[] = [];
	const sourceDuration = durationMs( source.duration );
	const builtDuration = durationMs( built.duration );
	if ( sourceDuration !== undefined && builtDuration !== undefined ) {
		const tolerance = Math.max( 50, sourceDuration * 0.05 );
		if ( Math.abs( sourceDuration - builtDuration ) > tolerance ) {
			out.push( { fact: source, property: 'duration', expected: source.duration ?? '', actual: built.duration ?? '' } );
		}
	}
	if ( source.iterations && built.iterations && source.iterations !== built.iterations ) {
		out.push( { fact: source, property: 'iterations', expected: source.iterations, actual: built.iterations } );
	}
	if ( source.easing && built.easing && source.easing.trim().toLowerCase() !== built.easing.trim().toLowerCase() ) {
		out.push( { fact: source, property: 'easing', expected: source.easing, actual: built.easing } );
	}
	return out;
}

/** Compare the source inventory with the built page's measured facts. */
export function diffAnimations(
	source: ForgeAnimationFact[],
	built: ForgeAnimationFact[],
	builtCss?: string
): { missing: ForgeAnimationFact[]; extra: ForgeAnimationFact[]; timing: AnimationTimingMismatch[]; matched: number } {
	const builtKeys = new Set(
		built.map( ( fact ) => `${ fact.kind }:${ fact.name }:${ fact.bandId ?? '' }:${ fact.target ?? '' }` )
	);
	const expected = source.filter( ( fact ) => fact.rebuild === 'css' );
	const missing: ForgeAnimationFact[] = [];
	const timing: AnimationTimingMismatch[] = [];
	for ( const fact of expected ) {
		const key = expectedBuiltKey( fact );
		if ( builtKeys.has( key ) ) {
			const builtFact = built.find(
				( candidate ) => `${ candidate.kind }:${ candidate.name }:${ candidate.bandId ?? '' }:${ candidate.target ?? '' }` === key
			);
			if ( builtFact ) {
				timing.push( ...timingMismatches( fact, builtFact ) );
			}
			continue;
		}
		// A finished animation can fall out of getAnimations(); the built HTML
		// still carries the deterministic keyframes, so use the CSS as the
		// presence fallback before reporting a regression.
		const hash = fact.bandId ? animationHash( fact.bandId, fact.name, fact.target ?? '', fact.mediaIndex ) : '';
		if ( builtCss && hash && builtCss.includes( `@keyframes forge-anim-${ hash }` ) ) {
			continue;
		}
		missing.push( fact );
	}
	const sourceKeys = new Set( expected.map( expectedBuiltKey ) );
	const extra = built.filter(
		( fact ) => fact.kind === 'css-keyframes' && fact.name.startsWith( 'forge-anim-' ) && ! sourceKeys.has( `${ fact.kind }:${ fact.name }:${ fact.bandId ?? '' }:${ fact.target ?? '' }` )
	);
	return { missing, extra, timing, matched: expected.length - missing.length };
}
