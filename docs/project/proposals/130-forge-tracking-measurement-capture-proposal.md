# Proposal 130 — Tracker & measurement capture (keep the source's analytics, pixels and verification working)

> Owner direction (2026-11-08): research best practices and web-search the
> industry standards, then make sure **the forge can also capture the
> trackers that are enabled on the source site** — Google Analytics /
> Google Search, the Meta (Facebook) pixel, and the rest of the tag
> families a real WordPress site carries — so a forged rebuild does not
> silently go blind on day one.

**Status:** P0–P2 shipped 2026-11-08 (`lib/tracking.ts`, `lib/tracking-audit.ts`,
the template loader + consent banner, `analyze --tracking` /
`verify --tracking`, MCP `tracking_analyze` / `tracking_apply`). P3
(external-CMP hand-off), P4 (full commerce event map) and P5 (server-side)
remain declared seams. Implementation record:
`130-forge-tracking-measurement-capture-implementation-plan.md`.
**Related:** 096 (forge core), 114 §3.9 (forms bridge — the "content at
build time, behaviour proxied" precedent), 115 (inventory), 118 (vision,
advisory), 119 (MCP/plugin practicality), 120–123 (chrome/facts), 126
(cross-pollination), 104 §4.5 (copy tables), 105 §4 (no-FOUC policy boot).

---

## 0. Summary

The forge today rebuilds content and presentation, and **deliberately strips
every `<script>` from WordPress content**. Trackers, however, are not
content — they are injected by the theme, by `wp_head`, or by WordPress
plugins, and they never appear in `wp/v2` responses. A forged site therefore
ships with:

- no analytics (GA4/GTM), so the client loses reporting the day they switch;
- no advertising pixel (Meta/TikTok/LinkedIn/…), so paid campaigns lose
  attribution and optimisation;
- no consent management, so the rebuild can be *less* compliant than the
  source;
- no Search Console/Bing/Pinterest ownership verification, so the rebuild
  can be de-indexed or lose Merchant/Business features.

This proposal adds a **detect → normalise → declare → rebuild → verify**
pipeline for measurement and consent, with the same discipline the forge
already applies to design (114) and scroll behaviour (121):

| Stage | Deliverable | Where |
|---|---|---|
| Detect | vendor fingerprints from rendered HTML (+ optional browser probe) | `lib/tracking.ts` |
| Normalise | one canonical `ForgeTracking` model, validated IDs, deduped | `types.ts` |
| Declare | operator-owned `profile.tracking` block (never auto-enabled) | `sites/<name>.json` |
| Rebuild | first-party, consent-gated loader + tokens + verification files | template + scaffold |
| Verify | `forge verify --tracking` ordering + presence gates | `lib/tracking-audit.ts` |

**The forge never pastes the source's vendor snippets.** Vendor code is
detected as *facts* (which vendor, which public ID, which category) and
rebuilt with first-party, auditable, consent-first delivery — the same
"extract the language, never clone the markup" rule as 114.

---

## 1. Research (2026-11-08)

### 1.1 The tag surface a WordPress source actually carries

Trackers reach a WordPress page through five distinct channels, and each
needs a different detection strategy:

1. **Theme / `wp_head` injection** — raw snippet in the rendered `<head>`.
2. **Consent-gated plugin output** — the plugin prints the snippet only
   after a CMP signal, so the markup may be absent or wrapped.
3. **Tag manager** — one GTM container that loads everything else at
   runtime; the page shows only `gtm.js?id=GTM-…`.
4. **Cookie-injected** — set by a plugin on the WP origin; invisible in HTML.
5. **Server-side / edge** — GTM server container, Cloudflare Zaraz, Meta
   Conversions API; **zero footprint in the HTML**.

Channels 3–5 are why HTML-only detection is insufficient, and why the
proposal carries an optional browser probe (§2.3) and an explicit
"server-side is out of scope but declared" stance (§2.9).

### 1.2 Regulatory baseline (what "capture" must mean)

The acceptance bar is not "the pixel fires" — it is "the pixel fires only
where it is allowed to".

- **EU/EEA/UK:** the ePrivacy Directive (transposed nationally) plus GDPR
  require **prior, informed consent** for non-essential cookies/identifiers.
  Analytics and advertising scripts are non-essential (ICO/EDPB/CNIL
  positions; iubenda, Secure Privacy, Kukie guidance all restate the same
  rule: *no analytics or pixel script before consent*).
- **UK — Data (Use and Access) Act 2025** introduced a narrow
  **statistical-purposes exception** that can cover some consentless
  analytics *under conditions*; the ICO does **not** treat it as a broad
  analytics exemption. Practical consequence: **the consent layer must be
  regionally configurable, never hard-coded with one default set**
  (cookie-script.com, 2025).
- **US:** CCPA/CPRA and the 2023–2025 state wave treat cross-context
  behavioural advertising as "sale/share" and require honouring
  **Global Privacy Control** (`navigator.globalPrivacyControl`).
- **Frameworks:** IAB **TCF 2.2** is the deployed ad-tech consent-string
  standard; the **GPP** implementation guidelines were finalised Feb 2025
  and Google announced support for **GPP National v2 starting September
  2025**. Google Ads in the EEA additionally requires a **Google-certified
  CMP**.
- **Dark-pattern rules** (GDPR Art. 7, EDPB, CNIL, CPPA): reject must be as
  easy as accept; no pre-ticked non-essential categories; no consent walls
  without a genuine paid alternative.

> The forge ships **engineering controls**, not legal advice. Every generated
> site carries a `COMPLIANCE.md` note stating that banner copy, legal bases
> and regional defaults are the site owner's responsibility.

### 1.3 Google Consent Mode v2 — non-negotiable from March 2024

- v2 adds two required signals to v1's `ad_storage` / `analytics_storage`:
  **`ad_user_data`** and **`ad_personalization`** (Google Tag Platform docs).
  Optional v1 signals remain: `functionality_storage`,
  `personalization_storage`, `security_storage`.
- **Without v2, Google tags do not capture new EEA user data** for Google
  Ads / GA4 advertising features (multiple 2025/2026 implementations guides).
