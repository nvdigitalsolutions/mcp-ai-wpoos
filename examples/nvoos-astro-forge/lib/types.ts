// ---------------------------------------------------------------------------
// types.ts — the forge site profile + collection contracts (Proposal 096).
//
// A site profile is operator-configured data (allowlisted URLs only — the
// forge never accepts arbitrary fetch targets; Proposal 096 §2.4). A
// collection maps one WordPress REST base onto one Astro content collection.
// ---------------------------------------------------------------------------

import type {
	SemanticSection,
	PricingPlan,
	StatItem,
	StepItem,
	TeamMember,
	MarqueeItem,
	ComparisonRow,
} from './semantic.ts';
import type { ForgeVerify } from './gate-policy.ts';

/** One WordPress REST collection (e.g. posts, pages, a CPT). */
export interface WpCollection {
	/** Collection slug in the generated Astro project (posts, pages, …). */
	slug: string;
	/**
	 * URL prefix for this collection's routes. Defaults to `/<slug>`.
	 *
	 * An EXTRA collection (an arbitrary CPT) needs this to keep the source's
	 * URLs: WordPress serves `portfolio` items under `/blog/portfolio/<slug>/`,
	 * so a rebuild that silently moved them to `/portfolio/…` would drop the
	 * site's existing rankings. Core collections ignore it (their routes are
	 * template-owned).
	 */
	basePath?: string;
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
	 * Wire format: 'wp' (wp/v2 + wc/v3, default), 'payload' — the
	 * nvoos-commerce/v1 envelope served by the nvoos-astro-bridge
	 * ({ docs, totalDocs, totalPages, page } pagination, flat documents), or
	 * 'local' — the same envelope read from the bridge's local-content
	 * collection (Proposal 135 §D4), where documents carry an explicit slug.
	 */
	source?: 'wp' | 'payload' | 'local';
	/**
	 * Auth source for the generated project: application passwords
	 * (default) or WooCommerce consumer key/secret (wc/v3 endpoints).
	 */
	authMode?: 'application-password' | 'wc-consumer';
	/** A 404/empty source is tolerated (warn + empty collection), not fatal. */
	optional?: boolean;
	/**
	 * Proposal 131 — where to read SEO meta the primary wire cannot carry.
	 * wc/v3 products expose no Rank Math/Yoast fields, so the loader fetches
	 * this wp/v2 CPT once (id → SEO fields) and merges it by product id — ONE
	 * extra paginated request per build, never N+1. Absent = no enrichment.
	 */
	seoFrom?: string;
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
	/**
	 * How a submission becomes an email.
	 *
	 * `bridge` (default) POSTs to WordPress, which owns the handler AND its
	 * mailer — the SMTP setting lives there, so credentials never reach the
	 * site. `direct` sends from the managed runtime through a provider HTTP
	 * API instead, for a site with no bridge installed. Direct REQUIRES
	 * `deploy.runtime: managed` (a static build has nowhere to keep a key).
	 */
	delivery?: 'bridge' | 'direct';
	/**
	 * Direct-delivery settings. Public identifier data only — the provider
	 * SECRET comes from the environment (BREVO_API_KEY / MAILJET_*), never
	 * from the profile or the manifest.
	 */
	mail?: {
		provider: 
			| 'brevo'
			| 'mailjet';
		/** Authenticated sender — must align with the domain's SPF/DKIM. */
		from: string;
		fromName?: string;
		/** Where submissions are delivered. */
		to: string;
		subject?: string;
	};
}

/**
 * The tracking block (Proposal 130 §2.2) — the source site's measurement
 * surface, detected as FACTS (vendor + public id + category) and rebuilt by
 * the generated project with first-party, consent-gated delivery. The forge
 * never pastes the source's vendor snippets.
 */
export type TrackerCategory =
	| 'analytics'
	| 'advertising'
	| 'session'
	| 'consent'
	| 'verification'
	| 'server';

/** One vendor found on the source (or declared by the operator). */
export interface ForgeTracker {
	/** Canonical vendor slug (e.g. 'google-analytics-4', 'meta-pixel'). */
	vendor: string;
	category: TrackerCategory;
	/** Operator decision — detection NEVER enables a tracker. */
	enabled: boolean;
	/** Extracted public ids (measurementId, pixelId, containerId, script…). */
	ids: Record<string, string>;
	/** Provenance for the report (which fingerprints matched). */
	detectedBy: string[];
	confidence: 'high' | 'medium' | 'low';
	/** The source gates this behind a consent layer (banner + opt-in). */
	sourceConsented?: boolean;
	/** How it was found: rendered HTML, browser probe, or operator entry. */
	detectedVia?: 'html' | 'probe' | 'operator';
	/** Dead vendor (Universal Analytics) — reported, never rebuilt. */
	deprecated?: boolean;
	notes?: string[];
}

/** The consent layer baked into the generated project. */
export interface ForgeConsent {
	/**
	 * forge = our banner + loader; external-cmp = load the vendor's script and
	 * wait for it; consent-mode-only = advanced Consent Mode (cookieless
	 * pings, no banner from us); none = no consent layer (warns).
	 */
	mode: 'forge' | 'external-cmp' | 'consent-mode-only' | 'none';
	/** Denied is the only compliant default; 'granted' is a US-only opt-in. */
	default: 'denied' | 'granted';
	/** basic blocks tags until consent; advanced sends cookieless pings. */
	strategy?: 'basic' | 'advanced';
	/** ms to wait for a CMP update (Google's default is 500). */
	waitForUpdate?: number;
	/** Honour navigator.globalPrivacyControl (recommended). */
	gpc?: boolean;
	/** Per-category operator overrides (promote/demote). */
	categories?: Partial<Record<'analytics' | 'advertising' | 'functional' | 'personalization', boolean>>;
	/** The source CMP (recorded; loaded only in external-cmp mode). */
	cmp?: { vendor?: string; script?: string; cookieName?: string };
	policy?: { privacy?: string; cookies?: string };
	banner?: { position?: 'bottom' | 'bottom-left' | 'modal'; theme?: 'auto' | 'light' | 'dark' };
	/**
	 * Emit the CSP as ENFORCING instead of Report-Only (Proposal 130 §2.11).
	 * Default false: an enforcing CSP that misses one host takes a live site
	 * down, so the generated `_headers` starts in report-only.
	 */
	enforceCsp?: boolean;
	/** External checkout URL that receives the consent handoff (§2.8). */
	checkoutUrl?: string;
}

/** Ownership-verification tokens (Search Console, Bing, Yandex, …). */
export interface ForgeVerification {
	google?: string;
	bing?: string;
	yandex?: string;
	pinterest?: string;
	facebook?: string;
	/** Static proof files copied into public/ verbatim (path + exact body). */
	files?: Array<{ path: string; content: string }>;
}

