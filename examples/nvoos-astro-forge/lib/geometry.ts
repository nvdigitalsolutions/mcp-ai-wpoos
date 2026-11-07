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
import { readFile } from 'node:fs/promises';
import { extname, join, normalize, sep } from 'node:path';

import type { ForgeGeometry } from './types.ts';

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

async function loadChromium(): Promise< PlaywrightChromium > {
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
			const measured = await page.evaluate( ( { ids, kind } ) => {
				const out: Record< string, Record< string, number > > = {};
				for ( const id of ids ) {
					const selector =
						kind === 'built' ? `[data-section-id="${ id }"]` : `.elementor-element-${ id }`;
					const element = document.querySelector( selector );
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
						marginTop: parseFloat( style.marginTop ) || 0,
						marginBottom: parseFloat( style.marginBottom ) || 0,
					};
				}
				return out;
			}, { ids: options.ids, kind: options.kind } );
			for ( const [ id, band ] of Object.entries( measured ) ) {
				geometry[ id ] ??= {};
				geometry[ id ][ viewport.name ] = {
					height: Math.round( band.height ),
					width: Math.round( band.width ),
					paddingTop: Math.round( band.paddingTop ),
					paddingBottom: Math.round( band.paddingBottom ),
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