- **Ordering is the single biggest implementation failure**: the default
  denied state must be pushed **before any Google tag loads**. If the CMP
  loads asynchronously and the GA4 tag fires first, the state defaults to
  granted until the CMP speaks (gatilab, 2026 guide). The hard rule:
  *inline defaults → then the container*.
- Canonical default block:

  ```js
  window.dataLayer = window.dataLayer || [];
  function gtag(){ dataLayer.push(arguments); }
  gtag('consent', 'default', {
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    analytics_storage: 'denied',
    wait_for_update: 500,
  });
  ```

- **Basic vs advanced mode** (Google docs + Secure Privacy comparison):
  - *basic* — tags are **blocked** until consent. Cleanest privacy story,
    no modelling. Best fit for a static rebuild's default.
  - *advanced* — tags load immediately and send **cookieless pings** while
    denied, enabling conversion/behavioural modelling. Requires the defaults
    to be in place *before* the tag, and buys modelling only at meaningful
    ad spend.
  - Proposal default: **basic for `delivery: deferred`, advanced only as an
    explicit `consent.mode: 'consent-mode-only'` opt-in** (§2.5).

### 1.4 Meta and the ad-pixel family

- Meta Pixel consent uses the **`fbq('consent', 'grant' | 'revoke')`** API;
  Meta's own consent mode is the framework that decides whether the Pixel
  **and Conversions API** may collect (Secure Privacy, Meta docs, 2025).
- **Aggregated Event Measurement (AEM) manual configuration was removed in
  June 2025** — Meta now aggregates eligible events automatically. This
  matters: older rebuild checklists that tell you to configure AEM per
  domain are stale (PixelYourSite docs).
- **Domain verification** (`<meta name="facebook-domain-verification">` or
  DNS TXT) is still required for full event/domain control in Business
  Manager — so it belongs in the verification block (§2.7).
- The rest of the family follows the same shape — a global queue/stub plus
  an `init` call: TikTok `ttq.load(id)` / `ttq.page()`, Pinterest
  `pintrk('load', id)`, X `twq('config', id)`, Snap `snaptr('init', id)`,
  Reddit `rdt('init', id)`, LinkedIn `_linkedin_partner_id` + `lintrk`,
  Bing `tiq`/`uetq`. Each also has a vendor-specific consent surface; the
  safe universal rule is **do not load the vendor script until the category
  is granted**, then initialise.

### 1.5 Ownership verification tokens (the "Google Search" half of the ask)

Search Console (and its peers) offer several verification methods; the
forge can satisfy two of them and must document the rest:

| Method | Forge can | Notes |
|---|---|---|
| HTML **meta tag** (`google-site-verification`) | ✅ | Emit in `Base.astro` `<head>`; token is public |
| HTML **file** (`/google<token>.html`) | ✅ | Write to `public/`; Astro copies verbatim |
| **DNS TXT** (domain property) | ❌ | Registrar/DNS scope — document, never fake |
| GA4 / GTM association | ✅ | Falls out of the tracking block |
| Bing `msvalidate.01`, Yandex `yandex-verification` | ✅ | Same meta mechanism |
| Pinterest `p:domain_verify`, Meta domain verification | ✅ | Same meta mechanism |

Verification **fails most often for mundane reasons**: the tag outside
`<head>`, the HTML file 404ing, or the file URL redirecting (http→https,
subdomain, trailing slash) — sitemapfixer/serionflow, 2025. The forged site
is static and emits `sitemap.xml` + `robots.txt` already, so **discovery
plumbing exists; only proof-of-ownership is missing**.

### 1.6 Delivery mechanics in Astro (the part that bites)

- A plain static MPA (the forge template today — **no `ClientRouter`**) fires
  one real page load per navigation, so a stock GA4 snippet counts page
  views correctly.
- If `ClientRouter`/View Transitions is ever enabled, `page_view` **breaks**:
  the GA4 tag initialises once and client-side navigations are invisible
  unless `send_page_view: false` is set and a `page_view` is fired on
  `astro:page-load` (farrosfr; garrettdigital's 2025 write-up documents the
  same failure for GTM click triggers, which attach listeners once and never
  re-run). The GTM variant is a `virtualPageview` dataLayer push per
  navigation (`@digi4care/astro-google-tagmanager`).
- **Partytown**: historically the default recommendation, but it has a
  long-standing Firefox/View-Transitions breakage (Dec 2023, still
  outstanding per ricostacruz) and requires `forward: ['dataLayer.push']` or
  `gtag` calls silently vanish. In 2026 the pragmatic default is **deferred
  loading** (inject after `load`/consent), with Partytown as an opt-in for
  sites with heavy third-party scripts.
- `is:inline` scripts are **plain JS** (no TS assertions) — the same
  constraint the theme boot already lives under (Base.astro, Proposal 105).
- Generated projects are **zero-dependency by default**; a tracker loader
  and a consent banner are first-party modules, not npm packages.

### 1.7 Third-party cookie status (do not design for a dead future)

Google **ended the Privacy Sandbox** deprecation plan; third-party cookies
**remain in Chrome with no removal timeline**, and Chrome's own third-party
cookie consent prompt was dropped (April 2025 announcement; Didomi,
Usercentrics, Jentis analyses). Consequences for the forge:

- cookie-based pixels still work, so **capture must not be replaced by
  server-side-only**;
- but **consent is still mandatory** — retention is a browser decision, not
  a legal one;
- server-side (CAPI / GTM SS) remains an **accuracy and durability** upgrade,
  not a compliance substitute (§2.9).

### 1.8 WordPress plugin fingerprints (detection vocabulary)

The plugin landscape determines what the rendered HTML looks like:

| Plugin family | What it prints | Detection signal |
|---|---|---|
| **Site Kit by Google** | `gtag.js` with `G-…`, sometimes GTM | gtag src + `googlesitekit` inline config |
| **MonsterInsights** | gtag/js + `monsterinsights` frontend script | `monsterinsights` script handle |
| **GA Google Analytics** (lightweight) | bare `gtag.js` | gtag src only |
| **GTM4WP / GTM Kit** | `gtm.js?id=GTM-…` + `dataLayer` | gtm src, `dataLayer` pre-seeded |
| **PixelYourSite** | Meta + GA + Pinterest + TikTok snippets | several vendor srcs + `pys-` options object |
| **WooCommerce Google Analytics Integration** | gtag + `purchase` on the thank-you page | gtag + `gtag('event','purchase'` |
| **Complianz / CookieYes / Cookiebot / OneTrust / Osano / iubenda / Termly** | CMP bootstrap, cookie names (`complianz-consent`, `cookieyes-consent`, `CookieConsent`, `OptanonConsent`, …) | CMP script src / consent cookie / banner markup |

This is why the detector must be **name-and-shape based** (script hosts,
global stub names, ID regexes) rather than "look for the plugin".

### 1.9 Forge baseline — the gap

| Capability | Today | Gap |
|---|---|---|
| Scripts in content | stripped (`SANITIZE_VERSION = 2`, `disallowedTagsMode: 'discard'`) | correct — trackers live in the **layout**, not content |
| Embed/asset hosts | `content-scan.ts` records `embeds` + `images.hosts` | no script hosts, no vendor classification |
| Rendered HTML access | `fetchDesignInput()` (homepage + stylesheets, SSRF-guarded, capped) | reusable as the tracker input |
| Head layout | `Base.astro` — meta, canonical, fonts, theme boot | no consent bootstrap, no tag loader, no verification meta |
| Profile/manifest | `SiteProfile` + `nvoos.json` | no `tracking` block |
| Browser access | Playwright already used by `analyze --geometry` | no probe for globals/network/cookies |
| Verification gate | `verify` (facts, geometry, parity, axe) | no ordering/presence gate |
| Ecommerce | static PLP/PDP; checkout stays on WordPress (108/110) | no event map, no `purchase` dedupe, no consent handoff |

---

## 2. Design

### 2.1 Principles

1. **Detect facts, never clone snippets.** Vendor JS is re-implemented as a
   first-party loader with the same public IDs.
2. **Consent-first, deny-by-default.** Nothing non-essential loads before a
   decision; Consent Mode v2 defaults are inline and precede every tag.
3. **Static-safe.** No PHP proxy in the request path; no runtime dependency
   on WordPress for tracking.
4. **Env-overridable, IDs not secrets.** Public IDs are baked; staging/prod
   splits come from `PUBLIC_*` env vars.
5. **Zero-dependency default.** Banner + loader are ~3 KB of first-party JS;
   Partytown/CMPs are opt-in.
6. **Never double-count.** One source of truth per event; `purchase` is
   explicitly owned by either WordPress or the forge, never both.
7. **Fail closed, warn loudly.** An unresolvable CMP or an unknown vendor is
   a warning in the report and a `denied` default — never an implicit grant.
8. **No PII.** The forge never reads, stores or transmits personal data;
   discovery writes vendor names + public IDs only.

### 2.2 Data model (`lib/types.ts`)

```ts
export type TrackerCategory =
  | 'analytics'      // GA4, Plausible, Matomo, Fathom, Clarity, Hotjar
  | 'advertising'    // Meta, TikTok, LinkedIn, Pinterest, X, Snap, Reddit, Bing, Ads
  | 'consent'        // a CMP (recorded, not rebuilt unless mode = 'forge')
  | 'verification'   // ownership proof (no runtime script)
  | 'server-side';   // declared only — see §2.9

export interface ForgeTracker {
  vendor: string;                       // 'google-analytics-4'
  category: TrackerCategory;
  enabled: boolean;                     // operator decision (detected ≠ enabled)
  ids: Record<string, string>;          // { measurementId: 'G-…' } | { pixelId: '…' }
  detectedBy: string[];                 // ['script:googletagmanager.com/gtag/js']
  confidence: 'high' | 'medium' | 'low';
  /** The source gates this behind a CMP (detected banner + opt-in pattern). */
  sourceConsented?: boolean;
  /** A cookie-only or server-side footprint the HTML cannot prove. */
  detectedVia?: 'html' | 'probe' | 'operator';
  notes?: string[];
}

export interface ForgeConsent {
  /** forge = first-party banner; external-cmp = hand off to the vendor's
   *  script; consent-mode-only = advanced mode, no banner from us;
   *  none = no consent layer (warns when the source had one or the site
   *  serves the EEA). */
  mode: 'forge' | 'external-cmp' | 'consent-mode-only' | 'none';
  /** The only compliant default. 'granted' is a US-only opt-in. */
  default: 'denied' | 'granted';
  /** category → allows the operator to promote/demote a category. */
  categories?: Partial<Record<'analytics' | 'advertising' | 'functional' | 'personalization', boolean>>;
  /** Honour navigator.globalPrivacyControl (recommended: true). */
  gpc?: boolean;
  /** Consent Mode strategy: basic blocks tags, advanced sends cookieless pings. */
  strategy?: 'basic' | 'advanced';
  waitForUpdate?: number;               // ms; default 500
  cmp?: { vendor?: string; script?: string; cookieName?: string };
  policy?: { privacy?: string; cookies?: string };
  banner?: { position?: 'bottom' | 'bottom-left' | 'modal'; theme?: 'auto' | 'light' | 'dark' };
  /** Path the checkout handoff appends consent to (§2.8). */
  checkoutUrl?: string;
}

export interface ForgeVerification {
  google?: string;        // google-site-verification token
  bing?: string;          // msvalidate.01
  yandex?: string;        // yandex-verification
  pinterest?: string;     // p:domain_verify
  facebook?: string;      // facebook-domain-verification
  /** Static proof files copied into public/ verbatim. */
  files?: Array<{ path: string; content: string }>;
}

export interface ForgeTracking {
  trackers: ForgeTracker[];
  consent: ForgeConsent;
  verification?: ForgeVerification;
  delivery?: {
    mode?: 'deferred' | 'inline' | 'partytown';   // default 'deferred'
    partytown?: boolean;                          // opt-in only
  };
  ecommerce?: {
    events?: 'ga4' | 'none';
    purchaseSource?: 'wordpress' | 'forge';       // never both
  };
  /** Emitted to the deploy headers (§2.11). */
  csp?: { hosts: string[] };
  analyzedAt?: string;
  pagesAnalyzed?: string[];
}
```

