import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import manifest from '../../nvoos.json';

/** /sitemap.xml — every generated URL, self-referential (never the WP origin).
 * Prerendered in both render modes. */
export const prerender = true;

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

	const urls = [
		site ? new URL('/', site).href : null,
		site ? new URL('/posts/', site).href : null,
		hasProducts && site ? new URL('/products/', site).href : null,
		...posts.map((post) => (site ? new URL(`/posts/${post.id}/${post.data.slug}/`, site).href : null)),
		...products.map((product) =>
			site ? new URL(`/products/${product.id}/${product.data.slug}/`, site).href : null
		),
		...pages
			.filter((page) => page.data.slug !== frontPageSlug)
			.map((page) => (site ? new URL(`/${page.data.slug}/`, site).href : null)),
	].filter((url): url is string => url !== null);

	const body = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls.map((url) => `\t<url><loc>${url}</loc></url>`).join('\n')}
</urlset>
`;
	return new Response(body, { headers: { 'content-type': 'application/xml; charset=utf-8' } });
};
