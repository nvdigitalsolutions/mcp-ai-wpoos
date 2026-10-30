# Astro Bridge

> The WordPress half of the NV Astro storefront (Proposal 092) — the
> Payload-compatible `nvoos-commerce/v1` commerce envelope over WordPress +
> WooCommerce, plus the embedded Astro CMS admin. The legacy `funiq/v1`
> namespace is retained for the licensed PWA leg (owner decision Q1).

---

## Quick Install (on a WordPress site)

### 1. Copy the plugin folder

Upload the entire plugin folder to your WordPress site:

```
/wp-content/plugins/nvoos-astro-bridge/
```

The repo folder is `addons/nvoos-astro-bridge/`; the plugin is **packaged** as
`nvoos-astro-bridge/` (zip top-level folder, main file `nvoos-astro-bridge.php`
inside). Build the zip with PowerShell `Compress-Archive` of the folder —
`tar -a` produces broken zips, and zipping the folder's *contents* (files at
the zip root) installs `nvoos-astro-bridge.php` directly into `wp-content/plugins/`.
Uploading twice with the same folder name creates a random-suffix folder —
delete the old copy first.

You can do this via SFTP, or zip the folder and upload via **Plugins → Add New → Upload Plugin**.

### 2. Activate

Go to **Plugins → Installed Plugins**, find "Astro Bridge", and click **Activate**.

On activation the plugin:
- Creates the `manage_astro` capability and grants it to Administrators and Editors (existing installs are migrated automatically from the legacy `manage_funiq` slug on the next page load)
- Seeds default option values for Banner and Carousel
- Flushes rewrite rules so the REST API endpoints are reachable

### 3. Done — no build step needed

The admin SPA ships pre-compiled in `build/index.js`. After activation, an **Astro CMS** menu item appears in the WordPress admin sidebar immediately. No `npm install` or build step is required on the server.

> **For developers:** If you modify the React source in `src/`, rebuild with:
> ```bash
> cd addons/nvoos-astro-bridge && npm install && npm run build
> ```

### 4. Verify it works

- Visit **Astro CMS** in the WP admin sidebar → you should see the Products list.
- Hit `GET /wp-json/funiq/v1/products` in your browser or `curl` → you should get a `{"docs":[],"totalDocs":0,...}` response.

---

## Connecting the Funiq PWA frontend (legacy `funiq/v1`)

The Funiq React PWA expects to fetch from `/api/products`, `/api/categories`, etc. You have two options:

### Option A — `.htaccess` rewrite (recommended, zero PWA changes)

Add this to your WordPress `.htaccess` file, **before** the WordPress rules:

```apache
RewriteEngine On

# Proxy Funiq PWA API calls to the WordPress REST API.
RewriteRule ^api/(.*)$ /wp-json/funiq/v1/$1 [L,QSA]
```

Now `GET /api/products` → `GET /wp-json/funiq/v1/products` transparently.

### Option B — change one file in the PWA

In the Funiq PWA source, edit `funiq/src/config/index.tsx`:

```ts
// Replace:
const MAIN_URL = 'https://your-payload-server.com/api';

// With:
const MAIN_URL = 'https://your-wordpress-site.com/wp-json/funiq/v1';
```

And remove the `/api` prefix from each endpoint URL (since `funiq/v1` already includes the namespace).

---

## Data Model Mapping

| Contract / Payload Field | WordPress Storage | Notes |
|---|---|---|
| `name` | `post_title` | |
| `description` | `post_content` | |
| `price` | `_funiq_price` (post meta) | Float |
| `oldPrice` | `_funiq_old_price` (post meta) | Nullable float |
| `category` | `funiq_category` taxonomy | Single term → `{id, name}` |
| `brand` | `funiq_brand` taxonomy | Single term → `{id, name}` |
| `colors` | `funiq_color` taxonomy | Multiple terms → `[{id, name, hexCode}]` |
| `statuses` | `funiq_status` taxonomy | Multiple terms → `[{id, name}]` |
| `promotion` | `_funiq_promotion_id` (post meta) | References `funiq_promotion` CPT |
| `image` | `_thumbnail_id` | Output as URL (PWA) + `imageId` raw ID (admin) |
| `images` | `_funiq_gallery` (post meta) | Array of attachment IDs → URLs in response |
| `width`/`height`/`depth` | Post meta | Floats |
| `rating` | Post meta | Float 0–5 |
| `isBestseller`/`isFeatured` | Post meta | Boolean |
| `createdAt`/`updatedAt` | `post_date`/`post_modified` | |

