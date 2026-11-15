# Proposal 135 — Forge two-tier deploy model: domain topology + managed sites

> Status: **Phases 1–6 shipped** (2026-11-09). Live collections (2c,
> `defineLiveCollection`) remain the only open build item; `/manage` on a forged
> site is decided-not-built (D7). D4 decided and implemented.
> Supersedes nothing. Extends Proposal 096 (Astro Forge), the 114 shared-mirror
> addendum, 092/095 (Astro storefront + `nvoos-astro-bridge`), 114 §3.9 (forms
> bridge), 130 (tracking capture), 131 (SEO head fidelity), and 132 (stored
> redirect capture).
>
> Companion: `135-forge-managed-tier-domain-topology-implementation-plan.md`.

---

## 1. Executive summary

The forge today produces exactly one kind of artifact: a **fully static,
prebuilt `dist/`** force-pushed to a `site/<name>` branch of the private
`nvdigitalsolutions/nvoos-forge-sites` mirror, where a Velocity app serves the
branch root with a no-op `npm run build`.

That is correct for a site like **jayainc** — no forms, no admin, no runtime.
It is **not** sufficient for the second class of client site the fleet now
needs: sites with **forms, SEO/tracking configuration, and a `/manage` surface**
for both local content and bridged WordPress content.

Two questions have to be answered together, because the second determines the
first:

1. **Which domain does WordPress live on when the Astro site owns the public
   domain?**
2. **How does a "managed" site get a server runtime, an admin surface and
   fresh content without abandoning the mirror/branch deploy model?**

Proposal answer, in one line:

> **WordPress is never the public origin in either tier.** It lives on
> `cms.<client>` and is reachable only by the builder (static tier) or by the
> site's own Node server (managed tier). The only public domain is the Astro
> site. The managed tier is the *same* mirror branch with a real start command
> plus server-to-server **endpoint routes** (not a custom `src/fetch.ts` — see
> §4.2) — not a new platform.

---

## 2. Problem statement

### 2.1 Two site classes, one pipeline

| | Class A — "100% static" (jayainc) | Class B — "managed" |
|---|---|---|
| Examples | Marketing site, brochure, catalogue read-only | Forms, tracked marketing site, editor-managed content |
| Runtime | None | Node process (BFF + `/manage` + revalidate) |
| Freshness | Rebuild on content change | Tag-based revalidate (seconds) + rebuild as backstop |
| Admin | `wp-admin` on the CMS host | `/manage` on the public site |
| Deploy | Flat `dist/` + no-op build | `dist/` + `server.js` + runtime `package.json` |

The shared mirror and branch-per-site model serves both. The **emission** is
what differs, and today it only knows the static shape
(`lib/deploy-config.ts` → `mirrorPushScript()`).

### 2.2 The domain ambiguity

When the Astro app is served at `jayainc.com`, operators reasonably ask whether
WordPress's `siteurl`/`home` should also be `jayainc.com`. **It should not.**

`home_url()` is the value WordPress emits into:

- REST `link` fields and media `source_url`
- Yoast / Rank Math canonical + `og:url`
- preview URLs and `admin_url()` values
- WooCommerce checkout/cart/return URLs

Set it to the public domain and every one of those references a route the Astro
app does not serve (`/wp-content/uploads`, `/wp-admin`, `/wp-json`) — broken
media and broken previews. The forge already models the split correctly in its
own vocabulary:

- `WP_SITE_URL` — the **source** origin (also the Astro Image remote allowlist)
- `ASTRO_SITE` — the **public canonical** (sitemap, robots, canonical/OG)

Source and canonical are different things; the topology should say so.

---

## 3. Findings

### 3.1 Repository facts (verified 2026-11-09)

