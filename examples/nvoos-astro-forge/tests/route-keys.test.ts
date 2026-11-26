// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// route-keys.test.ts — the duplicate-URL gate behind `verify`.
//
// Astro's default conflict behaviour is `warn` + first-path-wins, so the only
// place a duplicate is still visible after a build is the content store.
// ---------------------------------------------------------------------------

import { mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import { findRouteKeyConflicts, readContentStore, routeKeyFor } from '../lib/route-keys.ts';
import { rmTemp } from './helpers/tmp.ts';

const roots: string[] = [];

async function tempDir(): Promise<string> {
	const dir = await mkdtemp(join(tmpdir(), 'forge-route-keys-'));
	roots.push(dir);
	return dir;
}

afterEach(async () => {
	await Promise.all(roots.splice(0).map((dir) => rmTemp(dir)));
});

const POSTS = { slug: 'posts', kind: 'post' } as const;
const PAGES = { slug: 'pages', kind: 'page' } as const;

/** One decoded-store entry (`collection → id → entry`). */
function entry(id: string | number, slug: string, extra: Record<string, unknown> = {}) {
	return { id, data: { id, slug, ...extra } };
}

describe('routeKeyFor', () => {
	it('routes a page on its own slug and everything else on id + slug', () => {
		expect(routeKeyFor(PAGES, '5609', 'about')).toBe('/about/');
		expect(routeKeyFor(POSTS, '2228', 'blog-details')).toBe('/posts/2228/blog-details/');
		expect(routeKeyFor({ slug: 'case-studies', basePath: '/work' }, '7', 'Acme')).toBe(
			'/work/7/acme/'
		);
	});

	it('falls back to the id when an entry carries no slug', () => {
		expect(routeKeyFor(PAGES, '5609', undefined)).toBe('/5609/');
	});
});

describe('findRouteKeyConflicts', () => {
	it('passes a store where every URL is claimed once', () => {
		const report = findRouteKeyConflicts(
			{
				posts: { '2228': entry('2228', 'blog-details'), '897': entry('897', 'gallery') },
				pages: { '5609': entry('5609', 'about'), '9': entry('9', 'application') },
			},
			[POSTS, PAGES]
		);

		expect(report.conflicts).toEqual([]);
		expect(report.entries).toBe(4);
		expect(report.collections).toEqual(['pages', 'posts']);
	});

	it('passes a de-duplicated snapshot (the writer publishes unique slugs)', () => {
		// `lib/materialize.ts` renames the loser instead of leaving the collision
		// for this gate — the gate is the SECOND guard, not the only one.
		const report = findRouteKeyConflicts(
			{
				pages: {
					'101': entry('101', 'team'),
					'102': entry('102', 'team-3'),
					'103': entry('103', 'team-2'),
				},
			},
			[PAGES]
		);

		expect(report.conflicts).toEqual([]);
		expect(report.entries).toBe(3);
	});

	it('fails two pages that publish one slug (the arbitrary-winner case)', () => {
		const report = findRouteKeyConflicts(
			{ pages: { '5609': entry('5609', 'about'), '101': entry('101', 'About') } },
			[PAGES]
		);

		expect(report.conflicts).toHaveLength(1);
		expect(report.conflicts[0]).toMatchObject({
			collection: 'pages',
			kind: 'duplicate-route',
			slug: 'about',
			route: '/about/',
		});
		// Integer-like object keys enumerate ascending, so the id ORDER is not
		// insertion order — only the set is meaningful.
		expect([...report.conflicts[0].ids].sort()).toEqual(['101', '5609']);
	});

	it('fails two posts sharing a slug under different ids (duplicate content)', () => {
		const report = findRouteKeyConflicts(
			{ posts: { '2228': entry('2228', 'blog-details'), '2229': entry('2229', 'blog-details') } },
			[POSTS]
		);

		expect(report.conflicts).toHaveLength(1);
		expect(report.conflicts[0].kind).toBe('duplicate-slug');
		expect(report.conflicts[0].ids).toEqual(['2228', '2229']);
	});

	it('never ships a draft, so a draft collision is not a conflict', () => {
		const report = findRouteKeyConflicts(
			{
				pages: {
					'5609': entry('5609', 'about'),
					'101': entry('101', 'about', { draft: true }),
				},
			},
			[PAGES]
		);

		expect(report.conflicts).toEqual([]);
		expect(report.entries).toBe(1);
	});

	it('treats a collection the profile does not declare as id-routed', () => {
		const report = findRouteKeyConflicts(
			{ portfolio: { '1': entry('1', 'acme'), '2': entry('2', 'acme') } },
			[]
		);

		// Different pathnames (the id differs), but the same document twice.
		expect(report.conflicts.map((conflict) => conflict.kind)).toEqual(['duplicate-slug']);
	});
});

describe('readContentStore', () => {
	it('reports a missing store instead of pretending it passed', () => {
		const result = readContentStore(join(tmpdir(), 'forge-no-such-project'));
		expect('error' in result && result.error).toMatch(/npm run build/);
	});

	it('decodes the flat serialization Astro writes', async () => {
		const project = await tempDir();
		await mkdir(join(project, 'node_modules', '.astro'), { recursive: true });
		// The store is a flat array: objects hold REFERENCES into it (out-of-range
		// numbers stay primitives, which is how ids/dates survive).
		const flat = [
			{ posts: 1, pages: 4 },
			{ '2228': 2 },
			{ id: 2228, data: 3 },
			{ id: 2228, slug: 'blog-details' },
			{ '5609': 5, '5609b': 7 },
			{ id: 5609, data: 6 },
			{ id: 5609, slug: 'about' },
			{ id: 5609, data: 8 },
			{ id: 5609, slug: 'about' },
		];
		await writeFile(
			join(project, 'node_modules', '.astro', 'data-store.json'),
			JSON.stringify(Object.fromEntries(flat.map((node, index) => [String(index), node]))),
			'utf8'
		);

		const store = readContentStore(project);
		expect('error' in store).toBe(false);
		if ('error' in store) {
			return;
		}

		expect(store.path).toContain('data-store.json');
		const report = findRouteKeyConflicts(store.root, [POSTS, PAGES]);
		expect(report.entries).toBe(3);
		// Two page entries publish `/about/` — the gate's reason to exist.
		expect(report.conflicts.map((conflict) => conflict.route)).toContain('/about/');
	});
});
