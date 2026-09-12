# NV oOS Checkout API — Changelog

## 0.1.2 — 2026-09-12

### Changed

- **Default price $34.99** — `DEFAULT_PRICE_CENTS` moves from 4900 to **3499** for fresh vendor installs, mirroring the content-graph client's new fallback default. The `/session` `amount` stays authoritative for what the buyer is actually charged, and the client now renders that amount in the purchase modal instead of its local default (#6603)

### Fixed

- **Card statement descriptor rejected by Stripe** — Stripe no longer accepts the full `statement_descriptor` parameter for card charges created with `automatic_payment_methods`, so every live PaymentIntent creation failed with a Stripe 424 rejection. `/session` now sends the stored value as `statement_descriptor_suffix` (appended to the account's statement descriptor prefix; 2–22 characters, at least one letter — invalid values drop out so Stripe's default applies), and the settings sanitizer enforces the suffix rules. Covered in `tests/test-rest-checkout.php`

- **Create product does nothing after switching Stripe accounts** — the admin action was idempotent on the stored `product_id`/`price_id`, so after changing the secret key the old account's IDs still matched and both creations were skipped (the new account never received a product/price, while a success notice claimed they were created). The action now verifies the stored IDs against the current key first: a 404 `resource_missing` (the signature of a switched account) clears the stale IDs and recreates both objects in the current account, a price attached to a different product is recreated too, and any other failure (invalid key, transport) aborts without touching the stored IDs. Distinct admin notices (`recreated` success, `verify` error) and an updated button hint explain the behavior. Covered by `ensure_product_and_price()` tests in `tests/test-admin-page.php` plus `retrieve_product()`/`retrieve_price()` client tests

## 0.1.1 — 2026-09-11

### New

- **Public `GET /health` probe** — a cheap, unauthenticated status endpoint (no Stripe call, no rate-limit token, no writes) so customer sites can verify the checkout API is reachable before starting a payment session; returns `status`, `service`, `version`, `configured` (whether Stripe keys are set), and `server_time`. Backed by `test_health_*` coverage in `tests/test-rest-checkout.php`
- **Admin endpoint self-check** — a new "REST endpoints" section on the storefront admin lists every checkout route with a live registered/missing marker (from the REST server's route table, refreshed on every page load) and a "Check endpoints" action that fetches `GET /health` over loopback HTTP from the server itself — the same call customer sites make — reporting HTTP status and latency; nonce-protected admin-post handler with `route_statuses()` / `check_endpoints()` helpers covered by `tests/test-admin-page.php`

- **Buyer country + EU billing address (VAT records)** — the purchase modal gains a country selector; buyers choosing an EU member state must provide a billing address (street + city, postal optional), which is attached to the payment as Stripe `billing_details` and recorded on the license (`buyer_country` column, fill-once) via the optional `buyer_country` verify param
- **Stripe statement descriptor + product/price metadata** — new storefront settings (`statement_descriptor`, `product_name`, `product_id`, `price_id`); `/session` attaches the descriptor and the Product/Price IDs to each PaymentIntent, and an admin action creates the `service`-type Product + one-time Price in Stripe (idempotent)
- **Checkout consent + legal links** — the `/session` response now carries `terms_url` and `refund_policy_url` (configurable in the storefront admin; defaults point at the vendor's published Terms of Service and Refund Policy), and `/verify` accepts an optional `terms_agreed_at` consent timestamp that is recorded on the license row (filling an empty value, never overwriting) as proof the buyer agreed to the Terms at purchase
- **Buyer email on licenses** — `/verify` accepts an optional `buyer_email`; the intent's `receipt_email` (set by Stripe from the client's `confirmParams.receipt_email`) is authoritative, and the stored value is recorded in a new `buyer_email` license column (fill-once, never overwritten), including webhook-issued licenses
- **Consent + email + country columns** — the licenses admin table shows when each buyer agreed to the Terms, their receipt/refund email, and their country
- **Stripe connection test** — a "Test connection" action on the storefront admin reads the account balance with the stored secret key (read-only) and reports live vs test mode plus the available balance

### Changed

- Default product is now the **NV oOS Complete** bundle: `nvoos-oos-complete` added to the accepted products (legacy `nvoos-content-graph-ai` stays accepted), the default ZIP source and cache/download filenames target `nvdigital-open-operator-system-oos-complete-{VERSION}.zip` from the monorepo GitHub releases (`nvdigital-oos-v*.*.*` tags), and the default sold version is `1.1.74`
- Download streaming is chunked (1 MB reads) instead of buffering the whole ZIP in memory — the Complete bundle can be tens of MB

### Fixed

- **Stripe boolean serialization** — request bodies are form-encoded, and PHP's serializer turned booleans into `1`/empty strings, which Stripe rejects (`Invalid boolean: 1` on `automatic_payment_methods[enabled]` — every live PaymentIntent creation failed, surfacing as the client's 502 and the modal's fallback redirect). The Stripe client now stringifies booleans to literal `true`/`false` recursively before sending; regression coverage in `tests/test-stripe-client.php`

- **Stripe error mapping** — Stripe 4xx rejections now surface as status 424 with Stripe's own message (bad key, invalid params, account restrictions) instead of the blanket 502, so customer sites show the real rejection reason in the purchase modal instead of treating checkout as unreachable; transport failures and Stripe 5xx stay 502. Covered in `tests/test-stripe-client.php` and `tests/test-rest-checkout.php`

- Storefront admin settings form no longer nests the Stripe product/price form inside the `options.php` settings form — browsers drop inner `<form>` tags, so the inner `action` input overrode the Settings API `action=update` and every save landed on a blank `options.php`; the action forms are now standalone sections, and the settings form round-trips `product_id`/`price_id` as hidden fields so a save can no longer wipe them

### New

- Stripe checkout endpoints: `POST /session` (PaymentIntent creation) and `POST /verify` (server-side verification + license issuance) under `/wp-json/nvoos-checkout/v1/`
- `payment_intent.succeeded` webhook handling issues the license server-side, so a buyer whose browser flow is interrupted after paying still gets their license (picked up by `/verify` on their return)
- License store in a custom table (`nvoos_checkout_licenses`) — one row per issued license, idempotent per payment intent, active/revoked lifecycle
- Signed, expiring download URLs (HMAC-SHA256, constant-time verification) serving cached addon ZIPs per version
- Stripe webhook receiver (`POST /webhooks/stripe`) with signature verification and idempotent processing; refunds/disputes revoke the matching license
- Per-IP rate limiting on the public endpoints
- Storefront admin page: Stripe keys, price, currency, test mode, addon version, ZIP source, recent-license table with revoke action
- Storefront admin fields for the Terms of Service and Refund Policy URLs shown at checkout

### Security

- Stripe secret key and webhook secret are encrypted at rest (AES-256-CBC keyed from AUTH_KEY + SECURE_AUTH_KEY) and never leave the vendor server; only the publishable key is returned by `/session`
- The storefront admin never re-renders the stored secret/webhook keys — the password fields are masked with a placeholder (saving blank keeps the stored credential, and legacy plaintext values are upgraded to encrypted storage on save); IV generation now uses `random_bytes()`
- Payment verification (status, amount, currency, product, site binding) happens server-side against Stripe before any license is issued — both in `/verify` and in the webhook path
- Webhook signature verification mirrors Stripe's reference algorithm (tolerance window, constant-time compare, multiple v1 values); events are processed idempotently
- Download links are signed (HMAC-SHA256), expiring, and capped at 10 downloads per link
