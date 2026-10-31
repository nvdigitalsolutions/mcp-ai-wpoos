---
name: mcp-ai-wpoos-astro-forge
description: Operational guide for the NV oOS Astro Forge (Proposal 096) — the WP→Astro frontend generator in examples/nvoos-astro-forge (wp-client, Astro content-layer loader, versioned sanitizer, scaffold CLI, blog template, hermetic WP wire fixture, CI exit gate). Use when forging a customer frontend, adding a template or collection, debugging the e2e gate, or continuing Phases 1–4 (wc/v3 Woo loader, site_analyze, tokens_apply, MCP tool surface, bridge plugin).
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
| `lib/scaffold.ts` | Template copy, FORGE-marker rewrite, manifest, gates |
| `scripts/forge.mjs` | CLI (Node ≥ 22.6 — native TS type stripping, zero build step) |
| `scripts/wp-fixture-server.mjs` | Hermetic wp/v2 wire fixture — the Phase 0 CI stand-in for WordPress Playground |
| `scripts/builtin-template.mjs` | Test fixture generator (nested `main/templates/blog/` tree with a REAL loader) |
| `templates/blog/` | The production template (posts/pages, canonical + JSON-LD, sitemap/robots, `--forge-*` tokens) |
| `tests/` | `wp-client` / `sanitize` / `wp-loader` / `scaffold` unit suites + `forge-e2e.test.ts` (exit gate) |
| `.github/workflows/astro-forge.yml` | CI: typecheck + unit + e2e on Node 22 |

## Dev loop (hermetic — no live site needed)

```sh
cd examples/nvoos-astro-forge
npm install
npm test                 # 38 unit tests (client, sanitizer, loader, scaffold)
npm run typecheck
npm run test:e2e         # exit gate: fixture WP → scaffold → npm install → astro check + build → HTML asserts (~40s, registry access for the generated project's install)
node scripts/wp-fixture-server.mjs   # :8473 — real wp/v2 wire shapes
node scripts/forge.mjs site.json --target ./out --check   # live source check (root doc + per-collection size-vs-cap)
node scripts/forge.mjs site.json --target ./out           # scaffold the project
```

## Forging a customer site (the standard loop)

1. Write `site.json` (operator allowlist): `{ url, name, collections: [{ slug, restBase, fields, embed, perPage, maxPages }] }`.
2. `--check` first — a build must never silently truncate.
3. Scaffold → `cd <target> && npm install && npm run build` with `ASTRO_SITE` set.
4. Design language (098 `design_theme` output) applies over the template's `--forge-*` tokens in `src/styles/global.css` — content is re-themed, never re-cloned.

## The exit gate (CI-enforced)

`tests/forge-e2e.test.ts` boots the fixture server on an ephemeral port,
scaffolds the **production** template, `npm install`s the generated project,
runs `astro check` + `astro build`, then asserts on `dist/`: posts/pages
rendered, `alert`/`onerror`/`javascript:` count = 0 in the xss-trap post,
allowlisted embeds survive, canonical + `BlogPosting` JSON-LD + sitemap/robots
wired. The workflow runs on `paths: examples/nvoos-astro-forge/**`.

## Gotchas (each cost a fix 2026-10-31 — do not re-hit)

- **Astro 7 spread params are flat slash-joined strings, NOT arrays** —
  `getStaticPaths` for `src/pages/posts/[...parts].astro` must return
  `params: { parts: '1/slug' }`. The route generator uses the params value as
  the path segment directly and the validator requires `typeof value === 'string'`
  (verified against astro 7.4.4 dist sources: `routing/generator.js`,
  `routing/internal/validation.js`, `render/params-and-props.js`). An array
  fails the build with `GetStaticPathsInvalidRouteParam`.
- **FORGE marker rewriting must be line-anchored** — naive
  `indexOf('// FORGE:site')` matches prose that mentions the marker inside a
  header comment and mangles `content.config.ts`. Match
  `line.trim() === '// FORGE:site'` only.
