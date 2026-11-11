---
name: mcp-ai-wpoos-astro-forge
description: Operational guide for the NV oOS Astro Forge (Proposal 096) — the WP→Astro frontend generator in examples/nvoos-astro-forge (wp-client, Astro content-layer loader, versioned sanitizer, scaffold CLI, blog template, hermetic WP wire fixture, CI exit gate). Use when forging a customer frontend, adding a template or collection, debugging the e2e gate, continuing Phases 1–4 (wc/v3 Woo loader, site_analyze, tokens_apply, MCP tool surface, bridge plugin), running the Proposal 114 design-rebuild loop (design block, semantic sections, contrast audit, forge verify), or working the 114 §3.9 forms bridge (sanitizer form allowlist, Form.astro, bridge forms/submit route).
---

# NV oOS Astro Forge — ops guide (Proposal 096 Phase 0)

## What this is

The generator that turns a WordPress/WooCommerce site into a native **Astro 7**
headless frontend. WordPress stays the CMS; the generated project pulls
content through an Astro **content-layer loader** at build time (freshness =
webhook → rebuild, never runtime fetches), renders sanitized HTML, and ships
SEO-complete static output. Pilot customer: a WooCommerce storefront — its
design language was already extracted via Proposal 098 (see the customer
notes below). Full context: `docs/project/proposals/096-astro-forge-wp-generator-mcp-proposal.md`
(+ `-implementation-plan.md`, `-research.md`).

## Architecture decisions (do NOT re-litigate)

- **Content-layer loaders, not materialized content files** — `content_materialize` stays opt-in (096 §2.3).
- **REST-first**: `wp/v2` + `wc/v3`, **never WPGraphQL**. `_fields` pruning + `_embed` are the anti-N+1 discipline.
- **Sanitization is mandatory, versioned, allowlisted, never a passthrough** — all WordPress HTML passes `lib/sanitize.ts` before `set:html` (096 §2.4).
- **No plaintext credentials** — auth comes from `.env` (`WP_APPLICATION_USER`/`WP_APPLICATION_PASSWORD`), never from the profile or the generated repo. SSRF-safe by construction: the CLI consumes operator-written site profiles only (the open-world fetch surface is the `/mcp` `design_analyze` guard, Proposal 098).
- **Never silent truncation** — `fetchAll` returns `truncated: true`, the loader warns, entries carry the flag, pages show a notice.
- **Built assets never ship** — the scaffold's gitignore gate guarantees `dist/` + `.astro/` are ignored in the customer repo.

## File map