| Fact | Where | Consequence |
|---|---|---|
| Forge separates source from canonical | `lib/scaffold.ts` L286-288 (`WP_SITE_URL`), `lib/deploy-config.ts` L138 (`ASTRO_SITE`) | The split is already supported; only the topology/runbook is missing |
| Static media is build-time | README "Media pipeline" — `SmartImage` + `WP_SITE_URL` allowlist | A private CMS works for the static tier (nothing fetches at runtime) |
| Velocity runs Node | `examples/nvoos-astro-velocity/README.md` — `sync:dist` ships `server.js` + `serve.mjs`; Entry file = `server.js`; PM2 needs the root entry | **Managed forge sites are feasible on the same host**; the static-flat shape is an emission choice, not a platform limit |
| Tag invalidation exists | same README — `NVOOS_REVALIDATE_TOKEN` + `POST /internal/revalidate/` | The managed tier's freshness path has a working precedent |
| Bridge binds to `home_url()` | `includes/Schema.php` L256-263 (shop defaults), `includes/REST/ShopGlobalsController.php` L246-250 (URL allowlist) | **Gap**: with the CMS on a subdomain, checkout/cart/return URLs and the allowlist point at the wrong host |
| Bridge is same-origin by design | `includes/REST/AuthController.php` L3-12 — "Cookie auth flows through the storefront's same-origin proxy, so no CORS configuration is needed" | A cross-origin `/manage` is the wrong answer; the BFF is the right one |
| Bridge already caches public GETs | `includes/REST/BaseController.php` L75-85 — `Cache-Control: public`, ETag/Last-Modified | Cache **tags** are the missing half for tag purge |
| Mirror emission is static-only | `lib/deploy-config.ts` `mirrorPushScript()` — no-op `package.json`, flat copy | Needs a `runtime` dimension |
| Forms are browser-direct today | `plugins/nvoos-forge-bridge` → `POST /nvoos-forge/v1/forms/submit` with an Origin allowlist | Forces a **public** CMS. Proxy through the BFF to keep it private |

### 3.2 Industry standards (researched)

