// ---------------------------------------------------------------------------
// design-import-modes.test.ts — Proposal 098 Phase 1–4 gates: WordPress +
// Astro-theme source modes, the re-extraction diff, the adaptation plan, and
// theme emission. Network-free: every fetch test injects fetch + DNS.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { analyzeDesignLanguage } from '../../src/lib/design-import/analyzer';
import { compareDesignLanguages } from '../../src/lib/design-import/compare';
import { fetchAstroThemeAssets, parseGitHubUrl } from '../../src/lib/design-import/modes/astro-theme';
import { extractThemeSlug, fetchWpThemeAssets, wpThemeJsonFamilies, wpThemeJsonRoles } from '../../src/lib/design-import/modes/wp';
import { buildAdaptationPlan } from '../../src/lib/design-import/plan';
import { emitTheme, themeNameFromUrl } from '../../src/lib/design-import/theme';
import type { DesignAnalysisResult, DesignLanguage } from '../../src/lib/design-import/types';

const PUBLIC_IP = '93.184.216.34';
const publicLookup = (async () => [{ address: PUBLIC_IP, family: 4 }]) as unknown as typeof import('node:dns/promises').lookup;

function mkLanguage(accent: string, concept = 'warm accent over light neutrals palette, serif display / sans body type, airy spacing rhythm.'): DesignLanguage {
	return {
		concept,
		palette: {
			roles: {
				accent: { value: accent, confidence: 'high', source: 'css:--color-accent' },
				surface: { value: '#faf6f1', confidence: 'high', source: 'css:--color-background' },
				text: { value: '#2b2b2b', confidence: 'high', source: 'css:--color-text' },
				'accent-ink': { value: '#ffffff', confidence: 'low', source: 'derived:luminance' },
			},
			unassigned: ['#0a0a0a'],
		},
		typography: { families: [{ family: 'Playfair Display', weight: 2 }], scalePx: [16, 40] },
		spacing: { scalePx: [8, 16] },
		radii: { valuesPx: [6] },
		shadows: { count: 1, samples: ['0 2px 8px rgba(0,0,0,0.08)'] },
		components: { vocabulary: { card: 2, button: 1 } },
		tokensDraft: { semantic: { accent } },
		adaptationBrief: {
			keep: [],
			replace: [],
			remix: [],
			licensingNote: 'Inspired-by analysis.',
			attribution: ['Source: https://example.com/'],
		},
	};
}

function mkResult(): DesignAnalysisResult {
	return { source: { url: 'https://example.com/', mode: 'generic', fetchedAt: 'now' }, designLanguage: mkLanguage('#0f6b4f') };
}

const WP_THEME_JSON = JSON.stringify({
	settings: {
		color: {
			palette: [
				{ slug: 'primary', color: '#0f6b4f' },
				{ slug: 'background', color: '#ffffff' },
				{ slug: 'foreground', color: '#111111' },
			],
		},
		typography: { fontFamilies: [{ fontFamily: 'Newsreader', slug: 'heading' }] },
	},
});

describe('wordpress mode', () => {
	it('derives the theme slug from wp-content links', () => {
		expect(extractThemeSlug('<link rel="stylesheet" href="/wp-content/themes/twentytwentyfive/style.css">')).toBe('twentytwentyfive');
		expect(extractThemeSlug('<html>plain</html>')).toBeNull();
	});

	it('fetches theme.json and maps presets to high-confidence roles', async () => {
		const requested: string[] = [];
		const fetchImpl = async (input: string | URL | Request) => {
			const url = String(input);
			requested.push(url);
			if (url.endsWith('/theme.json')) {
				return new Response(WP_THEME_JSON, { status: 200, headers: { 'content-type': 'application/json' } });
			}
			return new Response('', { status: 404 });
		};
		const html = '<link href="/wp-content/themes/twentytwentyfive/style.css">';
		const assets = await fetchWpThemeAssets(html, 'https://example.com/', { fetchImpl, lookupImpl: publicLookup });
		expect(assets.themeSlug).toBe('twentytwentyfive');
		expect(assets.themeJson).not.toBeNull();
		expect(requested[0]).toBe('https://example.com/wp-content/themes/twentytwentyfive/theme.json');
		expect(wpThemeJsonRoles(assets.themeJson!)).toEqual([
			{ role: 'accent', value: '#0f6b4f', source: 'theme.json:primary' },
			{ role: 'surface', value: '#ffffff', source: 'theme.json:background' },
			{ role: 'text', value: '#111111', source: 'theme.json:foreground' },
		]);
		expect(wpThemeJsonFamilies(assets.themeJson!)).toEqual(['Newsreader']);
	});

	it('degrades when theme.json is missing or no theme slug exists', async () => {
		const fetchImpl = async () => new Response('', { status: 404 });
		const missing = await fetchWpThemeAssets('<link href="/wp-content/themes/x/style.css">', 'https://example.com/', {
			fetchImpl,
			lookupImpl: publicLookup,
		});
		expect(missing.themeJson).toBeNull();
		expect(missing.themeSlug).toBe('x');

		const noSlug = await fetchWpThemeAssets('<html></html>', 'https://example.com/', { fetchImpl, lookupImpl: publicLookup });
		expect(noSlug).toEqual({ themeJson: null, themeSlug: null });
	});

	it('analyzer merges theme.json roles and families', () => {
		const result = analyzeDesignLanguage(
			'<html><body><div class="card">Card</div></body></html>',
			[],
			{ url: 'https://example.com/', mode: 'wordpress', fetchedAt: 'now' },
			{
				themeJsonRoles: wpThemeJsonRoles(JSON.parse(WP_THEME_JSON)),
				themeJsonFamilies: wpThemeJsonFamilies(JSON.parse(WP_THEME_JSON)),
			}
		);
		expect(result.designLanguage.palette.roles.accent).toMatchObject({ value: '#0f6b4f', confidence: 'high' });
		expect(result.designLanguage.typography.families[0]?.family).toBe('Newsreader');
	});
});

