# nvoos-astro-forge — WP → Astro frontend generator (Proposal 096, Phases 0–4)

Turns a WordPress/WooCommerce site into a native **Astro 7** headless
frontend. WordPress stays the CMS; the generated project pulls content
through an Astro **content-layer loader** at build time (freshness via
webhook → rebuild), renders sanitized HTML, and ships SEO-complete static
output. The pilot customer is a WooCommerce storefront (Proposal 098 has
already extracted its design language — the tokens feed the template's
`--forge-*` variables).

> Phases 0–3 (generation core, Woo collections, SEO/media/search, bridge
> plugin, vault + deploy + multi-site) and Phase 4 (the `setup_wizard` and
> the `/mcp` tool surface) are shipped. Remaining: the registry publication
> (owner action — `server.json` is ready) and the §3.3 long-tail tool
> slices.

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
| `lib/vault.ts` | Phase 3.1 credential store — AES-256-GCM entries under `~/.nvoos-forge/vault.json`, key from `NVOOS_FORGE_VAULT_KEY`, masked output |
| `lib/sites.ts` | Phase 3.4 multi-site registry — named profile refs + default flag in `~/.nvoos-forge/sites.json` |
| `lib/github.ts` | Phase 3.2 octokit deploy — private-by-default repo create + git-data commit/push, sanitized errors |
| `lib/deploy-config.ts` | Phase 3.3 generated pipeline — deploy.yml + rebuild.yml + the fail-closed `verify-dispatch.mjs` |
| `scripts/wp-fixture-server.mjs` | Hermetic wp/v2 + wc/v3 wire fixture (`:8473`) — stands in for WordPress Playground in CI |
| `scripts/builtin-template.mjs` | Test fixture generator (nested `main/templates/blog/` tree with a REAL loader) |
| `templates/blog/` | The production template — posts/pages/products, static front page, RSS, 404, 301 redirect map, canonical + JSON-LD, sitemap/robots, `--forge-*` tokens |
| `lib/contrast.ts` | WCAG 2.2 contrast math — the token gate warns on AA failures at scaffold time |
| `tests/` | Unit tests (`npm test`) + the exit-gate e2e (`npm run test:e2e`) |

## Dev loop

```sh
npm install
npm test                 # unit: client, loader, sanitizer, scaffold, vault, github, deploy-config, sites
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
- `source: "payload"` reads the `nvoos-commerce/v1` envelope served by the
  nvoos-astro-bridge (body pagination `{ docs, totalDocs, totalPages }`,
  flat documents with `price`/`oldPrice` sale semantics).
- `exclude` filters junk/system slugs; `frontPage` renders the WordPress
  static front page at `/` (single canonical URL); `optional: true` lets a
  source be absent (empty collection, warning, not a failed build).
- `tokens` applies the 098 design language over the `--forge-*` defaults at
  scaffold time (`{ "accent": "#720eec", ... }`); the scaffold warns when
  rendered pairs fail WCAG AA (SC 1.4.3) — warnings, never blocks.

### SEO surfaces (generated per site)

- `/feed.xml` (RSS 2.0), `/404.html`, `/sitemap.xml`, `/robots.txt`.
- `/search/` — client-side full-text search (Pagefind, indexed post-build).
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
project; generated sites are the customer's own. Published to npm as
`@nvdigitalsolutions/nvoos-astro-forge` (tag `nvoos-astro-forge-vX.Y.Z`
through `.github/workflows/publish-astro-forge.yml`).

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
6. **Deploys default private, auth never persists** — repos are created
   private unless `--public` is explicit; the GitHub token comes from
   `GITHUB_TOKEN` or the vault for one invocation, is masked in every
   output, and is never written to disk.
7. **Fail-closed rebuilds** — the generated pipeline refuses stale or
   mis-signed dispatch events before any build runs (Phase 3.3
   `verify-dispatch.mjs`).

## Phase 3 — vault, multi-site, deploy

```sh
# Register named sites (profiles stay operator-owned files).
export NVOOS_FORGE_HOME=~/.nvoos-forge
node scripts/forge.mjs site add store site.json --default
node scripts/forge.mjs site list
node scripts/forge.mjs site use store

