// ---------------------------------------------------------------------------
// scaffold.test.ts — template copy, FORGE-marker rewrite, vendoring, gates.
// ---------------------------------------------------------------------------

import { mkdir, mkdtemp, readdir, readFile, rm, stat, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { afterEach, describe, expect, it } from 'vitest';

import { AGENT_STUB_FILES, collectProjectFiles } from '../lib/project-files.ts';
import { auditTokenBlock, FORGE_LIB_FILES, FORGE_ROOT, mergeTokens, renderAstroConfig, renderChromeGenerated, renderDesignGenerated, renderSectionsGenerated, renderTokensDtcg, rewriteOgTokens, scaffold, renderContentConfig, renderPolicyGenerated, renderTokenBlock, resolveTemplateDir, rewriteContentConfig, rewriteGlobalCss, tokenWarnings } from '../lib/scaffold.ts';
import type { ForgeDesign, SiteProfile } from '../lib/types.ts';
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

/** A jayainc-shaped design block — the rebuilt theme input (114 §3.3). */
const DESIGN: ForgeDesign = {
	mode: 'rebuild',
	source: { url: 'https://shop.example.com', analyzedAt: '2026-11-05T00:00:00.000Z' },
	concept: 'cool accent over light neutrals, rubik display / roboto body, compact spacing rhythm.',
	palette: {
		roles: { accent: '#6ec1e4', text: '#444444', surface: '#ffffff', 'field.border': '#767676' },
		unassigned: [ '#ffcf0e', '#242424', '#3d3d3d' ],
		confidence: { accent: 'high', text: 'medium' },
	},
	typography: { display: 'Rubik', body: 'Roboto', scalePx: [ 14, 16, 20, 28, 40 ] },
	spacing: { scalePx: [ 4, 8, 16, 24, 48 ] },
	radii: { valuesPx: [ 4, 8 ] },
	shadows: { count: 2 },
	components: { button: 7, card: 8 },
	modes: { dark: 'auto', contrast: 'auto' },
	adaptation: {
		keep: [ 'The palette roles, type pairing, and spacing rhythm.' ],
		replace: [ 'All copy, imagery, logos with the customer\'s own content.' ],
		remix: [ 'Shift the accent hue by roughly 10 degrees.' ],
		licensingNote: 'Inspired-by analysis: no source CSS, HTML, copy, or assets were copied.',
		attribution: [ 'Source: https://shop.example.com' ],
	},
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

		// The drift manifest (126 §drift) hashes every generated file.
		const generated = JSON.parse(await readFile(path.join(target, 'nvoos.generated.json'), 'utf8'));
		expect(Object.keys(generated.files)).toEqual([
			'src/styles/global.css',
			'src/lib/design.generated.ts',
			'src/lib/sections.generated.ts',
			'src/lib/chrome.generated.ts',
			'src/lib/behaviors.generated.ts',
			'src/lib/vocabulary.generated.ts',
			'public/tokens.dtcg.json',
		]);
	});

	it('check-generated passes on a clean scaffold and fails on drift (126 §drift)', async () => {
		const root = await tempDir();
		const target = path.join(root, 'site');
		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog' });

		const run = () =>
			spawnSync(process.execPath, [path.join(target, 'scripts', 'check-generated.mjs')], { encoding: 'utf8' });
		expect(run().status).toBe(0);

		// Tamper with a generated file — the gate must fail, then pass again
		// after the file is restored.
		const generated = path.join(target, 'src', 'lib', 'design.generated.ts');
		const original = await readFile(generated, 'utf8');
		await writeFile(generated, original + '\n// hand edit\n', 'utf8');
		expect(run().status).toBe(1);
		await writeFile(generated, original, 'utf8');
		expect(run().status).toBe(0);
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

	it('keeps the previous project intact when a re-scaffold fails part-way', async () => {
		const root = await tempDir();
		const target = path.join(root, 'site');
		const template = await createBuiltinTemplateFixture(root);
		await scaffold({ profile: PROFILE, targetDir: target, template });

		// Break the token span: the template copy succeeds, the rewrite throws.
		// Deleting the target up front made this destroy the previous project
		// and leave a half-written one that still built (template-default
		// tokens, no site tokens) — see the staging swap in scaffold().
		const cssPath = path.join(template, 'src', 'styles', 'global.css');
		const css = await readFile(cssPath, 'utf8');
		await writeFile(cssPath, css.replace(/\/\* FORGE:tokens(-end)? \*\/\n?/g, ''), 'utf8');

		await expect(
			scaffold({ profile: PROFILE, targetDir: target, template, overwrite: true })
		).rejects.toThrow(/FORGE:tokens/);

		// The previous project is untouched and no staging litter remains.
		expect((await readFile(path.join(target, 'nvoos.json'), 'utf8')).length).toBeGreaterThan(0);
		expect(await readFile(path.join(target, 'src', 'styles', 'global.css'), 'utf8')).toContain(
			'--forge-accent'
		);
		expect((await readdir(root)).filter((name) => /\.forge-(tmp|old)-/.test(name))).toEqual([]);
	});

	it('swaps the new project into place and reports the real target', async () => {
		const root = await tempDir();
		const target = path.join(root, 'site');
		const template = await createBuiltinTemplateFixture(root);
		await scaffold({ profile: PROFILE, targetDir: target, template });

		const result = await scaffold({
			profile: PROFILE,
			targetDir: target,
			template,
			overwrite: true,
		});

		// The swap reports the real directory, not the staging path.
		expect(result.targetDir).toBe(target);
		expect((await readdir(root)).filter((name) => /\.forge-(tmp|old)-/.test(name))).toEqual([]);
	});

	it('rejects unknown built-in template names', async () => {
		const root = await tempDir();
		await expect(resolveTemplateDir('does-not-exist')).rejects.toThrow(/not found/);
	});
});

