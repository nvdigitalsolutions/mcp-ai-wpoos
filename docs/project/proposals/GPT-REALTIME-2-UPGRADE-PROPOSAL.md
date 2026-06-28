# GPT-Realtime-2 Voice Models Upgrade — Feature Proposal

**Date:** 2026-06-26
**Phase:** 1 — Discovery
**Status:** Draft
**Author:** Zed AI Agent (research & synthesis)
**Proposal File:** `docs/project/proposals/GPT-REALTIME-2-UPGRADE-PROPOSAL.md`
**Source Research:** `openai.com/index/advancing-voice-intelligence-with-new-models-in-the-api/` (May 7, 2026)

---

## Executive Summary

On May 7, 2026, OpenAI released three new realtime voice models and a WebRTC-first transport architecture:
- **GPT-Realtime-2** — reasoning-capable voice model (GPT-5-class), 128K context, configurable reasoning effort, preambles, parallel tool calls
- **GPT-Realtime-Translate** — live speech translation, 70+ input languages → 13 output languages
- **GPT-Realtime-Whisper** — streaming speech-to-text with controllable latency

The legacy beta Realtime API was deprecated and removed on May 12, 2026. NV oOS currently uses the **beta session format** (`/v1/realtime/sessions`), the **deprecated `gpt-realtime` model**, and **WebSocket-only transport**. This proposal defines the full upgrade to the GA (Generally Available) Realtime API with WebRTC as the primary transport, all three new models, and the enhanced reasoning/preambles/tooling capabilities.

---

## Problem Statement

> What problem does this solve? Who experiences it? What is the current workaround?

1. **The beta Realtime API is dead.** OpenAI removed it on May 12, 2026. NV oOS's `WP_MCP_AI_OpenAI_Realtime_Client` hits the deprecated `/v1/realtime/sessions` endpoint. Any new deploys or API key rotations will fail.

2. **The default model (`gpt-realtime`) is obsolete.** The new model offers reasoning, 4× larger context (128K vs 32K), parallel tool calls, preambles, and 15.2% higher accuracy on Big Bench Audio.

3. **WebSocket-only transport is suboptimal.** OpenAI now recommends WebRTC for all browser/mobile clients (sub-200ms latency, native echo cancellation, simpler audio handling). NV oOS's current `chat-voice-realtime-service.js` does manual PCM16 encoding over WebSocket — fragile, high-latency, and CPU-intensive.

4. **No translation or live transcription.** Users can't offer multilingual voice support or real-time meeting captions.

5. **Voice tooling is basic.** No `wait_for_user` no-op tool, no parallel tool calling, no preamble transparency, no sideband control channel for tool security.

**Current workaround:** None. The beta endpoint is gone. The plugin's voice features will break as API keys rotate or new sessions fail.

---

## Target Users

- **Site administrators** who configure AI voice assistants for customer support, content creation, or internal tools.
- **Developers** building voice-first applications on WordPress (e.g., accessibility tools, multilingual sites, meeting transcription).
- **End users** interacting with NV oOS assistants via voice in the chat UI.

---

## WordPress Ecosystem Context

### Related Plugins/Solutions
| Solution | Approach | Limitation |
|---------|---------|-----------|
| OpenAI's standalone WebRTC console | Browser-only demo; no WordPress integration | Not embeddable in WordPress; no tool orchestration |
| Pipecat (Daily) | Python framework for realtime voice agents | Server-side only; not WordPress-native |
| Custom WebSocket implementations | Direct `wss://` connections | Deprecated beta format; no longer works |

### WordPress Core Features Leveraged
- WP REST API (`rest_api_init`) for token minting and SDP relay
- `wp_remote_post()` for server-side API calls to OpenAI
- WordPress options API for settings persistence
- WordPress Transients API for session token caching
- `wp_add_inline_script()` for JS config localization

### NV oOS Components Affected
- [x] Tool registry — `wait_for_user` tool; updated realtime tool formatting
- [x] REST API — new `/mcp-ai/v1/realtime/token` and `/mcp-ai/v1/realtime/session` endpoints
- [x] Chat UI — WebRTC service, translation UI, transcription panel, reasoning controls
- [x] Admin settings — model selector, reasoning effort, preambles, transport toggle
- [ ] Database schema — no changes needed (options-based configuration)
- [x] External API integration — OpenAI Realtime API GA endpoints

---

## Proposed Solution

### 1. Transport Layer: WebSocket → WebRTC (Primary)

