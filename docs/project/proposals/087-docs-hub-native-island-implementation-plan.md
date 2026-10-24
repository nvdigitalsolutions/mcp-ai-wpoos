# Implementation Plan — Proposal 087 (Docs Hub native island, Phases 0–3)

Companion to `087-docs-hub-native-island-proposal.md`. Phases 0–3 are
implemented on this branch.

## Phase 0 — Docs Hub island API + MemoryRouter mode (upstream) ✅

Files changed in `addons/docs-hub/`:

| File | Change |
|---|---|
| `src/index.tsx` | Export `mountDocsHub( container ) → { unmount() }`; expose it on `window.NVoosDocsHub`; skip the auto-mount scan when `config.defer_mount` is set |
| `esbuild.config.js` | Drop `globalName` (the entry assigns the global explicitly) |
| `src/navigation.ts` | New: `DocRouterModeContext` + `useDocRouterMode()` (`'hash'` default, `'memory'` for host islands) |
| `src/App.tsx` | Render `MemoryRouter` when `config.router === 'memory'`; wrap the tree in the mode context; keep the skip-link from touching the host hash in memory mode |
| `src/components/Breadcrumbs.tsx` | `Link to` path-style (`/slug`) instead of `/#/slug` |
| `src/components/ContentArea.tsx` | In memory mode, intercept `#/slug…` route anchors (local + remote resolvers) and `navigate()` the island router |
| `src/routes/DocPage.tsx` | Read the anchor fragment from `useLocation().hash` (works under both routers) |
| `src/api/manifest-client.ts` | Type the `router` / `defer_mount` config keys |

