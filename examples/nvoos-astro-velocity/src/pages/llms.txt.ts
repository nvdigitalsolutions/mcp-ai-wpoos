// /llms.txt — the storefront's agent index (Proposal 136 §4.4).
//
// Links the canonical HTML pages (velocity's content is Portable Text, so a
// markdown projection is a separate piece of work; advertising `.md` URLs that
// do not exist would be worse than linking the pages).
//
// Every collection load is fail-soft: a storefront must never 500 because one
// collection is unavailable — the file simply omits that section.
import type { APIRoute } from 'astro';
import { getAll } from '../lib/commerce/client';
import { composeLlmsTxt } from '../lib/llms';

interface ProductDoc {
	id: number;
	name?: string;
	slug?: string;
	shortDescription?: string;
}

interface ContentDoc {
	id: number;
	title?: string;
	slug?: string;
	excerpt?: string;
}

/** Load a collection, degrading to [] on any failure. */
async function safeLoad<T>(load: () => Promise<T[]>): Promise<T[]> {
	try {
		return await load();
	} catch {
		return [];
	}
}

export const GET: APIRoute = async ({ url, site }) => {
	const origin = (site?.origin ?? url.origin).replace(/\/+$/, '');

	const products = await safeLoad(() => getAll<ProductDoc>('products', url));
	const pages = await safeLoad(() => getAll<ContentDoc>('content/pages', url));
	const posts = await safeLoad(() => getAll<ContentDoc>('content/posts', url));

	const body = composeLlmsTxt({
		name: 'Storefront',
		summary: 'A storefront: products, categories, brands and editorial pages.',
		origin,
		products: products
			.filter((product) => product.slug)
			.map((product) => ({
				path: `/products/${product.id}/${product.slug}/`,
				title: product.name ?? String(product.id),
				note: product.shortDescription,
			})),
		pages: pages
			.filter((page) => page.slug)
			.map((page) => ({
				path: `/pages/${page.id}/${page.slug}/`,
				title: page.title ?? String(page.id),
				note: page.excerpt,
			})),
		posts: posts
			.filter((post) => post.slug)
			.map((post) => ({
				path: `/blog/${post.id}/${post.slug}/`,
				title: post.title ?? String(post.id),
				note: post.excerpt,
			})),
		optional: [
			{ path: '/products/', title: 'All products' },
			{ path: '/blog/', title: 'Blog' },
			{ path: '/search/', title: 'Search' },
		],
		// The `.md` variants exist for products, pages and posts — link those now
		// that they are real routes (a link to a 404 is worse than no link).
		variant: 'markdown',
	});

	return new Response(body, {
		headers: { 'Content-Type': 'text/plain; charset=utf-8' },
	});
};
