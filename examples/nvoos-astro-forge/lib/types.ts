// ---------------------------------------------------------------------------
// types.ts — the forge site profile + collection contracts (Proposal 096).
//
// A site profile is operator-configured data (allowlisted URLs only — the
// forge never accepts arbitrary fetch targets; Proposal 096 §2.4). A
// collection maps one WordPress REST base onto one Astro content collection.
// ---------------------------------------------------------------------------

import type { SemanticSection } from './semantic.ts';

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

/**
 * The site-shape inventory (Proposal 115 §2) — which URLs the source
 * exposes (sitemaps + homepage links + optional Wayback), how the homepage
 * navigates (anchors = one-pager sections, paths = additional pages), and
 * the missing-page diff against the REST collections.
 */
export interface ForgeInventory {
	/** one-pager: the homepage nav is (almost) all same-page anchors.
	 *  multi-page: the nav links to distinct pages. hybrid: both. */
	shape: 'one-pager' | 'multi-page' | 'hybrid';
	/** Anchor targets found on the homepage (#about, /#about). */
	navAnchors: string[];
	/** Distinct page paths found on the homepage. */
	navPages: string[];
	/** Paths covered by the REST collections (after exclusions). */
	covered: string[];
	/** Paths the profile excludes. */
	excluded: string[];
	/** Paths the source exposes but the collections do not cover. */
	missing: string[];
	/** Anchor targets with no matching element id in the homepage HTML. */
	danglingAnchors: string[];
	analyzedAt: string;
}

/**
 * Per-breakpoint behavior of one wireframe section (Proposal 115 §4) — the
 * responsive language captured from the source, never its CSS.
 */
export interface ForgeResponsive {
	/** Breakpoint labels, ascending width: ['mobile', 'tablet', 'desktop']. */
	breakpoints: string[];
	/** Column counts per breakpoint label. */
	columns?: Record<string, number>;
	/** explicit = per-breakpoint column classes captured from the markup;
	 *  inferred = the industry-standard desktop N → tablet 2 → mobile 1
	 *  collapse rule. */
	columnSource: 'explicit' | 'inferred';
}

/** One section of a page wireframe: the semantic section + its responsive behavior. */
export interface ForgeWireframeSection {
	section: SemanticSection;
	responsive: ForgeResponsive;
}

/**
 * A page wireframe (Proposal 115 §3) — the semantic blueprint of a page the
 * REST collections do not cover, extracted from the rendered source HTML.
 * Never source markup: sections carry escaped text + media refs only (114).
 */
export interface ForgeWireframe {
	title: string;
	slug: string;
	/** The URL the wireframe was extracted from. */
	source: string;
	extractedAt: string;
	/** captured = the source served the page; placeholder = it 404s (the
	 *  scaffold still emits the page so the site structure survives). */
	status: 'captured' | 'placeholder';
	/** Anchor targets captured on the page with no matching id (completeness gap). */
	danglingAnchors?: string[];
	sections: ForgeWireframeSection[];
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
	/**
	 * The inventory block (Proposal 115 §2) — the site-shape analysis
	 * (one-pager vs multi-page vs hybrid), the homepage nav targets, and
	 * the missing-page diff against the REST collections.
	 */
	inventory?: ForgeInventory;
	/**
	 * The wireframe block (Proposal 115 §3) — per-page semantic blueprints
	 * for pages the REST collections do not cover, extracted from the
	 * rendered source. The scaffold emits one static page per wireframe so
	 * the rebuild covers the full source site structure.
	 */
	wireframes?: Record<string, ForgeWireframe>;
	/**
	 * The learned vocabulary block (Proposal 116 §3) — per-site widget/block
	 * role maps plus the unknown-name report. The scaffold bakes it into
	 * vocabulary.generated.ts; the renderer merges it over the seeded
	 * classifier defaults so vocabulary evolves per-site, never per-release.
	 */
	vocabulary?: ForgeVocabulary;
}

/**
 * The learned widget/block vocabulary (Proposal 116 §3). Roles are the
 * classifier's kind names; unknown names are REPORTED (they still classify
 * as `content` — never dropped) so the operator can pin them.
 */
export interface ForgeVocabulary {
	/** Operator-pinned / learned widget roles — win over the seeded defaults. */
	widgetRoles: Record<string, string>;
	/** Learned block roles from the WP block-types registry. */
	blockRoles: Record<string, string>;
	/** elementor-widget-* names seen on the site with no seeded/learned role. */
	unknownWidgets: string[];
	/** Block registry names with no keyword match. */
	unknownBlocks: string[];
	analyzedAt: string;
}

/**
 * One source page section's background facts (Proposal 117 §2) — the
 * per-band theming data the scaffold bakes into sections.generated.ts.
 * Keyed by the Elementor section id the semantic classifier carries on
 * each rebuilt section, so the source band theming re-applies positionally.
 */
export interface ForgeSection {
	/** Elementor's elementor-element-<id> class suffix. */
	id?: string;
	/** The section's own HTML id (the source anchor — #about, #contact…). */
	anchor?: string;
	bgImage?: string;
	bgColor?: string;
	bgGradient?: string;
	/** The background-overlay tint (Elementor's overlay layer). */
	overlay?: string;
	padding?: string;
}

