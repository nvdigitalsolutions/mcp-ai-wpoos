# NV oOS Cloud — Hosted "Managed Tokens" Service

> **Status:** Pro feature shipped in v1.7.0 · Brand: **NV oOS Cloud** · Distribution: **Pro-only**

## Overview

NV oOS Cloud is the **second tier** of AI access alongside the existing BYOK
(Bring-Your-Own-Key) flow. With a single click, a site can route inference
through NV's master OpenRouter account — **no per-provider key management
required**.

| Tier | Distribution | Setup | Billing |
|------|--------------|-------|---------|
| **BYOK** (today, free) | Base + Pro | Paste an OpenAI / Anthropic / OpenRouter key | None — direct provider billing |
| **NV oOS Cloud** (new, paid) | **Pro-only** | Click "Connect NV oOS Cloud" → Stripe Checkout | Wholesale + 7% service fee + Stripe pass-through |

## Why Pro-only?

The WordPress.org Plugin Directory reviewers (we asked) are wary of plugins that
default to a paid SaaS — even when the SaaS is opt-in. Shipping NV oOS Cloud as
a **Pro-only** module avoids the compliance risk and matches the conceptual
distribution split: a paid hosted service belongs in the paid distribution.

## Pricing model

- **Markup:** **7%** on the upstream wholesale token cost (`upstream × 1.07`).
- **Stripe pass-through:** **2.9% + $0.30** per top-up — surfaced as a
  transparent line item on every invoice (the customer always sees what the
  processor took).
- **Minimum top-up:** **$25 USD** — protects margin from Stripe's fixed-fee
  component on small accounts (see plan §6 unit-economics check).
- **Auto-top-up:** opt-in, defaults off; refills the minimum amount when the
  balance drops below $2.
- **Geographic scope:** **Worldwide.** Stripe Tax handles VAT/GST/sales tax
  automatically — no per-jurisdiction setup required.

## Hosting / backend

The SaaS proxy lives at [`addons/cloud-worker/`](../../addons/cloud-worker/) in
this monorepo (for review convenience) but is **deployed independently** as a
Cloudflare Worker — it is not a WordPress plugin and does not run inside
WordPress. The architecture:

```
WordPress (NV oOS plugin)
        │  (HTTPS, Bearer Connect Token)
        ▼
  nvoos.cloud (Cloudflare Worker)
        │  (Cloudflare AI Gateway with revenue-share)
        ▼
  openrouter.ai (master key, never exposed)
        │
        ▼
  OpenAI / Anthropic / Meta / Google / Mistral / …
```

**Cloudflare AI Gateway** gives us caching, rate limiting, observability and
revenue-share metering for free, eliminating most of Phase 1's bespoke
proxy/abuse-control work in the plan.

### Why not Cloudways?