describe('tokens_apply', () => {
	it('merges profile overrides over the defaults in the token block', () => {
		const out = renderTokenBlock({ accent: '#720eec', ink: '#7a7a7a' });
		expect(out).toContain('--forge-accent: #720eec;');
		expect(out).toContain('--forge-ink: #7a7a7a;');
		expect(out).toContain('--forge-surface: #ffffff;'); // default survives
		expect(out).toContain('--forge-space: 1rem;'); // layout tokens survive
	});

	it('rewrites only the marked span in global.css', () => {
		const css = ['/* FORGE:tokens */', ':root {', '\t--forge-accent: #old;', '}', '/* FORGE:tokens-end */', 'body { color: red; }'].join('\n');
		const out = rewriteGlobalCss(css, { accent: '#720eec' });
		expect(out).toContain('--forge-accent: #720eec;');
		expect(out).toContain('body { color: red; }');
		expect(out).not.toContain('#old');
		expect(out).toContain('/* FORGE:tokens */');
		expect(out).toContain('/* FORGE:tokens-end */');
	});

	it('applies a profile measure token over the template default (the section-width fix)', () => {
		const css = ['/* FORGE:tokens */', ':root {', '\t--forge-measure: 75rem;', '}', '/* FORGE:tokens-end */'].join('\n');
		const out = rewriteGlobalCss(css, { measure: 'min(1600px, 100%)' });
		expect(out).toContain('--forge-measure: min(1600px, 100%);');
		expect(out).not.toContain('--forge-measure: 75rem');
	});

	it('fails loudly when the markers are missing', () => {
		expect(() => rewriteGlobalCss(':root {}', {})).toThrow(/FORGE:tokens/);
	});

	it('audits the generated span against the profile (the green-button class)', () => {
		const template = [
			'/* FORGE:tokens */',
			':root {',
			'\t--forge-accent: #0f6b4f;',
			'}',
			'/* FORGE:tokens-end */',
		].join('\n');

		// A tree that never got the rewrite keeps the template defaults.
		const stale = auditTokenBlock(template, { accent: '#ffcf0e' });
		expect(stale.markers).toBe(true);
		expect(stale.drifted.find((drift) => drift.name === 'accent')).toEqual({
			name: 'accent',
			expected: '#ffcf0e',
			actual: '#0f6b4f',
		});

		// A rewritten tree round-trips clean.
		const applied = rewriteGlobalCss(template, { accent: '#ffcf0e' });
		expect(auditTokenBlock(applied, { accent: '#ffcf0e' }).drifted).toEqual([]);

		// A hand-copied template has no span at all.
		expect(auditTokenBlock(':root {}', { accent: '#ffcf0e' })).toEqual({
			markers: false,
			drifted: [],
		});
	});

	it('warns when rendered pairs fail WCAG AA', () => {
		const warnings = tokenWarnings({ accent: '#ffffff', 'accent-ink': '#ffffff' });
		expect(warnings.some((w) => w.includes('WCAG') && w.includes('accent'))).toBe(true);
	});

	it('warns on unparseable tokens and stays quiet on clean ones', () => {
		expect(tokenWarnings({ ink: 'red' }).length).toBeGreaterThan(0);
		expect(tokenWarnings({})).toEqual([]); // ADS defaults pass
	});

	it('writes the tokens into the scaffold output and ships the NOTICE', async () => {
		const root = await tempDir();
		const target = path.join(root, 'themed');
		const result = await scaffold({
			profile: { ...PROFILE, tokens: { accent: '#0b5f4e' } },
			targetDir: target,
			template: 'blog',
		});

		const css = await readFile(path.join(target, 'src', 'styles', 'global.css'), 'utf8');
		expect(css).toContain('--forge-accent: #0b5f4e;');
		const manifest = JSON.parse(await readFile(path.join(target, 'nvoos.json'), 'utf8'));
		expect(manifest.tokens).toEqual({ accent: '#0b5f4e' });
		const notice = await readFile(path.join(target, 'NOTICE'), 'utf8');
		expect(notice).toContain('MIT');
		expect(notice).toContain('Generated output');
		expect(result.warnings).toEqual([]);
	});
});

describe( 'renderChromeGenerated (Proposal 123 facts)', () => {
	const profile: SiteProfile = {
		url: 'https://jayainc.example/',
		name: 'Jaya',
		collections: [],
		design: {
			mode: 'rebuild',
			source: { url: 'https://jayainc.example', analyzedAt: '2026-11-06T00:00:00.000Z' },
			concept: 'test',
			palette: { roles: {}, unassigned: [], confidence: {} },
			typography: { scalePx: [ 16 ] },
			spacing: { scalePx: [ 8 ] },
			radii: { valuesPx: [] },
			shadows: { count: 0 },
			components: {},
			modes: { dark: 'auto', contrast: 'auto' },
			adaptation: { keep: [], replace: [], remix: [], licensingNote: '', attribution: [] },
			chrome: {
				topbarBg: '#04132b',
				topbarInk: '#ffffff',
				headerBg: '#04132b',
				headerInk: '#ffffff',
				favicon: 'https://jayainc.example/wp-content/uploads/cropped-icon-32x32.png',
				iconCss: 'https://jayainc.example/wp-content/themes/phlox-pro/css/auxin-icon.css?ver=5.17.20',
				footerInk: 'rgba(255, 255, 255, 0.7)',
				navStyle: { weight: '400', size: '16px', transform: 'uppercase' },
				navInk: '#ffffff',
				call: {
					label: 'CALL US',
					href: 'tel:305-500-9319',
					bg: 'linear-gradient(98deg, #ffcf0e 0%, #ffb70e 100%)',
					ink: '#242424',
					style: { padding: '14px 43px', radius: '100em', weight: '400', size: '16px', transform: 'uppercase' },
				},
				footerBarBg: 'rgba(8, 26, 67, 0.9)',
				footerBarInk: 'rgba(255, 255, 255, 0.7)',
				footerHeadingStyle: { weight: '500', size: '29px', transform: 'capitalize' },
				chromeSize: '16px',
			},
		},
	};

	it( 'bakes the typography/shape facts (alpha preserved, no zero letter-spacing)', () => {
		const module = renderChromeGenerated( profile );
		expect( module ).toContain( "topbarBg: '#04132b'" );
		expect( module ).toContain( "headerBg: '#04132b'" );
		expect( module ).toContain( "navStyle: { weight: '400', size: '16px', transform: 'uppercase' }" );
		expect( module ).toContain( "style: { padding: '14px 43px', radius: '100em', weight: '400', size: '16px', transform: 'uppercase' }" );
		expect( module ).toContain( "footerBarBg: 'rgba(8, 26, 67, 0.9)'" );
		expect( module ).toContain( "footerBarInk: 'rgba(255, 255, 255, 0.7)'" );
		expect( module ).toContain( "footerHeadingStyle: { weight: '500', size: '29px', transform: 'capitalize' }" );
		expect( module ).toContain( "chromeSize: '16px'" );
		expect( module ).toContain( "favicon: 'https://jayainc.example/wp-content/uploads/cropped-icon-32x32.png'" );
		expect( module ).toContain( "iconCss: 'https://jayainc.example/wp-content/themes/phlox-pro/css/auxin-icon.css?ver=5.17.20'" );
		expect( module ).toContain( "footerInk: 'rgba(255, 255, 255, 0.7)'" );
	} );

	it( 'bakes undefined for a chrome-less profile', () => {
		const design = profile.design!;
		const bare: SiteProfile = { ...profile, design: { ...design } };
		delete bare.design!.chrome;
		expect( renderChromeGenerated( bare ) ).toContain( 'export const chrome: ForgeChrome | undefined = undefined;' );
	} );
} );

