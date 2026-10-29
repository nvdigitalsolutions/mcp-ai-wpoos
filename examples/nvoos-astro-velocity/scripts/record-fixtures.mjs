#!/usr/bin/env node
// ---------------------------------------------------------------------------
// record-fixtures.mjs — capture golden contract fixtures from a live origin.
//
//   NVOOS_API_BASE=https://site/wp-json/nvoos-commerce/v1 node scripts/record-fixtures.mjs
//
// Writes tests/fixtures/*.json for every collection + globals. Used when the
// contract suite should track a real seeded environment (Phase 2.7 drift
// guard: diff recorded fixtures against the bridge's own test fixtures).
// ---------------------------------------------------------------------------

import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const base = (process.env.NVOOS_API_BASE || '').replace(/\/+$/, '');
if (!base) {
	console.error('Set NVOOS_API_BASE first.');
	process.exit(1);
}

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = join(HERE, '..', 'tests', 'fixtures');

async function fetchAll(collection) {
	const docs = [];
	let page = 1;
	for (;;) {
		const res = await fetch(`${base}/${collection}?page=${page}&limit=100`);
		if (!res.ok) {
			throw new Error(`${collection}: HTTP ${res.status}`);
		}
		const data = await res.json();
		docs.push(...data.docs);
		if (!data.hasNextPage) {
			break;
		}
		page += 1;
	}
	return docs;
}

const collections = ['products', 'categories', 'brands', 'colors', 'statuses', 'promotions', 'promocodes'];

for (const collection of collections) {
	const docs = await fetchAll(collection);
	writeFileSync(join(OUT, `${collection}.json`), `${JSON.stringify(docs, null, '\t')}\n`);
	console.log(`[fixtures] ${collection}: ${docs.length} docs`);
}

for (const global of ['banner', 'carousel']) {
	const res = await fetch(`${base}/globals/${global}`);
	if (res.ok) {
		const data = await res.json();
		writeFileSync(join(OUT, `${global}.json`), `${JSON.stringify(data, null, '\t')}\n`);
		console.log(`[fixtures] globals/${global} recorded`);
	}
}
