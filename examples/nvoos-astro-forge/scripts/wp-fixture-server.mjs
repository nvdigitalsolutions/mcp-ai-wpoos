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
			namespaces: ['wp/v2', 'wc/v3', 'oembed/1.0'],
			show_on_front: 'page',
			page_on_front: 11,
		},
		postTypes: {
			post: { name: 'Posts', rest_base: 'posts' },
			page: { name: 'Pages', rest_base: 'pages' },
			product: { name: 'Products', rest_base: 'product' },
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
		/** WooCommerce wc/v3 flat documents — prices, stock, images, categories. */
		wooProducts: [
			{
				id: 101,
				name: 'Fjord Merino Sweater',
				slug: 'fjord-merino-sweater',
				permalink: `${ORIGIN}/product/fjord-merino-sweater/`,
				date_created_gmt: '2026-10-10T00:00:00',
				date_modified_gmt: '2026-10-12T00:00:00',
				description: '<p>Three-ply merino, expedition weight.</p>',
				short_description: 'Three-ply merino.',
				price: '120.00',
				regular_price: '120.00',
				sale_price: '',
				stock_status: 'instock',
				sku: 'FJ-101',
				images: [{ src: `${ORIGIN}/wp-content/uploads/product-a.png` }],
				categories: [{ name: 'Knitwear' }],
			},
			{
				id: 102,
				name: 'Harbor Linen Shirt',
				slug: 'harbor-linen-shirt',
				permalink: `${ORIGIN}/product/harbor-linen-shirt/`,
				date_created_gmt: '2026-10-11T00:00:00',
				date_modified_gmt: '2026-10-11T00:00:00',
				description: '<p>Washed linen, relaxed cut.</p>',
				short_description: 'Washed linen.',
				price: '90.00',
				regular_price: '110.00',
				sale_price: '90.00',
				stock_status: 'outofstock',
				sku: 'HB-102',
				images: [{ src: `${ORIGIN}/wp-content/uploads/product-b.png` }],
				categories: [{ name: 'Shirts' }],
			},
			{
				id: 103,
				name: 'External CDN Cap',
				slug: 'external-cdn-cap',
				permalink: `${ORIGIN}/product/external-cdn-cap/`,
				date_created_gmt: '2026-10-12T00:00:00',
				date_modified_gmt: '2026-10-12T00:00:00',
				description: '<p>Image served from a CDN host — SmartImage passes it through.</p>',
				short_description: 'CDN image.',
				price: '25.00',
				regular_price: '25.00',
				sale_price: '',
				stock_status: 'instock',
				sku: 'EC-103',
				images: [{ src: 'https://cdn.fixture.test/product-c.png' }],
				categories: [{ name: 'Accessories' }],
			},
		],
		/** wp/v2 product CPT documents — no commerce fields, featured_media IDs. */
		wpProducts: [
			{
				id: 201,
				date_gmt: '2026-10-01T00:00:00',
				modified_gmt: '2026-10-01T00:00:00',
				title: { rendered: 'Plain CPT Product' },
				slug: 'plain-cpt-product',
				link: `${ORIGIN}/product/plain-cpt-product/`,
				content: { rendered: '<p>A product without commerce fields.</p>' },
				featured_media: 501,
			},
		],
		/** Media documents for featured_media resolution. */
		media: {
			501: { source_url: `${ORIGIN}/wp-content/uploads/media-501.png` },
		},
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
		const pageMatch = path.match(/^\/wp-json\/wp\/v2\/pages\/(\d+)$/);
		if (pageMatch) {
			const doc = data.pages.find((page) => page.id === Number(pageMatch[1]));
			if (doc) {
				json(res, { slug: doc.slug });
			} else {
				res.writeHead(404, { 'content-type': 'application/json' });
				res.end(JSON.stringify({ code: 'rest_post_invalid_id', message: 'Invalid page ID.' }));
			}
			return;
		}
		if (path === '/wp-json/wc/v3/products') {
			const { slice, headers } = paginate(data.wooProducts);
			json(res, slice, headers);
			return;
		}
		if (path === '/wp-json/wp/v2/product') {
			const { slice, headers } = paginate(data.wpProducts);
			json(res, slice, headers);
			return;
		}
		const mediaMatch = path.match(/^\/wp-json\/wp\/v2\/media\/(\d+)$/);
		if (mediaMatch) {
			const doc = data.media[Number(mediaMatch[1])];
			if (doc) {
				json(res, doc);
			} else {
				res.writeHead(404, { 'content-type': 'application/json' });
				res.end(JSON.stringify({ code: 'rest_post_invalid_id', message: 'Invalid media ID.' }));
			}
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
