# NV oOS Forge Bridge — wp.org Review Reply Draft (0.2.0)

Excluded from the shipping ZIP via `.distignore` (`/WPORG-REVIEW-*.md`).
Reply to the review thread; keep it short and factual per the team's request.

---

Hi,

Thanks for the review. The TypeError in `Rebuild_Webhook::send()` is fixed
and version 0.2.0 has been uploaded:

- `send()` no longer declares an `array` return type. A failed HTTP request
  now returns a `WP_Error` (as the docblock already documented) instead of
  raising an uncaught TypeError at shutdown. The plugin requires PHP 7.4,
  where union types are unavailable, so the correct fix is the untyped
  signature.
- I swept the whole plugin for the same pattern — the only other
  `wp_remote_post` call (the optional Cloudflare Turnstile verification)
  already handles `WP_Error` explicitly, and no other typed return crosses
  an HTTP result.
- Added a regression test: a simulated transport failure returns `WP_Error`
  from `send()` and the `schedule()` → `send_pending()` shutdown path
  completes without a fatal error.

Also re-verified the activation checklist on a clean WordPress install with
WP_DEBUG enabled: activation produces no errors, warnings, notices, or
output; the plugin creates no database tables (options and transients
only); assets are not enqueued anywhere (the settings page is plain
server-side HTML); and nothing contacts external servers on activation —
the webhook fires only on the shutdown hook after content changes and only
once the dispatches URL, token, and secret are saved.

Validation: `wp plugin check` reports 0 errors and 0 warnings, phpcs
(WPCS + PHPCompatibilityWP) is clean, and the full PHPUnit suite passes
(48 tests) on the latest WordPress.

Keeping the permalink `nvoos-forge-bridge`.

Thanks,
V.
