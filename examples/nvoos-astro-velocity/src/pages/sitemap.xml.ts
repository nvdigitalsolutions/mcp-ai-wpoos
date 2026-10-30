// ---------------------------------------------------------------------------
// sitemap.xml — SSR endpoint (Proposal 092 §5.4 / D11).
//
// Hybrid-mode note: the sitemap integration only lists prerendered pages,
// which would silently omit the SSR'd PLP bases (the highest-value SEO
// URLs). This endpoint generates the FULL sitemap instead: prerendered
// PDPs + SSR PLP bases + static pages, with lastmod from updatedAt.
// Cached 1h (public) — the bridge B1 headers cover the upstream fetches.
// ---------------------------------------------------------------------------

import type { APIRoute } from 'astro';
import { getAllCategories, getAllBrands, getAllColors, getAllProducts } from '../lib/commerce/client';
import { toIsoDate } from '../lib/commerce/normalize';
import { categoryPath, brandPath, colorPath, productPath, absoluteUrl } from '../lib/url';

export const GET: APIRoute = async ({ site, url }) => {
	const base = site ?? new URL('https://store.nvoos.cloud');

	const [products, categories, brands, colors] = await Promise.all([
		getAllProducts(url),
		getAllCategories(url),
		getAllBrands(url),
		getAllColors(url),
	]);

	const entries: Array<{ loc: string; lastmod?: string }> = [
		{ loc: absoluteUrl(base, '/') },
		{ loc: absoluteUrl(base, '/about/') },
		{ loc: absoluteUrl(base, '/terms/') },
		{ loc: absoluteUrl(base, '/promotions/') },
		{ loc: absoluteUrl(base, '/promocodes/') },
		{ loc: absoluteUrl(base, '/categories/') },
		{ loc: absoluteUrl(base, '/brands/') },
		{ loc: absoluteUrl(base, '/colors/') },
		...categories.map((category) => ({
			loc: absoluteUrl(base, categoryPath(category.id, category.name)),
		})),
		...brands.map((brand) => ({
			loc: absoluteUrl(base, brandPath(brand.id, brand.name)),
		})),
		...colors.map((color) => ({
			loc: absoluteUrl(base, colorPath(color.id, color.name)),
		})),
		...products.map((product) => ({
			loc: absoluteUrl(base, productPath(product.id, product.name)),
			lastmod: toIsoDate(product.updatedAt) ?? undefined,
		})),
	];

	const xml = [
		'<?xml version="1.0" encoding="UTF-8"?>',
		'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
		...entries.map((entry) => {
			const lastmod = entry.lastmod
				? `\n    <lastmod>${entry.lastmod}</lastmod>`
				: '';
			return `  <url>\n    <loc>${entry.loc}</loc>${lastmod}\n  </url>`;
		}),
		'</urlset>',
	].join('\n');

	return new Response(xml, {
		status: 200,
		headers: {
			'content-type': 'application/xml; charset=utf-8',
			'cache-control': 'public, s-maxage=3600, stale-while-revalidate=86400',
		},
	});
};
