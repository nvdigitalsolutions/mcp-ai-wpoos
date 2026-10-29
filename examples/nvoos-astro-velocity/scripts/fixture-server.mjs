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
			return send(res, 200, { id: 1, email: 'shopper@example.com', name: 'Fixture Shopper' });
		}
		if (segments[1] === 'logout') {
			return send(res, 200, { ok: true });
		}
		if (segments[1] === 'me') {
			return send(res, 200, { id: 1, email: 'shopper@example.com', name: 'Fixture Shopper' });
		}
		return send(res, 404, { code: 'fixture_not_found' });
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
	const docs = segments[0] === 'products' ? filterProducts(where, sort) : collection;

	return send(res, 200, envelope(docs, page, limit), {
		'cache-control': 'public, s-maxage=300, stale-while-revalidate=60',
	});
});

server.listen(PORT, () => {
	console.log(`[fixtures] NV commerce contract fixtures on :${PORT}`);
});
