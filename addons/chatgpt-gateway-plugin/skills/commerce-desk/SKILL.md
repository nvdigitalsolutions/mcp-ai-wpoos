---
name: commerce-desk
description: Explore WooCommerce store capabilities exposed by the NV oOS demo site over the fleet gateway — catalog and order lookups through namespaced demo_* tools, when the demo site exposes them.
---

Work against the connected `nvoos_gateway` MCP server. Store tools, when the
public demo site exposes them, appear in `tools/list` with the `demo_`
prefix (for example `demo_get_woo_products`).

Rules:

- Check `tools/list` first — the demo site's operator decides which store
  tools the gateway exposes; do not assume WooCommerce tools exist.
- Prefer read-only lookups (products, orders the demo exposes publicly).
- Never place orders, change prices, refund, or modify store data without
  explicit user confirmation and the exact record IDs.
- Financial figures are demo data — label them as such in every answer.
