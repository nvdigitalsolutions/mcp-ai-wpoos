# Proposal: UI Stack Enhancement for `toolkit-shell` & `schedule-anything-spa`

> Status: **P0 implemented 2026-10-07** — see
> [`060-spa-ui-stack-enhancement-implementation-plan.md`](./060-spa-ui-stack-enhancement-implementation-plan.md)
> for the shipped file manifest and verification results.
>
> Scope: evaluate open-source React component ecosystems against the needs of
> two WordPress SPA addons, recommend adopt/reject decisions, and lay out a
> phased implementation plan.

---

## 1. Current state

The repo now ships **three distinct React UI approaches**:

| Addon | React | Build | Styling | Component primitives |
|---|---|---|---|---|
| `toolkit-shell` | 19.1 (bundled, isolated) | esbuild IIFE | Hand-rolled `main.css` | None — bespoke Table/Kanban/Detail/Form views |
| `chat-spa` | 19.1 (bundled, isolated) | esbuild IIFE | Hand-rolled | None |
| `schedule-anything-spa` | 18.3 (bundled) | Vite 6 | Tailwind **v3** | None — bespoke components + React Flow 11 + recharts |
| `saas-controller` | 18 (WP core externals) | `wp-scripts` | wp-admin | `@wordpress/components` v33 (Ariakit-based) |

Cross-cutting assets that already exist and should be consumed:

- **`nvoos-design-system`** injects `--nds-*` design tokens (colors, type,
  spacing, dark/high-contrast/reduced-motion) on every page. Neither SPA
  consumes them today — both hardcode hex values.
- **`@wordpress/i18n`** is already a devDep of `toolkit-shell` (used) and
  absent from `schedule-anything-spa` (English-only strings today).
- **a11y tooling** (`@axe-core/react`, `eslint-plugin-jsx-a11y`, vitest)
  exists in `toolkit-shell` and `chat-spa`; `schedule-anything-spa` has none.

### Hard platform constraint (verified 2026-10)

