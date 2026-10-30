// ---------------------------------------------------------------------------
// Contract gate (D3 + naming policy) — the CI-enforced invariant that the
// storefront binds ONLY to the NV commerce contract. The gate covers BOTH
// app trees: src/ and the nvoos-astro-mcp mount in mcp/.
//
// src/ and mcp/ may never contain:
//   - backend-specific URL strings (the BFF in scripts/serve.mjs owns those)
//   - backend nonce/admin semantics
//   - the retired licensed brand name
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const SCOPES = [
	{ dir: join(ROOT, 'src'), label: 'src' },
	{ dir: join(ROOT, 'mcp'), label: 'mcp' },
];

const FORBIDDEN = [
	{ pattern: /wp-json/i, reason: 'backend REST URL — proxy config belongs in scripts/serve.mjs' },
	{ pattern: /wp-admin/i, reason: 'backend admin URL' },
	{ pattern: /wp-login/i, reason: 'backend login URL' },
	{ pattern: /wp_rest/i, reason: 'backend nonce semantics' },
	{ pattern: /wp-includes/i, reason: 'backend asset URL' },
	{ pattern: /funiq/i, reason: 'retired licensed brand — the contract is nvoos-commerce/v1' },
];

function walk(dir: string): string[] {
	const files: string[] = [];
	for (const entry of readdirSync(dir)) {
		const full = join(dir, entry);
		if (statSync(full).isDirectory()) {
			files.push(...walk(full));
		} else if (/\.(astro|ts|tsx)$/.test(entry)) {
			files.push(full);
		}
	}
	return files;
}

const files = SCOPES.flatMap(({ dir, label }) =>
	walk(dir).map((file) => ({ label: join(label, relative(dir, file)), file }))
);

describe('contract gate (D3) — covers src/ and mcp/', () => {
	it('scans every src and mcp file', () => {
		expect(files.length).toBeGreaterThan(10);
	});

	for (const { pattern, reason } of FORBIDDEN) {
		it(`src/ and mcp/ never contain ${pattern} (${reason})`, () => {
			const offenders: string[] = [];
			for (const { label, file } of files) {
				const content = readFileSync(file, 'utf8');
				if (pattern.test(content)) {
					offenders.push(label);
				}
			}
			expect(offenders).toEqual([]);
		});
	}
});
