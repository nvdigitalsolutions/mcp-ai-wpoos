# Chat Tester — Review & Enhancement Plan

> **Date:** 2026-08-26
> **Status:** ✅ Fully implemented (Phases 0–4 — including the graph-context checkbox and the transient-cached model catalogue endpoint).
> **Scope:** Admin "Chat Tester" tab (`admin.php?page=nvoos-content-graph&tab=ai_chat_ui`) in `plugins/nvoos-content-graph-ai`
> **Constraint:** It is a *tester*, not a product chat UI. Keep it dependency-free (no build step). Align its wire contract with SPA-v2 (`addons/pro/assets/spa-v2/src/sse-adapter.ts`) so the three chat surfaces never diverge.

---

## 1. Review Findings (Verified)

The tester is a thin shell over the core's agentic chat engine. The stack is:

```
ChatInterface.php (PHP section, renders markup + config)
  → assets/js/content-graph-ai-chat.js (vanilla JS, SSE parser)
    → POST /nvoos-content-graph/v1/ai/chat  (ChatController.php)
      → CoreBridge → ChatOrchestrator::handleChatStreaming()
        → ProviderRouter → provider client  (lib/core)
        → SseHandler (SSE framing, lib/core)
```

Every finding below was verified against current source.

### 1.1 Root cause of "no response comes back"

The server emits the final assistant text twice:

1. **Token deltas** — `event: message` frames with `{choices:[{delta:{content}}]}` (ChatOrchestrator `$onStreamChunk`).
2. **Final frame** — one last `event: message` with `{assistant_id, data, tool_results, cost}` (ChatOrchestrator L1248-1256).

`handleEvent()` in `content-graph-ai-chat.js` (L210-222) renders the final frame's `tool_results` and `cost` but **never renders `payload.data`**. So whenever deltas don't arrive — provider request buffered/failed mid-way, chunk callback skipped, connection drop — the user gets a permanently pulsing "Thinking…" bubble with no content. That is exactly the reported symptom.

Additional contributors to the same symptom:

- **Provider clients buffer the whole upstream response** (`OpenAiCompatibleClient::stream()`, `GeminiClient::stream()` call `$this->http->send()` first, parse after). Deltas only exist once the full upstream response is in. Any upstream failure produces a *clean* `event: error` frame (handled), but a partial/timing failure can leave only the final frame — which the JS drops.
- **Stuck "Thinking…" is never cleared** when the final frame carries the content. The thinking class is only removed by the delta branch (`thinkingEl.classList.remove('nvoos-chat-thinking')`). If the final frame is the only carrier of text, the bubble keeps the `nvoos-chat-thinking` class + the literal "Thinking…" prefix (matches the pasted DOM from the reporter).
- **`buildHistory()` includes the stuck bubble** (`content.textContent` starts with "Thinking…"), corrupting the next turn's history.

### 1.2 The Model selector is a dead control

- PHP renders only `<option value="">Default</option>`; the JS has no code that populates it.
- Even if populated, the REST route declares args `messages|provider|stream` only — undeclared body params are stripped by WP REST validation, and `ChatController::handleChat()` never reads `model`. The orchestrator therefore always falls back to `ContentGraphSettingsStore::getDefaultModel()`.
- There is no models endpoint. `ProviderRouter::listAllModels()` and each provider's `listModels()` exist but are unused.

### 1.3 Chat Settings tab has zero effect on the tester

`ChatController` builds `$options` with only `provider`. `temperature` / `max_tokens` from the "Chat Behavior" tab (`ChatSettings.php`) are never read, never forwarded, and the orchestrator does not merge settings defaults into `$options` (providers only honor `$options['temperature']` etc.).

### 1.4 The tester never tests the knowledge graph

`ChatController` passes `assistantConfig = array()`, so `buildAllowedTools([])` returns no tools. The agentic loop is a **bare LLM chat** — none of the 13 AI tools nor the graph query tools can run, despite the empty-state copy promising "Your knowledge graph is available as context."

### 1.5 Cost badge never renders

`showCost()` reads `cost.total_cost` / `cost.total_tokens`. The server emits `CostCalculator::calculateFromResponse()` → `{cost_usd, provider, model, is_estimated}` (+ `prompt_tokens`, `completion_tokens`, `agentic_accumulated` in the streaming path). Key names don't match — the badge is always empty.

### 1.6 Provider dropdown is a hardcoded map, not the live registry

`ChatInterface::getAvailableProviders()` hardcodes 13 providers/labels. It is not filtered to providers actually registered (`ProviderRouter::has()`) or to those with credentials (`ContentGraphSettingsStore::hasCredentials()`). The `GET /ai/providers` endpoint (registered slugs) exists but is never called. The default selection is always the first `<option>` (OpenAI) — `ai_default_provider` from settings is ignored, so users with only a Gemini key get a confusing failure until they manually switch.

