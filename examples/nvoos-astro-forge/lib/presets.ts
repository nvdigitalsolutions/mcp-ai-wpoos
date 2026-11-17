// ---------------------------------------------------------------------------
// presets.ts — forge presets (Proposal 104 §4.3, D8): one command-line
// choice maps onto a template + a default collection profile + surface
// toggles, mirroring the Accessible Starter CLI pattern.
//
//   - `blog`       — today's default: posts, pages, search, feeds.
//   - `storefront` — blog + the products catalog + the static front page.
//   - `minimal`    — posts/pages only (the home and page routes consume the
//                    pages collection), no search surface.
//
// `suggestPreset()` derives the default from a site_analyze report (Woo or
// product content detected → 'storefront', otherwise 'blog'). The wizard
// auto-suggests from it; `applyPresetToProfile()` applies the collection
// profile. Erasable-only TS (no enums/namespaces) — forge.mjs imports this
// file under Node native type stripping.
// ---------------------------------------------------------------------------

import type { DiscoveryReport } from './discovery.ts';
import type { SiteProfile, WpCollection } from './types.ts';

export const PRESET_NAMES = ['blog', 'storefront', 'portfolio', 'landing', 'docs', 'minimal'] as const;
export type PresetName = (typeof PRESET_NAMES)[number];

export const RENDER_MODES = ['static', 'ssr'] as const;
export type RenderMode = (typeof RENDER_MODES)[number];

/**
 * Theme policy (Proposal 105 §4) — the site-level theme decision baked into
 * the generated project at scaffold time (`--theme-policy`). 'brand:<slug>'
 * forces a brand theme (slug `^[a-z0-9-]+$`); the forge registry is static
 * light/dark today, so brand slugs fail open to 'auto' at runtime until
 * brand themes land (the template's policy module owns that ladder).
 */
export const THEME_POLICY_MODES = ['auto', 'light', 'dark'] as const;
export const DEFAULT_THEME_POLICY = 'auto';
export const THEME_POLICY_SLUG_REGEX = /^[a-z0-9-]+$/;
export type ThemePolicyValue = (typeof THEME_POLICY_MODES)[number] | `brand:${string}`;

/** Validate a --theme-policy value (the enum + the brand-slug regex). */
export function isValidThemePolicy(value: unknown): value is ThemePolicyValue {
	if (typeof value !== 'string') {
		return false;
	}
	if ((THEME_POLICY_MODES as readonly string[]).includes(value)) {
		return true;
	}
	if (value.startsWith('brand:')) {
		const slug = value.slice('brand:'.length);
		return slug.length > 0 && THEME_POLICY_SLUG_REGEX.test(slug);
	}
	return false;
}

/** Surfaces a preset toggles at scaffold time. */
export interface PresetSurfaces {
	/** /search/ page + post-build Pagefind index. */
	search: boolean;
	/** /feed.xml RSS. */
	feed: boolean;
	/** /sitemap.xml (+ the robots.txt sitemap pointer). */
	sitemap: boolean;
	/**
	 * Per-post build-time OG cards (104 §4.8 / Q5, the Cactus pattern) —
	 * ships the satori/resvg endpoint + injected devDeps + og:image wiring.
	 * OFF by default: default scaffolds stay zero-dep for OG.
	 */
	ogImages: boolean;
}

/**
 * Preset guidance for agents (Proposal 136 §4.4). `presets.json` is the
 * machine-readable catalogue an agent outside MCP can read to choose a preset
 * without opening code — the same job `index.json` does for the reference
 * landing-page library.
 */
export interface PresetGuidance {
	id: PresetName;
	bestFor: string;
	/** Signals that make this preset the right choice. */
	detect: string[];
	render: RenderMode;
	/** Section kinds the preset is expected to produce. */
	kinds: string[];
	surfaces: string[];
}

