# nvoos-astro-velocity — NV-Authored Headless Storefront

> **Proposal 092.** An NV-authored, **MIT-licensed** Astro 7 storefront that
> binds **only** to the NV commerce contract (`nvoos-commerce/v1`, the
> Payload-shaped REST envelope). It replaces the licensed Funiq PWA as the
> public storefront while the PWA stays live as the A/B rollback (owner
> decision Q1: keep A/B).

<img width="75%" height="75%" alt="Screenshot 2026-11-09 202059" src="https://github.com/user-attachments/assets/db7d7dc4-28f2-40c1-bb1a-dff5c6916fc2" />


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
| PDP (`/products/{id}/{slug}/`) | **On-demand SSR** with route caching (Proposal 104 — the 092 build-time prerender baked fixture data into the mirror; SSR renders the live catalog, enables real 301s for slug drift, and publishes 404s for drafts) |
| All-products PLP (`/products/` + filters) | On-demand SSR, `s-maxage=300` (Proposal 104 P3) |
| PLP (`/categories|brands|colors/{id}/{slug}/` + filters) | On-demand SSR, `s-maxage=300` (prerendered page-1s were dropped — a static page would ignore filter query strings) |
| Home / promotions / promocodes / search | SSR, cached |
| Cart badge, gallery, promo-code field, add-to-cart, account | React islands (`client:idle` / `client:visible` / `client:load` on account) |
| Cart (`/cart/`) + embedded checkout (`/checkout/`, `/checkout/thanks/`) | SSR shells (`no-store` — a payment surface is never cached) + `CartView`/`CheckoutView` islands (Proposal 110) |
| `/manage` (Astro CMS) | SSR shell + `ManageGate` island (sign-in gate → injected CMS bundle); `noindex` |
| About / terms | Static markdown |
| Sitemap / robots | SSR endpoints (the sitemap integration cannot list SSR'd PLP bases, so a custom endpoint generates the full map; drafts are filtered by the 104 publication predicate) |

### Theme runtime (Proposal 104)

- `src/lib/theme/` is the client theme module: `localStorage` is the source
  of truth, application is idempotent (`data-theme` + `color-scheme` set
  atomically), and the layout boot **re-applies on every client-side
  navigation** via `astro:after-swap` — Astro's ClientRouter swaps the whole
  `<html>` element, so without the listener the theme reset to light on page
  2 (Defect A).
- The theme list derives from the ADS bundle (`src/lib/theme/registry.ts` →
  `tokens.generated.ts`), not a hardcoded array; ≤ 3 themes = single-button
  cycle, more = `role=menu` picker.
- A high-contrast axis (`data-contrast="high"` → `ContrastToggle`) remaps
  muted text to ink and lines to `line-strong`; reduced-motion is respected
  via a global `prefers-reduced-motion` kill switch.
- BFF security headers (Proposal 104 D12): CSP is computed per response with
  SHA-256 hashes of every inline script (never a blanket `unsafe-inline`);
  static assets pass through unbuffered.

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
| `NVOOS_API_BASE` | build (dev/CI only) | Full base URL of the commerce contract for the hermetic local build loop (no route fetches at build anymore — Proposal 104) |
| `NVOOS_TARGET_SITE` | `serve.mjs` (runtime) | Backend origin for the `/api` and `/media` proxies |
| `PUBLIC_API_BASE` | islands (dev only) | Client-side API base when `astro dev` has no BFF |
| `ASTRO_SITE` | build/runtime | Canonical host for sitemap, robots, canonical/OG tags |
| `NVOOS_CURRENCY` | build/runtime | Price formatting + JSON-LD (default `USD`) |
| `PORT` | `serve.mjs` | Listen port (Velocity injects it) |
| `NVOOS_REVALIDATE_TOKEN` | `serve.mjs` (runtime) | Shared secret for `POST /internal/revalidate/` (tag-based cache purge, Proposal 104 Q3) — unset disables the route (503) |
| `MAIL_PROVIDER` | `/forms/submit` (runtime) | `brevo` (default) or `mailjet` — Proposal 138 |
| `MAIL_FROM` / `MAIL_FROM_NAME` | `/forms/submit` (runtime) | Sender; must be on the provider-authenticated domain |
| `MAIL_TO` / `MAIL_SUBJECT` | `/forms/submit` (runtime) | Destination; subject defaults to "New website enquiry" |
| `BREVO_API_KEY` | `/forms/submit` (runtime) | Brevo credential (never commit) |
| `MAILJET_API_KEY` / `MAILJET_SECRET_KEY` | `/forms/submit` (runtime) | Mailjet credentials (never commit) |
| `PUBLIC_CONTACT_EMAIL` | `/contact/` (build) | Fallback address rendered on the page |

`npm run build:prod-sim` runs the production-parity build with `NVOOS_API_BASE`
**unset** — the CI-enforced mirror invariant (a build-time commerce base baked
fixture data into prod; Proposal 104 Defect B).

### Open Graph cards (Proposal 104 Q5)

`GET /og/product/{id}.png` renders a per-product branded OG card (satori +
resvg, ADS semantic tokens, Inter via Fontsource) and caches it for a day.
PDPs point `og:image` at it; render failures redirect to the product image —
an OG card never breaks the page.

## Public contact form (Proposal 138)

`/contact/` posts to `/forms/submit`, which sends through a provider HTTP API
(Brevo or Mailjet) — never raw SMTP, and never through `/api/*` (that prefix is
the commerce proxy, so an Astro route there is unreachable).

- **Native first**: a real `<form method="post">`, so it works without
  JavaScript. The no-JS path returns `303 → /contact/?sent=1`; a `fetch` caller
  gets `{ ok: true }`. The bundled `<script>` only upgrades it.
- **Abuse layers, in order**: 64 KB body cap → honeypot (silent accept) → 2 s
  time trap → 5 requests / 10 min per IP → provider send. A filled honeypot is
  accepted, never rejected: a rejection teaches the bot what to change.
- **The page is `no-store`** — it embeds a render timestamp for the time trap,
  and a cached copy would look "too fast to be human".
- **The transport is shared with the forge**: `src/lib/mail.ts` is byte-identical
  to `examples/nvoos-astro-forge/lib/mail.ts`, enforced by
  `bin/check-astro-app-parity.mjs`. Edit one, copy it to the other.
- **Secrets are read at request time** via `runtimeEnv()`; `import.meta.env` is a
  build-time snapshot and cannot see a key injected after the build.

Deliverability is a domain problem: publish SPF + DKIM + DMARC (aligned) for
`MAIL_FROM`'s domain. A perfect API call from an unauthenticated domain lands in
spam. Details:
`.agents/skills/mcp-ai-wpoos-astro-forge/reference/email-delivery.md`.

## MCP assistant surface (`/mcp`)

`nvoos-astro-mcp` 0.4.0 serves 35 tools over Streamable HTTP at `/mcp`
(bundled into `dist/mcp-entry.mjs` on `astro:build:done`). Every tool carries
a human-readable `title` and spec `annotations` (readOnly/destructive/idempotent/
openWorld hints) so MCP clients — Zed, NV oOS assistants, Claude — can render
and gate the registry without scraping descriptions:

- **Design (14)** — `tokens_get/set/import_dtcg/rollback`, `theme_*`,
  `contrast_check` (WCAG 2.2 AA/AAA levels), `design_analyze/compare/plan/theme`
  (inspired-by design-language import — never a clone). Theme descriptors carry
  an optional SemVer `version` (static themes declare it in their DTCG source;
  `theme_create` accepts it; imported themes default to 1.0.0) — the site
  footer shows the active theme and its version. Every mutating design
  write echoes its pre-write `snapshot_id`, so `tokens_rollback` can undo
  exactly that write (no argument rolls back to the latest snapshot).
- **CMS (6)** — `cms_list/get/create/update/delete`, `media_upload` over the
  commerce contract (collection allowlist, `limit` ≤ 100; writes require a
  manager session cookie — the backend enforces `manage_astro`).
- **Content (8, Proposal 107)** — `content_list/get/create/update/delete`,
  `content_preview_url`, `content_restore`, `content_validate` over the
  `content/pages` + `content/posts` collections. Writes are manager-gated and
  ride the approval ladder: creates default to **draft**, `pending`/`draft`
  round-trips stay open to the submitting role, and `publish`/`future` (or
  unpublishing) require the backend `publish_posts` capability — a state the
  submitting actor cannot flip (`content_publish_forbidden`). Documents carry
  Portable Text `blocks` (the allowlisted component schema) and an audit trail
  (`submittedBy`/`approvedBy`). `content_preview_url` mints a short-lived
  signed link that exchanges for a host-only preview cookie at
  `/preview/{type}/{id}` and redirects to the canonical route — draft previews
  work for logged-in managers only (fail-closed 404 otherwise) and render with
  a draft banner, `noindex`, and no caching. `content_validate` is the
  read-only dry-run gate (block schema + raw-HTML sanitizer).
- **Ops (5)** — `site_status`, `preview_url`, `rebuild_trigger` (webhook env),
  `screenshot` (opt-in `NVOOS_MCP_SCREENSHOT=1`, path-only input),
  `nds_export` (the NDS DTCG document for the Design System round-trip).
- **Shop (2, Proposal 108)** — `shop_get`/`shop_update` over the shop globals
  doc (checkout/cart/return URLs, display currency, payment-method hints).
  Writes are manager-gated and the backend enforces same-site/allowlisted
  URLs on the editable fields.

## Embedded checkout (Proposal 110)

The cart hands off to the EMBEDDED checkout (`/checkout/`) in woo/both product
modes — the WooCommerce session cart rides the bridge `/checkout` proxy
(Store API, bridge-minted nonce), so addresses, shipping rates, coupons and
payment all process Woo-side through the configured gateway. Card collection
happens inside Stripe's hosted Payment Element (INV-1: the app never renders
raw card fields; card data never touches the app/BFF/bridge). The hosted
`checkoutUrl` stays the fail-open fallback, and Apple/Google Pay is a
live-verified follow-up (Stripe.js v10 removed the token-yielding Payment
Request Button).

Auth is fail-closed: reads are public; design writes accept an
`Authorization: Bearer <NVOOS_MCP_TOKEN>` **or** a manager session cookie;
contract writes (cms_* + content_* writes, media_upload) require the manager
cookie. Host/Origin allowlists (DNS-rebinding guards), a per-IP rate limit, a
per-session content-write quota (G14 — `NVOOS_MCP_CONTENT_WRITE_MAX` writes per
`NVOOS_MCP_CONTENT_WRITE_WINDOW_MS`, default 30/10 min), and audit log lines
sit in front of the handler. The /manage **Design System tab** is itself an
MCP client over the same-origin endpoint (manager cookie).

Preview tokens (107 §3.2): `NVOOS_PREVIEW_SECRET` (HMAC secret — set it on
production mounts; the dev fallback is intentionally public) and
`NVOOS_PREVIEW_TTL_MS` (default 30 min). Successful content writes purge the
matching route-cache tags through `/internal/revalidate` when
`NVOOS_REVALIDATE_TOKEN` + `NVOOS_SSR_API_ORIGIN` are configured.

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
