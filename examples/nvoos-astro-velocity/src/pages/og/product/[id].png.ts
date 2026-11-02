// ---------------------------------------------------------------------------
// /og/product/[id].png — per-product Open Graph card (Proposal 104 §4.8,
// owner Q5 approved). On-demand render: fetch the live product, draw the
// ADS-branded card with satori, rasterize with resvg, cache hard.
//
// Failure discipline: an OG card must never break the page — render errors
// redirect to the product image (crawlers follow the redirect; a missing
// preview is worse than none but a 500 is worst). Missing/draft products 404.
// ---------------------------------------------------------------------------

import type { APIRoute } from 'astro';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import satori from 'satori';
import { Resvg } from '@resvg/resvg-js';
import { getProduct } from '../../../lib/commerce/client';
import { isPublished } from '../../../lib/commerce/publish';
import { ogCardElement, ogCardTokens } from '../../../lib/seo/og';

const require = createRequire(import.meta.url);

// Inter via Fontsource — files resolve from node_modules at runtime (the
// mirror ships installed deps). OFL-licensed.
const FONT_REGULAR = readFileSync(
	require.resolve('@fontsource/inter/files/inter-latin-400-normal.woff')
);
const FONT_BOLD = readFileSync(
	require.resolve('@fontsource/inter/files/inter-latin-700-normal.woff')
);

export const GET: APIRoute = async ({ params, url, cache, redirect }) => {
	const id = Number(params.id ?? 0);
	if (!Number.isInteger(id) || id <= 0) {
		return new Response('Not found', { status: 404 });
	}

	const raw = await getProduct(id, url);
	if (!raw || !isPublished(raw)) {
		return new Response('Product not found', { status: 404 });
	}

	if (cache.enabled) {
		cache.set({ maxAge: 86400, swr: 86400, tags: ['products', `product-${id}`] });
	}

	try {
		const svg = await satori(ogCardElement(raw, ogCardTokens()), {
			width: 1200,
			height: 630,
			fonts: [
				{ name: 'Inter', data: FONT_REGULAR, weight: 400, style: 'normal' },
				{ name: 'Inter', data: FONT_BOLD, weight: 700, style: 'normal' },
			],
		});
		const png = new Resvg(svg, { fitTo: { mode: 'width', value: 1200 } })
			.render()
			.asPng();
		return new Response(new Uint8Array(png), {
			status: 200,
			headers: {
				'content-type': 'image/png',
				'cache-control': 'public, s-maxage=86400, stale-while-revalidate=86400',
			},
		});
	} catch (error) {
		console.error(`[og] card render failed for product ${id}:`, error);
		// Fallback: the product's own image (through the /_image endpoint).
		const image = raw.image;
		if (image) {
			return redirect(image, 302);
		}
		return new Response('Not found', { status: 404 });
	}
};
