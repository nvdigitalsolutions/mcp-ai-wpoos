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

## Development

```bash
cd examples/nvoos-astro-velocity
npm install

# Fixture contract server (hermetic data for build/tests):
node scripts/fixture-server.mjs            # :8123

# Build against the fixtures (prerenders the full catalog; also builds the
# Astro CMS bundle from addons/funiq-bridge — install its deps first):
cd ../../addons/funiq-bridge && npm install && cd -
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
(`addons/funiq-bridge/tests/phpunit/Transformers/TestTransformerGolden.php`)
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
