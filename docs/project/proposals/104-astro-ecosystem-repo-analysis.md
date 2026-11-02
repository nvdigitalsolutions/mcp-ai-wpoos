# Proposal 104 — Astro Ecosystem Repo Analysis: 15 Themes, What They Prove, and What the Pro Toolkits Should Steal

**Date:** 2026-11-02
**Companion to:** `104-astro-storefront-runtime-hardening-proposal.md` · `-research.md` · `-implementation-plan.md`
**Scope:** Deep read of the 15 Astro repos from the AdminLTE.io 2026 roundup (READMEs, `package.json`, and key implementation files fetched from GitHub 2026-11-02), mapped onto **nvoos-astro-velocity** (the storefront), **nvoos-astro-forge** (the generator/templates), and the **NV oOS Pro toolkits** (SPA v2 consoles, NDS/ADS design system, toolkit dashboards, chat surfaces).

---

## 1. Method

Per repo we read: the README (features + architecture claims), `package.json` (Astro version, dependency choices, script gates), and — where the pattern matters to us — the implementation file that owns it (theme engines, token bridges, config files). Findings below are split into (2) repo-by-repo, (3) cross-cutting patterns, (4–6) the three mappings, (7) a recommendation matrix.

---

## 2. Repo-by-repo findings

| Repo | Astro | License | Load-bearing patterns (implementation-verified where quoted) |
|---|---|---|---|
| **arthelokyo/astrowind** | 7 | MIT | **shadcn/ui-compatible design tokens** exposed from the theme so component libraries pick up brand colors with zero wiring. 30+ page-section widgets, MDX blog with categories/tags/RSS, `astro-seo`, `@astrojs/partytown`, `astro-compress` + sharp/svgo overrides, icon sets via `astro-icon` + `@iconify-json`. `check` gate = `astro check` + eslint + prettier. |
| **withastro/starlight** | 7 | MIT | The reference for **package-based theming**: config-driven sidebar/nav/i18n, search, dark mode out of the box, stored-theme → `prefers-color-scheme` boot. Its significance to us is the *consumption* model (ScrewFast embeds it into a marketing site) — an installed theme that updates via dependency bump, not fork-merge. |
| **satnaing/astro-paper** | 7 | MIT | **The token-bridge pattern, verbatim** (`src/styles/theme.css`): `@theme inline { --color-background: var(--background); … }` registers Tailwind v4 utilities against semantic vars, then `:root, [data-theme="light"] { --background: … }` and `[data-theme="dark"] { … }` hold the values. **This is our ADS architecture (core → semantic → `[data-theme]` overrides) independently confirmed**, plus the missing Tailwind-4 bridge for cross-surface sharing. Also: Pagefind, Satori+Sharp dynamic OG images, i18n-ready, conventional commits, per-post color-scheme customization. |
| **mearashadowfax/ScrewFast** | 7 | MIT | The best **config-driven architecture reference** in the set: `src/data_files/constants.ts` (SITE/OG), `src/data_files/navigation.ts`, typed copy tables (`src/copy/en.ts`/`fr.ts` where **the French table is typed against the English one so a missing translation fails `astro check`**), `src/views/` (one view per route) composing `src/components/sections/` via props with one-line locale page shells, middleware → `Astro.locals`. Centralized `metadata.ts` head builder with a `kind` union (`WebPage | BlogPosting | Article | Product`) — nobody hand-writes schema.org. `AI_GUIDE.md` for coding agents. Security headers in `vercel.json`, post-build HTML minifier, smoke script. |
| **chrismwilliams/astro-theme-cactus** | 7 | MIT | Draft discipline (**drafts filtered from every surface**: listings, OG images, RSS, sitemap — the "filter everywhere, once" rule), frontmatter VSCode snippets, Satori OG images with per-post override, Pagefind with `data-pagefind-body`/`data-pagefind-filter` scoping, Expressive Code with **per-mode code themes** (github-light/dracula), webmentions, deploy gotcha notes (Cloudflare prerenderEnvironment). |
| **zeon-studio/astroplate** | 7 | MIT | 15+ pages, i18n, search, dark mode, Sitepins Git-CMS. **Ships the multi-agent repo layer**: `.agents/`, `.codex/`, `AGENTS.md`, `CLAUDE.md`, `.mcp.json`, `skills-lock.json` — independent ecosystem confirmation of our `AGENTS.md`/`.context`/`.github/agents`/skills layering. |
| **incluud/accessible-astro-starter** | 6→7 | MIT | The **a11y bar we should match**: preference toggles for **dark mode, high contrast, and reduced motion** (with system-preference support), OKLCH color system with **automatic palette generation from primary/secondary colors**, command launcher (Cmd/Ctrl+K), ESLint `jsx-a11y` strict, focus indicators that work on light+dark, skip links, WCAG 2.2 form component library (Form/Input/Textarea/Checkbox/Radio/Fieldset with validation + screen-reader errors), accessibility-statement page, **create CLI with presets** (`full/blog/portfolio/minimal/barebones`) that strips contributor tooling. |
| **oxygenna-themes/foxi** | 6 | MIT | **Theme policy as config**: `mode: 'auto' | 'light' | 'dark'` in `src/config/config.ts` — auto follows system, light/dark **force** the site and render the switcher non-functional. Typed config files (`config.ts`, `navigationBar.ts`, `footerNavigation.ts`, `analytics.ts`, `socialLinks.ts`). Blocks/ui split (`components/blocks/` vs `components/ui/`). |
| **JustGoodUI/dante** | 7 | GPL-3 | Single `site-config.ts` driving nav/branding/hero/social/pagination; `CustomImage` component that **auto-selects Astro `Image` vs plain `<img>` by source** (optimized-when-importable, passthrough otherwise); newsletter form with honeypot spam field; View Transitions + canonical/OG discipline. |
| **Ladvace/astro-bento-portfolio** | 7 | MIT | **The runtime multi-theme engine** (`src/lib/appearance.ts`): named style classes (`style-glass`, `style-sharp`, `style-neon`) + avatar theme classes; `applyAppearance()` = read localStorage → **remove all theme classes → add the active one** (idempotent, safe to re-run on every navigation). SSR (Netlify), islands in SolidJS+Svelte, `motion-gate.ts` gating animation behind reduced-motion, interactive `site-setup` script writing config + `.env`. |
| **themefisher/bookworm-light** | 6 | MIT | Multi-author model (authors index + author pages + post bylines), categories/tags with similar-post suggestions, Fuse.js client search, drafts, Netlify pre-config. |
| **trevortylerlee/astro-micro** | 6 | MIT | Zero UI framework (Nano fork) + Pagefind + Giscus; system/light/dark preconfigured; the "fewest deps that still type-check" floor. |
| **HYP3R00T/CelestialDocs** | 7 | MIT | Multi-collection single repo (docs/blog/product) with **one typed `data/config.ts`**; React + shadcn/ui with "drop in Vue/Svelte/Solid anywhere" claim; Biome for lint/format/import-order (`pnpm validate` gate). |
| **surjithctly/astroship** | 3 | GPL-2 | **The negative example** — two majors behind, View Transitions walled into the $49 Pro tier. Proof that (a) current-major discipline is a product property and (b) we made the right call shipping VT + themes in the OSS storefront. |
| **themesberg/flowbite-astro-admin-dashboard** | 2 | MIT | **The only substantial free Astro admin reference** — see §6.1; it is analyzed as a design/IA reference explicitly, not as installable code (five majors behind). |