`SiteProfile` gains `tracking?: ForgeTracking`. `ForgeManifest` gains a
**reduced** `tracking: { vendors: string[]; consentMode: string;
hasVerification: boolean }` — provenance for `verify`, and no tokens that
could leak identity into a public repo.

### 2.3 Detector — `lib/tracking.ts`

Inputs, in order of cost:

1. **Reuse the HTML already fetched.** When `analyze --design` runs,
   `--tracking` consumes the same `DesignFetchInput` (no second homepage
   fetch). Standalone `--tracking` performs one bounded fetch.
2. **Representative pages** (`--tracking-pages`, default 3): homepage +
   the newest post + one product. Some tags only mount on PDPs
   (`view_item`) or the thank-you page; a homepage-only scan under-reports.
   Bounded, same-origin, SSRF-guarded via `lib/ssrf.ts`.
3. **Optional browser probe** (`--probe`, reuses the Playwright that
   `--geometry` already installs):
   - `page.on('request')` filtered against the vendor host list — **the most
     reliable signal** (catches GTM-injected tags, Zaraz, CMP-loaded
     pixels);
   - `document.cookie` **names only** (`_ga`, `_fbp`, `_ttp`, `_uetvid`,
     `_hjSessionUser_*`, `_clck`, `__hstc`, …) — never values;
   - read-only globals: `dataLayer`, `gtag`, `fbq`, `ttq`, `pintrk`, `twq`,
     `snaptr`, `rdt`, `uetq`, `hj`, `clarity`, `_paq`.
   Fail-soft: a probe failure degrades to HTML-only and says so.

**Fingerprint tables** (excerpt — the full table is data, not code paths):

| Vendor | Host / stub | ID regex | Category |
|---|---|---|---|
| `google-analytics-4` | `googletagmanager.com/gtag/js?id=G-` | `\bG-[A-Z0-9]{6,12}\b` | analytics |
| `google-tag` | `gtag/js?id=GT-` | `\bGT-[A-Z0-9]{6,12}\b` | analytics |
| `google-tag-manager` | `googletagmanager.com/gtm.js?id=` | `\bGTM-[A-Z0-9]{5,9}\b` | (container) |
| `google-ads` | `gtag/js?id=AW-` / `conversion_linker` | `\bAW-\d{6,12}\b` | advertising |
| `universal-analytics` | `UA-` | `\bUA-\d{4,12}-\d{1,4}\b` | analytics (dead — warn) |
| `meta-pixel` | `connect.facebook.net/**/fbevents.js`, `fbq('init'` | `\b\d{15,16}\b` | advertising |
| `tiktok-pixel` | `analytics.tiktok.com`, `ttq.load` | `\bC[A-Z0-9]{15,22}\b` | advertising |
| `linkedin-insight` | `snap.licdn.com`, `_linkedin_partner_id` | `\b\d{6,8}\b` | advertising |
| `pinterest-tag` | `s.pinimg.com/ct/core.js`, `pintrk` | `\b\d{13}\b` | advertising |
| `x-pixel` | `static.ads-twitter.com`, `twq` | `\b[op][a-z0-9]{4,}\b` | advertising |
| `snap-pixel` | `sc-static.net`, `snaptr` | UUID | advertising |
| `reddit-pixel` | `redditstatic.com/ads/pixel.js`, `rdt` | `\bt2_[A-Za-z0-9_]{6,}\b` | advertising |
| `bing-uet` | `bat.bing.com`, `uetq`/`tiq` | `\b\d{8}\b` | advertising |
| `hotjar` | `static.hotjar.com`, `hj(` | `\b\d{5,8}\b` | analytics/session |
| `microsoft-clarity` | `clarity.ms`, `clarity(` | `\b[a-z0-9]{10}\b` | analytics/session |
| `hubspot` | `js.hs-scripts.com`, `__hstc` | `\b\d{6,9}\b` | analytics/CRM |
| `klaviyo` | `static.klaviyo.com`, `__kla_id` | `\b[A-Za-z0-9]{6}\b` | analytics/CRM |
| `matomo` | `matomo.js`/`piwik.js`, `_paq` | site id | analytics |
| `plausible` | `plausible.io/js/…` | domain | analytics |
| `fathom` | `cdn.usefathom.com` | site id | analytics |
| `cloudflare-web-analytics` | `cloudflareinsights.com/beacon` | token | analytics |
| `cloudflare-zaraz` | `zaraz` config in HTML | — | (edge) |
| CMPs | `cookiebot`, `onetrust`, `cookieyes`, `complianz`, `osano`, `iubenda`, `termly`, `usercentrics`, `didomi` | cookie names + script host | consent |

**Precision rules** (each earns a test):

- A **comment or prose mention** of `gtag` must not match — only `<script
  src>`, a real call shape (`gtag(` at statement position), or a global.
- The **UA- warning**: Universal Analytics stopped processing in July 2023;
  detection emits `deprecated: true` and the report says so instead of
  rebuilding it.
- `dataLayer` alone is **medium** confidence (GTM *or* a hand-rolled
  snippet); the container id upgrades it.
- Duplicate IDs across pages collapse (`Set`), and a vendor with **two**
  ids (e.g. two GA4 properties) is kept as `ids: { measurementId, secondary }`
  with a warning — never silently dropped.

### 2.4 Normalisation

```
raw fingerprints → vendor match → id extraction → format validation
                 → category → confidence → dedupe → ForgeTracker[]
```

The output of `analyze --tracking` is a **report**, not a decision:

```
Tracking report — https://example.com  (3 pages, html + probe)
  google-analytics-4   G-AB12CD34EF   analytics    high    (gtag src, dataLayer)
  google-tag-manager   GTM-XY12Z3     container    high    (gtm.js)
  meta-pixel           123456789012345 advertising  high    (fbevents.js, _fbp)
  cookieyes            —              consent      high    (cookieyes-consent)
  google-site-verification  abc…      verification high    (<meta>)
  ! Universal Analytics UA-1234-1 detected — deprecated (sunset 2023), not rebuilt.
  ! Source has a CMP; the forged site must ship consent too (mode: external-cmp or forge).
  ! No purchase event source found while WooCommerce is present — see §2.8.
```

