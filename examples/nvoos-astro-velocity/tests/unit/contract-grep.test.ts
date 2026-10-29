// ---------------------------------------------------------------------------
// Contract gate (D3 + naming policy) — the CI-enforced invariant that the
// storefront binds ONLY to the NV commerce contract.
//
// src/ may never contain:
//   - backend-specific URL strings (the BFF in scripts/serve.mjs owns those)
//   - backend nonce/admin semantics
//   - the retired licensed brand name
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const SRC = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'src');

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

const files = walk(SRC);

describe('contract gate (D3)', () => {
	it('scans every src file', () => {
		expect(files.length).toBeGreaterThan(10);
	});

	for (const { pattern, reason } of FORBIDDEN) {
		it(`src/ never contains ${pattern} (${reason})`, () => {
			const offenders: string[] = [];
			for (const file of files) {
				const content = readFileSync(file, 'utf8');
				if (pattern.test(content)) {
					offenders.push(file.replace(SRC, 'src'));
				}
			}
			expect(offenders).toEqual([]);
		});
	}
});
