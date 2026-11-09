// ---------------------------------------------------------------------------
// section-kinds.test.ts — the `data-section` drift gate.
//
// Two invariants, both cheap source greps:
//   1. every content block in src/components/blocks/ declares exactly ONE
//      `data-section` on its root, and that value is in SECTION_KINDS;
//   2. every kind in SECTION_KINDS is used by at least one block (no dead
//      vocabulary).
//
// These catch the two real failure modes: a new block type that forgets the
// attribute (invisible to every runtime test — the band still renders), and a
// kind that outlives its block (the vocabulary silently lies to agents).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
	BLOCK_TYPE_TO_KIND,
	SECTION_KINDS,
	isSectionKind,
} from '../../src/lib/content/section-kinds';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const BLOCKS_DIR = join(ROOT, 'src', 'components', 'blocks');

/** Non-block files that live in the blocks folder.
 *  - `Blocks.astro` is the Portable Text dispatcher (the component map), not a
 *    block — it renders other blocks and owns no band of its own.
 *  - `LinkMark` is an inline mark renderer, not a band.
 */
const NON_BLOCK_FILES = new Set(['Blocks.astro', 'LinkMark.astro']);

/**
 * `data-section="…"` only. `data-section-id="…"` does NOT match: the quote
 * after the attribute name anchors it (identity must never be counted as a
 * kind).
 */
const SECTION_ATTR = /data-section="([^"]+)"/g;

const blockFiles = readdirSync(BLOCKS_DIR)
	.filter((entry) => entry.endsWith('.astro') && !NON_BLOCK_FILES.has(entry))
	.sort();

function kindsIn(file: string): string[] {
	return [...readFileSync(join(BLOCKS_DIR, file), 'utf8').matchAll(SECTION_ATTR)].map(
		(match) => match[1]
	);
}

describe('data-section vocabulary', () => {
	it('finds the block components', () => {
		expect(blockFiles.length).toBeGreaterThanOrEqual(10);
	});

	for (const file of blockFiles) {
		it(`${file} declares exactly one in-vocabulary data-section`, () => {
			const kinds = kindsIn(file);
			expect(kinds, `${file} must declare exactly one data-section`).toHaveLength(1);
			expect(
				isSectionKind(kinds[0]),
				`${file} declares data-section="${kinds[0]}", which is not in SECTION_KINDS`
			).toBe(true);
		});
	}

	it('uses every kind at least once (no dead vocabulary)', () => {
		const used = new Set(blockFiles.flatMap(kindsIn));
		const unused = SECTION_KINDS.filter((kind) => !used.has(kind));
		expect(unused, `kinds with no block: ${unused.join(', ')}`).toEqual([]);
	});

	it('BLOCK_TYPE_TO_KIND covers every kind and only maps to real kinds', () => {
		const mapped = Object.values(BLOCK_TYPE_TO_KIND);
		for (const kind of mapped) {
			expect(isSectionKind(kind), `${kind} is mapped but not in SECTION_KINDS`).toBe(true);
		}
		const uncovered = SECTION_KINDS.filter((kind) => !mapped.includes(kind));
		expect(uncovered, `kinds with no Portable Text type: ${uncovered.join(', ')}`).toEqual([]);
	});

	it('rejects unknown values and non-strings', () => {
		expect(isSectionKind('faq')).toBe(true);
		expect(isSectionKind('stats')).toBe(true);
		expect(isSectionKind('pillar')).toBe(false);
		expect(isSectionKind('')).toBe(false);
		expect(isSectionKind(null)).toBe(false);
		expect(isSectionKind(42)).toBe(false);
	});
});
