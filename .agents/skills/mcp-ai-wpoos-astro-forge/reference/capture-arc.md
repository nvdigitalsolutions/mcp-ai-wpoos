# Capture surfaces — Proposals 130 / 131 / 132 (Astro Forge)

`SKILL.md` keeps the summary only; this file holds the full operational
notes for the three capture passes: trackers/consent, SEO head fidelity and
stored redirects.

## Proposal 130 — tracker & measurement capture (2026-11-08)

Trackers never reach `wp/v2` — the theme, `wp_head` or a plugin injects them
into the rendered document. The forge now detects them as FACTS and rebuilds
them with first-party, consent-gated delivery. **It never pastes a vendor
snippet.** Proposal + implementation plan:
`docs/project/proposals/130-forge-tracking-measurement-capture-*.md`.

- **`lib/tracking.ts`** — `TRACKING_VENDORS` is a data table (~30 rows: GA4,
  Google Tag, GTM, Google Ads, UA-deprecated, Meta, TikTok, LinkedIn,
  Pinterest, X, Snap, Reddit, Bing UET, Hotjar, Clarity, HubSpot, Klaviyo,
  Matomo, Plausible, Fathom, Cloudflare Web Analytics/Zaraz, + 8 CMPs).
  Precedence: probe request (high) → script src (high) → inline call shape
  (medium) → probe global (medium) → cookie NAME (medium). `scanTrackingHtml`
  (htmlparser2) records script srcs, inline JS, metas and `<noscript><img>`;
  `probeTracking` is the optional Playwright pass (network log + globals +
  `document.cookie` NAMES only — never values). `analyzeTracking` fetches the
  homepage (`html` can be reused from `--design`) plus ≤3 representative
  pages and merges signals. `trackingFromReport` drafts every tracker
  **`enabled: false`**; `mergeTrackingDraft` keeps operator decisions on a
  re-analysis; `cspHostsFor` derives the allowlist from the ENABLED set.
- **Consent:** modes `forge` | `external-cmp` | `consent-mode-only` | `none`,
  `default: 'denied'` only (a `'granted'` opt-in exists for US-only sites),
  `strategy: basic|advanced`, GPC honoured (forces the three ad signals to
  denied even over a stored grant). Draft mode = `external-cmp` when the
  source had a CMP, else `none` + a warning.
- **Template:** `src/lib/tracking.generated.ts` (inert stub, overwritten by
  the scaffold), `src/lib/consent.ts` (pure, unit-pinned `CONSENT_KEY` /
  `CONSENT_VERSION`), `src/components/Tracking.astro` (verification meta →
  config JSON → **`#nvoos-consent-default`** → events JSON → loader),
  `src/components/ConsentBanner.astro` (`mode: forge` only), `consent.*` in
  the copy table, and **`public/_headers`** (the CSP: per-directive from the
  ENABLED vendors + the WordPress origin, **Report-Only** unless
  `consent.enforceCsp`, no `default-src` so frames/forms stay unrestricted).
  **Ordering invariant:** no vendor `<script src>` in the static HTML; the
  loader injects after the category is granted (GTM wins over a hardcoded
  GA4 — the audit warns about double counting).
- **A11y:** the bar is a NON-modal `role="region"` (no `aria-modal` lie, no
  focus trap); preferences are a native `<dialog>`; accept/reject share one
  style (equal prominence, reject first); `html[data-consent-open]` reserves
  body space for WCAG 2.2 SC 2.4.11. EAA (in force 2025-06-28) ⇒ WCAG 2.2 AA.
- **CLI/MCP:** `analyze --tracking [--tracking-pages n] [--probe]`,
  `verify --tracking [--strict-tracking]`; MCP `tracking_analyze` (read) /
  `tracking_apply` (write, merges, never enables).
- **Gate codes:** `verification-meta-missing`, `verification-file-missing`,
  `consent-default-missing`, `tag-before-consent`,
  `tracker-in-static-html`, `purchase-owned-by-wordpress`,
  `csp-host-missing` (fail) / `csp-host-stale`, `trackers-never-fire`,
  `cmp-script-missing`, `inline-basic-conflict`, `gtm-gtag-double`,
  `partytown-unavailable`, `deprecated-tracker`.
