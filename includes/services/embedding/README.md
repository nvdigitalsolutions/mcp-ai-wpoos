# Embedding

## Purpose

Contains the four concrete embedding provider implementations — DigitalOcean, Gemini, Ollama, and OpenAI — each fulfilling the `WP_MCP_AI_Embedding_Provider_Interface` contract so `WP_MCP_AI_Vector_Context_Service` can generate vector embeddings without coupling to a single backend.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `WP_MCP_AI_Vector_Context_Service` selects the configured provider at runtime |
| **Optional dependencies** | each provider gated on its own API key / endpoint availability |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Embedding_Provider_DigitalOcean` | `class-wp-mcp-ai-embedding-provider-digitalocean.php` | `WP_MCP_AI_Vector_Context_Service` |
| `WP_MCP_AI_Embedding_Provider_Gemini` | `class-wp-mcp-ai-embedding-provider-gemini.php` | `WP_MCP_AI_Vector_Context_Service`; also used by `semantic_content_search` for Gemini-backed assistants |
| `WP_MCP_AI_Embedding_Provider_Ollama` | `class-wp-mcp-ai-embedding-provider-ollama.php` | same |
| `WP_MCP_AI_Embedding_Provider_OpenAI` | `class-wp-mcp-ai-embedding-provider-openai.php` | same (default when API key is present) |

## Inputs / Outputs / Neighbors

- **Reads from:** provider API keys (WordPress options); OpenAI model filter `wp_mcp_ai_embedding_provider_openai_model`.
- **Writes to:** outbound HTTP to provider embedding endpoints; returns `array<int,float>` (vector) or `WP_Error`.
- **Upstream callers:** `services/class-wp-mcp-ai-vector-context-service.php`.
- **Downstream collaborators:** each provider's SDK client (`WP_MCP_AI_OpenAI_Client`, `WP_MCP_AI_Ollama_Client`, etc.).
- **Events fired:** none.
- **Events listened to:** none.

## Conventions

- Each class implements `WP_MCP_AI_Embedding_Provider_Interface` with four methods: `get_id()`, `get_model()`, `is_available()`, `embed()`.
- `is_available()` gates on API key presence — services should call this before invoking `embed()`.
- `embed()` returns `array<int,float>` on success, `WP_Error` on failure. The vector service consumes the result directly.
- OpenAI's default model is `text-embedding-3-small` (overridable via the `wp_mcp_ai_embedding_provider_openai_model` filter).
- Each provider lazy-initialises its underlying SDK client.

## Tests

```bash
vendor/bin/phpunit --filter 'Embedding' tests/
```

Embedding provider tests are part of the broader services test suite.

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — API key handling, outbound HTTP (always)
- Parent folder: [`includes/services/README.md`](../README.md) — full services layer overview

## See Also

- Upstream parent: [`includes/services/`](../) — services layer
- Consumer: [`includes/services/class-wp-mcp-ai-vector-context-service.php`](../class-wp-mcp-ai-vector-context-service.php)
- Interface: `WP_MCP_AI_Embedding_Provider_Interface` (defined in `includes/interfaces/`)