**Domain topology.** The consensus is CMS on a subdomain, frontend on the apex.
Pantheon's headless playbook: *"In most headless projects, WordPress lives on a
subdomain such as `cms.example.com`, while the frontend lives on the main
domain. Making this decision early prevents you from having to reconfigure
URLs, tokens and cookies later."* The same shape appears in the freeCodeCamp
Astro-SSR-on-Cloudflare walkthrough ("Move WordPress to `cms.yourdomain.com` so
the root domain is free for Astro") and Servebolt's headless guide.

Pantheon also names the cost we are paying: *"many features that come for free
in traditional WordPress — forms, search, comments, previews, SEO tooling —
must be custom-built … The content editing experience can suffer if preview
workflows are not carefully implemented."* That is precisely the managed tier's
scope.

**Preview / draft.** The established pattern is a **tokenized preview URL**:
the CMS opens `/api/preview?secret=…&slug=…`, the secret is exchanged for a
short-lived cookie, and the app renders drafts only for that session
(Next.js Draft Mode; the same shape as the bridge's existing
`content_preview_url` single-use hashed tokens). A shared static build cannot
preview drafts — this is a managed-tier capability.

**On-demand revalidation.** Astro 7 (June 2026) stabilised **route caching**:
`Astro.cache.set({ maxAge, swr, tags })`, declarative `routeRules`, and
`cache.invalidate({ tags, path })`, with `memoryCache()` on the Node adapter and
experimental `cacheNetlify/Vercel/Cloudflare()` providers. This is the Astro
equivalent of Next.js ISR and replaces "rebuild the whole site to change one
product price".

**Live content collections.** Astro 7 integrates live loaders with route
caching: *"A live loader can attach a cache hint to the data it returns, with
tags for invalidation and a last-modified time for freshness"* — pass the entry
to `Astro.cache.set()` and Astro reads the hint. This is exactly the
"bridged WP content, fresh, without a rebuild" half of the model, while local
content stays build-time.

**Advanced routing.** Astro 7 adds `src/fetch.ts` — a standard Fetch handler
with full control over the request pipeline and Hono compatibility. It can
proxy `/api` before Astro's pipeline runs. **This replaces a bespoke
`serve.mjs`** for forged apps and is what makes `/manage` portable.

**Consent.** Google Consent Mode **v2 is required for EEA/UK traffic** (since
March 2024) and needs `ad_user_data` + `ad_personalization`. The common
failure — a banner that records a preference but never fires
`gtag('consent','update',…)` — is worth an explicit gate in `verify
--tracking`.

**Local content on a static site.** Git-based CMSs are the standard answer.
Keystatic fits Astro with a TypeScript schema and runs locally, then GitHub
mode in production; Decap needs an OAuth backend; Tina adds visual editing at
more weight. **Do not run two content stores** — either local content lives in
WordPress behind the bridge, or it is Git-based. Pick one per site.

---

## 4. Proposed architecture

### 4.1 Domain topology (both tiers)

```
PUBLIC                      PRIVATE (never the public origin)
┌──────────────────────┐    ┌──────────────────────────────┐
│ client.com   (apex)  │    │ cms.client.com               │
│ www.client.com → 301 │    │  WP_HOME / WP_SITEURL        │
└──────────┬───────────┘    │  X-Robots-Tag: noindex       │
           │                │  robots.txt: Disallow: /     │
           ▼                └──────────────┬───────────────┘
   Velocity app (mirror branch)            │
   site/<name>                             │
   A) static: flat dist/                   │ build-time fetch (CI IP allowlist)
   B) managed: server.js + dist/           │ OR server-to-server (BFF) 
                                           │
                              ┌────────────┴────────────┐
                              │ Class A: fetch at build │
                              │ Class B: BFF at runtime │
                              └─────────────────────────┘
```

| Setting | Value |
|---|---|
| Public origin | `https://client.com` (+ `www` → 301) |
| `ASTRO_SITE` | `https://client.com` |
| `WP_SITE_URL` (forge) | `https://cms.client.com` |
| `WP_HOME` / `WP_SITEURL` | `https://cms.client.com` |
| CMS reachability | Class A: CI/builder IP allowlist. Class B: Node-server IP allowlist |
| CMS indexability | `noindex` + disallow-all `robots.txt` |
| Cookies | Host-only on the public origin (BFF strips `Domain=`) |

Rationale: the CMS host is *not* a browser dependency in either tier, so it can
stay private. That removes an attack surface and a bot-protection problem at
once (the same class of block already burned the forge CI — see
`docs/operations/deployment/forge-ci-bot-protection.md`).

### 4.2 Class B (managed) request flow

```
browser ──► https://client.com  (Astro + endpoint proxy routes)
                 ├── /                prerendered + route cache
                 ├── /products/**     on-demand, tags, s-maxage + swr
                 ├── /api/*           → proxy cms.client.com/wp-json/nvoos-commerce/v1
                 ├── /api/auth/*      → login, cookie re-scoped to the public host
                 ├── /api/forms/submit → proxy nvoos-forge/v1/forms/submit
                 ├── /media/*         → proxy uploads
                 ├── /api/revalidate  → cache.invalidate({tags|path}), token-gated
                 ├── /api/preview     → token → host-only cookie → 302
                 └── /preview/<slug>/  SSR draft render (managed only)
```

`/manage` is deliberately absent from that tree: it is served by the **CMS**,
not the forged site (D7 — the bridge SPA is proprietary and the template is a
separate distribution). `/mcp` is likewise not emitted — the template has no
assistant surface.

Content freshness, three tiers of cost:

1. **tag revalidate** (managed) — seconds, one webhook call.
2. **full rebuild** (dispatch) — minutes, `repository_dispatch` (existing).
3. **ISR-style incremental builds** (Astro 7.2 experimental) — future;
   explicitly *not* a dependency.

### 4.3 Local content and preview (Phase 5, shipped)

A managed site needs content that never existed in the source WordPress site.
D4 puts it in WordPress — the `nvoos_forge_local` CPT, reached only through the
bridge contract (`/nvoos-commerce/v1/local`), never through `wp/v2`. One store,
one capability (`manage_astro`), one audit trail, and the same Proposal 107
approval state machine as pages and posts (creates are drafts; publishing needs
`publish_posts`).

The document serves **two consumers** from one shape: the `/manage` SPA reads
the content document (title/content/excerpt/seo/status), and the forge loader
reads the nvoos-commerce envelope (`name`/`description`). Local documents
therefore carry both — plus an explicit `slug`, because a URL must survive a
title edit.

The forge reads it with `source: 'local'` (same envelope, canonical REST base,
slug-authoritative). `optional: true` degrades a missing collection to an empty
one instead of failing the build.

**Preview** is the interesting half. The public origin and the CMS share no
cookie, so:

```
/manage  ──POST /local/<id>/preview-token──►  bridge mints
                                             HMAC(post_id.exp)
         ◄── { token, expiresAt, previewUrl = <publicOrigin>/api/preview?… }

browser  ──GET /api/preview?token&slug──►     forged site
         ◄── 302 /preview/<slug>/ + Set-Cookie nvoos_preview
                                             (host-only, HttpOnly, 15 min)

/preview/<slug>/ ──GET local?preview_token=…&where[slug][equals]=…──► bridge
         ◄── exactly that one document, published or not
```

The verification key never leaves WordPress (D6), the forged app cannot mint a
token, and a preview response is never cacheable: the B1 cache filter answers
`is_preview_request()` before it marks anything public. The preview page is
`noindex, nofollow` with `Cache-Control: private, no-store`.

---

## 5. Enhancement backlog

### 5.1 `addons/nvoos-astro-bridge` (bridge)

| # | Change | Why | Phase |
|---|---|---|---|
| A1 | ✅ **Shipped** — `OPTION_PUBLIC_ORIGIN` + setting; used by `shop_defaults()` and the shop-URL allowlist | `home_url()` is the wrong origin when the CMS is on a subdomain | 3 |
| A2 | ✅ **Shipped** — `Cache-Tag` / `Surrogate-Key` on public GETs | Enables `cache.invalidate({tags})` + CDN providers | 4 |
| A3 | ✅ **Shipped** — token-gated draft reads (`?preview_token=`) on the local collection + `POST /local/<id>/preview-token` | Preview of unpublished content | 5 |
| A4 | Tracking/consent config endpoint (GTM/GA4 ids + consent-mode v2 defaults) | One source of truth for the emitted head | 6 |
| A5 | Fail closed on an unexpected `Origin` (deferred: no cross-origin consumer in the managed topology) | Today the absence of CORS is implicit | 4 |
| A6 | ✅ **Shipped** — media origin option (`cms` absolute vs `frontend` `/media/`) | Same-origin media without leaking the CMS host | 4 |

### 5.2 `examples/nvoos-astro-forge` (forge)

| # | Change | Why | Phase |
|---|---|---|---|
| B1 | ✅ **Shipped** — `deploy.runtime: 'static' \| 'managed'` in the profile + `DeployConfig` | The two-tier switch | 1 |
| B2 | ✅ **Shipped** — managed **proxy endpoint routes** (`/api/*`, `/api/forms/submit`, `/media/*`) + token-gated `/api/revalidate`. **Not** `src/fetch.ts`: a custom fetch handler makes Astro skip its sessions/actions/middleware/cache handlers | Makes `/manage` and revalidate portable | 2 |
| B3 | **Partial** — per-response tag emission on the SSR product route shipped (`Astro.cache.set` behind the generated `MANAGED` flag); live collections (`defineLiveCollection`) remain | Fresh bridged content without a rebuild | 2c |
| B4 | ✅ **Shipped** — `routeRules` + `memoryCache()` emission | Codifies cache policy next to the routes | 2 |
| B5 | ✅ **Shipped** — the forge-bridge posts `{ tags }` to `/api/revalidate` on content changes, independent of the dispatch | Seconds-fresh content, dispatch as backstop | 2b |
| B6 | ✅ **Shipped** (gate; the emission shipped with 130) — consent v2 default/update + `ad_user_data`/`ad_personalization` checks in `verify --tracking` | EEA/UK compliance; catches the "records but never updates" bug | 6 |
| B7 | ✅ **Shipped** — `/api/preview` (token → host-only cookie) + `/preview/<slug>/` SSR draft render, emitted for `runtime: managed` | Editors see drafts | 5 |
| B8 | ✅ **Shipped** (shape) — `deploy` block (`runtime`/`manage`/`revalidate`/`forms`) | Drives the bundle and deploy shape per site declaratively | 1 |
| B9 | ✅ **Shipped** — one local-content store per site: the bridge's `nvoos_forge_local` CPT, read through `source: 'local'` | Avoids two content sources | 5 |

### 5.3 `nvoos-forge-sites` (mirror)

| # | Change | Why | Phase |
|---|---|---|---|
| C1 | ✅ **Shipped** — `workflow_dispatch` `preview: true` writes `site/<name>-preview` | Preview deployments | 4 |
| C2 | ✅ **Shipped** — `deploy.json` manifest at each branch root (`runtime`, `entry`, `outputDirectory`) | Reproducible Velocity settings instead of console folklore | 1 |
| C3 | ✅ **Shipped** (opt-in `NVOOS_FORGE_SMOKE`) — post-push 200 + `noindex` check on the public origin | Catches a broken mirror push | 4 |
| C4 | Keep secrets in monorepo vars/secrets only | Already true; preserved | — |

---

## 6. Decisions required (owner)

| # | Decision | Recommendation |
|---|---|---|
| D1 | CMS hostname convention: `cms.<domain>` vs `admin.<domain>` | `cms.` — matches the industry references and reads correctly for editors |
| D2 | Is the CMS public for editors, or is `/manage` the only editing surface? | `/manage` for Class B; `wp-admin` on an allowlisted host for Class A |
| D3 | Class B on the same shared mirror, or its own mirror? | Same mirror, `runtime: managed` — one pipeline, one mental model |
| D4 | Local content: WordPress CPT or Git (Keystatic)? | **Decided: WordPress `nvoos_forge_local` CPT behind the bridge** (one editing surface, one auth model, one audit trail). Shipped. |
| D5 | Do we adopt Astro 7 route caching now or after 7.2 ISR stabilises? | Now for managed; ISR is not a dependency |
| D6 | Preview authentication: shared secret on the frontend vs bridge-verified token? | **Decided: bridge-verified, frontend-transported.** The token is `HMAC(wp_salt('auth'), post_id.exp)`; the forged app only stores it in a host-only cookie. The key exists only in WordPress, so a frontend compromise cannot mint previews. |
| D7 | Ship the `/manage` SPA bundle inside the forge template? | **No.** `addons/nvoos-astro-bridge/LICENSE` is proprietary and the forge template is a separate distribution, so a compiled bundle cannot be redistributed with it. Editors use the CMS-hosted Astro CMS page (today), or a future `/manage` route that *loads the bundle from the licensed CMS host* (no redistribution). Recorded, not built. |

---

## 7. Success metrics

- A new site is one `sites/<name>.json` (plus `deploy.runtime`); no new repo,
  no console-only configuration.
- Class A: content change → live in < 5 min (rebuild).
- Class B: content change → live in < 60 s (tag revalidate).
- `verify --tracking --strict-tracking` fails on a consent default without an
  update path.
- No client site has a publicly indexable CMS origin.
- `forge-sites.yml` is green for both runtimes with unset mirror secrets
  (build-only degradation preserved).

---

## 8. Effort

| Phase | Scope | Estimate |
|---|---|---|
| 1 | `deploy.runtime` + manifest + workflow plumbing + tests | 0.5 day |
| 2 | `src/fetch.ts` BFF + live collections + `routeRules` + revalidate | 3–4 days |
| 3 | Bridge public origin + fail-closed origin | 1 day |
| 4 | Cache tags + media origin + preview branches + smoke check | 2 days |
| 5 | Preview/draft + local content (CPT, contract routes, `/manage` view, forge `source: 'local'`, preview exchange + SSR draft render) | 2–3 days |
| 6 | Consent emission + tracking gate | 1–2 days |

---

## 9. Risks

| Risk | Mitigation |
|---|---|
| `src/fetch.ts`, route caching, and live collections must exist at the pinned Astro version | **Resolved for Phases 2–3**: route caching + `memoryCache()` verified on Astro 7.4.4. Live collections remain Phase 2b — prototype on one staging profile first |
| Astro 7.2 incremental builds are experimental | Never a dependency; rebuild remains the backstop |
| Managed deploy silently ships without `server.js` | **Fail closed** at mirror-push (Phase 1) |
| Bridge `home_url()` assumptions break on a subdomain | Phase 3 must land before the first managed client |
| Two content stores drift | D4 forces one per site |
| A preview token leaks a draft | Token is per-document, HMAC-signed, expires in 15 min, and the response is never cacheable (the B1 filter runs `is_preview_request()` first) |
| `/manage` bundle redistributed under the wrong licence | D7: never bundle it; load it from the licensed CMS host or use the CMS page |
| The generated drift manifest fails on every managed site | **Fixed 2026-11-09** — the manifest is now hashed after the managed rewrite (`writeGeneratedManifest`, step 3g) |
| Velocity console settings are not in the repo | `deploy.json` manifest (C2) documents them per branch |

---

## 10. Out of scope

- Multisite WordPress consolidation (one WP app per client remains the default).
- A CMS-hosted preview service (the preview exchange lives on the Astro side).
- Migrating the velocity storefront to `src/fetch.ts` (it keeps `serve.mjs`;
  the forge emission is new code, not a refactor of a live app).
- Any change to the licensed PWA / `funiq/v1` legacy namespace.
