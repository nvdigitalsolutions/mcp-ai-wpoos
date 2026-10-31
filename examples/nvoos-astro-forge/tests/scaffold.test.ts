// ---------------------------------------------------------------------------
// scaffold.test.ts — template copy, FORGE-marker rewrite, vendoring, gates.
// ---------------------------------------------------------------------------

import { mkdir, mkdtemp, readFile, rm, stat, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { afterEach, describe, expect, it } from 'vitest';

import { scaffold, renderContentConfig, resolveTemplateDir, rewriteContentConfig } from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';
import { createBuiltinTemplateFixture } from '../scripts/builtin-template.mjs';
import { createWpLoader } from '../lib/wp-loader.ts';

const PROFILE: SiteProfile = {
	url: 'https://shop.example.com/',
	name: 'Example Store',
	collections: [
		{
			slug: 'posts',
			restBase: 'wp/v2/posts',
			fields: ['id', 'date_gmt', 'title', 'content'],
			embed: true,
		},
		{
			slug: 'products',
			restBase: 'wp/v2/product',
			fields: ['id', 'title'],
			embed: false,
		},
	],
};

const tempDirs: string[] = [];
async function tempDir(): Promise<string> {
	const dir = await mkdtemp(path.join(os.tmpdir(), 'forge-scaffold-'));
	tempDirs.push(dir);
	return dir;
}

afterEach(async () => {
	await Promise.all(tempDirs.splice(0).map((dir) => rm(dir, { recursive: true, force: true })));
});

describe('scaffold — built-in template fixture', () => {
	it('copies the template, vendors the forge libs, rewrites the config, and gates .gitignore', async () => {
		const root = await tempDir();
		const templateDir = await createBuiltinTemplateFixture(root);
		const target = path.join(root, 'out');

		const result = await scaffold({ profile: PROFILE, targetDir: target, template: templateDir });

		// Manifest.
		const manifest = JSON.parse(await readFile(path.join(target, 'nvoos.json'), 'utf8'));
		expect(manifest.generator).toBe('nvoos-astro-forge');
		expect(manifest.url).toBe('https://shop.example.com');
		expect(manifest.collections).toHaveLength(2);
		expect(manifest.collections[1].restBase).toBe('wp/v2/product');
		expect(result.collections).toEqual(['posts', 'products']);

		// Template copied.
		expect((await readFile(path.join(target, 'src/pages/index.astro'), 'utf8')).trim()).toBe('<h1>Fixture</h1>');

		// Vendored forge libs — the REAL loader.
		const vendored = await import(pathToFileURL(path.join(target, 'src/forge/wp-loader.ts')).href);
		const loader = vendored.createWpLoader({
			site: PROFILE,
			collection: PROFILE.collections[0],
		});
		expect(loader.name).toBe('nvoos-wp');
		expect((loader as { schema?: unknown }).schema).toBeDefined();

		// Rewritten content config.
		const config = await readFile(path.join(target, 'src/content.config.ts'), 'utf8');
		expect(config).toContain("const siteUrl = (import.meta.env.WP_SITE_URL ?? 'https://shop.example.com')");
		expect(config).toContain("restBase: 'wp/v2/product'");
		expect(config).toContain("slug: 'products'");
		expect(config).toContain('// FORGE:collections-end');
		expect(config).not.toContain('Fixture Site'); // template default replaced

		// The gitignore gate: built assets never ship.
		const gitignore = await readFile(path.join(target, '.gitignore'), 'utf8');
		for (const entry of ['node_modules/', 'dist/', '.astro/']) {
			expect(gitignore.split(/\r?\n/)).toContain(entry);
		}

		// Env example carries the URL, never secrets.
		const envExample = await readFile(path.join(target, '.env.example'), 'utf8');
		expect(envExample).toContain('WP_SITE_URL=https://shop.example.com');
		expect(envExample).toContain('WP_APPLICATION_PASSWORD=');
	});
});

describe('scaffold — production blog template', () => {
	it('resolves the built-in template by name and emits the full blog', async () => {
		const root = await tempDir();
		const target = path.join(root, 'site');

		const result = await scaffold({ profile: PROFILE, targetDir: target, template: 'blog' });

		expect(result.collections).toEqual(['posts', 'products']);
		await expect(stat(path.join(target, 'src/pages/index.astro'))).resolves.toBeTruthy();
		await expect(stat(path.join(target, 'src/pages/posts/[...parts].astro'))).resolves.toBeTruthy();
		await expect(stat(path.join(target, 'src/layouts/Base.astro'))).resolves.toBeTruthy();
		await expect(stat(path.join(target, 'src/pages/sitemap.xml.ts'))).resolves.toBeTruthy();
		await expect(stat(path.join(target, 'src/forge/sanitize.ts'))).resolves.toBeTruthy();

		// Both profile collections land in the config.
		const config = await readFile(path.join(target, 'src/content.config.ts'), 'utf8');
		expect(config).toContain('posts: defineCollection');
		expect(config).toContain('products: defineCollection');

		// Manifest carries the site identity for the template chrome.
		const manifest = JSON.parse(await readFile(path.join(target, 'nvoos.json'), 'utf8'));
		expect(manifest.name).toBe('Example Store');
	});

	it('keeps the storefront pages when the profile declares products', async () => {
		const root = await tempDir();
		const target = path.join(root, 'shop');
		await scaffold({
			profile: {
				...PROFILE,
				collections: [
					...PROFILE.collections,
					{
						slug: 'catalog',
						restBase: 'wc/v3/products',
						fields: ['id', 'name'],
						kind: 'product',
					},
				],
			},
			targetDir: target,
			template: 'blog',
		});
		await expect(stat(path.join(target, 'src/pages/products/index.astro'))).resolves.toBeTruthy();
	});

	it('prunes the storefront pages for a blog-only profile', async () => {
		const root = await tempDir();
		const target = path.join(root, 'blog');
		await scaffold({
			profile: { ...PROFILE, collections: PROFILE.collections.filter((c) => c.slug !== 'products') },
			targetDir: target,
			template: 'blog',
		});
		await expect(stat(path.join(target, 'src/pages/products/index.astro'))).rejects.toThrow();
		const manifest = JSON.parse(await readFile(path.join(target, 'nvoos.json'), 'utf8'));
		expect(manifest.collections).toHaveLength(1);
	});

	it('records the front page slug in the manifest', async () => {
		const root = await tempDir();
		const target = path.join(root, 'front');
		await scaffold({
			profile: { ...PROFILE, frontPage: 'home' },
			targetDir: target,
			template: 'blog',
		});
		const manifest = JSON.parse(await readFile(path.join(target, 'nvoos.json'), 'utf8'));
		expect(manifest.frontPage).toBe('home');
	});
});

describe('scaffold — safety guards', () => {
	it('refuses to clobber a non-forge directory without overwrite', async () => {
		const root = await tempDir();
		const target = path.join(root, 'occupied');
		await mkdir(target, { recursive: true });
		await writeFile(path.join(target, 'keep.txt'), 'precious');

		await expect(scaffold({ profile: PROFILE, targetDir: target, template: 'blog' })).rejects.toThrow(
			/is not a forge project/
		);
		// The precious file survives.
		expect(await readFile(path.join(target, 'keep.txt'), 'utf8')).toBe('precious');
	});

	it('overwrites a forge project when overwrite is true', async () => {
		const root = await tempDir();
		const target = path.join(root, 'site');
		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog' });
		await writeFile(path.join(target, 'src/pages/extra.astro'), '<p>extra</p>');

		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog', overwrite: true });

		await expect(stat(path.join(target, 'src/pages/extra.astro'))).rejects.toThrow();
		expect((await readFile(path.join(target, 'nvoos.json'), 'utf8')).length).toBeGreaterThan(0);
	});

	it('rejects unknown built-in template names', async () => {
		const root = await tempDir();
		await expect(resolveTemplateDir('does-not-exist')).rejects.toThrow(/not found/);
	});
});

describe('renderContentConfig', () => {
	it('emits env-driven site URL and both auth blocks', () => {
		const out = renderContentConfig(PROFILE);
		expect(out).toContain("import.meta.env.WP_SITE_URL ?? 'https://shop.example.com'");
		expect(out).toContain('const applicationAuth: WpAuth | undefined =');
		expect(out).toContain('const wcAuth: WpAuth | undefined =');
		expect(out).toContain("type: 'basic'");
		expect(out).toContain('export const collections = {');
		expect(out).toContain("replace(/\\/+$/, '')");
	});

	it('wires wc-consumer collections to the Woo auth env', () => {
		const profile: SiteProfile = {
			...PROFILE,
			collections: [
				...PROFILE.collections,
				{
					slug: 'products',
					restBase: 'wc/v3/products',
					fields: ['id', 'name', 'price'],
					kind: 'product',
					authMode: 'wc-consumer',
				},
			],
		};
		const out = renderContentConfig(profile);
		expect(out).toContain("authMode: 'wc-consumer',");
		expect(out).toContain('collection: productsCollection, auth: wcAuth');
		expect(out).toContain('collection: postsCollection, auth: applicationAuth');
	});

	it('emits exclude and optional flags', () => {
		const profile: SiteProfile = {
			...PROFILE,
			collections: [
				{
					slug: 'pages',
					restBase: 'wp/v2/pages',
					fields: ['id', 'title'],
					exclude: ['lost-password', 'checkout'],
					optional: true,
				},
			],
		};
		const out = renderContentConfig(profile);
		expect(out).toContain("exclude: ['lost-password', 'checkout'],");
		expect(out).toContain('optional: true,');
	});

	it('normalises trailing slashes on the profile URL', () => {
		const out = renderContentConfig({ ...PROFILE, url: 'https://shop.example.com///' });
		expect(out).toContain("'https://shop.example.com'");
	});

	it('ignores marker names mentioned in prose (line-anchored matching)', () => {
		const source = [
			'// Header comment mentioning FORGE:site in prose.',
			'// FORGE:site',
			'const siteUrl = \'OLD\';',
			'// FORGE:collections-end',
		].join('\n');
		const out = rewriteContentConfig(source, PROFILE);
		expect(out).toContain('// Header comment mentioning FORGE:site in prose.');
		expect(out).toContain("'https://shop.example.com'");
		expect(out).toContain('// FORGE:collections-end');
		expect(out).not.toContain('OLD');
	});
});
