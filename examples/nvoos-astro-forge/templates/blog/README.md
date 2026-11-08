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
- `/redirects.json` + `dist/_redirects` (post-build) — the redirect map: the
  source site's stored rules (Redirection / Rank Math, via the Forge Bridge)
  merged with the old WordPress URL structure → the generated one

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
   `color-scheme` kept in sync with the theme. The pure runtime modules are
   `src/lib/theme/theme.ts` (+ `registry.ts`) — the canonical port target.
   **Wired since Proposal 105 §4:** the boot script + `ThemeToggle` are in
   the layout, driven by the baked policy (`src/lib/theme/policy.generated.ts`,
   `--theme-policy` at scaffold time) — forced light/dark/brand apply before
   any stored preference and disable the toggle; `auto` runs stored → media →
   `defaultTheme` (`src/lib/theme/policy.ts`, the forge-canonical ladder the
   velocity storefront mirrors).
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

## Tracking & consent (Proposal 130)

`src/lib/tracking.generated.ts` is the baked measurement surface (vendors +
public ids, the consent config, verification tokens, the delivery mode and the
single `purchase` owner). `src/components/Tracking.astro` emits, in order: the
ownership-verification `<meta>` tags, the inert loader config, the
`#nvoos-consent-default` Consent Mode v2 bootstrap (defaults + stored decision
+ Global Privacy Control), the event-ownership JSON, and the deferred
first-party loader. **No vendor `<script src>` is ever in the static HTML** —
the loader injects each vendor only after its category is granted
(`basic` strategy). `src/components/ConsentBanner.astro` renders only when
`consent.mode === 'forge'`; it is a non-modal region (no focus trap, no
`aria-modal`) with a native `<dialog>` preferences panel, equal-prominence
accept/reject, and `html[data-consent-open]` body padding for WCAG 2.2 SC
2.4.11. Decisions live in `localStorage['nvoos-consent']` (never a consent
cookie); `src/lib/consent.ts` is the pure model the inline scripts mirror.
A profile without a tracking block leaves the stub inert and renders nothing.

## SEO builder (`src/lib/seo.ts`)

`jsonLdFor(kind, input)` covers `WebPage | BlogPosting | Article | Product`
(plus a `breadcrumbList()` helper), typed with `schema-dts` so a malformed
document fails `astro check` instead of silently shipping; `renderJsonLd()`
applies the `<` → `\u003c` escape for `<script type="application/ld+json">`
tags. The posts detail pages emit `BlogPosting`, the pages emit `WebPage`,
and the product detail pages emit `Product` (Proposal 106 JSON-LD parity) —
the e2e gate pins all three. Posts/pages titles + descriptions prefer the
source SEO meta (Rank Math → Yoast → title/excerpt).

**Proposal 131 additions.** `composeDocumentTitle(title, site)` is the
single title rule — it appends the site name only when the page title does
not already end with it (Rank Math/Yoast titles are final and usually carry
it; a blind append produced `X — Site — Site`). `jsonLdGraph({ site, page,
breadcrumbs })` wraps the page node in the site entity graph —
`Organization` + `WebSite` (+ `SearchAction` when the search surface is on) +
`BreadcrumbList` — wired by `@id`, rebuilt from facts (never Yoast's raw
`schema.@graph`). `src/lib/seo-graph.ts` supplies the identity from
`nvoos.json` + the harvested chrome logo. The layout renders the full social
head (`og:*` + `twitter:*`) and the source `robots` directive, and the
sitemap carries `<lastmod>`, drops `noindex` URLs, and ships image entries.
`forge verify --seo [--strict-seo]` audits the built head (double-branded
titles, a noindex homepage, invalid JSON-LD, missing OG/Twitter/canonical).

## Redirect map (`/redirects.json` → `_redirects`)

Two sources feed one deterministic map (Proposal 132):

1. **Stored rules** — your redirect plugin's existing rules (Redirection
   `redirection_items`, Rank Math `rank_math_redirections`), read at build
   time from the Forge Bridge's read-only `GET /nvoos-forge/v1/redirects`.
   Endpoint resolution: `NVOOS_FORGE_REDIRECTS_ENDPOINT` → `WP_SITE_URL` →
   the manifest URL; a token-gated export uses
   `NVOOS_FORGE_REDIRECTS_TOKEN`. A missing bridge is a console warning, not
   a build failure.
2. **The rebuild's URL structure** — posts → `/posts/{id}/{slug}/`, pages →
   `/` or `/{slug}/`, products → `/products/{id}/{slug}/`. These win on
   conflict: the rebuild knows where it actually serves the content.

`redirects.json` keeps the **faithful** source status
(`301/302/303/307/308/404/410`). `scripts/postbuild.mjs` projects it onto
what the static hosts actually honour: `303/307→302`, `308→301`, `410→404`,
unknown statuses omitted, 404/410 target rendered as `/`. It writes a `#`
note when anything was projected and logs the count.

Host matrix (documented, not guessed): Netlify documents 301/302/404 and
calls 307 unsupported; Cloudflare Pages documents 301/302/303/307/308 and
rejects 404/410 (an absent path already 404s). Regex, query-scoped, and
conditional rules cannot be expressed portably — the bridge counts them in
`skipped` instead of widening them.

## Tailwind bridge (`src/styles/tokens-tailwind.css`)

Ships unused by default: an `@theme inline` mapping of the `--forge-*` tokens
onto Tailwind v4 utility tokens. Import it only if/when Tailwind v4 is added
to the project — zero build impact otherwise, and the scaffold copies it
byte-identical (it deliberately carries no FORGE markers).

## Hybrid local content (Proposal 106 P2)

`src/content/posts/` + `src/content/pages/` hold local `.mdx` files
(frontmatter: `title`, `description`, `date`, `draft`) that merge under the
WordPress collections: **WP wins on collision, local fills the gaps, drafts
never ship**. The example post makes a scaffolded site build and render with
**no WordPress connection** — a network-level failure degrades to local-only
with a loud loader warning (HTTP-level errors still fail the build, never
silent truncation).

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
