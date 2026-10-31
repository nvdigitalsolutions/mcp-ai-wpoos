# nvoos-astro-forge — WP → Astro frontend generator (Proposal 096, Phase 0)

Turns a WordPress/WooCommerce site into a native **Astro 7** headless
frontend. WordPress stays the CMS; the generated project pulls content
through an Astro **content-layer loader** at build time (freshness via
webhook → rebuild), renders sanitized HTML, and ships SEO-complete static
output. The pilot customer is a WooCommerce storefront (Proposal 098 has
already extracted its design language — the tokens feed the template's
`--forge-*` variables).

> This is Phase 0 + Phase 1a (the generation core + Woo collections).
> Remaining Phase 1 (RSS/404/search, `site_analyze`, `tokens_apply` at
> scaffold, `/mcp` surface) and Phases 2–4 follow Proposal 096 §5.
> Design-language application (Proposal 098 `design_theme` output) lands on
> top of the generated `--forge-*` tokens.

## Layout

| Path | Role |
| --- | --- |
| `lib/types.ts` | `SiteProfile` / `WpCollection` contracts — `exclude`, `kind`, `authMode`, `optional`, `frontPage` (allowlisted URLs only) |
| `lib/wp-client.ts` | WP REST client — pagination via `X-WP-TotalPages`, `_fields` pruning + `_embed`, typed errors, explicit truncation |
| `lib/wp-loader.ts` | The Astro content-layer loader — wc/v3 commerce extraction, wp/v2 featured-media resolution, optional sources |
| `lib/sanitize.ts` | Versioned `sanitize-html` allowlist — the XSS gate before `set:html` |
| `lib/scaffold.ts` | Template copy + FORGE-marker rewrite + manifest + gitignore gate + storefront-page pruning |
| `scripts/forge.mjs` | CLI: `analyze <url> [--write]` (site_analyze) + `node scripts/forge.mjs <site.json> --target <dir> [--template blog] [--check]` (Node ≥ 22.6) |
| `lib/discovery.ts` | `site_analyze` — root/types/counts/front-page/junk/Woo/SEO probes → draft site.json |
| `scripts/wp-fixture-server.mjs` | Hermetic wp/v2 + wc/v3 wire fixture (`:8473`) — stands in for WordPress Playground in CI |
| `scripts/builtin-template.mjs` | Test fixture generator (nested `main/templates/blog/` tree with a REAL loader) |
| `templates/blog/` | The production template — posts/pages/products, static front page, RSS, 404, 301 redirect map, canonical + JSON-LD, sitemap/robots, `--forge-*` tokens |
| `lib/contrast.ts` | WCAG 2.2 contrast math — the token gate warns on AA failures at scaffold time |
| `tests/` | Unit tests (`npm test`) + the exit-gate e2e (`npm run test:e2e`) |

## Dev loop

```sh
npm install
npm test                 # unit: client, loader, sanitizer, scaffold (30 tests)
npm run typecheck
npm run test:e2e         # fixture WP → scaffold → npm install → astro check + build → HTML asserts
```

## Forge a site

```sh
# 1. Write the operator profile (the allowlist — the forge never takes arbitrary URLs).
cat > site.json <<'EOF'
{
  "url": "https://shop.example.com",
  "name": "Example Store",
  "collections": [
    { "slug": "posts", "restBase": "wp/v2/posts",
      "fields": ["id", "date_gmt", "modified_gmt", "title", "slug", "link", "content", "excerpt"],
      "embed": true },
    { "slug": "pages", "restBase": "wp/v2/pages",
      "fields": ["id", "date_gmt", "modified_gmt", "title", "slug", "link", "content", "excerpt"],
      "embed": true }
  ]
}
EOF

# 1. Discover — one command drafts the profile (site_analyze).
node scripts/forge.mjs analyze https://shop.example.com --write site.json

# 2. Check the source first — a build must never silently truncate.
node scripts/forge.mjs site.json --target ./customer-frontend --check

# 3. Scaffold + build.
node scripts/forge.mjs site.json --target ./customer-frontend
cd customer-frontend && npm install && npm run build
```

### Storefront profiles (Phase 1a)

```json
{
	"url": "https://shop.example.com",
	"name": "Example Store",
	"frontPage": "home",
	"collections": [
		{ "slug": "pages", "restBase": "wp/v2/pages", "fields": ["id", "title", "slug", "content"],
		  "embed": true, "exclude": ["checkout", "lost-password"] },
		{ "slug": "products", "restBase": "wc/v3/products", "kind": "product",
		  "authMode": "wc-consumer", "fields": ["id", "name", "slug", "permalink", "description",
		  "price", "regular_price", "sale_price", "stock_status", "sku", "images", "categories"] }
	]
}
```

- `kind: "product"` enables commerce extraction — wc/v3 flat documents
  (price/stock/sku/images/categories) via `WP_WC_CONSUMER_KEY`/`_SECRET` in
  `.env`, or wp/v2 CPTs (no commerce fields; featured media resolved by ID).
- `exclude` filters junk/system slugs; `frontPage` renders the WordPress
  static front page at `/` (single canonical URL); `optional: true` lets a
  source be absent (empty collection, warning, not a failed build).
- `tokens` applies the 098 design language over the `--forge-*` defaults at
  scaffold time (`{ "accent": "#720eec", ... }`); the scaffold warns when
  rendered pairs fail WCAG AA (SC 1.4.3) — warnings, never blocks.

### SEO surfaces (generated per site)

- `/feed.xml` (RSS 2.0), `/404.html`, `/sitemap.xml`, `/robots.txt`.
- `/redirects.json` — the structured 301 map from the WP URL structure to the
  generated one; the post-build step materializes the host-agnostic
  `dist/_redirects` (Netlify/Cloudflare/static-server compatible).

### Media pipeline

Same-host WordPress images flow through **Astro Image** (`SmartImage.astro` —
responsive WebP/AVIF via `widths`/`sizes`/`inferSize`, lazy): the config
allowlists the WP host from `WP_SITE_URL ?? nvoos.json`. Images on any other
host (CDNs, external) pass through as plain `<img>` — never a build failure.

## License

MIT via `LICENSE` + `NOTICE` (owner decision 096 Q7 — mirrors the 092 Q8
storefront split). The scaffold ships the NOTICE into every generated
project; generated sites are the customer's own. `private: true` until
Phase 3 publishing.

## Safety invariants (Proposal 096 §2)

1. **No plaintext credentials** — auth comes from `.env`
   (`WP_APPLICATION_USER` / `WP_APPLICATION_PASSWORD`), never from the
   profile or the generated repo.
2. **SSRF-safe by construction** — the forge consumes operator-configured
   site profiles; the open-world fetch surface lives behind the
   `/mcp` `design_analyze` guard (Proposal 098), not here.
3. **Mandatory sanitization** — WordPress HTML passes the versioned
   allowlist before `set:html`. Scripts, event handlers, `javascript:`
   URLs, and non-allowlisted embed hosts never reach a page.
4. **Never silent truncation** — `fetchAll` returns `truncated: true`, the
   loader warns, entries carry the flag, pages show a notice.
5. **Built assets never ship** — the scaffold gate guarantees `dist/` and
   `.astro/` are git-ignored in the customer repo.

## Research basis

`docs/project/proposals/096-astro-forge-wp-generator-mcp-research.md` —
loader semantics, Woo auth (`Application Passwords`), sanitize-html
allowlisting, SEO (canonical/sitemap/JSON-LD), and hybrid-rendering
standards, verified 2026-10-31.
