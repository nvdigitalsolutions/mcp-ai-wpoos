# `nvoos-commerce/v1` — wire contract reference

> **Purpose.** A byte-compatible specification of every JSON document the NV
> commerce contract emits, so a second server implementation (PostgreSQL,
> Laravel, Node) can replace the WordPress bridge behind the same `/api/*`
> proxy without touching the Astro storefront or the `/manage` CMS.
>
> **Authority.** Extracted from `addons/nvoos-astro-bridge/includes/` (the
> serving implementation) and cross-checked against the two consuming type
> layers: `examples/nvoos-astro-velocity/src/lib/commerce/types.ts` (Astro SSR)
> and `addons/nvoos-astro-bridge/src/types/index.ts` (the CMS SPA). Where the
> three disagree, the disagreement is recorded in
> [§17 Drift register](#17-drift-register) — it is **not** silently resolved.

---

## 0. Scope and invariants

| Item | Value |
|---|---|
| Canonical namespace | `nvoos-commerce/v1` |
| Legacy alias | `funiq/v1` — **identical routes**, registered twice from the same controllers (`Plugin::register_rest_routes`) |
| Base path | `/wp-json/{namespace}/` on the origin; `/api/{collection}` through the storefront proxy |
| Proxy | `examples/nvoos-astro-velocity/scripts/serve.mjs` → `{TARGET_SITE}/wp-json/nvoos-commerce/v1/{rest}{query}` |
| Consumers | Astro SSR (`src/lib/commerce/client.ts`), `/manage` SPA (`src/hooks/useCollection.ts`), storefront MCP (`mcp/tools/*.ts`) |

**A replacement server must be reachable at the exact same URL shape.** The
proxy appends `{collection}{?query}` verbatim to
`/wp-json/nvoos-commerce/v1/` — so a Postgres implementation either keeps that
path prefix or the proxy target changes and nothing else does.

### 0.1 Three invariants that outrank everything else

1. **Envelope.** Every *collection* response is the Payload envelope, exactly
   the six keys in §1.1. No wrapper, no `data`, no pagination links.
2. **Errors.** Every non-2xx body is the WordPress `WP_Error` REST shape
   `{code, message, data:{status}}` (§1.2). `@wordpress/api-fetch` reads
   `error.code` — `useCollection.ts` specifically branches on
   `rest_cookie_invalid_nonce` to re-mint and retry. A differently shaped error
   body breaks the CMS's nonce-retry ladder.
3. **`X-WP-Nonce`.** The header name is part of the contract even though the
   value is opaque. The CMS SPA sends it on every request and re-mints from
   `GET /auth/nonce`. A replacement server may mint its own CSRF token; it must
   accept and validate it under that exact header.

---

## 1. Universal shapes

### 1.1 Paginated envelope

Emitted by `BaseController::paginatedResponse()`. Key order as built:

```json
{
  "docs": [],
  "totalDocs": 0,
  "limit": 10,
  "totalPages": 1,
  "page": 1,
  "hasNextPage": false,
  "hasPrevPage": false
}
```

| Field | Type | Rule |
|---|---|---|
| `docs` | array | Documents, in server order |
| `totalDocs` | int | Total matching records (post-filter, pre-pagination) |
| `limit` | int | Page size **as the server resolved it** — not what the client asked |
| `totalPages` | int | `ceil(totalDocs / limit)`, minimum `1` |
| `page` | int | 1-based, as resolved |
| `hasNextPage` | bool | `page < totalPages` |
| `hasPrevPage` | bool | `page > 1` |

**Exception:** `GET /content/{pages,posts,local}/{id}/revisions` returns only
`{"docs":[...],"totalDocs":N}` — no pagination keys. See §5.4.

### 1.2 Error body

WordPress REST serialization of `WP_Error`:

```json
{ "code": "astro_not_found", "message": "Product not found.", "data": { "status": 404 } }
```

`rest_invalid_param` additionally carries `data.params`. Codes observed across
the bridge: `astro_not_found`, `astro_invalid_source`, `astro_missing_name`,
`astro_delete_failed`, `astro_revision_not_found`, `astro_restore_failed`,
`astro_managers_not_found`, `astro_managers_protected`, `astro_managers_self`,
`astro_shop_invalid_body`, `astro_shop_invalid_field`,
`astro_shop_invalid_currency`, `astro_shop_invalid_currency_symbol`,
`astro_theme_policy_invalid_*`, `astro_cart_*`, `astro_woo_readonly_statuses`,
`content_publish_forbidden`, `nvoos_invalid_nonce`, `nvoos_invalid_email`,
`nvoos_weak_password`, `nvoos_email_exists`, `nvoos_registration_failed`,
`nvoos_invalid_credentials`, `nvoos_media_missing_file`,
`nvoos_media_invalid_file`, `nvoos_media_upload_failed`,
`nvoos_media_attach_failed`, `nvoos_media_not_found`,
`nvoos_promocode_not_found`, `nvoos_promocode_inactive`,
`nvoos_promocode_expired`, `rest_forbidden`,
`rest_cookie_invalid_nonce`, `rest_invalid_param`.

### 1.3 Status codes

| Code | Meaning in this contract |
|---|---|
| 200 | Success (all GET/POST/PUT/PATCH) |
| 204 | Successful `DELETE` — **empty body** |
| 400 | Malformed body / invalid field / invalid argument |
| 401 | No session (`/auth/me`, writes) |
| 403 | Session without the capability, or CSRF failure |
| 404 | Missing document — **also** returned instead of 403 for a non-published doc read by an anonymous caller (existence must not leak) |
| 405 | Write to a read-only collection (Woo stock statuses) |
| 409 | Duplicate account email |
| 422 | Promocode inactive / expired |
| 501 | Feature requires a dependency not installed (WooCommerce Store API) |

### 1.4 Response headers

Applied by `BaseController::set_cache_headers_on_public_gets()` (B1, priority
10) to anonymous 2xx `GET`s:

```
Cache-Control: public, s-maxage=300, stale-while-revalidate=60
ETag: "<md5 of wp_json_encode(body)>"
Cache-Tag: <collection>[,<collection>:<id>]
Surrogate-Key: <collection>[ <collection>:<id>]
Last-Modified: <from updatedAt, when present>      # single docs only
```

`globals/theme` and `globals/shop` override to
`public, s-maxage=60, stale-while-revalidate=15` at priority 20.

**Never cached:** authenticated responses (`is_user_logged_in()`), any request
carrying a `preview_token` param (presence, not validity), and non-2xx.
A replacement server must reproduce the `preview_token` and auth exclusions or
drafts leak through the CDN.

### 1.5 Value encodings

| Kind | Encoding | Notes |
|---|---|---|
| IDs | JSON integer | Never string |
| Booleans | JSON `true`/`false` | Never `0`/`1` |
| Prices / dimensions | JSON number | `float` in PHP → `json_encode` drops the zero fraction (`1299.0` → `1299`) |
| Doc timestamps | `YYYY-MM-DD HH:MM:SS` | WP `post_date` / `post_modified` — **site-local, no offset** |
| Globals timestamps | ISO 8601 | `gmdate('c')` → `2026-11-15T10:30:00+00:00` |
| Media | Absolute URL **or** `/media/<path>` | Mode-switched by `Schema::media_url_from()` (see §1.6) |
| Empty string vs null | Both occur, per field | Table in each section; do not normalize |

> **Postgres trap.** `node-postgres` returns `numeric`/`decimal`/`bigint` as
> **strings**. Emitting `"1299"` where PHP emits `1299` is a silent contract
> break that no type checker catches. Cast in SQL or register a type parser;
> the fixtures compare `1299` (number), not `"1299"`.

### 1.6 Media URL resolution

`Schema::media_url( $attachment_id )` → `''` when the ID is empty, otherwise
`Schema::media_url_from( wp_get_attachment_url( $id ) )`:

- `OPTION_MEDIA_ORIGIN = 'cms'` (default) → the absolute WordPress uploads URL.
- `OPTION_MEDIA_ORIGIN = 'frontend'` → `/media/<path-after-/wp-content/uploads>`.

The storefront's `normalizeMediaUrl()` then rewrites **any** absolute URL whose
path starts with `/wp-content/uploads` to `/media/<rest>`, and passes
non-absolute values through untouched. Consequence: a replacement server may
emit any absolute host, but the `/wp-content/uploads` path segment must be
preserved for the rewrite to fire.

---

## 2. Query grammar

### 2.1 Parameters

| Param | Type | Default | Notes |
|---|---|---|---|
| `page` | int ≥ 1 | `1` | |
| `limit` | int 1–100 | **`10` on `/products`**, `100` elsewhere | Term collections ignore it (§2.3) |
| `sort` | string | `date` | `/products` only: `date`, `-date`, `price`, `-price`, `rating`, `-rating`, `name`, `-name`. **Bare = DESC, `-` prefix = ASC** — see §17 D18 |
| `status` | `publish`\|`draft`\|`any` | `publish` | `/products`, `/content/pages`, `/content/posts`, `/local` only |
| `where` | brackets **or** JSON string | `{}` | See §2.2 |
| `preview_token` | string | — | `/local` and any route read by the SSR preview flow |
| `search` | string ≥ 2 chars | `''` | `/managers` only |

### 2.2 `where` clause

Wire form is bracket-expanded, one param per value:

```
?where[category][equals]=5
&where[colors][in]=1&where[colors][in]=2
&where[name][like]=sofa
```

Operators: `equals`, `in`, `like`. `WhereClause::normalize()` also accepts a
single JSON-encoded string (`?where={"name":{"like":"sofa"}}`) — used by
`ProductsController`, and by `ContentTransform::where()` for the content
collections. Both forms must be accepted.

### 2.3 Per-collection capability matrix

| Collection | `page`/`limit` | `sort` | `where` | `status` |
|---|---|---|---|---|
| `products` | yes (default 10) | yes | `id.equals`, `name.like`, `category`/`brand`/`colors`/`statuses` `.equals`/`.in` (native) + `price.gt/gte/lt/lte` (woo) — see §17 D19 | yes (manage-gated) |
| `categories` | **ignored** | no | no | no |
| `brands` | **ignored** | no | no | no |
| `colors` | **ignored** | no | no | no |
| `statuses` | **ignored** | no | no | no |
| `promotions` | yes (default 100) | no | no | **ignored** |
| `promocodes` | yes (default 100) | no | no | **ignored** |
| `content/pages` | yes (default 100) | no | `slug.equals` | yes (manage-gated) |
| `content/posts` | yes (default 100) | no | `slug.equals`, `category.equals` | yes (manage-gated) |
| `local` | yes (default 100) | no | `slug.equals` | yes (manage-gated) |
| `media` | n/a | n/a | n/a | n/a |
| `managers` | yes (default 50) | no | no (`search`) | no |

Term collections return the whole set as one page:
`{docs, totalDocs: N, limit: N, totalPages: 1, page: 1, hasNextPage: false, hasPrevPage: false}`.

---

## 3. Authentication

### 3.1 Session model

Cookie session (`wordpress_logged_in_*`) + `X-WP-Nonce` header. The nonce is
minted per-session by `GET /auth/nonce`:

```
GET /api/auth/nonce                       # public
200 {"nonce":"<opaque>"}
```

`AuthSupport::maybe_restore_user_from_logged_in_cookie()` runs inside the
permission callbacks because WP 6.9's `rest_cookie_check_errors()` forces user 0
on cookie requests without a nonce, and the secure auth cookie is path-scoped to
`/wp-admin` + `/wp-content/plugins` on single-site HTTPS.

### 3.2 Authorization model

| Surface | Rule |
|---|---|
| All collection `GET` | Public, **publish-only** |
| `GET /media/{id}` | Requires the manage capability (unlike every other read) |
| `GET /managers` | Requires the manage capability |
| All writes | Manage capability — `manage_astro` (`Schema::CAP_MANAGE`) |
| Publish / schedule / unpublish on `content/*` + `local` | Additionally `publish_posts` |
| Non-publish `status` on `products` / `content/*` | Manage capability; non-managers silently get publish-only |

All permission failures are **401** (`rest_forbidden` via
`rest_authorization_required_code()`) except the publish gate, which is an
explicit **403** (`content_publish_forbidden`).

### 3.3 `GET /auth/me`

**401** for an anonymous request (`get_me_permissions_check` returns `false`).
The zero-filled payload branch inside `me_payload()` is unreachable over the
wire for guests — it only fires for a session whose user record vanished.

```json
{
  "id": 7,
  "email": "owner@example.com",
  "name": "Store Owner",
  "canManage": true,
  "adminUrl": "https://cms.example.com/wp-admin/admin.php?page=astro-cms",
  "capabilities": ["edit_posts", "manage_astro", "publish_posts", "read", "upload_files"],
  "readOnlyCollections": ["statuses"]
}
```

| Field | Type | Notes |
|---|---|---|
| `id` | int | `0` only in the unreachable branch |
| `email` | string | |
| `name` | string | `display_name`, falling back to `user_login` |
| `canManage` | bool | `current_user_can('manage_astro')` |
| `adminUrl` | string | `''` when `canManage` is false; absolute wp-admin URL otherwise |
| `capabilities` | string[] | **Role caps only**, sorted — see §17 D7 |
| `readOnlyCollections` | string[] | `["statuses"]` when products source is `woo`/`both`, else `[]` |

The `/manage` gate uses only `id`/`email`/`name`/`canManage`; the CMS SPA
consumes `capabilities` + `readOnlyCollections` through
`src/auth/permissions.ts`.

### 3.4 Auth routes

| Route | Method | Auth | Body | Response |
|---|---|---|---|---|
| `/auth/nonce` | GET | public | — | `{nonce}` |
| `/auth/register` | POST | public + nonce in body | `{email, password, name?, nonce}` | `me_payload` (200) |
| `/auth/login` | POST | public | `{email, password}` | `me_payload` + optional `wooLoginToken` |
| `/auth/logout` | POST | public | — | `{ok: true}` |
| `/auth/me` | GET | session | — | `me_payload` |

`register` validation: valid email, password ≥ 8 chars, 409 on duplicate.
`login` sets `wooLoginToken` (string, single-use) **only** when
`OPTION_WOO_SSO_ENABLED` is `yes`.

---

## 4. Collection documents

### 4.1 `products`

Routes: `GET /products`, `POST /products`, `GET /products/{id}`,
`PUT /products/{id}`, `DELETE /products/{id}`.

Document — native source (`ProductTransformer`), key order as built:

```json
{
  "id": 1,
  "name": "Aurora 3-Seater Sofa",
  "price": 1299,
  "oldPrice": 1499,
  "description": "<p>A deep, cloud-soft three-seater with a solid oak base.</p>",
  "category": { "id": 1, "name": "Sofas" },
  "brand": { "id": 1, "name": "NV Home" },
  "colors": [ { "id": 1, "name": "Sage", "hexCode": "#9CAF88" } ],
  "width": 210,
  "height": 85,
  "depth": 95,
  "rating": 4.8,
  "isBestseller": true,
  "isFeatured": true,
  "image": "https://cms.example.com/wp-content/uploads/2026/10/aurora-sofa.jpg",
  "images": ["https://cms.example.com/wp-content/uploads/2026/10/aurora-sofa-2.jpg"],
  "imageId": 11,
  "imagesIds": [12, 13],
  "statuses": [ { "id": 1, "name": "In Stock" } ],
  "promotion": {
    "id": 1,
    "title": "Autumn Sale",
    "description": "Up to 25% off selected pieces.",
    "startDate": "2026-10-01",
    "endDate": "2026-11-30",
    "active": true
  },
  "createdAt": "2026-09-10 10:00:00",
  "updatedAt": "2026-10-20 09:30:00",
  "source": "native",
  "status": "publish"
}
```

| Field | Type | Nullability / fallback |
|---|---|---|
| `id` | int | |
| `name` | string | post title |
| `price` | number | `0` when unset (never null) |
| `oldPrice` | number \| null | `null` when the meta is `''` |
| `description` | string | raw post content (HTML) |
| `category` | `{id,name}` \| null | **first** term only |
| `brand` | `{id,name}` \| null | **first** term only |
| `colors` | `{id,name,hexCode}[]` | `hexCode` always present here — `''` when unset |
| `width`/`height`/`depth` | number | `0` when unset |
| `rating` | number \| null | `null` when unset |
| `isBestseller`/`isFeatured` | bool | `false` when unset |
| `image` | string | `''` when no featured image |
| `images` | string[] | empty entries filtered out |
| `imageId` | int \| null | `null` when no featured image |
| `imagesIds` | int[] | invalid/zero IDs filtered |
| `statuses` | `{id,name}[]` | Woo mode: at most one pseudo-doc |
| `promotion` | Promotion \| null | full embedded object, not a ref |
| `createdAt`/`updatedAt` | string | `YYYY-MM-DD HH:MM:SS` |
| `source` | `"native"` \| `"woocommerce"` | |
| `status` | string | WP post status; **absent from the route's JSON schema** (§17 D2) |

Woo source (`WooProductSource`) emits the same key set with these differences:
`status` appears earlier (after `isFeatured`), `source` is `"woocommerce"`,
`imageId` may be `null`, and a variable product **additionally** carries
`variants` — **single-document reads only**, never in listings:

```json
"variants": [
  {
    "id": 101,
    "sku": "AUR-3S-OAK",
    "price": 1299,
    "oldPrice": 1499,
    "stock": 4,
    "attributes": [ { "name": "Size", "value": "3-Seater" } ],
    "image": ""
  }
]
```

`stock` is `int | null` (`null` when Woo returns `''`). `image` is **always a
string** in the PHP output (`''` when absent) — see §17 D3.

**Listing semantics.** `GET /products` merges every active source: each source
paginates independently and the pages are concatenated (native first, then
Woo) with **summed** `totalDocs`. `limit` is applied per source, so a `both`
deployment can return up to `limit × sourceCount` docs on one page.

**Read gating.** `status != publish` requires the manage capability on the
listing; a non-published single doc 404s for an anonymous caller.

**Write body.** Any subset of the document fields, plus optional
`source: "native" | "woocommerce"` to select the backing store in `both` mode.
Relationship fields accept either `{id, name}` or a bare ID. Response is the
full re-read document. `DELETE` → 204, empty body.

### 4.2 `categories`

Routes: `GET /categories`, `POST /categories`, `GET /categories/{id}`,
`PUT /categories/{id}`, `DELETE /categories/{id}`.

```json
{ "id": 1, "name": "Sofas", "image": "https://…/cat-sofas.jpg", "productsCount": 2 }
```

| Field | Type | Notes |
|---|---|---|
| `image` | string | `''` when no term-meta image |
| `productsCount` | int | Native: published `funiq_product` count. Woo: the term's own `count` |

`image` on write accepts an attachment **ID** (numeric); `''`/empty deletes it.

### 4.3 `brands` · `colors` · `statuses`

Routes: standard five-verb CRUD on each.

```json
{ "id": 1, "name": "NV Home" }                       // brand, status
{ "id": 1, "name": "Sage", "hexCode": "#9CAF88" }     // color — hexCode key OMITTED when empty
```

All three are served by `TermTransformer`, which emits `hexCode` **only when
non-empty** (contrast with the product-embedded color, §4.1). Write body:
`{name}` (+ `{hexCode}` for colors).

`statuses` in Woo mode is a read-only pseudo-collection — deterministic IDs so
product relationship payloads stay stable:

| id | name | Woo `stock_status` |
|---|---|---|
| 1 | `In stock` | `instock` |
| 2 | `Out of stock` | `outofstock` |
| 3 | `On backorder` | `onbackorder` |

POST/PUT/DELETE answer 405 `astro_woo_readonly_statuses` in that mode. Note the
capitalisation differs from the native taxonomy fixtures (`In Stock`,
`Made to Order`) — the pseudo-docs are a separate vocabulary.

### 4.4 `promotions`

Routes: standard five-verb CRUD.

```json
{
  "id": 1,
  "title": "Autumn Sale",
  "description": "<p>Up to 25% off selected pieces.</p>",
  "startDate": "2026-10-01",
  "endDate": "2026-11-30",
  "active": true
}
```

`startDate` / `endDate` are the **raw stored strings** — not parsed, not
normalized, `''` when unset. A replacement server must not coerce them to ISO.
`active` is a bool. Write body: `{title, description, startDate, endDate, active}`.

### 4.5 `promocodes`

Routes: standard five-verb CRUD **plus** `GET /promocodes/validate?code=…`
(public).

```json
{
  "id": 1,
  "name": "Summer",
  "title": "Summer 10",
  "code": "SUMMER10",
  "discount": 10,
  "expiresAt": "Jun 30, 2027",
  "isActive": true,
  "logo": ""
}
```

| Field | Type | Notes |
|---|---|---|
| `name` / `title` | string | `''` when unset |
| `code` | string | post title, uppercased by convention only |
| `discount` | int | percent |
| `expiresAt` | string | **Display format `M j, Y`**, `''` when unset — see §17 D6 |
| `isActive` | bool | |
| `logo` | string | media URL, `''` when unset |

Write body accepts `expiresAt` as a **raw date string** (stored verbatim) —
the read format is not round-trippable. `logo` accepts an attachment ID.

`validate` is case-insensitive on `code`, 404 when missing, 422 when inactive
or expired (`strtotime(expiresAt) < time()`).

### 4.6 `media`

| Route | Method | Auth | Body | Response |
|---|---|---|---|---|
| `/media` | POST | manage + session | multipart, field `file`, optional `alt` | `{id, url, path}` |
| `/media/{id}` | GET | manage + session | — | `{id, url, path}` |

```json
{ "id": 11, "url": "https://cms.example.com/wp-content/uploads/2026/10/sofa.jpg", "path": "2026/10/sofa.jpg" }
```

`path` is the uploads-relative path **without** a leading slash. `url` is `''`
if resolution fails. The storefront serves the file through `/media/{path}`
(`mediaUrl()` in `useCollection.ts`, `normalizeMediaUrl()` in the SSR layer).

### 4.7 `managers`

| Route | Method | Auth | Params / body | Response |
|---|---|---|---|---|
| `/managers` | GET | manage | `search` (≥2 chars), `page`, `limit` | envelope of Manager docs |
| `/managers/{id}` | PUT | manage | `{manage: bool}` | Manager doc |

```json
{ "id": 7, "email": "owner@example.com", "name": "Store Owner", "isManager": true, "protected": false, "isSelf": true }
```

Semantics a replacement must keep:

- `search` returns **all matching users**, not only current managers.
- `protected` = super admin (or the `funiq_bridge_managers_protected_user`
  filter) — attempting to change one is 400 `astro_managers_protected`.
- Demoting yourself is 400 `astro_managers_self`.
- Demoting a non-manager is an idempotent no-op, still 200.

---

## 5. Content documents

### 5.1 `content/pages` and `content/posts`

Routes: `GET`, `POST` on the collection; `GET`, `PUT`, `DELETE` on `/{id}`;
plus `GET /{id}/revisions` and `POST /{id}/restore` (`revision` int required).

`ContentTransform::transform()` — order as built:

```json
{
  "id": 42,
  "slug": "shipping",
  "title": "Shipping",
  "excerpt": "Free delivery over $500.",
  "content": "<p>Free delivery over $500.</p>",
  "contentRendered": "<p>Free delivery over $500.</p>",
  "type": "page",
  "featuredImage": null,
  "seoTitle": "Shipping",
  "seoDescription": "Free delivery over $500.",
  "category": null,
  "createdAt": "2026-10-01 09:00:00",
  "updatedAt": "2026-10-02 11:30:00",
  "status": "publish",
  "publishAt": null
}
```

| Field | Type | Rule |
|---|---|---|
| `slug` | string | `post_name` |
| `excerpt` | string | `get_the_excerpt()`, falling back to the first 55 words of stripped content |
| `content` | string | **raw** stored HTML |
| `contentRendered` | string | `the_content` filter output; falls back to `content` when the render is empty. Shortcodes/embeds execute here |
| `type` | `"page"` \| `"post"` | controller-fixed |
| `featuredImage` | string \| null | `large` size URL, `null` when none |
| `seoTitle` | string | Rank Math → Yoast → `post_title`, trimmed; never `''` |
| `seoDescription` | string | Rank Math → Yoast → `excerpt`, trimmed; never `''` |
| `category` | `{id,name}` \| null | **posts only**, first WP category. Always `null` for pages/local |
| `status` | string | `publish` \| `draft` \| `pending` \| `future` |
| `publishAt` | string \| null | `post_date` when `status === "future"`, else `null` |
| `blocks` | PortableTextBlock[] | **key omitted** when empty |
| `submittedBy` | string | **key omitted** when unset |
| `approvedBy` | string | **key omitted** when unset; written as `"<name> @ <ISO8601>"` |

The three trailing keys are additively emitted only when present — a doc that
predates Proposal 107 must serialize **without** them for the goldens to stay
identical.

**Write body.** `{title, content, excerpt, status, publishAt, category, blocks,
seoTitle, seoDescription, submittedBy}`.

- Creates default to `draft` on `content/*` and `local`.
- `publish` / `future` transitions require `publish_posts` (403
  `content_publish_forbidden`); so does unpublishing a live doc.
- `category` accepts `{id}` or a bare ID; posts only.
- `publishAt` sets `post_date` and flips `draft`/`pending` → `future`; an
  explicit publish status wins over the scheduling flip.

### 5.2 `local`

Same document as §5.1 (`type` is always `"page"`, `category` always `null`)
**plus** three trailing keys:

```json
{
  "...": "content document fields",
  "name": "Shipping",
  "description": "<p>Free delivery over $500.</p>",
  "source": "local"
}
```

`name` mirrors `title`, `description` mirrors `content` (raw, not rendered) —
they exist so the forge loader's `extractPayloadRaw()` maps the same envelope
without the CMS forking the wire format. `source` is `"local"`.

Additional route: `POST /local/{id}/preview-token` (manage):

```json
{
  "token": "<opaque>",
  "expiresAt": "2026-11-15T10:45:00+00:00",
  "slug": "shipping",
  "previewUrl": "https://store.example.com/api/preview?token=…&slug=shipping"
}
```

`previewUrl` is `''` when `OPTION_PUBLIC_ORIGIN` is unset — deliberately, so the
UI can say "configure the public origin" instead of opening a 404 on the CMS
host. Token TTL is 900 s. A valid `preview_token` param lets that one post be
listed and read regardless of status.

### 5.3 Revision documents

```json
{ "docs": [ { "id": 91, "date": "2026-10-02 11:30:00", "author": "Store Owner" } ], "totalDocs": 1 }
```

Restore is `POST /{id}/restore` with `{revision: <int>}`; WP semantics mean the
restore itself creates a new revision. Response is the full re-read document.

---

## 6. Globals

All four are singletons — no collection envelope, no `page`/`limit`.

### 6.1 `GET|PUT /globals/banner`

```json
{ "image": "https://…/banner.jpg", "promotion": { …Promotion… } }
```

`image` is `''` when unset; `promotion` is the full embedded object or `null`.
PUT body takes **attachment/post IDs** (`{image: 11, promotion: 1}`), not URLs.

### 6.2 `GET|PUT /globals/carousel`

```json
{ "carousel": [ { "image": "https://…/slide-1.jpg", "promotion": { … } }, { "image": "…", "promotion": null } ] }
```

PUT body: `{carousel: [{image: <int|null>, promotion: <int|null>}]}`.

### 6.3 `GET|PATCH /globals/shop`

```json
{
  "checkoutUrl": "https://shop.example.com/checkout",
  "cartUrl": "https://shop.example.com/cart",
  "returnUrl": "https://store.example.com/checkout/return",
  "currency": "USD",
  "currencySymbol": "$",
  "updatedAt": "2026-11-15T10:30:00+00:00",
  "productsSource": "native",
  "paymentMethods": [ { "id": "stripe", "title": "Credit card" } ],
  "stripePublishableKey": "",
  "guestCheckout": true
}
```

`PATCH` accepts **only** `checkoutUrl`, `cartUrl`, `returnUrl`, `currency`,
`currencySymbol` — any other key is 400 `astro_shop_invalid_field`. URL fields
must be absolute `http(s)` and same-site (site host, configured public origin,
or the `funiq_bridge_checkout_url_allowlist` filter); userinfo and non-80/443
ports are rejected. `currency` is 3 letters, uppercased; `currencySymbol` is
1–8 chars.

`productsSource` ∈ `native` | `woo` | `both`. `paymentMethods`,
`stripePublishableKey`, `guestCheckout` are **read-only server state** — the
storefront may display them, never drive them.

### 6.4 `GET|PATCH /globals/theme`

```json
{
  "policy": "auto",
  "contrast": "auto",
  "defaultTheme": "light",
  "updatedAt": "2026-11-15T10:30:00+00:00",
  "$extensions": {}
}
```

`policy` ∈ `auto` | `light` | `dark` | `brand:<slug>` (slug `^[a-z0-9-]+$`).
`contrast` ∈ `auto` | `standard` | `high`. `defaultTheme` ∈ `light` | `dark`.
`$extensions` is always `{}` in v1 but the key must be present.

A PATCH must trigger the debounced storefront rebuild — the policy feeds SSR
cache keys. A replacement server owes the same invalidation side effect.

---

## 7. Cart and checkout

These five routes proxy the WooCommerce Store API. **They are the only part of
the contract that is not a CRUD mapping over rows** — see §18.

### 7.1 `POST /cart/sync` (public)

Request: header `X-Nvoos-Cart-Session: <[A-Za-z0-9-]{8,64}>`, body:

```json
{ "lines": [ { "productId": 1, "qty": 2, "attributes": [ { "name": "Size", "value": "3-Seater" } ] } ], "coupon": "SUMMER10" }
```

`lines` max 100; `productId` ≥ 1 (int); `qty` 1–99 (int). Response:

```json
{
  "session": "6f1c…",
  "items": [ { "key": "abc123", "productId": 1, "name": "Aurora Sofa", "qty": 2, "price": 1299 } ],
  "totals": { "totalItems": 2, "totalPrice": "2598.00" },
  "notices": []
}
```

400 `astro_cart_native_mode` when `productsSource === native`; 501
`astro_cart_woo_missing` without the Store API.

### 7.2 `GET /checkout` (public, session-scoped)

```json
{
  "session": "6f1c…",
  "orderId": 512,
  "orderStatus": "checkout-draft",
  "items": [ { "key": "abc123", "productId": 1, "name": "Aurora Sofa", "qty": 2, "price": 1299 } ],
  "totals": { "totalItems": 2, "totalPrice": "2598.00", "shippingTotal": "0.00", "taxTotal": "0.00" },
  "billingAddress": { "firstName": "", "lastName": "", "company": "", "address1": "", "address2": "", "city": "", "state": "", "postcode": "", "country": "", "phone": "", "email": "" },
  "shippingAddress": { "…same 11 keys…": "" },
  "paymentMethod": "",
  "shippingRates": [ { "packageId": "0", "name": "Ship", "rates": [ { "rateId": "flat_rate:1", "name": "Flat rate", "description": "", "price": "0.00", "selected": true } ] } ],
  "needsShipping": true,
  "coupons": [ { "code": "SUMMER10", "discount": "259.80" } ],
  "notices": []
}
```

Address objects **always carry all 11 keys**, empty-string filled. `country` is
uppercased. `billingAddress`/`shippingAddress` may be `null`.

### 7.3 `POST /checkout/customer` · `POST /checkout/shipping-rate` · `POST /checkout/coupon`

Each accepts a partial body (`billingAddress`, `shippingAddress`,
`{packageId, rateId}`, `{code}`) and returns the **merged checkout doc** of
§7.2.

### 7.4 `POST /checkout` — place order

```json
{
  "orderId": 512,
  "orderKey": "wc_order_abc123",
  "status": "processing",
  "paymentResult": { "paymentStatus": "success", "redirectUrl": "", "paymentDetails": [] },
  "notices": []
}
```

Request accepts `expected_total`; a mismatch is 409 with the refreshed cart
preserved. Rate-limited per session and per IP. All `/checkout*` routes are
excluded from the public cache headers and sent `private, no-store`.

---

## 8. Storefront MCP surface (`/mcp`)

Not part of the REST contract, but it binds to the same base:
`mcp/tools/cms.ts` reuses `buildCollectionQuery()` / `joinPath()` from
`src/lib/commerce/client.ts`, so every shape above is also the MCP tool
output. Writes forward the manager session cookie verbatim and surface backend
401/404 as typed tool errors. A replacement contract server gets the MCP
surface for free.

---

## 9. Verification harness

| Gate | Location | What it pins |
|---|---|---|
| Transformer goldens | `addons/nvoos-astro-bridge/tests/golden/product-aurora.json`, `woo-variable-product.json` | Product doc shape (volatile fields masked) |
| Golden test | `tests/phpunit/Transformers/TestTransformerGolden.php` | Fails before drift reaches the storefront |
| Fixture sync | `examples/nvoos-astro-velocity/scripts/sync-bridge-fixtures.mjs` | Regenerates `tests/fixtures/products.json` from goldens; CI diffs the result |
| Committed fixtures | `examples/nvoos-astro-velocity/tests/fixtures/*.json` | `products`, `categories`, `brands`, `colors`, `statuses`, `promotions`, `promocodes`, `banner`, `carousel` |
| Backend-independence gate | `tests/unit/contract-grep.test.ts` | Fails the build if `wp-json` / `wp-admin` / a backend origin appears in `src/` |

**What is *not* gated:** there is no runtime schema validation of documents.
`client.ts` validates only the envelope (`docs: z.array(z.unknown())`).
Byte-compatibility is therefore asserted by the goldens/fixtures plus rendered
output — a replacement server can emit a wrong-typed field and nothing will
fail until a page renders badly. **Recommendation:** when the Postgres server
lands, promote the fixtures into a zod schema suite (or a shared JSON Schema)
and run it against both implementations — that converts this document from
prose into an executable gate.

---

## 10. Drift register

Every place the three authorities disagree, with the consequence for a
replacement implementation.

| # | Drift | PHP output | TS type / fixture | Action for the Postgres server |
|---|---|---|---|---|
| **D1** | `Product.source` | Always emitted (`native` \| `woocommerce` \| `local` on `/local`) | Absent from `Product` in both type files; absent from `tests/fixtures/products.json` | Emit it — it is the routing key for `ProductSources::resolve()` on writes, and the forge loader reads it |
| **D2** | `Product.status` | Always emitted | Optional (`status?`) in `Product`; **missing from `ProductsController::get_item_schema()`** | Emit it. The admin SPA's status filter and the draft-404 gate both depend on it |
| **D3** | `ProductVariant.image` | Always a **string** (`''` when absent) | `string \| null` | Emit `''`, never `null` — the fixture golden says `""` |
| **D4** | `hexCode` presence | Product-embedded colors: **key always present**, `''` fallback. `/colors` collection: **key omitted** when empty (`TermTransformer`) | `ColorRef.hexCode?` optional; `Color.hexCode?` optional; fixtures always present | Reproduce both behaviours. Do not unify them without updating the goldens |
| **D5** | Promotion dates | Raw stored strings, `''` when unset | `string` (no format constraint) | Do not normalize to ISO; the storefront parses loosely |
| **D6** | `Promocode.expiresAt` | Read: `M j, Y` display format. Write: raw stored string | `string` | Reproduce the display formatting on read. The field is **not** round-trippable |
| **D7** | `capabilities[]` completeness | `get_role_caps()` — **role grants only**. A user-level `manage_astro` grant (exactly what `ManagersController` writes) is **not** included | `can()` gates on this array | Either reproduce the bug or fix both sides. Today `canManage=true` can coexist with `capabilities` lacking `manage_astro`. No nav entry gates on a capability yet, so it is latent |
| **D8** | Pagination defaults | `/products` → 10; `PostTypeController` → 100; term collections ignore `limit` and echo `limit = N`, `page = 1` | `PAGE_SIZE = 24` on the storefront; SPA sends `limit=20` | Match per-collection defaults exactly; `limit` in the response is the *resolved* value |
| **D9** | Revisions envelope | `{docs,totalDocs}` only — no `limit`/`totalPages`/`page`/`hasNextPage`/`hasPrevPage` | Not typed in either file | Reproduce the reduced shape; a full envelope would break a strict client |
| **D10** | `notices[]` | Raw Woo passthrough (`$added['notices']`, `$applied['notices']`) — element shape undefined | `Array<{type, text}>` in `CheckoutDoc`/`CheckoutResult` | Woo notices are `{code, message, …}`. The TS type is aspirational; nothing normalizes them |
| **D11** | Checkout `totals` | Woo minor-unit **strings** (`"2598.00"`) | `string` — matches | Keep as strings. `CartItem.price` is a **float** (§7.1) — two price encodings in one flow |
| **D12** | Timestamp formats | Docs: `Y-m-d H:i:s` (site-local, no offset). Globals: ISO 8601 `gmdate('c')` | `string` both ways | Two formats, one contract. Do not unify |
| **D13** | Guest `/auth/me` | 401 (`permission_callback` false). The zero-filled payload is unreachable over the wire | `Customer` (non-nullable id/email/name) | Return 401, not `200 {id:0,…}` |
| **D14** | `GET /media/{id}` auth | **Manage capability required** — every other collection read is public | `fetchMedia()` is called only from authenticated CMS code | Keep the gate. This is a deliberate asymmetry, not an oversight |
| **D15** | `statuses` vocabulary | Native taxonomy: tenant-defined (`In Stock`, `Made to Order`). Woo pseudo-docs: fixed (`In stock`, `Out of stock`, `On backorder`) with IDs 1/2/3 | `Status = TermRef` | Pick one vocabulary per mode. The IDs must stay 1/2/3 in Woo mode for product relationship stability |
| **D16** | `local` `name`/`description` | Emitted **in addition to** `title`/`content` — deliberate dual-shape | `ContentDoc` has no `name`/`description`; the forge loader reads them | Emit both sets. `description` mirrors **raw** `content`, not `contentRendered` |
| **D17** | Merged pagination (`both` mode) | Each source paginated independently, docs concatenated, totals summed → one page may exceed `limit` | `limit` treated as authoritative by `PAGE_SIZE` loops | Document the deviation or disable `both` in the replacement |
| **D18** | Sort direction | `SortClause::parse()` sets `direction = ASC` when the value starts with `-`, `DESC` otherwise. So `price` = **high→low** and `-price` = **low→high** — the **inverse** of Payload's `-` convention | `SortValue` type carries no direction documentation; the PLP labels confirm the inverted reading (`-price` = "Price: low to high") | Reproduce the inversion exactly. Implementing the Payload convention silently flips every PLP ordering while still returning 200 |
| **D19** | `where` operator set | Native: `id.equals`, `name.like`, `category/brand/colors/statuses` × `equals`/`in`. Woo: the same minus `statuses`, **plus** `price.gt/gte/lt/lte`. All others are silently ignored (no 400) | `WhereOperator = 'equals' \| 'in' \| 'like'` — the four price comparisons are untyped and unused by the storefront | Accept and apply the price comparisons in Postgres mode; keep the silent-ignore behaviour for unknown operators (a 400 would break the SPA's relationship filters) |

### D-note: key ordering

JSON objects are unordered by spec and both consumers parse to objects, so key
order is not semantically load-bearing. It **is** load-bearing for the
`sync-bridge-fixtures.mjs` → committed-fixture text diff, which only covers
`products`, `categories`, `brands`, `colors`, `statuses`, `promotions`,
`promocodes`, `banner`, `carousel`. Emit in the documented order and the diff
stays empty.

---

## 11. Postgres implementation checklist

Ordered by the dependency edges in the shapes above.

1. **Identity.** `bigint` primary keys exposed as JSON **numbers** — every
   `id` in the contract is a number, and `where[id][equals]` round-trips as one.
2. **Envelope builder.** One function returning the seven keys in §1.1, with
   `limit` = resolved page size and `totalPages` floored at 1. Do not compute
   these at call sites.
3. **Error mapper.** One function producing `{code, message, data:{status}}`.
   Keep the WP code strings — `useCollection.ts` matches
   `rest_cookie_invalid_nonce` verbatim.
4. **Numeric serialization.** Cast `numeric` → `float8` (or parse) so prices
   serialize as JSON numbers, not strings.
5. **Date serialization.** Two formatters: `Y-m-d H:i:s` for documents, ISO
   8601 for globals. Store both as `timestamptz` and format at the edge; do
   **not** let the driver's default `Date` → ISO conversion leak into document
   `createdAt`.
6. **Where → SQL.** A bounded translator for `equals` / `in` / `like` (+ the
   woo-only `gt`/`gte`/`lt`/`lte` on `price`) over an allowlist of
   (field, column) pairs per collection. `like` maps to `ILIKE` with
   `%value%`; unknown operators are **ignored, not rejected** (D19). Never
   interpolate a raw field name.
7. **Status gate.** Publish-only listing by default; `status != publish`
   requires the manager session; non-published single doc → 404 for anonymous.
8. **Media.** Table of `{id, url, path}`; `path` uploads-relative, no leading
   slash. Serve a `/media/*` route (the storefront's proxy expects it) or keep
   `media_origin = cms` and emit absolute URLs.
9. **Globals.** Four singleton rows, not collections. `$extensions: {}` must be
   present on the theme doc.
10. **Cart/checkout.** Do not reimplement until payments are in scope — point
    these five routes at Woo (or a new gateway) and keep §7's shapes. This is
    the only section where the database is not the hard part.

---

## 12. Open questions for the owner

1. Should the replacement **keep** `funiq/v1` as an alias, or is retiring the
   legacy namespace acceptable at the same time? (`Plugin::register_rest_routes`
   registers both from one controller set, so keeping it is nearly free — but
   it is a second public surface.)
2. Should `GET /media/{id}` stay manage-gated, or is a public read acceptable
   now that the CMS is the only caller? (D14)
3. Fix or preserve D7 (`capabilities` excluding user-level grants)? Fixing it
   changes `/auth/me` for every existing manager.
4. Does `productsSource: both` survive the migration (D17), or is the
   replacement single-source?
