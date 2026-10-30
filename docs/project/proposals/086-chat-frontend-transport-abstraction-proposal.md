# Proposal 086 — Chat Frontend Transport Abstraction: Bring-Your-Own-Backend for the NV oOS Chat UI

**Status:** Proposal (open decisions in §8) · **Date:** 2026-10-24
**Related:** `addons/chat-spa` (0.7.0, `@ai-sdk/react` 1.2.12), `addons/pro/assets/spa-v2`
(`EmbeddedApp`), `examples/nvoos-pro-spa-vite`, Proposal 065 (Content Graph npm packages),
Proposal 083 (Complete Console — deferred `packages/nvoos-spa-host/` extraction),
Proposal 077 (SPA Hub single shell), `addons/librechat`

## 1. Problem Statement

The NV oOS chat frontends are well-liked, but the *frontend* is coupled to the
*backend wire format*: `addons/chat-spa/src/sse-adapter.ts` is the only file that
knows how to translate NV oOS's native SSE frames (`NvOosFrame`: `delta`,
`reasoning`, `tool_call`, `finish`, `usage`) into the AI SDK Data Stream protocol
that `@ai-sdk/react`'s `useChat` consumes. `App.tsx` wires it via the legacy
`fetch:` injection:

```ts
const { messages, ... } = useChat( {
    api: endpoint,                          // /wp-json/mcp-ai/v1/chat-client
    fetch: customFetch as typeof globalThis.fetch,   // createChatFetch(...)
    streamProtocol: 'data',
} );
```

Beyond the stream, the UI also depends on plugin-owned REST surfaces through
`src/api/*` (`transcripts.ts`, `memory.ts`, `threads.ts`, `tools.ts`,
`hitl.ts`, `config.ts`).

Two audiences are underserved today:

1. **Developers who like the UI but want their own provider SDK backend**
   (the "option B" question that spawned this proposal). They must fork the
   SPA and rewrite the adapter, and they inherit `src/api/*` coupling to WP REST
   that their backend may not implement.
2. **Products that want to embed the chat surface in non-WordPress contexts**
   with a stock AI SDK backend (`@ai-sdk/openai`, `@ai-sdk/anthropic`, LangChain,
   any OpenAI-compatible gateway). Today the only supported runtime is the
   plugin's REST layer.

The plugin *backend* is already provider-agnostic (17+ provider subtabs, OpenAI/
Anthropic/Gemini base-URL overrides, failover). The *frontend* is the missing
half: there is no supported, versioned seam between "NV oOS chat UI" and
"whatever model-calling layer you already run."

## 2. Research & Industry Standards

### 2.1 The OpenAI-compatible API is the de facto interchange standard

