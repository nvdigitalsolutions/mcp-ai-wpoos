# Proposal 038 — Update Woo Product Qty: Notify Suppression Flag

**Status:** ✅ Implemented & validated (2026-09-08)
**Date:** 2026-09-08
**Scope:** E-commerce Pro Toolkit (`addons/pro/includes/tools/ecommerce/`)
**Related:** `036-woo-product-price-qty-update-tools.md`, issue #6389 (item 2)

## Summary

Add a `notify` boolean parameter (default `true`) to `update_woo_product_qty`
so operators can suppress WooCommerce's low-stock / no-stock **notification
emails** for bulk or automated restock passes — without masking notifications
for any other call in the same request.

## Motivation / Gap Analysis

Quantity updates route through `wc_update_product_stock()` and, on the
zero-quantity path, an explicit `woocommerce_no_stock` action — matching
WooCommerce bulk-edit behavior. Every AI-driven restock sweep therefore fires
admin emails per product (a 500-SKU pass = up to 500 emails), which is noise
for scheduled/automated passes. The trait adoption in `woo_products` and
`bulk_update_products` inherits the same behavior.

The fix must be **scoped to the current call only**: a global filter left in
place would silently suppress emails for unrelated order-driven stock changes
in the same request (and, if leaked, indefinitely).

## Design

### Suppression seam

WooCommerce 10.9 gates the admin stock emails inside `WC_Emails::low_stock()`
/ `WC_Emails::no_stock()` with:

- `woocommerce_should_send_low_stock_notification` (since WC 4.7.0)
- `woocommerce_should_send_no_stock_notification` (since WC 4.10.0)

These are the canonical "don't send" seams: returning `false` short-circuits
before `wp_mail()` runs. The issue's suggested recipient filters
(`woocommerce_email_recipient_low_stock` / `woocommerce_email_recipient_no_stock`)
were considered and rejected: returning an empty recipient still reaches
`wp_mail( '' )` (empty-recipient send), and both seams share the same
evaluation timing, so the `should_send` filters are strictly cleaner.

### Scoping

`apply_stock_quantity()` in the shared trait gains a `$notify = true`
parameter. When `false`, the two `should_send` filters are added
(`__return_false`, priority 999) **immediately before the stock write** and
removed in a `finally` block **immediately after**, covering:

- the positive-quantity `wc_update_product_stock()` path,
- the zero-quantity CRUD path (decision: **yes, the zero-qty path also
  suppresses** — its explicit `woocommerce_no_stock` action is the primary
  email trigger for `set 0` restock passes; the action itself still fires so
  non-email observers such as push-notification triggers and inventory
  plugins are unaffected),
- early returns/errors (the `finally` guarantees filter removal).

The stock write, stock-status sync, transient cleanup, and response shape are
untouched. `woo_products` and `bulk_update_products` call the trait with the
default (`true`), so their behavior is unchanged — the flag can be surfaced on
those tools later if needed.

### Tool contract (`update_woo_product_qty`)

Schema addition:

```
notify: boolean, default true
```

- Sanitized at entry via boolean coercion (two-gate rule, gate one).
- Passed through to `apply_stock_quantity( $target, $quantity, $operation,
  $manage, $notify )` per target.
- Canonical envelope unchanged: success array or `WP_Error`; no new error
  codes; response keys unchanged.
- Tool description updated to document the flag.

### Known limitation (documented)

When a store enables the opt-in `deferred_transactional_emails` feature
(disabled by default), stock emails are queued to Action Scheduler and sent in
a later request; request-scoped filters cannot suppress queued sends. The
suppression covers the default synchronous path, which is what the flag is
documented to target.

## Testing

Extended `addons/pro/tests/test-update-woo-product-qty-tool.php`:

- Schema asserts the `notify` property (default `true`).
- `test_notify_false_suppresses_notification_decision`: a recorder on
  `woocommerce_no_stock` resolves `woocommerce_should_send_no_stock_notification`
  during a `quantity => 0` update with `notify => false` and asserts it
  resolves to `false` inside the call and back to `true` after the call;
  asserts the suppression filters are removed afterwards (no global leak).
- `test_notify_true_default_keeps_notifications_open`: same probe with the
  default resolves to `true` during the call.
- `test_notify_false_still_updates_stock`: stock write, status sync, and
  response shape unchanged under suppression.
- Existing suite (metadata, gating, validation, integration) stays green.

## Risks & Mitigations

- **Scoped-suppression leak.** Mitigated by `finally`-guarded removal; tests
  assert filter removal after the call.
- **Other notification channels.** The `woocommerce_no_stock` /
  `woocommerce_low_stock` actions still fire; only the admin email send is
  suppressed. Push notifications and third-party observers are unaffected.
- **Deferred-emails caveat** documented in the tool description and proposal.