describe( 'renderSectionsGenerated + renderDesignGenerated (125 facts)', () => {
	const design: ForgeDesign = {
		mode: 'rebuild',
		source: { url: 'https://jayainc.example', analyzedAt: '2026-11-06T00:00:00.000Z' },
		concept: 'test',
		palette: { roles: {}, unassigned: [], confidence: {} },
		typography: { display: 'rubik', body: 'rubik', bodyWeight: '300', scalePx: [ 16 ] },
		spacing: { scalePx: [ 8 ] },
		radii: { valuesPx: [] },
		shadows: { count: 0 },
		components: {},
		modes: { dark: 'auto', contrast: 'auto' },
		adaptation: { keep: [], replace: [], remix: [], licensingNote: '', attribution: [] },
	};
	const profile: SiteProfile = {
		url: 'https://jayainc.example/',
		name: 'Jaya',
		collections: [],
		design: {
			...design,
			sections: [
				{
					id: 'd5844b9',
					anchor: 'about',
					bgGradient: 'linear-gradient(180deg, #e5e5e5 0%, #ffffff 100%)',
					layout: 'split',
					bodyColumns: 2,
					marginTop: { desktop: '0px', tablet: '170px', mobile: '100px' },
					titleAlign: 'center',
					titleDivider: { width: '125px', height: '6px', background: '#ffcf0e', shadow: '0px 4px 8px 0px rgba(255,207,14,0.5)' },
					titleStyle: { size: '43px', weight: '500', lineHeight: '57px', letterSpacing: '-1.5px' },
					mediaShape: { width: '412px', height: '450px', background: 'linear-gradient(39deg, #ffcf0e 0%, #ffb70e 100%)' },
					mediaOffsets: [ { top: '-25%', left: '0px', shadow: '0px 3px 34px 0px rgba(0,0,0,0.16)' }, { top: '25%', right: '-110px' } ],
				},
				{
					id: 'fd63522',
					bgColor: '#f6f6f6',
					catalog: { title: 'fiori-digital-catalogue', source: 'https://jayainc.example/wp-content/uploads/catalog.pdf' },
				},
			],
		},
	};

	it( 'emits the split-hero + collage facts into sections.generated', () => {
		const module = renderSectionsGenerated( profile );
		expect( module ).toContain( "layout: 'split'" );
		expect( module ).toContain( 'bodyColumns: 2' );
		expect( module ).toContain( "marginTop: { desktop: '0px', tablet: '170px', mobile: '100px' }" );
		expect( module ).toContain( "export const bandSpacing: BandSpacing = 'template';" );
		expect( module ).toContain( "titleAlign: 'center'" );
		expect( module ).toContain( "titleDivider: { width: '125px', height: '6px', background: '#ffcf0e', shadow: '0px 4px 8px 0px rgba(255,207,14,0.5)' }" );
		expect( module ).toContain( "titleStyle: { size: '43px', weight: '500', lineHeight: '57px', letterSpacing: '-1.5px' }" );
		expect( module ).toContain( "mediaShape: { width: '412px', height: '450px', background: 'linear-gradient(39deg, #ffcf0e 0%, #ffb70e 100%)' }" );
		expect( module ).toContain( "mediaOffsets: [{ top: '-25%', left: '0px', shadow: '0px 3px 34px 0px rgba(0,0,0,0.16)' }, { top: '25%', right: '-110px' }]" );
		expect( module ).toContain( "catalog: { title: 'fiori-digital-catalogue', source: 'https://jayainc.example/wp-content/uploads/catalog.pdf' }" );
	} );

	it( 'emits the harvested webfont for Google Fonts loading (125 §2)', () => {
		const module = renderDesignGenerated( profile, { accent: '#0b5f4e' } );
		expect( module ).toContain( 'export const webFonts' );
		expect( module ).toContain( "family: 'rubik', weights: [ 300, 400, 500, 600, 700 ]" );
	} );

	it( 'skips system stacks in the webfont list', () => {
		const systemDesign: ForgeDesign = {
			...design,
			typography: { display: 'system-ui', body: 'arial', scalePx: [ 16 ] },
		};
		const bare: SiteProfile = { ...profile, design: { ...systemDesign, sections: [] } };
		const module = renderDesignGenerated( bare, { accent: '#0b5f4e' } );
		expect( module ).toContain( 'export const webFonts' );
		// The type declaration carries `family: string`; a VALUE emission
		// would carry a quoted family literal.
		expect( module ).not.toContain( "family: '" );
	} );
} );

