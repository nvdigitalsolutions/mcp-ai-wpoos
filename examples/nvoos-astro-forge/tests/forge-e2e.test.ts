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
import http from 'node:http';
import { mkdtemp, readFile, rm, stat } from 'node:fs/promises';
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
	// The design block (125): the fixture's About page carries a split-hero
	// section whose facts ride design.sections — the rebuilt home must
	// render the copy/divider/collage layout, and the harvested webfont
	// (rubik) must load from Google Fonts.
	design: {
		mode: 'rebuild',
		source: { url: 'https://fixture.test', analyzedAt: '2026-11-06T00:00:00.000Z' },
		concept: 'hermetic split hero',
		palette: { roles: { accent: '#0b5f4e' }, unassigned: [], confidence: { accent: 'high' } },
		typography: { display: 'rubik', body: 'rubik', bodyWeight: '300', scalePx: [ 8, 13, 29, 48 ] },
		spacing: { scalePx: [ 4, 10, 17, 30, 70 ] },
		radii: { valuesPx: [ 4 ] },
		shadows: { count: 0 },
		components: {},
		modes: { dark: 'auto', contrast: 'auto' },
		adaptation: {
			keep: [],
			replace: [],
			remix: [],
			licensingNote: '',
			attribution: [],
		},
		sections: [
			{
				id: 'a1b2c3d',
				anchor: 'about',
				bgGradient: 'linear-gradient(180deg, #e5e5e5 0%, #ffffff 100%)',
				measure: '1140px',
				paddingX: '30px',
				layout: 'split',
				bodyColumns: 2,
				titleDivider: { width: '125px', height: '6px', background: '#0b5f4e', shadow: '0px 4px 8px 0px rgba(11,95,78,0.5)' },
				titleStyle: { size: '43px', weight: '500', lineHeight: '57px', letterSpacing: '-1.5px' },
				mediaShape: { width: '412px', height: '450px', background: 'linear-gradient(39deg, #0b5f4e 0%, #0a8a71 100%)' },
				mediaOffsets: [
					{ top: '-25%', left: '0px', shadow: '0px 3px 34px 0px rgba(0,0,0,0.16)' },
					{ top: '25%', right: '-110px' },
				],
			},
			{
				id: 'b4c5d6e',
				bgColor: '#f6f6f6',
				catalog: { title: 'fiori-digital-catalogue', source: 'https://fixture.test/wp-content/uploads/catalog.pdf' },
			},
		],
	},
	// The form bridge (114 §3.9) — the default endpoint derives from the
	// profile URL; the rebuilt forms POST there, never to WP directly.
	forms: {},
	collections: [
		{
			slug: 'posts',
			restBase: 'wp/v2/posts',
			fields: [
				'id', 'date_gmt', 'modified_gmt', 'title', 'slug', 'link', 'content', 'excerpt',
				'rank_math_title', 'rank_math_description', 'yoast_head_json',
			],
			embed: true,
		},
		{
			slug: 'pages',
			restBase: 'wp/v2/pages',
			fields: [
				'id', 'date_gmt', 'modified_gmt', 'title', 'slug', 'link', 'content', 'excerpt',
				'rank_math_title', 'rank_math_description', 'yoast_head_json',
			],
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
			// Proposal 131 — wc/v3 has no SEO fields; the loader merges the
			// product CPT's Rank Math/Yoast head in one id-keyed sidecar pass.
			seoFrom: 'wp/v2/product',
		},
	],
};

/** Serves a built dist directory over loopback (the axe e2e gate). */
async function serveStatic( dir: string ): Promise<{ port: number; close: () => Promise<void> }> {
	const types: Record<string, string> = {
		'.html': 'text/html; charset=utf-8',
		'.css': 'text/css',
		'.js': 'text/javascript',
		'.json': 'application/json',
		'.svg': 'image/svg+xml',
		'.png': 'image/png',
		'.webp': 'image/webp',
		'.xml': 'application/xml',
		'.txt': 'text/plain',
		'.ico': 'image/x-icon',
	};
	const server = http.createServer( async ( req, res ) => {
		const url = new URL( req.url ?? '/', 'http://localhost' );
		let target = path.join( dir, decodeURIComponent( url.pathname ) );
		if ( url.pathname.endsWith( '/' ) ) {
			target = path.join( target, 'index.html' );
		}
		const body = await readFile( target ).catch( () => null );
		if ( body === null ) {
			res.writeHead( 404 );
			res.end();
			return;
		}
		res.writeHead( 200, { 'content-type': types[ path.extname( target ) ] ?? 'application/octet-stream' } );
		res.end( body );
	} );
	await new Promise<void>( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );
	const address = server.address();
	if ( ! address || typeof address === 'string' ) {
		throw new Error( 'static server failed to bind' );
	}
	return {
		port: address.port,
		close: () => new Promise( ( resolve ) => server.close( () => resolve() ) ),
	};
}

