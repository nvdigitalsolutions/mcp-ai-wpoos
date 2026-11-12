// ---------------------------------------------------------------------------
// geometry.ts — rendered box geometry, measured instead of inferred.
//
// `scoreParity` compares the design LANGUAGE (roles, scale, spacing); it cannot
// see that a card renders 903px tall because a width/height attribute beat
// `aspect-ratio`, or that a band pulls 170px on desktop where the source is
// flush. Both shipped. This module measures the boxes in a real browser and
// diffs build against source (Proposal 114 §3.6, the `--baselines` stub).
//
// Deliberate scope, per the visual-regression practice of comparing geometry
// rather than pixels: box metrics are stable across machines and fonts where
// pixel diffs are not, so no threshold tuning or Docker font pinning is needed.
// ---------------------------------------------------------------------------

import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { readFile, stat } from 'node:fs/promises';
import { extname, join, normalize, sep } from 'node:path';

import type {
	BandBoxDeclaration,
	BandBoxFacts,
	BandTypeFact,
	BandTypeProperties,
	ForgeBandContainer,
	ForgeBandFacts,
	ForgeChromeBox,
	ForgeChrome,
	ForgeChromeGeometry,
	ForgeGeometry,
	ForgeMobileNav,
	ForgeStickyHeader,
	ForgeTopbarFacts,
} from './types.ts';

export interface GeometryViewport {
	name: string;
	width: number;
	height: number;
}

/**
 * The three viewports the rebuild is judged at. The tablet/mobile widths sit
 * inside Elementor's own ranges (mobile ≤767, tablet 768–1024) so a device
 * -scoped rule is exercised on both sides of each breakpoint.
 */
export const DEFAULT_GEOMETRY_VIEWPORTS: GeometryViewport[] = [
	{ name: 'desktop', width: 1440, height: 900 },
	{ name: 'tablet', width: 834, height: 1112 },
	{ name: 'mobile', width: 390, height: 844 },
];

/** Allowable drift per metric, in CSS pixels. */
export interface GeometryTolerance {
	height: number;
	width: number;
	spacing: number;
}

/**
 * Small and justified: spacing declarations are exact, so they get 1px of
 * sub-pixel rounding; heights absorb line-box rounding and are the noisiest.
 * Beyond 2× the tolerance is a failure, not a warning.
 */
export const DEFAULT_GEOMETRY_TOLERANCE: GeometryTolerance = {
	height: 10,
	width: 2,
	spacing: 1,
};

export interface GeometryDeviation {
	sectionId: string;
	viewport: string;
	metric: string;
	expected: number | null;
	actual: number | null;
	delta: number;
	severity: 'warn' | 'fail';
}

type PlaywrightChromium = typeof import('playwright').chromium;

/** Load Playwright's chromium binding, or explain how to install it. Shared by
 *  the geometry pass and the band diff (`lib/band-diff.ts`). */
export async function loadChromium(): Promise< PlaywrightChromium > {
	try {
		const playwright = await import('playwright');
		return playwright.chromium;
	} catch {
		throw new Error(
			'Geometry measurement needs Playwright. Install it in the forge (npm i -D playwright).'
		);
	}
}

/**
 * Wait until the page's boxes have stopped moving: fonts loaded, every
 * viewport scrolled through so lazy images have fetched, and animations off.
 * Lazy image loading is the one thing that silently changes band height.
 */
async function settle( page: import('playwright').Page ): Promise< void > {
	await page.addStyleTag( {
		content:
			'*,*::before,*::after{animation:none!important;transition:none!important;' +
			'scroll-behavior:auto!important}html{scroll-behavior:auto!important}',
	} );
	await page.evaluate( async () => {
		const doc = document as Document & { fonts?: { ready: Promise< unknown > } };
		if ( doc.fonts ) {
			await doc.fonts.ready;
		}
		const height = document.body.scrollHeight;
		for ( let y = 0; y < height; y += window.innerHeight ) {
			window.scrollTo( 0, y );
			await new Promise( ( resolve ) => setTimeout( resolve, 60 ) );
		}
		window.scrollTo( 0, 0 );
		await Promise.all(
			Array.from( document.images )
				.filter( ( image ) => ! image.complete )
				.map(
					( image ) =>
						new Promise( ( resolve ) => {
							image.addEventListener( 'load', resolve, { once: true } );
							image.addEventListener( 'error', resolve, { once: true } );
							setTimeout( resolve, 3000 );
						} )
				)
		);
	} );
}

export interface MeasureOptions {
	url: string;
	/** The section ids to measure (the profile's bands). */
	ids: string[];
	/** `built` reads `[data-section-id]`; `source` reads Elementor's class. */
	kind: 'built' | 'source';
	/**
	 * An explicit SOURCE selector per band id. Elementor sites expose
	 * `.elementor-element-<id>`; outline-band builders (WPBakery/Brando, Bricks,
	 * Oxygen) expose no such class at all, so the id selector silently measures
	 * nothing and the whole baseline comes back empty. Those sources do carry
	 * the band's own anchor (`#about`, `#contact`… — the one the nav links to),
	 * which the caller passes here. Tried after the Elementor class so an
	 * Elementor profile keeps its exact match.
	 */
	selectors?: Record< string, string >;
	viewports?: GeometryViewport[];
	timeout?: number;
}