- WordPress core ships **React 18.3 through WP 7.1**; the React 19 upgrade was
  punted and is currently experimental in Gutenberg 23.4+
  ([make/core 2026-05-27](https://make.wordpress.org/core/2026/05/27/react-19-upgrade-in-wordpress/),
  [make/core 2026-07-24](https://make.wordpress.org/core/2026/07/24/react-19-punted-beyond-wordpress-7-1-experiment-in-gutenberg/)).
- `@wordpress/components` v33 is built on **@ariakit/react** headless
  primitives (confirmed in `addons/saas-controller/package-lock.json`).
- Consequence: anything using `@wordpress/element`/`components` must run on
  React 18. `toolkit-shell`'s bundled React 19 is fine **in isolation** but
  must never mix with WP-global React (dual-React hazard).

---

## 2. Decision criteria (WordPress-plugin-specific)

1. **License** — must be GPLv2+/v3-compatible (MIT / Apache-2.0 / BSD OK) and
   bundled (no CDN loading; wp.org Plugin Check requirement).
2. **Bundle size** — admin pages must stay fast; every KB is loaded per page
   view inside wp-admin or the front end.
3. **wp-admin visual fit** — a foreign-looking admin UI is a UX regression for
   a WP plugin; native look is a selling point.
4. **Accessibility** — WCAG AA expectation across the WP ecosystem.
5. **i18n** — `@wordpress/i18n` + `wp_set_script_translations` pipeline.
6. **Longevity** — React 19 readiness now, WP 7.1+ React 19 in core later.
7. **Repo-pattern fit** — consistency with the three existing approaches.

---

## 3. Library evaluation

### 3.1 Pre-styled / enterprise frameworks — **REJECT all**

| Library | Verdict | Reason |
|---|---|---|
| Material UI (MUI) | ❌ | ≥90 KB gz tree-shaken (often 200 KB+); Material look clashes with wp-admin; theme engine fights NDS tokens |
| Ant Design | ❌ | ~300 KB+ plus icons; enterprise-dashboard look; heavyweight runtime |
| Chakra UI | ❌ | ~100 KB+; runtime theme context overkill; emotional CSS-in-JS dep |
| PrimeReact | ❌ | ~250 KB+; excellent data grids, but the only feature we'd use (advanced tables) is available headless via TanStack Table |
| HeroUI (NextUI) | ❌ | Requires Tailwind + its own system layer; aesthetics-first, adds a second theming system on top of NDS |

### 3.2 Headless primitives — **ADOPT Radix; watch Base UI; optional React Aria**

| Library | Verdict | Notes |
|---|---|---|
| **Radix UI** | ✅ **Adopt** | MIT; ~2–8 KB per primitive (tree-shaken); battle-tested a11y (WAI-ARIA authoring guides); React 19 support since late 2024; powers shadcn/ui — the de-facto 2025–26 default |
| **Base UI** | 👀 Watch | MUI-maintained, 1.0 stable Dec 2025, 35 components, render-prop API. Younger; adopt later if it wins mindshare |
| **React Aria** | ⚠️ Optional | Apache-2.0; deepest WCAG/i18n correctness but the most code per component. Only if strict-a11y audits demand it |
| **Ariakit** | ℹ️ Note | Already ships inside `@wordpress/components` v33 — relevant to the `saas-controller` pattern, not needed directly |

### 3.3 Copy-paste / Tailwind-native collections — **ADOPT the shadcn *methodology*, skip the rest**

| Library | Verdict | Reason |
|---|---|---|
| **shadcn/ui** | ✅ **Adopt as pattern** | MIT; components are *copied into your repo* (you own them); thin wrappers over Radix + CVA + tailwind-merge; no runtime framework lock-in; GPL-plugin friendly (all code redistributed under our GPLv3 with MIT attribution in `THIRD_PARTY_NOTICES.md`) |
| Flowbite React | ❌ | Installed dependency with its own styling assumptions; the copy-paste benefit doesn't materialize |
| Untitled UI React | ❌ | Commercial license — cannot be redistributed in a GPL plugin |
| Opensource UI | ⚠️ | Reference for designs only; don't copy code |

### 3.4 Animated / micro-interactions — **defer, tiny opt-in later**

| Library | Verdict | Notes |
|---|---|---|
| motion (Framer Motion successor) | 🕐 P2 optional | MIT; for booking-page and builder polish. Only if the a11y story (respecting `prefers-reduced-motion`, NDS motion tokens) is written first |
| GodUI / Fancy Components | ❌ | Novelty effects; portfolio-oriented; wrong fit for admin tooling |

### 3.5 WordPress-native — **ADOPT where the surface is admin-only**

| Library | Verdict | Notes |
|---|---|---|
| `@wordpress/components` + `@wordpress/icons` via WP core externals | ✅ For admin-only surfaces | **0 KB added to our bundle** (already enqueued in wp-admin); native admin look; i18n/a11y for free; proven in-repo by `saas-controller`. Cost: React 18 coupling and wp-admin-only styling |

### 3.6 Domain utilities (already in play or missing)

| Need | Choice | Notes |
|---|---|---|
| Data tables | **@tanstack/react-table** (+ `@tanstack/react-virtual`) | MIT; headless; sorting/filtering/column pinning without a UI framework |
| Drag & drop (Kanban) | **@dnd-kit** (core + sortable) | MIT; modern successor to react-beautiful-dnd; needs a peer-dep override under React 19 |
| Forms | **react-hook-form + zod** | MIT; zod is already in `chat-spa` (`^3.25.76`) — ecosystem consistency |
| Toasts | **sonner** | MIT; React 19-ready; or Radix Toast for zero-extra-dep |
| Charts | keep **recharts**, but route-split | ~100 KB gz — fine if lazy-loaded; revisit only if size budget fails |
| Flow editor | **@xyflow/react v12** (upgrade from reactflow 11) | MIT; v12 is the maintained line, smaller core, React 18/19 compatible |
| Class utilities | **clsx + tailwind-merge + class-variance-authority** | The shadcn trio; clsx already in schedule-anything-spa |
| CSS framework | **Tailwind v4** (schedule-anything-spa upgrade; optional for toolkit-shell) | v4 production CSS ≈15–25 KB gz vs 22–35 KB v3; CSS-first config |

---

## 4. Recommendations per addon

### 4.1 `toolkit-shell` — "headless shell, NDS-skinned"

**Decision:** keep the standalone React 19 esbuild bundle (it embeds on front
end **and** admin; WP-core externals would break front-end embeds), but
replace hand-rolled interactive CSS with Radix primitives + `--nds-*` tokens.

Rationale: the shell's whole value proposition is "one lean bundle, many
surfaces, any WP version". `@wordpress/components` would couple it to
wp-admin-only loading and React 18. Headless Radix keeps the bundle small
while fixing the shell's real gaps (focus management, ARIA, dropdown/dialog
behavior) that hand-rolled CSS cannot provide.

**P0 — foundation (3–5 dev-days)**

1. **Token adoption, zero new deps:** rewrite `src/styles/main.css` to consume
   `--nds-*` variables with wp-admin-colored fallbacks
   (`var(--nds-color-primary, #2271b1)` pattern); keep the `data-theme` dark
   mode but map it onto NDS dark tokens.
2. **Radix primitives** for existing interaction holes: `Dialog` (delete
   confirms), `DropdownMenu` (row actions), `Select` (list filters),
   `Tooltip`, `Tabs`. Add `sonner` (or Radix Toast) for save/delete feedback.
3. **TableView:** swap bespoke table for `@tanstack/react-table` +
   `@tanstack/react-virtual` — sorting, filtering, sticky header; virtualize
   past the 25-row page.
4. **KanbanView:** add `@dnd-kit` drag/drop between columns.
5. **FormView:** Radix `Select`/`Checkbox`/`RadioGroup`; keep
   manifest-driven schema; wire `react-hook-form` + `zod` for validation.

**P1 (2–3 dev-days):** Tailwind v4 (via `@tailwindcss/postcss` esbuild
plugin) with CVA + tailwind-merge; convert primitives to the shadcn file
layout (`src/components/ui/`) so future shells copy them.

**P2 (optional):** command palette (`cmdk`), motion micro-animations gated on
NDS reduced-motion tokens.

**Bundle budget:** stay under ~100 KB gz total (today: React ≈45 KB + CSS).
Radix+CVA+sonner ≈ +20–30 KB; TanStack Table ≈ +15 KB; dnd-kit ≈ +12 KB.

### 4.2 `schedule-anything-spa` — "product SPA on the shadcn methodology"

**Decision:** it's a tenant-facing SaaS product with a public booking portal —
it keeps its own brand layer (Vite + Tailwind) but modernizes: Tailwind v4,
in-repo `components/ui/` primitives (Radix + CVA + tailwind-merge), React Flow
v12, route-split heavy pages, i18n + a11y tooling, NDS token fallbacks.

**P0 (5–8 dev-days)**

1. **Tailwind v3 → v4 migration** (`@tailwindcss/postcss`, CSS-first config).
   Keep preflight scoped to the SPA root class to avoid wp-admin clashes on
   embedded pages.
2. **`src/components/ui/` kit** (shadcn methodology, ~12 primitives): button,
   card, dialog, dropdown-menu, select, tabs, table, badge, input, textarea,
   skeleton, tooltip, popover, form (+ RHF/zod), toast (sonner).
3. **@xyflow/react v12** upgrade in the builder.
4. **Route-level code splitting** (`React.lazy`): `AnalyticsPage` (recharts)
   and `BuilderPage` (xyflow) become lazy chunks; dashboard/schedules stay
   eager. Removes ~200 KB from the critical path.
5. **i18n:** adopt `@wordpress/i18n`; extract all strings; add
   `wp_set_script_translations` + a `languages/` dir on the PHP side.
6. **a11y tooling:** add `vitest-axe` (or `@axe-core/react`) and
   `eslint-plugin-jsx-a11y` — same stack as toolkit-shell/chat-spa.
7. **NDS tokens:** map the SPA's Tailwind theme to `--nds-*` where tokens
   overlap (colors, spacing, motion), so the Design System preset rebrands the
   tenant UI.

**P1 (3–4 dev-days):** TanStack Table on Schedules/History; @dnd-kit for
preset ordering; dark mode via NDS dark tokens.

**P2 (optional):** motion-based booking-page polish; command palette; chart
re-evaluation only if the lazy chunk still exceeds budget.

### 4.3 Cross-cutting decisions

| Topic | Decision |
|---|---|
| Shared `@nvoos/ui` npm package | **No for now.** Follow the shadcn philosophy: a canonical `components/ui/` layout + CVA convention, copied per addon (zero versioning coupling). Revisit when a 3rd SPA adopts the stack. |
| `THIRD_PARTY_NOTICES.md` | `schedule-anything-spa` has none — add one; update `toolkit-shell`'s for Radix/TanStack/dnd-kit (MIT attributions). |
| Bundle-size gate | Add a CI check (esbuild `metafile` / Vite build report) with the budgets above. |
| WP 7.1+ React 19 in core | When core ships React 19, re-evaluate moving admin-only surfaces to the `saas-controller` external pattern; toolkit-shell stays isolated regardless. |

---

## 5. What we explicitly reject (and why)

- **MUI / Ant / Chakra / PrimeReact / HeroUI** — bundle weight + wp-admin
  visual conflict + second theming system on top of NDS.
- **Flowbite React** — dependency rather than owned code.
- **Untitled UI / Opensource UI code** — licensing risk / design-only value.
- **GodUI / Fancy Components** — novelty over utility.
- **@wordpress/components inside `toolkit-shell`** — would break front-end
  embeds and force React 18; the isolation is a feature.
- **New charting stack** — recharts is adequate once lazy-loaded.

## 6. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Dual-React hazard in toolkit-shell (19 vs WP's 18) | Hard rule: toolkit-shell imports only `@wordpress/i18n` (React-free); never `@wordpress/element`/`components`. Add an eslint `no-restricted-imports` guard. |
| Tailwind v4 migration regressions | Preflight scoped to root class; visual diff via existing pages; v4 CSS output is smaller — verify with the size gate. |
| Radix/dnd-kit peer-dep warnings on React 19 | Use `overrides` in package.json (pattern already used repo-wide for dependabot hardening). |
| wp.org Plugin Check | All deps bundled, no CDN; MIT/Apache notices added; licenses are all GPL-compatible. |
| Scope creep (pre-styled frameworks temptation) | The reject list above is the guardrail; P0 commits touch only primitives + tokens. |

## 7. Sources

- WordPress React 19 status: [make/core 2026-05-27](https://make.wordpress.org/core/2026/05/27/react-19-upgrade-in-wordpress/), [make/core 2026-07-24](https://make.wordpress.org/core/2026/07/24/react-19-punted-beyond-wordpress-7-1-experiment-in-gutenberg/)
- Headless comparison: [greatfrontend 2026](https://www.greatfrontend.com/blog/top-headless-ui-libraries-for-react-in-2026), [Radix vs Base UI](https://www.shadcndeck.com/blog/radix-vs-base-ui), [LogRocket](https://blog.logrocket.com/headless-ui-alternatives/)
- Tailwind v4: [release post](https://tailwindcss.com/blog/tailwindcss-v4), size data [tech-insider 2026](https://tech-insider.org/tailwind-css-tutorial-dashboard-v4-2026/)
- In-repo evidence: `addons/saas-controller` (@wordpress/components externals), `addons/nvoos-design-system` (NDS tokens), `addons/chat-spa` (React 19 + zod pattern)

Bundle-size figures above are approximate gzipped estimates for orientation,
not benchmarks.
