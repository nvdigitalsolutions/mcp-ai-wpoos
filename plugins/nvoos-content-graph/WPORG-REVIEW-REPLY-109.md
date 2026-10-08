# WordPress.org Review Reply — 1.0.9 Upload (draft)

**Plugin:** NV oOS Content Graph (`nvoos-content-graph`)
**Prepared:** 2026-10-08
**Version uploaded:** 1.0.9

**How to send:** Reply to the original review email thread (do not create a new
email), after uploading v1.0.9 via the "Add your plugin" page while logged in
as `vsamtani`. Replace `[REVIEW ID]` with the ticket/review identifier from
the latest reviewer email.

**Distribution:** This file is correspondence with the Plugin Review Team and
is excluded from the distribution ZIP via `.distignore` (`WPORG-REVIEW-*.md`).

---

Hi,

Thank you for the continued review. Version 1.0.9 has been uploaded
(review: [REVIEW ID]). Changes in this release:

- The purchase modal now shows a loading indicator while Stripe's
  Payment Element mounts. Stripe.js is loaded on demand when the modal
  opens, and on slower sites the payment area previously rendered empty
  until it arrived — the modal now shows a spinner for that wait.
- A "Get the free NV oOS base version — no payment required" link was
  added at the bottom of the checkout, so the free option is visible
  right next to the paid bundle. The link opens the project's GitHub
  release page in the browser; the plugin sends no data and contacts no
  server to show it. This is disclosed in the readme's External
  services section.
- A short note in the purchase modal states that NV oOS is still in
  active development and testing and restates the 30-day money-back
  guarantee (wording consistent with the Refund Policy linked in the
  consent checkbox). No dates or urgency tactics are used.
- Remote source drivers (Generic REST, Wikidata, SPARQL, RSS/Sitemap)
  received configuration and lookup fixes — including the
  double-encoded search terms, the missing edge-mapping fields, and
  inline "Test Connection" results.
- The recurring rebuild cron is now scheduled on `init` instead of
  `plugins_loaded`. Previously, when no rebuild event existed,
  `wp_schedule_event()` could consult the `cron_schedules` filter
  before translations load on WP 6.7+, triggering the "translation
  loading triggered too early" notice when WooCommerce is active.
- The build script now excludes the `blueprints/` development folder
  (it was already excluded by the CI packaging and `.distignore`).

The commerce flow itself is unchanged: no new data is collected or
sent, the payment form, consent, and disclosures are as previously
reviewed (see `WPORG-REVIEW-COMMERCE-NOTES.md` for the current
summary).

Validated on a clean WordPress install with WP_DEBUG enabled:
activation is clean, `wp plugin check` reports 0 errors (161 warnings,
all in the previously reviewed non-blocking categories), and the full
PHPUnit suite passes (154 unit + 18 integration tests) on WordPress
6.9.

Keeping the permalink `nvoos-content-graph`.

Thanks,
[Your name]
