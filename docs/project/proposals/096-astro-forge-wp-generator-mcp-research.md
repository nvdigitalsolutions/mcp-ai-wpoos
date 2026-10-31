# Proposal 096 — Research Notes: MCP-Driven WP→Astro Generation (Verified 2026-10-30)

Companion to `096-astro-forge-wp-generator-mcp-proposal.md` §2. All sources verified live 2026-10-30 (fetched, not recalled), except repo-internal references (verified earlier in this session).

---

## 0. Phase 0 build research (verified 2026-10-31)

Gap-filling pass run before implementing the Phase 0 spike (`examples/nvoos-astro-forge/`). Load-bearing findings:

- **Astro 7 loader contract** ([content-loader-reference](https://docs.astro.build/en/reference/content-loader-reference/) + [content-layer-deep-dive](https://astro.build/blog/content-layer-deep-dive/)): a loader is `{ name, load({ store, logger, parseData, generateDigest }) }`; entries land via `store.set({ id, data, digest, rendered: { html } })`; **the digest must be a string that changes when the data changes** — `generateDigest(updated_gmt)` is the canonical pattern (whoooop.co.uk's CMS loader + pawcode.de's PocketBase loader both do exactly this); loaders run in parallel at build/dev; `glob()`/`file()` are the built-ins and custom loaders are first-class. The forge loader is therefore a *digest-disciplined paginated REST reader*, nothing more.
- **Woo auth contract unchanged** ([woocommerce.github.io v3 docs](https://woocommerce.github.io/woocommerce-rest-api-docs/v3.html)): `wc/v3` still uses HTTP Basic with consumer key/secret; products paginate via `per_page`/`page` with `category` etc. filters. The forge client needs one auth seam: basic (Woo) vs none (public wp/v2) vs application-password bearer for private content.
- **XSS at the boundary is the settled standard** ([XSS in Astro: set:html advisory](https://vibeappscanner.com/vulnerability-in/xss-astro)): "Use sanitize-html or DOMPurify before passing content to set:html"; wp-headless-kit's `sanitizeHtml(html, { allowTags?, allowAttrs? })` allowlist shape and the djmixoftheweek PR (allowlist wide enough for headings/images/lists/tables, scripts + event handlers + `javascript:` URLs stripped) are the reference implementations. The forge sanitizer ships a versioned allowlist, never a pass-through.
- **SEO = URL-level canonical preservation** ([WPPoland headless SEO patterns](https://wppoland.com/en/seo-patterns-for-headless-wordpress/), [gautamkhorana WP→Astro migration](https://gautamkhorana.com/blog/wordpress-to-astro-migration-seo/)): preserve canonical URLs at the URL level (not the slug), 301-redirect anything that changes with no chains longer than one hop, render meta/JSON-LD server-side, and keep internal links, canonicals, sitemap entries, and redirects pointing at the same URL form. This lands as the template's sitemap/robots/canonical discipline, not a bolt-on.

**Phase 0 implementation learnings (verified in code 2026-10-31, astro 7.4.4):**

- **Astro 7 spread params are flat slash-joined strings, not arrays.**
  `getStaticPaths` for `src/pages/posts/[...parts].astro` must return
  `params: { parts: '1/slug' }` — the route generator uses the params value
  as the path segment directly (`dist/core/routing/generator.js`
  `getParameter`) and the validator requires `typeof value === 'string'`
  (`dist/core/routing/internal/validation.js`). An array value fails the
  build with `GetStaticPathsInvalidRouteParam`.
- **`astro check` on a generated project needs `@types/sanitize-html` and
  `@types/node` in the template devDeps** (the vendored `sanitize.ts` and
  `wp-client.ts` typecheck under the language server). Astro's base tsconfig
  already sets `allowImportingTsExtensions` + bundler resolution, so vendored
  `.ts`-extension imports need no extra flags.
- **Template rewriting must be line-anchored.** Naive `indexOf('// FORGE:site')`
  matches prose that mentions the marker inside a header comment and mangles
  the config. Match marker lines with `trim() === '// FORGE:site'` only.
- **Template-copy filters must exclude by directory basename.** A
  `/(node_modules|dist|\.astro)$/` path regex also matches every `*.astro`
  page file (the `\.astro$` branch) — the scaffold silently dropped all
  pages. Filter on `path.basename(src)` ∈ {node_modules, dist, .astro}.
- **Emitting a regex literal through a JS template literal needs the
  backslash doubled** (`\/+$/` — otherwise `\/` is an identity escape that
  emits `//`, turning the emitted line into a comment and breaking the parse).
- **Node ≥ 22.6 native type stripping runs the CLI with zero build step** —
  `scripts/forge.mjs` imports the `.ts` libs directly, provided relative
  imports use explicit `.ts` extensions and the TS is erasable-only.
- **Truncation must compare page-with-remaining, not page-count:**
  `truncated = page === maxPages && page < totalPages` — a catalog that ends
  exactly at the cap is complete, not truncated.
- **`sanitize-html` `allowedIframeHostnames` strips the disallowed `src` but
  keeps the inert `<iframe>` tag** — assert on `src` absence, not tag absence.
- **The Phase 0 CI fixture is the hermetic wp/v2 wire server**
  (`scripts/wp-fixture-server.mjs` — JSON bodies + `X-WP-Total`/
  `X-WP-TotalPages`, `_fields`/`_embed` honoured), standing in for the
  WordPress Playground fixture until the bridge-plugin phase (096 §4).

---

## 1. Competitor & ecosystem survey

### 1.1 `vapvarun/wp-astro-mcp` — the only real WP+Astro+MCP generator

GitHub search `wordpress+astro+mcp` (2026-10-30): 5 total results; 3 are irrelevant (a landing page for a WordPress Abilities-MCP plugin, `corsen-context` — a read-only content MCP with a WP plugin, and a portfolio). `wp-astro-mcp` (12★, MIT, TypeScript, 113 tests, 57 tools, pushed 2026-09-13) is the only genuine generator. Full README reviewed. Architecture in one line: **extract → convert (HTML→Markdown, 13 steps) → materialize files → SQLite state machine → sync by `modified_gmt` → git/GitHub publish → Vercel/Netlify/Cloudflare configs**, plus an optional `wp-astro-bridge` WordPress plugin (HMAC webhooks, single-use draft-preview tokens, normalized SEO REST field, health endpoint).

Weaknesses we verified from its own documentation (our proposal fixes each):
1. **Plaintext credentials** — `config/sites.json` stores application passwords + a GitHub PAT unencrypted ("no encryption layer; the file is the credential store").
2. **No SSRF guard** — "There is no allowlist; whatever base URL you configure is fetched" (its own security section).
3. **Git argv token exposure** — during `github_push` the PAT "may briefly appear in the push process's argv (visible via `ps`)".
4. **Layout loss on page builders** — its own FAQ: Elementor/WPBakery/Divi content "will lose their visual structure … you get the content, not the layout."
5. **OOM at scale** — ≥500 Markdown files OOM the Astro build; the fix is a JSON escape hatch (a workaround for the materialization model itself).
6. **Astro 6** target — a full major behind the Astro 7 storefront we already operate.

Strengths worth adopting: capability auto-detection (`site_add`/`site_analyze`), `_fields`/`_embed`-aware extraction, content audit tooling, the bridge-plugin feature set (webhooks/preview/SEO field/health), setup-wizard UX, router-vs-full tool modes, single-source-of-truth Zod registry with a CI coverage test, structured output, per-site token-bucket rate limiting.

### 1.2 Adjacent signals

- **`RegionallyFamous/dashless`** (1★, PHP, "WordPress without the dashboard: conversational publishing through local Codex, with an owned Astro frontend and first-class WP Cloud deployment") — confirms the product direction (assistant-driven WP + owned Astro frontend) is being pursued independently.
- **`starwind-ui`** (759★) — the component-library option wp-astro-mcp's scaffolder uses; for us, 092's own components are the donor library (no new dependency).
- **No reusable "Astro WP content loader" npm package exists** (GitHub search `astro+wordpress+loader`: 2 irrelevant results). The loader is greenfield — built once, reusable (→ `packages/` extraction later, per the 065 npm-packages precedent).

## 2. Astro's official position (the standards anchor)

The official [Headless WordPress & Astro guide](https://docs.astro.build/en/guides/cms/wordpress/) (re-verified 2026-10-30) prescribes:

- **REST-first**: `/wp-json/wp/v2/` is the default ("available by default with any WordPress site"); WPGraphQL/Gato GraphQL are optional. Consistent with 092 §2.2's decision.
- **Field pruning + embedding**: use `_fields` to restrict responses and `_embed` for related resources (featured media, authors, terms) — the standard anti-N+1 discipline; our loader implements both.
- **Render HTML, don't convert**: the guide's examples use `set:html={post.content.rendered}` — WordPress HTML is the rendering format. This is the industry-standard alternative to wp-astro-mcp's Markdown conversion and is the reason the 096 loader preserves page-builder layouts.
- **Prerender via `getStaticPaths`**; SSR as the dynamic option. The Content Layer API is the Astro-5+ mechanism for both; the guide's own community-resources list links "Shattering Headless WordPress Build Times with Astro's Content Layer API" and "Your client clicks Publish. Then what? Automatic rebuilds for headless WordPress and Astro" — i.e. the two community articles that describe exactly the loader + webhook-rebuild architecture 096 adopts.
- **Public REST is read-only by default** ("does not allow users to modify your data"); authentication is opt-in — the trust model for read loaders.

## 3. The loader-first architecture (decision basis)

| Concern | Materialize (wp-astro-mcp) | Content Layer loader (096) |
|---|---|---|
| Content copies vs CMS | Files copied; sync engine reconciles drift | WordPress stays the single source of truth |
| Page-builder layouts | Lost (their FAQ) | Preserved (sanitized HTML render) |
| Scale | OOM ≥500 MD files → JSON workaround | Loader + Astro content cache; no file explosion |
| Sync complexity | SQLite jobs, resumable exports, change detection | Webhook → rebuild (the repo's shipped funiq-bridge pattern) or SSR |
| Repo hygiene | Content churn in git history | Repos hold code + templates only |
| Offline/archival | Inherent | Opt-in `content_materialize` export |

Adopted: B (loader), with A kept as an opt-in escape hatch — this is the *superset* of wp-astro-mcp's capability with a fraction of the state machinery, and it matches the direction of the Astro docs' own community references.

## 4. Security standards applied (industry + repo)

- **Application passwords**: WordPress-native, per-user scoped, revocable — the Elementor MCP / WordPress MCP Adapter guidance (already encoded in the `design-elementor-mcp-connection` skill).
- **No plaintext credential stores**: the repo's Vault discipline (encrypted at rest, masked in UI/logs); 096's `vault.ts` is the MCP-side equivalent, directly remediating wp-astro-mcp weakness #1.
- **SSRF guard before fetch**: `is_url_allowed` pattern (repo standard) — remediates wp-astro-mcp weakness #2.
- **No subprocess secrets**: octokit over `git` CLI — remediates weakness #3.
- **XSS at the content boundary**: `sanitize-html` (already a dependency of `examples/nvoos-astro-velocity`) with a versioned allowlist — the DOMPurify discipline wp-astro-mcp uses, on the library we already ship.
- **Bridge plugin wp.org discipline**: nonce/cap checks, output escaping, HMAC fail-closed, single-use + capability-checked preview tokens, redacted health endpoint — per the `mcp-ai-wpoos-wporg-submission` skill and the wp-astro-bridge security model (which we re-implement, not copy).

## 5. MCP craft (carried from 095)

- SDK v2 (`@modelcontextprotocol/server`), protocol 2026-07-28 (matches the NV oOS MCP Apps client) — verified in the 095 research session.
- Tool annotations (read-only/destructive/open-world) — the MCP spec's own tool-annotation feature; wp-astro-mcp demonstrates them in production.
- Single-source-of-truth registry (Zod schemas drive validation AND discovery; CI coverage test) — wp-astro-mcp's `registry.ts` pattern, adopted in 095 §8 and here.
- `structuredContent` + text blocks for machine-readable results.
- Router mode (small promoted tool list) vs full mode — context-budget discipline for LLM clients.

## 6. Repo assets this builds on (verified locations)

| Asset | Role |
|---|---|
| `examples/nvoos-astro-velocity/` | Template donor: Astro 7 config, product components, cart/gallery islands, image pipeline, `resolveBase` env pattern |
| `addons/funiq-bridge/includes/RebuildWebhook.php` | The debounced webhook → `repository_dispatch` → rebuild pattern, generalized to posts/pages |
| `.github/workflows/sync-nvoos-astro-velocity.yml` | The mirror deploy recipe, parameterized per client for `deploy_config` (Velocity) |
| 095 output (`src/tokens/`, ADS generator) | Token layer every generated site ships; `tokens_apply` rebrand primitive |
| Proposal 054 / 061 | npx packaging + MCP registry publishing rails for Forge itself |
| `mcp-ai-wpoos-playground-demos` skill | WordPress Playground blueprints = the hermetic CI fixture + the public demo |
| `mcp-ai-wpoos-wporg-submission` skill | Bridge plugin gate (plugin-check, escaping, readme) |

## 7. Corrections / clarifications to prior repo statements

- **092 Phase 4** (`nvoos-astro-starter` as a static template) is superseded by 096: the blog template inside Forge *is* the starter, now MCP-driven, loader-based, and brand-themed at scaffold time (owner decision Q2).
- **092 §2.5 / 095 research** (Astro's official MCP = docs-only): Forge is the repo's own *generation* MCP — the third node in the ecosystem picture: Astro Docs MCP (knowledge) + Forge (generation) + 095's `nvoos-astro-mcp` (runtime ops).
