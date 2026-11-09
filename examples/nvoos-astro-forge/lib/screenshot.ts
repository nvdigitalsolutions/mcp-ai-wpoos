// ---------------------------------------------------------------------------
// screenshot.ts — deterministic screenshots for the pixel gate.
//
// The pixel gate compares the SOURCE and the BUILD, so both sides must be
// captured the same way or the diff measures the capture, not the site. Every
// knob below is the documented anti-flake set (Playwright's `toHaveScreenshot`,
// Percy/Chromatic guidance, Visual Sentinel 2026):
//
//  * `animations: 'disabled'` + a CSS kill switch — infinite animations reset,
//    finite ones fast-forward (Playwright's own semantics).
//  * `caret: 'hide'` — the blinking text cursor is pure noise.
//  * `deviceScaleFactor: 1`, `scale: 'css'` — CSS pixels on every machine.
//  * `reducedMotion: 'reduce'`, fixed `colorScheme`/`locale`/`timezoneId` —
//    no media-query or locale-dependent variation.
//  * `document.fonts.ready` + lazy-image scroll-through + image settle — the
//    two classic "first paint differs from steady state" failures.
//  * `mask` for regions that are genuinely dynamic (carousels, clocks,
//    third-party widgets). Masking beats loosening the global threshold.
//
// Deliberate omission: the clock is NOT faked. Playwright's clock API replaces
// timers, which can freeze hydration and produce a screenshot of a page that
// never finished rendering. Determinism comes from the knobs above plus masks.
//
// Playwright is an optional dev dependency (same contract as geometry.ts).
// ---------------------------------------------------------------------------

import type { Browser, Page } from 'playwright';

import { serveDir } from './geometry.ts';
import type { GeometryViewport } from './geometry.ts';

export type CaptureViewport = GeometryViewport;

export interface CaptureOptions {
	url: string;
	viewport: CaptureViewport;
	/** Colour scheme to render under (default `light`). */
	mode?: 'light' | 'dark';
	/** Full-page (tall) capture. Default false — viewport-sized, comparable. */
	fullPage?: boolean;
	/** CSS selectors for dynamic regions to mask out of the comparison. */
	masks?: string[];
	timeout?: number;
}

export interface CaptureResult {
	png: Uint8Array;
	width: number;
	height: number;
}

type PlaywrightChromium = typeof import('playwright').chromium;

async function loadChromium(): Promise< PlaywrightChromium > {
	try {
		const playwright = await import('playwright');
		return playwright.chromium;
	} catch {
		throw new Error(
			'Pixel parity needs Playwright. Install it in the forge (npm i -D playwright).'
		);
	}
}

const FREEZE_CSS =
	'*,*::before,*::after{animation:none!important;transition:none!important;' +
	'caret-color:transparent!important;scroll-behavior:auto!important}' +
	'html{scroll-behavior:auto!important}';

/**
 * Wait for a stable frame: fonts loaded, every viewport scrolled through so
 * lazy images fetched, and every image either complete or timed out.
 */
async function settle( page: Page ): Promise< void > {
	await page.addStyleTag( { content: FREEZE_CSS } );
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
						new Promise< void >( ( resolve ) => {
							image.addEventListener( 'load', () => resolve(), { once: true } );
							image.addEventListener( 'error', () => resolve(), { once: true } );
							setTimeout( resolve, 3000 );
						} )
				)
		);
	} );
}

/** Capture one URL at one viewport with the deterministic settings. */
export async function capturePage( options: CaptureOptions ): Promise< CaptureResult > {
	const chromium = await loadChromium();
	const browser: Browser = await chromium.launch( {
		args: [ '--force-device-scale-factor=1', '--font-render-hinting=none' ],
	} );
	try {
		const context = await browser.newContext( {
			viewport: { width: options.viewport.width, height: options.viewport.height },
			deviceScaleFactor: 1,
			colorScheme: options.mode === 'dark' ? 'dark' : 'light',
			reducedMotion: 'reduce',
			locale: 'en-US',
			timezoneId: 'UTC',
		} );
		const page = await context.newPage();
		await page.goto( options.url, { waitUntil: 'load', timeout: options.timeout ?? 90000 } );
		await settle( page );
		const mask = ( options.masks ?? [] )
			.filter( ( selector ) => selector.trim().length > 0 )
			.map( ( selector ) => page.locator( selector ) );
		const buffer = await page.screenshot( {
			fullPage: options.fullPage === true,
			animations: 'disabled',
			caret: 'hide',
			scale: 'css',
			...( mask.length > 0 ? { mask } : {} ),
		} );
		return {
			png: new Uint8Array( buffer ),
			width: options.viewport.width,
			height: options.viewport.height,
		};
	} finally {
		await browser.close();
	}
}

/**
 * Serve a built `dist/` and capture `/` at each viewport. Serving over HTTP is
 * mandatory: `file://` breaks absolute asset paths (same reason as geometry).
 */
export async function captureDist(
	distDir: string,
	viewports: CaptureViewport[],
	options: {
		path?: string;
		fullPage?: boolean;
		masks?: string[];
		timeout?: number;
		mode?: 'light' | 'dark';
	} = {}
): Promise< Map< string, CaptureResult > > {
	const served = await serveDir( distDir );
	const out = new Map< string, CaptureResult >();
	try {
		for ( const viewport of viewports ) {
			const url = new URL( options.path ?? '/', served.url ).toString();
			out.set(
				viewport.name,
				await capturePage( {
					url,
					viewport,
					mode: options.mode,
					fullPage: options.fullPage,
					masks: options.masks,
					timeout: options.timeout,
				} )
			);
		}
		return out;
	} finally {
		await new Promise< void >( ( resolve ) => served.server.close( () => resolve() ) );
	}
}