const PRESET_GUIDANCE: Record< PresetName, { bestFor: string; detect: string[]; kinds: string[] } > = {
	blog: {
		bestFor: 'Editorial sites: a blog with pages, search and feeds.',
		detect: [ 'posts outnumber products', 'no wc/v3 namespace advertised' ],
		kinds: [ 'hero', 'feature-grid', 'icon-list', 'testimonial', 'cta', 'text', 'form' ],
	},
	storefront: {
		bestFor: 'WooCommerce catalogues and product marketing.',
		detect: [ 'wc/v3 advertised', 'product post type present' ],
		// `model-3d` (sanitize v5): a 3D product viewer band the storefront
		// template can carry — always poster-first, never hydrated.
		kinds: [ 'hero', 'pricing', 'feature-grid', 'gallery', 'stats', 'comparison', 'testimonial', 'cta', 'model-3d' ],
	},
	portfolio: {
		bestFor: 'Creative work: a project/case-study catalogue with an optional journal.',
		detect: [ 'a portfolio/projects/case-study CPT is advertised', 'no wc/v3 namespace advertised' ],
		// The case-study band set: work shown (gallery), outcomes proven (stats),
		// process told (steps), and the client speaking (testimonial).
		kinds: [ 'hero', 'gallery', 'feature-grid', 'stats', 'testimonial', 'steps', 'comparison', 'cta' ],
	},
	landing: {
		bestFor: 'A single marketing page: hero, proof, and one call to action.',
		detect: [ 'one-page source with no blog or catalogue', 'a single conversion goal' ],
		kinds: [ 'hero', 'feature-grid', 'icon-list', 'stats', 'testimonial', 'pricing', 'accordion', 'cta' ],
	},
	docs: {
		bestFor: 'Documentation: a page hierarchy read in order, with search.',
		detect: [ 'many nested pages', 'no products', 'no dated posts' ],
		// Reading-order kinds: prose, ordered procedure, collapsible reference.
		kinds: [ 'hero', 'text', 'steps', 'accordion', 'comparison', 'content' ],
	},
	minimal: {
		bestFor: 'The smallest useful site: posts and pages, no search surface.',
		detect: [ 'few pages', 'no products', 'no search requirement' ],
		kinds: [ 'hero', 'text', 'feature-grid', 'cta' ],
	},
};

/** The catalogue entries, in the fixed preset order. */
export function presetCatalogue(): PresetGuidance[] {
	return PRESET_NAMES.map( ( name ) => {
		const preset = PRESETS[ name ];
		const guidance = PRESET_GUIDANCE[ name ];
		return {
			id: name,
			bestFor: guidance.bestFor,
			detect: guidance.detect,
			render: preset.render,
			kinds: guidance.kinds,
			surfaces: Object.entries( preset.surfaces )
				.filter( ( [ , enabled ] ) => enabled )
				.map( ( [ key ] ) => key ),
		};
	} );
}

/** Serialize `presets.json` (checked in; a test pins it to this function). */
export function serializePresets(): string {
	return `${ JSON.stringify( { version: 1, presets: presetCatalogue() }, null, '\t' ) }\n`;
}

export interface ForgePreset {
	name: PresetName;
	/** Built-in template name (or path) the preset scaffolds from. */
	template: string;
	/**
	 * Rendering model (104 Q4): 'static' is today's template; 'ssr' swaps
	 * the static product pages for an on-demand route + server output.
	 */
	render: RenderMode;
	/** Surface toggles applied to the generated project. */
	surfaces: PresetSurfaces;
	/**
	 * Template variants this preset can carry (validated by scaffold against
	 * TEMPLATE_VARIANTS). Empty means "no variant applies" — a variant is a
	 * surface specific to the preset's job, so `product-3d` belongs to
	 * `storefront` and nowhere else. `presets-catalogue.test.ts` pins every id
	 * here to the shipped variant list.
	 */
	variants: readonly string[];
	/** One-line description for CLI prompts. */
	description: string;
}

export const PRESETS: Record<PresetName, ForgePreset> = {
	blog: {
		name: 'blog',
		template: 'blog',
		render: 'static',
		surfaces: { search: true, feed: true, sitemap: true, ogImages: false },
		variants: [],
		description: 'Blog: posts, pages, search, feeds (the Phase 0 default).',
	},
	storefront: {
		name: 'storefront',
		template: 'blog',
		render: 'static',
		surfaces: { search: true, feed: true, sitemap: true, ogImages: false },
		variants: [ 'product-3d', 'video-hero' ],
		description: 'Storefront: blog + products catalog + static front page.',
	},
	portfolio: {
		name: 'portfolio',
		template: 'blog',
		render: 'static',
		// A work catalogue is browsed by facet, not by date — no RSS.
		surfaces: { search: true, feed: false, sitemap: true, ogImages: false },
		variants: [ 'video-hero' ],
		description: 'Portfolio: work catalogue (projects CPT) + optional journal, no feed.',
	},
	landing: {
		name: 'landing',
		template: 'blog',
		render: 'static',
		// One page: nothing to search, nothing to subscribe to.
		surfaces: { search: false, feed: false, sitemap: true, ogImages: false },
		variants: [ 'video-hero' ],
		description: 'Landing: one-pager marketing site — hero, proof, one CTA.',
	},
	docs: {
		name: 'docs',
		template: 'blog',
		render: 'static',
		// Documentation is searched, not subscribed to.
		surfaces: { search: true, feed: false, sitemap: true, ogImages: false },
		variants: [],
		description: 'Docs: a page hierarchy read in order, searchable, no feed.',
	},
	minimal: {
		name: 'minimal',
		template: 'blog',
		render: 'static',
		surfaces: { search: false, feed: true, sitemap: true, ogImages: false },
		variants: [],
		description: 'Minimal: posts and pages only, no search.',
	},
};

