import type { APIRoute } from 'astro';
import { getCollection } from 'astro:content';
import manifest from '../../nvoos.json';

/** /feed.xml — RSS 2.0 over the posts collection. */
export const GET: APIRoute = async ({ site }) => {
	const posts = (await getCollection('posts'))
		.filter((post) => post.data.date)
		.sort((a, b) => b.data.date.localeCompare(a.data.date));
	const origin = site ? new URL('/', site).href.replace(/\/+$/, '') : '';

	const items = posts
		.map((post) => {
			const url = new URL(`/posts/${post.id}/${post.data.slug}/`, site).href;
			return [
				'<item>',
				`\t<title>${escapeXml(post.data.title)}</title>`,
				`\t<link>${url}</link>`,
				`\t<guid isPermaLink="true">${url}</guid>`,
				`\t<pubDate>${new Date(post.data.date).toUTCString()}</pubDate>`,
				post.data.excerpt ? `\t<description>${escapeXml(stripHtml(post.data.excerpt))}</description>` : '',
				'</item>',
			]
				.filter(Boolean)
				.join('\n');
		})
		.join('\n');

	const body = `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
\t<title>${escapeXml(manifest.name ?? 'Forge Site')}</title>
\t<link>${origin}/</link>
\t<description>Latest posts.</description>
\t<atom:link href="${new URL('/feed.xml', site).href}" rel="self" type="application/rss+xml" />
${items}
</channel>
</rss>
`;
	return new Response(body, { headers: { 'content-type': 'application/rss+xml; charset=utf-8' } });
};

function escapeXml(value: string): string {
	return value
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&apos;');
}

function stripHtml(html: string): string {
	return html.replace(/<[^>]*>/g, '');
}
