# Embedded Abilities

## Purpose

Registers every embedded addon operation as a WordPress Ability (`wp_register_ability()`) so AI agents can discover, inspect, and execute them via the MCP Adapter. One ability = one typed operation with JSON Schema input/output contracts.

## Tier

| | |
|---|---|
| **Distribution** | Pro addon (`addons/embedded/`) |
| **PHP target** | 7.4+ (ability registration guarded by `function_exists('wp_register_ability')`) |
| **Loaded by** | `nvoos-embedded.php` → `NV_oOS_Embedded_Abilities::init()` on `plugins_loaded` |
| **Optional dependencies** | WordPress 6.9+ (for `wp_register_ability()`; degrades gracefully on older versions) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NV_oOS_Embedded_Abilities` | `class-nvoos-embedded-abilities.php` | `NV_oOS_Embedded::on_plugins_loaded()` |

## Registered Abilities

| Ability ID | Category | MCP Public | Returns |
|---|---|---|---|
| `nvoos-embedded/transcribe-audio` | `nvoos-embedded-voice` | Yes | `{ text, language }` |
| `nvoos-embedded/get-stt-config` | `nvoos-embedded-voice` | Yes | `{ active_backend, stt_model, vad_threshold, voice_enabled }` |
| `nvoos-embedded/get-llm-backends` | `nvoos-embedded-inference` | Yes | `{ backends[], active }` |
| `nvoos-embedded/get-model-list` | `nvoos-embedded-inference` | Yes | `{ models[] }` (filterable by `backend`, `type`) |
| `nvoos-embedded/analyze-image` | `nvoos-embedded-vision` | Yes | `{ description, model_used, client_side }` |
| `nvoos-embedded/ocr-document` | `nvoos-embedded-ocr` | Yes | `{ text, raw, model_type, metadata }` |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_embedded_settings`, `NV_oOS_Embedded_Backend_Registry`
- **Writes to:** Nothing (read-only abilities; transcribe passes through to `WP_MCP_AI_Embedded_Transcribe`)
- **Upstream callers:** MCP Adapter (`wordpress/mcp-adapter`), any AI agent via REST
- **Downstream collaborators:** `NV_oOS_Embedded_Backend_Registry`, `WP_MCP_AI_Embedded_Transcribe`
- **Events listened to:** `wp_abilities_api_init`

## Conventions

- Every ability follows the Unix Theory canonical envelope: `execute_callback` returns `array` on success, `WP_Error` on failure.
- `permission_callback` is explicit — never `__return_true` on state-changing operations. Read-only discovery abilities (backends, models, config) use `__return_true`.
- `meta.mcp.public` is `true` for read-only operations safe for AI agent consumption. Destructive operations (not yet registered) will set this `false`.
- `input_schema` and `output_schema` are JSON Schema objects. The MCP Adapter uses these for tool discovery and parameter validation.
- Registration is guarded by `function_exists('wp_register_ability')` — degrades silently on WordPress < 6.9.

## Tests

```bash
vendor/bin/phpunit tests/php/test-embedded-abilities-registration.php
```

## Also Load

- `.context/conventions.md` — naming + style
- `.context/security-checklist.md` — security
- `CLAUDE.md` — PHP compat, tool patterns
- [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/)
- [WordPress MCP Adapter](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/)