- **CSP authority (fix 2026-11-08):** `verify --tracking` reads the emitted
  `dist/_headers` and diffs the enabled vendors' hosts against it. The
  profile's `csp.hosts` is only a declaration and may legitimately be empty —
  an `analyze --tracking` draft writes `[]` because detection never enables a
  tracker, so enabling one must not require hand-editing the list. An empty
  declaration is never a failure; a declared list or the emitted header can be.
- **Gotchas (each cost a fix 2026-11-08):** `renderTrackingGenerated` must
  emit `vendors: [],` on one line (a bare `vendors: [` + `],` pair breaks the
  inert stub); id keys are emitted through `tsKey` (bare when a valid
  identifier) so generated output reads `measurementId: 'G-…'`; template
  `<script>` tags need explicit `></script>` (self-closing is not reliable);
  a script-only vendor keeps `ids.script` ONLY when it has no id extractor
  (otherwise the missing-id warning is swallowed); verification files land
  in `public/` (Astro copies verbatim — no route, no redirect chain);
  `.env.example` keys are the generic
  `PUBLIC_<VENDOR>_<IDKEY>` shape.
- **Validation:** 50 new unit tests (detector/audit/consent/scaffold) + the
  full suite (516) + `npm run typecheck` + the **31-test e2e**. The fixture
  homepage now carries GA4 + Meta + CookieYes + a verification meta + a
  `<noscript>` pixel, so the e2e asserts the whole loop: detection drafts
  everything disabled, a tracking-enabled scaffold installs/checks/builds,
  the built HTML has the verification meta + proof file, the consent
  bootstrap before everything, zero vendor `<script src>` (deferred), the
  `purchase:false` events map, `dist/_headers` in Report-Only, and **axe
  passes with the banner visible**. `verify --tracking` exits 0 clean and 1
  with an injected tag.

## Proposal 131 — SEO head fidelity (Rank Math / Yoast) (2026-11-08)

Answering "is the SEO section forged?": the metadata was, the plugin HEAD
was not. 131 closes it. Proposal + plan:
`docs/project/proposals/131-forge-seo-head-fidelity-*.md`.

- **The double-brand bug is fixed.** Rank Math/Yoast titles are final and
  usually carry the site name; `Base` appended `${site}` unconditionally, so
  real sources rendered `X — Site — Site` (the old e2e PINNED the append).
  `composeDocumentTitle` now appends only when the title does not already end
  with the site name (optionally behind ` — `, ` - `, ` | `, ` · `, `: `).
- **Loader (`extractSeoMeta`)** carries `title`, `description`, `canonical`,
  `robots`, `ogTitle/Description/Image/Type/SiteName`,
  `twitterCard/Title/Description/Image`. `normalizeRobots` handles Rank
  Math's **array** and Yoast's **object**. Social images must be absolute
  URLs (an attachment ID is rejected). `yoast_head_json` may be an object or
  a JSON string. `contentSchema.seo` is additive; `seoTitle`/
  `seoDescription` still exist.
- **Product SEO sidecar** (`WpCollection.seoFrom`): wc/v3 has no SEO fields
  and Woo products ARE the `product` CPT, so the loader does ONE bounded
  `wp/v2/<rest_base>` request and merges by id (no N+1). Discovery sets it
  automatically; the manifest always emits the key (`''` when unused) so the
  SSR route typechecks; the SSR PDP fetches `/wp/v2/<rest_base>/<id>`
  fail-soft.
- **Graph**: `jsonLdGraph` emits Organization + WebSite (+ SearchAction when
  the search surface is on) + optional BreadcrumbList + the page node, wired
  with `@id`s from the site origin. `src/lib/seo-graph.ts` supplies identity
  from `nvoos.json` + `chrome.generated.ts`. Yoast's raw `schema.@graph` is
  never copied.
