// ---------------------------------------------------------------------------
// app-parity.test.ts — the cross-app contract gate (forge ↔ velocity).
//
// The two Astro products share a CONTRACT but not code: `examples/nvoos-astro-forge`
// GENERATES sites, `examples/nvoos-astro-velocity` is a hand-built storefront.
// Both compose the agent-facing `llms.txt` and both declare a `data-section`
// vocabulary, so those two surfaces must not drift — and neither app's own
// suite can see the other tree, so drift is invisible without this file.
//
// This mirrors the base+pro / CG-Pro discipline in this repo (and
// `bin/check-chat-parity.php`): compare the implementations directly and make
// every difference DELIBERATE and written down.
//
// It imports the velocity modules by relative path on purpose. Both are
// dependency-free (no imports at all), so this runs with the forge's install
// alone — no second `npm ci`.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import { SECTION_KINDS as FORGE_KINDS } from '../lib/types';
import { composeLlmsTxt as forgeCompose } from '../lib/llms';
import { SECTION_KINDS as VELOCITY_KINDS } from '../../nvoos-astro-velocity/src/lib/content/section-kinds';
import { composeLlmsTxt as velocityCompose } from '../../nvoos-astro-velocity/src/lib/llms';

/**
 * The ONE declared behavioural difference between the two composers: the
 * default link target. The forge always emits markdown links (it generates
 * those routes); velocity defaults to HTML because only products/pages/posts
 * have a `.md` route there. Everything else must be byte-identical.
 */
const ORIGIN = 'https://example.com';

const BASE_INPUT = {
	name: 'Acme',
	summary: 'A test site.',
	origin: ORIGIN,
	pages: [{ path: '/about/', title: 'About', note: 'Who we are' }],
	products: [{ path: '/p/1/', title: 'Sofa' }],
	posts: [{ path: '/blog/1/', title: 'Hello' }],
	optional: [{ path: '/contact/', title: 'Contact' }],
	extra: 'Extra prose.',
};

/** Fixtures that exercise every formatting branch both implementations share. */
const FIXTURES: Array<{ name: string; input: typeof BASE_INPUT }> = [
	{ name: 'all sections', input: BASE_INPUT },
	{
		name: 'no optional section',
		input: { ...BASE_INPUT, optional: [] },
	},
	{
		name: 'entry order does not affect output (sorted by path)',
		input: {
			...BASE_INPUT,
			pages: [
				{ path: '/z/', title: 'Z' },
				{ path: '/a/', title: 'A' },
				{ path: '/m/', title: 'M' },
			],
		},
	},
	{
		name: 'long note is word-bounded and clipped',
		input: {
			...BASE_INPUT,
			pages: [
				{
					path: '/long/',
					title: 'Long',
					note: `${'word '.repeat(40).trim()} end`,
				},
			],
		},
	},
	{
		name: 'brackets are stripped from titles',
		input: {
			...BASE_INPUT,
			pages: [{ path: '/b/', title: 'A [bracketed] title' }],
		},
	},
	{
		name: 'blank title entries are dropped',
		input: {
			...BASE_INPUT,
			pages: [
				{ path: '/ok/', title: 'Kept' },
				{ path: '/blank/', title: '   ' },
			],
		},
	},
];

describe('llms.txt parity (forge ↔ velocity)', () => {
	for (const { name, input } of FIXTURES) {
		it(name, () => {
			const forge = forgeCompose(input as never);
			const velocity = velocityCompose({ ...input, variant: 'markdown' } as never);
			expect(velocity).toBe(forge);
		});
	}

	it('the default variant differs ONLY in link targets', () => {
		const html = velocityCompose(BASE_INPUT as never);
		const markdown = velocityCompose({ ...BASE_INPUT, variant: 'markdown' } as never);

		// The declared difference: default links pages, the markdown variant
		// links `<path>/index.md`. Normalise BOTH link forms and they must be
		// identical — so a future format change to one variant cannot hide here.
		const normalise = (doc: string) => doc.replace(/\]\([^)]*\)/g, '](LINK)');
		expect(normalise(html)).toBe(normalise(markdown));

		expect(html).not.toMatch(/\/index\.md\)/);
		expect(markdown).toMatch(/\/index\.md\)/);
	});
});

/**
 * The section vocabulary. Each app declares its own list — deliberately, since
 * the forge does not generate faq/tabs/product-grid/promotion bands and
 * velocity does not generate cta/logo-cloud/form bands. The relation must be
 * exactly: each list = (shared) ∪ (its own declared extras).
 *
 * Adding a kind to EITHER app therefore fails this test until it is declared
 * here — which is the point: a new kind is a cross-product decision, not a
 * local one (the `stats` vs `stat` and `product-grid` vs `productGrid` calls
 * both had to be made consciously).
 */
const SHARED = ['hero', 'testimonial', 'stats', 'comparison', 'steps', 'team'];

const FORGE_ONLY = [
	'feature-grid',
	'icon-list',
	'logo-cloud',
	'cta',
	'gallery',
	'text',
	'form',
	'raw',
	'pricing',
	'marquee',
	'content',
];

const VELOCITY_ONLY = ['image', 'faq', 'tabs', 'product-grid', 'promotion'];

describe('data-section vocabulary parity (forge ↔ velocity)', () => {
	it('forge kinds = shared + forge-only, exactly', () => {
		expect([...FORGE_KINDS].sort()).toEqual([...SHARED, ...FORGE_ONLY].sort());
	});

	it('velocity kinds = shared + velocity-only, exactly', () => {
		expect([...VELOCITY_KINDS].sort()).toEqual([...SHARED, ...VELOCITY_ONLY].sort());
	});

	it('the shared set is non-empty and spelled identically on both sides', () => {
		expect(SHARED.length).toBeGreaterThan(0);
		for (const kind of SHARED) {
			expect(FORGE_KINDS as readonly string[]).toContain(kind);
			expect(VELOCITY_KINDS as readonly string[]).toContain(kind);
		}
	});

	it('no kind is declared as both shared and app-only', () => {
		for (const kind of [...FORGE_ONLY, ...VELOCITY_ONLY]) {
			expect(SHARED).not.toContain(kind);
		}
	});
});