/** The full tracking block a profile carries. */
export interface ForgeTracking {
	trackers: ForgeTracker[];
	consent: ForgeConsent;
	verification?: ForgeVerification;
	delivery?: {
		/** deferred (default) injects after consent; inline only with advanced. */
		mode?: 'deferred' | 'inline' | 'partytown';
		partytown?: boolean;
	};
	ecommerce?: {
		events?: 'ga4' | 'none';
		/** Exactly one owner of the purchase event — never both. */
		purchaseSource?: 'wordpress' | 'forge';
	};
	/** Hosts the deploy headers must allow (computed from enabled vendors). */
	csp?: { hosts: string[] };
	analyzedAt?: string;
	pagesAnalyzed?: string[];
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

/**
 * The deploy runtime a forged site ships as (Proposal 135).
 *
 *  - `static`  — a prebuilt, flat `dist/` on the site branch. No Node process;
 *                velocity serves the files (a no-op build satisfies Velocity).
 *  - `managed` — the same mirror branch plus a Node entry (`server.js`). The
 *                app owns `/api/*`, `/manage`, `/mcp` and `/api/revalidate`
 *                through an Astro `src/fetch.ts` BFF, so the WordPress origin
 *                never has to be publicly reachable from a browser.
 */
export type ForgeRuntime = 'static' | 'managed';

/**
 * The deploy block (Proposal 135 §4) — how the forged site is hosted and how
 * it reaches WordPress at runtime. Every field is optional; an absent block
 * means the historical static tier.
 */
export interface ForgeDeploy {
	/** Runtime shape (default `static`). */
	runtime?: ForgeRuntime;
	/**
	 * The public origin shoppers/visitors use (canonical host). Defaults to
	 * ASTRO_SITE at build time and then to `profile.url`. This is NOT the
	 * WordPress origin when the CMS lives on `cms.<domain>`.
	 */
	publicOrigin?: string;
	/** Managed only: expose the token-gated `POST /api/revalidate` route. */
	revalidate?: boolean;
	/** Managed only: serve the `nvoos-astro-bridge` `/manage` surface. */
	manage?: boolean;
	/**
	 * How rebuilt forms reach the forge bridge: `bridge-direct` posts from the
	 * browser (needs a publicly reachable CMS), `bff-proxied` posts through the
	 * managed site's BFF (keeps the CMS private). Default `bridge-direct`.
	 */
	forms?: 'bridge-direct' | 'bff-proxied';
}

/**
 * The media block (Proposal 139) — where images come from and whether the
 * build keeps them local. Every field is optional; absent = the defaults
 * (CMS host only, localization on).
 */
export interface ForgeMedia {
	/**
	 * Extra hosts Astro may optimize and the localizer may mirror: a CDN, an
	 * image proxy, or a second domain the media still lives on. The CMS host
	 * is always authorized. Do NOT list a host that serves WordPress media
	 * under a path that the CMS also serves — a stale `/wp-content/` URL is
	 * repaired to the CMS origin instead (Proposal 139 §repair).
	 */
	hosts?: string[];
	/**
	 * Mirror remote media into `dist/media` after the build (default true).
	 * Astro already localizes the images it optimizes; this pass covers body
	 * content, site chrome and non-optimized images so the deployed artifact
	 * never hotlinks the CMS.
	 */
	localize?: boolean;
	/** Per-file download cap in MB (default 25). Larger files stay remote. */
	maxFileMb?: number;
	/**
	 * The mirror strategy, overriding the runtime-derived default (Proposal
	 * 140). Absent = `local` for the static tier, `proxy` when the bridge is
	 * deployed. Set `local` on a managed site when the artifact must be
	 * self-contained (a CMS move then never breaks the deploy).
	 */
	mode?: 'local' | 'proxy' | 'off';
}

/** One operator-configured WordPress site. */
export interface SiteProfile {
	/** Root URL (https://…). No trailing slash. */
	url: string;
	/** Display name (discovery fills it when absent). */
	name?: string;
	/**
	 * The media block (Proposal 139) — extra authorized image hosts and the
	 * build-time localization pass. Absent = CMS host only, localization on.
	 */
	media?: ForgeMedia;
	/**
	 * The deploy block (Proposal 135) — the runtime tier and the public-vs-CMS
	 * origin split. Absent = the static tier (backwards compatible).
	 */
	deploy?: ForgeDeploy;
	/** Page slug to render at `/` (the WordPress static front page). */
	frontPage?: string;
	/**
	 * How the site navigates — an OPERATOR SETTING, not an inference.
	 *
	 * `--inventory` derives a verdict into `inventory.shape`, but that is a
	 * report line: it never drove the build. This field is the decision. When it
	 * is absent the derived verdict is used; when both are absent the site is
	 * treated as multi-page (the safe default).
	 *
	 * `one-pager` means the header nav is same-page anchors and the page ids are
	 * the navigation contract — the sanitizer keeps those ids for exactly this
	 * reason, and the scaffold records the anchors in the manifest so the header
	 * can link them.
	 */
	shape?: 'one-pager' | 'multi-page' | 'hybrid';
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
	 * The tracking block (Proposal 130) — the source's measurement and consent
	 * surface (analytics, ad pixels, verification tokens, CMP) rebuilt with
	 * first-party, consent-gated delivery. Absent = the generated project
	 * emits no tracking markup at all.
	 */
	tracking?: ForgeTracking;
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
	 * Site-level SEO facts captured from the source's RENDERED head
	 * (Proposal 131 §2.5). wp/v2 does not expose `rank_math_description` on
	 * every install — jayainc's returns only id/slug/title/excerpt — so the
	 * homepage's own `<meta name="description">` is the only exact source.
	 * The template uses it as the front page's description fallback.
	 */
	seo?: { description?: string; ogDescription?: string };
	/**
	 * Per-page band facts (Proposal 115 extension) — the same home-page
	 * measurement applied to every page the rebuild renders, keyed by page slug.
	 * A positional band list is per-PAGE data: the front page's band heights and
	 * surfaces are not the sub-pages' (a doomsday article rendered as a 7,497px
	 * band before this existed). Absent → sub-pages keep the homepage surfaces
	 * positionally, as before.
	 */
	pageBands?: Record< string, ForgePageBands >;
	/**
	 * Measured source geometry (`analyze --geometry`), one box per band per
	 * viewport. `verify --geometry` diffs the built site against it — the gate
	 * the language-parity score cannot provide (Proposal 114 §3.6).
	 */
	geometry?: ForgeGeometry;
	/**
	 * Measured per-band presentation facts that are NOT box geometry — the
	 * inner container width and the band's own heading/body typography
	 * (`analyze --geometry`). Kept beside `geometry` rather than inside it
	 * because geometry is keyed by VIEWPORT name, and these are one-per-band
	 * facts: a raw-HTML band renders through the template's prose defaults and
	 * produced UA heading sizes (a 40px source `<h3>` came out at 15.21px =
	 * 1.17em of the 13px body).
	 */
	bandFacts?: Record< string, ForgeBandFacts >;
	/**
	 * The measured PAGE background (`analyze --geometry`). A builder paints the
	 * body, and every band with a transparent surface — plus every margin between
	 * bands — shows it. jayainc's body is #e5e5e5; the rebuild's white body turned
	 * the source's own band margins into bright white strips while scrolling.
	 */
	pageBackground?: string;
	/**
	 * The measured sticky-header behaviour (`analyze --geometry`). A source
	 * header that pins on scroll and changes appearance — the classic
	 * transparent-over-the-hero header that gains a solid background and shrinks
	 * once the page moves — cannot be inferred from the markup: the class the
	 * theme adds on scroll (`shrink`, `is-sticky`, `navbar-fixed`) carries no
	 * colours, and the resting rule is often transparent. The browser is the
	 * only faithful source, so this is a measurement.
	 */
	stickyHeader?: ForgeStickyHeader;
	/**
	 * The animated-effect inventory (`analyze --animations`) — CSS keyframes,
	 * transitions, WAAPI, library signatures, video/SMIL. `verify --animations`
	 * compares the built page's `document.getAnimations()` against it.
	 */
	animations?: ForgeAnimationInventory;
	/**
	 * Per-page animation inventories (`analyze --animations-pages N`). The
	 * homepage inventory drives the generated CSS; these are reported in
	 * MOTION.md so a sub-page's motion is visible without pretending the
	 * homepage's band ids apply there. Refreshed only by a run that measures them.
	 */
	pageAnimations?: Record< string, ForgeAnimationInventory >;
	/**
	 * The content baseline (`analyze --text`): the source's normalised visible
	 * text, page-level and per band. `verify --text` diffs the build against it
	 * — the gate neither the design-language score nor the fact audit can give
	 * (both pass while words go missing).
	 */
	text?: ForgeTextBaseline;
	/**
	 * The colour-system baseline (`analyze --colors`): computed background/text
	 * per colour scheme and region. `verify --colors` diffs the build against it
	 * with CIEDE2000.
	 */
	colors?: ForgeColorModes;
	/**
	 * The gate-policy block (`lib/gate-policy.ts`). The forge serves many sites
	 * built by different page builders, so a gate's MEASUREMENT is shared but its
	 * BLOCKING-ness cannot be: a pixel diff rides on the source's font
	 * rasterisation, image renditions and third-party widgets, and a box delta can
	 * absorb sub-pixel font-metric drift on a band of long prose. This block lets a
	 * site DOWNGRADE one of the three addressable gates (`pixel parity`,
	 * `pixel regression`, `box geometry`) to `advisory`, which keeps the
	 * measurement and the scorecard line without failing the run. Content gates are
	 * fixed — see the header of `lib/gate-policy.ts` for why. Absent = defaults
	 * (`pixel parity` resolves `auto`: advisory until a source seam exists).
	 */
	verify?: ForgeVerify;
	/**
	 * The contact band (issue #7549). A forged site used to lose the source's
	 * contact block entirely when the band was theme-injected (virtuallyjamaica:
	 * the `#contact` section is not in `post_content`), leaving a dead one-pager
	 * anchor. When this block is present the front page renders a `#contact`
	 * band; its form posts through `forms.delivery` (Proposal 138) — `direct`
	 * calls the site's own provider-backed route, `bridge` calls WordPress.
	 * Without a transport the band renders as copy + a mailto link, never a
	 * form pointed at a dead endpoint.
	 */
	contact?: ForgeContact;
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
	/**
	 * The handoff lock (see `lib/lock.ts`). Once a site is synced and handed to
	 * a developer, the profile carries the owner record so CI and the CLI can
	 * refuse to re-forge it (`forge-sites.yml`, `forge deploy`, `scaffold`).
	 * Mirrors `forge.lock.json` in the generated project.
	 */
	locked?: { at: string; by?: string; reason?: string };
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
	/** Horizontal padding. Optional: baselines recorded before it was measured
	 * stay valid and are simply treated as 0. */
	paddingLeft?: number;
	paddingRight?: number;
	/**
	 * The heights of the band's direct COLUMNS, in DOM order (px).
	 *
	 * A builder row often gives its columns deliberately different heights —
	 * virtuallyjamaica's HOW IT WORKS row is a 700px fixed-photo column beside a
	 * 948px copy column — and a rebuilt grid that stretches or equalises them
	 * shows a gap the source does not have. Recorded so `diffGeometry` can catch
	 * the drift instead of leaving it to a screenshot.
	 */
	columns?: number[];
	/**
	 * The REBUILT band's kind (`data-section` on the wrapper), recorded only when
	 * measuring a build.
	 *
	 * A component band (a `hero`, a `split`, an `icon-list`, …) is forged from a
	 * component, not from the source's raw markup, so it legitimately has no
	 * builder columns — virtuallyjamaica's contact hero measures 2 source
	 * columns and 0 build columns and that is CORRECT. Without this tag the
	 * column diff reads the absence as a lost row and warns on every component
	 * band; with it, the diff grades the band box and leaves the internal
	 * arrangement to the component's own gates.
	 */
	kind?: string;
}

/** Measured band geometry: section id → viewport name → box. */
export type ForgeGeometry = Record< string, Record< string, BandGeometry > >;

/**
 * One page's measured bands (`analyze --geometry --pages`).
 *
 * A positional band list is PER-PAGE data: the homepage's `minHeight`,
 * padding and typography are not the sub-pages', and applying them positionally
 * to every page forced a 7,497px article band (monicarajiv.com, 2026-11-11).
 * The sections keep the same stable-band ids the scaffold bakes into
 * `pages.generated.ts`, so a built sub-page's `data-section-id` matches.
 */
export interface ForgePageBands {
	/** The source URL this page's bands were measured from. */
	url: string;
	measuredAt: string;
	/** The builder adapter that segmented the page (`flatsome`, `elementor`…). */
	builder?: string;
	/** The page's bands in DOM order (surfaces + measured facts). */
	sections: ForgeSection[];
	geometry: ForgeGeometry;
	bandFacts: Record< string, ForgeBandFacts >;
}

/**
 * The measured sticky-header facts (see `SiteProfile.stickyHeader`).
 *
 * Captured by scrolling the live page and re-reading the computed styles: the
 * source's scroll handler (or its CSS class) is the only thing that knows what
 * the pinned header looks like.
 */
export interface ForgeStickyHeader {
	/** The header pins (position fixed/sticky, or a sticky class appeared on
	 * scroll). */
	sticky: boolean;
	/** The source's own `position` at rest — `fixed` means the header floats
	 * OVER the first band (the band flows beneath it), `sticky` means it holds
	 * its place in the flow. */
	position?: string;
	/** Computed background at scroll position 0 — often fully transparent for
	 * a header that floats over a hero photo. */
	restBg?: string;
	/** Computed text colour at scroll position 0. */
	restInk?: string;
	/** The header's height at rest, in px. */
	restHeight?: number;
	/** Computed background once the page has scrolled past the trigger. */
	pinnedBg?: string;
	/** Computed text colour once the page has scrolled past the trigger. */
	pinnedInk?: string;
	/** The pinned header's height, in px (a shrinking sticky header). */
	pinnedHeight?: number;
	/** The pinned box-shadow (the sticky strip's separation from the page). */
	shadow?: string;
	/** True when scrolling actually CHANGES the background — the fact the
	 * rebuild animates. */
	bgChanged?: boolean;
	/** True when the pinned header is shorter than the resting one. */
	heightChanged?: boolean;
}

/**
 * One measured typography fact (a heading level or the body paragraph).
 * Values are the browser's COMPUTED styles, so they are already resolved to
 * px and already include the builder's stylesheet — the rebuild can emit them
 * verbatim without guessing at the source's theme variables.
 */
export interface BandTypeFact {
	/** Computed `font-size` (px). */
	size?: string;
	/** Computed `font-weight`. */
	weight?: string;
	/** Computed `line-height` (px when the source resolves it to a length). */
	lineHeight?: string;
	/** Computed `letter-spacing` (px). */
	letterSpacing?: string;
	/** Computed `text-transform`. */
	transform?: string;
	/** Computed `text-align` — the EFFECTIVE (inherited) alignment, which is how
	 * the source centers a band's copy from a wrapper class. */
	align?: string;
	/** The first family of the computed `font-family`. */
	family?: string;
	/**
	 * Computed `color`.
	 *
	 * The source's palette does not survive a WordPress round trip: a builder
	 * writes per-element colours as inline `style="color:#262626 !important"`
	 * and the sanitizer drops every `style` attribute, so a rebuilt heading fell
	 * back to whatever the template's accent happened to be (virtuallyjamaica's
	 * accordion titles rendered `#fe6c61` where the source is `#262626`).
	 *
	 * Optional: a profile captured before this fact existed simply carries no
	 * colour, and the template default applies as before.
	 */
	color?: string;
	/**
	 * Computed `max-width` — a heading the builder deliberately narrows.
	 * jayainc's services title is `max-width: 473px` inside a 640px column and
	 * wraps to three lines; rendered full width it took two, and the band's
	 * pinned height exposed the 64px shortfall as dead space.
	 */
	maxWidth?: string;
	/**
	 * What the source CHANGES at the template's tablet/mobile breakpoints, and
	 * only that. A 55px hero headline is 22px on a phone — emitting the desktop
	 * size at every width made every rebuilt band taller than its source at
	 * 390px (virtuallyjamaica's features band: 1808px against 1525).
	 */
	tablet?: BandTypeProperties;
	mobile?: BandTypeProperties;
}

/** A BandTypeFact without its own device overrides (one device's properties). */
export type BandTypeProperties = Omit< BandTypeFact, 'tablet' | 'mobile' >;

/**
 * One measured presentation declaration set for a class the band keeps, scoped
 * to that band (`padding:40px 15.5938px`, `margin:0 0 18px 0`).
 *
 * The builder's spacing scale is NOT a fixed ladder — Brando's
 * `margin-four-bottom` resolves to 7.8px on an icon and 21.6px on a paragraph,
 * and `padding-four-all` is 3.4rem on desktop but `40px 15.5938px` at 390px.
 * Only a browser can resolve it, so these are MEASURED per device and re-stated
 * verbatim rather than guessed from the class name.
 */
export interface BandBoxDeclaration {
	/** Declarations at the desktop breakpoint (the base). */
	base?: string;
	/** Declarations the source CHANGES inside the tablet range. */
	tablet?: string;
	/** Declarations the source CHANGES inside the mobile range. */
	mobile?: string;
}

/**
 * The measured box facts of one band, keyed by a class the band carries.
 *
 * Each entry is recorded only when the class resolves to the SAME padding and
 * margin on every instance inside that band — a class the source uses at two
 * different sizes would otherwise get one arbitrary value. Classes that appear
 * once (Brando's per-column `brando_column_<id>` hashes) are always consistent
 * and carry the column padding the rebuild otherwise loses.
 */
export type BandBoxFacts = Record< string, BandBoxDeclaration >;

/**
 * One column of a band's grid, measured (`collectBandColumnFacts`).
 *
 * Builder columns carry layout the markup cannot express once the vendor
 * stylesheet is gone: virtuallyjamaica's left column is `min-height: 700px`
 * with `background: url(...) fixed / cover`, its sibling 948px of copy. The
 * sanitizer drops the inline `min-height` (layout fights the band rhythm), so
 * the browser measurement is the only faithful source.
 */
export interface ForgeBandColumnFact {
	/** The unique class token that identifies the column (the renderer scopes
	 * it to the band, so it must be CSS-safe and appear once in the band). */
	selector: string;
	/** The rendered border-box height (px). */
	height?: number;
	/** The rendered background attachment — `fixed` is a parallax/fixed photo. */
	attachment?: 'fixed' | 'scroll';
	/** The rendered `background-size` (`cover`, `contain`, `auto`). */
	size?: string;
	/** The column paints a background image. */
	image?: boolean;
}

/** The band's columns per device. Sparse: a device with no grid is absent. */
export interface BandColumnFacts {
	desktop?: ForgeBandColumnFact[];
	tablet?: ForgeBandColumnFact[];
	mobile?: ForgeBandColumnFact[];
}

/**
 * One PAGED carousel inside a band, measured per device
 * (`collectBandCarouselFacts`).
 *
 * A slider's geometry lives entirely in the vendor's JS options — `items: 5`,
 * `margin: 0`, `responsive: { 834: { items: 2 } }` — which neither the markup
 * nor the sanitized stylesheet carries. The rebuild therefore guessed with
 * `flex: 1 1 0` ("one lane of marks", correct for a logo strip) and squeezed
 * virtuallyjamaica's seven-slide gallery into seven 129px columns at 1440, where
 * the source shows five 222px slides and paginates the remaining two.
 *
 * `perView` is the only number the layout needs: a slide is
 * `(100% - (perView - 1) * gap) / perView` of the track. `dots` is recorded so
 * the rebuild can reproduce the source's own pagination (`ceil(count / perView)`
 * at every measured viewport) instead of inventing one.
 */
export interface ForgeBandCarouselFact {
	/** The carousel root's unique class token inside the band. */
	selector: string;
	/** Slides in the track. */
	count: number;
	/** Slides visible at rest (rounded track width ÷ slide width). */
	perView: number;
	/** Space between slides (px). Builders usually pad the SLIDE instead, so
	 * this is frequently 0 and the visual gutter comes from a box fact. */
	gap?: number;
	/** Pagination dots the source rendered (0 when the slider has none). */
	dots?: number;
}

/** The band's carousels per device. Sparse: a device without one is absent. */
export interface BandCarouselFacts {
	desktop?: ForgeBandCarouselFact[];
	tablet?: ForgeBandCarouselFact[];
	mobile?: ForgeBandCarouselFact[];
}

/** The measured presentation facts of one source band (see `SiteProfile.bandFacts`). */
/**
 * One band's inner container as the browser measured it at one viewport.
 *
 * `content` is the width the columns get; `padX` is the container's own side
 * padding — the source's gutter. Both are needed, because the rebuild's
 * `max-width` is a BORDER-BOX value: `content + 2 * padX`. Deriving either from
 * a constant is what made a fluid band full-bleed on a phone (0px of the
 * source's 15) and left a boxed band 64px-compensated at 390px, where the
 * template's own gutter is 16.
 */
export interface ForgeBandContainer {
	/** The container's CONTENT width — the columns' available width. */
	content: number;
	/**
	 * The container's own side padding: the LARGER of left/right, in px.
	 *
	 * Symmetric in every builder convention (`15px`, `10px`, `1rem`), and
	 * `--ds-pad-x` can only express one value. On the rare asymmetric container
	 * this keeps the CONTENT width exact (the `max-width` compensates for both
	 * sides) and centres a slightly wider box, which is invisible because the
	 * band background lives on the wrap, not the container.
	 */
	padX: number;
	/**
	 * Whether the band's own grid CANCELS that gutter.
	 *
	 * A Bootstrap-family `.row` carries a NEGATIVE horizontal margin that pulls
	 * its columns back out over the container's padding — which is why
	 * `.container` padding and `.row` margin only work as a pair, and why
	 * restating one without the other shrank virtuallyjamaica's #wifi to 1376px
	 * against a 1440px source. When this is true the rebuilt band must render
	 * FLUSH (`--ds-pad-x: 0`) so its columns span the container's border-box; the
	 * per-column padding is already in the band's `box` facts. False means the
	 * columns genuinely sit inside the gutter, so `padX` applies.
	 */
	flush?: boolean;
}

/**
 * A state effect drawn by a CONTROL'S CHILD rather than the control itself.
 *
 * Builder buttons paint their hover fill with an overlay span (Auxin's
 * `.aux-overlay`, Elementor's `.elementor-button-content-wrapper`) and the anchor
 * itself reports no computed change at all — jayainc's three buttons were
 * measured as "no states" before this existed, while the button visibly changed
 * on hover. The child is diffed only while the PARENT is forced, because that is
 * how the source's selector works (`a:hover .aux-overlay`).
 */
export interface ForgeInteractionChildFact {
	/** Selector relative to the control (`span[class~="aux-overlay"]`). */
	selector: string;
	/** Rest layout as a share of the control (`position:absolute; top:50%; width:119.3%`). */
	base?: string;
	/** The child's `::after` slot, when that is what paints the fill. */
	pseudo?: string;
	/** The child's own easing, emitted under `prefers-reduced-motion` rules. */
	transition?: string;
	hover?: string;
	focus?: string;
	focusVisible?: string;
	active?: string;
}

/**
 * One measured interactive control's STATE deltas (`analyze` interaction pass).
 *
 * A rebuilt band is the source's own sanitized markup with the vendor sheet
 * gone, so every `:hover` / `:focus-visible` / `:active` effect disappears while
 * the resting page still looks right. The states are measured by forcing each
 * pseudo-class in a real browser (CDP `CSS.forcePseudoState`, the API behind
 * DevTools' `:hov` panel) and diffing the computed style.
 *
 * Only CHANGED declarations are stored, as kebab-case CSS (`color:rgb(…)`). The
 * signature (tag + sorted class tokens + input type) is what the scaffold turns
 * into a band-scoped selector.
 */
export interface ForgeInteractionFact {
	/** Lower-case tag name (`a`, `button`, `input`). */
	tag: string;
	/** Every class token on the representative element, sorted. */
	classes: string[];
	/** `input`/`button` type attribute, when the element has one. */
	type?: string;
	/** How many elements in the band share the signature (diagnostic). */
	count?: number;
	/** Rest-state declarations worth re-stating (the source's `cursor`). */
	base?: string;
	/** The source's computed `transition` shorthand (the easing BETWEEN states). */
	transition?: string;
	/** Pointer-over deltas. */
	hover?: string;
	/** Element-focus deltas (mostly form fields). */
	focus?: string;
	/** Keyboard-focus deltas — `:focus-visible`, not `:focus` (MDN/WCAG 2.4.7). */
	focusVisible?: string;
	/** Pressed deltas; measured as `hover + active` (a source writes `:hover:active`). */
	active?: string;
	/**
	 * `false` when the element cannot receive keyboard focus (a content card
	 * whose hover the source styled, but which is not a link/button/field).
	 * Emitted only in the negative: an ABSENT flag means focusable, so an older
	 * profile keeps the focus-indicator requirement for its controls. The
	 * `verify --interactions` gate skips the WCAG 2.4.7 indicator check for
	 * these — a non-interactive card has no keyboard state to indicate.
	 */
	focusable?: boolean;
	/**
	 * Effects the control's DIRECT CHILDREN draw while the control is in a state
	 * (the overlay-fill button pattern). Direct children only: that is where
	 * every builder puts the overlay, and a deeper walk multiplies the pass for
	 * nothing.
	 */
	children?: ForgeInteractionChildFact[];
}

/**
 * One band's measured interactions (see `SiteProfile.interactions`).
 */
export type ForgeBandInteractions = ForgeInteractionFact[];

export interface ForgeBandFacts {
	/**
	 * The width of the band's own inner container. Builder sources box their
	 * content (`.container`, 1140px) or run fluid (`.container-fluid`, the
	 * viewport), and the rebuild's site measure is neither — virtuallyjamaica's
	 * services band renders 1136px against the source's 1440px.
	 *
	 * DESKTOP only — legacy. Prefer `container`, which carries every device.
	 */
	containerWidth?: number;
	/**
	 * The band's inner container, measured per DEVICE (`analyze --geometry`).
	 *
	 * One desktop width applied to every viewport made the gutter a desktop
	 * decision: a fluid band rendered full-bleed on a phone (0px of the source's
	 * 15) and a boxed band stayed 64px-compensated at 390px, where the template's
	 * own gutter is 16. The rebuild needs the container's BORDER-BOX width as its
	 * `max-width` and the container's own side padding as its `--ds-pad-x`, and
	 * neither is a constant across builders (Bootstrap 15px, Elementor 10px,
	 * Brando 30px) or across viewports.
	 *
	 * Recorded ONLY when the container is a strict descendant of the band. When
	 * the band itself serves as the container its padding already reaches the
	 * rebuild through the measured band padding, and emitting it here too would
	 * apply the same inset twice.
	 */
	container?: {
		desktop?: ForgeBandContainer;
		tablet?: ForgeBandContainer;
		mobile?: ForgeBandContainer;
	};
	/** Heading facts keyed by tag (`h1`…`h6`); the band's LARGEST instance wins. */
	headings?: Record< string, BandTypeFact >;
	/** The band's body-paragraph fact (the body scale as rendered, not declared). */
	body?: BandTypeFact;
	/**
	 * Selectors for subtrees the SOURCE renders hidden at rest (accordion panels,
	 * tab panes, mega-menus, mobile drawers) — hidden at BOTH measured
	 * breakpoints, so a responsive state is never mistaken for a resting one.
	 * The rebuild emits them as `display:none` scoped to the band.
	 */
	hidden?: string[];
	/**
	 * Every class token present in the band (measured). The scaffold trims the
	 * builder's utility pack to what the page actually uses, so the emitted CSS
	 * carries only the vendor conventions this site relies on.
	 */
	classes?: string[];
	/**
	 * The measured padding/margin of the classes this band keeps, per device.
	 * The class names survive sanitisation, so re-stating their measured box is
	 * how a raw-HTML band keeps its layout without the vendor stylesheet.
	 * Emitted AFTER the utility pack, so a measured value always wins over a
	 * hand-authored approximation.
	 */
	box?: BandBoxFacts;
	/**
	 * The band's interactive controls' measured STATE deltas — hover, focus,
	 * focus-visible, active — plus the source's own pointer cursor and easing.
	 * Emitted as band-scoped `:hover` / `:focus-visible` / `:active` rules; the
	 * `:hover` and `:active` ones are wrapped in `@media (hover: hover)` so a
	 * touch device never keeps a sticky hover.
	 */
	interactions?: ForgeBandInteractions;
	/**
	 * Cropped-media heights, keyed by class and emitted ONLY as `img.<class>`.
	 *
	 * Kept apart from `box` because a class can sit on an image AND on a
	 * paragraph (`padding-six-lr` does both) — a fixed 711.5px image height
	 * applied to the paragraph made it 712px tall (virtuallyjamaica, 2026-11-11).
	 */
	media?: BandBoxFacts;
	/**
	 * The source's crop anchor for this band's `object-fit: cover` photos —
	 * `object-position` as computed (`63% 90%`). A builder writes it in a
	 * `<style>` block sanitisation drops, so copying the markup re-crops every
	 * photo to the centre (Flatsome's hero, monicarajiv.com 2026-11-11). Emitted
	 * as `--ds-media-pos`; the default (`50% 50%`) is never recorded.
	 */
	mediaPosition?: string;
	/**
	 * The hero track's column split, measured (`collectBandSplitFacts`): the
	 * normalised width fraction of each direct child of the structural
	 * copy + illustration row. The markup carries no width class — the ratio
	 * lives in the vendor stylesheet — so a guessed ratio wraps the copy
	 * differently (virtuallyjamaica's 60/40 hero wrapped its H1 to four lines
	 * at 55/45 where the source has three). Emitted as `--ds-split`.
	 */
	splitColumns?: number[];
	/**
	 * The band's grid COLUMNS per device: rendered heights, background photos
	 * and their attachment (`collectBandColumnFacts`). Emitted by the scaffold
	 * as band-scoped `.column-class` rules, because the sanitized markup keeps
	 * the classes but the vendor sheet — and the inline min-height — is gone.
	 */
	columns?: BandColumnFacts;
	/**
	 * The band's PAGED carousels per device (`collectBandCarouselFacts`): how
	 * many slides the source shows at once and how it paginates them. The vendor
	 * slider's `items` option is the only place that number exists, so without
	 * this fact every gallery rebuilds as an equal split of the track.
	 */
	carousel?: BandCarouselFacts;
	/**
	 * The band contains the source's OWN vertical-centring device: a
	 * `table-cell` with `vertical-align: middle` (Brando's slider), or a
	 * flex/grid container filling half the band with centred items
	 * (Elementor's `e-con`). A full-height band is not automatically centred —
	 * virtuallyjamaica's contact band is top-aligned under 130px of padding,
	 * and centring it moved the copy 151px down.
	 */
	centeredContent?: boolean;
}

/** The contact band copy (see `SiteProfile.contact`, issue #7549). */
export interface ForgeContact {
	heading?: string;
	blurb?: string;
	/** Shown as a mailto link when no form transport is configured. */
	email?: string;
}

/** One band's normalised visible text from the source (`lib/text-parity.ts`). */
export interface ForgeTextSection {
	id: string;
	text: string;
}

/**
 * The content baseline (`analyze --text`), mirroring `geometry`: captured from
 * the source once, diffed against the build by `verify --text`. Text is stored
 * NORMALISED (NFKC, punctuation folded, whitespace collapsed, lowercased) so a
 * comparison never re-litigates the same editorial noise.
 */
export interface ForgeTextBaseline {
	capturedAt: string;
	url: string;
	/** Normalised visible text of the source homepage. */
	page: string;
	/** Per-band text, for bands whose source node could be located. */
	sections: ForgeTextSection[];
	/** Band ids with no extractable source text (reported, never failed). */
	skipped: string[];
}

/** One ink in a region's palette: a colour plus its share of the region's text. */
export interface InkShare {
	color: string;
	/** Share of the region's rendered text mass, 0–1, rounded to 2 decimals. */
	share: number;
}

/** One region's computed surface + text colour (`#rrggbb` or `transparent`). */
export interface RegionColors {
	background: string;
	text: string;
	/**
	 * The region's measured ink PALETTE — every colour carrying at least 4% of the
	 * region's rendered text mass (`inkMass`), dominant first, shares summing to
	 * roughly 1.
	 *
	 * `text` is `inks[0].color` and stays for human output and for baselines
	 * recorded before the palette existed. The palette exists because a band's
	 * text is frequently two colours (a white lede over grey list items); gating
	 * only the dominant one let the minority ink regress silently
	 * (virtuallyjamaica's `#services` grey `li`s turned white, 2026-11-12).
	 */
	inks?: InkShare[];
	/**
	 * The region's painted background IMAGE, when it has one — a `linear-gradient(…)`
	 * or a `url(…)`. Bands only (`ColorRegion.descendantFill`): a builder paints a
	 * band's gradient on a child layer and the sanitizer drops the `<style>` that
	 * declared it, so the browser is the only source. Absent on body/header/footer
	 * and on flat surfaces.
	 *
	 * A gradient is WIRED (`applyBandSurfaces` → `ForgeSection.bgGradient`); a photo
	 * `url()` is captured but NOT wired — setting `bgImage` makes
	 * `resolveSectionTheme` return `hero`, which is wrong for a content band.
	 * `verify --colors` gates the gradient (`metric: 'image'`), never the URL.
	 */
	image?: string;
}

/**
 * The colour-system baseline (`analyze --colors`): computed background/text
 * per COLOUR SCHEME, per region (body, header, footer), from the source's
 * RENDERED page. Modes are measured separately because a header may be dark
 * in light mode, or the site may theme `prefers-color-scheme` — comparing one
 * scheme only would miss both.
 *
 * Shape: `{ light: { body: {...}, header: {...} }, dark: { ... } }`.
 * `verify --colors` re-measures the build and diffs with CIEDE2000.
 */
export type ForgeColorModes = Record< string, Record< string, RegionColors > >;

/**
 * The closed section-kind vocabulary (Proposal 136 §4.1).
 *
 * Kinds name the BAND'S STRUCTURE, never its styling: the palette, type and
 * motion always come from the source's extracted design language. `content` is
 * the fallback — an unrecognised band is classified as content, never dropped
 * (the 116 "never enumerate widgets" rule, generalised).
 *
 * The six 136 kinds are `pricing`, `stats`, `steps`, `team`, `marquee` and
 * `comparison`; the rest are the 114/114-P7 set.
 */
export const SECTION_KINDS = [
	'hero',
	'feature-grid',
	'icon-list',
	'testimonial',
	'logo-cloud',
	'cta',
	'gallery',
	'text',
	'form',
	'raw',
	'pricing',
	'stats',
	'steps',
	'team',
	'marquee',
	'comparison',
	'content',
] as const;

export type ForgeSectionKind = ( typeof SECTION_KINDS )[ number ];

/** True when `value` is a known section kind (the emitted `data-section`). */
export function isSectionKind( value: unknown ): value is ForgeSectionKind {
	return typeof value === 'string' && ( SECTION_KINDS as readonly string[] ).includes( value );
}

/** One plan in a pricing band. `price` is the source's VERBATIM string (it
 * carries the currency and period — `$98/mo`), so the structured-data gate can
 * string-match it against the visible text. The interfaces live in
 * `semantic.ts` because the classifier and the renderer share them; they are
 * re-exported here for the profile-side code. */
export type {
	PricingPlan,
	StatItem,
	StepItem,
	TeamMember,
	MarqueeItem,
	ComparisonRow,
};

/**
 * One source page section's background facts (Proposal 117 §2) — the
 * per-band theming data the scaffold bakes into sections.generated.ts.
 * Keyed by the Elementor section id the semantic classifier carries on
 * each rebuilt section, so the source band theming re-applies positionally.
 */
export interface ForgeSection {
	/** Elementor's elementor-element-<id> class suffix. */
	id?: string;
	/** The band's classified kind (Proposal 136 §4.2). Advisory: the build
	 * emits it as `data-section` and `verify` compares the two when present.
	 * Absent → the fact gate still requires a valid kind on the built band. */
	kind?: ForgeSectionKind;
	/** The section's own HTML id (the source anchor — #about, #contact…). */
	anchor?: string;
	/**
	 * The source band carries no content of its own — a spacer strip that exists
	 * only to hold a background and vertical rhythm. The analyzer cannot tell
	 * "empty by design" from "copy was lost", so this is a human-pinned fact
	 * (verify the source band really is empty before setting it). The per-band
	 * audit reads it to stop reporting an intentionally empty band as a content
	 * failure — jayainc's `dff3f1a` is a 0-text/0-image grey strip.
	 */
	spacer?: boolean;
	bgImage?: string;
	bgColor?: string;
	bgGradient?: string;
	/** The background-overlay tint (Elementor's overlay layer). */
	overlay?: string;
	/** The source band's declared padding — a CSS shorthand, or a per-device
	 * map when the source scopes it (jayainc's 059421c is 125px on desktop,
	 * 100px on tablet and mobile). Absent → the bandSpacing policy. */
	padding?: string | ResponsiveLength;
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
	/**
	 * The band's MEASURED source height (`analyze --geometry`), mirrored as a
	 * CSS `min-height` floor on the rebuilt band. Band heights are frequently
	 * declared through builder classes (`full-screen`, `min-height-700`) or an
	 * inline `min-height` the sanitizer drops, so they cannot be read off the
	 * markup the way backgrounds can — measurement is the only faithful source.
	 * It is a FLOOR, never a lock: content that grows past it is never clipped.
	 */
	minHeight?: string;
	/**
	 * The band measured EXACTLY the viewport height at every capture breakpoint
	 * (`analyze --geometry`): a full-screen stage — Brando's `.full-screen`,
	 * Elementor's 100vh hero. `minHeight` becomes `100vh` instead of the
	 * captured pixels, because the captured height IS that device's viewport
	 * and must not freeze the stage on a different one. The rebuilt wrap also
	 * carries `ds-section-wrap--full`, which stretches the single-file wrapper
	 * chain so the copy track can centre vertically, as the source did.
	 * `false` is an operator override back to the measured pixels.
	 */
	fullViewport?: boolean;
	/** The measured column split of the hero track (`bandFacts.splitColumns`).
	 * Emitted as `--ds-split`; the structural hero grid consumes it, so the
	 * copy column keeps the source's width and line breaks. */
	split?: number[];
	/** The band measured the source's own vertical-centring device
	 * (`bandFacts.centeredContent`). `true` adds `ds-section-wrap--centered`
	 * (restores the height chain and centring the vendor sheet supplied);
	 * `false` is a MEASURED opt-out — a full-height band the source top-aligns
	 * under its own padding — and clears the hero default. Absent = not
	 * measured, so the template default applies. */
	centered?: boolean;
	/**
	 * The band's typography facts — a per-heading-level map plus the body
	 * paragraph, measured by `analyze --geometry` (`SiteProfile.bandFacts`).
	 * A pin here wins over the measurement, which is how an operator corrects a
	 * band whose largest heading is not the visual title.
	 */
	type?: { headings?: Record< string, BandTypeFact >; body?: BandTypeFact };
	/**
	 * Selectors for subtrees the SOURCE renders hidden at rest — measured by
	 * `analyze --geometry` (`SiteProfile.bandFacts`) and emitted as scoped
	 * `display:none` rules. A pin here wins over the measurement.
	 */
	hidden?: string[];
	/**
	 * The source's crop anchor for this band's cover photos (`object-position`,
	 * e.g. `63% 90%`) — measured by `analyze --geometry` (`bandFacts`) and
	 * emitted as `--ds-media-pos`. A pin here wins over the measurement.
	 */
	mediaPosition?: string;
	/**
	 * The band's measured interaction states (hover / focus / focus-visible /
	 * active), per control signature — measured by `analyze --geometry`
	 * (`SiteProfile.bandFacts[].interactions`). A pin here wins over the
	 * measurement, which is how an operator corrects a state the browser read
	 * from an unusual control.
	 */
	interactions?: ForgeBandInteractions;
	/** The top-level column responsive facts (124 §6) — desktop/tablet/
	 * mobile widths (elementor-col/md/sm-*) + order/visibility flags. The
	 * split components re-measure from these; absent → the template
	 * default ratio. */
	columns?: ForgeSectionColumn[];
	/**
	 * The band's measured COLUMN geometry, keyed by the column's unique class —
	 * a rendered `min-height` floor plus a fixed background photo's attachment
	 * and size (`bandFacts[id].columns`). Emitted by the renderer as band-scoped
	 * CSS; absent → the columns size themselves.
	 */
	columnBox?: BandBoxFacts;
	/**
	 * The band's measured CAROUSEL geometry, keyed by the carousel root's unique
	 * class (`bandFacts[id].carousel`) — the per-view slide count and the track's
	 * own scroll behaviour. Emitted as band-scoped CSS; absent → the shim's
	 * "one lane of marks" fallback (correct only for a logo strip).
	 */
	carouselBox?: BandBoxFacts;
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
	/**
	 * The icon-row rhythm of an icon-list band (Elementor's advanced-text
	 * widgets). Like the card box model this is measured, not band-scoped CSS:
	 * each row carries its own inner padding, a large leading icon box and its
	 * own title size (jayainc's services rows: 36px/50px padding, an 81px
	 * circle with a 45px glyph, 27px/32px titles). Empty → template defaults.
	 */
	iconRowStyle?: {
		/** Per-device: the source pads these rows 63px on desktop and 36/50px at
		 * tablet and mobile. A single value baked the TABLET number and the pinned
		 * band height then showed ~255px of dead space. */
		padTop?: ResponsiveLength;
		padBottom?: ResponsiveLength;
		/** The row's horizontal inset (the source's 50px desktop / 35px mobile). */
		padX?: ResponsiveLength;
		/** The gap BELOW each row (the source's widget margin — 20px on jayainc).
		 * The measured `padding-block` alone left the row pitch 45px short. */
		rowGap?: string;
		titleSize?: string;
		titleLineHeight?: string;
		markerSize?: string;
		markerGlyph?: string;
		markerBackground?: string;
		markerInk?: string;
	};
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

