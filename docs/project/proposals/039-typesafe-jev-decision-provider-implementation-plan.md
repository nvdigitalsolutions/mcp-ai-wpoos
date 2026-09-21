# Implementation Plan 039 — TypeSafe Jev Decision Provider

**Status:** 🔨 In progress
**Date:** 2026-09-21
**Proposal:** `039-typesafe-jev-decision-provider.md`
**Branch:** `feat/typesafe-jev-decision-provider` (PR against `alpha-working` per repo convention)

## Design decisions (locked)

1. **Provider slug:** `typesafe`. **Settings keys:** `enable_typesafe`,
   `typesafe_api_key`, `typesafe_model` (default `jev-latest`),
   `typesafe_base_url` (default `https://api.typesafe.ai`, endpoint
   `/v1/systemone`).
2. **Jev is a decision provider, not a chat provider.** It is deliberately
   **excluded** from every surface that selects the assistant's *chat*
   provider: `WP_MCP_AI_Model_Config::get_available_providers()`,
   `WP_MCP_AI_Admin_Settings::get_available_providers()`, the onboarding
   wizard allowlist, `WP_MCP_AI_Section_General` default-provider
   validation, `add_model_config`/`research_model` valid-provider lists, and
   all chat-client maps (router, parallel dispatcher, page-agent, research
   fallbacks). Selecting Jev as a chat provider would break chat by design.
3. **Two transports, one interface.** New
   `Interface_WP_MCP_AI_Decision_Client` with `decide()`. Implementations:
   `WP_MCP_AI_Typesafe_Client` (native) and the extended
   `WP_MCP_AI_OpenRouter_Client::create_decision()` (bridge; the concrete
   OpenRouter decision client is a thin adapter class in
   `includes/infrastructure/providers/` so the same interface covers both).
4. **OpenRouter Decisions endpoint is alpha.** Default
   `https://openrouter.ai/api/alpha/decisions`, filterable via
   `wp_mcp_ai_openrouter_decisions_endpoint`. 404/route-missing returns a
   clean `WP_Error` carrying a "configure a TypeSafe key" action.
5. **Wire format:** flat `{ model, state, questions }`; questions typed
   `choice|score|noul` with `instructions` + `criteria`; responses normalized
   to `{ model, answers: {name: {type, value, probabilities, confidence}}, usage }`.
6. **Credential resolution is free:** `WP_MCP_AI_Credential_Resolver` is
   provider-generic and already resolves `typesafe_api_key` /
   `TYPESAFE_API_KEY` (env/constant) with zero changes.
7. **Cost model:** input-only billing ($0.042/M input, $0 output) recorded in
   the cost calculator and tool usage logging.

## Phase 0 — Base plugin (this branch)

### 0.1 New files

| File | Purpose |
|---|---|
| `includes/interfaces/interface-wp-mcp-ai-decision-client.php` | `decide( $state, $questions, $options )`, `get_provider_slug()`. Doc-blocked as deliberately distinct from the chat interface. |
| `includes/class-wp-mcp-ai-typesafe-client.php` | Native client modeled on `WP_MCP_AI_OpenRouter_Client`: constants (`DEFAULT_BASE_URL = 'https://api.typesafe.ai'`, `API_ENDPOINT = '/v1/systemone'`, `DEFAULT_MODEL = 'jev-latest'`, `USER_AGENT = 'WP-MCP-AI-Typesafe-Client/1.0'`), `get_api_key()` via settings + Credential_Resolver, `set_api_key()` override, `get_model()`, `get_base_url()`, `decide()`, `normalize_decision_response()`, `handle_api_error()` incl. 429/`retry-after`, `test_connection()`. |
| `includes/infrastructure/providers/class-wp-mcp-ai-typesafe-provider-client.php` | Adapter implementing `Interface_WP_MCP_AI_Decision_Client` (not the chat interface) — container-friendly. |
| `includes/tools/class-wp-mcp-ai-tool-typesafe-decide.php` | Base tool `typesafe_decide` (contract in Phase 0.5). |

### 0.2 Extended files

| File | Change |
|---|---|
| `includes/class-wp-mcp-ai-openrouter-client.php` | Add `DECISIONS_ENDPOINT` constant + `create_decision( $state, $questions, $options )` reusing `get_api_key()` / `build_request_headers()` / `resolve_timeout()`; parse + normalize same shape as the native client; graceful 404/route error with configure action. |
| `includes/bootstrap/loader.php` | Guarded `require_once` for the interface + both new client files, next to the existing provider-client block (~L610). |
| `includes/class-wp-mcp-ai-tool-registry.php` | Register `WP_MCP_AI_Tool_Typesafe_Decide` in `load_default_tools()` base-tool map. |
| `includes/admin/sections/class-wp-mcp-ai-section-providers.php` | New subtab group `typesafe` (icon `dashicons-yes-alt`); fields `enable_typesafe` (checkbox, default false), `typesafe_api_key` (password, env hint `TYPESAFE_API_KEY`), `typesafe_model` (select `jev-latest` / `jev-1.13.0`, pin advice), `typesafe_base_url` (text, default `https://api.typesafe.ai`); section description notes the decision-only nature; add label to `render_provider_priority_list()` display map **only if** harmless — omitted (see decision 2). |
| `includes/admin/class-wp-mcp-ai-provider-diagnostics.php` | Add a TypeSafe (Jev) row: key-configured check via Credential_Resolver + `test_typesafe()` probe calling `test_connection()`. |
| `includes/cli/class-wp-mcp-ai-cli-provider-command.php` | Add `typesafe` to `$provider_labels` + `get_provider_client()` map → `WP_MCP_AI_Typesafe_Client`. |
| `includes/class-wp-mcp-ai-cost-calculator.php` | Add `typesafe` pricing entry: `jev-latest` / `jev-1.13.0` at $0.042/M input, $0 output + `default`. |
| `includes/services/class-wp-mcp-ai-token-usage-service.php` | Add `typesafe` display label "TypeSafe (Jev)". |
| `includes/bridge/class-wp-mcp-ai-wp70-bridge.php` | Add `typesafe` to `CUSTOM_CONNECTORS` (name, description, `api_key` auth, `https://console.typesafe.ai/settings/keys` credentials URL). |
| `includes/data/model-catalog.json` | Append three entries: provider `typesafe` (`jev-1.13.0`, `jev-latest`) with `supports_streaming: false`, `supports_function_calling: false`, input cost $0.042, output $0, context 32768, notes decision-only; provider `openrouter` entry `typesafe/jev-1.13` with notes flagging the Decisions route. Bump `version`/`updated_at`. |