---

## REST API Endpoints

All endpoints under `/wp-json/funiq/v1/` and — identically — under
`/wp-json/nvoos-commerce/v1/` (the NV commerce contract namespace,
Proposal 092; the Astro storefront binds here). The legacy namespace is
retained for the licensed PWA (owner decision Q1: keep A/B) and retires
with it.

| Method | Path | Auth |
|--------|------|------|
| `GET` | `/products[?page=&limit=&where=...&sort=...]` | Public |
| `GET` | `/products/{id}` | Public |
| `POST` | `/products` | `manage_astro` |
| `PUT` | `/products/{id}` | `manage_astro` |
| `DELETE` | `/products/{id}` | `manage_astro` |
| `GET`/`POST`/`PUT`/`DELETE` | `/categories[/{id}]` | Read public / Write cap |
| `GET`/`POST`/`PUT`/`DELETE` | `/brands[/{id}]` | Read public / Write cap |
| `GET`/`POST`/`PUT`/`DELETE` | `/colors[/{id}]` | Read public / Write cap |
| `GET`/`POST`/`PUT`/`DELETE` | `/statuses[/{id}]` | Read public / Write cap |
| `GET`/`POST`/`PUT`/`DELETE` | `/promotions[/{id}]` | Read public / Write cap |
| `GET`/`POST`/`PUT`/`DELETE` | `/promocodes[/{id}]` | Read public / Write cap |
| `GET` | `/promocodes/validate?code=...` | Public (idempotent read) |
| `GET`/`PUT` | `/globals/banner` | Read public / Write cap |
| `GET`/`PUT` | `/globals/carousel` | Read public / Write cap |
| `GET` | `/auth/nonce` | Public (CSRF nonce) |
| `POST` | `/auth/register` | Public (requires nonce) |
| `POST` | `/auth/login` | Public |
| `POST` | `/auth/logout` | Public |
| `GET` | `/auth/me` | Logged-in cookie |
| `GET` | `/managers[?search=&page=&limit=]` | `manage_astro` |
| `PUT` | `/managers/{id}` (`{"manage": true|false}`) | `manage_astro` |

**Managers:** `GET /managers` lists every user holding the `manage_astro`
capability (role- or user-granted). `?search=` returns up to 20 matching
users (by name or email) with an `isManager` flag, powering the CMS "add a
manager" picker. `PUT /managers/{id}` promotes (`manage: true`) or demotes
(`manage: false`) via a user-level capability grant — role capabilities are
never touched. Guardrails: site owners (super admins) cannot be modified
from the CMS, and a manager cannot remove their own access. The
`funiq_bridge_managers_protected_user` filter extends the protected set.

**Auth payload (camelCase, 2026-10-30):** `GET /auth/me` returns the
session customer as `{ id, email, name, canManage, adminUrl, capabilities,
readOnlyCollections }`. `capabilities` is the session's granted WordPress
capability slugs (sorted); `readOnlyCollections` is the server-computed
mode-derived list (woo mode → `["statuses"]`, whose writes answer 405).
The admin SPA consumes both to gate surfaces without probing endpoints —
the console's capability/endpoint-emptiness pattern. Cookie-authenticated
writes require the `X-WP-Nonce` header minted by `GET /auth/nonce`; the
admin bundle re-mints and retries once on `rest_cookie_invalid_nonce`.

**Filtering & sorting (B2):** `where` supports `id.equals`, `name.like`,
`category.equals`/`category.in`, `brand.equals`/`brand.in`,
`colors.equals`/`colors.in`, `statuses.equals`/`statuses.in`. `sort` accepts
`date` (default), `-date`, `price`, `-price`, `rating`, `-rating`, `name`,
`-name`.

**Cache headers (B1):** successful **unauthenticated** GET responses on both
namespaces carry `Cache-Control: public, s-maxage=300, stale-while-revalidate=60`,
an ETag, and (for single documents) `Last-Modified` — enabling the Velocity
`serve.mjs` proxy micro-cache and the Astro fetch layer to serve from cache.

**Rebuild webhook (Proposal 092 Phase 2.3):** when the options
`funiq_bridge_rebuild_webhook_url` (e.g. a GitHub dispatches endpoint) and
`funiq_bridge_rebuild_webhook_token` (optional bearer token) are configured,
every catalog change (products, promotions, promocodes, terms, banner /
carousel) fires one debounced `nvoos-storefront-content-changed` notification
on shutdown — the storefront's CI re-syncs and redeploys. Empty URL = no-op.

**Query examples:**

