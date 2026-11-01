# nvoos-astro-velocity — NV-Authored Headless Storefront

> **Proposal 092.** An NV-authored, **MIT-licensed** Astro 7 storefront that
> binds **only** to the NV commerce contract (`nvoos-commerce/v1`, the
> Payload-shaped REST envelope). It replaces the licensed Funiq PWA as the
> public storefront while the PWA stays live as the A/B rollback (owner
> decision Q1: keep A/B).

The storefront is backend-agnostic by construction: whoever serves the
contract — WordPress + bridge today, a Laravel commerce service tomorrow —
is invisible to this app (085 appendix §2).

## Architecture

```
Browser ──> Velocity app (nvoos-astro-velocity, Node adapter + BFF)
              ├── Astro SSR handler        # pages, /manage, /_astro assets
              ├── /api/*   → nvoos-commerce/v1/*   (contract proxy)
              └── /media/* → uploads base          (media normalization, D7)
```

**No backend admin is proxied.** `wp-admin`, `wp-login.php`, `wp-json` and
`wp-includes` are deliberately NOT proxied (2026-10-29 decision): the admin
experience is the standalone **Astro CMS** on `/manage` — the bridge's admin
SPA (Astro-branded), bundled standalone (`@wordpress/*` + React baked in) and
shipped in `public/manage-assets/`. It binds to the contract (`/api/*`) like
every other surface; manager sign-in is the contract's own REST auth flow.