beforeAll(async () => {
	const port = await freePort();
	origin = `http://127.0.0.1:${port}`;
	server = spawn(process.execPath, [FIXTURE_SERVER, '--port', String(port), '--quiet'], { stdio: 'pipe' });
	await waitForHealth(`${origin}/health`);

	workDir = await mkdtemp(path.join(os.tmpdir(), 'forge-e2e-'));
	await scaffold({ profile: PROFILE, targetDir: workDir, template: 'blog', demoContent: true });

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
	it( 'renders posts, the front page, sitemap and robots', async () => {
		const index = await readFile(path.join(workDir, 'dist', 'index.html'), 'utf8');
		// The About page is the static front page — its rebuilt sections
		// render at `/`, with the site name in the chrome.
		expect(index).toContain('At Fixture Wares we serve the fjords.');
		expect(index).toContain('<title>Fixture Wares</title>');
		// ...and /about/ must not exist as a second URL for the same content.
		await expect(readFile(path.join(workDir, 'dist', 'about', 'index.html'), 'utf8')).rejects.toThrow();

		const posts = await readFile(path.join(workDir, 'dist', 'posts', 'index.html'), 'utf8');
		expect(posts).toContain('Fjord merino sweater, first look');
		expect(posts).toContain('Care guide: wool, cashmere and linen');
		expect(posts).toContain('Stitch pattern: honeycomb rib');
		// The hybrid-local demo post merges under the WP entries (106 P2).
		expect(posts).toContain('Hello from the forge');
		// The content blocks (126 §blocks) render in the local post — the
		// native exclusive FAQ accordion and the no-JS tab set (Astro
		// scopes the elements, so assert attribute-agnostically).
		const localPost = await readFile(path.join(workDir, 'dist', 'posts', 'hello-world', 'hello-world', 'index.html'), 'utf8');
		expect(localPost).toContain('nvoos-content-faq');
		expect(localPost).toContain('<summary');
		expect(localPost).toContain('Where do you ship?');
		expect(localPost).toContain('nvoos-tabs-input');
		expect(localPost).toContain('nvoos-tabs-tab');
		expect(localPost).toContain('nvoos-tabs-panel');

		const sitemap = await readFile(path.join(workDir, 'dist', 'sitemap.xml'), 'utf8');
		expect(sitemap).toContain('/posts/1/fjord-merino-sweater/');
		expect(sitemap).toContain('/products/101/fjord-merino-sweater/');
		expect(sitemap).toContain('/posts/hello-world/hello-world/');
		expect(sitemap).not.toContain('/about/');
		// 131 §2.4 — a noindex page leaves the map, and lastmod ships.
		expect(sitemap).not.toContain('/contact/');
		expect(sitemap).toContain('<lastmod>');

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

	it('optimizes same-host images and passes CDN images through', async () => {
		const listing = await readFile(path.join(workDir, 'dist', 'products', 'index.html'), 'utf8');
		// Same-host WP images go through Astro Image (hashed local assets).
		expect(listing).toMatch(/src="\/_astro\/[^"]+\.(webp|avif)"/);
		expect(listing).toContain('srcset=');
		// External CDN hosts are never transformed — plain passthrough, no
		// build failure.
		expect(listing).toContain('https://cdn.fixture.test/product-c.png');
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
		// 131 §2.2 — the wp/v2 product sidecar merged the CPT's Yoast head into
		// the wc/v3 document, and its title already brands itself.
		expect(detail).toContain('<title>Fjord Merino Sweater — buy the original | Fixture Wares</title>');
		expect(detail).toContain('name="twitter:card" content="summary_large_image"');

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
		// Form-control vectors (114 §3.9 risk gate) die with the rest.
		expect(detail).not.toContain('onfocus');
	});

	it('rebuilds forms as design components on the bridge endpoint (114 §3.9)', async () => {
		const trap = await readFile(path.join(workDir, 'dist', 'posts', '3', 'xss-trap', 'index.html'), 'utf8');
		// The search form maps onto the site's own /search/ surface (input q).
		expect(trap).toContain('action="/search/"');
		expect(trap).toContain('name="q"');
		// The Elementor form rebuilds with the bridge endpoint as its action —
		// the source WP action/method/carriers never leak.
		expect(trap).toContain('action="https://fixture.test/wp-json/nvoos-forge/v1/forms/submit"');
		expect(trap).toContain('name="form"');
		expect(trap).toContain('value="fixture-form-1"');
		expect(trap).toContain('name="honeypot"');
		expect(trap).toContain('name="form_fields[name]"');
		expect(trap).toContain('placeholder="Jane"');
		expect(trap).not.toContain('name="form_id"');
		expect(trap).not.toContain('elementor-pro-forms');

		// The JetFormBuilder form on /contact/ rebuilds with its numeric form id.
		const contact = await readFile(path.join(workDir, 'dist', 'contact', 'index.html'), 'utf8');
		expect(contact).toContain('action="https://fixture.test/wp-json/nvoos-forge/v1/forms/submit"');
		expect(contact).toContain('value="9"');
		expect(contact).toContain('name="email"');
		expect(contact).toContain('Ask away');
	});

	it('keeps allowlisted embeds and wires SEO surfaces on detail pages', async () => {
		const detail = await readFile(path.join(workDir, 'dist', 'posts', '4', 'studio-notes-autumn', 'index.html'), 'utf8');
		expect(detail).toContain('youtube-nocookie.com/embed/abc123');
		expect(detail).toContain('application/ld+json');
		expect(detail).toContain('"BlogPosting"');
		expect(detail).toContain(`<link rel="canonical" href="${origin}/posts/4/studio-notes-autumn/"`);
	});

	it('applies the design tokens, RSS, 404 and the redirect map', async () => {
		// tokens_apply: the accent override is baked into the built CSS. The
		// theme-policy additions (105) pushed the bundle past Astro's inline
		// threshold, so the tokens now ship in the linked stylesheet — resolve
		// the href and assert there (or inline when the bundle is small again).
		const index = await readFile(path.join(workDir, 'dist', 'index.html'), 'utf8');
		const cssHref = /href="(\/_astro\/[^"]+\.css)"/.exec(index)?.[1];
		if (cssHref) {
			const css = await readFile(path.join(workDir, 'dist', cssHref.replace(/^\//, '')), 'utf8');
			// Minifier-agnostic: esbuild preserves the space in custom-property
			// values, lightningcss strips it.
			expect(css).toMatch(/--forge-accent:\s*#0b5f4e/);
			// The 2026-11-06 regressions must never come back: (a) a minifier
			// that merges the timeline into the `animation` shorthand kills
			// every parallax + reveal; (b) the brand strips must stay equal
			// columns (the 3rem-height flex row made jayainc's wide banners
			// dominate, shrank the square marks, and wrapped the row).
			expect(css).toContain('animation-timeline:scroll(root)');
			expect(css).toContain('animation-timeline:view()');
			expect(css).not.toMatch(/animation:[^;}]*scroll\(root\)/);
			expect(css).not.toMatch(/animation:[^;}]*view\(\)/);
			expect(css).toMatch(/grid-template-columns:\s*repeat\(\s*auto-fit\s*,\s*minmax\(\s*8rem\s*,\s*1fr\s*\)\s*\)/);
			expect(css).toMatch(/\.ds-logos\s+img\s*\{[^}]*height:\s*auto/);
			// The hero headline constraint (125 §8): the band title does not
			// span the full measure on desktop (jayainc's 72px headline sits
			// in a ~66% column; the var is the per-site seam).
			expect(css).toMatch(/\.ds-section-wrap--hero\s+\.ds-title\s*\{[^}]*max-width:\s*var\(--ds-hero-title-max/);
			// The title-to-grid rhythm (125 §8): the card grid opens with
			// breathing room below the band heading.
			expect(css).toMatch(/\.ds-grid\s*\{[^}]*margin-top:\s*var\(--forge-space\)/);
			// The chrome reflow (125 §8 — recovered after the #7493 squash
			// dropped the commit): the header must wrap wherever the single
			// flex row does not fit (it needs ~850px of content) or the call
			// button gets sliced at the body's clip edge, and the collage
			// offsets must reset to static flow below the measured band.
			expect(css).toMatch(/@media[^{]*\{\s*\.site-header\s*\{[^}]*flex-wrap:\s*wrap/);
			// The wrap must start at the band-reflow breakpoint, not the phone
			// width: pinning it to 767px sliced the call button off at 768px
			// (right edge x=819 on a 753px body). The source stacks its nav to
			// 1024px and only goes single-row by 1152px.
			expect(css).toMatch(/@media\s*\(width<\s*1152px\)\s*\{\s*\.site-header\s*\{[^}]*flex-wrap:\s*wrap/);
			expect(css).toMatch(/\.ds-collage-media\s*\{[^}]*position:\s*static/);
			// The collage must not spill once it reflows: an implicit auto track
			// is sized by the fixed 412px shape, so max-width has no definite
			// containing block to resolve against and the shape drags the
			// photos past the viewport (measured: 45px of horizontal scroll at
			// 390px, 21px at 768px). The definite track is the load-bearing fix;
			// the shape then goes full width at its own ratio.
			expect(css).toMatch(/\.ds-hero-collage\s*\{[^}]*grid-template-columns:\s*minmax\(\s*0\s*,\s*1fr\s*\)/);
			expect(css).toMatch(/\.ds-collage-shape\[data-fluid\]\s*\{[^}]*aspect-ratio:\s*var\(--ds-shape-ratio\)/);
		} else {
			expect(index).toMatch(/--forge-accent:\s*#0b5f4e/);
		}
		// The split-hero rebuild (125 §1): the fixture's About page carries a
		// copy+media-collage section whose facts ride the profile's
		// design.sections — the rebuilt home must render the split layout,
		// the heading divider, and the backdrop shape.
		expect(index).toContain('ds-hero-split');
		expect(index).toContain('ds-title-divider');
		expect(index).toContain('ds-collage-shape');
		// The shape's geometry rides custom properties so the reflow can
		// override it — inline width/height would outrank the media query.
		expect(index).toContain('--ds-shape-w:412px');
		expect(index).toContain('--ds-shape-ratio:412 / 450');
		expect(index).toContain('--ds-off-top:-25%');
		// The flipbook catalog (125 §4) — the PDF source dies with its
		// script; the section fact rebuilds it as a centered open-CTA (the
		// slug-ish data-title is backend id, never rendered copy).
		expect(index).toContain('ds-catalog');
		expect(index).toContain('https://fixture.test/wp-content/uploads/catalog.pdf');
		expect(index).not.toContain('fiori-digital-catalogue');
		// The harvested webfont loads (125 §2) — rubik from Google Fonts.
		expect(index).toContain('fonts.googleapis.com/css2?family=rubik');
		expect(index).toContain('type="application/rss+xml"');
		// The image pipeline (126 §image): same-host WP media rebuilds
		// through SmartImage — processed WebP under /_astro/ with a
		// responsive srcset (the fixture serves a real PNG and the
		// WP-host-derived image.domains authorizes it).
		const productsIndex = await readFile(path.join(workDir, 'dist', 'products', 'index.html'), 'utf8');
		expect(productsIndex).toMatch(/src="\/_astro\/[^"]+\.webp"/);
		expect(productsIndex).toContain('srcset');
		// The DTCG interchange doc (126 §dtcg) ships verbatim from
		// public/ — the machine-readable design contract beside the CSS.
		const dtcg = JSON.parse(await readFile(path.join(workDir, 'dist', 'tokens.dtcg.json'), 'utf8'));
		expect(dtcg.$schema).toContain('designtokens.org');
		expect(dtcg.core.color.$type).toBe('color');
		expect(dtcg.core.color.accent.$value).toBe('#0b5f4e');

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
		// Proposal 132 — the stored rules exported by the Forge Bridge ride along.
		expect(map).toContainEqual({
			from: '/old-fixture/',
			to: '/products/101/fjord-merino-sweater/',
			status: 301,
		});
		expect(map).toContainEqual({
			from: '/legacy-fixture/',
			to: '/posts/1/fjord-merino-sweater/',
			status: 302,
		});

		const redirects = await readFile(path.join(workDir, 'dist', '_redirects'), 'utf8');
		expect(redirects).toContain('/fjord-merino-sweater/\t/posts/1/fjord-merino-sweater/\t301');
		expect(redirects).toContain('/about/\t/\t301');
		expect(redirects).toContain('/product/fjord-merino-sweater/\t/products/101/fjord-merino-sweater/\t301');
		expect(redirects).toContain('/old-fixture/\t/products/101/fjord-merino-sweater/\t301');
		expect(redirects).toContain('/legacy-fixture/\t/posts/1/fjord-merino-sweater/\t302');
	});

	it('passes the axe-core WCAG gate on the rebuilt home (126 §a11y)', async () => {
		// axe-core's own docs: color-contrast does not work in JSDOM — the
		// browser route (Chromium + injected axe) is the sanctioned one. The
		// built dist serves statically; the gate covers the rebuilt design
		// bands, not just the fixture's sanitized markup.
		const { port, close } = await serveStatic(path.join(workDir, 'dist'));
		let browser;
		try {
			const { chromium } = await import('playwright');
			const axeModule = ( await import('axe-core') ) as unknown as { default: { source: string } };
			browser = await chromium.launch();
			const page = await browser.newPage();
			await page.goto(`http://127.0.0.1:${port}/`);
			await page.waitForLoadState('networkidle');
			await page.addScriptTag({ content: axeModule.default.source });
			const results = await page.evaluate(async () => {
				const w = window as unknown as { axe: { run: (c: unknown, o: unknown) => Promise<{ violations: unknown[] }> } };
				return w.axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] } });
			});
			expect(results.violations, JSON.stringify(results.violations, null, 2)).toEqual([]);
		} finally {
			await browser?.close();
			await close();
		}
	}, 120_000);

	it('rebuilds builder sections and ships the design audit (Proposal 114)', async () => {
		const detail = await readFile(path.join(workDir, 'dist', 'posts', '6', 'elementor-landing', 'index.html'), 'utf8');
		// The hero + feature grid rebuild as ADS design sections…
		expect(detail).toContain('design-sections');
		expect(detail).toContain('ds-hero');
		expect(detail).toContain('ds-grid');
		expect(detail).toContain('Ship to the fjords');
		expect(detail).toContain('Read More');
		expect(detail).toContain('Linen');
		// The rebuilt feature grid serves slot-aware sizes (126 §image): a
		// 4-up card is ~25vw on desktop, never the 100vw default.
		expect(detail).toContain('sizes="(min-width: 1025px) 25vw, (min-width: 768px) 50vw, 100vw"');
		// …and the builder scaffolding classes are gone from the output (the
		// semantic model replaced them — the bloat metric).
		expect(detail).not.toContain('elementor-section');
		expect(detail).not.toContain('elementor-widget');

		// The logo wall keeps its heading + CTA — nothing the classifier
		// captured is dropped by the component.
		expect(detail).toContain('Trusted by crews');
		expect(detail).toContain('Meet the crews');
		// The heading + single-image section renders its media too (the text
		// fallback bucket renders everything).
		expect(detail).toContain('Field notes');
		expect(detail).toContain('alt="Field notes"');

		// The build-time contrast audit ships (warn mode on the default scaffold).
		const audit = JSON.parse(await readFile(path.join(workDir, 'dist', 'design-audit.json'), 'utf8'));
		expect(audit.strict).toBe(false);
		expect(audit.audit.pairs.length).toBeGreaterThanOrEqual(10);
		expect(audit.audit.failures).toEqual([]);
	});

	it('ships the deploy-branch build report as an artifact (README.md)', async () => {
		const readme = await readFile(path.join(workDir, 'dist', 'README.md'), 'utf8');

		// It has to SAY it is generated: the mirror branch is force-pushed, so a
		// hand-edited copy would silently disappear on the next build.
		expect(readme).toContain('Generated artifact — do not edit this branch by hand.');

		// Identity + provenance come from the manifest, never from a template.
		expect(readme.startsWith('# Fixture Wares\n')).toBe(true);
		expect(readme).toContain('| Source CMS |');
		expect(readme).toContain('| Render mode | `static` |');

		// Counts are read from the built collections.
		expect(readme).toContain('| Posts |');
		expect(readme).toContain('| Pages |');

		// The design summary mirrors /design-audit.json.
		expect(readme).toContain('token pairs meet WCAG 2.2');

		// Artifacts and an exact rebuild command close the loop. No siteSlug is
		// passed here (scaffold() is called directly), so the slug falls back to
		// the display name.
		expect(readme).toContain('`design-audit.json`');
		expect(readme).toContain('node scripts/forge.mjs sites/fixture-wares.json');
	});

	it('indexes the site with Pagefind for client-side search', async () => {
		const search = await readFile(path.join(workDir, 'dist', 'search', 'index.html'), 'utf8');
		expect(search).toContain("import('/pagefind/pagefind.js')");
		expect(search).toContain('Searching…');
		// The post-build indexer emitted the runtime + fragment index.
		await expect(stat(path.join(workDir, 'dist', 'pagefind', 'pagefind.js'))).resolves.toBeTruthy();
		await expect(
			stat(path.join(workDir, 'dist', 'pagefind', 'pagefind-entry.json'))
		).resolves.toBeTruthy();
	});

	it('site_analyze drafts a scaffold-ready profile from the fixture', async () => {
		const discoveredPath = path.join(workDir, 'discovered.json');
		// Direct node spawn (no shell) — shell quoting mangles Windows paths.
		const result = spawnSync(
			process.execPath,
			[path.join(FORGE_ROOT, 'scripts', 'forge.mjs'), 'analyze', origin, '--write', discoveredPath],
			{ cwd: FORGE_ROOT, encoding: 'utf8', timeout: 60_000 }
		);
		const stdout = result.stdout ?? '';
		const stderr = result.stderr ?? '';
		expect(result.status, `forge analyze failed:\n${stdout}\n${stderr}`).toBe(0);
		expect(stdout).toContain('Fixture Wares');
		expect(stdout).toContain('WooCommerce');

		const profile = JSON.parse(await readFile(discoveredPath, 'utf8'));
		expect(profile.frontPage).toBe('about');
		expect(profile.collections.map((collection: { slug: string }) => collection.slug)).toEqual([
			'posts',
			'pages',
			'products',
		]);
		const products = profile.collections.find(
			(collection: { slug: string }) => collection.slug === 'products'
		);
		expect(products.restBase).toBe('wc/v3/products');
		expect(products.authMode).toBe('wc-consumer');
		expect(products.fields).toContain('regular_price');
	});

	it('renders the care-guide rich content (tables, figures, quotes)', async () => {
		const detail = await readFile(path.join(workDir, 'dist', 'posts', '2', 'care-guide', 'index.html'), 'utf8');
		expect(detail).toContain('<table>');
		expect(detail).toContain('Folded knits');
		expect(detail).toContain('blockquote');
		expect(detail).toContain('alt="Folded knits"');
	});

	it('generated source and dist carry no credential patterns (Phase 3.5)', async () => {
		// The build runs with the fixture's demo consumer keys in the env;
		// values must never be baked into the scaffolded source or the built
		// output — only env references are allowed.
		const { readdir } = await import('node:fs/promises');
		const patterns = /ghp_|github_pat_|ck_test|cs_test|NVOOS_FORGE_VAULT_KEY=[^'"\n]{4,}|WP_APPLICATION_PASSWORD=[^'"\n]{4,}/;
		const skipDirs = new Set(['node_modules', '.git', '.astro']);
		const hits: string[] = [];
		async function walk(dir: string) {
			for (const entry of await readdir(dir, { withFileTypes: true })) {
				const full = path.join(dir, entry.name);
				if (entry.isDirectory()) {
					if (!skipDirs.has(entry.name)) {
						await walk(full);
					}
					continue;
				}
				const content = await readFile(full, 'utf8');
				if (patterns.test(content)) {
					hits.push(full);
				}
			}
		}
		await walk(workDir);
		expect(hits, `credential patterns leaked into generated files: ${hits.join(', ')}`).toEqual([]);
	});
});

describe('forge e2e — long-tail content tools against the fixture', () => {
	it('audits, previews, maps redirects, and renders configs from a registered site', async () => {
		const { mkdir, writeFile, rm } = await import('node:fs/promises');
		const { SitesStore } = await import('../lib/sites.ts');
		const { SITE_TOOLS } = await import('../mcp/tools/sites.ts');
		const { CONTENT_TOOLS } = await import('../mcp/tools/content.ts');
		const home = await mkdtemp(path.join(os.tmpdir(), 'forge-content-home-'));
		try {
			// Seed the registry directly — the loopback exemption is CLI-only
			// by design, so site_add (the MCP surface) refuses 127.0.0.1.
			await mkdir(path.join(home, 'profiles'), { recursive: true });
			const profilePath = path.join(home, 'profiles', 'fixture.json');
			await writeFile(profilePath, JSON.stringify({ ...PROFILE, url: origin }), 'utf8');
			const sites = new SitesStore(home);
			await sites.add('fixture-e2e', { url: origin, profile: profilePath });

			const env = { homeDir: home, workDir: path.join(home, 'projects'), openAnalyze: true };
			const tool = (name: string) =>
				[...SITE_TOOLS, ...CONTENT_TOOLS].find((entry) => entry.name === name)!.handler;

			// content_preview — the sanitizer kills every XSS vector.
			const preview = await tool('content_preview')({ site: 'fixture-e2e', collection: 'posts', slug: 'xss-trap' }, env);
			expect(preview.isError).toBeUndefined();
			const previewData = preview.structuredContent as { before: { scripts: number; handlers: number; javascriptUrls: number }; after: { scripts: number; handlers: number; javascriptUrls: number } };
			expect(previewData.before.scripts).toBeGreaterThan(0);
			expect(previewData.after.scripts).toBe(0);
			expect(previewData.after.handlers).toBe(0);
			expect(previewData.after.javascriptUrls).toBe(0);

			// content_audit — embed hosts aggregate across entries.
			const audit = await tool('content_audit')({ site: 'fixture-e2e', limit: 10 }, env);
			expect(audit.isError).toBeUndefined();
			const auditData = audit.structuredContent as { entries: unknown[]; aggregate: { embeds: string[]; unprocessedShortcodes: string[] } };
			expect(auditData.entries.length).toBeGreaterThan(0);
			expect(auditData.aggregate.embeds).toContain('www.youtube-nocookie.com');
			expect(auditData.aggregate.unprocessedShortcodes).toEqual([]);

			// redirects_generate — sane single-hop map.
			const redirects = await tool('redirects_generate')({ site: 'fixture-e2e' }, env);
			expect(redirects.isError).toBeUndefined();
			const redirectData = redirects.structuredContent as {
				redirects: Array<{ from: string; to: string; status: number }>;
				scanned: number;
				bridge: number;
			};
			expect(redirectData.scanned).toBeGreaterThan(0);
			// Proposal 132 — the fixture bridge exports two stored rules.
			expect(redirectData.bridge).toBe(2);
			expect(redirectData.redirects).toContainEqual({
				from: '/legacy-fixture/',
				to: '/posts/1/fjord-merino-sweater/',
				status: 302,
			});
			for (const entry of redirectData.redirects) {
				expect([301, 302, 303, 307, 308, 404, 410]).toContain(entry.status);
				expect(entry.from).not.toBe(entry.to);
				if (entry.status >= 400) {
					expect(entry.to).toBe('');
				} else {
					expect(entry.to.endsWith('/')).toBe(true);
				}
			}

			// media_audit — the care-guide images are found.
			const media = await tool('media_audit')({ site: 'fixture-e2e', limit: 25 }, env);
			expect(media.isError).toBeUndefined();
			const mediaData = media.structuredContent as { totals: { total: number; sameHost: number } };
			expect(mediaData.totals.total).toBeGreaterThan(0);
			expect(mediaData.totals.sameHost).toBeGreaterThan(0);

			// collections_generate — the typed loader config block.
			const config = await tool('collections_generate')({ site: 'fixture-e2e' }, env);
			expect(config.isError).toBeUndefined();
			const configData = config.structuredContent as { block: string };
			expect(configData.block).toContain('wp/v2/posts');
			expect(configData.block).toContain('wc/v3/products');
			expect(configData.block).toContain('defineCollection');
		} finally {
			await rm(home, { recursive: true, force: true });
		}
	});
});

describe( 'forge e2e — theme policy + hybrid local (Proposals 105 §4 / 106 P2)', () => {
	it( 'the default scaffold bakes auto and wires the policy boot + toggle', async () => {
		const generated = await readFile(
			path.join(workDir, 'src', 'lib', 'theme', 'policy.generated.ts'),
			'utf8'
		);
		expect(generated).toContain("policy: 'auto',");
		await expect(stat(path.join(workDir, 'src', 'lib', 'theme', 'policy.ts'))).resolves.toBeTruthy();
		await expect(stat(path.join(workDir, 'src', 'components', 'ThemeToggle.astro'))).resolves.toBeTruthy();

		const index = await readFile(path.join(workDir, 'dist', 'index.html'), 'utf8');
		// The inert policy JSON node + the no-FOUC boot ladder ship in the head.
		expect(index).toContain('data-theme-policy');
		expect(index).toContain('"policy":"auto"');
		expect(index).toContain('forge-theme-policy');
		expect(index).toContain('astro:after-swap');
		expect(index).toContain('data-theme-toggle');
	} );

	it( 'scaffolding with --theme-policy dark bakes the forced policy', async () => {
		const darkDir = await mkdtemp(path.join(os.tmpdir(), 'forge-dark-'));
		try {
			await scaffold({ profile: PROFILE, targetDir: darkDir, template: 'blog', themePolicy: 'dark' });
			const generated = await readFile(
				path.join(darkDir, 'src', 'lib', 'theme', 'policy.generated.ts'),
				'utf8'
			);
			expect(generated).toContain("policy: 'dark',");
			// The boot ladder enforces the forced theme before any stored value.
			const base = await readFile(path.join(darkDir, 'src', 'layouts', 'Base.astro'), 'utf8');
			expect(base).toContain('forge-theme-policy');
			expect(base).toContain('policy === \'light\' || policy === \'dark\'');
		} finally {
			await rm(darkDir, { recursive: true, force: true });
		}
	} );

	it( 'the CLI rejects malformed --theme-policy values with the clack error', async () => {
		const profilePath = path.join(workDir, 'cli-policy-profile.json');
		await (await import('node:fs/promises')).writeFile(profilePath, JSON.stringify(PROFILE), 'utf8');
		for (const bad of ['sepia', 'brand:', 'brand:Bad_Slug']) {
			const result = spawnSync(
				process.execPath,
				[path.join(FORGE_ROOT, 'scripts', 'forge.mjs'), profilePath, '--target', path.join(workDir, 'cli-policy-out'), '--theme-policy', bad],
				{ cwd: FORGE_ROOT, encoding: 'utf8', timeout: 60_000 }
			);
			expect(result.status, `expected exit 2 for "${bad}"`).toBe(2);
			expect(`${result.stderr ?? ''}`).toContain('Unknown theme policy');
		}
	} );

	it( 'renders SEO meta from Rank Math + Yoast on the post/page routes', async () => {
		// Rank Math title lands in <title> + the BlogPosting headline (106 §1.2).
		const rankPost = await readFile(
			path.join(workDir, 'dist', 'posts', '4', 'studio-notes-autumn', 'index.html'),
			'utf8'
		);
		expect(rankPost).toContain('Studio Notes: The Autumn Palette — a curated journal — Fixture Wares');
		expect(rankPost).toContain('"BlogPosting"');
		expect(rankPost).toContain('Moss, bark and bone: the autumn palette, curated by the studio.');

		// Yoast title via the fallback path.
		const yoastPost = await readFile(
			path.join(workDir, 'dist', 'posts', '2', 'care-guide', 'index.html'),
			'utf8'
		);
		expect(yoastPost).toContain('Care guide for wool, cashmere and linen — the studio journal — Fixture Wares');
		// Proposal 131 — the rest of the Yoast head is carried, not dropped.
		expect(yoastPost).toContain('property="og:title" content="How to care for natural fibres"');
		expect(yoastPost).toContain('property="og:type" content="article"');
		expect(yoastPost).toContain('name="twitter:card" content="summary_large_image"');
		expect(yoastPost).toContain('property="og:image" content="' + origin + '/wp-content/uploads/care.png"');
		// The site graph is rebuilt around the page node (never Yoast's @graph).
		expect(yoastPost).toContain('"@graph"');
		expect(yoastPost).toContain('"Organization"');
		expect(yoastPost).toContain('"WebSite"');
		expect(yoastPost).toContain('"BreadcrumbList"');

		// Pages carry WebPage JSON-LD (velocity parity).
		const contact = await readFile(path.join(workDir, 'dist', 'contact', 'index.html'), 'utf8');
		expect(contact).toContain('"WebPage"');
		expect(contact).toContain('application/ld+json');
		// 131 §2.1 — the source title already carries the site name: no second suffix.
		expect(contact).toContain('<title>Contact Fixture Wares</title>');
		expect(contact).not.toContain('Contact Fixture Wares — Fixture Wares');
		// 131 §2.2 — the robots directive survives the rebuild.
		expect(contact).toContain('<meta name="robots" content="noindex, follow"');
	} );

	it( 'a forged blog builds and renders with NO WP connection (the demo path)', async () => {
		const offlineDir = await mkdtemp(path.join(os.tmpdir(), 'forge-offline-'));
		try {
			// Blog-only: posts + pages (the products collection needs WP auth).
			await scaffold({
				profile: { ...PROFILE, collections: PROFILE.collections.filter((collection) => collection.slug !== 'products') },
				targetDir: offlineDir,
				template: 'blog',
				// The demo seed IS the offline content — this test is the reason
				// `demoContent` exists.
				demoContent: true,
			});
			const install = run(offlineDir, 'npm', ['install', '--no-audit', '--no-fund']);
			expect(install.status, `npm install failed:\n${install.stderr}`).toBe(0);
			// A dead endpoint — the hybrid loader degrades to local-only with a
			// warning instead of failing the build (never silent: it logs).
			// (Port 59999: connection refused — avoid undici's reserved ports.)
			const build = run(offlineDir, 'npm', ['run', 'build'], {
				WP_SITE_URL: 'http://127.0.0.1:59999/',
				ASTRO_SITE: 'https://demo.example.com',
			});
			expect(build.status, `offline build failed:\n${build.stdout}\n${build.stderr}`).toBe(0);
			expect(build.stdout).toContain('serving local content only');

			// The demo post renders; the WP fixture posts do not.
			const detail = await readFile(
				path.join(offlineDir, 'dist', 'posts', 'hello-world', 'hello-world', 'index.html'),
				'utf8'
			);
			expect(detail).toContain('Hello from the forge');
			expect(detail).toContain('"BlogPosting"');
			const listing = await readFile(path.join(offlineDir, 'dist', 'posts', 'index.html'), 'utf8');
			expect(listing).not.toContain('Fjord merino sweater');
		} finally {
			await rm(offlineDir, { recursive: true, force: true });
		}
	}, 600_000);
} );

describe( 'forge e2e — Proposal 104 Phase 4 artifacts', () => {
it( 'emits the agent stubs, copy tables, seo builder and theme module', async () => {
		const agents = await readFile(path.join(workDir, 'AGENTS.md'), 'utf8');
		expect(agents).toContain('nvoos.json');
		expect(agents).toContain('Storefront runtime contract');
		await expect(stat(path.join(workDir, '.context', 'README.md'))).resolves.toBeTruthy();

		await expect(stat(path.join(workDir, 'src', 'copy', 'en.ts'))).resolves.toBeTruthy();
		await expect(stat(path.join(workDir, 'src', 'copy', 'index.ts'))).resolves.toBeTruthy();
		await expect(stat(path.join(workDir, 'src', 'lib', 'seo.ts'))).resolves.toBeTruthy();
		await expect(stat(path.join(workDir, 'src', 'lib', 'theme', 'theme.ts'))).resolves.toBeTruthy();
		await expect(stat(path.join(workDir, 'src', 'lib', 'theme', 'registry.ts'))).resolves.toBeTruthy();
	} );

	it('ships tokens-tailwind.css byte-identical (no marker rewrites)', async () => {
		const templateFile = await readFile(
			path.join(FORGE_ROOT, 'templates', 'blog', 'src', 'styles', 'tokens-tailwind.css'),
			'utf8'
		);
		const scaffolded = await readFile(path.join(workDir, 'src', 'styles', 'tokens-tailwind.css'), 'utf8');
		expect(scaffolded).toBe(templateFile);
	} );

	it('the new builder still pins BlogPosting + Product JSON-LD in dist', async () => {
		const post = await readFile(
			path.join(workDir, 'dist', 'posts', '4', 'studio-notes-autumn', 'index.html'),
			'utf8'
		);
		expect(post).toContain('"BlogPosting"');
		expect(post).toContain('application/ld+json');
		const product = await readFile(
			path.join(workDir, 'dist', 'products', '101', 'fjord-merino-sweater', 'index.html'),
			'utf8'
		);
		expect(product).toContain('"Product"');
		expect(product).toContain('InStock');
	} );
} );

describe('forge e2e — minimal preset scaffold (Proposal 104 §4.3)', () => {
	it('scaffolds the minimal preset and passes astro check + build', async () => {
		const minimalDir = await mkdtemp(path.join(os.tmpdir(), 'forge-minimal-'));
		try {
			await scaffold({ profile: PROFILE, targetDir: minimalDir, template: 'blog', preset: 'minimal' });

			// Surface pruning: no search page, no products, pagefind dropped.
			await expect(stat(path.join(minimalDir, 'src', 'pages', 'search.astro'))).rejects.toThrow();
			await expect(stat(path.join(minimalDir, 'src', 'pages', 'products'))).rejects.toThrow();
			const pkg = JSON.parse(await readFile(path.join(minimalDir, 'package.json'), 'utf8'));
			expect(pkg.scripts.build).not.toContain('pagefind');
			const manifest = JSON.parse(await readFile(path.join(minimalDir, 'nvoos.json'), 'utf8'));
			expect(manifest.surfaces).toEqual({ search: false, feed: true, sitemap: true, ogImages: false });

			const install = run(minimalDir, 'npm', ['install', '--no-audit', '--no-fund']);
			expect(install.status, `npm install failed:\n${install.stderr}`).toBe(0);
			const check = run(minimalDir, 'npm', ['run', 'check'], { WP_SITE_URL: origin });
			expect(check.status, `astro check failed:\n${check.stdout}\n${check.stderr}`).toBe(0);
			const build = run(minimalDir, 'npm', ['run', 'build'], { WP_SITE_URL: origin, ASTRO_SITE: origin });
			expect(build.status, `astro build failed:\n${build.stdout}\n${build.stderr}`).toBe(0);

			// No search/products surfaces in dist; the front page still renders
			// and the nav no longer links to search.
			await expect(readFile(path.join(minimalDir, 'dist', 'search', 'index.html'), 'utf8')).rejects.toThrow();
			await expect(readFile(path.join(minimalDir, 'dist', 'products', 'index.html'), 'utf8')).rejects.toThrow();
			const index = await readFile(path.join(minimalDir, 'dist', 'index.html'), 'utf8');
			expect(index).toContain('At Fixture Wares we serve the fjords.');
			expect(index).not.toContain('href="/search/"');
		} finally {
			await rm(minimalDir, { recursive: true, force: true });
		}
	}, 600_000);
} );

describe('forge e2e — SSR render variant (Proposal 104 Q4 + Q5)', () => {
	it('scaffolds --render ssr with OG cards, builds, and serves the product route', async () => {
		const ssrDir = await mkdtemp(path.join(os.tmpdir(), 'forge-ssr-'));
		let serverProc: ReturnType<typeof spawn> | undefined;
		try {
			await scaffold({
				profile: PROFILE,
				targetDir: ssrDir,
				template: 'blog',
				render: 'ssr',
				surfaces: { ogImages: true },
			});

			// Variant selection: the static product pages are gone, the
			// on-demand route + server config are in, and the OG endpoint
			// shipped with the injected deps.
			await expect(stat(path.join(ssrDir, 'src', 'pages', 'products', '[...parts].astro'))).rejects.toThrow();
			await expect(stat(path.join(ssrDir, 'src', 'pages', 'products', '[id]', '[...slug].astro'))).resolves.toBeTruthy();
			const config = await readFile(path.join(ssrDir, 'astro.config.mjs'), 'utf8');
			expect(config).toContain("import node from '@astrojs/node';");
			expect(config).toContain("output: 'server'");
			await expect(stat(path.join(ssrDir, 'src', 'pages', 'og', '[slug].png.ts'))).resolves.toBeTruthy();
			const pkg = JSON.parse(await readFile(path.join(ssrDir, 'package.json'), 'utf8'));
			expect(pkg.devDependencies?.satori).toBeDefined();

			const install = run(ssrDir, 'npm', ['install', '--no-audit', '--no-fund']);
			expect(install.status, `npm install failed:\n${install.stderr}`).toBe(0);
			const check = run(ssrDir, 'npm', ['run', 'check'], { WP_SITE_URL: origin });
			expect(check.status, `astro check failed:\n${check.stdout}\n${check.stderr}`).toBe(0);
			const build = run(ssrDir, 'npm', ['run', 'build'], {
				WP_SITE_URL: origin,
				ASTRO_SITE: origin,
				WP_WC_CONSUMER_KEY: 'ck_test',
				WP_WC_CONSUMER_SECRET: 'cs_test',
			});
			expect(build.status, `astro build failed:\n${build.stdout}\n${build.stderr}`).toBe(0);

			// Build-time OG cards + prerendered SEO surfaces live under
			// dist/client in server-output mode.
			await expect(stat(path.join(ssrDir, 'dist', 'client', 'og', 'fjord-merino-sweater.png'))).resolves.toBeTruthy();
			await expect(stat(path.join(ssrDir, 'dist', 'client', 'sitemap.xml'))).resolves.toBeTruthy();

			// Serve the standalone build and hit the on-demand route.
			const port = await freePort();
			serverProc = spawn(process.execPath, [path.join(ssrDir, 'dist', 'server', 'entry.mjs')], {
				env: {
					...process.env,
					HOST: '127.0.0.1',
					PORT: String(port),
					WP_SITE_URL: origin,
					WP_WC_CONSUMER_KEY: 'ck_test',
					WP_WC_CONSUMER_SECRET: 'cs_test',
				},
				stdio: 'pipe',
			});
			await waitForHealth(`http://127.0.0.1:${port}/`);

			// 200 + Product JSON-LD in the initial HTML + the cache contract.
			const ok = await fetch(`http://127.0.0.1:${port}/products/101/fjord-merino-sweater/`, { redirect: 'manual' });
			expect(ok.status).toBe(200);
			const html = await ok.text();
			expect(html).toContain('application/ld+json');
			expect(html).toContain('"Product"');
			expect(html).toContain('Fjord Merino Sweater');
			expect(html).toContain('120.00');
			expect(ok.headers.get('cache-control')).toBe('public, s-maxage=300, stale-while-revalidate=600');

			// 301 slug convergence.
			const moved = await fetch(`http://127.0.0.1:${port}/products/101/old-slug/`, { redirect: 'manual' });
			expect(moved.status).toBe(301);
			expect(moved.headers.get('location')).toContain('/products/101/fjord-merino-sweater/');

			// 404 unknown ids.
			const missing = await fetch(`http://127.0.0.1:${port}/products/999/nope/`, { redirect: 'manual' });
			expect(missing.status).toBe(404);
		} finally {
			serverProc?.kill();
			await rm(ssrDir, { recursive: true, force: true });
		}
	}, 600_000);
} );

describe('forge e2e — setup_wizard (Phase 4 exit: URL → built, deploy-ready)', () => {
	it('runs the wizard against the hermetic fixture end-to-end', async () => {
		const wizardHome = await mkdtemp(path.join(os.tmpdir(), 'forge-wizard-home-'));
		const wizardTarget = await mkdtemp(path.join(os.tmpdir(), 'forge-wizard-site-'));
		const result = run(
			FORGE_ROOT,
			'node',
			[
				path.join(FORGE_ROOT, 'scripts', 'forge.mjs'),
				'wizard',
				origin + '/',
				'--target',
				wizardTarget,
				'--name',
				'fixture-wizard',
				'--design',
				'--build',
			],
			{ NVOOS_FORGE_HOME: wizardHome, NVOOS_FORGE_ALLOW_LOOPBACK: '1' }
		);
		expect(result.status, `forge wizard failed:\n${result.stdout}\n${result.stderr}`).toBe(0);

		// Scaffolded + manifest (the wizard normalizes trailing slashes).
		const manifest = JSON.parse(await readFile(path.join(wizardTarget, 'nvoos.json'), 'utf8'));
		expect(manifest.url).toBe(origin);
		// The --design step extracted the fixture homepage's design language
		// (the non-TTY path applies it deterministically) and the manifest
		// carries the rebuild block.
		expect(manifest.design?.palette?.roles?.accent).toBe('#6ec1e4');

		// Built.
		await expect(readFile(path.join(wizardTarget, 'dist', 'index.html'), 'utf8')).resolves.toContain('Fixture Wares');

		// The non-interactive wizard suggested the storefront preset (the
		// fixture advertises Woo) — product pages survive, agent stubs emit.
		await expect(stat(path.join(wizardTarget, 'src', 'pages', 'products', 'index.astro'))).resolves.toBeTruthy();
		await expect(stat(path.join(wizardTarget, 'AGENTS.md'))).resolves.toBeTruthy();
		const wizardManifest = JSON.parse(await readFile(path.join(wizardTarget, 'nvoos.json'), 'utf8'));
		expect(wizardManifest.surfaces).toEqual({ search: true, feed: true, sitemap: true, ogImages: false });
		expect(wizardManifest.render).toBe('static'); // non-TTY wizard stays static
		expect(wizardManifest.collections.map((collection: { slug: string }) => collection.slug)).toContain('products');

		// git-initialized.
		await expect(stat(path.join(wizardTarget, '.git'))).resolves.toBeDefined();

		// Deploy pipeline emitted (Phase 3.3 file set).
		await expect(readFile(path.join(wizardTarget, '.github', 'workflows', 'deploy.yml'), 'utf8')).resolves.toContain('configure-pages');
		await expect(readFile(path.join(wizardTarget, '.github', 'workflows', 'rebuild.yml'), 'utf8')).resolves.toContain('repository_dispatch');
		await expect(readFile(path.join(wizardTarget, 'scripts', 'verify-dispatch.mjs'), 'utf8')).resolves.toContain('timingSafeEqual');

		// Registered in the multi-site registry with a drafted profile.
		const sites = JSON.parse(await readFile(path.join(wizardHome, 'sites.json'), 'utf8'));
		expect(sites.sites['fixture-wizard']).toBeDefined();
		expect(sites.default).toBe('fixture-wizard');

		// The wizard build ran without consumer keys — values never baked.
		const builtIndex = await readFile(path.join(wizardTarget, 'dist', 'index.html'), 'utf8');
		expect(builtIndex).not.toMatch(/ck_test|cs_test/);

		await rm(wizardHome, { recursive: true, force: true });
		await rm(wizardTarget, { recursive: true, force: true });
	}, 600_000);
});

describe( 'forge e2e — tracking capture (Proposal 130)', () => {
	let trackingDir = '';
	let trackingProfile: SiteProfile | undefined;

	afterAll( async () => {
		if ( trackingDir ) {
			await rm( trackingDir, { recursive: true, force: true } );
		}
	} );

	it( 'detects the fixture trackers and drafts them DISABLED', async () => {
		const { analyzeTracking, trackingFromReport, cspHostsFor } = await import( '../lib/tracking.ts' );
		const report = await analyzeTracking( { url: origin, fetchImpl: fetch } );
		const vendors = report.trackers.map( ( tracker ) => tracker.vendor );
		expect( vendors ).toContain( 'google-analytics-4' );
		expect( vendors ).toContain( 'meta-pixel' );
		expect( vendors ).toContain( 'cookieyes' );
		expect( report.trackers.find( ( tracker ) => tracker.vendor === 'google-analytics-4' )?.ids.measurementId ).toBe(
			'G-FIXTURE1234'
		);
		expect( report.trackers.find( ( tracker ) => tracker.vendor === 'meta-pixel' )?.ids.pixelId ).toBe( '111222333444555' );
		expect( report.consent.cmp?.vendor ).toBe( 'cookieyes' );
		expect( report.verification.google ).toBe( 'fixture-verify-token' );
		expect( report.warnings.join( ' ' ) ).toContain( 'noscript' );

		const draft = trackingFromReport( report );
		expect( draft.trackers.every( ( tracker ) => tracker.enabled === false ) ).toBe( true );
		expect( draft.consent.mode ).toBe( 'external-cmp' );

		// The operator keeps GA4 + Meta and switches to the first-party banner.
		const enabled = draft.trackers.map( ( tracker ) =>
			tracker.vendor === 'google-analytics-4' || tracker.vendor === 'meta-pixel'
				? { ...tracker, enabled: true }
				: tracker
		);
		trackingProfile = {
			...PROFILE,
			url: origin,
			tracking: {
				...draft,
				trackers: enabled,
				consent: { mode: 'forge', default: 'denied', strategy: 'basic', gpc: true, policy: { privacy: '/privacy/' } },
				verification: {
					...draft.verification,
					files: [ { path: '/google-fixture.html', content: 'google-site-verification: google-fixture.html' } ],
				},
				csp: { hosts: cspHostsFor( enabled ) },
			},
		};
	} );

	it( 'scaffolds, checks, builds and emits consent-first tracking', async () => {
		expect( trackingProfile ).toBeDefined();
		trackingDir = await mkdtemp( path.join( os.tmpdir(), 'forge-tracking-' ) );
		await scaffold( { profile: trackingProfile as SiteProfile, targetDir: trackingDir, template: 'blog' } );

		const install = run( trackingDir, 'npm', [ 'install', '--no-audit', '--no-fund' ] );
		expect( install.status, `npm install failed:\n${ install.stderr }` ).toBe( 0 );

		const check = run( trackingDir, 'npm', [ 'run', 'check' ], { WP_SITE_URL: origin } );
		expect( check.status, `astro check failed:\n${ check.stdout }\n${ check.stderr }` ).toBe( 0 );

		const build = run( trackingDir, 'npm', [ 'run', 'build' ], { WP_SITE_URL: origin } );
		expect( build.status, `astro build failed:\n${ build.stdout }\n${ build.stderr }` ).toBe( 0 );

		const html = await readFile( path.join( trackingDir, 'dist', 'index.html' ), 'utf8' );
		// Verification meta + proof file.
		expect( html ).toContain( 'google-site-verification' );
		expect( html ).toContain( 'fixture-verify-token' );
		expect( await readFile( path.join( trackingDir, 'dist', 'google-fixture.html' ), 'utf8' ) ).toContain(
			'google-site-verification'
		);
		// The consent bootstrap precedes the loader, and no vendor script is in
		// the static HTML — deferred, consent-gated delivery.
		const consentIndex = html.indexOf( 'nvoos-consent-default' );
		expect( consentIndex ).toBeGreaterThan( -1 );
		expect( html.indexOf( 'nvoos-tracking-loader' ) ).toBeGreaterThan( consentIndex );
		expect( /<script[^>]+src=["'][^"']*googletagmanager/.test( html ) ).toBe( false );
		expect( /<script[^>]+src=["'][^"']*facebook\.net/.test( html ) ).toBe( false );
		// The banner renders and the event map keeps purchase on WordPress.
		expect( html ).toContain( 'nvoos-consent-banner' );
		expect( html ).toContain( '"purchase":false' );
		// The CSP header file ships Report-Only with both enabled vendors' hosts.
		const headers = await readFile( path.join( trackingDir, 'dist', '_headers' ), 'utf8' );
		expect( headers ).toContain( 'Content-Security-Policy-Report-Only' );
		expect( headers ).toContain( 'https://www.googletagmanager.com' );
		expect( headers ).toContain( 'https://connect.facebook.net' );
		// The pure gate agrees with the real build.
		const { auditTracking } = await import( '../lib/tracking-audit.ts' );
		const findings = auditTracking( trackingProfile?.tracking, html, { hasDistFile: () => true } );
		expect( findings.filter( ( finding ) => finding.severity === 'fail' ) ).toEqual( [] );
	}, 480_000 );

	it( 'shows the consent banner with no axe violations', async () => {
		const { port, close } = await serveStatic( path.join( trackingDir, 'dist' ) );
		let browser;
		try {
			const { chromium } = await import( 'playwright' );
			const axeModule = ( await import( 'axe-core' ) ) as unknown as { default: { source: string } };
			browser = await chromium.launch();
			const page = await browser.newPage();
			await page.goto( `http://127.0.0.1:${ port }/` );
			await page.waitForLoadState( 'networkidle' );
			// A first visit has no stored decision — the banner must be visible.
			expect( await page.locator( '#nvoos-consent-banner' ).isVisible() ).toBe( true );
			await page.addScriptTag( { content: axeModule.default.source } );
			const results = await page.evaluate( async () => {
				const w = window as unknown as { axe: { run: ( c: unknown, o: unknown ) => Promise< { violations: unknown[] } > } };
				return w.axe.run( document, {
					runOnly: { type: 'tag', values: [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] },
				} );
			} );
			expect( results.violations, JSON.stringify( results.violations, null, 2 ) ).toEqual( [] );
		} finally {
			await browser?.close();
			await close();
		}
	}, 180_000 );
} );
