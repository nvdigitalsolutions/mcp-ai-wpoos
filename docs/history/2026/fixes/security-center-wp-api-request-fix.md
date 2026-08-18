# Security Center wp.apiRequest Error — Fix History (PR #5887)

> **Fixed in:** 1.1.58 · **Commit:** `0df4b8020` · **Area:** `includes/admin/class-wp-mcp-ai-settings-dashboard.php`

## Symptom

Refreshing the Security Center tab (overview and sub-tabs) in Settings → NV oOS threw a `wp.apiRequest is not a function`-style JavaScript error and none of the security-tab actions worked — security posture refresh, IP tests, snapshot restore, compliance export, and the security self-test — because their inline scripts call `wp.apiRequest()` without the `wp-api` script being loaded.

## Root Cause

The settings dashboard's `enqueue_dashboard_scripts()` conditionally enqueues tab-specific scripts (e.g. the tools manager scripts on `?tab=tools`), but the security tab had no matching branch. Its inline scripts assumed the `wp-api` script — and therefore the `wp.apiRequest()` helper — was already present, which is only true on tabs that happen to enqueue it.

## Fix

Added a conditional enqueue on the security tab (8 lines):

```php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
if ( isset( $_GET['tab'] ) && 'security' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
    wp_enqueue_script( 'wp-api' );
}
```

`wp-api` ships with WordPress core, so no new asset is loaded — the script is simply registered on the tab whose inline code depends on it.

## Prevention

- Inline scripts that call `wp.apiRequest()` must declare their dependency: the tab's enqueue branch should call `wp_enqueue_script( 'wp-api' )` (and pass `array( 'wp-api' )` as the dependency to any custom script handle).
- The pattern is the same as the pre-existing tools-tab branch in the same method.