describe( 'renderTokensDtcg (126 §dtcg — the DTCG 2025.10 interchange doc)', () => {
	const baseProfile: SiteProfile = {
		url: 'https://jayainc.example/',
		name: 'Jaya',
		collections: [],
		design: {
			mode: 'rebuild',
			source: { url: 'https://jayainc.example', analyzedAt: '2026-11-06T00:00:00.000Z' },
			concept: 'test',
			palette: { roles: {}, unassigned: [], confidence: {} },
			typography: { display: 'rubik', body: 'rubik', bodyWeight: '300', scalePx: [ 16, 13, 29, 48 ] },
			spacing: { scalePx: [ 4, 8, 16, 24, 48 ] },
			radii: { valuesPx: [ 4, 8 ] },
			shadows: { count: 0 },
			components: {},
			modes: { dark: 'auto', contrast: 'auto' },
			adaptation: { keep: [], replace: [], remix: [], licensingNote: '', attribution: [] },
		},
	};

	it( 'emits the 2025.10 schema with core→semantic groups and typed tokens', () => {
		const doc = JSON.parse( renderTokensDtcg( baseProfile, { accent: '#0b5f4e', 'accent-ink': '#ffffff', ink: '#1a1a1a', muted: '#6b6b6b', surface: '#ffffff', 'surface-alt': '#f5f5f4', line: '#e4e4e2', 'line-strong': '#767676' } ) );
		expect( doc.$schema ).toContain( 'designtokens.org' );
		expect( doc.core.color.$type ).toBe( 'color' );
		expect( doc.core.color.accent.$value ).toBe( '#0b5f4e' );
		expect( doc.core.font.$type ).toBe( 'fontFamily' );
		expect( doc.core.font.body.$value ).toBe( 'rubik' );
		expect( doc.core.space.$type ).toBe( 'dimension' );
		expect( doc.core.space.md.$value ).toEqual( { value: 16, unit: 'px' } );
		expect( doc.core.radius.$type ).toBe( 'dimension' );
		expect( doc.semantic.color.$type ).toBe( 'color' );
		// The typography composite aliases the core family (DTCG {ref}).
		expect( doc.semantic.typography.body.$type ).toBe( 'typography' );
		expect( doc.semantic.typography.body.$value.fontFamily ).toBe( '{core.font.body}' );
		expect( doc.semantic.typography.body.$value.fontSize ).toEqual( { value: 13, unit: 'px' } );
		expect( doc.semantic.typography.body.$value.fontWeight ).toBe( 300 );
	} );

	it( 'keeps non-hex token values CSS-only (the var stays authoritative)', () => {
		const doc = JSON.parse( renderTokensDtcg( baseProfile, { accent: 'rgb(11, 95, 78)', ink: '#1a1a1a' } ) );
		expect( doc.core.color.accent ).toBeUndefined();
		expect( doc.core.color.ink.$value ).toBe( '#1a1a1a' );
	} );
} );

describe( 'renderContentConfig', () => {
	it('emits env-driven site URL and both auth blocks', () => {
		const out = renderContentConfig(PROFILE);
		expect(out).toContain("import.meta.env.WP_SITE_URL ?? 'https://shop.example.com'");
		expect(out).toContain('const applicationAuth: WpAuth | undefined =');
		expect(out).toContain('const wcAuth: WpAuth | undefined =');
		expect(out).toContain("type: 'basic'");
		expect(out).toContain('export const collections = {');
		expect(out).toContain("replace(/\\/+$/, '')");
		expect(out).toContain('userAgent: import.meta.env.WP_USER_AGENT');
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
			"const siteUrl = 'OLD';",
			'// FORGE:collections-end',
		].join('\n');
		const out = rewriteContentConfig(source, PROFILE);
		expect(out).toContain('// Header comment mentioning FORGE:site in prose.');
		expect(out).toContain("'https://shop.example.com'");
		expect(out).toContain('// FORGE:collections-end');
		expect(out).not.toContain('OLD');
	});
});

describe('scaffold — agent stubs (Proposal 104 §4.4)', () => {
	it('emits AGENTS.md + .context/README.md by default', async () => {
		const root = await tempDir();
		const target = path.join(root, 'site');
		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog' });

		const agents = await readFile(path.join(target, 'AGENTS.md'), 'utf8');
		expect(agents).toContain('src/content.config.ts');
		expect(agents).toContain('nvoos.json');
		expect(agents).toContain('Storefront runtime contract');
		expect(agents).toContain('npm run build');

		const context = await readFile(path.join(target, '.context', 'README.md'), 'utf8');
		expect(context).toContain('customer context');
	} );

	it( 'omits both stubs with agents: false (--no-agents)', async () => {
		const root = await tempDir();
		const target = path.join(root, 'lean');
		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog', agents: false });
		await expect(stat(path.join(target, 'AGENTS.md'))).rejects.toThrow();
		await expect(stat(path.join(target, '.context'))).rejects.toThrow();
	} );

	it( 'collectProjectFiles ships the stubs (deploy + github_commit payload)', async () => {
		const root = await tempDir();
		const target = path.join(root, 'pushed');
		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog' });
		const files = await collectProjectFiles(target);
		for ( const stub of AGENT_STUB_FILES ) {
			expect( files[stub] ).toBeDefined();
		}
		expect( files['AGENTS.md'] ).toContain( 'nvoos-astro-forge' );
	} );
} );

describe( 'scaffold — preset surfaces (Proposal 104 §4.3)', () => {
	it( 'blog/storefront keep the search surface and flag it in the manifest', async () => {
		const root = await tempDir();
		const target = path.join(root, 'blog');
		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog', preset: 'storefront' });
		await expect(stat(path.join(target, 'src', 'pages', 'search.astro'))).resolves.toBeTruthy();
		const manifest = JSON.parse(await readFile(path.join(target, 'nvoos.json'), 'utf8'));
		expect(manifest.surfaces).toEqual({ search: true, feed: true, sitemap: true, ogImages: false });
		const pkg = JSON.parse(await readFile(path.join(target, 'package.json'), 'utf8'));
		expect(pkg.scripts.build).toContain('pagefind');
	} );

	it( 'minimal prunes the search page, the pagefind step, and flags the manifest', async () => {
		const root = await tempDir();
		const target = path.join(root, 'minimal');
		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog', preset: 'minimal' });
		await expect(stat(path.join(target, 'src', 'pages', 'search.astro'))).rejects.toThrow();
		const manifest = JSON.parse(await readFile(path.join(target, 'nvoos.json'), 'utf8'));
		expect(manifest.surfaces).toEqual({ search: false, feed: true, sitemap: true, ogImages: false });
		const pkg = JSON.parse(await readFile(path.join(target, 'package.json'), 'utf8'));
		expect(pkg.scripts.build).toBe('astro build && node scripts/postbuild.mjs');
	} );
} );