---

## 3. Cross-cutting patterns (the ones that matter)

**P1 — Semantic tokens → Tailwind v4 bridge (`@theme inline`).** AstroPaper proves the canonical shape: Tailwind utilities are *registered* against semantic custom properties; the semantic layer is swapped by `[data-theme]` blocks. Our ADS already generates the second half; the first half (a Tailwind bridge file for the Pro SPA v2 / forge templates) is the missing Proposal 097 piece.

**P2 — Runtime theme application = "remove all, add one", re-run on every navigation.** Bento's `applyAppearance()` and AstroPaper's `[data-theme]` blocks converge on exactly the Proposal 104 theme module contract (localStorage source of truth; idempotent apply; re-apply after swap). Independent confirmation of the fix design.

**P3 — Named-theme registry as product surface.** Bento (glass/sharp/neon), AstroPaper (predefined color schemes), and our ADS (`default`/`example-brand`) all treat **theme choice as user-facing data** — a registry of named themes, not a hardcoded two-state toggle. Our ThemeToggle cycling a hardcoded 3-list is the v0 of this; the registry should derive from the ADS theme store.

**P4 — Theme policy as config.** Foxi's `mode: auto|light|dark` (site forces light, switcher disabled) is the storefront-level analog of the Pro plugin's read-only profile gate and the bridge's product-source options — an owner-set policy layer above user preference. Starlight adds the third value via its `disable404Route`-style config surface. Worth a bridge setting: `storefront_theme_policy = auto | light | dark | brand`.

