# Context Window Management

> **Feature area:** AI Provider Integration · **Phase:** Complete (v1.1.29)  
> **Scope:** Base plugin (all 13 providers) · **Related:** `CLAUDE.md` § "Context Window Management"

## Overview

The Context Window Management subsystem provides pre-flight validation of prompt sizes before they are sent to any AI provider. This prevents silent truncation, mid-stream errors, and degraded response quality when the combined system prompt, conversation history, and tool definitions exceed the model's context window.

All 13 first-class AI providers (OpenAI, Anthropic, Gemini, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio, Ollama) use the shared `validate_context_window()` helper before sending requests.

## Architecture

### Shared Helper

```php
/**
 * Validate that the estimated prompt fits within the model's context window.
 *
 * @param string $model       Model identifier (e.g. 'gpt-5.2', 'claude-opus-4-20250514').
 * @param array  $messages    Conversation messages.
 * @param array  $tools       Tool definitions to include in the count.
 * @param int    $max_tokens  Requested max output tokens.
 * @return array{valid: bool, estimated_tokens: int, context_limit: int, message: string}
 */
function wp_mcp_ai_validate_context_window( $model, $messages, $tools, $max_tokens );
```

Returns actionable guidance: whether the prompt fits, estimated token count, the model's context limit, and a human-readable message explaining the outcome.

### tiktoken Integration

Accurate token counting is powered by:
- **npm:** `gpt-tokenizer` package for client-side estimation.
- **PHP:** Server-side port for pre-flight checks.
- Tokenizer selection is model-aware — different models use different tokenizers.

### Estimator Metabox

A metabox on the assistant edit screen (`WP Admin → AI Assistants → Edit`) shows:
- **System prompt tokens** — the assistant's base instructions.
- **Tool tokens** — total tokens consumed by tool definitions.
- **Messages tokens** — estimated conversation buffer.
- **Reserved tokens** — requested `max_tokens` output budget.
- **Total** — sum of all the above vs. the model's context limit.
- **Status indicator:** 🟢 OK / 🟡 Warning (80%+) / 🔴 Over limit.

### Token-Budget Tool Capping

When the estimated prompt size exceeds **80%** of the context window:
1. Tools are sorted by priority (capability flags: `read-only` > `write` > `state-changing` > `external-api`).
2. Least-capable tools are pruned first.
3. The model receives a truncated tool list that fits the budget.
4. A warning is logged to the debug console.

This ensures the assistant always has access to its most important tools while staying within model limits.

### Chat Parity Drift Detection

In `lib/core`, a monitoring subsystem tracks response quality across providers:
- Compares semantic similarity of responses to identical prompts across providers.
- Detects model degradation (e.g., provider-side model updates that change behavior).
- Alerts via admin notice when drift exceeds configurable threshold.

## Supported Providers

| Provider | Client Class | Context Window Validation | Token Capping |
|----------|-------------|--------------------------|---------------|
| OpenAI | `WP_MCP_AI_OpenAI_Client` | ✅ | ✅ |
| Anthropic | `WP_MCP_AI_Anthropic_Client` | ✅ | ✅ |
| Gemini | `WP_MCP_AI_Gemini_Client` | ✅ | ✅ |
| DeepSeek | `WP_MCP_AI_DeepSeek_Client` | ✅ | ✅ |
| OpenRouter | `WP_MCP_AI_OpenRouter_Client` | ✅ | ✅ |
| Baseten | `WP_MCP_AI_Baseten_Client` | ✅ | ✅ |
| Kimi | `WP_MCP_AI_Kimi_Client` | ✅ | ✅ |
| DigitalOcean | `WP_MCP_AI_DigitalOcean_Client` | ✅ | ✅ |
| NVIDIA NIM | `WP_MCP_AI_NVIDIA_Client` | ✅ | ✅ |
| Cloudflare | `WP_MCP_AI_Cloudflare_Client` | ✅ | ✅ |
| Hugging Face | `WP_MCP_AI_HuggingFace_Client` | ✅ | ✅ |
| LM Studio | `WP_MCP_AI_LM_Studio_Client` | ✅ | ✅ |
| Ollama | `WP_MCP_AI_Ollama_Client` | ✅ | ✅ |

## Admin Settings

Located at **Settings → NV oOS → Advanced → Context Window**:

| Setting | Default | Description |
|---------|---------|-------------|
| Enable pre-flight validation | On | Toggle context-window checking |
| Warning threshold | 80% | Percentage of context window that triggers tool capping |
| Enable tool capping | On | Automatically prune tools when near budget |
| Enable estimator metabox | On | Show token budget on assistant edit screen |
| Drift detection threshold | 0.15 | Semantic similarity score below which drift is flagged |

## Hooks

| Hook | Type | Description |
|------|------|-------------|
| `wp_mcp_ai_validate_context_window` | Filter | Override the validation result |
| `wp_mcp_ai_context_window_warning_threshold` | Filter | Change the 80% threshold |
| `wp_mcp_ai_context_window_tool_cap_enabled` | Filter | Enable/disable tool capping |
| `wp_mcp_ai_chat_parity_drift_threshold` | Filter | Change drift detection sensitivity |

## Debugging

When `WP_MCP_AI_DEBUG` is enabled, the following is logged:
- Estimated token count per request.
- Which tools were pruned (if any).
- Drift detection scores (when above threshold).
- Validation failures with the specific model limit that was exceeded.

Enable via: `define( 'WP_MCP_AI_DEBUG', true );` in `wp-config.php`.

## Related Files

- `includes/class-wp-mcp-ai-openai-client.php` — refactored to use shared helper
- `includes/class-wp-mcp-ai-anthropic-client.php` — refactored to use shared helper
- `lib/core/` — chat parity drift detection
- `assets/js/context-window-estimator.js` — estimator metabox JS
