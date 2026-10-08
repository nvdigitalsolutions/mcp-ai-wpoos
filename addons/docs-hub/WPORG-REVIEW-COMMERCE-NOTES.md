# WordPress.org Review — Commerce Flow Notes

**Plugin:** NV oOS Docs Hub (`nvoos-docs-hub`)
**Prepared:** 2026-10-08
**Status:** Notes for the plugin review team ahead of the 0.5.2 upload
that introduces the commerce flow (ported from the Content Graph plugin's
`WPORG-REVIEW-COMMERCE-NOTES.md`).

> This file is correspondence with the Plugin Review Team and is excluded
> from the distribution ZIP via `.distignore` (`*.md` — docs-hub ships only
> `readme.txt`).

---

## What the commerce flow does

The plugin contains an **optional, opt-in purchase flow** for the
**NV oOS Complete** bundle — the full NV oOS plugin (base + Pro), which is a
**separate plugin sold off-directory** by NV Digital Unlocked LLC (the seller
of record, under license from NV Digital Solutions, the developer and IP
owner). The flow:

1. Shows a "Get NV oOS Complete" card on the plugin's own settings page,
   **after the settings Save button** (no site-wide notices, no automatic
   pop-ups). The card is hidden entirely once the Complete bundle is active.
2. On click, opens a modal with Stripe's Payment Element. **No Stripe keys
   ship in this plugin** — the vendor's own checkout server
   (`nvdigitalsolutions.com/wp-json/nvoos-checkout/v1/`) creates and verifies
   payments. Stripe.js is loaded from `js.stripe.com` only when the modal
   opens.
3. Requires explicit agreement to the vendor's **Terms of Service** and
   acknowledgement of the **30-day money-back Refund Policy** (consent
   timestamp recorded with the purchase), and a buyer email address
   (attached to the Stripe intent as `receipt_email` so Stripe emails the
   receipt; also recorded on the vendor-side license for refund matching).
4. After payment, the plugin shows the license key and a signed, expiring
   **download URL for manual installation** (the primary documented path:
   Plugins → Add New Plugin → Upload Plugin). A one-click automatic
   installer (WordPress' own `download_url()` + `Plugin_Upgrader` APIs) is
   offered as a convenience, and it refuses to proceed when another copy
   of the NV oOS base plugin already exists on the site (a second copy
   would fatally redeclare constants/classes).

**Payment is entirely optional. The free plugin is complete and fully
functional without it — nothing in the docs engine is paywalled or
feature-locked.**

## Guideline mapping

| # | Guideline | How this plugin complies |
|---|---|---|
| 1 | GPL-compatible licensing | GPLv3 or later; the paid bundle's GPL components remain GPL (the payment covers the license key, updates, support, and distribution) |
| 5 | No trialware / paywalled features | The free plugin has no locked functionality; the paid product is a separate plugin distributed outside the directory |
| 6 | SaaS/services documented with ToS links | The vendor checkout service is disclosed in `readme.txt == External Services ==` with Terms and Refund-policy links |
| 7 | No phone-home; data collection documented | All remote calls are user-initiated (modal open, payment, install); nothing runs in the background; the data-sent list is in `readme.txt` |
| 8 | No obfuscation; no remote executable code | All code is human-readable; no keys ship; the install step uses core WordPress APIs and runs only after an explicit purchase; manual install is the primary documented path |
| 11 | Contextual notices only | The upsell is a settings-page card, not a site-wide notice |
| 18 | No annoying upsells | No persistent nags, no lock-outs; checkout opens only on click |

## Data sent to third parties (all opt-in, all documented in readme.txt)

- **Stripe** (`js.stripe.com` / Stripe API via the vendor server): payment
  card details (entered in Stripe's own iframe — the plugin never sees
  them), buyer email, buyer country, EU billing address (VAT records), and
  receipt delivery.
- **Vendor checkout server** (operated by NV Digital Solutions on behalf of
  NV Digital Unlocked LLC, the seller of record): product name, site URL,
  Stripe payment ID, buyer email, buyer country, Terms consent timestamp.
- **GitHub** (`github.com`): only an HTTPS download of the purchased ZIP via
  a vendor-signed URL (or the public release-asset fallback); no data sent.

## Port provenance

The commerce stack is a faithful port of the Content Graph plugin's
commerce flow (`plugins/nvoos-content-graph/src/Commerce/`,
`src/Rest/CommerceController.php`, `assets/js/content-graph-commerce.js`),
which the review team has already seen, with docs-hub naming
(`nvoos-docs-hub` text domain, `NV_oOS_Docs_Hub_Checkout*` classes,
`nvoos_docs_hub_checkout_*` filters, `nvoos-dh-*` asset classes). The
legacy AI-addon paths were dropped — docs-hub never sold that artifact.

## Review-team questions, pre-answered

- **"Why does a free plugin install another plugin?"** — Only after an
  explicit payment on the vendor's server; the buyer can always take the
  manual download path instead, and the installer refuses to overwrite or
  duplicate an existing NV oOS installation.
- **"Where is the paid plugin distributed?"** — Outside the directory, from
  NV Digital Solutions' own server (signed, expiring, download-capped URLs
  proxied from the project's GitHub releases).
- **"Is the free plugin a demo?"** — No. The documentation engine, local
  uploads source, search, and remote-import service are complete without
  any purchase.
