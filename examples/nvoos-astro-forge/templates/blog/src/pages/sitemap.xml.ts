import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import manifest from '../../nvoos.json';

/** /sitemap.xml — every generated URL, self-referential (never the WP origin).
 * Prerendered in both render modes.
 *
 * Proposal 131 §2.4: entries the source marks `noindex` are excluded (a
 * noindex URL in the sitemap is a Search Console error), `<lastmod>` carries
 * the source's `modified_gmt`, and image URLs ride the image extension.
 */
export const prerender = true;

/** A W3C datetime; WP `_gmt` fields omit the zone but are UTC. */
function isoUtc(value?: string): string | undefined {
	if (!value) {
		return undefined;
	}
	const trimmed = value.trim();
	if (!trimmed) {
		return undefined;
	}
	const candidate = /Z|[+-]\d{2}:?\d{2}$/i.test(trimmed) ? trimmed : `${trimmed}Z`;
	const parsed = new Date(candidate);
	return Number.isNaN(parsed.getTime()) ? undefined : parsed.toISOString();
}

/** Escape the five XML entities in a URL or text node. */
function xml(value: string): string {
	return value
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&apos;');
}

interface Entry {
	loc: string;
	lastmod?: string;
	image?: string;
}

export const GET: APIRoute = async ({ site }) => {
	const posts = await getCollection('posts');
	const pages = await getCollection('pages');
	// The products pages are pruned by the scaffold when the profile has no
	// products collection — mirror that here so the endpoint never references
	// an undefined collection. `astro check` types getCollection against the
	// declared collections, so the cast documents that products exists only
	// in storefront scaffolds (the manifest gate keeps the call unreachable
	// otherwise; id/slug are common to every collection shape).
	const hasProducts = manifest.collections.some((collection) => collection.slug === 'products');
	const products = hasProducts ? await getCollection('products' as 'posts') : [];
	const frontPageSlug = manifest.frontPage;

	/** `noindex` in the source's robots directive keeps a URL out of the map. */
	const indexable = (seo: { robots?: string } | undefined) => !(seo?.robots ?? '').toLowerCase().includes('noindex');

	const entries: Entry[] = [];
	const push = (path: string, data: { modified?: string; featuredImage?: string; seo?: { robots?: string } }) => {
		if (!site || !indexable(data.seo)) {
			return;
		}
		entries.push({
			loc: new URL(path, site).href,
			lastmod: isoUtc(data.modified),
			image: data.featuredImage,
		});
	};

	if (site) {
		entries.push({ loc: new URL('/', site).href });
		entries.push({ loc: new URL('/posts/', site).href });
		if (hasProducts) {
			entries.push({ loc: new URL('/products/', site).href });
		}
	}
	for (const post of posts) {
		push(`/posts/${post.id}/${post.data.slug}/`, post.data);
	}
	for (const product of products) {
		push(`/products/${product.id}/${product.data.slug}/`, product.data);
	}
	for (const page of pages) {
		if (page.data.slug !== frontPageSlug) {
			push(`/${page.data.slug}/`, page.data);
		}
	}

	const body = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
	xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
${entries
	.map((entry) =>
		[
			'\t<url>',
			`\t\t<loc>${xml(entry.loc)}</loc>`,
			entry.lastmod ? `\t\t<lastmod>${entry.lastmod}</lastmod>` : '',
			entry.image
				? `\t\t<image:image><image:loc>${xml(entry.image)}</image:loc></image:image>`
				: '',
			'\t</url>',
		]
			.filter(Boolean)
			.join('\n')
	)
	.join('\n')}
</urlset>
`;
	return new Response(body, { headers: { 'content-type': 'application/xml; charset=utf-8' } });
};