Migrate from the beta WebSocket flow to the GA WebRTC flow using either:
- **Ephemeral token pattern** — server mints token via `POST /v1/realtime/client_secrets`; browser uses it for `RTCPeerConnection`
- **Unified interface pattern** — browser sends SDP offer to NV oOS server; server relays to `POST /v1/realtime/calls` with API key

Keep WebSocket as a configurable fallback for server-side pipelines (SIP integrations, broadcast ingest).

### 2. Model Upgrade: gpt-realtime → gpt-realtime-2

| Capability | Old (`gpt-realtime`) | New (`gpt-realtime-2`) |
|-----------|---------------------|------------------------|
| Reasoning | None | `minimal` / `low` / `medium` / `high` / `xhigh` |
| Context window | 32K tokens | 128K tokens (~1–2 hours) |
| Preambles | Not supported | Short spoken updates before reasoning/tools |
| Parallel tool calls | No | Yes, with audible progress |
| Tool transparency | No | "Checking your calendar" while calling |
| Recovery behavior | Silent failure | "I'm having trouble with that right now" |
| Image input | No | `input_image` content type |
| Message channels | Single output | `commentary` (preambles/tools) + `final` (user-facing) |
| Tone control | Basic | Calm / empathetic / upbeat — context-aware |
| Domain vocabulary | Standard | Better healthcare/legal/technical term retention |
| Prompt caching | Not available | $0.40/1M cached input tokens |

### 3. New Models: Translation + Transcription

- **GPT-Realtime-Translate** — dedicated `/v1/realtime/translations` endpoint; continuous stream (no turn lifecycle); $0.034/min
- **GPT-Realtime-Whisper** — streaming transcription session type; configurable latency; $0.017/min

### 4. Enhanced Tool System for Voice

- `wait_for_user` no-op tool — handles silence, background noise, hold music
- Parallel tool call configuration
- Per-tool preamble phrases in tool descriptions
- Sideband control channel for secure server-side tool execution
- Tool output JSON envelope (`response_text` + `require_repeat_verbatim`) for reliable verbatim delivery

### 5. Structured Prompt Template

Replace the flat system prompt with a labeled-section template aligned with OpenAI's recommended structure:
```
# Role and Objective
# Personality and Tone
# Language
# Reasoning
# Message Channels
# Preambles
# Verbosity
# Tools
# Unclear Audio
# Entity Capture
# Long Context Behavior
# Escalation
```

---

## Feasibility Assessment

| Dimension | Assessment | Notes |
|-----------|-----------|-------|
| Technical complexity | **High** | WebRTC integration, three new models, session format migration, JS refactor |
| Security considerations | API key stays server-side; ephemeral tokens never expose credentials; sideband channel for tools; Safety-Identifier header | Low risk |
| Third-party dependencies | OpenAI API (GA endpoints); browser WebRTC APIs (built-in, no dependency) | No new vendor deps |
| Base vs Pro placement | **Base** — voice is a core platform capability | All users benefit |
| Estimated stories | ~30 stories across 6 epics | See Implementation Plan |

---

## Security Implications

- [x] Handles user credentials or API keys: **Yes** — OpenAI API key stays server-side; ephemeral tokens are short-lived (~60s); session tokens cached in transients with 50s TTL
- [x] Accesses external services: **Yes** — OpenAI API (`api.openai.com`)
- [ ] Processes user-uploaded content: **Yes** — microphone audio streamed to OpenAI; no local storage of raw audio
- [x] Exposes new REST endpoints: **Yes** — `/mcp-ai/v1/realtime/token` (capability-gated), `/mcp-ai/v1/realtime/session` (capability-gated)
- [x] Requires new capabilities: Yes — `edit_posts` minimum; configurable per assistant
- [x] Safety identifiers: `OpenAI-Safety-Identifier` header with hashed user ID for abuse monitoring

---

## Competitive Alternatives

| Alternative | Approach | Why NV oOS Is Different |
|------------|---------|------------------------|
| Standalone WebRTC app | Build a separate voice app | NV oOS integrates voice natively into WordPress with full tool orchestration, agent memory, and MCP |
| Pipecat / LiveKit | Python/Node.js frameworks | Requires separate server; no WordPress admin UI or tool registry |
| Direct WebSocket (self-maintained) | Raw WebSocket to OpenAI | Already broken (beta deprecated); no tool orchestration or settings UI |

---

## Pricing Impact

