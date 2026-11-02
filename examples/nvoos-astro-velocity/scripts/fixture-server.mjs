#!/usr/bin/env node
// ---------------------------------------------------------------------------
// Fixture server — serves tests/fixtures/*.json as the NV commerce contract
// (P5 golden-contract smoke + hermetic `astro build` and BFF smoke tests).
//
//   NVOOS_API_BASE=http://localhost:8123/wp-json/nvoos-commerce/v1
//   NVOOS_TARGET_SITE=http://localhost:8123
//
// Mirrors bridge semantics: envelope pagination, where operators (B2),
// sort, promocode validation, globals and the auth surface (Q5).
// ---------------------------------------------------------------------------

import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const PORT = Number(process.env.PORT) || 8123;
const HERE = dirname(fileURLToPath(import.meta.url));
const FIXTURES = join(HERE, '..', 'tests', 'fixtures');

const load = (name) =>
	JSON.parse(readFileSync(join(FIXTURES, `${name}.json`), 'utf8'));

const products = load('products');
const categories = load('categories');
const brands = load('brands');
const colors = load('colors');
const statuses = load('statuses');
const promotions = load('promotions');
const promocodes = load('promocodes');
const banner = load('banner');
const carousel = load('carousel');

const COLLECTIONS = {
	products,
	categories,
	brands,
	colors,
	statuses,
	promotions,
	promocodes,
};

// ---------------------------------------------------------------------------
// Proposal 106 §1.1 — a variable product fixture. Variants ride the
// SINGLE-doc route only (the Payload pattern: listings stay flat) and are
// kept OUT of the products array, so tests/fixtures/products.json stays
// byte-identical to the bridge goldens (the 9901-draft discipline).
// ---------------------------------------------------------------------------
const productVariants = {
	3: [
		{
			id: 301,
			sku: 'HLC-COMP-OAK',
			price: 749,
			oldPrice: 799,
			stock: 4,
			attributes: [{ name: 'Size', value: 'Compact' }],
			image: 'http://localhost:8000/wp-content/uploads/2026/10/halo-chair.jpg',
		},
		{
			id: 302,
			sku: 'HLC-STD-OAK',
			price: 799,
			oldPrice: 899,
			stock: 0,
			attributes: [{ name: 'Size', value: 'Standard' }],
			image: 'http://localhost:8000/wp-content/uploads/2026/10/halo-chair.jpg',
		},
		{
			id: 303,
			sku: 'HLC-LRG-OAK',
			price: 849,
			oldPrice: null,
			stock: 2,
			attributes: [{ name: 'Size', value: 'Large' }],
			image: 'http://localhost:8000/wp-content/uploads/2026/10/halo-chair-2.jpg',
		},
	],
};

// ---------------------------------------------------------------------------
// Proposal 105 §1 — the theme-policy doc. GET is public; PATCH mutates the
// in-memory copy through the manager seam (the nvoos_fixture_manager cookie,
// mirroring /auth/me) and re-stamps updatedAt — the SSR cache-key component.
// ---------------------------------------------------------------------------
let themePolicy = {
	policy: 'auto',
	contrast: 'auto',
	defaultTheme: 'dark',
	updatedAt: '2026-11-03T09:00:00Z',
	$extensions: {},
};

const POLICY_VALUES = ['auto', 'light', 'dark'];
const BRAND_SLUG_RE = /^brand:[a-z0-9-]+$/;
const CONTRAST_VALUES = ['auto', 'standard', 'high'];
const DEFAULT_THEME_VALUES = ['light', 'dark'];

function isManagerRequest(req) {
	return /(?:^|;\s*)nvoos_fixture_manager=1(?:;|$)/.test(String(req.headers.cookie ?? ''));
}

