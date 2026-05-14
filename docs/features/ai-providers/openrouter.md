# OpenRouter Provider — Setup & Reference

> **NV oOS** supports OpenRouter as a first-class AI provider. OpenRouter exposes a single OpenAI-compatible API that routes to OpenAI, Anthropic, Google, Meta (Llama), Mistral, and 100+ other models — so you can switch providers without changing your API integration.

---

## Contents

1. [Prerequisites](#prerequisites)
2. [Quick Start](#quick-start)
3. [Available Models](#available-models)
4. [Custom Base URL](#custom-base-url)
5. [Tool Calling & Streaming](#tool-calling--streaming)
6. [Known Limitations](#known-limitations)
7. [Diagnostics & Testing](#diagnostics--testing)
8. [Troubleshooting](#troubleshooting)

---

## Prerequisites

- NV oOS base plugin active (no Pro addon required).
- An OpenRouter account and API key — sign up at [openrouter.ai](https://openrouter.ai).
- PHP 7.4+ (base plugin requirement).

---

## Quick Start

1. In your WordPress admin, go to **NV oOS → Settings → Providers → OpenRouter**.
2. Check **Enable OpenRouter Provider**.
3. Paste your API key (starts with `sk-or-…`).
4. Choose a default model (e.g. `openai/gpt-4o` or `anthropic/claude-3-5-sonnet`).
5. Click **Save Changes**.
6. Optionally go to **Tools → Provider Diagnostics** and click **Test OpenRouter Connection**.

---

## Available Models

OpenRouter provides access to 100+ models from multiple providers. Popular choices:

| Model ID | Provider | Tool Calling | Notes |
|----------|----------|--------------|-------|
| `openai/gpt-4o` | OpenAI | ✅ | Full parity with direct OpenAI |
| `openai/gpt-4o-mini` | OpenAI | ✅ | Cost-effective default |
| `anthropic/claude-3-5-sonnet` | Anthropic | ✅ | Strong reasoning |
| `anthropic/claude-3-haiku` | Anthropic | ✅ | Fast, low cost |
| `google/gemini-pro-1.5` | Google | ✅ | Long context |
| `meta-llama/llama-3.3-70b-instruct` | Meta | ✅ | Open weights |
| `mistralai/mistral-large` | Mistral | ✅ | European option |

Check [openrouter.ai/models](https://openrouter.ai/models) for the complete list and live pricing.

---

## Custom Base URL

If you use a proxy or a regional OpenRouter endpoint, set it in **NV oOS → Settings → Providers → OpenRouter → Base URL**. The default is `https://openrouter.ai/api/v1`.

---

## Tool Calling & Streaming

OpenRouter uses OpenAI-compatible tool-calling format. All NV oOS tools work identically through OpenRouter as they do through the direct OpenAI client. Server-Sent Events (SSE) streaming is fully supported.

---

## Known Limitations

- **Context windows** vary per model — some OpenRouter models have smaller context limits than their direct-provider equivalents. Check the model's OpenRouter page for accurate limits.
- **Rate limits** are set by OpenRouter, not the upstream provider. See your OpenRouter dashboard.
- **Model availability** may change — OpenRouter sometimes adds or removes models. If a model ID becomes unavailable, NV oOS will return a provider error.

---

## Diagnostics & Testing

Go to **Tools → Provider Diagnostics → OpenRouter** and click **Test Connection**. This sends a short ping request to verify your key and endpoint are reachable.

---

## Troubleshooting

| Problem | Likely Cause | Fix |
|---------|-------------|-----|
| `401 Unauthorized` | Invalid API key | Re-enter your key in Settings → Providers → OpenRouter |
| `429 Too Many Requests` | OpenRouter rate limit | Add per-model rate limits in NV oOS Settings |
| Model not found | Model ID changed | Check [openrouter.ai/models](https://openrouter.ai/models) for the current ID |
| Streaming not working | SSE proxy issue | Check your server supports `text/event-stream` responses |

---

**Terms & Privacy:** [OpenRouter Terms](https://openrouter.ai/terms) | [OpenRouter Privacy](https://openrouter.ai/privacy)
