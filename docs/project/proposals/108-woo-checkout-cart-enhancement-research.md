# 108 — WooCommerce Checkout & Cart Enhancement — Industry Research

> Companion research to the gap analysis and implementation plan
> (`108-woo-checkout-cart-enhancement-gap-analysis.md`,
> `108-woo-checkout-cart-enhancement-implementation-plan.md`).
> Scope: how industry-standard headless storefronts integrate a hosted
> WooCommerce checkout/cart, which surfaces are the supported integration
> points, and what the security baseline is. Sources verified 2026-11-03.

## 1. The hosted-checkout handoff IS the industry pattern

Every major headless platform defaults to **redirecting the shopper to the
platform's hosted checkout** rather than re-implementing payment UX in the
storefront:

- **Shopify Hydrogen** — "For Shopify-backed stores, redirect to Shopify's
  hosted checkout via the `cart.checkoutUrl`. This is the recommended
  approach — Shopify Checkout handles payment processing, tax calculation,
  shipping rate retrieval, discount codes, and order creation."
  (digitalapplied.com, "Headless Commerce: Next.js Storefront Dev Guide").
- **Shopify's own docs** describe the two-domain shape: the storefront on the
  primary domain, checkout on a dedicated `checkout.*` domain with the
  customer routed back afterwards (shopify.dev, Hydrogen redirect-traffic).
- **BigCommerce / Medusa** follow the same redirect-or-embedded-checkout
  split; Medusa's open-source engine is the reference for "checkout is owned
  by the commerce backend, not the head".

**Consequence for NV oOS:** the Proposal 106 handoff
(`buildCheckoutUrl()` → `{checkoutUrl}?add-to-cart=1,2`) already matches the
industry default. The enhancement work is therefore **hardening + parity**,
not a re-architecture: keep the hosted checkout, close the gaps around it.

## 2. PCI DSS scope — why the handoff is also the *secure* choice

WooCommerce's PCI documentation is explicit (woocommerce.com,
"PCI-DSS compliance and WooCommerce"):

> If you're using a payment gateway that processes payments off-site or
> through hosted fields (such as Stripe, PayPal, or WooPayments), your site
> is not directly handling raw cardholder data. However, because your site
> still serves the checkout page, PCI-DSS does still apply — though your
> compliance scope may be significantly reduced.

- Redirect-style gateways keep the merchant at **SAQ A** scope
  (kioptrix.com, "PCI DSS Compliance for WooCommerce Stores"; patsatech.com,
  "PCI scope explained").
- Direct/integrated gateways (card fields rendered in the storefront)
  shoulder the heavier SAQ A-EP / SAQ D burden.

**Hard invariant adopted (INV-1):** the Astro app never renders card fields,
never receives cardholder data, and never proxies payment responses. Any
future "embedded" checkout UI must be re-scoped against PCI before design
work starts. The only sanctioned checkout surface is the WordPress-hosted
WooCommerce checkout where the configured gateway runs its own flow.

## 3. WooCommerce's two REST surfaces — which to use where

| Surface | Auth | Purpose | Headless fit |
|---|---|---|---|
| **Store API** `wc/store/v1` (`/cart`, `/checkout`) | **Cart token or nonce** (public, cookie-backed) | The supported customer-facing cart/checkout surface — "The Store API provides public Rest API endpoints for the development of customer-facing cart, checkout, and product functionality" (developer.woocommerce.com) | ✅ The sanctioned headless cart path. `POST /cart/add-item` accepts **variation** arrays — solves the 106 variant-exclusion gap without the URL form. |
| **Legacy REST** `wc/v3` (`orders`, `payment_gateways`, `products`) | Consumer key/secret or WP auth (admin) | Admin/integration surface (reports, gateways, order management) | ✅ Only server-side (bridge PHP) with stored credentials; **never** from the browser |
| Legacy `?add-to-cart=` URL form | None (public GET) | URL convenience form; fires the cart-add handler | ⚠️ Current handoff mechanism. State-changing GET, ids only (no variations), no error surface |

Key Store API facts (developer.woocommerce.com docs, Cart/Checkout pages):

- "All POST endpoints require a Nonce Token or a Cart Token and return the
  updated state of the full cart once complete."
- `POST /wc/store/v1/checkout` "includes a draft order (created from the
  current cart) and customer billing and shipping addresses … payment
  information … only persisted when the order gets updated via POST requests
  (right before payment processing)" — i.e. the Store API checkout itself
  drives the **configured gateway**, exactly like the blocks checkout.
