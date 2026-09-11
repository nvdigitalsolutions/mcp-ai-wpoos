# Implementation Plan 036 — WooCommerce Product Price & Quantity Update Tools

**Status:** ✅ Complete — implemented and validated (2026-09-07)
**Date:** 2026-09-07
**Proposal:** `036-woo-product-price-qty-update-tools.md`
**Branch target:** feature branch → PR against `alpha-working` per repo convention

## Phase 0 — Preconditions

- [x] Research complete (proposal doc).
- [x] Repo conventions confirmed: canonical envelope, two-gate sanitization, capability flags, `mcp-ai-wpoos-pro` text domain, eager tool loading via `wp_mcp_ai_pro_register_tools()`.
- [x] Confirm WooCommerce present in test env (integration tests skip otherwise). — WooCommerce loads in the WP 6.9 Docker env; absent in WP 7.1 (skip gates verified on both).

## Phase 1 — Shared trait

**New file:** `addons/pro/includes/tools/ecommerce/trait-wp-mcp-ai-woo-price-qty-updater.php`

Contents:

1. `resolve_update_targets( WC_Product $product, $scope )` → `array<WC_Product>|WP_Error`
   - `product`: `array( $product )`.
   - `variations`: requires `variable` type → children via `get_children()`; else `WP_Error( 'invalid_scope' )`.
   - `all`: variable → children; grouped → children; otherwise self.
   - Guard: variable + `product` scope → `WP_Error( 'invalid_scope' )` with guidance (per-tool override text via optional `$context` param).
2. `normalise_price( $value )` → `wc_format_decimal( (string) $value )`.
3. `apply_price_fields( WC_Product $product, array $args, array &$changes )` → `void|WP_Error`
   - Applies `regular_price`, `sale_price` (or `''` to clear), `sale_date_from/to` (validated via `strtotime`; `''` clears).
   - Validates sale < effective regular (new regular if provided, else current); returns `WP_Error( 'invalid_sale_price' )`.
   - Validates `sale_date_to >= sale_date_from`; returns `WP_Error( 'invalid_sale_dates' )`.
   - No save; caller saves (keeps `woo_products`/`bulk_update_products` single-save flow intact).
4. `apply_stock_quantity( WC_Product $product, $quantity, $operation = 'set', $enable_manage_stock = true )` → `array|WP_Error`
   - `$quantity` absint, `$operation` ∈ `set|increase|decrease` (sanitized by caller).
   - Computes new qty: `set` → absolute; `increase` → current + qty; `decrease` → `max( 0, current - qty )`.
   - If new qty > 0 → `wc_update_product_stock( $id, $new_qty, 'set' )` (fires low/no-stock notifications, syncs status, clears transients, saves).
   - If new qty === 0 → CRUD path: `set_manage_stock( true )` (when enabled), `set_stock_quantity( 0 )`, `save()`, `wc_delete_product_transients()`, `do_action( 'woocommerce_no_stock', $product )` (because `wc_update_product_stock()` rejects falsy quantities).
   - Returns `array( 'before' => int, 'after' => int, 'stock_status' => string, 'low_stock' => bool )`; `low_stock` via `wc_get_low_stock_amount()` comparison when managed.
5. `sync_variable_parent( WC_Product $product )` → calls `WC_Product_Variable::sync( $product->get_id() )` + `wc_delete_product_transients()` when `is_type( 'variable' )`.
6. `clear_transients_for( $ids )` → loop `wc_delete_product_transients()`.

## Phase 2 — Tool: Update Woo Product Price

**New file:** `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-price.php`