- **Template-copy filters must exclude by directory basename** — a
  `/(node_modules|dist|\.astro)$/` path regex also matches every `*.astro`
  page file (the `\.astro$` branch) and silently drops all pages. Filter on
  `path.basename(src)` ∈ {node_modules, dist, .astro}.
- **Emitting a regex literal through a JS template literal needs the
  backslash doubled** — `\\/+$/` in the scaffold source (an identity escape
  otherwise emits `//`, turning the generated line into a comment and
  breaking the parse).
- **Truncation math:** `truncated = page === maxPages && page < totalPages` —
  a catalog that ends exactly at the cap is complete, not truncated.
- **`sanitize-html` `allowedIframeHostnames` strips the disallowed `src` but
  keeps the inert `<iframe>` tag** — assert on `src` absence, not tag absence.
- **Node ≥ 22.6 native type stripping runs the CLI with zero build step** —
  `scripts/forge.mjs` imports the `.ts` libs directly. Requirements: explicit
  `.ts` extension specifiers in relative imports, erasable-only TS (no enums/
  namespaces), `allowImportingTsExtensions: true` + `noEmit` in the tsconfig.
  The generated template inherits `allowImportingTsExtensions` from
  `astro/tsconfigs/strict` — vendored `.ts`-extension imports typecheck as-is.
- **`astro check` on a generated project needs `@types/sanitize-html` and
  `@types/node` in the template devDeps** — the vendored `sanitize.ts` and
  `wp-client.ts` typecheck under the language server.
- **`--overwrite` re-scaffold is destructive** — it `rm`s the target
  including `node_modules` (reinstall after). An empty target dir is safe to
  scaffold into; a non-empty non-forge dir is refused without `overwrite: true`.
- **Woo sets `price` to the sale price while on sale** — sale state is
  `salePrice !== regularPrice`, never `salePrice !== price` (the first cut
  rendered no strike-throughs at all). wc/v3 needs consumer keys (401
  without); wp/v2 product CPTs stay public but have no commerce fields —
  both shapes share one loader via `kind: 'product'`.
- **`_embed` does not guarantee media** — resolve `featured_media` IDs via
  `wp/v2/media/{id}?_fields=source_url` with bounded concurrency (5); an
  image miss is never a build failure.
- **Template pages binding collections must be scaffold-pruned** when the
  profile omits the collection, and every remaining consumer (the sitemap
  endpoint) gates on `nvoos.json` — an undefined `getCollection()` fails
  the build.
- **The WP static front page** maps to profile `frontPage` — rendered at
  `/`, skipped by the slug route + sitemap (one canonical URL).
- **Astro ignores underscore-prefixed files in `src/pages/`** (the partial
  convention) — `_redirects.ts` never becomes a route. Emit
  `/redirects.json` and materialize `dist/_redirects` post-build.
- **Token rewrites must keep their FORGE markers** and cover every token
  the template block defines (layout tokens included) — see the
  `/* FORGE:tokens */` span contract below.
- **The scaffold CLI is operator-run and has no rate limiting** — rate
  limiting/retry belongs on the future MCP surface, not the CLI.

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
- Keep the customer's name and URL out of skills and generated code — the
  design tokens alone are the deliverable (the 098 recreation loop is
  concept-adaptation, never cloning).

## Phase roadmap (096 §5)

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
- **Phase 1c (remaining):** search (Pagefind), full `site_analyze` discovery
  (CPTs/SEO plugin/Woo/REST lockdown), media pipeline (Astro Image).
- **Phase 2:** `nvoos-astro-bridge` plugin — HMAC-fail-closed rebuild
  webhook, preview tokens, wp.org checklist (see `mcp-ai-wpoos-wporg-submission`).
- **Phase 3–4:** publishing + multi-site, wizard + packaging (octokit deploy,
  vault-backed credentials).

## Repo conventions

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
  ADS the template borrows from)
- Related skills: `mcp-ai-wpoos-astro-storefront` (the serving side — bridge,
  /mcp, tokens), `mcp-ai-wpoos-playground-demos` (the Phase 2 Playground
  fixture replacement), `mcp-ai-wpoos-git-ops`.
