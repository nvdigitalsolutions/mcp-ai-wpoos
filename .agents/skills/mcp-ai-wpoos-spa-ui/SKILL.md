---
type: Skill
name: mcp-ai-wpoos-spa-ui
description: UI stack, build, and test conventions for NV oOS React SPA addons (toolkit-shell, chat-spa, schedule-anything-spa, saas-controller) — three sanctioned build patterns plus the standalone Pro SPA Vite app (examples/nvoos-pro-spa-vite), dual-React guard, headless component rules, NDS tokens, Tailwind v4, @xyflow/react v12, vitest/jsdom gotchas, the full-JS-rebuild map, and the TS-shadows-JS webpack gotcha. Use when building or modifying SPA addon UI, writing SPA JS tests, upgrading SPA deps, or rebuilding JS for packaging.
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.99"
  plugin-version-tested: "1.1.99"
  last-updated: "2026-10-10"
---

# NV oOS SPA UI Stack & Testing Guide

Operational guide for the React SPA addons. Canonized from proposal **060 —
SPA UI Stack Enhancement** (PR #6952) — research, implementation plan, and
verification notes live in
[`docs/project/proposals/060-spa-ui-stack-enhancement.md`](../../../docs/project/proposals/060-spa-ui-stack-enhancement.md)
and
[`060-spa-ui-stack-enhancement-implementation-plan.md`](../../../docs/project/proposals/060-spa-ui-stack-enhancement-implementation-plan.md).
The canonical addon pattern itself is
[`docs/addons/toolkit-spa-blueprint.md`](../../../docs/addons/toolkit-spa-blueprint.md).

## When to use this skill

- Building or modifying React UI in `addons/toolkit-shell`, `addons/chat-spa`,
  `addons/schedule-anything-spa`, or a new toolkit SPA
- Choosing which SPA build pattern a new addon should use
- Adding a component, dialog, select, table, or form to a SPA addon
- Writing or repairing SPA JS tests (vitest) — for **PHPUnit** work use the
  `mcp-ai-wpoos-test-suite` skill instead
- Upgrading a SPA dependency (React, Tailwind, @xyflow/react, Radix) and
  re-bundling
- Rebuilding "all the JS" so packaged ZIPs carry up-to-date artifacts — see
  "Full JS rebuild map"
- Triaging webpack "export 'X' was not found … possible exports" warnings —
  see "TS-shadows-JS resolution gotcha"
- "Continue the 060 work" / P1 items (zod size pass, per-page i18n sweep,
  table virtualization)
- Building or deploying the standalone Pro SPA app
  (`examples/nvoos-pro-spa-vite/`, Cloudways Velocity, `chat.nvoos.cloud`)
  — see "Standalone SPA app (pattern D)"

## The three sanctioned build patterns — pick one, don't mix

| Pattern | Addons | Stack | Choose when |
|---|---|---|---|
| **A — standalone bundle** | `toolkit-shell`, `chat-spa` | esbuild IIFE, **bundled React 19.1**, hand-rolled or token CSS, `@wordpress/i18n` externalized to `window.wp.i18n` | The SPA embeds on **front end and admin** and must not depend on WP core scripts (any WP 6.x) |
| **B — Vite product SPA** | `schedule-anything-spa` | Vite 6, **React 18.3**, Tailwind v4, `@wordpress/api-fetch` + i18n bundled | Tenant-facing product with a public (unauthenticated) surface and its own brand |
| **C — WP-core externals** | `saas-controller` | `wp-scripts`, `@wordpress/components`/`element`/`icons` externalized to `window.wp.*`, React 18 from WP core | Admin-only settings surfaces where zero-KB bundle and native wp-admin look matter |

Rules that follow from the matrix:

1. **React version constraint:** WP core ships React 18 through WP 7.1; the
   React 19 core upgrade is experimental in Gutenberg. Patterns B and C run
   on WP's React 18. Pattern A ships its own React 19 — fine in isolation.
2. **Dual-React guard (Pattern A):** a bundled React 19 tree must never import
   `@wordpress/element` or `@wordpress/components` (they pull WP's React 18
   renderer and corrupt hooks state). Only the React-free `@wordpress/i18n` is
   allowed. Enforce with ESLint `no-restricted-imports` (see
   `addons/toolkit-shell/eslint.config.js`). Pattern B may use
   `@wordpress/api-fetch` + `@wordpress/i18n` — both React-free.
3. Never mix patterns in one addon (no `@wordpress/components` inside a
   Pattern-A bundle, no bundled React inside a Pattern-C admin page).

## Standalone SPA app (pattern D — Vite shell over spa-v2)

`examples/nvoos-pro-spa-vite/` is a standalone Vite + React app that mounts the
**Pro SPA v2 sources directly via a Vite alias**
(`../../addons/pro/assets/spa-v2/src`) and connects to **any WordPress backend**
running the plugin — no WP host page, no fork (the app tracks the plugin SPA).
Deploy playbook: `docs/operations/deployment/nvoos-pro-spa-velocity-setup.md`.

Reusable mechanics (all learned the hard way — do not rediscover):

- **Runtime global:** spa-v2 expects `window.NVOOS_PRO_SPA` (`apiUrl`,
  `proApi`, `nonce`, `endpoints`, `user`, `assistants`, `config`) localized by
  the plugin's PHP loader. The app builds the same object from a connection
  screen: `{site}/wp-json/mcp-ai/v1/{chat,chat-client,chat-transcripts,…}` +
  `{site}/wp-json/mcp-ai-pro/v1/{workflows,…}`, and mints the `wp_rest` nonce
  from `mcp-ai/v1/session/nonce` (**cookie mode only**).
- **Shims:** a tiny `@wordpress/i18n` drop-in (spa-v2 reads `window.wp.i18n`),
  and a `window.fetch` wrapper that injects
  `Authorization: Bearer cred_…` / `X-WP-MCP-AI-Guest` on **every** request —
  including the SSE chat stream (the AI SDK adapter opens it through global
  fetch) — and **strips the empty `X-WP-Nonce`** the SPA sends, which the
  plugin's REST layer rejects for token auth.
- **Full screen:** `shell.css` re-creates the WP-admin height chain
  (`#wpwrap` → root) the three-column layout expects.
- **Auth modes (four):** assistant credential (bearer) = the production
  cross-origin path; guest = public chat; cookie = dev proxy **or** the
  production `serve.mjs` proxy (login via `/wp-login.php?redirect_to=%2F` —
  keep `redirect_to` **relative**, `wp_validate_redirect()` rejects
  cross-host absolute URLs); **WordPress login (`wp`)** = Application
  Password (Basic auth over REST) — `wp/v2/users/me?context=edit`
  validation, the **real user + capabilities** mounted (full admin surface,
  real server-side enforcement), the Basic header built UTF-8-safe
  (`TextEncoder`) and attached **only** to the configured site origin
  (never the media worker/third-party URLs). Transcripts/approvals accept
  bearer credentials too (reads scoped to the issuing assistant;
  `approve`/`deny` stay `manage_options` — issue #6987 closed by #6990).
- **CORS:** the backend must echo the app origin — plugin **≥1.2.3**
  `cors_allowed_origins` (or the §5b mu-plugin) + widen
  `wp_mcp_ai_cors_guard_route_prefixes` with `/mcp-ai-pro/v1` (the default
  scope is `/mcp-ai/v1` only); the OOS SSE chat stream also emits the
  resolved header (`emit_stream_cors_headers()`). Never flip the dropdown
  to "Allow All".
- **Build/deploy:** `npm run build` → static `dist/`; `scripts/serve.mjs` is
  the Velocity entry and doubles as the **zero-dependency production
  reverse proxy** when the **runtime** env var `NVOOS_TARGET_SITE` is set
  (`/wp-json`, `/wp-admin`, `/wp-login.php`, `/wp-includes`; SSE streaming,
  login redirect + `Set-Cookie` Domain rewriting, hop-by-hop filtering,
  502 on upstream failure). Dev (`scripts/dev.mjs`) resolves
  `NVOOS_TARGET_SITE` with shell > `.env` > `http://localhost:8000`
  precedence (Vite `loadEnv` with an empty prefix makes non-`VITE_` keys
  load); build-time `VITE_DEFAULT_SITE_URL` pre-fills the connection
  screen. Velocity **root directory must be `examples/nvoos-pro-spa-vite`**
  — the repo root has no `package.json` and the build dies with `npm error
  enoent` (exit 254); the sync/deploy workflows pin `main` at git init
  (`git init -q -b main`) and assemble the deploy tree **under `examples/`**
  (a bare `git init` + root-level copy broke both).
- **Media worker:** the app fronts the worker directly (URL + `X-Site-Token`
  in the connection screen) — the two stay separate Velocity apps.

## Component rules — headless only

- **Adopt Radix primitives** (`@radix-ui/react-*`, MIT) wrapped per-addon in
  `src/components/ui/` with the **shadcn methodology**: copy the wrapper code
  into the repo (we own and ship it), CVA for variants, `clsx`
  (+ `tailwind-merge` when Tailwind is in play) via a `cn()` helper in
  `src/lib/utils.ts`. Use `sonner` for toasts, `react-hook-form` + `zod`
  (`^3.25`, `@hookform/resolvers` `^3`) for forms, `@tanstack/react-table`
  for tables, `@dnd-kit` for drag-and-drop.
- **Reject pre-styled frameworks** (MUI, Ant Design, Chakra, PrimeReact,
  HeroUI): bundle weight, wp-admin visual clash, second theming system.
  Advanced tables come from TanStack Table, not PrimeReact.
- **Consume NV oOS Design System tokens**: every color/size should reference
  `var(--nds-*, <wp-admin fallback>)` so the design-system addon rebrands the
  UI when active. Verified token names include `--nds-color-accent`,
  `--nds-color-accent-hover`, `--nds-color-border`, `--nds-color-surface`,
  `--nds-color-text-primary`, `--nds-font-family`, `--nds-space-{xs,sm,md}`,
  `--nds-transition-{fast,normal}`. In Tailwind v4 map them with
  `@theme inline { --color-primary-500: var(--nds-color-accent, #3b82f6); }`
  (the `inline` keyword emits the raw `var()`). Pattern A keeps plain CSS with
  local aliases (`--ts-*`) so the dark NDS surface default never leaks into
  the light wp-admin theme.
- Licenses: MIT / Apache-2.0 / BSD / ISC only; bundle everything (no CDN);
  update `THIRD_PARTY_NOTICES.md` (+ root `CREDITS.md`) and the README
  Credits section; run `gh-advisory-database` for any new dependency.

## Tailwind v4 (Pattern B) notes

- `@tailwindcss/vite` plugin (or `@tailwindcss/postcss` for esbuild); delete
  `tailwind.config.js` — config is CSS-first (`@import "tailwindcss";` +
  `@theme`/`@theme inline` blocks in `global.css`).
- Drop autoprefixer (Lightning CSS handles prefixing).
- Never reference undefined theme tokens in utility classes (e.g.
  `ring-ring`); use real palette keys or define them.

## @xyflow/react v12 (upgraded from reactflow 11)

- **No default export** — `import { ReactFlow, ... } from '@xyflow/react'`.
- Custom node data typing: `type ToolNodeType = Node<ToolNodeData, 'toolNode'>`
  (data must be a type alias, not an interface, to satisfy
  `Record<string, unknown>`); components take `NodeProps<ToolNodeType>`.
- `NodeProps` requires the full node descriptor (id, type, dragging, zIndex,
  isConnectable, positionAbsoluteX/Y…) — tests need a typed props factory
  (see `addons/schedule-anything-spa/src/components/builder/__tests__/nodes.test.tsx`).
- `instance.project(...)` is gone — use `instance.screenToFlowPosition({x, y})`.
- `<Handle/>` needs `ReactFlowProvider` ancestry, including in tests.
- Heavy builder routes should be `React.lazy`-split (see App.tsx).

## SPA test stack (both addons)

Stack: **vitest + jsdom + @testing-library/react/user-event/jest-dom**. Setup
file (`src/test-setup.ts`) must polyfill for Radix's Popper layer:
`ResizeObserver`, `Element.prototype.scrollIntoView`,
`hasPointerCapture`/`setPointerCapture`/`releasePointerCapture`, and
`window.matchMedia`. Pattern A additionally needs the `window.NVOOS_TOOLKIT_SHELL`
bootstrap fixture and heavy-view mocks (see `api.test.tsx`).

### Known jsdom gotchas (all encountered in PR #6952 — do not rediscover)

1. **Radix Select inside a native `<form>` resets its value under jsdom.**
   Radix's hidden `BubbleSelect` (native form integration) re-dispatches a
   `change` event whose `target.value` is bogus in jsdom, resetting the
   controlled field. Real browsers filter the duplicate upstream. Fix in
   tests: integration-test the real Radix Select **form-free** (bare wrapper
   asserting `onValueChange`), and in form tests use a documented
   native-select stand-in via `vi.mock` (see
   `addons/toolkit-shell/src/components/__tests__/FormView.test.tsx`). In
   production code, defensively ignore `undefined`/`null` in `onValueChange`.
2. **RHF + zodResolver identity churn resets controlled Radix inputs.** RHF
   re-validates when the resolver identity changes; a resolver built inline
   from a runtime schema re-runs every render. Always
   `useMemo`-stabilize the schema, `zodResolver(schema)`, and `defaultValues`.
3. **zod coerces empty numeric fields to `0` on submit.** Use
   `z.preprocess(emptyToUndefined, z.coerce.number().optional())` so `''`
   stays unset; required numerics reject empty with the field's message.
4. **dnd-kit sortable attributes put `role="button"` on the wrapper**, which
   nests a button inside a button when the card itself is a button. Use the
   drag-handle pattern: separate handle button carrying `{...attributes}
   {...listeners}`, content button for the click action.
5. **jsdom pointer events lack `pointerType`** — Radix pointer paths are
   unreliable; prefer `user.click` on triggers/options (works form-free) or
   `fireEvent` for isolated cases.

## Release bookkeeping

- Pattern A: bump **all three** versions together (plugin header `Version:`,
  `NVOOS_*_VERSION` define, `package.json`) whenever the bundle changes —
  never hand-edit `assets/dist/`, always `npm run build`.
- Budgets: keep Pattern-A shells ≲170 KB gzip total; split heavy routes in
  Pattern B (`React.lazy`) — the Schedule Anything main chunk is ~93 KB gzip
  with Builder at ~63 KB.
- Lint gates: `npm run lint:a11y` (`eslint-plugin-jsx-a11y`, `--max-warnings 0`)
  must pass; typecheck with `npm run typecheck`; tests with `npm test`.
- PRs: branch `add/<slug>` from `alpha-working`, PR back to `alpha-working`,
  commit built artifacts and lockfiles.
- Shared worktree caution: another agent or a sync process can revert a file
  mid-session (seen with `workflowHelpers.ts`, 2026-10-09). After edits,
  verify on-disk state with a marker grep before rebuilding/committing; if a
  fuzzy edit double-applies, rewrite the file whole with `write_file`.

## Full JS rebuild map — shipping fresh artifacts

When the ask is "rebuild all JS so the plugin is packaged up to date", the
scope is the whole tree, not one addon. Verified inventory (2026-10-09):

| Area | Command | Notes |
|---|---|---|
| Core CSS/JS | `npm run build:css && npm run build:js` | root esbuild configs |
| Pro JS | `npm run build:js:pro` | **needs `addons/pro/node_modules`** — worktrees often lack it; run `(cd addons/pro && npm install --legacy-peer-deps --omit=optional --no-optional)` first. Bundles `generate-{pdf,word,excel}` + remotion into `addons/pro/bin/` |
| docs-hub | `npm run build:docs-hub` | prebuild runs `npm ci` if `node_modules` missing |
| workflow + TMA | `npm run build:workflow && npm run build:tma` | wp-scripts webpack → `addons/pro/build/` |
| Pattern A SPAs | `toolkit-shell`, `chat-spa`, `comic-reader`, `librechat`, `media-studio`, `funiq-bridge`, `canvas-toolkit`, `document-editor`, `pro/assets/spa-v2` — esbuild `npm run build`; `page-agent` uses `node esbuild.config.js` (dev output, no `--prod`) | `npm ci` per addon when `node_modules` missing (lockfile is committed) |
| Pattern B SPA | `schedule-anything-spa` — `npm run build` (`tsc && vite build`) | |
| Pattern C SPA | `saas-controller` — `npm run build` (worker esbuild + drift-manifest stamp + wp-scripts admin) | |
| Legacy pro SPA | `addons/pro/assets/spa` — `npm run build` (wp-scripts webpack → `dist/`) | |
| npm packages | `packages/nvoos-*` — each has `npm run build` (`adapt-for-npm.cjs`); `nvoos-mcp-bridge` has none by design | loop with a small node script; dist folders are git-tracked |
| content-graph vendor | `plugins/nvoos-content-graph` — `npm install` then `node scripts/copy-vendor.js` | |

Skips: `algorave` / `fantasy-football` (no-op echo builds), `mcp-gateway` /
`mcp-wordpress-gateway` / `media-worker` (run from source, no build step),
`tenant-router` (wrangler-only), `canvas` (native linux-x64 binary — Docker CI
workflow only; use `--skip-canvas` on dev machines).

Verification: deterministic bundles rebuild **byte-identical** — those files
don't show in `git status`; only stale artifacts appear as modified. Check
mtimes to confirm a build actually ran. Full run: ~15 min, mostly `npm ci`
for saas-controller (2k+ deps). npm on dev machines blocks dependency install
scripts (esbuild postinstall etc.) — harmless, platform binaries ship via
optional deps.

## TS-shadows-JS resolution gotcha (wp-scripts)

`@wordpress/scripts` webpack resolves extensionless imports with TypeScript
first, so a `foo.ts` sibling **shadows** `foo.js` — the `.ts` edition is what
gets bundled, silently. The 2026-10 TS upgrade created `.ts` editions of the
workflow-builder utils but left the `.js` originals in place; the incomplete
`.ts` editions were bundled instead. Signature (a runtime bug, not just
noise — missing exports resolve to `undefined`, e.g. dead Export/Import
buttons):

```
WARNING in ./src/workflow-builder/components/WorkflowBuilder.jsx 276:4-18
export 'exportWorkflow' (imported as 'exportWorkflow') was not found in '../utils/workflowHelpers' (possible exports: generateNodeId, validateWorkflow)
```

When you see this warning: grep the resolved module's directory. If both
`foo.js` and `foo.ts` exist, webpack is bundling the `.ts` — port the missing
logic into the `.ts` file (it is canonical), then delete the dead `.js`
duplicate and update README/docs references. Reconciliation precedent
(2026-10-09, PR #6979): ported `exportWorkflow`/`importWorkflow`, the
`validateWorkflow` node-config checks + DFS cycle detection, and
`animated: true` template edges (`WorkflowEdge.animated?: boolean` added to
`src/shared/types.ts`); deleted five dead `.js` utils; `workflowExecutor.js`
kept — it has no TS counterpart and is canonical as-is. The other pairs
(`executionHistory`, `workflowHistory`, `workflowVersioning`) were already
functionally equivalent.

## References

- Proposal 060 docs (linked above) — decisions, file manifest, verification matrix
- `docs/addons/toolkit-spa-blueprint.md` — canonical addon pattern
- `addons/toolkit-shell/src/components/ui/` and `addons/schedule-anything-spa/src/components/ui/` — reference UI kits
- `addons/saas-controller/README.md` + `THIRD_PARTY_NOTICES.md` — Pattern C reference
- `mcp-ai-wpoos-test-suite` skill — PHPUnit side of testing (disjoint scope)
- `.github/agents/toolkit-spa-maintainer.agent.md` — addon-scoped writer agent