describe( 'scaffold — tokens-tailwind.css (Proposal 104 §4.7)', () => {
	it( 'survives scaffold byte-identical (no FORGE markers, no rewrites)', async () => {
		const root = await tempDir();
		const target = path.join(root, 'site');
		await scaffold({
			profile: { ...PROFILE, tokens: { accent: '#0b5f4e' } },
			targetDir: target,
			template: 'blog',
		} );
		const templateFile = await readFile(
			path.join(FORGE_ROOT, 'templates', 'blog', 'src', 'styles', 'tokens-tailwind.css'),
			'utf8'
		);
		const scaffolded = await readFile(path.join(target, 'src', 'styles', 'tokens-tailwind.css'), 'utf8');
		expect(scaffolded).toBe(templateFile);
		expect(scaffolded).not.toContain('FORGE:tokens'); // no rewrite markers inside
		expect(scaffolded).toContain('--color-forge-accent: var(--forge-accent);');
	} );
} );

describe( 'renderAstroConfig (Proposal 104 Q4)', () => {
	const TEMPLATE_CONFIG = [
		"import { defineConfig } from 'astro/config';",
		'// FORGE:adapter-import',
		"import manifest from './nvoos.json';",
		'',
		'export default defineConfig({',
		'	site,',
		'	// FORGE:render-config',
		'	image: {',
		'		domains: [],',
		'	},',
		'});',
	].join('\n');

	it( 'static removes both markers (the config is untouched)', () => {
		const out = renderAstroConfig(TEMPLATE_CONFIG, 'static');
		expect(out).not.toContain('FORGE');
		expect(out).not.toContain('@astrojs/node');
		expect(out).not.toContain("output: 'server'");
		expect(out).toContain("import { defineConfig } from 'astro/config';");
	} );

	it( 'ssr wires the adapter import + server output', () => {
		const out = renderAstroConfig(TEMPLATE_CONFIG, 'ssr');
		expect(out).toContain("import node from '@astrojs/node';");
		expect(out).toContain("\toutput: 'server',");
		expect(out).toContain("\tadapter: node({ mode: 'standalone' }),");
		expect(out).not.toContain('FORGE');
	} );

	it( 'fails loudly when the markers are missing', () => {
		expect(() => renderAstroConfig('export default defineConfig({});', 'static')).toThrow(/FORGE:adapter-import/);
	} );
} );

describe( 'scaffold — render variant (Proposal 104 Q4)', () => {
	it( 'ssr swaps the static product pages for the on-demand route and rewrites the config', async () => {
		const root = await tempDir();
		const target = path.join(root, 'ssr');
		const profile: SiteProfile = {
			...PROFILE,
			collections: [
				...PROFILE.collections.filter((c) => c.slug !== 'products'),
				{
					slug: 'products',
					restBase: 'wc/v3/products',
					fields: ['id', 'name'],
					kind: 'product',
					authMode: 'wc-consumer',
				},
			],
		};
		await scaffold({ profile, targetDir: target, template: 'blog', render: 'ssr' });

		await expect(stat(path.join(target, 'src', 'pages', 'products', '[...parts].astro'))).rejects.toThrow();
		await expect(stat(path.join(target, 'src', 'pages', 'products', '_ssr'))).rejects.toThrow();
		await expect(stat(path.join(target, 'src', 'pages', 'products', '[id]', '[...slug].astro'))).resolves.toBeTruthy();
		const config = await readFile(path.join(target, 'astro.config.mjs'), 'utf8');
		expect(config).toContain("import node from '@astrojs/node';");
		expect(config).toContain("output: 'server'");
		const pkg = JSON.parse(await readFile(path.join(target, 'package.json'), 'utf8'));
		expect(pkg.scripts.build).toContain('pagefind --site dist/client');
		// The static chrome stays build-time static — only the PDP is on-demand.
		const postsPage = await readFile(path.join(target, 'src', 'pages', 'posts', '[...parts].astro'), 'utf8');
		expect(postsPage).toContain('export const prerender = true;');
		const productRoute = await readFile(path.join(target, 'src', 'pages', 'products', '[id]', '[...slug].astro'), 'utf8');
		expect(productRoute).toContain('export const prerender = false;');
		const manifest = JSON.parse(await readFile(path.join(target, 'nvoos.json'), 'utf8'));
		expect(manifest.render).toBe('ssr');
		expect(manifest.collections.find((c: { slug: string }) => c.slug === 'products').authMode).toBe('wc-consumer');
	} );

	it( 'static keeps the static pages and prunes the inert _ssr dir', async () => {
		const root = await tempDir();
		const target = path.join(root, 'static');
		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog' });
		await expect(stat(path.join(target, 'src', 'pages', 'products', '[...parts].astro'))).resolves.toBeTruthy();
		await expect(stat(path.join(target, 'src', 'pages', 'products', '[id]'))).rejects.toThrow();
		await expect(stat(path.join(target, 'src', 'pages', 'products', '_ssr'))).rejects.toThrow();
		const config = await readFile(path.join(target, 'astro.config.mjs'), 'utf8');
		expect(config).not.toContain("output: 'server'");
		expect(config).not.toContain('@astrojs/node');
		const postsPage = await readFile(path.join(target, 'src', 'pages', 'posts', '[...parts].astro'), 'utf8');
		expect(postsPage).not.toContain('export const prerender = true;'); // static chrome untouched
		const manifest = JSON.parse(await readFile(path.join(target, 'nvoos.json'), 'utf8'));
		expect(manifest.render).toBe('static');
	} );

	it( 'ssr with a blog-only profile prunes the whole products tree', async () => {
		const root = await tempDir();
		const target = path.join(root, 'blog-ssr');
		await scaffold({
			profile: { ...PROFILE, collections: PROFILE.collections.filter((c) => c.slug !== 'products') },
			targetDir: target,
			template: 'blog',
			render: 'ssr',
		} );
		await expect(stat(path.join(target, 'src', 'pages', 'products'))).rejects.toThrow();
	} );
} );

