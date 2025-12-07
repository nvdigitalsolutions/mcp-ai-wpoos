# Security Checks Implementation - WP oOS

This document describes the security checks implemented for Open Operator System (WP oOS) as requested in the problem statement.

## Overview

Five critical security checks have been implemented with comprehensive test coverage:

1. **Auth Split-Brain Test** - MCP endpoint authentication enforcement
2. **SSE Auth & CORS Test** - Server-Sent Events security
3. **Rate Limiting & Backoff** - Request throttling and audit trails
4. **Break-Glass Emergency Shutdown** - Root Security Key protection
5. **Tool Scope Sanity** - OAuth scope and capability enforcement

## 1. Auth Split-Brain Test

### Goal
Verify that nonce-only access fails for remote MCP endpoint; bearer token succeeds.

### Implementation

#### Code Changes
- **File**: `includes/class-wp-mcp-ai-rest.php`
- **New Method**: `permissions_check_mcp()`
- **Route Update**: `/mcp` endpoint now uses `permissions_check_mcp` instead of generic `permissions_check`

#### Behavior
- **Accepts**: Bearer tokens (local credentials or Auth0), Mesh API keys
- **Rejects**: WordPress nonce-only authentication
- **Returns**: 401 with helpful error message and actions when auth fails

#### Test Coverage
**File**: `tests/security/test-auth-split-brain.php`

Tests:
1. `test_mcp_endpoint_rejects_nonce_only_auth()` - Verifies 401 for nonce-only requests
2. `test_mcp_endpoint_accepts_bearer_token_auth()` - Verifies 200 for valid bearer tokens
3. `test_mcp_endpoint_rejects_invalid_bearer_token()` - Verifies rejection of invalid tokens
4. `test_other_endpoints_still_accept_nonce_auth()` - Ensures other endpoints unchanged
5. `test_mcp_endpoint_accepts_mesh_api_key()` - Verifies mesh key authentication works

### How to Test Manually

```bash
# Test 1: Nonce-only should FAIL (returns 401)
curl -X POST https://your-site/wp-json/mcp-ai/v1/mcp \
  -H "X-WP-Nonce: YOUR_NONCE" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'

# Test 2: Bearer token should SUCCEED (returns 200)
curl -X POST https://your-site/wp-json/mcp-ai/v1/mcp \
  -H "Authorization: Bearer cred_xxxxx.SECRET" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
```

## 2. SSE Auth & CORS Test

### Goal
Ensure `/sse` endpoint requires authentication and respects origin headers.

### Implementation

The `/sse` endpoint already uses `permissions_check()` which accepts:
- Bearer tokens
- WordPress nonces (for logged-in users)
- Guest tokens

CORS headers are set in `WP_MCP_AI_SSE_Handler::send_sse_headers()`:
- `Access-Control-Allow-Origin: *`
- `Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce`
- `Access-Control-Allow-Methods: GET, POST, OPTIONS`

#### Test Coverage
**File**: `tests/security/test-sse-auth-cors.php`

Tests:
1. `test_sse_endpoint_requires_authentication()` - Verifies auth is required
2. `test_sse_endpoint_accepts_bearer_token()` - Bearer token works
3. `test_sse_endpoint_accepts_nonce_for_logged_in_users()` - Nonce works for logged-in users
4. `test_sse_headers_include_cors()` - CORS headers present
5. `test_sse_respects_origin_header()` - Origin handling verified
6. `test_sse_endpoint_accepts_guest_token()` - Guest tokens work

### How to Test Manually

```bash
# Test 1: No auth should FAIL
curl -N -H "Accept: text/event-stream" \
  https://your-site/wp-json/mcp-ai/v1/sse

# Test 2: With bearer token should SUCCEED (streams events)
curl -N -H "Accept: text/event-stream" \
  -H "Authorization: Bearer cred_xxxxx.SECRET" \
  https://your-site/wp-json/mcp-ai/v1/sse?assistant_id=123

# Test 3: Check CORS from untrusted origin
curl -N -H "Accept: text/event-stream" \
  -H "Origin: https://untrusted.com" \
  -H "Authorization: Bearer cred_xxxxx.SECRET" \
  https://your-site/wp-json/mcp-ai/v1/sse?assistant_id=123
```