| Surface | Rendering |
|---|---|
| PDP (`/products/{id}/{slug}/`) | **Prerendered** for the full catalog at build time |
| PLP (`/categories|brands|colors/{id}/{slug}/` + filters) | On-demand SSR, `s-maxage=300` (prerendered page-1s were dropped — a static page would ignore filter query strings) |
| Home / promotions / promocodes / search | SSR, cached |
| Cart badge, gallery, promo-code field, add-to-cart, account | React islands (`client:idle` / `client:visible` / `client:load` on account) |
| `/manage` (Astro CMS) | SSR shell + `ManageGate` island (sign-in gate → injected CMS bundle); `noindex` |
| About / terms | Static markdown |
| Sitemap / robots | SSR endpoints (the sitemap integration cannot list SSR'd PLP bases, so a custom endpoint generates the full map) |

## Contract rules (CI-enforced)

- `src/` binds **only** to the commerce contract. The D3 grep gate
  (`tests/unit/contract-grep.test.ts`) fails CI if `src/` ever contains
  backend URL strings (`wp-json`, `wp-admin`, …) or the retired brand name.
  Backend specifics live exclusively in `scripts/serve.mjs` (the BFF).
- Media URLs are normalized to same-origin `/media/*` at the fetch edge
  (D7) — the backend origin never leaks into HTML, and the T1 backend flip
  is a pure `serve.mjs` env change.
- Golden fixtures under `tests/fixtures/` mirror the contract; the client
  fails loudly on envelope drift (P5).

## Environment

| Variable | Used by | Purpose |
|---|---|---|
| `NVOOS_API_BASE` | build (CI) | Full base URL of the commerce contract for `getStaticPaths` |
| `NVOOS_TARGET_SITE` | `serve.mjs` (runtime) | Backend origin for the `/api` and `/media` proxies |
| `PUBLIC_API_BASE` | islands (dev only) | Client-side API base when `astro dev` has no BFF |
| `ASTRO_SITE` | build/runtime | Canonical host for sitemap, robots, canonical/OG tags |
| `NVOOS_CURRENCY` | build/runtime | Price formatting + JSON-LD (default `USD`) |
| `PORT` | `serve.mjs` | Listen port (Velocity injects it) |

## MCP assistant surface (`/mcp`)

`nvoos-astro-mcp` serves 21 tools over Streamable HTTP at `/mcp` (bundled into
`dist/mcp-entry.mjs` on `astro:build:done`). Every tool carries a
human-readable `title` and spec `annotations` (readOnly/destructive/idempotent/
openWorld hints) so MCP clients — Zed, NV oOS assistants, Claude — can render
and gate the registry without scraping descriptions:

- **Design (10)** — `tokens_get/set/import_dtcg/rollback`, `theme_*`,
  `contrast_check` (WCAG 2.2 AA/AAA levels). Every mutating design write
  echoes its pre-write `snapshot_id`, so `tokens_rollback` can undo exactly
  that write (no argument rolls back to the latest snapshot).
- **CMS (6)** — `cms_list/get/create/update/delete`, `media_upload` over the
  commerce contract (collection allowlist, `limit` ≤ 100; writes require a
  manager session cookie — the backend enforces `manage_astro`).
- **Ops (5)** — `site_status`, `preview_url`, `rebuild_trigger` (webhook env),
  `screenshot` (opt-in `NVOOS_MCP_SCREENSHOT=1`, path-only input),
  `nds_export` (the NDS DTCG document for the Design System round-trip).

Auth is fail-closed: reads are public; design writes accept an
`Authorization: Bearer <NVOOS_MCP_TOKEN>` **or** a manager session cookie;
contract writes (cms_* writes, media_upload) require the manager cookie.
Host/Origin allowlists (DNS-rebinding guards), a per-IP rate limit, and audit
log lines sit in front of the handler. The /manage **Design System tab** is
itself an MCP client over the same-origin endpoint (manager cookie).

`preview_url` / `screenshot` resolve the storefront origin as
`NVOOS_STOREFRONT_ORIGIN` → the inbound request Host (scheme from
`X-Forwarded-Proto`, then socket TLS state) → `http://localhost:4321` (local
dev fallback). Set the env override when the request Host is rewritten by a
proxy.

Connect:

```jsonc
// 1. NV oOS assistant — Remote Sites → MCP Server connection
{ "url": "https://astro.nvoos.cloud/mcp", "auth_type": "bearer", "token": "<NVOOS_MCP_TOKEN>" }
// then attach it as an assistant MCP App (connection_ref); tools bridge as
// mcp_app_astro_* in chat. Allowlist the host in Security Center. (Bearer
// unlocks design writes only — contract writes still need a manager session,
// so a header-auth connection carrying the Cookie is the cms-write variant.)

// 2. Claude Desktop / generic client
{ "mcpServers": { "nvoos-astro": { "url": "https://astro.nvoos.cloud/mcp", "headers": { "Authorization": "Bearer <NVOOS_MCP_TOKEN>" } } } }

// 3. Manager browser session — sign in at /manage; the session cookie gates writes.

// 4. Local coding agent (same registry over stdio, from this repo)
npm run mcp:stdio

// 5. Zed / Claude Desktop / Cursor via the published npx bridge — the bridge
//    logs in with MCP_AI_LOGIN_* at startup, attaches the session cookies to
//    every request, and re-establishes the session on 401/403 or expiry, so
//    contract writes work with zero manual cookie handling
{ "mcpServers": { "nvoos-astro": {
  "command": "npx",
  "args": ["-y", "@nvdigitalsolutions/nvoos-mcp-bridge@latest"],
  "env": {
    "MCP_AI_BASE_URL": "https://astro.nvoos.cloud/mcp",
    "MCP_AI_TOKEN": "<NVOOS_MCP_TOKEN>",
    "MCP_AI_LOGIN_URL": "https://astro.nvoos.cloud/api/auth/login",
    "MCP_AI_LOGIN_EMAIL": "manager@your-domain.com",
    "MCP_AI_LOGIN_PASSWORD": "<password>"
  }
} } }
```

Runtime state (token overrides, runtime themes, snapshots) is process-local
unless `NVOOS_MCP_STATE_FILE` points at a writable path (atomic persistence).
See the deployment doc for the full env matrix.

## Development

```bash
cd examples/nvoos-astro-velocity
npm install

# Fixture contract server (hermetic data for build/tests):
node scripts/fixture-server.mjs            # :8123

# Build against the fixtures (prerenders the full catalog; also builds the
# Astro CMS bundle from addons/nvoos-astro-bridge — install its deps first):
cd ../../addons/nvoos-astro-bridge && npm install && cd -
NVOOS_API_BASE=http://localhost:8123/wp-json/nvoos-commerce/v1 npm run build

# Serve the built app + BFF against the fixtures:
NVOOS_TARGET_SITE=http://localhost:8123 PORT=4321 npm run serve
# → http://localhost:4321  (/, /products/1/aurora-3-seater-sofa/, /api/products)

# Tests (unit + contract gate):
npm test

# E2E (Playwright — installs its own servers; run once to fetch Chromium):
npx playwright install chromium
npm run test:e2e

# Lighthouse CI (needs Chromium — point CHROME_PATH at the Playwright build):
npm run lhci:ci

# Dev server (client islands need PUBLIC_API_BASE — see .env.example):
PUBLIC_API_BASE=http://localhost:8123/wp-json/nvoos-commerce/v1 npm run dev
```

## Contract drift guard (Phase 2.7)

The bridge's golden snapshot test
(`addons/nvoos-astro-bridge/tests/phpunit/Transformers/TestTransformerGolden.php`)
pins the transformer output; `npm run sync:bridge-fixtures` propagates the
goldens into `tests/fixtures/`. CI runs the generator and fails on any diff —
a contract change must update both sides in the same PR.

## Deploy sync (mirror)

`npm run sync:dist` assembles the deploy tree (`dist/` + `server.js` +
`scripts/serve.mjs` + runtime `package.json`). CI workflow
`sync-nvoos-astro-velocity.yml` builds, tests, smoke-tests and force-pushes
one flat commit to the **public** mirror repo
`nvdigitalsolutions/nvoos-astro-velocity` (owner decision Q6) → Cloudways
Velocity redeploys. Never commit to the mirror directly.

The mirror differs from the console mirrors deliberately: Astro SSR needs
its runtime dependencies, so `package.json` keeps real deps and Velocity
runs its normal `npm install` (build is a no-op — `dist/` is prebuilt).

## Velocity setup

1. Create a Node.js app on Velocity and connect the mirror repo.
2. Root directory: repo root. Entry file: default (`server.js` — a one-line
   shim booting `scripts/serve.mjs`; PM2 requires the root entry).
3. Build command: none (no-op shim included).
4. Env vars: `NVOOS_TARGET_SITE` (required), `ASTRO_SITE`,
   `NVOOS_CURRENCY`, `PORT` (injected).

## SEO notes

- JSON-LD (Product/Offer/AggregateRating/BreadcrumbList/Organization) is in
  the **initial HTML** — never client-injected.
- Canonical on base PLPs; `noindex,follow` on filtered permutations and
  pagination (crawl-budget discipline).
- PDPs are id-keyed (`/products/{id}/{slug}/`) with informational slug
  suffixes; unknown slugs 404 (prerendered pages cannot 301) while the
  canonical tag teaches crawlers the right URL.
- Catalog freshness: prerendered PDPs update at build; revalidate via the
  bridge "content changed" webhook → Velocity redeploy (Phase 2.3).

## License

MIT — see `LICENSE`.

The committed `/manage` admin bundle (`public/manage-assets/`) is built from
`addons/nvoos-astro-bridge/src`, which carries its own MIT grant
(`src/LICENSE` there — owner decision Q8). Bundled third-party components
(`@wordpress/*`, React, TanStack Query, zod) keep their licenses; see
`NOTICE` for attribution. The proprietary bridge PHP plugin is not part of
this app or its public mirror.
