---
type: Skill
name: mcp-ai-wpoos-checkout-integration
description: "Operational guide for integrating the NV oOS Complete purchase flow (vendor checkout API client) into standalone WordPress plugins — docs-hub 0.5.2 (added in PR #6963) and nvoos-content-graph 1.0.9 (live). Covers the vendor API contract (session/verify/health, nvoos-oos-complete product, 424/502 error passthrough), the five-class client stack (config, vendor client, license store, installer, REST controller), the purchase-modal JS/CSS port recipe (class-prefix rename, keep-the-JS-identical trick), wp.org commerce compliance (External Services disclosure, WPORG-REVIEW-COMMERCE-NOTES.md, js.stripe.com service exception), and the checkout test conventions (direct controller calls, pi_ fixture rules, zero-HTTP assertions). Use when adding the Get NV oOS Complete upsell/checkout to a plugin, porting the commerce stack to a new standalone plugin, extending the purchase flow, or debugging a checkout integration."
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.99"
  plugin-version-tested: "1.1.99"
  last-updated: "2026-10-08"
---

# NV oOS Checkout Integration — Client-Side Purchase Flow Guide

Operational playbook for the **NV oOS Complete** purchase flow that standalone
plugins embed on their settings pages. Distilled from the content-graph
commerce stack (1.0.4 → 1.0.9, live on wp.org) and the docs-hub port (0.5.2,
PR #6963). The vendor side is the proprietary `addons/checkout-api` addon —
clients never touch Stripe keys; all payment work happens on the vendor
server.

## When to use this skill

- "Add the Get NV oOS Complete upsell to <plugin>" / "integrate the checkout API"
- Porting the commerce stack into a new standalone plugin (docs-hub precedent)
- Extending the purchase modal (new links, new data collected, new i18n)
- Debugging checkout issues (424/502, throttle lockout, install conflicts)
- Reviewing checkout code for a wp.org submission

For wp.org readiness around the checkout surface (PCP, disclosures, review
notes) work with `.agents/skills/mcp-ai-wpoos-wporg-submission/SKILL.md`;
for suite-repair conventions use
`.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md` (pattern 68).

## Vendor API contract (`nvoos-checkout/v1`)

Clients POST to the vendor base URL (default
`https://nvdigitalsolutions.com/wp-json/nvoos-checkout/v1`):

- `POST /session` — body `{ product, site_url, addon_version }`. Returns
  `{ client_secret, publishable_key, amount, currency, test_mode, terms_url,
  refund_policy_url }`. The product id is **`nvoos-oos-complete`** (the vendor
  also accepts legacy `nvoos-content-graph-ai`).
- `POST /verify` — body adds `payment_intent`, `terms_agreed_at`,
  `buyer_email`, `buyer_country`. Returns `{ license_key, download_url,
  addon_version, amount, currency }`. The vendor re-verifies the Stripe
  intent server-side (status, amount, product + site binding from the intent
  metadata — a session created for another site cannot be replayed).
- `GET /health` — public probe `{ status, service, version, configured,
  server_time }`; never consumes rate-limit tokens (diagnostics only).

Error contract: vendor Stripe 4xx → **424** with Stripe's message (client
shows it in-modal, never redirects); transport/Stripe 5xx → **502** (client
keeps the release-page fallback). Two independent throttles exist: client
transients (5 session / 15 verify attempts per 10 min per user) and vendor
per-IP limits — when debugging "Too many checkout attempts", check both.

## Where the clients live

