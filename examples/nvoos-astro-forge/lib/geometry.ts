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
	ForgeGeometry,
	ForgeStickyHeader,
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
		// the viewport. The CONTENT width is what the columns get, so the band's
		// own gutters are subtracted — and recorded, because the rebuild needs them
		// as its own `--ds-pad-x` (a constant was wrong at every viewport but
		// desktop).
		const container =
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
} ): Record< string, { boxes: Record< string, MeasuredBox >; media: Record< string, MeasuredBox > } > {
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
	const out: Record< string, { boxes: Record< string, MeasuredBox >; media: Record< string, MeasuredBox > } > = {};
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
		const seen = new Map< string, { pads: Set< string >; mars: Set< string > } >();
		const mediaHeights = new Map< string, Set< string > >();
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
			}
			for ( const name of classes ) {
				if ( ! seen.has( name ) ) {
					seen.set( name, { pads: new Set(), mars: new Set() } );
				}
				const entry = seen.get( name ) as { pads: Set< string >; mars: Set< string > };
				entry.pads.add( pad );
				entry.mars.add( mar );
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
		out[ id ] = { boxes: facts, media };
	}
	return out;
}

/** Render one measured box as a declaration string (empty when nothing to say). */
export function boxDeclarations( box: MeasuredBox ): string {
	return [
		box.pad && `padding:${ box.pad }`,
		box.mar && `margin:${ box.mar }`,
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
export async function measureBandFacts( options: MeasureOptions ): Promise< Record< string, ForgeBandFacts > > {
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
		// The computed BOX (padding/margin/cropped-media height) per class — the
		// only faithful source for a builder's numeric spacing scale. Read at each
		// device while its viewport is still current.
		const desktopBox = await page.evaluate( collectBandBoxFacts, {
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
			const box = mergeBoxFacts( desktopBox[ id ]?.boxes, tabletBox[ id ]?.boxes, mobileBox[ id ]?.boxes );
			if ( box ) {
				band.box = box;
			}
			// Cropped-media heights are their own map, emitted as `img.<class>`.
			const media = mergeBoxFacts( desktopBox[ id ]?.media, tabletBox[ id ]?.media, mobileBox[ id ]?.media );
			if ( media ) {
				band.media = media;
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
		return facts;
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
			const snapshot = () => {
				const style = getComputedStyle( header as Element );
				const rect = ( header as Element ).getBoundingClientRect();
				return {
					bg: style.backgroundColor,
					ink: style.color,
					shadow: style.boxShadow,
					height: Math.round( rect.height ),
					position: style.position,
					classes: ( header as Element ).getAttribute( 'class' ) ?? '',
				};
			};
			const rest = snapshot();
			window.scrollTo( 0, Math.min( 600, Math.max( 200, window.innerHeight ) ) );
			window.dispatchEvent( new Event( 'scroll' ) );
			await new Promise( ( resolve ) => setTimeout( resolve, 900 ) );
			const pinned = snapshot();
			window.scrollTo( 0, 0 );
			const pinsByStyle = rest.position === 'fixed' || rest.position === 'sticky' || pinned.position === 'fixed' || pinned.position === 'sticky';
			const classGained = pinned.classes
				.split( /\s+/ )
				.some( ( name ) => name && ! rest.classes.split( /\s+/ ).includes( name ) && /sticky|shrink|pinned|scrolled|scroll-nav|is-scroll/i.test( name ) );
			const sticky = pinsByStyle || classGained;
			const bgChanged = pinned.bg !== rest.bg && pinned.bg !== 'rgba(0, 0, 0, 0)';
			const heightChanged = Math.abs( pinned.height - rest.height ) >= 2;
			if ( ! sticky ) {
				return { sticky: false };
			}
			const clean = ( value: string ) => ( /^rgba\(0, 0, 0, 0\)$/.test( value ) ? undefined : value );
			const shadow = pinned.shadow === 'none' ? undefined : pinned.shadow;
			return {
				sticky: true,
				// `fixed` — not `sticky` — is the source's own overlay: the header
				// floats OVER the first band, so a sticky rebuild pushes that band down
				// and leaves white chrome on the page background.
				position: rest.position,
				...( clean( rest.bg ) ? { restBg: clean( rest.bg ) } : {} ),
				...( clean( rest.ink ) ? { restInk: clean( rest.ink ) } : {} ),
				restHeight: rest.height,
				...( bgChanged && clean( pinned.bg ) ? { pinnedBg: clean( pinned.bg ) } : {} ),
				...( bgChanged && clean( pinned.ink ) ? { pinnedInk: clean( pinned.ink ) } : {} ),
				...( heightChanged ? { pinnedHeight: pinned.height } : {} ),
				...( shadow ? { shadow } : {} ),
				bgChanged,
				heightChanged,
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
