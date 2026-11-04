# Proposal 112 — Native→WooCommerce product sync (shadow mirror)

**Status:** Research + proposal — awaiting owner decisions (§8) before implementation.
**Depends on:** Proposal 110 (embedded checkout, shipped), the bridge Settings page (#7433).
**Goal:** make native-catalog products purchasable through the embedded Woo checkout in `both` mode without changing the storefront's content model.

---

## 1. The gap (why this exists)

Proposal 110 gave the storefront an embedded checkout that runs through the
WooCommerce Store API. The Store API cart can only contain **real WooCommerce
products** — `add-item` validates the product exists and is purchasable; there
is no "custom line item" path (WooCommerce's Store API docs, and the 110
implementation, both confirm this). In `both` product mode the storefront
catalog merges native (`funiq_product` CPT) and Woo (`product` CPT) documents,
so a shopper's cart can contain native products. Today that breaks exactly as
the owner observed: Woo rejects the line, the sync surfaces a notice, and the
cart fails open to the hosted-checkout handoff — which is *not* the embedded
UX the owner wants.

Two structural facts make the fix non-trivial:

- **ID collision.** Both catalogs are WordPress post types, so native product
  id `5` and Woo product id `5` are different products. The app's cart line
  (`src/lib/commerce/cart-api.ts`) carries only `productId` — no `source`
  discriminator — so the bridge cannot even tell the sources apart today.
- **No stable business key.** Native products have no SKU (Schema meta map:
  `_funiq_price`, `_funiq_old_price`, dimensions, rating, flags, gallery).
  The industry-standard feed-sync key (`wc_get_product_id_by_sku`) has
  nothing to key on, so the mapping must be an explicit bridge-owned table.

## 2. Research — industry standards

1. **Single source of truth + one-way push (PIM → commerce).** Headless
   architecture guidance is uniform: the PIM/CMS owns catalog content and
   pushes it to the commerce engine over APIs; consumers (storefront, search)
   read but never mutate product data
   ([Cmarix — PIM in headless e-commerce](https://www.cmarix.com/blog/pim-headless-ecommerce-product-data-chaos/),
   [Digital Natives — PIM as the brain](https://digitalnatives.cc/beyond-the-storefront-why-pim-is-the-brain-behind-headless-e-commerce/),
   [Plytix — headless commerce architecture](https://www.plytix.com/blog/headless-commerce-architecture/)).
   Applied here: the Astro CMS (native catalog) is the **content source of
   truth**; Woo owns **transactional** fields (stock, tax class, shipping
   class) and order history. Sync is one-way: native → Woo.
2. **A scheduled reconciliation job is standard**, not just event hooks.
   "Every production headless stack needs a scheduled reconciliation job
   … comparing the source state against the live state of the commerce
   backend" ([FeedOn — headless product feed sync guide](https://feedon.ai/blog/headless-commerce-product-feed-sync-the-engineering-guide-nobody-else-has-written)).
   Applied here: lifecycle hooks for immediacy + an Action Scheduler
   reconciliation sweep for drift (missed hooks, failed writes, manual
   DB edits).
3. **Use WooCommerce CRUD objects, not raw post/meta writes.** Woo's own
   developer docs: "Whenever possible these objects should be used in your
   code instead of directly updating metadata or using WordPress post
   objects" ([WooCommerce CRUD objects](https://developer.woocommerce.com/docs/best-practices/data-management/crud-objects/)).
   Applied here: `WC_Product_Simple` + setters + `save()` for the shadow
   products (price, sale price, image/gallery ids, dimensions, status,
   catalog visibility).
4. **Idempotent upserts keyed by a stable business key.** Feed-sync practice
   is key-by-SKU with `wc_get_product_id_by_sku()` and create-or-update
   semantics ([Stack Overflow — bulk product sync pattern](https://stackoverflow.com/questions/42064673/bulk-create-woocommerce-products-programmatically)).
   Applied here: generated SKU namespace `NV-<native_id>` + a mapping table
   `native_id ↔ woo_id` (the SKU is a secondary lookup; the map is primary).
5. **"Hidden" catalog visibility is the standard shadow-product mechanism.**
   A product with `catalog_visibility = hidden` is fully live and purchasable
   via direct URL but excluded from shop/search listings
   ([AdTribes — product visibility](https://adtribes.io/woocommerce-product-visibility/),
   [WebAppick — visibility guide](https://webappick.com/woocommerce-product-visibility/)).
   Applied here: shadow products are `publish` + hidden, plus a bridge meta
   flag (`_nvoos_bridge_shadow_of`) so the bridge's own `WooProductSource` listings
   deterministically exclude them (the app never renders a Woo duplicate of
   a native product).
6. **The Store API is the recommended headless surface and requires real
   products.** ([WooCommerce Store API docs](https://developer.woocommerce.com/docs/apis/store-api/),
   [Store API stable announcement](https://developer.woocommerce.com/2022/03/25/store-api-is-now-considered-stable/)).
   Applied here: the embedded checkout keeps consuming `/wc/store/v1/*`;
   the shadow mirror is what makes native products legal Store API citizens.
7. **Orders must reference real products** — ERP/OMS/fulfillment data flows
   back to the commerce engine by product reference
   ([Virtocommerce — PIM/eCommerce B2B architecture](https://virtocommerce.com/blog/pim-ecommerce-integration)).
   Anti-patterns rejected: fee-style pseudo-lines, per-order ad-hoc product
   creation at checkout time (unbounded catalog growth, reporting noise).
8. **Batch work belongs on Action Scheduler** (WooCommerce-bundled; the repo
   skill `wp-action-scheduler`): chunked per-entity jobs, idempotent
   callbacks that re-read durable state, namespaced hooks/group, args small
   (ids only), throw on real failures.

## 3. Options considered

| Option | What it is | Verdict |
|---|---|---|
| **A. Shadow Woo mirror** | Every native product gets a hidden Woo twin; mapping table; cart sync translates native ids → Woo ids; app cart lines gain `source`. | **Recommended.** Smallest blast radius, keeps the CMS UX, checkout and reporting stay standard Woo. |
| B. Migrate to Woo-only | Deprecate `funiq_product`; the Astro CMS edits Woo products; one catalog. | Architecturally cleanest end-state, but it rewrites the CMS product editor, migrates data, and changes every contract consumer — a separate proposal with its own risk budget. Not a fix for the live gap. |
| C. Gate native out of `both` | Native products stay unpurchasable in `both` mode (typed error + handoff). | Zero work but denies the owner's core requirement. Rejected. |
| D. Non-product cart lines | Inject fee/custom lines into the Store API cart. | Not supported by the Store API; breaks order line references and reporting. Rejected as anti-pattern (§2.7). |

## 4. Recommended design (Option A)

### 4.1 Mapping table

New table `nvoos_bridge_native_woo_map` (custom table, `dbDelta` on
activation like the bridge's other tables; indexed lookups, no autoload):

```
native_id  BIGINT PK   — the funiq_product post id
woo_id     BIGINT      — the shadow product id
sku        VARCHAR(64) — "NV-<native_id>" (namespaced business key)
status     VARCHAR(16) — synced | stale | error
hash       VARCHAR(32) — content hash of the last mirrored field set
error      TEXT        — last failure message (empty when synced)
synced_at  DATETIME
```

- `SKU` is generated because native has none; the `NV-` namespace cannot
  collide with real Woo SKUs and is the fallback lookup when the map row is
  missing but the shadow product exists.
- Resolution is **exact** because the app sends `source` per cart line
  (§4.4) — the ID-collision problem disappears at the contract level.

### 4.2 Sync engine — `includes/Woo/NativeWooSync.php`

- **Immediate hooks** (single-product, in-request): `NativeProductSource::
  create_product/update_product/delete_product` call the syncer after their
  own save succeeds (these are the only writers — REST routes; no
  `save_post` guessing). Trash/untrash of native posts also triggers
  (status → draft/restore). Single-product upsert via WC CRUD is millisecond
  scale — acceptable in-request for the CMS editor path.
- **Reconciliation**: an Action Scheduler recurring action
  (`nvoos_bridge/sync_reconcile`, group `nvoos-bridge-sync`, hourly) walks
  native products in chunks, enqueues per-product `nvoos_bridge/sync_product`
  jobs (arg: `native_id` only), and each callback re-reads the native post,
  compares the content hash, and upserts only on drift. Idempotent; throws
  on real failures so AS retries and the admin UI shows them.
- **Upsert (WC CRUD)**: `WC_Product_Simple`; `set_name`, `set_description`,
  `set_status` (mirror publish/draft), `set_regular_price(price)`,
  `set_sale_price(oldPrice)` when present, `set_width/height/length`,
  `set_image_id` (featured — native uses the same media library attachment
  id, zero re-upload), `set_gallery_image_ids` (same), `set_manage_stock(false)`
  (native has no stock model — §4.5), `set_catalog_visibility('hidden')`,
  `set_sku("NV-<id>")`, `update_meta_data('_nvoos_bridge_shadow_of', native_id)`,
  `save()`.
- **Loop guard**: the bridge never syncs woo→native, but a `nvoos_bridge_syncing`
  static flag is set around every sync write so any future `save_post_product`
  observer in the bridge (and third-party hook storms) cannot re-enter.
- **Not mirrored** (decision D6): `isBestseller`, `isFeatured`,
  `promotionId`, `rating` — storefront-marketing attributes with no Woo
  equivalent; fabricating Woo review aggregates is a known anti-pattern.
  Categories/brands/colors stay out of v1 (no Woo category assignment; the
  `_nvoos_bridge_shadow_of` flag identifies the product in wp-admin).
- **Deletes**: native force-delete → **trash** the shadow, never force-delete
  (Woo practice: products with order history must remain restorable; the map
  row is deleted and the shadow's SKU keeps the trace).
- **Backfill**: one-time Action Scheduler sweep on first enable (opt-in
  button on the Settings page) for catalogs that already contain native
  products when the feature ships.

### 4.3 Listing exclusion

`WooProductSource::list_products()` gains a `meta_query` exclusion for
`_nvoos_bridge_shadow_of` so shadow products never appear in the bridge's woo
listings (no PDP duplicates, no merged-pagination drift). The wp-admin
WooCommerce product list shows them to admins (normal Woo behavior — and
useful for traceability); `catalog_visibility=hidden` covers Woo's own
front-end surfaces.

### 4.4 Cart contract — `source` per line

- App: `CartLine` (cart-api.ts zod) gains optional `source: 'native' |
  'woocommerce'` (default `'woocommerce'` for backward compatibility).
  `CartView` already holds the product doc at add-to-cart time — it sets
  `source` from the doc's `source` field. Same for the checkout page's
  re-sync payload.
- Bridge: `CartController::validate_lines` accepts the optional `source`
  enum; `sync_cart` translates `native` ids through the map
  (`NativeWooSync::woo_id_for( native_id )` — inline upsert when the map row
  is missing and the native product is publish; typed 422
  `astro_cart_native_unavailable` for drafts). `woocommerce` ids pass
  through untouched. This removes every ambiguity from the shared-ID space.

### 4.5 Out of scope (explicit)

- **Stock/inventory** on native products — the native schema has no stock
  model. Shadow products are unmanaged-stock in v1; adding stock to the
  native schema (or "Woo wins stock") is a follow-up decision.
- Variations — native has none; Woo variations remain woo-only.
- Woo → native feedback (reviews, sales stats) — the storefront can read
  Woo aggregates later via the bridge; no sync in that direction.
- Express checkout (Apple/Google Pay) — already deferred in 110.

### 4.6 Naming — branding vs data layer

The 2026-10-29/30 Astro CMS rebrand removed all user-facing funiq branding
(folder `nvoos-astro-bridge`, Astro CMS admin, `manage_astro` cap) and
recorded that **data-layer identifiers stay until a separate
migration project**: the `funiq_product` CPT, `_funiq_*` meta, the legacy
`funiq/v1` REST alias, and the ~20 `funiq_bridge_*` options/filters/
transients. This proposal must READ those identifiers (they are the schema
being synced), so it references them — that is interop, not branding.

For **new** identifiers this feature mints, the split is:

- **Operator-facing surface** (WP-CLI command names, Settings-page labels,
  notices): NV oOS / Astro language — `wp nvoos-astro-bridge sync …`,
  never the legacy prefix.
- **Internal identifiers — DECIDED (2026-11-04, owner):** identifiers
  minted from this feature forward use the **`nvoos_bridge_` prefix** — the
  mapping table `nvoos_bridge_native_woo_map`, Action Scheduler hooks
  `nvoos_bridge/sync_*` (group `nvoos-bridge-sync`), the loop-guard flag
  `nvoos_bridge_syncing`, and the Woo shadow marker
  `_nvoos_bridge_shadow_of`. The legacy `funiq_bridge_*` options/filters/
  transients stay until the global data-migration project renames them;
  interop code reading those keys is unaffected. Accepted trade-off: the
  plugin temporarily carries two internal prefixes (grep both
  `funiq_bridge_*` and `nvoos_bridge_*`); the eventual migration collapses
  them.

## 5. Phases

- **Phase 1 — sync engine:** table + `NativeWooSync` + source-layer hooks +
  loop guard + listing exclusion + wp-cli (`wp nvoos-astro-bridge sync
  <all|id>` — the operator-facing command uses the plugin's NV oOS
  identity, not the legacy data-layer prefix; see §4.6) + PHPUnit
  (CRUD-object seam, idempotency, draft, trash, force-delete, hook-storm
  test).
- **Phase 2 — cart contract:** app `source` per line (zod + CartView +
  checkout re-sync) + bridge translation in `sync_cart` + collision
  regression tests (native id 5 vs woo id 5 in one cart) + typed 422s.
- **Phase 3 — operations:** reconciliation recurring action + chunked jobs +
  backfill toggle + Settings-page sync status (map size, last sweep, error
  list) + e2e (fixture woo pair: native line → embedded checkout → order).
- **Phase 4 — rollout:** bridge zip deploy **together with** the app mirror
  build (the merge-deploys-app-not-bridge rule from the astro skill), live
  verification, skill/doc updates.

## 6. Test plan (highlights)

- Zero-HTTP seams for Store API stay unchanged; the sync engine is tested
  against WC CRUD directly (Woo present in the local matrix) and skipped
  with the availability seam elsewhere.
- Idempotency: reconcile twice → second pass performs zero `save()` calls
  (hash compare), asserted via a counting seam.
- Loop guard: a `save_post_product` observer asserting the guard flag.
- Collision: one native id 5 + one woo id 5 in one sync body with `source`
  on both lines → correct Woo ids in both `add-item` calls.
- Status matrix: publish/draft/trash/force-delete × shadow state.
- Listing exclusion: woo listing with a shadow product returns only
  non-shadow docs.

## 7. Risks & mitigations

- **Catalog divergence** (native edited outside the REST routes) → the
  reconciliation sweep is the safety net; hourly is the starting cadence.
- **Woo hook storms on every sync save** → hash-compare skip + the
  `nvoos_bridge_syncing` flag; per-product jobs are chunked.
- **Woo-side edits to shadow products being overwritten** → content fields
  are native-owned by design (one-way sync); Woo-owned fields (stock, tax
  class, shipping class) are **never written** by the syncer.
- **Performance on large catalogs** → chunked AS jobs, args carry ids only,
  per-product writes are WC CRUD (no bulk SQL), backfill is opt-in.
- **Rollback** → the feature is a settings-gated toggle; disabling it stops
  syncs and the map/table are additive (shadow products become inert
  publish+hidden products; force-delete map rows via wp-cli if desired).

## 8. Owner decisions requested

1. **Confirm Option A (shadow mirror)** over Option B (eventual Woo-only
   migration) as the near-term direction.
2. **Shadow product visibility to admins:** publish+hidden (recommended —
   restorable, traceable, purchasable) vs `private` (invisible in wp-admin
   store lists, still purchasable via direct link).
3. **Draft handling:** native draft → shadow draft (recommended) vs shadow
   publish+hidden with purchasability gate at sync time.
4. **Stock:** v1 unmanaged (recommended) vs add a native `stock` field in
   the same phase.
5. **Backfill on enable:** auto-sweep opt-in button (recommended) vs
   silent auto-backfill on first activation.
6. **Reconciliation cadence:** hourly (recommended) vs configurable
   interval on the Settings page.
7. **Naming convention for new internal identifiers** (§4.6) — DECIDED
   (2026-11-04): **`nvoos_bridge_`** prefix for identifiers minted from
   this feature forward; legacy `funiq_bridge_*` keys stay until the
   global data-migration project.

## 9. References

- `docs/project/proposals/110-embedded-checkout-store-api-proposal.md`
- Bridge sources: `includes/Sources/NativeProductSource.php`,
  `includes/Sources/WooProductSource.php`, `includes/REST/CartController.php`,
  `includes/Woo/StoreApiClient.php`, `includes/Schema.php`
- App: `src/lib/commerce/cart-api.ts`, `src/islands/CartView.tsx`,
  `src/islands/CheckoutView.tsx`
- Skills: `wp-action-scheduler`, `mcp-ai-wpoos-astro-storefront`
- External citations inline in §2.
