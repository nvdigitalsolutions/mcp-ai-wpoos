import type { APIRoute } from 'astro';

import manifest from '../../nvoos.json';

/** /robots.txt — indexable, with a self-referential sitemap pointer (the
 * pointer is dropped when the sitemap surface is off). Prerendered in both
 * render modes.
 *
 * `NVOOS_FORGE_NOINDEX=1` closes the whole site to crawlers: a staging or
 * preview deployment is built for a temporary origin, and letting it be
 * indexed creates a duplicate of the production site on that host. The
 * per-page `<meta name="robots">` follows the same flag (Base.astro). */
export const prerender = true;

export const GET: APIRoute = async ({ site }) => {
	if ( process.env.NVOOS_FORGE_NOINDEX === '1' ) {
		const body = [ 'User-agent: *', 'Disallow: /', '' ].join( '\n' );
		return new Response( body, { headers: { 'content-type': 'text/plain; charset=utf-8' } } );
	}
	const sitemap =
		site && manifest.surfaces?.sitemap !== false ? new URL('/sitemap.xml', site).href : undefined;
	const body = [ 'User-agent: *', 'Allow: /', sitemap ? `Sitemap: ${sitemap}` : '', '' ]
		.filter( Boolean )
		.join( '\n' );
	return new Response( body, { headers: { 'content-type': 'text/plain; charset=utf-8' } } );
};