| Plugin | Version | Notes |
|---|---|---|
| `plugins/nvoos-content-graph` | 1.0.9 (live on wp.org) | Origin of the stack; namespaced classes + slash filters (`nvoos_content_graph/payments/*`); legacy AI-addon paths kept for pre-1.0.6 purchases |
| `addons/docs-hub` | 0.5.2 (PR #6963) | Faithful port with docs-hub naming: procedural `NV_oOS_Docs_Hub_Checkout*` classes + snake_case filters (`nvoos_docs_hub_checkout_*`); legacy-addon paths dropped |

The next standalone plugin that wants the flow (design-system pipeline) should
port from the docs-hub variant (it is the trimmed, wp.org-clean shape).

## The client stack (5 PHP classes + 2 assets)

Per plugin, under `includes/checkout/` (docs-hub) or `src/Commerce/` +
`src/Rest/CommerceController.php` (content-graph):

1. **Config** (Payments / `NV_oOS_Docs_Hub_Checkout`) — vendor URL, price
   (display-only, `max(50, …)` floor), addon/base version pins, zip/fallback
   URLs, terms/refund/roadmap/changelog URLs, support email, EU-27 list,
   `purchase_payload()`. Every value filterable; keep the browser-visible
   price labelled display-only — the vendor amount is authoritative.
2. **Vendor client** (`NV_oOS_Docs_Hub_Checkout_Client`) — thin
   `wp_remote_post`/`get` wrapper: session, verify, health; 4xx status
   passthrough with the vendor's `message`; 5xx → 502.
3. **License store** — one autoload-free option (`nvoos_docs_hub_checkout_license`);
   never stores card/secret data.
4. **Installer** — `download_url()` + `Plugin_Upgrader` + `activate_plugin()`
   of the Complete bundle (`nvdigital-open-operator-system-oos-complete/`).
   MUST refuse when another copy of NV oOS exists (duplicate constants/
   classes fatal): fast-path `defined( 'WP_MCP_AI_VERSION' )` + a
   `KNOWN_BASE_PLUGINS` disk scan, with a `…_skip_base_plugin_detection`
   filter seam for tests. The bundle folder/plugin basenames are constant.
5. **REST controller** — `POST /payments/session`, `POST /payments/verify`,
   `GET /payments/health` under the plugin's own namespace, `manage_options`
   only, per-user transient throttles (separate session/verify buckets),
   **already-licensed short-circuit first** (licensed + bundle active →
   return the recorded license with zero vendor calls — a second charge must
   be impossible from the purchase screen). Verify records the license, then
   installs; install failures return the signed ZIP URL + `licensed: true`.
   Only https download URLs are accepted (`sanitize_zip_url`).

Assets: the purchase-modal JS (≈1,300 lines, vanilla) + its CSS. Stripe.js is
injected on demand (`https://js.stripe.com/v3/`) when the modal opens — never
enqueued with the settings page, so merely visiting settings contacts no
third party. Localize a config object (`NVOOS_DH_CHECKOUT` /
`nvoosContentGraphCommerce`) with rest_url, nonce, price_label, legal URLs,
buyer_email (current user's own address), eu_countries, and the full i18n map.

### Port recipe (proven on the docs-hub pass)

1. Copy the JS and CSS, then `sed`-rename identifiers only:
   `nvoos-cg- → nvoos-dh-`, config object name, sessionStorage key
   (`nvoosCgPendingIntent → nvoosDhPendingIntent`), and the upsell button
   class (`nvoos-content-graph-buy-ai → nvoos-docs-hub-buy-complete`).
   Verify zero occurrences of the old prefix remain in both files.
2. Keep the JS body **identical** to the origin except prefixes, so future
   fixes re-sync mechanically. Plugin-specific i18n strings ship from the
   PHP localize block; strings for features the target plugin never sold
   (e.g. the legacy AI-addon success step) are localized as `''` — the JS
   already skips empty step items.
3. CSS extraction: the modal CSS lives inside content-graph's shared
   `content-graph-admin.css` (a clearly delimited "Addon purchase modal"
   section, ~L406–890). Extract + rename; some `nvoos-cg-*` classes are
   intentionally unstyled (they ride WP core button/link styles) — a
   JS-vs-CSS class diff will list them; that is parity, not a gap.
4. Upsell card: render after the settings form's `submit_button()`, gated on
   the bundle NOT being active. Copy: "Unlock AI-powered features / Install
   the NV oOS Complete bundle to enable semantic extraction, AI chat,
   embeddings, and agent memory…" — adapt the last clause to the plugin's
   domain ("documentation site" for docs-hub, "knowledge graph" for CG).
5. New strings go through the plugin's own text domain and the POT must be
   regenerated in the same PR: one-off `wordpress:cli` container,
   `php -d memory_limit=1G $(which wp) i18n make-pot …` (the default 128M cap
   fatals on the dist bundle). Verify no strings were lost:
   `comm -23 <(git show HEAD:<pot> | grep '^msgid "' | sort) <(grep '^msgid "' <pot> | sort)`.

## wp.org commerce compliance (same PR, every time)

- **No Stripe keys ship in the plugin.** The publishable key arrives
  per-session from the vendor; the card form is Stripe's own iframe.
- **`readme.txt == External Services ==`** must gain a checkout block listing
  every host + data sent: the vendor checkout server (product, site URL,
  intent id, buyer email/country, consent timestamp), `js.stripe.com`
  (browser-side, on-demand), and the ZIP-download host (`github.com` fallback
  / vendor signed URL). Wording: user-initiated only, opens only on click,
  nothing in the background, the free plugin is complete without it.
- **`WPORG-REVIEW-COMMERCE-NOTES.md`** at the plugin root, excluded from the
  ZIP by the existing `*.md` distignore rule (docs-hub) or `WPORG-REVIEW-*.md`
  (content-graph). Pre-answer: why a free plugin installs another plugin
  (post-payment only, manual download is primary), where the paid plugin is
  distributed (off-directory, signed expiring URLs), and that the free plugin
  is not a demo.
- **js.stripe.com CDN load** is the one apparent Guideline-8 tension —
  payment elements are an accepted service exception with the content-graph
  precedent live on the directory; disclose it and be ready to cite it.
- PCP gate stays clean (0 blocking errors) — the checkout adds no findings.

## Test conventions (full conventions in pattern 68)

- Call the controller methods **directly** (`create_session( new
  WP_REST_Request() )`) so `WP_Error`s come back raw; `$server->dispatch()`
  wraps errors in `WP_REST_Response` — use dispatch only for permission
  (assert `get_status() === 403`) and arg-validation (assert
  `get_data()['code'] === 'rest_invalid_param'`) tests.
- Mock the vendor with a `pre_http_request` filter that routes by URL
  substring (`/session`, `/verify`, `/health`) and counts calls; assert
  **zero HTTP** on already-licensed paths and throttle blocks.
- Stripe intent fixtures must be **alphanumeric-only**
  (`pi_1234567890abcdef`) — `pi_test_…` contains `_` and fails the
  `^pi_[A-Za-z0-9]{8,}$` REST validator, silently 400ing before the callback.
- Already-licensed state: save the license option +
  `update_option( 'active_plugins', array( BUNDLE_BASENAME ) )`.
- Install-conflict test: `define( 'WP_MCP_AI_VERSION', … )` (guarded) trips
  the installer's base-plugin guard so verify records the license and returns
  the manual-download error without any download attempt.
- Upsell render tests: `ob_start()` around `render_page()`
  (`require_once ABSPATH . 'wp-admin/includes/template.php'` for
  `submit_button()`), assert the button class/heading appears when the
  bundle is inactive and disappears when it is active.

## Gotchas collected on the docs-hub pass

- readme changelog edits can swallow the previous version's heading (the
  `= 0.5.1 =` line) and merge its bullets into the new section — re-verify
  the whole `== Changelog ==` structure after editing.
- Keep `DEFAULT_ADDON_VERSION` / `DEFAULT_BASE_VERSION` pins in lockstep with
  releases; they are fallbacks only — the vendor's `/verify` payload is
  authoritative for the download.
- The `already_licensed` semantics differ per plugin: docs-hub has a single
  artifact (bundle active), content-graph keeps the legacy-addon branch.
  Don't copy CG's `bundle_active: false` messaging into a plugin that never
  sold the addon.
- **The localize object name and the JS global must match exactly.** The
  docs-hub port renamed the config to `NVOOS_DH_CHECKOUT` on the PHP side
  (matching the plugin's own `NVOOS_DH_SETTINGS_PAGE` convention) but left
  the JS reading `window.nvoosDocsHubCheckout` — `config` fell back to
  `{}`, so every purchase call hit `/wp-admin/undefined/payments/session`
  (404) and checkout was silently dead. After any rename, grep both sides
  for the old name and keep the docs-hub regression test
  (`test_checkout_localize_name_matches_js`) — it asserts the localize
  payload, the JS global, and the absence of the old name.

## References

- Vendor addon (server side): `addons/checkout-api/` (REST controller holds
  the accepted product list and the license-issue gates)
- wp.org readiness: `.agents/skills/mcp-ai-wpoos-wporg-submission/SKILL.md`
- Test conventions: `.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md` (pattern 68)
- Origin implementation: `plugins/nvoos-content-graph/src/Commerce/` +
  `assets/js/content-graph-commerce.js` + `WPORG-REVIEW-COMMERCE-NOTES.md`
- Port reference: `addons/docs-hub/includes/checkout/` +
  `assets/admin/docs-hub-checkout.{js,css}` + `tests/test-checkout.php`
