// ---------------------------------------------------------------------------
// render.ts — opt-in rendered capture (Proposal 098 Phase 2.1).
//
// JS-heavy sources yield near-empty static reads; this module renders the
// page in a real browser and hands the post-JS DOM + a computed-style
// snapshot back to the analyzer. Build-safe by construction: playwright is
// loaded through createRequire at RUNTIME (esbuild cannot statically bundle
// it), and every failure degrades to null — the tool falls back to the
// static read with an honest note. Gated by NVOOS_MCP_FETCH_RENDER=1; in the
// unbundled dev loop (serve.mjs / e2e) playwright resolves from the app's
// devDependencies; in the bundled production artifact it is absent and the
// capture simply never runs.
// ---------------------------------------------------------------------------

import { createRequire } from 'node:module';

export interface RenderedCapture {
	/** Post-JS DOM — richer class vocabulary than the static HTML. */
	renderedHtml: string;
	/** Computed body styles expressed as a CSS block for the analyzer. */
	computedCss: string;
}

export async function renderDesignSource(url: string): Promise<RenderedCapture | null> {
	try {
		const require = createRequire(import.meta.url);
		const { chromium } = require('playwright') as typeof import('playwright');
		const browser = await chromium.launch();
		try {
			const page = await browser.newPage();
			await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15_000 });
			const renderedHtml = await page.content();
			const computedCss = await page.evaluate(() => {
				const style = getComputedStyle(document.body);
				return `body{font-family:${style.fontFamily};font-size:${style.fontSize};color:${style.color};background-color:${style.backgroundColor}}`;
			});
			return { renderedHtml, computedCss };
		} finally {
			await browser.close();
		}
	} catch {
		return null;
	}
}
