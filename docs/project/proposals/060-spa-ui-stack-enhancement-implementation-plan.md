# 060 — SPA UI Stack Enhancement — Implementation Plan

> Companion to [`060-spa-ui-stack-enhancement.md`](./060-spa-ui-stack-enhancement.md)
> Status: **Implemented** · Date: 2026-10-07
> Scope: `addons/toolkit-shell` + `addons/schedule-anything-spa` (P0 of the proposal)

## Overview

Adopt headless UI primitives (Radix), modernize the styling stacks (NDS design
tokens, Tailwind v4), and harden a11y/i18n tooling across the two SPA addons —
without adding any pre-styled framework (MUI/Ant/Chakra) and without changing
either addon's runtime architecture (standalone bundle / Vite).

| Area | toolkit-shell | schedule-anything-spa |
|---|---|---|
| React / build | React 19 esbuild IIFE (unchanged) | React 18 + Vite 6 (unchanged) |
| Styling | NDS `--nds-*` tokens in hand-rolled CSS | Tailwind v3 → **v4** + NDS token aliases |
| Primitives | Radix Dialog/DropdownMenu/Select/Tabs/Checkbox + CVA + sonner | shadcn-style `components/ui/` kit (Radix + CVA + tailwind-merge + sonner) |
| Tables | TanStack Table (sorting) in TableView | TanStack-ready ui/table shell (page adoption deferred) |
| Kanban | @dnd-kit drag between columns | — |
| Forms | react-hook-form + zod (runtime manifest schema) | ui/form + RHF/zod available for future pages |
| Flow editor | — | reactflow 11 → **@xyflow/react 12** |
| Loading | — | React.lazy route-splitting (Builder, Analytics) |
| i18n | already @wordpress/i18n | **adopt** @wordpress/i18n (layout/shared/chrome) |
| A11y | eslint no-restricted-imports guard | add axe + jsx-a11y tooling (mirror toolkit-shell) |

## File manifest

### toolkit-shell

**New files**

| File | Purpose |
|---|---|
| `src/lib/utils.ts` | `cn()` class combiner (clsx) |
| `src/components/ui/button.tsx` | CVA button (variants: default/secondary/destructive/ghost; sizes) |
| `src/components/ui/dialog.tsx` | Radix Dialog primitives (Content/Overlay/Title/Description/Close) |
| `src/components/ui/confirm-dialog.tsx` | Composable confirm dialog (replaces `window.confirm`) |
| `src/components/ui/dropdown-menu.tsx` | Radix DropdownMenu primitives |
| `src/components/ui/select.tsx` | Radix Select primitives |
| `src/components/ui/tabs.tsx` | Radix Tabs primitives |
| `src/components/ui/checkbox.tsx` | Radix Checkbox |
| `src/components/toaster.tsx` | sonner `<Toaster />` mount point |

**Modified files**