/**
 * Slugs that mean "a portfolio/project catalogue" in the wild. Used both to
 * SUGGEST the preset and to decide whether `portfolio` must add a default
 * projects collection.
 */
const PORTFOLIO_SLUG = /portfolio|projects?|case-stud|case_stud|\bwork\b/i;

/**
 * Suggest a preset from a site_analyze report.
 *
 * Precedence is deliberate: commerce beats everything (a shop with a
 * portfolio CPT is still a shop), then a work catalogue, then the blog
 * default. `landing` and `docs` are only ever chosen explicitly — shape and
 * reading order are operator INTENT, and the probe cannot see intent.
 */
export function suggestPreset(report: Pick<DiscoveryReport, 'woocommerce' | 'collections'>): PresetName {
	const hasProducts =
		report.woocommerce === true || report.collections.some((collection) => collection.kind === 'product');
	if (hasProducts) {
		return 'storefront';
	}
	if (report.collections.some((collection) => PORTFOLIO_SLUG.test(collection.slug))) {
		return 'portfolio';
	}
	return 'blog';
}

/**
 * The variant a preset can carry, if any — a SUGGESTION, never automatic.
 *
 * `site_analyze` reports collections and WooCommerce; it cannot see whether
 * individual product records carry a `model3d` field. So the 3D viewer is an
 * operator decision (the `--variant` flag, or a TTY prompt), and inventing it
 * would drop a viewer — and a vendored runtime — into a site that has no models.
 */
export function suggestedVariant(preset: PresetName): string | null {
	return PRESETS[preset].variants[0] ?? null;
}

/** The standard wc/v3 products collection a storefront profile defaults to. */
const STOREFRONT_PRODUCTS: WpCollection = {
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
	optional: true,
};

/**
 * The standard projects collection a portfolio preset defaults to. `optional`
 * because a site that advertises the preset without that CPT must still build
 * (a 404 source warns and yields an empty collection, it does not fail).
 */
const PORTFOLIO_PROJECTS: WpCollection = {
	slug: 'projects',
	restBase: 'wp/v2/projects',
	fields: ['id', 'slug', 'date_gmt', 'title', 'excerpt', 'content', 'featured_media'],
	kind: 'post',
	optional: true,
};

/** Commerce is the ONE thing a content preset prunes — a CPT is content. */
function withoutCommerce(profile: SiteProfile): SiteProfile {
	return {
		...profile,
		collections: profile.collections.filter((collection) => collection.slug !== 'products'),
	};
}

/**
 * Apply a preset to a drafted profile (the wizard's collection-profile step).
 *
 * Two invariants hold for every preset:
 *   - COMMERCE is the only thing a content preset prunes; an extra collection
 *     is content the profile declared on purpose (an arbitrary CPT), so it
 *     survives every preset;
 *   - a preset never invents a taxonomy — it only ensures the one collection it
 *     can honestly default (`storefront` → wc/v3 products, `portfolio` →
 *     projects).
 */
export function applyPresetToProfile(profile: SiteProfile, preset: PresetName): SiteProfile {
	switch (preset) {
		case 'minimal':
			return withoutCommerce(profile);
		case 'storefront':
			if (profile.collections.some((collection) => collection.slug === 'products')) {
				return profile;
			}
			return { ...profile, collections: [...profile.collections, { ...STOREFRONT_PRODUCTS }] };
		case 'portfolio': {
			const content = withoutCommerce(profile);
			if (content.collections.some((collection) => PORTFOLIO_SLUG.test(collection.slug))) {
				return content;
			}
			return { ...content, collections: [...content.collections, { ...PORTFOLIO_PROJECTS }] };
		}
		case 'landing':
			// A landing site is ONE page by definition — `shape` is what the
			// template reads to render anchor nav instead of page links.
			return { ...withoutCommerce(profile), shape: 'one-pager' };
		case 'docs':
			// The page hierarchy IS the site; commerce is pruned, search stays on.
			// Shape is PINNED multi-page: a docs source that happens to look like a
			// one-pager would otherwise lose its hierarchy navigation.
			return { ...withoutCommerce(profile), shape: 'multi-page' };
		case 'blog':
		default:
			return profile;
	}
}