**P5 — Single typed config + data files.** ScrewFast (`constants.ts` + `copy/`), Dante (`site-config.ts`), Foxi (`src/config/*`), CelestialDocs (`data/config.ts`), Astro Cactus (`site.config.ts`) — every maintained theme converged on **one typed config object plus content-as-data**, with content collections for the rest. The forge's `site.json`/`nvoos.json` is this pattern; the missing piece is the typed copy-table + `kind`-based metadata layer.

**P6 — Typed i18n completeness gate.** ScrewFast's "French table typed against English, missing translation = `astro check` failure" is the compile-time version of our `wp-i18n-audit` discipline (runtime/manual). Adoptable by both the forge templates (typed copy tables) and — conceptually — the Pro SPA v2 console copy.

**P7 — Metadata is centralized, `kind`-typed.** ScrewFast's `metadata.ts` (`title | section | site`, `kind: WebPage|BlogPosting|Article|Product`) matches our `jsonld.ts`/`meta.ts`; the `kind` union + never-hand-write-schema.org rule is a clean upgrade for velocity (add `CollectionPage` for PLPs) and the forge.

**P8 — Accessibility is a toggle set, not one switch.** Accessible Starter's dark / high-contrast / reduced-motion preference trio + OKLCH palette generation + a11y form library is the benchmark. Our ADS has dark; high-contrast is a natural third axis (`data-contrast="high"` overriding the semantic layer), and the PLP filter form should meet their form-validation bar.

