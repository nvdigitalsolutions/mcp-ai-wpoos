# Security Fixes — March 2026 Code Review

**Date:** March 11, 2026
**Scope:** Pro Addon REST API Controllers (`addons/pro/includes/rest/`)
**Files Changed:** 5

---

## Summary

A security-focused code review of the pro plugin's REST API controllers identified 9 vulnerabilities (1 critical, 2 high, 5 medium, 1 low). All 7 actionable findings were fixed in this session. One medium finding (room-level access control) requires architectural changes and is deferred. The low finding (Twitter CRC info disclosure) already has rate limiting and the exposure is minimal.

---

## Fixes Applied

### CRITICAL — Google Chat OIDC JWT: No Cryptographic Signature Verification

**File:** `class-wp-mcp-ai-google-chat-webhook-controller.php`
**Method:** `validate_google_oidc_token()`

**Vulnerability:** The JWT payload was decoded locally with `base64url_decode()` and the claims (`exp`, `iss`, `aud`) were inspected without ever verifying the RS256 signature. Any caller could forge a JWT with valid-looking header/payload and pass authentication.

**Fix:** Replaced the local decode+claims-check approach with a call to Google's `tokeninfo` endpoint (`https://oauth2.googleapis.com/tokeninfo?id_token=<token>`). Google performs full RS256 signature verification server-side and returns HTTP 400 for invalid/expired tokens. The response is then used to validate `iss` and `aud` claims. The `base64url_decode()` method is retained for other potential uses but is no longer used in the OIDC validation path.

---

### HIGH — Outlook Webhook: `?validationToken` Bypasses All HMAC Authentication

**File:** `class-wp-mcp-ai-outlook-webhook-controller.php`
**Method:** `validate_outlook_signature()`

**Vulnerability:** Any request carrying a non-null `validationToken` query parameter was unconditionally allowed through, bypassing the `clientState` HMAC check entirely.

**Fix:** The bypass now requires that at least one active Outlook connection with a configured `client_state` exists. Requests with `validationToken` are rejected with 403 when no integration is configured, preventing exploitation on sites that don't use the Outlook integration.

---

### HIGH — Google Chat: `space_name` URL Injection

**File:** `class-wp-mcp-ai-google-chat-webhook-controller.php`
**New method:** `is_valid_space_name()`

**Vulnerability:** `space_name` from the webhook JSON payload (attacker-controlled when ISSUE-1 is exploited) was concatenated directly into Google Chat API URLs after only `sanitize_text_field()`, which does not remove URL-special characters like `?`, `&`, `#`. A forged `space.name` value could redirect the outbound authenticated API call, leaking the Bearer token.

**Fix:** Added `is_valid_space_name()` which validates the format `^spaces/[A-Za-z0-9_-]+$`. Applied at all three URL construction sites. Invalid space names are logged and the operation is aborted (or a 400 WP_Error is returned for REST handler paths).

---

### MEDIUM — WebChat SSE: Wildcard `Access-Control-Allow-Origin`

**File:** `class-wp-mcp-ai-webchat-signaling-rest-controller.php`
**Method:** `stream_events()`

**Vulnerability:** The SSE endpoint sent `Access-Control-Allow-Origin: *`, allowing any cross-origin page to read the WebRTC signaling stream (SDP offers/answers, ICE candidates, peer identities).

**Fix:** Changed to `get_site_url()` with a `Vary: Origin` header to restrict the stream to same-origin consumers.

---

### MEDIUM — WebChat Signaling: `from_peer` Not Ownership-Verified

**File:** `class-wp-mcp-ai-webchat-signaling-rest-controller.php`
**Methods:** `signal()`, `exchange_ice_candidate()`

**Vulnerability:** The `from_peer` parameter was only checked for existence in the room. Any authenticated user could supply another user's `peer_id` as `from_peer`, impersonating them as the sender of WebRTC offers/answers or ICE candidates (man-in-the-middle).

**Fix:** After retrieving the peer record, `user_id` is compared against `get_current_user_id()`. The check **fails closed** — if `user_id` is absent from the peer record, the request is rejected with 403 rather than bypassing the check.

---

### MEDIUM — Skill Manager: `http://` URLs Permitted for Skill Installation

**File:** `class-wp-mcp-ai-skill-manager-rest-controller.php`
**Method:** `install_from_url()`

**Vulnerability:** The URL scheme allowlist included both `http` and `https`. Skills fetched over `http://` are transmitted in plain text and can be silently replaced by a MitM attacker, resulting in malicious skill content being installed.

**Fix:** Restricted to `https` only. The error message updated to say "Only HTTPS URLs are supported for skill installation."

---

### MEDIUM — Telegram Mini App: No Rate Limiting on `/validate` Endpoint

**File:** `class-wp-mcp-ai-telegram-mini-app-controller.php`
**Method:** `handle_validate_init_data()`

**Vulnerability:** The public `/validate` endpoint (permission callback `__return_true`) had no rate limiting, enabling replay attacks within the `INIT_DATA_MAX_AGE` window and free user enumeration for valid tokens.

**Fix:** Added transient-based per-IP rate limiting (20 requests per minute) consistent with the existing Twitter CRC rate limiter pattern. Exceeding the limit returns HTTP 429.

---

## Deferred / Accepted Risk

| # | Issue | Severity | Status |
|---|-------|----------|--------|
| 6 | WebChat: No room-level access control (unauthorized room joining) | MEDIUM | Deferred — requires architectural changes (room token model) |
| 9 | Twitter CRC endpoint: connection-ID existence disclosure | LOW | Accepted — already rate-limited (5 req/min); disclosure limited to binary exist/not-exist |

---

## What Was Confirmed Well-Implemented

- **SQL injection**: All `$wpdb` queries use `$wpdb->prepare()` with placeholders.
- **HMAC webhook auth**: Slack, Teams, Telegram, WhatsApp, Messenger, iCloud, Discord, Apple Messages, and Twitter POST endpoints all use `hash_equals()` with HMAC-SHA256; secrets are required-or-403 (fail-closed).
- **Telegram login auth_date freshness**: Both Login Widget and Mini App validate token freshness.
- **Skill name path traversal**: Parser enforces `^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`; `uninstall_skill()` also checks for `..` and `/`.
- **SSRF in skill install-url**: DNS rebinding protection and private/reserved IP range rejection.
- **Capability checks**: All admin endpoints require `manage_options`.
- **XSS**: REST JSON responses returned via `rest_ensure_response()` with proper content-type; no direct `echo` of unescaped user input.
