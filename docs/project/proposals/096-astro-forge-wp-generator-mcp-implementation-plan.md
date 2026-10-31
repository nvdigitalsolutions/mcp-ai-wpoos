# Proposal 096 — Implementation Plan: `nvoos-astro-forge` + `nvoos-astro-bridge`

**Companion to:** `096-astro-forge-wp-generator-mcp-proposal.md` (design, options, decisions) · `096-astro-forge-wp-generator-mcp-research.md` (verified sources)
**Date:** 2026-10-30
**Ground rules:**
- Loader-first: generated projects consume WordPress via the Astro Content Layer API — no materialized content files by default; `content_materialize` is the opt-in escape hatch.
- Secrets: vault-backed credential store; octokit (no `git` CLI); nothing secret in argv, logs, tool responses, or generated output (CI grep gates).
- Templates are Astro 7 and reuse 092/095 assets; generated output never contains licensed bytes (092's rule).
- Hermetic: WordPress Playground fixtures in CI — no live site needed (repo skill: `mcp-ai-wpoos-playground-demos`).

---

## 1. File map

```
examples/nvoos-astro-forge/
  package.json                # SDK v2, octokit, sanitize-html, zod; scripts: build/lint/test/e2e/forge
  src/
    index.ts                  # stdio entry (npx nvoos-astro-forge)
    http.ts                   # optional Streamable HTTP entry (team mode, P4)
    registry.ts               # SINGLE SOURCE OF TRUTH: tool name → Zod schema + annotations
    tools/
      sites.ts                # site_add/test/list/get/update/remove/set_default
      discover.ts             # site_analyze, content_audit, site_health
      scaffold.ts             # scaffold_project/status/package, tokens_apply, ads_generate
      content.ts              # collections_generate, content_preview, content_materialize,
                              # redirects_generate, media_audit, media_rewrite
      sync.ts                 # rebuild_config, rebuild_trigger, preview_token, webhook_verify
      github.ts               # github_init/create_repo/commit/push/status (octokit)
      deploy.ts               # deploy_config, deploy_status, setup_wizard
    services/
      wp-client.ts            # paginated WP REST client (_fields/_embed, rate limit, retry)
      discovery.ts            # capability detection (CPTs, taxonomies, SEO plugin, Woo, REST lockdown)
      vault.ts                # OS-keychain-backed store; encrypted-file fallback; gitignored 0600 floor
      sanitize.ts             # sanitize-html config shared by loader + content_preview
      allowlist.ts            # site-URL SSRF guard (is_url_allowed pattern)
      github.ts               # octokit wrapper; token from env only; redacted responses
      templates.ts            # template versions, manifest (nvoos.json) read/write
  lib/
    wp-loader.ts              # REUSABLE Astro Content Layer loader: defineLoader wrapper
                              # (pagination, digests/cache, Zod schemas, sanitize, render HTML)
    wp-loader.d.ts
  templates/
    blog/                     # Astro 7: posts/pages/CPTs, search, RSS, JSON-LD, sitemap,
                              # 404, /preview SSR route, ADS tokens (095 defaults), nvoos.json
    storefront/               # + Woo wc/v3 loader / Payload-envelope option, 092 product
                              # components as islands, cart badge, PLP/PDP layouts
  tests/
    unit/wp-client.test.ts    # pagination, _fields, rate-limit/429, retry, error taxonomy
    unit/loader.test.ts       # digest cache, schema coercion, sanitize fuzz, render output
    unit/tools.test.ts        # per-tool units (incl. scaffold determinism, materialize idempotence)
    mcp/handshake.test.ts     # initialize → tools/list → tools/call conformance (SDK client)
    mcp/auth.test.ts          # vault/allowlist/redaction matrix
    e2e/forge-loop.spec.ts    # Playground WP → add/analyze/scaffold → astro build → preview
    fixtures/wp-playground/   # blueprint fixtures (plain WP, WP+Woo, restricted-REST variant)
plugins/nvoos-astro-bridge/
  nvoos-astro-bridge.php
  includes/class-rest.php     # astro_seo register_rest_field (normalized across Yoast/RankMath/AIOSEO)
                              # + health endpoint (anonymous = status only, internals manage_options-gated)
  includes/class-webhook.php  # HMAC-signed publish/update/trash dispatcher, debounced, non-blocking
  includes/class-preview.php  # single-use, 5-min-TTL, edit_post-capability-checked preview tokens
  admin/class-settings.php    # 3 fields: Astro URL, webhook URL, secret (read-only password field)
  tests/phpunit/              # phpunit + plugin-check green (wp.org discipline)
  readme.txt
docs + skills:
  .agents/skills/mcp-ai-wpoos-astro-forge/SKILL.md           # P4
  docs/project/proposals/096-*                               # status updates as phases land
```

---

## 2. Phase 0 — Spike (~1–2 wks)

**Goal:** prove the loader-first thesis end-to-end on a WordPress Playground fixture.

| # | Task | Notes |
|---|---|---|
| 0.1 | `wp-client.ts` | Paginated fetches with `_fields` pruning + `_embed`; token-bucket rate limit + 429 halving; structured error taxonomy (`WPForgeError` hierarchy, in-band `isError`) |
| 0.2 | `wp-loader.ts` prototype | `defineCollection({ loader: wpLoader({ url, route, schema, fields }) })`; digests for the content cache; sanitize via `sanitize-html`; HTML rendered, not Markdown-converted |
| 0.3 | Discovery | `site_add` + `site_analyze` against Playground: WP version, public CPTs/taxonomies, SEO plugin detection, Woo presence, REST lockdown detection |
| 0.4 | Blog template v0 | Layouts, index/post/page/404, sitemap, robots, RSS, JSON-LD, search (Pagefind), ADS token stub (095 Phase 0 tokens once available), `nvoos.json` manifest |
| 0.5 | `scaffold_project` | Deterministic output (no timestamps; sorted keys) so scaffold diffs are stable; writes template + generated `content.config.ts` |
| 0.6 | CI: Playground fixture | Blueprint boots WP in CI (`@wp-playground/client`), seeds fixture posts; `astro build` of the scaffolded output must pass; e2e crawlability (JS-disabled) |

**Exit:** one `forge scaffold` command → `astro check && astro build` green in CI against the fixture; loader unit tests green; `< 2 min` scaffold on the fixture.

### 2.1 Phase 0 status — shipped 2026-10-31 (PR to `alpha-working`)

The generation core is live in `examples/nvoos-astro-forge/` as a **CLI-first
package** (the MCP tool surface from §1 lands in Phase 1+ — the CLI is the
standalone delivery seam, per the `docs-hub`/`content-graph` standalone-plugin
precedent):

| Plan item | Shipped as | Notes |
|---|---|---|
| 0.1 `wp-client.ts` | `lib/wp-client.ts` | Pagination via `X-WP-TotalPages`, `_fields`/`_embed`, typed `WpClientError` codes, explicit `truncated` flag. Rate limiting deferred to the MCP surface (CLI is operator-run) |
| 0.2 `wp-loader.ts` | `lib/wp-loader.ts` | Astro 7 content-layer loader: ID-keyed entries, `generateDigest(modified_gmt)`, sanitized `rendered.html`, `store.clear()` propagates deletions, truncation warning |
| 0.3 Discovery | partial — `forge.mjs --check` | Root doc + per-collection size-vs-cap report; full `site_analyze` (CPTs, SEO plugin, Woo, REST lockdown) stays in Phase 1 |
| 0.4 Blog template | `templates/blog/` | index/posts listing/post detail/page/sitemap/robots, canonical + `BlogPosting` JSON-LD, `--forge-*` tokens defaulting to the ADS core palette (098 output applies over them). 404/RSS/Pagefind search deferred to Phase 1 |
| 0.5 `scaffold_project` | `lib/scaffold.ts` + `scripts/forge.mjs` | Template copy (dir-basename filter), FORGE-marker line-anchored rewrite, `nvoos.json` manifest, `.env.example`, gitignore gate (`dist/`/`.astro/` never pushed), overwrite guard that refuses non-forge dirs |
| 0.6 CI fixture | `scripts/wp-fixture-server.mjs` + `.github/workflows/astro-forge.yml` | Hermetic wp/v2 wire server (real headers/params) stands in for the WordPress Playground blueprint until Phase 2; e2e = fixture → scaffold → `npm install` → `astro check` + `astro build` → HTML asserts (XSS dead, SEO wired) |

**Exit-gate result (local, 2026-10-31):** 38 unit tests green · typecheck clean ·
e2e 6/6 green — scaffolded blog passes `astro check` + `astro build` against
the fixture and emits 9 pages; `alert`/`onerror`/`javascript:` count = 0 in
built HTML. Build learnings that cost fixes are recorded in the research doc
§0 (Astro 7 spread-params-as-strings, line-anchored marker rewriting,
dir-basename copy filter, template-literal regex escaping, truncation math).

### 2.2 Phase 1a status — Woo collections + storefront, shipped 2026-10-31

The Woo/storefront slice of Phase 1 is live in the same package:

- **Commerce loader** (`kind: 'product'`): wc/v3 flat documents —
  price/regular_price/sale_price/stock_status/sku/images/categories — via
  `WP_WC_CONSUMER_KEY`/`_SECRET` (authMode `wc-consumer`, HTTP Basic), or
  wp/v2 product CPTs (no commerce fields; `featured_media` resolved by ID
  with bounded concurrency when `_embed` has none).
- **Storefront template pages**: `/products/` listing (sale = strike-through
  regular price — Woo sets `price` to the sale price, so sale state is
  `salePrice !== regularPrice`, never `!== price`) + `/products/{id}/{slug}/`
  detail with `Product` JSON-LD (Offer + availability), stock badge, SKU.
- **Static front page**: profile `frontPage` slug renders the page at `/`;
  `[slug]` and the sitemap skip it — one canonical URL (the 096 §0 SEO rule).
- **Excludes**: per-collection `exclude` slugs filter junk/system pages.
- **Optional collections**: `optional: true` tolerates an absent source
  (404 → empty collection + warning, auth failures still fatal).
- **Scaffold pruning**: profiles without a `products` collection get the
  storefront pages removed (no undefined-collection build failures); the
  sitemap endpoint is manifest-driven for the same reason.
- **Site identity**: `Base.astro` reads the brand name from `nvoos.json`.

**Validation:** 48 unit tests + e2e 8/8 (front page, catalog prices/sales,
Product JSON-LD, no duplicate front-page URL). **Live forge (pilot
customer, 2026-10-31): 49 pages built from the production site** — 2 posts,
14 content pages (10 system/auth slugs excluded), 31 products, zero XSS
vectors in built HTML, brand chrome correct. Commerce data (prices) awaits
Woo REST consumer keys from the customer (env-only, never in-repo).

### 2.3 Phase 1b status — license + tokens_apply + SEO, shipped 2026-10-31

- **License (owner decision Q7 — mirrors 092 Q8):** the forge package is
  **MIT** via `LICENSE` + `NOTICE` inside the GPL-3.0-or-later repo; the
  scaffold ships the NOTICE (MIT notice + generated-output rule) into every
  generated project; `private: true` until Phase 3 publishing.
- **`tokens_apply`:** profile `tokens` (098 design-language output) merges
  over the `--forge-*` defaults between the line-anchored
  `/* FORGE:tokens */` markers; the manifest records the applied tokens; a
  dependency-free WCAG 2.2 contrast gate (`lib/contrast.ts`) warns (never
  blocks) when ink/surface or accent-ink/accent fail SC 1.4.3 — the CLI
  prints the warnings.
- **SEO suite:** `/feed.xml` (RSS 2.0, linked from the chrome), branded
  `/404.html`, and `/redirects.json` — the structured 301 map from the WP
  URL structure to the generated one (single-hop, front-page → `/`).
  `scripts/postbuild.mjs` materializes the host-agnostic `dist/_redirects`
  (Netlify/Cloudflare/static-server compatible).

**Validation:** 61 unit tests (contrast known-pairs, token rewrite/merge,
NOTICE ship, marker gates) + e2e 9/9 (baked accent CSS, RSS items/guids,
404, redirect map with the front-page and product entries).

### 2.4 Phase 1c status — site_analyze + media pipeline, shipped 2026-10-31

- **`site_analyze`** (`lib/discovery.ts` + `forge.mjs analyze <url>
  [--write site.json]`): one polite pass (~6 requests) — root doc, post
  types, per-collection counts, the static front page slug, junk-page
  heuristics (system/auth slugs + `manage-profile*` prefixes), Woo
  detection with a wc/v3 auth probe (falls back to the wp/v2 CPT count
  when consumer keys are required — never fails analysis), REST-lockdown
  detection, and SEO-plugin detection (namespace-root deduped). Output:
  a summary + a scaffold-ready draft profile.
  **Live validation:** the draft profile reproduces the hand-built pilot
  profile — all three collections, `/home/` front page, and all ten
  manually excluded junk pages found automatically.
- **Media pipeline** (`SmartImage.astro` + config `image.domains`):
  same-host WordPress images flow through Astro Image (responsive
  WebP/AVIF via `widths`/`sizes`/`inferSize`, lazy); the domain allowlist
  derives from `WP_SITE_URL ?? nvoos.json` (the content layer's env-first
  rule). Any other host (CDNs, external) passes through as a plain
  `<img>` — a remote image can never fail the build.

**Validation:** 66 unit tests (discovery canned-backend incl. lockdown,
woo-count fallback, junk heuristics; profile drafting) + e2e 11/11
(optimized `/_astro/*.webp` srcset in the listing, CDN passthrough,
`forge analyze` against the fixture server drafting the full profile).

### 2.5 Phase 1d status — Pagefind search + Payload-envelope variant, shipped 2026-10-31

- **Search (Pagefind):** the template's build chains
  `astro build && postbuild && pagefind --site dist`; `/search/` is a thin
  shell that lazily imports the runtime on first input (debounced, results
  with excerpts/marks). e2e asserts the emitted `pagefind/` runtime +
  entry manifest.
- **Payload-envelope collections** (`source: 'payload'`): the loader reads
  the `nvoos-commerce/v1` bridge envelope — body pagination
  (`{ docs, totalDocs, totalPages }`, `page`/`limit` params, no `_fields`),
  flat documents mapped to the stable shape (`name` → title, slugified;
  `oldPrice` = strike-through regular price → sale when it differs from
  `price`; `image(s)`, single `category`, named `statuses`). Discovery
  offers the payload candidate when the namespace is advertised and no
  product CPT exists.

**Validation:** 68 unit tests (payload extraction incl. oldPrice sale
mapping + slugify, envelope pagination + truncation, discovery payload
candidate) + e2e 12/12 (Pagefind runtime + index asserted in the built
output).

---

## 3. Phase 1 — Storefront template (~2 wks)

| # | Task | Notes |
|---|---|---|
| 1.1 | Woo loader variants | `wc/v3` loader (consumer key or app-password auth) + Payload-envelope loader option when `nvoos-commerce/v1` is reachable (funiq-bridge present) |
| 1.2 | Reuse 092 components | Product card, cart badge, gallery islands copied from `examples/nvoos-astro-velocity/src/components|islands` (template-ized: no hardcoded contract URLs; env-driven base like the storefront's `resolveBase`) |
| 1.3 | SEO suite | Product/Article/Breadcrumb JSON-LD, canonical, sitemap, redirects generator (`redirects_generate`) from WP→Astro URL map |
| 1.4 | ADS defaults | Generated projects ship 095's `src/tokens/` layout + `tokens_apply` (brand theme at scaffold time via DTCG file) |
| 1.5 | Gates | Storefront template builds against Woo fixture; licensed-bytes grep gate on generated output; image pipeline uses Astro Image with WP media domains allowlisted from env |

**Exit:** generated storefront passes the 092-style crawlability e2e; `tokens_apply` rebrands the fixture site in one call.

---

## 4. Phase 2 — `nvoos-astro-bridge` plugin (~2 wks)

| # | Task | Notes |
|---|---|---|
| 2.1 | `astro_seo` REST field | Normalized across Yoast/RankMath/AIOSEO/fallback (title/description/canonical/OG/robots) — wp-astro-bridge's proven contract, re-implemented to this repo's escaping/capability standards |
| 2.2 | Webhook dispatcher | HMAC-SHA256 (`X-Astro-Signature`), timing-safe verify, 2 s debounce, non-blocking dispatch to the configured webhook URL; fail-closed (no secret → reject) |
| 2.3 | Preview tokens | Single-use, 5-min TTL, `edit_post` capability re-check per post; preview URL rewrite on the WP Preview button |
| 2.4 | Health endpoint | `/wp-json/nvoos-astro-bridge/v1/health` — anonymous: status + version only; internals `manage_options`-gated |
| 2.5 | `rebuild_config` | Generates the client repo's `.github/workflows/rebuild.yml` (webhook → `repository_dispatch` → `astro build` → deploy) — the funiq-bridge `RebuildWebhook` pattern generalized to posts/pages |
| 2.6 | Gates | phpunit + plugin-check green (wp.org discipline); HMAC fail-closed tests; token expiry/capability tests; escaping audit (wp-security-audit skill checklist) |

**Exit:** publish → webhook → rebuild loop works against a Playground WP + fixture workflow; plugin passes the plugin-check gate.

---

## 5. Phase 3 — Publishing & multi-site (~2 wks)

| # | Task | Notes |
|---|---|---|
| 3.1 | `vault.ts` | Credential store: OS keychain when available, encrypted JSON file (AES-256-GCM, key from `NVOOS_FORGE_VAULT_KEY` env) otherwise; gitignored-0600 plaintext config as documented fallback only (Q6); masked in every tool response |
| 3.2 | GitHub tools (octokit) | `github_create_repo` (private default), commit/push with per-invocation auth (never persisted); redacted `github_status` output |
| 3.3 | Deploy configs | Velocity: connected-repo mirror recipe (the 092 `sync-nvoos-astro-velocity.yml` pattern, parameterized per client); Cloudflare Pages: static deploy config; adapter-generic output |
| 3.4 | Multi-site profiles | Per-site profiles (URL, credentials, export config, allowlist entry, default flag); two-site round-trip demo in CI |
| 3.5 | Secret grep gates | CI greps: no `ghp_`, no app-password patterns, no `NVOOS_FORGE_VAULT_KEY` in generated output, logs, or git diffs |

**Exit:** two-site agency demo (scaffold both, publish one, sync the other) passes in CI; grep gates green.

---

## 6. Phase 4 — Wizard, packaging, docs (~1–2 wks)

| # | Task | Notes |
|---|---|---|
| 6.1 | `setup_wizard` | One command: add site → analyze → scaffold → preview → git init → deploy config (the wp-astro-mcp `setup_wizard` idea, on our architecture) |
| 6.2 | Router mode | `NVOOS_FORGE_MODE=router|full` — 3 meta-tools + promoted tools (095 craft list) |
| 6.3 | npx + registry | Package `@nvdigitalsolutions/nvoos-astro-forge` (054 pattern), MCP registry entry (061 pattern), `mcpName` marker |
| 6.4 | Demo + skill | Playground-based demo blueprint (repo skill), `.agents/skills/mcp-ai-wpoos-astro-forge/SKILL.md` (ops guide), README + proposal status updates |

---

## 7. Test & CI matrix

| Gate | Command / mechanism | Phase |
|---|---|---|
| Loader units | `vitest run tests/unit/loader.test.ts tests/unit/wp-client.test.ts` | P0 |
| Tool units | `vitest run tests/unit/tools.test.ts` | P0–P3 |
| MCP conformance | `vitest run tests/mcp/handshake.test.ts` | P0 |
| Auth/vault/redaction | `vitest run tests/mcp/auth.test.ts` | P3 |
| Template builds | Playground WP + Woo fixtures → `astro check && astro build` on scaffolded output | P0–P1 |
| Crawlability e2e | Playwright JS-disabled crawl of generated blog + storefront | P1 |
| Bridge plugin | phpunit + plugin-check + webhook/preview-token suites | P2 |
| Secret gates | grep for token patterns in output/logs/diffs; scaffold determinism (no timestamps) | P3 |
| Registry coverage | CI fails if a tool lacks a Zod schema or annotation (single-source `registry.ts`) | P0+ |

## 8. Adding a tool — the drift checklist (095 §8, applied)

1. `registry.ts` entry (name → Zod schema + read-only/destructive/open-world annotation).
2. Unit test (success/failure/idempotence where applicable).
3. Handshake listing assertion (tools/list contains it; schema matches).
4. Auth/vault/redaction matrix row if it touches secrets or outbound fetches.
5. Tool catalog table in the proposal + this plan updated.
6. e2e touch point if user-visible in the forge loop.

## 9. Security checklist

- [ ] Site-URL allowlist before any fetch (SSRF guard); Playground/localhost entries explicit.
- [ ] Credentials in the vault only; masked in responses; env-only for GitHub + vault key.
- [ ] octokit only — no `git` CLI subprocesses, no argv secrets.
- [ ] `sanitize-html` on ALL WordPress HTML before render (loader + `content_preview`); allowlist config versioned.
- [ ] Bridge plugin: nonce/cap checks on settings, HMAC fail-closed, preview tokens single-use + `edit_post`-checked, health endpoint redacts internals.
- [ ] Generated repos contain zero secrets (grep gates) and zero licensed bytes (092 rule).
- [ ] Registry publishes with a package lock; CI validates the published tarball (054 precedent).

## 10. Effort & sequencing

| Phase | Effort | Depends on | Parallelizable with |
|---|---|---|---|
| 0 — Spike | 1–2 wks | — | 095 P1 |
| 1 — Storefront template | 2 wks | P0 (+ 095 P0 tokens) | 095 P2 |
| 2 — Bridge plugin | 2 wks | P0 | 095 P2–P3 |
| 3 — Publishing + multi-site | 2 wks | P1/P2 | 095 P3 |
| 4 — Wizard + packaging | 1–2 wks | P3 | 095 P4 |

Total ~8–10 weeks; staged commits + PRs against `alpha-working` per the `mcp-ai-wpoos-git-ops` skill (titles `Proposal 096 — …`).
