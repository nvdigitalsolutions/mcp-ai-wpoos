# Composio Connect Integration - Proposal

**Date:** 2026-08-17
**Status:** 🔮 PENDING
**Estimated Effort:** 10–14 development days (phased; see Effort Estimation)
**Priority:** MEDIUM-HIGH
**Distribution:** Pro addon only (no Base changes)

## Executive Summary

Composio Connect gives AI agents per-user authenticated access to 1,000+ third-party apps (Gmail, Slack, GitHub, Notion, Linear, and more) through a single REST API at `https://backend.composio.dev`. Instead of building and maintaining dozens of bespoke per-app integrations, NV oOS can add **one new `composio` connection type to the existing Pro Remote Site Manager**, backed by a thin PHP API client, six new MCP tools (`composio_*`), a hosted per-user OAuth flow ("Connect Links"), and a webhook receiver for event triggers.

The key architectural win: **third-party OAuth tokens never touch WordPress**. Composio hosts the auth flow, stores and refreshes per-user credentials in "connected accounts", and NV oOS only ever holds its own project API key (encrypted in the existing Remote Site Manager credential store). This proposal recommends integrating via Composio's REST API v3.1 from PHP — **not** by installing the `@composio/core` TypeScript package (rationale recorded in §5.4).

## Problem Statement

1. **Integration surface explosion.** NV oOS Pro currently ships 24 bespoke remote connection types (Gmail, Slack, Shopify, WhatsApp, etc.), each with its own credential schema, validation branch, test-connection method, and tool set. Each new app integration costs days of work and permanent maintenance.
2. **No per-user identity.** Existing Pro OAuth flows (Gmail, Google Drive, Upwork, LinkedIn) are site-wide single identities. There is no way for *each WordPress user* to act on their *own* Gmail/Slack/GitHub account through an assistant.
3. **Credential risk.** Every bespoke integration stores and refreshes third-party OAuth tokens inside WordPress. Each one is an attack surface.
4. **Long-tail gap.** Users regularly request access to apps (Notion, Linear, Airtable, HubSpot, Jira, ...) that will never justify dedicated first-class integrations.

## Proposed Solution

Add Composio as a **meta-connection** in the Pro Remote Site Manager. One connection record unlocks hundreds of apps behind the existing manager abstraction (caching, dedup, retry, health metrics, SSRF guard, encryption).

### 5.1 What Composio Connect provides

| Facet | Mechanism | Benefit to NV oOS |
|---|---|---|
| **Hosted per-user auth ("Connect Links")** | `POST /api/v3.1/connected_accounts/link` returns a hosted sign-in URL; Composio creates/refreshes the user's "connected account" | Per-WP-user OAuth without any OAuth client work; tokens stay in Composio |
| **Tool execution** | `POST /api/v3.1/tools/execute/{slug}` with `connected_account_id` | One execution path for 1,000+ apps |
| **Triggers + webhooks** | `POST /api/v3.1/trigger_instances/{slug}/upsert`; events delivered to a registered webhook subscription with a signing secret | Event-driven automation feeding Pro Workflow Builder / Schedule Manager |
| **Callback identity verification** | `POST /api/v3.1/connected_accounts/complete_auth` redeems a single-use `session_uri` | Defends against OAuth session fixation |

### 5.2 Architecture (three layers + one optional mode)

1. **Connection layer** — new `composio` type in `WP_MCP_AI_Pro_Remote_Site_Manager` / `WP_MCP_AI_Pro_Remote_Sites_Admin`: fields `api_key` (encrypted), `base_url` (default `https://backend.composio.dev`), `webhook_secret` (encrypted), `webhook_subscription_id`, `default_user_mode` (`admin_shared` | `per_wp_user`), `toolkit_allowlist`, `cache_ttl`.
2. **Client layer** — new `WP_MCP_AI_Composio_Client` in `addons/pro/includes/composio/`, wrapping `wp_remote_request()` with `x-api-key` auth, pinned to API **v3.1**, honoring `429`/`Retry-After`.
3. **Experience layer**
   - **Admin UI**: Composio fieldset in the connection edit form, "Connected Accounts" list (cached sync), "Create Connect Link" actions, test connection.
   - **MCP tools**: `composio_list_tools`, `composio_get_tool_schema`, `composio_list_connected_accounts`, `composio_create_connect_link`, `composio_execute_tool`, `composio_manage_triggers`.
   - **Webhook receiver**: `POST /wp-json/mcp-ai/v1/webhooks/composio/{connection_id}` (mirrors chat-channel webhook pattern), HMAC-verified, dispatching `composio.trigger.message`, `composio.connected_account.expired`, `composio.trigger.disabled`.
4. **Optional (deferred)**: Sessions/MCP mode — create a Composio session per assistant and store its hosted MCP URL (upgrade path; not in initial scope).

### 5.3 Base vs Pro placement

| Layer | Placement |
|---|---|
| Connection type, client, tools, webhook controller, admin UI | **Pro only** (`addons/pro/`) |
| Base plugin | **Untouched** — keeps wp.org compatibility; no hooks needed |

### 5.4 Technology decision: no `@composio/core` package

The TypeScript SDK is a wrapper around the same REST API designed for Node/TS agent runtimes. NV oOS is a PHP plugin; installing the package would (a) add a Node build/runtime dependency with zero functional gain, (b) duplicate the client surface, and (c) create a secret-exposure risk if the API key ever reached browser JS. Admin JS calls only our own AJAX/REST endpoints. **Decision: PHP REST client, no Composio SDK.**

