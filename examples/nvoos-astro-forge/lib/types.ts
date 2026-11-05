// ---------------------------------------------------------------------------
// types.ts — the forge site profile + collection contracts (Proposal 096).
//
// A site profile is operator-configured data (allowlisted URLs only — the
// forge never accepts arbitrary fetch targets; Proposal 096 §2.4). A
// collection maps one WordPress REST base onto one Astro content collection.
// ---------------------------------------------------------------------------

/** One WordPress REST collection (e.g. posts, pages, a CPT). */
export interface WpCollection {
	/** Collection slug in the generated Astro project (posts, pages, …). */
	slug: string;
	/** REST base path under /wp-json (e.g. 'wp/v2/posts'). */
	restBase: string;
	/** `_fields` pruning list — the anti-N+1 discipline (096 research §2.2). */
	fields: string[];
	/** Fetch `_embed` for related resources (featured media, authors). */
	embed?: boolean;
	/** Page size for pagination. Default 100. */
	perPage?: number;
	/** Hard cap on pages per build (defense against runaway catalogs). */
	maxPages?: number;
	/** Slug allowlist filter — junk/system pages stay out of the build. */
	exclude?: string[];
	/**
	 * Content semantics: 'post' | 'page' | 'product'.
	 * 'product' enables commerce extraction (price/stock/sku/images).
	 */
	kind?: 'post' | 'page' | 'product';
	/**
	 * Wire format: 'wp' (wp/v2 + wc/v3, default) or 'payload' — the
	 * nvoos-commerce/v1 envelope served by the nvoos-astro-bridge
	 * ({ docs, totalDocs, totalPages, page } pagination, flat documents).
	 */
	source?: 'wp' | 'payload';
	/**
	 * Auth source for the generated project: application passwords
	 * (default) or WooCommerce consumer key/secret (wc/v3 endpoints).
	 */
	authMode?: 'application-password' | 'wc-consumer';
	/** A 404/empty source is tolerated (warn + empty collection), not fatal. */
	optional?: boolean;
}

/**
 * The forms block (Proposal 114 §3.9) — bridge configuration baked into the
 * scaffolded manifest. All values are overridable per-deploy via env vars;
 * the endpoint is the only value the rendered Form component reads (search
 * forms always map to the site's own /search/ surface).
 */
export interface ForgeForms {
	/**
	 * Bridge REST base the rebuilt forms POST to — defaults to
	 * `<profile.url>/wp-json/nvoos-forge/v1` at scaffold time; the generated
	 * project resolves env NVOOS_FORGE_FORMS_ENDPOINT first.
	 */
	endpoint?: string;
	/** The static origin hosting the forged site (the bridge's CORS allowlist). */
	origin?: string;
	/** Extra input names that mark a single-field form as a search form. */
	searchForms?: string[];
}

/** One operator-configured WordPress site. */
export interface SiteProfile {
	/** Root URL (https://…). No trailing slash. */
	url: string;
	/** Display name (discovery fills it when absent). */
	name?: string;
	/** Page slug to render at `/` (the WordPress static front page). */
	frontPage?: string;
	/**
	 * The form bridge block (Proposal 114 §3.9) — the static site rebuilds
	 * source forms as the Form design component; submissions POST to the
	 * bridge endpoint (`<endpoint>/forms/submit`) on WordPress, where the
	 * JetFormBuilder / Elementor Pro handler runs natively. The endpoint
	 * defaults to `<profile.url>/wp-json/nvoos-forge/v1` and is overridable
	 * per-deploy via the NVOOS_FORGE_FORMS_ENDPOINT env var (env-first,
	 * never hardcoded into the customer repo).
	 */
	forms?: ForgeForms;
	/**
	 * Design-token overrides (Proposal 098 output) applied over the
	 * template defaults at scaffold time. Keys are the `--forge-*` names
	 * without the prefix (accent, accent-ink, ink, muted, surface,
	 * surface-alt, line, radius, font); values are CSS values. Explicit
	 * overrides win over the design block's derived tokens.
	 */
	tokens?: Record<string, string>;
	/**
	 * The design rebuild block (Proposal 114 §3.3) — the 098-style design
	 * language extracted from the source site (roles + confidence +
	 * unassigned pool, typography, spacing, radii, modes, adaptation
	 * brief). The scaffold derives the `--forge-*` token theme from it.
	 */
	design?: ForgeDesign;
	/** Collections to wire into the generated project. */
	collections: WpCollection[];
}

/**
 * The v2 design block (Proposal 114) — a DTCG-flavored design language,
 * never source assets. Roles are fail-loud (098): only confident values
 * land in `roles`; everything else stays in `unassigned`.
 */
export interface ForgeDesign {
	mode: 'rebuild';
	source: { url: string; analyzedAt: string };
	concept: string;
	palette: {
		roles: Record<string, string>;
		unassigned: string[];
		confidence: Record<string, 'high' | 'medium' | 'low'>;
	};
	typography: { display?: string; body?: string; scalePx: number[] };
	spacing: { scalePx: number[] };
	radii: { valuesPx: number[] };
	shadows: { count: number };
	components: Record<string, number>;
	modes: { dark: 'auto' | 'light' | 'dark'; contrast: 'auto' | 'standard' | 'high' };
	adaptation: {
		keep: string[];
		replace: string[];
		remix: string[];
		licensingNote: string;
		attribution: string[];
	};
}

/** Auth for private endpoints — never stored in the scaffolded repo. */
export type WpAuth =
	| { type: 'basic'; username: string; password: string }
	| { type: 'bearer'; token: string };

/** A single fetched entry (the raw WP REST document). */
export type WpEntry = Record<string, unknown>;

/** A paginated fetch result — truncation is explicit, never silent. */
export interface FetchAllResult<T> {
	entries: T[];
	/** True when the page cap stopped the fetch before the last page. */
	truncated: boolean;
	pages: number;
}

/** The WP root document (site discovery). */
export interface WpRoot {
	name: string;
	description: string;
	url: string;
	namespaces: string[];
	/** Static front page config ('page' + page_on_front ID). */
	show_on_front?: string;
	page_on_front?: number;
}
