# LibreChat SPA Addon — Comprehensive Implementation Proposal

> **Status:** Proposal (v1.1.29)  
> **Date:** June 11, 2026  
> **Author:** NV Digital Solutions  
> **Target:** `addons/librechat/` — standalone SPA addon for NV oOS

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Industry Standards & Best Practices](#2-industry-standards--best-practices)
3. [Gap Analysis — What LibreChat Fills](#3-gap-analysis--what-librechat-fills)
4. [Architecture](#4-architecture)
5. [Integration Strategy — Two-Phase Approach](#5-integration-strategy--two-phase-approach)
6. [Phase 1: Quick-Start Bridge (Weeks 1–4)](#6-phase-1-quick-start-bridge-weeks-14)
7. [Phase 2: Deep Integration (Weeks 5–10)](#7-phase-2-deep-integration-weeks-510)
8. [Feature Mapping — LibreChat → NV oOS](#8-feature-mapping--librechat--nv-oos)
9. [File Structure](#9-file-structure)
10. [REST Bridge Contract](#10-rest-bridge-contract)
11. [Authentication Bridge](#11-authentication-bridge)
12. [Security Considerations](#12-security-considerations)
13. [Testing Strategy](#13-testing-strategy)
14. [Milestones & Timeline](#14-milestones--timeline)

---

## 1. Executive Summary

LibreChat (39k GitHub stars, MIT license) is the leading open-source ChatGPT clone with unique features absent from NV oOS: **sandboxed code interpreter**, **web search with reranking**, **speech-to-text/text-to-speech**, **code artifacts**, and **conversation forking**.

This proposal outlines a two-phase integration of LibreChat as a standalone NV oOS addon at `addons/librechat/`, following the same SPA blueprint pattern used by `addons/chat-spa/`, `addons/docs-hub/`, and `addons/toolkit-shell/`.

**Phase 1** (4 weeks) delivers a quick-start bridge: configure LibreChat to call NV oOS REST API for AI, with WordPress authentication passthrough. **Phase 2** (6 weeks) strips LibreChat's Node.js backend, replacing it with native WordPress PHP services, achieving deep integration.

**Total estimated effort:** 10 weeks for complete integration.

---

## 2. Industry Standards & Best Practices

### 2.1 Web Search Standards

Web search in AI chat interfaces has converged on a three-stage pipeline:

| Stage | Industry Standard | Implementation |
|---|---|---|
| **Search** | Google Programmable Search, Brave Search API, Tavily, Serper, SearXNG | LibreChat supports 5+ providers; NV oOS currently supports 3 |
| **Scrape** | Firecrawl, Jina Reader, Tavily Extract, Puppeteer/Browserless | LibreChat supports 4 scrapers; NV oOS has Crawl4AI (single) |
| **Rerank** | Cohere Rerank, Jina Reranker, cross-encoder models | LibreChat supports Jina + Cohere; NV oOS has none |

**Best practice:** Combine at least one search provider + one scraper + one reranker. The reranking step is critical — raw search results without relevance scoring produce low-quality AI responses.

### 2.2 Code Interpreter Standards

| Aspect | Best Practice |
|---|---|
| **Isolation** | Docker container per execution, no network access, read-only filesystem |
| **Languages** | Python, JavaScript, TypeScript, Go, C/C++, Java, PHP, Rust |
| **File handling** | Upload → execute → download pipeline with MIME validation |
| **Timeouts** | Per-language defaults (Python: 60s, C++: 30s, Go: 30s) |
| **Security** | No outbound network, memory limits, process limits, no privileged mode |

LibreChat uses a dedicated Code Interpreter API server with Docker sandboxing — this is the gold standard.

### 2.3 Speech/Audio Standards

| Capability | Provider Options | NV oOS Status |
|---|---|---|
| **STT** (Speech-to-Text) | OpenAI Whisper, Azure Speech, Deepgram, browser Web Speech API | ❌ None |
| **TTS** (Text-to-Speech) | OpenAI TTS, ElevenLabs, Azure Speech, browser Web Speech API | ❌ None |
| **Streaming** | Chunked audio responses, interim results for STT | ❌ None |

### 2.4 Chat UI Component Standards

Modern chat UIs follow these patterns:

| Pattern | Description | LibreChat | NV oOS chat-spa |
|---|---|---|---|
| **Streaming** | Token-by-token response rendering | ✅ SSE + Resumable | ✅ SSE |
| **Message actions** | Edit, resubmit, copy, delete, speak | ✅ Full set | Partial (copy only) |
| **Conversation management** | Search, fork, branch, export, import | ✅ Full | Basic |
| **Multi-model** | Switch models mid-conversation | ✅ | ✅ |
| **File attachments** | Image, PDF, code, multi-file | ✅ | ✅ (basic) |
| **Reasoning UI** | Chain-of-thought display (e.g., DeepSeek-R1) | ✅ | ❌ |
| **Artifacts** | Code/HTML/Mermaid preview panels | ✅ | ❌ |
| **Accessibility** | ARIA labels, keyboard nav, screen reader | ✅ | ✅ (basic) |

---

## 3. Gap Analysis — What LibreChat Fills

### 3.1 High-Priority Gaps (NV oOS has NONE of these)

| # | Feature | Business Value | Complexity |
|---|---|---|---|
| 1 | **Sandboxed Code Interpreter** | Enables "AI data analyst" use cases — run Python on CSV exports, generate charts, analyze WordPress data | High |
| 2 | **Web Search with Reranking** | Dramatically improves answer quality for current-events and factual queries | ✅ Implemented (5 providers + Jina/Cohere reranker in `class-wp-mcp-ai-tool-web-search.php`) |
| 3 | **Speech-to-Text + Text-to-Speech** | Accessibility, mobile-first UX, hands-free operation | Medium |
| 4 | **Code Artifacts** | Visual code/HTML/Mermaid preview in chat — key for developer UX | Low |

### 3.2 Medium-Priority Gaps (NV oOS has partial)

| # | Feature | Current NV oOS State | LibreChat Adds |
|---|---|---|---|
| 5 | **Conversation Forking** | No forking | Branch conversations at any point |
| 6 | **Preset System** | No model presets | Save/share model configs across chats |
| 7 | **Reasoning UI** | Plain text display | Collapsible chain-of-thought panels |
| 8 | **Message Search** | No global search | Full-text conversation search |

### 3.3 Feature Matrix

| Feature | chat-spa (existing) | librechat addon (proposed) |
|---|---|---|
| AI chat with streaming | ✅ | ✅ |
| Tool calling (MCP) | ✅ | ✅ |
| Code interpreter | ❌ | ✅ |
| Web search | ✅ (5 providers at tool level: DuckDuckGo, Brave, Tavily, Exa, Perplexity) | ✅ (5 providers + Jina/Cohere reranker) |
| Speech/TTS/STT | ❌ | ✅ |
| Artifacts | ❌ | ✅ |
| Conversation forking | ❌ | ✅ |
| Presets | ❌ | ✅ |
| Reasoning UI | ❌ | ✅ |
| Multi-model comparison | ❌ | ✅ |
| Message search | ❌ | ✅ |
| GPT/file import/export | ❌ | ✅ |
| WordPress auth integration | ✅ | ✅ (Phase 2) |

---

## 4. Architecture

### 4.1 Phase 1 Architecture (Bridge Mode)

```
┌─────────────────────────────────────────────────────────┐
│                   WordPress Plugin                       │
│                                                         │
│  ┌──────────────────┐    ┌───────────────────────────┐  │
│  │  NV oOS Core     │    │  addons/librechat/        │  │
│  │                  │    │                           │  │
│  │  • AI providers  │◄───│  • LibreChat React SPA    │  │
│  │  • Tool registry │    │  • LibreChat Node.js API  │  │
│  │  • MCP server    │    │  • MongoDB (conversations)│  │
│  │  • Auth layer    │    │  • MeiliSearch (search)   │  │
│  │  • File uploads  │    │  • Code Interpreter API   │  │
│  └──────────────────┘    │  • RAG API                │  │
│           ▲              └───────────┬───────────────┘  │
│           │                          │                   │
│           │    REST API Bridge       │                   │
│           └──────────────────────────┘                   │
│                                                         │
│  LibreChat configured with:                             │
│    custom endpoint → NV oOS /mcp-ai/v1/chat-client      │
│    auth bridge → WordPress nonce → LibreChat JWT        │
│    file upload → NV oOS /wp/v2/media                    │
└─────────────────────────────────────────────────────────┘
```

### 4.2 Phase 2 Architecture (Deep Integration)

```
┌─────────────────────────────────────────────────────────┐
│                   WordPress Plugin                       │
│                                                         │
│  ┌──────────────────┐    ┌───────────────────────────┐  │
│  │  NV oOS Core     │    │  addons/librechat/        │  │
│  │                  │    │                           │  │
│  │  • AI providers  │    │  LibreChat React SPA      │  │
│  │  • Tool registry │    │  (frontend only)          │  │
│  │  • MCP server    │    │                           │  │
│  │  • Memory layer  │    │  PHP Backend Services:    │  │
│  │  • Auth layer    │    │  • class-librechat-       │  │
│  │  • File uploads  │    │    code-interpreter.php   │  │
│  │  • WP DB          │    │  • class-librechat-       │  │
│  └──────────────────┘    │    web-search.php         │  │
│           ▲              │  • class-librechat-       │  │
│           │              │    speech.php             │  │
│           │  PHP native  │  • class-librechat-       │  │
│           │  method calls│    rest-controller.php    │  │
│           │              │  • CPT: librechat_convo   │  │
│           └──────────────│  • CPT: librechat_preset  │  │
│                          └───────────────────────────┘  │
│                                                         │
│  Node.js backend ELIMINATED. All data in WordPress DB.  │
│  Code interpreter: Docker socket via WP-Cron.           │
│  Web search: PHP HTTP client to search APIs.            │
│  Speech: PHP proxy to OpenAI/Azure TTS endpoints.       │
└─────────────────────────────────────────────────────────┘
```

---

## 5. Integration Strategy — Two-Phase Approach

### Why Two Phases?

LibreChat ships as a full-stack application (React frontend + Node.js backend + MongoDB + MeiliSearch). A "rip and replace" approach is high-risk and would take months. The two-phase strategy delivers value incrementally:

- **Phase 1** — Get it working as a standalone addon with minimal NV oOS changes. Users get the full LibreChat experience immediately, with NV oOS as the AI provider.
- **Phase 2** — Replace the Node.js backend with WordPress-native PHP services. Eliminates external dependencies (Node.js, MongoDB, MeiliSearch) and achieves deep integration.

---

## 6. Phase 1: Quick-Start Bridge (Weeks 1–4)

### 6.1 Scope

- Fork LibreChat into `addons/librechat/`
- Configure custom endpoint to point at NV oOS REST API
- Build WordPress authentication bridge
- Create SPA shortcode and Gutenberg block
- Docker Compose for local development (Node.js + MongoDB + MeiliSearch)
- Admin settings page for LibreChat configuration
- Production deployment guide

### 6.2 Deliverables

| Week | Deliverable | Details |
|---|---|---|
| 1 | **Scaffold + Config** | Fork LibreChat into `addons/librechat/`. Create `librechat.yaml` with NV oOS custom endpoint. Docker Compose for dev. |
| 2 | **Auth Bridge** | WordPress nonce → LibreChat JWT passthrough. Single sign-on: WP user logs in → auto-authenticated in LibreChat. Guest token support. |
| 3 | **Shortcode + Block** | `[nvoos_librechat]` shortcode. Gutenberg block `nvoos/librechat`. Admin settings page with endpoint/config UI. |
| 4 | **Documentation + Test** | User guide, developer guide, deployment guide. PHPUnit + E2E tests. CI integration. |

### 6.3 Phase 1 Limitations

- Requires Node.js + MongoDB + MeiliSearch alongside WordPress
- Two separate auth systems (WordPress + LibreChat JWT)
- Conversation data stored in MongoDB, not WordPress DB
- File uploads go to LibreChat's storage, not WP Media Library

---

## 7. Phase 2: Deep Integration (Weeks 5–10)

### 7.1 Scope

- Replace Node.js API with PHP REST controllers
- Store conversations in WordPress CPTs
- Integrate code interpreter via Docker socket
- Wire web search through NV oOS tool registry
- Add speech services as WordPress REST endpoints
- Migrate preset system to WordPress options

### 7.2 Deliverables

| Week | Deliverable | Details |
|---|---|---|
| 5–6 | **PHP REST Controllers** | Replace Node.js `/api/` routes with WordPress REST: `conversations`, `messages`, `presets`, `search`, `files`. Store in `librechat_convo` + `librechat_message` CPTs. |
| 7 | **Code Interpreter** | `WP_MCP_AI_LibreChat_Code_Interpreter` service. Docker sandbox per execution. WP-Cron job queue. File in → execute → file out pipeline. |
| 8 | **Web Search Integration** | `WP_MCP_AI_LibreChat_Web_Search` service. Register as NV oOS tool. Multi-provider with Jina/Cohere reranking. |
| 9 | **Speech Services** | `WP_MCP_AI_LibreChat_Speech` service. STT: OpenAI Whisper + Azure + browser fallback. TTS: OpenAI + ElevenLabs + Azure. |
| 10 | **Polish + Ship** | Remove Node.js dependency. Migration tool (MongoDB → WordPress CPTs). Full test suite. Production documentation. |

### 7.3 Phase 2 Benefits

- **Zero external dependencies** — everything runs in WordPress
- **Single auth system** — WordPress users, roles, capabilities
- **WP-native storage** — conversations in CPTs, searchable via WordPress search
- **Unified file management** — uploads go to WP Media Library
- **One-click install** — activate the addon, configure, done

---

## 8. Feature Mapping — LibreChat → NV oOS

### 8.1 Features to KEEP (already overlap with NV oOS)

| LibreChat Feature | NV oOS Equivalent | Strategy |
|---|---|---|
| AI model selection | Provider/model registration | Use NV oOS providers; disable LibreChat's built-in providers |
| MCP support | NV oOS MCP server | Route through NV oOS tool registry |
| File uploads | `/wp/v2/media` | Replace with WP media upload |
| Multi-user auth | WordPress auth | Replace with WP auth |

### 8.2 Features to ADOPT (NV oOS gaps)

| LibreChat Feature | Integration Approach |
|---|---|
| Code interpreter | Run Docker sandbox via `WP_MCP_AI_Process_Service` |
| Web search + reranking | PHP HTTP clients to Serper/Tavily/Brave + Jina/Cohere API |
| Speech STT/TTS | PHP proxies to OpenAI/Azure/ElevenLabs endpoints |
| Code artifacts | Frontend-only React component (no backend needed) |
| Conversation forking | Clone CPT post + meta |
| Presets | Store as `librechat_preset` CPT or WordPress options |
| Reasoning UI | Frontend component — detect `reasoning_content` in SSE stream |
| Message search | WordPress `WP_Query` on `librechat_message` CPT |

### 8.3 Features to DEFER (Phase 3+)

| Feature | Reason for Deferral |
|---|---|
| RAG API | NV oOS has Vector Context Service; need to evaluate gap |
| Agent marketplace | Requires community platform infrastructure |
| OAuth2/LDAP auth | WordPress handles auth; defer until enterprise demand |
| S3/CloudFront CDN | WordPress already has media CDN plugins |

---

## 9. File Structure

```
addons/librechat/
├── librechat.php                          # Plugin bootstrap
├── uninstall.php                          # Cleanup on uninstall
├── README.md
├── package.json
├── composer.json
├── docker-compose.yml                     # Phase 1 only (Node.js dev)
│
├── client/                                # LibreChat React SPA (Phase 1 & 2)
│   ├── src/
│   │   ├── components/
│   │   ├── hooks/
│   │   ├── utils/
│   │   └── App.tsx
│   ├── public/
│   └── package.json
│
├── api/                                   # Phase 1 only (Node.js backend)
│   ├── server/
│   │   ├── controllers/
│   │   ├── middleware/
│   │   └── routes/
│   └── package.json
│
├── includes/                              # Phase 2 PHP backend
│   ├── class-librechat-plugin.php         # Main plugin class
│   ├── class-librechat-rest-controller.php
│   ├── class-librechat-code-interpreter.php
│   ├── class-librechat-web-search.php
│   ├── class-librechat-speech.php
│   ├── class-librechat-cpt-conversation.php
│   ├── class-librechat-cpt-preset.php
│   ├── class-librechat-auth-bridge.php    # Phase 1 only
│   ├── class-librechat-shortcode.php
│   ├── class-librechat-block.php
│   └── class-librechat-admin.php
│
├── assets/
│   ├── js/
│   │   └── librechat-block.js             # Gutenberg block editor
│   └── css/
│       └── librechat-admin.css
│
├── config/
│   └── librechat.yaml                     # LibreChat configuration
│
├── templates/
│   └── admin-settings.php
│
└── tests/
    ├── test-code-interpreter.php
    ├── test-web-search.php
    ├── test-speech.php
    └── test-rest-controller.php
```

---

## 10. REST Bridge Contract

### 10.1 Phase 1: LibreChat → NV oOS Bridge

LibreChat's custom endpoint configuration (in `librechat.yaml`):

```yaml
endpoints:
  custom:
    - name: 'NV oOS'
      apiKey: '${WP_NONCE}'              # WordPress nonce passthrough
      baseURL: '${WP_REST_URL}mcp-ai/v1/' # NV oOS REST base
      models:
        default:
          - 'gpt-4o'
          - 'claude-sonnet-4'
        fetch: true                       # Fetch from NV oOS /models
      titleConvo: true
      titleModel: 'gpt-4o-mini'
      modelDisplayLabel: 'NV oOS'
      # Map NV oOS SSE format to LibreChat
      headers:
        X-WP-Nonce: '${WP_NONCE}'
```

### 10.2 Phase 2: WordPress REST Endpoints

| Method | Route | Handler | Description |
|---|---|---|---|
| GET | `/nvoos-librechat/v1/conversations` | `get_conversations` | List user's conversations |
| POST | `/nvoos-librechat/v1/conversations` | `create_conversation` | Create new conversation |
| GET | `/nvoos-librechat/v1/conversations/{id}` | `get_conversation` | Get conversation + messages |
| DELETE | `/nvoos-librechat/v1/conversations/{id}` | `delete_conversation` | Trash conversation |
| POST | `/nvoos-librechat/v1/conversations/{id}/fork` | `fork_conversation` | Fork at message |
| POST | `/nvoos-librechat/v1/code/execute` | `execute_code` | Run code in sandbox |
| GET | `/nvoos-librechat/v1/code/result/{job_id}` | `get_code_result` | Poll execution result |
| POST | `/nvoos-librechat/v1/speech/transcribe` | `transcribe_audio` | STT endpoint |
| POST | `/nvoos-librechat/v1/speech/synthesize` | `synthesize_speech` | TTS endpoint |
| GET | `/nvoos-librechat/v1/presets` | `get_presets` | List user presets |
| POST | `/nvoos-librechat/v1/presets` | `save_preset` | Save model preset |
| POST | `/nvoos-librechat/v1/search` | `search_conversations` | Full-text message search |

---

## 11. Authentication Bridge

### 11.1 Phase 1 Auth Flow

```
1. User logs into WordPress (standard wp-login or Auth0)
2. WordPress sets auth cookie + generates wp_rest nonce
3. SPA shortcode renders iframe or mounts React app
4. On mount, SPA POSTs to /nvoos-librechat/v1/auth/bridge
   with X-WP-Nonce header
5. Bridge endpoint validates nonce, generates LibreChat JWT
6. SPA stores JWT, attaches to all LibreChat API calls
7. JWT expires with WordPress session
```

### 11.2 Phase 2 Auth Flow

```
1. User logs into WordPress
2. All REST calls use standard WordPress cookie auth
3. permission_callback checks current_user_can('use_librechat')
4. No separate JWT needed — unified auth
```

---

## 12. Security Considerations

### 12.1 Code Interpreter Sandboxing

| Layer | Control |
|---|---|
| **Container** | Docker with `--read-only --no-new-privileges --cap-drop=ALL --network=none` |
| **Memory** | `--memory=256m --memory-swap=256m` |
| **CPU** | `--cpus=1` |
| **Timeout** | 60s default, configurable per language |
| **Filesystem** | Temp volume mounted `:ro` except `/tmp` |
| **Rate limit** | 10 executions per user per hour |

### 12.2 Web Search

- All outbound requests go through `wp_remote_get()` with SSRF protection
- User-provided search queries sanitized via `sanitize_text_field()`
- Rate limited: 50 searches per user per hour

### 12.3 Speech

- Audio files uploaded to `/tmp`, processed, then deleted
- Max upload size: 25MB for STT
- TTS responses streamed, not stored (except optional caching)

### 12.4 General

- All REST endpoints require `permission_callback` (never `__return_true`)
- Nonce validation on all state-changing endpoints
- Input sanitization: `sanitize_text_field()`, `absint()`, `wp_kses_post()`
- Output escaping: `esc_html()`, `esc_attr()`, `wp_json_encode()`

---

## 13. Testing Strategy

### 13.1 Phase 1 Tests

| Test Type | Scope | Count |
|---|---|---|
| **PHPUnit** | Auth bridge, shortcode, block registration, admin settings | ~15 tests |
| **E2E (Playwright)** | SPA mounts, conversation flows, auth passthrough | ~10 tests |
| **Manual QA** | Full feature walkthrough with real AI calls | Checklist |

### 13.2 Phase 2 Tests

| Test Type | Scope | Count |
|---|---|---|
| **PHPUnit** | REST controllers, code interpreter, web search, speech, CPTs, preset CRUD | ~40 tests |
| **Integration** | Code interpreter sandbox, web search multi-provider, TTS/STT pipelines | ~15 tests |
| **E2E (Playwright)** | Full user journey: chat → code interpreter → web search → speech → presets | ~20 tests |

---

## 14. Milestones & Timeline

```
Week 1:  ✅ Scaffold addon, SPA build setup, custom REST endpoints
Week 2:  ✅ Auth bridge (WP nonce), React chat UI working (App.tsx + SSE streaming)
Week 3:  ✅ Shortcode [nvoos_librechat], Gutenberg block nvoos/librechat, admin settings page
Week 4:  ✅ Pre-built bundle, PHPCS clean, documentation, 🚀 Phase 1 SHIP (v1.1.29)
         ─────────────────────────────────────────────
Week 5:  PHP REST controller for conversations + messages
Week 6:  CPT registration, message search, preset CRUD
Week 7:  ✅ Code interpreter service (Docker sandbox, 8 languages, WP-Cron dispatch)
Week 8:  ✅ Web search reranker (Jina/Cohere integrated into web_search tool)
Week 9:  ✅ Speech services (STT: OpenAI Whisper, TTS: OpenAI + ElevenLabs)
Week 10: Remove Node.js, migration tool, final tests, 🚀 Phase 2 SHIP
```

### Success Criteria

| Phase | Criteria |
|---|---|
| Phase 1 | Users can open `[nvoos_librechat]` on any page, chat with NV oOS assistants using LibreChat UI, with code interpreter, web search, and speech working |
| Phase 2 | All Phase 1 features work without Node.js/MongoDB. Conversations stored in WordPress. One-click install. No external services beyond AI provider APIs. |

---

## Appendix A: Comparison — Why LibreChat Over Alternatives

| Feature | NV oOS chat-spa | Open WebUI | LobeHub | Big-AGI | **LibreChat** |
|---|---|---|---|---|---|
| Stars | — | 141k | 78k | 7k | 39k |
| License | GPL | Custom | Community | MIT | **MIT** ✅ |
| Code interpreter | ❌ | ❌ | ❌ | ❌ | **✅** |
| Web search + rerank | Partial (3 providers) | ✅ (15+) | Partial | ✅ | **✅** (5+ + rerank) |
| Speech STT/TTS | ❌ | ✅ | ❌ | Partial | **✅** |
| Artifacts | ❌ | ❌ | ❌ | ❌ | **✅** |
| Conversation forking | ❌ | ❌ | ❌ | ❌ | **✅** |
| Presets | ❌ | ❌ | ❌ | ❌ | **✅** |
| Stack | React + PHP | Python + Svelte | Next.js | Next.js | **React + Node.js** |
| Backend complexity | None (WordPress) | Heavy (Python) | Medium (Vercel) | Light (Next.js) | Medium (Node.js) |
| Integration effort | — | High | High | Low | **Medium** |

**Verdict:** LibreChat wins on feature gap coverage with the least integration complexity. MIT license allows unrestricted forking and modification.

---

## Appendix B: Web Search Provider Comparison

| Provider | Type | Free Tier | API Quality | LibreChat Support | NV oOS Support |
|---|---|---|---|---|---|
| **Tavily** | Search + Scrape | 1,000/month | Excellent | ✅ | ✅ (via web_search tool) |
| **Serper** | Search (Google) | 2,500/month | Excellent | ✅ | ❌ |
| **Brave Search** | Search | 2,000/month | Good | ❌ | ✅ |
| **SearXNG** | Meta-search | Self-hosted | Good | ✅ | ❌ |
| **Firecrawl** | Scrape | 500/month | Excellent | ✅ | ❌ |
| **Jina** | Scrape + Rerank | 1,000,000 tokens | Excellent | ✅ | ✅ (via maybe_rerank_results) |
| **Cohere** | Rerank | 1,000/month | Excellent | ✅ | ✅ (via maybe_rerank_results) |
| **DuckDuckGo** | Search | Unlimited | Basic | ❌ | ✅ |

**Recommendation:** Phase 1 ships with Tavily (search + scrape in one) + Jina reranker as default. Phase 2 adds Serper + Brave as NV oOS tools.
