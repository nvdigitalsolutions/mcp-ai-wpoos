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
	/**
	 * Whether a band that declares NO padding fact keeps the template's own band
	 * rhythm (`template`, the default) or renders flush like the source
	 * (`source`). Elementor reports `padding: 0` for many bands; `source` honours
	 * that instead of adding the template's breathing room. Opt-in per profile —
	 * nothing changes unless a profile asks for it.
	 */
	bandSpacing?: 'template' | 'source';
	/**
	 * Measured source geometry (`analyze --geometry`), one box per band per
	 * viewport. `verify --geometry` diffs the built site against it — the gate
	 * the language-parity score cannot provide (Proposal 114 §3.6).
	 */
	geometry?: ForgeGeometry;
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
	/**
	 * Operator-owned facts inside the ANALYZED `design` block (see
	 * `lib/pins.ts`). `analyze --write` refreshes `design` wholesale, so these
	 * paths are re-applied afterwards — a regenerate can never silently drop a
	 * hand-tuned fact. Pins live here, at the top level, because `design` is the
	 * block being replaced.
	 */
	pins?: DesignPin[];
}

/**
 * One operator-owned fact inside the design block. `path` is a dot path
 * (`chrome.footerInk`) or a section shorthand
 * (`sections.5e78df1.marginTop`); `value` is any JSON value, compared
 * structurally. `why` is the operator's note on why the fact is pinned.
 */
export interface DesignPin {
	path: string;
	value: unknown;
	why?: string;
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
 * A band length that may differ per device. Elementor scopes a section's pull
 * to a device range, so a single value is unfaithful: jayainc's products band
 * is 0px on desktop, 170px on tablet and 100px on mobile.
 */
export type ResponsiveLength = string | { desktop?: string; tablet?: string; mobile?: string };

/** One band's measured box at one viewport (see `lib/geometry.ts`). */
export interface BandGeometry {
	height: number;
	width: number;
	paddingTop: number;
	paddingBottom: number;
	marginTop: number;
	marginBottom: number;
}

/** Measured band geometry: section id → viewport name → box. */
export type ForgeGeometry = Record< string, Record< string, BandGeometry > >;

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
	/** The boxed container's max-width (Elementor's boxed sections constrain
	 * their content — jayainc's 1140px default). Absent → the site measure. */
	measure?: string;
	/** The container's column-gap side padding (gap-default 10px …
	 * gap-wider 30px) — the source's inner gutter. */
	paddingX?: string;
	/** Section-level pull facts (124 §4) — negative margins pull the band
	 * over its neighbor (the source's overlap rhythm), z-index stacks it. */
	marginTop?: ResponsiveLength;
	marginBottom?: ResponsiveLength;
	zIndex?: string;
	/** The top-level column responsive facts (124 §6) — desktop/tablet/
	 * mobile widths (elementor-col/md/sm-*) + order/visibility flags. The
	 * split components re-measure from these; absent → the template
	 * default ratio. */
	columns?: ForgeSectionColumn[];
	/** The section's content rhythm (125 §1) — 'split' = the source's
	 * two-column hero band: a copy column (heading + divider + body +
	 * links) beside a media collage column (jayainc's about band). */
	layout?: 'split';
	/** The heading's underline divider (aux-modern-heading-divider) —
	 * width/height/background/shadow facts the rebuild re-applies under
	 * the split hero's title. */
	titleDivider?: { width: string; height: string; background: string; shadow?: string };
	/** The heading typography (the source's modern-heading rule) — size/
	 * weight/line-height/letter-spacing. Absent → the template tokens. */
	titleStyle?: { size?: string; weight?: string; lineHeight?: string; letterSpacing?: string };
	/** The heading is centered (the source's aux-widget-inner centering —
	 * jayainc's "Other Featured Products" band). */
	titleAlign?: 'center';
	/**
	 * The card box model of a media grid band. Elementor cards are theme
	 * components, so a source's card padding / grid gutter / text-block padding
	 * are not band-scoped CSS facts — they are measured per band (jayainc's
	 * products cards: 10px wrap padding, 40px text padding, no gutter). Empty →
	 * the template defaults.
	 */
	cardStyle?: { gap?: string; padding?: string; textPadding?: string };
	/** The media collage's backdrop shape (aux-the-shape) — the rectangle
	 * the offset photos compose over (jayainc's 412x450 yellow shape). */
	mediaShape?: { width: string; height: string; background: string; radius?: string };
	/** Per-image collage offsets (the absolutely-positioned parallax pieces
	 * — aux-parallax-piece) in media order: top/right/bottom/left resolve
	 * against the collage box; absent → the image sits in flow. */
	mediaOffsets?: Array<{ top?: string; right?: string; bottom?: string; left?: string; shadow?: string }>;
	/** The body paragraphs render in N columns (the source's sub-column
	 * split — jayainc's two side-by-side about paragraphs). */
	bodyColumns?: number;
	/** The band's flipbook catalog (125 §4 — the dflip shortcode): the PDF
	 * source dies with its script under sanitization, so the rebuild
	 * renders a titled open-catalogue CTA instead. Captured from the raw
	 * homepage (the _df_book node + its df-shortcode-script payload). */
	catalog?: { title?: string; source: string };
}

/** One top-level column's responsive facts (Elementor's per-device
 * width/order/visibility classes) — the rebuild's relayout input. */
export interface ForgeSectionColumn {
	/** Desktop width % (elementor-col-*). */
	width?: number;
	/** Tablet width % (elementor-md-*). */
	widthTablet?: number;
	/** Mobile width % (elementor-sm-*). */
	widthMobile?: number;
	reverseMobile?: boolean;
	reverseTablet?: boolean;
	hiddenMobile?: boolean;
	hiddenTablet?: boolean;
	hiddenDesktop?: boolean;
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
	/** The source icon-font stylesheet (the icons CSS link, e.g. the theme's
	 * auxin-icon.css) — the rebuild's icon markers carry the source glyph
	 * classes and this sheet draws them (the customer's own asset, like the
	 * content media URLs). */
	iconCss?: string;
	/** The subfooter text color (the source paints white-ish text on its
	 * dark footer — jayainc's rgba(255,255,255,0.7)). */
	footerInk?: string;
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
