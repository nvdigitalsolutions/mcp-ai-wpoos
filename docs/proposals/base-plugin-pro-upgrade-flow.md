# Base Plugin → Pro Upgrade Purchase Flow — Implementation Plan

**Status:** Proposed
**Related:** PR #6507 (checkout consent, legal docs, buyer email — merged)
**Owner decision needed:** open questions in the final section.

---

## Problem

Base-plugin users have **no in-plugin way to buy Pro**. Today's "upgrade"
surface is:

- `WP_MCP_AI_Pro_License` (`includes/admin/class-wp-mcp-ai-pro-license.php`) —
  a legacy **license-key form** that activates externally purchased keys
  against a separate license server (`/activate`, `/check`, `/deactivate`);
  options: `wp_mcp_ai_pro_license_key|status|plan|expires`.
- The `wp_mcp_ai_pro_upgrade_url` filter, defaulting to **off-site** URLs
  (`nvdigitalsolutions.com/pricing`, `/renew`).
- A Pro Dashboard notice pointing at that URL.

There is no Stripe checkout, no purchase modal, and no Pro installer in the
base plugin. Meanwhile the vendor checkout API (`addons/checkout-api/`) and
the full client stack (modal, consent, buyer email, license record, manual-
first installer) exist and are proven in the **content-graph plugin**, which
sells the **NV oOS Complete** bundle — but its installer refuses to run when
the base plugin is already installed (conflict guard), so base users cannot
use that path to upgrade.

## Goal

Give base-plugin users a compliant, in-plugin purchase flow for the
**Pro add-on** (`nvdigital-oos-pro`), reusing the vendor checkout API and the
patterns shipped in PR #6507:

1. Purchase Pro via the Stripe modal (ToS consent + buyer email, refund
   guarantee surfaced).
2. License recorded into the **existing** Pro License options.
3. Pro add-on installed + activated automatically, with **manual ZIP
   download as the primary documented path** (consistent with the
   wp.org-compliance posture).
4. Base plugin readme/`SUBMISSION.md` disclosures updated (the base plugin
   will no longer be "commerce-free").

## Non-goals

- No license-gating of Pro features in this phase (see Open questions).
- No selling on wp.org; no change to the Complete-bundle flow.
- No change to the content-graph plugin.

---

## Design

### 1. Vendor checkout API — second product

`addons/checkout-api/` today sells one product (`nvoos-oos-complete`, legacy
`nvoos-content-graph-ai`). Add:

- `nvoos-oos-pro` to `NVOOS_Checkout_API_Rest_Controller::PRODUCTS`.
- **Per-product configuration.** Current settings are single-product
  (`price_cents`, `currency`, `addon_version`, `zip_source`). Generalize to a
  product map — `nvoos-oos-complete` and `nvoos-oos-pro` entries with their
  own price/version/ZIP-source pattern; keep the existing keys as the
  Complete defaults for back-compat; add a products table to the storefront
  admin page.
- **Per-product ZIP source.** Pro ships as
  `nvdigital-oos-pro-{VERSION}.zip` (built by `bin/build-pro-standalone.sh` →
  `build/`), while Complete uses
  `nvdigital-open-operator-system-oos-complete-{VERSION}.zip`. The download
  server's per-version cache must key on product + version, not version
  alone.
- `/session` and `/verify` are already product-aware (metadata `product`,
  validation against `PRODUCTS`, per-intent product binding). The amount
  check in `/verify` must compare against the **purchased product's** price.
- License rows already carry `product`; add a per-product filter to the
  admin license table (nice-to-have).

### 2. Base plugin — commerce client (port from content-graph)

New folder `includes/commerce/` (mirrors `plugins/nvoos-content-graph/src/Commerce/`):

| File | Responsibility |
|---|---|
| `class-wp-mcp-ai-commerce-vendor.php` | Thin `wp_remote_post()` client for the vendor `/session` + `/verify` endpoints (mirror of `NvoosContentGraph\Commerce\Vendor`) |
| `class-wp-mcp-ai-commerce-payments.php` | Config: vendor URL, product id `nvoos-oos-pro`, price label, Pro addon version, fallback product URL — filterable under `wp_mcp_ai_pro_upgrade_*` (consistent with the existing `wp_mcp_ai_pro_upgrade_url` filter) |
| `class-wp-mcp-ai-commerce-rest.php` | Admin-only `POST /mcp-ai/v1/payments/session` and `/payments/verify` under `WP_MCP_AI_REST::REST_NAMESPACE`; `manage_options` capability, `wp_rest` nonce, per-user throttle (mirror of `CommerceController`) |
| `class-wp-mcp-ai-commerce-license.php` | Writes the vendor-issued license into the **existing** options (`wp_mcp_ai_pro_license_key`, `status=valid`, `plan`, `expires`); records buyer email + consent timestamp (`wp_mcp_ai_pro_license_email`, `wp_mcp_ai_pro_license_consent_at`). Does **not** change `WP_MCP_AI_Pro_License::is_pro_active()` (constant-based) |
| `class-wp-mcp-ai-commerce-installer.php` | Installs + activates the Pro standalone ZIP via `download_url()` + `Plugin_Upgrader`. Guards: skip (record-only) when `WP_MCP_AI_PRO_VERSION` is already defined (bundled full build or existing standalone Pro); require `install_plugins`/`activate_plugins` and fall back to the manual download when missing |

