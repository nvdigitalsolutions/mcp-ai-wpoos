---
type: Skill
name: mcp-ai-wpoos-content-graph-spa
description: Operational guide for the NV oOS Content Graph ↔ Pro SPA surface — the #/knowledge-graph page, the vendored explorer sync discipline, the nvoos-content-graph/v1 REST + bearer-auth contract, the visual-config headless route, and the content-graph CI workflows. Use when updating the graph page in spa-v2 or the standalone app, re-syncing the vendored explorer from the plugin, extending the plugin's REST/auth surface for headless clients, or fixing PHPUnit Content Graph / Build Content Graph Plugin CI failures.
---

# NV oOS Content Graph ↔ SPA Surface

Operational guide for the knowledge-graph page shipped by PR #6998
(2026-10-10). The companion proposal for npm packaging the shared pieces is
`docs/project/proposals/065-content-graph-npm-packages-proposal.md`.

## When to use this skill

- Updating the `#/knowledge-graph` page (spa-v2 or the standalone Vite app)
- Re-syncing the vendored explorer after `plugins/nvoos-content-graph/assets/js/`
  changes upstream
- Adding/extending the plugin's REST routes or auth for headless clients
- Triaging "PHPUnit Content Graph" / "Build Content Graph Plugin" CI failures
- Answering "does the SPA graph page work on site X" / "what auth modes does it need"

Shared mechanics live in the sibling skills: porting/UI/testing gotchas in
`mcp-ai-wpoos-spa-ui` (vendored-upstream section), the plugin's PHPUnit
Docker recipe in `mcp-ai-wpoos-test-suite` (standalone plugin suites section).

## Surface map

| Piece | Location |
|---|---|
| Canonical explorer JS/CSS | `plugins/nvoos-content-graph/assets/js/{content-graph-admin,content-graph-theme,content-graph-icons}.js` + `assets/css/content-graph-admin.css` |
| SPA vendored copies | `addons/pro/assets/spa-v2/src/features/knowledge-graph/upstream/` (byte-identical body + documented wrapper delta) |
| React page | `addons/pro/assets/spa-v2/src/features/knowledge-graph/KnowledgeGraphPage.tsx` (+ `knowledge-graph.css`, `__tests__/`) |
| Route + command | `router.tsx` (`/knowledge-graph`), `useBootstrap.ts` (`nav-knowledge-graph`) |
| Endpoints type | `addons/pro/assets/spa-v2/src/api/config.ts` (`ProSpaEndpoints.contentGraph`) |
| Plugin-side endpoint | `addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php` (admin+non-guest only, `class_exists( 'NvoosContentGraph\Plugin' )` gated) |
| Standalone app endpoint | `examples/nvoos-pro-spa-vite/src/runtime-config.ts` (`buildEndpoints()`) |
| Standalone aliases + shims | `examples/nvoos-pro-spa-vite/vite.config.ts` (jquery/cytoscape/fcose aliases), `src/shims/cytoscape-fcose.d.ts`, `tsconfig.json` paths (`jquery` → `@types/jquery`, `cytoscape` → `@types/cytoscape`) |
| Plugin REST + auth | `plugins/nvoos-content-graph/src/Rest/Controller.php` |
| CI | `.github/workflows/phpunit-content-graph.yml`, `.github/workflows/build-nvoos-content-graph.yml` (plugin-check job) |

## REST + auth contract

Namespace `nvoos-content-graph/v1`. Read routes (`/graph`, `/nodes`,
`/nodes/{id}`, `/edges`, `/search`, `/retrieve`, `/resolve`,
`/graph/visual-config`) accept, via `checkReadPermission()`:

1. logged-in user with `read` (cookie / Application Password),
2. an NV oOS assistant credential — `Authorization: Bearer cred_XXXXX.SECRET`
   or the raw header form, gated by the `wp_mcp_ai_accept_raw_credential_header`
   filter, validated through `\WP_MCP_AI_Credentials::validate_token()`
   (must be GLOBAL-namespace-qualified inside the namespaced controller —
   see test-suite pattern 70), or
3. a base-plugin guest token.

A credential-format token that FAILS validation is a definitive 401 (no
fall-through); requests without a credential fall through to the guest
check. Write routes (`/build`, `/export`, `/sources*`) stay
`manage_options`-only — credentials can never rebuild or mutate. Standalone
installs (no base plugin) are unaffected (`class_exists()` guarded).

`GET /graph/visual-config` (1.1.0+) returns `{ visual, presets, height,
max_nodes }` — the shape the SPA page assembles into
`window.nvoosContentGraphAdmin`. The SPA page shows an unavailable/error
state without it; the version hint in the page text says "1.1.0 or later".

CORS: cross-origin SPA modes need the app origin in `cors_allowed_origins`
AND `/nvoos-content-graph/v1` in `wp_mcp_ai_cors_guard_route_prefixes`
(default scope is `/mcp-ai/v1` only). Cookie mode needs neither.

## Vendored explorer sync discipline

When `plugins/nvoos-content-graph/assets/js/content-graph-admin.js` (or the
theme/icons/CSS) changes upstream:

1. Copy the file(s) into `.../knowledge-graph/upstream/` (overwrite).
2. Re-apply ONLY the documented wrapper delta (listed in the vendored file
   header): IIFE → `export function initNvoosGraphExplorer( $ )`, the
   `destroyed` latch, namespaced `.nvoos-cg` document bindings, and the
   `destroy()` + `return { destroy }` tail. Everything else must stay
   byte-identical — verify with
   `git --no-pager diff --no-index plugins/nvoos-content-graph/assets/js/content-graph-admin.js <spa-v2>/upstream/content-graph-admin.js`.
3. Re-run the spa-v2 test suite (the page tests mock the factory — they
   assert the contract, not the internals) and the standalone build.
   Careful: the monorepo standalone build can stay green while the MIRROR
   build fails — spa-v2 resolves its own `node_modules` in the monorepo,
   but the sync workflow ships `addons/` without it. Every spa-v2 dep needs
   a tsconfig `paths` entry (typed) or a `src/shims/` ambient declaration
   (untyped) in the standalone app; PR #7000 added the sync/deploy
   build-verify gates that catch this in CI.
4. Note the sync in the plugin's CHANGELOG if the change is user-visible.

Adding a NEW id to the explorer markup requires the same id in the page's
JSX toolbar (mirror `SettingsPage.php`), or the delegated document handlers
silently never fire.

## CI notes

- Both content-graph workflows use `shivammathur/setup-php` with
  `tools: wp-cli`, which flakes intermittently ("Could not setup wp-cli")
  without failing the step — each has an "Ensure WP-CLI is available"
  phar-fallback step; keep it when adding steps (see test-suite skill).
- The plugin's own test suites run via the test-suite skill's Docker recipe
  (isolated DB `wordpress_test_nvooscg` pattern); expected sizes ~163 Unit +
  ~27 Integration tests.

## PR state (2026-10-10)

- #6998 `add/content-graph-spa-page` — the page + plugin auth/route + CI
  hardening (this surface's origin PR).
- #6999 `add/standalone-spa-login-rescope` — wp-login proxy re-scoping,
  split off from shared-checkout WIP. If both PRs touch
  `examples/nvoos-pro-spa-vite/README.md`/`vite.config.ts` on merge, keep
  #6999's README wording and #6998's alias block.
- #7000 `add/standalone-spa-graph-typecheck` — mirror-only TS2307 fix
  (jquery/cytoscape tsconfig paths) + build-verify gates in the
  sync/deploy workflows.
