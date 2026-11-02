# forge-blog — NV oOS Astro Forge blog template (Proposal 096)

An Astro 7 static frontend generated from a WordPress source. WordPress stays
the CMS; this project pulls content through an Astro content-layer loader on
every build. Freshness comes from rebuild triggers (webhook → build), never
from runtime fetches.

> This directory is a **template** — it does not build on its own. Run the
> Forge CLI (`node scripts/forge.mjs <site.json> --target <dir>`) to scaffold a
> real project, which vendors the loader libraries into `src/forge/`.

## Scaffolded project — quick start

```sh
npm install
npm run dev      # dev server; the content layer refetches on reload
npm run build    # static build (dist/)
npm run check    # astro check
```

Environment (see `.env.example`):

| Variable | Purpose |
| --- | --- |
| `WP_SITE_URL` | WordPress source (defaults to the hermetic fixture `http://127.0.0.1:8473`) |
| `WP_APPLICATION_USER` / `WP_APPLICATION_PASSWORD` | Optional Application Passwords for private content — never committed |
| `WP_WC_CONSUMER_KEY` / `WP_WC_CONSUMER_SECRET` | Optional WooCommerce REST keys for `wc/v3` collections (Woo → Settings → Advanced → REST API) |
| `ASTRO_SITE` | Canonical host for sitemap/robots/canonical URLs |

## What gets generated

- `/` — the WordPress static front page (`manifest.frontPage`) when set;
  otherwise a blog-style home with the latest posts
- `/posts/` — all posts (sorted by date, descending)
- `/posts/{id}/{slug}/` — post detail with sanitized WordPress HTML, canonical
  link, and `BlogPosting` JSON-LD
- `/products/` + `/products/{id}/{slug}/` — the storefront catalog: prices
  (sale = strike-through regular), stock, SKU, categories, `Product` JSON-LD
  (the pages are pruned by the scaffold when the profile has no products)
- `/{slug}/` — WordPress pages (the front page slug is skipped to keep one
  canonical URL)
- `/sitemap.xml`, `/robots.txt`, `/feed.xml` (RSS 2.0), `/404.html`
- `/redirects.json` + `dist/_redirects` (post-build) — the 301 map from the
  old WordPress URL structure to the generated one

## Safety invariants (Proposal 096 §2.4)

- All WordPress HTML passes through the versioned `sanitize-html` allowlist
  (`src/forge/sanitize.ts`) before `set:html` — scripts, event handlers,
  `javascript:` URLs and unknown embed hosts never reach the page.
- Credentials live only in `.env` (git-ignored); the scaffolded repo contains
  no secrets.
- Built assets (`dist/`, `.astro/`) are git-ignored by the scaffold gate.
- Truncated fetches are never silent: entries carry a `truncated` flag, the
  loader warns, and pages show a notice.

## Design tokens (`tokens_apply`)

`src/styles/global.css` defines `--forge-*` tokens defaulting to the ADS core
palette inside `/* FORGE:tokens */` markers. The Forge pipeline applies a
site's design language (Proposal 098 output) over these variables at scaffold
time — content is re-themed, never re-cloned — and warns when the rendered
ink/surface and accent-ink/accent pairs fail WCAG AA (SC 1.4.3).

## Storefront runtime contract (Proposal 104)

Every generated project must satisfy these four behaviours (Proposal 104,
`docs/project/proposals/104-astro-storefront-runtime-hardening-implementation-plan.md`
§4.2):

1. **Theming** — a no-FOUC boot script in the layout `<head>`, theme
   re-applied on `astro:after-swap` (client-side navigation), and
   `color-scheme` kept in sync with the theme. The pure runtime module is
   `src/lib/theme/theme.ts` (+ `registry.ts`) — the canonical port target;
   the boot script and toggle get wired when runtime theming lands
   (Proposal 104 Q4, define-only today).
2. **PDP canonical + 301 slug convergence** — every product page exposes one
   canonical URL, and old/incorrect slugs converge via a 301.
3. **Product JSON-LD in initial HTML** — the PDP ships `Product` structured
   data in the first response, built by `src/lib/seo.ts`
   (`jsonLdFor('Product', …)`).
4. **Cache headers + tags on commerce routes** — `/products/*` routes send
   cache headers with invalidation tags.

`AGENTS.md` (scaffolded with every project; skip with `--no-agents`) points
agent sessions at this section.

## Typed copy tables (`src/copy/`)

UI strings live in typed copy tables, not inline: `src/copy/en.ts` is the
reference table (nav labels, footer, aria labels, empty states),
`src/copy/index.ts` derives `Copy = typeof en` and exports `useCopy()`.
The completeness gate is the type itself: a second locale is added as
another `Copy`-typed table — a missing key fails `astro check` in the
generated project. When a customer needs real multi-locale routing and
tree-shaken messages, the forge emits `@inlang/paraglide-js` wiring instead
(Proposal 104 D9); the table keys map 1:1 onto message files, so the
migration is mechanical. The zero-dependency tables remain the default.

## SEO builder (`src/lib/seo.ts`)

`jsonLdFor(kind, input)` covers `WebPage | BlogPosting | Product` (plus a
`breadcrumbList()` helper), typed with `schema-dts` so a malformed document
fails `astro check` instead of silently shipping; `renderJsonLd()` applies
the `<` → `\u003c` escape for `<script type="application/ld+json">` tags.
The posts and products detail pages consume it — the e2e gate pins the
`BlogPosting` + `Product` output.

## Tailwind bridge (`src/styles/tokens-tailwind.css`)

Ships unused by default: an `@theme inline` mapping of the `--forge-*` tokens
onto Tailwind v4 utility tokens. Import it only if/when Tailwind v4 is added
to the project — zero build impact otherwise, and the scaffold copies it
byte-identical (it deliberately carries no FORGE markers).

## Render variant + OG cards (Proposal 104 Q4/Q5 — scaffold-time)

- **Static (default)** — this template, untouched. The scaffold removes the
  FORGE config markers and the inert `_ssr`/`_og` directories.
- **SSR (`--render ssr`)** — the scaffold swaps the static product pages for
  the on-demand `src/pages/products/[id]/[...slug].astro` route (fetch,
  sanitize, `Product` JSON-LD, 404/301, cache headers) and writes
  `output: 'server'` + the `@astrojs/node` standalone adapter into
  `astro.config.mjs`. Everything else stays build-time static.
- **OG cards (`--og-images`)** — the scaffold ships
  `src/pages/og/[slug].png.ts` (build-time per-post cards, branded from the
  `--forge-*` tokens, `satori` + `@resvg/resvg-js` + `@fontsource/inter`,
  injected into `package.json` only when the flag is on) and post pages
  point `og:image` at the card. Render failures warn + fall back.

## License

The Forge template and vendored libraries are MIT (`NOTICE` at the project
root). Generated sites are the customer's own.
