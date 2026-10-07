---
type: Skill
name: mcp-ai-wpoos-spa-ui
description: UI stack and test conventions for NV oOS React SPA addons (toolkit-shell, chat-spa, schedule-anything-spa, saas-controller, and future toolkit SPAs) — the three sanctioned build patterns and when to pick each, the dual-React guard (bundled React 19 must never import @wordpress/element/components), headless-only component rules (Radix + CVA + clsx/tailwind-merge + sonner, shadcn methodology), NV oOS Design System (--nds-*) token consumption, Tailwind v4 setup, @xyflow/react v12 migration notes, the vitest + jsdom + Testing Library stack with its Radix polyfills and known jsdom gotchas (BubbleSelect form caveat, RHF resolver churn, zod numeric coercion), bundle-size budgets, and the add/* → alpha-working PR workflow. Use when building or modifying SPA addon UI, adding React components, writing SPA JS tests, upgrading a SPA dependency, or continuing proposal 060 (SPA UI Stack Enhancement).
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.99"
  plugin-version-tested: "1.1.99"
  last-updated: "2026-10-07"
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
- "Continue the 060 work" / P1 items (zod size pass, per-page i18n sweep,
  table virtualization)

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

## References

- Proposal 060 docs (linked above) — decisions, file manifest, verification matrix
- `docs/addons/toolkit-spa-blueprint.md` — canonical addon pattern
- `addons/toolkit-shell/src/components/ui/` and `addons/schedule-anything-spa/src/components/ui/` — reference UI kits
- `addons/saas-controller/README.md` + `THIRD_PARTY_NOTICES.md` — Pattern C reference
- `mcp-ai-wpoos-test-suite` skill — PHPUnit side of testing (disjoint scope)
- `.github/agents/toolkit-spa-maintainer.agent.md` — addon-scoped writer agent