### 1.7 SSE parser is fragile (vs. the proven SPA-v2 parser)

`streamResponse()` splits on `\n` and routes purely on payload shape; it ignores the `event:` field entirely, doesn't handle multi-line `data:` blocks, and treats `[DONE]`/blank lines ad hoc. The SPA-v2 `parseSseBuffer()` (split on `\n\n`, track `eventType`, force `type:'error'` for `event: error`) is the correct reference. Consequences today:

- `event: status` with `{type:'rejected'}` (pre-step policy rejection, ChatOrchestrator L916) renders nothing.
- HTTP-level failures (`!res.ok`) throw `HTTP <status>` and discard the WP REST error JSON (`{code,message,data}`) → user sees only the generic i18n string.

### 1.8 Minor bugs / gaps

| # | Issue | Location |
|---|---|---|
| 1 | `onClear()` uses `cfg.i18n.placeholder` ("Type your message…") as the empty-state text | `content-graph-ai-chat.js` L61-65 |
| 2 | No conversation persistence — history is rebuilt from DOM bubbles (last 20), losing tool messages; page reload wipes the chat | JS `buildHistory()` |
| 3 | No markdown rendering — long LLM answers render as one escaped text blob | JS |
| 4 | No cancel/stop button, no retry, no copy, no per-message meta (provider/model/iterations/tokens) | UI |
| 5 | `innerHTML += escapeHtml(token)` per token — O(n²) DOM churn (acceptable for a tester, but see P2) | JS |
| 6 | No JS tests; only one PHP integration test file | `tests/` |
| 7 | Provider/model selection not remembered across reloads | JS |
| 8 | `handleChatStreaming()` returns `[]` on policy rejection but sends `[DONE]` — client has no way to distinguish "empty response" from "rejected" | lib/core |

---

## 2. Current Data Flow

```mermaid
flowchart TD
    A[Admin Chat Tester UI] -->|POST /ai/chat stream=true| B[ChatController]
    B -->|assistantConfig=[]| C[ChatOrchestrator handleChatStreaming]
    C --> D[ProviderRouter stream]
    D --> E[Provider client - buffered upstream call]
    E -->|onChunk tokens| F[SseHandler event: message deltas]
    C -->|final| G[event: message data + tool_results + cost]
    C -->|errors| H[event: error]
    F --> I[JS parser]
    G -->|data dropped| I
    H --> I
    I --> J[DOM]
```

---

## 3. Enhancement Plan

### Phase 0 — Make responses reliable (bug fixes, ~2 h)

**Goal:** the tester always shows *something* — the answer, or an actionable error.

1. **Render the final frame's content** — in `handleEvent()`, when `payload.data` exists, extract `payload.data.choices[0].message.content` and append it if the delta path didn't already stream it (track an `assembled` flag per turn). Also clear the `nvoos-chat-thinking` class and strip the "Thinking…" prefix whenever real content lands (delta *or* final frame).
2. **Robust SSE parser** — port the SPA-v2 approach into the vanilla file: buffer on `\n\n` event boundaries, read the `event:` name, join multi-line `data:` fields, JSON-parse with a `message_delta` fallback, ignore `[DONE]`, and force `type:'error'` for `event: error`. Keep the existing payload router on top.
3. **Handle `type:'rejected'`** status frames — render a distinct "Request rejected by policy" notice.
4. **Parse REST error bodies** — on `!res.ok`, attempt `res.json()` and surface `message` (fall back to the generic string + status).
5. **Fix the cost badge** — read `cost_usd` (+ `prompt_tokens`/`completion_tokens`/`agentic_iterations_count` when present), label `~` when `is_estimated`.
6. **Fix empty-state i18n** — add a dedicated `empty` string to the config; use it in `onClear()` and the initial server-rendered state.
7. **Guard `buildHistory()`** — skip `nvoos-chat-thinking` bubbles and tool cards so history stays clean.

### Phase 1 — Make the REST contract honest (~2.5 h)

**Goal:** controls that exist actually work; the tester can exercise the product's core (graph tools).

8. **Pass `model` through** — declare `model` in the route `args` (sanitize), read it in `handleChat()`, forward to `$options`.
9. **Pass `temperature` / `max_tokens` through** — declare both; default them from settings (`ai_temperature`, `ai_max_tokens`) so the Chat Behavior tab starts affecting the tester.
10. **New endpoint `GET /ai/chat/config`** (or extend `/ai/providers`) returning:
    - `providers`: registered slugs + labels + `configured` (has credentials) — server-filtered, not hardcoded,
    - `defaultProvider` / `defaultModel` from settings,
    - `temperature` / `max_tokens` defaults,
    - optionally per-provider model lists (transient-cached, lazily — see Phase 2).
