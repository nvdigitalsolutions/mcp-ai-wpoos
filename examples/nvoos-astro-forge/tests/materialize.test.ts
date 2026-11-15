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

describe('materializeContent', () => {
	it('writes frontmatter MDX beside the HTML body', async () => {
		const dir = await mkdtemp(join(tmpdir(), 'forge-materialize-'));
		const storeFile = join(dir, 'store.mjs');
		await writeFile(storeFile, `export default ${JSON.stringify(STORE)};\n`, 'utf8');

		const outDir = join(dir, 'content');
		const documents = await materializeContent({ storeFile, outDir });

		expect(documents).toEqual([
			{ collection: 'pages', slug: 'application', mdxBytes: expect.any(Number), htmlBytes: BODY.length },
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
});
