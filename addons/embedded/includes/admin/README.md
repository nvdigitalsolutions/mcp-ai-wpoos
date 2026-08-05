# Embedded Admin

## Purpose

Provides the WordPress admin UI for managing embedded AI settings — model selection, backend configuration, STT setup, and model download/delete operations.

## Tier

| | |
|---|---|
| **Distribution** | Pro addon (`addons/embedded/`) |
| **PHP target** | 7.4+ |
| **Loaded by** | `nvoos-embedded.php` (guarded by `is_admin()`) |
| **Optional dependencies** | None |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_WebLLM_Settings_Page` | `class-wp-mcp-ai-webllm-settings-page.php` | WordPress admin menu (`admin_menu`) |
| `WP_MCP_AI_Embedded_Model_Ajax` | `class-wp-mcp-ai-embedded-model-ajax.php` | WordPress AJAX (`wp_ajax_*`) |
| `NV_oOS_Embedded_OCR_Dashboard` | `class-nvoos-embedded-ocr-dashboard.php` | WordPress admin menu (`admin_menu`) — OCR health sub-page |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_embedded_settings`, `wp_mcp_ai_settings`
- **Writes to:** `nvoos_embedded_settings` (via Settings API), server filesystem (`WP_MCP_AI_Embedded_Client` model downloads)
- **Upstream callers:** WordPress admin menu system, AJAX requests from admin UI
- **Downstream collaborators:** `WP_MCP_AI_Embedded_Client` (model download/delete/binary management)
- **Events fired:** AJAX responses (`wp_send_json_success` / `wp_send_json_error`)

## Conventions

- All AJAX handlers call `self::verify_request()` which enforces `check_ajax_referer('wp_mcp_ai_embedded_models', 'nonce')` + `current_user_can('manage_options')`.
- Settings are registered via the WordPress Settings API with `register_setting()`. Each field has a `sanitize_callback`.
- Model download operations are fire-and-forget from the AJAX perspective — the `WP_MCP_AI_Embedded_Client` handles the actual HTTP download to the server filesystem.

## Tests

```bash
vendor/bin/phpunit tests/php/test-embedded-provider-dropdown.php
vendor/bin/phpunit tests/php/test-embedded-provider-subtab-integration.php
vendor/bin/phpunit tests/php/test-embedded-provider-pro-loading.php
```

## Also Load

- `.context/conventions.md` — naming + style
- `.context/security-checklist.md` — security
- `CLAUDE.md` — PHP compat, tool patterns
