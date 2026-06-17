# Pro Toolkit Optimizations

> **Feature area:** Pro Performance · **Phase:** Complete (v1.1.29)  
> **Scope:** Pro addon only · **Related:** `CLAUDE.md` § "Pro Toolkit Optimizations"

## Overview

The Pro Toolkit Optimization system provides performance tuning across 6 Pro toolkits. Each optimization class implements autoload control, query caching, lazy loading, and data retention policies to reduce database load and improve response times.

## Optimization Classes

| Class | Toolkit | File | Lines |
|-------|---------|------|-------|
| `WP_MCP_AI_CC_Optimization` | Chat Channels | `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-cc-optimization.php` | 333 |
| `WP_MCP_AI_SM_Optimization` | Social Media | `addons/pro/includes/tools/social-media/class-wp-mcp-ai-sm-optimization.php` | 276 |
| `WP_MCP_AI_HC_Optimization` | Healthcare | `addons/pro/includes/tools/healthcare/class-wp-mcp-ai-hc-optimization.php` | 278 |
| `WP_MCP_AI_EC_Optimization` | Ecommerce | `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-ec-optimization.php` | 208 |
| `WP_MCP_AI_Cal_Orch_Optimization` | Calendar / Orchestration | `addons/pro/includes/tools/calendar-booking/class-wp-mcp-ai-cal-orch-optimization.php` | 192 |
| `WP_MCP_AI_DG_Optimization` | Document Generation / QMS | `addons/pro/includes/tools/document-generation/class-wp-mcp-ai-dg-optimization.php` | 145 |

## Optimization Strategies

### Autoload Control

Each optimization class manages the `autoload` flag on its toolkit's WordPress options:
- Frequently accessed options are set to `autoload = yes` (loaded on every request).
- Infrequently accessed options are set to `autoload = no` (loaded on demand).
- Periodic audit cron identifies options that have drifted from their intended autoload state.

### Query Caching

Expensive database queries are cached using WordPress transients:
- Cache TTLs are toolkit-specific (e.g., social media analytics cache for 15 min, healthcare records cache for 5 min).
- Cache keys are salted with the current user ID and site ID for multisite safety.
- Stale cache entries are pruned on toolkit deactivation.

### Lazy Loading

Tool classes are lazy-loaded:
- Heavy dependencies (FFmpeg, PDF generators, DICOM parsers) are only loaded when the tool is actually invoked.
- Toolkit `init.php` files register tools as callables rather than instantiating them at boot.
- Reduces memory footprint on requests that don't use the toolkit.

### Retention Policies

Data lifecycle management per toolkit:
- Chat channel message logs: 30-day retention by default.
- Social media analytics cache: 7-day retention.
- Healthcare vitals data: configurable (default 90 days for HIPAA compliance).
- Ecommerce cart/order temp data: 24-hour retention.
- Calendar event history: 60-day retention.
- Document generation temp files: 1-hour retention.

## Wiring

Each optimization class is wired into its toolkit's init file:

```php
// Example: addons/pro/includes/tools/chat-channels/init.php
if ( class_exists( 'WP_MCP_AI_CC_Optimization' ) ) {
    $cc_optimization = new WP_MCP_AI_CC_Optimization();
    $cc_optimization->init();
}
```

Optimization classes only activate when their parent toolkit is enabled (controlled via Features → Pro Toolkits admin toggles).

## Admin Settings

Located at **Settings → NV oOS → Advanced → Pro Toolkit Performance**:

| Setting | Default | Description |
|---------|---------|-------------|
| Enable autoload optimization | On | Manage autoload flags on toolkit options |
| Enable query caching | On | Cache expensive DB queries |
| Enable lazy loading | On | Defer heavy dependency loading |
| Cache TTL override | (per toolkit) | Override default cache durations |

## Testing

Full test suite at `tests/test-pro-toolkit-optimization.php` (767 lines):

```bash
# Run specific test file
vendor/bin/phpunit tests/test-pro-toolkit-optimization.php

# Run with coverage
vendor/bin/phpunit tests/test-pro-toolkit-optimization.php --coverage-html coverage/
```

## Hooks

| Hook | Type | Description |
|------|------|-------------|
| `wp_mcp_ai_pro_toolkit_optimization_enabled` | Filter | Enable/disable all optimizations |
| `wp_mcp_ai_pro_toolkit_cache_ttl` | Filter | Override cache TTL per toolkit |
| `wp_mcp_ai_pro_toolkit_autoload_audit_interval` | Filter | Change audit cron frequency |
| `wp_mcp_ai_pro_toolkit_retention_days` | Filter | Override retention period per toolkit |

## Related Files

- `addons/pro/includes/tools/chat-channels/init.php` — CC optimization wiring
- `addons/pro/includes/tools/social-media/init.php` — SM optimization wiring
- `addons/pro/includes/tools/healthcare/init.php` — HC optimization wiring
- `addons/pro/includes/tools/ecommerce/init.php` — EC optimization wiring
- `addons/pro/includes/tools/calendar-booking/init.php` — Calendar/Orch optimization wiring
- `addons/pro/includes/tools/document-generation/init.php` — DG optimization wiring
- `tests/test-pro-toolkit-optimization.php` — Full test suite
