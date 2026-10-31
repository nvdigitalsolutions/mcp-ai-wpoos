import type { APIRoute } from 'astro';

/** /robots.txt — indexable, with a self-referential sitemap pointer. */
export const GET: APIRoute = async ({ site }) => {
	const sitemap = site ? new URL('/sitemap.xml', site).href : undefined;
	const body = ['User-agent: *', 'Allow: /', sitemap ? `Sitemap: ${sitemap}` : '', '']
		.filter(Boolean)
		.join('\n');
	return new Response(body, { headers: { 'content-type': 'text/plain; charset=utf-8' } });
};