function patchThemePolicy(body) {
	const patch = {};
	if (body === null || typeof body !== 'object' || Array.isArray(body)) {
		return { error: { code: 'astro_theme_policy_invalid_body', message: 'Expected a JSON object.' } };
	}
	for (const [key, value] of Object.entries(body)) {
		if (key === 'policy') {
			if (typeof value !== 'string' || (!POLICY_VALUES.includes(value) && !BRAND_SLUG_RE.test(value))) {
				return { error: { code: 'astro_theme_policy_invalid_policy', message: 'policy must be auto | light | dark | brand:<slug>.' } };
			}
			patch.policy = value;
		} else if (key === 'contrast') {
			if (!CONTRAST_VALUES.includes(value)) {
				return { error: { code: 'astro_theme_policy_invalid_contrast', message: 'contrast must be auto | standard | high.' } };
			}
			patch.contrast = value;
		} else if (key === 'defaultTheme') {
			if (!DEFAULT_THEME_VALUES.includes(value)) {
				return { error: { code: 'astro_theme_policy_invalid_default_theme', message: 'defaultTheme must be light | dark.' } };
			}
			patch.defaultTheme = value;
		} else {
			return { error: { code: 'astro_theme_policy_invalid_field', message: `Unknown field: ${key}` } };
		}
	}
	themePolicy = {
		...themePolicy,
		...patch,
		updatedAt: new Date().toISOString(),
	};
	return { doc: themePolicy };
}

// ---------------------------------------------------------------------------
// Proposal 106 §1.3 — the shop globals doc (checkout handoff).
// ---------------------------------------------------------------------------
const shop = {
	checkoutUrl: 'https://nvoos.pro/checkout/',
	cartUrl: 'https://nvoos.pro/cart/',
	currency: 'USD',
	currencySymbol: '$',
	updatedAt: '2026-11-03T09:00:00Z',
};

// ---------------------------------------------------------------------------
// Proposal 106 §1.2 — CMS content fixtures (content/pages + content/posts).
// Inline (not tests/fixtures/*.json): the golden fixtures are the bridge
// product contract and stay byte-identical; the synthetic draft 9902 pins
// publish-only listings end to end.
// ---------------------------------------------------------------------------
const contentPages = [
	{
		id: 21,
		slug: 'about',
		title: 'About',
		excerpt: 'About the NV Store.',
		content: '<p>Raw about content.</p>',
		contentRendered: '<p>The <strong>NV Store</strong> is an NV-authored, MIT-licensed headless storefront.</p>',
		type: 'page',
		featuredImage: null,
		seoTitle: 'About | NV Store',
		seoDescription: 'About the NV Store — an NV-authored, MIT-licensed Astro storefront.',
		category: null,
		createdAt: '2026-09-10 10:00:00',
		updatedAt: '2026-10-20 09:30:00',
		status: 'publish',
	},
	{
		id: 22,
		slug: 'delivery',
		title: 'Delivery',
		excerpt: 'Delivery information.',
		content: '<p>Raw delivery content.</p>',
		contentRendered: '<p>Free delivery over $500. Standard orders ship in 2-4 working days.</p>',
		type: 'page',
		featuredImage: null,
		seoTitle: 'Delivery | NV Store',
		seoDescription: 'Delivery information for the NV Store.',
		category: null,
		createdAt: '2026-09-12 10:00:00',
		updatedAt: '2026-10-18 09:00:00',
		status: 'publish',
	},
];

const contentPosts = [
	{
		id: 101,
		slug: 'autumn-lookbook',
		title: 'Autumn Lookbook 2026',
		excerpt: 'Our autumn selection, in one look.',
		content: '<p>Raw lookbook content.</p>',
		contentRendered: '<p>Our autumn lookbook pairs warm terracotta tones with solid oak.</p>',
		type: 'post',
		featuredImage: null,
		seoTitle: 'Autumn Lookbook 2026 | NV Store',
		seoDescription: 'Our autumn selection, in one look.',
		category: { id: 3, name: 'Lookbooks' },
		createdAt: '2026-10-01 10:00:00',
		updatedAt: '2026-10-15 09:00:00',
		status: 'publish',
	},
	{
		id: 102,
		slug: 'caring-for-oak',
		title: 'Caring for Oak Furniture',
		excerpt: 'A two-minute care guide.',
		content: '<p>Raw care-guide content.</p>',
		contentRendered: '<p>Oil your oak twice a year and keep it out of direct sunlight.</p>',
		type: 'post',
		featuredImage: null,
		seoTitle: 'Caring for Oak Furniture | NV Store',
		seoDescription: 'A two-minute care guide for oak furniture.',
		category: { id: 4, name: 'Guides' },
		createdAt: '2026-10-05 10:00:00',
		updatedAt: '2026-10-12 10:00:00',
		status: 'publish',
	},
	{
		id: 103,
		slug: 'studio-notes-november',
		title: 'Studio Notes: November',
		excerpt: 'What the studio is working on.',
		content: '<p>Raw studio-notes content.</p>',
		contentRendered: '<p>November brings a new wool range and a smaller-footprint daybed.</p>',
		type: 'post',
		featuredImage: null,
		seoTitle: 'Studio Notes: November | NV Store',
		seoDescription: 'What the studio is working on this November.',
		category: { id: 3, name: 'Lookbooks' },
		createdAt: '2026-10-20 10:00:00',
		updatedAt: '2026-10-26 08:00:00',
		status: 'publish',
	},
	{
		id: 9902,
		slug: 'unreleased-draft-post',
		title: 'Unreleased Draft Post',
		excerpt: 'Draft fixture.',
		content: '<p>Draft content.</p>',
		contentRendered: '<p>Draft fixture — must never render publicly.</p>',
		type: 'post',
		featuredImage: null,
		seoTitle: '',
		seoDescription: '',
		category: { id: 3, name: 'Lookbooks' },
		createdAt: '2026-11-01 10:00:00',
		updatedAt: '2026-11-01 10:00:00',
		status: 'draft',
	},
];

