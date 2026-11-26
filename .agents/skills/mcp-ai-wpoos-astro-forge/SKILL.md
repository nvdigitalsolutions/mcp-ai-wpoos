---
name: mcp-ai-wpoos-astro-forge
description: "Operational guide for the NV oOS Astro Forge (Proposal 096): the WP→Astro frontend generator in examples/nvoos-astro-forge (wp-client, content-layer loader, versioned sanitizer, scaffold CLI, blog template, hermetic WP fixture, CI gate). Use when forging a customer frontend, adding a template or collection, debugging the e2e gate, continuing the wc/v3 Woo loader and MCP tool surface, running the design-rebuild loop (design block, contrast audit, forge verify), or working the forms bridge."
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

- **Content-layer loaders, not materialized content files** — `content_materialize` stays opt-in (096 §2.3). When it IS opted in, snapshot **while the CMS is live**: `forge materialize <profile> --from-wp` drives the production loader, because `--from <build>` needs a `dist/server` store a `static` runtime never has. Details: `reference/cms-removal-materialized-content.md`.
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
| `lib/animations.ts` | 142 animated-effect inventory — `@keyframes` + library signatures, `document.getAnimations()` probe (rest/50%/bottom), planner (finite/safe → scoped CSS; infinite/JS/non-band → deferred), diff |
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
5. Template CSS edits after the fact: `forge tokens <profile> --target <dir>`
   re-applies the token block in place (`--check` reports drift). Copying a
   template `global.css` over the project wipes the block, and only the scaffold
   pipeline rewrites it — see `reference/flatsome-and-outline-rebuild.md`.

Pick a preset with `--preset blog|storefront|minimal` (or let the wizard
suggest one from the analysis — Woo/products detected → `storefront`).

## Gated sources — maintenance / coming-soon / staging auth (152, shipped 2026-11-20)

The forge fetches two planes: `wp/v2` (Application Password) and the RENDERED
HTML (a `wp-login.php` **session**). An Application Password cannot log into
`wp-login.php`, so a pre-launch site that answers `503 + Retry-After` (or a
`200` holding page) on HTML while `/wp-json/` stays open used to skip the design
pass with a warning and scaffold the coming-soon page. Now:

- **`lib/gate.ts`** — `openGate(profile)` probes, logs in once (or takes a
  cookie/storageState/bypass token), and installs a **host-scoped** global
  fetch wrapper plus the Playwright cookies (`gateContextOptions()`).
  `gate.blocked` is the verdict; `gate.close()` uninstalls.
- **Credentials as variables** (env → vault → prompt, never the profile or the
  generated repo): `WP_GATE_USERNAME` / `WP_GATE_PASSWORD`, `WP_GATE_COOKIE`,
  `WP_GATE_STORAGE_STATE`, `WP_GATE_QUERY`, `WP_GATE_HEADERS_JSON`,
  `CF_ACCESS_CLIENT_ID` / `_SECRET`, `WP_GATE_HTTP_AUTH`.
- **`forge gate <profile>`** diagnoses (exit `3` gated, `4` edge-auth, `5`
  rate-limited); `--login [--vault]` stores the credential encrypted. `analyze`
  **refuses** a blocked source (`--allow-blocked` overrides); the wizard never
  allows it; MCP gains the read-only `gate_probe` and `design_apply` fails
  closed with the remediation ladder.
- **The bridge corroborates the probe (P4).** Every probe also asks
  `GET /wp-json/nvoos-forge/v1/gate` on the **forge bridge** plugin (v0.3.4).
  A `404` means the plugin is absent; a JSON verdict (`maintenance`, `mode`,
  `plugin`, `checkedAt`) turns an `open` **anonymous** HTML read into `gated` —
  the cached-anonymous-page case HTML alone cannot distinguish. The bridge
  reports the site's **anonymous** mode, so it must never downgrade an
  authenticated read (a real login unlocks what the anonymous visitor sees).
- **Never** spoof a crawler UA, solve a JS challenge, forge a cookie, or
  disable a production gate to make a build pass.

Full contract, the probe classification table, the bridge route, the fixture's
`WP_FIXTURE_GATE` / `WP_FIXTURE_BRIDGE_GATE` modes and the implementation
gotchas (async spawn vs an in-process fixture; `process.exit()` →
`0xC0000409`; `<const T>` for `gateContextOptions`):
[`reference/gated-sources.md`](reference/gated-sources.md).

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