[Cloudways](https://www.cloudways.com/) is a **managed-hosting** provider for
WordPress / LAMP / Node sites — it doesn't offer an AI inference gateway.
You could host the NV oOS Cloud Worker code on Cloudways' Node/Vultr servers,
but you'd lose Cloudflare's per-edge caching, revenue-share, and request
deduplication. **You can absolutely use both:** Cloudways for the
marketing/billing dashboard at `nvoos.pro` and Cloudflare AI Gateway for the
inference proxy at `nvoos.cloud`. This plugin only cares about the
inference endpoint.

## Plugin-side architecture

### Components

| Class | Location | Responsibility |
|---|---|---|
| `WP_MCP_AI_NV_Cloud_Service` | `addons/pro/includes/services/` | Singleton — connect-token storage (encrypted), balance cache, prefs, markup math, ledger. |
| `WP_MCP_AI_NV_Cloud_Client` | `addons/pro/includes/providers/` | OpenAI-compatible HTTP client (subclass of `WP_MCP_AI_OpenRouter_Client`). |
| `WP_MCP_AI_NV_Cloud_Provider_Client` | `addons/pro/includes/providers/` | Adapter implementing `Interface_WP_MCP_AI_Provider_Client`. |
| `WP_MCP_AI_NV_Cloud_Billing_Observer` | `addons/pro/includes/services/` | Hooks `wp_mcp_ai_cost_calculated`, writes ledger entries with wholesale + 7% fee. |
| `WP_MCP_AI_REST_NV_Cloud_Controller` | `addons/pro/includes/rest/` | `/mcp-ai-pro/v1/cloud/{status,connect,disconnect,refresh-balance,topup-url,ledger,prefs}`. |
| `WP_MCP_AI_NV_Cloud_Settings_Page` | `addons/pro/includes/admin/` | Admin UI: Connect, Disconnect, Balance, Top-up, Auto-top-up, Ledger. |
| `nv-cloud-init.php` | `addons/pro/includes/` | Bootstrap — wires the router filter, REST routes, daily cron. |

### Provider id

The router is extended via the new base filter `wp_mcp_ai_route_to_provider`
(see `WP_MCP_AI_Language_Model_Router::route_to_provider()`). When `provider`
is `nv_hosted`, the Pro module short-circuits and creates a chat completion
through `WP_MCP_AI_NV_Cloud_Client`. Add-ons can register additional providers
the same way.

### Cost dashboard

Each chat turn that routes through NV oOS Cloud appends a ledger entry to the
local mirror (capped at 200 entries). The admin page shows three columns:

- **Wholesale** — raw upstream cost from the gateway (`X-NV-Wholesale-Cost`
  response header, falls back to `cost_usd / 1.07`).
- **Service fee (7%)** — markup retained.
- **Total** — what was actually debited from the prepaid wallet.

The authoritative ledger lives on the SaaS — the local mirror is a
pleasant offline view; downloadable monthly statements (PDF + CSV) come from
the SaaS dashboard at `https://nvoos.cloud/`.

## Cloudflare Worker contract

The Worker exposes an OpenAI-compatible REST surface plus a small account
namespace. The plugin calls these endpoints with `Authorization: Bearer <connect_token>`.

### Inference (passthrough)

| Endpoint | Notes |
|---|---|
| `POST /v1/chat/completions` | Standard OpenAI format. SSE streaming preserved. |
| `GET /v1/models` | Mirror of OpenRouter's catalogue, surfaced via Cloudflare AI Gateway. |
| `POST /v1/embeddings` | Optional — when the underlying model exposes it. |

**Custom response headers (added by the Worker):**

- `X-NV-Wholesale-Cost` — upstream USD cost for the request (for transparent ledger).
- `X-NV-Service-Fee` — 7% markup amount.
- `X-NV-Total-Charged` — what was debited from the wallet.

### Account / billing

| Endpoint | Notes |
|---|---|
| `GET  /v1/account/balance` → `{ balance_usd, currency }` | Polled by the plugin's daily cron. |
| `POST /v1/account/topup` → `{ checkout_url }` | Returns a Stripe Checkout session URL. Body: `{ amount_usd, processor_fee, return_url, cancel_url, site_url }`. |
| `POST /v1/account/revoke` | Best-effort token revocation on disconnect. |

## Settings UI flow

```
Plugin admin (NV oOS → NV oOS Cloud)
  ├─ "Connect NV oOS Cloud" button  → opens https://nvoos.cloud/connect?site_url=…
  │      Stripe Checkout for $25 → redirects back with ?token=<connect_token>
  │      Plugin POSTs token to /mcp-ai-pro/v1/cloud/connect
  │
  ├─ "Refresh balance"  → /mcp-ai-pro/v1/cloud/refresh-balance
  ├─ "Top up"           → /mcp-ai-pro/v1/cloud/topup-url → opens Stripe Checkout
  ├─ "Disconnect"       → /mcp-ai-pro/v1/cloud/disconnect (revokes token at SaaS)
  ├─ "Use as default"   → prefs flag; promotes nv_hosted in provider priority list
  └─ "Auto-top-up"      → prefs flag; SaaS handles refills server-side
```

## Security

- **Connect tokens are encrypted at rest** in WP options using AES-256-CBC keyed
  by `AUTH_KEY + SECURE_AUTH_KEY`. The plaintext token is never logged.
- **Site binding** — the SaaS verifies that the `X-NV-Site-Url` header matches
  the URL the token was issued for; mismatched sites are rejected.
- **Capability gate** — every REST endpoint requires `manage_options`.
- **Balance drift** — local cache is updated optimistically after each chat
  turn; daily cron pulls authoritative value.
- **Master key isolation** — the OpenRouter master key never leaves the
  Cloudflare Worker; it cannot be exfiltrated through the plugin.

## Filters and actions

| Hook | Type | Description |
|---|---|---|
| `wp_mcp_ai_nv_cloud_base_url` | filter | Override the SaaS base URL (default `https://nvoos.cloud/v1`). |
| `wp_mcp_ai_nv_cloud_request_headers` | filter | Modify outbound request headers. |
| `wp_mcp_ai_nv_cloud_connected` | action | Fires after a connect token is stored. |
| `wp_mcp_ai_nv_cloud_disconnected` | action | Fires after the connection is wiped. |
| `wp_mcp_ai_nv_cloud_request_billed` | action | Fires per chat turn with `(wholesale, fee, total, cost_data)`. |
| `wp_mcp_ai_route_to_provider` | filter (base) | Lets any add-on register a provider id. |

## Constants

| Constant | Default | Purpose |
|---|---|---|
| `WP_MCP_AI_NV_CLOUD_BASE_URL` | `https://nvoos.cloud/v1` | Override the SaaS endpoint (staging/dev). |
| `WP_MCP_AI_NV_Cloud_Service::MARKUP_RATE` | `0.07` | Service-fee rate (7%). |
| `WP_MCP_AI_NV_Cloud_Service::DEFAULT_MIN_TOPUP_USD` | `25.0` | Stripe-fee-protected minimum top-up. |
| `WP_MCP_AI_NV_Cloud_Service::LOW_BALANCE_THRESHOLD_USD` | `2.0` | When the low-balance banner triggers. |

## Testing

```bash
# Run the NV oOS Cloud test suite
vendor/bin/phpunit --filter Test_WP_MCP_AI_NV_Cloud
```

The test file (`addons/pro/tests/test-nv-cloud.php`) covers service state,
billing-observer math, REST permission gates, top-up minimums, ledger cap,
and router filter wiring.