# Store the deploy token (value on stdin — never argv, never the shell
# history). Requires NVOOS_FORGE_VAULT_KEY (any passphrase).
export NVOOS_FORGE_VAULT_KEY='a long passphrase'
printf 'ghp_…' | node scripts/forge.mjs vault set github

# Deploy: scaffold if needed, emit the pipeline, create a PRIVATE repo,
# push the source. The workflow builds + ships to the target; configure
# FORGE_WEBHOOK_SECRET (plus CLOUDFLARE_* / VELOCITY_* per mode) as repo
# secrets afterwards.
node scripts/forge.mjs deploy store --target ./customer-frontend \
  --to github-pages                 # or cloudflare-pages (--cloudflare-project X) / velocity
```

Deploy modes are adapter-generic: `github-pages` (Pages artifact deploy),
`cloudflare-pages` (wrangler), or `velocity` (connected-repo mirror of
`dist/`). Every mode gets the same `rebuild.yml` — a `repository_dispatch`
wrapper whose `verify-dispatch.mjs` re-verifies the bridge plugin's HMAC
proof (freshness window + constant-time compare) before building.

The vault is AES-256-GCM per entry (wrong key or tampering fails closed);
`NVOOS_FORGE_VAULT_PLAINTEXT=1` is the documented 0600-file fallback for
fixtures only.

## Phase 4 — the wizard and the MCP surface

```sh
# One command: URL → analyzed → registered → scaffolded → built → git →
# deploy pipeline. (Local dev sites need NVOOS_FORGE_ALLOW_LOOPBACK=1.)
node scripts/forge.mjs wizard https://shop.example.com \
  --target ./customer-frontend --build

# The MCP server — dual-era Streamable HTTP (team mode) …
npm run mcp                        # http://127.0.0.1:8472/mcp (FORGE_MCP_PORT to change)

# … or the stdio entry for coding agents (npx nvoos-forge-mcp-stdio).
npx nvoos-forge-mcp-stdio
```

- **Tool catalog (v1, 29 tools + 3 meta-tools):** `forge_help` /
  `forge_plan` / `forge_status` (the `NVOOS_FORGE_MODE=router` surface),
  `site_add|test|list|get|update|remove|set_default`, `site_analyze`,
  `scaffold_project|status`, `tokens_apply`, `scaffold_package`,
  `content_audit`, `content_preview`, `redirects_generate`, `media_audit`,
  `collections_generate`, `rebuild_config`, `rebuild_trigger`,
  `preview_token`, `webhook_verify`, `github_create_repo|commit|status`,
  `deploy_config`, `setup_wizard`.
- **Single-source registry** (`mcp/registry.ts`): every tool declares a
  zod schema, explicit MCP annotations (read-only/destructive/open-world),
  and a write flag — CI fails when any is missing.
- **Write tools fail closed:** a bearer `NVOOS_FORGE_MCP_TOKEN` is
  required (timing-safe compare); unset = every write is refused with
  401 `-32001`. Reads pass unauthenticated. Rate limiting, Host/Origin
  validation, and per-IP audit logging wrap every request.
- **SSRF-guarded fetches:** `site_analyze` accepts registry hosts, or any
  host under the `NVOOS_FORGE_OPEN_ANALYZE=1` opt-in — private/reserved/
  literal-IP ranges are always refused (`lib/ssrf.ts`). Scaffold targets
  are confined to `NVOOS_FORGE_WORKDIR`.
- **Registry entry** (061 pattern): `server.json` — the MCP registry
  metadata (`io.github.nvdigitalsolutions/nvoos-astro-forge`); it is NOT
  part of the npm package.

## Research basis

`docs/project/proposals/096-astro-forge-wp-generator-mcp-research.md` —
loader semantics, Woo auth (`Application Passwords`), sanitize-html
allowlisting, SEO (canonical/sitemap/JSON-LD), and hybrid-rendering
standards, verified 2026-10-31.
