// SPDX-License-Identifier: MIT
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
import { ECA_DAY_ORDER, ECA_DEFAULTS, ECA_DEFAULT_STAGES } from './eca.ts';
import type { ForgeEca } from './eca.ts';

export const PRESET_NAMES = ['blog', 'storefront', 'portfolio', 'landing', 'docs', 'eca', 'minimal'] as const;
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
	eca: {
		bestFor: 'Schools and clubs: an extra-curricular activities catalogue.',
		detect: [ 'mcp_ai_eca post type advertised', 'activity/course records with a schedule', 'no wc/v3 namespace required' ],
		// The activity set: what is on, when, who runs it, what it costs.
		kinds: [ 'hero', 'feature-grid', 'stats', 'testimonial', 'steps', 'cta', 'content' ],
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
	eca: {
		name: 'eca',
		template: 'blog',
		render: 'static',
		// An activities catalogue is browsed by facet and by term — never
		// subscribed to. Search stays on (3-layer search, 163 §P5).
		surfaces: { search: true, feed: false, sitemap: true, ogImages: false },
		variants: [],
		description: 'ECA: extra-curricular activities catalogue — facets, calendar, timetable, ICS.',
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
	// An ECA catalogue outranks commerce: a school that also runs a shop is
	// still an ECA site, and `eca` is the only preset that renders a timetable.
	if (
		report.collections.some(
			(collection) => /(?:^|\/)mcp_ai_eca$/.test(collection.restBase) || /^(?:mcp_ai_)?eca$/.test(collection.slug),
		)
	) {
		return 'eca';
	}
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

/**
 * REST keys an ECA collection needs verbatim.
 *
 * `createWpLoader` projects a fixed field set, so `meta` never reaches a page
 * unless the collection asks for it. Without it the whole activity record
 * collapses to a title and a paragraph.
 */
export const ECA_PASSTHROUGH_FIELDS = [ 'meta', 'mcp_ai_eca_category', 'content' ];

/**
 * The `eca` preset's collection — the toolkit's activity CPT.
 *
 * `meta` is load-bearing: all 18 `_eca_*` fields arrive through it. A `_fields`
 * list that omits it drops the entire schedule and price silently, which is
 * exactly the trap the first research pass fell into.
 */
export const ECA_COLLECTION: WpCollection = {
	slug: 'activities',
	// The source publishes activities under `/eca/<slug>/`; the rebuilt site owns
	// the new IA and 301s `/eca/*` onto `/activities/*`.
	basePath: '/activities',
	restBase: 'wp/v2/mcp_ai_eca',
	// The ECA preset emits its own detail route at `/activities/<slug>/`, so the
	// redirect map must target that — not the generic `/activities/<id>/<slug>/`.
	canonicalPath: 'slug',
	fields: [
		'id',
		'slug',
		'link',
		'date_gmt',
		'modified_gmt',
		'title',
		'content',
		'excerpt',
		'featured_media',
		'meta',
		'mcp_ai_eca_category',
	],
	perPage: 100,
	maxPages: 10,
	passthrough: [ ...ECA_PASSTHROUGH_FIELDS ],
};

/**
 * Fields an ECA collection cannot function without.
 *
 * `meta` and `mcp_ai_eca_category` are the whole record; `content`/`link`/
 * `featured_media` feed the detail page. A discovered collection usually omits
 * `meta`, so the preset MERGES these in rather than trusting the profile.
 */
export const ECA_REQUIRED_FIELDS = [
	'meta',
	'mcp_ai_eca_category',
	'title',
	'slug',
	'link',
	'content',
	'featured_media',
];

/** Append the required fields a collection is missing, preserving operator order. */
export function withRequiredFields( existing: string[], required: string[] ): string[] {
	const out = [ ...existing ];
	for ( const field of required ) {
		if ( ! out.includes( field ) ) out.push( field );
	}
	return out;
}

/**
 * What the `eca` preset writes into `profile.eca` when the operator has not.
 *
 * Deliberately structural only — path, stages, day order, time bands, hand-off
 * mode. Terms, timezone and currency are SITE facts the forge cannot infer, so
 * a missing one warns at scaffold time rather than being guessed.
 */
export const ECA_PROFILE_DEFAULTS: ForgeEca = {
	basePath: '/activities',
	stages: [ ...ECA_DEFAULT_STAGES ],
	days: [ ...ECA_DAY_ORDER ],
	timeBands: [ ...ECA_DEFAULTS.timeBands ],
	booking: { mode: 'external' },
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
		case 'eca': {
			// Commerce is pruned for the same reason `portfolio` prunes it: the
			// catalogue is the site, and a school selling paid activities routes
			// that through the register CTA (D1), not a cart.
			const content = withoutCommerce( profile );
			const eca = { ...ECA_PROFILE_DEFAULTS, ...( content.eca ?? {} ) };
			const index = content.collections.findIndex(
				( collection ) =>
					collection.slug === ECA_COLLECTION.slug || /^wp\/v2\/mcp_ai_eca$/.test( collection.restBase ),
			);

			if ( index < 0 ) {
				return { ...content, collections: [ ...content.collections, { ...ECA_COLLECTION } ], eca };
			}

			// A discovered collection is named after the CPT (`eca`) and its
			// `_fields` list does NOT include `meta` — the whole schedule and price
			// would vanish silently. Repair both here instead of trusting the profile.
			const existing = content.collections[ index ];
			const repaired: WpCollection = {
				...existing,
				// The rebuilt IA owns the path; the source's /eca/* URLs 301 onto it.
				basePath: eca.basePath ?? ECA_COLLECTION.basePath,
				// The ECA routes are slug-only, so 301s must say so.
				canonicalPath: 'slug',
				fields: withRequiredFields( existing.fields, ECA_REQUIRED_FIELDS ),
				// `meta`/`content` must survive the loader's projection.
				passthrough: [ ...new Set( [ ...( existing.passthrough ?? [] ), ...ECA_PASSTHROUGH_FIELDS ] ) ],
			};
			const collections = [ ...content.collections ];
			collections[ index ] = repaired;
			return { ...content, collections, eca };
		}
		case 'blog':
		default:
			return profile;
	}
}
