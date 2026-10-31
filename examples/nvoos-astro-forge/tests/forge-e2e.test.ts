// ---------------------------------------------------------------------------
// forge-e2e.test.ts — the exit gate (096 §4): fixture WP → scaffold → build.
//
// Boots the hermetic WP fixture server, scaffolds the production blog
// template from a site profile, installs the generated project, runs
// `astro check` + `astro build`, and asserts the emitted HTML on disk:
// content present, XSS vectors dead, SEO surfaces wired.
//
// Slow by design (npm install + astro build) — excluded from `npm test`,
// run via `npm run test:e2e`.
// ---------------------------------------------------------------------------

import { spawn, spawnSync } from 'node:child_process';
import net from 'node:net';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { afterAll, beforeAll, describe, expect, it } from 'vitest';

import { scaffold } from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';

const FORGE_ROOT = fileURLToPath(new URL('..', import.meta.url));
const FIXTURE_SERVER = path.join(FORGE_ROOT, 'scripts', 'wp-fixture-server.mjs');

let server: ReturnType<typeof spawn> | undefined;
let origin = '';
let workDir = '';

/** Grab an ephemeral port. */
async function freePort(): Promise<number> {
	return new Promise((resolve, reject) => {
		const probe = net.createServer();
		probe.listen(0, '127.0.0.1', () => {
			const address = probe.address();
			if (address && typeof address === 'object') {
				const { port } = address;
				probe.close(() => resolve(port));
			} else {
				probe.close(() => reject(new Error('no port')));
			}
		});
	});
}

async function waitForHealth(url: string, attempts = 100): Promise<void> {
	for (let i = 0; i < attempts; i++) {
		try {
			const res = await fetch(url);
			if (res.ok) return;
		} catch {
			// Not up yet.
		}
		await new Promise((resolve) => setTimeout(resolve, 100));
	}
	throw new Error(`fixture server never became healthy at ${url}`);
}

/** Run a command in the generated project. */
function run(cwd: string, command: string, args: string[], env: Record<string, string> = {}): { status: number | null; stdout: string; stderr: string } {
	const result = spawnSync(command, args, {
		cwd,
		shell: true,
		encoding: 'utf8',
		env: { ...process.env, ...env },
		timeout: 300_000,
	});
	return { status: result.status, stdout: result.stdout ?? '', stderr: result.stderr ?? '' };
}

const PROFILE: SiteProfile = {
	url: 'https://fixture.test/', // overridden by WP_SITE_URL at build time
	name: 'Fixture Wares',
	// The fixture's About page becomes the static front page — the home
	// renders page content, and /about/ must disappear (one canonical URL).
	frontPage: 'about',
	// tokens_apply: the 098 design language lands over the --forge-* defaults.
	tokens: { accent: '#0b5f4e' },
	collections: [
		{
			slug: 'posts',
			restBase: 'wp/v2/posts',
			fields: ['id', 'date_gmt', 'modified_gmt', 'title', 'slug', 'link', 'content', 'excerpt'],
			embed: true,
		},
		{
			slug: 'pages',
			restBase: 'wp/v2/pages',
			fields: ['id', 'date_gmt', 'modified_gmt', 'title', 'slug', 'link', 'content', 'excerpt'],
			embed: true,
		},
		{
			slug: 'products',
			restBase: 'wc/v3/products',
			fields: [
				'id',
				'name',
				'slug',
				'permalink',
				'date_created_gmt',
				'date_modified_gmt',
				'description',
				'short_description',
				'price',
				'regular_price',
				'sale_price',
				'stock_status',
				'sku',
				'images',
				'categories',
			],
			kind: 'product',
			authMode: 'wc-consumer',
		},
	],
};

beforeAll(async () => {
	const port = await freePort();
	origin = `http://127.0.0.1:${port}`;
	server = spawn(process.execPath, [FIXTURE_SERVER, '--port', String(port), '--quiet'], { stdio: 'pipe' });
	await waitForHealth(`${origin}/health`);

	workDir = await mkdtemp(path.join(os.tmpdir(), 'forge-e2e-'));
	await scaffold({ profile: PROFILE, targetDir: workDir, template: 'blog' });

	// Install the generated project (network: registry).
	const install = run(workDir, 'npm', ['install', '--no-audit', '--no-fund']);
	expect(install.status, `npm install failed:\n${install.stderr}`).toBe(0);
}, 420_000);

afterAll(async () => {
	server?.kill();
	if (workDir) {
		await rm(workDir, { recursive: true, force: true });
	}
});

describe('forge e2e — scaffold → check → build', () => {
	it('passes astro check', () => {
		const result = run(workDir, 'npm', ['run', 'check'], { WP_SITE_URL: origin });
		expect(result.status, `astro check failed:\n${result.stdout}\n${result.stderr}`).toBe(0);
	}, 300_000);

	it('builds a static site against the hermetic WP fixture', () => {
		const result = run(workDir, 'npm', ['run', 'build'], {
			WP_SITE_URL: origin,
			ASTRO_SITE: origin,
			// The fixture ignores auth; these exercise the wc-consumer env wiring.
			WP_WC_CONSUMER_KEY: 'ck_test',
			WP_WC_CONSUMER_SECRET: 'cs_test',
		});
		expect(result.status, `astro build failed:\n${result.stdout}\n${result.stderr}`).toBe(0);
	}, 300_000);
});

