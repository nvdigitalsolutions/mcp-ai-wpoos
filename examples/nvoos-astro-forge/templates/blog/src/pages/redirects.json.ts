import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import manifest from '../../nvoos.json';

/**
 * /redirects.json — the structured 301 map from the WordPress URL structure
 * to the generated one (Proposal 096 §0 SEO rule: preserve URLs at the URL
 * level; everything that moved gets a single-hop redirect).
 *
 * `scripts/postbuild.mjs` materializes the host-agnostic `dist/_redirects`
 * from this document after `astro build`.
 */
export const GET: APIRoute = async () => {
	const redirects: Array<{ from: string; to: string; status: 301 }> = [];
	const push = (source: string, target: string) => {
		if (source && source !== target && source !== '/') {
			redirects.push({ from: source, to: target, status: 301 });
		}
	};

	const posts = await getCollection('posts');
	for (const post of posts) {
		const source = pathnameOf(post.data.link);
		if (!source) continue;
		push(source, `/posts/${post.id}/${post.data.slug}/`);
	}

	const pages = await getCollection('pages');
	for (const page of pages) {
		const source = pathnameOf(page.data.link);
		if (!source) continue;
		const target = page.data.slug === manifest.frontPage ? '/' : `/${page.data.slug}/`;
		push(source, target);
	}

	const hasProducts = manifest.collections.some((collection) => collection.slug === 'products');
	if (hasProducts) {
		const products = await getCollection('products');
		for (const product of products) {
			const source = pathnameOf(product.data.link);
			if (!source) continue;
			push(source, `/products/${product.id}/${product.data.slug}/`);
		}
	}

	// Deterministic output — same content, same file, stable diffs.
	redirects.sort((a, b) => a.from.localeCompare(b.from));
	return new Response(`${JSON.stringify(redirects, null, '\t')}\n`, {
		headers: { 'content-type': 'application/json; charset=utf-8' },
	});
};

/** The pathname of a WP canonical link (empty when not same-site/parseable). */
function pathnameOf(link: string | undefined): string | undefined {
	if (!link) {
		return undefined;
	}
	try {
		const url = new URL(link);
		return url.pathname;
	} catch {
		return undefined;
	}
}
