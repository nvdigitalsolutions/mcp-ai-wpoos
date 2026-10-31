#!/usr/bin/env node
// ---------------------------------------------------------------------------
// wp-fixture-server.mjs — the hermetic WordPress REST fixture (096 exit gate).
//
// Serves the real wp/v2 wire shapes (JSON bodies + X-WP-Total headers,
// `_fields`/`_embed` query params) so the Forge e2e loop — scaffold → build →
// assert — never depends on a live WordPress. Stands in for the WordPress
// Playground fixture in CI.
//
//   node scripts/wp-fixture-server.mjs [--port 8473] [--quiet]
// ---------------------------------------------------------------------------

import http from 'node:http';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const ARGS = new Map();
for (let i = 0; i < process.argv.length; i++) {
	const arg = process.argv[i];
	if (arg.startsWith('--')) {
		const next = process.argv[i + 1] && !process.argv[i + 1].startsWith('--') ? process.argv[i + 1] : 'true';
		ARGS.set(arg, next);
	}
}
const PORT = Number(ARGS.get('--port') ?? process.env.WP_FIXTURE_PORT ?? 8473);
const QUIET = ARGS.has('--quiet');

const ORIGIN = `http://127.0.0.1:${PORT}`;

/** One transparent 1x1 PNG (featured media). */
const PIXEL_PNG = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
	'base64'
);

/** The fixture content — one XSS-carrying post included on purpose. */
export function createWpFixtureData() {
	return {
		root: {
			name: 'Fixture Wares',
			description: 'Hermetic WordPress fixture for the Astro Forge e2e loop.',
			url: ORIGIN,
			namespaces: ['wp/v2', 'oembed/1.0'],
		},
		postTypes: {
			post: { name: 'Posts', rest_base: 'posts' },
			page: { name: 'Pages', rest_base: 'pages' },
		},
		posts: [
			{
				id: 1,
				date_gmt: '2026-10-28T09:00:00',
				modified_gmt: '2026-10-30T10:00:00',
				title: { rendered: 'Fjord merino sweater, first look' },
				slug: 'fjord-merino-sweater',
				link: `${ORIGIN}/fjord-merino-sweater/`,
				excerpt: { rendered: '<p>A first look at the Fjord merino sweater.</p>' },
				content: {
					rendered: [
						'<h2>First look</h2>',
						'<p>The <strong>Fjord merino</strong> arrives in three weights.',
						' <a href="https://example.com/wool">Read the wool guide</a>.</p>',
						'<ul><li>Lightweight</li><li>Midweight</li><li>Expedition</li></ul>',
					].join(''),
				},
				_embedded: { 'wp:featuredmedia': [{ source_url: `${ORIGIN}/wp-content/uploads/featured.png` }] },
			},
			{
				id: 2,
				date_gmt: '2026-10-26T09:00:00',
				modified_gmt: '2026-10-26T09:00:00',
				title: { rendered: 'Care guide: wool, cashmere and linen' },
				slug: 'care-guide',
				link: `${ORIGIN}/care-guide/`,
				excerpt: { rendered: '<p>How to wash, store and repair natural fibres.</p>' },
				content: {
					rendered: [
						'<figure class="wp-block-image"><img src="https://fixture.test/care.jpg" alt="Folded knits" width="800" height="450" loading="lazy" /><figcaption>Folded knits, ready to store.</figcaption></figure>',
						'<blockquote class="wp-block-quote"><p>Cold water and flat drying keep fibres alive for decades.</p></blockquote>',
						'<table><thead><tr><th>Fibre</th><th>Wash</th></tr></thead><tbody><tr><td>Wool</td><td>Cold, wool cycle</td></tr><tr><td>Linen</td><td>Warm, gentle</td></tr></tbody></table>',
					].join(''),
				},
			},
			{
				id: 3,
				date_gmt: '2026-10-24T09:00:00',
				modified_gmt: '2026-10-24T09:00:00',
				title: { rendered: 'The xss-carrying post (fixture trap)' },
				slug: 'xss-trap',
				link: `${ORIGIN}/xss-trap/`,
				excerpt: { rendered: '<p>This post carries script content the sanitizer must strip.</p>' },
				content: {
					rendered:
						'<p>Safe paragraph.</p><script>alert("xss")</script><img src="x" onerror="steal()" alt="trap" /><a href="javascript:alert(1)">click</a>',
				},
			},
			{
				id: 4,
				date_gmt: '2026-10-20T09:00:00',
				modified_gmt: '2026-10-21T09:00:00',
				title: { rendered: 'Studio notes: autumn palette' },
				slug: 'studio-notes-autumn',
				link: `${ORIGIN}/studio-notes-autumn/`,
				excerpt: { rendered: '<p>Moss, bark and bone: the autumn palette board.</p>' },
				content: {
					rendered:
						'<p>Moss, bark and bone.</p><iframe src="https://www.youtube-nocookie.com/embed/abc123" title="Palette film" width="560" height="315" frameborder="0" allowfullscreen></iframe>',
				},
			},
			{
				id: 5,
				date_gmt: '2026-10-18T09:00:00',
				modified_gmt: '2026-10-18T09:00:00',
				title: { rendered: 'Stitch pattern: honeycomb rib' },
				slug: 'honeycomb-rib',
				link: `${ORIGIN}/honeycomb-rib/`,
				excerpt: { rendered: '<p>A written pattern with code-style rows.</p>' },
				content: {
					rendered: '<pre class="wp-block-code"><code>Row 1: k1, p1, repeat.</code></pre><p>Repeat rows 1\u20132.</p>',
				},
			},
		],
		pages: [
			{
				id: 11,
				date_gmt: '2026-10-01T00:00:00',
				modified_gmt: '2026-10-01T00:00:00',
				title: { rendered: 'About' },
				slug: 'about',
				link: `${ORIGIN}/about/`,
				excerpt: { rendered: '' },
				content: { rendered: '<p>Fixture Wares is the hermetic test storefront.</p>' },
			},
			{
				id: 12,
				date_gmt: '2026-10-01T00:00:00',
				modified_gmt: '2026-10-01T00:00:00',
				title: { rendered: 'Contact' },
				slug: 'contact',
				link: `${ORIGIN}/contact/`,
				excerpt: { rendered: '' },
				content: { rendered: '<p>Write to <a href="mailto:hello@fixture.test">hello@fixture.test</a>.</p>' },
			},
		],
	};
}