```bash
# List products, page 2, 20 per page, sorted by price ascending
curl "https://yoursite.com/wp-json/nvoos-commerce/v1/products?page=2&limit=20&sort=-price"

# Get a single product
curl https://yoursite.com/wp-json/nvoos-commerce/v1/products/42

# Filter products by category
curl "https://yoursite.com/wp-json/nvoos-commerce/v1/products?where[category][equals]=5"

# Filter by brand and multiple colors
curl "https://yoursite.com/wp-json/nvoos-commerce/v1/products?where[brand][equals]=3&where[colors][in]=1&where[colors][in]=2"

# Free-text search
curl "https://yoursite.com/wp-json/nvoos-commerce/v1/products?where[name][like]=sofa"

# Validate a promo code
curl "https://yoursite.com/wp-json/nvoos-commerce/v1/promocodes/validate?code=SUMMER10"

# Create a product (requires authentication)
curl -X POST https://yoursite.com/wp-json/nvoos-commerce/v1/products \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -d '{"name":"New Product","price":29.99}'
```

**Paginated response shape** (Payload-compatible):

```json
{
  "docs": [ ... ],
  "totalDocs": 150,
  "limit": 10,
  "totalPages": 15,
  "page": 1,
  "hasNextPage": true,
  "hasPrevPage": false
}
```

---

## WooCommerce integration (WP/Woo mode vs CMS Basic)

The bridge has two product backends behind one contract:

| Mode | Backing store | Products | Categories | Brands/Colors | Statuses |
|---|---|---|---|---|---|
| `native` (default) — **CMS Basic** | Built-in `funiq_product` CPTs | bridge CPTs | `funiq_category` | bridge taxonomies | `funiq_status` |
| `woo` — **WP/Woo mode** | WooCommerce `product` | Woo products | `product_cat` | `pa_brand`/`pa_color` attributes (falls back to `product_brand` / bridge taxonomies) | stock-status pseudo-collection (read-only) |
| `both` | merged | native first, then Woo | native taxonomy | native taxonomies | native taxonomy |

**Enable via** `wp option update funiq_bridge_products_source woo` (or `both`),
or in code: `add_filter( 'funiq_bridge_products_source', fn() => 'woo' );`.
A `woo`/`both` mode **degrades to `native`** when WooCommerce is not active, so the contract never breaks.

### Why this shape (industry-standard)

- **In-process CRUD API, not the REST/Store API.** WooCommerce's REST API
  (`/wc/v3`) and Store API exist for *remote* headless consumers and carry
  HTTP + key-auth overhead. A plugin on the same install uses the CRUD
  layer directly — `wc_get_product`, `WC_Product_Simple` setters, and
  `WP_Query` over the `product` post type with Woo's own meta keys
  (`_price`, `_wc_average_rating`) for filtering/sorting. (WC's
  `WC_Product_Query` data store drops `s` and `meta_query`, so listing uses
  `WP_Query` with `wc_get_product` hydration — pinned by tests.)
- **One Payload envelope.** The storefront binds to `nvoos-commerce/v1`
  regardless of backing store — the same contract-invariant that makes the
  T1 Laravel flip a non-event. Product documents carry an additive
  `source` field (`native` | `woocommerce`).
- **IDs need no namespacing.** Posts and terms share global ID sequences,
  so native and Woo documents can never collide — merged listings and
  single-document routing are safe by construction.

### Field mapping (contract ← WooCommerce)

| Contract | WooCommerce |
|---|---|
| `price` | current price (sale price when on sale) |
| `oldPrice` | `regular_price` when higher than the current price |
| `description` | description (falls back to short description) |
| `width` / `height` / `depth` | width / height / length |
| `rating` | average rating |
| `isFeatured` | featured flag |
| `isBestseller` | total sales ≥ 10 (filter `funiq_bridge_woo_bestseller_threshold`) |
| `category` / `brand` / `colors` | `product_cat` / `pa_brand` / `pa_color` |
| `imageId` / `imagesIds` | thumbnail + gallery attachment IDs |
| `statuses` | stock status (`instock` → In stock, …) |
| `promotion` | always null in Woo mode |

**Writes:** create/update use `WC_Product` setters — `price` + `oldPrice`
map to regular/sale price, `imageId`/`imagesIds` to Woo's image fields,
category/brand/colors to term assignment. In `both` mode, POST /products
accepts a `source` body field (`native` | `woocommerce`) to choose the
backing store (default: the primary source).

