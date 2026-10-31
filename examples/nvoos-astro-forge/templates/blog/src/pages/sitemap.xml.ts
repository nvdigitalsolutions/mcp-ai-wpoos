import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';

/** /sitemap.xml — every generated URL, self-referential (never the WP origin). */
export const GET: APIRoute = async ({ site }) => {
	const posts = await getCollection('posts');
	const pages = await getCollection('pages');

	const urls = [
		site ? new URL('/', site).href : null,
		site ? new URL('/posts/', site).href : null,
		...posts.map((post) => (site ? new URL(`/posts/${post.id}/${post.data.slug}/`, site).href : null)),
		...pages.map((page) => (site ? new URL(`/${page.data.slug}/`, site).href : null)),
	].filter((url): url is string => url !== null);

	const body = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${urls.map((url) => `\t<url><loc>${url}</loc></url>`).join('\n')}
</urlset>
`;
	return new Response(body, { headers: { 'content-type': 'application/xml; charset=utf-8' } });
};
