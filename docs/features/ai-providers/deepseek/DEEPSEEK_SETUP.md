# DeepSeek Provider — Setup & Reference

> **NV oOS** supports DeepSeek as a first-class AI provider. DeepSeek exposes an
> OpenAI-compatible REST API and integrates into NV oOS the same way as
> Anthropic, NVIDIA NIM, and other cloud providers.
>
> **Not to be confused with** the _DeepSeek V4 Orchestration_ design (a
> paper-inspired multi-agent coordination architecture inside NV oOS). See
> [`docs/DEEPSEEK-V4-README.md`](../../DEEPSEEK-V4-README.md) for that.

---

## Contents

1. [Prerequisites](#prerequisites)
2. [Quick Start](#quick-start)
3. [Available Models](#available-models)
4. [Pricing & Off-Peak Window](#pricing--off-peak-window)
5. [Custom Base URL (Proxies & Regional Endpoints)](#custom-base-url)
6. [Tool Calling & Function Calling](#tool-calling--function-calling)
7. [Known Limitations](#known-limitations)
8. [Diagnostics & Testing](#diagnostics--testing)
9. [Troubleshooting](#troubleshooting)

---

## Prerequisites

- NV oOS base plugin active (no Pro addon required).
- A DeepSeek account and API key — sign up at
  [platform.deepseek.com](https://platform.deepseek.com).
- PHP 7.4+ (base plugin requirement).

---

## Quick Start

1. In your WordPress admin, go to **NV oOS → Settings → Providers → DeepSeek**.
2. Check **Enable DeepSeek Provider**.
3. Paste your API key (starts with `sk-…`).
4. Choose a default model (`deepseek-chat` is recommended for most use cases).
5. Click **Save Changes**.
6. Optionally go to **Tools → Provider Diagnostics** and click **Test DeepSeek
   Connection** to verify everything works.

---

## Available Models

| Model ID             | Alias        | Tool Calling | Context  | Notes                                          |
|----------------------|--------------|:------------:|----------|------------------------------------------------|
| `deepseek-chat`      | DeepSeek-V3  | ✅ Yes        | 64 K     | General-purpose. **Recommended default.**      |
| `deepseek-reasoner`  | DeepSeek-R1  | ❌ No         | 64 K     | Chain-of-thought. Tools are stripped automatically. Returns `reasoning_content`. |
| `deepseek-coder`     | Coder variant| ❌ No         | 64 K     | Optimised for code generation.                 |

> **Model discovery:** Live models available on your account can be fetched via
> **Settings → NV oOS → Model Discovery** (the service calls `GET /models`).

---

## Pricing & Off-Peak Window

> Pricing is approximate and subject to change. Verify at
> [platform.deepseek.com/api-docs](https://platform.deepseek.com/api-docs).

| Model               | Input (per 1M tokens) | Output (per 1M tokens) |
|---------------------|-----------------------|------------------------|
| `deepseek-chat`     | $0.27                 | $1.10                  |
| `deepseek-reasoner` | $0.55                 | $2.19                  |
| `deepseek-coder`    | $0.27                 | $1.10                  |

### Off-Peak Discount

DeepSeek offers **~50% discounted pricing** during its off-peak window:

- **Window:** UTC 16:30 – 00:30 (8 hours daily).
- **Discounted `deepseek-chat` rates:** $0.135 input / $0.55 output per 1M tokens.

The NV oOS cost calculator uses standard pricing by default. Off-peak detection
can be enabled for test-accurate math via the filter:

```php
add_filter( 'wp_mcp_ai_deepseek_offpeak_active', '__return_true' );
```

---

## Custom Base URL

The default base URL is `https://api.deepseek.com`. You can override it for:

- **Volcano Engine mirror** (DeepSeek's own alternative endpoint for China).
- **Together AI / other OpenAI-compatible proxies** that expose DeepSeek models.
- **Self-hosted DeepSeek-compatible servers**.

Set the custom URL under **Settings → Providers → DeepSeek → API Base URL
(Optional)**.

Requirements for a compatible custom endpoint:

- Must be HTTPS.
- Must implement the OpenAI-compatible `/chat/completions` and `/models` paths.
- Authentication via `Authorization: Bearer <key>` header.

---

## Tool Calling & Function Calling

`deepseek-chat` supports OpenAI-compatible function/tool calling:

- Pass `tools` as an array of OpenAI-format tool definitions.
- Supports `tool_choice: "auto" | "required" | "none"` and parallel tool calls.
- JSON mode (`response_format: { type: "json_object" }`) is also supported.

`deepseek-reasoner` and `deepseek-coder` do **not** support tool calling. NV oOS
automatically strips the `tools` parameter for these models and logs a warning
— no manual configuration is needed.

---

## Known Limitations

| Limitation              | Detail                                                                                                |
|-------------------------|-------------------------------------------------------------------------------------------------------|
| **No embeddings**       | DeepSeek does not expose a public embeddings endpoint. Use OpenAI or Gemini for embeddings.           |
| **No vision (v1)**      | Vision support (`deepseek-vl2`) is not advertised in v1 to avoid mis-routing. Enable via filter `wp_mcp_ai_deepseek_supports_vision`. |
| **Reasoner tool calls** | `deepseek-reasoner` rejects the `tools` parameter — NV oOS strips it automatically.                  |
| **Token counting**      | DeepSeek has no public token-count endpoint. The plugin uses a character-based heuristic (~4 chars/token). |

---

## Diagnostics & Testing

1. Go to **Tools → Provider Diagnostics** in your WordPress admin.
2. Scroll to the **DeepSeek** tile.
3. Click **Test DeepSeek Connection**.
   - A green success notice means your API key and network connectivity are working.
   - A red error notice shows the HTTP status code and error message returned by
     the API — check your key and internet connectivity.

---

## Troubleshooting

| Symptom                               | Likely Cause                          | Fix                                             |
|---------------------------------------|---------------------------------------|-------------------------------------------------|
| `401 Invalid API key`                 | Wrong or expired API key              | Generate a new key at platform.deepseek.com     |
| `429 Rate limit exceeded`             | Too many requests                     | Wait a few seconds, or upgrade your plan        |
| `tools are stripped` in logs          | Model is `deepseek-reasoner`          | Expected behaviour — switch to `deepseek-chat` for tool calling |
| Models not appearing in Model Config  | Model Discovery not run               | Run Model Discovery from Settings               |
| Custom URL not working                | Incompatible proxy or missing path    | Verify the proxy supports `/chat/completions` and `/models` |

---

*Last updated: May 2026 — DeepSeek API v1*
