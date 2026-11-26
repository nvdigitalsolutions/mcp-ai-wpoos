// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// localize-media-snapshot.test.ts — the localizer reads a committed snapshot
// BEFORE the network, so a CMS-less rebuild does not depend on surviving CDN
// derivatives.
//
// virtuallyjamaica (2026-11-15): a cold rebuild saw a different set of
// /wp-content/ derivatives 404 as the CDN copy expired, including the icon
// font. The snapshot is keyed by the ORIGINAL source URL, which is exactly what
// the built HTML still carries after `repairMediaUrl`.
// ---------------------------------------------------------------------------

import { execFile } from 'node:child_process';
import { createServer } from 'node:http';
import { mkdtemp, mkdir, readFile, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { promisify } from 'node:util';

import { afterEach, describe, expect, it } from 'vitest';

import { rmTemp } from './helpers/tmp.ts';

const execFileAsync = promisify(execFile);
const LOCALIZER = resolve('templates/blog/scripts/localize-media.mjs');

const roots: string[] = [];

async function tempDir(): Promise<string> {
	const dir = await mkdtemp(join(tmpdir(), 'forge-localize-snapshot-'));
	roots.push(dir);
	return dir;
}

afterEach(async () => {
	await Promise.all(roots.splice(0).map((dir) => rmTemp(dir)));
});

describe('localize-media — committed snapshot', () => {
	it('uses the snapshot before the network and rewrites the built HTML', async () => {
		const project = await tempDir();
		const base = 'http://127.0.0.1:59999';
		const sheetUrl = `${base}/wp-content/themes/x/icon.css`;
		const fontUrl = `${base}/wp-content/themes/x/font.woff`;

		await mkdir(join(project, 'dist', 'client'), { recursive: true });
		await writeFile(
			join(project, 'nvoos.json'),
			JSON.stringify({ url: base, media: { localize: true, mode: 'local', snapshot: true } })
		);
		await writeFile(
			join(project, 'dist', 'client', 'index.html'),
			`<html><head><link rel="stylesheet" href="${sheetUrl}"></head><body><i class="icon-flag"></i></body></html>`
		);

		await mkdir(join(project, 'media-snapshot', 'media'), { recursive: true });
		await writeFile(
			join(project, 'media-snapshot', 'manifest.json'),
			JSON.stringify({
				version: 1,
				entries: {
					[sheetUrl]: { file: 'media/abc-icon.css', contentType: 'text/css' },
					[fontUrl]: { file: 'media/def-font.woff', contentType: 'font/woff' },
				},
			})
		);
		await writeFile(
			join(project, 'media-snapshot', 'media', 'abc-icon.css'),
			'@font-face{font-family:"et-line";src:url("font.woff") format("woff")}\n.icon-flag{font-family:"et-line"}'
		);
		await writeFile(join(project, 'media-snapshot', 'media', 'def-font.woff'), 'snapshot-font');

		// No server at 59999 — an authoritative snapshot must not need one.
		await execFileAsync(process.execPath, [LOCALIZER], {
			cwd: project,
			env: { ...process.env, WP_SITE_URL: base, NVOOS_FORGE_MEDIA_TIMEOUT_MS: '1000' },
			timeout: 30_000,
		});

		const html = await readFile(join(project, 'dist', 'client', 'index.html'), 'utf8');
		expect(html).not.toContain(sheetUrl);
		expect(html).toMatch(/href="\/media\/[0-9a-f]{12}-icon\.css"/);

		const cssHref = /href="(\/media\/[^"]+icon\.css)"/.exec(html)?.[1];
		const css = await readFile(join(project, 'dist', 'client', cssHref!.slice(1)), 'utf8');
		expect(css).toContain('@font-face');
		expect(css).toMatch(/url\(["']?\/media\/[0-9a-f]{12}-font\.woff/);

		const report = JSON.parse(await readFile(join(project, '.forge-cache', 'media-localize.json'), 'utf8'));
		expect(report.failed).toEqual([]);
		expect(report.localized.every((entry: { source?: string }) => entry.source === 'snapshot')).toBe(true);
		expect(report.localized.some((entry: { file?: string }) => /-font\.woff$/.test(entry.file ?? ''))).toBe(true);
	});

	it('stops the build when an authoritative snapshot has a hole', async () => {
		// A manifest is a promise that the asset is COMMITTED. When it is
		// authoritative there is no other source, so a missing file used to leave
		// the origin URL in the page — eight dead hotlinks on a site whose CMS is
		// gone (monicarajiv: 68 manifest entries, 41 with no file in git).
		const project = await tempDir();
		const base = 'http://127.0.0.1:59998';
		const imgUrl = `${base}/wp-content/uploads/a.jpg`;

		await mkdir(join(project, 'dist', 'client'), { recursive: true });
		await writeFile(
			join(project, 'nvoos.json'),
			JSON.stringify({ url: base, media: { localize: true, mode: 'local', snapshot: true } })
		);
		await writeFile(
			join(project, 'dist', 'client', 'index.html'),
			`<html><body><img src="${imgUrl}" alt=""></body></html>`
		);

		await mkdir(join(project, 'media-snapshot', 'media'), { recursive: true });
		await writeFile(
			join(project, 'media-snapshot', 'manifest.json'),
			JSON.stringify({ version: 1, entries: { [imgUrl]: { file: 'media/abc-a.webp', contentType: 'image/webp' } } })
		);
		// The manifest names a file the snapshot does not carry.

		// The localizer reports the hole and exits non-zero; the message is on
		// stderr, so read the whole rejection rather than only `.message`.
		let failure = '';
		try {
			await execFileAsync(process.execPath, [LOCALIZER], {
				cwd: project,
				env: { ...process.env, WP_SITE_URL: base, NVOOS_FORGE_MEDIA_TIMEOUT_MS: '1000' },
				timeout: 30_000,
			});
		} catch (error) {
			const rejection = error as { message?: string; stdout?: string; stderr?: string };
			failure = `${rejection.message ?? ''}${rejection.stdout ?? ''}${rejection.stderr ?? ''}`;
		}
		expect(failure).toMatch(/media snapshot is incomplete: 1 of 1/);
		expect(failure).toContain('Re-freeze it');
	});

	it('replaces an asset the CMS no longer serves with a local stand-in', async () => {
		// monicarajiv's WP install has lost ten of its own uploads to 404. Leaving
		// the origin URL shipped a hotlink to a dead host; the localizer now writes
		// a transparent mark and points the reference at it, so the layout keeps
		// its box and nothing leaves the site.
		const project = await tempDir();
		const base = 'http://127.0.0.1:59997';
		const goneUrl = `${base}/wp-content/uploads/gone.jpg`;

		await mkdir(join(project, 'dist', 'client'), { recursive: true });
		await writeFile(
			join(project, 'nvoos.json'),
			JSON.stringify({ url: base, media: { localize: true, mode: 'local' } })
		);
		await writeFile(
			join(project, 'dist', 'client', 'index.html'),
			`<html><body><img src="${goneUrl}" alt=""></body></html>`
		);

		// A server that answers 404 for everything.
		const server = createServer((_request, response) => {
			response.writeHead(404, { 'content-type': 'text/plain' });
			response.end('nope');
		});
		await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
		const address = server.address();
		const port = typeof address === 'object' && address ? address.port : 0;
		const origin = `http://127.0.0.1:${port}`;
		try {
			await writeFile(
				join(project, 'nvoos.json'),
				JSON.stringify({ url: origin, media: { localize: true, mode: 'local' } })
			);
			await writeFile(
				join(project, 'dist', 'client', 'index.html'),
				`<html><body><img src="${origin}/wp-content/uploads/gone.jpg" alt=""></body></html>`
			);
			await execFileAsync(process.execPath, [LOCALIZER], {
				cwd: project,
				env: { ...process.env, WP_SITE_URL: origin, NVOOS_FORGE_MEDIA_TIMEOUT_MS: '1000' },
				timeout: 30_000,
			});
		} finally {
			await new Promise<void>((resolve) => server.close(() => resolve()));
		}

		const html = await readFile(join(project, 'dist', 'client', 'index.html'), 'utf8');
		expect(html).not.toContain('/wp-content/uploads/gone.jpg');
		expect(html).toContain('/media/unavailable.svg');
		expect(await readFile(join(project, 'dist', 'client', 'media', 'unavailable.svg'), 'utf8')).toContain('<svg');

		const report = JSON.parse(await readFile(join(project, '.forge-cache', 'media-localize.json'), 'utf8'));
		expect(report.failed).toEqual([]);
		expect(report.replaced).toHaveLength(1);
	});
});
