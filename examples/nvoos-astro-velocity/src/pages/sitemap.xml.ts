// ---------------------------------------------------------------------------
// sitemap.xml — SSR endpoint (Proposal 092 §5.4 / D11).
//
// Hybrid-mode note: the sitemap integration only lists prerendered pages,
// which would silently omit the SSR'd PLP bases (the highest-value SEO
// URLs). This endpoint generates the FULL sitemap instead: prerendered
// PDPs + SSR PLP bases + static pages, with lastmod from updatedAt.
//
// Proposal 106 §2: CMS pages + posts from BOTH sources (envelope + local),
// L2-merged (envelope wins slug collisions — see lib/content/merge).
// Local docs canonicalize under the id-0 fallback URLs. Cached 1h (public) —
// the bridge B1 headers cover the upstream fetches.
// ---------------------------------------------------------------------------

import type { APIRoute } from 'astro';
import {
	getAllBrands,
	getAllCategories,
	getAllColors,
	getAllPages,
	getAllPosts,
	getAllProducts,
} from '../lib/commerce/client';
import { isPublished } from '../lib/commerce/publish';
import { toIsoDate } from '../lib/commerce/normalize';
import { getCollection } from 'astro:content';
import { categoryPath, brandPath, colorPath, productPath, absoluteUrl } from '../lib/url';

export const GET: APIRoute = async ({ site, url }) => {
	const base = site ?? new URL('https://store.nvoos.cloud');

	const [products, categories, brands, colors, pages, posts, localPages, localPosts] =
		await Promise.all([
			getAllProducts(url),
			getAllCategories(url),
			getAllBrands(url),
			getAllColors(url),
			getAllPages(url),
			getAllPosts(url),
			getCollection('pages', (entry) => !entry.data.draft),
			getCollection('posts', (entry) => !entry.data.draft),
		]);

	// L2: envelope wins slug collisions; local docs fill gaps.
	const localPageSlugs = new Set(pages.filter(isPublished).map((page) => page.slug));
	const localPostSlugs = new Set(posts.filter(isPublished).map((post) => post.slug));
	const extraPages = localPages.filter((entry) => !localPageSlugs.has(entry.id));
	const extraPosts = localPosts.filter((entry) => !localPostSlugs.has(entry.id));

	const entries: Array<{ loc: string; lastmod?: string }> = [
		{ loc: absoluteUrl(base, '/') },
		{ loc: absoluteUrl(base, '/about/') },
		{ loc: absoluteUrl(base, '/terms/') },
		{ loc: absoluteUrl(base, '/promotions/') },
		{ loc: absoluteUrl(base, '/promocodes/') },
		{ loc: absoluteUrl(base, '/products/') },
		{ loc: absoluteUrl(base, '/categories/') },
		{ loc: absoluteUrl(base, '/brands/') },
		{ loc: absoluteUrl(base, '/colors/') },
		{ loc: absoluteUrl(base, '/blog/') },
		...categories.map((category) => ({
			loc: absoluteUrl(base, categoryPath(category.id, category.name)),
		})),
		...brands.map((brand) => ({
			loc: absoluteUrl(base, brandPath(brand.id, brand.name)),
		})),
		...colors.map((color) => ({
			loc: absoluteUrl(base, colorPath(color.id, color.name)),
		})),
		...products
			.filter(isPublished) // draft discipline (104 §2.1) — never advertise drafts
			.map((product) => ({
				loc: absoluteUrl(base, productPath(product.id, product.name)),
				lastmod: toIsoDate(product.updatedAt) ?? undefined,
			})),
		// CMS content (106 §2): envelope first, local under the id-0 fallback.
		...pages
			.filter(isPublished)
			.map((page) => ({
				loc: absoluteUrl(base, `/pages/${page.id}/${page.slug}/`),
				lastmod: toIsoDate(page.updatedAt) ?? undefined,
			})),
		...extraPages.map((entry) => ({
			loc: absoluteUrl(base, `/pages/0/${entry.id}/`),
			lastmod: entry.data.date?.toISOString(),
		})),
		...posts
			.filter(isPublished)
			.map((post) => ({
				loc: absoluteUrl(base, `/blog/${post.id}/${post.slug}/`),
				lastmod: toIsoDate(post.updatedAt) ?? undefined,
			})),
		...extraPosts.map((entry) => ({
			loc: absoluteUrl(base, `/blog/0/${entry.id}/`),
			lastmod: entry.data.date?.toISOString(),
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
