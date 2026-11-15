# Phase roadmap (096 §5) — historical status

> Extracted from `SKILL.md` on 2026-11-10 to keep the skill under Zed's
> ~100 KB load limit. This is the SHIPPED/status history; the operational
> rules live in `SKILL.md`. Read this when you need to know what landed
> when (or what is still outstanding) rather than how to operate the forge.


- **Phase 0 — shipped** (PR #7335, merged 2026-10-31): client, loader,
  sanitizer, scaffold CLI, blog template, hermetic fixture, CI exit gate.
- **Phase 1a — shipped 2026-10-31** (PR #7337): Woo collections (`kind:
  'product'`, wc/v3 + wp/v2 shapes, wc-consumer auth env), storefront
  template pages (`/products/` + detail, Product JSON-LD), static front page
  (`frontPage`), per-collection `exclude`, `optional` sources, scaffold page
  pruning, brand name from `nvoos.json`. Live pilot forge: 49 pages from
  production.
- **Phase 1b — shipped 2026-10-31:** MIT license carve-out (owner decision
  Q7, mirrors 092 Q8 — NOTICE ships into generated projects), `tokens_apply`
  (098 design language over the `--forge-*` markers + WCAG contrast gate
  warnings), SEO suite (`/feed.xml`, `/404.html`, `/redirects.json` →
  `dist/_redirects` post-build).
- **Phase 1c — shipped 2026-10-31:** `site_analyze` (`forge.mjs analyze
  <url> [--write site.json]` — types/counts/front-page/junk/Woo/SEO/lockdown
  probes, wc/v3-auth count fallback; live-verified against the pilot — all
  ten junk pages found automatically) + the media pipeline (SmartImage:
  Astro Image with `inferSize` for same-host images, CDN passthrough).
- **Phase 1d — shipped 2026-10-31:** Pagefind search (post-build index,
  lazy-loaded `/search/` shell) + the `source: 'payload'` collection variant
  (nvoos-commerce/v1 envelope — body pagination, `oldPrice` sale semantics,
  slugify for missing slugs; discovery offers the payload candidate when
  the namespace is advertised and no product CPT exists).
- **Phase 2 — shipped 2026-10-31:** the standalone companion plugin
  **`plugins/nvoos-forge-bridge`** (Q4 naming amendment — `nvoos-astro-bridge`
  was taken by the velocity bridge addon): signed, debounced rebuild
  webhook (X-Forge-Signature, `send_pending()`/`reset_pending()` seams),
  inbound signature-verified `/rebuild` route, single-use hashed preview
  tokens, redacted health, settings page with write-only secrets,
  uninstall.php, own phpcs + PHPUnit + `.github/workflows/phpunit-forge-bridge.yml`
  with the PCP gate, `.distignore`, and `.wordpress-org/` listing assets.
  **Standalone framing (2026-11-02):** the plugin's contract is a signed
  `repository_dispatch` + shared HMAC secret — ANY consumer works (GitHub
  Actions, Netlify, Vercel, custom CI); the forge is the reference consumer,
  not a requirement. The payload's self-verifying `time`/`nonce`/`signature`
  proof (`payload_signature()`) exists because the `X-Forge-Signature`
  header does not survive GitHub's dispatch delivery.
  **wp.org readiness pass (PR #7388, merged 2026-11-02; standalone-framing
  follow-up #7390):** pre-review fixes —
  `/preview-tokens/verify` is POST-only (state-changing GET removed, pinned
  by a GET-404 test), autosave/revision/auto-draft saves are skipped in
  `save_post`, `delete_post` + `trashed_post` hooks added, minting requires
  `edit_pages` (editors+, matches docs), tags `headless, rebuild, webhook,
  automation, static-site` (no framework names). Gates: PCP 0 errors/
  0 warnings, phpcs clean, `php -l` clean, PHPUnit 30/30 (75 assertions)
  on WP latest. Tooling: artwork via Gemini +
  `bin/process-nvoos-forge-bridge-artwork.js`; ZIP builder
  `bin/build-nvoos-forge-bridge.py` (Python zipfile, shape assertions, the
  ZIP itself is never committed); 18-point signoff
  `WPORG-REVIEW-18POINT-SIGNOFF.md` (excluded from the ZIP).
  **Submission state (2026-11-03):** the `plugin-check` CI job passed on both
  PRs (`Plugin Check ERRORS=0, WARNINGS=0` — runs 41456964217 / 41458449391).
  GitHub release `nvoos-forge-bridge-v0.1.0` hosts the submission ZIP + sha256
  (no standalone mirror repo yet) — the add-form Plugin URL:
  `https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download/nvoos-forge-bridge-v0.1.0/nvoos-forge-bridge-v0.1.0.zip`.
  The drafted add-form copy (name, short description, Additional Information)
  lives in the wporg-submission skill's forge-bridge section.
- **Phase 3 — shipped 2026-10-31:** vault + multi-site + GitHub deploy +
  generated pipeline (see `-implementation-plan.md` §2.7): `forge vault` /
  `forge site` / `forge deploy` commands; repos private by default;
  per-invocation auth (never persisted, masked); the emitted
  `verify-dispatch.mjs` is the CI-side signature verification — the bridge
  payload proof exists because the `X-Forge-Signature` header does not
  survive GitHub's dispatch delivery. Publish: `@nvdigitalsolutions/nvoos-
  astro-forge`, tag `nvoos-astro-forge-v*` → `publish-astro-forge.yml`
  (provenance). **Publishing lesson (2026-11-02):** 0.1.0 shipped with the
  raw `.ts`/`.mjs` sources as bin targets — fine from a checkout (Node
  native type stripping) but broken from an installed package: Node refuses
  type stripping under `node_modules/`
  (`ERR_UNSUPPORTED_NODE_MODULES_TYPE_STRIPPING`). 0.1.1 fixes it by
  shipping esbuild-bundled `dist/` entries (`scripts/prepare-dist.mjs` via
  `prepublishOnly`; `outExtension: { '.js': '.mjs' }`; no `banner` — the
  sources carry their own shebangs and esbuild preserves them, a banner
  would double the line and break the ESM parse). `dist/` is gitignored and
  built at publish time; `FORGE_ROOT` (`new URL('..', import.meta.url)` from
  `lib/scaffold.ts`) resolves identically from `dist/` since the bundles
  sit at the package root. Verify before publishing: `npm pack`, install
  the tarball in a scratch dir, run `node_modules/.bin/nvoos-forge`.
  **Registry lag (2026-11-02):** npm processes publishes async — the CLI
  prints the `+ pkg@ver` line then "Your package is being processed and
  may take a few minutes to become available"; `npm view` keeps showing
  the OLD packument (even after several minutes) and the version endpoint
  404s until processing lands. Verify with `npm view --prefer-online` or a
  direct `curl` of the packument; wait for `modified` to move.
  **Deprecations:** local npm is not authed on this machine — use the
  generic dispatch-only `npm-deprecate.yml` workflow (package/versions/
  message inputs; dispatch via the API JSON-body form with
  `ref=alpha-working`). 0.1.0 (broken bins) and the accidental
  `0.0.0-stage` are deprecated (2026-11-02) — use 0.1.1. Secret grep
  gates in CI + the e2e credential-hygiene walk.
- **Phase 4 — core shipped 2026-10-31:** `forge wizard` (one command, URL
  → built + deploy-ready) and the `/mcp` surface (see §2.8): 29-tool v1
  catalog (incl. the §3.3 long-tail slices — content_audit/preview,
  redirects_generate, media_audit, collections_generate, site_update,
  preview_token, webhook_verify) over the single-source registry,
  `NVOOS_FORGE_MODE=router|full` (3 meta-tools vs full), dual-era
  Streamable HTTP (`forge-mcp.mjs`) + stdio (`nvoos-forge-mcp-stdio`),
  rate limit + Host/Origin + fail-closed write auth, SSRF-guarded fetches.
  Remaining: registry publication (owner) and the Playground demo
  blueprint.
- **Proposal 104 Phase 4 — shipped 2026-11-02 (the forge half):** preset
  wizard (commander + clack; `blog`/`storefront`/`minimal`,
  `suggestPreset` from `site_analyze`, TTY-gated prompts), agent stubs
  (`AGENTS.md` + `.context/`, `--no-agents`), typed copy tables
  (`src/copy/`, the completeness gate is the type), kind-typed SEO util
  (`src/lib/seo.ts` over `schema-dts`, `<`→`\u003c` escape), theme module
  port (`src/lib/theme/`), Tailwind v4 token bridge
  (`tokens-tailwind.css`), limax slugs (D4). **Q4 + Q5 approved + shipped
  2026-11-02:** the render variant (`--render static|ssr` — on-demand PDP,
  server output via `@astrojs/node`, prerendered chrome, cache headers,
  301 slug convergence; wizard prompt for storefront) and per-post OG
  cards (`--og-images`, satori/resvg/@fontsource injected only when on),
  plus the wired `feed`/`sitemap` surface pruning. The storefront runtime
  contract lives in the template README — velocity-side phases (P1–P3)
  are the sibling track in the 104 plan.
- **Proposals 105 §4 + 106 P2 — shipped 2026-11-03 (the forge half):**
  `--theme-policy auto|light|dark|brand:<slug>` preset (invalid → clack
  error exit 2; default `auto`) baking `policy.generated.ts` in the
  scaffold; **canonical** `templates/blog/src/lib/theme/policy.ts` (the
  velocity storefront mirrors the ladder — keep logic identical; velocity
  adds `contrastForced`/`storedContrast` and live-doc reads); template
  `ThemeToggle` with forced-mode disable + delegated handlers
  (`astro:after-swap` sync). **Hybrid local content (106 P2)**: the posts +
  pages collections merge a local `glob()` (`src/content/posts|pages/*.mdx`)
  with the WP loaders **inside `createWpLoader`** — Astro 7.4.4 has no
  loaders-array, WP wins on store-id collision, network-level failures
  degrade to local-only with a warning (HTTP errors still fail; products
  never degrade); `@astrojs/mdx ^8.1.2` required for the mdx glob.
  SEO-meta passthrough: `rank_math_title`/`rank_math_description` →
  `yoast_head_json` (absent-plugin tolerant) in the posts/pages fields;
  `seoTitle ?? title` precedence in the templates; `Article`/`BlogPosting`/
  `WebPage` JSON-LD parity with velocity. Demo seed post ships in
  `src/content/posts/` — forged sites build offline with no WP connection.
  **Implementation gotchas (2026-11-03 session — each cost a fix):**
  - **Astro 7.4.4 `defineCollection` accepts a SINGLE loader** — there is
    no loaders-array merge (verified against `node_modules/astro/dist/content/`
    `config.d.ts` + `content-layer.js`). The hybrid merge is implemented
    INSIDE `createWpLoader` (glob first → WP second → local re-added only
    where WP produced no id) — WP wins on collision by construction.
  - **Hybrid collections need a schema `.transform()`** — local frontmatter
    goes through Astro's `parseData` validation, so the collection schema
    must accept it; the transform normalizes (Date→ISO,
    `description`→`excerpt`, slug fallback) so template pages keep `string`
    types with no optional-handling churn.
  - **Offline tolerance is scoped**: network-level failures (TypeError)
    degrade to local-only for the hybrid posts/pages collections; HTTP
    errors (auth/404/5xx) and non-hybrid collections (products) still fail
    the build — 096 never-silent-truncation.
  - **The e2e token assertion must resolve the built stylesheet href** —
    template additions push the CSS bundle past Astro's inline threshold,
    so asserting the token in the built CSS requires following the
    stylesheet link (assertion adapted, gate unchanged).
