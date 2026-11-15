# NV oOS Forge Bridge — wp.org Review Findings

## Review T2 (0.3.0 submission, 2026-11-10)

Review ID: `R nvoos-forge-bridge/vsamtani/5Nov26/T2 10Nov26/4.4RC1 (P0TDX399590HGN)`

Three findings: the public form route, the rate-limit IP source, and the
native-response capture. Fixed in 0.3.1.

### Finding 1 — public form route can dispatch to non-public forms

> `permission_callback => '__return_true'` on `/forms/submit`; the endpoint can
> dispatch to unpublished or otherwise non-public forms. The Origin header can
> be spoofed, so the allowlist does not provide authorization.

**Assessment.** The route is genuinely public (anonymous visitors submit forms
from the static site), so `__return_true` is the correct declaration. The real
defect was the missing visibility check: an attacker who passed the origin gate
could submit to a draft/private/password-protected form and trigger its
mail/actions.

**Fix (0.3.1).**

| Path | Change |
|---|---|
| `resolve_jfb_form_id()` | By ID: requires `post_status = publish` and `post_password = ''` via `is_public_post()`. |
| `jfb_form_by_title()` | Query scoped to `post_status = publish` + `has_password = false`. |
| `elementor_has_form()` | Joins `wp_posts` and requires `post_status = publish` + `post_password = ''`, for both the `post_id` and global lookups. |
| `register_routes()` | Comment states the authorization model (origin = CSRF gate, not identity; visibility re-checked server-side). |

Non-public forms return the same `nvoos_forge_form_not_found` 404 as a missing
form (no draft enumeration).

### Finding 2 — rate-limit key trusts `X-Forwarded-For`

> The rate-limit key uses an untrusted `X-Forwarded-For` header, allowing
> clients to evade the limit by changing their claimed IP.

**Fix (0.3.1).** `client_ip()` uses `REMOTE_ADDR` by default. A forwarding
chain is honored only when the direct peer is in the
`nvoos_forge_bridge_trusted_proxies` filter (exact IPs or CIDR; empty by
default), and then the chain is walked right-to-left so the first untrusted hop
is used. New helpers: `normalize_ip()` (`rest_is_ip_address()`),
`ip_in_list()`, `ip_in_cidr()` (IPv4 + IPv6).

### Finding 3 — `wp_send_json()` ends the REST request

> `wp_send_json()` exits directly during a REST request, so the die-handler
> filter cannot capture native responses; dispatch can terminate the REST
> request prematurely.

**Assessment.** Correct. In a REST request `wp_send_json()` echoes the payload
and calls `die` (it only routes through `wp_die()` when `wp_doing_ajax()`), so
the previous `wp_die_json_handler` filter never fired.

**Fix (0.3.1).** `capture_json()` now:

1. filters `wp_doing_ajax` to true for the dispatch (the native handlers'
   contract), so `wp_send_json()` calls `wp_die()`;
2. filters `wp_die_ajax_handler` + `wp_die_json_handler` to return a callback
   that throws the capture sentinel;
3. buffers the echoed JSON with `ob_start()` and decodes it;
4. restores every filter and buffer in `finally` (including on error).

The helper is `protected` so the test proxy can exercise it directly.

### Related-issue sweep

| Route | Callback | Verdict |
|---|---|---|
| `POST /forms/submit` | `__return_true` | Public by design; now visibility-gated + documented. |
| `GET /health` | `__return_true` | Public by design; booleans/versions/host only. Comment added. |
| `POST /preview-tokens/verify` | `__return_true` | Public by design; single-use bearer token is the proof. Comment added. |
| `POST /rebuild` | `verify_signature` | HMAC, fail closed. |
| `POST /preview-tokens` | `can_mint` | `edit_pages` capability. |
| `GET /redirects` | `check_access` | Optional shared token (operator opt-in). |

### Additional audit findings (same class, fixed in 0.3.1)

