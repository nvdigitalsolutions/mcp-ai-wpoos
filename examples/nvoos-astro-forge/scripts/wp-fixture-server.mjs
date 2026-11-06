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
				// Yoast-style SEO meta — the loader's fallback path (106 §1.2).
				yoast_head_json: {
					title: 'Care guide for wool, cashmere and linen — the studio journal',
					description: 'Wash, store and repair natural fibres the studio way.',
				},
				content: {
					rendered: [
						'<figure class="wp-block-image"><img src="https://fixture.test/care.jpg" alt="Folded knits" width="800" height="450" loading="lazy" /><figcaption>Folded knits, ready to store.</figcaption></figure>',
						`<img src="${ORIGIN}/wp-content/uploads/care-local.jpg" alt="Local care image" />`,
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
					rendered: [
						'<p>Safe paragraph.</p><script>alert("xss")</script><img src="x" onerror="steal()" alt="trap" /><a href="javascript:alert(1)">click</a>',
						// Forms (114 §3.9): a search form, an Elementor submit form
						// with its hidden carriers, and XSS vectors inside form
						// controls (onfocus + javascript: value) — the sanitizer
						// keeps the functional allowlist and kills the vectors.
						'<form role="search"><input type="search" name="s" placeholder="Search the fixture" /><button type="submit">Go</button></form>',
						'<form class="elementor-form" method="post" action="https://fixture.test/?elementor-pro-forms=submit">' +
							'<input type="hidden" name="form_id" value="fixture-form-1" />' +
							'<input type="hidden" name="post_id" value="3" />' +
							'<label for="trap-name">Name</label>' +
							'<input type="text" name="form_fields[name]" id="trap-name" required="required" placeholder="Jane" />' +
							'<label for="trap-msg">Message</label>' +
							'<textarea name="form_fields[message]" id="trap-msg" rows="4"></textarea>' +
							'<input type="text" name="evil" onfocus="alert(2)" value="javascript:alert(3)" />' +
							'<button type="submit">Send</button>' +
							'</form>',
					].join( '' ),
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
				// Rank Math-style SEO meta — the loader's primary path (106 §1.2).
				rank_math_title: 'Studio Notes: The Autumn Palette — a curated journal',
				rank_math_description: 'Moss, bark and bone: the autumn palette, curated by the studio.',
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
			{
				id: 6,
				date_gmt: '2026-10-15T09:00:00',
				modified_gmt: '2026-10-15T09:00:00',
				title: { rendered: 'The elementor landing post (114 fixture)' },
				slug: 'elementor-landing',
				link: `${ORIGIN}/elementor-landing/`,
				excerpt: { rendered: '<p>A builder-built landing section the semantic renderer rebuilds.</p>' },
				content: {
					rendered: [
						'<div class="elementor-section elementor-top-section"><div class="elementor-container"><div class="elementor-column"><div class="elementor-widget-wrap">',
						'<div class="elementor-widget elementor-widget-heading"><div class="elementor-widget-container"><h2>Ship to the fjords</h2></div></div>',
						'<div class="elementor-widget elementor-widget-text-editor"><div class="elementor-widget-container"><p>Fast and easy.</p></div></div>',
						'<div class="elementor-widget elementor-widget-button"><div class="elementor-widget-container"><a href="#about">Read More</a></div></div>',
						'</div></div></div></div>',
						'<div class="elementor-section elementor-top-section"><div class="elementor-container"><div class="elementor-column"><div class="elementor-widget-wrap">',
						'<div class="elementor-widget elementor-widget-icon-box"><div class="elementor-widget-container"><h3>Wool</h3><p>Merino and cashmere.</p></div></div>',
						'<div class="elementor-widget elementor-widget-icon-box"><div class="elementor-widget-container"><h3>Linen</h3><p>Washed and relaxed.</p></div></div>',
						'</div></div></div></div>',
						// A logo wall carrying a heading + CTA — the LogoCloud
						// component must render all three, never drop the links.
						'<div class="elementor-section elementor-top-section"><div class="elementor-container"><div class="elementor-column"><div class="elementor-widget-wrap">',
						'<div class="elementor-widget elementor-widget-heading"><div class="elementor-widget-container"><h2>Trusted by crews</h2></div></div>',
						'<div class="elementor-widget elementor-widget-image"><div class="elementor-widget-container"><img src="/wp-content/uploads/a.png" alt="Alpha" /></div></div>',
						'<div class="elementor-widget elementor-widget-image"><div class="elementor-widget-container"><img src="/wp-content/uploads/b.png" alt="Beta" /></div></div>',
						'<div class="elementor-widget elementor-widget-image"><div class="elementor-widget-container"><img src="/wp-content/uploads/c.png" alt="Gamma" /></div></div>',
						'<div class="elementor-widget elementor-widget-button"><div class="elementor-widget-container"><a href="#crews">Meet the crews</a></div></div>',
						'</div></div></div></div>',
						// A heading + single image section (a title card) — the
						// text fallback must render the media too.
						'<div class="elementor-section elementor-top-section"><div class="elementor-container"><div class="elementor-column"><div class="elementor-widget-wrap">',
						'<div class="elementor-widget elementor-widget-heading"><div class="elementor-widget-container"><h2>Field notes</h2></div></div>',
						'<div class="elementor-widget elementor-widget-image"><div class="elementor-widget-container"><img src="/wp-content/uploads/notes.png" alt="Field notes" /></div></div>',
						'</div></div></div></div>',
					].join( '' ),
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
				rank_math_title: 'About Fixture Wares',
				content: {
					rendered:
						// The split-hero fixture (125 §1): a copy column + a media
						// collage column. The section id is hex — Elementor's real
						// id shape; the classifier only extracts [0-9a-f]{4,} —
						// and the profile's design.sections entry (a1b2c3d) carries
						// the split facts the rebuilt home must apply.
						'<section class="elementor-section elementor-top-section elementor-element elementor-element-a1b2c3d">' +
						'<div class="elementor-container">' +
						'<div class="elementor-column elementor-col-50"><div class="elementor-widget-wrap">' +
						'<div class="elementor-element elementor-element-sf1 elementor-widget elementor-widget-aux_modern_heading"><div class="elementor-widget-container"><h2 class="aux-modern-heading-primary">At Fixture Wares we serve the fjords.</h2><div class="aux-modern-heading-divider"></div></div></div>' +
						'<div class="elementor-element elementor-element-sf2 elementor-widget elementor-widget-text-editor"><div class="elementor-widget-container"><p>Your needs are our top priority.</p></div></div>' +
						'<div class="elementor-element elementor-element-sf3 elementor-widget elementor-widget-text-editor"><div class="elementor-widget-container"><p>We source every major brand.</p></div></div>' +
						'<div class="elementor-element elementor-element-sf4 elementor-widget elementor-widget-aux_button"><div class="elementor-widget-container"><a href="#products">Our Products</a></div></div>' +
						'</div></div>' +
						'<div class="elementor-column elementor-col-50"><div class="elementor-widget-wrap">' +
						'<div class="elementor-element elementor-element-sf5 elementor-widget elementor-widget-aux_simple_shape"><div class="elementor-widget-container"><div class="aux-the-shape"></div></div></div>' +
						// The media URLs are absolute here — the split-hero's SmartImage
						// pipeline only optimizes same-host WP sources (relative fixture
						// paths would silently skip the processed-image e2e pin).
						'<div class="elementor-element elementor-element-sf6 elementor-widget elementor-widget-aux_image"><div class="elementor-widget-container"><div class="aux-media-image"><img src="' + ORIGIN + '/wp-content/uploads/a.png" alt="Ronan" /></div></div></div>' +
						'<div class="elementor-element elementor-element-sf7 elementor-widget elementor-widget-aux_image"><div class="elementor-widget-container"><div class="aux-media-image"><img src="' + ORIGIN + '/wp-content/uploads/b.png" alt="Elevate" /></div></div></div>' +
						'</div></div>' +
						'</div>' +
						'</section>' +
						// The flipbook catalog (125 §4): the PDF source lives only
						// in the dflip script — the profile's design.sections fact
						// (b4c5d6e) must turn the sanitized empty band into
						// a titled open-catalogue CTA.
						'<section class="elementor-section elementor-top-section elementor-element elementor-element-b4c5d6e">' +
						'<div class="elementor-container"><div class="elementor-column"><div class="elementor-widget-wrap">' +
						'<div class="elementor-element elementor-element-cf1 elementor-widget elementor-widget-shortcode"><div class="elementor-widget-container"><div class="elementor-shortcode">' +
						'<div class="_df_book df-lite" id="df_2113" _slug="fiori-digital-catalogue" data-title="fiori-digital-catalogue" wpoptions="true"></div>' +
						'<script class="df-shortcode-script" type="application/javascript">window.option_df_2113 = {"outline":[],"source":"https://fixture.test/wp-content/uploads/catalog.pdf"}; if(window.DFLIP){window.DFLIP.parseBooks();}</script>' +
						'</div></div></div>' +
						'</div></div></div>' +
						'</section>',
				},
			},
			{
				id: 12,
				date_gmt: '2026-10-01T00:00:00',
				modified_gmt: '2026-10-01T00:00:00',
				title: { rendered: 'Contact' },
				slug: 'contact',
				link: `${ORIGIN}/contact/`,
				excerpt: { rendered: '' },
				content: {
					rendered: [
						'<p>Write to <a href="mailto:hello@fixture.test">hello@fixture.test</a>.</p>',
						// A JetFormBuilder form — the hidden carrier gives the WP form id.
						'<form class="jet-form-builder" method="post">' +
							'<input type="hidden" name="_jet_engine_booking_form_id" value="9" />' +
							'<label>Email</label>' +
							'<input type="email" name="email" required="required" />' +
							'<label>Message</label>' +
							'<textarea name="message" rows="5"></textarea>' +
							'<button type="submit">Ask away</button>' +
							'</form>',
					].join( '' ),
				},
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
		/** Payload-envelope products (nvoos-commerce/v1 — the bridge wire shape). */
		payloadProducts: [
			{
				id: 301,
				name: 'Aurora 3-Seater Sofa',
				price: 1299,
				oldPrice: 1499,
				description: '<p>A deep, cloud-soft three-seater with a solid oak base.</p>',
				category: { id: 1, name: 'Sofas' },
				image: `${ORIGIN}/wp-content/uploads/product-a.png`,
				images: [`${ORIGIN}/wp-content/uploads/product-a.png`],
				statuses: [{ id: 1, name: 'In Stock' }],
				createdAt: '2026-09-10 10:00:00',
				updatedAt: '2026-10-20 09:30:00',
				status: 'publish',
			},
			{
				id: 302,
				name: 'Birch Side Table',
				price: 240,
				description: '<p>Turned birch, oiled finish.</p>',
				category: { id: 2, name: 'Tables' },
				image: `${ORIGIN}/wp-content/uploads/product-b.png`,
				statuses: [{ id: 2, name: 'Out of Stock' }],
				createdAt: '2026-09-11 10:00:00',
				updatedAt: '2026-09-11 10:00:00',
				status: 'publish',
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

		// wp/v2 honours `slug` as a collection query param — the fixture
		// mirrors that (the content tools filter by slug).
		const slug = url.searchParams.get('slug');
		const bySlug = (all) => (slug ? all.filter((entry) => entry.slug === slug) : all);

		if (path === '/health') {
			res.writeHead(200, { 'content-type': 'text/plain' });
			res.end('ok');
			return;
		}
		// The homepage — the design-import fetch target (114 P1): a minimal
		// HTML document with an Elementor-style inline <style> so the wizard's
		// --design step extracts deterministic roles hermetically.
		if (path === '/') {
			res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
			res.end(
				'<!doctype html><html><head><style>' +
					':root{--e-global-color-primary:#6ec1e4;--e-global-color-text:#444444;' +
					'--e-global-typography-primary-font-family:"Rubik";--e-global-typography-text-font-family:"Roboto";}' +
					'.hero{font-size:40px;padding:16px;border-radius:4px}' +
					'</style></head><body><h1 class="hero">Fixture Wares</h1>' +
					'<a class="btn">Buy</a><div class="card"></div></body></html>'
			);
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
			const { slice, headers } = paginate(bySlug(data.posts));
			json(res, slice, headers);
			return;
		}
		if (path === '/wp-json/wp/v2/pages') {
			const { slice, headers } = paginate(bySlug(data.pages));
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
			const { slice, headers } = paginate(bySlug(data.wooProducts));
			json(res, slice, headers);
			return;
		}
		// Single-product detail routes (Proposal 104 Q4 — the SSR product
		// route fetches one document at request time; the shapes mirror the
		// collection documents so both render paths stay identical).
		const wooProductMatch = path.match(/^\/wp-json\/wc\/v3\/products\/(\d+)$/);
		if (wooProductMatch) {
			const doc = data.wooProducts.find((product) => product.id === Number(wooProductMatch[1]));
			if (doc) {
				json(res, doc);
			} else {
				res.writeHead(404, { 'content-type': 'application/json' });
				res.end(JSON.stringify({ code: 'woocommerce_rest_product_invalid_id', message: 'Invalid product ID.' }));
			}
			return;
		}
		if (path === '/wp-json/wp/v2/product') {
			const { slice, headers } = paginate(data.wpProducts);
			json(res, slice, headers);
			return;
		}
		const wpProductMatch = path.match(/^\/wp-json\/wp\/v2\/product\/(\d+)$/);
		if (wpProductMatch) {
			const doc = data.wpProducts.find((product) => product.id === Number(wpProductMatch[1]));
			if (doc) {
				// _embed carries the featured media, mirroring wp/v2 collections.
				const sourceUrl = data.media[doc.featured_media]?.source_url;
				json(res, sourceUrl ? { ...doc, _embedded: { 'wp:featuredmedia': [{ source_url: sourceUrl }] } } : doc);
			} else {
				res.writeHead(404, { 'content-type': 'application/json' });
				res.end(JSON.stringify({ code: 'rest_post_invalid_id', message: 'Invalid product ID.' }));
			}
			return;
		}
		if (path === '/wp-json/nvoos-commerce/v1/products') {
			const limit = Number(url.searchParams.get('limit') ?? '100');
			const pageNum = Number(url.searchParams.get('page') ?? '1');
			const total = data.payloadProducts.length;
			const totalPages = Math.max(1, Math.ceil(total / limit));
			const slice = data.payloadProducts.slice((pageNum - 1) * limit, pageNum * limit);
			json(res, {
				docs: slice,
				totalDocs: total,
				limit,
				totalPages,
				page: pageNum,
				hasNextPage: pageNum < totalPages,
				hasPrevPage: pageNum > 1,
			});
			return;
		}
		const payloadProductMatch = path.match(/^\/wp-json\/nvoos-commerce\/v1\/products\/(\d+)$/);
		if (payloadProductMatch) {
			const doc = data.payloadProducts.find((product) => product.id === Number(payloadProductMatch[1]));
			if (doc) {
				json(res, doc);
			} else {
				res.writeHead(404, { 'content-type': 'application/json' });
				res.end(JSON.stringify({ code: 'not_found', message: 'Product not found.' }));
			}
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
