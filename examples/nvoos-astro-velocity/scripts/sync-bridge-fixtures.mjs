#!/usr/bin/env node
// ---------------------------------------------------------------------------
// sync-bridge-fixtures.mjs — the storefront side of the Phase 2.7 contract
// drift guard.
//
// Reads the bridge's golden snapshots (addons/funiq-bridge/tests/golden/)
// and upserts the matching docs into the app's committed fixtures. CI runs
// this script and diffs the result: any drift between the bridge contract
// (goldens) and the storefront fixtures fails the build.
// ---------------------------------------------------------------------------

import { readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const HERE = dirname(fileURLToPath(import.meta.url));
const APP_FIXTURES = resolve(HERE, '..', 'tests', 'fixtures');
const BRIDGE_GOLDEN = resolve(
	HERE,
	'..',
	'..',
	'..',
	'addons',
	'funiq-bridge',
	'tests',
	'golden'
);

function loadGoldens() {
	const files = readdirSync(BRIDGE_GOLDEN).filter((file) => /^product-.*\.json$/.test(file));
	return files.map((file) => JSON.parse(readFileSync(join(BRIDGE_GOLDEN, file), 'utf8')));
}

const goldens = loadGoldens();
if (goldens.length === 0) {
	console.log('[sync-bridge-fixtures] no bridge goldens found — nothing to sync.');
	process.exit(0);
}

const productsPath = join(APP_FIXTURES, 'products.json');
const products = JSON.parse(readFileSync(productsPath, 'utf8'));
const byId = new Map(products.map((doc) => [doc.id, doc]));

for (const golden of goldens) {
	const refs = golden.refs ?? {};
	const fields = golden.fields;
	// Compose in the contract's canonical key order so regenerated fixtures
	// diff cleanly against the committed file.
	const composed = {
		id: golden.fixtureId,
		name: fields.name,
		price: fields.price,
		oldPrice: fields.oldPrice ?? null,
		description: fields.description,
		category: fields.category
			? { id: refs.category, ...fields.category }
			: null,
		brand: fields.brand
			? { id: refs.brand, ...fields.brand }
			: null,
		colors: (fields.colors ?? []).map((color, index) => ({
			id: refs.colors?.[index],
			...color,
		})),
		width: fields.width,
		height: fields.height,
		depth: fields.depth,
		rating: fields.rating ?? null,
		isBestseller: fields.isBestseller,
		isFeatured: fields.isFeatured,
		image: golden.media.image,
		images: golden.media.images,
		imageId: golden.media.imageId ?? null,
		imagesIds: golden.media.imagesIds ?? [],
		statuses: (fields.statuses ?? []).map((status, index) => ({
			id: refs.statuses?.[index],
			...status,
		})),
		promotion: fields.promotion
			? { id: refs.promotion, ...fields.promotion }
			: null,
		createdAt: golden.createdAt,
		updatedAt: golden.updatedAt,
	};
	byId.set(golden.fixtureId, composed);
}

const merged = [...byId.values()].sort((a, b) => a.id - b.id);
writeFileSync(productsPath, `${JSON.stringify(merged, null, 2)}
`);

console.log(
	`[sync-bridge-fixtures] synced ${goldens.length} golden product(s) into ${products.length}-doc fixture set.`
);