| # | Finding | Fix |
|---|---|---|
| 1 | **JFB routing-carrier override.** `dispatch_jfb()` set `$_POST[$form_key]` before writing the submitted fields, so a field named `_jet_engine_booking_form_id` could overwrite the resolved id and re-target the dispatch to an arbitrary (possibly non-public) form — the same visibility-gate bypass the review flagged, one layer deeper. | Strip the handler's carrier keys from the fields (`without_reserved_carriers()`), write user fields first, and re-assert the carriers last. Regression test on the helper. |
| 2 | **`maybe_unserialize()` on provider columns** (`rankmath_sources()`, `action_data_url()`). A tampered serialized row is a PHP object-injection primitive. | New `decode_array()`: JSON first, then `unserialize( $raw, ['allowed_classes' => false] )`; non-arrays are dropped. Test: a serialized `stdClass` row yields no rules. |
| 3 | **Redirect source/target control characters.** A stored newline in a source/target would be emitted into the static `_redirects` file and could inject an extra rule. | Reject `[\x00-\x1F\x7F]` in `normalize_source()` / `normalize_target()`, and refuse a redirect code with an empty target. Test covers both fields. |
| 4 | **Uninstall left the hashed rate-limit transients** behind (`nvoos_forge_bridge_forms_rate_<md5>`), which the finite option list cannot reach. | `uninstall.php` wildcard-deletes `_transient_` / `_transient_timeout_` rows for the prefix with `esc_like()` + `prepare()`. |

### Residual risks (accepted, documented)

- **SSRF is admin-gated.** `rebuild_url` / `revalidate_url` are `manage_options`-only and validated with `wp_http_validate_url()`, but `wp_remote_post()` can still reach internal hosts if an administrator configures one. That is the same trust model as any webhook plugin.
- **Inbound `/rebuild` replay.** The route verifies the HMAC over the exact body but does not parse a timestamp, so a captured signed request can be replayed. Impact is a debounced rebuild (CI cost), not data access.
- **`/redirects` is public when the token is empty.** This is the documented operator opt-in for build pipelines; set a token to keep the URL map private.
- **`/health` runs provider probes.** Up to four `SHOW TABLES` per request (read-only, bounded); not cached to avoid cross-test staleness.

### Pre-upload gate for this pattern

`.github/scripts/check-forge-bridge-public-routes.php` scans every
`register_rest_route()` call and fails when a CREATABLE / EDITABLE / DELETABLE
route uses `__return_true` without an entry in
`forge-bridge-public-write-routes.txt` (it also fails on stale entries). Wired
into `.github/workflows/phpunit-forge-bridge.yml` before PHPCS. Run it locally
with `php .github/scripts/check-forge-bridge-public-routes.php`.

### Tests added (0.3.1)

- `test_jfb_nonpublic_form_404` — draft, private, and password-protected forms
  are 404; a draft title does not resolve.
- `test_elementor_nonpublic_carrier_404` — a draft carrier post is not
  dispatchable, scoped and global.
- `test_forwarded_for_ignored_without_trusted_proxy` — a spoofed header cannot
  escape a saturated `REMOTE_ADDR` bucket (429).
- `test_forwarded_for_used_with_trusted_proxy` — rightmost untrusted hop wins.
- `test_capture_json_emulates_ajax_and_decodes_output` — ajax emulation +
  decode + environment restored.
- `test_capture_json_intercepts_wp_send_json` — native `wp_send_json_success()`
  is captured, run in a separate process.

### Validation (2026-11-10)

| Gate | Result |
|---|---|
| `vendor/bin/phpcs --standard=phpcs.xml.dist .` | 0 violations (exit 0) |
| `phpcs` on `tests/test-forms.php` | 0 violations |
| `php -l` on modified PHP files | No syntax errors |
| PHPUnit (own config, wp-phpunit, WP 6.9 / PHP 8.2) | **68 tests, 198 assertions, OK** |

---

# Review T1 (0.2.0 submission, 2026-11-05)

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
