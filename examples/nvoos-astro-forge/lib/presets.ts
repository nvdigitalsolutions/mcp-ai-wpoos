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

export const PRESET_NAMES = ['blog', 'storefront', 'minimal'] as const;
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
		kinds: [ 'hero', 'pricing', 'feature-grid', 'gallery', 'stats', 'comparison', 'testimonial', 'cta' ],
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
	/** One-line description for CLI prompts. */
	description: string;
}

export const PRESETS: Record<PresetName, ForgePreset> = {
	blog: {
		name: 'blog',
		template: 'blog',
		render: 'static',
		surfaces: { search: true, feed: true, sitemap: true, ogImages: false },
		description: 'Blog: posts, pages, search, feeds (the Phase 0 default).',
	},
	storefront: {
		name: 'storefront',
		template: 'blog',
		render: 'static',
		surfaces: { search: true, feed: true, sitemap: true, ogImages: false },
		description: 'Storefront: blog + products catalog + static front page.',
	},
	minimal: {
		name: 'minimal',
		template: 'blog',
		render: 'static',
		surfaces: { search: false, feed: true, sitemap: true, ogImages: false },
		description: 'Minimal: posts and pages only, no search.',
	},
};

/**
 * Suggest a preset from a site_analyze report: WooCommerce or any product
 * collection detected → 'storefront', otherwise the blog default.
 */
export function suggestPreset(report: Pick<DiscoveryReport, 'woocommerce' | 'collections'>): PresetName {
	const hasProducts =
		report.woocommerce === true || report.collections.some((collection) => collection.kind === 'product');
	return hasProducts ? 'storefront' : 'blog';
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
 * Apply a preset to a drafted profile (the wizard's collection-profile
 * step). 'minimal' drops commerce collections — posts/pages stay because
 * the home, page and sitemap routes consume them; 'storefront' ensures a
 * products collection exists (wc/v3 with consumer-key auth); 'blog' is the
 * identity.
 */
export function applyPresetToProfile(profile: SiteProfile, preset: PresetName): SiteProfile {
	switch (preset) {
		case 'minimal':
			return {
				...profile,
				collections: profile.collections.filter(
					(collection) => collection.slug === 'posts' || collection.slug === 'pages'
				),
			};
		case 'storefront':
			if (profile.collections.some((collection) => collection.slug === 'products')) {
				return profile;
			}
			return { ...profile, collections: [...profile.collections, { ...STOREFRONT_PRODUCTS }] };
		case 'blog':
		default:
			return profile;
	}
}
