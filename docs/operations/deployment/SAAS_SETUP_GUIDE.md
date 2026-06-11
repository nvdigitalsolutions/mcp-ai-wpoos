# SaaS Setup Guide — NV oOS Cloud

> **Status:** Reference implementation guide · Last reviewed: **May 2026** · Version: **1.0**
> **Scope:** Operator-facing install, provisioning, and runbook documentation for the **NV oOS Cloud** SaaS surface (the Cloudflare Worker in [`addons/cloud-worker/`](../addons/cloud-worker/) and the Pro plugin module that consumes it).

This document follows industry-standard SaaS onboarding documentation conventions
([Write the Docs — SaaS onboarding guidance](https://www.writethedocs.org/),
[Atlassian Docs Style Guide](https://atlassian.design/content/), and the
operator-runbook pattern popularized by Google's
[Site Reliability Engineering](https://sre.google/sre-book/table-of-contents/) book).
It is structured around the twelve canonical sections any production SaaS guide is expected to cover:
**Introduction → Prerequisites → Account Provisioning → Authentication & SSO → Initial
Configuration → Billing & Subscription → Security & Compliance → User Management →
Core Setup & Walkthrough → Monitoring & Runbook → Best Practices → Appendices**.

---

## Table of Contents

1. [Introduction](#1-introduction)
2. [Prerequisites](#2-prerequisites)
3. [Account Provisioning](#3-account-provisioning)
4. [Authentication & Connect Tokens](#4-authentication--connect-tokens)
5. [Initial Configuration & Environment Setup](#5-initial-configuration--environment-setup)
6. [Billing & Subscription Management](#6-billing--subscription-management)
7. [Security & Compliance](#7-security--compliance)
8. [User Management](#8-user-management)
9. [Core Setup & Feature Walkthrough](#9-core-setup--feature-walkthrough)
10. [Monitoring, Support & Runbook](#10-monitoring-support--runbook)
11. [Best Practices](#11-best-practices)
12. [Appendices](#12-appendices)

---

## 1. Introduction

**NV oOS Cloud** is the hosted "managed-tokens" SaaS that ships alongside the
NV oOS WordPress plugin. It lets a site route AI inference through a
managed Cloudflare Worker — `nvoos.cloud` — without managing an
OpenAI / Anthropic / OpenRouter API key directly. It is **Pro-only**, opt-in,
and exists in addition to the plugin's existing **BYOK** (Bring-Your-Own-Key)
flow, which remains free and the default.

| Tier | Distribution | Setup | Billing |
|------|--------------|-------|---------|
| **BYOK** | Base + Pro | Paste a provider API key | Direct provider billing |
| **NV oOS Cloud** | **Pro-only** | Click "Connect NV oOS Cloud" → Stripe Checkout | Wholesale + 7% service fee + Stripe pass-through |

This guide covers two audiences:

| Audience | Sections Most Relevant |
|---|---|
| **SaaS operator** (deploys the Worker at `nvoos.cloud`) | §2, §3, §5, §7, §10 |
| **Site administrator** (connects a WordPress site to an existing NV oOS Cloud account) | §3, §4, §6, §8, §9 |

> **Authoritative references.** When this guide and the following documents disagree, the linked documents win:
> - [`docs/features/nv-cloud.md`](features/nv-cloud.md) — feature spec (plugin contract, hooks, constants)
> - [`addons/cloud-worker/README.md`](../addons/cloud-worker/README.md) — Worker source-of-truth (deploy commands, schema)
> - [`docs/deployment/PRODUCTION_DEPLOYMENT.md`](deployment/PRODUCTION_DEPLOYMENT.md) — plugin-side production deploy

---

## 2. Prerequisites

### 2.1 For SaaS operators (deploying the Worker)

| Requirement | Minimum | Notes |
|---|---|---|
| **Cloudflare account** | Workers Paid plan | D1, KV, Workers, AI Gateway are all required. Free plan is **not** sufficient (D1 quotas + Workers CPU). |
| **Custom domain** | DNS managed by Cloudflare | Default `nvoos.cloud`; override via `WP_MCP_AI_NV_CLOUD_BASE_URL` and the `wp_mcp_ai_nv_cloud_base_url` filter for staging. |
| **Cloudflare AI Gateway** | One gateway configured for OpenRouter | Provides cost metering (`cf-aig-cost-usd` header), caching, rate limits, and observability. |
| **OpenRouter account** | Production API key | The single master key the Worker uses to fan out to all upstream providers. Stored as a Wrangler secret — never returned to clients. |
| **Stripe account** | Live mode + Stripe Tax enabled | Required for top-ups, processor-fee passthrough, and worldwide VAT/GST handling. |
| **Node.js** | ≥ 20 LTS | For Wrangler CLI and TypeScript builds. |
| **Wrangler CLI** | Latest stable | `npm i -g wrangler` or via `npx`. |

### 2.2 For site administrators (consuming the SaaS)

| Requirement | Minimum | Notes |
|---|---|---|
| **WordPress** | 6.0+ | See plugin readme for full matrix. |
| **PHP** | 8.1+ | Pro addon requires PHP 8.1+. Base plugin runs on 7.4+. |
| **NV oOS Pro** | Latest | NV oOS Cloud is **Pro-only**. Base alone cannot connect. |
| **`manage_options` capability** | Required | All Cloud REST endpoints are gated on `manage_options`. |
| **Outbound HTTPS to `nvoos.cloud`** | Allowed | If the site is firewalled, allowlist the host. |
| **WordPress secret keys** | `AUTH_KEY` + `SECURE_AUTH_KEY` set | Used to AES-256-CBC-encrypt connect tokens at rest. |

### 2.3 Supported browsers (admin UI)

The Pro admin "NV oOS Cloud" settings page targets the same browser matrix as
WordPress core: the latest two stable releases of Chrome, Firefox, Edge, and
Safari. IE/legacy Edge are **not supported**.

---

## 3. Account Provisioning

### 3.1 Operator-side provisioning (one-time, per environment)

Provisioning a new NV oOS Cloud environment (e.g. `dev`, `staging`, `prod`) is a
six-step process. The full commands live in
[`addons/cloud-worker/README.md`](../addons/cloud-worker/README.md); the
checklist below is the operational sequencing:

1. **Create the D1 database** for the environment, run `schema.sql`, and copy
   the resulting `database_id` into `wrangler.toml` under `[[d1_databases]]`.
2. **Create the KV namespace** (`RATE_KV`) for rate-limit state and bind it.
3. **Configure a Cloudflare AI Gateway** scoped to OpenRouter; copy its URL
   into the `CF_AI_GATEWAY_URL` secret.
4. **Set Wrangler secrets**: `OPENROUTER_API_KEY`, `STRIPE_SECRET_KEY`,
   `STRIPE_WEBHOOK_SECRET`, `CF_AI_GATEWAY_URL`.
5. **Deploy the Worker** (`npm run deploy`) and bind the production hostname
   (e.g. `nvoos.cloud`) in the Cloudflare dashboard.
6. **Configure the Stripe webhook** to `https://<your-host>/stripe/webhook`
   and verify the signing secret matches.

Acceptance criteria for a healthy provisioning:

- [ ] `GET /v1/models` returns a non-empty list and a `X-NV-Worker-Version` header.
- [ ] `POST /stripe/webhook` with a tampered signature returns HTTP 401.
- [ ] D1 table `connect_tokens` exists and is empty.
- [ ] D1 table `wallets` exists.
- [ ] `npm test` and `npm run typecheck` pass against the deployed code.

### 3.2 Site-side provisioning (per WordPress site)

A site administrator provisions a Cloud account by **clicking a single button**
in WordPress admin. The plugin handles the rest:

```
WP-Admin → NV oOS → NV oOS Cloud → "Connect NV oOS Cloud"
   └── opens https://nvoos.cloud/connect?site_url=<urlencoded>
       └── Stripe Checkout for the configured minimum top-up (default $25)
           └── on success, redirects back with ?token=<connect_token>
               └── plugin POSTs the token to /mcp-ai-pro/v1/cloud/connect
                   └── token is encrypted (AES-256-CBC) and stored in wp_options
```

The plaintext connect token is **shown once** (during the redirect) and
**never** logged or persisted in clear. If the redirect is interrupted, the
operator can re-issue a token from the SaaS dashboard.

---

## 4. Authentication & Connect Tokens

NV oOS Cloud uses **bearer tokens** ("Connect Tokens"), not username/password,
not OAuth. There is no SSO surface today; each site has exactly one active
token at a time. Three security primitives back this design:

| Primitive | Where Enforced | Behaviour |
|---|---|---|
| **SHA-256 hashing at rest** | D1 `connect_tokens.token_hash` | Plaintext token is returned only on first issuance and never stored on the SaaS. |
| **Site binding** | Worker — `auth.ts` middleware | The `site_url` recorded at issue time must match the `X-NV-Site-Url` header on every request. Mismatches return HTTP 403. |
| **AES-256-CBC encryption in WordPress** | `WP_MCP_AI_NV_Cloud_Service` | Token is encrypted with a key derived from `AUTH_KEY + SECURE_AUTH_KEY` before being written to `wp_options`. |

### 4.1 Plugin-side authentication

The plugin authenticates **outbound** to the SaaS:

```http
POST /v1/chat/completions HTTP/1.1
Host: nvoos.cloud
Authorization: Bearer <connect_token>
X-NV-Site-Url: https://example.com
Content-Type: application/json
```

### 4.2 Plugin REST authentication (administrator → plugin)

All `/mcp-ai-pro/v1/cloud/*` endpoints require the WordPress
`manage_options` capability and a valid REST nonce. There is no
unauthenticated surface.

### 4.3 Token rotation

Recommended rotation cadence: **every 90 days**, or immediately upon any
suspicion of compromise. Rotation procedure:

1. Site administrator clicks **Disconnect** in the admin UI. The plugin calls
   `POST /v1/account/revoke`, which flips `connect_tokens.revoked_at` on the
   SaaS side.
2. Site administrator clicks **Connect NV oOS Cloud** again to re-provision.
3. The wallet balance and ledger persist across rotations — only the bearer
   secret changes.

---

## 5. Initial Configuration & Environment Setup

### 5.1 Operator-side configuration

| Concern | Where | Default |
|---|---|---|
| Markup rate | `WP_MCP_AI_NV_Cloud_Service::MARKUP_RATE` (plugin) **and** `MARKUP_RATE` constant in the Worker | `0.07` (7%) |
| Minimum top-up | `DEFAULT_MIN_TOPUP_USD` | `25.0` USD |
| Low-balance threshold | `LOW_BALANCE_THRESHOLD_USD` | `2.0` USD |
| Auto-top-up default | Off | Opt-in per site |
| Rate limits | Cloudflare AI Gateway dashboard | Per-token + global |
| Cost ceilings | Cloudflare AI Gateway dashboard | Per-token + global |

> **Critical:** the plugin and Worker compute the markup independently and must
> agree to the cent. If you change `MARKUP_RATE` on one side without the
> other, every request will fail the version-skew check (see
> `X-NV-Worker-Version`).

### 5.2 Site-side configuration

After connecting, three optional preferences become available in the admin
UI:

| Preference | Effect |
|---|---|
| **Use as default provider** | Promotes `nv_hosted` to the top of the provider priority list — assistants that don't pin a specific provider will route through NV oOS Cloud. |
| **Auto-top-up** | When wallet balance drops below `LOW_BALANCE_THRESHOLD_USD`, the SaaS issues a fresh Stripe charge for `DEFAULT_MIN_TOPUP_USD` server-side. |
| **Custom base URL** (constant only) | Override `WP_MCP_AI_NV_CLOUD_BASE_URL` (or filter `wp_mcp_ai_nv_cloud_base_url`) to point at a staging Worker. |

### 5.3 Multi-environment matrix

| Environment | Plugin constant | Worker secret set | Stripe mode |
|---|---|---|---|
| **Local dev** | `WP_MCP_AI_NV_CLOUD_BASE_URL = 'http://localhost:8787/v1'` | `.dev.vars` | `sk_test_…` |
| **Staging** | `WP_MCP_AI_NV_CLOUD_BASE_URL = 'https://staging.nvoos.cloud/v1'` | Wrangler secrets on staging Worker | `sk_test_…` |
| **Production** | unset (defaults to `https://nvoos.cloud/v1`) | Wrangler secrets on prod Worker | `sk_live_…` |

---

## 6. Billing & Subscription Management

NV oOS Cloud is **prepaid**, not subscription. There are no monthly minimums,
no tiers, and no contracts. Customers fund a wallet and consume tokens against
it.

### 6.1 Pricing model

```
wholesale_usd  = upstream_cost          (from Cloudflare AI Gateway / OpenRouter)
service_fee    = round(wholesale_usd × 0.07, 6)
total_charged  = wholesale_usd + service_fee
```

Stripe processor fees (`2.9% + $0.30`) are applied **once per top-up**, not per
request, and are surfaced as a transparent line item in the Stripe Checkout
description.

### 6.2 Top-up flow

1. Site administrator clicks **Top up** in the admin UI.
2. Plugin calls `POST /v1/account/topup` with `{ amount_usd, processor_fee, return_url, cancel_url, site_url }`.
3. Worker creates a Stripe Checkout session and returns `{ checkout_url }`.
4. Site administrator pays in Stripe Checkout; Stripe Tax handles VAT/GST.
5. Stripe fires `checkout.session.completed` to `/stripe/webhook`.
6. Worker verifies the `Stripe-Signature` (HMAC-SHA-256, constant time),
   idempotently credits the wallet using `event.id` as the dedupe key, and
   appends a ledger row.

### 6.3 Ledger

| Surface | Authority | Retention |
|---|---|---|
| **SaaS-side ledger** (D1 `ledger`) | **Authoritative** — used for monthly statements (PDF + CSV) generated by `nvoos.cloud`. | Indefinite. |
| **Plugin-side ledger mirror** | Convenience view in WP-Admin. | Capped at 200 entries. |

### 6.4 Refunds & disputes

Refunds are issued through Stripe in the SaaS dashboard. Wallet balance is
adjusted by a compensating ledger entry; the plugin's ledger mirror is
updated on the next `refresh-balance` poll (daily cron, or on-demand from
the admin UI).

---

## 7. Security & Compliance

### 7.1 Threat model

| Threat | Control |
|---|---|
| **Master key exfiltration** | OpenRouter master key is a Wrangler secret, never in client requests, logs, or D1. |
| **Token replay from a different site** | `X-NV-Site-Url` header verified against `connect_tokens.site_url` on every request. Mismatches → HTTP 403. |
| **Token theft from WordPress DB** | AES-256-CBC encryption keyed by `AUTH_KEY + SECURE_AUTH_KEY`. |
| **Stripe webhook forgery** | Constant-time HMAC-SHA-256 signature verification with `STRIPE_WEBHOOK_SECRET`. |
| **Webhook replay (double-credit)** | Idempotency via `topup_sessions.event_id` primary-key dedupe. |
| **PII leakage** | Ledger stores token **counts**, never message bodies. |
| **Unauthenticated REST surface** | Every plugin REST endpoint requires `manage_options`. |

### 7.2 Compliance posture

NV oOS Cloud inherits the broader plugin's compliance documentation:

| Framework | Status | Reference |
|---|---|---|
| ISO/IEC 27001:2022 | 100% (83 / 83 controls) | [`compliance/iso27001/`](compliance/iso27001/) |
| SOC 2 Trust Services | 100% (54 / 54 criteria) | [`compliance/soc2/`](compliance/soc2/) |
| HIPAA Security Rule | 98% (42 / 43 safeguards) | [`compliance/hipaa/`](compliance/hipaa/) |
| GDPR / UK-GDPR | Data Processing Addendum on request | [`SECURITY.md`](../SECURITY.md) |

Full posture references: [`docs/HIPAA_POSTURE.md`](HIPAA_POSTURE.md),
[`docs/compliance/`](compliance/).

### 7.3 Incident response

- **Vulnerability disclosure:** [`SECURITY.md`](../SECURITY.md).
- **Operator runbook for token compromise:** revoke the token via
  `POST /v1/account/revoke`, rotate `OPENROUTER_API_KEY`, and review
  the D1 `ledger` for anomalous request volumes.
- **Stripe incident escalation:** Stripe Radar alerts are routed to the
  operator email configured in the Stripe dashboard.

---

## 8. User Management

NV oOS Cloud does **not** define its own user model on the SaaS side. Identity
and authorization are anchored in WordPress:

- One **WordPress site** ↔ one **NV oOS Cloud account** ↔ one active **connect token**.
- Within a site, any administrator with `manage_options` can connect, top up,
  or disconnect.
- For sites that need finer-grained delegation, gate the admin page with
  a custom capability via the standard WordPress `map_meta_cap` filter and the
  `wp_mcp_ai_nv_cloud_request_headers` filter to inject auditing metadata.

For team-scoped budgets, see
[`docs/orchestration-reference.md`](orchestration-reference.md) §6 (Phase 6
Team Budget Manager) — that subsystem applies a per-team daily cap *on top of*
NV oOS Cloud's wallet, independently of the SaaS.

---

## 9. Core Setup & Feature Walkthrough

### 9.1 End-to-end "first inference" path (site administrator)

| Step | Where | Outcome |
|---|---|---|
| 1. Activate NV oOS Pro | WP-Admin → Plugins | "NV oOS Cloud" submenu appears under "NV oOS". |
| 2. Open NV oOS Cloud settings | WP-Admin → NV oOS → NV oOS Cloud | Settings page renders with "Connect NV oOS Cloud" CTA. |
| 3. Click **Connect NV oOS Cloud** | Settings page | Redirects to `nvoos.cloud/connect?site_url=…`. |
| 4. Complete Stripe Checkout for $25 | Stripe Checkout | Wallet funded. Connect token issued. |
| 5. Land back in WP-Admin | Settings page | Token stored encrypted; balance $25 displayed. |
| 6. (Optional) Tick **Use as default provider** | Settings page | `nv_hosted` promoted in router. |
| 7. Open any assistant and send a message | Chat UI | Inference routes through `nvoos.cloud`. |
| 8. Open the **Ledger** tab | Settings page | Wholesale, fee, total displayed for the most recent turns. |

### 9.2 Operator setup checklist

```
[ ] Cloudflare account on Workers Paid plan
[ ] Custom domain configured in DNS
[ ] AI Gateway created and OpenRouter master key configured
[ ] D1 database created and schema applied (schema.sql)
[ ] KV namespace bound (RATE_KV)
[ ] Wrangler secrets set (OPENROUTER_API_KEY, STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET, CF_AI_GATEWAY_URL)
[ ] Worker deployed (npm run deploy) and custom hostname bound
[ ] Stripe webhook configured at /stripe/webhook
[ ] Smoke test: GET /v1/models returns 200 with X-NV-Worker-Version
[ ] Smoke test: tampered Stripe signature returns 401
[ ] Cloudflare AI Gateway rate limits + cost ceilings configured
```

### 9.3 Hooks and constants for integrators

The Pro plugin module exposes the following hooks for downstream addons:

| Hook | Type | Description |
|---|---|---|
| `wp_mcp_ai_nv_cloud_base_url` | filter | Override the SaaS base URL (default `https://nvoos.cloud/v1`). |
| `wp_mcp_ai_nv_cloud_request_headers` | filter | Modify outbound request headers. |
| `wp_mcp_ai_nv_cloud_connected` | action | Fires after a connect token is stored. |
| `wp_mcp_ai_nv_cloud_disconnected` | action | Fires after the connection is wiped. |
| `wp_mcp_ai_nv_cloud_request_billed` | action | Fires per chat turn with `(wholesale, fee, total, cost_data)`. |
| `wp_mcp_ai_route_to_provider` | filter (base) | Lets any add-on register a provider id. |

Full hook reference: [`docs/features/nv-cloud.md`](features/nv-cloud.md) §"Filters and actions".

---

## 10. Monitoring, Support & Runbook

### 10.1 Metrics & dashboards

| Surface | What to watch | Alert threshold |
|---|---|---|
| Cloudflare AI Gateway analytics | Request volume, error rate, p95 latency, blocked-by-rate-limit count | Sustained 5xx > 1% over 5 min |
| Cloudflare Workers analytics | CPU time, subrequests, exception rate | CPU > 80% of limit over 5 min |
| D1 metrics | Read/write QPS, storage used | Storage > 80% of plan |
| Stripe dashboard | Successful checkouts, failed charges, dispute rate | Dispute rate > 0.5% rolling 30 days |
| Plugin admin "Cloud" page | Wallet balance, low-balance banner | Balance < `LOW_BALANCE_THRESHOLD_USD` |

### 10.2 Logs

| Component | Where | Notes |
|---|---|---|
| Worker | `wrangler tail` (live) or Cloudflare Logpush | Structured JSON; never logs the plaintext connect token. |
| Plugin | WordPress error log + `wp option get wp_mcp_ai_recent_errors --format=json` | Bearer tokens are scrubbed from request logs. |

### 10.3 Common incidents

| Symptom | Likely Cause | First Response |
|---|---|---|
| Plugin shows "Disconnected" after working previously | Token revoked SaaS-side, or `AUTH_KEY` rotated and the encrypted token can no longer be decrypted | Re-connect via the admin UI. If `AUTH_KEY` was rotated, re-issue from the SaaS dashboard. |
| All chat turns return HTTP 403 from `nvoos.cloud` | Site URL changed (e.g. `http` → `https`, or domain migration) breaking site-binding | Issue a new token bound to the new URL via the SaaS dashboard. |
| Wallet credited twice after a Stripe top-up | Webhook idempotency miss (extremely rare) | Verify `topup_sessions` table for duplicate `event_id`; raise a Stripe refund if needed. |
| `X-NV-Worker-Version` mismatch warning in the plugin | Plugin and Worker on incompatible versions | Update whichever side is older; the markup math contract must match. |
| Latency spike on streaming responses | Cloudflare edge issue or upstream provider latency | Check Cloudflare status page, then OpenRouter status page; AI Gateway will surface upstream timings. |

### 10.4 Support channels

- **Issues:** <https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues>
- **Security disclosures:** [`SECURITY.md`](../SECURITY.md)
- **Plugin troubleshooting:** [`docs/deployment-troubleshooting.md`](deployment-troubleshooting.md)

---

## 11. Best Practices

### 11.1 For SaaS operators

- **Pin the Worker version** (`name = "nvoos-cloud-prod"`) and bump it explicitly via deploys, not on every commit.
- **Run staging first.** Use a separate D1 database and Stripe test-mode account for staging — never share secrets with production.
- **Set per-token cost ceilings** in the Cloudflare AI Gateway dashboard. A misconfigured assistant could otherwise drain a wallet rapidly.
- **Never log full request/response bodies.** The Worker is designed to log only metadata. Audit `console.log` calls before each deploy.
- **Rotate `OPENROUTER_API_KEY` quarterly** (Wrangler `secret put` is zero-downtime).
- **Backup D1 nightly** via `wrangler d1 export`. Without the `wallets` and `ledger` tables you have no source of truth for refunds.

### 11.2 For site administrators

- **Enable auto-top-up only on production sites** that you actively monitor; on staging, prefer manual top-ups so a runaway test loop doesn't autobill.
- **Rotate the connect token after any administrator role change** that removes a previously-trusted user.
- **Pin `nv_hosted` only for assistants that benefit** from multi-provider routing. For BYOK-only assistants, leave the default provider unchanged.
- **Monitor the low-balance banner.** Daily cron polls authoritative balance, but a high-volume burst can drain a wallet between cron ticks.
- **Use a dedicated `WP_MCP_AI_NV_CLOUD_BASE_URL` constant for staging sites**, never the production hostname with a test connect token.
- **Keep `AUTH_KEY` and `SECURE_AUTH_KEY` stable.** Rotating either forces a fresh **Connect** because previously-encrypted tokens cannot be decrypted.

### 11.3 General

- **Treat the markup rate as a contract.** It is hard-coded in two places (plugin + Worker). Changes require a coordinated release.
- **Prefer the plugin's REST surface** (`/mcp-ai-pro/v1/cloud/*`) for any custom UI rather than reaching into options directly. The encryption layer is non-trivial.
- **Don't fork the Worker without forking the plugin module.** The version-skew check (`X-NV-Worker-Version`) will fail.

---

## 12. Appendices

### 12.1 Glossary

| Term | Definition |
|---|---|
| **BYOK** | Bring-Your-Own-Key — the free, default flow where the site holds its own provider API key. |
| **Connect Token** | The bearer secret issued by the SaaS to a site, SHA-256-hashed at rest on the SaaS, AES-256-CBC-encrypted at rest in WordPress. |
| **Markup rate** | The fraction added on top of upstream wholesale cost. Default `0.07` (7%). |
| **Service fee** | `wholesale × markup_rate`, rounded to 6 decimal places. |
| **Site binding** | The mechanism that pins a connect token to the URL it was issued for via the `X-NV-Site-Url` header. |
| **Wallet** | Prepaid USD balance held on the SaaS in D1 `wallets`. |
| **Ledger** | Append-only D1 table recording wholesale + fee + total per request. Authoritative. |
| **Wholesale cost** | The upstream USD cost reported by Cloudflare AI Gateway / OpenRouter (`cf-aig-cost-usd` header). |

### 12.2 Endpoint quick reference

#### Inference (passthrough)

| Method | Path | Notes |
|---|---|---|
| `POST` | `/v1/chat/completions` | OpenAI-compatible. SSE preserved. |
| `GET` | `/v1/models` | Mirror of OpenRouter's catalogue. |
| `POST` | `/v1/embeddings` | Where the underlying model exposes it. |

#### Account / billing

| Method | Path | Notes |
|---|---|---|
| `GET` | `/v1/account/balance` | `{ balance_usd, currency }`. |
| `POST` | `/v1/account/topup` | Body: `{ amount_usd, processor_fee, return_url, cancel_url, site_url }` → `{ checkout_url }`. |
| `POST` | `/v1/account/revoke` | Best-effort connect-token revocation. |

#### Webhooks

| Method | Path | Notes |
|---|---|---|
| `POST` | `/stripe/webhook` | Stripe-signed; verifies HMAC-SHA-256 with `STRIPE_WEBHOOK_SECRET`. |

#### Plugin REST (administrator → plugin)

| Method | Path | Notes |
|---|---|---|
| `GET` | `/mcp-ai-pro/v1/cloud/status` | Connection + balance. |
| `POST` | `/mcp-ai-pro/v1/cloud/connect` | Stores a connect token (encrypted). |
| `POST` | `/mcp-ai-pro/v1/cloud/disconnect` | Wipes local state and revokes upstream. |
| `POST` | `/mcp-ai-pro/v1/cloud/refresh-balance` | On-demand balance pull. |
| `GET` | `/mcp-ai-pro/v1/cloud/topup-url` | Returns a Stripe Checkout URL. |
| `GET` | `/mcp-ai-pro/v1/cloud/ledger` | Local mirror, capped at 200 entries. |
| `GET`/`POST` | `/mcp-ai-pro/v1/cloud/prefs` | Reads/writes default-provider and auto-top-up flags. |

### 12.3 Related documentation

| Document | Purpose |
|---|---|
| [`docs/features/nv-cloud.md`](features/nv-cloud.md) | NV oOS Cloud feature spec (plugin contract, hooks, constants). |
| [`addons/cloud-worker/README.md`](../addons/cloud-worker/README.md) | Cloudflare Worker source-of-truth (deploy commands, schema). |
| [`docs/deployment/PRODUCTION_DEPLOYMENT.md`](deployment/PRODUCTION_DEPLOYMENT.md) | Plugin-side production deploy guide. |
| [`docs/PRODUCTION_SETUP.md`](PRODUCTION_SETUP.md) | Composer / autoloader posture for production. |
| [`docs/HIPAA_POSTURE.md`](HIPAA_POSTURE.md) | HIPAA compliance posture. |
| [`docs/compliance/`](compliance/) | ISO 27001, SOC 2, HIPAA detailed control mappings. |
| [`SECURITY.md`](../SECURITY.md) | Vulnerability disclosure. |
| [`CONTRIBUTING.md`](../CONTRIBUTING.md) | PR process and quality gates. |

### 12.4 Change log

| Version | Date | Notes |
|---|---|---|
| 1.0 | 2026-05-05 | Initial industry-standard SaaS setup guide for NV oOS Cloud. |