| Model | Input Price | Output Price | Notes |
|-------|------------|--------------|-------|
| gpt-realtime-2 | $32/1M tokens ($0.40 cached) | $64/1M tokens | ~2× cost of old realtime but with reasoning |
| gpt-realtime-translate | $0.034/min | N/A | Per-minute; cost-effective for continuous translation |
| gpt-realtime-whisper | $0.017/min | N/A | Per-minute; cheaper than file-based Whisper for long sessions |

**Recommendation:** Default to `gpt-realtime-2` with `reasoning.effort: "low"` — balances intelligence with cost. Add per-session cost tracking in the admin usage dashboard.

---

## Success Metrics

| Goal | Metric | Target |
|------|--------|--------|
| Voice sessions work on GA endpoints | 100% session creation success rate | No errors from deprecated endpoints |
| Latency improvement | End-to-end voice latency | < 500ms (WebRTC, down from ~2s WebSocket) |
| Model accuracy | User satisfaction with voice responses | Qualitative improvement over gpt-realtime |
| Translation availability | Languages supported | 70+ input, 13 output |
| Transcription accuracy | Word error rate | Comparable to file-based Whisper |

---

## Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| WebRTC browser compatibility issues | Medium | Medium | Keep WebSocket fallback; feature-detect WebRTC support |
| API cost increase with gpt-realtime-2 | High | Medium | Default to `low` reasoning; add cost tracking; admin budget alerts |
| Session migration breaking existing configs | Medium | High | Phased rollout; backward-compat config mapping; admin migration notice |
| Translation/transcription latency too high for real-time | Low | Medium | Configurable latency delay; test with real audio conditions |
| ICE connection failures in restricted networks | Medium | Medium | TURN/STUN server configuration option; WebSocket fallback |

---

## Dependencies

- OpenAI API access with Realtime API enabled (Tier 3+ recommended)
- Browser supporting WebRTC (`RTCPeerConnection`) — Chrome 56+, Firefox 44+, Safari 11+, Edge 79+
- WordPress 6.0+ (existing requirement)
- PHP 7.4+ (existing requirement)
- `class-wp-mcp-ai-openai-client.php` — for API key resolution
- `class-wp-mcp-ai-admin-settings.php` — for settings persistence
- `class-wp-mcp-ai-rest.php` — for new REST route registration

---

## Recommendations

**Proceed to Implementation:** Yes — time-critical due to beta API deprecation.

**Key risks:**
1. The beta endpoint is already removed; voice features are broken without this upgrade
2. WebRTC adds complexity but delivers significant latency and reliability improvements
3. New model pricing is higher; cost tracking is essential

**Key assumptions:**
1. Users have OpenAI API keys with Realtime API access (Tier 3+)
2. Target browsers support WebRTC and `getUserMedia`
3. The existing voice mode integration (`chat-voice-mode-integration.js`) can be extended rather than rewritten
4. The existing tool registry is compatible with the new realtime tool format

---

## Analyst Sign-off Checklist

- [x] Problem statement is clear and specific
- [x] Target users identified with concrete use cases
- [x] WordPress ecosystem context researched
- [x] Feasibility assessment complete (complexity, security, dependencies)
- [x] Base vs Pro placement recommended with rationale (Base — core platform)
- [x] Security implications enumerated
- [x] Recommendation to proceed is stated
- [x] All factual claims verified against OpenAI's official documentation (May 2026)

---

## References

- [Advancing voice intelligence with new models in the API](https://openai.com/index/advancing-voice-intelligence-with-new-models-in-the-api/) — OpenAI, May 7, 2026
- [Realtime and audio — OpenAI API docs](https://platform.openai.com/docs/guides/realtime) — GA interface reference
- [Realtime API with WebRTC](https://platform.openai.com/docs/guides/realtime-webrtc) — Connection guide
- [Realtime conversations](https://platform.openai.com/docs/guides/realtime-conversations) — Session lifecycle
- [Using realtime models](https://platform.openai.com/docs/guides/realtime-models-prompting) — Prompting guide
- [Realtime with tools](https://platform.openai.com/docs/guides/realtime-mcp) — MCP and function calling
- [GPT-Realtime-2 Model](https://platform.openai.com/docs/models/gpt-realtime-2) — Model capabilities
- [Realtime API Changelog](https://platform.openai.com/docs/changelog) — Beta deprecation (May 12, 2026)

---

*Next step: Architect creates the Implementation Plan at `docs/project/proposals/GPT-REALTIME-2-IMPLEMENTATION-PLAN.md`.*
