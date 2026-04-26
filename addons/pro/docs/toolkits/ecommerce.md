# E-commerce Toolkit

> Advanced WooCommerce automation: products, orders, customers, inventory, marketing,
> and shipping. 20 tools backed by `@woocommerce/woocommerce-rest-api` and `stripe`.

| | |
|---|---|
| **Activation setting** | `enable_ecommerce_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → E-commerce |
| **Tools** | 20 |
| **NPM** | `@woocommerce/woocommerce-rest-api`, `stripe`, `currency.js` |
| **Requires** | WooCommerce |

---

## Tool categories

- **Product management:** `create_product_advanced`, `bulk_update_products`,
  `import_products_csv`, `export_products_report`, `low_stock_alert_automation`
- **Orders & fulfillment:** `process_order_workflow`, `bulk_order_status_update`,
  `refund_order_advanced`, `generate_invoice_pdf`, `get_order_analytics`
- **Customers:** `customer_lifetime_value`, `segment_customers`, `export_customer_data`
- **Inventory & shipping:** `inventory_forecast`, `shipping_rate_estimator`,
  `shipping_box_packer`
- **Marketing:** `create_discount_campaign`, `abandoned_cart_recovery`,
  `sales_performance_dashboard`

Tool source: `addons/pro/includes/tools/ecommerce/`.

---

## Activation

1. Activate WooCommerce and the Pro add-on.
2. Toggle **E-commerce Toolkit** under **NV oOS → Settings → Pro Features**.
3. Provide WooCommerce REST API credentials (and Stripe keys, if used) — store secrets in
   the [Password Vault](password-vault.md).

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/includes/tools/ecommerce/README.md`](../../includes/tools/ecommerce/README.md)
- [`addons/pro/docs/ECOMMERCE_ENHANCEMENTS_PLAN.md`](../ECOMMERCE_ENHANCEMENTS_PLAN.md)