- LiteLLM's gateway exists precisely to "put your entire AI stack behind one
  OpenAI-compatible API" — one endpoint in front of 100+ LLM providers, MCP
  tools, and A2A agents, with cost tracking, guardrails and load balancing
  ([LiteLLM](https://www.litellm.ai/), [LiteLLM proxy docs](https://docs.litellm.ai/docs/simple_proxy),
  [BerriAI/litellm](https://github.com/BerriAI/litellm)).
- Practitioner summary: "The OpenAI API format has become the de facto
  standard. A gateway lets you use that same format with Anthropic, Google,
  Mistral, and any other provider"
  ([julien.cloud — LLM gateway for OpenCode](https://julien.cloud/blog/llm-gateway-for-opencode-building-a-local-litellm-router/)).

**Consequence:** a backend-agnostic frontend should define its neutral wire
contracts around OpenAI-style chat semantics or the AI SDK Data Stream
protocol — never around one vendor's proprietary SSE frame format. The current
`NvOosFrame` adapter is the vendor-specific shim; it must become *one
implementation* of a neutral transport interface, not the interface.

### 2.2 The Vercel AI SDK ships the transport abstraction as a first-class feature

- `useChat`'s stream protocol docs distinguish text streams vs the structured
  Data Stream protocol and define `streamProtocol` selection
  ([ai-sdk.dev — Stream Protocols](https://ai-sdk.dev/docs/ai-sdk-ui/stream-protocol)).
- **AI SDK 5** redesigned `useChat` "with modularity at its core, enabling three
  powerful extensibility patterns: **Flexible Transports**: swap out the default
  fetch-based transport for custom implementations. Use WebSockets for real-time
  communication **or connect directly to LLM providers without a backend**"
  ([Vercel — AI SDK 5](https://vercel.com/blog/ai-sdk-5)).
- The repo currently uses the AI SDK 4-era pattern (`fetch:` injection +
  `streamProtocol: 'data'`). Adopting the transport concept is therefore an
  upgrade *toward* the vendor's stated direction, not a fork of it.

**Consequence:** the abstraction to build is a `ChatTransport` interface, with
the existing SSE adapter refactored into `NvOosRestTransport` (default) and a
new `DataStreamTransport` (stock fetch) for foreign backends. Direct-to-provider
browser connections become a third transport (stretch, §8 Q2).

### 2.3 Headless, provider-agnostic chat UI is the industry direction

- shadcn's 2026 chat components are explicit about the boundary:
  "MessageScroller owns that behavior without owning your messages, AI state,
  **transport, persistence, or model state**"
  ([shadcn/ui — Components for Chat Interfaces](https://ui.shadcn.com/docs/changelog/2026-06-chat-components)).
- assistant-ui is the reference open-source library for this: MIT-licensed
  composable chat primitives with adapters for AI SDK, LangGraph Cloud,
  LangChain, and Mastra — described as "the TypeScript/React library closest to
  'Stripe for AI chat'" ([Create UI](https://createui.co/blog/best-ai-chat-ui-components-react),
  [AdminLTE.IO](https://adminlte.io/blog/shadcn-ui-ai-chat-templates/)).
- Kits built on these primitives are "provider-agnostic" by construction —
  "You can also integrate other AI providers by creating your own
  implementation" (shadcn-chatbot-kit docs, [jqueryscript.net](https://next.jqueryscript.net/shadcn-ui/ai-chatbots-kit/)).

**Consequence:** the split this proposal makes — UI components / transport /
persistence as independently replaceable layers — is the consensus 2026
architecture, not an NV oOS invention. The repo's existing seam
(`sse-adapter.ts` + `src/api/*`) already maps cleanly onto it.

### 2.4 BYOK is a recognized product pattern — with a hard security rule

- BYOK is mainstream in developer tools: VS Code lets users add their own
  model provider keys/endpoints into the model picker
  ([VS Code blog](https://code.visualstudio.com/blogs/2026/06/18/byok-vscode));
  Cursor, JetBrains AI, and AnythingLLM (30+ providers incl. Ollama/LM Studio)
  do the same ([awesome-byok-apps](https://github.com/yatsyk/awesome-byok-apps),
  [DM Champ](https://dmchamp.com/best/best-ai-tools-byok-2026/)).
- **The rule:** provider secret keys must never sit in frontend code.
  "Putting keys in frontend code exposes them to anyone who opens DevTools"
  ([byok-relay](https://byokrelay.com/)); gateway BYOK keeps the key server-side
  and issues the app an opaque relay token
  ([Cloudflare AI Gateway — BYOK](https://developers.cloudflare.com/ai-gateway/configuration/bring-your-own-keys/)).
  The OpenAI developer community confirms the expected posture: keys belong on
  the user's server, not in browser storage
  ([OpenAI Community — BYOK policy](https://community.openai.com/t/bring-your-own-key-policy/446168)).

**Consequence:** this proposal's transports **always** target a server endpoint
(their SDK backend, a gateway, or the WP plugin). A browser-direct transport is
acceptable *only* for loopback/local providers (Ollama, LM Studio on
localhost) — never for hosted-provider keys. The BYOK guidance ships as docs,
not as key-storage code.

### 2.5 MCP is the standard for tool/agentic-backend interoperability

- MCP "standardizes how to integrate additional context and tools into the
  ecosystem of AI applications" ([MCP specification 2026-07-28](https://modelcontextprotocol.io/specification/2026-07-28));
  OpenAI adopted it across products including ChatGPT
  ([Wikipedia — MCP](https://en.wikipedia.org/wiki/Model_Context_Protocol)).
- Client-side support spans Claude, ChatGPT, Gemini, Copilot, Cursor, Zed;
  server-side connectors exist for Slack, GitHub, Google, Stripe, Shopify,
  Figma and more
  ([WorkOS — MCP in 2026](https://workos.com/blog/everything-your-team-needs-to-know-about-mcp-in-2026)).
  Enterprise guides call it "the defining standard for connecting AI agents to
  enterprise tools, data sources, and external systems"
  ([MCP Enterprise Adoption Guide 2026](https://guptadeepak.com/the-complete-guide-to-model-context-protocol-mcp-enterprise-adoption-market-trends-and-implementation-strategies/)).

**Consequence:** for teams whose "provider SDK backend" also owns tool
execution, the tool side of the frontend should standardize on MCP-shaped tool
lists (`tools/list` semantics) — which is what the plugin already emits. The
`ToolsAdapter` in §3.4 formalizes this instead of inventing a new tool schema.

### 2.6 The BFF same-origin model remains the security baseline

- The hybrid deployment shape (WP shell + external chat stream) inherits the
  BFF posture already documented in Proposal 085 §2.2: one origin, cookie
  sessions, no keys in JS
  ([Auth0 — BFF pattern](https://auth0.com/blog/the-backend-for-frontend-pattern-bff/),
  [Duende BFF](https://docs.duendesoftware.com/bff/)).

## 3. Proposed Design

### 3.1 Core concept: two small interfaces

```ts
// src/transport/types.ts
interface ChatTransport {
    readonly id: string;                       // 'nvoos-rest' | 'data-stream' | 'local'
    readonly capabilities: {
        reasoningDeltas: boolean;              // NV oOS reasoning frames
        toolLifecycle: 'nvoos' | 'data-stream' | 'none';
        usageReporting: boolean;
        attachments: boolean;
    };
    /** Data Stream Protocol writer the UI hands to useChat's transport. */
    stream( request: ChatRequest, ctx: ChatTransportContext ): ReadableStream<UIMessageStream>;
}

// src/features/types.ts
interface ChatFeatureAdapters {
    transcripts: TranscriptsAdapter;           // default: WP REST /chat-transcripts
    memory: MemoryAdapter;                     // default: WP REST /chat-memory
    threads: ThreadsAdapter;                   // default: WP REST /threads
    approvals: ApprovalsAdapter;               // HITL — default: WP REST /approvals
    tools: ToolsAdapter;                       // tool registry / MCP tools/list
}
```

Every adapter ships a default WP-REST implementation and a `null`/in-memory
fallback, so the UI runs with **zero** WordPress endpoints when the host
backend provides its own persistence.

### 3.2 Three shipped transports

| Transport | Backend | Status |
| --- | --- | --- |
| `NvOosRestTransport` | `/wp-json/mcp-ai/v1/chat-client` (current SSE frames) | Default — refactor of `sse-adapter.ts`, zero behavior change |
| `DataStreamTransport` | Any AI SDK Data Stream endpoint (their SDK backend, LangChain, gateway) | New — stock fetch, `streamProtocol: 'data'` |
| `LocalProviderTransport` | Browser → localhost Ollama / LM Studio | Stretch (§8 Q2) — loopback only, no hosted keys |

### 3.3 Deployment shapes (which backend the user "sticks with")

1. **SDK-first (native Data Stream):** their Node/Python backend streams Data
   Stream frames from their provider SDK; `DataStreamTransport` + null feature
   adapters. No WordPress at all.
2. **Hybrid:** WP keeps transcripts/memory/approvals; only the chat stream
   points at their backend (`DataStreamTransport` + default WP adapters).
   This is the minimum-friction BYOK story for existing plugin users.
3. **Emulated plugin SSE:** their backend re-implements `NvOosFrame`. Documented
   as possible but **discouraged** — it re-creates the vendor lock-in this
   proposal removes.

### 3.4 Reference backend + package

- A reference backend ships as an example (`examples/chat-ui-provider-sdk/`):
  an Express route using `streamText()` from `@ai-sdk/openai` /
  `@ai-sdk/anthropic` + `toDataStreamResponse()`. It is the executable
  contract for `DataStreamTransport`.
- The UI extraction follows the Proposal 065 npm-package pattern:
  `packages/nvoos-chat-ui/` (transport registry + components + feature-adapter
  interfaces), consumed by `addons/chat-spa` and `examples/nvoos-pro-spa-vite`
  via alias. This also unblocks Proposal 083's deferred
  `packages/nvoos-spa-host/` extraction.

## 4. Scope

**In scope:** `addons/chat-spa` transport + feature-adapter extraction; the
reference backend example; docs (BYOK security guide, hybrid runbook);
vitest/a11y/typecheck gates; THIRD_PARTY_NOTICES updates.

**Out of scope:** changes to the plugin REST surface; shipping provider SDKs
server-side in the plugin; browser-side hosted-provider key storage; spa-v2
`EmbeddedApp` refactor (P5 in the plan, listed as follow-on); MCP client
execution in the browser.

## 5. Risks

| Risk | Mitigation |
| --- | --- |
| AI SDK major-version churn (4 → 5, transport API) | Pin the currently-vendored `@ai-sdk/react` 1.2.12; treat a v5 migration as its own proposal. The transport interface insulates the UI. |
| Transport capability drift (reasoning deltas, tool lifecycle lost on Data Stream) | `capabilities` flags declared per transport; UI degrades explicitly (hides reasoning/approvals UI) instead of silently dropping frames. |
| Bundle-size growth (dual-React guard, three transports) | Lazy transport registration; size budget in CI (existing esbuild + SPA conventions). |
| BYOK key leakage via the reference example | Example uses **server-side env keys only**; docs make the browser-key prohibition explicit (§2.4). |
| wp.org compliance (third-party notices, external-service disclosure) | Follow Proposal 053/060 precedent: notices file + External Services disclosure where the example is showcased. |

## 6. Non-goals

- Not a new wire protocol. Data Stream Protocol + existing NV oOS SSE are the
  only two formats.
- Not a managed multi-tenant LLM gateway (LiteLLM-class concerns — routing,
  budget caps, key vaults — stay in the plugin backend or the user's stack).
- Not a visual redesign; components are reused as-is.

## 7. Success Criteria

1. `NvOosRestTransport` refactor lands with **zero observable behavior change**
   (existing vitest suite + manual streaming/tool-call smoke).
2. `DataStreamTransport` + the reference backend complete a streaming
   multi-turn conversation **including a tool call round trip** with no
   NV oOS REST endpoint involved.
3. The chat UI renders standalone (no WordPress) with in-memory feature
   adapters, verified in `examples/nvoos-pro-spa-vite`.
4. A "BYOK with your provider SDK" guide lets a developer go from checkout to
   a working own-backend chat in the documented steps.
5. All gates green: `vitest run`, `tsc --noEmit`, `eslint --max-warnings 0 src/`,
   `npm run build`, plugin PHP side untouched (`php -l` on changed PHP files if
   any).

## 8. Open Decisions

- **Q1 — Which surface first?** `addons/chat-spa` (smaller, self-contained) vs
  spa-v2 `EmbeddedApp` (the surface most Velocity/Console users see). Proposal
  assumes chat-spa first; spa-v2 follows in P5.
- **Q2 — Ship `LocalProviderTransport`?** Browser→localhost is a real BYOK
  niche (mirrors the dictation app's local-provider track) but adds an
  auth-less surface. Ship as stretch or defer?
- **Q3 — Package scope:** `packages/nvoos-chat-ui` (UI + transports) vs a
  narrower `packages/nvoos-chat-transport` (transports only, UI stays in-repo)?
  Proposal 065 precedent argues for the full UI package.
- **Q4 — HITL on foreign backends:** approvals currently ride NV oOS REST.
  Genericize via a Data Stream custom-event + adapter, or declare approvals an
  NV oOS-only feature for v1?
- **Q5 — Reference backend language:** Node (matches the AI SDK, one stack) vs
  Python (FastAPI, wider "provider SDK" audience)? Node first is assumed.