function json(res, body, headers = {}) {
	res.writeHead(200, { 'content-type': 'application/json; charset=utf-8', ...headers });
	res.end(JSON.stringify(body));
}

export function createWpFixtureServer(port = PORT) {
	const data = createWpFixtureData();

	return http.createServer((req, res) => {
		const url = new URL(req.url ?? '/', ORIGIN);
		const path = url.pathname.replace(/\/+$/, '') || '/';
		const page = Number(url.searchParams.get('page') ?? '1');
		const perPage = Number(url.searchParams.get('per_page') ?? '100');

		const paginate = (all) => {
			const totalPages = Math.max(1, Math.ceil(all.length / perPage));
			const slice = all.slice((page - 1) * perPage, page * perPage);
			return { slice, headers: { 'x-wp-total': String(all.length), 'x-wp-totalpages': String(totalPages) } };
		};

		if (path === '/health') {
			res.writeHead(200, { 'content-type': 'text/plain' });
			res.end('ok');
			return;
		}
		if (path === '/wp-json' || path === '/wp-json/') {
			json(res, data.root);
			return;
		}
		if (path === '/wp-json/wp/v2/types') {
			json(res, data.postTypes);
			return;
		}
		if (path === '/wp-json/wp/v2/posts') {
			const { slice, headers } = paginate(data.posts);
			json(res, slice, headers);
			return;
		}
		if (path === '/wp-json/wp/v2/pages') {
			const { slice, headers } = paginate(data.pages);
			json(res, slice, headers);
			return;
		}
		if (path.startsWith('/wp-content/uploads/')) {
			res.writeHead(200, { 'content-type': 'image/png', 'content-length': String(PIXEL_PNG.length) });
			res.end(PIXEL_PNG);
			return;
		}
		res.writeHead(404, { 'content-type': 'application/json' });
		res.end(JSON.stringify({ code: 'rest_no_route', message: 'No route was found matching the URL and request method.' }));
	});
}

// Direct execution: serve until killed.
const invokedPath = process.argv[1] ? pathToFileURL(path.resolve(process.argv[1])).href : '';
if (import.meta.url === invokedPath) {
	const server = createWpFixtureServer(PORT);
	server.listen(PORT, '127.0.0.1', () => {
		if (!QUIET) {
			console.log(`WP fixture listening on ${ORIGIN}`);
		}
	});
	process.on('SIGINT', () => server.close(() => process.exit(0)));
}
