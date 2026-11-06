#!/usr/bin/env node
// check-generated.mjs — the generated-artifact drift gate (Proposal 126
// §drift). The scaffold bakes nvoos.generated.json (sha256 of every
// generated file) beside the files it writes; this script re-hashes them
// and fails on any drift — a hand-edited generated file or a stale
// scaffold. Chained into `npm run check` so CI gates it on every build.
import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const manifestPath = path.join(root, 'nvoos.generated.json');

const sha256 = (text) => createHash('sha256').update(text).digest('hex');

const manifestRaw = await readFile(manifestPath, 'utf8').catch(() => null);
if (!manifestRaw) {
	console.error('nvoos.generated.json is missing — re-scaffold the site (the manifest ships with the generated files).');
	process.exit(1);
}
const manifest = JSON.parse(manifestRaw);
const failures = [];
for (const [file, expected] of Object.entries(manifest.files ?? {})) {
	const actual = await readFile(path.join(root, file), 'utf8').catch(() => null);
	if (!actual) {
		failures.push(`${file}: missing`);
	} else if (sha256(actual) !== expected) {
		failures.push(`${file}: drifted (hand-edited or stale) — re-scaffold to regenerate`);
	}
}
if (failures.length > 0) {
	console.error(`Generated artifacts drifted:\n  ${failures.join('\n  ')}`);
	process.exit(1);
}
console.log('Generated artifacts match the scaffold manifest.');
