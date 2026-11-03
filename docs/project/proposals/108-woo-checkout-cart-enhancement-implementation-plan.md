# 108 — WooCommerce Checkout & Cart Enhancement — Implementation Plan

> Phase-by-phase plan for the gaps in
> `108-woo-checkout-cart-enhancement-gap-analysis.md`. Phase 1 ships in this
> PR together with the 107 follow-up (tabs + accordion content blocks);
> Phases 2–4 are scoped for owner approval.

## Phase 1 — Harden the existing handoff (this PR)

### 1.1 H1 — same-site + allowlist checkout/cart/return URLs

`addons/nvoos-astro-bridge/includes/REST/ShopGlobalsController.php`:

- Add `is_allowed_shop_url( $url )`:
  1. absolute http(s) (existing check);
  2. host must equal the site home host (`wp_parse_url( home_url(), PHP_URL_HOST )`)
     OR be present in the `funiq_bridge_checkout_url_allowlist` filter
     (array of hostnames, empty by default);
  3. reject userinfo (`user@host`) and ports other than 80/443 by default.
- New error code `astro_shop_checkout_url_not_allowed` (400) replacing the
  generic invalid-URL code for off-site URLs.
- `Schema::shop_defaults()` unchanged — `home_url('/checkout/')` passes the
  same-site rule by construction.

Tests (`tests/phpunit/REST/TestShopGlobalsController.php`): PATCH with an
off-site URL → 400 + new code; PATCH with an allowlisted off-site host (via
filter) → 200; same-site with query string → 200; userinfo URL → 400.

### 1.2 H2 — safe link attributes

`examples/nvoos-astro-velocity/src/islands/CartView.tsx`: the checkout
anchor gains `rel="noopener noreferrer"` (same-tab navigation stays —
`target` unchanged). Update `tests/e2e/checkout.spec.ts` to assert the rel
attribute.

### 1.3 107 follow-up — promote tabs + accordion into content blocks

App-side only (the bridge passes blocks through; validation is the app
schema, per 107):

- `src/lib/content/portable-text.ts` — add the `tabs` custom block type:
  `{ _type: 'tabs', _key?: string, tabs: [{ title: string (1..120),
  blocks: PortableTextItem[] (1..20) }] (1..6) }` via `z.lazy` for the
  recursive tab content; extend `CUSTOM_BLOCK_TYPES`.
- `src/components/blocks/FaqBlock.astro` — re-render through
  `UiAccordion`/`UiAccordionItem` (native details/summary, shared
  `name="nvoos-content-faq"` → the exclusive-accordion pattern; solo-item
  top border restored in scope).
- New `src/components/blocks/TabsBlock.astro` — `UiTabs`/`UiTabsList`/
  `UiTabsTab`/`UiTabsPanel` with deterministic ids
  (`tabs-<hash>-<i>` / `panel-<hash>-<i>` from `_key` or a djb2 of the
  node JSON); nested tab content renders through `<Blocks>` recursively.
- `src/components/blocks/Blocks.astro` — register `tabs: TabsBlock`.
- Tests: extend `tests/unit/portable-text.test.ts` (accept tabs with nested
  blocks, reject empty tabs array / empty title / nested unsafe link);
  extend `tests/e2e/content-write.spec.ts` (FAQ renders as details/summary
  with the exclusive name; tabs render role=tab/tabpanel, click switches,
  axe stays green).

### 1.4 Gates

App: `npm run lint`, `NODE_OPTIONS=--max-old-space-size=8192 npm run check`,
`npm run test`, focused `npm run test:e2e -- content-write checkout
component-library`. Bridge: phpcs + PHPUnit (WP 6.9 + 7.1 matrix) per the
test-suite skill. Hermetic build (`npm run build:prod-sim` equivalent in
the sync workflow).

## Phase 2 — Cart parity via a bridge Store API proxy (owner approval)

1. **Bridge `POST /nvoos-commerce/v1/cart/items`** — server-side Store API
   client (`wc/store/v1/cart/*`): mint a cart nonce/cart token via the
   Store API bootstrap (cart token for guests, nonce for logged-in
   sessions), then forward add-item/update-item/remove-item and
   apply-coupon ops. Returns the full Store API cart state (items with
   variations, totals, coupons, notices) normalized to the envelope shape.
   - Credentials never reach the browser (H4).
   - Woo-absent → typed 501 error; native mode → existing behaviour.
2. **App cart island** switches to the proxy when `source: 'woo'` (bridge
   products-source option) and keeps `buildCheckoutUrl()` as the
   fail-closed fallback (Lane 3 handoff unchanged).
3. **Variant lines** — `CartLine.variantId` + `attributes` post to
   `add-item`; variant checkout closes (G1, G3).
4. **Handoff analytics** — `checkout_handoff` event (product ids, line
   count, source) via the existing event seam; no PII.
5. Tests: bridge PHPUnit (fixture-mocked Store API HTTP), app vitest for
   the new client path, e2e cart spec for variant add + coupon.

## Phase 3 — Checkout continuity (owner approval; Q1–Q3 decide)

1. **Return URL** — add `returnUrl` to the shop globals (default
   `home_url('/checkout/order-received/')`); bridge filter
   `woocommerce_get_return_url` points it at the storefront thanks route
   `/checkout/thanks/`; storefront route renders order reference from the
   query (no sensitive data).
2. **Gateway list** — shop globals gain read-only `paymentMethods` (enabled
   gateway ids/titles from `wc/v3/payment_gateways`, bridge-side, cached
   60 s) so the cart can show "Visa, PayPal" without the app touching
   `wc/v3` (G6).
3. **Optional SSO handoff (Q1)** — bridge mints a short-TTL HMAC token
   (`nvoos_woo_login` param) exchanged at a signed `/woo-login/{token}`
   endpoint that sets the auth cookie and redirects to checkout (H5).
   Off by default.

## Phase 4 — MCP `shop` tool slice (owner approval)

1. App `/mcp` gains `shop_get` (read-only, open) and `shop_update`
   (manager-session gated, per-session write quota, audit line) over the
   `/globals/shop` surface — the DECISION-1 slice.
2. Handshake test + unit tests + a `rebrand-loop`-style e2e for the write
   gate; `SERVER_VERSION` bump to 0.4.0.
3. Bridge Settings UI gains the `returnUrl` field (parity with the MCP
   write path).

## Validation matrix

| Phase | App | Bridge | e2e | Docs |
|---|---|---|---|---|
| 1 | lint · check · vitest | phpcs · PHPUnit 6.9+7.1 | checkout · content-write · component-library | 108 trio |
| 2 | lint · check · vitest | phpcs · PHPUnit (fixture HTTP) | cart variants/coupons | runbook update |
| 3 | lint · check · vitest | phpcs · PHPUnit | checkout continuity | setup doc |
| 4 | handshake · unit | — | shop-tool loop | skill + README |

## Rollback & strangler notes

- Every phase is additive behind the existing fail-closed handoff; the URL
  fallback remains until Phase 2 e2e proves parity.
- The bridge cart proxy is a thin pass-through so the T1 Laravel cart
  resource (Proposals 085/090) can replace it without touching the app's
  cart store.
- PCI scope never changes (INV-1): the app still renders zero payment UI.
