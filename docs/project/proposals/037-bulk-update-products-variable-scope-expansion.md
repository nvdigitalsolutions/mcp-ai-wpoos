# Proposal 037 — Bulk Update Products: Variable-Scope Expansion

**Status:** ✅ Implemented & validated (2026-09-08)
**Date:** 2026-09-08
**Scope:** E-commerce Pro Toolkit (`addons/pro/includes/tools/ecommerce/`)
**Related:** `036-woo-product-price-qty-update-tools.md`, issue #6389 (item 1)

## Summary

Add a `scope` argument to `bulk_update_products` so that price/stock updates
selected by ID or filter behave correctly for every product type:

- `all` (default): a variable parent expands to its variations and a grouped
  parent to its child products via the shared
  `WP_MCP_AI_Woo_Price_Qty_Updater::resolve_update_targets()` logic; variable
  parents are re-synced with `WC_Product_Variable::sync()` afterwards.
- `product`: exact-ID semantics preserved (legacy escape hatch).

Non-price/stock fields (status, featured, categories, tags) always apply to the
selected product itself, so `scope=all` is safe for operators who mix field
groups.

## Motivation / Gap Analysis

`bulk_update_products` applies price/stock updates to the exact product IDs
selected. WooCommerce stores price and stock on the **variations** of a variable
product and on the **children** of a grouped product — the parents carry no own
price/stock. Today:

| Selected ID | `regular_price` / `stock_quantity` outcome |
| --- | --- |
| simple / external / variation | Works (applied directly). |
| variable parent | Price silently ignored; stock update no-ops (parent never manages stock) — yet the response reports success. |
| grouped parent | Price/stock silently ignored — yet the response reports success. |

The trap is a false-success: the AI (or operator) believes a catalog-wide price
or restock pass succeeded when selected variable/grouped products were left
untouched. The single-product tools (`update_woo_product_price`,
`update_woo_product_qty`) solved this with an explicit `scope` argument; the
bulk tool was deliberately left behind because expansion changes the
per-input-ID response shape (`updated_products[]` would report multiple
targets per input ID). This proposal resolves that deferred decision.

## Decision: `scope` semantics for bulk

The deferred decision from #6388 — "a decision on `scope`-style arguments for
bulk semantics" — is resolved as follows:

1. **`scope` is a top-level argument**, enum `all|product`, **default `all`**.
   - `all` = expansion on (the issue's "Wanted" behavior by default).
   - `product` = exact-ID, legacy semantics (opt-out for callers that depend on
     the old behavior).
2. **`variations` is intentionally not offered.** In a bulk list of mixed
   types, "only variations" is ambiguous (what happens to simple IDs?). `all`
   already means "children for variable/grouped, self otherwise" — the useful
   bulk semantics.
3. **Expansion only happens when the update carries price/stock fields**
   (`regular_price`, `sale_price`, `price_adjustment`, `stock_quantity`,
   `stock_status`, `manage_stock`). A status/featured/taxonomy-only update of a
   variable parent keeps touching just the parent (matching today).
4. **Non-price/stock fields always target the selected product itself**, even
   under `scope=all` — status, featured, categories, and tags on a variable
   parent stay on the parent. This mirrors WooCommerce bulk-edit behavior and
   keeps `scope=all` safe for mixed-field updates.
5. **`scope=product` keeps legacy semantics including the silent no-op on
   variable/grouped parents.** It is the documented escape hatch; the default
   path is the corrected one. Rationale: defaulting to `product` would preserve
   the exact false-success trap this issue exists to fix, while defaulting to
   `all` matches operator intent (an ID list containing a variable parent and a
   price update means "update this product's price", which in WooCommerce
   semantics means its variations). The change is called out in the tool
   description and this proposal as a behavior change.

## Design

### Tool contract (`bulk_update_products`)

Schema addition:

```
scope: enum all|product, default all
```

- Sanitized at entry via `sanitize_key()` + whitelist (two-gate rule, gate one);
  unknown values fall back to `all`.
- Canonical envelope unchanged: success array or `WP_Error` — never
  `array( 'success' => false, ... )`. No change to error codes.

### Response shape (acknowledged change)

`updated_products[]` stays one entry **per input ID** (`product_id`, `name`,
`type`, `changes`, `targets[]`):

```php
array(
    'product_id' => 456,        // input ID (variable parent)
    'name'       => 'T-Shirt',
    'type'       => 'variable',
    'changes'    => array(),    // parent-level fields (status/featured/…)
    'targets'    => array(      // objects price/stock fields were applied to
        array( 'id' => 789, 'sku' => 'TS-S', 'type' => 'variation', 'changes' => array( 'regular_price' => '30.00' ) ),
        array( 'id' => 790, 'sku' => 'TS-L', 'type' => 'variation', 'changes' => array( 'regular_price' => '30.00' ) ),
    ),
)
```

- `total_found` counts input IDs; `updated` counts successful input IDs;
  new `updated_targets` counts objects actually written; `scope` echoes the
  applied scope. Existing message format is unchanged.
- Per-target failure: an invalid sale price on one variation fails that input
  ID with the `WP_Error` message in `errors[]`; already-written targets of
  earlier inputs stay written (bulk best-effort, same as today).

### Resolution & write path

`update_single_product( $product_id, $updates, $dry_run, $scope )`:

1. `wc_get_product()`; `invalid_product` per-ID error if missing.
2. Resolve targets: `all` + price/stock fields + variable/grouped type →
   `resolve_update_targets( $product, 'all' )` (reuses the shared trait);
   a childless variable/grouped parent fails the ID with `no_children`.
3. Apply price/stock updates per target through the existing shared helpers
   (`apply_price_fields`, `apply_stock_quantity`, `price_adjustment` math,
   `set_stock_status`, `set_manage_stock`), save each target, clear transients.
4. Apply status/featured to the selected product; save when changed.
5. `sync_variable_parent()` for variable parents; direct variation IDs re-sync
   their parent variable product (mirrors `update_woo_product_qty`); taxonomy
   updates (categories/tags) always target the selected product.
6. Dry runs report the same shape with computed changes and no writes.

## Testing

- New `addons/pro/tests/test-bulk-update-products-tool.php`:
  - Metadata, schema (`scope` enum + default `all`), capability flags,
    permission/argument gates.
  - Integration (skip when WooCommerce absent): simple round-trip; variable
    `scope=all` updates every variation + parent re-sync; grouped `scope=all`
    updates children; `scope=product` legacy exact-ID; status/featured-only
    update does not expand; price-adjustment routing; dry-run reports targets
    without writing; childless variable parent fails per-ID; invalid ID lands
    in `errors[]`.
- Regression: `test-update-woo-product-price-tool.php`,
  `test-update-woo-product-qty-tool.php` (trait untouched in this PR),
  tool-registry coverage manifest (no new classes).

## Risks & Mitigations

- **Behavior change for callers passing variable parents.** Previously
  false-success; now the variations are genuinely updated under the default
  `scope=all`. `scope=product` restores exact-ID semantics. Documented in the
  tool description, proposal, and PR.
- **Large variable catalogs.** `scope=all` writes every variation of each
  selected variable product; same caveat as the single-product tools and
  WooCommerce bulk edit. Callers can pass variation IDs directly or use
  `scope=product` + explicit ID lists.
- **Partial per-ID writes.** A validation error mid-target leaves earlier
  targets written; the input ID is reported in `errors[]` so callers can retry.
