# Proposal 036 — WooCommerce Product Price & Quantity Update Tools

**Status:** ✅ Implemented & validated (2026-09-07)
**Date:** 2026-09-07
**Scope:** E-commerce Pro Toolkit (`addons/pro/includes/tools/ecommerce/`)
**Related:** `036-woo-product-price-qty-update-tools-implementation-plan.md`

## Summary

Add two dedicated, all-product-type-aware tools to the E-commerce Toolkit:

1. **`update_woo_product_price`** — update regular/sale prices for simple, variable (parent + variations), grouped, and external products.
2. **`update_woo_product_qty`** — update stock quantity for simple, variation, variable (via variations), and grouped products, using WooCommerce's canonical `wc_update_product_stock()` path so low-stock/no-stock notifications and stock-status sync behave correctly.

## Motivation / Gap Analysis

The toolkit today can technically touch price and stock, but with real gaps:

| Existing surface | Gap |
| --- | --- |
| `woo_products` (update action) | Simple-product focused. No variation iteration, no `WC_Product_Variable::sync()`, no sale-price validation, `set_stock_quantity()` bypasses low-stock notifications and transient cleanup. |
| `bulk_update_products` | Same gaps. Stock on a variable parent is silently meaningless; sale ≥ regular prices silently ignored by WooCommerce. |
| `sync_product_inventory` | Cross-site inventory sync, not single-product operations. |

Dedicated single-product tools give the AI a precise, predictable operation surface (important for MCP tool calling) and fix the WooCommerce semantics at the same time.

## Industry Research (sources)

- **WooCommerce canonical stock API:** `wc_update_product_stock( $id, $qty, $operation )` with `set|increase|decrease` — auto-syncs `_stock_status`, fires `woocommerce_low_stock`, `woocommerce_no_stock`, `woocommerce_product_set_stock`, and clears transients. Raw `update_post_meta( '_stock' )` is discouraged (WooCommerce docs, puri.io, ithemelandco bulk-edit write-ups).
- **CRUD price API:** `set_regular_price()` / `set_sale_price()` with `wc_format_decimal()` normalization; `''` clears a sale; `set_date_on_sale_from/to()` for scheduled sales; validate sale < regular (WooCommerce silently ignores invalid sale prices).
- **Variable products:** price/stock live on variations. Loop `$product->get_children()` → update each `WC_Product_Variation` → call `WC_Product_Variable::sync( $product_id )` to re-sync parent min/max price and stock status; `wc_delete_product_transients()` after writes (Rudrastyh, Business Bloomer, QuadLayers).
- **Grouped products:** parent has no own price/stock — children (simple products) are the update targets.
- **MCP ecosystem:** leading WooCommerce MCP servers use `update_stock(product_id, quantity, operation)` signatures mapping directly to `wc_update_product_stock()` (juanlurg/woocommerce-mcp, woo-mcp-server). Official WooCommerce MCP tooling exposes price/stock updates with the REST field set (regular/sale price, dates, stock quantity/status).

## Design

### Shared trait (fixes drift across all ecommerce tools)

`trait-wp-mcp-ai-woo-price-qty-updater.php` provides:

- `resolve_update_targets( WC_Product $product, $scope )` — scope-aware target resolution (`product|variations|all`) with type validation.
- `apply_price_fields()` — validation + setter application (no save), returns change log or `WP_Error`.
- `apply_stock_quantity()` — operation math (`set|increase|decrease`, clamped at 0), routes positive quantities through `wc_update_product_stock()` (notifications + transient cleanup), handles the quantity-0 edge via CRUD + explicit `woocommerce_no_stock` action.
- `sync_variable_parent()` — `WC_Product_Variable::sync()` + transient cleanup.
- `clear_transients_for()` — `wc_delete_product_transients()`.

The trait is also adopted by `woo_products` and `bulk_update_products` so existing surfaces inherit the corrected semantics.

### Tool contracts

Both tools follow repo conventions: `WP_MCP_AI_Tool_Interface` + capability flags + safety profile (matching core write tools), canonical envelope (success array or `WP_Error`, never `success => false`), two-gate sanitization, `mcp-ai-wpoos-pro` text domain.

**`update_woo_product_price`**
- `product_id` (required), `scope` (`product|variations|all`), `regular_price`, `sale_price`, `sale_date_from`, `sale_date_to`, `clear_sale`.
- Errors: `woocommerce_not_active`, `permission_denied`, `product_not_found`, `invalid_scope`, `invalid_price`, `invalid_sale_price`, `invalid_sale_dates`, `unsupported_type`.
- Response: `product_id`, `product_type`, `scope`, `updated[]` (id, sku, type, before, after, on_sale), `message`.

**`update_woo_product_qty`**
- `product_id` (required), `quantity` (required, ≥ 0), `operation` (`set|increase|decrease`), `scope`, `manage_stock` (default `true`).
- Errors: as above plus `invalid_quantity`.
- Response: `product_id`, `product_type`, `scope`, `updated[]` (id, sku, type, before_qty, after_qty, stock_status, low_stock), `message`.

### Behavior matrix

| Product type | Price | Qty |
| --- | --- | --- |
| simple | direct | direct (`wc_update_product_stock`) |
| variable + `scope=product` | `WP_Error` → suggest `scope=all` | `WP_Error` → suggest `scope=all` |
| variable + `scope=variations/all` | update each variation → `WC_Product_Variable::sync()` | update each variation → `WC_Product_Variable::sync()` |
| grouped + `scope=all` | update each child simple product | update each child simple product |
| grouped + `scope=product` | `WP_Error` (parent has no own price) | `WP_Error` (parent has no own stock) |
| external/affiliate | direct price update | `WP_Error` (not stock-managed) |
| variation (ID passed directly) | direct | direct |

## Toolkit Enhancements (all surfaces)

1. Register both tools in `wp_mcp_ai_pro_register_tools()` (`$ecommerce_toolkit_tools`, gated by `enable_ecommerce_toolkit`).
2. Add slugs to the E-commerce MCP server `candidate_tool_slugs()`.
3. Add to `wp_mcp_ai_pro_tool_group_map` (`wordpress-plugins`) and `wp_mcp_ai_pro_tool_categories` (`medium_resource`).
4. Add to E-commerce and Product settings pages `get_tools_list()`; bump overview copy 20 → 22.
5. Add to ecommerce folder `README.md` tool inventory.
6. Add to root `README.md` commerce tools table.
7. Add to tool-presets helper (E-commerce preset) and Product Research page chat tool list.
8. Adopt shared trait in `woo_products` + `bulk_update_products` price/stock paths.

## Testing

- New tests in `addons/pro/tests/`: `test-update-woo-product-price-tool.php`, `test-update-woo-product-qty-tool.php` (metadata, schema, capability gating, validation errors, all-types integration paths, skip-if-WooCommerce-absent pattern).
- Existing WooCommerce stubs (`tests/helpers/woocommerce-stubs.php`) for unit coverage without WooCommerce.
- Validation: `composer run lint`, `composer run lint:compat`, targeted PHPUnit.

## Risks & Mitigations

- **Low-stock emails fire on qty updates** — intended (matches WooCommerce bulk-edit behavior); documented in tool description.
- **Large variable catalogs** — `scope=all` iterates all variations; acceptable for single-product ops; documented performance note.
- **Behavior change for existing tools** (trait adoption) — improvements are additive (validation, transient cleanup, notifications); no response-shape changes.