/**
 * The site chrome (Proposal 120 §2) — the rendered header/footer facts the
 * rebuild re-applies: topbar lines, logo, tagline, nav anchors, the call
 * button, footer blocks, copyright. Extracted from the RAW rendered
 * homepage (never from source CSS/markup — facts only, then rebuilt with
 * the template's own components). Absent when the source exposes no
 * recognizable chrome.
 */
export interface ForgeChromeStyle {
	/** The source's padding fact (e.g. the call button's `14px 43px`). */
	padding?: string;
	/** The source's border-radius fact (a pill renders as a huge px value). */
	radius?: string;
	/** The source's font-weight fact (300/400/500/…). */
	weight?: string;
	/** The source's font-size fact (chrome text runs at its own size, not
	 * the content body scale — jayainc's nav is 16px while its content
	 * body is 13px). */
	size?: string;
	/** The source's text-transform fact (uppercase nav, capitalize footer
	 * headings). */
	transform?: string;
	/** The source's letter-spacing fact. */
	letterSpacing?: string;
}

export interface ForgeChrome {
	/** The top contact strip lines (address, phone). */
	topbar?: string[];
	/** The top strip's own background (jayainc: the navy `#04132b`). */
	topbarBg?: string;
	/** The top strip's text color. */
	topbarInk?: string;
	/** The brand logo (the light-background variant when the source ships
	 * dark/light pairs). */
	logo?: { src?: string; alt?: string };
	/** The tagline under the brand (the source's site description). */
	tagline?: string;
	/** The header nav (anchor links on one-pagers; page links otherwise). */
	nav?: Array<{ label: string; href: string }>;
	/** The nav link typography (weight/size/transform/letter-spacing). */
	navStyle?: ForgeChromeStyle;
	/** The nav link color (usually white on a dark header). */
	navInk?: string;
	/** The header's own background (many one-pagers run a dark header the
	 * default white chrome must not paint over). */
	headerBg?: string;
	/** The header text color (inherits to the brand block). */
	headerInk?: string;
	/** The tel: call-to-action button — bg/ink carry the source button's
	 * own colors (the header CTA often wears a distinct color the site
	 * accent does not), style carries its shape/type facts. */
	call?: { label: string; href: string; bg?: string; ink?: string; style?: ForgeChromeStyle };
	/** The footer columns (heading + item lines each). */
	footerBlocks?: Array<{ title?: string; items: string[] }>;
	/** The footer column heading typography (jayainc: 500/29px/capitalize). */
	footerHeadingStyle?: ForgeChromeStyle;
	/** The copyright line. */
	copyright?: string;
	/** The site favicon (the first <link rel="icon"> href, absolute). */
	favicon?: string;
	/** The footer's brand logo (the footer-widget media image). */
	footerLogo?: { src?: string; alt?: string };
	/** The footer background color (resolved from the footer-widget CSS). */
	footerBg?: string;
	/** The copyright bar's own background (jayainc: a translucent navy
	 * strip below the light-gray subfooter). */
	footerBarBg?: string;
	/** The copyright bar's text color (usually translucent white). */
	footerBarInk?: string;
	/** The chrome base font-size — header/topbar/footer run at the source's
	 * chrome size, NOT the content body scale (jayainc: 16px chrome over
	 * a 13px content body). */
	chromeSize?: string;
}

/**
 * The scroll-behavior pass (Proposal 121) — the source's motion, keyed by
 * the same elementor-element-<id> the sections pass uses: sticky elements,
 * reveal-on-scroll effects (once), and parallax/scroll-tied effects
 * (continuous). Extracted from the raw rendered homepage (classes,
 * data-settings motion_fx JSON, CSS facts) — behavior facts only, the
 * template rebuilds them with its own CSS-native runtime.
 */
export interface ScrollBehaviorEntry {
	/** The source element id (elementor-element-<id> class suffix). */
	id?: string;
	kind: 'sticky' | 'reveal' | 'parallax';
	/** The canonical effect (reveal only; unmapped → 'fade'). */
	effect?: 'fade-up' | 'fade-down' | 'fade-left' | 'fade-right' | 'scale-up' | 'blur' | 'fade';
	/** The parallax speed (motion_fx_translateY_speed.size; default 1). */
	speed?: number;
}

export interface ScrollBehaviorPass {
	/** The source header is sticky (aux-logo-sticky / elementor-sticky). */
	headerSticky: boolean;
	entries: ScrollBehaviorEntry[];
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
	typography: { display?: string; body?: string; scalePx: number[]; bodyWeight?: string };
	spacing: { scalePx: number[] };
	radii: { valuesPx: number[] };
	shadows: { count: number };
	components: Record<string, number>;
	modes: { dark: 'auto' | 'light' | 'dark'; contrast: 'auto' | 'standard' | 'high' };
	/** The section-background pass (117 §2) — omitted when unavailable. */
	sections?: ForgeSection[];
	/** The site-chrome pass (120 §2) — omitted when unavailable. */
	chrome?: ForgeChrome;
	/** The scroll-behavior pass (121) — omitted when unavailable. */
	behavior?: ScrollBehaviorPass;
	/** The CTA metrics (121 §5) — the source button's padding + radius. */
	ctaStyle?: { padding?: string; radius?: string };
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