describe('forge e2e — emitted site', () => {
	it('renders posts, the front page, sitemap and robots', async () => {
		const index = await readFile(path.join(workDir, 'dist', 'index.html'), 'utf8');
		// The About page is the static front page — its content renders at `/`,
		// with the site name in the chrome.
		expect(index).toContain('Fixture Wares is the hermetic test storefront.');
		expect(index).toContain('<title>Fixture Wares</title>');
		// ...and /about/ must not exist as a second URL for the same content.
		await expect(readFile(path.join(workDir, 'dist', 'about', 'index.html'), 'utf8')).rejects.toThrow();

		const posts = await readFile(path.join(workDir, 'dist', 'posts', 'index.html'), 'utf8');
		expect(posts).toContain('Fjord merino sweater, first look');
		expect(posts).toContain('Care guide: wool, cashmere and linen');
		expect(posts).toContain('Stitch pattern: honeycomb rib');

		const sitemap = await readFile(path.join(workDir, 'dist', 'sitemap.xml'), 'utf8');
		expect(sitemap).toContain('/posts/1/fjord-merino-sweater/');
		expect(sitemap).toContain('/products/101/fjord-merino-sweater/');
		expect(sitemap).not.toContain('/about/');

		const robots = await readFile(path.join(workDir, 'dist', 'robots.txt'), 'utf8');
		expect(robots).toContain(`Sitemap: ${origin}/sitemap.xml`);
	});

	it('renders the storefront listing with prices, sales and categories', async () => {
		const listing = await readFile(path.join(workDir, 'dist', 'products', 'index.html'), 'utf8');
		expect(listing).toContain('Fjord Merino Sweater');
		expect(listing).toContain('120.00');
		expect(listing).toContain('Harbor Linen Shirt');
		expect(listing).toContain('<s>110.00</s>'); // sale: regular struck through
		expect(listing).toContain('90.00');
		expect(listing).toContain('Knitwear');
	});

	it('renders product details with commerce data and Product JSON-LD', async () => {
		const detail = await readFile(
			path.join(workDir, 'dist', 'products', '101', 'fjord-merino-sweater', 'index.html'),
			'utf8'
		);
		expect(detail).toContain('SKU FJ-101');
		expect(detail).toContain('In stock');
		expect(detail).toContain('Three-ply merino, expedition weight.');
		expect(detail).toContain('application/ld+json');
		expect(detail).toContain('Product');
		expect(detail).toContain('120.00');
		expect(detail).toContain('InStock');

		const outOfStock = await readFile(
			path.join(workDir, 'dist', 'products', '102', 'harbor-linen-shirt', 'index.html'),
			'utf8'
		);
		expect(outOfStock).toContain('outofstock');
	});

	it('kills every XSS vector in rendered WordPress HTML', async () => {
		const detail = await readFile(path.join(workDir, 'dist', 'posts', '3', 'xss-trap', 'index.html'), 'utf8');
		expect(detail).toContain('Safe paragraph.');
		expect(detail).not.toContain('<script>alert');
		expect(detail).not.toContain('onerror');
		expect(detail).not.toContain('javascript:');
	});

	it('keeps allowlisted embeds and wires SEO surfaces on detail pages', async () => {
		const detail = await readFile(path.join(workDir, 'dist', 'posts', '4', 'studio-notes-autumn', 'index.html'), 'utf8');
		expect(detail).toContain('youtube-nocookie.com/embed/abc123');
		expect(detail).toContain('application/ld+json');
		expect(detail).toContain('"BlogPosting"');
		expect(detail).toContain(`<link rel="canonical" href="${origin}/posts/4/studio-notes-autumn/"`);
	});

	it('applies the design tokens, RSS, 404 and the redirect map', async () => {
		// tokens_apply: the accent override is baked into the built CSS.
		const index = await readFile(path.join(workDir, 'dist', 'index.html'), 'utf8');
		expect(index).toContain('--forge-accent:#0b5f4e');
		expect(index).toContain('type="application/rss+xml"');

		const feed = await readFile(path.join(workDir, 'dist', 'feed.xml'), 'utf8');
		expect(feed).toContain('<rss version="2.0"');
		expect(feed).toContain('<title>Fjord merino sweater, first look</title>');
		expect(feed).toContain(`<guid isPermaLink="true">${origin}/posts/1/fjord-merino-sweater/</guid>`);

		const notFound = await readFile(path.join(workDir, 'dist', '404.html'), 'utf8');
		expect(notFound).toContain('404');

		// The 301 map: WP paths -> generated paths, one hop, front page home.
		// /redirects.json is the structured document; postbuild materializes
		// the host-agnostic _redirects file.
		const map = JSON.parse(await readFile(path.join(workDir, 'dist', 'redirects.json'), 'utf8'));
		expect(map).toContainEqual({
			from: '/fjord-merino-sweater/',
			to: '/posts/1/fjord-merino-sweater/',
			status: 301,
		});
		expect(map).toContainEqual({ from: '/about/', to: '/', status: 301 });

		const redirects = await readFile(path.join(workDir, 'dist', '_redirects'), 'utf8');
		expect(redirects).toContain('/fjord-merino-sweater/\t/posts/1/fjord-merino-sweater/\t301');
		expect(redirects).toContain('/about/\t/\t301');
		expect(redirects).toContain('/product/fjord-merino-sweater/\t/products/101/fjord-merino-sweater/\t301');
	});

	it('renders the care-guide rich content (tables, figures, quotes)', async () => {
		const detail = await readFile(path.join(workDir, 'dist', 'posts', '2', 'care-guide', 'index.html'), 'utf8');
		expect(detail).toContain('<table>');
		expect(detail).toContain('Folded knits');
		expect(detail).toContain('blockquote');
		expect(detail).toContain('alt="Folded knits"');
	});
});
