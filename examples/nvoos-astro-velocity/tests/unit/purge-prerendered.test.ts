// ---------------------------------------------------------------------------
// purge-prerendered.test.ts — the stale-prerender boot guard.
//
// This app is fully SSR: a correct build emits ZERO `.html` under
// dist/client. Velocity deploys incrementally and never deletes files that
// left the repo, so an orphaned prerendered page from an older build keeps
// shadowing the SSR route (the nine-day-stale PDP of 2026-11-09).
//
// The guard must remove HTML at any depth and leave everything else alone.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { mkdirSync, mkdtempSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { purgePrerenderedHtml } from '../../scripts/lib/purge-prerendered.mjs';

function buildTree(): string {
	const root = mkdtempSync(join(tmpdir(), 'purge-prerendered-'));
	mkdirSync(join(root, '_astro'), { recursive: true });
	mkdirSync(join(root, 'products', '1', 'aurora'), { recursive: true });
	writeFileSync(join(root, '_astro', 'app.js'), 'console.log(1)');
	writeFileSync(join(root, 'favicon.svg'), '<svg/>');
	writeFileSync(join(root, 'products', '1', 'aurora', 'index.html'), '<html>stale</html>');
	writeFileSync(join(root, 'orphan.html'), '<html>stale</html>');
	return root;
}

describe('purgePrerenderedHtml', () => {
	it('removes html at every depth and keeps other assets', () => {
		const root = buildTree();
		try {
			const removed = purgePrerenderedHtml(root);

			expect(removed).toHaveLength(2);
			expect(removed.some((p) => p.endsWith(join('products', '1', 'aurora', 'index.html')))).toBe(true);
			expect(removed.some((p) => p.endsWith('orphan.html'))).toBe(true);

			// Non-HTML survives.
			expect(readdirSync(join(root, '_astro'))).toContain('app.js');
			expect(readdirSync(root)).toContain('favicon.svg');
			// The HTML is gone.
			expect(readdirSync(join(root, 'products', '1', 'aurora'))).toEqual([]);
			expect(readdirSync(root)).not.toContain('orphan.html');
		} finally {
			rmSync(root, { recursive: true, force: true });
		}
	});

	it('is a no-op on a clean tree and on a missing directory', () => {
		const root = mkdtempSync(join(tmpdir(), 'purge-clean-'));
		try {
			writeFileSync(join(root, 'app.js'), 'x');
			expect(purgePrerenderedHtml(root)).toEqual([]);
		} finally {
			rmSync(root, { recursive: true, force: true });
		}

		expect(purgePrerenderedHtml(join(tmpdir(), 'does-not-exist-purge-xyz'))).toEqual([]);
	});
});
