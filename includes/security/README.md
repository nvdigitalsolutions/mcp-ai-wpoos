# Security

## Purpose

Houses security infrastructure services that are referenced from multiple
subsystems (REST controllers, admin sections, the agentic loop, and the
harness). Unlike `includes/class-wp-mcp-ai-security-manager.php` (runtime
request-level enforcement) or `includes/class-wp-mcp-ai-security-audit.php`
(ISO 27001 audit scheduling), the classes here provide **analytical / reporting**
services that operate across the full option set rather than on a per-request
basis.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` (eagerly, before `rest_api_init`) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Security_Posture` | `class-wp-mcp-ai-security-posture.php` | Security Center Overview sub-tab, `WP_MCP_AI_REST_Security_Center_Controller`, (future) dashboard widget + WP-CLI |

### `WP_MCP_AI_Security_Posture`

Computes a 0-100 weighted posture score from ~17 signals (HTTPS, HSTS, root
key, audit log, rate limiting, security headers, 2FA consistency,
IP-whitelist consistency, prompt-injection detector, PII filter, etc.).

```php
$posture = new WP_MCP_AI_Security_Posture();

// Cached (5-minute TTL).
$report = $posture->get_report();
// $report['score']      int  0-100
// $report['grade']      string A/B/C/D/F
// $report['signals']    array  per-signal results
// $report['quick_wins'] array  top-3 unmet signals by weight
// $report['computed_at'] string ISO 8601

// Force refresh.
$report = $posture->get_report( true );

// Invalidate cache (called automatically on settings restore).
$posture->invalidate_cache();
```

**Filter:** `wp_mcp_ai_security_posture_signals` — receives the raw signal
definition array and the current settings array. Pro code adds OTel,
vector-store, and MCP-server-token-age signals via this hook.

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` option, WordPress core functions (`is_ssl()`, `is_plugin_active()`), transient cache.
- **Writes to:** transient `wp_mcp_ai_security_posture` (5-minute TTL).
- **Upstream callers:** `WP_MCP_AI_Section_Security::render_overview_subtab()`, `WP_MCP_AI_REST_Security_Center_Controller::get_posture()`.
- **Filters fired:** `wp_mcp_ai_security_posture_signals`.

## Conventions

- Classes in this folder are pure read-only services — they never modify the `wp_mcp_ai_settings` option.
- The posture score is deterministic given a fixed settings snapshot; no side effects.

## Tests

```bash
vendor/bin/phpunit tests/test-security-center.php
```

Tests cover posture report shape, score range, grade validity, quick-wins
limit, score sensitivity to enabled controls, and signal non-emptiness.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md)
- [`.context/security-checklist.md`](../../.context/security-checklist.md)
