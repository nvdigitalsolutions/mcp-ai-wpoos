# DigitalOcean Serverless Inference Provider — Setup & Reference

> **NV oOS** supports DigitalOcean Serverless Inference as a first-class AI provider. DigitalOcean exposes a single OpenAI-compatible API at `https://inference.do-ai.run/v1` that hosts Llama, DeepSeek-R1 distill, OpenAI gpt-oss, and other open-weights models — so you can use one model-access key for chat completions, embeddings, and streaming without managing infrastructure.

---

## Contents

1. [Prerequisites](#prerequisites)
2. [Quick Start](#quick-start)
3. [Available Models](#available-models)
4. [Custom Base URL](#custom-base-url)
5. [Tool Calling & Streaming](#tool-calling--streaming)
6. [Embeddings](#embeddings)
7. [Prompt Caching & Reasoning](#prompt-caching--reasoning)
8. [Known Limitations](#known-limitations)
9. [Diagnostics & Testing](#diagnostics--testing)
10. [Troubleshooting](#troubleshooting)

---

## Prerequisites

- NV oOS base plugin active (no Pro addon required — DigitalOcean Serverless Inference is in the **Base** distribution).
- A DigitalOcean Gradient Platform account with Serverless Inference enabled.
- A **Model Access Key** created in **Gradient Platform → Serverless Inference → Model access keys**.
- PHP 7.4+ (base plugin requirement).

---

## Quick Start

1. In your WordPress admin, go to **NV oOS → Settings → Providers → DigitalOcean**.
2. Check **Enable DigitalOcean Provider**.
3. Paste your model access key (starts with `do-…`).
4. Choose a default model (e.g. `llama3.3-70b-instruct`).
5. Click **Save Changes**.
6. Optionally go to **Tools → Provider Diagnostics** and click **Test DigitalOcean Connection**.

---

## Available Models

DigitalOcean Serverless Inference hosts a curated catalogue of open-weights and partner models. Examples:

| Model ID | Family | Tool Calling | Reasoning | Notes |
|----------|--------|--------------|-----------|-------|
| `llama3.3-70b-instruct` | Meta Llama | ✅ | — | Default choice, broad availability |
| `llama3.1-8b-instruct` | Meta Llama | ✅ | — | Cost-effective small model |
| `deepseek-r1-distill-llama-70b` | DeepSeek-R1 distilled | ⚠️ | ✅ | Exposes `reasoning_content` for chain-of-thought passthrough |
| `openai-gpt-oss-120b` | OpenAI gpt-oss (open weights) | ✅ | — | Large open model |
| `gte-large-en-v1.5` | Embedding | — | — | Default embedding model |

The catalogue changes as DigitalOcean adds models. NV oOS refreshes the dropdown via the `/v1/models` endpoint whenever a key is configured (see **Model Discovery Service**).

---

## Custom Base URL

If you proxy DigitalOcean through your own VPC endpoint or gateway, set **DigitalOcean API Base URL (Optional)** to that URL. Leave empty to use the default `https://inference.do-ai.run/v1`.

The base URL must include the API version segment (`/v1`).

---

## Tool Calling & Streaming

- **Tool calling**: The DigitalOcean wire format is identical to OpenAI's `tools` / `tool_choice`. NV oOS passes tool definitions through unchanged. Support varies per model — `llama3.3-70b-instruct` and `openai-gpt-oss-120b` support tool calls; the DeepSeek-R1 distill model may degrade gracefully when tools are present.
- **Streaming**: Set `stream: true` to receive Server-Sent Events with the OpenAI-compatible `data: …` framing. The plugin's existing chat UI handles streaming chunks natively.

---

## Embeddings

Unlike OpenRouter, DigitalOcean Serverless Inference exposes a native `/embeddings` endpoint compatible with OpenAI's embeddings request shape. NV oOS ships a `WP_MCP_AI_Embedding_Provider_DigitalOcean` implementation alongside the OpenAI and Ollama providers — set **Vector Context → Embedding Provider** to `digitalocean` once you have configured the model access key.

The default embedding model is `gte-large-en-v1.5`. Override via the `digitalocean_embedding_model` setting or the `wp_mcp_ai_embedding_provider_digitalocean_model` filter.

---

## Prompt Caching & Reasoning

- **Reasoning models** (e.g. DeepSeek-R1 distill) return a `reasoning_content` field on the assistant message. NV oOS preserves it verbatim alongside the visible content so downstream consumers can decide whether to render it.
- **Prompt caching** is enabled automatically by DigitalOcean for supported models — no client-side flag required.

---

## Known Limitations

- **Agent endpoints out of scope.** DigitalOcean Agent endpoints (`*.agents.do-ai.run/api/v1`) use a different per-agent URL scheme and authentication flow. They are intentionally not handled by this client; a separate provider entry may be added in a future release.
- **Token counts are estimated.** DigitalOcean does not expose a public tokenizer endpoint; the plugin uses a chars/4 heuristic for pre-flight TPM accounting. Accurate counts are read back from the `usage` block in each response.
- **Pricing.** The bundled model catalogue ships with `cost_per_1k_input_tokens` and `cost_per_1k_output_tokens` set to `0`. Update them in the **Models** admin page (or your `wp_mcp_ai_model_catalog` filter) to reflect your DigitalOcean account's per-token billing for accurate cost tracking.

---

## Diagnostics & Testing

The **Provider Diagnostics** page (under **NV oOS → Tools**) includes a **DigitalOcean Serverless Inference** card that performs a `GET /v1/models` probe and reports latency, model count, and the default model. This probe does not spend inference credits.

Programmatic test:

```php
$client = new WP_MCP_AI_DigitalOcean_Client();
$result = $client->test_connection();
if ( is_wp_error( $result ) ) {
    error_log( 'DigitalOcean test failed: ' . $result->get_error_message() );
}
```

---

## Troubleshooting

| Symptom | Likely Cause | Fix |
|---------|--------------|-----|
| `wp_mcp_ai_digitalocean_auth_error` (HTTP 401/403) | Invalid or revoked model access key | Re-create the key in Gradient Platform → Serverless Inference → Model access keys |
| `wp_mcp_ai_digitalocean_insufficient_credits` (HTTP 402) | Account out of credits or key scope exhausted | Top up the DigitalOcean account or widen the key's scope |
| `wp_mcp_ai_rate_limit_exceeded` (HTTP 429) | Per-key rate limit hit | Retry after the duration in the `retry_after` field (read from the `Retry-After` header) |
| Tool calls silently ignored | Model does not support function calling | Switch to a tool-calling model (e.g. `llama3.3-70b-instruct`) or remove `tools` |
| Empty `reasoning_content` field | Model is not a reasoning model | Use `deepseek-r1-distill-llama-70b` for chain-of-thought passthrough |

---

## See Also

- [OpenRouter Provider](openrouter.md) — sibling unified-gateway provider
- [DeepSeek Provider](deepseek/) — direct DeepSeek API integration
- [DigitalOcean Serverless Inference docs](https://docs.digitalocean.com/products/gradient-ai-platform/how-to/use-serverless-inference/)
- [DigitalOcean Gradient Platform API reference](https://docs.digitalocean.com/reference/api/gradient-ai-platform-api/)