describe( 'scaffold — ogImages surface (Proposal 104 §4.8 / Q5)', () => {
	it( 'off (default): no OG endpoint, no injected deps, no og:image flag', async () => {
		const root = await tempDir();
		const target = path.join(root, 'plain');
		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog' });
		await expect(stat(path.join(target, 'src', 'pages', 'og'))).rejects.toThrow();
		await expect(stat(path.join(target, 'src', 'pages', '_og'))).rejects.toThrow();
		const pkg = JSON.parse(await readFile(path.join(target, 'package.json'), 'utf8'));
		expect(pkg.devDependencies?.satori).toBeUndefined();
		expect(pkg.devDependencies?.['@resvg/resvg-js']).toBeUndefined();
		expect(pkg.devDependencies?.['@fontsource/inter']).toBeUndefined();
		const manifest = JSON.parse(await readFile(path.join(target, 'nvoos.json'), 'utf8'));
		expect(manifest.surfaces.ogImages).toBe(false);
	} );

	it( 'on: ships the endpoint, bakes the tokens, injects the devDeps, flags the manifest', async () => {
		const root = await tempDir();
		const target = path.join(root, 'og');
		await scaffold({
			profile: { ...PROFILE, tokens: { accent: '#0b5f4e' } },
			targetDir: target,
			template: 'blog',
			surfaces: { ogImages: true },
		} );
		await expect(stat(path.join(target, 'src', 'pages', '_og'))).rejects.toThrow();
		const endpoint = await readFile(path.join(target, 'src', 'pages', 'og', '[slug].png.ts'), 'utf8');
		expect(endpoint).toContain("const OG_ACCENT = \"#0b5f4e\";");
		expect(endpoint).toContain("const OG_INK = \"#1a1a1a\";");
		expect(endpoint).toContain("const OG_SURFACE = \"#ffffff\";");
		expect(endpoint).not.toContain('FORGE:og-tokens');
		const pkg = JSON.parse(await readFile(path.join(target, 'package.json'), 'utf8'));
		expect(pkg.devDependencies?.satori).toBe('^0.63.2');
		expect(pkg.devDependencies?.['@resvg/resvg-js']).toBe('^2.6.2');
		expect(pkg.devDependencies?.['@fontsource/inter']).toBe('^5.3.0');
		const manifest = JSON.parse(await readFile(path.join(target, 'nvoos.json'), 'utf8'));
		expect(manifest.surfaces.ogImages).toBe(true);
	} );

	it( 'fails loudly when the OG token markers are missing', () => {
		expect(() => rewriteOgTokens('const x = 1;', {})).toThrow(/FORGE:og-tokens/);
	} );
} );

describe( 'scaffold — feed/sitemap surface pruning (104 follow-up)', () => {
	it( 'prunes the endpoints and flags the manifest when toggled off', async () => {
		const root = await tempDir();
		const target = path.join(root, 'lean-seo');
		await scaffold({
			profile: PROFILE,
			targetDir: target,
			template: 'blog',
			surfaces: { feed: false, sitemap: false },
		} );
		await expect(stat(path.join(target, 'src', 'pages', 'feed.xml.ts'))).rejects.toThrow();
		await expect(stat(path.join(target, 'src', 'pages', 'sitemap.xml.ts'))).rejects.toThrow();
		await expect(stat(path.join(target, 'src', 'pages', 'robots.txt.ts'))).resolves.toBeTruthy();
		// robots.txt.ts gates its sitemap pointer on the manifest flag.
		const robots = await readFile(path.join(target, 'src', 'pages', 'robots.txt.ts'), 'utf8');
		expect(robots).toContain("manifest.surfaces?.sitemap !== false");
		const manifest = JSON.parse(await readFile(path.join(target, 'nvoos.json'), 'utf8'));
		expect(manifest.surfaces).toEqual({ search: true, feed: false, sitemap: false, ogImages: false });
	} );
} );