Validation: `npm run typecheck` ✅, `npm run lint` ✅, `npm run build` ✅
(rebuilds `assets/dist/docs-hub.{js,css}` — the vendored artifact).
`npm test` blocked locally by a pre-existing Windows native-binding gap
(`@rolldown/binding-win32-x64-msvc` missing — npm bug #4828); the suite runs
in CI on Linux.

## Phase 1 — Vendored island in the Pro SPA ✅

| File | Change |
|---|---|
| `addons/pro/assets/spa-v2/src/features/docs-hub/upstream/docs-hub.js` | New: built bundle, **byte-identical** to `addons/docs-hub/assets/dist/docs-hub.js` |
| `addons/pro/assets/spa-v2/src/features/docs-hub/upstream/docs-hub.css` | New: built stylesheet, byte-identical |
| `addons/pro/assets/spa-v2/src/features/docs-hub/upstream/docs-hub.d.ts` | New: module + `window.NVoosDocsHub` / `window.NVOOS_DOCS_HUB` types |
| `addons/pro/assets/spa-v2/src/features/docs-hub/DocsPage.tsx` | Rewritten shell: inject island config → `mountDocsHub( container )` → `unmount()` on exit; unavailable/error states mirror `KnowledgeGraphPage` |
| `addons/pro/assets/spa-v2/src/features/docs-hub/docs-hub.css` | Island container sizing (pin the three-pane grid to the container height) |
| `addons/pro/assets/spa-v2/src/features/docs-hub/__tests__/DocsPage.test.tsx` | Rewritten: config injection, single mount with the shell container, theme pass-through, unmount + config teardown, missing-API error, unavailable state (5 tests) |
| `addons/pro/assets/spa-v2/src/api/config.ts` | `docsHub` doc comment: REST base, not front-end URL |
| `addons/pro/assets/spa-v2/src/router.tsx` | Route comment update |
| `addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php` | `docsHub` = local `nvoos-docs/v1` REST base, `class_exists( 'NV_oOS_Docs_Hub_Plugin' )` + admin/non-guest gated (mirrors `contentGraph`); filter retained as REST-base override |
| `addons/pro/tests/test-pro-spa-config-docs-hub.php` | New: empty-when-inactive, local base when active, filter override, non-admin empty (4 tests) |
| `examples/nvoos-pro-spa-vite/src/runtime-config.ts` | `docsHub: ${api}/nvoos-docs/v1` (same contract as `contentGraph`) |
| `addons/pro/assets/spa-v2/assets/dist/pro-spa.{js,css}` | Rebuilt (island inlined; ~2.3MB js / ~196KB css) |

Validation: spa-v2 `tsc` ✅; spa-v2 vitest 46 files / 371 tests ✅; PHP test
4/4 ✅ (Docker one-off runner, WP 6.9 / PHP 8.2); phpcs on changed PHP: 0
errors (1 pre-existing unrelated warning at line 216). Standalone-app
`tsc` not runnable locally (no `node_modules` in the worktree — CI mirror
gate covers it).

### Sync discipline (when `addons/docs-hub/assets/dist/*` changes upstream)

1. Rebuild: `cd addons/docs-hub && npm run build`.
2. Copy both files into `features/docs-hub/upstream/` (overwrite).
3. Verify byte-identity: `git --no-pager diff --no-index addons/docs-hub/assets/dist/docs-hub.js addons/pro/assets/spa-v2/src/features/docs-hub/upstream/docs-hub.js` (same for `.css`).
4. Rebuild the spa-v2 dist (`cd addons/pro/assets/spa-v2 && npm run build`)
   and re-run its vitest suite.

## Phase 2 — OKF knowledge source ✅

| File | Change |
|---|---|
| `addons/pro/includes/rest/class-wp-mcp-ai-pro-rest-okf-docs.php` | New: OKF → Docs Hub contract adapter. `GET /mcp-ai-pro/v1/okf/docs/{manifest,pages/{slug},search}` with bundle-prefixed slugs (`{bundle}/{concept}`), server-side cross-link rewriting to `#/…` island routes (OKF v0.2 §6.1 resolution + cross-bundle extension), rehype-slug-compatible heading TOC, prev/next neighbours, read-permission gate via the shared credential-auth trait |
| `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php` | Register `rest_okf_docs` next to `rest_okf` |
| `addons/pro/tests/test-pro-okf-docs-rest.php` | New: permission gate, manifest shape, page shape + link rewrites (same-bundle + cross-bundle), TOC anchors, prev/next, 404s, search shape, empty query (7 tests) |
| `addons/docs-hub/src/components/ContentArea.tsx` | Intercept literal `#/…` content links in memory mode (the OKF source emits them) — upstream change, rebuilt into the vendored bundle |
| `addons/docs-hub/src/__tests__/island.test.tsx` | Raw `#/` content-link navigation in memory mode |
| `addons/pro/assets/spa-v2/src/features/docs-hub/DocsPage.tsx` | Source toggle (`Docs Hub` / `OKF Knowledge`) shown when both REST bases exist; auto-selects the only available source; remounts the island on switch |
| `addons/pro/assets/spa-v2/src/features/docs-hub/docs-hub.css` | Segmented source-toggle styles |
| `addons/pro/assets/spa-v2/src/features/docs-hub/__tests__/DocsPage.test.tsx` | +2 tests: auto-OKF selection, toggle remount with the okf base (7 total) |

Validation: PHP 7/7 via the Docker one-off runner (WP 6.9 / PHP 8.2); phpcs 0
errors; docs-hub tsc/lint/build ✅ (vitest in CI); spa-v2 tsc + vitest 46
files / 373 tests ✅; `pro-spa.js` rebuilt.

## Phase 3 — Media Studio + Document Editor islands ✅

| File | Change |
|---|---|
| `addons/media-studio/src/index.tsx` | Island API: `mountMediaStudio( container ) → { unmount() }` exposed on `window.NVoosMediaStudio`; `defer_mount` skips the auto-scan; refactored `mountInto()` |
| `addons/media-studio/esbuild.config.cjs` | Drop `globalName` (explicit global assignment) |
| `addons/document-editor/src/index.tsx` + `esbuild.config.cjs` | Same (`mountDocumentEditor` / `window.NVoosDocumentEditor`) |
| Both addons `assets/dist/*` | Rebuilt (local `node_modules` repaired via `npm install`) |
| `addons/pro/assets/spa-v2/src/utils/island-assets.ts` | New: once-per-session lazy `<script>`/`<link>` loaders + `useIslandSkipLinkGuard` (keeps island skip links off the Pro SPA hash) |
| `addons/pro/assets/spa-v2/src/utils/auth-bridge.ts` | Slimmed: `postAuthToChild` removed (no callers left); `getAuthCredential` + auth slot retained |
| `features/media-studio/MediaStudioPage.tsx` + `features/document-editor/DocumentEditorPage.tsx` | Rewritten as island shells: config injection (incl. origin-checked headless `authorization`), lazy asset load, mount/unmount, unavailable/error states |
| Feature CSS files | `__frame` → `__island` sizing |
| `api/config.ts` | New `mediaStudioAsset` / `mediaStudioCss` / `documentEditorAsset` / `documentEditorCss` endpoints + parsing |
| `class-wp-mcp-ai-pro-spa-config.php` | media/document endpoints = local REST bases (feature-flag gated); new asset URL endpoints via `plugins_url`; filters retained as REST-base overrides |
| `examples/nvoos-pro-spa-vite/src/runtime-config.ts` | REST bases + asset URLs via Vite `?url` imports (bundles copied into the standalone build) |
| Page tests | Rewritten: 6 + 6 tests (config injection, asset loading, credential origin-check, mount/unmount, error, unavailable) |
| `addons/pro/tests/class-media-studio-stub.php`, `class-document-editor-stub.php`, `test-pro-spa-config-island-endpoints.php` | New: 4 tests for endpoint gating + asset URLs + filter overrides |

Validation: PHP 4/4 via the Docker one-off runner (WP 6.9 / PHP 8.2); phpcs 0
errors on all changed PHP; both addons tsc/lint/build ✅; spa-v2 tsc + vitest
46 files / 381 tests ✅; `pro-spa.js` rebuilt (iframe framing + postAuthToChild
gone — verified in the bundle).

## Rollout notes

- Complete-bundle sites get the local islands automatically (Docs Hub ships
  in the bundle; Media Studio and Document Editor load their bundles from the
  site's own plugin directories); sites without a plugin/addon see the
  unavailable state instead of a hosted-console or shortcode-page frame.
- Admins overriding the three `wp_mcp_ai_pro_spa_*_url` filters must now point
  them at `wp-json` REST bases, not front-end/page URLs (documented in the
  proposal).
- No wp.org-surface behaviour change for the standalone plugins/addons — the
  island modes are opt-in via injected config (`defer_mount`), and the
  postMessage child-side listeners remain as a harmless fallback.
