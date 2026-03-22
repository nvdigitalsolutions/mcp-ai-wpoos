# CORS Origin Allowlist Settings — Architecture Specification

**Date:** March 2026
**Phase:** 3 — Architecture
**Status:** Approved
**Author:** NV Digital Solutions / Agent: nv-oos-architect
**PRD Reference:** `docs/proposals/CORS-ALLOWLIST-PRD.md`
**Version:** 1.0

---

## System Overview

Adds a single **"CORS Allowed Origin"** text field to the Security settings tab.
On save, the value is stored in `wp_mcp_ai_settings['cors_allowed_origin']`.
`WP_MCP_AI_Security_Manager::__construct()` registers an
`add_filter( 'wp_mcp_ai_cors_allow_origin', ... )` callback that returns the
stored value (or `*` if empty). Six existing handlers already call
`apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' )` — no changes needed there.

---

## Component Diagram

```
┌──────────────────────────────────────────────────────────────┐
│                     NV oOS Base Plugin                        │
│                                                               │
│  ┌──────────────────────────────────────────────────────┐    │
│  │  WP_MCP_AI_Section_Security (Security Tab)            │    │
│  │  includes/admin/sections/                             │    │
│  │  class-wp-mcp-ai-section-security.php                 │    │
│  │                                                       │    │
│  │  + field: cors_allowed_origin (text input)            │    │
│  │  + sanitize: esc_url_raw()                            │    │
│  └──────────────────────────────────────────────────────┘    │
│                         │ saves to                            │
│                         ▼                                     │
│  ┌──────────────────────────────────────────────────────┐    │
│  │  wp_mcp_ai_settings['cors_allowed_origin']            │    │
│  │  (WordPress options, via WP_MCP_AI_Admin_Settings)    │    │
│  └──────────────────────────────────────────────────────┘    │
│                         │ read by                             │
│                         ▼                                     │
│  ┌──────────────────────────────────────────────────────┐    │
│  │  WP_MCP_AI_Security_Manager::__construct__()          │    │
│  │  includes/class-wp-mcp-ai-security-manager.php        │    │
│  │                                                       │    │
│  │  add_filter( 'wp_mcp_ai_cors_allow_origin',           │    │
│  │              [ $this, 'get_cors_allow_origin' ] )     │    │
│  └──────────────────────────────────────────────────────┘    │
│                         │ filter consumed by                  │
│                         ▼                                     │
│  ┌──────────────────────────────────────────────────────┐    │
│  │  apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' )  │    │
│  │  (called in 6 existing handlers — no changes needed)  │    │
│  │  • class-wp-mcp-ai-rest-mcp-methods.php               │    │
│  │  • class-wp-mcp-ai-rest.php                           │    │
│  │  • rest/class-wp-mcp-ai-rest-mcp-controller.php (×2) │    │
│  │  • rest/class-wp-mcp-ai-sse-handler.php (×2)          │    │
│  └──────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────┘
```

---

## Class Hierarchy

| Class | File | Change | Responsibility |
|-------|------|--------|---------------|
| `WP_MCP_AI_Section_Security` | `includes/admin/sections/class-wp-mcp-ai-section-security.php` | **Modify** | Add CORS heading + `cors_allowed_origin` field; sanitize value |
| `WP_MCP_AI_Security_Manager` | `includes/class-wp-mcp-ai-security-manager.php` | **Modify** | Register `wp_mcp_ai_cors_allow_origin` filter; implement `get_cors_allow_origin()` |

---

## Data Model

### WordPress Options

| Option Key | Type | Default | Description |
|------------|------|---------|-------------|
| `wp_mcp_ai_settings['cors_allowed_origin']` | string | `''` (empty) | Allowed CORS origin URL, e.g. `https://app.example.com`. Empty = wildcard `*`. |

No CPT, CCT, or migration required.

---

## Hook & Filter Registry

| Hook / Filter | Type | Registered In | Description |
|--------------|------|--------------|-------------|
| `wp_mcp_ai_cors_allow_origin` | filter | `WP_MCP_AI_Security_Manager::__construct` | Returns the configured allowed origin or `*`. Existing — new hook callback added. |

---

## REST API Design

No new REST endpoints.

---

## Security Model

- **Authentication:** `manage_options` capability (inherits Security tab gate)
- **Authorization:** `manage_options` — verified by existing settings page
  permission callback
- **Input sanitization:** `esc_url_raw()` in `WP_MCP_AI_Section_Security::sanitize()`
- **Output escaping:** field value echoed via `esc_url()` in the settings
  renderer (handled by the abstract settings section base class)
- **Nonce verification:** handled by `settings_fields()` / WordPress Settings API
  (existing — no change)
- **ABSPATH guard:** already present in both files being modified

### Localhost Debug Allowance Logic

```php
public function get_cors_allow_origin( $default ) {
    $saved = $this->settings->get( 'cors_allowed_origin', '' );
    $saved = trim( rtrim( $saved, '/' ) );

    if ( '' !== $saved ) {
        return $saved;
    }

    // WP_DEBUG: allow localhost with any port for development.
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $origin = isset( $_SERVER['HTTP_ORIGIN'] ) ?
            sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
        if ( preg_match( '#^https?://localhost(:\d+)?$#i', $origin ) ) {
            return $origin;
        }
    }

    return '*';
}
```

---

## File Map

| File | Action | Story | Description |
|------|--------|-------|-------------|
| `includes/admin/sections/class-wp-mcp-ai-section-security.php` | Modify | 1.1 | Add `_heading_cors` + `cors_allowed_origin` field after `_heading_rate_limiting` section; add sanitize logic |
| `includes/class-wp-mcp-ai-security-manager.php` | Modify | 2.1, 2.2 | Register filter in `__construct`; add `get_cors_allow_origin()` method |
| `tests/security/test-cors-allowlist.php` | Create | 3.1 | PHPUnit tests: empty setting → `*`; set URL → URL returned; debug localhost |

---

## Architecture Review Checklist

- [x] All components follow `WP_MCP_AI_` naming convention
- [x] Security model covers authentication, authorization, sanitization, escaping
- [x] Data model uses appropriate WordPress storage (Options, not CPT/CCT)
- [x] No new REST endpoints
- [x] Hook/filter enables extensibility (existing filter reused)
- [x] File map is complete and follows project structure
- [x] Backward compatibility maintained (empty setting → `*`, unchanged default)
- [x] Pro vs Base version gating: **Base** (all files in `includes/`)

---

*Next step: Scrum Master (nv-oos-scrum-master) breaks into stories →
Stories 1.1, 2.1, 2.2, 3.1 as defined in `docs/proposals/CORS-ALLOWLIST-PRD.md`*