| Path | Role |
|---|---|
| `lib/types.ts` | `SiteProfile` / `WpCollection` contracts |
| `lib/wp-client.ts` | WP REST client — `X-WP-TotalPages` pagination, typed `WpClientError` codes |
| `lib/wp-loader.ts` | Astro 7 content-layer loader (`createWpLoader`) |
| `lib/sanitize.ts` | `SANITIZE_VERSION` + allowlist + `sanitizeHtml()` |
| `lib/scaffold.ts` | Template copy, FORGE-marker rewrite, manifest, gates, preset surfaces, agent stubs |
| `lib/presets.ts` | 104 §4.3 preset registry — `blog`/`storefront`/`minimal` descriptors, `suggestPreset()`, `applyPresetToProfile()` |
| `lib/vault.ts` | Phase 3.1 credential store — AES-256-GCM per-entry (`~/.nvoos-forge/vault.json`), `NVOOS_FORGE_VAULT_KEY` passphrase, plaintext opt-in, `maskSecret` |
| `lib/sites.ts` | Phase 3.4 multi-site registry (`sites.json`) — named profile refs + default flag |
| `lib/github.ts` | Phase 3.2 octokit deploy — private-by-default repo create, git-data commit/push, sanitized errors |
| `lib/deploy-config.ts` | Phase 3.3 generated pipeline — deploy.yml/rebuild.yml + fail-closed `verify-dispatch.mjs` |
| `lib/wizard.ts` | Phase 4 setup_wizard — analyze → register → scaffold → build → git init → pipeline |
| `lib/content-scan.ts` | §3.3 content audit scanner (htmlparser2 — embeds/alt/hosts/shortcode artifacts + unprocessed brackets) |
| `lib/tracking.ts` | 130 tracker detector — vendor fingerprint table, HTML signals, optional Playwright probe (requests + globals + cookie NAMES), report → profile draft, CSP host derivation |
| `lib/tracking-audit.ts` | 130 `verify --tracking` gate — consent ordering, verification token/file presence, deferred static-HTML purity, purchase ownership, CSP superset/stale |
| `lib/seo-audit.ts` | 131 `verify --seo` gate — double-branded titles, robots, canonical, OG/Twitter, JSON-LD graph, html lang |
| `lib/redirects.ts` | 096 §3.3 + 132 redirect-map generator — URL-structure 301s merged beneath the bridge's stored Redirection/Rank Math rules (rebuild wins conflicts), fail-soft |
| `lib/ssrf.ts` | URL guard — private/literal-range refusal, registry allowlist + `NVOOS_FORGE_OPEN_ANALYZE` opt-in, CLI-only loopback exemption |
| `lib/vision.ts` | 118 §1 optional vision layer — OpenAI-compatible chat-completions, DATA-URL images in the body, fail-soft `{ok}` outcomes |
| `lib/capture.ts` | 118 §2 screenshot contract — `NVOOS_FORGE_CAPTURE_URL` endpoint or dropped `verify-artifacts/` files (browser-free) |
| `lib/project-files.ts` | Shared source collector for pushes (CLI deploy + `github_commit` tool) |
| `mcp/` | Phase 4 MCP surface (095 dual-era pattern): single-source `registry.ts` (zod + annotations + write flags; router/full modes), `bff.ts` (rate limit → Host/Origin → fail-closed write auth), `stdio.ts`, 7 tool slices |
| `scripts/forge-mcp.mjs` | MCP Streamable HTTP entry (`FORGE_MCP_PORT`, default 8472) |
| `server.json` | 061-pattern MCP registry metadata (`io.github.nvdigitalsolutions/nvoos-astro-forge`) — NOT in the npm `files` |
| `scripts/forge.mjs` | CLI on **commander** + **@clack/prompts** (104 §4.3) — `analyze`, `wizard [--preset …]`, `site`, `vault`, `deploy-config`, `deploy`, scaffold (`--preset`, `--no-agents`); plain JS, Node ≥ 22.6 native TS type stripping |
| `scripts/wp-fixture-server.mjs` | Hermetic wp/v2 + wc/v3 wire fixture — the Phase 0 CI stand-in for WordPress Playground (single-product detail routes for the Q4 SSR path) |
| `scripts/builtin-template.mjs` | Test fixture generator (nested `main/templates/blog/` tree with a REAL loader) |
| `templates/blog/` | The production template (posts/pages, canonical + JSON-LD, sitemap/robots, `--forge-*` tokens) + the 104 additions: `src/lib/theme/` (runtime theme module port), `src/copy/` (typed copy tables), `src/lib/seo.ts` (kind-typed JSON-LD), `src/styles/tokens-tailwind.css` (Tailwind v4 bridge, unused by default), the inert `src/pages/products/_ssr/[...slug].astro` (Q4 on-demand route) and `src/pages/_og/[slug].png.ts` (Q5 OG cards) |
| `tests/` | `wp-client` / `sanitize` / `wp-loader` / `scaffold` unit suites + `forge-e2e.test.ts` (exit gate) |
| `.github/workflows/astro-forge.yml` | CI: typecheck + unit + e2e on Node 24 (Astro 7 needs ≥ 22.23.4 — the runner's latest 22.x is one patch short) |

## Dev loop (hermetic — no live site needed)

```sh
cd examples/nvoos-astro-forge
npm install
npm test                 # 215 unit tests (client, sanitizer, loader, scaffold, presets, theme, seo, copy, …)
npm run typecheck
npm run test:e2e         # exit gate: fixture WP → scaffold → npm install → astro check + build → HTML asserts (~3 min, registry access for the generated projects' installs)
node scripts/wp-fixture-server.mjs   # :8473 — real wp/v2 wire shapes
node scripts/forge.mjs site.json --target ./out --check   # live source check (root doc + per-collection size-vs-cap)
node scripts/forge.mjs site.json --target ./out           # scaffold the project
```

## Forging a customer site (the standard loop)

1. Write `site.json` (operator allowlist): `{ url, name, collections: [{ slug, restBase, fields, embed, perPage, maxPages }] }`.
2. `--check` first — a build must never silently truncate.
3. Scaffold → `cd <target> && npm install && npm run build` with `ASTRO_SITE` set.
4. Design language (098 `design_theme` output) applies over the template's `--forge-*` tokens in `src/styles/global.css` — content is re-themed, never re-cloned.

Pick a preset with `--preset blog|storefront|minimal` (or let the wizard
suggest one from the analysis — Woo/products detected → `storefront`).

## The exit gate (CI-enforced)

`tests/forge-e2e.test.ts` boots the fixture server on an ephemeral port,
scaffolds the **production** template, `npm install`s the generated project,
runs `astro check` + `astro build`, then asserts on `dist/`: posts/pages
rendered, `alert`/`onerror`/`javascript:` count = 0 in the xss-trap post,
allowlisted embeds survive, canonical + `BlogPosting` JSON-LD + sitemap/robots
wired. The 104 extensions assert the agent stubs, copy tables, `lib/seo.ts`,
the theme module and the byte-identical `tokens-tailwind.css`, run the
**minimal preset** through its own install + `astro check` + build, and run
the **SSR variant** (Q4/Q5: `--render ssr` + `--og-images`) through
install + check + build, then serve `dist/server/entry.mjs` and assert the
live PDP (200 + `Product` JSON-LD + cache header, 301 slug convergence,
404) and the built OG cards. The wizard run exercises the non-TTY
`suggestPreset` path. The workflow runs on
`paths: examples/nvoos-astro-forge/**`.

## Gotchas (each cost a fix — do not re-hit)

The **full list** lives in [`reference/gotchas.md`](reference/gotchas.md) (split
out 2026-11-11 to keep this file under Zed's ~100 KB skill-load limit). Read it
before touching the CLI, the scaffold or the sanitizer. The highest-traffic ones:

- **Astro 7 spread params are flat slash-joined strings, NOT arrays** —
  `params: { parts: '1/slug' }`, never `['1','slug']`.
- **`forge.mjs` is plain JS** — `.mjs` gets no type stripping; `import type` and
  inline `type` specifiers are syntax errors there.
- **`scaffold()` stages beside the target and renames in** — the Windows
  `EPERM … forge-tmp-… -> <target>` on a fresh checkout is a retry, never a
  reason to delete the staging dir.
- **`--overwrite` re-scaffold is destructive** — it removes the target including
  `node_modules`.
- **Node strip-only mode bans parameter properties, enums and namespaces.**
- **A builder's numeric spacing scale is not a ladder** — `margin-four-bottom` is
  7.8px on an icon and 21.6px on a paragraph. Measure the computed box
  (`bandFacts[id].box`); never guess the class name's value.
- **The Bootstrap grid is coupled** — `.container-fluid`'s side padding and
  `.row`'s negative margin only work as a pair; restating one shrinks the band.
- **The sanitizer allows attributes PER TAG** — `class` must be listed for the
  tag or the builder's styles vanish (headings were missing it until 2026-11-11).
- **Vendor utilities and the accordion `display:none` are DATA now** — never
  re-introduce them into `global.css`; they come from `bands.ts` +
  `bandFacts[id].hidden`.
- **The class-coverage audit must include the page's inline `<style>`** and treat
  `[class~=…]`/`[class*=…]` as coverage, or correctly-styled bands read as missing.

## Template contract (FORGE markers)

`templates/blog/src/content.config.ts` carries line-anchored markers:
`// FORGE:site` … `// FORGE:collections-end`. `scaffold()` replaces that span
with env-driven `siteUrl` (`import.meta.env.WP_SITE_URL ?? '<profile url>'`),
the auth block, one `WpCollection` literal per profile collection, and the
`export const collections` block. Everything outside the span (imports,
`defineCollection` wiring) belongs to the template author. `nvoos.json`
(manifest v1: generator/url/generatedAt/collections) marks a forge project —
it is both the idempotency marker and the clobber guard.

## Customer design-language notes (feed into the forge)

From the 098 pipeline for the pilot customer (a WooCommerce storefront):

- Extracted design language: accent `#720eec` (Woo `--wc-primary`), surface
  `#ffffff`, body text `#7a7a7a`, type MuseoSansRounded/Oswald — theme name
  `pilot`. **WCAG flag:** body `#7a7a7a` on white = 4.29:1 (fails AA); the
  customer's unassigned pool has `#555555` (7.8:1) as the one-token fix — a
  ready upsell during the rebuild.
- Forge today: posts/pages collections can be forged live against the
  customer's site. Woo products need Phase 1's `wc/v3` collection.
- **Runtime theming port target (104 §4.2):** `templates/blog/src/lib/theme/`
  is the canonical port of the velocity theme module (`theme.ts` +
  `registry.ts`, pure — no Astro imports). The boot script + toggle land in
  the template layout when runtime theming ships (104 Q4 define-only); the
  forge registry variant derives brand themes from the template's own
  `--forge-*` token block, mirroring how velocity derives them from the
  generated ADS bundle.
- Keep the customer's name and URL out of skills and generated code — the
  design tokens alone are the deliverable (the 098 recreation loop is
  concept-adaptation, never cloning).

## Proposal 114 — design rebuild (shipped 2026-11-05)

The forge never replicates a source's markup: it extracts the design
LANGUAGE (098-style) and rebuilds the presentation with the ADS — semantic
content model → design components → full token theme → verified parity.
Proposal: `docs/project/proposals/114-forge-design-rebuild-proposal.md`
(+ `-research.md` — DTCG 2025.10, Style Dictionary theming, CI contrast
gates, visual-regression practice).

- **`lib/design.ts`** — the forge design-import module: `fetchDesignInput`
  (homepage + ≤6 same-origin stylesheets, bounded, SSRF-guarded by the
  caller), `analyzeDesignInput` (deterministic: Elementor/theme CSS-var
  roles with confidence + unassigned pool, type pairing + px scales, spacing,
  radii, shadows, component vocabulary, adaptation brief),
  `analyzeBuiltCss` (the verify-side extraction), `designToTokens` (role→
  `--forge-*` map; accent-ink by contrast, not threshold), `suggestContrastFixes`
  (one-token fixes from the source's own pool), `scoreParity` (role distance,
  family/scale/spacing overlap, order-independent concept signature — never
  pixel parity).
- **`lib/semantic.ts`** (vendored into generated projects) — the builder-
  structure classifier: Elementor widget sections + `wp-block-*`/`vc_*`
  markers → typed sections (hero/feature-grid/icon-list/testimonial/
  logo-cloud/cta/gallery/text/raw) with text/media slots. Only splits
  ALREADY-SANITIZED HTML; emits substrings + escaped text, never new markup.
  Elementor-widget-less documents stay on the post-body raw path.
- **Profile `design` block (v2)** — `types.ts` `ForgeDesign`; written by
  `forge analyze --design` (CLI) or the MCP `design_apply`; scaffold merges
  defaults ← design-derived ← explicit `tokens` (`mergeTokens`), writes
  `src/lib/design.generated.ts` (deterministic), and audits WCAG pairs via
  the shared `contrastAudit` (light + dark + high; `line-strong` is the
  3:1-audited border token, the ADS additive). `--strict-contrast` →
  manifest `designAudit: strict` → the build-time `/design-audit.json`
  endpoint THROWS on failures (contrast cannot creep back).
- **Template:** `src/components/design/` (DesignRenderer + Hero/FeatureGrid/
  IconList/Testimonial/LogoCloud/Cta/Gallery/TextBlock), wired into
  `[slug].astro` / `posts/[...parts].astro` / `index.astro` via the
  sanitized body — **the body string must be extracted in getStaticPaths**
  (props are serialized; the store's `rendered` object does not survive the
  trip; `entry.body` is never populated). Plain Gutenberg prose and
  non-builder content render identically to before (the e2e XSS/embed/rich-
  content gates pin this).
- **`forge verify <profile> --target <dir>`** — re-extracts the built CSS
  bundle and scores parity (default threshold 0.6). Parity is LANGUAGE parity
  (roles/scale/spacing), never pixels; three gates now cover what it cannot
  see:
  - `verify` (always) — **fact consumption** via `lib/fact-audit.ts`: every
    fact a band declares must leave its marker in that band's built HTML.
    This is what catches a component that never received the section theme
    (Found by the audit: `FeatureGrid` and `IconList` both silently dropped
    `titleStyle`/`titleDivider`/`titleAlign`.) `--strict-facts` makes it fail.
  - `analyze --geometry` + `verify --geometry` — **box geometry** via
    `lib/geometry.ts`: measures each band at 1440/834/390 into
    `profile.geometry`, then diffs the build against it (warn at tolerance,
    fail at 2×; `--geometry-limit` caps output). Box metrics survive font and
    anti-aliasing differences, so no pixel thresholds or Docker font pinning.
    A 903px card from a missing `height: auto` is invisible to CSS review and
    obvious here.
  - Extraction now reads `@media` bodies (`rulesInMedia`) for `margin-top`/
    `margin-bottom`: a band flush on desktop but pulled on tablet/mobile is
    captured as `{ desktop, tablet, mobile }`, not flattened. An inline margin
    outranks the media queries, so a device map is emitted as CSS only.
  MCP: `design_apply` (write, open-world policy like site_analyze) +
  `design_verify` (read). Wizard: `--design` + the `confirmDesign` clack
  hook (TTY-gated; non-TTY applies deterministically).
- **Gotchas (each cost a fix 2026-11-05):** htmlparser2's `handler.root` is
  the DOCUMENT — its `.children` are the top-level nodes; the analyzer must
  skip generic families (`ui-monospace` etc.) or the built bundle's
  `font-family:ui-monospace,…` code rule wins the pairing rank; scale/spacing
  px signals live in `--forge-scale-*`/`--forge-space-*` VAR VALUES, not in
  `font-size:` declarations — harvest both forms; `.ts` endpoint files have
  NO frontmatter fences (a leading `---` is a syntax error); `fetchDesignInput`
  is polite — a throttled source (jayainc served 14.8s responses after
  repeated analyzes) is a slow BUILD, not a bug (WpClient already times out
  at 15s).

## Proposal 114 §3.9 — form bridge P7 (shipped 2026-11-05)

Forms integrate like WooCommerce: **content at build time, behavior proxied
through WordPress.**

- **Render half (the forge):** `sanitize.ts` gains the form allowlist —
  `form`/`label`/`input`/`textarea`/`select`/`option`/`optgroup`/`button`/
  `fieldset`/`legend` + functional attributes only (`action`/`method`/
  `on*`/`formaction`/`style` are never allowed; `javascript:` `value`s die in
  transformTags) — `SANITIZE_VERSION = 2`. `semantic.ts` gains the `form`
  section kind + `FormInfo` (kind search|submit|cta, handler, formId,
  formName, postId, fields, submitLabel): forms are extracted FIRST, before
  the builder-section pipeline, from the top-level nodes; search forms = one
  search-ish input (`type=search` / name s|search|query|q / `role=search` /
  the profile's `forms.searchForms` names) or a hidden-ID carrier
  (`form_id` → elementor, `_jet_engine_booking_form_id` → jetformbuilder,
  `_wpcf7` → wpcf7; `post_id`/`__queried_post_id` → postId); no resolvable ID
  → `cta` (title/links band, dropped when empty — never a dead endpoint).
  `classifyHtml(html, options)` — the no-forms path is byte-identical to
  pre-P7 (all 297 unit tests pin it). `Form.astro` (pure Astro, NO islands):
  search → GET `/search/?q=`; submit → POST to `{NVOOS_FORGE_FORMS_ENDPOINT ||
  manifest.forms.endpoint}/forms/submit` with hidden `form`/`post_id?`/
  `honeypot`/`timestamp`/`_redirect` + delegated fetch enhancement (Astro
  hoists + dedupes identical bundled scripts); the no-JS floor is the
  ordinary form POST + `?form=ok|error` status script. `search.astro`
  prefills + auto-runs from `?q=`.
- **Config:** `types.ts` `ForgeForms` (endpoint/origin/searchForms) on
  `SiteProfile` + `ForgeManifest`; scaffold emits `manifest.forms` (endpoint
  defaults to `<profile.url>/wp-json/nvoos-forge/v1`) and `.env.example`
  (`NVOOS_FORGE_FORMS_ENDPOINT`, `NVOOS_FORGE_FORMS_ORIGIN`).
- **Behavior half (`plugins/nvoos-forge-bridge` 0.2.0):**
  `includes/rest/class-nvoos-forge-bridge-forms.php` — one public route
  `POST /nvoos-forge/v1/forms/submit` (permission `__return_true` — public
  by design; the gates are CSRF/abuse controls, NOT authorization — see the
  invariants below): disabled→404; origin allowlist (Origin header,
  Referer fallback, normalized via `Settings::normalize_origin`)→403;
  per-IP rate limit (transient, 5/10min)→429; honeypot non-empty or
  timestamp outside ±2h (JSON path; optional on the no-JS form-encoded
  floor)→silent 200 discard; optional Turnstile with `action`=
  `nvoos_forge_form` + hostname pinned to the allowlisted origin (fails
  closed on network errors); server-side `sanitize_textarea_field` on every
  field (count/length caps). Dispatch ladder: JFB (`jet_fb_handler()->
  process_form()` driven through the `_jet_engine_booking_form_id` +
  `_jet_engine_refer` `$_POST` carriers, `is_ajax = true` for the JSON
  response; form-id resolution via the `jet-form-builder` CPT) → Elementor
  Pro (`ElementorPro\Modules\Forms\Classes\Ajax_Handler::ajax_send_form()`
  with the admin-ajax contract emulated in-process: `action`/`form_id`/
  `post_id`/`queried_id`/`form_fields` + a fresh `wp_create_nonce(
  'elementor_pro_forms')`; form existence via a bounded `_elementor_data`
  LIKE) → typed 404 `nvoos_forge_form_not_found` / 501
  `nvoos_forge_no_form_handler`. Both native dispatches are wrapped in
  `capture_json()`: it filters `wp_doing_ajax` true (otherwise `wp_send_json`
  calls `die` directly and no die-handler filter runs), swaps
  `wp_die_ajax_handler`/`wp_die_json_handler` for a sentinel thrower, and
  buffers the echoed JSON (both `{status,…}` JFB and `{success,data}` shapes
  map into the envelope `{ok,message,errors?}`). Version-drift seams: `nvoos_forge_bridge_forms_
  jfb_available|elementor_available` (bool filters) + `…_jfb_dispatch|
  elementor_dispatch` (return an envelope array or WP_Error to pin a
  specific plugin release; null = native). Audit seam: the
  `nvoos_forge_bridge_form_submission` action (accepted|rejected). No-JS
  floor: form-encoded bodies redirect (302) to the allowlisted `_redirect`
  with `?form=ok|error` (open-redirect guarded). Settings: `OPTION_FORMS_*`
  (enabled, origins textarea, captcha provider/site key/secret) + UI
  section + `option_keys()` + redacted health fields.
- **Security invariants (0.3.1, do not regress):** only `post_status = publish`
  + empty `post_password` forms resolve (JFB by ID and title; Elementor
  `_elementor_data` lookup joins `wp_posts`); non-public forms return the same
  404 as missing ones (no draft enumeration). The rate-limit IP is
  `REMOTE_ADDR` unless the direct peer is in the
  `nvoos_forge_bridge_trusted_proxies` filter, then the rightmost untrusted
  hop. The route is public by design, so the origin allowlist is a CSRF gate,
  never authorization. A submitted field can never re-target the dispatch:
  JFB's routing carriers (`_jet_engine_booking_form_id` / `_jet_engine_refer`)
  are stripped from the fields and re-asserted last.
- **Tests:** forge — sanitizer form allowlist + XSS vectors, semantic form
  classification (search/submit/cta/JFB/CF7/postId/options), scaffold forms
  emission, e2e fixture forms (xss-trap search+Elementor form, contact JFB
  form) asserting the rebuilt `action`es + honeypot + no carrier/vector
  leaks. Bridge — `tests/test-forms.php` zero-HTTP: origin/rate/spam gates,
  404/501/400/429 contracts, both dispatch seams + sanitization capture,
  captcha via intercepted `pre_http_request`, no-JS 302 + open-redirect
  guard.
- **Gotchas (each cost a fix 2026-11-05, P7):** the generated project's
  frontmatter context cannot resolve DOM type NAMES (`HTMLInputTypeAttribute`
  → ts(2304)) — cast input `type` to a literal union instead; `is:inline`
  scripts are PLAIN JS — TS assertions (`as unknown as`) fail the check
  (ts(8016)); a type error in one template file makes `astro check` emit
  cascading ts(7027) "unreachable code" noise on unrelated inline scripts —
  fix the root error, not the warnings; `nvoos.json` has no `forms` key on
  form-less profiles — read it through an explicit cast shape; `--overwrite`
  re-scaffold wipes `node_modules` (reinstall); `classifyHtml` must split
  forms from the TOP-LEVEL nodes in document order or non-form content
  re-renders forms twice; the JFB `form_id` carrier is a hidden INPUT NAME
  (`_jet_engine_booking_form_id`), Elementor's is `form_id` + `post_id`
  hidden inputs — no `data-*` surface needed; bridge PHPUnit needs the
  Docker `oos-wp` env (wp-tests-config targets the compose network) — phpcs
  + `php -l` run anywhere, PHPUnit does not.

## Shared-mirror deploy (branch per site, 2026-11-06)

Owner decision: forged client sites deploy through ONE private mirror repo
(`nvdigitalsolutions/nvoos-forge-sites`) — one `site/<name>` branch per
client, built `dist/` at the branch ROOT (force-pushed, flat; `main` holds
only the README clone anchor). Velocity apps connect the mirror and select
Branch = `site/<name>` (Root directory = repo root — Velocity supports
per-app Branch + Root Directory per the official deployment docs). Never
commit generated sites into the monorepo: `sites/<name>.json` profiles are
the only committed artifact per site.

- **Forge:** `deploy`/`deploy-config` gain `--mirror-repo <owner/repo>` +
  `--site-branch <branch>` (paired, validated). Mirror mode emits
  `scripts/mirror-push.mjs` next to the workflows (single template in
  `lib/deploy-config.ts` `mirrorPushScript()` — orphan-branch checkout +
  force push; the workflow and the CLI run the SAME emitted file, no
  drift). `deploy --mirror-repo` skips repo creation and mirrors a prebuilt
  `dist/` (operator staging path; needs `GITHUB_TOKEN`). Legacy velocity
  shape (per-site repo, `deploy` branch under `site/`) is unchanged.
- **Monorepo pipeline:** `.github/workflows/forge-sites.yml` — push on
  `sites/**`/templates/libs, `workflow_dispatch` (`site` input), and
  `repository_dispatch` `nvoos-forge-content-changed` (bridge-fired; HMAC
  verified by the committed `.github/forge/verify-dispatch.mjs`, generated
  by `emitDeployConfig`). Per site: scaffold → deploy-config → build →
  mirror-push. Unset `NVOOS_FORGE_MIRROR_*` secrets degrade to build-only
  (never a red job). Needs repo variable `NVOOS_FORGE_MIRROR_REPO` +
  secrets `NVOOS_FORGE_MIRROR_URL`/`_TOKEN`, `FORGE_WEBHOOK_SECRET`.
- Full runbook + research: `docs/project/proposals/114-shared-mirror-deploy-addendum.md`.
- **Adding a client site:** seed the branch on the mirror —
  `gh api repos/nvdigitalsolutions/nvoos-forge-sites/git/refs -f ref=refs/heads/site/<name> -f sha=<main sha>`
  (read the sha via `gh api …/git/refs/heads/main --jq .object.sha`) — commit
  `sites/<name>.json`, merge → the workflow mirrors on the next run. The
  mirror's `main` README lists the convention (update it via the
  `contents` API PUT — base64 content + the current file `sha`).
- **Operational learnings (2026-11-06, first live mirror push):** the
  Windows self-hosted runner's `npm install` phase runs ~15 min — a quiet
  job is NOT a hang, check `gh run view <id> --log` before touching code.
  The WCAG scaffold warnings fix in the PROFILE, not the template: the
  TOP-LEVEL `tokens` keys are the `--forge-*` names minus the prefix (`ink`,
  `muted`, `line-strong`, …) — a `design.tokens` block does NOT exist and is
  silently ignored (the merge is `defaults ← designToTokens(design) ←
  profile.tokens`). Pick values from the source's own unassigned pool that
  PASS — the audit's one-size suggestion (same dark color for all three)
  flattens hierarchy, `#505050` muted and `#666666` line-strong both clear
  4.5:1/3:1 and keep it. GitHub intermittently rejects the orphan force
  push with `remote: fatal error in commit_refs` — the emitted script
  retries 3× with a 5s `Atomics.wait` sleep (cross-platform). Mirror CI
  secrets on the monorepo:
  `NVOOS_FORGE_MIRROR_URL` + `NVOOS_FORGE_MIRROR_TOKEN` + variable
  `NVOOS_FORGE_MIRROR_REPO` — set via `gh auth token | gh secret set …` so
  the token never lands in a transcript. A prebuilt local `dist/` stages
  instantly: run the generated `scripts/mirror-push.mjs` with `MIRROR_URL`/
  `MIRROR_TOKEN`/`SITE_BRANCH` env.
- **Local preview of a built site** (a `dist/` exists): the Zed terminal
  reaps backgrounded children, so detach the server —
  `powershell.exe -NoProfile -Command "Start-Process -FilePath 'node' -ArgumentList 'node_modules/astro/bin/astro.mjs','preview','--port','4199' -WorkingDirectory '<abs target>' -WindowStyle Hidden"`.
  astro's bin is `bin/astro.mjs` (NOT `astro.js`); `astro preview` binds
  `localhost` (::1) only — probe `curl http://localhost:<port>/`, not
  `127.0.0.1`; stop via the PID from `netstat -ano | grep <port> | grep LISTEN`.
- **Velocity app — build-centric, no static preset (2026-11-06):** Velocity
  ALWAYS runs `npm install` + a build command; there is no pure static
  framework option. With `Astro-ssr` selected, a mirror branch (prebuilt
  flat files, no package.json) fails `ENOENT package.json` (rc=254). The
  working pattern: the mirror-push emission ships a **no-op package.json
  stub** (`nvoos-forge-site`, build = `node -e "…"` — quoted: `sh -c` rejects a bare `(`) beside the
  built files, and the Velocity Deployment Settings set the **Output
  Directory to the repo root (`.`)** with the default build command
  (`npm run build` — the no-op). The Cloudways MCP exposes no
  deployment-settings tool (the apps toolset's web-rules tools are
  header/redirect rules only) — these are Velocity-console UI settings.
  The mirror content itself is not the problem: verify with
  `gh api repos/nvdigitalsolutions/nvoos-forge-sites/contents/?ref=site/<name>`
  — `index.html` at root means the push is good. A hand-added stub does NOT
  survive the next mirror force-push (the push replaces branch content) —
  the stub must come from the emission, so merge the emission fix BEFORE
  the next forge-sites run.

## Proposal 135 — two-tier deploy + domain topology (2026-11-09)

Full notes: `reference/proposal-135-managed-tier.md`.

- **WordPress is never the public origin.** A profile's `url` is the CMS host
  (`https://cms.client.com`); the public site is `ASTRO_SITE`
  (`https://client.com`). Set `WP_HOME`/`WP_SITEURL` to the CMS host.
- **`deploy.runtime`** — `static` (default) or `managed`; flows to
  `deploy-config` → `mirror-push` and a per-branch `deploy.json`. Managed
  **fails closed** without `server.js`, and implies SSR.
- Managed ships **endpoint proxy routes**, not `src/fetch.ts` (a custom fetch
  handler makes Astro skip sessions/actions/middleware/cache).
- `Astro.cache.set` only behind the generated `MANAGED` flag; bridge tags are
  `collection` + `collection:<id>`; `nvoos-forge-bridge` posts them to
  `/api/revalidate`. Preview branches (`preview: true`) + `NVOOS_FORGE_SMOKE`.
- **Phase 5 shipped:** `nvoos_forge_local` CPT + `/nvoos-commerce/v1/local`,
  forge `source: 'local'` (slug-authoritative, `restBase` optional, `optional`
  degrades), `/api/preview` (token → host-only cookie) and `/preview/<slug>/`
  (SSR, `noindex`, `no-store`, managed only). Preview tokens are
  **bridge-verified, frontend-transported** — never put the key on the frontend.
- **`/manage` is not bundled into the template** (the bridge is proprietary and
  the template is a separate distribution). Editors use the CMS page.
- Scaffold hazards fixed: the drift manifest must be hashed AFTER the managed
  rewrite (`writeGeneratedManifest`, step 3g); an omitted `frontPage` vanishes
  from `nvoos.json` and breaks `astro check` (emit `''`); an emitted `.astro`
  file must open with `---`. Remaining: live collections (2c).


## Proposal 115 — inventory, wireframes, responsive (2026-11-06)

The forge rebuilds the source site COMPLETELY: a full page inventory
(sitemap index + homepage links + optional Wayback CDX), site-shape
recognition (one-pager vs multi-page vs hybrid + nav-anchor
reconciliation), per-page wireframes for pages the REST collections do
not cover (captured from rendered HTML, or content-first placeholders for
dead URLs), and responsive recognition (css-tree breakpoints +
per-breakpoint grid columns).

- **CLI:** `forge analyze <url> --inventory [--wayback]` and
  `--wireframes` (implies inventory; attaches `profile.inventory` +
  `profile.wireframes`; the `--design` pass feeds its stylesheets to
  breakpoint extraction).
- **Scaffold:** `profile.wireframes` → `src/lib/wireframes.generated.ts` +
  one static `src/pages/<slug>.astro` per wireframe (reserved slugs +
  frontPage skipped with a warning; gated on the template's
  DesignRenderer). `ScaffoldResult.wireframePages`.
- **Adopted deps (forge-package only — never vendored):** `sitemap` v9
  (`parseSitemapIndex`/`parseSitemap` take a **Readable stream** — a
  string throws `xml.on is not a function`; index vs urlset are separate
  parsers), `css-tree` (+ `@types/css-tree`; MediaQueryList preludes
  serialize via `generate()`, NOT `.value`). Build-vs-buy + research:
  `docs/project/proposals/115-forge-inventory-wireframes-responsive-proposal.md`
  (WP2Static/Simply Static = crawl methodology only, their HTML is
  unmaintainable; Crawlee/readability/Trafilatura/wget rejected;
  Wayback CDX = the deleted-page source).
- **Gotchas (each cost a fix 2026-11-06):** `forge.mjs` is plain JS — a TS
  annotation in the analyze flow broke the CLI under node (the e2e caught
  it; unit/esbuild did not). `normalizePath('/')` must early-return before
  the trailing-slash append or it yields `//`. Mixed min/max media queries
  pollute width sets — `max-width` wins, `min-width` is the fallback.
  `.ds-grid` now renders `repeat(var(--ds-cols-*, N), minmax(0, 1fr))` at
  the canonical 1025/767 breakpoints — wireframe columns ride CSS custom
  properties on the grid container (DesignRenderer passes `columns` to
  FeatureGrid/IconList via the `wireframeSections` prop).

## Proposal 116 — learned vocabulary classifier (2026-11-06)

The classifier never enumerates widgets again: unknown `elementor-widget-*`
/ `wp-block-*` names classify as **`content`** (low confidence — slots
merge, the shape ladder decides the kind) instead of being dropped;
`classifyHtml` options gain `widgetRoles`/`blockRoles` overrides that win
over the exported seed dictionaries (`SEEDED_WIDGET_ROLES` /
`SEEDED_BLOCK_ROLES`); `forge analyze --vocabulary` learns per-site — the
`wp/v2/block-types` registry via keyword rules + every widget name on the
rendered homepage — and reports `unknownWidgets`/`unknownBlocks` for the
operator to pin in `profile.vocabulary` (scaffold bakes
`vocabulary.generated.ts`; the renderer merges it). Structured Gutenberg
docs (columns/group re-seeded `container`) now enter the section pipeline
via the container/content gate; a safety ladder pins **the pipeline never
empties a non-empty document** (embed-only posts stay raw text; empty
Elementor scaffolding still drops). Elementor 4.0 atomic output is real
semantic HTML — form extraction + the shape ladder cover it name-free; the
atomic JSON data layer is the documented enrichment path. Research:
`docs/project/proposals/116-forge-learned-vocabulary-proposal.md`.
- **Build-time proxy seam:** hosts with bot protection block datacenter
  egress (jayainc 403s GitHub runners — IP-based, UA alone won't fix it).
  `WP_FETCH_PROXY=http://user:pass@host:port` in the generated project's
  env routes ALL build fetches (content layer + remote image service)
  through an allowlisted proxy via `fetch-proxy.ts` (undici ProxyAgent,
  global dispatcher); unset = no-op, misconfigured = fail-closed build.
  Operator infra: a small allowlisted proxy (or the Cloudways bot-protection
  allowlist when the site is on NV's account).
- **Browser-UA hardening (same session):** `WpClient` sends a
  Chrome-desktop UA by default (`DEFAULT_USER_AGENT`), overridable per
  deploy via `WP_USER_AGENT` (the scaffolded content.config wires it) —
  UA-less requests are a bot-protection trigger on many hosts.
- **Clearing a WAF block is not allowlisting the IP (2026-11-10):** MalCare
  answered the content sync with 403 from both the CI runner and a dev
  machine. The block is only identifiable from the response **body**
  (`Blocked because of Malicious Activities`, reference id) — the status
  alone reads like bad credentials. One unblock let a build through, then
  the NEXT run 403'd again: it needs a permanent IP whitelist.

## Proposal 117 — classifier fidelity + section theming (2026-11-06)

jayainc's live rebuild taught two gaps: structured single-column widgets
flattened, and the flat theme ignored the source's band rhythm.

- **Structured icon-text items (semantic.ts):** `extractStructuredItem`
  detects the aux_text / icon-box family pattern — an `aux-ico-box` header
  (icon span or image) + an h3-h6 + body paragraphs — and turns each widget
  into ONE item (title/body/media/icon) instead of flat media + orphans.
  Product cards become a captioned `feature-grid`; icon features become an
  `icon-list` (items.every(icon)). Quote widgets carry the author line
  (`aux-author`/cite/byline → `item.author`). **Gotchas:** the header-box
  class regex must be `/ico-box|icon-box-icon|icon-box-wrapper/i` — a loose
  `/icon/` also matches the widget's OWN `elementor-widget-icon-box` class
  and empties every item body; testimonial in the kinds ladder needs
  `!items.some(title)` or titled structured items misclassify as quotes.
- **Section ids (the theming key):** every classified section carries its
  source `elementor-element-<id>` (`SemanticSection.id`).
- **`analyzeSections` (design.ts):** walks the RAW homepage for
  elementor-top-section elements and pulls per-id background facts from the
  same-origin CSS (gradient/photo/color/overlay/padding). **Gotchas:** a
  PHOTO beats a gradient for the same id (Elementor layers decoration
  gradients on child/overlay selectors — jayainc's hero photo was being
  cleared); an `elementor-background-overlay` rule's color must NEVER feed
  `bgColor`; `resolveSectionTheme` maps bgImage→hero, dark rgb/hex→dark,
  near-white→light, gray→alt — normalize rgb() to hex before the luminance
  math (navy gradients end in `rgb(4,19,43)`).
- **Baked theming:** scaffold writes `src/lib/sections.generated.ts`
  (template ships an empty stub); `DesignRenderer` looks sections up by id
  and wraps each in `.ds-section-wrap--{hero|dark|alt|light}` with the
  source bg inline (hero = photo + tint, overlay via `--ds-overlay`).
  global.css adapts foregrounds (dark bands → white headings, white cards).
  Hero renders ALL media images (`.ds-media-grid`). Regenerate a profile
  with `forge analyze <url> --design --write <tmp>` and merge the fresh
  `design` (sections included) + `vocabulary` blocks into the committed
  profile — `analyze --write` drafts a NEW profile, never merge over the
  hand-tuned collections/tokens/frontPage.

- **`analyze --write` merges by default (118 §3):** a fresh analysis draft
  MERGES into an existing profile — hand-tuned collections/tokens/frontPage/
  forms survive, the analyzed blocks (design/vocabulary/inventory/wireframes)
  refresh. `--fresh` forces a clean draft.
- **`forge verify --visual [--serve <port>] [--wait <s>] [--strict-visual
  [--min-fidelity 70]]` (118 §4):** after the parity gate, compare SOURCE vs
  BUILT screenshots with a vision model. Images travel as base64 DATA URLs
  in the request body — NEVER hosted URLs (mShots 403 / gist-binary / the
  hallucinated-placeholder failure chain, 2026-11-06). Env:
  `NVOOS_FORGE_VISION_BASE_URL`/`_KEY`/`_MODEL` (any OpenAI-compatible
  endpoint incl. local Ollama). Screenshots: `NVOOS_FORGE_CAPTURE_URL`
  (source only — remote endpoints can't reach the local build) or dropped
  `<target>/verify-artifacts/<label>-{source,built}.{png,jpg,webp}`.
  Fail-soft everywhere; vision never runs inside the build (determinism).
  Report: `verify-artifacts/visual-audit.json`.
- **119 determinations (2026-11-06):** the MCP surface covers the vision
  layer — `design_verify_visual` (read-only; capture endpoint or dropped
  artifacts; fail-closed errors unconfigured) and `design_apply` now
  reports the 117 section pass in its payload. Practicality: headless
  WP+Astro is the 2026 mainstream; rebuild-not-replicate is the legal
  line; vision stays ADVISORY (models miss small changes and sound sure —
  keep the deterministic gates). Base+pro: MESH to the forge MCP instead of
  porting; the only cheap PHP port is the section-background pass + the
  WCAG gate. Research: `119-forge-practicality-mcp-and-plugin-assessment.md`.

## Proposal 120 — chrome + banded scales (2026-11-06)

Vision-verified jayainc iteration (fidelity 60 → 85, parity 84%,
spacing overlap 40% → 71%) taught three rebuild gaps, all fixed in
`lib/design.ts` + the template:

- **Site chrome (`analyzeChrome`, 120 §2):** header/footer come from theme
  templates that never reach the REST content — the rebuilt site had NO
  chrome. `analyzeChrome` extracts chrome FACTS (topbar lines, logo,
  tagline, nav, tel: call, footer blocks, copyright) from the raw rendered
  homepage; `Base.astro` rebuilds them with its own components
  (`chrome.generated.ts` bakes at scaffold; absent chrome keeps the
  default header). **Site-node ranking** (`findSiteNode`): prefer the
  site-pattern id/class (`site-header|masthead|master-header` /
  `site-footer|sitefooter|colophon`), else the LAST match — a post's
  `<footer class="entry-meta">` precedes the real `<footer id="sitefooter">`
  on jayainc and must not win; same for stray `<header class="entry-header">`.
  **Header hint must exclude topbar-ish nodes** (`!/top-header|topbar|top-bar/i`)
  — `#top-header` matched the loose header regex and emptied logo/tagline/nav.
  **Footer columns live in a widget ASIDE** (`<aside class="subfooter">`)
  beside the footer element — merge both scopes for the blocks
  (position-scoped between headings, `startIndex`-ordered); the copyright
  stays strict to the footer element. Section anchors (`attribs.id` on the
  Elementor section → `ForgeSection.anchor`) re-apply as `id="…"` on each
  `ds-section-wrap` so the chrome nav resolves.
- **Banded type scale (120 §3):** dense builder harvests (jayainc: 52
  sizes) break the bottom/top 10-step trim — it kept
  `[8,9.6,10,11,64,72,80,82,96,128]` and ranked **body = 64px, small = 8px**.
  `trimScaleLadder` keeps a representative PER BAND (small ≤12, body 13-20,
  card 24-34, section 36-60, hero 60-100 + the largest display value), and
  `designToTokens` reads the same bands (body = FIRST in band — the source's
  actual body; headings = band centers; small = center of [8,12] → 10px,
  never the micro 8px). Rebuilt jayainc: body 13px, cards 29px, titles
  44-48px, hero 66-80px vs source ground truth 13/28/43/72. `body` now
  wires `font-size: var(--forge-scale-body)` — the token existed but
  nothing applied it (browsers default 16px).
- **Banded spacing (120 §4):** same disease — a 46-value margin/padding
  harvest baked `space-4: 104px`. `trimSpaceLadder` + band-mapped
  `space-1..5` (xs 2-6, sm 8-12, md 14-20, lg 24-40, xl 48-96 centers,
  hairline + largest kept) bake a real rhythm (jayainc: 4/10/17/30/70).
- **Full-bleed + layering + accent (120 §5, vision round 2):** the band
  backgrounds were trapped inside `--forge-measure` — the four band wraps
  (`hero/dark/alt/light`) escape the measure with a full-bleed centering
  (SUPERSEDED — see 122: the `calc(50% - 50vw)` form sat 7px left because
  100vw includes the scrollbar; the shipped form is `width: 100vw;
  margin-left: 50%; transform: translateX(-50%)` with motion on
  independent `translate`/`scale` properties), the inner
  `.ds-section` re-measures, `body` gets `overflow-x: clip` for the
  scrollbar sliver, bands are contiguous (the `.design-sections` grid gap
  is gone — seams the source doesn't have), each `.ds-section` carries its
  own vertical rhythm, and the hero gains `min-height: clamp(22rem, 75vh,
  48rem)` + flex centering (source: 724px). **Hero layering:** a
  `background-color` alongside a photo is the source's UNDERLAY (invisible
  beneath an opaque JPG) — `analyzeSections` drops it and `wrapStyle` never
  paints it over the photo; the `::before` overlay fallback softens to
  `rgba(0,0,0,0.3)` (a 0.9 navy default over-darkened the rebuild — hero
  pixels were rgb(33,47,82) vs the source's rgb(102,97,99); after the fix
  rgb(97,90,89)). **Accent recovery:** when the var role is an untouched
  builder default primary (#6ec1e4/#61ce70/#4054b2), `dominantCtaColor`
  scans button-ish selectors (`button|.btn|cta|aux-call|aux-ac|pricing`)
  for the most frequent saturated background — jayainc's real accent is
  #1bb0ce (headings/links/icons), not the stock Elementor blue.
  **Call-button colors:** `analyzeChrome` resolves the tel: link's classes
  through the CSS (flat bg + raw gradient — the gradient is the effective
  fill, it round-trips verbatim — + anchored `color:` declarations) into
  `call.bg`/`call.ink`; `Base.astro` inlines them (jayainc's CALL US = the
  exact yellow gradient + white ink). The ink regex must be
  `/(?:^|;)\s*color\s*:/` (unanchored it matches `background-color`) and
  `normalizeColor` needs the common CSS named colors (white/black/gold…).
- **Vision methodology:** full-page captures downscale to ~500px wide —
  text is illegible and the model hallucinates content complaints. Capture
  the TOP VIEWPORT at native resolution (1440×900) instead: header + hero
  carry the highest signal and the model's findings become accurate
  ("maintains the layout and content order…"). The model's 1-100 score
  plateaus around 85 for structurally-rebuilt sites regardless of the
  remaining deltas — treat it as advisory and verify claims with sharp
  pixel sampling + computed styles (the model asserted the rebuilt hero
  was LIGHTER when pixels proved it DARKER).

## Proposal 121 — scroll-behavior pass (2026-11-06)

`docs/project/proposals/121-forge-scroll-behavior-proposal.md`. The rebuild
was static while the source moves. `analyzeScrollBehavior` (design.ts) walks
**EVERY element** of the rendered homepage (the per-div loop), classifies
sticky / reveal / parallax, and the template rebuilds the motion with a
**CSS-native `@supports` runtime** — no JS, no new dependency:

- **Signals:** `aux-appear-watch-animation` + `aux-{effect}` classes (the
  Phlox system; effect name-mapped to fade-up/down/left/right, scale-up,
  blur, unmapped → fade); `aux-parallax-section`; `aux-logo-sticky` /
  `aux-top-sticky` / header-scoped `elementor-sticky` → the `headerSticky`
  flag; Elementor Motion Effects via the `data-settings` JSON
  (`motion_fx_motion_fx_scrolling: yes` + `motion_fx_*_effect: yes` →
  parallax with `motion_fx_translateY_speed.size`; non-scrolling motion →
  reveal); `elementor-invisible` → reveal; CSS facts
  (`position: sticky|fixed`, `background-attachment: fixed`) resolved
  against the element's own id rule.
- **Roll-up:** behaviors attach to the containing `elementor-top-section`
  id (a parallax COLUMN inside the hero makes the rebuilt hero section
  parallax) — the renderer only wraps top-level sections. A section-stack
  during the walk tracks the current top-section id.
- **Emission:** `profile.design.behavior` → `behaviors.generated.ts`;
  `DesignRenderer` adds `ds-reveal ds-reveal--{effect}` / `ds-parallax`
  (with `--ds-parallax-range` scaled by speed) / `ds-sticky`;
  `Base.astro` toggles `.site-header--sticky`. global.css: view() timelines
  for reveals, scroll(root) for parallax, all gated on `@supports` +
  `prefers-reduced-motion`, the finished state as the default (the golden
  rule). Verified live on jayainc: sticky header + 5 parallax bands + 2
  reveal bands, zero JS.
- **Gotchas (each cost a fix 2026-11-06):** the motion_fx key regex must be
  case-insensitive (`motion_fx_translateY_effect` has a capital Y);
  `renderBehaviorsGenerated` must emit the `entries: [` opener (the first
  cut emitted `],` with no opener — the e2e's astro check caught it with
  `ts(2695)`); the `data-settings` JSON is entity-encoded (`&quot;`) —
  decode before `JSON.parse`.
- **The CSS minifier can silently kill every scroll animation (cost the
  jayainc parallax 2026-11-06):** Chrome (as of 157) drops the ENTIRE
  `animation` declaration when a timeline value (`scroll()`/`view()`)
  appears inside the SHORTHAND — `animation-timeline` must stay a separate
  longhand. Vite 8 defaults to **lightningcss** when present (astro pulls
  it transitively) and lightningcss 1.33 merges the longhand back into the
  the deployed jayainc build lost all 5 parallax bands + both
  reveal bands to exactly this. The template's `astro.config.mjs` pins
  `vite.build.cssMinify: 'esbuild'` (esbuild preserves the longhand) —
  and the template devDeps must carry the `esbuild` package (Vite imports
  it at build time for that path; missing it fails the build); the
  e2e gate asserts `animation-timeline:scroll(root)` + `:view()` survive
  the built bundle and no timeline lands inside a shorthand. Never switch
  the minifier (or "optimize" those rules into one shorthand) without
  re-checking that gate.
- **Gotchas (each cost a fix 2026-11-06):** a bare `theme = themeFor(...)`
  assignment inside the DesignRenderer map is an implicit-global in strict
  ESM → `ReferenceError: theme is not defined` at PRERENDER (use `const`);
  `trimLadder` (bottom/top ends) survives for radii only — fonts/spacing
  are band-aware now; the vision layer 400s on oversized screenshots
  (qwen2.5vl rejected a 1440×6383 full-page capture) — resize dropped
  artifacts to a ≤2048 long edge first (sharp is in the forge devDeps via
  astro); a detached `astro preview` holds the target dir — re-scaffold
  `--overwrite` fails EBUSY until the preview PID is killed (`netstat -ano |
  grep <port>`).

## Proposal 122 — fidelity round (2026-11-06)

Four jayainc drift reports fixed in one round (PR #7486): section widths,
button shape, the missing services image, and the footer. All verified
on-screen with computed styles + pixel sampling before merge.

- **Measure is site, not prose (the width root cause):** the template's
  `--forge-measure: 72ch` (~470px at the rebuilt 13px body) squeezed EVERY
  band into a prose column — the rebuild looked like a blog, not the
  source. `measure` now defaults `75rem` in `DEFAULT_TOKENS` (scaffold.ts)
  and the template. NEVER reintroduce a ch-based measure; site bands are
  rem-bounded, not line-length bounded.
- **Full-bleed centering + motion composition:** `width: 100vw` +
  single-sided `margin-left` sits 7px left (100vw includes the scrollbar).
  Ship `margin-left: 50%; transform: translateX(-50%)` (self-referential,
  no calc) and move the reveal/parallax keyframes off `transform` onto the
  independent `translate`/`scale` properties — a keyframe `transform`
  REPLACES the centering transform (reveals jumped +40px, parallax bands
  shifted). `body { overflow-x: clip }` stays for the scrollbar sliver.
- **Section padding facts (band rhythm):** `sections.generated.ts` now
  emits the source's declared `padding` per band (jayainc: flush bands AND
  100px/125px bands), applied inline via `wrapStyle()`; template
  `.ds-section` default tightened to space-3/space-4. Same-origin CSS per
  section id is the fact source, as in 117.
- **CTA shape harvest (`dominantCtaFillFromHtml`):** new `cta` palette
  role + `design.ctaStyle` (padding/radius). Walks actual `<a>`/`<button>`
  elements (button-ish class filter `button|btn|cta|aux-ac|aux-call|
  pricing`), resolves each property through a specificity-APPROXIMATED
  cascade — per rule, `matchCount * 1000000 + ruleIndex` (the rule
  matching the MOST of the element's classes wins; ties by source order).
  The old frequency vote let base rules (`padding: 12px 23px`, `radius:
  5px`) out-vote the real pill rules. **Gradient fill ALWAYS beats flat
  color** — a flat hover/outline color (`#ebde68` on jayainc) otherwise
  wins over the gradient `background-image` that is the effective fill
  (this regressed once and was caught). Emits `--forge-cta`,
  `--forge-cta-ink`, `--forge-cta-pad`, `--forge-cta-radius`. jayainc
  pinned via `profile.tokens`: `cta-pad: "16px 50px"`, `cta-radius:
  "999px"` (the majority size — the source's CALL US is `14px 43px` at
  1600px, the rest `16px 50px`); yellow gradient `#ffcf0e → #ffb70e`,
  dark ink `#111111` for WCAG (source uses white ink on the yellow).
- **Icon-list split media:** `IconList.astro` renders `slots.media` in a
  `.ds-icon-split` two-column layout — the "Why Choose Us" portrait photo
  (`jaya-2-fix-784x1236.jpg`) returned after being silently dropped.
- **Footer logo/bg + favicon:** `analyzeChrome` captures `footerLogo`
  (img inside the footer/aside scope — jayainc's white PNG), `footerBg`
  (the `.subfooter` terminal rule — jayainc `#f3f3f3`), and `favicon`
  (first `<link rel="icon">`, absolute — `cropped-jaya-inc-icon-32x32`);
  `Base.astro` wires all three.
- **Operator pins survive `analyze --write`:** the tokens merge preserves
  hand-tuned `cta-pad`/`cta-radius`/`measure` keys. Facts INSIDE the analyzed
  `design` block are replaced wholesale, so declare those at the profile's top
  level in `pins` — `{ "path": "sections.5e78df1.marginTop", "value": "170px" }`
  or `{ "path": "chrome.footerInk", "value": "…" }`; `mergeProfileDraft`
  re-applies them (`lib/pins.ts`) and `tests/pins.test.ts` fails when the
  committed profile stops satisfying one. `--fresh` still drops them (it warns).

## Proposal 123 — chrome typography pass (2026-11-06)

Header/footer fidelity round: the chrome was rebuild-blind to the source's
nav/call/footer type facts and its strip colors. All facts verified with
headless-chromium computed styles against the live source.

- **The shared cascade (`resolveElementRules`):** every chrome fact resolves
  through the CSS with an own-match-first ladder — `!important` (per
  DECLARATION, not per rule) > own-token match > context match, spec =
  `own * 1e6 + context * 1e8 + ruleIndex`. A rule matching NONE of the
  element's own tokens can only INHERIT — it must lose (the header's
  `.aux-header-light { color: #fff }` must not paint the call button; its
  real ink comes from its own classes). Pseudo-states AND state CLASSES
  skip (`:hover` + `aux-hover` — jayainc's nav had an `!important`
  `.aux-hover` rule that out-cascaded the resting white). `terminalFill`
  gates the element-level FILL to rules whose selector ENDS on an own
  token (the call button's gradient must never leak onto the header).
  `bgForCss` keeps rgba()/hsla() alpha raw — `rgba(8,26,67,0.9)` and
  `rgba(255,255,255,0.7)` ARE the facts, hex-flattening loses the look.
- **The fetch must see the whole sheet set:** MAX_STYLESHEETS 6 silently
  dropped jayainc's CUSTOMIZER file (`uploads/phlox-pro/custom.css` — the
  file with every real body/header/footer override). Raised to 40 with a
  3MB total cap that breaks BETWEEN files; the per-file cap went 512KB →
  2MB — a 512KB cut landed mid-`@media` in the 886KB main.css and the
  dangling brace swallowed EVERY stylesheet after it. Inline `<style>`
  blocks and `<link>` sheets now interleave in DOCUMENT order (WP prints
  the inline blocks after the links — the inline 16px nav rule must
  cascade AFTER the linked 0.875em rule).
- **`topLevelRules` (the splitter):** the flat RULE_RE desyncs on `@media`
  blocks and on braces/quotes inside `url(data:…)` payloads — one svg data
  URI ate every later `body { font-family }` rule. The splitter is a
  char scanner: comments skipped, quoted strings inert-but-accumulated,
  quotes only open strings OUTSIDE `url(...)` parens, braces inside parens
  are value bytes. Media bodies stay inert blobs (no element tokens match
  a media selector).
- **Typography harvest:** the body font comes from the LAST `body`-selector
  rule (frequency ranking miscounts Elementor's unused token-layer
  families — jayainc's body rendered Rubik while the tokens said Roboto);
  the DISPLAY font from the `h1..h6` rule; `bodyWeight` token (300) from
  the body rule; `font` emits even when body === display (a single-family
  source renders BOTH roles in it). @font-face blocks are stripped before
  family ranking (load evidence, not usage).
- **New chrome facts (all in `design.chrome`):** `topbarBg/Ink`,
  `headerBg/Ink`, `navStyle` (weight/size/transform/letterSpacing),
  `navInk`, `call.style` (padding/radius/weight/size/transform — jayainc:
  `14px 43px`, `100em` pill, 400, 16px, uppercase), `footerBarBg/Ink` (the
  dark copyright strip — the bar INK resolves from the COPYRIGHT node, not
  the footer element), `footerHeadingStyle` (500/29px/capitalize),
  `chromeSize` (16px — the body rule's `100%` resolves against the html
  ROOT rule, never the rebuilt 13px content body).
- **Template wiring:** the facts ride as CSS vars on `<body>`
  (`--forge-chrome-size`, `--forge-header-bg`, `--forge-nav-transform`,
  `--forge-call-pad` → falls back to `--forge-cta-pad` → template
  default, …); `.site-header--themed`/`.site-footer--themed` classes drop
  the default hairline when a harvested bg exists; the footer splits into
  two full-width strips (light subfooter + dark bar — the source's rhythm,
  the inner containers re-measure); **`.site-header--sticky` must chain
  `var(--forge-header-bg, var(--forge-surface))`** — the sticky variant
  painted surface over the navy (caught in headless verification).
- **Verification:** headless chromium + a tiny in-process static server
  (the MCP browser backends cannot reach this machine's localhost) — dump
  computed styles per chrome element and diff against the live source.
  Re-scaffold (not manual CSS copies) after template edits — the
  `FORGE:tokens` rewrite only runs in the scaffold pipeline.

## Proposal 125 — split hero, webfonts, catalog (2026-11-06)

The live jayainc rebuild taught four rebuild gaps; all fixed in the
analyzer + template + profile:

- **Split hero + collage facts (`layout: 'split'`):** the source's
  two-column hero bands (a copy column beside a media collage column)
  rebuild as a split hero. `analyzeSections` classifies top-level columns
  by widget roles (`COPY_WIDGET_RE`/`MEDIA_WIDGET_RE`), skips
  photo-background heroes (the band IS the media — jayainc's hero carries
  an EMPTY aux_image shell, so the media column must contain a real
  img[src] or the shape), and captures `titleDivider`/`titleStyle`
  (aux-modern-heading rules), `mediaShape` (aux-the-shape
  width/height/gradient/radius), per-photo `mediaOffsets` (absolute
  top/right/bottom/left + box-shadow — Elementor scopes these rules to
  the WIDGET id, never the section id, so the widget ids are collected
  from the DOM first), and `bodyColumns` (the inner-section column count
  — the columns nest under the inner CONTAINER, not the inner section).
  Hero.astro renders the split: title facts ride CSS vars (the clamp
  floor survives on mobile), offsets ride `--ds-off-*` vars so the
  mobile reset can return the photos to static flow.
- **Webfonts (125 §2):** the rebuild never loaded the harvested family —
  jayainc rendered in Segoe UI instead of Rubik. `renderDesignGenerated`
  emits `webFonts` (family + weights, system stacks filtered);
  Base.astro preconnects + links Google Fonts.
- **Container measure truth (125 §3):** the theme's per-breakpoint
  `--container-max-width` declaration (jayainc: 1140/1024/767) beats any
  `.elementor-container` sheet rule — jayainc's sheets carry a 1900px
  rule the live site never renders.
- **Flipbook catalog (125 §4):** the dflip shortcode's PDF source lives
  ONLY in its script; the sanitizer strips scripts, so the band rendered
  EMPTY. `analyzeSections` captures the `_df_book` node + the
  `df-shortcode-script` payload (`window.option_df_N = {...}`) as
  `catalog: { title, source }`; the renderer builds a centered
  open-catalogue CTA (the slug-ish data-title is backend id, NOT
  rendered copy; the source's 0px band padding is operator-pinned to the
  100px rhythm in the profile).
- **Gotchas (each cost a fix 2026-11-06):** htmlparser2 v12 emits
  script/style nodes with type 'script'/'style', NOT 'tag' — match on
  `child.name`; the hero parallax `from { translate: 0 +24px }` opened a
  24px WHITE GAP under the header at load (the cover range clamps
  pre-scroll progress to the from state) — the hero keyframes are now
  neutral-at-load, lag-only (`from 0 → to +2×range`); the band never
  translates against flush neighbors, the media-carrier keyframes keep
  the ±range. The theme toggle hardcoded `var(--forge-ink)` —
  dark-on-dark on themed headers; it now reads
  `var(--forge-header-ink, var(--forge-ink))`. **Verify against the LIVE
  deploy, never file://** — root-absolute stylesheet hrefs don't resolve
  over file:// and every computed style reads as unstyled. **The e2e
  fixture section ids must be hex** — the classifier's `elementorId`
  extracts only `^elementor-element-([0-9a-f]{4,})$`; a human-readable id
  (`elementor-element-hero-split-fixture`) silently loses the whole facts
  chain (no `data-section-id`, `themeFor` misses, plain-hero fallback with
  no divider/collage). Real Elementor ids are hex — name fixture sections
  `elementor-element-<hex>` (e.g. `a1b2c3d`) and key the profile's
  `design.sections` entries to the same id.

## Proposal 126 — cross-pollination with astro-velocity (2026-11-07)

Research-backed (web.dev responsive images, MDN animation-timeline, DTCG
2025.10, axe-core, WCAG 2.2 — full citations in
`docs/project/proposals/126-forge-velocity-cross-pollination.md`):

- **Image pipeline:** the design-band components (Hero media grid,
  FeatureGrid, IconList, Gallery, TextBlock) render through the existing
  `SmartImage` (Astro Image, same-host-only, `inferSize`, WebP + srcset;
  `eager` prop → `fetchpriority="high"` for the LCP image). The collage
  photos + logo marks stay plain `<img>` (absolute offsets / exact SVG
  sizing beat optimization). The fixture's About media URLs are ABSOLUTE
  now — relative paths would silently skip the processed-image e2e pin
  (SmartImage's same-host gate).
- **DTCG interchange doc:** `renderTokensDtcg` emits
  `public/tokens.dtcg.json` (2025.10 core→semantic groups, typed tokens,
  `{ref}` aliases) beside the flat CSS vars — NDS importer / ADS tooling
  readable. Only hex colors are DTCG-safe; non-hex token values stay
  CSS-only.
- **Mobile overflow (125 §8):** the body's `overflow-x: clip` guard
  CLIPS instead of scrolling — the single-row header lost its right side
  on phones. Header wraps under 767px (nav full row, tagline hides under
  480px); horizontal reveals degrade to plain fade on phones; the collage
  photos return to static flow below 1152px (the measured band fit).
- **Hero headline width (125 §8):** the photo-hero title runs the full
  band measure; the source keeps the 72px headline in a ~66% column.
  `.ds-section-wrap--hero .ds-title { max-width:
  var(--ds-hero-title-max, 66%) }` above 767px — the var is the per-site
  seam (e2e-pinned).
- **Cluster B — axe e2e gate:** `playwright` + `axe-core` as forge
  devDeps; the vitest e2e serves `dist/` on a loopback static server and
  runs `axe.run` in real Chromium (`axe.default.source` injected via
  `addScriptTag`). axe's own docs: `color-contrast` does NOT work in
  JSDOM — the browser route is the sanctioned integration. CI adds
  `npx playwright install --with-deps chromium` before the e2e step.
- **Cluster B — content blocks:** `FaqAccordion.astro` (native
  `<details name>` exclusive accordion) + `Tabs.astro` (radio-input
  technique, deterministic ids from `src/lib/blocks.ts` djb2) ship in the
  template for local MDX content. Two traps: MDX imports must sit at the
  TOP of the file (after frontmatter — mid-file imports silently don't
  render), and Astro scopes component elements with `data-astro-cid`, so
  e2e assertions must be attribute-agnostic (`toContain('<summary')`, not
  exact tags).
- **Cluster B — drift gate:** the scaffold writes `nvoos.generated.json`
  (sha256 of every generated/rewritten file); the template's
  `scripts/check-generated.mjs` re-hashes on `npm run check` and fails on
  hand-edits or stale scaffolds (unit-pinned clean/tamper/restore).
- **Cluster D/D2 — shared core:** `packages/nvoos-design-import` holds
  the canonical `contrast.ts`, `webfonts.ts` and `color.ts`; forge keeps
  byte-identical copies (`lib/contrast.ts`, `lib/color.ts`) pinned by
  `tests/shared-libs.test.ts` — edit the package first, never the consumer
  copy. The color-conversion core is the ONLY provably-shared analyzer
  slice: `lightnessOf` (WCAG luminance in forge vs HSL lightness in
  velocity) and the hue/saturation formulas deliberately differ — forcing
  either would change tuned classification thresholds.

## Capture surfaces — tracking, SEO head, stored redirects (130 / 131 / 132)

Three shipped passes close the "capture everything the site does beyond
content" gap. Full operational notes: `reference/capture-arc.md`.

- **130** — tracker/consent detection + `verify --tracking`, a consent-first
  rebuild, Report-Only CSP headers.
- **131** — Rank Math / Yoast head fidelity (`composeDocumentTitle`, robots,
  OG/Twitter, JSON-LD `@graph`, sitemap `lastmod` + noindex, `verify --seo`).
- **132** — Redirection / Rank Math rules exported by bridge 0.3.0 and merged
  beneath the rebuild's URL map (`_redirects` projection for Netlify /
  Cloudflare).

## Fidelity gates and measured facts (2026-11-07)

`analyze --geometry` + `verify --geometry`, fact consumption in `verify`,
`rulesInMedia`, and typecheck-as-a-gate. Full notes:
`reference/fidelity-gates.md`.

## Content, colour and pixel parity gates (2026-11-09)

Three gates close the "words went missing / the colours never applied / the
render drifted" blind spot:

- `verify --text` diffs the build's normalised visible text against the
  `analyze --text` baseline (3-word w-shingles; **coverage of the source** is
  the gate — added copy never fails a rebuild).
- `verify --colors` diffs the COMPUTED body/header/footer background+text
  colour with CIEDE2000 (warn ≥ 3, fail ≥ 6); transparent surfaces skipped.
  The token block proves the CSS carries the colours — this proves the browser
  renders them.
- `verify --pixel` diffs deterministic screenshots with pixelmatch's YIQ delta
  **and** SSIM against a recorded baseline (build-vs-build regression) or the
  live source (`--pixel-live`, near-clone work only). Determinism is proven,
  not assumed: `--pixel-baseline` refuses to write when two captures of the
  same build differ.

Mask noisy regions instead of loosening thresholds; never fake the clock (it
can freeze hydration). Full thresholds, extraction rules, the determinism
recipe, measured virtuallyjamaica numbers and the industry basis:
`reference/pixel-and-text-parity.md`.

## Phase roadmap (096 §5) — see reference

The phase-by-phase SHIPPED status (Phase 0 → 4, the publishing lessons, the
wp.org submission state) moved to `reference/phase-roadmap.md` on 2026-11-10
when this skill hit Zed's load limit. Operational rules stay below; read the
reference only for "what landed when".

## Proposal 136 — section vocabulary + agent surfaces (2026-11-09)

Six section kinds (`pricing`, `stats`, `steps`, `team`, `marquee`,
`comparison`) decided by STRUCTURAL evidence only — never a class token —
in the fixed order comparison → steps → team → pricing → stats → marquee.
No evidence → the old shape ladder runs unchanged.

`data-section-id` is identity (load-bearing); `data-section` is the kind and
is advisory. `lib/fact-audit.ts` audits the kind, so fixtures without the
attribute now fail the gate — intended.

Also shipped: band-level JSON-LD (`offerCatalogNode` refuses to emit a
partial price catalogue), the `verify --seo --structured` gate (prices
band-scoped, other facts page-scoped), and the agent surfaces (`llms.txt`,
`.md` variants, `MOTION.md`, `presets.json`).

**Never add `FAQPage` or `HowTo`** — Google removed the FAQ rich result on
2026-05-07 (docs deleted 2026-06-15) and HowTo in 2023.

Full notes (kind evidence rules, the ts2304 `semantic.ts` trap, JSON-LD
builder contracts, surface table, deferred work):
`reference/proposal-136-section-vocabulary.md`.

## CSP + client navigation (transferred from velocity, 2026-11-09)

Generated sites use `script-src 'self' 'unsafe-inline'` (`lib/scaffold.ts`),
because the template ships several `is:inline` scripts — and the template has
**no** `ClientRouter` and **no** `client:*` islands. Those two facts are why the
velocity CSP outage does not affect forge output; keep both from drifting.

A hashed `script-src` + client-side routing is unsafe by construction: a
navigation keeps the SOURCE document's policy, so any inline script the target
page adds is blocked — and `client:visible`/`client:idle` each emit a per-page
inline bootstrap (only `client:load` adds none).

Managed/SSR mode emits `routeRules` with `swr: 600` for `/products/**` and
`/posts/**`; stale HTML plus a hashed CSP is the same failure. And incremental
hosts never delete files that leave the tree, so an orphaned
`dist/client/**/*.html` shadows the SSR route indefinitely — purge it at boot
**only** when the project is fully SSR (a `prerender = true` project must not).

Details, the generated-config inventory and the one-route-stale triage rule:
`reference/csp-client-navigation.md`.

## Builder vocabulary (Proposal 137, 2026-11-09)

The design extractor used to be Elementor-only: any other builder produced
**zero** bands and the build silently degraded (virtuallyjamaica.com —
WPBakery + Brando). `lib/bands.ts` now segments the DOM in three layers —
**adapters** (declarative fingerprints: elementor, wpbakery, divi, beaver,
bricks, gutenberg, oxygen, flatsome, revolution-slider), **semantic** landmarks,
then **VIPS-lite** visual boundaries.

Adding a builder = two data rows (`BUILDER_ADAPTERS` + `WIDGET_PATTERNS`) and a
real-markup fixture; nothing branches on builder identity.

Three rules that cost real debugging: column detection is ORTHOGONAL to band
detection (Brando emits `wpb_column`s with no `vc_row`); prefer the LARGEST
breakpoint (`vc_col-sm-12 vc_col-lg-6` → 50 %, not 100 %); and `design.outline`
is NOT `design.sections` — `sections` drives rendering and band-flow, skeleton
bands there fail the gate.

Full vocabulary table, the three rules in detail, the coverage-signal semantics
(8 → 2 unknowns) and the phases: `reference/builder-vocabulary.md`.

## Render-side bands, per-band gates and the handoff lock (2026-11-10)

Proposal 137 was the ANALYZER half. The virtuallyjamaica rebuild then failed on
the RENDER side; the rules that fixed it are in
[`reference/render-side-bands.md`](reference/render-side-bands.md):

- classify bands on `<section>` + WPBakery markers too, or the WHOLE page becomes
  one text section and positional theming paints every band with band 0's colour;
- `sliceHtml` must use `endIndex + 1` — htmlparser2's `endIndex` is INCLUSIVE. A
  truncated `</section` makes the browser nest every later band inside the
  previous one (symptom: opens ≫ closes, 8 000px bands, repeated text);
- the scaffold honours `design.bandSource`, so a promoted outline stays positional;
- `SANITIZE_VERSION = 3` keeps only `background-color`, `background-image`, a
  DERIVED contrast ink (`color`), and `class` on `<i>`; shortcode artifacts die;
- the template ships a WPBakery layout shim (`.row` / `.wpb_column` / inner rows /
  owl carousels) and **`:has()` cannot be nested** — an invalid selector drops the
  whole rule silently;
- `rm -rf .astro` after a sanitizer change (the content layer caches);
- pin `design.chrome` for header/footer/social facts the analyzer misses;
  a pin under `tokens.*` writes an unread `design.tokens` — a silent no-op.

Two capabilities ship with it — full contract in
[`reference/handoff-lock-and-band-gates.md`](reference/handoff-lock-and-band-gates.md):

- **`verify` runs a per-band audit by default** (`--no-bands` opts out):
  presence · surface · content · facts · text · geometry, per band, to
  `<target>/verify-artifacts/bands.json`. A check that cannot be evaluated is
  SKIPPED, never passed. `--bands-warn` downgrades failures.
- **A surface fact travels on two channels:** the wrap's inline `style` AND a
  scoped `<style>` rule. A per-device value cannot be inlined (it would outrank
  the media queries), so it only ever reaches the scoped rule — the audit read
  the inline attribute alone and reported `padding none ≠ 125px 0px 125px 0px`
  on jayainc `059421c` while the rule was in the page. `@media` blocks are
  stripped before parsing so a mobile value is never read as desktop.
- **`spacer: true`** pins a band the SOURCE renders empty (verify it first —
  jayainc `dff3f1a` has 0 text, 0 images). Without it an intentionally empty
  band fails the content check; with it the band still gates on presence and
  surface, it is just not asked for copy.
- **`forge lock | unlock | status`** transfer ownership after sync. Locked is
  fail-closed: `scaffold --overwrite` refuses (name the owner; `--unlock` forces),
  and the project's `npm run check` reports hand-edits as expected drift
  (`--strict-lock` restores failure).
- **Staging origins:** `ASTRO_SITE` is the BUILT site's public origin, while
  `WP_SITE_URL` stays the CMS. A staging host that self-canonicals must also set
  `NVOOS_FORGE_NOINDEX=1` (meta robots + `Disallow: /`).

## Band geometry — matching the source's BOXES (2026-11-10)

The same rebuild had the right content, colours and order but the wrong SIZE
(hero 746 vs 900, contact 675 vs 900). Rule: **measure, never scavenge** —
builder themes declare rhythm in classes the sanitizer strips, so
`getComputedStyle` is the only faithful source. Full contract:
[`reference/band-geometry.md`](reference/band-geometry.md).

- `analyze --geometry` now reads bands from the profile being written, so it no
  longer forces `--design` (which overwrote hand-tuned `design.chrome`/`tokens`).
- Source selector order: `.elementor-element-<id>` → caller's anchor `#about`.
  **No `#<id>` fallback** — `#45f5e88` is an invalid selector that throws, and a
  throw aborted the WHOLE baseline. Every candidate is `try/catch`ed.
- A source anchor on a COLUMN (`id="wifi"` on `wpb_column`) is promoted to the
  nearest `<section>`, else the row — the box actually rebuilt.
- Scaffold emits per-device `padding` (four sides) and per-device `minHeight`
  from the measurement. Measured FLUSH emits `'0'`, not nothing: absent leaves
  the template's own 32px rhythm in place.
- `minHeight` is a floor, never a lock — it closes UNDERSHOOT only. Overshoot is
  a content-scale difference and must be reported as such.
- `min-height` must be applied **before** the hero early return in `wrapStyle`,
  or the one band a source height pins never gets it.
- Rows are a **12-column GRID**, not equal-share flex: `grid-column: span N` from
  `vc_col-{lg,md}-N` with `[class~=…]` (whole token). Carousels stay
  `flex-wrap: nowrap` + `flex: 1 1 0`, or 7 logos wrap onto a second row.
- `verify --geometry` must serve `dist/client` for a server output — serving
  `dist` 404s and reports every band as "build missing".

## Measured band typography + the sticky header (2026-11-10)

The two passes that closed "the sections still don't match": the raw-HTML band
path had NO heading scale (a 40px source `<h3>` rendered at 15.21px = `1.17em`
of the 13px body), and a sticky header's SCROLLED state was never captured. Both
are browser facts on `analyze --geometry`, stored as `profile.bandFacts` and
`profile.stickyHeader`. Full contract:
[`reference/measured-band-facts-and-sticky-header.md`](reference/measured-band-facts-and-sticky-header.md).

- `bandFacts[id]` = per-heading computed facts (size/weight/line-height/
  letter-spacing/transform/**text-align**), the body paragraph, and the inner
  `containerWidth`. Scaffold emits `[data-section-id] h3{…}` + `--ds-title-*`,
  and derives the band `measure` from the container (fluid bands stop rendering
  at the site measure).
- `stickyHeader` = rest vs pinned background/ink/height. `Base.astro` adds
  `site-header--pinned`; global.css rebuilds it as a `scroll(root)` timeline
  (rest state is the BASE — a header's finished state is the scrolled one, and a
  solid strip over the hero at scrollY 0 is the louder error).
- `bandFacts[id].hidden` = the selectors the SOURCE renders hidden at rest
  (accordion panels, tab panes, mobile drawers), hidden at BOTH breakpoints and
  carrying `:not(.in)`-style exclusions so the open panel stays visible. This
  replaced the hand-written `.panel-collapse.collapse:not(.in)` rule.
- `verify` runs a **class-coverage audit** (report-only; `--strict-classes`
  makes it a gate): every class name in the rebuilt bands that matches no rule,
  written to `verify-artifacts/class-coverage.json`. That list is the inventory
  behind every hand fix in this arc — read it before adding CSS.
- **Vendor utilities are DATA, not template CSS** (`BUILDER_ADAPTERS[].utilities`):
  `text-center`, `text-medium`, `padding-four-all`, `font-weight-600` … are
  emitted per band, trimmed to the classes the page measured (`bandFacts[].classes`).
  `global.css` carries none of them, so a Divi site gets Divi's conventions and
  nothing else. Values are measured. The VALUE-NAMING responsive variants
  (`xs-padding-40px-tb`, `sm-padding-70px-tb`, `md/sm/xs-text-center`) are mapped;
  the numeric spacing scale is not — it arrives as a measured box fact instead
  (see the addendum below).
- Gotchas: read the pinned snapshot after a real wait (the live page's header
  colours land a beat after the scroll class); the fact audit cannot see a
  scoped rule that sits before the band, so its marker needs a `present`
  predicate over the whole page; and the audit must include the page's INLINE
  `<style>` blocks and treat `[class~=…]`/`[class*=…]` as coverage, or it
  reports correctly-styled bands as missing.

### Addendum (2026-11-11) — box facts, header LAYOUT, two sanitizer bugs

Typography and scrolled colours were not sufficient. Four more measured facts,
all general to builder-based sites (detail: reference §6–§10):

- **`verify --geometry` now explains itself.** Any deviating band gets a box-tree
  diff: the wrapper-vs-content split, the `hot path`, and the top contributors
  (Layout-Instability-style attribution, capped at five), written to
  `verify-artifacts/band-diff.json`. `--diff` forces it for every band. Read the
  contract before writing another throwaway Playwright probe:
  [`reference/band-diff.md`](reference/band-diff.md).
- **`bandFacts[id].box`** — computed `padding`/`margin` per class per device, plus
  a CROPPED image's rendered height. The numeric scale is not a ladder, so this
  outranks the utilities pack. Always emit `px`; record ZERO; emit the tablet
  range EXCLUSIVELY (a cascading `max-width:1024` leaks into phones); and NEVER
  restate half the Bootstrap grid (`container|container-fluid|row|vc_row|wpb_row`
  are excluded — their padding and negative margin only work as a pair).
- **`stickyHeader.position`** — a `fixed` source header floats OVER the first
  band. Sticky pushed it down a full header height, leaving virtuallyjamaica's
  WHITE logo/nav on the white page above the red hero. `site-header--float`.
- **Chrome ink ≠ nav-link ink** (`--forge-header-nav-ink`), and a transparent rest
  header CLEARS `chrome.headerBg` (`applyMeasuredHeader` reconciles the harvest).
- **A fluid band must not be inset** — `paddingX: 0` when `containerWidth >= 1300`.

Two bugs fixed: `class` was stripped from `h1`–`h6` (headings lost their measured
box), and `--dark` set light ink on the WRAP instead of its children.
virtuallyjamaica: **0 fail** box geometry, **0 drift** colour, **19 of 219**
classes inert (was 109 of 196).

## Cross-app parity gate (forge ↔ velocity, 2026-11-09)

The two Astro products share a CONTRACT but not code, so neither suite can see
the other tree. `bin/check-astro-app-parity.mjs` (CI:
`.github/workflows/astro-app-parity.yml`, triggered by EITHER app) is the
record — read it as the spec. Exit 0 = parity, 1 = undeclared drift. It pins:

- **`composeLlmsTxt` is byte-identical** modulo one declared knob: the forge
  always links `.md`, velocity defaults to HTML (`variant: 'markdown'`
  reproduces the forge exactly).
- **`data-section`**: each vocabulary = `(shared) ∪ (its own declared extras)`.
  Adding a kind to either app fails until declared — a new kind is a
  cross-product decision.

- It is a **script, not a vitest test**, on purpose: inside an app's suite every
run of that app would need the OTHER app's `node_modules` (each tsconfig
extends `astro/tsconfigs/strict`, resolved from the importing file's directory
→ `[TSCONFIG_ERROR] Tsconfig not found`). Node 24 strips TS types natively.
Same discipline as `bin/check-chat-parity.php`.

- **MCP surface (2026-11-09):** tool LISTS differ on purpose (a generator vs a
storefront), so the gate pins the shared RUNTIME plus every name that appears
on both sides:
- annotation presets (`READ_ONLY_TOOL` … `DESTRUCTIVE_WRITE_TOOL`) must carry
  the same four hints in both `mcp/util.ts` files;
- the request pipeline must keep the fail-closed ORDER
  (`limiter.allow(` → `validateHost(` → `validateOrigin(` →
  `authorizeRequest(`) and the codes `-32029` (rate limit) / `-32001`
  (unauthorized write);
- a tool name present in both apps must be declared either
  `SHARED_MCP_TOOLS` (identical title + annotations) or
  `MCP_NAME_COLLISIONS` (with a reason). Today the shared set is EMPTY and
  `rebuild_trigger` is the one declared collision (forge: vault-held signed
  bridge POST, open-world write; velocity: `NVOOS_REBUILD_WEBHOOK_URL`,
  idempotent write). The extractor reads both trees as TEXT (34 forge / 35
  velocity tools) so the script stays dependency-free — keep it that way.
- **Forward rule:** when the managed forge tier ships its `/mcp` surface
  (porting the velocity design/ops slices), every copied tool moves into
  `SHARED_MCP_TOOLS` — the gate fails the moment a name exists on both sides
  unclassified. The workflow `paths` cover `examples/*/mcp/**` for both apps.

## Template endpoints and the deploy-branch README (2026-11-09)

Mirror branches are force-pushed every build, so a README there must be a
**build artifact** (`src/pages/README.md.ts` → `dist/README.md`), never a
hand-written file. Two traps when adding a template endpoint: `nvoos.json`
**omits keys when they are unset** (`tracking`, `collections[].kind`), so the
inferred JSON type must be read through an all-optional shape cast via
`unknown`; and `getCollection` is typed per preset, so `'products'` is a type
error on a blog-only site — widen the accessor and keep it in a `try`.

Full notes: `reference/template-endpoints.md`.

A one-pager's `shape` is a profile SETTING (`profile.shape`), not the
`--inventory` verdict; the sanitizer strips `id` unless it is explicitly
allowed, and that is what anchors ride on. Full notes:
`reference/site-shape-and-anchors.md`.

## Extra (CPT) collections get routes (2026-11-09)

Any collection beyond `posts`/`pages`/`products` is a CPT: the scaffold
generates a `/<basePath>/` route pair, keeps it through every preset, and
emits it in sitemap/llms. The source needs `show_in_rest`. Notes:
`reference/cpt-collections.md`.

## Proposal 139 — media localization (2026-11-09)

Full notes: `docs/project/proposals/139-forge-media-localization-proposal.md`.

A forged site must never hotlink the CMS. Astro's `<Image>` localizes what it
can see; everything else did not — body-content `<img>`, chrome logos/favicon,
collage photos, and any host outside `image.domains`. A domain move
(`jayainc.com` → `cms.jayainc.com`) therefore broke images.

Three layers, one contract:

- **Authorize** — `image.domains` = CMS host ∪ `profile.media.hosts` ∪
  `NVOOS_FORGE_MEDIA_HOSTS`; `SmartImage` reads the same rule from
  `src/lib/media.ts` instead of a single-host comparison.
- **Repair** — a `/wp-content/` or `/wp-includes/` path whose host is neither
  the CMS nor an allowlisted media host is re-pointed at the CMS origin
  (path + query preserved). Do NOT put the old domain in `media.hosts`: the
  repair is what makes a stale DB work; allowlisting the dead host makes the
  build fetch from the wrong place.
- **Mirror** — `scripts/localize-media.mjs` runs after `astro build` (before
  pagefind). Strategy follows the tier: `nvoos.json runtime: 'static'` →
  **mirror** every remaining candidate into `dist/media/<hash>-<slug>.<ext>`;
  `runtime: 'managed'` (bridge/BFF present) → **proxy** uploads to the
  runtime's same-origin `/media/<rest>` route (a rewrite, no download).
  **External stylesheets are mirrored with their fonts in BOTH modes**
  (theme icon sheets, plus the Google Fonts sheet when
  `NVOOS_FORGE_LOCALIZE_FONTS` is on — the `/media` proxy only matches uploads,
  so leaving the font chain remote kept a render-blocking cross-origin hop in
  a supposedly self-contained artifact). Icon glyphs therefore stop depending
  on the CMS host — and stop tripping a future enforced CSP. **Linked documents** (an allowlist:
  pdf/doc/xls/ppt/odt/csv/txt/rtf/epub/ics/zip) are mirrored too, with their
  own cap and `#page=` fragments preserved; executables are never candidates.
  Non-image stored facts (the catalogue CTA, `chrome.iconCss`, the JSON-LD
  logo) are repaired at RENDER time via `repairMediaUrl` instead. Override:
  `NVOOS_FORGE_MEDIA_MODE=local|proxy|off`. Fail-soft (report in
  `.forge-cache/media-localize.json`, build never fails), idempotent, honours
  `WP_FETCH_PROXY`, caps per file (25 MB) and per build (500 MB).

- **Optimize** — Proposal 140 adds WebP re-encode (q82), intrinsic
  `width`/`height` fill, self-hosted webfonts with `font-display: swap`,
  dead `preconnect` stripping, and a `media budget` verify gate. Env vars,
  modes and gotchas:
  [`reference/performance-budget.md`](reference/performance-budget.md).

Config: `profile.media { hosts, localize, mode, maxFileMb }` → `nvoos.json` +
`.env.example`; env wins. `media.mode` (`local|proxy|off`) is how a managed
site — whose default is the bridge’s same-origin `/media` proxy — opts into a
fully self-contained artifact: `"media": { "localize": true, "mode": "local" }`.

**Never rewrite** `<a href>`, `<meta>` (`og:image` must stay absolute) or
JSON-LD — a relative path there is invalid, and the catalogue PDF must keep
working. Gotchas: emit `url(/media/…)` **unquoted** (a nested quote breaks a
`style="…"` attribute); attribute regexes need `(?:^|\s)` / `(?<![\w-])` so
`src` does not match inside `data-src`; SSR output lives in `dist/client`;
and a flaky `EPERM`/`ENOTEMPTY` scaffold timeout under 61 parallel workers is
the Windows staging race — re-run with `--maxWorkers=4` before debugging code.
- **In-process fixture server + `spawnSync` deadlocks:** an integration test
  that serves HTTP from the test process and runs the CLI synchronously
  hangs until the request timeout — the parent's event loop is blocked,
  so it can never answer the child. Spawn asynchronously; await the exit.

## Repo conventions

- **Public mirror:** `examples/nvoos-astro-forge/` is subtree-mirrored to
  the public repo `nvdigitalsolutions/nvoos-astro-forge` by
  `.github/workflows/sync-nvoos-astro-forge.yml` (flat history,
  force-pushed; the whole MIT subtree ships — `dist/` is gitignored).
  Activation: public repo + the `NVOOS_ASTRO_FORGE_REPO_TOKEN` secret
  (contents:write) + one workflow_dispatch backfill. Never commit to the
  mirror directly. The npm `repository` field still points at the monorepo
  directory (provenance source of truth).

- PRs against `alpha-working`, owner-merge; explicit-path staging (`git add
  <file>`, never `-A`) — see `mcp-ai-wpoos-git-ops`.
- Adding a skill updates the inventories: `AGENTS.md` (2 spots) +
  `.github/copilot-instructions.md` (2 spots), bumping the skill count.
- Docs: the research file §0 carries the verified build learnings; the
  implementation plan §2.1 carries Phase 0 status vs plan.

## References

- `examples/nvoos-astro-forge/README.md` (layout, quick start, invariants)
- Proposal 096 trio (`docs/project/proposals/096-*`) · Proposal 098 (design
  language import — the theme input) · Proposal 092/095 (the storefront +
  ADS the template borrows from) · Proposal 104 (storefront runtime
  hardening — the forge-half deliverables: presets, agent stubs, copy
  tables, `lib/seo.ts`, theme port, Tailwind bridge, limax slugs)
- Related skills: `mcp-ai-wpoos-astro-storefront` (the serving side — bridge,
  /mcp, tokens), `mcp-ai-wpoos-playground-demos` (the Phase 2 Playground
  fixture replacement), `mcp-ai-wpoos-git-ops`.
- The bridge plugin: `plugins/nvoos-forge-bridge` — **standalone** (any
  `repository_dispatch` consumer works; the forge is the reference
  consumer). Its wp.org submission track and review conventions live in
  the `mcp-ai-wpoos-wporg-submission` skill.

## Email delivery

`forms.delivery`: `bridge` (WP owns the mailer) or `direct` (managed +
Brevo/Mailjet API). SPF/DKIM/DMARC decide inbox placement. Notes:
`reference/email-delivery.md`.