describe('astro-theme mode', () => {
	it('parses github.com URLs', () => {
		expect(parseGitHubUrl('https://github.com/withastro/astro-theme/tree/main')).toEqual({
			owner: 'withastro',
			repo: 'astro-theme',
			ref: 'main',
		});
		expect(parseGitHubUrl('https://github.com/withastro/astro-theme')).toEqual({
			owner: 'withastro',
			repo: 'astro-theme',
			ref: null,
		});
		expect(parseGitHubUrl('https://example.com/')).toBeNull();
	});

	it('reads trees + token/css/LICENSE files through the GitHub API', async () => {
		const fetchImpl = async (input: string | URL | Request) => {
			const url = String(input);
			if (url.includes('/repos/o/r') && !url.includes('trees')) {
				return new Response('{"default_branch":"main"}', { status: 200, headers: { 'content-type': 'application/json' } });
			}
			if (url.includes('/trees/')) {
				const tree = {
					tree: [
						{ path: 'src/tokens/theme.json', type: 'blob' },
						{ path: 'src/styles/global.css', type: 'blob' },
						{ path: 'LICENSE', type: 'blob' },
					],
				};
				return new Response(JSON.stringify(tree), { status: 200, headers: { 'content-type': 'application/json' } });
			}
			if (url.endsWith('/src/styles/global.css')) {
				return new Response(':root{--color-accent:#0f6b4f}', { status: 200, headers: { 'content-type': 'text/css' } });
			}
			if (url.endsWith('/src/tokens/theme.json')) {
				return new Response('{"semantic":{"accent":{"$value":"#0f6b4f"}}}', { status: 200, headers: { 'content-type': 'application/json' } });
			}
			if (url.endsWith('/LICENSE')) {
				return new Response('MIT License', { status: 200, headers: { 'content-type': 'text/plain' } });
			}
			return new Response('', { status: 404 });
		};
		const assets = await fetchAstroThemeAssets('https://github.com/o/r', { fetchImpl, lookupImpl: publicLookup });
		expect(assets.ref).toBe('main');
		expect(assets.stylesheets).toEqual([':root{--color-accent:#0f6b4f}']);
		expect(assets.tokenDocuments).toHaveLength(1);
		expect(assets.license).toContain('MIT');
	});

	it('analyzer extracts roles from token documents', () => {
		const result = analyzeDesignLanguage(
			'<html></html>',
			[],
			{ url: 'https://github.com/o/r', mode: 'astro-theme', fetchedAt: 'now' },
			{ tokenDocuments: [{ path: 'src/tokens/theme.json', json: { semantic: { accent: { $value: '#0f6b4f' } } } }] }
		);
		expect(result.designLanguage.palette.roles.accent).toMatchObject({
			value: '#0f6b4f',
			confidence: 'high',
			source: 'tokens:src/tokens/theme.json#semantic.accent',
		});
	});
});

describe('compare (Phase 2.2)', () => {
	it('diffs two snapshots', () => {
		const before = mkLanguage('#0f6b4f');
		const after = mkLanguage('#b0413e', 'cool accent over dark neutrals palette, sans display / sans body type, compact spacing rhythm.');
		const diff = compareDesignLanguages(before, after);
		expect(diff.rolesChanged).toEqual([{ role: 'accent', before: '#0f6b4f', after: '#b0413e' }]);
		expect(diff.conceptChanged).not.toBeNull();
		expect(diff.summary).toContain('role change');
	});

	it('reports no changes for identical snapshots', () => {
		const diff = compareDesignLanguages(mkLanguage('#0f6b4f'), mkLanguage('#0f6b4f'));
		expect(diff.summary).toBe('no changes');
		expect(diff.rolesChanged).toHaveLength(0);
	});
});

describe('adaptation plan (Phase 3)', () => {
	it('maps roles onto storefront content slots', () => {
		const plan = buildAdaptationPlan(mkResult(), 'storefront');
		expect(plan.provider).toBe('deterministic');
		expect(plan.paletteMapping).toContainEqual({ role: 'accent', value: '#0f6b4f', slot: expect.any(String) });
		expect(plan.steps.length).toBeGreaterThan(0);
		expect(plan.brandDirection).toContain('palette');
	});

	it('varies slots per content hint', () => {
		const blog = buildAdaptationPlan(mkResult(), 'blog');
		expect(blog.paletteMapping[0]?.slot).not.toBe(buildAdaptationPlan(mkResult(), 'marketing').paletteMapping[0]?.slot);
	});
});

describe('theme emission (Phase 4)', () => {
	it('derives a valid theme name from the host', () => {
		expect(themeNameFromUrl('https://www.example.com/')).toBe('imported-example-com');
		expect(themeNameFromUrl('not a url')).toBe('imported-site');
	});

	it('emits a DTCG document, descriptor, audit, and attribution', () => {
		const emitted = emitTheme(mkResult());
		expect(emitted.name).toMatch(/^imported-[a-z0-9-]+$/);
		expect((emitted.dtcgDocument.groups as { semantic: Record<string, string> }).semantic.accent).toBe('#0f6b4f');
		expect(emitted.themeDescriptor.tokens.accent).toBe('#0f6b4f');
		expect(emitted.audit.pairs.length).toBeGreaterThan(0);
		expect(emitted.audit.passed).toBe(true);
		expect(emitted.attribution.join(' ')).toContain('https://example.com/');
		// Low-confidence derived values stay out of the emitted theme.
		expect(emitted.themeDescriptor.tokens['accent-ink']).toBeUndefined();
	});
});
