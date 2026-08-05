# Embedded Backend Registry

## Purpose

Provides a pluggable registry for LLM and STT inference backends. One backend does one form of inference — client-side browser or server-side CPU — and the registry picks the active one at runtime.

## Tier

| | |
|---|---|
| **Distribution** | Pro addon (`addons/embedded/`) |
| **PHP target** | 7.4+ |
| **Loaded by** | `addons/embedded/nvoos-embedded.php` → `NV_oOS_Embedded::on_plugins_loaded()` |
| **Optional dependencies** | None (client-side always available; server-side needs `shell_exec` + `llama.cpp` binary) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NV_oOS_Embedded_LLM_Backend` (interface) | `interface-nvoos-embedded-llm-backend.php` | `NV_oOS_Embedded_Backend_Registry`, all backend implementations |
| `NV_oOS_Embedded_Backend_Registry` | `class-nvoos-embedded-backend-registry.php` | `NV_oOS_Embedded`, `NV_oOS_Embedded_Abilities` |
| `NV_oOS_Embedded_Client_Backend` | `class-nvoos-embedded-client-backend.php` | Registry → auto-registered on `nvoos_embedded_backends_init` |
| `NV_oOS_Embedded_Server_Backend` | `class-nvoos-embedded-server-backend.php` | Registry → auto-registered on `nvoos_embedded_backends_init` |
| `WP_MCP_AI_Embedded_Client` | `class-wp-mcp-ai-embedded-client.php` | Wrapped by `NV_oOS_Embedded_Server_Backend` (internal) |
| `WP_MCP_AI_Embedded_Transcribe` | `class-wp-mcp-ai-embedded-transcribe.php` | `NV_oOS_Embedded::handle_transcribe_request()` |
| `WP_MCP_AI_WebLLM_Enqueue` | `class-nvoos-embedded-webllm-enqueue.php` | Base plugin shortcode/Elementor widget |
| `NV_oOS_Embedded_Self_Hosted_OCR_Backend` | `class-nvoos-embedded-self-hosted-ocr-backend.php` | Registry → auto-registered when `WP_MCP_AI_Self_Hosted_OCR_Client` is available |

## Inputs / Outputs / Neighbors

- **Reads from:** `nvoos_embedded_settings` option (`inference_backend`, `client_model`, `server_model`, etc.)
- **Writes to:** `wp_mcp_ai_embedded_chat_completion` filter (result envelope)
- **Upstream callers:** `NV_oOS_Embedded::handle_embedded_chat_completion()`, `NV_oOS_Embedded_Abilities`
- **Downstream collaborators:** `WP_MCP_AI_Embedded_Client` (server-side inference), browser JS (`embedded-llm-client.js` for client-side)
- **Events fired:** `nvoos_embedded_backends_init`, `nvoos_embedded_backends_registered`, `nvoos_embedded_stream_chunk`
- **Events listened to:** `nvoos_embedded_backends_init` (register defaults)

## Conventions

- Every backend implements `NV_oOS_Embedded_LLM_Backend`. The contract is the single source of truth — no backend may bypass it.
- `get_slug()` returns a machine-readable identifier used as the registry key. No two backends may share a slug.
- `is_available()` must be side-effect-free and fast (no network calls). Heavy checks go in `get_health_status()`.
- `create_chat_completion()` returns the canonical envelope: `array` on success, `WP_Error` on failure. Never `array( 'success' => false, ... )`.
- Third-party backends register on `nvoos_embedded_backends_registered` (after built-ins). Override by `unregister_llm_backend()` + `register_llm_backend()`.

## Tests

```bash
vendor/bin/phpunit tests/php/test-embedded-backend-registry.php
vendor/bin/phpunit tests/php/test-embedded-client-backend.php
vendor/bin/phpunit tests/php/test-embedded-server-backend.php
vendor/bin/phpunit tests/php/test-embedded-client-shared-libs.php
vendor/bin/phpunit tests/php/test-embedded-model-service.php
vendor/bin/phpunit tests/php/test-embedded-model-slug-sanitization.php
```

## Also Load

- `.context/conventions.md` — naming + style
- `.context/security-checklist.md` — security rules
- `CLAUDE.md` — PHP compat (7.4+), tool patterns, canonical envelope