## Benefits

**User benefits**
- Hundreds of new app integrations ship with one feature; no per-app setup beyond a Connect Link click.
- Each WordPress user can connect their *own* accounts (per-user auth — a first for NV oOS).
- Event triggers (new Gmail message, Slack mention, Stripe charge) can drive assistant actions and workflows.

**Technical benefits**
- Zero new third-party OAuth token storage in WordPress (only the Composio API key + webhook secret, encrypted via existing AES-256-CBC).
- Reuses existing infrastructure: encryption, `make_request()` caching/dedup/retry/health metrics, SSRF guard, tool registry, webhook hardening patterns, audit logging.
- New apps become configuration (allowlist + Connect Link), not code.

**Business benefits**
- Pro feature moat; replaces future bespoke integration backlog; alignment with composable-AI industry direction (tool aggregators as the standard integration pattern for agent platforms).

## Implementation Plan

Detailed in [`030-composio-connect-integration-implementation-plan.md`](./030-composio-connect-integration-implementation-plan.md). Summary:

| Phase | Deliverable | Est. |
|---|---|---|
| 0. Spike | Validate API with a real key; pin v3.1; confirm endpoint paths | 0.5 d |
| 1. Connection + client | `composio` connection type, `WP_MCP_AI_Composio_Client`, validation, test-connection | 2–3 d |
| 2. Connect Links + accounts | Auth handler (state + `complete_auth`), connected-accounts admin panel, per-user mapping | 2–3 d |
| 3. MCP tools | Six `composio_*` tools + registration + docs | 2 d |
| 4. Triggers & webhooks | REST receiver with HMAC verification, subscription lifecycle, Workflow/Schedule bridge | 2–3 d |
| 5. Assistant UX + release | Metabox "connected apps" surface, guide updates, i18n audit, pot | 1–2 d |

## Effort Estimation

| Phase | Code | Tests | Docs | Total |
|---|---|---|---|---|
| 0. Spike | — | — | research notes | 0.5 d |
| 1 | ~800 lines | ~500 lines | registry table | 2–3 d |
| 2 | ~700 lines | ~400 lines | admin guide | 2–3 d |
| 3 | ~900 lines | ~600 lines | tool reference | 2 d |
| 4 | ~600 lines | ~500 lines | webhook guide | 2–3 d |
| 5 | ~300 lines | — | end-user guide | 1–2 d |
| **Total** | **~3,300 lines** | **~2,000 lines** | | **10–14 d** |

**Dependencies:** None (no new third-party libraries). Composio account with a project API key for integration testing.

## Success Metrics

1. **Functional**: `composio` connection passes "Test Connection"; a Connect Link completes and produces an `ACTIVE` connected account visible in the admin panel.
2. **Tool coverage**: all six `composio_*` tools pass unit + integration tests; `composio_execute_tool` performs one real Gmail/Slack action end-to-end.
3. **Webhooks**: a real trigger event verifies HMAC, dedupes, and reaches the Workflow Builder bridge.
4. **Security**: `wp-security-audit` + `wp-security-secrets` skills pass clean; API key never appears in REST responses, logs, or browser JS.
5. **Quality**: WPCS lint, PHPUnit (including new composio tests), and i18n audit all pass (`composer run ci:all` green for touched files).
6. **Adoption proxy**: one production site activates and connects ≥ 3 toolkits within the first month.

## Decision Required

1. **Identity model for MVP**: site-wide shared accounts (`admin_shared` mode) or per-WP-user connected accounts from day one? (Recommendation: ship `admin_shared` first; `per_wp_user` behind a feature flag.)
2. **Priority toolkits**: which apps define the default allowlist (e.g., Gmail, Slack, GitHub, Notion)?
3. **Triggers in v1**: tools-only first, or include Phase 4 (webhooks) in the initial release?
4. **Region**: is EU-region base URL (`eu.composio.dev`) support required in the UI?

## Constraints & Risks

| Risk | Mitigation |
|---|---|
| Composio API churn (v2→v3→v3.1; hard deprecations on `POST /connected_accounts` for managed OAuth from 2026-05/07) | Pin `v3.1` in a constant; use Connect Links (the non-deprecated path); watch `Deprecation`/`Sunset` headers; isolate all calls in the client class |
| Cost/quota blowouts | Toolkit allowlist, per-connection cache TTL, concurrency guard, rate-limit header surfacing |
| Per-user identity mapping complexity | `admin_shared` default; `per_wp_user` gated |
| Webhook secret rotation | Rotation endpoint support + re-save flow |
| Vendor lock-in | Composio sits behind the existing Remote Site Manager abstraction; switching aggregators = new connection type, zero Base changes |
| wp.org review risk | None — Pro addon only |

## References

- [Composio docs](https://docs.composio.dev/docs) — sessions, Connect Links, authentication
- [Connected Accounts API reference](https://docs.composio.dev/reference/api-reference/connected-accounts) — link, complete_auth, lifecycle, deprecation timeline
- [Tools API reference](https://docs.composio.dev/reference/api-reference/tools) — list/execute/proxy/scopes
- [Triggers API reference](https://docs.composio.dev/reference/api-reference/triggers) — types, active instances, upsert
- [Webhook Subscriptions / Events](https://docs.composio.dev/reference/api-reference/webhook-subscriptions) — signing secret, `composio.trigger.message` (V3)
- Codebase: `addons/pro/includes/admin/README-REMOTE-CONNECTIONS.md`, `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`, `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`, `.context/pro-vs-base.md`
