# Baseten Provider — Setup & Reference

> **NV oOS** supports Baseten Model APIs as a first-class AI provider. Baseten exposes an OpenAI-compatible API that gives you managed, high-performance access to open-source LLMs (DeepSeek, GLM, Kimi) — no deployment step required.

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
- A Baseten account and API key — sign up at [baseten.co](https://app.baseten.co).
- PHP 7.4+ (base plugin requirement).

---

## Quick Start

1. In your WordPress admin, go to **NV oOS → Settings → Providers → Baseten**.
2. Check **Enable Baseten Provider**.
3. Paste your API key from the [Baseten API Keys](https://app.baseten.co/settings/api-keys) page.
4. Choose a default model (e.g. `deepseek-ai/DeepSeek-V3`).
5. Click **Save Changes**.
6. Optionally go to **Tools → Provider Diagnostics** and click **Test Baseten Connection**.

---

## Available Models

Baseten Model APIs offer managed access to popular open-source LLMs. Popular choices:

| Model ID | Provider | Tool Calling | Notes |
|----------|----------|--------------|-------|
| `deepseek-ai/DeepSeek-V3` | DeepSeek | ✅ | General-purpose, recommended |
| `deepseek-ai/DeepSeek-R1` | DeepSeek | ✅ | Chain-of-thought reasoning |
| `zai-org/GLM-4` | Zhipu AI | ✅ | Bilingual Chinese/English |
| `moonshotai/Kimi-K2` | Moonshot AI | ✅ | 256K context, agentic |

Check the [Baseten Model APIs](https://docs.baseten.co/inference/model-apis/overview) docs for the complete catalog and live pricing.

---

## Custom Base URL

If you use a proxy or a custom endpoint, set it in **NV oOS → Settings → Providers → Baseten → Base URL**. The default is `https://inference.baseten.co/v1`.

---

## Tool Calling & Streaming

Baseten uses OpenAI-compatible tool-calling format. All NV oOS tools work identically through Baseten as they do through the direct OpenAI client. Server-Sent Events (SSE) streaming is fully supported. Structured outputs (JSON mode) are supported on most models.

---

## Known Limitations

- **Context windows** vary per model — check the model's Baseten documentation for accurate limits.
- **Rate limits** are set by Baseten. See your Baseten dashboard for current limits and usage.
- **Model availability** may change — Baseten occasionally adds or removes models. If a model ID becomes unavailable, NV oOS will return a provider error.
- **Pricing is per million tokens** and varies per model.

---

## Diagnostics & Testing

Go to **Tools → Provider Diagnostics → Baseten** and click **Test Connection**. This sends a short chat completion (5-token max) to verify your API key and endpoint are reachable.

---

## Troubleshooting

| Problem | Likely Cause | Fix |
|---------|-------------|-----|
| `401 Unauthorized` | Invalid API key | Re-enter your key in Settings → Providers → Baseten |
| `402 Payment Required` | Insufficient credits | Top up your Baseten account at [app.baseten.co](https://app.baseten.co) |
| `429 Too Many Requests` | Baseten rate limit | Add per-model rate limits in NV oOS Settings |
| Model not found | Model ID changed or unavailable | Check the Baseten Model APIs catalog for the current ID |
| Streaming not working | SSE proxy issue | Check your server supports `text/event-stream` responses |

---

**Terms & Privacy:** [Baseten Terms](https://www.baseten.co/terms-and-conditions/) | [Baseten Privacy](https://www.baseten.co/privacy-policy/)