**Template CSS pins are part of the gate.** The CSS assertions read the
**minified** `dist/_astro/*.css` and pin exact values — the logo-cloud band,
for instance, asserts `grid-template-columns: repeat(6, minmax(0, 1fr))` plus
`.ds-logos img { … height: 72px; object-fit: contain }`. So a change to
`templates/blog/src/styles/global.css` that touches a pinned rule must update
`tests/forge-e2e.test.ts` **in the same PR**, and the regex must be checked
against a real build's output first — minifiers rewrite the spacing
(`grep -o '\.ds-logos[^}]*}' dist/_astro/*.css` in a forged project). #7771
merged with that check red, which left `alpha-working`'s gate failing on a
CSS-only PR and forced a second PR to repair it: a merged PR cannot be
reopened, so re-base the fix onto `alpha-working` instead of pushing to the
old branch.

## Gotchas (each cost a fix — do not re-hit)

The **full list** lives in [`reference/gotchas.md`](reference/gotchas.md) (split
out 2026-11-11 to keep this file under Zed's ~100 KB skill-load limit). Read it
before touching the CLI, the scaffold or the sanitizer. The highest-traffic ones:

- **e2e CSS asserts pin the minified template CSS** — changing a `global.css`
  rule the gate asserts (grid tracks, the logo box, the call button) means
  updating the matching `forge-e2e.test.ts` regex in the same PR, or
  `alpha-working` goes red on an otherwise-clean merge.
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
  `bandFacts[id].hidden`. The CLOSED state is data; REOPENING it is behaviour —
  `BuilderAccordion.astro` enhances `[data-accordion]` groups so the toggle,
  exclusivity and aria state are restored without the vendor's JS or icon font.
  Per the APG accordion pattern the panel `role=region` is OPTIONAL and is
  skipped past ~6 panels (landmark proliferation), which covers
  virtuallyjamaica's 8.
- **The accordion icon must stay inline in EVERY state.** `a:hover i` once
  shared the control's declaration block, so `display:block` landed on the
  `<i>` as soon as the pointer entered a row: a block icon takes the content
  width and the title grew 50px → 64px mid-hover. The icon's `display` is now
  stated for both the resting and the `:hover` selector; the control rule also
  uses `color:inherit` rather than naming the accent.
- **Text ink is a measured fact** (`BandTypeFact.color`, emitted as
  `[data-section-id] hN{color:…}` and `--ds-title-color`). A builder writes
  per-element colour as inline `style="… !important"` and sanitisation drops
  every `style` attribute, so this is the only surviving copy — without it a
  title inherited the template accent (virtuallyjamaica: `#fe6c61` for a
  `#262626` source).
- **A control's resting decoration is measured, not inferred**
  (`REST_PAINT_PROPERTIES` / `REST_LAYOUT_PROPERTIES` in `lib/interactions.ts`):
  `text-decoration-line` is emitted even when it is `none`, because the UA
  default for `a[href]` is UNDERLINE — omitting it drew a line through every
  builder CTA. A control that PAINTS (a fill or a drawn border) also carries
  `display` and `font-size`; a bare text link never does (freezing its size
  would pin responsive copy).
- **The class-coverage audit must include the page's inline `<style>`** and treat
  `[class~=…]`/`[class*=…]` as coverage, or correctly-styled bands read as missing.
- **Check the element EXISTS in the build before fixing its CSS.** A
  component-class fix is a silent no-op on a raw-HTML site: `grep -c
  'class="ds-title' dist/*.html` before touching the `.ds-title` rules. Per-
  element type on those bands is a CLASS BOX FACT (`bandFacts[id].box`), not
  `design.elementCss`, and `bandBoxCss`'s `.post-body .section-content .col .x`
  selector is a five-class specificity floor no reordering can beat.
- **`profile.url` is not always the rebuild.** A "detached" site can still have
  its URL pointing at live WordPress while a preview host serves the Astro
  build — check for `wp-content` / `<meta name="forge-build">` before deciding
  that a re-measure is circular.
- **`sites/` is enumerated as site profiles**, so an artefact parked there is
  scaffolded as a site and fails `assertProfile`. `forge compare` specs live in
  `compare/<site>.json`.

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
  IconList/Testimonial/LogoCloud/Cta/Gallery/TextBlock/SectionLinks), wired into
  `[slug].astro` / `posts/[...parts].astro` / `index.astro` via the
  sanitized body — **the body string must be extracted in getStaticPaths**
  (props are serialized; the store's `rendered` object does not survive the
  trip; `entry.body` is never populated). Plain Gutenberg prose and
  non-builder content render identically to before (the e2e XSS/embed/rich-
  content gates pin this).
  **Band-level CTAs:** `slots.links` is a superset that also carries card
  links, so a component rendering only `items` drops the band's own button
  (jayainc's products band shipped without its "ALL SERVICES" CTA). Every
  grid/list component mounts `SectionLinks`, which renders the leftovers as
  `.ds-section-links > .ds-button`; it filters against links already visible
  inside `items`, so a card CTA never renders twice.
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
    obvious here. It also measures each COLUMN's height, `attachment` and
    `size` — a 700px fixed photo beside 946px of copy is a fact the sanitized
    markup cannot carry — and each CAROUSEL's slides-per-view, gutter and dot
    count (a slider's `items` lives in its JS options). See
    `reference/band-geometry.md` §11–§12.
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

Moved to [`reference/form-bridge.md`](reference/form-bridge.md) — content unchanged
(split out 2026-11-17 to keep this file under Zed's load limit).
## Proposal 165 — the publication gate (managed tier)

A forged managed site can mimic Elementor: the public sees a holding page, a
signed-in manager sees the real site. Two modules, two questions — `lib/gate.ts`
is how the FORGE enters a gated source; `lib/gate-publish.ts` + `lib/gate-runtime.ts`
are how the BUILT site behaves while the CMS is gated. `gate.generated.json` is
always emitted (it is the runtime boot state), `trust: bridge` refuses to gate on
HTML inference alone, and a gate page stripped from the build falls back to a
built-in copy rather than 500.

Full contract, the `honor` policy, the session HMAC recipe and the artifact rules:
[`reference/proposal-165-publication-gate.md`](reference/proposal-165-publication-gate.md).

**A prerendered page only answers at its slashed URL** (since 2026-11-26,
#8330). `build.format 'directory'` writes `dist/client/<slug>/index.html` and
nothing else, so `/about-us` matched no route and 404'd on a managed build —
while WordPress 301s that exact form, so every legacy inbound link broke.
`createStatic` now probes `<path>/index.html` and 301s to it, query preserved.
Recognise it by: `/about-us/` = 200 and `/about-us` = 404 for the SAME document,
with SSR routes (`/manage`, `/api/*`) unaffected. A live page that 404s is worth
re-checking with the slash before suspecting content, the gate or the build.

## Manage sign-in (Proposal 165, hardened 2026-11-26)

`/manage` has three doors and five failure codes; the code names the layer.
Read `?error=` before touching the credential.

| Code | Meaning |
|---|---|
| `config` | no signing key (env, or the provisioned `.forge-gate-secret`) |
| `origin` | browser Origin not accepted — proxy Host rewrite vs `security.checkOrigin` |
| `credentials` | WordPress rejected the credential |
| `permission` | authenticated, but no `edit_posts` / `manage_astro` |
| `upstream` | the CMS did not answer in time |

Doors, the key-provisioning rule, the `allowedDomains` + `x-forwarded-host` fix
and why the bridge needs no new endpoint:
[`reference/manage-sign-in.md`](reference/manage-sign-in.md).

**`scripts/doctor.mjs` ships with every managed deploy.** Run it on the server
with `DOCTOR_EMAIL` / `DOCTOR_PASSWORD` set: it probes the CMS REST index, the
gate, the contract and the local health route, prints a PASS/FAIL line per layer
with timings, and exits non-zero. From a laptop it cannot see a WAF block on the
app's egress, which is exactly the failure it exists to catch.

## Shared-mirror deploy (branch per site, 2026-11-06)

Moved to [`reference/shared-mirror-deploy.md`](reference/shared-mirror-deploy.md)
(content unchanged; split out 2026-11-26 to keep this file under Zed
load limit).

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
- **The 2026-11-12 element pass (do not regress):** `aux-parallax-section` is a
  CONTAINER, not motion — Phlox's `AvertaParallaxBox` returns when it has no
  `aux-parallax-piece` targets (jayainc: 24 markers, 2 real pieces → only the
  About band is parallax). Pieces carry depth/origin/off + Elementor
  direction/speed/range/devices; the renderer emits per-media
  `--ds-parallax-from/to` rules with the source's `off`/device gate, and mounts
  ALL behaviors for a band (the old single `find` dropped one when a band was
  both reveal and parallax). `.ds-lede` is content-width except in centered/
  hero bands. Full contract:
  [`reference/parallax-element-pass.md`](reference/parallax-element-pass.md).
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

## Proposal 142 — animation inventory (2026-11-12)

`analyze --animations` measures the source's animated effects with
`document.getAnimations()` (rest + 50% + bottom scroll) plus static
`@keyframes`/library signatures; `verify --animations` matches the generated
`forge-anim-<hash>` rules on the built page (default **advisory** — a JS runtime
cannot be reproduced by a no-JS template). Finite, layout-safe CSS keyframes on
a band/media/control/title target rebuild as scoped CSS under `prefers-reduced-
motion: no-preference`; infinite motion ships with a page-level **Pause motion**
control (WCAG 2.2.2); JS runtimes (GSAP/Lottie/Rive) and non-band targets are
reported in `MOTION.md`, never vendored. `--animations-pages N` covers sub-pages.
The browser half is pinned hermetically (`tests/animations-browser.test.ts`:
CSS keyframe, WAAPI, IO reveal, and a `scroll(root)`/`cover 10%` round-trip
through the emitter back into Chrome) plus the reduced-motion base state.
**`Animation.rangeStart` is a dictionary, not a string** — `String()` gives
`[object Object]`; see the reference. **REA (`morluto/rea`) is a
reverse-engineering MCP, not an animation library — do not add it to the
package**; it is an operator-side investigation tool only. jayainc records
`verify.gates["animated effects"] = "gate"`. Full contract:
[`reference/animation-inventory.md`](reference/animation-inventory.md).

## Proposal 122 — fidelity round (2026-11-06)

Ten jayainc drift reports fixed in one round (PR #7486): section widths, button
shape, the missing services image, the footer, CTA shape, pins. Full notes:
[`reference/proposal-122-fidelity-round.md`](reference/proposal-122-fidelity-round.md).
The three rules that still bite: **measure is site, not prose** (never a ch-based
`--forge-measure`), **full-bleed centering must use
`margin-left:50%;transform:translateX(-50%)`** (never `100vw - calc`), and motion
keyframes use `translate`/`scale`, never `transform` (it replaces the centering).

## Proposal 123 — chrome typography pass (2026-11-06)

Moved to [`reference/chrome-typography.md`](reference/chrome-typography.md) — content unchanged
(split out 2026-11-17 to keep this file under Zed's load limit).
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
  **Those static tracks are RESPONSIVE** (`repeat(auto-fit, minmax(9rem, 1fr))`,
  shape spans the row): the source keeps its photos out of flow, so stacking
  them full width made the collage taller than the source's whole media column
  (jayainc about: 1286 vs 1207 at tablet, 1760 vs 1394 at mobile). Sharing a
  row drops the collage under the band's measured `minHeight`, which then pins
  the band to the source box exactly.
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

Three passes close the "capture everything beyond content" gap — notes:
`reference/capture-arc.md`.

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

## Measured chrome + band-list fidelity (2026-11-23)

Three rebuild failures, now rules — full detail in
[`reference/measured-chrome-and-band-fidelity.md`](reference/measured-chrome-and-band-fidelity.md):

- **Chrome surfaces and ink are MEASURED.** `applyChromeSurfaces` overwrites the
  harvest's header/footer surface + dominant ink from the colour pass (a
  transparent header takes the BODY surface); `navInk` must appear in the
  header's ink palette or the palette wins; header CTA pills are measured in the
  browser (`measureHeaderActions` — computed fill/ink/box + `hideMobile`) and
  stored as `profile.chromeActions` so `reconcileHeader` re-applies them.
- **The band list EXCLUDES header/footer chrome.** Elementor renders its
  header/footer templates into the homepage but the REST page body excludes
  them, so counting them made 9 bands where the build renders 7 — and every
  POSITIONAL audit compared band N with band N+2. `allSectionNodes` +
  `collectBands` both skip a band inside `header`/`footer`/`elementor-location-*`.
- **Words may never be lost.** A kind that will not paint a slot cannot be the
  band's kind: `RENDERED_SLOTS` + a <50%-of-source-words check demotes the band
  to its RAW slice (`kind: 'text'` + `html`), and `sectionHasContent` counts
  `section.html` or the demoted band loses its measured box.

## Content, colour and pixel parity gates (2026-11-09)

Three gates close the "words went missing / the colours never applied / the
render drifted" blind spot:

- `verify --text` diffs the build's normalised visible text against the
  `analyze --text` baseline (3-word w-shingles; **coverage of the source** is
  the gate — added copy never fails a rebuild).
- `verify --colors` diffs the COMPUTED background+text per region — chrome AND
  every band — with CIEDE2000 (warn ≥ 3, fail ≥ 6); transparent skipped. A
  band's gradient is a third metric (absent fails, differing warns). INK is a
  PALETTE weighted by rendered glyph area; every ink ≥ 15% of a region's text is
  gated, so a rebuild cannot lose a minority colour. `colors <profile> --write`
  re-records a baseline. See `reference/colour-gate.md`.
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

The phase-by-phase SHIPPED status moved to `reference/phase-roadmap.md` on
2026-11-10 when this skill hit Zed's load limit; operational rules stay below.

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

Generated sites use `script-src 'self' 'unsafe-inline'` and the template ships
**no** `ClientRouter` and **no** `client:*` islands — that pair is why the
velocity CSP outage does not affect forge output; keep both from drifting.
Managed/SSR `routeRules`, the orphaned `dist/client` shadow and the hashed-CSP
trap: `reference/csp-client-navigation.md`.

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
  a pin under `tokens.*` writes an unread `design.tokens` — a silent no-op;
- **Flatsome** ids bands `section_<digits>` (no anchor, sheet never ships): the
  template needs a shim, `domId` promotes the vendor id (no pins), band ids seed
  on STRUCTURE (dynamic copy renumbered them), band facts are PER PAGE
  (`analyze --pages`), and surfaces — opaque gradients, 2-col tiled fills — come
  from the browser: [`reference/flatsome-and-outline-rebuild.md`](reference/flatsome-and-outline-rebuild.md).

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
- **A decoration-only band escapes the 120px strip threshold**: with no heading,
  body, media or control it is a spacer (`contentlessBand()` mirrors it at any
  size — jayainc's `dff3f1a`); a control-only band is NOT decoration.
- **A profile `padding`/`minHeight` pin outranks the measurement** — the point
  of a pin, and how a stale one hides. jayainc pinned
  `sections.fd63522.padding: '0px 0px 100px 0px'` against a source box measured
  at 0; only `verify --geometry` caught it. When a geometry failure names
  `paddingBottom`, read `pins[]` before the renderer.
- `min-height` must be applied **before** the hero early return in `wrapStyle`,
  or the one band a source height pins never gets it.
- **`bandFacts[].elements` paths are POSITIONAL**, measured from the source band
  root, which the rebuild nests inside `.post-body`: emit them anchored
  (`[data-section-id] .post-body > * > <path>`) or the leading `div:nth-child(2)`
  becomes a DESCENDANT selector that paints the band's surface + pinned height
  over unrelated nodes (a hero photo vanished under an 810x900 block). Publish
  `--ds-col-min-h` with the floor too — a centred band wrapper rule re-states
  `min-height: var(--ds-col-min-h, 0)` above it. See
  [`reference/render-side-bands.md`](reference/render-side-bands.md) §9.
- Rows are a **12-column GRID**, not equal-share flex: `grid-column: span N` from
  `vc_col-{lg,md}-N` with `[class~=…]` (whole token). Carousels stay
  `flex-wrap: nowrap` + `flex: 1 1 0`, or 7 logos wrap onto a second row.
- `verify --geometry` must serve `dist/client` for a server output — serving
  `dist` 404s and reports every band as "build missing".

## Measured band typography + the sticky header (2026-11-10)

Moved to [`reference/measured-band-typography-sticky.md`](reference/measured-band-typography-sticky.md)
(content unchanged; split out 2026-11-26 to keep this file under Zed
load limit).

## Interaction states — hover, focus, press (2026-11-11)

A rebuilt band kept the source's markup and lost the source's STATES: the CTA
that darkened on hover, the button that pressed in, the field that lit up on
focus. `analyze --geometry` now measures them by forcing each pseudo-class in a
real browser (CDP `CSS.forcePseudoState`, the API behind DevTools' `:hov` panel)
and diffing the computed style. Full contract, standards and gotchas:
[`reference/interaction-states.md`](reference/interaction-states.md).

- **State set:** `hover`, `focus`, `focus-visible`, `active` (forced as
  `hover + active` — a source writes `:hover:active`).
- **Rest paint is part of the fact.** The delta alone painted white ink onto a
  light band, because the source's red pill lived in a vendor class the rebuild
  never ships. `base` re-states the measured rest look (ink, background, borders,
  radius, weight); `font-size`/`padding` stay with the band facts.
- **A control can delegate its state to a CHILD.** Auxin buttons paint their
  hover with `.aux-overlay` scaling from 0 to 1 — the anchor never changes. The
  pass diffs direct children while the parent is forced and records the child's
  rest box/`::after` fill in RELATIVE units (percent of the control, decomposed
  transform), so the overlay survives a reflow. A child with nothing to paint is
  counted, not faked.
- **`hover`/`active` are emitted inside `@media (hover: hover)`** — a touch device
  keeps a synthesised hover forever otherwise.
- **A state that REMOVES the focus ring is dropped** (WCAG F78), and a decoration
  colour with no decoration line is dropped too.
- **The source's easing is `prefers-reduced-motion: no-preference` only.**
- **Every site gets a focus baseline:**
  `:focus-visible { outline: max(2px, .12em) solid currentColor; outline-offset: 2px }`.
  `currentColor` is the trick — the control's own ink already meets contrast (a
  fixed accent ring measured 2.79:1 on virtuallyjamaica's light surface). The
  template's own `.ds-button` also darkens/presses, because a semantic CTA
  replaces the source's markup and inherits none of its states.
- **`bandFacts[id].interactions[]`** → `sections.generated.ts` (`selector` baked
  at scaffold time) → band-scoped `[data-section-id] …:hover` rules.
  virtuallyjamaica: 13 states on 5 bands, ~5 s; the CTA's rest/hover/press now
  match the source exactly.
- **`verify --interactions` gates it** (addressable: `profile.verify.gates` may
  downgrade it to `advisory`). It compares state PRESENCE, never declaration
  strings — the template legitimately adds a ring the source never had, and a
  re-rendered band (the contact form) keeps its behaviour on different classes.
  A lost `:hover` fails the run and names the control; a control the source
  generated in JS declares no state and is outside the contract.
- **Content blocks carry states too** (2026-11-12): a feature box, card or tile
  is a plain `div` and was never a candidate, so virtuallyjamaica's
  `.service-sub` (black on hover) rebuilt flat. Candidates now include
  structural content blocks (controls first in the per-band budget), a block
  earns a fact only from a REAL state, and non-focusable blocks are exempt from
  the focus gate (`focusable: false`). An appear animation is not a hover — the
  animation killer runs before the rest read and identity `transform`/`opacity:1`
  deltas are dropped. Detail: `reference/interaction-states.md` §10.

## Measured heading LEVEL, stage and split (2026-11-12)

Four more measured facts (detail:
[`reference/proposal-122-fidelity-round.md`](reference/proposal-122-fidelity-round.md) §2):

- **`type.titleLevel`** — the tag of the band's largest heading.
  `<SectionTitle>` renders it, so an `<h3>`-only band stops becoming `<h2>`;
  `h1` maps down to `h2` (the article owns the single h1).
- **`fullViewport`** — exactly the viewport height on EVERY capture breakpoint
  → `min-height: 100vh` + `ds-section-wrap--full` (never the captured px).
- **`centered`** — the band CONTAINS the source's centring device (`table-cell`
  + `vertical-align: middle`, or a flex/grid container ≥½ the band with centred
  items). `true` → `ds-section-wrap--centered`; `false` → `--ds-hero-justify:
  flex-start`. A full-height band is NOT automatically centred (a 151px drift
  on virtuallyjamaica's contact band).
- **`splitColumns`** → `--ds-split` (+ `--ds-split-gap: 0`) for the structural
  hero track; the guessed `1.15fr/0.95fr` wrapped a 3-line H1 to four lines.
  Side-by-side means adjacent RIGHT edges, not equal tops (the columns centre).
- **A centred title is never narrowed by the hero's 66% cap**: `--ds-title-max:
  none` when `align: center` with no measured max-width, and `margin-inline:
  auto` rides the centred type rule (otherwise the text sat ~200px off-centre).
- **A section `lineHeight` fact is one value for every breakpoint.** It reaches
  the DOM as a single inline `--ds-title-lh`, while the title's size comes from
  a `clamp()`, so a px leading measured on desktop also applies at phone
  widths — jayainc's 80px read fine under the 72px headline (1.11) but floated
  the two lines of a 29.65px phone headline apart (2.70). Measure the leading
  per breakpoint and scope the fix in the site's `global.css` (phones 45px, the
  tablet band relative, desktop the pinned px); the profile pin carries the
  desktop fact only.

## Cross-app parity gate (forge ↔ velocity, 2026-11-09)

The two Astro products share a CONTRACT but not code, so neither suite can see
the other tree. `bin/check-astro-app-parity.mjs` (CI:
`.github/workflows/astro-app-parity.yml`, triggered by EITHER app) is the
record — read it as the spec. Exit 0 = parity, 1 = drift, 2 = cannot verify. It pins:

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

Moved to [`reference/media-localization.md`](reference/media-localization.md) — content unchanged
(split out 2026-11-17 to keep this file under Zed's load limit).

## Tiny brochure sites — the raw-band hand layer (2026-11-20)

A four-page film-production site (one 350px wordmark stage, one 650px stage, a
white film page with a full-measure still and two credit columns) exposed a
third path beside "design renderer" and "hand-built holding page": **the
forge pipeline + a hand-owned page layer**, locked and regenerable-in-part.
The design renderer classified its hero as a CTA and demoted the `h1` to `h2`;
that is the classifier being honest about a layout that carries no sections.

- **Keep the pipeline, own the composition.** Content (`wp/v2` loader),
sanitizer, SEO head/graph, sitemap, media localization and the chrome facts
stay generated; the page composition becomes a site stylesheet + a small
`src/lib/<site>.ts` page table (layout kind, and the exact `<head>`
title/description the source's SEO plugin keeps out of REST). Then `forge lock`.
- **Render the sanitized body RAW** inside a measured stage. The sanitizer
keeps `elementor-element-<id>` classes, so the band id is recovered from the
class and re-attached with its surface facts by the page route — never
re-derived by hand.
- **The band gates addressed a `<div>` wrapper; a raw band is a `<section>`.**
`builtBandBlocks` (band-audit) and `builtBandsOf` (design/band flow) matched
`<div` only, so every raw band reported "missing from the build" while its
geometry and colour both passed. Both accept any element carrying
`data-section-id`.
- **A band must STATE its facts.** `data-section-id`, the advisory
`data-section` kind, and `background`/`padding`/`--ds-measure` inline are what
band flow, the per-band audit and the fact audit read; a stylesheet-only
surface reads as "background none". `SectionTheme` carries `kind` so the
scaffold can emit it, and the page route merges the facts into the source's tag
(sanitisation dropped them).
- **A single inline `padding` lies when the source re-pads at a breakpoint**
(the stage is flush on desktop, 25px under 768px). Leave that page's profile
section `padding` unset and keep the per-device value in CSS.
- **Nav links need separating whitespace.** `<a>A</a><a>B</a>` extracts as
`AB`: text parity then reports `projectscontact` and loses every 3-gram across
the nav (68.8% coverage on a page whose copy was byte-identical). `Base.astro`
renders the links with a literal space between them (a whitespace-only flex
item is suppressed by the spec, so the layout is unchanged).
- **`title.rendered` and `excerpt.rendered` are HTML.** They arrive
entity-encoded and the template uses both as TEXT sinks — `<title>` rendered
`We Didn&#8217;t` and `<meta name="description">` shipped a literal `<p>`.
`plainText()` in `wp-loader.ts` decodes the title and derives a plain-text
`seoDescription` when the source has no SEO plugin fields in REST.
- **A body copy difference of one NBSP changes the wrap.** The source's footer
is `Productions.&nbsp; All rights reserved`; with two plain spaces the line
fits at 390px and the footer is 121px instead of 148px. Copy source strings
verbatim — normalised text hides this.
- **The source's computed header colour can be a lie.** This theme's
`<header>` computes `#ffffff` and a child layer paints the black band over it,
so `analyze --colors` records white for a header that renders black. Verify
with a screenshot/pixel sample and pin the PAINTED value. Reproducing the
white-then-cover stack to satisfy the raw computed value was tried and
reverted: the covering pseudo-element is what the gate reads, so it reports
black anyway, and it did not change the nav's antialiasing.
- **Pixel parity that is not zero is still a pass** when it is explained: the
WebP re-encode of a hero still measured mean 2.8/255 channel delta (p99 = 9,
max 25) and the source's grayscale nav AA costs 0.07% of the desktop page.
Record the source screenshots (`--pixel-record-source`) and set a site
threshold with the reason, rather than dropping the gate.
- **A very small site can pass 18/19 gates.** The remaining advisory is class
coverage — a raw band keeps the vendor's class names without restating its
stylesheet, which is the point of the path.

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
- Interaction states (hover / focus / press):
  [`reference/interaction-states.md`](reference/interaction-states.md) — the
  CDP forced-pseudo-state mechanism, the rest-paint rule, the WCAG 2.4.13 /
  1.4.11 focus baseline and the measured virtuallyjamaica numbers.
- Form bridge P7 (render + proxy halves):
  [`reference/form-bridge.md`](reference/form-bridge.md) — the sanitize
  allowlist, the WordPress proxy half and the direct-mode boundary.
- Chrome typography (Proposal 123):
  [`reference/chrome-typography.md`](reference/chrome-typography.md) — the
  shared cascade-resolver ladder, terminalFill/bgForCss and the measured
  nav/call/footer type facts.
- Media localization (Proposal 139):
  [`reference/media-localization.md`](reference/media-localization.md) —
  localizing everything `<Image>` cannot see (body `<img>`, chrome
  logos/favicon).
- Builder-4 parity gotchas (a gated Elementor 4 rebuild, 2026-11-24):
  [`reference/builder-4-parity-gotchas.md`](reference/builder-4-parity-gotchas.md)
  — hash ids are not CSS identifiers, `--ds-measure` is the content column,
  a state delta must outrank the positional fact, and a query-loop band is
  data rather than design.
- Publication gate (Proposal 165, the managed tier):
  [`reference/proposal-165-publication-gate.md`](reference/proposal-165-publication-gate.md)
  — the two gate modules, `honor`, the session HMAC recipe and the
  directory-index 301.
- Manage sign-in (hardened 2026-11-26):
  [`reference/manage-sign-in.md`](reference/manage-sign-in.md) — the three
  doors, five `?error=` codes, key provisioning and the proxy-Origin trap.
- Split out verbatim on 2026-11-26 to keep this file under Zed's load limit:
  [`reference/shared-mirror-deploy.md`](reference/shared-mirror-deploy.md) and
  [`reference/measured-band-typography-sticky.md`](reference/measured-band-typography-sticky.md).

## Email delivery

`forms.delivery`: `bridge` (WP owns the mailer) or `direct` (managed +
Brevo/Mailjet API). SPF/DKIM/DMARC decide inbox placement. Notes:
`reference/email-delivery.md` (now also: provider IP allowlists, secret
hygiene, and why the throttle is proxy-wide). CMS removed, cutover, or
rebuilding with no WordPress: `reference/cms-removal-materialized-content.md`
(route parity for posts, the no-CMS image-host rule, snapshot freeze order).

## Proposal 130 — consent-exempt cookieless beacon (2026-11-19)

`cloudflare-web-analytics` was already in the detector's fingerprint table, but
the template gated **every** vendor behind a stored consent decision, so a
declared beacon could never fire. The fix is a per-tracker flag, never a blanket
rule: `trackers[].consentExempt: true` (guarded by `isCookielessVendor()`) makes
`renderTrackingGenerated` emit `cookieless` + `consentExempt` on the vendor row,
and `Tracking.astro` renders that vendor as a **static**
`<script type="module" … data-cf-beacon>` plus a preconnect — outside the
consent loader, so an exempt-only site ships zero banner machinery (verified: 0
`nvoos-tracking-loader` nodes, 8/8 pages carrying the tag).

Rules that matter:

- **The exemption is explicit and operator-declared.** `lib/tracking.ts` still
  treats cookieless analytics as consent-gated by default; the CNIL/EDPB
  audience-measurement frame (no cookie or device storage, publisher-only, no
  cross-site) is narrow, so it is a decision, never an inference. The README
  reports `consentExempt` from the manifest, so it cannot mislabel an exempt
  beacon as "consent-gated".
- **A cookie-setting vendor can never be exempt.** The generator drops the flag,
  so nothing downstream re-enables it; a test pins this.
- **`spa` is pinned false.** The beacon's default `spa: true` patches
  `History.pushState` and uses Soft Navigations; a static MPA gains nothing and
  would double-count if a client router were added later. Set
  `tracking.spa: true` only for a ClientRouter/SSR site.
- **One snippet per page.** Cloudflare's edge (automatic) injection plus a
  manual beacon double-counts — pick one. Automatic injection is skipped
  entirely when the origin sends `Cache-Control: public, no-transform`.
- **Manual beacons work on any hostname** (they POST to
  `cloudflareinsights.com/cdn-cgi/rum`), so a staging URL collects data before
  the DNS cutover; automatic injection reports to the site's own `/cdn-cgi/rum`
  and needs traffic through the zone.
- **GPC is deliberately not consulted** for the beacon: it reports timing
  metrics only, and a static tag cannot be un-loaded without losing its early
  start. Disable the tracker if an operator wants the signal honoured.
- **The scanner keeps the whole `<script src>` opening tag** (`scriptTags`,
  rebuilt from DECODED attributes). A `src`-only capture cannot see
  `data-cf-beacon`, and SSR frameworks escape it as `{&quot;token&quot;:…}` —
  that decoding is what makes a forged site's own beacon round-trip through
  `analyzeTracking` with the token intact.
- **CSP:** `script-src https://static.cloudflareinsights.com` +
  `connect-src https://cloudflareinsights.com`.
- **Arming:** `"enabled": true` and `"ids": { "token": "<site tag>" }`. A site
  tag is 32 lowercase hex; the extractor accepts 16–64 and reads both the
  `data-cf-beacon` attribute and the `?token=` query-string form.