- Class `WP_MCP_AI_Tool_Update_Woo_Product_Price`; implements `WP_MCP_AI_Tool_Interface`, `WP_MCP_AI_Tool_Capability_Flags_Interface`, `WP_MCP_AI_Tool_Safety_Profile_Interface`; uses `WP_MCP_AI_Woo_Price_Qty_Updater`, `WP_MCP_AI_Tool_Safety_Profile`.
- Guarded trait require at file top: `if ( ! trait_exists( ... ) ) { require_once ... }`.
- `is_available()` (static): WooCommerce active + not base version + `wp_mcp_ai_is_ecommerce_toolkit_enabled()` (mirrors `bulk_update_products`).
- `get_required_capability()` → `edit_posts` (registry gate); runtime `user_can( $user_id, 'edit_products' )` inside `execute()` (mirrors `woo_products`).
- `get_capability_flags()` → `pro`, `write`, `requires-plugin`, `local-only`.
- Slug `update_woo_product_price`; name "Update WooCommerce Product Price".
- Schema per proposal §Design; `required` = `array( 'product_id' )`; enum for `scope`.
- `execute()` flow:
  1. Availability gate → `WP_Error( 'woocommerce_not_active' )`.
  2. Sanitize entry (two-gate rule): `absint( product_id )`, `sanitize_key( scope )`, price strings via `wc_format_decimal`.
  3. Capability check via `$context['user_id']`.
  4. `wc_get_product()` → `WP_Error( 'product_not_found' )`.
  5. Guard post type ∈ `product|product_variation`.
  6. `resolve_update_targets()`.
  7. For each target: `apply_price_fields()` → on `WP_Error`, return it; else save, clear transients, record `updated[]` with before/after prices + `on_sale`.
  8. `sync_variable_parent()` when parent is variable.
  9. Canonical envelope: success array with `product_id`, `product_type`, `scope`, `updated`, `message` (sprintf + `__()`).
- External-product price updates supported (direct).

## Phase 3 — Tool: Update Woo Product Qty

**New file:** `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-qty.php`

- Class `WP_MCP_AI_Tool_Update_Woo_Product_Qty`; same interfaces/trait/flags as Phase 2 (flags: `pro`, `write`, `requires-plugin`, `local-only`).
- Slug `update_woo_product_qty`; name "Update WooCommerce Product Quantity".
- Schema: `product_id` (required), `quantity` (required, integer ≥ 0), `operation` enum `set|increase|decrease` (default `set`), `scope` enum, `manage_stock` boolean (default `true`).
- `execute()` flow: same gates; reject non-stock-managed types (`external`, variable/grouped parent via scope rules) with `WP_Error( 'unsupported_type' )`; `apply_stock_quantity()` per target; `sync_variable_parent()` for variable parents; response per proposal §Design with `before_qty`, `after_qty`, `stock_status`, `low_stock`.

## Phase 4 — Registration & surfaces

1. **`addons/pro/mcp-ai-wpoos-pro.php`**
   - Add both classes to `$ecommerce_toolkit_tools` (~L1311) under "Product Management tools".
   - Guarded `require_once` of the trait before the toolkit array (mirrors Shopify trait pattern ~L1223).
   - `wp_mcp_ai_pro_tool_group_map()`: add both slugs → `wordpress-plugins` (~L2210).
   - `wp_mcp_ai_pro_tool_categories()`: add both slugs → `medium_resource` (~L2694).
2. **`addons/pro/includes/mcp-servers/servers/class-wp-mcp-ai-ecommerce-mcp-server.php`** — add both slugs to `candidate_tool_slugs()`.
3. **`addons/pro/includes/admin/class-wp-mcp-ai-ecommerce-settings-page.php`**
   - `get_tools_list()`: add both entries.
   - `render_overview_tab()`: "20 powerful tools" → "22 powerful tools".
4. **`addons/pro/includes/admin/class-wp-mcp-ai-product-settings-page.php`** — `get_tools_list()`: add both entries.
5. **`addons/pro/includes/admin/class-wp-mcp-ai-product-research-page.php`** — add both slugs to `$product_tools` (product management group).
6. **`includes/helpers/class-wp-mcp-ai-tool-presets-helper.php`** — add both slugs to the E-commerce preset (~L465).
7. **`addons/pro/includes/rest/class-wp-mcp-ai-telegram-mini-app-controller.php`** — add both slugs to `enable_ecommerce_toolkit` → `tool_slugs` (~L5030).
8. **`addons/pro/includes/tools/ecommerce/README.md`** — add both tools to Product Management section; bump counts (22 → 24).
9. **Root `README.md`** — add both rows to "Commerce & finance operations" table (marked 🌟 Pro addon tool).