	// -- Proposal 136 §4.1 band slots -----------------------------------------
	// Extracted facts only: no markup, no styles. Each component reads its own
	// slot and renders it with the template's own markup.

	/** Pricing band: plans in source order. The plan carrying `featured` (or the
	 * single highlighted card) wins the emphasis. */
	pricing?: { plans: PricingPlan[]; note?: string };
	/** Stats band: value/unit/label triples in source order. */
	stats?: { items: StatItem[] };
	/** Steps band: an ORDERED list — order is the information. */
	steps?: { items: StepItem[] };
	/** Team band: people with their role (and portrait when the source has one). */
	team?: { people: TeamMember[] };
	/** Marquee band: repeated labels. `speed` only when a CSS animation-duration
	 * fact resolves; the pause control is always rendered (WCAG 2.2.2). */
	marquee?: { items: MarqueeItem[]; speed?: string };
	/** Comparison band: columns then rows; `values` align to the columns. */
	comparison?: { columns: string[]; rows: ComparisonRow[]; caption?: string };
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

/** One item in the source's top contact strip (see `ForgeChrome.topbarItems`). */
export interface ForgeTopbarItem {
	/** The item's text. An embedded newline is the source's own `<br>` (the
	 * address is two lines on jayainc); the template renders it with
	 * `white-space: pre-line`. */
	text: string;
	/** The icon element's class tokens (an `<i class="auxicon-location">` glyph
	 * the source's icon sheet draws — `chrome.iconCss`). */
	icon?: string;
	/** The item's link when the source makes it actionable (`tel:`, a map). */
	href?: string;
}

/** The top contact strip as measured (`measureTopbar`). */
export interface ForgeTopbarFacts {
	items: ForgeTopbarItem[];
	/** Icon-only links at the bar's other end (jayainc's Google-Maps glyph at
	 * the far left). Rendered as a leading group. */
	socials?: Array<{ icon: string; href?: string }>;
	/** Where the item group sits in the bar, measured against the bar's content
	 * edges (jayainc: right). */
	align?: 'start' | 'center' | 'end';
	height?: string;
	iconSize?: string;
	iconInk?: string;
	/** The gap between an icon and its text. */
	iconGap?: string;
	/** The gap between items. */
	itemGap?: string;
}

/**
 * The header's BOX at one viewport, measured (`measureChromeGeometry`).
 *
 * A builder rarely runs its header content full-bleed. jayainc wraps the row in
 * `.aux-container { max-width:1670px; padding:0 35px }`; virtuallyjamaica uses
 * Bootstrap's per-breakpoint `.container` (1170/970/…, 15px padding). A rebuild
 * that keeps `.site-header` as a full-width flex row pins the logo to the
 * viewport edge and the header stretches on a wide monitor — the "logo on the
 * left, should be boxed and not full width" drift (2026-11-12).
 */
export interface ForgeChromeBox {
	/**
	 * The container's cap (border-box px) — recorded only at a viewport where it
	 * is actually BINDING (box narrower than the header), so the fact means "the
	 * row is Npx wide here", not "some ancestor declares a max-width".
	 */
	measure?: number;
	/**
	 * The container's inline padding (the gutter between the box edge and the
	 * brand/nav, px). Read from the container's computed `padding-left`, so it is
	 * valid for a centred brand too — unlike a brand-relative inset.
	 */
	padX?: number;
}

/**
 * The header box per viewport. Sparse: a device with nothing to say is absent.
 *
 * `wide` (1920) exists because a container cap only becomes VISIBLE above it:
 * jayainc's 1670px box looks full-bleed at 1440 (35px gutters) and only shows
 * its centred box at 1920. Without it the gate could never see the cap.
 */
export interface ForgeChromeGeometry {
	wide?: ForgeChromeBox;
	desktop?: ForgeChromeBox;
	tablet?: ForgeChromeBox;
	mobile?: ForgeChromeBox;
}

/** Where a footer group sits horizontally inside the footer's brand row. */
export type ForgeFooterAlign = 'start' | 'center' | 'end';

/**
 * The footer brand ROW at one viewport: the brand mark and the social icons.
 *
 * A builder lays the footer out as columns (virtuallyjamaica: an 8/4 row — the
 * social icons in the left column, the logo `text-right` in the right one, both
 * `xs-text-center` at mobile). A rebuild that stacks them left reads wrong even
 * though every surface and colour matches (2026-11-12).
 */
export interface ForgeFooterBrandAlign {
	/** The brand mark's horizontal position inside the row. */
	logo?: ForgeFooterAlign;
	/** The social icon group's horizontal position inside the row. */
	socials?: ForgeFooterAlign;
	/**
	 * The brand mark's RENDERED height at this viewport (`62px`), so the rebuild
	 * matches the source's mark instead of the template's fixed 3rem.
	 */
	logoHeight?: string;
}

/**
 * The footer brand row per viewport. Sparse: a viewport with nothing to say is
 * absent. `divider` is a whole-element fact (the hairline above the copyright
 * bar) and therefore lives outside the per-viewport map.
 */
export interface ForgeFooterBrand {
	wide?: ForgeFooterBrandAlign;
	desktop?: ForgeFooterBrandAlign;
	tablet?: ForgeFooterBrandAlign;
	mobile?: ForgeFooterBrandAlign;
	/** The sub-footer row paints a bottom hairline above the copyright bar. */
	divider?: boolean;
}

/**
 * The source's opened mobile menu, as the browser paints it.
 *
 * A collapsed nav is not just a hidden list: the source puts it on a SURFACE.
 * virtuallyjamaica's Brando menu is an absolutely positioned panel at
 * `rgba(0,0,0,0.95)` under a transparent fixed header — so a rebuild that only
 * toggles `display` paints white links straight onto the hero photo and the menu
 * is unreadable. Every field is measured with the panel OPEN at 390×844.
 */
export interface ForgeMobileNav {
	/** The panel's painted background (a colour, or the gradient image). */
	background?: string;
	/** The ink the panel's links render in. */
	ink?: string;
	/** The panel's own horizontal padding (px) — the source's link inset. */
	padX?: number;
	/** The panel's own vertical padding (px). */
	padY?: number;
	/** The pitch between link rows (px) — the source's row height. */
	itemPitch?: number;
	/** The panel overlays the page (absolute/fixed) instead of pushing it. */
	overlay?: boolean;
	/** The panel spans the viewport width. */
	fullWidth?: boolean;
}

export interface ForgeChrome {
	/** The top contact strip lines (address, phone). Kept for profiles measured
	 * before `topbarItems` existed; the structured block wins when present. */
	topbar?: string[];
	/**
	 * The top contact strip's STRUCTURE, measured (`measureTopbar`): each item's
	 * text, icon glyph and link. jayainc's strip is two flex groups, each an
	 * icon (`auxicon-location` / `auxicon-phone-classic-on`) beside a two-line
	 * paragraph — the text-only `topbar` lines could never rebuild that.
	 */
	topbarItems?: ForgeTopbarItem[];
	/** Where the strip's items sit in the bar: measured from the item group's
	 * box against the bar's content edges (jayainc: right, `float: right`). */
	topbarAlign?: 'start' | 'center' | 'end';
	/** The strip's measured height (jayainc: 100px — the icons are large and
	 * the address is two lines; the template's 0.5rem padding came out ~42px). */
	topbarHeight?: string;
	/** The icon glyph's measured size / ink / trailing gap. */
	topbarIconSize?: string;
	topbarIconInk?: string;
	topbarIconGap?: string;
	/** The measured gap BETWEEN items (jayainc: 50px). */
	topbarItemGap?: string;
	/** Icon-only links in the strip (the source's leading social glyphs). */
	topbarSocials?: Array<{ icon: string; href?: string }>;
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
	/**
	 * The opened mobile menu's SURFACE and rhythm, measured at 390px with the
	 * panel open (`measureMobileNav`). Absent → the rebuild derives the panel
	 * from the header surface (or the page surface) so it is never transparent.
	 */
	mobileNav?: ForgeMobileNav;
	/**
	 * The header's BOX per viewport: the source's container cap and gutter.
	 * Absent → the template's own full-width flex row and gutter.
	 */
	geometry?: ForgeChromeGeometry;
	/**
	 * The footer brand ROW: where the logo and the social icons sit, measured
	 * per viewport (`measureFooterBrand`). Absent → the template stacks them.
	 */
	footerBrand?: ForgeFooterBrand;
	/**
	 * The source's own mobile nav disclosure (a Bootstrap `navbar-toggle`, a
	 * builder's hamburger button).
	 *
	 * Absent when the source never collapses its nav — the rebuild then keeps
	 * the nav inline at every width, which is what the source does too.
	 * `visible` is the MEASURED presence of the control per viewport, so the
	 * rebuild collapses exactly where the source does instead of guessing a
	 * breakpoint (Brando collapses at 991px, Bootstrap's default at 768px).
	 */
	navToggle?: {
		/** The control's accessible name (`Toggle navigation`, `Menu`, …). */
		label?: string;
		/** Per-viewport visibility of the control in the source. */
		visible?: { desktop?: boolean; tablet?: boolean; mobile?: boolean };
	};
	/** The header's own background (many one-pagers run a dark header the
	 * default white chrome must not paint over). */
	headerBg?: string;
	/** The header text color (inherits to the brand block). */
	headerInk?: string;
	/**
	 * True when the MEASURED resting header is fully transparent — it floats over
	 * the hero photo and paints nothing (virtuallyjamaica's Brando navbar).
	 * `headerBg` is then absent on purpose: a colour harvested from the source
	 * stylesheet would paint a strip the source does not have.
	 */
	headerTransparent?: boolean;
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
	/** The footer social links (the source's network badges). `icon` is the
	 * source's own icon class (e.g. `fab fa-facebook-f`) when one exists — the
	 * rebuild maps the network to its own inline glyph. */
	social?: Array<{ label?: string; href: string; icon?: string }>;
	/** The site favicon (the first <link rel="icon"> href, absolute). */
	favicon?: string;
	/** The source icon-font stylesheet (the icons CSS link, e.g. the theme's
	 * auxin-icon.css) — the rebuild's icon markers carry the source glyph
	 * classes and this sheet draws them (the customer's own asset, like the
	 * content media URLs). */
	iconCss?: string;
	/**
	 * The brand-icon sheet that draws the footer's `fab fa-*` glyphs
	 * (FontAwesome). Separate from `iconCss`: a source often ships a theme icon
	 * font AND FontAwesome, and an unknown network renders with the source's own
	 * glyph class rather than a fabricated inline path.
	 */
	socialIconCss?: string;
	/** The subfooter text color (the source paints white-ish text on its
	 * dark footer — jayainc's rgba(255,255,255,0.7)). */
	footerInk?: string;
	/** The footer's brand logo (the footer-widget media image). */
	footerLogo?: {
		src?: string;
		alt?: string;
		/** The footer block the logo shares a column with, when the source renders
		 * it as the first widget of that column (the brand column) instead of a
		 * standalone strip above the grid. The layout renders it inside the block. */
		blockIndex?: number;
	};
	/** The footer background color (resolved from the footer-widget CSS). */
	footerBg?: string;
	/** The copyright bar's own background (jayainc: a translucent navy
	 * strip below the light-gray subfooter). */
	footerBarBg?: string;
	/** The copyright bar's text color (usually translucent white). */
	footerBarInk?: string;
	/**
	 * True when the SOURCE exposes a theme / colour-mode control.
	 *
	 * The template ships a theme toggle unconditionally, which is an addition the
	 * source may not have — virtuallyjamaica has no dark mode at all and rendered
	 * a stray `light` button in its header. Absent/false → the toggle is not
	 * rendered (dark mode still follows `prefers-color-scheme`).
	 */
	themeToggle?: boolean;
	/** The chrome base font-size — header/topbar/footer run at the source's
	 * chrome size, NOT the content body scale (jayainc: 16px chrome over
	 * a 13px content body). */
	chromeSize?: string;
}

/**
 * One moving piece inside a parallax band (Proposal 121 + the 2026-11-12
 * element pass). Two source systems produce these:
 *
 * - Phlox's `aux-parallax-piece` — `data-parallax-depth/origin/off`. The
 *   theme's `AvertaParallaxBox` flips the depth sign
 *   (`depth = depth < 0 ? 1 : -1`), derives the raw travel from the element's
 *   viewport position (`top`/`bottom`/`middle` origin), clamps it to one
 *   viewport height and disables the effect at `innerWidth <= off`.
 * - Elementor Motion Effects — `motion_fx_*` scrolling JSON. `direction`
 *   `negative` also renders a POSITIVE translateY (measured live on the
 *   jayainc about collage), `speed.size` is the px travel, `affectedRange`
 *   narrows the viewport window and `devices` scopes the breakpoints.
 *
 * Facts only: the rebuild maps them onto a CSS `scroll(root)` timeline.
 */
export interface ParallaxPiece {
	/** The source element id (`elementor-element-<id>` / `data-id`). */
	id?: string;
	/** The piece's image URL when it carries one — the rebuild's media slot
	 * can be matched by src (index is the deterministic fallback). */
	src?: string;
	/** 0-based ordinal among the band's `<img>` elements in document order. */
	index?: number;
	/** `translate` (the default) or `background` (the source animates
	 * `background-position`; the rebuild does not fake it with a transform). */
	type?: 'position' | 'background';
	/** Phlox `data-parallax-depth` — sign AND magnitude. Negative depth moves
	 * DOWN as the page scrolls (the theme flips the sign). */
	depth?: number;
	/** Phlox `data-parallax-origin` — where the raw travel starts. Absent on
	 * Elementor-only pieces (symmetric travel). */
	origin?: 'top' | 'bottom' | 'middle';
	/** The viewport width at or below which the source disables the effect
	 * (`data-parallax-off`; Phlox compares `disablePoint >= innerWidth`). */
	off?: number;
	/** Elementor motion_fx translate direction. */
	direction?: 'negative' | 'positive';
	/** Elementor motion_fx translate speed (`motion_fx_*_speed.size`, px). */
	speed?: number;
	/** Elementor motion_fx affected viewport range (percent, 0-100). */
	range?: { start?: number; end?: number };
	/** Elementor motion_fx device list (`desktop`, `tablet`, `mobile`). */
	devices?: string[];
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
	/** The band's moving pieces, source order (parallax only). A band with
	 * pieces is a REAL parallax band; an `aux-parallax-section` column with
	 * none is only a theme container and must not move on its own. */
	pieces?: ParallaxPiece[];
}

export interface ScrollBehaviorPass {
	/** The source header is sticky (aux-logo-sticky / elementor-sticky). */
	headerSticky: boolean;
	entries: ScrollBehaviorEntry[];
}

/** One keyframe step in a CSS `@keyframes` fact. */
export interface ForgeAnimationStep {
	/** `from`, `to` or a percentage. */
	offset: string;
	/** Property → value (kebab-case properties). */
	declarations: Record< string, string >;
}

/**
 * One animated effect the source runs. Sources:
 * - `css-keyframes` / `css-transition` — `document.getAnimations()`
 *   (CSSAnimation / CSSTransition), with the computed keyframes.
 * - `waapi` — a script-driven Web Animation (no vendor CSS to copy).
 * - `library` — a script/markup signature for GSAP, Lottie, Swiper, … (the
 *   runtime is NOT vendored; the fact is reported and deferred).
 * - `video` / `smil` — autoplaying media / SMIL nodes.
 */
export interface ForgeAnimationFact {
	kind: 'css-keyframes' | 'css-transition' | 'waapi' | 'library' | 'video' | 'smil';
	/** CSS animation-name, transition property, library name, or `video`/`smil`. */
	name: string;
	/** The source band id (`elementor-element-<id>` / `data-section-id`). */
	bandId?: string;
	/** The target's role in a rebuilt band. */
	target?: 'band' | 'media' | 'control' | 'text' | 'other';
	/** 0-based media ordinal in the band (target = media). */
	mediaIndex?: number;
	/** A diagnostic selector for the source target. */
	selector?: string;
	/** The source target's tag/classes — the conservative control/text mapping. */
	targetTag?: string;
	targetClasses?: string[];
	/** Measured timing (`animation-duration` etc.; strings keep `auto`). */
	duration?: string;
	delay?: string;
	easing?: string;
	iterations?: string;
	direction?: string;
	fill?: string;
	playState?: string;
	/** `DocumentTimeline`, `ScrollTimeline` or `ViewTimeline`. */
	timeline?: string;
	rangeStart?: string;
	rangeEnd?: string;
	/** The animated CSS properties. */
	properties?: string[];
	/** Computed keyframes (capped) — the replication input. */
	keyframes?: ForgeAnimationStep[];
	/** True for an unbounded animation (iterations: infinite). */
	infinite?: boolean;
	/** The library signature when kind = library. */
	library?: string;
	/** A source asset URL (Lottie JSON, Rive .riv, video src) — the static
	 * fallback the operator can inspect/render; the runtime is never shipped. */
	asset?: string;
	/** A static poster/first-frame image for a video or Lottie player. */
	poster?: string;
	/** The rebuild decision. */
	rebuild?: 'css' | 'mapped' | 'deferred';
	/** Why it was deferred / mapped. */
	note?: string;
}

/** The measured/declared animation inventory (`analyze --animations`). */
export interface ForgeAnimationInventory {
	/** Measured at rest and after scrolling (IntersectionObserver-triggered). */
	facts: ForgeAnimationFact[];
	/** Static library signatures from scripts/markup. */
	libraries?: string[];
	/** CSS keyframes declared by the source sheets (name → steps). */
	keyframes?: Record< string, ForgeAnimationStep[] >;
	/** Counts by kind and rebuild outcome, for the report. */
	counts?: Record< string, number >;
	/** `<video>` nodes seen. */
	video?: number;
	/** SMIL nodes (`animate`/`animateTransform`/`animateMotion`) seen. */
	smil?: number;
	/** Populated when the browser probe could not run. */
	error?: string;
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
	typography: { display?: string; body?: string; scalePx: number[]; bodyWeight?: string; /**
	 * The MEASURED reading size, in px — the size the source's body text
	 * actually renders at. `scalePx` is harvested from px declarations only,
	 * so a source that inherits the browser default (a relative `100%`/
	 * `1rem` on html/body) leaves 16px out of the ladder entirely and the
	 * band heuristic picks the smallest neighbouring value instead
	 * (jayainc: 13px, against a real 16px). Optional: absent → the ladder.
	 */
	bodyPx?: number };
	spacing: { scalePx: number[] };
	radii: { valuesPx: number[] };
	shadows: { count: number };
	components: Record<string, number>;
	modes: { dark: 'auto' | 'light' | 'dark'; contrast: 'auto' | 'standard' | 'high' };
	/** The section-background pass (117 §2) — omitted when unavailable. */
	sections?: ForgeSection[];
	/**
	 * Where `sections` came from. `sections` = the Elementor extractor (ids
	 * match the classifier, so the renderer themes by id). `outline` = the
	 * vendor-agnostic outline promoted into the same shape — the renderer then
	 * themes POSITIONALLY, because those bands carry no Elementor ids.
	 */
	bandSource?: 'sections' | 'outline';
	/**
	 * The vendor-agnostic band OUTLINE (Proposal 137) — the source's structural
	 * bands for builders the rich extractor does not model (WPBakery, Divi,
	 * Bricks, Gutenberg, Flatsome, plain HTML, a slider hero…). NON-RENDERING:
	 * `sections` drives the build, and the band-flow gate requires every entry
	 * there to reach the page. Kept so the operator (and a future renderer)
	 * can work from real structure instead of guessing.
	 */
	outline?: Array< {
		id: string;
		anchor?: string;
		bgColor?: string;
		bgImage?: string;
		bgGradient?: string;
		measure?: string;
		paddingX?: string;
		columns?: { width: number }[];
		/** Which layer produced it: an adapter id, 'semantic' or 'visual'. */
		via: string;
	} >;
	/** The builder adapter that matched the source (elementor, wpbakery, divi…). */
	builder?: string;
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
