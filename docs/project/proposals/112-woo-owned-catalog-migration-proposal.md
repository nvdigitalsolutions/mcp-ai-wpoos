# Proposal 112 — Woo-owned catalog: retire the native product source

**Status:** Owner direction chosen and decisions resolved (2026-11-04) — ready for implementation scheduling.
**Depends on:** Proposal 110 (embedded checkout, shipped), the bridge Settings page (#7433), the Woo product source layer (shipped).
**Goal:** make every purchasable product a WooCommerce product, so the embedded checkout has a single catalog and the native→Woo translation problem disappears entirely.

---

## 1. The gap (why this exists)

Proposal 110 gave the storefront an embedded checkout that runs through the
WooCommerce Store API. The Store API cart can only contain **real WooCommerce
products** — `add-item` validates the product exists and is purchasable; there
is no "custom line item" path. In `both` product mode the storefront catalog
merges native (`funiq_product` CPT) and Woo (`product` CPT) documents, so a
shopper's cart can contain native products. Today that fails: Woo rejects the
line, the sync surfaces a notice, and the cart fails open to the hosted
checkout — not the embedded UX the owner wants.

Two structural facts shape the fix:

- **ID collision.** Both catalogs are WordPress post types, so native product
  id `5` and Woo product id `5` are different products. The app's cart line
  carries only `productId` — no `source` discriminator — so the bridge cannot
  tell the sources apart in `both` mode.
- **No stable business key.** Native products have no SKU (meta map:
  `_funiq_price`, `_funiq_old_price`, dimensions, rating, flags, gallery).

**Owner direction (2026-11-04):** do not patch this with a sync layer — make
Woo the single catalog (Option B below). The CMS remains the storefront's
editor; Woo becomes the store of record for every product.

## 2. Research — industry standards

1. **The commerce engine owns the purchasable catalog.** Headless
   architecture guidance is uniform: a single source of truth for product
   data, with the commerce engine as the transactional store and the
   CMS/PIM as its editor ([Cmarix — PIM in headless
   e-commerce](https://www.cmarix.com/blog/pim-headless-ecommerce-product-data-chaos/),
   [Digital Natives — PIM as the
   brain](https://digitalnatives.cc/beyond-the-storefront-why-pim-is-the-brain-behind-headless-e-commerce/)).
   A CMS-editor-over-Woo (no separate catalog, no sync) is the standard
   "headless WooCommerce" shape.
2. **Use WooCommerce CRUD objects, not raw post/meta writes.** "Whenever
   possible these objects should be used in your code instead of directly
   updating metadata or using WordPress post objects" ([WooCommerce CRUD
   objects](https://developer.woocommerce.com/docs/best-practices/data-management/crud-objects/)).
   Applied here: the migration and the write path both go through
   `WC_Product_*` setters + `save()`.
3. **Idempotent, keyed migration.** Feed-migration practice is create-or-
   update keyed by a stable business key with `wc_get_product_id_by_sku()`
   ([Stack Overflow — bulk product sync
   pattern](https://stackoverflow.com/questions/42064673/bulk-create-woocommerce-products-programmatically)).
   Applied here: the one-time migration keys on a generated SKU namespace
   `NV-<native_id>` and is safe to re-run.
4. **The Store API is the recommended headless surface and requires real
   products** ([WooCommerce Store API
   docs](https://developer.woocommerce.com/docs/apis/store-api/),
   [Store API stable
   announcement](https://developer.woocommerce.com/2022/03/25/store-api-is-now-considered-stable/)).
   A single Woo catalog makes every cart line a legal Store API citizen
   with zero translation.
5. **Orders must reference real products** — ERP/OMS/fulfillment flows back
   to the commerce engine by product reference ([Virtocommerce — PIM/eCommerce
   B2B architecture](https://virtocommerce.com/blog/pim-ecommerce-integration)).
   Woo-owned products give order lines, stock, and reporting correct
   semantics from day one.
6. **Batch work belongs on Action Scheduler** (WooCommerce-bundled; repo
   skill `wp-action-scheduler`): chunked per-entity jobs for the migration
   sweep, idempotent callbacks that re-read durable state, namespaced
   hooks/group, args small (ids only).

## 3. Options considered

| Option | What it is | Verdict |
|---|---|---|
| **B. Woo-owned catalog** | Migrate native products into Woo; the CMS edits Woo products through the existing `WooProductSource` write path; retire the native source. | **Chosen (owner, 2026-11-04).** One catalog — the whole translation problem class disappears permanently. |
| A. Shadow Woo mirror | Hidden Woo twins + mapping table + reconciliation; cart sync translates native ids. | Rejected. ~100% throwaway machinery the moment B happens: mapping table, shadow flags, reconciliation engine, cart-line translation all get deleted by the migration. |
| C. Gate native out of `both` | Native products stay unpurchasable. | Zero work but denies the core requirement. |
| D. Non-product cart lines | Fee/custom lines in the Store API cart. | Not supported by the Store API; breaks order lines and reporting. |

## 4. Recommended design (Option B)

### 4.1 What already exists (verified in code)

- `WooProductSource` implements the **full CRUD contract** —
  `create_product`, `update_product`, `delete_product` — alongside its
  listings and the `transform()` envelope mapper.
- The woo-mode transformer already maps the envelope's marketing fields to
  Woo-native semantics: `rating` → `get_average_rating()`,
  `isBestseller` → total-sales threshold (`funiq_bridge_woo_bestseller_threshold`),
  `isFeatured` → Woo's featured flag. The storefront contract survives
  almost unchanged.
- The Astro CMS admin SPA is a generic envelope client — it edits products
  through the same REST collection regardless of backing source.

So Option B is **not an editor rewrite**: it is a migration, a field-parity
pass, and a retirement sequence.

### 4.2 One-time migration — `includes/Woo/NativeCatalogMigrator.php`

- Action Scheduler sweep (`nvoos_bridge/migrate_native_product`, group
  `nvoos-bridge-migrate`, arg: `native_id` only — idempotent, re-runnable),
  kicked off from a Settings-page button and a WP-CLI command
  (`wp nvoos-astro-bridge migrate-native --dry-run|--run`).
- **Dry-run mode is mandatory first**: a report (counts, per-field mapping,
  unmapped taxonomies, drafts) rendered on the Settings page before any
  Woo write.
- Per product (WC CRUD): `set_name/set_description`, `set_status`
  (publish/draft mirror), `set_regular_price(price)` +
  `set_sale_price(oldPrice)` when present, `set_width/height/length`,
  `set_image_id` + `set_gallery_image_ids` (attachment ids are shared with
  the native catalog — zero re-upload), `set_sku("NV-<native_id>")`
  (generated business key; idempotency anchor for re-runs), and
  `update_meta_data('_nvoos_bridge_migrated_from', native_id)` for
  traceability.
- **Taxonomy mapping:** native Category → `product_cat`, Brand →
  `pa_brand`, Color → `pa_color` (the `WooTaxonomy` mapping already used by
  the woo source); unmapped terms are reported, not invented.
- **The one true field gap:** `promotionId` (native `_funiq_promotion_id`)
  has no Woo equivalent. Carry it as Woo product meta
  (`_nvoos_bridge_promotion_id`) and read it back in `transform()` so the
  storefront banner logic keeps working. The promotions collection itself
  is unchanged.
- **Draft fidelity:** native drafts migrate as Woo drafts; force-deleted
  native products have no shadow (nothing is created outside the migration).

### 4.3 Write-path parity pass

Close any field gaps between the SPA editor payload and
`WooProductSource::create/update_product` (fields verified during
implementation; expected gaps: sale-price clearing, promotion meta
write-through, gallery replacement semantics). No contract changes — the
envelope stays the same for the app.

### 4.4 Source-mode retirement sequence

1. Migration dry-run → staging run → prod run (backup first; storefront
   stays on `both` during the window — native reads keep working).
2. Flip the default of `funiq_bridge_products_source` to `woo`; keep `both`
   and `native` accepted for one release cycle (transition window).
3. Verify parity (listing counts, PDP docs, cart sync, checkout e2e against
   the migrated catalog).
4. Retire: stop registering the `funiq_product` CPT + native taxonomies,
   remove `NativeProductSource` from the registry; legacy `funiq/v1` routes
   keep serving — now backed by the Woo source — so old clients don't
   break. Data-layer identifiers (`funiq_product` historical rows, `_funiq_*`
   meta on migrated posts, `funiq/v1` alias) stay until the global
   data-migration project per the 2026-10-29/30 rebrand decision.

### 4.5 Cart & checkout simplification

With one catalog, `CartController::sync_cart` and the checkout routes need
**zero translation** — every `productId` is already a Woo id. The optional
`source` field on cart lines is *not needed* and is not added; the
ID-collision problem disappears because there is one ID space. (Backward
compat: lines without `source` already work today in `woo` mode.)

### 4.6 Naming — branding vs data layer

The 2026-10-29/30 Astro CMS rebrand removed all user-facing funiq branding
and recorded that **data-layer identifiers stay until a separate migration
project**. This proposal must read those identifiers (`funiq_product`,
`_funiq_*` meta) — that is interop, not branding.

- **Operator-facing surface** (WP-CLI commands, Settings-page labels,
  notices): NV oOS / Astro language — `wp nvoos-astro-bridge …`, never the
  legacy prefix.
- **Internal identifiers — DECIDED (2026-11-04, owner):** identifiers
  minted from this feature forward use the **`nvoos_bridge_` prefix** — the
  migration hooks `nvoos_bridge/migrate_native_product` (group
  `nvoos-bridge-migrate`), the Woo meta flags `_nvoos_bridge_migrated_from`
  and `_nvoos_bridge_promotion_id`. Legacy `funiq_bridge_*` keys stay until
  the global migration; accepted trade-off: temporarily two internal
  prefixes (grep both).

## 5. Phases

- **Phase 1 — migration engine:** `NativeCatalogMigrator` + dry-run report +
  Settings-page controls + wp-cli + PHPUnit (CRUD seam, idempotent re-run,
  taxonomy mapping, draft fidelity, meta carry-over).
- **Phase 2 — parity + write path:** SPA field-parity pass over
  `WooProductSource` CRUD; `promotion` meta write-through + `transform()`
  read-back; contract-drift tests.
- **Phase 3 — flip:** staging migration → prod migration (backup,
  storefront on `both`) → default source `woo` → e2e verification (listing
  counts, PDP, cart sync, embedded checkout end-to-end).
- **Phase 4 — retire:** unregister native CPT/taxonomies, remove
  `NativeProductSource`, legacy-namespace compatibility check, skill/doc
  updates.

## 6. Test plan (highlights)

- Migrator: idempotency (second run performs zero `save()` — counting
  seam), draft fidelity, SKU anchor, taxonomy mapping incl. unmapped-term
  report, promotion meta round-trip through `transform()`.
- Parity: the SPA product payload applied through
  `WooProductSource::create/update_product` yields the same envelope doc
  the native path produced (golden-doc comparison — the repo's
  `TestTransformerGolden` pattern).
- Retirement: with native source removed, listings/cart/checkout tests run
  woo-only with zero skips; legacy `funiq/v1` route smoke tests still pass.
- Woo-present/absent matrices per the repo convention (skip gates on the
  WP 7.1 env without Woo).

## 7. Risks & mitigations

- **Migration fidelity** (fields, taxonomies, drafts) → mandatory dry-run
  report; staging first; backup before prod; idempotent re-run keyed by SKU.
- **Migration window regressions** → storefront stays on `both` (native
  reads keep working) until the flip is verified; the flip is a settings
  change, reversible in seconds.
- **Woo-admin exposure** → products now appear in Woo's admin list. That is
  intentional (stock/tax/shipping become Woo-native); admins get the
  standard Woo surface instead of a hidden mirror.
- **Retirement breaks unknown native consumers** → one-release transition
  window with `both`/`native` still accepted + legacy `funiq/v1` routes
  kept alive.
- **Rollback** → before retirement, reverting is re-setting the source
  option; after retirement, the migrated Woo products ARE the catalog —
  rollback is restoring the backup, which is why the prod run is gated on
  the staging verification.

## 8. Owner decisions (resolved 2026-11-04)

All recommendations accepted:

1. **Migration trigger — DECIDED:** Settings-page button + wp-cli
   (`wp nvoos-astro-bridge migrate-native --dry-run|--run`) with a
   mandatory dry-run report before any Woo write.
2. **Taxonomy mapping — DECIDED:** native Category/Brand/Color →
   `product_cat` / `pa_brand` / `pa_color` (the `WooTaxonomy` mapping);
   unmapped terms are reported, never invented.
3. **Marketing fields — DECIDED:** carry `promotionId` as Woo meta
   (`_nvoos_bridge_promotion_id`); `rating`/`isBestseller`/`isFeatured`
   keep their Woo-native semantics in the transformer.
4. **Retirement timing — DECIDED:** one release cycle of `both`/`native`
   acceptance after the default flips to `woo`, then unregister the native
   CPT/taxonomies.
5. **Legacy `funiq/v1` namespace — DECIDED:** keep serving indefinitely,
   backed by the Woo source; no deprecation timeline (a future global
   data-migration project may revisit).

## 9. References

- `docs/project/proposals/110-embedded-checkout-store-api-proposal.md`
- Bridge sources: `includes/Sources/NativeProductSource.php`,
  `includes/Sources/WooProductSource.php`, `includes/Sources/WooTaxonomy.php`,
  `includes/Transformers/ProductTransformer.php`,
  `includes/REST/ProductsController.php`, `includes/REST/CartController.php`,
  `includes/Schema.php`
- App: `src/lib/commerce/cart-api.ts`, `src/islands/CartView.tsx`
- Skills: `wp-action-scheduler`, `mcp-ai-wpoos-astro-storefront`
- External citations inline in §2.
