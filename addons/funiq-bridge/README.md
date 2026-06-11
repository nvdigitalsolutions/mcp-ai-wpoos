# Funiq Bridge

> Bridges the Funiq React PWA frontend (built for Payload CMS) to a WordPress backend.

---

## Quick Install (on a WordPress site)

### 1. Copy the plugin folder

Upload the entire `funiq-bridge/` folder to your WordPress site:

```
/wp-content/plugins/funiq-bridge/
```

You can do this via SFTP, or zip the folder and upload via **Plugins → Add New → Upload Plugin**.

### 2. Activate

Go to **Plugins → Installed Plugins**, find "Funiq Bridge", and click **Activate**.

On activation the plugin:
- Creates the `manage_funiq` capability and grants it to Administrators and Editors
- Seeds default option values for Banner and Carousel
- Flushes rewrite rules so the REST API endpoints are reachable

### 3. Done — no build step needed

The admin SPA ships pre-compiled in `build/index.js`. After activation, a **Funiq CMS** menu item appears in the WordPress admin sidebar immediately. No `npm install` or build step is required on the server.

> **For developers:** If you modify the React source in `src/`, rebuild with:
> ```bash
> cd addons/funiq-bridge && npm install && npm run build
> ```

### 4. Verify it works

- Visit **Funiq CMS** in the WP admin sidebar → you should see the Products list.
- Hit `GET /wp-json/funiq/v1/products` in your browser or `curl` → you should get a `{"docs":[],"totalDocs":0,...}` response.

---

## Connecting the Funiq PWA frontend

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

| Funiq / Payload Field | WordPress Storage | Notes |
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

All endpoints under `/wp-json/funiq/v1/`.

| Method | Path | Auth |
|--------|------|------|
| `GET` | `/products[?page=&limit=&where=...]` | Public |
| `GET` | `/products/{id}` | Public |
| `POST` | `/products` | `manage_funiq` |
| `PUT` | `/products/{id}` | `manage_funiq` |
| `DELETE` | `/products/{id}` | `manage_funiq` |
| `GET`/`POST`/`PUT`/`DELETE` | `/categories[/{id}]` | Read public / Write cap |
| `GET`/`POST`/`PUT`/`DELETE` | `/brands[/{id}]` | Read public / Write cap |
| `GET`/`POST`/`PUT`/`DELETE` | `/colors[/{id}]` | Read public / Write cap |
| `GET`/`POST`/`PUT`/`DELETE` | `/statuses[/{id}]` | Read public / Write cap |
| `GET`/`POST`/`PUT`/`DELETE` | `/promotions[/{id}]` | Read public / Write cap |
| `GET`/`POST`/`PUT`/`DELETE` | `/promocodes[/{id}]` | Read public / Write cap |
| `GET`/`PUT` | `/globals/banner` | Read public / Write cap |
| `GET`/`PUT` | `/globals/carousel` | Read public / Write cap |

**Query examples:**

```bash
# List products, page 2, 20 per page
curl https://yoursite.com/wp-json/funiq/v1/products?page=2&limit=20

# Get a single product
curl https://yoursite.com/wp-json/funiq/v1/products/42

# Filter products by category
curl "https://yoursite.com/wp-json/funiq/v1/products?where[category][equals]=5"

# Create a product (requires authentication)
curl -X POST https://yoursite.com/wp-json/funiq/v1/products \
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

## Admin SPA (Funiq CMS)

Log in to WordPress as an Administrator or Editor. Click **Funiq CMS** in the admin sidebar.

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

Image uploads use the WordPress Media Library (the same picker the block editor uses). When creating/editing a product, click **Select Image** to open the media modal. The selected attachment ID is stored as post meta and resolved to a URL in the API response.

---

## Development

```bash
cd addons/funiq-bridge

# Install JS dependencies
npm install

# Build the React SPA (development, unminified, with source maps)
npm run build:dev

# Watch for changes (auto-rebuild on save)
npm run watch

# Production build (minified)
npm run build
```

### PHP autoloading

The addon uses PSR-4 autoloading via `spl_autoload_register` (no Composer `vendor/` required at runtime). If you have Composer available, run `composer install` for dev tooling.

### Running tests

```bash
# From the main plugin root:
vendor/bin/phpunit --group=funiq-bridge
```

---

## Uninstalling

Deleting the plugin via **Plugins → Delete** runs `uninstall.php`, which removes:

- All `funiq_product`, `funiq_promotion`, and `funiq_promocode` posts
- All terms in `funiq_category`, `funiq_brand`, `funiq_color`, and `funiq_status` taxonomies
- The `funiq_banner` and `funiq_carousel` options
- The `manage_funiq` capability from all roles

**Deactivating** (not deleting) only flushes rewrite rules — no data is removed.

---

## Requirements

- WordPress 6.7+
- PHP 8.1+
- Node.js 18+ (dev/build only; not needed by end users)
- NV oOS base plugin — optional; Funiq Bridge is standalone

## License

Proprietary — see `LICENSE` file.
