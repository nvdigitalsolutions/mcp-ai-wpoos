# Proposal 110 — Embedded Checkout via the Store API (bridge proxy + storefront UI)

> Status: **Phase A + Phase B shipped** (2026-11-04). Companion tracks:
> Proposal 108 (cart sync, shop globals, SSO), Proposal 106 (checkout
> handoff), Proposals 085/090 (Laravel backend flip). Research summary +
> industry sources: see §5.

## 1. Problem

Proposal 106/108 handed the shopper off to the hosted WooCommerce checkout
with a clean URL. That matches the industry DEFAULT (Shopify Hydrogen,
BigCommerce) and the SAQ-A-safe pattern — but the owner wants the UX owned:
redirects are the single largest checkout drop-off lever, and the Store API
(the surface the Woo Blocks checkout itself uses) can complete the entire
checkout server-side. **The storefront no longer needs to link out for the
common path.**

## 2. Why the Store API, not `wc/v3`

| Surface | Fit |
|---|---|
| `wc/v3` (orders, gateways) | Admin/management API — consumer key/secret, records orders but **never processes payment**; can never be exposed to a browser. Not a checkout surface. |
| **Store API `wc/store/v1`** | The supported customer-facing cart/checkout surface. `POST /checkout` accepts addresses, `payment_method`, gateway `payment_data`, optional `expected_total` (409 on drift) and returns `order_id`/`order_key`/`payment_result` (Woo dev docs, verified 2026-11-04). This is what Woo Blocks calls internally. |
| Legacy `?add-to-cart=` | Fallback only (native mode / proxy failure). |

## 3. The payment boundary (INV-1 refined)

The 108 invariant ("the app never renders card fields") is refined to:
**the app never renders RAW card inputs; gateway-hosted fields are
sanctioned.** Card collection happens inside Stripe's hosted Payment Element
(Stripe.js v10); the app relays only the `payment_method` token in
`payment_data`. Card data never touches the app, the BFF, or the bridge —
PCI posture: SAQ A (redirect methods) / SAQ A-EP (embedded Stripe Elements),
never SAQ D. Apple/Google Pay is deferred: Stripe.js v10 removed the legacy
Payment Request Button whose `paymentmethod` event yielded the pm token the
Store API flow needs; `ExpressCheckoutElement` confirms via client-secret
PaymentIntents and cannot feed the Woo gateway's `payment_data`. Express
checkout returns as a live-verified follow-up (also needs Apple Pay domain
registration on the deployment side).

## 4. What shipped

**Phase A — bridge (`addons/nvoos-astro-bridge/`):**
- `Woo/StoreApiClient` — `mint_nonce()` (`wc_store_api`), `get_checkout()`,
  `checkout()`, `update_customer()`, `select_shipping_rate()`; nonce + cart
  token headers on every call; the `funiq_bridge_store_api_pre_response`
  seam gained an additive 4th arg (resolved request args) so tests assert
  the outbound body/headers.
- `REST/CheckoutController` — `GET /checkout` (merged draft-order + cart
  doc), `POST /checkout/customer`, `POST /checkout/shipping-rate`,
  `POST /checkout/coupon`, `POST /checkout` (place order; per-session
  **and** per-IP rate limits; `expected_total` passthrough; 409 total-drift
  preserves the refreshed cart). All routes fail-closed (501 Woo missing,
  400 native mode, typed session validation) and pin
  `Cache-Control: private, no-store`.
- `BaseController::set_cache_headers_on_public_gets` — `/checkout` excluded
  from the public B1 cache headers (per-session draft orders must never be
  edge-cached).
