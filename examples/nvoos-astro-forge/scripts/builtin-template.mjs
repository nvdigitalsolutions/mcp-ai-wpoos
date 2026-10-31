// ---------------------------------------------------------------------------
// builtin-template.mjs — test fixture: a nested built-in template tree.
//
// Emits `<root>/main/templates/blog/` with a REAL content.config.ts (imports
// the real vendored loader) and a `.gitignore`, so scaffold tests can assert
// the template-copy path, the FORGE-marker rewrite, and the "never push
// built assets" gate without coupling to the production templates/blog.
// ---------------------------------------------------------------------------

import { copyFile, mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const FORGE_ROOT = fileURLToPath(new URL('..', import.meta.url));

/**
 * @param {string} rootDir test temp root
 * @returns {Promise<string>} the template directory
 */
export async function createBuiltinTemplateFixture(rootDir) {
	const templateDir = path.join(rootDir, 'main', 'templates', 'blog');

	await mkdir(path.join(templateDir, 'src', 'pages'), { recursive: true });
	await mkdir(path.join(templateDir, 'src', 'forge'), { recursive: true });

	// Vendor the REAL forge libs so the fixture content.config.ts is a real loader.
	for (const file of ['types.ts', 'wp-client.ts', 'sanitize.ts', 'wp-loader.ts']) {
		await copyFile(path.join(FORGE_ROOT, 'lib', file), path.join(templateDir, 'src', 'forge', file));
	}

	await writeFile(
		path.join(templateDir, 'package.json'),
		JSON.stringify({ name: 'fixture-template', type: 'module', dependencies: { astro: '^7.4.4' } }, null, '\t') + '\n',
		'utf8'
	);

	// The gate: built assets never ship to the customer repo.
	await writeFile(path.join(templateDir, '.gitignore'), 'node_modules/\ndist/\n.astro/\n', 'utf8');

	await writeFile(
		path.join(templateDir, 'src', 'content.config.ts'),
		[
			"import { defineCollection } from 'astro:content';",
			"import { createWpLoader } from './forge/wp-loader.ts';",
			"import type { SiteProfile, WpCollection, WpAuth } from './forge/types.ts';",
			'',
			'// FORGE:site',
			"const siteUrl = 'http://127.0.0.1:8473';",
			"const siteName = 'Fixture';",
			'const auth: WpAuth | undefined = undefined;',
			'const site: SiteProfile = { url: siteUrl, name: siteName, collections: [] };',
			'',
			'// FORGE:collections',
			"const postsCollection: WpCollection = { slug: 'posts', restBase: 'wp/v2/posts', fields: ['id'], embed: true };",
			'site.collections.push(postsCollection);',
			'export const collections = {',
			"\tposts: defineCollection({ loader: createWpLoader({ site, collection: postsCollection, auth }) }),",
			'};',
			'// FORGE:collections-end',
			'',
		].join('\n'),
		'utf8'
	);

	await writeFile(path.join(templateDir, 'src', 'pages', 'index.astro'), '<h1>Fixture</h1>\n', 'utf8');

	return templateDir;
}