/** Measure each band's box at each viewport. */
export async function measureGeometry( options: MeasureOptions ): Promise< ForgeGeometry > {
	const chromium = await loadChromium();
	const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
	try {
		const context = await browser.newContext( { deviceScaleFactor: 1 } );
		const page = await context.newPage();
		const geometry: ForgeGeometry = {};
		for ( const viewport of options.viewports ?? DEFAULT_GEOMETRY_VIEWPORTS ) {
			await page.setViewportSize( { width: viewport.width, height: viewport.height } );
			await page.goto( options.url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
			await settle( page );
			const measured = await page.evaluate( ( { ids, kind, selectors } ) => {
				const out: Record< string, Record< string, number > > = {};
				for ( const id of ids ) {
					// Candidate order is precise-first: the Elementor class is exact
					// when it exists, and the caller's selector covers outline-band
					// builders. No `#<id>` fallback — a profile id is a hash
					// (`45f5e88`), and `#45f5e88` is an INVALID selector that throws,
					// which used to abort the entire baseline on the first band.
					const candidates =
						kind === 'built'
							? [ `[data-section-id="${ id }"]` ]
							: [ `.elementor-element-${ id }`, selectors?.[ id ] ].filter(
									( value ): value is string => Boolean( value )
							  );
					// A malformed selector must cost one band, never the whole run.
					const pick = ( selector: string ): Element | null => {
						try {
							return document.querySelector( selector );
						} catch {
							return null;
						}
					};
					const matched = candidates.map( pick ).find( ( node ): node is Element => Boolean( node ) ) ?? null;
					// A source anchor can sit on a COLUMN rather than the band row
					// (Brando puts `id="wifi"` on a `wpb_column`). The rebuild draws the
					// BAND, so measuring the column compared a half-width box against a
					// full-width band and reported a phantom 720px/1440px failure.
					// Promote to the nearest band ancestor — the `<section>` the builder
					// wraps its rows in, or the row itself when there is no such
					// wrapper. `<section>` wins over `.row` because the row is an inner
					// grid that carries none of the band's own padding.
					const ROW = /(^|\s)(row|vc_row|wpb_row)(\s|$)|brando[_-](inner[_-])?row/;
					const COLUMN = /(^|\s)(wpb_column|vc_column_container)(\s|$)/;
					let element = matched;
					if ( kind === 'source' && element && COLUMN.test( element.getAttribute( 'class' ) ?? '' ) ) {
						let row: Element | null = null;
						for ( let up = element.parentElement; up; up = up.parentElement ) {
							if ( up.tagName === 'SECTION' ) {
								element = up;
								row = null;
								break;
							}
							if ( ! row && ROW.test( up.getAttribute( 'class' ) ?? '' ) ) {
								row = up;
							}
						}
						if ( row ) {
							element = row;
						}
					}
					if ( ! element ) {
						continue;
					}
					const rect = element.getBoundingClientRect();
					const style = getComputedStyle( element );
					out[ id ] = {
						height: rect.height,
						width: rect.width,
						paddingTop: parseFloat( style.paddingTop ) || 0,
						paddingBottom: parseFloat( style.paddingBottom ) || 0,
						paddingLeft: parseFloat( style.paddingLeft ) || 0,
						paddingRight: parseFloat( style.paddingRight ) || 0,
						marginTop: parseFloat( style.marginTop ) || 0,
						marginBottom: parseFloat( style.marginBottom ) || 0,
					};
				}
				return out;
			}, { ids: options.ids, kind: options.kind, selectors: options.selectors } );
			for ( const [ id, band ] of Object.entries( measured ) ) {
				geometry[ id ] ??= {};
				geometry[ id ][ viewport.name ] = {
					height: Math.round( band.height ),
					width: Math.round( band.width ),
					paddingTop: Math.round( band.paddingTop ),
					paddingBottom: Math.round( band.paddingBottom ),
					paddingLeft: Math.round( band.paddingLeft ),
					paddingRight: Math.round( band.paddingRight ),
					marginTop: Math.round( band.marginTop ),
					marginBottom: Math.round( band.marginBottom ),
				};
			}
		}
		return geometry;
	} finally {
		await browser.close();
	}
}

/** The device passes the band facts are measured at (the template's breakpoints). */
export const BAND_FACT_VIEWPORTS = {
	desktop: { width: 1440, height: 900 },
	tablet: { width: 834, height: 1112 },
	mobile: { width: 390, height: 844 },
} as const;

type DeviceKey = keyof typeof BAND_FACT_VIEWPORTS;

/**
 * The viewports the header box is measured at. `wide` is the only one where a
 * large container cap (jayainc: 1670px) is narrower than the viewport, so it is
 * the fact that distinguishes "boxed" from "full width" on a wide monitor.
 */
export const GEOMETRY_VIEWPORTS = {
	wide: { width: 1920, height: 1080 },
	desktop: { width: 1440, height: 900 },
	tablet: { width: 834, height: 1112 },
	mobile: { width: 390, height: 844 },
} as const;

/**
 * One band's typography at the CURRENT viewport.
 *
 * Module scope on purpose: Playwright serialises the function, so it can close
 * over nothing. Called once per device by `measureBandFacts`.
 */
function collectBandTypeFacts( input: {
	ids: string[];
	kind: 'built' | 'source';
	selectors?: Record< string, string >;
} ): Record<
		string,
		{
				containerWidth: number;
				containerPadX: number;
				containerInner: boolean;
				containerFlush: boolean;
				headings: Record< string, BandTypeProperties >;
				body?: BandTypeProperties;
			}
	> {
const pick = ( selector: string ): Element | null => {
		try {
			return document.querySelector( selector );
		} catch {
			return null;
		}
	};
	const COLUMN = /(^|\s)(wpb_column|vc_column_container)(\s|$)/;
	const ROW = /(^|\s)(row|vc_row|wpb_row)(\s|$)|brando[_-](inner[_-])?row/;
	const fact = ( node: Element ): BandTypeProperties => {
		const style = getComputedStyle( node );
		return {
			size: style.fontSize,
			weight: style.fontWeight,
			lineHeight: style.lineHeight,
			letterSpacing: style.letterSpacing,
			transform: style.textTransform,
			align: style.textAlign,
			family: ( style.fontFamily || '' ).split( ',' )[ 0 ].replace( /["']/g, '' ).trim(),
			// The source's own ink. Builder colours live in inline `style` that
			// sanitisation drops, so the computed value is the only surviving copy.
			color: style.color,
			// A builder can deliberately NARROW a heading (jayainc's services title
			// is `max-width: 473px` inside a 640px column, so it wraps to three
			// lines). Without the fact the rebuild renders it full-width, two lines,
			// and the band's pinned height shows the shortfall as dead space.
			maxWidth: style.maxWidth,
		};
	};
	const facts: Record<
		string,
		{
			containerWidth: number;
			containerPadX: number;
			containerInner: boolean;
			containerFlush: boolean;
			headings: Record< string, BandTypeProperties >;
			body?: BandTypeProperties;
		}
> = {};
	for ( const id of input.ids ) {
		const candidates =
			input.kind === 'built'
				? [ `[data-section-id="${ id }"]` ]
				: [ `.elementor-element-${ id }`, input.selectors?.[ id ] ].filter(
						( value ): value is string => Boolean( value )
				  );
		let element = candidates.map( pick ).find( ( node ): node is Element => Boolean( node ) ) ?? null;
		// The same column → band promotion the box pass uses: a source anchor can
		// sit on a `wpb_column`.
		if ( input.kind === 'source' && element && COLUMN.test( element.getAttribute( 'class' ) ?? '' ) ) {
			let row: Element | null = null;
			for ( let up = element.parentElement; up; up = up.parentElement ) {
				if ( up.tagName === 'SECTION' ) {
					element = up;
					row = null;
					break;
				}
				if ( ! row && ROW.test( up.getAttribute( 'class' ) ?? '' ) ) {
					row = up;
				}
			}
			if ( row ) {
				element = row;
			}
		}
		if ( ! element ) {
			continue;
		}
		// The band's inner container: `.container` boxes it, `.container-fluid` is
		// the viewport. Elementor's `.elementor-container` (and the 4.x
		// `.e-con-inner`) is the element that actually boxes its columns — without
		// it the SECTION itself was measured, so every band looked full-width
		// (jayainc's services band reported 1440 where its own container is the
		// same but the products band's cap is real). The CONTENT width is what the
		// columns get, so the band's own gutters are subtracted — and recorded,
		// because the rebuild needs them as its own `--ds-pad-x` (a constant was
		// wrong at every viewport but desktop).
		const container =
			element.querySelector( '.elementor-container, .e-con-inner' ) ??
			element.querySelector( '.container, .container-fluid' ) ??
			element.querySelector( '.row, .vc_row, .wpb_row' ) ??
			element;
		// Only a DESCENDANT container is a distinct inset. When the band itself is
		// the container, its padding already reaches the rebuild through the
		// measured band padding, and emitting it again would double the gutter.
		const containerInner = container !== element;
		const containerStyle = getComputedStyle( container );
		const padLeft = parseFloat( containerStyle.paddingLeft ) || 0;
		const padRight = parseFloat( containerStyle.paddingRight ) || 0;
		const containerWidth = Math.round(
			container.getBoundingClientRect().width - padLeft - padRight
		);
		const containerPadX = Math.round( Math.max( padLeft, padRight ) * 100 ) / 100;
		// Does the band's own grid cancel that gutter? A Bootstrap-family `.row`
		// carries a NEGATIVE horizontal margin that pulls its columns back out over
		// the container's padding. The rebuild's stylesheet never defines `.row`, so
		// it must compensate with a flush section — measuring the sign is the only
		// faithful way to know (Brando/Bootstrap: yes; a plain flex theme: no).
		const firstChild = containerInner ? container.firstElementChild : null;
		const childMarginLeft = firstChild
			? parseFloat( getComputedStyle( firstChild ).marginLeft ) || 0
			: 0;
		const containerFlush = firstChild !== null && childMarginLeft < 0;
		const headings: Record< string, BandTypeProperties > = {};
		for ( const tag of [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ] ) {
			const nodes = Array.from( element.querySelectorAll( tag ) ).filter(
				( node ) => ! node.closest( 'form' ) && ( node.textContent ?? '' ).trim().length > 0
			);
			if ( nodes.length === 0 ) {
				continue;
			}
			// The LARGEST rendered instance is the band's claim on that level.
			let best = nodes[ 0 ];
			let bestSize = parseFloat( getComputedStyle( nodes[ 0 ] ).fontSize ) || 0;
			for ( const node of nodes ) {
				const size = parseFloat( getComputedStyle( node ).fontSize ) || 0;
				if ( size > bestSize ) {
					best = node;
					bestSize = size;
				}
			}
			headings[ tag ] = fact( best );
		}
		// The body scale is the MOST COMMON paragraph size — an intro can be set
		// larger than the band's body copy.
		let body: BandTypeProperties | undefined;
		const paragraphs = Array.from( element.querySelectorAll( 'p' ) ).filter(
			( node ) => ! node.closest( 'form' ) && ( node.textContent ?? '' ).trim().length > 20
		);
		if ( paragraphs.length > 0 ) {
			const counts = new Map< string, number >();
			for ( const node of paragraphs ) {
				const size = getComputedStyle( node ).fontSize;
				counts.set( size, ( counts.get( size ) ?? 0 ) + 1 );
			}
			const common = Array.from( counts.entries() ).sort( ( a, b ) => b[ 1 ] - a[ 1 ] )[ 0 ][ 0 ];
			const sampled = paragraphs.find( ( node ) => getComputedStyle( node ).fontSize === common );
			if ( sampled ) {
				body = fact( sampled );
			}
		}
		facts[ id ] = {
			containerWidth,
			containerPadX,
			containerInner,
			containerFlush,
			headings,
			...( body ? { body } : {} ),
		};
	}
	return facts;
}

/** The typography properties a device override may carry. */
const TYPE_PROPERTIES = [ 'size', 'weight', 'lineHeight', 'letterSpacing', 'transform', 'align', 'color' ] as const;

/** One class's measured box, as resolved declarations. */
export interface MeasuredBox {
	/** `padding` shorthand in px, when consistent across instances. */
	pad?: string;
	/** `margin` shorthand in px, when consistent across instances. */
	mar?: string;
	/** An explicitly-sized replaced element's height (a cropped source image). */
	height?: string;
	/**
	 * The `color` this class OVERRIDES on its element — recorded only when the
	 * class paints the text a colour other than the one it inherits, and only
	 * when every instance of the class in the band agrees.
	 *
	 * A builder declares text ink in its stylesheet (`ul.medium-gray-text {
	 * color:#929292 }`) and the sanitizer ships the class name without the
	 * vendor sheet, so the rebuilt copy inherits the panel's colour instead
	 * (virtuallyjamaica's four grey service bullets rendered white). An
	 * INHERITED colour is not a fact about the class and is never recorded, so
	 * restating it can never fight the parent that legitimately sets it.
	 */
	color?: string;
}

/**
 * One band's measured boxes at the CURRENT viewport, keyed by class.
 *
 * Module scope on purpose: Playwright serialises the function, so it can close
 * over nothing. Called once per device by `measureBandFacts`.
 *
 * Why measure instead of transcribing the builder's spacing classes: Brando's
 * numeric scale is not a ladder (`margin-four-bottom` is 7.8px on an icon and
 * 21.6px on a paragraph) and its responsive variants are not the values their
 * names state at every width (`padding-four-all` is 3.4rem on desktop but
 * `40px 15.5938px` at 390px). The computed box is the only faithful source.
 *
 * Only classes that resolve to the SAME padding/margin on every instance in the
 * band are recorded — a shared class used at two sizes gets no rule rather than
 * an arbitrary one. Brando's per-column/per-module hashes appear once, so the
 * column padding the rebuild otherwise loses is always captured.
 */
function collectBandBoxFacts( input: {
	ids: string[];
	kind: 'built' | 'source';
	selectors?: Record< string, string >;
} ): Record<
	string,
	{ boxes: Record< string, MeasuredBox >; media: Record< string, MeasuredBox >; mediaPosition?: string }
> {
	const pick = ( selector: string ): Element | null => {
		try {
			return document.querySelector( selector );
		} catch {
			return null;
		}
	};
	const COLUMN = /(^|\s)(wpb_column|vc_column_container)(\s|$)/;
	const ROW = /(^|\s)(row|vc_row|wpb_row)(\s|$)|brando[_-](inner[_-])?row/;
	const round = ( value: string ): number => {
		const number = parseFloat( value );
		return Number.isFinite( number ) ? Math.round( number * 100 ) / 100 : 0;
	};
	// Units are mandatory: `padding:57.59 57.59 57.59 57.59` is an INVALID
	// declaration and the browser drops it silently, which is how the measured
	// column padding refilled nothing at all on the first pass.
	const px = ( value: string ): string => `${ round( value ) }px`;
	const boxesOf = ( node: Element ): string =>
		[ 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft' ]
			.map( ( side ) => px( getComputedStyle( node )[ side as 'paddingTop' ] ) )
			.join( ' ' );
	const marginsOf = ( node: Element ): string =>
		[ 'marginTop', 'marginRight', 'marginBottom', 'marginLeft' ]
			.map( ( side ) => px( getComputedStyle( node )[ side as 'marginTop' ] ) )
			.join( ' ' );
	// The Bootstrap grid is a COUPLED set: `.container-fluid` carries 15px of
	// side padding that `.row`'s -15px margin cancels. Restating one half without
	// the other shrinks the band (virtuallyjamaica's #wifi rendered 1376px wide
	// against the source's 1440 and grew 52px tall). The template owns the outer
	// grid, so the measured sheet restates the COLUMN and module boxes — which is
	// where the source's layout actually lives — and leaves the scaffolding alone.
	const SCAFFOLDING = /(^|\s)(container|container-fluid|row|vc_row|wpb_row|vc_row-fluid)(\s|$)/;
	const out: Record<
		string,
		{ boxes: Record< string, MeasuredBox >; media: Record< string, MeasuredBox >; mediaPosition?: string }
	> = {};
	for ( const id of input.ids ) {
		// The band root — same ladder as `collectBandTypeFacts`, duplicated because
		// Playwright ships this function's source without any module scope.
		const candidates =
			input.kind === 'built'
				? [ `[data-section-id="${ id }"]` ]
				: [ `.elementor-element-${ id }`, input.selectors?.[ id ] ].filter(
						( value ): value is string => Boolean( value )
				  );
		let element = candidates.map( pick ).find( ( node ): node is Element => Boolean( node ) ) ?? null;
		if ( input.kind === 'source' && element && COLUMN.test( element.getAttribute( 'class' ) ?? '' ) ) {
			let row: Element | null = null;
			for ( let up = element.parentElement; up; up = up.parentElement ) {
				if ( up.tagName === 'SECTION' ) {
					element = up;
					row = null;
					break;
				}
				if ( ! row && ROW.test( up.getAttribute( 'class' ) ?? '' ) ) {
					row = up;
				}
			}
			if ( row ) {
				element = row;
			}
		}
		if ( ! element ) {
			continue;
		}
		// class → the distinct values seen. Box facts and MEDIA heights are kept
		// apart on purpose: a class can sit on an image AND on a paragraph
		// (`padding-six-lr` does both), and a cropped image's fixed height applied
		// to the paragraph made it 712px tall (virtuallyjamaica, 2026-11-11).
		const seen = new Map< string, { pads: Set< string >; mars: Set< string >; colors: Set< string > } >();
		const mediaHeights = new Map< string, Set< string > >();
		// The source's own crop anchor. A builder photo layer is `object-fit: cover`
		// plus an `object-position` written in a `<style>` block sanitisation drops
		// (Flatsome's hero is `63% 90%`), so copying the markup without this fact
		// re-crops every photo to the centre (monicarajiv.com, 2026-11-11).
		const mediaPositions = new Map< string, number >();
		// Computed ink, cached: siblings share a parent and this pass already
		// resolves style four times per node.
		const colorCache = new Map< Element, string >();
		const colorOf = ( target: Element | null ): string => {
			if ( ! target ) {
				return '';
			}
			const cached = colorCache.get( target );
			if ( cached !== undefined ) {
				return cached;
			}
			const value = getComputedStyle( target ).color;
			colorCache.set( target, value );
			return value;
		};
		for ( const node of Array.from( element.querySelectorAll( '*' ) ) ) {
			const classes = ( node.getAttribute( 'class' ) ?? '' ).split( /\s+/ ).filter( Boolean );
			if ( classes.length === 0 ) {
				continue;
			}
			if ( SCAFFOLDING.test( node.getAttribute( 'class' ) ?? '' ) ) {
				continue;
			}
			const pad = boxesOf( node );
			const mar = marginsOf( node );
			// The class's OWN ink: a colour that differs from the one the node
			// inherits. An inherited colour says nothing about the class (the parent
			// already painted it), so it is recorded as the empty string and a class
			// that overrides on one instance but inherits on another gets no rule.
			const nodeColor = colorOf( node );
			const parentColor = colorOf( node.parentElement );
			const ownColor = parentColor && nodeColor !== parentColor ? nodeColor : '';
			// A replaced element the source CROPS: its rendered aspect disagrees with
			// the file's. Recording the height is how the rebuild keeps the source's
			// band height when it swaps in a differently-sized rendition.
			let height: string | undefined;
			if ( node.tagName === 'IMG' ) {
				const img = node as HTMLImageElement;
				const rect = img.getBoundingClientRect();
				const natural = img.naturalHeight / ( img.naturalWidth || 1 );
				const rendered = rect.height / ( rect.width || 1 );
				if ( rect.height > 0 && img.naturalWidth > 0 && Math.abs( natural - rendered ) > 0.02 ) {
					height = `${ round( String( rect.height ) ) }px`;
				}
				// A cropped cover image carries the source's crop anchor.
				if ( rect.height > 0 && rect.width > 0 && getComputedStyle( node ).objectFit === 'cover' ) {
					const position = getComputedStyle( node ).objectPosition || '50% 50%';
					mediaPositions.set( position, ( mediaPositions.get( position ) ?? 0 ) + 1 );
				}
			}
			for ( const name of classes ) {
				if ( ! seen.has( name ) ) {
					seen.set( name, { pads: new Set(), mars: new Set(), colors: new Set() } );
				}
				const entry = seen.get( name ) as { pads: Set< string >; mars: Set< string >; colors: Set< string > };
				entry.pads.add( pad );
				entry.mars.add( mar );
				entry.colors.add( ownColor );
				if ( height ) {
					// Recorded against the class but emitted as `img.<class>` ONLY — a
					// class shared with a paragraph must never carry the image's height.
					if ( ! mediaHeights.has( name ) ) {
						mediaHeights.set( name, new Set() );
					}
					( mediaHeights.get( name ) as Set< string > ).add( height );
				}
			}
		}
		const facts: Record< string, MeasuredBox > = {};
		for ( const [ name, entry ] of seen ) {
			const box: MeasuredBox = {};
			const pad = [ ...entry.pads ][ 0 ];
			// ZERO IS A FACT, not noise: the source's `margin: 0` on a heading must
			// outrank the template's prose default, otherwise the rebuilt heading
			// carries 17px of margin the source never had. Only an INCONSISTENT
			// class is dropped.
			if ( entry.pads.size === 1 ) {
				box.pad = pad;
			}
			const mar = [ ...entry.mars ][ 0 ];
			if ( entry.mars.size === 1 ) {
				box.mar = mar;
			}
			const color = [ ...entry.colors ][ 0 ];
			if ( entry.colors.size === 1 && color ) {
				box.color = color;
			}
			if ( Object.keys( box ).length > 0 ) {
				facts[ name ] = box;
			}
		}
		const media: Record< string, MeasuredBox > = {};
		for ( const [ name, heights ] of mediaHeights ) {
			if ( heights.size === 1 ) {
				media[ name ] = { height: [ ...heights ][ 0 ] };
			}
		}
		out[ id ] = {
			boxes: facts,
			media,
			// Default is not a fact: the rebuild already centres.
			...( mediaPositions.size > 0
				? ( () => {
						const [ best ] = [ ...mediaPositions.entries() ].sort( ( a, b ) => b[ 1 ] - a[ 1 ] );
						return best && best[ 0 ] !== '50% 50%' ? { mediaPosition: best[ 0 ] } : {};
				  } )()
				: {} ),
		};
	}
	return out;
}

/**
 * The hero track's column SPLIT and the band's VERTICAL-CENTRING mechanism,
 * measured.
 *
 * The structural hero rule in the template (`:has(> div > h1):has(> div > img)`)
 * recognises the source's copy + illustration row, but its ratio was a guess
 * (`1.15fr / 0.95fr`). virtuallyjamaica's copy column is 60% of the track (691px
 * of 1152) and the illustration 40%; at the guessed ratio the H1 wrapped to four
 * lines where the source has three, which also moved the vertically-centred
 * copy. The ratio lives only in the vendor stylesheet — there is no width class
 * in the markup — so the browser is the only source.
 *
 * `centered` records the source's own centring DEVICE: a `table-cell` with
 * `vertical-align: middle` (Brando's slider) or a flex/grid container that
 * fills half the band with centred items (Elementor's `e-con`). Merely being a
 * full-height band does NOT mean the content is centred — virtuallyjamaica's
 * contact band is top-aligned under 130px of padding, and centring it moved the
 * copy 151px down.
 *
 * The split is recorded only when the track's children actually sit SIDE BY
 * SIDE: a stacked layout at the measured breakpoint is not a split. Fractions
 * are normalised to the children's own widths, so gutters and gaps cancel out.
 */
export function collectBandLayoutFacts( input: {
	ids: string[];
	kind: 'built' | 'source';
	selectors?: Record< string, string >;
} ): Record< string, { split?: number[]; centered?: boolean } > {
	const pick = ( selector: string ): Element | null => {
		try {
			return document.querySelector( selector );
		} catch {
			return null;
		}
	};
	const COLUMN = /(^|\s)(wpb_column|vc_column_container)(\s|$)/;
	const ROW = /(^|\s)(row|vc_row|wpb_row)(\s|$)|brando[_-](inner[_-])?row/;
	// The SAME structural signature the template's hero rule uses, so a measured
	// ratio always lands on the element the rule will lay out.
	const TRACK = ':is(div, section):has(> div > h1):has(> div > img)';
	const out: Record< string, { split?: number[]; centered?: boolean } > = {};
	for ( const id of input.ids ) {
		const candidates =
			input.kind === 'built'
				? [ `[data-section-id="${ id }"]` ]
				: [ `.elementor-element-${ id }`, input.selectors?.[ id ] ].filter(
						( value ): value is string => Boolean( value )
				  );
		let element = candidates.map( pick ).find( ( node ): node is Element => Boolean( node ) ) ?? null;
		if ( input.kind === 'source' && element && COLUMN.test( element.getAttribute( 'class' ) ?? '' ) ) {
			let row: Element | null = null;
			for ( let up = element.parentElement; up; up = up.parentElement ) {
				if ( up.tagName === 'SECTION' ) {
					element = up;
					row = null;
					break;
				}
				if ( ! row && ROW.test( up.getAttribute( 'class' ) ?? '' ) ) {
					row = up;
				}
			}
			if ( row ) {
				element = row;
			}
		}
		if ( ! element ) {
			continue;
		}
		const bandRect = element.getBoundingClientRect();
		// Recorded for EVERY resolved band — `false` is a measurement too. The
		// renderer keeps its historical default for a profile that has no fact
		// (older profiles, unmeasured pages) and only opts OUT on an explicit
		// `false`.
		const entry: { split?: number[]; centered?: boolean } = { centered: false };
		for ( const node of Array.from( element.querySelectorAll( '*' ) ) ) {
			const style = getComputedStyle( node );
			if ( style.display === 'table-cell' && style.verticalAlign === 'middle' ) {
				entry.centered = true;
				break;
			}
			const isFlexOrGrid = style.display.includes( 'flex' ) || style.display.includes( 'grid' );
			const centresItems =
				style.alignItems === 'center' ||
				style.alignContent === 'center' ||
				style.placeItems.startsWith( 'center' );
			if ( isFlexOrGrid && centresItems && node.getBoundingClientRect().height >= bandRect.height * 0.5 ) {
				entry.centered = true;
				break;
			}
		}
		let track: Element | null = null;
		try {
			track = element.querySelector( TRACK );
		} catch {
			track = null;
		}
		if ( track ) {
			const children = Array.from( track.children ).filter( ( child ) => {
				const rect = child.getBoundingClientRect();
				return rect.width > 0 && rect.height > 0;
			} );
			const rects = children.map( ( child ) => child.getBoundingClientRect() );
			// Side by side: the first two boxes are adjacent HORIZONTALLY. Their tops
			// need not agree — a centred track aligns the columns on their middle
			// (virtuallyjamaica: the copy starts at y=351 while the illustration,
			// taller, starts at 277). A stacked layout fails the right-edge test.
			const sideBySide =
				rects.length >= 2 && ! ( rects[ 0 ].right > rects[ 1 ].left + 2 );
			const total = rects.reduce( ( sum, rect ) => sum + rect.width, 0 );
			if ( sideBySide && total > 0 ) {
				entry.split = rects.map( ( rect ) => Math.round( ( rect.width / total ) * 1000 ) / 1000 );
			}
		}
		if ( entry.split || entry.centered !== undefined ) {
			out[ id ] = entry;
		}
	}
	return out;
}

/** Render one measured box as a declaration string (empty when nothing to say). */
export function boxDeclarations( box: MeasuredBox ): string {
	return [
		box.pad && `padding:${ box.pad }`,
		box.mar && `margin:${ box.mar }`,
		// The class's own ink override (see `MeasuredBox.color`). Last on purpose:
		// a declaration string is diffed per device by `mergeBoxFacts`, so the
		// order only has to be stable.
		box.color && `color:${ box.color }`,
		box.height && `height:${ box.height }`,
	]
		.filter( Boolean )
		.join( ';' );
}

/**
 * Merge three devices' measured boxes into per-class `{ base, tablet, mobile }`
 * declarations, recording a device only where it CHANGES a value.
 *
 * Pure and exported so the merge is testable without a browser.
 */
export function mergeBoxFacts(
	desktop: Record< string, MeasuredBox > | undefined,
	tablet: Record< string, MeasuredBox > | undefined,
	mobile: Record< string, MeasuredBox > | undefined
): BandBoxFacts | undefined {
	const out: BandBoxFacts = {};
	const names = new Set< string >( [
		...Object.keys( desktop ?? {} ),
		...Object.keys( tablet ?? {} ),
		...Object.keys( mobile ?? {} ),
	] );
	for ( const name of names ) {
		const base = boxDeclarations( desktop?.[ name ] ?? {} );
		const tabletDecl = boxDeclarations( tablet?.[ name ] ?? {} );
		const mobileDecl = boxDeclarations( mobile?.[ name ] ?? {} );
		const entry: BandBoxDeclaration = {};
		if ( base ) {
			entry.base = base;
		}
		// A device override is emitted only when it differs from the BASE — the
		// same rule the typography facts use, so an unchanged value never doubles
		// the stylesheet.
		if ( tabletDecl && tabletDecl !== base ) {
			entry.tablet = tabletDecl;
		}
		if ( mobileDecl && mobileDecl !== base ) {
			entry.mobile = mobileDecl;
		}
		if ( Object.keys( entry ).length > 0 ) {
			out[ name ] = entry;
		}
	}
	return Object.keys( out ).length > 0 ? out : undefined;
}

/** What `other` changes relative to `base`, or undefined when nothing differs.
 * Only the differences are recorded, so a band whose headings do not scale per
 * device emits no media query at all.
 */
export function diffTypeFact(
	base: BandTypeProperties,
	other?: BandTypeProperties
): BandTypeProperties | undefined {
	if ( ! other ) {
		return undefined;
	}
	const out: BandTypeProperties = {};
	for ( const property of TYPE_PROPERTIES ) {
		const next = other[ property ];
		if ( next && next !== base[ property ] ) {
			out[ property ] = next;
		}
	}
	return Object.keys( out ).length > 0 ? out : undefined;
}

/**
 * Attach the tablet/mobile differences to a band's desktop facts.
 *
 * Pure and exported so the merge is testable without a browser. The source
 * scaling a 55px hero headline to 22px on a phone is a FACT about that band, and
 * the rebuild has to carry it or every band renders taller than its source at
 * 390px (virtuallyjamaica: features 1808 vs 1525, services 1633 vs 1281).
 */
export function attachDeviceOverrides(
	desktop: { headings?: Record< string, BandTypeFact >; body?: BandTypeFact },
	tablet?: { headings: Record< string, BandTypeProperties >; body?: BandTypeProperties },
	mobile?: { headings: Record< string, BandTypeProperties >; body?: BandTypeProperties }
): void {
	for ( const [ tag, fact ] of Object.entries( desktop.headings ?? {} ) ) {
		const tabletDiff = diffTypeFact( fact, tablet?.headings[ tag ] );
		const mobileDiff = diffTypeFact( fact, mobile?.headings[ tag ] );
		if ( tabletDiff ) {
			fact.tablet = tabletDiff;
		}
		if ( mobileDiff ) {
			fact.mobile = mobileDiff;
		}
	}
	if ( desktop.body ) {
		const tabletDiff = diffTypeFact( desktop.body, tablet?.body );
		const mobileDiff = diffTypeFact( desktop.body, mobile?.body );
		if ( tabletDiff ) {
			desktop.body.tablet = tabletDiff;
		}
		if ( mobileDiff ) {
			desktop.body.mobile = mobileDiff;
		}
	}
}

/**
 * Measure the per-band presentation facts that are not box geometry: the inner
 * container width, the band's own heading/body typography (per device), the
 * subtrees the source hides at rest, and the class inventory.
 *
 * Why this exists: a non-Elementor source's bands are rebuilt as the classifier
 * found them, and a band with no structural kind renders through the raw-HTML
 * path. That path has no heading scale of its own, so the browser's default
 * `h3 { font-size: 1.17em }` scaled the 13px body to 15.21px against the
 * source's 40px — every band's hierarchy collapsed while the box-geometry gate
 * passed. Computed styles are the only faithful source: the sizes live in
 * builder classes and theme variables, never in the sanitized markup.
 *
 * Measured at all three template breakpoints. The desktop pass is the base; the
 * tablet and mobile passes contribute only the properties the source CHANGES
 * (`attachDeviceOverrides`) — a rebuild that keeps the desktop size at 390px
 * makes every band taller than its source.
 */
export async function measureBandFacts( options: MeasureOptions ): Promise< {
	bands: Record< string, ForgeBandFacts >;
	pageBackground?: string;
} > {
	const chromium = await loadChromium();
	const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
	try {
		const context = await browser.newContext( { deviceScaleFactor: 1 } );
		const page = await context.newPage();
		const measureTypeAt = async (
			device: DeviceKey
		): Promise< Record<
			string,
			{
				containerWidth: number;
				containerPadX: number;
				containerInner: boolean;
				containerFlush: boolean;
				headings: Record< string, BandTypeProperties >;
				body?: BandTypeProperties;
			}
		> > => {
			const { width, height } = BAND_FACT_VIEWPORTS[ device ];
			await page.setViewportSize( { width, height } );
			// A resize re-runs the source's media queries; give the layout a frame.
			await page.waitForTimeout( 350 );
			return page.evaluate( collectBandTypeFacts, {
				ids: options.ids,
				kind: options.kind,
				selectors: options.selectors,
			} );
		};

		const desktop = BAND_FACT_VIEWPORTS.desktop;
		await page.setViewportSize( { width: desktop.width, height: desktop.height } );
		await page.goto( options.url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
		await settle( page );

		const base = await measureTypeAt( 'desktop' );
		// The PAGE background. A builder paints the body, and every band with a
		// transparent surface (and every margin between bands) shows it —
		// jayainc's body is #e5e5e5, so the rebuild's white body turned the
		// source's own band margins into bright white strips while scrolling.
		const pageBackground = await page.evaluate( () => {
			const pick = ( value: string ) =>
				value && value !== 'rgba(0, 0, 0, 0)' && value !== 'transparent' ? value : undefined;
			const body = pick( getComputedStyle( document.body ).backgroundColor );
			if ( body ) {
				return body;
			}
			return pick( getComputedStyle( document.documentElement ).backgroundColor );
		} );
		// The computed BOX (padding/margin/cropped-media height) per class — the
		// only faithful source for a builder's numeric spacing scale. Read at each
		// device while its viewport is still current.
		const desktopBox = await page.evaluate( collectBandBoxFacts, {
			ids: options.ids,
			kind: options.kind,
			selectors: options.selectors,
		} );
		// The hero track's column split + the band's centring device (see
		// `collectBandLayoutFacts`). Desktop only: a split hero collapses to one
		// column below the breakpoint, and the rebuild's mobile rule stacks.
		const desktopLayout = await page.evaluate( collectBandLayoutFacts, {
			ids: options.ids,
			kind: options.kind,
			selectors: options.selectors,
		} );
		// The source's RESTING visibility. A builder hides its accordion panels,
		// tab panes, mega-menus and mobile drawers with a class the sanitizer keeps
		// but the rebuilt stylesheet never reaches — so every panel rendered open
		// (virtuallyjamaica's features band: 2352 characters against 486). Measured
		// at both end breakpoints and INTERSECTED: an element hidden only on
		// desktop is a responsive state, not a resting one.
		const desktopHidden = await page.evaluate( collectHiddenSignatures, {
			ids: options.ids,
			kind: options.kind,
			selectors: options.selectors,
		} );
		const tablet = await measureTypeAt( 'tablet' );
		const tabletBox = await page.evaluate( collectBandBoxFacts, {
			ids: options.ids,
			kind: options.kind,
			selectors: options.selectors,
		} );
		const mobile = await measureTypeAt( 'mobile' );
		const mobileBox = await page.evaluate( collectBandBoxFacts, {
			ids: options.ids,
			kind: options.kind,
			selectors: options.selectors,
		} );
		const mobileHidden = await page.evaluate( collectHiddenSignatures, {
			ids: options.ids,
			kind: options.kind,
			selectors: options.selectors,
		} );

		const facts: Record< string, ForgeBandFacts > = {};
		// The container fact is per device, and only for a container that is a
		// strict descendant of the band (see `collectBandTypeFacts`): when the band
		// itself is the container its padding is already the band padding fact.
		const containerAt = (
			entry:
				| {
						containerWidth: number;
						containerPadX: number;
						containerInner: boolean;
						containerFlush: boolean;
				  }
				| undefined
		): ForgeBandContainer | undefined =>
			entry && entry.containerInner && entry.containerWidth > 0
				? { content: entry.containerWidth, padX: entry.containerPadX, flush: entry.containerFlush }
				: undefined;
		for ( const [ id, desktopFacts ] of Object.entries( base ) ) {
			const band: ForgeBandFacts = {
				containerWidth: desktopFacts.containerWidth,
				headings: { ...desktopFacts.headings },
				...( desktopFacts.body ? { body: { ...desktopFacts.body } } : {} ),
			};
			const container = {
				desktop: containerAt( desktopFacts ),
				tablet: containerAt( tablet[ id ] ),
				mobile: containerAt( mobile[ id ] ),
			};
			if ( container.desktop || container.tablet || container.mobile ) {
				band.container = Object.fromEntries(
					Object.entries( container ).filter( ( [ , value ] ) => value !== undefined )
				) as ForgeBandFacts[ 'container' ];
			}
			attachDeviceOverrides( band, tablet[ id ], mobile[ id ] );
			// The hero track's measured split + the source's centring device (see
			// `collectBandLayoutFacts`).
			const layout = desktopLayout[ id ];
			if ( layout?.split && layout.split.length >= 2 ) {
				band.splitColumns = layout.split;
			}
			if ( layout?.centered ) {
				band.centeredContent = true;
			}
			const box = mergeBoxFacts( desktopBox[ id ]?.boxes, tabletBox[ id ]?.boxes, mobileBox[ id ]?.boxes );
			if ( box ) {
				band.box = box;
			}
			// Cropped-media heights are their own map, emitted as `img.<class>`.
			const media = mergeBoxFacts( desktopBox[ id ]?.media, tabletBox[ id ]?.media, mobileBox[ id ]?.media );
			if ( media ) {
				band.media = media;
			}
			// The source's crop anchor (desktop wins; the crop rarely changes per
			// device, and a per-device map would need its own scoped CSS).
			const mediaPosition =
				desktopBox[ id ]?.mediaPosition ?? tabletBox[ id ]?.mediaPosition ?? mobileBox[ id ]?.mediaPosition;
			if ( mediaPosition ) {
				band.mediaPosition = mediaPosition;
			}
			const hidden = signaturesHiddenAtBoth( desktopHidden[ id ], mobileHidden[ id ] );
			if ( hidden.length > 0 ) {
				band.hidden = hidden;
			}
			// The class inventory is the union of the viewports: an element that only
			// exists when stacked still counts as used.
			const classes = [
				...new Set( [ ...( desktopHidden[ id ]?.classes ?? [] ), ...( mobileHidden[ id ]?.classes ?? [] ) ] ),
			].sort();
			if ( classes.length > 0 ) {
				band.classes = classes;
			}
			facts[ id ] = band;
		}
		return { bands: facts, ...( pageBackground ? { pageBackground } : {} ) };
	} finally {
		await browser.close();
	}
}

/**
 * Measure the header's BOX at each viewport — does the source constrain its
 * brand/nav row to a container, and with what gutter?
 *
 * Two container shapes matter:
 *   • a declared cap — jayainc's `.aux-container { max-width:1670px; padding:0
 *     35px; margin:auto }`;
 *   • a per-breakpoint width — virtuallyjamaica's Bootstrap `.container`
 *     (1170px at 1440, 970 at 1024, 100% below 992) with 15px padding.
 *
 * The first is read from computed `max-width` (the design constant, stable
 * across viewports); the second from the measured box. `measure` is recorded
 * only where the cap is BINDING (box narrower than the header) so a 1670px cap
 * at a 1440px viewport reads as "full-bleed here" on both sides of the gate.
 * The 1920px viewport exists to make a cap that large visible at all.
 *
 * `padX` is the container's INLINE PADDING, not the brand's offset: it stays
 * true for a source that centres its brand under a breakpoint (jayainc centres
 * it at 1024/834), where a brand-relative inset would be nonsense.
 *
 * FAIL-SOFT: no header or no brand returns `{}` and the template keeps its own
 * gutter.
 */
export async function measureChromeGeometry( options: {
	url: string;
	timeout?: number;
} ): Promise< ForgeChromeGeometry > {
	const chromium = await loadChromium();
	const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
	try {
		const context = await browser.newContext( { deviceScaleFactor: 1 } );
		const page = await context.newPage();
		await page.goto( options.url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
		await settle( page );
		const out: ForgeChromeGeometry = {};
		for ( const device of [ 'wide', 'desktop', 'tablet', 'mobile' ] as const ) {
			const { width, height } = GEOMETRY_VIEWPORTS[ device ];
			await page.setViewportSize( { width, height } );
			await page.waitForTimeout( 300 );
			const box = await page.evaluate( () => {
				const header = document.querySelector( 'header, #masthead, #header, .site-header, nav.navbar, nav' );
				if ( ! header ) {
					return null;
				}
				const headerRect = header.getBoundingClientRect();
				const brand =
					header.querySelector( 'a[rel="home"], .site-logo, .navbar-brand, a[href="/"]' ) ?? header.querySelector( 'a' );
				if ( ! brand ) {
					return null;
				}
				const brandRect = brand.getBoundingClientRect();
				const read = ( node: Element ) => {
					const style = getComputedStyle( node );
					const rect = node.getBoundingClientRect();
					return {
						node,
						rect,
						maxWidth: Number.parseFloat( style.maxWidth ),
						padLeft: Number.parseFloat( style.paddingLeft ) || 0,
					};
				};
				const chain = [];
				for ( let node = brand.parentElement; node && node !== header; node = node.parentElement ) {
					chain.push( read( node ) );
				}
				// A `max-width` below ~60% of the header (or 480px) is a logo cell, not
				// the row container. `.aux-logo-anchor` itself computes `max-width:226px`.
				const plausible = Math.max( 480, headerRect.width * 0.6 );
				const capped = chain.find(
					( entry ) => Number.isFinite( entry.maxWidth ) && entry.maxWidth >= plausible
				);
				const binding = capped && capped.maxWidth <= headerRect.width - 8 ? capped : null;
				// The container is the OUTERMOST padded ancestor wide enough to be the
				// row — walking up, not down: a Bootstrap row and its `.container`
				// share a width, and the row is the one without the gutter. Reversing
				// also skips the logo COLUMN (virtuallyjamaica's 785px `.col-lg-2` at
				// tablet), which is padded but is not the header's box.
				const padded = [ ...chain ]
					.reverse()
					.find( ( entry ) => entry.padLeft > 0 && entry.rect.width >= headerRect.width * 0.6 );
				const boxed = padded && padded.rect.width <= headerRect.width - 8 ? padded : null;
				return {
					measure: binding ? Math.round( binding.maxWidth ) : boxed ? Math.round( boxed.rect.width ) : undefined,
					padX: Math.round( ( padded ?? capped )?.padLeft ?? brandRect.x - headerRect.x ),
				};
			} );
			if ( ! box ) {
				continue;
			}
			const entry: ForgeChromeBox = {};
			if ( typeof box.measure === 'number' && box.measure > 0 ) {
				entry.measure = box.measure;
			}
			if ( typeof box.padX === 'number' && box.padX >= 0 ) {
				entry.padX = box.padX;
			}
			if ( Object.keys( entry ).length > 0 ) {
				out[ device ] = entry;
			}
		}
		return out;
	} finally {
		await browser.close();
	}
}

/**
 * Measure the source's OPENED mobile menu — the surface, ink and rhythm of the
 * panel a collapsed nav reveals.
 *
 * Toggling `display` is not enough: a builder paints the panel on its own layer.
 * virtuallyjamaica's Brando menu is an absolutely positioned
 * `rgba(0,0,0,0.95)` panel under a fixed TRANSPARENT header, so a rebuild that
 * only shows the links paints white text straight onto the hero photo — the
 * menu is unreadable on any band that is not dark (2026-11-12).
 *
 * Method: load at 390×844, click the measured toggle, then find the panel as the
 * EYE does — the painted box that starts at the header's bottom edge (or covers
 * the screen) and carries an opaque surface or a gradient. Everything else (the
 * link pitch, the inset, the overlay behaviour) is read off that box.
 *
 * FAIL-SOFT: no toggle → `undefined`; a toggle whose panel stays invisible →
 * `{ overlay: false }`, and the rebuild derives a readable surface instead.
 */
export async function measureMobileNav( options: {
	url: string;
	timeout?: number;
} ): Promise< ForgeMobileNav | undefined > {
	const chromium = await loadChromium();
	const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
	try {
		const context = await browser.newContext( {
			viewport: { width: 390, height: 844 },
			deviceScaleFactor: 1,
			isMobile: true,
			hasTouch: true,
		} );
		const page = await context.newPage();
		await page.goto( options.url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
		await settle( page );
		const closed = await page.evaluate( () => {
			const TOGGLE = /(?:^|\s)(?:navbar-toggle|menu-toggle|nav-toggle|mobile-toggle|hamburger|burger|aux-menu-toggle|toggle-menu)(?:\s|$)/i;
			const header = document.querySelector( 'header, #masthead, #header, .site-header, nav' ) ?? document.body;
			for ( const node of header.querySelectorAll( 'button, a, [role="button"]' ) ) {
				const element = node as HTMLElement;
				const classes = typeof element.className === 'string' ? element.className : '';
				const declared =
					element.hasAttribute( 'aria-expanded' ) ||
					element.hasAttribute( 'data-toggle' ) ||
					element.hasAttribute( 'data-bs-toggle' );
				if ( ! declared && ! TOGGLE.test( classes ) ) {
					continue;
				}
				const style = getComputedStyle( element );
				const box = element.getBoundingClientRect();
				if ( style.display === 'none' || style.visibility === 'hidden' || box.width < 1 || box.height < 1 ) {
					continue;
				}
				// Measure the CLOSED layout first, then open it: the panel can grow the
				// header (in-flow disclosure) and the header's bottom edge would move.
				const before = {
					headerBottom: Math.round( header.getBoundingClientRect().bottom ),
					scrollHeight: document.documentElement.scrollHeight,
				};
				element.click();
				return before;
			}
			return null;
		} );
		if ( ! closed ) {
			return undefined;
		}
		await page.waitForTimeout( 800 );
		const facts = await page.evaluate( ( closed ) => {
			const alpha = ( value: string ): number => {
				const match = /rgba?\(([^)]+)\)/.exec( value );
				if ( ! match ) {
					return value === 'transparent' ? 0 : 1;
				}
				const parts = match[ 1 ].split( /[,/]/ ).map( ( part ) => part.trim() ).filter( Boolean );
				return parts.length >= 4 ? Number( parts[ 3 ] ) : 1;
			};
			// The panel is found the way the EYE finds it: the container of the menu
			// links, then the first ancestor that actually PAINTS a surface. Picking
			// the biggest painted box instead caught the page band behind the header
			// (virtuallyjamaica's red hero, 8302px tall).
			const header = document.querySelector( 'header, #masthead, #header, .site-header, nav' ) ?? document.body;
			const toggle = ( () => {
				for ( const node of header.querySelectorAll( 'button, a, [role="button"]' ) ) {
					const element = node as HTMLElement;
					const classes = typeof element.className === 'string' ? element.className : '';
					const declared =
						element.hasAttribute( 'aria-expanded' ) ||
						element.hasAttribute( 'data-toggle' ) ||
						element.hasAttribute( 'data-bs-toggle' );
					if ( declared || /(?:^|\s)(?:navbar-toggle|menu-toggle|nav-toggle|mobile-toggle|hamburger|burger|aux-menu-toggle|toggle-menu)(?:\s|$)/i.test( classes ) ) {
						return element;
					}
				}
				return null;
			} )();
			const menuLinks = [ ...header.querySelectorAll( 'a' ) ].filter( ( link ) => {
				const box = link.getBoundingClientRect();
				return (
					box.height >= 8 &&
					box.width >= 20 &&
					box.top >= closed.headerBottom - 4 &&
					box.top < innerHeight &&
					link.textContent.trim().length > 0
				);
			} );
			if ( menuLinks.length === 0 ) {
				return { overlay: false };
			}
			// The deepest node containing EVERY menu link — the list/panel wrapper.
			const shared = new Set< Element >();
			for ( let node: Element | null = menuLinks[ 0 ]; node; node = node.parentElement ) {
				shared.add( node );
			}
			for ( const link of menuLinks.slice( 1 ) ) {
				for ( const node of [ ...shared ] ) {
					if ( ! node.contains( link ) ) {
						shared.delete( node );
					}
				}
			}
			let list: Element | null = null;
			for ( let node = menuLinks[ 0 ].parentElement; node; node = node.parentElement ) {
				if ( shared.has( node ) ) {
					list = node;
					break;
				}
			}
			if ( ! list ) {
				return { overlay: false };
			}
			// Walk UP for the painted surface, and stop at the disclosure's own row:
			// past it lies the page, and the page is not the menu.
			const stopAt = toggle ? ( toggle.parentElement ?? header ) : header;
			let panel: Element | null = null;
			for ( let node: Element | null = list; node; node = node.parentElement ) {
				const style = getComputedStyle( node );
				const hasGradient = Boolean( style.backgroundImage ) && style.backgroundImage !== 'none';
				if ( alpha( style.backgroundColor ) >= 0.9 || hasGradient ) {
					panel = node;
					break;
				}
				if ( node === stopAt || node === header ) {
					break;
				}
			}
			const surface = panel ?? list;
			const surfaceStyle = getComputedStyle( surface );
			const surfaceBox = surface.getBoundingClientRect();
			const opaque = alpha( surfaceStyle.backgroundColor ) >= 0.9;
			const boxes = menuLinks.map( ( link ) => {
				const box = link.getBoundingClientRect();
				return {
					top: box.top,
					color: getComputedStyle( link ).color,
					area: box.width * box.height,
				};
			} );
			const pitches: number[] = [];
			for ( let i = 1; i < boxes.length; i += 1 ) {
				const pitch = boxes[ i ].top - boxes[ i - 1 ].top;
				if ( pitch > 4 ) {
					pitches.push( pitch );
				}
			}
			pitches.sort( ( a, b ) => a - b );
			const biggest = [ ...boxes ].sort( ( a, b ) => b.area - a.area )[ 0 ];
			const position = getComputedStyle( panel ?? list ).position;
			return {
				...( opaque
					? { background: surfaceStyle.backgroundColor }
					: panel && surfaceStyle.backgroundImage !== 'none'
						? { background: surfaceStyle.backgroundImage }
						: {} ),
				...( biggest ? { ink: biggest.color } : {} ),
				padX: Math.round( Number.parseFloat( surfaceStyle.paddingLeft ) || 0 ),
				padY: Math.round( Number.parseFloat( surfaceStyle.paddingTop ) || 0 ),
				...( pitches.length > 0 ? { itemPitch: Math.round( pitches[ Math.floor( pitches.length / 2 ) ] ) } : {} ),
				overlay: position === 'absolute' || position === 'fixed' || document.documentElement.scrollHeight <= closed.scrollHeight + 4,
				fullWidth: surfaceBox.width >= innerWidth - 2,
			};
		}, closed );
		return facts;
	} finally {
		await browser.close();
	}
}

/**
 * Measure the source's mobile nav disclosure: the button a builder shows at
 * narrow widths to open its menu.
 *
 * Presence is a markup fact (`design.ts` reads the control and its label);
 * WHERE it appears is only knowable from a browser. Brando collapses its nav
 * below 991px, Bootstrap's default is 768px, and a builder can pick anything —
 * so the rebuild collapses at the widths the source measured, rather than at a
 * guessed breakpoint that would either strand links behind a hidden menu on a
 * wide screen or render a wrapped stack where the source shows a hamburger
 * (virtuallyjamaica at 390px: 6 links over 3 rows).
 *
 * FAIL-SOFT: a page with no toggle returns `undefined` and the rebuild keeps
 * the nav inline at every width, which is what the source does too.
 */
export async function measureNavToggle( options: {
	url: string;
	timeout?: number;
} ): Promise< ForgeChrome[ 'navToggle' ] > {
	const chromium = await loadChromium();
	const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
	try {
		const context = await browser.newContext( { deviceScaleFactor: 1 } );
		const page = await context.newPage();
		await page.setViewportSize( { width: 1440, height: 900 } );
		await page.goto( options.url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
		await settle( page );

		const visibleAt = async ( device: DeviceKey ): Promise< boolean > => {
			const { width, height } = BAND_FACT_VIEWPORTS[ device ];
			await page.setViewportSize( { width, height } );
			await page.waitForTimeout( 250 );
			return page.evaluate( () => {
				const TOGGLE = /(?:^|\s)(?:navbar-toggle|menu-toggle|nav-toggle|mobile-toggle|hamburger|burger|aux-menu-toggle|toggle-menu)(?:\s|$)/i;
				const header = document.querySelector( 'header, #masthead, #header, .site-header, nav' ) ?? document.body;
				for ( const node of header.querySelectorAll( 'button, a, [role="button"]' ) ) {
					const element = node as HTMLElement;
					const classes = typeof element.className === 'string' ? element.className : '';
					const declared =
						element.hasAttribute( 'aria-expanded' ) ||
						element.hasAttribute( 'data-toggle' ) ||
						element.hasAttribute( 'data-bs-toggle' );
					if ( ! declared && ! TOGGLE.test( classes ) ) {
						continue;
					}
					const style = getComputedStyle( element );
					const box = element.getBoundingClientRect();
					if ( style.display === 'none' || style.visibility === 'hidden' || box.width < 1 || box.height < 1 ) {
						continue;
					}
					return true;
				}
				return false;
			} );
		};

		const visible = {
			desktop: await visibleAt( 'desktop' ),
			tablet: await visibleAt( 'tablet' ),
			mobile: await visibleAt( 'mobile' ),
		};
		if ( ! visible.desktop && ! visible.tablet && ! visible.mobile ) {
			return undefined;
		}
		return { visible };
	} finally {
		await browser.close();
	}
}

/**
 * The source's top contact strip, as the browser sees it.
 *
 * `analyzeChrome` can only read the strip's TEXT (two `<p>` lines). The
 * structure around that text is what makes the strip recognisable: each line
 * sits beside an icon glyph (`auxicon-location`, `auxicon-phone-classic-on`),
 * the address is two lines from a `<br>`, the items are a right-floated flex
 * group, and the icons are large and painted in the site accent. None of that
 * is in the text, and none of it is knowable from CSS (the rules live in
 * vendor classes) — so it is measured, like every other chrome fact.
 *
 * FAIL-SOFT: a page with no top strip returns `undefined` and the rebuild keeps
 * the text-only lines it always rendered.
 */
export async function measureTopbar( options: {
	url: string;
	timeout?: number;
} ): Promise< ForgeTopbarFacts | undefined > {
	const chromium = await loadChromium();
	const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
	try {
		const context = await browser.newContext( { deviceScaleFactor: 1 } );
		const page = await context.newPage();
		await page.setViewportSize( { width: 1440, height: 900 } );
		await page.goto( options.url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
		// A transition/scroll animation mid-flight moves the strip's box.
		await page.addStyleTag( {
			content: '*,*::before,*::after{transition:none!important;animation-duration:0s!important}',
		} );
		await settle( page );
		return await page.evaluate( () => {
			const TOPBAR = [
				'#top-header',
				'[id*="top-header"]',
				'[class*="top-header"]',
				'[class*="topbar"]',
				'[class*="top-bar"]',
			];
			let bar: Element | null = null;
			for ( const selector of TOPBAR ) {
				try {
					const node = document.querySelector( selector );
					if ( node && node.getBoundingClientRect().height >= 10 ) {
						bar = node;
						break;
					}
				} catch {
					// Invalid selector — try the next.
				}
			}
			if ( ! bar ) {
				return undefined;
			}
			const barRect = bar.getBoundingClientRect();
			const barStyle = getComputedStyle( bar );
			let contentLeft = barRect.left + ( parseFloat( barStyle.paddingLeft ) || 0 );
			let contentRight = barRect.right - ( parseFloat( barStyle.paddingRight ) || 0 );
			// The bar itself is usually flush; the GUTTER lives on its inner
			// container (jayainc: `.aux-container { padding: 0 35px }`). Walk up from
			// the first item to the nearest padded ancestor, or the alignment
			// comparison measures against the viewport edge and finds nothing.
			const paddedAncestor = ( start: Element | null ): Element | null => {
				for ( let node = start; node && node !== bar; node = node.parentElement ) {
					const style = getComputedStyle( node );
					if ( ( parseFloat( style.paddingLeft ) || 0 ) > 0 || ( parseFloat( style.paddingRight ) || 0 ) > 0 ) {
						return node;
					}
				}
				return null;
			};

			const isIcon = ( el: Element ): boolean =>
				/i(?:con|co|glyph)/i.test( el.getAttribute( 'class' ) ?? '' ) && ! ( el.textContent ?? '' ).trim();
			const itemText = ( el: Element ): string => {
				const raw = ( ( el as HTMLElement ).innerText ?? el.textContent ?? '' )
					.replace( /[\t\u00a0 ]+/g, ' ' )
					.replace( / *\n */g, '\n' )
					.trim();
				return raw;
			};

			// The INNERMOST containers that hold an icon AND real text: the strip's
			// items. A wrapper above them (the group) holds neither a direct icon
			// nor text of its own, so it never wins.
			const itemNodes: Element[] = [];
			for ( const el of Array.from( bar.querySelectorAll( '*' ) ) ) {
				if ( itemNodes.some( ( item ) => item.contains( el ) ) ) {
					continue;
				}
				const icon = Array.from( el.children ).find( isIcon );
				if ( ! icon || itemText( el ).length === 0 ) {
					continue;
				}
				itemNodes.push( el );
			}
			// No icons in the source strip — the paragraphs ARE the items.
			if ( itemNodes.length === 0 ) {
				for ( const p of Array.from( bar.querySelectorAll( 'p, a' ) ) ) {
					if ( itemText( p ).length > 0 ) {
						itemNodes.push( p );
					}
				}
			}

			const items = itemNodes.map( ( el ) => {
				const icon = Array.from( el.children ).find( isIcon );
				const link = el.matches( 'a[href]' ) ? el : el.querySelector( 'a[href]' ) ?? el.closest( 'a[href]' );
				return {
					text: itemText( el ),
					...( icon?.getAttribute( 'class' ) ? { icon: ( icon.getAttribute( 'class' ) ?? '' ).trim() } : {} ),
					...( link?.getAttribute( 'href' ) ? { href: link.getAttribute( 'href' ) as string } : {} ),
				};
			} );
			if ( items.length === 0 ) {
				return undefined;
			}
			// Icon-only links (the source's leading social glyphs). They are not
			// items — no text — and they sit at the bar's other end.
			const socials: Array< { icon: string; href?: string } > = [];
			for ( const link of Array.from( bar.querySelectorAll( 'a[href]' ) ) ) {
				if ( itemNodes.some( ( item ) => item.contains( link ) ) ) {
					continue;
				}
				if ( ( link.textContent ?? '' ).trim().length > 0 ) {
					continue;
				}
				const icon = Array.from( link.querySelectorAll( 'i, span' ) ).find( isIcon );
				if ( ! icon ) {
					continue;
				}
				socials.push( {
					icon: ( icon.getAttribute( 'class' ) ?? '' ).trim(),
					href: link.getAttribute( 'href' ) as string,
				} );
			}

			const rects = itemNodes.map( ( el ) => el.getBoundingClientRect() );
			const gutterNode = paddedAncestor( itemNodes[ 0 ]?.parentElement ?? null );
			if ( gutterNode ) {
				const gutterRect = gutterNode.getBoundingClientRect();
				const gutterStyle = getComputedStyle( gutterNode );
				contentLeft = gutterRect.left + ( parseFloat( gutterStyle.paddingLeft ) || 0 );
				contentRight = gutterRect.right - ( parseFloat( gutterStyle.paddingRight ) || 0 );
			}
			const groupLeft = Math.min( ...rects.map( ( rect ) => rect.left ) );
			const groupRight = Math.max( ...rects.map( ( rect ) => rect.right ) );
			const groupCenter = ( groupLeft + groupRight ) / 2;
			const contentCenter = ( contentLeft + contentRight ) / 2;
			const snap = ( value: number ): string => `${ Math.round( value * 100 ) / 100 }px`;
			const align =
				Math.abs( groupRight - contentRight ) <= 40
					? 'end'
					: Math.abs( groupLeft - contentLeft ) <= 40
						? 'start'
						: Math.abs( groupCenter - contentCenter ) <= 40
							? 'center'
							: undefined;

			const firstIcon = bar.querySelector( 'i, span' );
			const iconStyle = firstIcon ? getComputedStyle( firstIcon ) : null;
			const ordered = [ ...rects ].sort( ( a, b ) => a.left - b.left );
			const gaps = ordered
				.slice( 1 )
				.map( ( rect, index ) => rect.left - ordered[ index ].right )
				.filter( ( gap ) => gap > 0 );

			return {
				items,
				...( socials.length > 0 ? { socials } : {} ),
				...( align ? { align } : {} ),
				height: snap( barRect.height ),
				...( iconStyle && firstIcon && isIcon( firstIcon )
					? {
							iconSize: iconStyle.fontSize,
							iconInk: iconStyle.color,
							iconGap: snap( parseFloat( iconStyle.marginRight ) || 0 ),
						}
					: {} ),
				...( gaps.length > 0 ? { itemGap: snap( Math.max( ...gaps ) ) } : {} ),
			};
		} );
	} finally {
		await browser.close();
	}
}

/**
 * Measure the sticky header: does the source pin it, and does its appearance
 * change once the page scrolls?
 *
 * Why a measurement rather than a stylesheet read: the theme adds a class on
 * scroll (`shrink`, `is-sticky`, `navbar-fixed`, `aux-logo-sticky`) and that
 * class carries no colours in the markup — the background lives in a selector
 * the analyzer would have to guess at. Scrolling a real page and reading the
 * computed style is the only faithful source, and it is the same discipline the
 * band boxes use.
 *
 * Header candidates are structural (a `<header>`, the masthead, or the nav),
 * never a theme class, so the pass works on any builder. The pass is
 * FAIL-SOFT: a page with no header returns `{ sticky: false }`.
 */
export async function measureStickyHeader( options: { url: string; timeout?: number } ): Promise< ForgeStickyHeader > {
	const chromium = await loadChromium();
	const browser = await chromium.launch( { args: [ '--force-device-scale-factor=1' ] } );
	try {
		const context = await browser.newContext( { deviceScaleFactor: 1 } );
		const page = await context.newPage();
		await page.setViewportSize( { width: 1440, height: 900 } );
		await page.goto( options.url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
		// Fonts decide the header height; transitions would make the pinned
		// snapshot read a mid-animation colour.
		await page.addStyleTag( {
			content:
				'*,*::before,*::after{transition:none!important;animation-duration:0s!important}',
		} );
		await page.evaluate( async () => {
			const doc = document as Document & { fonts?: { ready: Promise< unknown > } };
			if ( doc.fonts ) {
				await doc.fonts.ready;
			}
		} );
		return await page.evaluate( async () => {
			const CANDIDATES = [
				'header.site-header',
				'header',
				'#masthead',
				'#header',
				'.site-header',
				'[class*="masthead"]',
				'[class*="site-header"]',
				'nav.navbar',
				'[class*="navbar"]',
				'nav',
			];
			let header: Element | null = null;
			for ( const selector of CANDIDATES ) {
				let node: Element | null = null;
				try {
					node = document.querySelector( selector );
				} catch {
					node = null;
				}
				if ( node && node.getBoundingClientRect().height >= 30 && node.querySelector( 'a' ) ) {
					header = node;
					break;
				}
			}
			if ( ! header ) {
				return { sticky: false };
			}
			const read = ( el: Element ) => {
				const style = getComputedStyle( el );
				const rect = el.getBoundingClientRect();
				return {
					bg: style.backgroundColor,
					shadow: style.boxShadow,
					height: Math.round( rect.height ),
					position: style.position,
					classes: el.getAttribute( 'class' ) ?? '',
				};
			};
			// The chrome ink lives on the TEXT nodes, never on the header element
			// itself (a transparent header computes black) nor on the first anchor
			// (Flatsome's first anchor is an empty banner link that computes the
			// accent red). The most frequent colour among text-bearing nodes is the
			// ink the source actually renders.
			const inkOf = ( el: Element | null ): string | undefined => {
				if ( ! el ) {
					return undefined;
				}
				const counts = new Map< string, number >();
				for ( const node of [ el, ...el.querySelectorAll( '*' ) ] ) {
					const carriesText = [ ...node.childNodes ].some(
						( child ) => child.nodeType === 3 && ( child.textContent ?? '' ).trim().length > 0
					);
					if ( ! carriesText ) {
						continue;
					}
					const rect = node.getBoundingClientRect();
					if ( rect.width < 2 || rect.height < 2 ) {
						continue;
					}
					const color = getComputedStyle( node ).color;
					counts.set( color, ( counts.get( color ) ?? 0 ) + 1 );
				}
				const best = [ ...counts.entries() ].sort( ( a, b ) => b[ 1 ] - a[ 1 ] )[ 0 ];
				if ( best ) {
					return best[ 0 ];
				}
				const link = el.querySelector( 'a' );
				return link ? getComputedStyle( link ).color : undefined;
			};
			// The strip itself is often a painted CHILD, not the header element
			// (Flatsome's `.header-bg-color` paints #0a0a0a; the header stays
			// transparent). Returns the first full-width painted descendant.
			const fillOf = ( el: Element | null, minHeight = 30 ) => {
				if ( ! el ) {
					return undefined;
				}
				return [ ...el.querySelectorAll( '*' ) ]
					.map( ( node ) => ( { style: getComputedStyle( node ), rect: node.getBoundingClientRect() } ) )
					.filter(
						( { style, rect } ) =>
							!/^rgba\(0, 0, 0, 0\)$/.test( style.backgroundColor ) &&
							rect.height >= minHeight &&
							rect.width >= window.innerWidth * 0.8
					)
					.map( ( { style, rect } ) => ( { bg: style.backgroundColor, height: Math.round( rect.height ) } ) )[ 0 ];
			};
			// Header-ish nodes only — a `position: fixed` ad bar must never win.
			const headerish = [ ...document.querySelectorAll( 'header, [class*="header"], [class*="masthead"], [class*="navbar"], [class*="sticky"], [class*="stuck"]' ) ];
			const fixedAtRest = new Set(
				headerish.filter( ( node ) => {
					try {
						return getComputedStyle( node ).position === 'fixed';
					} catch {
						return false;
					}
				} )
			);

			const rest = read( header );
			const restInk = inkOf( header );
			// A fill only stands for the header's OWN surface when it covers most of
			// the header box — the topbar is a 30px strip inside a 158px header and
			// must not repaint the whole chrome red.
			const restFill = fillOf( header, Math.max( 30, rest.height * 0.6 ) );
			window.scrollTo( 0, Math.min( 600, Math.max( 200, window.innerHeight ) ) );
			window.dispatchEvent( new Event( 'scroll' ) );
			await new Promise( ( resolve ) => setTimeout( resolve, 1200 ) );
			const pinned = read( header );
			// The pinned strip can be a JS-injected CLONE in another subtree: Flatsome's
			// `sticky-jump` duplicates the header into a fixed `.header-wrapper.stuck`
			// (70px, #0a0a0a) and leaves the original where it was. Anything header-ish
			// that is fixed/sticky at the top of the viewport after scrolling and was
			// not fixed at rest IS the source's pinned header.
			const strip = headerish
				.filter( ( node ) => {
					if ( fixedAtRest.has( node ) ) {
						return false;
					}
					let style: CSSStyleDeclaration;
					let rect: DOMRect;
					try {
						style = getComputedStyle( node );
						rect = node.getBoundingClientRect();
					} catch {
						return false;
					}
					return (
						( style.position === 'fixed' || style.position === 'sticky' ) &&
						rect.height >= 30 &&
						rect.width >= window.innerWidth * 0.6 &&
						rect.top <= 8 &&
						Boolean( node.querySelector( 'a' ) )
					);
				} )
				.sort( ( a, b ) => a.getBoundingClientRect().top - b.getBoundingClientRect().top )[ 0 ];
			const stripInfo = strip ? read( strip ) : undefined;
			const stripInk = inkOf( strip );
			const stripFill = stripInfo ? fillOf( strip, Math.max( 30, stripInfo.height * 0.5 ) ) : undefined;
			window.scrollTo( 0, 0 );

			const gained = ( from: ReturnType< typeof read >, to: ReturnType< typeof read > ) =>
				to.classes
					.split( /\s+/ )
					.some( ( name ) => name && !from.classes.split( /\s+/ ).includes( name ) && /sticky|shrink|pinned|scrolled|scroll-nav|is-scroll|stuck/i.test( name ) );
			const classGained = gained( rest, pinned );
			const pinsByStyle =
				[ rest, pinned, stripInfo ]
					.filter( ( value ): value is NonNullable< typeof value > => Boolean( value ) )
					.some( ( value ) => value.position === 'fixed' || value.position === 'sticky' );
			const sticky = pinsByStyle || classGained;
			if ( ! sticky ) {
				return { sticky: false };
			}
			const clean = ( value: string | undefined ) => ( value === undefined || /^rgba\(0, 0, 0, 0\)$/.test( value ) ? undefined : value );
			const shadow = ( stripInfo?.shadow ?? pinned.shadow ) === 'none' ? undefined : stripInfo?.shadow ?? pinned.shadow;
			// Background: the injected strip's fill wins, then the strip, then the header.
			const restBg = clean( restFill?.bg ) ?? clean( rest.bg );
			const pinnedBg = clean( stripFill?.bg ) ?? clean( stripInfo?.bg ) ?? clean( pinned.bg );
			const bgChanged = Boolean( pinnedBg ) && pinnedBg !== restBg;
			// Height: the strip's own box when a clone exists, else a shrunk header.
			const headerShrank = Math.abs( pinned.height - rest.height ) >= 2;
			const pinnedHeight = stripInfo
				? stripInfo.height
				: headerShrank
					? pinned.height
					: stripFill?.height;
			return {
				sticky: true,
				// `fixed` — not `sticky` — is the source's own overlay: the header
				// floats OVER the first band, so a sticky rebuild pushes that band down
				// and leaves white chrome on the page background.
				position: rest.position,
				...( restBg ? { restBg } : {} ),
				...( clean( restInk ) ? { restInk: clean( restInk ) } : {} ),
				// The resting height is a fact whether or not the header also shrinks:
				// the rebuild renders the header at REST and shrinks it when pinned, so
				// omitting it left a 47px header where the source has 75px (and the
				// opened mobile panel then sat 28px too high, 2026-11-12).
				restHeight: rest.height,
				...( bgChanged && pinnedBg ? { pinnedBg } : {} ),
				...( bgChanged && stripInk && stripInk !== restInk ? { pinnedInk: stripInk } : {} ),
				...( pinnedHeight ? { pinnedHeight } : {} ),
				...( shadow ? { shadow } : {} ),
				bgChanged,
				heightChanged: headerShrank || Boolean( stripInfo && Math.abs( stripInfo.height - rest.height ) >= 2 ),
			};
		} );
	} finally {
		await browser.close();
	}
}

/**
 * Collect the class signatures of elements the SOURCE hides at rest.
 *
 * Module scope on purpose: Playwright serialises the function, so it can close
 * over nothing. Returns `{ hidden, visible }` per band as class-token arrays —
 * a signature is the sorted class list, which is exactly what the sanitised
 * markup still carries.
 */
function collectHiddenSignatures( input: {
	ids: string[];
	kind: 'built' | 'source';
	selectors?: Record< string, string >;
} ): Record< string, { hidden: string[][]; visible: string[][]; classes: string[] } > {
	const pick = ( selector: string ): Element | null => {
		try {
			return document.querySelector( selector );
		} catch {
			return null;
		}
	};
	const COLUMN = /(^|\s)(wpb_column|vc_column_container)(\s|$)/;
	const out: Record< string, { hidden: string[][]; visible: string[][]; classes: string[] } > = {};
	for ( const id of input.ids ) {
		const candidates =
			input.kind === 'built'
				? [ `[data-section-id="${ id }"]` ]
				: [ `.elementor-element-${ id }`, input.selectors?.[ id ] ].filter( ( value ): value is string => Boolean( value ) );
		let element = candidates.map( pick ).find( ( node ): node is Element => Boolean( node ) ) ?? null;
		if ( input.kind === 'source' && element && COLUMN.test( element.getAttribute( 'class' ) ?? '' ) ) {
			// The same column → band promotion the box pass uses.
			for ( let up = element.parentElement; up; up = up.parentElement ) {
				if ( up.tagName === 'SECTION' ) {
					element = up;
					break;
				}
			}
		}
		if ( ! element ) {
			continue;
		}
		const signature = ( node: Element ): string[] =>
			( node.getAttribute( 'class' ) ?? '' )
				.split( /\s+/ )
				.map( ( token ) => token.trim() )
				.filter( Boolean )
				.sort();
		const hidden: string[][] = [];
		const visible: string[][] = [];
		// Every class the band carries — the trim key for the builder's utility pack.
		const classTokens = new Set< string >( signature( element ) );
		const seenHidden = new Set< string >();
		const seenVisible = new Set< string >();
		const nodes = Array.from( element.querySelectorAll( '*' ) );
		for ( const node of nodes ) {
			const classes = signature( node );
			for ( const token of classes ) {
				classTokens.add( token );
			}
			// A selector needs a class to key on; an unclassed hidden node (a
			// `type=hidden` input) is hidden for other reasons.
			if ( classes.length === 0 ) {
				continue;
			}
			const display = getComputedStyle( node ).display;
			if ( display !== 'none' ) {
				const key = classes.join( '.' );
				if ( ! seenVisible.has( key ) ) {
					seenVisible.add( key );
					visible.push( classes );
				}
				continue;
			}
			// Only the OUTERMOST hidden node: a hidden child of a hidden parent is
			// already covered, and emitting it would multiply every rule.
			let parentHidden = false;
			for ( let up = node.parentElement; up && up !== element; up = up.parentElement ) {
				if ( getComputedStyle( up ).display === 'none' ) {
					parentHidden = true;
					break;
				}
			}
			if ( parentHidden ) {
				continue;
			}
			const key = classes.join( '.' );
			if ( ! seenHidden.has( key ) ) {
				seenHidden.add( key );
				hidden.push( classes );
			}
		}
		out[ id ] = { hidden, visible, classes: [ ...classTokens ].sort() };
	}
	return out;
}

/** Escape a class token for inside an attribute selector value. */
function cssAttributeValue( token: string ): string {
	return token.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' );
}

/**
 * Turn a class signature into a selector that matches EXACTLY that class set.
 *
 * `exclude` holds the tokens a VISIBLE sibling carries and the hidden one does
 * not — the acccordion's `.in`. Without them `[class~="collapse"]` would hide
 * the open panel too, which is the one thing worse than hiding nothing.
 */
function signatureSelector( signature: string[], exclude: string[] = [] ): string {
	const own = signature.map( ( token ) => `[class~="${ cssAttributeValue( token ) }"]` ).join( '' );
	const not = exclude.map( ( token ) => `:not([class~="${ cssAttributeValue( token ) }"])` ).join( '' );
	return `${ own }${ not }`;
}

/**
 * Selectors for the subtrees the source hides at rest in BOTH viewports.
 *
 * A hidden element is keyed by its class signature. When a VISIBLE sibling in
 * the same band carries the same classes plus one or two more (the accordion's
 * `.in`), the extra classes become `:not(…)` — otherwise the rule would hide the
 * open panel too, which is the one outcome worse than hiding nothing.
 *
 * Conservative by construction: a signature that is hidden in one viewport and
 * visible in the other, or that appears both hidden and visible in the same
 * band, is skipped rather than guessed at.
 */
export function signaturesHiddenAtBoth(
	desktop?: { hidden: string[][]; visible: string[][] },
	mobile?: { hidden: string[][]; visible: string[][] }
): string[] {
	if ( ! desktop && ! mobile ) {
		return [];
	}
	const keyOf = ( signature: string[] ): string => [ ...signature ].sort().join( '.' );
	const desktopHidden = desktop ? new Set( desktop.hidden.map( keyOf ) ) : undefined;
	const mobileHidden = mobile ? new Set( mobile.hidden.map( keyOf ) ) : undefined;
	const hiddenKeys =
		desktopHidden && mobileHidden
			? [ ...desktopHidden ].filter( ( key ) => mobileHidden.has( key ) )
			: [ ...( desktopHidden ?? mobileHidden ?? [] ) ];
	const visibleKeys = new Set(
		[ ...( desktop?.visible ?? [] ), ...( mobile?.visible ?? [] ) ].map( keyOf )
	);
	const selectors: string[] = [];
	const seen = new Set< string >();
	for ( const key of hiddenKeys ) {
		if ( visibleKeys.has( key ) ) {
			continue;
		}
		const signature = key.split( '.' ).sort();
		// The tightest visible superset: its extra classes are what distinguishes
		// the hidden state from the open one.
		const extras = [ ...visibleKeys ]
			.map( ( other ) => other.split( '.' ) )
			.filter( ( other ) => other.length > signature.length && signature.every( ( token ) => other.includes( token ) ) )
			.map( ( other ) => other.filter( ( token ) => ! signature.includes( token ) ) )
			.filter( ( difference ) => difference.length > 0 && difference.length <= 2 )
			.sort( ( a, b ) => a.length - b.length );
		const selector = signatureSelector( signature, extras[ 0 ] ?? [] );
		if ( ! seen.has( selector ) ) {
			seen.add( selector );
			selectors.push( selector );
		}
		if ( selectors.length >= 12 ) {
			break;
		}
	}
	return selectors;
}

const MIME: Record< string, string > = {
	'.html': 'text/html; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.js': 'text/javascript; charset=utf-8',
	'.json': 'application/json',
	'.svg': 'image/svg+xml',
	'.png': 'image/png',
	'.jpg': 'image/jpeg',
	'.jpeg': 'image/jpeg',
	'.webp': 'image/webp',
	'.avif': 'image/avif',
	'.woff': 'font/woff',
	'.woff2': 'font/woff2',
	'.pdf': 'application/pdf',
};

/**
 * The directory to serve a build from.
 *
 * Astro writes the browser-facing tree to `dist/client` whenever the project
 * uses a server output (`output: 'server'` — the managed/SSR template behind
 * `server.js`). Serving `dist` itself then 404s at `/`, every band measures as
 * missing, and the geometry/colour/pixel gates report a total wipe-out that
 * looks like a rebuild regression. Prefer the client tree when it carries a
 * page, otherwise the flat static build.
 */
export async function builtStaticRoot( distDir: string ): Promise< string > {
	try {
		await stat( join( distDir, 'client', 'index.html' ) );
		return join( distDir, 'client' );
	} catch {
		return distDir;
	}
}

/**
 * Serve a built `dist/` on an ephemeral port. The gate must load the build over
 * HTTP: `file://` breaks absolute asset paths, and an unstyled page measures
 * nothing like the real one.
 */
export async function serveDir( root: string ): Promise<{ url: string; server: Server } > {
	const server = createServer( async ( request, response ) => {
		try {
			const pathname = decodeURIComponent( new URL( request.url ?? '/', 'http://localhost' ).pathname );
			const relative = normalize( pathname ).replace( /^([/\\])+/, '' );
			const target = relative === '' || relative.endsWith( sep ) || ! extname( relative )
				? join( root, relative, 'index.html' )
				: join( root, relative );
			const body = await readFile( target );
			response.writeHead( 200, { 'content-type': MIME[ extname( target ) ] ?? 'application/octet-stream' } );
			response.end( body );
		} catch {
			response.writeHead( 404, { 'content-type': 'text/plain' } );
			response.end( 'not found' );
		}
	} );
	await new Promise< void >( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
	const { port } = server.address() as AddressInfo;
	return { url: `http://127.0.0.1:${ port }/`, server };
}

const METRICS: Array<{ key: keyof GeometryTolerance; metric: string } > = [
	{ key: 'height', metric: 'height' },
	{ key: 'width', metric: 'width' },
	{ key: 'spacing', metric: 'paddingTop' },
	{ key: 'spacing', metric: 'paddingBottom' },
	{ key: 'spacing', metric: 'marginTop' },
	{ key: 'spacing', metric: 'marginBottom' },
];

/**
 * Diff the build against the source geometry. Missing bands fail; a metric
 * beyond tolerance warns, beyond 2× tolerance fails — the pass/warn/fail split
 * the geometry-oracle tooling uses, so a real regression is never buried in
 * warnings.
 */
export function diffGeometry(
	source: ForgeGeometry,
	built: ForgeGeometry,
	tolerance: GeometryTolerance = DEFAULT_GEOMETRY_TOLERANCE
): GeometryDeviation[] {
	const deviations: GeometryDeviation[] = [];
	for ( const [ id, viewports ] of Object.entries( source ) ) {
		for ( const [ viewport, expected ] of Object.entries( viewports ) ) {
			const actual = built[ id ]?.[ viewport ];
			if ( ! actual ) {
				deviations.push( {
					sectionId: id,
					viewport,
					metric: 'band',
					expected: expected.height,
					actual: null,
					delta: 0,
					severity: 'fail',
				} );
				continue;
			}
			for ( const { key, metric } of METRICS ) {
				const expectedValue = expected[ metric as keyof typeof expected ] as number;
				const actualValue = actual[ metric as keyof typeof actual ] as number;
				const delta = actualValue - expectedValue;
				const limit = tolerance[ key ];
				if ( Math.abs( delta ) <= limit ) {
					continue;
				}
				deviations.push( {
					sectionId: id,
					viewport,
					metric,
					expected: expectedValue,
					actual: actualValue,
					delta,
					severity: Math.abs( delta ) > limit * 2 ? 'fail' : 'warn',
				} );
			}
		}
	}
	return deviations;
}