## Phase 5 — Trait adoption in existing tools (P2, additive)

1. **`woo_products` (`class-wp-mcp-ai-pro-tool-woo-products.php`)**
   - `use WP_MCP_AI_Woo_Price_Qty_Updater;` + guarded trait require.
   - `update_product()`: replace inline `set_regular_price`/`set_sale_price` with `apply_price_fields()` (gains validation); replace `set_stock_quantity` block with `apply_stock_quantity()` (gains notifications + status sync); after `save()`, `sync_variable_parent()` + transient cleanup.
   - Response shape unchanged (`format_product()`).
2. **`bulk_update_products` (`class-wp-mcp-ai-tool-bulk-update-products.php`)**
   - Same trait wiring in `update_single_product()`; keep `$changes` reporting; keep single-target-per-ID response (variable-parent scope expansion deferred — documented follow-up).
   - `price_adjustment` logic unchanged.

## Phase 6 — Tests

**New files (in `addons/pro/tests/`):**

- `test-update-woo-product-price-tool.php`
  - Metadata: slug, name, description, schema shape, capability flags, safety profile present.
  - Gating: subscriber → `permission_denied`; missing `product_id` → error; WooCommerce-absent → skip or stub-based error path.
  - Validation: negative regular price, sale ≥ regular, invalid sale dates, invalid scope on simple product.
  - Integration (skip when WooCommerce absent): simple price update round-trip; sale price clear; variable product `scope=all` updates every variation; variation-ID direct update; grouped `scope=all`.
- `test-update-woo-product-qty-tool.php`
  - Metadata + gating as above.
  - Validation: negative quantity, invalid operation, invalid scope, external product rejection.
  - Integration: simple set/increase/decrease round-trips; zero-stock path sets `outofstock`; variable `scope=all` updates variations + parent status; grouped `scope=all`.

**Existing tests to update/verify:**

- `tests/test-plugin-integration-tools-settings.php` — no change required (new tools are not in its asserted list), verify still green.
- `addons/pro/tests/tools/test-remaining-pro-tools.php` — new classes must NOT be added there (they have dedicated tests); verify no overlap.

## Phase 7 — Documentation

- [x] `docs/project/proposals/036-woo-product-price-qty-update-tools.md` (proposal)
- [x] `docs/project/proposals/036-woo-product-price-qty-update-tools-implementation-plan.md` (this doc)
- [x] E-commerce README + root README (Phase 4 items 8–9)

## Phase 8 — Validation gates

- [x] `php -l` each new/changed PHP file — all clean.
- [x] `phpcs` (WPCS) — 0 errors on new/changed files (only the pre-existing unknown-capability warnings for WooCommerce-registered capabilities, identical to existing ecommerce tools).
- [ ] `composer run lint:compat` (PHP 7.4–8.3) — not run in this session; code uses no syntax beyond PHP 7.4.
- [x] Targeted PHPUnit (Docker, WP 6.9 + WooCommerce): 34 tests / 113 assertions, 2 environment skips — green.
- [x] Targeted PHPUnit (Docker, WP 7.1, no WooCommerce): 34 tests, 22 environment skips — green.
- [x] Regression sweep: 9 affected existing suites (plugin-integration, pro-tools group map/loading/capability-flag, woo tools, ecommerce opt-in, admin menu priority, product research page loading, toolkit server contract) — 51 tests / 1150 assertions, green.

## Rollback plan

- Revert registration entries + delete two tool files → zero effect on existing tools.
- Trait adoption in existing tools is additive; revert those hunks only if a regression appears (response shapes unchanged).

## Follow-ups (deferred, documented)

- Variable-parent scope expansion for `bulk_update_products` (response-shape change; needs its own proposal).
- `notify` suppression flag on qty updates if email noise becomes an issue.
- `assets/csv-templates/TOOL-MAPPING.md` and assistant-type docs mention of the new tools.
