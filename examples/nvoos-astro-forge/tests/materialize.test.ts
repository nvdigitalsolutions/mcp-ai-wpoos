// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// materialize.test.ts — recover a built artifact's baked content (2026-11-15).
//
// The store is Astro's flat serialization: primitives inline, tagged arrays
// (`['Map', …]`) and plain objects whose properties are INDEXES back into the
// array, with -1 for "absent". It must be revived, never JSON.parsed (the
// literal is JS, so it can carry escapes JSON rejects).
// ---------------------------------------------------------------------------

import { mkdtemp, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { decodeContentStore, materializeContent } from '../lib/materialize.ts';

const BODY = '<section id="home"><p>Hi</p></section>';

/** A store shaped exactly like the one Astro bakes into an artifact. */
const STORE: unknown[] = [
	['Map', 1, 2], // 0: root  { pages → 2 }
	'pages', // 1
	['Map', 3, 4], // 2: pages { application → 4 }
	'application', // 3
	{ id: 5, data: 6, rendered: 7 }, // 4: entry
	'9', // 5: id
	{ title: 8, slug: 9, date: -1, link: 10 }, // 6: frontmatter (-1 = absent)
	{ html: 11 }, // 7: rendered
	'Application', // 8
	'application', // 9
	'https://example.test/', // 10
	BODY, // 11
];

/** Two pages share `team`; a third really IS `team-2` (the suffix must skip it). */
const DUP_STORE: unknown[] = [
	['Map', 1, 2], // 0: root { pages → 2 }
	'pages', // 1
	['Map', 3, 4, 5, 6, 7, 8], // 2: pages { 101 → 4, 102 → 6, 103 → 8 }
	'101', // 3
	{ id: 9, data: 10, rendered: 11 }, // 4
	'102', // 5
	{ id: 12, data: 13, rendered: 14 }, // 6
	'103', // 7
	{ id: 15, data: 16, rendered: 17 }, // 8
	'101', // 9
	{ title: 18, slug: 19 }, // 10: About team
	{ html: 20 }, // 11
	'102', // 12
	{ title: 21, slug: 19 }, // 13: Services team (same slug)
	{ html: 22 }, // 14
	'103', // 15
	{ title: 23, slug: 24 }, // 16: Real team two
	{ html: 25 }, // 17
	'About team', // 18
	'team', // 19
	'<p>about</p>', // 20
	'Services team', // 21
	'<p>services</p>', // 22
	'Real team two', // 23
	'team-2', // 24
	'<p>real</p>', // 25
];

describe('decodeContentStore', () => {
	it('revives Map nodes, index references and -1 as absent', () => {
		const root = decodeContentStore(STORE) as {
			pages: Record<string, { id: string; data: Record<string, unknown>; rendered: { html: string } }>;
		};

		expect(root.pages.application.id).toBe('9');
		expect(root.pages.application.data.title).toBe('Application');
		expect(root.pages.application.data.date).toBeUndefined();
		expect(root.pages.application.rendered.html).toBe(BODY);
	});
});

/** Two pages share `team`; the one whose OWN link is /team/ must keep it. */
const LINKED_DUP_STORE: unknown[] = [
	['Map', 1, 2], // 0: root { pages → 2 }
	'pages', // 1
	['Map', 3, 4, 5, 6, 7, 8], // 2: pages { 201 → 4, 202 → 6, 203 → 8 }
	'201', // 3
	{ id: 9, data: 10, rendered: 11 }, // 4
	'202', // 5
	{ id: 12, data: 13, rendered: 14 }, // 6
	'203', // 7
	{ id: 15, data: 16, rendered: 17 }, // 8
	'201', // 9
	{ title: 18, slug: 19, link: 20 }, // 10: About team, /about/team/
	{ html: 21 }, // 11
	'202', // 12
	{ title: 22, slug: 19, link: 23 }, // 13: Top-level team, /team/
	{ html: 24 }, // 14
	'203', // 15
	{ title: 25, slug: 26, link: 27 }, // 16: Real team two
	{ html: 28 }, // 17
	'About team', // 18
	'team', // 19
	'https://example.test/about/team/', // 20
	'<p>about</p>', // 21
	'Top-level team', // 22
	'https://example.test/team/', // 23
	'<p>top</p>', // 24
	'Real team two', // 25
	'team-2', // 26
	'https://example.test/team-2/', // 27
	'<p>real</p>', // 28
];

describe('materializeContent', () => {
	it('writes frontmatter MDX beside the HTML body', async () => {
		const dir = await mkdtemp(join(tmpdir(), 'forge-materialize-'));
		const storeFile = join(dir, 'store.mjs');
		await writeFile(storeFile, `export default ${JSON.stringify(STORE)};\n`, 'utf8');

		const outDir = join(dir, 'content');
		const documents = await materializeContent({ storeFile, outDir });

		expect(documents).toEqual([
			{
				collection: 'pages',
				slug: 'application',
				file: 'application',
				mdxBytes: expect.any(Number),
				htmlBytes: BODY.length,
			},
		]);

		const mdx = await readFile(join(outDir, 'pages', 'application.mdx'), 'utf8');
		expect(mdx.startsWith('---\n')).toBe(true);
		expect(mdx).toContain('title: "Application"');
		expect(mdx).toContain('slug: "application"');
		// -1 never becomes a frontmatter field.
		expect(mdx).not.toContain('date:');

		// The body must NOT live in the MDX: MDX parses it as JSX and rejects
		// arbitrary WordPress HTML.
		expect(mdx).not.toContain('<section');
		expect(await readFile(join(outDir, 'pages', 'application.html'), 'utf8')).toBe(BODY);
	});

	it('keeps two documents that share a slug instead of overwriting one', async () => {
		// WordPress page slugs are unique among SIBLINGS only, so a snapshot can
		// legitimately hold `/about/team/` and `/services/team/`. Both the FILENAME
		// and the frontmatter `slug` are de-duplicated (the flat `/[slug]/` route
		// reads the latter), the suffix never takes a document's own real slug
		// (`team-2`), and the source slug is kept as `slugRenamedFrom`.
		const dir = await mkdtemp(join(tmpdir(), 'forge-materialize-'));
		const storeFile = join(dir, 'store.mjs');
		await writeFile(storeFile, `export default ${JSON.stringify(DUP_STORE)};\n`, 'utf8');

		const outDir = join(dir, 'content');
		const documents = await materializeContent({ storeFile, outDir });

		expect(documents.map((doc) => `${doc.collection}/${doc.file}`)).toEqual([
			'pages/team',
			'pages/team-3',
			'pages/team-2',
		]);
		expect(documents.map((doc) => doc.slug)).toEqual(['team', 'team-3', 'team-2']);
		expect(documents.map((doc) => doc.slugRenamedFrom)).toEqual([undefined, 'team', undefined]);

		const about = await readFile(join(outDir, 'pages', 'team.mdx'), 'utf8');
		const services = await readFile(join(outDir, 'pages', 'team-3.mdx'), 'utf8');
		const real = await readFile(join(outDir, 'pages', 'team-2.mdx'), 'utf8');
		expect(about).toContain('title: "About team"');
		expect(about).toContain('slug: "team"');
		expect(about).not.toContain('slugRenamedFrom');
		expect(services).toContain('title: "Services team"');
		expect(services).toContain('slug: "team-3"');
		expect(services).toContain('slugRenamedFrom: "team"');
		expect(real).toContain('slug: "team-2"');
		expect(real).not.toContain('slugRenamedFrom');

		// Each body travels with its own frontmatter file.
		expect(await readFile(join(outDir, 'pages', 'team.html'), 'utf8')).toBe('<p>about</p>');
		expect(await readFile(join(outDir, 'pages', 'team-3.html'), 'utf8')).toBe('<p>services</p>');
		expect(await readFile(join(outDir, 'pages', 'team-2.html'), 'utf8')).toBe('<p>real</p>');
	});

	it('keeps the bare slug for the document whose source link is /<slug>/', async () => {
		// A deterministic keeper matters for redirects: the document whose OWN
		// path is `/<slug>/` must keep it, otherwise its redirect (source ≠ target)
		// would shadow the page now served there. Link wins over id here (202 > 201).
		const dir = await mkdtemp(join(tmpdir(), 'forge-materialize-'));
		const storeFile = join(dir, 'store.mjs');
		await writeFile(storeFile, `export default ${JSON.stringify(LINKED_DUP_STORE)};\n`, 'utf8');

		const outDir = join(dir, 'content');
		const documents = await materializeContent({ storeFile, outDir });

		expect(documents.map((doc) => doc.slug)).toEqual(['team-3', 'team', 'team-2']);
		expect(documents.map((doc) => doc.slugRenamedFrom)).toEqual(['team', undefined, undefined]);

		const top = await readFile(join(outDir, 'pages', 'team.mdx'), 'utf8');
		const about = await readFile(join(outDir, 'pages', 'team-3.mdx'), 'utf8');
		expect(top).toContain('title: "Top-level team"');
		expect(top).toContain('slug: "team"');
		expect(about).toContain('title: "About team"');
		expect(about).toContain('slug: "team-3"');
		expect(about).toContain('slugRenamedFrom: "team"');
	});
});
