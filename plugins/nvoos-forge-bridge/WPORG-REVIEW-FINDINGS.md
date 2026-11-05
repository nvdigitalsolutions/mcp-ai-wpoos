# NV oOS Forge Bridge — wp.org Review Findings (v0.1.0 submission)

Review ID: `F1 ❗ACT nvoos-forge-bridge/vsamtani/5Nov26/T1 5Nov26/4.4RC1 (P0TDX399590HGN)`

Excluded from the shipping ZIP via `.distignore` (`/WPORG-REVIEW-*.md`).

## Finding: uncaught TypeError in `Rebuild_Webhook::send()`

> After configuration, `Rebuild_Webhook::send()` declares an array return
> type, but a failed HTTP request returns `WP_Error`, causing an uncaught
> TypeError at shutdown when content changes.

### Root cause

`includes/class-nvoos-forge-bridge-rebuild-webhook.php` declared

```php
public static function send( string $type ): array {
	…
	return wp_remote_post( $url, $args );
}
```

`wp_remote_post()` returns `array|WP_Error`. When the HTTP transport fails
(host down, timeout, DNS failure — the `pre_http_request`/`http_api_debug`
path returns a `WP_Error`), the `: array` return type raised a
`TypeError: Return value must be of type array, WP_Error returned`. Because
the send runs on the `shutdown` hook (`send_pending()`), the TypeError was
uncaught and fatal — a failed webhook delivery crashed the request after the
page had already rendered.

### Fix (0.2.0)

Removed the `: array` return type. The plugin supports PHP 7.4, which has no
union types (`array|WP_Error` is PHP 8.0+), so the correct PHP 7.4 shape is
an untyped declaration with the `@return array|\WP_Error` docblock (which was
already accurate). `send_pending()` discards the result either way, so a
failed delivery is now a silent no-op (Contentful-style fire-and-forget)
instead of a fatal error.

Regression test added: `Test_Forge_Bridge_Webhook::
test_failed_request_returns_wp_error_without_typeerror` — a `pre_http_request`
filter returns `WP_Error`, and both the direct `send()` call and the
`schedule()` → `send_pending()` shutdown path are asserted to complete
without a TypeError.

### Related-issue sweep (same category, whole plugin)

| Call site | Result |
|---|---|
| `Rebuild_Webhook::send()` | Fixed (this finding) |
| `Rest\Forms::verify_turnstile()` | Already safe — no return type, explicit `is_wp_error()` fail-closed branch |
| Every other `: array` / `: bool` / `: string` declaration | Returns built-in values only (`option_keys()`, `store()`, `sign()`, …) — grep-verified, no other `wp_remote_*` result crosses a typed boundary |
| `pre_http_request` / `http_api_debug` hooks | None registered by the plugin |

## Checklist items from the review email (re-verified)

| Item | Verification |
|---|---|
| Activates clean with `WP_DEBUG` | One-off clean-WP container test (see signoff 0.2.0 section): activation + front-page load produce no notices/warnings/fatals, empty `debug.log` |
| DB tables via `$wpdb->prefix` / `get_charset_collate()` / `dbDelta()` | N/A — the plugin creates no tables (5 options + 2 transients only) |
| Activation prints nothing | No output at file scope; main file has the `ABSPATH` guard; activation emits nothing (verified in the clean-install test) |
| Unavailable dependencies checked before use | `Requires at least: 6.0` + `Requires PHP: 7.4` headers (WordPress blocks activation itself); no optional-plugin APIs called without checks; the form bridge checks for JetFormBuilder / Elementor Pro handlers at dispatch time (typed 404/501) |
| Assets only on own screens | No `wp_enqueue_*` calls anywhere — the settings page is plain PHP+HTML |
| No external contact on activation | No `register_activation_hook`; the only outbound call (`send()`) fires on `shutdown`, after content changes, and only when the webhook is fully configured |
