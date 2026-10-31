# nvoos-astro-forge — WP → Astro frontend generator (Proposal 096, Phase 0)

Turns a WordPress/WooCommerce site into a native **Astro 7** headless
frontend. WordPress stays the CMS; the generated project pulls content
through an Astro **content-layer loader** at build time (freshness via
webhook → rebuild), renders sanitized HTML, and ships SEO-complete static
output. The customer driver is **MYYCO** (`https://myyco.com/`).

> This is Phase 0 (the generation core). Phases 1–3 (CLI polish, WooCommerce
> collections, `/mcp` integration + deploy) follow Proposal 096 §5.
> Design-language application (Proposal 098 `design_theme` output) lands on
> top of the generated `--forge-*` tokens.

## Layout

| Path | Role |
| --- | --- |
| `lib/types.ts` | `SiteProfile` / `WpCollection` contracts (allowlisted URLs only) |
| `lib/wp-client.ts` | WP REST client — pagination via `X-WP-TotalPages`, `_fields` pruning + `_embed`, typed errors, explicit truncation |
| `lib/wp-loader.ts` | The Astro content-layer loader (digest = `modified_gmt`, sanitized `rendered.html`, deletions propagate) |
| `lib/sanitize.ts` | Versioned `sanitize-html` allowlist — the XSS gate before `set:html` |
| `lib/scaffold.ts` | Template copy + FORGE-marker rewrite + manifest + gitignore gate |
| `scripts/forge.mjs` | CLI: `node scripts/forge.mjs <site.json> --target <dir> [--template blog] [--check]` (Node ≥ 22.6) |
| `scripts/wp-fixture-server.mjs` | Hermetic wp/v2 wire fixture (`:8473`) — stands in for WordPress Playground in CI |
| `templates/blog/` | The production blog template (Astro 7, ADS-default tokens) |
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
  "url": "https://myyco.com",
  "name": "MYYCO",
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

# 2. Check the source first — a build must never silently truncate.
node scripts/forge.mjs site.json --target ./myyco-frontend --check

# 3. Scaffold + build.
node scripts/forge.mjs site.json --target ./myyco-frontend
cd myyco-frontend && npm install && npm run build
```

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