11. **Optional tool toggle (P1.5)** — add a `tools` arg (array of slugs) and a toolbar toggle `Tools: none | graph` in the tester. `graph` forwards an allowlist (e.g. `nvoos_content_graph_query_graph` + safe AI tools) into `$options['tools']` / `assistantConfig['tools']` so the tester genuinely exercises the agentic loop. Default `none` to keep the tester cheap.

### Phase 2 — UI polish (tester-grade, ~3 h)

12. **Live provider + model dropdowns** — populate from the config endpoint; preselect `defaultProvider`; show a "no key configured" hint next to unconfigured providers instead of failing at send time. Model dropdown gets `Default (<defaultModel>)` + lazily-fetched models when the provider is changed (transient-cached server-side; hide on failure, don't block).
13. **Markdown-lite rendering** — no build step: render fenced code blocks, inline code, bold, lists, and links with a small hand-rolled renderer that still escapes everything (or reuse `marked`+DOMPurify only if already bundled by the core plugin — verify before adding a dependency). Toggle "Raw" view per message for debugging.
14. **Stop button** — `AbortController` to cancel the fetch; surface a "cancelled" state.
15. **Per-message meta footer** — provider · model · iterations · tokens · cost (fed by the final frame).
16. **sessionStorage persistence** — per-tab history (aligned with `CHAT-SHORTCODE-PLAN.md` Step 1), so accidental reloads don't destroy the session.
17. **Copy button** on assistant messages; Retry on error bubbles.
18. **Debug panel** — collapsible `<details>` showing raw SSE frames + timings for the last turn (the single most valuable feature for a *tester*).
19. **Remember last provider/model** in `user_meta` (admin only) or sessionStorage.

### Phase 3 — Alignment & quality (~2 h)

20. **Shared frame parser** — extract `parseSseFrames()` + `routeFrame()` into a small, testable module (`assets/js/content-graph-ai-sse.js`) consumed by the admin JS now and by the planned `[nvoos_content_graph_chat]` shortcode later (per `CHAT-SHORTCODE-PLAN.md` §"Refactor opportunity").
21. **JS unit tests** — mirror chat-spa's vitest setup for the parser/router (delta, final-frame, error, rejected, multi-line data, `[DONE]`).
22. **PHP tests** — `ChatController` arg passthrough (model/temperature/max_tokens/tools), config endpoint shape.
23. **i18n audit** — every new string through `wp_add_inline_script` config / `__()`.

### Phase 4 — Optional (only if wanted)

24. Graph-context checkbox — system prompt injection via RAG/embeddings **with a keyword-search fallback over node labels** (works without an embeddings index; availability is based on graph node count).
25. Model catalog endpoint with transient caching across all providers (`ProviderRouter::listAllModels()`).

---

## 4. Alignment Matrix (what stays shared with SPA-v2)

| Contract element | Tester | SPA-v2 |
|---|---|---|
| POST `/nvoos-content-graph/v1/ai/chat` | ✅ | ✅ |
| Delta frames `{choices:[{delta:{content}}]}` | ✅ | ✅ |
| Final frame `{assistant_id,data,tool_results,cost}` | ✅ (after P0.1) | ✅ |
| `event: error` → `{code,message}` | ✅ (after P0.2) | ✅ |
| `tool_start`/`tool_result` frames | ✅ | ✅ |
| Cost keys `cost_usd` / `is_estimated` | ✅ (after P0.5) | ✅ |
| Markdown + rich tool cards | lite (P2.13) | full |

Deliberately **out of scope** (per the "it's a tester" constraint): threads/transcripts, assistant/agent CRUD, command palette, HITL, memory preferences, media attachments — all Pro SPA-v2 features (see `CHAT-SHORTCODE-PLAN.md` §"What Stays Different").

---

## 5. Verification Checklist

- [ ] Send a message with a valid key → streamed answer appears, thinking indicator clears.
- [ ] Kill/block the provider upstream mid-request → final-frame content still renders (P0.1).
- [ ] Unconfigured provider selected → inline error with message, not a stuck "Thinking…".
- [ ] Cost badge shows `$0.000123 · 456 tokens` (or `~` for estimated).
- [ ] Model dropdown passes `model` through (verify via debug panel / provider logs).
- [ ] Temperature change in Chat Behavior tab is reflected in the request.
- [ ] Tools toggle = graph → tool card renders for a graph query; `max_iterations` respected.
- [ ] Clear button restores the correct empty-state copy.
- [ ] `npm run lint:js` and `vendor/bin/phpunit` green (addon-level).
- [ ] Manual A/B: SPA-v2 and tester produce identical answers for the same prompt (contract parity).

## 6. Estimated Effort

| Phase | Effort |
|---|---|
| P0 — reliability fixes | ~2 h |
| P1 — REST contract | ~2.5 h |
| P2 — UI polish | ~3 h |
| P3 — alignment + tests | ~2 h |
| **Total** | **~9.5 h** |
