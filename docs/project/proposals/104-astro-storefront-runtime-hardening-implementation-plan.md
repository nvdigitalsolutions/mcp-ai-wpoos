# Proposal 104 — Implementation Plan: Storefront Runtime Hardening (Integrated — Velocity + Forge)

**Date:** 2026-11-02 (v2 — adopt-now stretch items from the ecosystem repo analysis integrated)
**Companion to:** `104-astro-storefront-runtime-hardening-proposal.md` (decisions) · `104-astro-storefront-runtime-hardening-research.md` (sourcing) · `104-astro-ecosystem-repo-analysis.md` (the 15-repo read whose recommendation matrix feeds every phase below)

**Scope note:** this plan covers the **velocity storefront** (Phases 1–3) and the **forge generator + templates** (Phase 4), per the user's direction. The three Pro-toolkit increments from the recommendation matrix (command palette for chat surfaces, console page inventory, modal-based edit flows) touch `addons/pro` SPA code and remain a **separate track** — they are recorded in the repo analysis §7 (rows 10–12) for a follow-up proposal.

Each phase carries its own exit gate. P1 and P2 are independent and may land as one PR cluster; P3 follows; P4 (forge) can ship in parallel with P3.

---

## Dependency decisions — hand-rolled vs package (D-series)

Every hand-rolled piece in this plan was checked against the current npm ecosystem (verified 2026-11-02). The rule: **adopt a package when it is maintained, single-purpose, and removes real risk; keep hand-rolled when the zero-dependency property is the feature** (no-FOUC inline scripts, generated artifacts, contract-pinned fetch paths).