`--write` merges the report into `profile.tracking` using the existing
`mergeProfileDraft` discipline (118 §3): **detected trackers arrive
`enabled: false`** until the operator promotes them. Enabling a tracker is
an explicit act.

### 2.5 Consent engine

Three shipping modes, one contractual ordering.

**Head ordering (the invariant):**

```
<head>
  1. meta / title / canonical / verification meta
  2. <script is:inline>  consent defaults        ← dataLayer + gtag consent
                                                    'default' denied + fbq revoke
                                                    + storage read + GPC
  3. <script is:inline>  loader (only when delivery: 'inline' or
                         strategy: 'advanced')
  4. theme policy JSON + theme boot               (existing, untouched)
  5. deferred: the loader is imported by a small module and runs
     on DOMContentLoaded / after consent
</head>
```

- **`mode: 'forge'`** — first-party banner (`ConsentBanner.astro`) + the
  loader. Categories: *necessary* (always), *analytics*, *advertising*,
  *functional*, *personalization*. Reject-all is the primary visual weight
  peer of accept-all; preferences are a second layer. Decision persisted in
  `localStorage['nvoos-consent'] = { v:1, ts, categories, gpc }` — **a
  localStorage decision, not a consent cookie**, so the banner's own state
  never becomes a consent paradox.
- **`mode: 'external-cmp'`** — the operator names the CMP; we emit its
  script (as-is, at the vendor's documented position) **after** the inline
  defaults, and the loader waits for the CMP's callback (`__tcfapi` /
  `OnetrustActiveGroups` / `cookieyes` events / a `nvoos:consent` DOM event
  the operator can bridge). Never assume a CMP interface exists silently —
  unresolved → deny + warn.
- **`mode: 'consent-mode-only'`** — advanced Consent Mode: Google tags may
  load immediately, cookieless pings only, no banner from us (the operator
  uses the CMP they already have, or accepts modelling-only). All *non*
  Google vendors stay blocked (they have no equivalent cookieless contract).

**Category → signal map:**

| Category | gtag keys | Other |
|---|---|---|
| analytics | `analytics_storage` | Matomo/Plausible/Fathom: gate the script |
| advertising | `ad_storage`, `ad_user_data`, `ad_personalization` | `fbq('consent', …)`, vendor init |
| functional | `functionality_storage` | — |
| personalization | `personalization_storage` | — |
| always | `security_storage` (granted) | CMP/banner |

**GPC:** if `navigator.globalPrivacyControl === true`, force
`ad_storage`/`ad_user_data`/`ad_personalization` to `denied` and suppress
"sale/share" tags regardless of a stored grant — and show the banner in
"opted out" state rather than a fresh prompt.

**Region:** a static site cannot geo-IP. Honest options, in order:

1. default `denied` globally (recommended for any site with EU traffic);
2. an **external CMP** that does geo (recommended for global brands);
3. an explicit `default: 'granted'` for provably US-only sites;
4. an edge-set cookie (Cloudflare `CF-IPCountry` → a tiny worker) —
   documented as an operator add-on, not built in.

No `navigator.language` guessing — it is not a jurisdiction signal.

### 2.6 Emission into the generated project

New files (scaffold-time, all covered by `nvoos.generated.json`):

| File | Role |
|---|---|
| `src/lib/tracking.generated.ts` | baked config: vendors, ids, consent, csp hosts |
| `src/lib/consent.ts` | **pure**, unit-tested: defaults, storage, GPC, mapping |
| `src/components/Tracking.astro` | head scripts (`is:inline`, plain JS) |
| `src/components/ConsentBanner.astro` | `mode: 'forge'` only; dialog a11y |
| `public/google<token>.html`, `public/BingSiteAuth.xml`, … | verification files |
| `.env.example` additions | `PUBLIC_GA4_ID`, `PUBLIC_GTM_ID`, `PUBLIC_META_PIXEL_ID`, … |
| `src/copy/en.ts` → `consent.*` | banner strings under the copy-table gate |

`Base.astro` imports `Tracking.astro` immediately after the `<head>` meta
block. `manifest.tracking` records `{ vendors, consentMode,
hasVerification }` for `verify`.

**Env-first:** every id resolves `import.meta.env.PUBLIC_* ?? bakedId`.
Astro exposes `PUBLIC_`-prefixed vars to the client by design; ids are
public identifiers, so this is the right prefix (no secrets, ever — a
server-side CAPI token must never enter the generated repo).

### 2.7 Verification tokens

- Meta tags render in `Base.astro` from `tracking.verification` — present on
  every page, which satisfies "in the `<head>` of the homepage".
- File-based proofs are written into **`public/`**, which Astro copies
  verbatim to `dist/` — no route generation, no dynamic filename problem,
  no redirect chain (the three classic failure modes, §1.5).
- The report states explicitly which methods remain **out of forge scope**
  (DNS TXT, domain properties). Generated `COMPLIANCE.md` carries the
  copy-paste guidance.

### 2.8 Ecommerce events and the purchase-dedupe rule

The forged site renders PLP/PDP statically; **checkout stays on WordPress**
(108/110). That splits the funnel across two origins, and double-counting is
the default failure.

| Event | Owner | Rule |
|---|---|---|
| `page_view`, `view_item_list`, `view_item`, `select_item` | **forge** | fire from the static pages, consent-gated |
| `add_to_cart`, `begin_checkout` | **forge** | fire on the click that hands off to WordPress, with an `event_id` |
| `purchase` | **WordPress** (`purchaseSource: 'wordpress'`, default) | the forge must **not** emit `purchase`; the WP site's own WooCommerce integration owns it |
| `purchase` | forge (`purchaseSource: 'forge'`) | only when checkout is forge-owned (110 embedded checkout) |

- **Dedupe:** when both sides could fire, the forge emits a stable
  `event_id` and the WP side is configured to accept it; `verify
  --tracking` fails if `purchaseSource === 'wordpress'` but a `purchase`
  call exists in the built bundle.