describe( 'scaffold — theme policy (Proposal 105 §4)', () => {
	it( 'bakes the default auto policy and ships the policy module verbatim', async () => {
		const root = await tempDir();
		const target = path.join(root, 'policy-auto');
		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog' });

		const generated = await readFile(path.join(target, 'src', 'lib', 'theme', 'policy.generated.ts'), 'utf8');
		expect(generated).toContain("policy: 'auto',");
		expect(generated).toContain("contrast: 'auto',");
		expect(generated).toContain("defaultTheme: 'dark',");
		// The policy module is the canonical copy — byte-identical to the template.
		const templatePolicy = await readFile(
			path.join(FORGE_ROOT, 'templates', 'blog', 'src', 'lib', 'theme', 'policy.ts'),
			'utf8'
		);
		const scaffolded = await readFile(path.join(target, 'src', 'lib', 'theme', 'policy.ts'), 'utf8');
		expect(scaffolded).toBe(templatePolicy);
	} );

	it( 'bakes the chosen policy (--theme-policy dark) with a scaffold timestamp', async () => {
		const root = await tempDir();
		const target = path.join(root, 'policy-dark');
		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog', themePolicy: 'dark' });

		const generated = await readFile(path.join(target, 'src', 'lib', 'theme', 'policy.generated.ts'), 'utf8');
		expect(generated).toContain("policy: 'dark',");
		expect(generated).toMatch(/updatedAt: '\d{4}-\d{2}-\d{2}T/);
		// The layout boot + toggle read the policy node.
		const base = await readFile(path.join(target, 'src', 'layouts', 'Base.astro'), 'utf8');
		expect(base).toContain('forge-theme-policy');
		expect(base).toContain('data-theme-policy');
		await expect(stat(path.join(target, 'src', 'components', 'ThemeToggle.astro'))).resolves.toBeTruthy();
	} );

	it( 'falls back to auto with a warning for an invalid policy', async () => {
		const root = await tempDir();
		const target = path.join(root, 'policy-bad');
		const result = await scaffold({ profile: PROFILE, targetDir: target, template: 'blog', themePolicy: 'brand:Bad_Slug!' });
		expect(result.warnings.join('\n')).toContain('Unknown theme policy');
		const generated = await readFile(path.join(target, 'src', 'lib', 'theme', 'policy.generated.ts'), 'utf8');
		expect(generated).toContain("policy: 'auto',");
	} );

	it( 'renders the generated stub in repo style (tabs + single quotes)', () => {
		const out = renderPolicyGenerated('brand:lenas-chair', '2026-11-03T09:00:00Z');
		expect(out).toContain("\tpolicy: 'brand:lenas-chair',");
		expect(out).toContain("\tupdatedAt: '2026-11-03T09:00:00Z',");
		expect(out).toContain("import type { PolicyDoc } from './policy.ts';");
		expect(out).toContain('\t$extensions: {},');
	} );
} );

describe( 'scaffold — hybrid local content (Proposal 106 P2)', () => {
	it( 'wires the local MDX glob under the posts/pages loaders (WP wins, local fills)', async () => {
		const root = await tempDir();
		const target = path.join(root, 'hybrid');
		await scaffold({ profile: PROFILE, targetDir: target, template: 'blog' });

		const config = await readFile(path.join(target, 'src', 'content.config.ts'), 'utf8');
		expect(config).toContain("local: { pattern: '**/*.mdx', base: './src/content/posts' }");
		// The demo seed ships with every scaffold.
		const example = await readFile(path.join(target, 'src', 'content', 'posts', 'hello-world.mdx'), 'utf8');
		expect(example).toContain('Hello from the forge');
		await expect(stat(path.join(target, 'src', 'content', 'pages'))).resolves.toBeTruthy();
	} );

	it( 'keeps non-hybrid collections on the plain WP loader', () => {
		const out = renderContentConfig(PROFILE);
		expect(out).toContain('collection: postsCollection, auth: applicationAuth, local: { pattern:');
		// The products collection (no local source) stays plain.
		expect(out).toContain('products: defineCollection({ loader: createWpLoader({ site, collection: productsCollection, auth: applicationAuth, userAgent: import.meta.env.WP_USER_AGENT }) })');
	} );
} );

describe( 'scaffold — design rebuild (Proposal 114)', () => {
	it( 'vendors the semantic classifier + contrast math alongside the loader libs', () => {
		expect( FORGE_LIB_FILES ).toContain( 'semantic.ts' );
		expect( FORGE_LIB_FILES ).toContain( 'contrast.ts' );
	} );

	it( 'derives the token theme from the design block (defaults ← design ← explicit overrides)', () => {
		const merged = mergeTokens( DESIGN, { accent: '#123456' } );
		// Explicit operator overrides win.
		expect( merged.accent ).toBe( '#123456' );
		// Design-derived values land everywhere else.
		expect( merged.ink ).toBe( '#444444' );
		expect( merged[ 'font-display' ] ).toContain( 'Rubik' );
		expect( merged[ 'line-strong' ] ).toBe( '#767676' );
		expect( merged[ 'scale-h1' ] ).toBe( '40px' );
		// Untouched defaults survive.
		expect( merged.measure ).toBe( '75rem' );
	} );

	it( 'applies the design through the scaffold and writes the generated design module', async () => {
		const root = await tempDir();
		const target = path.join( root, 'rebuilt' );
		const result = await scaffold( { profile: { ...PROFILE, design: DESIGN }, targetDir: target, template: 'blog' } );

		// The token span carries the design-derived values.
		const css = await readFile( path.join( target, 'src', 'styles', 'global.css' ), 'utf8' );
		expect( css ).toContain( '--forge-accent: #6ec1e4;' );
		expect( css ).toContain( '--forge-ink: #444444;' );
		expect( css ).toContain( "--forge-font-display: 'Rubik', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;" );

		// The resolved design module ships with tokens + provenance.
		const generated = await readFile( path.join( target, 'src', 'lib', 'design.generated.ts' ), 'utf8' );
		expect( generated ).toContain( "'accent': '#6ec1e4'" );
		expect( generated ).toContain( "source: { url: 'https://shop.example.com', analyzedAt: '2026-11-05T00:00:00.000Z' }" );
		expect( generated ).toContain( 'adaptation: {' );

		// The manifest records the design block + the warn-mode audit gate.
		const manifest = JSON.parse( await readFile( path.join( target, 'nvoos.json' ), 'utf8' ) );
		expect( manifest.design.palette.roles.accent ).toBe( '#6ec1e4' );
		expect( manifest.designAudit ).toBe( 'warn' );

		// The build-time audit endpoint ships.
		await expect( stat( path.join( target, 'src', 'pages', 'design-audit.json.ts' ) ) ).resolves.toBeTruthy();

		// The jayainc-class failing text color would have produced warnings;
		// #444444 passes every pair (no design-driven warnings here).
		expect( result.warnings ).toEqual( [] );
	} );

	it( 'flips the manifest to strict contrast and suggests pool fixes for failing pairs', async () => {
		const root = await tempDir();
		const target = path.join( root, 'strict' );
		const failing: ForgeDesign = { ...DESIGN, palette: { ...DESIGN.palette, roles: { ...DESIGN.palette.roles, text: '#7a7a7a' } } };
		const result = await scaffold( {
			profile: { ...PROFILE, design: failing },
			targetDir: target,
			template: 'blog',
			strictContrast: true,
		} );
		const manifest = JSON.parse( await readFile( path.join( target, 'nvoos.json' ), 'utf8' ) );
		expect( manifest.designAudit ).toBe( 'strict' );
		// The gate suggests the source's own pool color as the one-token fix.
		expect( result.warnings.some( ( warning ) => warning.includes( 'unassigned pool' ) ) ).toBe( true );
		expect( result.warnings.some( ( warning ) => warning.includes( '4.5:1' ) ) ).toBe( true );
	} );

	it( 'renders the generated design module deterministically', () => {
		const merged = mergeTokens( DESIGN );
		const once = renderDesignGenerated( { ...PROFILE, design: DESIGN }, merged );
		const twice = renderDesignGenerated( { ...PROFILE, design: DESIGN }, merged );
		expect( once ).toBe( twice );
		expect( once ).toContain( 'export const resolvedDesign: ResolvedDesign = {' );
	} );

	it( 'renders the generated section-theme module from the design sections (117 §2)', () => {
		const design: ForgeDesign = {
			...DESIGN,
			sections: [
				{ id: 'a4ad586', bgImage: 'https://shop.example.com/hero.jpg', bgColor: 'rgba(8, 26, 67, 0.9)' },
				{ id: '5e78df1', bgGradient: 'linear-gradient(180deg, #ffffff 6%, rgb(4, 19, 43) 100%)', measure: '1140px', paddingX: '30px' },
				{ id: '48ace0b', bgColor: '#f6f6f6' },
			],
		};
		const out = renderSectionsGenerated( { ...PROFILE, design } );
		expect( out ).toContain( "id: 'a4ad586', theme: 'hero', bgImage: 'https://shop.example.com/hero.jpg'" );
		expect( out ).toContain( "id: '5e78df1', theme: 'dark', bg: 'linear-gradient(180deg, #ffffff 6%, rgb(4, 19, 43) 100%)', measure: '1140px', paddingX: '30px'" );
		expect( out ).toContain( "id: '48ace0b', theme: 'light', bg: '#f6f6f6'" );
	} );

	it( 'bakes the section pull facts (124 §4)', () => {
		const design: ForgeDesign = {
			...DESIGN,
			sections: [ { id: '059421c', bgColor: '#081a43', marginTop: '-120px', zIndex: '2' } ],
		};
		const out = renderSectionsGenerated( { ...PROFILE, design } );
		expect( out ).toContain( "marginTop: '-120px', zIndex: '2'" );
	} );

	it( 'bakes the column responsive facts (124 §6)', () => {
		const design: ForgeDesign = {
			...DESIGN,
			sections: [ { id: 'fcc5e07', bgColor: '#e5e5e5', columns: [ { width: 50 }, { width: 50, reverseMobile: true } ] } ],
		};
		const out = renderSectionsGenerated( { ...PROFILE, design } );
		expect( out ).toContain( 'columns: [{ width: 50 }, { width: 50, reverseMobile: true }]' );
	} );

	it( 'warns with a design block but stays quiet on clean designs', () => {
		expect( tokenWarnings( undefined, DESIGN ) ).toEqual( [] );
		expect( tokenWarnings( { ink: 'red' }, DESIGN ).length ).toBeGreaterThan( 0 );
	} );
} );

describe( 'scaffold — forms bridge block (Proposal 114 §3.9)', () => {
	it( 'emits the forms block in the manifest with the default endpoint', async () => {
		const root = await tempDir();
		const target = path.join( root, 'forms' );
		await scaffold({ profile: { ...PROFILE, forms: { origin: 'https://static.example.com', searchForms: [ 'find' ] } }, targetDir: target, template: 'blog' } );
		const manifest = JSON.parse( await readFile( path.join( target, 'nvoos.json' ), 'utf8' ) );
		expect( manifest.forms ).toEqual( {
			endpoint: 'https://shop.example.com/wp-json/nvoos-forge/v1',
			origin: 'https://static.example.com',
			searchForms: [ 'find' ],
		} );
	} );

	it( 'emits the forms env example and omits the block when unconfigured', async () => {
			const root = await tempDir();
			const target = path.join( root, 'forms-env' );
			await scaffold({ profile: PROFILE, targetDir: target, template: 'blog' } );
			const envExample = await readFile( path.join( target, '.env.example' ), 'utf8' );
			expect( envExample ).toContain( 'NVOOS_FORGE_FORMS_ENDPOINT=' );
			expect( envExample ).toContain( 'NVOOS_FORGE_FORMS_ORIGIN=' );
			const manifest = JSON.parse( await readFile( path.join( target, 'nvoos.json' ), 'utf8' ) );
			expect( manifest.forms ).toBeUndefined();
		} );
	} );

	describe( 'scaffold — wireframe pages (Proposal 115 §3)', () => {
		it( 'emits the wireframe module + one page per wireframe and skips reserved slugs', async () => {
			const root = await tempDir();
			const target = path.join( root, 'wf' );
			const profile: SiteProfile = {
				...PROFILE,
				wireframes: {
					about: {
						title: 'About',
						slug: 'about',
						source: 'https://shop.example.com/about/',
						extractedAt: '2026-11-06T00:00:00.000Z',
						status: 'placeholder',
						sections: [],
					},
					posts: {
						title: 'Posts',
						slug: 'posts',
						source: 'https://shop.example.com/posts/',
						extractedAt: '2026-11-06T00:00:00.000Z',
						status: 'captured',
						sections: [],
					},
				},
			};
			const result = await scaffold({ profile, targetDir: target, template: 'blog' } );

			await expect( stat( path.join( target, 'src', 'lib', 'wireframes.generated.ts' ) ) ).resolves.toBeTruthy();
			await expect( stat( path.join( target, 'src', 'pages', 'about.astro' ) ) ).resolves.toBeTruthy();
			// Reserved route slug — never shadowed.
			await expect( stat( path.join( target, 'src', 'pages', 'posts.astro' ) ) ).rejects.toThrow();
			expect( result.wireframePages ).toEqual( [ 'src/pages/about.astro' ] );
			expect( result.warnings.some( ( w ) => w.includes( 'posts' ) ) ).toBe( true );

			const page = await readFile( path.join( target, 'src', 'pages', 'about.astro' ), 'utf8' );
			expect( page ).toContain( 'DesignRenderer wireframeSections' );
			expect( page ).toContain( "wireframes['about']" );
			const module = await readFile( path.join( target, 'src', 'lib', 'wireframes.generated.ts' ), 'utf8' );
			expect( module ).toContain( "status: 'captured' | 'placeholder'" );
			expect( module ).toContain( '"slug": "about"' );
		} );

		it( 'leaves no wireframe routes when the profile has none', async () => {
			const root = await tempDir();
			const target = path.join( root, 'plain' );
			const result = await scaffold({ profile: PROFILE, targetDir: target, template: 'blog' } );
			await expect( stat( path.join( target, 'src', 'lib', 'wireframes.generated.ts' ) ) ).rejects.toThrow();
			expect( result.wireframePages ).toBeUndefined();
		} );
	} );

	describe( 'scaffold — learned vocabulary (Proposal 116 §3)', () => {
		it( 'bakes the profile vocabulary into vocabulary.generated.ts', async () => {
			const root = await tempDir();
			const target = path.join( root, 'vocab' );
			await scaffold({
				profile: {
					...PROFILE,
					vocabulary: {
						widgetRoles: { mystery_widget: 'feature' },
						blockRoles: { columns: 'container' },
						unknownWidgets: [ 'mystery_widget' ],
						unknownBlocks: [],
						analyzedAt: '2026-11-06T00:00:00.000Z',
					},
				},
				targetDir: target,
				template: 'blog',
			} );
			const module = await readFile( path.join( target, 'src', 'lib', 'vocabulary.generated.ts' ), 'utf8' );
			expect( module ).toContain( '"mystery_widget":"feature"' );
			expect( module ).toContain( '"columns":"container"' );
			expect( module ).toContain( 'as Record<string, WidgetRole>' );
		} );

		it( 'emits an empty vocabulary by default (the seeded defaults apply)', async () => {
			const root = await tempDir();
			const target = path.join( root, 'vocab-empty' );
			await scaffold({ profile: PROFILE, targetDir: target, template: 'blog' } );
			const module = await readFile( path.join( target, 'src', 'lib', 'vocabulary.generated.ts' ), 'utf8' );
			expect( module ).toContain( 'widgetRoles: {}' );
			expect( module ).toContain( 'blockRoles: {}' );
		} );
	} );
