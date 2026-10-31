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

## License

The Forge template and vendored libraries are MIT (`NOTICE` at the project
root). Generated sites are the customer's own.
