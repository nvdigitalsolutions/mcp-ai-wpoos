# NV oOS Pro vs Base Version Guide

> **GSD Context File** — Load this when making decisions about Base vs Pro feature placement.
> Last reviewed: August 12, 2026.

---

## Overview

NV oOS has two distribution modes:

| Mode | Constant | Tools | Description |
|------|---------|-------|-------------|
| **Base** | `WP_MCP_AI_BASE_VERSION = true` | ~300 core tools | Open-source, WordPress.org compatible |
| **Full (Pro)** | `WP_MCP_AI_BASE_VERSION = false` | ~1,543 tools (~300 base + ~1,243 Pro) | Premium addon with third-party integrations |

---

## Decision Framework: Base or Pro?

### → Put in BASE if:
- Core WordPress functionality (posts, pages, users, media, options, taxonomies)
- No third-party API dependencies
- No paid service integrations
- No proprietary data formats
- Useful to any WordPress site owner

### → Put in PRO if:
- Requires paid third-party API (OpenAI Pro tiers, Shopify, Upwork, etc.)
- Requires optional plugins (JetEngine, WooCommerce, Elementor, Rank Math)
- Advanced orchestration or multi-agent features
- Healthcare/medical tools (HIPAA-sensitive)
- Enterprise features (DICOM imaging, custom workflows)
- CRM integrations (Upwork, Bitwarden, Firefly)
- Cannabis dispensary management (FlowHub API integration)
- Shopify e-commerce sync (Shopify Admin API integration)
- Chat channels (Slack, Teams, Discord, WhatsApp, Telegram)
- Server management (DietPi, SSH proxy, provisioning)
- AI safety features (Layer I guardrails, jailbreak detection)
- SaaS platforms (Schedule Anything with Stripe)
- Advanced AI features (code interpreter, speech services via LibreChat)
- Meta-Harness auto-optimization (trace capture, search engine, proposer, auto-deploy)
- Agent delegation (inline execution, REST dispatch, cron resilience)
- Tool presets system (essentials layers, auto-upgrade, deduplication)

---

## Code Guard Patterns

### Checking for Pro Addon
```php
// Check if Pro addon is active:
if ( class_exists( 'WP_MCP_AI_Pro' ) ) {
    // Pro-only code
}
```

### Checking Base Version Flag
```php
// In base plugin code:
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) || ! WP_MCP_AI_BASE_VERSION ) {
    // Only runs in full (non-base) mode
}
```

### Checking Optional Dependencies
```php
// JetEngine:
if ( function_exists( 'jet_engine' ) ) {
    // JetEngine-specific code
}

// WooCommerce:
if ( class_exists( 'WooCommerce' ) ) {
    // WooCommerce-specific code
}

// Elementor:
if ( did_action( 'elementor/loaded' ) ) {
    // Elementor-specific code
}

// Rank Math:
if ( class_exists( 'RankMath' ) ) {
    // Rank Math-specific code
}
```

---

## File Structure by Version

### Base Version Files
```
mcp-ai-wpoos.php                    # Main plugin file
mcp-ai-wpoos-base.php               # Base-only entry point
includes/
├── tools/class-wp-mcp-ai-tool-*.php    # ~201 core tools
├── class-wp-mcp-ai-transcript-retention.php  # Transcript retention (base)
├── class-wp-mcp-ai-rest.php            # Core REST API
├── class-wp-mcp-ai-admin.php           # Core admin
└── ...
```

### Pro Version Files
```
addons/pro/
├── mcp-ai-wpoos-pro.php                # Pro addon main file
├── includes/
│   ├── tools/                          # Pro tools
│   ├── tools/{category}/               # Categorized pro tools
│   ├── admin/                          # Pro admin sections
│   ├── rest/                           # Pro REST controllers
│   └── class-wp-mcp-ai-*.php           # Pro classes
```

---

## Optional Dependency Tools

| Dependency | Tools Added | Toolkit Flag |
|-----------|-------------|-------------|
| JetEngine | 5 additional CCT tools | `enable_jetengine_toolkit` |
| WooCommerce | 3 e-commerce tools | `enable_woocommerce_toolkit` |
| Elementor | 1 widget tool | (auto-detected) |
| Rank Math | 1 SEO analysis tool | `enable_seo_toolkit` |
| WPCode | 1 code snippet tool | `enable_wpcode_toolkit` |

---

## Toolkit Enable Flags

Pro tools can be conditionally enabled via admin settings:

```php
// In Pro tool registration:
if ( $this->is_toolkit_enabled( 'enable_crm_toolkit' ) ) {
    $this->register_tool( 'WP_MCP_AI_Tool_Search_Upwork_Jobs' );
    $this->register_tool( 'WP_MCP_AI_Tool_Score_Upwork_Job' );
    $this->register_tool( 'WP_MCP_AI_Tool_Draft_Upwork_Proposal' );
}
```

Available toolkit flags:
- `enable_crm_toolkit`
- `enable_health_toolkit`
- `enable_woocommerce_toolkit`
- `enable_jetengine_toolkit`
- `enable_seo_toolkit`
- `enable_wpcode_toolkit`
- `enable_media_toolkit`
- `enable_workflow_toolkit`
- `enable_dietpi_toolkit`
- `enable_librechat_toolkit`
- `enable_schedule_anything_toolkit`

---

## Testing Both Versions

When a story touches code that runs in both modes, test with:

```php
// Test in Base mode:
define( 'WP_MCP_AI_BASE_VERSION', true );
// ... test that pro features are NOT available

// Test in Full mode:
define( 'WP_MCP_AI_BASE_VERSION', false );
// ... test that pro features ARE available
```

In CI, the phpunit.xml.dist runs in standard mode. To test base mode:
```bash
WP_MCP_AI_BASE_VERSION=1 vendor/bin/phpunit
```

---

## WordPress.org Compliance (Base Version Only)

When modifying base plugin code, verify:
- No output escaping violations
- ABSPATH guards on all non-root files
- No hardcoded admin menu positions
- All strings translated via `__()` / `_e()`
- No direct database schema modifications without upgrade hooks
- `plugin_action_links` for settings link (not hardcoded menu URL)