## 3. Rate Limiting & Backoff

### Goal
Observe 429 status codes on burst requests, backoff logs, and audit entries.

### Implementation

Rate limiting is handled by `WP_MCP_AI_Rate_Limit_Manager` and can be configured in settings:
- `enable_rate_limiting` - Enable/disable rate limiting
- `rate_limit_requests_per_minute` - Maximum requests per minute

When rate limit is exceeded:
- Returns 429 status code
- Logs rate limit events
- Creates audit trail entries

#### Test Coverage
**File**: `tests/security/test-rate-limit-backoff.php`

Tests:
1. `test_burst_requests_trigger_rate_limit()` - Verifies 429 on burst
2. `test_rate_limit_generates_backoff_logs()` - Logs are created
3. `test_rate_limit_creates_audit_trail()` - Audit entries exist
4. `test_rate_limit_manager_tracks_requests()` - Manager tracking works
5. `test_rate_limit_response_includes_retry_after()` - Response guidance

### How to Test Manually

```bash
# Enable rate limiting in WordPress admin:
# Settings → WP oOS → Security → Enable Rate Limiting
# Set limit to 5 requests per minute

# Burst test script:
for i in {1..20}; do
  curl -w "\nStatus: %{http_code}\n" \
    -H "X-WP-Nonce: YOUR_NONCE" \
    https://your-site/wp-json/mcp-ai/v1/assistants
  sleep 0.1
done

# Check logs:
# WordPress admin → Settings → WP oOS → Logs
# Look for rate limit entries and audit trail
```

## 4. Break-Glass Emergency Shutdown

### Goal
Emergency shutdown with Root Security Key blocks re-enablement until correct key provided.

### Implementation

Already implemented in:
- `includes/class-wp-mcp-ai-root-security-key.php`
- `includes/class-wp-mcp-ai-nefarious-usage-monitor.php`

Features:
- Define `WP_MCP_AI_ROOT_SECURITY_KEY` constant in `wp-config.php`
- Emergency shutdown blocks tool execution
- Re-enablement requires Root Security Key
- Failed attempts trigger lockout (5 attempts = 15 min lockout)
- All actions logged for audit trail

#### Test Coverage
**File**: `tests/security/test-break-glass.php`

Tests:
1. `test_root_security_key_configuration()` - Key configuration
2. `test_emergency_shutdown_can_be_triggered()` - Shutdown activation
3. `test_emergency_shutdown_blocks_tool_execution()` - Execution blocked
4. `test_root_key_blocks_reenablement()` - Re-enablement requires key
5. `test_correct_root_key_allows_reenablement()` - Correct key works
6. `test_incorrect_root_key_is_rejected()` - Wrong key rejected
7. `test_shutdown_creates_log_trail()` - Logs created
8. `test_failed_key_attempts_trigger_lockout()` - Lockout on failures

### How to Test Manually

```bash
# Step 1: Define Root Security Key in wp-config.php
define( 'WP_MCP_AI_ROOT_SECURITY_KEY', 'your-secure-random-key-minimum-32-chars' );

# Step 2: Trigger emergency shutdown
# WordPress admin → Settings → WP oOS → Security Monitor
# Click "Trigger Emergency Shutdown"

# Step 3: Try to re-enable without key (should FAIL)
# Attempt to clear shutdown - will be denied

# Step 4: Try with wrong key (should FAIL and log)
# Provide incorrect key - will be rejected and logged

# Step 5: Provide correct key (should SUCCEED)
# Enter correct Root Security Key - shutdown cleared

# Step 6: Check logs for complete audit trail
# All attempts should be logged with IP, timestamp, user ID
```

