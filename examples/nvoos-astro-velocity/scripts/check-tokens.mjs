#!/usr/bin/env node
// ---------------------------------------------------------------------------
// check-tokens.mjs — CI drift gate (Proposal 095): rebuilds in memory and
// compares against the committed generated artifacts. Non-zero exit = stale
// output (run `npm run build:tokens` and commit the result).
// ---------------------------------------------------------------------------

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildTokens, readTokenSources } from './lib/tokens-build.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..');

const { css, ts, bundle, report } = buildTokens(readTokenSources());
if (report.errors.length > 0) {
	console.error('[check-tokens] validation errors:');
	for (const error of report.errors) console.error(`  - ${error}`);
	process.exit(1);
}

const committed = {
	css: readFileSync(join(ROOT, 'src', 'styles', 'generated', 'tokens.css'), 'utf8'),
	ts: readFileSync(join(ROOT, 'src', 'lib', 'tokens.generated.ts'), 'utf8'),
	bundle: readFileSync(join(ROOT, 'src', 'tokens', 'bundle.generated.json'), 'utf8'),
};

const drift = [];
if (committed.css !== css) drift.push('src/styles/generated/tokens.css');
if (committed.ts !== ts) drift.push('src/lib/tokens.generated.ts');
if (committed.bundle !== bundle) drift.push('src/tokens/bundle.generated.json');

if (drift.length > 0) {
	console.error(`[check-tokens] drift in generated artifacts: ${drift.join(', ')}`);
	console.error('[check-tokens] run `npm run build:tokens` and commit the output.');
	process.exit(1);
}
console.log('[check-tokens] generated artifacts are current');
