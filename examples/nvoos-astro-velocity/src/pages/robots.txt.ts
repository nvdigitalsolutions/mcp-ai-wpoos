// robots.txt — SSR endpoint so the sitemap URL uses the configured site host.
import type { APIRoute } from 'astro';

export const GET: APIRoute = ({ site }) => {
	const base = site ?? new URL('https://store.nvoos.cloud');
	const body = [
		'User-agent: *',
		'Allow: /',
		'',
		`Sitemap: ${base.toString().replace(/\/+$/, '')}/sitemap.xml`,
		'',
	].join('\n');

	return new Response(body, {
		status: 200,
		headers: {
			'content-type': 'text/plain; charset=utf-8',
			'cache-control': 'public, s-maxage=3600',
		},
	});
};