const CONTENT_COLLECTIONS = {
	'content/pages': contentPages,
	'content/posts': contentPosts,
};

/** where[slug][equals] / where[category][equals] for content listings. */
function filterContent(docs, where) {
	let out = [...docs];
	for (const [field, operators] of Object.entries(where ?? {})) {
		for (const [operator, value] of Object.entries(operators)) {
			if (operator !== 'equals') continue;
			if (field === 'slug') {
				out = out.filter((doc) => doc.slug === String(value));
			}
			if (field === 'category') {
				out = out.filter((doc) => doc.category && doc.category.id === Number(value));
			}
		}
	}
	return out;
}

// Proposal 104 §2.6 — a synthetic DRAFT product, appended at boot and never
// written to tests/fixtures/ (the golden JSON is the bridge contract and
// stays byte-identical). Exercises the storefront publication guard
// end-to-end: PDP 404s, listings/sitemap exclude it.
products.push({
	id: 9901,
	name: 'Unreleased Prototype Sofa',
	price: 999,
	oldPrice: null,
	description: '<p>Draft fixture — must never render publicly.</p>',
	category: categories[0] ?? null,
	brand: brands[0] ?? null,
	colors: [],
	width: 200,
	height: 85,
	depth: 95,
	rating: null,
	isBestseller: false,
	isFeatured: false,
	image: null,
	images: [],
	imageId: null,
	imagesIds: [],
	statuses: [],
	promotion: null,
	createdAt: '2026-11-02T00:00:00Z',
	updatedAt: '2026-11-02T00:00:00Z',
	status: 'draft',
});

function envelope(docs, page, limit) {
	const total = docs.length;
	const pages = limit > 0 ? Math.ceil(total / limit) : 1;
	const start = (page - 1) * limit;
	return {
		docs: docs.slice(start, start + limit),
		totalDocs: total,
		limit,
		totalPages: pages,
		page,
		hasNextPage: page < pages,
		hasPrevPage: page > 1,
	};
}

function termMatch(doc, field, values) {
	if (field === 'colors' || field === 'statuses') {
		return doc[field].some((term) => values.includes(term.id));
	}
	const ref = doc[field];
	return ref !== null && values.includes(ref.id);
}

function filterProducts(where, sort) {
	let docs = [...products];
	const clause = where ?? {};
	for (const [field, operators] of Object.entries(clause)) {
		for (const [operator, value] of Object.entries(operators)) {
			if (field === 'id' && operator === 'equals') {
				docs = docs.filter((doc) => doc.id === Number(value));
			}
			if (field === 'name' && operator === 'like') {
				const needle = String(value).toLowerCase();
				docs = docs.filter((doc) => doc.name.toLowerCase().includes(needle));
			}
			const taxMap = {
				category: 'category',
				brand: 'brand',
				colors: 'colors',
				statuses: 'statuses',
			};
			if (taxMap[field] && (operator === 'equals' || operator === 'in')) {
				const values = (Array.isArray(value) ? value : [value]).map(Number);
				docs = docs.filter((doc) => termMatch(doc, taxMap[field], values));
			}
		}
	}
	const direction = typeof sort === 'string' && sort.startsWith('-') ? -1 : 1;
	const field = typeof sort === 'string' ? sort.replace(/^-/, '') : 'date';
	const sorted = [...docs];
	sorted.sort((a, b) => {
		if (field === 'price' || field === 'rating') {
			return direction * (Number(a[field] ?? 0) - Number(b[field] ?? 0));
		}
		if (field === 'name') {
			return direction * a.name.localeCompare(b.name);
		}
		return direction * (a.id - b.id);
	});
	return sorted;
}

