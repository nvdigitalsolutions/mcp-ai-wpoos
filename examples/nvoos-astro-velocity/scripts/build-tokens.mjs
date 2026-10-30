#!/usr/bin/env node
// ---------------------------------------------------------------------------
// build-tokens.mjs — ADS generator CLI (Proposal 095). Writes the three
// generated artifacts; refuses to write anything when validation fails.
// ---------------------------------------------------------------------------

import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildTokens, readTokenSources } from './lib/tokens-build.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = join(HERE, '..');

const sources = readTokenSources();
const { css, ts, bundle, report } = buildTokens(sources);

if (report.warnings.length > 0) {
	console.warn('[build-tokens] warnings:');
	for (const warning of report.warnings) console.warn(`  - ${warning}`);
}

if (report.errors.length > 0) {
	console.error('[build-tokens] validation failed — no files written:');
	for (const error of report.errors) console.error(`  - ${error}`);
	process.exit(1);
}

mkdirSync(join(ROOT, 'src', 'styles', 'generated'), { recursive: true });
writeFileSync(join(ROOT, 'src', 'styles', 'generated', 'tokens.css'), css);
writeFileSync(join(ROOT, 'src', 'lib', 'tokens.generated.ts'), ts);
writeFileSync(join(ROOT, 'src', 'tokens', 'bundle.generated.json'), bundle);
console.log('[build-tokens] wrote tokens.css, tokens.generated.ts, bundle.generated.json');
