# Security Settings Reference

> **Since:** 1.2.0 · **Pages:** Security (4 subtabs), Authentication (6 subtabs)

## Security → Network (`?tab=security&subtab=network`)

### IP Filtering

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Enable IP Whitelist | Checkbox | OFF | Only allow access from whitelisted IPs |
| IP Whitelist | Textarea | — | IPs/CIDR ranges, one per line |
| Enable IP Blacklist | Checkbox | OFF | Block access from blacklisted IPs |
| IP Blacklist | Textarea | — | IPs/CIDR ranges to block |
| Require HTTPS | Checkbox | OFF | Block non-HTTPS API requests |

### Rate Limiting

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Enable Rate Limiting | Checkbox | ON | General API rate limiting |
| Rate Limit (req/window) | Number | 300 | Max requests per window |
| Rate Limit Window (s) | Number | 3600 | Time window in seconds |
| Rate Limit By | Select | User ID | Track by User ID, IP, or both |

### Webhook HMAC Secret

| Setting | Type | Description |
|---------|------|-------------|
| Webhook HMAC Secret | Status badge | Shows ✓ Configured or ⚠ Not configured. Auto-generated on first webhook use. |

### Auth Brute-Force Protection (1.2.0)

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Enable Auth Rate Limiting | Checkbox | ON | Throttle repeated failed auth attempts per IP |
| Failed Auth Threshold | Number | 10 | Failures before temporary IP block |
| Auth Rate Limit Window (s) | Number | 300 | Time window for counting failures |
| Rate Limit OAuth Token Endpoint | Checkbox | ON | Apply rate limiting to /oauth/token and /oauth/register |

### Connection & Payload Limits (1.2.0)

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Max SSE Connections per User | Number | 5 | Simultaneous SSE streams per user (0 = unlimited) |
| Max Request Body Size (KB) | Number | 1024 | Largest accepted request body (0 = PHP default) |
| Max JSON Nesting Depth | Number | 32 | Maximum JSON object/array nesting (1–512) |

### Error Disclosure (1.2.0)

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| API Error Detail Level | Select | Normal | Safe (generic only), Normal (actionable for authed users), Verbose (full dev detail) |

### Security Headers

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Enable Security Headers | Checkbox | ON | X-Content-Type-Options, X-Frame-Options, Referrer-Policy |
| Enable HSTS | Checkbox | OFF | HTTP Strict Transport Security |
| HSTS Max Age (s) | Number | 31536000 | Browser HTTPS enforcement duration |
| CSP frame-ancestors | Select | 'none' | Clickjacking protection policy |

---

## Security → AI Safety (`?tab=security&subtab=ai_safety`)

### Prompt Injection

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Enable Prompt-Injection Detector | Checkbox | OFF | Scan incoming messages for injection patterns |
| Detection Sensitivity | Select | Medium | Low, Medium, High |
| Action on Detection | Select | Flag only | Flag only (log + allow) or Block (403) |

### PII Filter

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Enable PII Filter | Checkbox | OFF | Redact personal data before model/storage |
| Patterns to Detect | Multi-select | All | Email, phone, SSN, credit card, API keys |
| Filter Side | Select | Both | Request, Response, or Both |
| Redaction Mode | Select | Redact | Redact (replace) or Block (error) |

### HITL Approvals

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Require Human Approval for Write Tools | Checkbox | OFF | Route write-flag tools through approval queue |
| Approval Threshold | Select | State-changing | None, Any write, State-changing, Destructive only |

### Sandbox Mode

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Enable Sandbox (Dry-Run) Mode | Checkbox | OFF | Force ALL write tools through approval queue globally |

### AI Cost Control (1.2.0)

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Enable AI Cost Tracking | Checkbox | OFF | Track and enforce per-assistant API spend |
| Default Daily Budget (USD) | Number | $10 | Max daily spend per assistant (0 = unlimited) |
| Default Monthly Budget (USD) | Number | $100 | Max monthly spend per assistant (0 = unlimited) |

---

## Authentication → REST API (`?tab=authentication&subtab=rest_api`)

### REST Endpoint Access

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Enable REST Assistant Listing | Checkbox | ON | Allow listing assistants via REST API |
| Enable REST Assistant Creation | Checkbox | OFF | Allow creating assistants via REST API |
| Enable REST Assistant Deletion | Checkbox | OFF | Allow deleting assistants via REST API |
| Enable POST Method on SSE | Checkbox | OFF | Allow POST to SSE endpoint (non-standard) |

### OAuth & Client Registration (1.2.0)

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Disable Open OAuth Client Registration | Checkbox | ON | Require admin approval for new OAuth clients |

### Destructive Operations (1.2.0)

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Require Confirmation for Destructive Tools | Checkbox | ON | Require `confirm_destructive=true` for write/state-changing tools |

---

## Authentication → Guest Access (`?tab=authentication&subtab=guest`)

### Token Management

| Setting | Type | Default | Description |
|---------|------|:-------:|-------------|
| Guest Token Lifetime (s) | Number | 86400 | How long guest tokens remain valid |
| Assistant Credential Lifetime (days) | Number | 90 | Expiry for issued `identifier.secret` credentials (0 = no expiry) |

### Guest Token Scope

Guest tokens have **read-only** access to public assistants and chat endpoints. They **cannot**:
- Execute write-capable tools
- Access chat transcripts
- Manage assistants
- Perform administrative operations

Each tool enforces its own capability check independent of guest status.

---

## Programmatic Access

### CORS Configuration

```php
// Restrict to specific origin:
add_filter( 'wp_mcp_ai_cors_allow_origin', function() {
    return 'https://api.openai.com';
} );

// Allow multiple origins:
add_filter( 'wp_mcp_ai_cors_allow_origin', function() {
    $allowed = array( 'https://api.openai.com', 'https://api.anthropic.com' );
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    return in_array( $origin, $allowed, true ) ? $origin : '';
} );
```

### URL Guard (SSRF Protection)

```php
// Add custom blocked ranges:
add_filter( 'wp_mcp_ai_url_guard_blocked_ranges', function( $ranges ) {
    $ranges[] = '203.0.113.0/24'; // Documentation/test range.
    return $ranges;
} );

// Allow specific private IPs (e.g., local AI services):
add_filter( 'wp_mcp_ai_url_guard_blocked_ranges', function( $ranges ) {
    // Remove 192.168.0.0/16 to allow local network access.
    return array_diff( $ranges, array( '192.168.0.0/16' ) );
} );
```

### Destructive Operations

```php
// Customize which flags trigger the confirmation gate:
add_filter( 'wp_mcp_ai_destructive_confirmation_flags', function( $flags ) {
    // Only require confirmation for truly destructive operations.
    return array( 'destructive', 'data-destruction', 'irreversible' );
} );
```

### Concurrency Limits

```php
// Adjust concurrent operation limits:
add_filter( 'wp_mcp_ai_concurrency_limits', function( $limits ) {
    $limits['image_generation'] = 5;
    $limits['video_generation'] = 2;
    return $limits;
} );
```
