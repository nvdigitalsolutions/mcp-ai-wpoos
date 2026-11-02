import type { APIRoute } from 'astro';

import manifest from '../../nvoos.json';

/** /robots.txt — indexable, with a self-referential sitemap pointer (the
 * pointer is dropped when the sitemap surface is off). Prerendered in both
 * render modes. */
export const prerender = true;

export const GET: APIRoute = async ({ site }) => {
	const sitemap =
		site && manifest.surfaces?.sitemap !== false ? new URL('/sitemap.xml', site).href : undefined;
	const body = ['User-agent: *', 'Allow: /', sitemap ? `Sitemap: ${sitemap}` : '', '']
		.filter(Boolean)
		.join('\n');
	return new Response(body, { headers: { 'content-type': 'text/plain; charset=utf-8' } });
};
