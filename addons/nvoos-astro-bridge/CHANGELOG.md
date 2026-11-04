# Changelog — NV oOS Astro Bridge (nvoos-astro-bridge)

## 1.1.2 — 2026-11-04

### Fixed

- **Embedded checkout fatal on a failed cart bootstrap**: `CheckoutController` destructured `resolve_token()` into `list()` before checking for a `WP_Error`, so a failed Store API cart bootstrap crashed with `Cannot use object of type WP_Error as array` (production incident 2026-11-04) and the raw "critical error" HTML leaked through the BFF. All five checkout routes now capture-and-check first and return a clean typed 502.
- **Cart bootstrap 502 on Cloudways**: the Store API client looped back to the site's public URL over HTTPS; the platform WAF (Imunify360 / anti-bot) intercepted the server-side POST and answered without the `Cart-Token` header, so guest carts could never bootstrap. The client now dispatches the Store API in-process through Woo's REST server (`rest_do_request`) — no network hop, nothing to intercept.

## 1.1.1 — 2026-11-04

### Added

- **Embedded checkout proxy** (Proposal 110 Phase A): five `nvoos-commerce/v1` routes — `GET /checkout` (merged draft-order + cart doc), `POST /checkout/customer`, `POST /checkout/shipping-rate`, `POST /checkout/coupon`, `POST /checkout` (order + payment through the configured gateway) — all over the WooCommerce Store API with a bridge-minted `wc_store_api` nonce and the session `Cart-Token`. Per-session/IP rate limits, `expected_total` passthrough (409 drift preserves the refreshed cart), fail-closed gates (501/400) and `private, no-store` headers; the `/checkout` routes are excluded from the public B1 cache headers.
- **Shop globals extensions**: read-only `stripePublishableKey` (public key; empty when Stripe is inactive) and `guestCheckout` (Woo's guest-checkout switch).

## 1.1.0 — 2026-11-03

### Added

- **Pages & Posts in the Astro CMS `/manage` surface** (Proposal 107 Phase 2 — the editor experience). The content workbench lands in the admin SPA: a **Content** sidebar section (Pages, Posts) over the governed `content/pages` + `content/posts` collections; list views with the status-workflow filter (all/published/draft/pending/scheduled — the bridge's manager-only `?status=` listing); edit forms for title, excerpt, content, SEO title/description, scheduled publish, and the approval status select (draft/pending/publish/scheduled) — publishing stays gated by the bridge's `publish_posts` state machine, the SPA just surfaces it. Config-driven like every other collection: a new `select` field type (options + zod enum from one config) and a `statusFilter` collection flag power both lists.

### Fixed

- **Astro CMS admin page rendered a broken products table in wp-admin** (`admin.php?page=astro-cms`): body rows were text-only (`<tr>1846</tr>`, zero `<td>` elements) while the identical SPA on the storefront `/manage` surface rendered correctly. Root cause: the wp-admin build target rendered through an externals + JSX-shim pipeline (`jsx`/`jsxs` → `window.React.createElement` captured from the wp-admin script chain), which commits an inconsistent tree under WP 7.1.4 (the same surface that broke before — React error #31, 2026-10-29). The admin page now enqueues the **standalone bundle** (`build/standalone/astro-admin.{js,css}`), which bundles React and the `@wordpress/*` packages and is the same artifact `/manage` ships — verified live against the production backend.
- **wp-admin stylesheet was never enqueued**: `AdminPage` referenced a `build/index.css` that does not exist, so the SPA rendered unstyled inside wp-admin. The standalone enqueue now also ships the real stylesheet.

### Changed

- **Full-bleed admin shell**: the Astro CMS page overrides WP's `#wpcontent { padding-left: 20px }` (scoped to `body.toplevel_page_astro-cms`) so the app shell sits edge-to-edge, matching the `/manage` surface.

## 1.0.1 — 2026-10-30

- Rename funiq-bridge → nvoos-astro-bridge (Astro CMS rebrand; data-layer identifiers deliberately unchanged).
- Restore REST cookie auth on single-site HTTPS (AuthSupport session re-resolution) and mint `/auth/nonce` for the restored session.