### 3. UI

- **Upgrade card** on the NV oOS dashboard/settings (settings-page-scoped,
  dismissible — wp.org guideline 11) and a **Purchase Pro section** on the
  existing Pro License screen. When the vendor endpoint is unreachable,
  fall back to the `wp_mcp_ai_pro_upgrade_url` target (the current behavior).
- **Purchase modal** — port `plugins/nvoos-content-graph/assets/js/
  content-graph-commerce.js` to `assets/js/pro-upgrade.js`: Stripe Payment
  Element loaded on demand, required ToS/refund consent checkbox, required
  buyer email (prefilled, `receipt_email`), gated Pay button, and the
  **manual ZIP download as the primary path** in the success state.
- i18n strings in the `mcp-ai-wpoos` text domain.

### 4. Compliance & documentation

The base plugin currently ships **no commerce code** — that claim in
`SUBMISSION.md` becomes false, so:

- **`readme.txt` (base):** new `== External Services ==` entries (Stripe
  `js.stripe.com`, vendor checkout server, GitHub-served Pro ZIP) with the
  exact data-sent list (product name, site URL, Stripe payment ID, buyer
  email, ToS consent timestamp), a payment-optional FAQ, and
  manual-install-first wording.
- **`SUBMISSION.md`:** rewrite the Commercial model section to cover the
  base-plugin flow; add base-plugin reviewer notes (mirror of the
  content-graph `WPORG-REVIEW-COMMERCE-NOTES.md`, excluded from the ZIP).
- **Privacy Notice** update (local purchase record now includes the buyer
  email).
- Reuse the vendor's already-configured ToS/refund URLs (settings defaults
  from PR #6507).

### 5. Tests

- PHPUnit: commerce REST endpoints (session/verify, throttle, capability
  gate), license integration (existing options written, `is_pro_active`
  unchanged), installer guards (bundled-Pro skip, missing caps → manual
  path, base-not-active error), vendor client error mapping.
- JS: mirror of `scripts/verify-commerce-fallback.js` for the new modal.
- phpcs (base standard) + Docker validation on WP 6.9 and WP 7.1.

### 6. Rollout

- Vendor-side: publish Pro ZIPs per release (build script → GitHub releases
  or private mirror), configure the `nvoos-oos-pro` product row (price,
  version, ZIP source), confirm Stripe receipt delivery.
- Changelogs, docs catch-up, skill bookkeeping if a new skill is added.

---

## Open questions (owner decisions needed)

1. **License gating** — Pro features are currently gated on the
   `WP_MCP_AI_PRO_VERSION` constant, not on a valid license. Decide: soft
   gate (admin nag only) first, hard gate (tools disabled) later, or keep
   ungated. Recommendation: ship soft-gate first; hard-gate is a separate
   PR with its own migration concerns.
2. **Product positioning/pricing** — Pro add-on (for existing base users)
   vs Complete bundle (base + Pro for new installs). Confirm prices and
   whether buying Complete remains the upsell for content-graph users.
3. **Renewals** — the vendor checkout is one-time today; the legacy license
   server has expiry concepts. Decide the renewal/subscription story before
   wiring `expires` into the new flow.
4. **Full builds** — GitHub full ZIPs bundle `addons/pro/`. Confirm the
   purchase flow should record-only (no install) when Pro is bundled.

## Acceptance criteria

- [ ] Vendor: `nvoos-oos-pro` purchasable end-to-end in test mode with
      per-product price/version/ZIP source; `/verify` validates against the
      purchased product's price.
- [ ] Base plugin: purchase modal with consent + email; Pay gated; manual
      download always offered; license lands in the existing Pro options.
- [ ] Installer: Pro ZIP installs + activates on a stock base install;
      skips cleanly when Pro is bundled; manual path works without
      `install_plugins`.
- [ ] Base `readme.txt` + `SUBMISSION.md` disclose the new data flows;
      wp.org guideline mapping documented for reviewers.
- [ ] PHPUnit + phpcs green on both WP versions.

## Suggested PR breakdown

1. **Vendor per-product support** (settings, products map, price check,
   download cache, tests).
2. **Base commerce client + REST** (Vendor/Payments/Rest/License, tests).
3. **UI** (upgrade card, modal JS, license-screen purchase section, i18n).
4. **Installer + guards** (tests).
5. **Compliance/docs** (readme, SUBMISSION, reviewer notes, privacy).
