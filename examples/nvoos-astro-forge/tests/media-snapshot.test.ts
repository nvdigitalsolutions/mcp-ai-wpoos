// ---------------------------------------------------------------------------
// media-snapshot.test.ts — the committed media net for a CMS-less site.
//
// The localizer already mirrors what it can into dist/media; a cache-less
// rebuild still loses a different set of CDN derivatives every time. The
// snapshot is built FROM the localizer's own report, so URL → file stays the
// one mapping the artifact actually used.
// ---------------------------------------------------------------------------

import { mkdtemp, mkdir, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

import { writeMediaSnapshot } from '../lib/media-snapshot.ts';

const roots: string[] = [];

async function tempDir(): Promise<string> {
	const dir = await mkdtemp(join(tmpdir(), 'forge-media-snapshot-'));
	roots.push(dir);
	return dir;
}

afterEach(async () => {
	await Promise.all(roots.splice(0).map((dir) => rm(dir, { recursive: true, force: true })));
});

const CSS_URL = 'https://cms.example.com/wp-content/themes/x/icon.css';
const FONT_URL = 'https://cms.example.com/wp-content/themes/x/et-line.woff';
const IMAGE_URL = 'https://cms.example.com/wp-content/uploads/hero.png';

async function projectWithReport(): Promise<string> {
	const project = await tempDir();
	await mkdir(join(project, '.forge-cache'), { recursive: true });
	await mkdir(join(project, '.forge-cache', 'source-stylesheets'), { recursive: true });
	await mkdir(join(project, 'dist', 'client', 'media'), { recursive: true });
	// The built sheet is the REWRITTEN copy; the snapshot must carry the SOURCE.
	await writeFile(join(project, 'dist', 'client', 'media', 'abc-icon.css'), '@font-face{font-family:X;src:url(/media/def-font.woff)}');
	await writeFile(
		join(project, '.forge-cache', 'source-stylesheets', 'abc-icon.css'),
		'@font-face{font-family:X;src:url("../fonts/font.woff") format("woff")}'
	);
	await writeFile(join(project, 'dist', 'client', 'media', 'def-font.woff'), 'woff-bytes');
	await writeFile(join(project, 'dist', 'client', 'media', 'ghi-hero.webp'), 'webp-bytes');
	await writeFile(
		join(project, '.forge-cache', 'media-localize.json'),
		JSON.stringify({
			localized: [
				{ url: CSS_URL, file: '/media/abc-icon.css', contentType: 'text/css', bytes: 37, sourceFile: '.forge-cache/source-stylesheets/abc-icon.css' },
				{ url: FONT_URL, file: '/media/def-font.woff', contentType: 'font/woff', bytes: 10 },
				{ url: IMAGE_URL, file: '/media/ghi-hero.webp', contentType: 'image/webp', bytes: 10 },
			],
		})
	);
	return project;
}

describe('writeMediaSnapshot', () => {
	it('copies every localized file and writes a URL-keyed manifest', async () => {
		const project = await projectWithReport();
		const out = join(await tempDir(), 'site.media');

		const result = await writeMediaSnapshot({ projectDir: project, outDir: out });

		expect(result.count).toBe(3);
		expect(result.files).toBe(3);
		expect((await stat(join(out, 'media', 'def-font.woff'))).size).toBe(10);
		// The snapshot holds the SOURCE sheet, so a second localization pass can
		// resolve its relative font URLs again (not the `/media/...` rewrite).
		const snapshotCss = await readFile(join(out, 'media', 'abc-icon.css'), 'utf8');
		expect(snapshotCss).toContain('../fonts/font.woff');
		expect(snapshotCss).not.toContain('/media/def-font.woff');
		const manifest = JSON.parse(await readFile(join(out, 'manifest.json'), 'utf8'));
		expect(manifest.version).toBe(1);
		expect(manifest.entries[FONT_URL]).toMatchObject({
			file: 'media/def-font.woff',
			contentType: 'font/woff',
		});
		expect(manifest.entries[CSS_URL].file).toBe('media/abc-icon.css');
	});

	it('reports report entries whose build file is missing', async () => {
		const project = await projectWithReport();
		await rm(join(project, 'dist', 'client', 'media', 'ghi-hero.webp'));
		const out = join(await tempDir(), 'site.media');

		const result = await writeMediaSnapshot({ projectDir: project, outDir: out });

		expect(result.missing).toEqual([IMAGE_URL]);
		expect(result.count).toBe(2);
	});

	it('refuses a project without a localization report', async () => {
		const project = await tempDir();

		await expect(writeMediaSnapshot({ projectDir: project, outDir: join(project, 'out') })).rejects.toThrow(
			/media-localize\.json/
		);
	});

	it('merges into an existing snapshot without dropping old files', async () => {
		const project = await projectWithReport();
		const out = join(await tempDir(), 'site.media');
		await mkdir(join(out, 'media'), { recursive: true });
		await writeFile(join(out, 'media', 'old-font.woff'), 'old');

		await writeMediaSnapshot({ projectDir: project, outDir: out });

		expect((await stat(join(out, 'media', 'old-font.woff'))).size).toBe(3);
		const manifest = JSON.parse(await readFile(join(out, 'manifest.json'), 'utf8'));
		expect(manifest.entries[FONT_URL].file).toBe('media/def-font.woff');
	});
});