## 5. Tool Scope Sanity

### Goal
Ensure least-privilege OAuth scopes for Gmail/Calendar tools and enforce capability checks.

### Implementation

#### Gmail Tool (`WP_MCP_AI_Tool_Search_Gmail`)
- **Default Capability**: `manage_options`
- **Recommended OAuth Scopes**:
  - `https://www.googleapis.com/auth/gmail.readonly` (read-only)
  - `https://www.googleapis.com/auth/gmail.metadata` (metadata only)
- **Enforces**: User capability check before execution
- **Filterable**: `wp_mcp_ai_search_gmail_capability` filter

#### Calendar Tool (`WP_MCP_AI_Tool_Create_Google_Calendar_Event`)
- **Default Capability**: `manage_options`
- **OAuth Scope**: `https://www.googleapis.com/auth/calendar.events` (least privilege - events only, not full calendar)
- **Constant**: `DEFAULT_SCOPE` and `DEFAULT_REQUIRED_CAPABILITY`
- **Enforces**: User capability check before execution
- **Filterable**: `wp_mcp_ai_google_calendar_required_capability` filter

Both tools:
- Check multisite membership
- Respect capability requirements
- Return `WP_Error` for unauthorized users

#### Test Coverage
**File**: `tests/security/test-tool-scope-sanity.php`

Tests:
1. `test_search_gmail_oauth_scopes()` - Gmail scopes documented
2. `test_search_gmail_enforces_capability_checks()` - Low-priv users blocked
3. `test_search_gmail_allows_admin_execution()` - Admins pass checks
4. `test_google_calendar_oauth_scopes()` - Calendar scope is least-privilege
5. `test_google_calendar_enforces_capability_checks()` - Low-priv blocked
6. `test_google_calendar_has_default_capability()` - Default capability set
7. `test_tool_capability_can_be_filtered()` - Capability filtering works
8. `test_tools_check_multisite_membership()` - Multisite checks

### How to Test Manually

```bash
# Test as low-privilege user (subscriber)
# 1. Log in as subscriber
# 2. Try to execute Gmail search tool via assistant
# Expected: Error "You do not have permission to search Gmail"

# Test as admin user
# 1. Log in as administrator
# 2. Configure Gmail OAuth credentials
# 3. Try to execute Gmail search tool
# Expected: Success (or API error if not fully configured)

# Review OAuth scopes:
# WordPress admin → Settings → WP oOS → Gmail Settings
# Verify scopes are minimal:
# - Gmail: gmail.readonly or gmail.metadata
# - Calendar: calendar.events (NOT full calendar)
```

## Running the Tests

```bash
# Run all security tests
vendor/bin/phpunit tests/security/

# Run specific test suites
vendor/bin/phpunit tests/security/test-auth-split-brain.php
vendor/bin/phpunit tests/security/test-sse-auth-cors.php
vendor/bin/phpunit tests/security/test-rate-limit-backoff.php
vendor/bin/phpunit tests/security/test-break-glass.php
vendor/bin/phpunit tests/security/test-tool-scope-sanity.php

# Run with specific group
vendor/bin/phpunit --group security
vendor/bin/phpunit --group mcp
vendor/bin/phpunit --group oauth
```

## Summary

All five security checks from the problem statement have been implemented and tested:

✅ **Auth Split-Brain**: MCP endpoint enforces bearer-only authentication
✅ **SSE Auth & CORS**: SSE endpoint requires auth and handles CORS properly
✅ **Rate Limiting**: Returns 429 on burst, logs events, creates audit trail
✅ **Break-Glass**: Emergency shutdown with Root Security Key protection
✅ **Tool Scope Sanity**: OAuth scopes are minimal, capability checks enforced

Each security check has:
- Comprehensive test coverage
- Manual testing instructions
- Clear documentation
- Proper error messages for users