**P9 — Draft/content discipline is "filter everywhere, once."** Cactus: drafts excluded from listings, OG, RSS, sitemap in one shared predicate. Our bridge has draft status (PR #7331) but the velocity sitemap/related-products surfaces must honour it the same way — a shared `isPublished` predicate.

**P10 — AI-assistant friendliness is table stakes.** ScrewFast's `AI_GUIDE.md`, Astroplate's `.agents/`+`.codex/`+`skills-lock.json` — the ecosystem now ships agent context as part of the template. Our AGENTS.md/CLAUDE.md/.context/skills layering is ahead of both; the forge's generated projects should ship a minimal `AGENTS.md`/`.context` stub by default.

**P11 — Preset CLI as onboarding.** Accessible Starter's `create` CLI (full/blog/portfolio/minimal/barebones, strips contributor tooling) is the forge wizard's direct peer — validates `forge wizard` and suggests preset profiles as its next increment.

---

## 4. Mapping → nvoos-astro-velocity (the storefront)

| Finding | Action | Status |
|---|---|---|
| P2 theme engine | Already designed in Proposal 104 §3.1 (`src/lib/theme/theme.ts`) — bento/AstroPaper confirm the contract; add the "remove-all-then-add" idempotency note to the implementation plan | In plan (P1) |
| P1 token bridge | Ship a `tokens-tailwind.css` generator output (`@theme inline` over `--ads-semantic-*`) so the velocity app *and* future Tailwind consumers share one layer — feeds Proposal 097 | Add as 104 P3-stretch |
| P3 named-theme registry | Derive ThemeToggle's cycle list from the ADS theme store (`TokenService.themes()`) instead of the hardcoded 3-list; toggle becomes a menu when > 3 themes | Add as 104 P1-stretch |
| P4 theme policy | Bridge option `funiq_bridge_theme_policy` (auto/light/dark) surfaced in the `/manage` settings → boot script reads it before user preference | New owner decision Q6 |
| P7 `kind` metadata | Extend `src/lib/seo/jsonld.ts` with a `kind`-driven builder; add `CollectionPage` JSON-LD to PLPs | 104 P3-stretch |
| P8 a11y toggle set | High-contrast axis (`data-contrast="high"`) + reduced-motion wiring on the theme module; PLP filter form a11y audit | 104 P3-stretch |
| P9 draft discipline | `isPublished` predicate shared by sitemap, related-products, and the bridge's `status=any` handling | 104 P2 note |
| P5/P6 | ScrewFast-style copy tables only if i18n lands (currently English-only) — record as forge-following, not now | Defer |

## 5. Mapping → nvoos-astro-forge (generator + templates)

| Finding | Action |
|---|---|
| P5 single typed config | The forge's `site.json`/`nvoos.json` is validated by five independent themes; add the typed `copy` table + section-driven page composition (ScrewFast views/sections) to the **storefront template** increment |
| P6 typed i18n gate | When a customer needs bilingual output, the forge should emit ScrewFast-style typed copy tables — a missing translation fails `astro check` in the generated project |
| P7 `kind` metadata | The blog template's hand-rolled JSON-LD becomes the same `kind`-typed builder (BlogPosting/Product/WebPage) — one shared util vendored like `sanitize.ts` |
| P10 agent layer | Generated projects ship a minimal `AGENTS.md` + `.context/` stub (Astroplate pattern) — costs one scaffold file, makes every forged repo AI-ready |
| P11 preset CLI | `forge wizard` gains presets mirroring Accessible Starter (blog / storefront / minimal) — selects template + collections + optional surfaces |
| P2 theme runtime | The Proposal 104 theme module (§3.5 contract) ports verbatim when the storefront template gains View Transitions + runtime theming |
| P1 token bridge | The forged template's Tailwind layer registers `@theme inline` over the `--forge-*` tokens — brand themes flow into utility classes, not just custom properties |
| P9 draft discipline | Content-layer loader excludes drafts by default (already the case) — extend to OG/sitemap/RSS with the same shared predicate |

## 6. Mapping → the Pro toolkits

### 6.1 Flowbite Astro Admin Dashboard (user-flagged, analyzed in depth)

15 pages: two dashboard layouts (charts/widgets/tables/datepickers/stat cards), **CRUD products + CRUD users** (list/detail/modal flows), settings, six auth screens (sign-in/sign-up/reset/forgot/profile-lock), maintenance, 404/500, playground. Structure: `components/` (atomic UI) · `modules/` (complex views) · `lib/` (utilities) · `services/` (server-side CRUD) · `pages/api/[...entities].ts` (catch-all REST) · `types/`. Tooling: strictest TS, eslint + astro parser, prettier, editorconfig, VS Code recs, GH workflow; ApexCharts for dataviz; static-first with one-line `output: 'server'` flip.

**Direct parallels to the Pro toolkit stack** (`addons/pro/assets/spa-v2/`, Complete Console, toolkit dashboards):

| Flowbite surface | Pro toolkit analog | What to adopt |
|---|---|---|
| Dashboard layouts (stacked/sidebar) | Console app shells (Complete Console, toolkit dashboards) | The sidebar/stacked IA variants as reference frames — we already have shell choices; compare against them during console work |
| CRUD list → detail → modal/edit flows | CRM leads/deals, project-management tasks, F&B tables, security ops records | The **modal-based edit flow over full-page forms** for lightweight records; we overuse full pages in some toolkits |
| Settings page shape | Settings page/side-menu + per-toolkit status maps | Grouped-settings layout (sections → cards → per-row toggles) as the reference for the toolkit settings surface |
| Auth screens (6 states incl. profile-lock) | SPA v2 login/`ManageGate`/Docs Hub auth | The **profile-lock + password-reset states** — our auth surfaces only model the happy path + 401 wall |
| Error/empty states (404/500/maintenance) | Every console route | Standardize an `EmptyState`/`ErrorState` component pair with NDS tokens |
| `services/` + catch-all REST + `types/` split | `spa-v2/src/api/*.ts` + REST contract audit discipline | Confirms our api-client/service/type layering; adopt the **catch-all-with-per-entity-services** convention when a console grows past 6 endpoints |
| ApexCharts widget set | Toolkit dashboards (F&B analytics, CRM pipeline, security posture) | Chart-type coverage list (area/bar/donut/sparkline/radial) as the widget catalogue to mirror with `nvoos-chart` fences in chat + React charts in consoles |

**Verdict:** Flowbite admin is a *design and IA reference*, not code to port (Astro 2, Flowbite, five majors back). Its page inventory is the checklist for our console/toolkit dashboards; its structure maps 1:1 onto the SPA v2 conventions.

### 6.2 Accessible Astro ecosystem (incluud) → console + chat surfaces

- **Command launcher (Cmd/Ctrl+K)** — the single most reusable idea for the **chat UI**: NV oOS chat surfaces (Complete Console chat, docs-hub assistant) would get an assistant/tool/settings quick-palette. Its `LauncherConfig.astro` (6 KB) shows the config surface shape.
- **A11y form component library** (Form/Input/Textarea/Checkbox/Radio/Fieldset with WCAG 2.2 validation + screen-reader errors) — the Pro SPA v2 form primitives should be audited against this set; toolkit dashboards (CRM forms, schedule-anything) inherit the improvements.
- **Preference toggles (dark/high-contrast/reduced-motion)** — the Complete Console should respect the storefront's theme choice (cross-surface consistency, Proposal 097) and add high-contrast as a shared NDS axis.
- **OKLCH + palette auto-generation from primary/secondary** — the strongest candidate for the NDS token pipeline's next increment (Proposal 047/058 territory): generate a full semantic palette from two brand colors, WCAG-checked, instead of hand-authoring. The ADS contrast tooling already does the verification half.

### 6.3 Starlight/ScrewFast → docs + console copy

- **Starlight's package-based theming** validates the docs-hub (0.5.3) + NDS direction; ScrewFast's embedding (marketing site wrapping Starlight docs) is the exact pattern for a future velocity/docs-hub shared shell.
- **Typed copy tables** (ScrewFast) — the SPA v2 consoles hardcode English strings; a typed `copy.ts` per console would make the Pro plugin's `languages/` translation pipeline reach the SPAs for the first time. Worth a scoping note, not a silent adoption.

---

## 7. Recommendation matrix

| # | Adoption | Where | Source pattern |
|---|---|---|---|
| 1 | **Adopt now (104 P1)** | velocity theme module — "remove-all/add-one" idempotency + re-apply | bento `appearance.ts`, AstroPaper `theme.css` |
| 2 | **Adopt now (104 P1-stretch)** | ThemeToggle reads ADS theme registry (menu > 3) | bento style registry, AstroPaper schemes |
| 3 | **Adopt (104 P3-stretch)** | `@theme inline` Tailwind bridge over `--ads-*`/`--forge-*` | AstroPaper `theme.css`, AstroWind shadcn tokens |
| 4 | **Adopt (104 P3-stretch)** | `kind`-typed metadata builder + `CollectionPage` | ScrewFast `metadata.ts` |
| 5 | **Adopt (104 P3-stretch)** | High-contrast + reduced-motion axes; PLP form a11y | Accessible Starter |
| 6 | **Adopt (104 P2 note)** | `isPublished` predicate everywhere | Cactus draft discipline |
| 7 | **Owner decision Q6** | Bridge `theme_policy` option (auto/light/dark/brand) | Foxi `mode`, Starlight config |
| 8 | **Forge increment** | Preset wizard + typed copy tables + minimal `AGENTS.md` stub in generated projects | Accessible Starter CLI, ScrewFast, Astroplate |
| 9 | **Forge increment** | Shared `kind` metadata util vendored like `sanitize.ts` | ScrewFast |
| 10 | **Pro toolkit increment** | Command palette for chat surfaces | Accessible Starter launcher |
| 11 | **Pro toolkit increment** | Console page inventory checklist (dashboards/CRUD/settings/auth/error states) | Flowbite admin |
| 12 | **Pro toolkit increment** | Modal-based edit flows for lightweight records; settings-page grouping | Flowbite admin |
| 13 | **Defer (scope)** | OKLCH palette auto-generation → NDS next increment | Accessible Starter |
| 14 | **Defer (scope)** | SPA copy tables + typed i18n → its own proposal | ScrewFast |
| 15 | **Reject** | Astroship (Astro 3, VT behind paywall) · Dante/Astroship GPL licensing for anything we redistribute | — |

---

## Sources (all fetched 2026-11-02)

1. arthelokyo/astrowind — `package.json` (Astro 7.3, shadcn-token claim, check/fix gates)
2. withastro/starlight — README (package-based theming model)
3. satnaing/astro-paper — README + `src/styles/theme.css` (`@theme inline` bridge, `[data-theme]` blocks)
4. mearashadowfax/ScrewFast — README (views/sections/copy tables/typed i18n/`metadata.ts`/AI_GUIDE.md)
5. chrismwilliams/astro-theme-cactus — README (draft discipline, Satori OG, dual code themes)
6. zeon-studio/astroplate — root tree (`.agents/`, `.codex/`, `AGENTS.md`, `CLAUDE.md`, `.mcp.json`, `skills-lock.json`)
7. incluud/accessible-astro-starter — README + `src/components/` listing (preference toggles, OKLCH, launcher, a11y forms, preset CLI)
8. oxygenna-themes/foxi-astro-theme — README (`mode` policy, `src/config/*`)
9. JustGoodUI/dante-astro-theme — README (`site-config.ts`, `CustomImage`, honeypot)
10. Ladvace/astro-bento-portfolio — README + `src/lib/appearance.ts` (named-style runtime theming, `motion-gate.ts`)
11. themefisher/bookworm-light-astro — README (multi-author, Fuse.js, similar posts)
12. trevortylerlee/astro-micro — README (zero-framework + system/light/dark)
13. HYP3R00T/CelestialDocs — README (multi-collection, `data/config.ts`, Biome)
14. surjithctly/astroship — README (negative example: Astro 3, GPL, VT in Pro tier)
15. themesberg/flowbite-astro-admin-dashboard — README (15-page admin inventory, structure, tooling) — **§6.1 analysis**
16. AdminLTE.io — *15 Best Free Astro Templates for Developers (2026)* (roundup context)