### 0.3 Explicitly NOT changed

- `WP_MCP_AI_Model_Config::get_available_providers()` / `WP_MCP_AI_Admin_Settings::get_available_providers()` (chat dropdowns).
- Onboarding wizard valid-provider list; `WP_MCP_AI_Section_General` validation; `add_model_config` / `research_model` `VALID_PROVIDERS` (chat model configs).
- `WP_MCP_AI_Language_Model_Router`, `WP_MCP_AI_Pro_Parallel_Model_Dispatcher`, page-agent provider maps.
- No Cloudflare wiring (unverified third-party listing).

### 0.4 Tool contract (`typesafe_decide`)

- Schema: `state` (string|object|array, required), `questions` (required map of `{type ∈ choice|score|noul, instructions, criteria}`, ≤32 questions), `model` (optional), `transport ∈ typesafe|openrouter` (default `typesafe`).
- Entry sanitisation (two-gate, gate one): every `instructions`/`criteria` string `sanitize_text_field`; `state` deep-sanitised per type; question names `sanitize_key`.
- Envelope (P0): success array `{ model, answers, usage }` or `WP_Error` — never `success => false`.
- Capability: `edit_posts` registry gate + runtime `user_can( $user_id, 'manage_options' )` (mirrors `add_model_config`; decision calls are admin-scoped plumbing).
- Capability flags: `external-api`, `read`-only semantics, `cost-incurring`.
- Description documents: decision-only model, adversarial-state caveat, input-only billing, pinning guidance.
- Escapes every echoed value at exit (gate two).

### 0.5 Tests

| File | Coverage |
|---|---|
| `tests/test-typesafe-client.php` | Defaults (base URL/model), request body shape (flat, question serialisation), response normalisation incl. fractional `score` + `noul` probabilities, missing-key `WP_Error` with action, 429 handling honouring `retry-after`, input-only usage, `set_api_key()` override, `test_connection()`. HTTP via `pre_http_request` filters (mirrors `test-openrouter-client.php`). |
| `tests/test-typesafe-decide-tool.php` | Schema assertions, sanitisation of nested question strings, envelope shape, capability rejection, invalid `type` → `WP_Error`, transport switch, empty-questions error. |
| `tests/test-openrouter-client.php` (extend) | `create_decision()` shape + 404 route-missing error path. |

### 0.6 Docs & registry

- `docs/` provider page (`docs/providers/typesafe-jev.md` or per existing structure) + `docs/tool-reference.md` entry.
- `changelog.txt` + `readme.txt` note per repo release conventions.
- Update `.agents/skills/mcp-ai-wpoos-plugin/SKILL.md` provider inventory line.
- Proposal + this plan committed under `docs/project/proposals/039-*`.

### 0.7 Verification

- `vendor/bin/phpunit tests/test-typesafe-client.php tests/test-typesafe-decide-tool.php tests/test-openrouter-client.php`
- `composer run lint` (WPCS + the two custom sniffs on the new tool).
- Broader: `vendor/bin/phpunit tests/test-providers-subtab-checkbox-issue.php tests/test-assistant-tools.php`.
- Full suite in Docker before PR (per test-suite skill gate).

## Phase 1 — Pro integrations (separate cluster, post-merge)

- Jev-assisted cascade pre-step in `WP_MCP_AI_Pro_Parallel_Model_Dispatcher` (routing only — never a chat client).
- `research_eca` / `generate_research_report` internal classification via `typesafe_decide`.
- NV Cloud passthrough once OpenRouter GA's the Decisions route (`WP_MCP_AI_NV_Cloud_Client` decision method).
- Content Graph port: interface + client + tool + catalog to `plugins/nvoos-content-graph-ai` per ecosystem-port loop.

## Phase 2 — Stretch

- Cloudflare direct support (blocked on verification CF serves Jev weights).
- Guest-chat moderation Noul guard (Pro).

## Risk register

| Risk | Mitigation |
|---|---|
| Waitlisted TypeSafe access | OpenRouter bridge works with existing OpenRouter keys. |
| `jev-latest` alias drift / SDK churn | Pin-able `typesafe_model`; response logs concrete versioned model; body shape versioned constant. |
| OpenRouter decisions route alpha/404 | Filterable endpoint; clean `WP_Error` + configure-Typesafe action; integration test for the 404 path. |
| Adversarial `state` | Tool description warning; privileged decisions stay behind code gates; no routing of state-changing ops on Jev alone. |
| Self-run vendor benchmarks | Docs state the calibration caveat; no claims of factual accuracy. |