| File | Change |
|---|---|
| `package.json` | +radix packages, sonner, clsx, cva, @tanstack/react-table, @dnd-kit/*, react-hook-form, zod, @hookform/resolvers; dnd-kit React-19 peer overrides; version 0.2.0 → 0.3.0 |
| `src/App.tsx` | Radix Tabs for view switching; ConfirmDialog + sonner toasts for delete; Kanban `onMove` wiring (`updateResource` with `group_by`) |
| `src/components/TableView.tsx` | TanStack Table with click-to-sort headers; row actions via ui/button + ConfirmDialog |
| `src/components/KanbanView.tsx` | @dnd-kit sortable cards + cross-column drag; `onMove` prop |
| `src/components/FormView.tsx` | RHF + zod (schema built from manifest fields); Radix Select/Checkbox |
| `src/components/DetailView.tsx` | ui/button adoption |
| `src/styles/main.css` | `--nds-*` token consumption with wp-admin fallbacks |
| `eslint.config.js` | `no-restricted-imports`: forbid `@wordpress/element`, `@wordpress/components` (dual-React guard) |
| `nvoos-toolkit-shell.php` | Version 0.3.0 |
| `THIRD_PARTY_NOTICES.md` | MIT attributions for new deps |
| `README.md` | Dependency + a11y notes |

### schedule-anything-spa

**New files**

| File | Purpose |
|---|---|
| `src/lib/utils.ts` | `cn()` (clsx + tailwind-merge) |
| `src/lib/i18n.ts` | @wordpress/i18n locale bootstrap |
| `src/components/ui/{button,card,input,textarea,label,badge,skeleton}.tsx` | Leaf primitives |
| `src/components/ui/{dialog,dropdown-menu,select,tabs,tooltip,popover}.tsx` | Radix primitives |
| `src/components/ui/{table,form,toast}.tsx` | TanStack-ready table shell; RHF/zod form; sonner |
| `eslint.config.js` | jsx-a11y flat config (toolkit-shell pattern) |
| `THIRD_PARTY_NOTICES.md` | MIT attributions |

**Modified files**

| File | Change |
|---|---|
| `package.json` | tailwindcss ^4 + @tailwindcss/postcss + @tailwindcss/vite; **remove** reactflow → +@xyflow/react ^12; +radix set, cva, sonner, RHF/zod, @wordpress/i18n, @axe-core/react, eslint-plugin-jsx-a11y; version 0.1.0 → 0.2.0 |
| `postcss.config.js` | Tailwind v4 (`@tailwindcss/postcss`), drop autoprefixer |
| `vite.config.ts` | add `@tailwindcss/vite` plugin |
| `tailwind.config.js` | **deleted** (CSS-first config) |
| `src/styles/global.css` | `@import "tailwindcss"` + `@theme inline` NDS aliases |
| `src/index.tsx` | dev-only axe import (`import.meta.env.DEV`) |
| `src/App.tsx` | React.lazy route-splitting (Builder, Analytics, History, Presets) + Suspense |
| `src/components/builder/FlowCanvas.tsx` | `@xyflow/react` v12 imports + i18n strings |
| `src/components/builder/{ToolNode,TriggerNode,PropertyPanel}.tsx` | `@xyflow/react` type imports |
| `src/components/layout/AppLayout.tsx` | i18n nav labels |
| `src/components/shared/ErrorBoundary.tsx` | i18n strings |
| `README.md` | Stack + build notes |

## Explicitly deferred (P1/P2 — tracked here for follow-up)

- TableView virtualization (`@tanstack/react-virtual`) — pagination caps pages
  at 25 rows today; virtualization adds fixed-row-height constraints that need
  visual QA. Adopt only if a manifest ships large unpaginated datasets.
- schedule-anything-spa page-level string sweep (Dashboard/Schedules/Presets/
  History/Analytics/Settings/Booking bodies) — i18n infra lands now; per-page
  `__()` conversion is mechanical and follows in a dedicated sweep.
- Tailwind adoption inside toolkit-shell (tokens stay plain CSS).
- Radix Toast vs sonner unification — both addons use sonner.
- motion micro-interactions, command palette, charts re-evaluation.

## Verification gate (all must pass)

```bash
# toolkit-shell
cd addons/toolkit-shell && npm ci
npm run typecheck && npm run lint:a11y && npm test && npm run build

# schedule-anything-spa
cd addons/schedule-anything-spa && npm install
npm run typecheck && npm run build
```

Plus: no `@wordpress/element` / `@wordpress/components` imports in either SPA
(grep gate), and `grep -c "var(--nds-" addons/toolkit-shell/src/styles/main.css`
> 0.

## Verification results (2026-10-07, updated with test suite)

| Gate | toolkit-shell 0.3.0 | schedule-anything-spa 0.2.0 |
|---|---|---|
| `npm run typecheck` | ✅ | ✅ |
| `npm run lint:a11y` | ✅ (0 warnings) | ✅ (0 warnings; fixed 6 pre-existing PropertyPanel label issues) |
| `npm test` | ✅ **31/31** (api + ui + TableView + KanbanView + FormView) | ✅ **23/23** (utils, i18n, ui kit, ErrorBoundary, AppLayout, builder nodes) |
| `npm run build` | ✅ `toolkit-shell.js` 538.1 KB (≈164 KB gzip), CSS 10.2 KB | ✅ index 92.9 KB gz + BuilderPage 63.4 KB gz (split), CSS 7.2 KB gz |
| grep gates | ✅ 14 `var(--nds-` refs; no WP React imports | ✅ no `reactflow` imports remain |

### Test suite notes

- **Test infrastructure**: both addons share the same stack — vitest + jsdom +
  testing-library with `@testing-library/jest-dom` and a polyfill setup file
  (ResizeObserver, scrollIntoView, pointer capture, matchMedia) required by
  Radix Popper-based primitives. The SPA gained `vitest.config.ts`,
  `src/test-setup.ts`, and the testing-library devDeps.
- **Real bugs the new tests caught (fixed)**:
  1. Empty optional numeric fields coerced to `0` on submit — zod
     `z.preprocess` now maps `''` → `undefined` before coercion.
  2. RHF + zodResolver identity churn resetting controlled Radix inputs —
     schema/resolver/defaults now `useMemo`-stabilized.
  3. Kanban cards exposed a nested-interactive a11y violation (dnd-kit put
     `role="button"` on the `<li>` wrapping a real `<button>`) — restructured
     to the documented drag-handle pattern.
- **Known jsdom limitation**: Radix Select's hidden `BubbleSelect` (native
  form integration) dispatches a bogus change event under jsdom **when the
  Select is inside a native `<form>`**, which resets the controlled field.
  Real browsers filter the duplicate upstream. The real Radix wrapper is
  integration-tested form-free in `ui.test.tsx`; `FormView.test.tsx` swaps
  in a documented native-select stand-in so the RHF/zod/payload logic
  remains under test.
- @xyflow/react v12's `NodeProps` requires the full node descriptor
  (dragging/zIndex/positionAbsolute*), so node smoke tests build complete
  props objects via a typed factory.