- **Consent handoff:** the checkout link appends
  `?nvoos_consent=analytics:1,ads:0&gcs=G100` (and, on the same registrable
  domain, sets `nvoos-consent` with `domain=.example.com` so the WP origin
  can read it). Without this, a visitor who accepted on the static site is
  treated as unconsented at checkout and the purchase is lost.

### 2.9 Server-side (declared, not built)

GTM server containers, Meta CAPI and Cloudflare Zaraz are the 2026 accuracy
answer, and **fully out of scope for a static generator**:

- they require a runtime, secrets and PII handling — exactly the three
  things the forge's "no plaintext credentials, no runtime fetches"
  invariant forbids in a generated repo;
- the correct architecture is *operator infrastructure* (sGTM on Cloud
  Run/Cloudflare, or Zaraz at the edge) receiving the client-side events the
  forge already emits.

The proposal therefore ships **`tracking.serverSide` as a declaration**
(`{ provider, endpoint, note }`) validated against the emitted event map,
plus a documented upsell. Explicitly rejected: relaying events through
`nvoos-forge-bridge` — it would turn the client's WordPress into a tracker
and put hashed PII in a plugin whose contract is "signed rebuild webhooks".

### 2.10 Privacy / PII hygiene

- Never read or persist cookie **values**; probe stores names only.
- Never accept email/phone/hashed PII in `profile.tracking`.
- Never emit `advanced` matching keys (Meta `em`/`ph`) client-side.
- Redact ids in logs (they are public, but the report should not become a
  spy sheet for a competitor's ad accounts — mask the middle: `G-AB…EF`).
- Vendor ids are **not** secrets, but they **are** customer-identifying:
  keep them in the committed profile (they already live in the public site),
  never in the vault.

### 2.11 CSP and headers

The scaffold emits **`public/_headers`** (Astro copies it verbatim to the
dist root, where Cloudflare Pages and Netlify read it). The policy is built
per-directive from the **enabled** vendor set only plus the WordPress origin
(the forms-bridge POST and the CDN image passthrough):

```
/*
  Content-Security-Policy-Report-Only: script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://connect.facebook.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: https://*.google-analytics.com https://*.g.doubleclick.net https://www.facebook.com https://wp.example.com; connect-src 'self' https://*.google-analytics.com https://*.analytics.google.com https://stats.g.doubleclick.net https://www.facebook.com https://connect.facebook.net https://wp.example.com; font-src 'self' data: https://fonts.gstatic.com; object-src 'none'; base-uri 'self'
```

Three deliberate safety rules:

1. **Report-Only by default.** An enforcing policy that misses one host
takes a live site down, so enforcement is an explicit
`tracking.consent.enforceCsp = true` opt-in (the header name flips).
2. **No `default-src`.** Frames/media/forms the forge cannot enumerate stay
unrestricted; only `script-src`, `style-src`, `img-src`, `connect-src`,
`font-src`, `object-src` and `base-uri` are constrained.
3. **Wildcards where Google needs them.** `https://*.google-analytics.com`
covers the `region1..N` collection endpoints; `www.googletagmanager.com`
appears in `script-src`, `img-src` **and** `connect-src`.

`tracking.csp.hosts` is the flat union the `verify --tracking` gate diffs
against: a missing host is a failure, and a host with no enabled vendor is a
stale-allowlist warning.

### 2.12 What we will **not** do

1. Paste the source's raw vendor `<script>` blocks.
2. Auto-enable a detected tracker without an operator decision.
3. Emit a `<noscript><img>` pixel by default — it fires for JS-disabled
   users with **no way to withhold consent**; opt-in only, with a warning.
4. Guess a jurisdiction from `navigator.language`.
5. Proxy trackers through WordPress.
6. Rebuild a CMP vendor's banner — vendor banners are licensed, not
   transferable; `mode: 'external-cmp'` loads *their* script.
7. Rebuild deprecated tags (UA) instead of warning.
8. Store any secret or PII.

---

## 3. CLI + MCP surface

### CLI

| Command | Behaviour |
|---|---|
| `forge analyze <url> --tracking` | report only (no write). Reuses `--design`'s fetch when present |
| `--tracking-pages <n>` | representative page count (default 3) |
| `--probe` | add the Playwright probe (network + globals + cookie names) |
| `--probe-checkout` | also probe the cart/checkout path for `purchase` ownership |
| `analyze --tracking --write` | merge into `profile.tracking`, trackers `enabled: false` |
| `forge verify <profile> --target <dir> --tracking` | ordering + presence gates |
| `--strict-tracking` | warnings become failures |

`--tracking` implies nothing about `--design`; both may run in one pass and
share the fetch.

### MCP (Phase 4 surface, `mcp/tools/tracking.ts`)

| Tool | Kind | Notes |
|---|---|---|
| `tracking_analyze` | read | open-world policy mirrors `site_analyze`/`design_analyze` |
| `tracking_apply` | **write** | merges the report into the registered profile; never enables |

Registered in `registry.ts`'s `ALL_TOOLS`; write flag drives the fail-closed
auth path automatically. Router recipes gain a `track|analytic|tag|pixel`
match.

---

## 4. Template changes

- `Base.astro`: verification meta + `<Tracking />` right after the meta
  block; no other reordering (the theme boot stays where it is).
- `src/lib/consent.ts`: pure module — `readDecision()`, `writeDecision()`,
  `consentState(categories)`, `gtagConsentMap()`, `honoursGpc()`,
  `isGranted(category)`. Unit-tested; the inline bootstrap is a thin
  mirror (documented as "keep the two in sync", like the theme policy).
- `src/components/ConsentBanner.astro`: `<dialog>`-based or div+`role="dialog"`,
  focus trap, Escape = reject-non-essential, `aria-label`s from the copy
  table, no layout shift (fixed overlay, `prefers-reduced-motion` respected).
- `src/components/Tracking.astro`: emits per-vendor loaders in a fixed
  order; every loader is behind `isGranted(category)`; the `partytown`
  branch is opt-in and forwards `dataLayer.push`.
- `src/copy/en.ts`: `consent: { title, body, acceptAll, rejectAll,
  preferences, save, categories: { analytics, advertising, functional,
  personalization }, policyLink }` — the copy-table completeness gate then
  guarantees any second locale carries them.
- `scripts/check-generated.mjs`: new generated files are hashed (automatic
  once written through the scaffold's manifest).
- `COMPLIANCE.md` (agent-stub sibling): the legal-ownership note, DNS
  verification instructions, CMP hand-off guidance.

---

## 5. Vendor matrix (v1 coverage)

| Vendor | Category | Rebuilt as | Consent key | Verification |
|---|---|---|---|---|
| Google Analytics 4 | analytics | gtag.js, one `config`, `send_page_view` flag | `analytics_storage` | via GA4 |
| Google Tag (GT-) | analytics | merged into the gtag loader | same | — |
| Google Tag Manager | container | gtm.js + inline defaults **before** it | all four | via GTM |
| Google Ads | advertising | gtag conversion linker | `ad_storage`,`ad_user_data`,`ad_personalization` | — |
| Meta Pixel | advertising | fbevents.js + `fbq('consent')` | fbq consent | `facebook-domain-verification` |
| TikTok Pixel | advertising | ttq | gate script | domain |
| LinkedIn Insight | advertising | lintrk | gate | domain |
| Pinterest Tag | advertising | pintrk | gate | `p:domain_verify` |
| X Pixel | advertising | twq | gate | — |
| Snap Pixel | advertising | snaptr | gate | — |
| Reddit Pixel | advertising | rdt | gate | — |
| Bing UET | advertising | uetq/tiq | gate | `msvalidate.01` |
| Hotjar | session | hj, gated | gate | — |
| Microsoft Clarity | session | clarity(), gated | Clarity consent API | — |
| HubSpot | CRM | hs-scripts, gated | gate | — |
| Klaviyo | CRM | klaviyo.js, gated | gate | — |
| Matomo (self-host) | analytics | matomo.js + `_paq` | gate | — |
| Plausible / Fathom / Umami / CF Web Analytics | analytics (cookieless) | script, **still consent-gated** (EEA) | `analytics_storage` | — |
| CMP (8 vendors) | consent | *not rebuilt* — loaded or replaced | — | — |

Cookieless analytics are intentionally gated anyway: cookieless ≠
consent-free in the EEA (and the UK exception is narrow, §1.2). Operators
can promote them to `exempt` explicitly.

---

## 6. Test plan

**Unit (`tests/tracking.test.ts`)**

- one positive fixture per vendor (script src / inline shape / meta);
- negative fixtures: `gtag` in a prose comment, `G-XXXX` in body text, a
  third-party script that merely *contains* the string `fbq`;
- id validation + dedupe + multi-property warning;
- UA deprecation warning;
- confidence aggregation and `detectedBy` provenance.

**Unit (`tests/consent.test.ts`)**

- default map is denied on all four keys;
- category→key mapping (analytics/advertising/functional/personalization);
- GPC forces ad keys denied and suppresses the prompt;
- storage round-trip, version bump discards an old shape, corrupt JSON → deny;
- `basic` vs `advanced` strategy decision.

**Scaffold (`tests/scaffold-tracking.test.ts`)**

- emission order in built `Base.astro` output;
- verification meta + `public/` file content;
- env override wins over the baked id;
- `csp.hosts` equals the union of enabled vendors, and shrinks when a
  tracker is disabled;
- `tracking` absent → byte-identical output to today (regression pin).

**E2E (`tests/forge-e2e.test.ts`, fixture server)**

- fixture homepage gains: gtag `G-TEST12345`, a Meta pixel, a CMP stub, and
  `google-site-verification`;
- assert: consent-default script index **<** every vendor script index in
  `dist/index.html`;
- assert: with `delivery: 'deferred'`, no `googletagmanager.com` `<script
  src>` exists in the static HTML (it is injected post-consent);
- assert `/google<token>.html` is served verbatim (200, exact body);
- run `verify --tracking` → exit 0; flip `purchaseSource` to `wordpress`
  with a `purchase` call present → exit 1 with the named finding;
- axe gate still passes with the banner open (focus trap, names, contrast).

**Negative gate**

- a built page must never contain `fbq('track'` or `gtag('event'`
  *before* the consent-default script — a string-index assertion, same
  technique as the token gate.

---

## 7. Phases

| Phase | Scope | Exit |
|---|---|---|
| **P0 — detect** | `lib/tracking.ts` + `analyze --tracking` report + `--probe` | report matches the pilot source's real tag set; zero writes |
| **P1 — safe emit** | verification block, consent defaults, `mode: 'none' \| 'consent-mode-only'`, CSP hosts | GA4 + GSC survive the rebuild with **no** banner; deny-by-default verified |
| **P2 — banner** | `mode: 'forge'`, `consent.ts`, copy tables, a11y, storage | E2E ordering gate green; axe green |
| **P3 — CMP hand-off** | `mode: 'external-cmp'`, GPC/GPP read, unresolved-CMP deny | CMP fixture drives the loader |
| **P4 — commerce** | GA4 event map, `event_id`, purchase ownership, checkout consent handoff | funnel complete with zero double-count |
| **P5 — verify + docs** | `verify --tracking`, `--strict-tracking` in CI, `COMPLIANCE.md`, server-side upsell note | gate in `.github/workflows/astro-forge.yml`; skill + AGENTS inventory updated |

P0/P1 are shippable independently and already remove the "day-one blind"
risk; nothing in P2+ is required for a client who brings their own CMP.

---

## 8. Open questions (recommended defaults in bold)

1. **Default consent state for a new forge** — **`denied` globally**; the
   report recommends a promotion only when every analyzed page shows US-only
   intent.
2. **Partytown** — **opt-in only**; the View-Transitions/Firefox caveat and
   the `dataLayer.push` forward requirement make it a footgun as a default.
3. **Rebuild the vendor banner?** — **No.** `external-cmp` loads theirs;
   `forge` ships ours. Vendor banners are licensed UI.
4. **`send_page_view`** — **`true` (today's MPA)**; the `false` +
   `astro:page-load` path activates automatically if `ClientRouter` is ever
   scaffolded.
5. **Geo gating** — **external CMP or an edge worker**; no built-in geo
   guess.
6. **`purchase` owner** — **`wordpress`** while checkout lives on WP.
7. **Verification files** — **`public/`**, not a route endpoint.
8. **Where do ids live?** — **committed profile** (public identifiers), env
   overrides for staging.
9. **Default for cookieless analytics (Plausible/Fathom)** — **gated**
   (`analytics_storage`), promotable to `exempt` per site.
10. **`noscript` pixels** — **off**, opt-in with a documented warning.

---

## 9. Acceptance criteria

1. `forge analyze <url> --tracking` identifies every vendor on the pilot
   source with `high` confidence for script-src-detectable tags and
   provenance for each finding.
2. A forged site with `tracking` configured emits **verification meta +
   files**, a **consent-default script that precedes every tag**, and
   **no consentless vendor script** under `delivery: 'deferred'`.
3. Disabling a tracker removes its loader, its hosts from `csp.hosts`, and
   its id from the manifest.
4. `verify --tracking --strict` fails on: missing verification meta,
   tag-before-consent, a `purchase` call with
   `purchaseSource === 'wordpress'`, a CSP host with no enabled vendor, or a
   stale `UA-` id.
5. A profile **without** `tracking` produces byte-identical output to
   today's scaffold (regression pin).
6. All existing gates stay green: `npm test`, `npm run typecheck`,
   `npm run test:e2e` (including the axe pass), `php -l`/phpcs untouched.

---

## 10. Risks & mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Vendor fingerprints rot | missed tag | probe tier (network truth) + table is data; report unknown hosts for review |
| Operators paste raw snippets anyway | breaks the invariant | `verify --tracking` fails on a vendor `<script src>` in static HTML when `deferred` |
| Consent banner tanks Lighthouse | perf/UX regression | first-party ~3 KB, no blocking request, `content-visibility`; measured in the e2e |
| Legal over-reach (we ship advice) | liability | `COMPLIANCE.md` ownership statement; defaults are conservative |
| Consent handoff missed at checkout | lost purchases | gate: any external checkout link must carry the handoff params |
| CMP interface drift | consent never resolves | unresolved → deny + warn; the operator sees it in `verify` |
| Double counting | corrupt reporting | single-owner matrix + `event_id` + gate |
| Third-party cookie churn | future breakage | server-side declared + documented, never assumed |

---

## 11. References

**Consent / measurement standards**

- Google — Consent mode overview & setup guide:
  <https://developers.google.com/tag-platform/security/concepts/consent-mode>,
  <https://developers.google.com/tag-platform/security/guides/consent>
- Simo Ahava — Consent Mode v2 for Google tags:
  <https://www.simoahava.com/analytics/consent-mode-v2-google-tags/>
- GA4 consent verification: <https://support.google.com/analytics/answer/14275483>
- Google Ads consent: <https://support.google.com/google-ads/answer/10000067>
- Consent Mode v2 implementation pitfalls (2026):
  <https://gatilab.com/google-consent-mode-v2/>
- Basic vs advanced comparison:
  <https://support.secureprivacy.ai/article/basic-vs-advanced-google-consent-mode-full-comparison-guide/>
- Meta consent mode (2025): <https://secureprivacy.ai/blog/meta-consent-mode-explained-2025>
- Meta Pixel + CAPI consent guide (2026):
  <https://flexyconsent.com/blog/meta-pixel-facebook-conversions-api-consent-guide/>
- AEM manual config removed June 2025:
  <https://www.pixelyoursite.com/docs/meta-facebook-settings-setup>
- Meta Pixel 2026 setup/audit:
  <https://affectgroup.com/blog/meta-pixel-in-2026-the-setup-and-audit-guide-for-smb-owners-and-in-house-marketers/>

**Privacy law**

- Prior-consent script blocking (GDPR/ePrivacy):
  <https://support.secureprivacy.ai/article/complete-guide-to-blocking-cookies-for-gdpr-compliance-prior-consent-script-load/>
- iubenda — cookies and the GDPR: <https://www.iubenda.com/en/help/5525-cookies-gdpr-requirements/>
- ePrivacy vs GDPR for engineers (incl. UK DUAA 2025 exception):
  <https://cookie-script.com/guides/eprivacy-vs-gdpr>
- IAB GPP (implementation guidelines Feb 2025): <https://iabtechlab.com/gpp/>
- Google GPP National v2 support (Sept 2025):
  <https://support.google.com/campaignmanager/answer/10031693>
- Usercentrics — GPP overview: <https://usercentrics.com/knowledge-hub/global-privacy-platform/>

**Verification**

- Search Console ownership verification: <https://support.google.com/webmasters/answer/9008080>
- Verification methods + failure modes:
  <https://serionflow.com/guides/google-search-console-verification>,
  <https://sitemapfixer.com/learn/gsc-verify-website>

**Astro / delivery**

- Astro + GA guide (Partytown, View Transitions, CookieConsent):
  <https://daniel.es/blog/the-ultimate-astro-google-analytics-guide/>
- Partytown in Astro (Firefox/View-Transitions caveat):
  <https://ricostacruz.com/posts/google-analytics-in-astro>
- GA4 pageview breakage with View Transitions:
  <https://www.garrettdigital.com/blog/astro-ga4-pageview-tracking/>
- `send_page_view: false` + manual page views:
  <https://farrosfr.com/blog/pro-google-analytics-in-astro-with-partytown/>
- GTM for Astro with `virtualPageview`:
  <https://www.npmjs.com/package/@digi4care/astro-google-tagmanager>

**Cookies / platform**

- Privacy Sandbox shutdown, 3PC retained:
  <https://usercentrics.com/knowledge-hub/what-is-google-privacy-sandbox/>,
  <https://www.didomi.io/blog/google-chrome-third-party-cookies-april-2025>

**WordPress plugin landscape**

- <https://quillforms.com/google-analytics-wordpress-plugins/>
- <https://www.monsterinsights.com/best-google-analytics-plugins-for-wordpress/>