- **Base head** renders `og:site_name/type/title/description/url/image` + the
  Twitter card + `robots`. The SOURCE canonical is deliberately not rendered
  (it points at the WordPress origin); `pageDescription` falls back to
  `seo.description` (the front page passes none).
- **Sitemap**: `<lastmod>` (gmt → `Z`), **noindex exclusion**, image
  extension, XML escaping.
- **Gate**: `verify --seo [--strict-seo] [--seo-pages n]` audits up to 8
  built pages (homepage first). Fails: `title-double-brand`,
  `homepage-noindex`, `jsonld-invalid`, `title-missing`. Warns: canonical
  missing/mismatch, description, robots, `og-incomplete`,
  `twitter-card-missing`, `jsonld-graph-missing`, `html-lang-missing`. A
  design-less profile can run `--seo`/`--tracking` without the parity
  precondition.
- **Decisions:** schema `name`/`description` keep the CONTENT values (the SEO
  title is for `<title>`/social); robots render verbatim (fidelity), with a
  loud fail only on a noindex homepage.
- **Validation:** 544 unit tests (28 new) + typecheck + 31 e2e (338s). Manual:
  `verify --seo --strict-seo` → exit 0 on a clean 8-page build, exit 1 after
  injecting a double-branded title.
- **Out of scope:** hreflang and profile canonical overrides (reported, not
  applied). Redirection-plugin rules were out of scope here and are delivered
  by **Proposal 132** (below).

## Proposal 132 — Stored redirect capture (Redirection / Rank Math) (2026-11-08)

Closes the last cutover gap: the rules the site already relies on. Proposal +
plan: `docs/project/proposals/132-forge-stored-redirect-capture-*.md`.

- **Bridge `0.3.0`** — `GET /nvoos-forge/v1/redirects` (always 200; opt-in
  `OPTION_REDIRECTS_ENABLED`, optional `OPTION_REDIRECTS_TOKEN` via
  `X-Forge-Redirects-Token`/Bearer with `hash_equals`, bounded 500 +
  `truncated`). Redirection: `redirection_items` JOIN `redirection_groups`
  (`status='enabled'`, `match_type='url'`, `regex=0`, `action_data.url` JSON
  **or** PHP-serialized, errors 404/410). Rank Math: `rank_math_redirections`
  (`status='active'`, `sources` serialized **or** JSON, `exact` only,
  `url_to` + `header_code`). Detection is filterable
  (`nvoos_forge_bridge_redirect_providers`).
- **Never widened.** Regex, query/fragment sources, non-URL match types, 451
  and odd action types are **counted** in `skipped`; no ids/hits/groups/
  titles/timestamps leave the server.
- **Merge** (`lib/redirects.ts`): stored rules first, then the URL-structure
  pass overwrites the same `from`; 404 is silent, other failures warn; the
  report gains `bridge`/`warnings`; `{ bridge: false }` skips it.
  `WpClient.transport()` keeps the fetcher hermetic.
- **Template**: `redirects.json.ts` fetches at build time (env → `WP_SITE_URL`
  → manifest) and stays **faithful**; `postbuild.mjs` projects for
  `_redirects` (`303/307→302`, `308→301`, `410→404`, unknown omitted, 404/410
  target `/`). Netlify documents 301/302/404 and calls 307 unsupported;
  Cloudflare allows 301/302/303/307/308 and no 404/410.
- **The seam for any bridge-backed build input:** env → `WP_SITE_URL` →
  manifest resolution, `console.warn` + fallback (never a build failure), the
  route in `scripts/wp-fixture-server.mjs`, and an assertion on the built
  artifact in `tests/forge-e2e.test.ts`.
- **Validation:** PHPCS 0/0; bridge PHPUnit 58 tests/167 assertions; typecheck
  clean; 554 unit; 31 e2e (the fixture returns one 301 + one 302, asserted in
  both `redirects.json` and `_redirects`).
- **Limits:** no regex/query/conditional rules, no chain flattening; Rank Math
  patterns keep their stored form after the leading slash (Netlify normalises
  trailing slashes, Cloudflare does not).