**Query support (where/sort):** `id.equals`, `name.like`, `category/brand/colors`
`.equals/.in`, and `price.gt/.gte/.lt/.lte` (native source additionally
supports `statuses` filters); `sort` accepts `date/-date`, `price/-price`,
`rating/-rating`, `name/-name`.

---

## Admin SPA (Astro CMS)

Log in to WordPress as an Administrator or Editor. Click **Astro CMS** in the admin sidebar. The same SPA also runs **standalone** on the Astro storefront's `/manage` surface (see the storefront app — the standalone esbuild bundle ships `@wordpress/*` + React with no admin script chain).

### Collections managed

- **Products** — full product data including price, dimensions, categories, brands, colors, statuses, promotions, images
- **Categories** — name + category image
- **Brands** — name only
- **Colors** — name + hex code
- **Statuses** — name only
- **Promotions** — title, description, date range, active toggle
- **Promocodes** — code, discount %, expiry date, logo

Each collection has a list view (paginated table) and create/edit forms with fields appropriate to the data type.

### Media handling

Image fields use a **native uploader** against the bridge's media endpoint (`POST /media`, multipart, `manage_astro`-gated) — identical in wp-admin and on the standalone surface, no media-modal dependency. The endpoint returns `{ id, url, path }`; previews use the host's media base (`/media/*` on the storefront, absolute URL in wp-admin). `GET /media/{id}` returns the same record for already-attached images.

---

## Development

```bash
cd addons/nvoos-astro-bridge

# Install JS dependencies
npm install

# Build the React SPA (development, unminified, with source maps)
npm run build:dev

# Watch for changes (auto-rebuild on save)
npm run watch

# Production build (minified)
npm run build

# Standalone bundle for the storefront /manage surface (bundles @wordpress/* +
# React; output: build/standalone/astro-admin.{js,css})
npm run build:standalone
```

### PHP autoloading

The addon uses PSR-4 autoloading via `spl_autoload_register` (no Composer `vendor/` required at runtime). If you have Composer available, run `composer install` for dev tooling.

---

## License

Split license (Proposal 092, owner decision Q8):

- **The WordPress plugin** (main plugin file, `includes/`, build tooling) —
  **proprietary** (`LICENSE`). Self-hosted distribution only; not
  wp.org-compatible.
- **The React admin SPA source in `src/`** — **MIT** (`src/LICENSE`). This
  carve-out exists because the standalone build
  (`build/standalone/astro-admin.{js,css}`) is committed to the MIT-licensed
  Astro storefront (`examples/nvoos-astro-velocity/public/manage-assets/`)
  and shipped in its public mirror — the source of bytes published under
  MIT must itself be MIT.
- Bundled third-party packages (`@wordpress/*`, React) keep their own
  licenses (GPL-2.0-or-later / MIT); attribution lives in the storefront's
  `NOTICE`.

### Code standards

The addon carries its own ruleset, `phpcs.xml.dist`, and is excluded from the
base plugin's `phpcs.xml.dist` scan (PSR-4 class-file naming conflicts with
the WPCS filename convention). Deliberate exclusions are documented in the
ruleset: short ternaries and the meta_key/tax_query slow-query warnings (the
bridge sorts products by meta and counts category products via `tax_query`
against the small native CPT — mirrors the Payload frontend's query
behaviour). A dedicated step in `.github/workflows/php-linting.yml` runs the
addon ruleset on every PR/push:

```bash
vendor/bin/phpcs --standard=addons/nvoos-astro-bridge/phpcs.xml.dist \
  addons/nvoos-astro-bridge/includes addons/nvoos-astro-bridge/nvoos-astro-bridge.php \
  addons/nvoos-astro-bridge/uninstall.php
```

### Running tests

```bash
# From the main plugin root:
vendor/bin/phpunit --group=nvoos-astro-bridge
```

---

## Uninstalling

Deleting the plugin via **Plugins → Delete** runs `uninstall.php`, which removes:

- All `funiq_product`, `funiq_promotion`, and `funiq_promocode` posts
- All terms in `funiq_category`, `funiq_brand`, `funiq_color`, and `funiq_status` taxonomies
- The `funiq_banner` and `funiq_carousel` options
- The `manage_astro` capability (and any legacy `manage_funiq` grants) from all roles

**Deactivating** (not deleting) only flushes rewrite rules — no data is removed.

---

## Requirements

- WordPress 6.7+
- PHP 8.1+
- Node.js 18+ (dev/build only; not needed by end users)
- NV oOS base plugin — optional; Astro Bridge is standalone

## License

Proprietary — see `LICENSE` file.