| # | Piece (phase) | Decision | Package (verified) | Rationale |
|---|---|---|---|---|
| D1 | Theme boot + apply module (P1) | **Keep hand-rolled** | — (Starlight/Paper/bento also hand-roll; no maintained standalone theme-runtime lib exists) | Must inline into `<head>` for no-FOUC; ~60 lines; the e2e pins behaviour. A package would add a build step to a pre-paint script. |
| D2 | Toggle icons (P1) | **Adopt (optional)** | `astro-icon` (used by AstroWind/Cactus/Accessible Starter) | Icon-set access for the toggle/menu without vendoring SVGs; optional — text labels already meet WCAG. |
| D3 | Commerce envelope validation (P2) | **Adopt** | `zod` **v4 — already a dependency** (mcp surface) | Replace the hand-rolled `assertEnvelope` in `client.ts` with a typed `z.object` envelope parse; same loud-failure contract, real type narrowing, zero new deps. |
| D4 | URL slugging (P2, velocity + forge) | **Adopt** | `limax` 4.x (AstroWind's own slugger) | Hand-rolled `slugify()` mangles non-Latin names (é→dropped, ß, CJK). `limax` transliterates per Unicode; keep the 80-char cap + `'item'` fallback wrapper. |
| D5 | JSON-LD builders (P3 velocity, P4 forge) | **Adopt** | `schema-dts` 2.1.0 (Google-maintained; published 13 days ago; types-only, zero runtime bytes) | The `kind` builder becomes typed against the real Schema.org vocabulary — `WithContext<Product>` catches `offers`/`aggregateRating` drift at `astro check` time. |
| D6 | A11y gate (P3) | **Adopt** | `@axe-core/playwright` (Deque) | Lighthouse a11y stays the baseline; axe adds machine-checkable WCAG 2.2 assertions per route (names, landmarks, contrast) in CI. |
| D7 | Sitemap (P2/P3) | **Keep hand-rolled** (deliberate) | — (`@astrojs/sitemap` only lists prerendered pages) | The SSR sitemap is the whole point (Proposal 092 §5.4 D11); no package can do it. |
| D8 | Forge CLI parsing + wizard prompts (P4) | **Adopt** | `commander` + `@clack/prompts` (the create-CLI standard) | `forge.mjs` arg handling and `wizard` prompts become standard, arrow-key navigable, and consistent with `create-astro`. Plain-JS constraint of `.mjs` files stays. |
| D9 | Copy tables / i18n (P4 forge) | **Keep hand-rolled as the zero-dep floor; package as the upgrade path** | `@inlang/paraglide-js` (compiler-based; Astro guide at paraglidejs.com/astro) | Typed `Copy` tables need no deps and already fail `astro check` on missing keys. When a customer needs real multi-locale routing + tree-shaken messages, the forge **emits Paraglide wiring** instead. |
| D10 | Dynamic OG images (P4 stretch, Q5) | **Adopt when taken** | `satori` + `sharp` + `@resvg/resvg-js` (+ `@fontsource` fonts) — the AstroPaper/Cactus stack | Per-product/per-post branded OG cards; sharp is already in the tree (astro-compress override precedent). Owner-optional; see 4.8. |
| D11 | Forge `tokens_apply` WCAG gate (P4) | **Audit → adopt if math is hand-rolled** | `wcag-contrast` (single-purpose, tested) | The gate warns on failing pairs; if it hand-rolls ratio math, swap to the package — same output, less risk. |
| D12 | BFF security headers (P5) | **Keep hand-rolled constants** (no package) | — (`helmet` is express/connect-shaped; the BFF is `node:http`) | A small typed headers list (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy) on `serve.mjs`. CSP must allow the inline theme boot via hash/nonce — tune, don't blanket `unsafe-inline` (see 5.1). |
| D13 | Commerce fetch client (P2) | **Rejected (deliberate)** | `ofetch`/`ky` considered | The client is contract-pinned and deliberately thin — retry/timeout sugar would mask bridge failures; the B1 `s-maxage` layer is the resilience mechanism. Recorded so the question doesn't reopen. |


---

## Phase 1 — Theme runtime contract (velocity, Defect A + analysis rows 1–2)

### 1.1 New module `src/lib/theme/theme.ts` (pure, zero Astro imports — forge-portable)

```ts
// Single source of truth for the client theme. No Astro imports — the forge
// template copies this module verbatim (Proposal 104 §3.5). Pattern follows
// the ecosystem norm (astro-bento-portfolio appearance.ts / AstroPaper
// [data-theme] blocks): localStorage is truth, application is idempotent,
// re-application happens on every navigation.
export const THEME_STORAGE_KEY = 'nvoos-theme';
export const CONTRAST_STORAGE_KEY = 'nvoos-contrast';
export const CONTRAST_MODES = ['standard', 'high'] as const;
export type ContrastMode = (typeof CONTRAST_MODES)[number];

/** 'light' is implicit (absent data-theme); registry-derived names extend it. */
export type Theme = string;

export function resolveStoredTheme(
	storage: Pick<Storage, 'getItem'> | null,
	media: Pick<MediaQueryList, 'matches'> | null,
	registry: readonly string[]
): Theme {
	let stored: string | null = null;
	try {
		stored = storage?.getItem(THEME_STORAGE_KEY) ?? null;
	} catch { /* storage unavailable */ }
	if (stored && (stored === 'light' || registry.includes(stored))) {
		return stored;
	}
	return media?.matches ? 'light' : 'dark';
}

/** Idempotent by construction: single attribute, set-or-remove (the
 *  ecosystem's "remove all, add one" collapses to one attribute here). */
export function applyTheme(theme: Theme, root: HTMLElement = document.documentElement): void {
	if (theme === 'light') {
		root.removeAttribute('data-theme'); // contract: absent = light (theme.spec.ts L9)
	} else {
		root.setAttribute('data-theme', theme);
	}
	root.style.colorScheme = theme === 'dark' ? 'dark' : 'light';
}

/** High-contrast axis (Accessible Starter pattern) — independent of theme. */
export function applyContrast(mode: ContrastMode, root: HTMLElement = document.documentElement): void {
	if (mode === 'high') {
		root.setAttribute('data-contrast', 'high');
	} else {
		root.removeAttribute('data-contrast');
	}
}

export function persistTheme(theme: Theme, storage: Pick<Storage, 'setItem'> | null): void {
	try {
		storage?.setItem(THEME_STORAGE_KEY, theme);
	} catch { /* storage unavailable */ }
}

export function persistContrast(mode: ContrastMode, storage: Pick<Storage, 'setItem'> | null): void {
	try {
		storage?.setItem(CONTRAST_STORAGE_KEY, mode);
	} catch { /* storage unavailable */ }
}
```

### 1.2 New module `src/lib/theme/registry.ts` — theme list derives from the ADS bundle

The toggle must stop hardcoding `['light', 'dark', 'example-brand']` (analysis row 2): the ADS theme store is the source of truth.

```ts
// Theme names derive from the generated ADS bundle (bundle.generated.json →
// themes.*). Single source of truth; a brand theme added via the /mcp
// theme_create flow shows up here on the next build.
import bundle from '../../tokens/bundle.generated.json';

export const BRAND_THEMES: readonly string[] = Object.keys(
	(bundle as { themes?: Record<string, unknown> }).themes ?? {}
).filter((name) => name !== 'default');

export const ALL_THEMES: readonly string[] = ['light', 'dark', ...BRAND_THEMES];

/** Cycle target — pure, unit-testable (the >3 menu threshold lives in the UI). */
export function nextTheme(current: string, registry: readonly string[]): string {
	const themes = ['light', ...registry.filter((name) => name !== 'light')];
	const index = themes.indexOf(current);
	return themes[(index + 1) % themes.length];
}
```

Note: `default` is the ADS light/dark carrier (its light block is `:root`, dark rides `data-theme="dark"`) — it maps to the built-in light/dark, not a selectable brand.

### 1.3 `src/layouts/BaseLayout.astro` — extend the boot (keep it `is:inline`, head)

The inline boot uses the shared logic (inlined as a string, no imports in the raw script) + the one-time `astro:after-swap` registration + the contrast axis:

```js
(function () {
	var KEY = 'nvoos-theme', CKEY = 'nvoos-contrast';
	var REGISTRY = ['light', 'dark', '__BRAND_THEMES__']; // substituted by the layout frontmatter
	function stored(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }
	function resolveTheme() {
		var s = stored(KEY);
		if (s && REGISTRY.indexOf(s) !== -1) return s;
		return (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
	}
	function apply() {
		var t = resolveTheme();
		if (t === 'light') document.documentElement.removeAttribute('data-theme');
		else document.documentElement.setAttribute('data-theme', t);
		document.documentElement.style.colorScheme = t === 'dark' ? 'dark' : 'light';
		if (stored(CKEY) === 'high') document.documentElement.setAttribute('data-contrast', 'high');
		else document.documentElement.removeAttribute('data-contrast');
	}
	apply();
	// document survives the swap; the element does not — re-apply before paint
	// on every client-side navigation (Astro docs' dark-mode pattern).
	document.addEventListener('astro:after-swap', apply);
})();
```

The layout frontmatter computes the substitution from the registry module:

```ts
const brandThemes = BRAND_THEMES.map((name) => JSON.stringify(name)).join(',');
// …inline script content replaces the '__BRAND_THEMES__' marker…
```

Repo lessons that apply (do not re-hit): keep `var`/`catch (e)` semantics (the #7302 `? 'light' : 'light'` regression); keep the ternary meaningful; the unit test below pins the dark fallback.

### 1.4 `src/components/ThemeToggle.astro` + new `src/components/ContrastToggle.astro`

**ThemeToggle** — registry-driven state (analysis row 2):
- Import `ALL_THEMES`, `nextTheme`, `resolveStoredTheme`, `applyTheme`, `persistTheme` from `../lib/theme/*`.
- `currentTheme()` reads `resolveStoredTheme(localStorage, matchMedia, ALL_THEMES)` — the storage value, never the swapped-away DOM attribute (ordering-independent vs. the boot listener).
- **UI shape:** ≤ 3 themes (light + dark + ≤ 1 brand) → keep the single-button cycle; **> 3 themes → a `<button>` + `<ul role="menu">` list** (the bento/Starlight pattern), each item calling `applyTheme` + `persistTheme` and syncing the label.
- On module run, sync the label from the resolved theme (bundled scripts re-run per navigation).
- `aria-pressed` on the cycle button; menu items `role="menuitemradio"` + `aria-checked`; the label text = theme name.
- All transition polish gated on `prefers-reduced-motion` (see 1.6).

**ContrastToggle** (new, a11y axis — analysis row 5): a small sibling button (`data-contrast-toggle`, `aria-pressed`) cycling `standard ↔ high` via `applyContrast`/`persistContrast`, labelled "High contrast". Placed next to ThemeToggle in the header.

### 1.5 CSS fallbacks — `src/styles/global.css`

```css
:root { color-scheme: light; }
[data-theme="dark"] { color-scheme: dark; }

/* High-contrast axis: full-ink muted text, strong borders — built from the
   existing ADS semantic tokens (muted → ink, line → line-strong). No new
   token values; WCAG-gated by the contrast audit on the mapped pairs. */
[data-contrast="high"] {
	--nv-muted: var(--nv-ink);
	--nv-line: var(--ads-semantic-line-strong);
}
```

### 1.6 Reduced-motion gate (analysis row 5)

- `global.css`: `@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: 0.01ms !important; transition-duration: 0.01ms !important; scroll-behavior: auto !important; } }` — the ecosystem-wide kill switch (Accessible Starter / bento `motion-gate.ts`). Scoped to velocity (View Transitions remain functional — they are navigation, not animation).

### 1.7 Tests

- **`tests/unit/theme.test.ts`:** `resolveStoredTheme` (stored valid/invalid/missing → media fallback both ways; registry filtering), `applyTheme` (attribute set/removed; colorScheme per theme; idempotent re-application), `applyContrast`/`persistContrast`, `nextTheme` (cycle wraps, > 3 registry, brand at end), `BRAND_THEMES` derives from the bundle (drift pin — fails if tokens-build stops emitting themes).
- **Extend `tests/e2e/theme.spec.ts`:**
  - theme persists across client-side navigation (`/tokens/` → toggle dark → click internal link → attribute + `colorScheme` + label survive; navigate again → still dark);
  - toggle continues from the stored theme after navigation (proves storage, not attribute, is read);
  - high-contrast axis: toggle on → `data-contrast="high"` → navigate → survives; reload → survives;
  - stored theme + contrast on hard load (extend the existing no-FOUC test).

### 1.8 Gate

`NODE_OPTIONS=--max-old-space-size=8192 npm run check && npm run lint && npm run test` (Windows heap note) + `npm run test:e2e` against the fixture BFF. LHCI a11y ≥ 0.9 must stay green (the menu/toggle additions are audited markup).

---

## Phase 2 — PDP on-demand SSR + caching + publication discipline (velocity, Defect B + analysis row 6)

### 2.1 `src/lib/commerce/publish.ts` (new) — the Cactus draft discipline, shared

```ts
// Draft discipline (astro-theme-cactus pattern): ONE predicate, used by
// every surface that lists or links a product. Native docs carry
// status: 'publish' | 'draft'; woo docs carry any WP status; a MISSING
// status means published (backward-compatible with older bridge output).
export function isPublished(doc: { status?: string }): boolean {
	return doc.status === undefined || doc.status === 'publish';
}
```

Consumers: the PDP (2.2), related products (2.2), `sitemap.xml.ts` (filter before mapping), and — as a documented contract note — the bridge's `status=any` manager-only listing already stays out of the storefront (default query omits `status`, which the backend resolves to publish).

### 2.2 `src/pages/products/[id]/[...slug].astro`

Rewrite the frontmatter (full sketch in the proposal; deltas integrated here):

```astro
---
// On-demand SSR (Proposal 104 §3.2) + publication guard + real 301s.
import BaseLayout from '../../../layouts/BaseLayout.astro';
import { getProduct, getProducts } from '../../../lib/commerce/client';
import { isPublished } from '../../../lib/commerce/publish';
import { normalizeProductMedia, sanitizeDescription } from '../../../lib/commerce/normalize';
import { formatPrice, resolveCurrency } from '../../../lib/commerce/money';
import { categoryPath, productPath, slugify, absoluteUrl } from '../../../lib/url';
import { breadcrumbJsonLd, productJsonLd } from '../../../lib/seo/jsonld';
import { metaForProduct } from '../../../lib/seo/meta';
import { fixedImage, renderOrigin, responsiveImage } from '../../../lib/images';

Astro.response.headers.set(
	'Cache-Control',
	'public, s-maxage=300, stale-while-revalidate=600'
);

const id = Number(Astro.params.id ?? 0);
if (!Number.isInteger(id) || id <= 0) {
	return new Response('Not found', { status: 404 });
}

const raw = await getProduct(id, Astro.url);
// Publication guard: a draft (or non-publish WP status) must 404 to
// storefront visitors even when the id is guessed — the Cactus rule.
if (!raw || !isPublished(raw)) {
	return new Response('Product not found', { status: 404 });
}

// SSR makes canonical-slug 301s possible (the prerender could not).
const requestedSlug = String(Astro.params.slug ?? '').replace(/\/+$/, '');
if (requestedSlug !== slugify(raw.name)) {
	return Astro.redirect(productPath(id, raw.name), 301);
}

if (Astro.cache.enabled) {
	Astro.cache.set({ maxAge: 300, swr: 600, tags: ['products', `product-${id}`] });
}

const product = normalizeProductMedia(raw);
const site = Astro.site ?? new URL('https://store.nvoos.cloud');

// Related at request time, publication-filtered, self-excluded.
const related = product.category
	? (
		await getProducts(Astro.url, {
			page: 1,
			limit: 8,
			where: { category: { equals: product.category.id } },
			sort: '-date',
		})
	).docs
		.filter((candidate) => candidate.id !== product.id && isPublished(candidate))
		.map(normalizeProductMedia)
	: [];
// …breadcrumbs/jsonld/gallery identical to today (props: raw → product)…
---
```

Keep: `getStaticPaths` removed; template body unchanged except `related`/`product`; the `[...slug]` param is a flat slash-joined **string** in Astro 7 (the forge's documented gotcha — the `.replace()` handles the trailing-slash form).

### 2.3 `src/pages/sitemap.xml.ts` — publication filter

`...products.filter(isPublished).map(...)` before building entries. (Draft discipline surfaces #2.)

### 2.4 `astro.config.mjs` — cache provider + route rules

```js
import { defineConfig, memoryCache } from 'astro/config';
// …
export default defineConfig({
	cache: { provider: memoryCache() },
	routeRules: {
		// Config-level defaults; per-page Astro.cache.set merges (tags accumulate).
		'/products/[...path]': { maxAge: 300, swr: 600, tags: ['products'] },
	},
	// …existing site/output/adapter/image/integrations unchanged…
});
```

### 2.5 `.github/workflows/sync-nvoos-astro-velocity.yml` — hermetic production build

"Assemble mirror tree" step: **remove** `NVOOS_API_BASE` from `env` (keep `ASTRO_SITE`). The production rebuild now needs no backend at all; the fixture base stays exclusively on the localhost smoke/e2e build step (unchanged). Update the step comment to state the invariant: *the prod artifact must never bake a build-time commerce base; commerce binds to the runtime `/api/*` proxy only.*

### 2.6 Tests

- **Extend `tests/e2e/crawlability.spec.ts`** (or new `tests/e2e/pdp.spec.ts`):
  - PDP 200 + heading + JSON-LD (existing test stays green — now via SSR);
  - unknown id → 404 (`/products/999999/whatever/`);
  - wrong slug → 301 to the canonical;
  - sitemap PDP URLs resolve: fetch `/sitemap.xml`, extract a `/products/` loc, GET it → 200.
- **Unit:** `tests/unit/publish.test.ts` (`isPublished` truth table incl. missing status) + extend `tests/unit/client.test.ts` base-resolution block with the "no `getStaticPaths` at build" documentation case.
- **Draft e2e (golden-safe option):** the goldens (`tests/fixtures/products.json` ↔ `TestTransformerGolden`) are the contract — a draft fixture doc is only added if the bridge golden test is extended in the same PR and `npm run sync:bridge-fixtures` shows a format-only reflow for existing docs. Otherwise the publication guard is unit-covered (predicate) + exercised live post-deploy; do not bend the golden invariant.

### 2.7 Gate

Full hermetic loop unchanged: fixture server → localhost-only `NVOOS_API_BASE` build → `NVOOS_TARGET_SITE=http://localhost:8123 PORT=4321 npm run serve` → `npm run smoke` → `npm run test:e2e` → LHCI.

### 2.8 Package work (D3, D4)

- **D3 — zod envelope:** `src/lib/commerce/client.ts` replaces the hand-rolled `assertEnvelope` with a `z.object({ docs: z.array(z.unknown()), totalDocs: z.number(), totalPages: z.number(), page: z.number() })` parse (zod v4 already in dependencies). Same loud-failure contract (prefixed error, never silent), real type narrowing at the call sites. `tests/unit/client.test.ts` pins the error path.
- **D4 — limax slugs:** `src/lib/url.ts`'s `slugify()` becomes a thin wrapper over `limax` (80-char cap + `'item'` fallback preserved — existing call sites and `productPath`/`categoryPath` unchanged). Unit test adds transliteration cases (`Café Crème` → `cafe-creme`, `Straße` → `strasse`). The forge's payload-mode slug helper (missing-slug fallback, Phase 1d) gets the same swap in Phase 4 so both apps slug identically — one canonical URL algorithm across the ecosystem.

---

## Phase 3 — Catalog surface + metadata/accessibility stretches (velocity, analysis rows 3–5)

### 3.1 New `src/pages/products/index.astro`

Clone the category PLP's proven pattern (SSR + `s-maxage` + canonical + `noindex,follow` on filters): filters category/brand/color + `q` + sort + `PAGE_SIZE` pagination via `getProducts` where-clauses; heading "All products"; cache tags `['products']` behind `Astro.cache.enabled`.

**A11y pass on the filter form (Accessible Starter bar):** wrap the color checkbox group in `<fieldset>` + `<legend>` ("Filter by colour"), visible `<label>` per chip (not just the swatch), `aria-sort` on the sort control is unnecessary (native select) but a visible `<label for>` on every select; noscript submit stays. Playwright asserts each input has an accessible name.

### 3.2 Wiring

- `src/layouts/BaseLayout.astro` nav: add `<a href="/products/">Products</a>` (first position in `.site-nav`).
- `src/pages/sitemap.xml.ts`: add `{ loc: absoluteUrl(base, '/products/') }` to the base entries.
- `src/pages/categories/index.astro` and `brands/index.astro`: cross-link "Browse all products".

### 3.3 `kind`-typed metadata builder (analysis row 4, D5) — `src/lib/seo/jsonld.ts` refactor

ScrewFast `metadata.ts` shape: a `kind` union drives the head instead of ad-hoc builders — and the objects are **typed with `schema-dts`** (types-only, zero runtime bytes) so `offers`/`aggregateRating`/`breadcrumb` drift fails `astro check`:

```ts
export type StructuredKind =
	| { type: 'WebPage' }
	| { type: 'CollectionPage'; name: string; url: string; breadcrumb: BreadcrumbEntry[] }
	| { type: 'BlogPosting'; /* …existing post fields… */ }
	| { type: 'Product'; product: Product; site: URL; currency: string };

/** The one builder every route calls — no hand-written schema.org objects. */
export function jsonLdFor(kind: StructuredKind): Record<string, unknown>[];
```

Existing `productJsonLd` / `breadcrumbJsonLd` become internal helpers or thin wrappers so PDP/PLP call sites don't churn (backward-compat wrappers, deprecated in comments). PLPs (category/brand/color/products) gain `CollectionPage` JSON-LD.

### 3.4 Tailwind token bridge artifact (analysis row 3) — velocity side

Extend `scripts/lib/tokens-build.mjs` to also emit **`src/styles/generated/tokens-tailwind.css`**:

```css
/* Generated by tokens-build — do not edit. Tailwind v4 bridge over the ADS
   semantic layer (AstroPaper theme.css pattern). Import alongside
   tokens.css in Tailwind consumers (Pro SPA v2 via Proposal 097; forge
   templates when a customer adds Tailwind). */
@theme inline {
	--color-ads-ink: var(--ads-semantic-ink);
	--color-ads-muted: var(--ads-semantic-muted);
	--color-ads-surface: var(--ads-semantic-surface);
	--color-ads-surface-alt: var(--ads-semantic-surface-alt);
	--color-ads-line: var(--ads-semantic-line);
	--color-ads-accent: var(--ads-semantic-accent);
	--color-ads-accent-ink: var(--ads-semantic-accent-ink);
	--color-ads-danger: var(--ads-semantic-danger);
	--font-ads: var(--ads-semantic-font);
	--radius-ads: var(--ads-semantic-radius);
	--spacing-ads: var(--ads-semantic-space);
}
```

- Committed + drift-gated like the other generated artifacts: `scripts/check-tokens.mjs` gains the file; `tests/unit/tokens.test.ts` pins its contents (tabs/single-quote conventions do not apply — it is CSS — but the pin prevents silent drift).
- Velocity itself does not adopt Tailwind (no dependency added); the artifact is for cross-surface consumers.

### 3.5 High-contrast + reduced-motion axes

Already specced in 1.4–1.6 (ContrastToggle, `[data-contrast="high"]` block, reduced-motion kill switch). Phase 3 delivers the **a11y audit** of the dark+high-contrast combinations on the PLP form, promo field, and cart island (`field.border` → `line-strong` precedent from 095 applies), plus the e2e assertions in 1.7.

### 3.6 Tests

- e2e `/products/`: renders ≥ 1 card; `?brand=<id>` filters; filtered view carries `noindex,follow`; canonical = `/products/`; every filter input has an accessible name.
- **D6 — axe gate:** `@axe-core/playwright` (devDependency) added to the e2e suite — `AxeBuilder.analyze()` on `/products/` and a PDP, assert 0 violations. Lighthouse a11y ≥ 0.9 remains the CI baseline; axe adds per-rule, per-node reporting for the WCAG 2.2 checklist.
- Unit: `jsonld.test.ts`-adjacent coverage for `jsonLdFor` (`CollectionPage` shape, `Product` passthrough, schema-dts typing exercised); tokens tests for the new generated file.

---

## Phase 4 — Invalidation route + forge inheritance + forge stretches (forge, analysis rows 8–9 + Q3/Q4)

### 4.1 `/internal/revalidate` (only if Q3 approved)

In `scripts/serve.mjs`: `POST /internal/revalidate` → bearer `NVOOS_REVALIDATE_TOKEN` (timing-safe compare), body `{ tags?: string[] }` → import the Astro cache invalidation seam (expose `cache.invalidate` via a small `scripts/lib/revalidate.mjs`) → 204/401/422. Not reachable under `/api/*` (deliberately not proxied). Tests: unit for the seam + a BFF integration check in the smoke script.

### 4.2 Forge runtime contract + theme module port (proposal §3.5)

- Add the `FORGE-STORE-RUNTIME-CONTRACT` section to `examples/nvoos-astro-forge/templates/blog/README.md` (the four behaviours — no-FOUC boot + `astro:after-swap` re-apply + `color-scheme` sync; PDP canonical URL + 301; `Product` JSON-LD in initial HTML; cache headers + tags on commerce routes).
- Copy `src/lib/theme/theme.ts` + `registry.ts` (minus the ADS-bundle import — the forge variant reads its theme list from the template's own tokens file) into `templates/blog/src/lib/theme/` with unit coverage; wire the boot snippet + toggle into the template layout **only when** runtime theming lands (Q4 define-only: document, don't default).
- Update the `mcp-ai-wpoos-astro-forge` skill's customer-design-language notes: the theme module is the canonical port target.

### 4.3 Forge preset wizard (analysis row 11 — Accessible Starter CLI pattern, D8)

`forge wizard` gains `--preset blog|storefront|minimal`, and the CLI itself moves onto `commander` (args) + `@clack/prompts` (interactive steps — arrow-key navigation, cancellable, the `create-astro` UX). The `.mjs` plain-JS constraint (no TS syntax in `forge.mjs`) is unchanged — both packages are plain-JS friendly.

- **Preset registry** in `lib/presets.ts`: each preset = template selection + default collection profile + surface toggles (search, feed, sitemap). `blog` = today's default; `storefront` = blog + products collections + `frontPage`; `minimal` = posts-only, no search.
- Wizard prompt ordering: URL → analyze → preset (auto-suggested from `site_analyze` output: Woo detected → `storefront`) → confirm.
- Exit-gate extension: CI scaffolds at least two presets (blog + minimal) through `npm install` + `astro check` + `astro build`; the storefront preset runs when the fixture advertises products (it does).

### 4.4 Minimal agent layer in generated projects (analysis row 10 — Astroplate pattern)

- `lib/scaffold.ts` emits a minimal **`AGENTS.md`** (project layout, commands, the runtime contract pointer, "content lives in `src/content/`, config in `nvoos.json`") and **`.context/README.md`** (empty hook for customer context files) into every scaffolded project; `--no-agents` skips it.
- Add both to `lib/project-files.ts` source collection (CLI deploy + `github_commit` tool pick them up); the exit gate asserts both exist in the scaffold output.

### 4.5 Typed copy tables in the blog template (analysis row 5/6 — ScrewFast pattern)

- New template files `src/copy/en.ts` (the reference table: nav labels, footer, aria labels, empty states) + `src/copy/index.ts` (`export type Copy = typeof en;` + `const copy: Record<string, Copy> = { en };`).
- The **completeness gate is the type itself**: a second locale is added as another `Copy`-typed table — a missing key fails `astro check` in the generated project (documented in the template README as the ScrewFast i18n gate).
- **D9 — upgrade path:** the typed tables remain the zero-dependency floor. When a customer needs real multi-locale routing and tree-shaken messages, the forge **emits `@inlang/paraglide-js` wiring** (compiler-based, typos become compile errors, Astro guide at paraglidejs.com/astro) instead — the copy-table keys map 1:1 onto message files, so the migration is mechanical. Default stays zero-dep.
- Layout/components consume `copy.en.*` (via a `useCopy()` helper returning the current locale's table; single-locale for now).
- Exit gate: `astro check` already runs on the generated project — this simply adds the files; no new CI machinery.

### 4.6 `kind`-typed SEO util vendored into the template (analysis row 9, D5)

- New template file `src/lib/seo.ts` (vendored like `sanitize.ts`): `type StructuredKind` + `jsonLdFor(kind)` for `WebPage | BlogPosting | Product` (+ `BreadcrumbList` helper), typed with `schema-dts`, with the same `<` → `\u003c` escaping the templates already do.
- `posts/[...parts].astro` and `products/[...parts].astro` swap their hand-rolled `jsonLd` objects for `jsonLdFor(...)`.
- Exit gate: existing assertions (BlogPosting + Product JSON-LD present in `dist/`) stay green unchanged — they now pin the new builder.

### 4.7 Tailwind bridge optional artifact in the template (analysis row 3 — forge side)

- Ship `templates/blog/src/styles/tokens-tailwind.css` — `@theme inline` over the `--forge-*` tokens (the forge token contract, not the ADS names), with a header comment: "import this only if/when you add Tailwind v4 to the project; unused by default".
- Zero build impact (no Tailwind dependency); the exit gate asserts the file exists and is byte-identical post-scaffold (marker rewriting must not touch it — it contains no FORGE markers).

### 4.8 Dynamic OG images — owner-optional stretch (D10, proposal Q5)

- **Velocity:** per-product OG cards (`satori` + `sharp` + `@resvg/resvg-js`, one `@fontsource` variable font) on the PDP's `og:image`, falling back to the product image when generation fails (an OG card must never 500 the page). The AstroPaper/Cactus pattern: `src/pages/og/product-[id].png.ts`-style endpoint or build-time route.
- **Forge:** the blog template's posts gain the same capability (Cactus's `_ogMarkup.ts` pattern) behind a preset flag; skipped by default so zero-dep scaffolds stay zero-dep.
- Only if the owner approves Q5; otherwise both halves stay recorded here for a later proposal.

### 4.9 Gates

- All forge CI: `npm test` (unit) + `npm run typecheck` + `npm run test:e2e` (exit gate, extended per 4.3/4.4/4.6).
- Velocity CI (P1–P3 gates) unaffected by this phase; the two tracks merge at the repo-level PR cluster.

---

## Phase 5 — CI gates, docs, skills (velocity + forge)

### 5.1 CI gates

- **No build-time commerce dependency gate:** assert the production build completes with `NVOOS_API_BASE` **unset** — a dedicated `npm run build:prod-sim` script (`ASTRO_SITE=https://astro.nvoos.cloud npm run build` with the env explicitly cleared) run in CI after the fixture build; fails if any route still calls `resolveBase(undefined)` at build.
- Extend `tests/unit/contract-grep.test.ts` (or its sibling): `src/` must not re-introduce `prerender = true` on commerce routes; `src/pages/products/**` must not contain `getStaticPaths`; generated `tokens-tailwind.css` drift gate chained into `npm run check`.
- **D12 — BFF security headers:** `scripts/serve.mjs` sets a typed header list on every response (CSP, X-Frame-Options: DENY, X-Content-Type-Options: nosniff, Referrer-Policy: strict-origin-when-cross-origin, Permissions-Policy). **CSP must hash/nonce the inline theme boot** — a blanket `unsafe-inline` is forbidden; the boot snippet is stable enough to hash, and a smoke assertion pins that the boot script still runs under the shipped CSP. Proxy headers and the existing cookie-domain strip are untouched.

### 5.2 Docs + skills (per the repo's update discipline)

- `docs/project/proposals/092-astro-storefront-layer-proposal.md`: append a "Superseded by 104" note on the §5.4 PDP-prerender decision (no rewrite).
- `examples/nvoos-astro-velocity/README.md`: rendering-model section (PDP SSR + caching + 301s + `/products/` PLP) + theme runtime section (registry, contrast axis, reduced motion).
- `examples/nvoos-astro-forge/README.md` + template README: presets, agent stub, copy tables, `lib/seo.ts`, runtime contract.
- `docs/operations/deployment/nvoos-astro-velocity-setup.md`: mirror-build env table (prod build = `ASTRO_SITE` only).
- `mcp-ai-wpoos-astro-storefront` skill: replace the "PDPs are prerendered" statements with the SSR+cache model; add the theme gotchas (after-swap re-apply, localStorage source of truth, color-scheme, high-contrast axis); add the 104 reference.
- `mcp-ai-wpoos-astro-forge` skill: presets, agent stub, seo util, theme module port target.
- No new skill → `AGENTS.md` / copilot-instructions inventories unchanged (no count bump). Both skills stay under the description-cap budget.

---

## Verification matrix

| Phase | Command / gate | Expected |
|---|---|---|
| P1 | `NODE_OPTIONS=--max-old-space-size=8192 npm run check && npm run lint && npm run test` | 101 + new unit tests (theme/registry/publish) green |
| P1 | `npm run test:e2e` (fixture BFF) | theme navigation + contrast-axis spec green ×6 cases |
| P2 | `npm run build` (localhost, fixture base) + smoke + e2e + lhci | PDP 200/404/301 spec green; LHCI thresholds unchanged |
| P2 | `npm run build` with `NVOOS_API_BASE` unset (CI gate 5.1) | builds clean — nothing fetches at build |
| P2 | `npm run sync:bridge-fixtures` + `git diff` | format-only reflow (goldens untouched unless the draft-doc option is taken deliberately) |
| P3 | e2e PLP + a11y spec | filters/canonical/noindex/a11y-name assertions green; **axe-core: 0 violations on `/products/` + PDP** |
| P3 | tokens tests | `tokens-tailwind.css` content pinned; drift gate green |
| P4 | forge `npm test` + `typecheck` + `test:e2e` | preset scaffolds (blog/minimal/storefront) pass; agent stub + seo util + copy tables asserted |
| P4 | velocity unit + smoke for `/internal/revalidate` (if Q3) | auth-gated 401/204; seam tests green |
| All | `composer run docs:check-folder-readmes` (repo-level, unaffected) | no new violations |

## Rollout & rollback

1. **P1** ships independently — purely additive client-side behaviour (theme/contrast/registry); rollback = revert commit, zero deployment coupling.
2. **P2** ships with the workflow change in the same PR; the mirror redeploys on merge. Rollback = restore `prerender` + fixture base (returns to today's known-bug state, nothing worse).
3. **P3** is additive (new route, metadata builder wrappers, generated artifact) — no rollback plan needed beyond revert; the `tokens-tailwind.css` file is generated and drift-gated, so reverting is clean.
4. **P4** touches only forge templates/scaffold — generated projects change on the next scaffold; existing customer outputs are untouched (idempotency marker `nvoos.json` guards them). Rollback = revert + no re-scaffold.
5. Post-deploy live checks on the mirror: `/products/` PLP renders; a real product URL from `/sitemap.xml` returns 200; theme + contrast survive `/` → `/categories/` → PDP client-side navigation; dark mode shows dark form controls/scrollbars; a draft product id returns 404.