function send(res, status, body, headers = {}) {
	res.writeHead(status, { 'content-type': 'application/json', ...headers });
	res.end(JSON.stringify(body));
}

const server = createServer((req, res) => {
	const url = new URL(req.url, 'http://localhost');

	// Media uploads — a real 1x1 PNG so Astro's runtime /_image endpoint has
	// bytes to optimize (Phase 1.7 image-optimization smoke).
	if (url.pathname.startsWith('/wp-content/uploads/')) {
		const PNG = Buffer.from(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
			'base64'
		);
		res.writeHead(200, {
			'content-type': 'image/png',
			'cache-control': 'public, max-age=31536000, immutable',
		});
		res.end(PNG);
		return;
	}

	const prefix = '/wp-json/nvoos-commerce/v1/';
	if (!url.pathname.startsWith(prefix)) {
		return send(res, 404, { code: 'fixture_not_found' });
	}
	const path = url.pathname.slice(prefix.length).replace(/\/+$/, '');
	const segments = path.split('/');

	// Globals.
	if (segments[0] === 'globals') {
		if (segments[1] === 'banner') return send(res, 200, banner);
		if (segments[1] === 'carousel') return send(res, 200, carousel);
		if (segments[1] === 'shop') return send(res, 200, shop);
		if (segments[1] === 'theme') {
			if (req.method === 'GET') return send(res, 200, themePolicy);
			if (req.method === 'PATCH') {
				// Manager seam (105 §1): manage_astro → the manager cookie.
				if (!isManagerRequest(req)) {
					return send(res, 403, { code: 'rest_forbidden', message: 'manage_astro required.' });
				}
				let raw = '';
				req.setEncoding('utf8');
				req.on('data', (chunk) => {
					raw += chunk;
				});
				req.on('end', () => {
					let body = null;
					try {
						body = raw ? JSON.parse(raw) : {};
					} catch {
						return send(res, 400, { code: 'astro_theme_policy_invalid_body', message: 'Body must be JSON.' });
					}
					const result = patchThemePolicy(body);
					if (result.error) {
						return send(res, 400, result.error);
					}
					return send(res, 200, result.doc);
				});
				return;
			}
			return send(res, 405, { code: 'fixture_method_not_allowed' });
		}
		return send(res, 404, { code: 'fixture_not_found' });
	}

	// Promocode validation (idempotent read, case-insensitive).
	if (segments[0] === 'promocodes' && segments[1] === 'validate') {
		const code = (url.searchParams.get('code') ?? '').toLowerCase();
		const match = promocodes.find((item) => item.code.toLowerCase() === code);
		if (!match) return send(res, 404, { code: 'nvoos_promocode_not_found', message: 'Promo code not found.' });
		if (!match.isActive) return send(res, 422, { code: 'nvoos_promocode_inactive', message: 'This promo code is no longer active.' });
		return send(res, 200, match);
	}

	// Auth surface (Q5).
	if (segments[0] === 'auth') {
		if (segments[1] === 'nonce') {
			return send(res, 200, { nonce: 'fixture-nonce' });
		}
		if (segments[1] === 'register' || segments[1] === 'login') {
			// Mirror the bridge: login sets a session cookie.
			res.setHeader('set-cookie', 'nvoos_fixture_session=1; Path=/; HttpOnly');
			return send(res, 200, { id: 1, email: 'shopper@example.com', name: 'Fixture Shopper' });
		}
		if (segments[1] === 'logout') {
			return send(res, 200, { ok: true });
		}
		if (segments[1] === 'me') {
			// Mirror the bridge: /me is cookie-authenticated — no cookie, 401.
			if (!req.headers.cookie) {
				return send(res, 401, { code: 'rest_forbidden', message: 'Not signed in.' });
			}
			// The manager flag cookie unlocks the /manage surfaces the way the
			// real bridge's manage_astro cap does (e2e seam, Proposal 095 4.1).
			const isManager = /(?:^|;\s*)nvoos_fixture_manager=1(?:;|$)/.test(
				String(req.headers.cookie ?? '')
			);
			return send(res, 200, {
				id: 1,
				email: 'shopper@example.com',
				name: 'Fixture Shopper',
				canManage: isManager,
				adminUrl: '',
				capabilities: isManager ? ['read', 'manage_astro'] : ['read'],
				readOnlyCollections: [],
			});
		}
		return send(res, 404, { code: 'fixture_not_found' });
	}

	// Content collections (106 §1.2) — listings + single docs.
	const contentCollection = CONTENT_COLLECTIONS[`${segments[0]}/${segments[1]}`];
	if (contentCollection) {
		if (segments.length >= 3) {
			const id = Number(segments[2]);
			const doc = contentCollection.find((item) => item.id === id);
			if (!doc) return send(res, 404, { code: 'fixture_not_found' });
			return send(res, 200, doc);
		}
		const page = Math.max(1, Number(url.searchParams.get('page')) || 1);
		const limit = Math.min(100, Math.max(1, Number(url.searchParams.get('limit')) || 10));
		const where = {};
		for (const [key, value] of url.searchParams.entries()) {
			const match = /^where\[(.+)\]\[(.+)\]$/.exec(key);
			if (!match) continue;
			const [, field, operator] = match;
			where[field] = where[field] ?? {};
			if (where[field][operator] === undefined) {
				where[field][operator] = value;
			} else if (Array.isArray(where[field][operator])) {
				where[field][operator].push(value);
			} else {
				where[field][operator] = [where[field][operator], value];
			}
		}
		let docs = filterContent(contentCollection, where);
		// Publish-only listings (drafts excluded — the 9902 pin), unless the
		// manager preview passes status=any.
		if (url.searchParams.get('status') !== 'any') {
			docs = docs.filter((doc) => doc.status === undefined || doc.status === 'publish');
		}
		return send(res, 200, envelope(docs, page, limit), {
			'cache-control': 'public, s-maxage=300, stale-while-revalidate=60',
		});
	}

	// Collections.
	const collection = COLLECTIONS[segments[0]];
	if (!collection) {
		return send(res, 404, { code: 'fixture_not_found' });
	}

	if (segments.length >= 2) {
		const id = Number(segments[1]);
		const doc = collection.find((item) => item.id === id);
		if (!doc) return send(res, 404, { code: 'fixture_not_found' });
		// 106 §1.1: variants ride the single-doc route only (listings stay flat).
		if (collection === products && productVariants[id]) {
			return send(res, 200, { ...doc, variants: productVariants[id] });
		}
		return send(res, 200, doc);
	}

	const page = Math.max(1, Number(url.searchParams.get('page')) || 1);
	const limit = Math.min(100, Math.max(1, Number(url.searchParams.get('limit')) || 10));

	// Parse where[field][operator] params.
	const where = {};
	for (const [key, value] of url.searchParams.entries()) {
		const match = /^where\[(.+)\]\[(.+)\]$/.exec(key);
		if (!match) continue;
		const [, field, operator] = match;
		where[field] = where[field] ?? {};
		if (where[field][operator] === undefined) {
			where[field][operator] = value;
		} else if (Array.isArray(where[field][operator])) {
			where[field][operator].push(value);
		} else {
			where[field][operator] = [where[field][operator], value];
		}
	}

	const sort = url.searchParams.get('sort') ?? 'date';
	let docs = segments[0] === 'products' ? filterProducts(where, sort) : collection;

	// Mirror the bridge's listing default: publish-only unless status=any
	// (manager preview). The synthetic draft (Proposal 104 §2.6) exercises
	// the storefront-side publication guard end-to-end.
	if (url.searchParams.get('status') !== 'any') {
		docs = docs.filter((doc) => doc.status === undefined || doc.status === 'publish');
	}

	return send(res, 200, envelope(docs, page, limit), {
		'cache-control': 'public, s-maxage=300, stale-while-revalidate=60',
	});
});

server.listen(PORT, () => {
	console.log(`[fixtures] NV commerce contract fixtures on :${PORT}`);
});