- The Cart and Checkout **Blocks** use the Store API internally — it is the
  supported, future-proof path (codeatoz.com, "Best WooCommerce Store API
  Guide").

**Consequence:** the enhancement ladder is
(1) keep the URL handoff for simple products today, (2) add a bridge-side
Store API cart proxy (`POST /cart/items`, server-side nonce/token minting)
so variant lines, coupons and error reporting work, (3) optionally expose
`payment_method` selection — all without the app ever touching `wc/v3`.

## 4. Cart & checkout page customization — the supported levers

WooCommerce documents three customization tiers (woocommerce.com,
"Customizing the Cart and Checkout Pages", "Customizing checkout fields"):

1. **Blocks / Store Editing** (Cart and Checkout blocks) — the supported
   no-code tier; extensions that add markup "usually require integration
   work to support the Cart and Checkout Blocks".
2. **Hooks** — `woocommerce_checkout_fields` (field overrides),
   `woocommerce_get_return_url` (post-payment return destination),
   checkout action hooks. The supported code tier.
3. **Template overrides** (child-theme copies of `form-checkout.php` etc.) —
   last resort; "take care not to omit any action hooks … integral to the
   WooCommerce checkout page functionality" (woocommerce.com).

**Consequence:** "customizable checkout/cart pages" is a **WordPress-side**
capability. The storefront's job is to hand off correctly and come back
cleanly. The bridge should expose the two levers that matter to a headless
frontend: the **checkout URL** (already done, 106) and the **return URL**
(new — gap G5).

## 5. Redirect security — the checkoutUrl trust boundary

The checkoutUrl is a PATCHable absolute URL that the cart island turns into
a navigation. Industry guidance on redirect targets (OWASP cheat sheet via
nhimg.org; learn.snyk.io "Open redirect vulnerability"; redirect.live):

- "Only allow redirects to pages from an allow list" (Snyk).
- "The most reliable safeguard is to check redirect targets fundamentally
  against an explicit allowlist of known, permitted domains" (mironsoft.de).
- Where the destination must vary, "map an identifier to a server-side list"
  rather than trusting user input (OWASP).

**Current risk:** `ShopGlobalsController` validates only "absolute http(s)
URL" — an attacker with `manage_astro` (or a misconfigured site) can point
the storefront's checkout button at a phishing domain. Mitigation standard:
same-site validation plus an explicit allowlist filter — see the gap
analysis H1.

## 6. Session continuity across the storefront → checkout boundary

- Shopify runs checkout on a **subdomain of the same registrable domain**
  (`checkout.hydrogen.shop`) so its auth cookies cross. `astro.nvoos.cloud`
  vs `nvoos.pro` are **different registrable domains** — Woo's auth/cart
  cookies can never cross that boundary directly.
- Standard fixes in the wild: (a) accept the re-login at checkout (cheap,
  honest), (b) SSO token exchange — a short-lived signed token the backend
  exchanges for a logged-in session before landing on checkout, (c) run the
  storefront on a subdomain of the backend. Option (b) is the scalable
  standard for headless WP (see e.g. the WPGraphQL/WooGraphQL SSO patterns
  and Reddit r/Wordpress headless threads on payment flows).

**Consequence:** gap G4 is real but optional — Phase 3 ships the signed
handoff token only if the owner wants logged-in continuity; the default
remains "log in at checkout".

## 7. Post-payment return — closing the loop

The industry pattern is a configurable return-to-storefront URL after the
order completes (Shopify's "Headless Redirect After Checkout" forum thread;
WooCommerce's `woocommerce_get_return_url` filter). The storefront should
render the order-received/thanks state itself or link back to it.

## Sources

- developer.woocommerce.com — Store API, Cart API, Checkout API docs
- developer.woocommerce.com — REST API v3, Payment Gateway API
- woocommerce.com — "PCI-DSS compliance and WooCommerce",
  "WooCommerce site and data security FAQ", "Customizing the Cart and
  Checkout Pages", "Customizing checkout fields using actions and filters"
- patsatech.com — "PCI scope explained for ecommerce merchants"
- kioptrix.com — "PCI DSS Compliance for WooCommerce Stores"
- digitalapplied.com — "Headless Commerce: Next.js Storefront Dev Guide",
  "Headless Commerce 2026: API-First eCommerce Guide"
- shopify.dev / community.shopify.com — Hydrogen checkout-redirect patterns
- learn.snyk.io — "Open redirect vulnerability"
- mironsoft.de — "Reliably Preventing Open Redirect Vulnerabilities"
- redirect.live — "Secure Redirects" series
- woocustomdev.com, codeatoz.com — Store API headless guides
