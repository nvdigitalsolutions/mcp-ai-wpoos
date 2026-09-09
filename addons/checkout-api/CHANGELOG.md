# NV oOS Checkout API — Changelog

## 0.1.0 — Unreleased

### Changed

- Default product is now the **NV oOS Complete** bundle: `nvoos-oos-complete` added to the accepted products (legacy `nvoos-content-graph-ai` stays accepted), the default ZIP source and cache/download filenames target `nvdigital-open-operator-system-oos-complete-{VERSION}.zip` from the monorepo GitHub releases (`v*.*.*` tags), and the default sold version is `1.1.75`
- Download streaming is chunked (1 MB reads) instead of buffering the whole ZIP in memory — the Complete bundle can be tens of MB

### New

- Stripe checkout endpoints: `POST /session` (PaymentIntent creation) and `POST /verify` (server-side verification + license issuance) under `/wp-json/nvoos-checkout/v1/`
- `payment_intent.succeeded` webhook handling issues the license server-side, so a buyer whose browser flow is interrupted after paying still gets their license (picked up by `/verify` on their return)
- License store in a custom table (`nvoos_checkout_licenses`) — one row per issued license, idempotent per payment intent, active/revoked lifecycle
- Signed, expiring download URLs (HMAC-SHA256, constant-time verification) serving cached addon ZIPs per version
- Stripe webhook receiver (`POST /webhooks/stripe`) with signature verification and idempotent processing; refunds/disputes revoke the matching license
- Per-IP rate limiting on the public endpoints
- Storefront admin page: Stripe keys, price, currency, test mode, addon version, ZIP source, recent-license table with revoke action

### Security

- Stripe secret key and webhook secret are encrypted at rest (AES-256-CBC keyed from AUTH_KEY + SECURE_AUTH_KEY) and never leave the vendor server; only the publishable key is returned by `/session`
- Payment verification (status, amount, currency, product, site binding) happens server-side against Stripe before any license is issued — both in `/verify` and in the webhook path
- Webhook signature verification mirrors Stripe's reference algorithm (tolerance window, constant-time compare, multiple v1 values); events are processed idempotently
- Download links are signed (HMAC-SHA256), expiring, and capped at 10 downloads per link