- `ShopGlobalsController` — read-only `stripePublishableKey` (public key by
  design; empty when Stripe is inactive) + `guestCheckout` (Woo's switch).
- `Plugin.php` — controller registered under BOTH namespaces
  (`funiq/v1` + `nvoos-commerce/v1` — the app binds only to the latter).
- Tests: `tests/phpunit/REST/TestCheckoutController.php` — 12 tests, 59
  assertions, green on WP 6.9 **and** WP 7.1 (163/163 group run, 2026-11-04);
  phpcs clean.

**Phase B — storefront (`examples/nvoos-astro-velocity/`):**
- `lib/commerce/checkout-api.ts` — zod-validated client for the five routes,
  `buildStripePaymentData()` (the Woo-documented key set),
  `postPaymentDestination()`, `parseThanksParams()`, `canEmbedCheckout()`.
- `pages/checkout/index.astro` — SSR shell, `Cache-Control: no-store`, and
  the `x-nvoos-csp-payment` marker that scopes the Stripe.js CSP directives
  to THIS document only (`scripts/lib/security-headers.mjs` — the marker is
  stripped before the response ships; every other page keeps the locked-down
  policy).
- `islands/CheckoutView.tsx` — address step (native validation) →
  `POST /checkout/customer` (Woo-side validation + totals) → shipping rates
  → coupon → payment methods (Stripe Payment Element via
  `@stripe/stripe-js`/`@stripe/react-stripe-js` v10 when the key ships;
  radio list otherwise) → place order with `expected_total` → gateway
  redirect or `/checkout/thanks/?order_id=&order_key=`. Analytics seams:
  `nvoos:checkout-submitted/succeeded/failed/redirect`.
- `pages/checkout/thanks.astro` — the 108 route, now reading VALIDATED
  params only (`parseThanksParams`), `no-store`.
- `islands/CartView.tsx` — woo/both mode: sync → **`/checkout/`** (event
  mode `embedded`); the hosted URL stays the fail-open fallback; native mode
  unchanged.
- Fixture server — stateful per-session checkout twin (cart/sync seeds
  lines; customer/rate/coupon/place-order mutate; 409 drift simulation);
  shop doc gains `guestCheckout` (+ `cod` gateway), deliberately NO Stripe
  key (hermetic e2e never loads Stripe.js).
- Gates: `astro check` 0 errors · eslint 0 · vitest 363/363 (23 new) ·
  Playwright 75/75 (2 new specs + 1 updated on the woo pair; axe-clean
  checkout page) · hermetic `npm run build` + token/policy drift gates
  clean.

## 5. Research basis (verified 2026-11-04)

- Woo dev docs, **Checkout API** — endpoint contract, Stripe `payment_data`
  example, `expected_total` 409 semantics, nonce/cart-token auth.
- Woo dev blog + WP Clan / Javier Miz / codeatoz guides — Store API as the
  sanctioned headless checkout path; Stripe Elements for PCI-compliant card
  capture on a custom frontend.
- Stripe PCI guidance — hosted fields keep card data off merchant servers;
  Elements on a custom form = SAQ A-EP vs SAQ A for pure redirect.
- Embedded-checkout UX research (Shuttle, North, Tagada, Weaverse, Stripe
  checkout best practices) — embedded checkout removes the redirect drop-off;
  keep the flow thin; express payments are the biggest friction reducer
  (deferred per §3).

## 6. Fit in the overall plan

- **092/104 (storefront):** this closes the last "link out" lane — the three
  lanes become: app routes (cart + embedded checkout), content blocks, and
  the HOSTED Woo checkout as the fail-open fallback lane.
- **108 (checkout hardening):** 108's cart sync, shop globals, return URL
  and SSO work are all reused; its hosted-checkout lanes remain for native
  mode and unsupported gateways.
- **085/090 (T1 Laravel flip):** the bridge is transitional. The app binds
  ONLY to the `nvoos-commerce/v1` checkout envelope — Laravel re-implements
  the same five routes against the T1 cart/order resource and the app
  survives unchanged. The proxy stays a thin pass-through by design.
- **PWA parity (Q1):** the A/B PWA comparison gains a checkout-UX
  differentiator; hosted parity remains available until retirement.
- **PCI posture change (owner decision):** embedded Stripe Elements moves
  the merchant from SAQ A to SAQ A-EP for the card path. Accepted in this
  PR; re-scope before any change that touches card data handling.

## 7. Follow-ups

- **Express checkout (Apple/Google Pay)** — live-verified follow-up (§3).
- **Order status lookup** ("where is my order") — T1 candidate (108 gap
  sweep).
- **Live install verification** — the Stripe `payment_data` shape is pinned
  to the Woo-documented keys; verify against the live Stripe gateway on
  staging before announcing the card path.
