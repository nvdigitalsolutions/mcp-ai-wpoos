// ---------------------------------------------------------------------------
// csp-inline-invariance.test.ts — Proposal 104 D12 guard.
//
// The CSP `script-src` hash list is computed per RESPONSE, but it belongs to
// the DOCUMENT: ClientRouter keeps it across an in-app navigation, so the
// target page's own CSP is ignored. Any inline script that exists on some
// pages but not others is therefore blocked on navigation to those pages —
// the tabs outage of 2026-11-09.
//
// Two source-level rules keep the first-party inline set page-invariant:
//   1. bundled/hoisted scripts stay external  → vite.build.assetsInlineLimit: 0
//   2. no island uses client:visible / client:idle — each emits a per-page
//      inline bootstrap (IntersectionObserver / requestIdleCallback).
//      client:load emits none.
//
// Verified end-to-end by the `tabs work after in-app navigation` e2e and by
// comparing `script-src` hashes across routes.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');

function walk(dir: string): string[] {
	const files: string[] = [];
	for (const entry of readdirSync(dir)) {
		const full = join(dir, entry);
		if (statSync(full).isDirectory()) {
			files.push(...walk(full));
		} else if (/\.(astro|tsx|ts)$/.test(entry)) {
			files.push(full);
		}
	}
	return files;
}

/**
 * Strip comments before matching. Prose legitimately NAMES the directives
 * (including this file's own explanation), so a raw grep would flag
 * documentation rather than usage. Line comments are only stripped when they
 * START a line, so `https://` inside a string survives.
 */
function stripComments(source: string): string {
	return source
		.replace(/\{\/\*[\s\S]*?\*\/\}/g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/^[ \t]*\/\/.*$/gm, '');
}

describe('CSP inline invariance (Proposal 104 D12)', () => {
	it('astro.config.mjs keeps bundled scripts external', () => {
		const config = readFileSync(join(ROOT, 'astro.config.mjs'), 'utf8');
		expect(config).toMatch(/assetsInlineLimit:\s*0/);
	});

	it('no island uses a directive that emits a per-page inline bootstrap', () => {
		// `client:visible` and `client:idle` both ship an inline loader; only
		// `client:load` (and `client:only`) leave the inline set untouched.
		const offenders: string[] = [];
		for (const file of walk(join(ROOT, 'src'))) {
			const content = stripComments(readFileSync(file, 'utf8'));
			const matches = content.match(/client:(visible|idle)\b/g);
			if (matches) {
				offenders.push(`${file.replace(ROOT, '')}: ${[...new Set(matches)].join(', ')}`);
			}
		}
		expect(
			offenders,
			`client:visible/client:idle emit per-page inline bootstraps and break hash-CSP navigation:\n${offenders.join('\n')}`
		).toEqual([]);
	});
});
