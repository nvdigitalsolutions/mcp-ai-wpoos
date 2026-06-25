# NV oOS → FastAPI Port: Implementation Plan

## Comprehensive Plan for Python/FastAPI Re-Implementation of the Base Plugin

**Status:** 📋 Proposal  
**Version:** 1.0.0  
**Date:** 2026-06-25  
**Author:** AI Agent (via Zed)  
**Audience:** Engineering leadership, architecture reviewers  

> **🔗 Related Documents:**
> - [`cross-platform-extraction-architecture.md`](./cross-platform-extraction-architecture.md) — the existing Hexagonal Architecture extraction (PHP)
> - [`cross-platform-extraction-gap-analysis.md`](./cross-platform-extraction-gap-analysis.md) — current-state assessment of the PHP extraction
> - [`lib/README.md`](../../lib/README.md) — the extracted core + adapter packages
> - [`.context/cross-platform-extraction.md`](../../.context/cross-platform-extraction.md) — Hexagonal Architecture rules for agents

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Motivation & Strategic Rationale](#2-motivation--strategic-rationale)
3. [Architecture Design](#3-architecture-design)
4. [Domain Contract Mapping (PHP → Python)](#4-domain-contract-mapping-php--python)
5. [API Surface Design](#5-api-surface-design)
6. [Tool Migration Plan](#6-tool-migration-plan)
7. [Provider Client Migration](#7-provider-client-migration)
8. [Agentic Loop & Chat Orchestrator](#8-agentic-loop--chat-orchestrator)
9. [Authentication & Security](#9-authentication--security)
10. [Storage & Persistence](#10-storage--persistence)
11. [SSE & Streaming Infrastructure](#11-sse--streaming-infrastructure)
12. [MCP Protocol Integration](#12-mcp-protocol-integration)
13. [Frontend Migration Strategy](#13-frontend-migration-strategy)
14. [Project Structure & Tooling](#14-project-structure--tooling)
15. [Implementation Phases & Timeline](#15-implementation-phases--timeline)
16. [Risk Analysis & Mitigation](#16-risk-analysis--mitigation)
17. [Testing Strategy](#17-testing-strategy)
18. [Key Decisions Record](#18-key-decisions-record)
19. [Open Questions & Next Steps](#19-open-questions--next-steps)
20. [Appendices](#20-appendices)

---

## 1. Executive Summary

### 1.1 The Opportunity

NV oOS currently operates as a **monolithic WordPress plugin** containing ~195 base tools, ~795 Pro tools, 13 AI provider clients, an agentic orchestration loop, SSE streaming, ACP protocol support, voice/realtime APIs, and a chat-memory bridge. The framework-agnostic extraction via `lib/core/` (Hexagonal Architecture, PHP 8.1+) has already decoupled the AI orchestration core from WordPress — proving the domain contracts and tool interfaces are viable across platforms.

This proposal takes the next logical step: **port the entire base plugin to Python/FastAPI**, targeting deployment scenarios where WordPress is unavailable or undesirable — cloud-native environments, edge computing, serverless platforms, and teams that prefer the Python/async ecosystem.

### 1.2 What We're Building

A standalone **Python 3.11+ application** that:

| Capability | Target |
|---|---|
| **MCP Server** | Full MCP compliance via official `mcp` Python SDK (tools, resources, prompts, notifications) |
| **Custom REST API** | FastAPI surface for chat, admin, analytics, workflows, approvals, threads, teams, voice, slash commands |
| **AI Provider Clients** | All 13 providers: OpenAI, Anthropic, Gemini, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, Cloudflare, HuggingFace, NVIDIA NIM, LM Studio, Ollama |
| **Agentic Loop** | Multi-iteration tool execution with TPM budget management, guardrails, context window validation |
| **SSE Streaming** | RFC 6202-compliant real-time streaming via `sse-starlette` |
| **Authentication** | OAuth2/JWT, API keys, guest tokens — 4 auth modes |
| **Storage** | PostgreSQL (primary), Redis (cache/queue), S3-compatible (files) |
| **Tools** | ~195 base tools ported from PHP, maintaining identical behavior |
| **Frontend** | Reuse existing JS chat UI with minimal HTTP adapter changes |

### 1.3 Why Python + FastAPI?

| Factor | Rationale |
|---|---|
| **Async-native** | `asyncio` + `httpx` = efficient concurrent I/O for 13 simultaneous provider calls |
| **Pydantic validation** | Declarative JSON Schema generation matches tool `inputSchema` requirements exactly |
| **MCP ecosystem** | Official `mcp` Python SDK from Anthropic — production-grade, actively maintained |
| **AI/ML adjacency** | Same language as HuggingFace, LangChain, LlamaIndex — seamless integration |
| **Developer velocity** | Type hints, auto-docs (OpenAPI/Swagger), fast iteration cycle |
| **Cloud-native** | Trivial containerization, serverless deployment, horizontal scaling |
| **Industry adoption** | FastAPI is the #1 async Python web framework; growing faster than Django REST |

### 1.4 Key Outcomes

| Outcome | Measurement |
|---|---|
| **Feature parity** | 100% of base tool behavior matches PHP implementation |
| **API contract parity** | All REST endpoints documented with identical request/response shapes |
| **Performance** | Async Python meets or exceeds PHP/Guzzle throughput under load |
| **Deployability** | Single `docker compose up` + `uvicorn` command |
| **Testability** | Full unit test suite without external dependencies (mockable adapters) |
| **Extensibility** | Same Hexagonal Architecture; swap PostgreSQL for MongoDB, Redis for Memcached |

---

## 2. Motivation & Strategic Rationale

### 2.1 Current Limitations

The WordPress plugin, while powerful, is constrained by:

- **PHP 7.4 floor** — cannot use `readonly` classes, enums, fibers, or `match` expressions in the main plugin
- **WordPress-only deployment** — every installation requires WordPress + MySQL + PHP
- **Synchronous I/O** — Guzzle HTTP calls block; parallel provider calls require workarounds
- **Testing friction** — unit tests require WordPress bootstrapping; slow, fragile
- **Ecosystem isolation** — Python AI/ML libraries (LangChain, LlamaIndex, HuggingFace transformers) are inaccessible

### 2.2 Strategic Benefits

| Benefit | Impact |
|---|---|
| **New addressable market** | Python/FastAPI ecosystem — 2nd most popular language on GitHub |
| **Cloud-native deployment** | AWS Lambda, GCP Cloud Run, Kubernetes — no PHP runtime dependency |
| **AI/ML pipeline integration** | Direct access to `transformers`, `sentence-transformers`, `spaCy`, `scikit-learn` |
| **Competitive differentiation** | First major MCP/AI orchestration framework available in both PHP and Python |
| **Community growth** | Python developers can contribute tools without learning WordPress |
| **Operational simplicity** | No WordPress core updates, plugin conflicts, or PHP version constraints |

### 2.3 Leveraging Existing Work

The `lib/core/` extraction provides:

- **Proven domain contracts** — 9 interfaces that have already been validated against WordPress, Laravel, and Craft CMS
- **Tool behavior specifications** — 82 tools extracted with defined inputs/outputs
- **Provider client logic** — 12 provider clients with known API behavior
- **Orchestrator algorithm** — agentic loop with configurable iterations, TPM budgeting
- **Test patterns** — existing PHP tests serve as specification for Python equivalents

---

## 3. Architecture Design

### 3.1 High-Level Architecture

```
┌──────────────────────────────────────────────────────────────────────┐
│                        CLIENT LAYER                                   │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────────────┐ │
│  │ JS Chat  │  │ MCP      │  │ REST API │  │ External Services    │ │
│  │ UI       │  │ Clients  │  │ Consumers│  │ (Auth0, Webhooks)    │ │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘  └──────────┬───────────┘ │
└───────┼──────────────┼─────────────┼───────────────────┼─────────────┘
        │              │             │                   │
        ▼              ▼             ▼                   ▼
┌──────────────────────────────────────────────────────────────────────┐
│                      TRANSPORT LAYER                                  │
│  ┌──────────────────┐  ┌──────────────────────────────────────────┐ │
│  │ Starlette /      │  │ FastAPI (APIRouter)                       │ │
│  │ FastMCP (ASGI)   │  │ /api/v1/chat, /api/v1/assistants, etc.   │ │
│  │ /mcp (JSON-RPC)  │  │ SSE streaming, OAuth2 token endpoints    │ │
│  └────────┬─────────┘  └────────────────────┬─────────────────────┘ │
└───────────┼─────────────────────────────────┼───────────────────────┘
            │                                 │
            ▼                                 ▼
┌──────────────────────────────────────────────────────────────────────┐
│                      APPLICATION LAYER                                │
│  ┌────────────────┐  ┌────────────┐  ┌──────────┐  ┌─────────────┐ │
│  │ ChatOrchestrator│  │ ToolRegistry│  │Provider  │  │SkillRegistry│ │
│  │ (agentic loop) │  │ (lifecycle)│  │Router    │  │(SKILL.md)   │ │
│  └───────┬────────┘  └─────┬──────┘  └────┬─────┘  └──────┬──────┘ │
└──────────┼─────────────────┼──────────────┼────────────────┼────────┘
           │                 │              │                │
           ▼                 ▼              ▼                ▼
┌──────────────────────────────────────────────────────────────────────┐
│                      DOMAIN LAYER                                     │
│  ┌──────────┐ ┌─────────┐ ┌──────────┐ ┌────────┐ ┌──────────────┐ │
│  │Contracts │ │Entities │ │Errors    │ │Events  │ │Value Objects │ │
│  │(Protocol)│ │(Pydantic)│ │(Exception)│ │(Event) │ │(frozen DC)   │ │
│  └────┬─────┘ └────┬────┘ └────┬─────┘ └───┬────┘ └──────┬───────┘ │
└───────┼────────────┼──────────┼────────────┼──────────────┼─────────┘
        │            │          │            │              │
        ▼            ▼          ▼            ▼              ▼
┌──────────────────────────────────────────────────────────────────────┐
│                    INFRASTRUCTURE LAYER                               │
│  ┌──────────────┐ ┌───────┐ ┌────────┐ ┌───────┐ ┌───────────────┐ │
│  │PostgreSQL    │ │Redis  │ │S3/MinIO│ │Celery │ │13 AI Provider│ │
│  │Adapter       │ │Adapter│ │Adapter │ │Adapter│ │Clients (httpx)│ │
│  │(SQLAlchemy)  │ │(redis)│ │(boto3) │ │(celery)│ │              │ │
│  └──────────────┘ └───────┘ └────────┘ └───────┘ └───────────────┘ │
└──────────────────────────────────────────────────────────────────────┘
```

### 3.2 Hexagonal Architecture (Python)

Following the same pattern as the PHP extraction, the Python port uses **Protocol classes** (Python's equivalent of interfaces) for the 9 domain contracts:

```
src/nvoos/
├── domain/
│   ├── contracts/          # 9 Protocol classes
│   │   ├── content_store.py
│   │   ├── auth_provider.py
│   │   ├── settings_store.py
│   │   ├── file_store.py
│   │   ├── cache_store.py
│   │   ├── queue_client.py
│   │   ├── event_dispatcher.py
│   │   ├── error_factory.py
│   │   └── tool.py
│   ├── entities/           # Pydantic v2 models
│   │   ├── content.py      # ContentItem, ContentCollection, ContentQuery
│   │   ├── auth.py         # AuthContext, Credential, UserInfo
│   │   ├── file.py         # StoredFile, FileUploadCommand
│   │   ├── job.py          # JobStatus, JobDefinition
│   │   └── assistant.py    # AssistantConfig, AssistantBlueprint
│   ├── errors.py           # Typed exception hierarchy
│   └── events.py           # Domain event classes
├── application/
│   ├── chat/
│   │   └── orchestrator.py      # ChatOrchestrator (agentic loop)
│   ├── provider/
│   │   └── router.py            # ProviderRouter
│   ├── tool/
│   │   └── registry.py          # ToolRegistry
│   └── skill/
│       ├── registry.py          # SkillRegistry
│       └── parser.py            # SKILL.md parser
├── infrastructure/
│   ├── providers/               # 13 AI provider clients
│   │   ├── base.py              # AIProviderClient (ABC)
│   │   ├── openai_compat.py     # OpenAI-compatible base
│   │   ├── openai.py
│   │   ├── anthropic.py
│   │   ├── gemini.py
│   │   ├── deepseek.py
│   │   ├── openrouter.py
│   │   ├── baseten.py
│   │   ├── kimi.py
│   │   ├── digitalocean.py
│   │   ├── cloudflare.py
│   │   ├── huggingface.py
│   │   ├── nvidia.py
│   │   ├── lmstudio.py
│   │   └── ollama.py
│   ├── streaming/
│   │   └── sse_handler.py       # SSE framing + heartbeat
│   ├── storage/
│   │   ├── sqlalchemy_content.py
│   │   ├── sqlalchemy_auth.py
│   │   ├── sqlalchemy_settings.py
│   │   ├── s3_file.py
│   │   ├── redis_cache.py
│   │   └── celery_queue.py
│   ├── auth/
│   │   ├── jwt.py               # JWT issuer + verifier
│   │   ├── api_key.py           # Static API key validator
│   │   └── guest.py             # Guest token issuer
│   ├── cost/
│   │   └── calculator.py        # Per-model token cost tracking
│   └── token/
│       └── counter.py           # tiktoken integration
├── tools/                        # ~195 tool implementations
│   ├── base.py                  # AbstractTool base class
│   ├── content/                 # Content CRUD (create_post, get_post, etc.)
│   ├── media/                   # Image/video generation tools
│   ├── search/                  # Search + semantic tools
│   ├── data/                    # Format/utility tools
│   ├── ai/                      # AI provider tools (embeddings, moderation)
│   ├── weather/                 # Weather + geospatial
│   ├── huggingface/             # HuggingFace dataset tools
│   ├── crawl4ai/                # Crawl4AI crawling tools
│   └── system/                  # System/health tools
├── api/                          # FastAPI route modules
│   ├── __init__.py              # router aggregation
│   ├── deps.py                  # FastAPI dependencies (DI)
│   ├── chat.py                  # /api/v1/chat/*
│   ├── chat_memory.py           # /api/v1/chat-memory/*
│   ├── assistants.py            # /api/v1/assistants/*
│   ├── tools.py                 # /api/v1/tools/*
│   ├── admin.py                 # /api/v1/admin/*
│   ├── analytics.py             # /api/v1/analytics/*
│   ├── threads.py               # /api/v1/threads/*
│   ├── teams.py                 # /api/v1/teams/*
│   ├── approvals.py             # /api/v1/approvals/*
│   ├── workflows.py             # /api/v1/workflows/*
│   ├── voice.py                 # /api/v1/voice/*
│   ├── slash_commands.py        # /api/v1/slash-commands/*
│   ├── sse.py                   # SSE streaming endpoints
│   └── auth.py                  # OAuth2 token + guest token
├── mcp_server.py                # FastMCP integration + mounting
└── config.py                    # pydantic-settings configuration
```

### 3.3 Data Flow: Chat Request (End-to-End)

```
1. CLIENT → POST /api/v1/chat { messages: [...], assistant_id: "..." }
2. FastAPI dependency injection resolves:
   - AuthProvider → authenticates JWT/API key/guest token
   - SettingsStore → loads assistant config
   - ToolRegistry → loads assistant's allowed tools
3. ChatOrchestrator.handle_chat():
   a. ProviderRouter.resolve(model) → selects AIProviderClient
   b. validate_context_window(messages, tools, model)
   c. Layer I guardrails check (jailbreak detection)
   d. LOOP:
      - Send messages + tool definitions to provider
      - If response has tool_calls:
        * Execute each tool via ToolRegistry.execute()
        * Validate TPM budget remaining
        * Append tool_results to messages
        * Continue loop
      - Else: break (final response)
   e. Strip orphaned tool calls
   f. Calculate cost via CostCalculator
4. Return ChatResponse { content, tool_calls, cost, tokens }
5. CLIENT ← 200 OK with response body
```

---

## 4. Domain Contract Mapping (PHP → Python)

### 4.1 Contract Correspondence

The 9 contracts from `lib/core/src/Domain/Contract/` map to Python `Protocol` classes:

| # | PHP Interface (`lib/core/src/Domain/Contract/`) | Python Protocol | Primary Methods |
|---|---|---|---|
| 1 | `ContentStoreInterface.php` | `ContentStore` | `get()`, `list()`, `create()`, `update()`, `delete()`, `search()`, `count()` |
| 2 | `AuthProviderInterface.php` | `AuthProvider` | `authenticate()`, `authorize()`, `get_current_user()`, `issue_token()`, `revoke_token()` |
| 3 | `SettingsStoreInterface.php` | `SettingsStore` | `get()`, `set()`, `delete()`, `get_all()`, `has()` |
| 4 | `FileStoreInterface.php` | `FileStore` | `upload()`, `download()`, `delete()`, `get_url()`, `get_metadata()` |
| 5 | `CacheStoreInterface.php` | `CacheStore` | `get()`, `set()`, `delete()`, `increment()`, `has()`, `clear()` |
| 6 | `QueueClientInterface.php` | `QueueClient` | `enqueue()`, `schedule()`, `cancel()`, `get_status()`, `list_jobs()` |
| 7 | `EventDispatcherInterface.php` | `EventDispatcher` | `dispatch()`, `add_listener()`, `add_filter()`, `remove_listener()` |
| 8 | `ErrorFactoryInterface.php` | `ErrorFactory` | `access_denied()`, `not_found()`, `validation_error()`, `server_error()`, `rate_limited()` |
| 9 | `ToolInterface.php` | `Tool` | `slug`, `definition`, `execute()`, `required_capability` |

### 4.2 Python Protocol Example

```python
from typing import Protocol, runtime_checkable
from nvoos.domain.entities.content import ContentItem, ContentCollection, ContentQuery

@runtime_checkable
class ContentStore(Protocol):
    """Framework-agnostic content storage contract. (Port)"""

    async def get(self, content_id: str) -> ContentItem | None:
        """Retrieve a single content item by ID."""
        ...

    async def list(self, query: ContentQuery) -> ContentCollection:
        """List content items matching query filters."""
        ...

    async def create(self, data: dict) -> ContentItem:
        """Create a new content item."""
        ...

    async def update(self, content_id: str, data: dict) -> ContentItem:
        """Update an existing content item."""
        ...

    async def delete(self, content_id: str) -> None:
        """Delete a content item."""
        ...

    async def search(self, query: str, limit: int = 20) -> ContentCollection:
        """Full-text search across content."""
        ...

    async def count(self, filters: dict | None = None) -> int:
        """Count content items matching filters."""
        ...
```

### 4.3 Default Adapter Implementation

The default adapter for all contracts uses production-grade Python libraries:

| Contract | Default Adapter | Library | Notes |
|---|---|---|---|
| `ContentStore` | `SQLAlchemyContentStore` | SQLAlchemy 2.0 (async) | PostgreSQL with JSONB metadata |
| `AuthProvider` | `SQLAlchemyAuthProvider` | SQLAlchemy + `passlib` | bcrypt password hashing |
| `SettingsStore` | `SQLAlchemySettingsStore` | SQLAlchemy | Key-value table, JSONB values |
| `FileStore` | `S3FileStore` | `boto3` (async via `aioboto3`) | S3 / MinIO / R2 compatible |
| `CacheStore` | `RedisCacheStore` | `redis-py` (async) | TTL support, pattern delete |
| `QueueClient` | `CeleryQueueClient` | Celery 5.x | Redis broker, priority queues |
| `EventDispatcher` | `InProcessEventDispatcher` | Custom | In-process pub/sub with filters |
| `ErrorFactory` | `FastAPIErrorFactory` | Custom | Maps domain errors → HTTP exceptions |

---

## 5. API Surface Design

### 5.1 Split Strategy: MCP SDK vs FastAPI

The port maintains a clear separation between MCP protocol endpoints and custom REST:

| Layer | Technology | Verbs | What It Covers |
|---|---|---|---|
| **MCP Protocol** | `mcp` SDK (FastMCP) | JSON-RPC 2.0 over HTTP POST | `initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read`, `prompts/list`, `prompts/get`, notifications, logging |
| **Custom REST** | FastAPI | RESTful (GET/POST/PUT/DELETE) | Chat messages, chat memory, assistants CRUD, admin dashboards, analytics, workflows, approvals, teams, threads, voice synthesis/transcription, slash commands, SSE streaming, OAuth2 auth |
| **Streaming** | `sse-starlette` + Starlette `StreamingResponse` | SSE / chunked transfer | Real-time chat token streaming, tool execution progress events |
| **Admin UI** | Static files served by FastAPI | GET | Reused JS chat UI + admin dashboard (Phase 6) |

### 5.2 Complete Route Table

```
# ═══ MCP Protocol (FastMCP, mounted at /mcp) ═══
POST   /mcp                              # JSON-RPC 2.0 endpoint

# ═══ Authentication ═══
POST   /api/v1/auth/token                # OAuth2 password flow → access token
POST   /api/v1/auth/refresh              # Refresh token
POST   /api/v1/auth/guest                # Temporary guest token
DELETE /api/v1/auth/token                # Revoke token

# ═══ Chat & Agentic Loop ═══
POST   /api/v1/chat                      # Send chat message (non-streaming)
POST   /api/v1/chat/stream               # Send chat message (SSE streaming)
GET    /api/v1/chat/transcripts          # List transcripts for user
GET    /api/v1/chat/transcripts/{id}     # Retrieve transcript
DELETE /api/v1/chat/transcripts/{id}     # Delete transcript

# ═══ Chat Memory ═══
GET    /api/v1/chat-memory/{context_id}  # Recall memory for context
POST   /api/v1/chat-memory/              # Store/update memory
POST   /api/v1/chat-memory/wake-up       # Wake up dormant context
POST   /api/v1/chat-memory/audit         # Audit trail for a context
GET    /api/v1/chat-memory/sessions/{id} # Session replay

# ═══ Assistants CRUD ═══
GET    /api/v1/assistants                # List all assistants
POST   /api/v1/assistants                # Create assistant
GET    /api/v1/assistants/{id}           # Get assistant config
PUT    /api/v1/assistants/{id}           # Update assistant
DELETE /api/v1/assistants/{id}           # Delete assistant
POST   /api/v1/assistants/{id}/clone     # Clone assistant

# ═══ Tools ═══
GET    /api/v1/tools                     # List registered tools
GET    /api/v1/tools/{slug}              # Get tool definition
POST   /api/v1/tools/{slug}/execute      # Direct tool execution (non-MCP)

# ═══ Threads ═══
GET    /api/v1/threads                   # List threads
POST   /api/v1/threads                   # Create thread
GET    /api/v1/threads/{id}              # Get thread
DELETE /api/v1/threads/{id}              # Delete thread
GET    /api/v1/threads/{id}/messages     # List messages in thread

# ═══ Teams (Multi-Agent) ═══
GET    /api/v1/teams                     # List teams
POST   /api/v1/teams                     # Create team
GET    /api/v1/teams/{id}                # Get team
PUT    /api/v1/teams/{id}                # Update team
DELETE /api/v1/teams/{id}                # Delete team
POST   /api/v1/teams/{id}/invoke         # Invoke team (fan-out)

# ═══ Approvals (Human-in-the-Loop) ═══
GET    /api/v1/approvals                 # List pending approvals
GET    /api/v1/approvals/{id}            # Get approval details
POST   /api/v1/approvals/{id}/approve    # Approve
POST   /api/v1/approvals/{id}/reject     # Reject

# ═══ Workflows ═══
GET    /api/v1/workflows                 # List workflows
POST   /api/v1/workflows                 # Create workflow
GET    /api/v1/workflows/{id}            # Get workflow
PUT    /api/v1/workflows/{id}            # Update workflow
DELETE /api/v1/workflows/{id}            # Delete workflow
POST   /api/v1/workflows/{id}/execute    # Execute workflow
GET    /api/v1/workflows/{id}/runs       # List run history
GET    /api/v1/workflows/{id}/runs/{run} # Get specific run

# ═══ Voice ═══
POST   /api/v1/voice/synthesize          # Text-to-speech (OpenAI/Gemini)
POST   /api/v1/voice/transcribe          # Speech-to-text

# ═══ Slash Commands ═══
GET    /api/v1/slash-commands            # List available commands
POST   /api/v1/slash-commands/{cmd}      # Execute slash command

# ═══ Admin ═══
GET    /api/v1/admin/settings            # Current configuration
PUT    /api/v1/admin/settings            # Update configuration
GET    /api/v1/admin/health              # System health check
GET    /api/v1/admin/models              # Available AI models catalog

# ═══ Analytics ═══
GET    /api/v1/analytics/usage           # Token usage over time
GET    /api/v1/analytics/costs           # Cost breakdown by provider/model
GET    /api/v1/analytics/tools           # Most-used tools
GET    /api/v1/analytics/errors          # Error rate over time

# ═══ SSE Streaming ═══
GET    /api/v1/sse/chat/{session_id}     # Per-session chat SSE stream
GET    /api/v1/sse/jobs/{job_id}         # Job status SSE stream
```

### 5.3 Pydantic Request/Response Models (Key Examples)

```python
from pydantic import BaseModel, Field
from datetime import datetime
from uuid import UUID

# ── Chat ──
class ChatMessage(BaseModel):
    role: str = Field(..., pattern="^(system|user|assistant|tool)$")
    content: str
    name: str | None = None
    tool_call_id: str | None = None

class ChatRequest(BaseModel):
    messages: list[ChatMessage]
    assistant_id: UUID | None = None
    model: str | None = None
    stream: bool = False
    max_tokens: int | None = None
    temperature: float = Field(default=0.7, ge=0.0, le=2.0)

class ChatResponse(BaseModel):
    id: UUID
    content: str
    role: str = "assistant"
    tool_calls: list[dict] = []
    usage: dict[str, int]
    cost: float
    model: str
    created_at: datetime

# ── Tools ──
class ToolDefinition(BaseModel):
    name: str
    description: str
    input_schema: dict  # JSON Schema
    required_capability: str
    category: str
    is_read_only: bool = True
    is_async: bool = False

class ToolExecuteRequest(BaseModel):
    arguments: dict
    context: dict = {}

class ToolExecuteResponse(BaseModel):
    success: bool
    data: dict | None = None
    error: str | None = None

# ── Assistant ──
class AssistantCreate(BaseModel):
    name: str = Field(..., min_length=1, max_length=255)
    system_prompt: str
    model: str
    tools: list[str] = []
    temperature: float = Field(default=0.7, ge=0.0, le=2.0)
    max_iterations: int = Field(default=10, ge=1, le=50)

class AssistantResponse(BaseModel):
    id: UUID
    name: str
    system_prompt: str
    model: str
    tools: list[str]
    temperature: float
    max_iterations: int
    created_at: datetime
    updated_at: datetime
```

---

## 6. Tool Migration Plan

### 6.1 Current Tool Landscape

| Category | PHP Location | Count | Python Target |
|---|---|---|---|
| **Extracted (framework-agnostic)** | `lib/core/src/Tool/` | 82 | Phase 1: direct port |
| **Base plugin (WP-coupled)** | `includes/tools/` | ~113 | Phase 2-3: rewrite with adapter |
| **Total base tools** | — | ~195 | Phase 5: full coverage |

### 6.2 Tool Porting Pattern

**PHP source** (`lib/core/src/Tool/CreatePostTool.php`):
```php
final readonly class CreatePostTool extends AbstractTool {
    public function __construct(
        private ContentStoreInterface $content_store,
        private AuthProviderInterface $auth,
        private ErrorFactoryInterface $errors,
        private EventDispatcherInterface $events,
    ) {}

    public function get_slug(): string {
        return 'create_post';
    }

    public function get_definition(): array {
        return [
            'name' => 'Create Post',
            'description' => 'Create a new content post...',
            'required_capability' => 'create_posts',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Post title'],
                    'content' => ['type' => 'string', 'description' => 'Post body'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'publish']],
                ],
                'required' => ['title'],
            ],
        ];
    }

    public function execute(array $arguments, array $context): array {
        $user = $this->auth->get_current_user();
        if (!$user || !$this->auth->authorize($user, 'create_posts')) {
            return $this->errors->access_denied('create_posts');
        }

        $post = $this->content_store->create(new CreateContentCommand(
            title: $arguments['title'],
            content: $arguments['content'] ?? '',
            status: $arguments['status'] ?? 'draft',
            author_id: $user->id,
        ));

        $this->events->dispatch(new ContentCreated($post));
        return ['success' => true, 'post_id' => $post->id, 'url' => $post->url];
    }
}
```

**Python target** (`src/nvoos/tools/content/create_post.py`):
```python
from nvoos.tools.base import AbstractTool, ToolResult
from nvoos.domain.contracts import ContentStore, AuthProvider, EventDispatcher, ErrorFactory
from nvoos.domain.entities.content import CreateContentCommand
from nvoos.domain.events import ContentCreated

class CreatePostTool(AbstractTool):
    """Create a new content post."""

    def __init__(
        self,
        content_store: ContentStore,
        auth: AuthProvider,
        errors: ErrorFactory,
        events: EventDispatcher,
    ):
        self.content_store = content_store
        self.auth = auth
        self.errors = errors
        self.events = events

    @property
    def slug(self) -> str:
        return "create_post"

    @property
    def definition(self) -> dict:
        return {
            "name": "Create Post",
            "description": "Create a new content post with title, body, and status.",
            "required_capability": "create_posts",
            "input_schema": {
                "type": "object",
                "properties": {
                    "title": {"type": "string", "description": "Post title"},
                    "content": {"type": "string", "description": "Post body content"},
                    "status": {
                        "type": "string",
                        "enum": ["draft", "publish"],
                        "default": "draft",
                    },
                },
                "required": ["title"],
            },
        }

    async def execute(self, arguments: dict, context: dict) -> ToolResult:
        user = await self.auth.get_current_user()
        if not user or not await self.auth.authorize(user, "create_posts"):
            return ToolResult.error(self.errors.access_denied("create_posts"))

        cmd = CreateContentCommand(
            title=self._sanitize_str(arguments["title"]),
            content=self._sanitize_html(arguments.get("content", "")),
            status=arguments.get("status", "draft"),
            author_id=user.id,
        )
        post = await self.content_store.create(cmd)
        await self.events.dispatch(ContentCreated(post=post))

        return ToolResult.success({
            "post_id": str(post.id),
            "url": post.url,
        })
```

### 6.3 Tool Migration Phases

**Phase 1 — Data & Utility Tools (26 tools, Week 1-3):**
Core utility tools with no external dependencies:
`format_date`, `time_ago`, `parse_csv`, `math_eval`, `count_tokens`, `hash_string`,
`validate_json`, `strip_html`, `truncate_text`, `color_convert`, `merge_arrays`,
`generate_slug`, `generate_uuid`, `format_bytes`, `extract_domain`, `base64_tool`,
`count_posts`, `get_post_meta`, `get_post_taxonomies`, `delete_cache`,
`increment_cache`, `get_cache`, `set_cache`, `get_setting`, `set_setting`,
`delete_setting`, `list_settings`

**Phase 2 — Content CRUD Tools (15 tools, Week 3-5):**
`create_post`, `get_post`, `update_post`, `delete_post`, `save_post`,
`get_recent_posts`, `search_content`, `search_attachments`, `get_site_summary`,
`get_post_type_schema`, `create_term`, `update_term`, `get_user_info`,
`get_current_user`, `check_capability`

**Phase 3 — AI Provider Tools (18 tools, Week 5-7):**
`create_text_embeddings`, `generate_openai_image`, `edit_openai_image`,
`generate_openai_speech`, `transcribe_openai_audio`, `generate_gemini_image`,
`edit_gemini_image`, `analyze_image`, `moderate_content`, `suggest_best_model`,
`get_model_information`, `list_available_models`, `web_search`, `deep_research`,
`generate_chart`, `generate_mermaid`, `count_tokens` (provider-aware),
`get_environment_status`

**Phase 4 — External Integration Tools (23 tools, Week 7-9):**
10 HuggingFace dataset tools + `get_gdacs_events`, `get_nhc_active_storms`,
`get_open_meteo_forecast`, `geocode_address`, `reliefweb_reports`,
`search_places`, `run_crawl4ai_job`, `crawl4ai_price_lookup`,
`scrape_product`, `probe_remote_mcp`, `invoke_jetengine_route`

**Phase 5 — Remaining Base Tools (~113 tools, Week 9-12):**
Complete coverage of all remaining base plugin tools.

### 6.4 Tool Registry Design

```python
from collections.abc import AsyncIterator

class ToolRegistry:
    """Maintains registered tools. Supports hook-based discovery."""

    def __init__(self):
        self._tools: dict[str, Tool] = {}
        self._initialized = False

    async def init(self) -> None:
        """Discover and register all tools."""
        if self._initialized:
            return

        # Built-in tools discovered via entry points or manual registration
        await self._discover_builtins()
        # Hook: allow plugins to register custom tools
        await self._dispatch_hook("tools.register", self)
        self._initialized = True

    def register(self, tool: Tool) -> None:
        """Register a tool instance."""
        self._tools[tool.slug] = tool

    def get(self, slug: str) -> Tool | None:
        """Get a tool by slug."""
        return self._tools.get(slug)

    def get_all(self) -> list[Tool]:
        """Get all registered tools."""
        return list(self._tools.values())

    def get_definitions(self) -> list[dict]:
        """Get tool definitions for LLM payload assembly."""
        return [tool.definition for tool in self._tools.values()]

    async def execute(
        self, slug: str, arguments: dict, context: dict
    ) -> ToolResult:
        """Execute a tool by slug with arguments."""
        tool = self._tools.get(slug)
        if not tool:
            return ToolResult.error(f"Unknown tool: {slug}")

        # Pre-execution hook
        await self._dispatch_hook("tools.before_execute", slug, arguments)

        result = await tool.execute(arguments, context)

        # Post-execution hook
        await self._dispatch_hook("tools.after_execute", slug, arguments, result)

        return result
```

### 6.5 Canonical Return Envelope (P0 Compliance)

Following the PHP canonical return envelope pattern:

```python
from dataclasses import dataclass, field

@dataclass
class ToolResult:
    """Canonical tool execution result. Always success=True or an error string."""
    success: bool
    data: dict | None = None
    error: str | None = None

    @classmethod
    def success_result(cls, data: dict | None = None) -> "ToolResult":
        return cls(success=True, data=data or {})

    @classmethod
    def error_result(cls, error: str) -> "ToolResult":
        return cls(success=False, error=error)
```

---

## 7. Provider Client Migration

### 7.1 Provider Client Hierarchy

```
AIProviderClient (ABC)
├── OpenAICompatibleClient (ABC)
│   ├── OpenAIClient
│   ├── DeepSeekClient
│   ├── OpenRouterClient
│   ├── KimiClient
│   ├── DigitalOceanClient
│   ├── CloudflareClient
│   ├── NVIDIA_NIM_Client
│   ├── BasetenClient
│   ├── LMStudioClient
│   └── OllamaClient
├── AnthropicClient
├── GeminiClient
└── HuggingFaceClient
```

### 7.2 Base Provider Client

```python
from abc import ABC, abstractmethod
from dataclasses import dataclass
import httpx

@dataclass
class ChatCompletionResult:
    content: str
    tool_calls: list[dict]
    usage: dict[str, int]  # prompt_tokens, completion_tokens, total_tokens
    model: str
    finish_reason: str

class AIProviderClient(ABC):
    """Base for all AI provider clients."""

    def __init__(
        self,
        settings: "SettingsStore",
        http: httpx.AsyncClient,
        token_counter: "TokenCounter",
    ):
        self.settings = settings
        self.http = http
        self.token_counter = token_counter

    @property
    @abstractmethod
    def provider_name(self) -> str: ...

    @abstractmethod
    async def chat_completion(
        self,
        messages: list[dict],
        model: str,
        tools: list[dict] | None = None,
        stream: bool = False,
        max_tokens: int | None = None,
        temperature: float = 0.7,
    ) -> ChatCompletionResult: ...

    @abstractmethod
    async def get_models(self) -> list[dict]: ...

    @abstractmethod
    def get_context_window(self, model: str) -> int: ...

    @abstractmethod
    def validate_context_window(
        self, model: str, messages: list[dict], tools: list[dict] | None
    ) -> bool: ...

    async def get_api_key(self) -> str:
        """Resolve API key: settings → env → config."""
        settings_key = await self.settings.get(f"{self.provider_name}_api_key")
        if settings_key:
            return settings_key
        import os
        env_key = os.getenv(f"NVOOS_{self.provider_name.upper()}_API_KEY", "")
        if env_key:
            return env_key
        raise ValueError(f"No API key configured for {self.provider_name}")
```

### 7.3 ProviderRouter

```python
class ProviderRouter:
    """Routes model names to provider clients."""

    def __init__(self):
        self._providers: dict[str, AIProviderClient] = {}
        self._model_map: dict[str, str] = {}

    def register(self, provider: AIProviderClient, models: list[str]) -> None:
        """Register a provider and its supported models."""
        self._providers[provider.provider_name] = provider
        for model in models:
            self._model_map[model.lower()] = provider.provider_name

    def resolve(self, model: str) -> AIProviderClient:
        """Resolve a model name to its provider client."""
        provider_name = self._model_map.get(model.lower())
        if not provider_name:
            # Heuristic: try prefix matching
            for prefix, name in self._model_map.items():
                if model.lower().startswith(prefix):
                    provider_name = name
                    break
        if not provider_name:
            raise ValueError(f"No provider found for model: {model}")
        return self._providers[provider_name]
```

### 7.4 Provider Client Roadmap

| Week | Providers | Notes |
|---|---|---|
| 1-2 | OpenAI, Anthropic, Gemini | Core three; largest user base |
| 3-4 | DeepSeek, OpenRouter, Ollama | OpenAI-compatible; mostly config differences |
| 5-6 | Kimi, DigitalOcean, Cloudflare, Baseten | OpenAI-compatible |
| 7-8 | NVIDIA NIM, LM Studio, HuggingFace | Remaining providers |
| 9-10 | ProviderRouter, context window validation, integration tests | Polish |

---

## 8. Agentic Loop & Chat Orchestrator

### 8.1 Orchestrator Design

```python
from dataclasses import dataclass, field

@dataclass
class OrchestratorConfig:
    max_iterations: int = 10
    tpm_safety_margin: float = 0.8
    tpm_fallback_tokens: int = 100_000
    guardrails_enabled: bool = True
    cost_tracking_enabled: bool = True

class ChatOrchestrator:
    """Orchestrates the agentic loop: send → receive → execute tools → repeat."""

    def __init__(
        self,
        provider_router: ProviderRouter,
        tool_registry: ToolRegistry,
        event_dispatcher: EventDispatcher,
        error_factory: ErrorFactory,
        cost_calculator: "CostCalculator",
        config: OrchestratorConfig = field(default_factory=OrchestratorConfig),
    ):
        self.provider_router = provider_router
        self.tool_registry = tool_registry
        self.events = event_dispatcher
        self.errors = error_factory
        self.cost_calculator = cost_calculator
        self.config = config

    async def handle_chat(
        self,
        messages: list[dict],
        assistant_config: dict,
        context: dict,
    ) -> ChatResponse:
        """Execute the agentic loop for a single chat turn."""

        # Step 1: Resolve provider
        model = assistant_config.get("model", context.get("model"))
        provider = self.provider_router.resolve(model)
        allowed_tools = await self._load_allowed_tools(assistant_config)

        # Step 2: Pre-flight context window validation
        if not provider.validate_context_window(model, messages, allowed_tools):
            messages = await self._trim_messages(messages, provider, model)

        # Step 3: Layer I guardrails (jailbreak detection)
        if self.config.guardrails_enabled:
            await self._run_guardrails(messages, assistant_config)

        # Step 4: Agentic loop
        total_tokens = 0
        for iteration in range(self.config.max_iterations):
            await self.events.dispatch("chat.iteration.start", iteration=iteration)

            # Send to provider
            response = await provider.chat_completion(
                messages=messages,
                model=model,
                tools=allowed_tools,
                max_tokens=assistant_config.get("max_tokens"),
                temperature=assistant_config.get("temperature", 0.7),
            )

            total_tokens += response.usage["total_tokens"]

            # Check TPM budget
            if not self._check_tpm_budget(total_tokens, provider, model):
                await self.events.dispatch("chat.tpm_exceeded")
                break

            # No tool calls → return final response
            if not response.tool_calls:
                cost = self.cost_calculator.calculate(model, response.usage)
                return ChatResponse(
                    content=response.content,
                    usage=response.usage,
                    cost=cost,
                    model=model,
                    iterations=iteration + 1,
                )

            # Execute tool calls sequentially
            tool_results = []
            for tool_call in response.tool_calls:
                result = await self.tool_registry.execute(
                    slug=tool_call["function"]["name"],
                    arguments=tool_call["function"]["arguments"],
                    context=context,
                )
                tool_results.append((tool_call["id"], result))

            # Append tool results to messages
            messages.append({
                "role": "assistant",
                "content": response.content,
                "tool_calls": response.tool_calls,
            })
            for call_id, result in tool_results:
                messages.append({
                    "role": "tool",
                    "tool_call_id": call_id,
                    "content": json.dumps(result.data) if result.success else result.error,
                })

        raise MaxIterationsExceededError(
            f"Agentic loop exceeded {self.config.max_iterations} iterations"
        )
```

### 8.2 Context Window Management

```python
def validate_context_window(
    self,
    messages: list[dict],
    tools: list[dict] | None,
    model: str,
    max_tokens: int | None = None,
) -> bool:
    """Pre-flight context window validation across all providers."""
    window = self.get_context_window(model)
    available = window - (max_tokens or 4096)

    # Estimate prompt tokens
    prompt_tokens = self.token_counter.estimate_messages(messages)
    tool_tokens = self.token_counter.estimate_tools(tools or [])
    total_estimate = prompt_tokens + tool_tokens

    # 80% threshold triggers tool capping
    if total_estimate > available * 0.8:
        return False  # Caller should trim messages or cap tools

    return True
```

---

## 9. Authentication & Security

### 9.1 Auth Mode Migration

| WordPress Auth | FastAPI Equivalent | Library |
|---|---|---|
| WP Nonce (`X-WP-Nonce`) | Bearer JWT | `python-jose[cryptography]` |
| Assistant credential (`cred_xxxxx.SECRET`) | OAuth2 client credentials | `python-jose` |
| Auth0 tokens | OAuth2/OIDC bearer | `fastapi-auth0` or custom `HTTPBearer` |
| Guest token (`X-WP-MCP-AI-Guest`) | Short-lived JWT with restricted scope | `python-jose` |
| Mesh API key | API key header (`X-API-Key`) | Custom middleware |

### 9.2 FastAPI Dependency Chain

```python
from fastapi import Depends, HTTPException, Security
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials
from nvoos.domain.contracts import AuthProvider

security = HTTPBearer(auto_error=False)
api_key_header = APIKeyHeader(name="X-API-Key", auto_error=False)

async def get_auth_provider() -> AuthProvider:
    """Resolve the auth provider from DI container."""
    return container.auth_provider

async def get_current_user(
    credentials: HTTPAuthorizationCredentials | None = Security(security),
    api_key: str | None = Security(api_key_header),
    auth: AuthProvider = Depends(get_auth_provider),
) -> UserInfo:
    """Authenticate via JWT bearer or API key."""
    if credentials:
        user = await auth.authenticate(credentials.credentials)
    elif api_key:
        user = await auth.authenticate_api_key(api_key)
    else:
        raise HTTPException(status_code=401, detail="Authentication required")
    if not user:
        raise HTTPException(status_code=401, detail="Invalid credentials")
    return user

def require_capability(capability: str):
    """Factory: create a dependency that checks a specific capability."""
    async def checker(
        user: UserInfo = Depends(get_current_user),
        auth: AuthProvider = Depends(get_auth_provider),
    ) -> UserInfo:
        if not await auth.authorize(user, capability):
            raise HTTPException(status_code=403, detail=f"Missing capability: {capability}")
        return user
    return checker

# Usage:
@router.post("/api/v1/assistants")
async def create_assistant(
    body: AssistantCreate,
    user: UserInfo = Depends(require_capability("manage_assistants")),
): ...
```

---

## 10. Storage & Persistence

### 10.1 Storage Migration Map

| WordPress Storage | FastAPI Replacement | Technology |
|---|---|---|
| `get_option()` / `update_option()` | `SettingsStore` → `settings` table | SQLAlchemy + PostgreSQL JSONB |
| `WP_Post` / `WP_Query` | `ContentStore` → `content_items` table | SQLAlchemy 2.0 async + PostgreSQL |
| Post meta (`get_post_meta()`) | `content_item_meta` JSONB column | PostgreSQL JSONB |
| `WP_User` / user meta | `users` table + JSONB metadata | SQLAlchemy + passlib (bcrypt) |
| `get_transient()` / `set_transient()` | Redis | `redis-py` (async) |
| `wp_upload_dir()` | S3-compatible (MinIO dev, S3 prod) | `aioboto3` |
| Action Scheduler | Celery tasks + Redis broker | Celery 5.x |
| WP-Cron | Celery Beat / APScheduler | Celery Beat |
| Assistant CPT (`mcp_ai_assistant`) | `assistants` table | SQLAlchemy |
| Chat transcripts (CCT) | `transcripts` table | SQLAlchemy |
| Credentials (post meta) | `credentials` table (encrypted) | SQLAlchemy + `cryptography` |

### 10.2 Core SQLAlchemy Models

```python
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column, relationship
from sqlalchemy.dialects.postgresql import JSONB, UUID
from sqlalchemy import DateTime, String, Text, Integer, Float, ForeignKey, func
import uuid
from datetime import datetime

class Base(DeclarativeBase):
    pass

class ContentItem(Base):
    __tablename__ = "content_items"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    title: Mapped[str] = mapped_column(String(500), nullable=False)
    slug: Mapped[str] = mapped_column(String(500), unique=True)
    content: Mapped[str] = mapped_column(Text, default="")
    excerpt: Mapped[str | None] = mapped_column(Text, nullable=True)
    status: Mapped[str] = mapped_column(String(20), default="draft")
    content_type: Mapped[str] = mapped_column(String(50), default="post")
    author_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("users.id"))
    parent_id: Mapped[uuid.UUID | None] = mapped_column(ForeignKey("content_items.id"), nullable=True)
    meta: Mapped[dict] = mapped_column(JSONB, default=dict)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())

    author: Mapped["User"] = relationship(back_populates="posts")

class User(Base):
    __tablename__ = "users"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    username: Mapped[str] = mapped_column(String(100), unique=True)
    email: Mapped[str] = mapped_column(String(255), unique=True)
    hashed_password: Mapped[str] = mapped_column(String(255))
    is_active: Mapped[bool] = mapped_column(default=True)
    is_superuser: Mapped[bool] = mapped_column(default=False)
    capabilities: Mapped[list[str]] = mapped_column(JSONB, default=list)
    meta: Mapped[dict] = mapped_column(JSONB, default=dict)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())

    posts: Mapped[list["ContentItem"]] = relationship(back_populates="author")

class Assistant(Base):
    __tablename__ = "assistants"

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    name: Mapped[str] = mapped_column(String(255))
    system_prompt: Mapped[str] = mapped_column(Text)
    model: Mapped[str] = mapped_column(String(100))
    tools: Mapped[list[str]] = mapped_column(JSONB, default=list)
    temperature: Mapped[float] = mapped_column(Float, default=0.7)
    max_tokens: Mapped[int | None] = mapped_column(Integer, nullable=True)
    max_iterations: Mapped[int] = mapped_column(Integer, default=10)
    config: Mapped[dict] = mapped_column(JSONB, default=dict)
    owner_id: Mapped[uuid.UUID] = mapped_column(ForeignKey("users.id"))
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now())
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())

class Settings(Base):
    __tablename__ = "settings"

    key: Mapped[str] = mapped_column(String(255), primary_key=True)
    value: Mapped[dict] = mapped_column(JSONB, nullable=False)
    autoload: Mapped[bool] = mapped_column(default=False)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now())
```

### 10.3 Alembic Migrations

```bash
# Initialize
alembic init alembic

# Generate migration
alembic revision --autogenerate -m "initial_schema"

# Apply
alembic upgrade head
```

---

## 11. SSE & Streaming Infrastructure

### 11.1 PHP Current: `WP_MCP_AI_SSE_Handler`

| Parameter | Value |
|---|---|
| Chunk size | 50 characters |
| Retry interval | 3,000 ms |
| Heartbeat interval | Every 5 polls (15 seconds) |
| Job polling | 120 polls × 3 seconds (6 minutes max) |
| Rate limiting | `WP_MCP_AI_SSE_Rate_Limiter` |

### 11.2 Python SSE Implementation

```python
import asyncio
import json
from starlette.responses import StreamingResponse
from sse_starlette.sse import EventSourceResponse

STREAMING_CHUNK_SIZE = 50
STREAMING_CHUNK_DELAY = 0.01  # 10ms
HEARTBEAT_INTERVAL = 15.0  # seconds

async def chat_stream(
    messages: list[dict],
    assistant_config: dict,
    orchestrator: ChatOrchestrator,
) -> AsyncIterator[dict]:
    """Generator that yields SSE frames for chat streaming."""

    last_heartbeat = asyncio.get_event_loop().time()

    async for event in orchestrator.handle_chat_stream(messages, assistant_config):
        now = asyncio.get_event_loop().time()

        # Send heartbeat
        if now - last_heartbeat > HEARTBEAT_INTERVAL:
            yield {"event": "heartbeat", "data": ""}
            last_heartbeat = now

        # Simulated chunking for non-streaming providers
        if "content" in event:
            content = event["content"]
            for i in range(0, len(content), STREAMING_CHUNK_SIZE):
                chunk = content[i : i + STREAMING_CHUNK_SIZE]
                yield {
                    "event": "message",
                    "data": json.dumps({"content": chunk}),
                }
                await asyncio.sleep(STREAMING_CHUNK_DELAY)
        else:
            yield {
                "event": event.get("type", "message"),
                "data": json.dumps(event),
            }

    # Final frame
    yield {"event": "done", "data": "[DONE]"}

# FastAPI endpoint
@router.post("/api/v1/chat/stream")
async def chat_stream_endpoint(
    request: ChatRequest,
    orchestrator: ChatOrchestrator = Depends(get_orchestrator),
):
    return EventSourceResponse(
        chat_stream(request.messages, {}, orchestrator),
        headers={
            "Cache-Control": "no-cache",
            "X-Accel-Buffering": "no",
        },
    )
```

---

## 12. MCP Protocol Integration

### 12.1 FastMCP Server Configuration

The official `mcp` Python SDK provides `FastMCP` which integrates natively with Starlette/FastAPI ASGI applications:

```python
# src/nvoos/mcp_server.py
from mcp.server.fastmcp import FastMCP
from nvoos.application.tool.registry import ToolRegistry

def create_mcp_server(tool_registry: ToolRegistry) -> FastMCP:
    """Create and configure the FastMCP server with all registered tools."""

    mcp = FastMCP(
        "NV oOS",
        instructions="AI Assistant framework with 13 providers and 195+ tools.",
        stateless_http=True,   # Production mode — no session state on server
        json_response=True,     # JSON by default (no SSE overhead for simple calls)
    )

    # Register all tools from the tool registry as MCP tools
    for tool in tool_registry.get_all():
        async def handler(arguments=..., ctx=..., _tool=tool):
            result = await _tool.execute(arguments, {"mcp_context": ctx})
            if result.success:
                return result.data
            raise ValueError(result.error)

        mcp.tool(
            name=tool.slug,
            title=tool.definition.get("name", tool.slug),
            description=tool.definition.get("description", ""),
            input_schema=tool.definition.get("input_schema", {}),
        )(handler)

    # Register resources (optional)
    @mcp.resource("info://server")
    def server_info() -> str:
        return json.dumps({
            "name": "NV oOS FastAPI",
            "version": "1.0.0",
            "tools_count": len(tool_registry.get_all()),
        })

    return mcp
```

### 12.2 Mounting in FastAPI

```python
# src/nvoos/main.py
from contextlib import asynccontextmanager
from fastapi import FastAPI
from starlette.routing import Mount

from nvoos.mcp_server import create_mcp_server
from nvoos.api import chat, assistants, tools, admin, auth, sse
from nvoos.di.container import Container

@asynccontextmanager
async def lifespan(app: FastAPI):
    """Application lifecycle: startup and shutdown."""
    container = Container()
    await container.init()

    # Wire MCP server
    mcp_server = create_mcp_server(container.tool_registry)
    app.mount("/mcp", mcp_server.streamable_http_app())

    # Store container for DI
    app.state.container = container
    yield
    await container.shutdown()

app = FastAPI(
    title="NV oOS API",
    version="1.0.0",
    lifespan=lifespan,
)

# Include FastAPI routers
app.include_router(auth.router, prefix="/api/v1/auth", tags=["auth"])
app.include_router(chat.router, prefix="/api/v1/chat", tags=["chat"])
app.include_router(assistants.router, prefix="/api/v1/assistants", tags=["assistants"])
app.include_router(tools.router, prefix="/api/v1/tools", tags=["tools"])
app.include_router(admin.router, prefix="/api/v1/admin", tags=["admin"])
app.include_router(sse.router, prefix="/api/v1/sse", tags=["sse"])
# ... remaining routers
```

### 12.3 Transport Decision: Streamable HTTP

Following the MCP spec's recommendation and the SDK's direction:

- **Streamable HTTP** for production (supports stateful + stateless modes, JSON + SSE responses, session resumability)
- **SSE transport** is being superseded by the MCP spec — avoid
- **STDIO** for local development/testing only

```python
# Production
mcp.run(transport="streamable-http")

# Development
mcp.run(transport="streamable-http", host="0.0.0.0", port=8000)
```

### 12.4 CORS for MCP Browser Clients

```python
from starlette.middleware.cors import CORSMiddleware

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # Restrict in production
    allow_methods=["GET", "POST", "DELETE"],  # MCP streamable HTTP methods
    expose_headers=["Mcp-Session-Id"],  # Required for MCP session management
)
```

---

## 13. Frontend Migration Strategy

### 13.1 Current Frontend

The JS chat UI lives in `assets/js/chat.js` and communicates with WordPress REST endpoints:
- `POST /wp-json/mcp-ai/v1/chat`
- `GET /wp-json/mcp-ai/v1/chat/transcripts`
- SSE via `EventSource` to WordPress SSE handler
- Auth via `X-WP-Nonce` header
- Guest tokens via `X-WP-MCP-AI-Guest` header

### 13.2 Migration Approach

**Phase A (Quick Win):** Keep JS as-is, add an HTTP adapter layer:
```javascript
// Before:
const API_BASE = '/wp-json/mcp-ai/v1';

// After:
const API_BASE = '/api/v1';  // or 'https://api.nvoos.example.com/api/v1'
```

**Phase B (API Normalization):** Update auth headers:
```javascript
// Before:
headers: { 'X-WP-Nonce': wpApiSettings.nonce }

// After:
headers: { 'Authorization': `Bearer ${token}` }
```

**Phase C (SSE Migration):** Update SSE endpoint URL:
```javascript
// Before:
const eventSource = new EventSource('/wp-json/mcp-ai/v1/sse?session_id=xxx');

// After:
const eventSource = new EventSource('/api/v1/sse/chat/xxx');
```

**Phase D (Optional — long-term):** Extract to standalone React/Vue SPA in a separate repo. The API contract is identical so this can happen independently.

### 13.3 Serving the Frontend

```python
from fastapi.staticfiles import StaticFiles

# Serve built JS/CSS assets
app.mount("/static", StaticFiles(directory="frontend/dist"), name="static")

# Serve index.html for SPA routes
@app.get("/{full_path:path}")
async def serve_spa(full_path: str):
    if full_path.startswith("api/") or full_path.startswith("mcp"):
        raise HTTPException(404)
    return FileResponse("frontend/dist/index.html")
```

---

## 14. Project Structure & Tooling

### 14.1 Python Build Configuration

```toml
# pyproject.toml
[build-system]
requires = ["hatchling"]
build-backend = "hatchling.build"

[project]
name = "nvoos-fastapi"
version = "0.1.0"
description = "NV oOS — AI Assistant framework for FastAPI"
readme = "README.md"
requires-python = ">=3.11"
license = {text = "MIT"}
authors = [
    {name = "NV Digital Solutions", email = "developer@nvdigitalsolutions.com"},
]
dependencies = [
    "fastapi[standard]>=0.115,<1.0",
    "uvicorn[standard]>=0.30",
    "sqlalchemy[asyncio]>=2.0,<3.0",
    "asyncpg>=0.29",
    "alembic>=1.14",
    "pydantic>=2.8,<3.0",
    "pydantic-settings>=2.5",
    "httpx>=0.27",
    "redis[hiredis]>=5.0",
    "sse-starlette>=2.0",
    "python-jose[cryptography]>=3.3",
    "passlib[bcrypt]>=1.7",
    "python-multipart>=0.0.9",
    "mcp[cli]>=1.27,<2",
    "openai>=1.50",
    "anthropic>=0.30",
    "google-genai>=1.0",
    "huggingface-hub>=0.24",
    "tiktoken>=0.7",
    "celery[redis]>=5.4",
    "aioboto3>=13.0",
    "pyyaml>=6.0",
]
keywords = ["ai", "mcp", "fastapi", "llm", "agent"]

[project.optional-dependencies]
dev = [
    "pytest>=8.0",
    "pytest-asyncio>=0.23",
    "pytest-cov>=5.0",
    "pytest-mock>=3.14",
    "httpx-mock>=0.12",
    "respx>=0.21",
    "ruff>=0.6",
    "mypy>=1.11",
    "pre-commit>=3.8",
]
all = ["nvoos-fastapi[dev]"]

[project.scripts]
nvoos = "nvoos.cli:main"

[tool.ruff]
target-version = "py311"
line-length = 100
exclude = ["alembic/", ".venv/"]

[tool.ruff.lint]
select = ["E", "F", "I", "N", "W", "UP", "B", "C4", "SIM"]

[tool.mypy]
python_version = "3.11"
strict = true
warn_unused_ignores = true

[tool.pytest.ini_options]
asyncio_mode = "auto"
testpaths = ["tests"]
addopts = "-v --tb=short"
```

### 14.2 Docker Configuration

```yaml
# docker-compose.yml
services:
  api:
    build:
      context: .
      dockerfile: Dockerfile
    command: uvicorn nvoos.main:app --host 0.0.0.0 --port 8000 --reload
    ports:
      - "8000:8000"
    volumes:
      - ./src:/app/src
      - ./frontend:/app/frontend
    environment:
      - DATABASE_URL=postgresql+asyncpg://nvoos:nvoos@postgres:5432/nvoos
      - REDIS_URL=redis://redis:6379/0
      - NVOOS_OPENAI_API_KEY=${NVOOS_OPENAI_API_KEY:-}
      - NVOOS_ANTHROPIC_API_KEY=${NVOOS_ANTHROPIC_API_KEY:-}
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_started

  postgres:
    image: pgvector/pgvector:pg16
    environment:
      POSTGRES_DB: nvoos
      POSTGRES_USER: nvoos
      POSTGRES_PASSWORD: nvoos
    ports:
      - "5432:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U nvoos"]
      interval: 5s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data

  worker:
    build: .
    command: celery -A nvoos.infrastructure.queue.worker worker -l info -Q default,tools
    volumes:
      - ./src:/app/src
    environment:
      - DATABASE_URL=postgresql+asyncpg://nvoos:nvoos@postgres:5432/nvoos
      - REDIS_URL=redis://redis:6379/0
    depends_on:
      - postgres
      - redis

  scheduler:
    build: .
    command: celery -A nvoos.infrastructure.queue.worker beat -l info
    volumes:
      - ./src:/app/src
    environment:
      - DATABASE_URL=postgresql+asyncpg://nvoos:nvoos@postgres:5432/nvoos
      - REDIS_URL=redis://redis:6379/0
    depends_on:
      - postgres
      - redis

  minio:
    image: minio/minio:latest
    command: server /data --console-address ":9001"
    ports:
      - "9000:9000"
      - "9001:9001"
    environment:
      MINIO_ROOT_USER: minioadmin
      MINIO_ROOT_PASSWORD: minioadmin
    volumes:
      - minio_data:/data

volumes:
  postgres_data:
  redis_data:
  minio_data:
```

### 14.3 CI/CD (GitHub Actions)

```yaml
# .github/workflows/python-ci.yml
name: Python CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      matrix:
        python-version: ["3.11", "3.12"]

    services:
      postgres:
        image: pgvector/pgvector:pg16
        env:
          POSTGRES_DB: nvoos_test
          POSTGRES_USER: nvoos
          POSTGRES_PASSWORD: nvoos
        ports:
          - 5432:5432
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

      redis:
        image: redis:7-alpine
        ports:
          - 6379:6379

    steps:
      - uses: actions/checkout@v4

      - name: Install uv
        uses: astral-sh/setup-uv@v3

      - name: Set up Python
        run: uv python install ${{ matrix.python-version }}

      - name: Install dependencies
        run: uv sync --all-extras

      - name: Run ruff
        run: uv run ruff check .

      - name: Run mypy
        run: uv run mypy src/

      - name: Run tests
        run: uv run pytest --cov=src/nvoos --cov-report=xml
        env:
          DATABASE_URL: postgresql+asyncpg://nvoos:nvoos@localhost:5432/nvoos_test
          REDIS_URL: redis://localhost:6379/0

      - name: Upload coverage
        uses: codecov/codecov-action@v4
        with:
          file: ./coverage.xml
```

---

## 15. Implementation Phases & Timeline

### 15.1 Overall Timeline

| Phase | Name | Duration | Deliverables |
|---|---|---|---|
| 0 | Foundation | Week 1 | Project scaffold, Docker, CI, domain contracts, entities, errors |
| 1 | Core Infrastructure | Weeks 2-3 | All 9 adapters (SQLAlchemy, Redis, S3, Celery), FastAPI setup, auth |
| 2 | Tool System | Weeks 3-5 | AbstractTool, ToolRegistry, Phase 1 tools (26 utility), MCP SDK integration |
| 3 | Provider Clients | Weeks 5-7 | 3 core providers (OpenAI, Anthropic, Gemini) + 3 compat providers |
| 4 | Chat Orchestrator | Weeks 7-9 | Agentic loop, SSE streaming, chat memory, transcript storage, Phase 2 tools (15 content) |
| 5 | REST API | Weeks 9-11 | All 17 API route modules, remaining provider clients, Phase 3 tools (18 AI) |
| 6 | Polish & Coverage | Weeks 11-12 | Phase 4-5 tools, guardrails, prompt injection, cost calculator, full tests, docs |

**Total: 12 weeks** for a single senior engineer (faster with a pair).

### 15.2 Phase 0 — Foundation (Week 1)

| Day | Task | Details |
|---|---|---|
| 1 | Scaffold project | `uv init`, `pyproject.toml`, Docker files, CI workflow |
| 2 | Domain contracts | 9 Python `Protocol` classes |
| 3 | Domain entities | 10+ Pydantic v2 models |
| 4 | Domain errors | Typed exception hierarchy + `ErrorFactory` protocol |
| 5 | Domain events | Event classes + dispatch pattern |

**Week 1 Goal:** `src/nvoos/domain/` complete. All contracts, entities, and errors defined. Zero infrastructure dependencies — pure Python protocols and models.

### 15.3 Phase 1 — Core Infrastructure (Weeks 2-3)

| Week | Task | Details |
|---|---|---|
| 2 | SQLAlchemy adapters | `ContentStore`, `AuthProvider`, `SettingsStore` — PostgreSQL adapters |
| 2 | Alembic migrations | Initial schema, seed data |
| 3 | Redis + S3 + Celery adapters | `CacheStore`, `FileStore`, `QueueClient` |
| 3 | FastAPI setup + auth | App scaffold, middleware, JWT + API key auth |
| 3 | `EventDispatcher` + `ErrorFactory` | In-process event bus, HTTP error mapping |

**Week 3 Goal:** Docker Compose boots a working API. `POST /api/v1/auth/token` returns a JWT. All 9 adapters functional.

### 15.4 Phase 2 — Tool System (Weeks 3-5)

| Week | Task | Details |
|---|---|---|
| 3-4 | `AbstractTool` + `ToolRegistry` | Base class, registry with hooks, canonical envelope |
| 4 | Phase 1 tools (26 utility) | Data/format tools: `format_date`, `math_eval`, `parse_csv`, etc. |
| 4-5 | MCP SDK integration | FastMCP server, tool registration, mount in FastAPI |
| 5 | Tool tests | Unit tests for all 26 tools |

**Week 5 Goal:** All 26 utility tools registered in both FastAPI REST and MCP server. MCP Inspector successfully connects and lists tools.

### 15.5 Phase 3 — Provider Clients (Weeks 5-7)

| Week | Task | Details |
|---|---|---|
| 5 | `AIProviderClient` base + `ProviderRouter` | Abstract base, provider resolution logic |
| 5-6 | Core providers | OpenAI, Anthropic, Gemini — tested with mock HTTP |
| 6-7 | Compat providers | DeepSeek, OpenRouter, Ollama (OpenAI-compatible base) |
| 7 | Provider integration tests | End-to-end with real API keys (manual), mock tests in CI |

**Week 7 Goal:** `ProviderRouter.resolve("gpt-4o")` returns a working `OpenAIClient`. Chat completions work against real APIs.

### 15.6 Phase 4 — Chat Orchestrator (Weeks 7-9)

| Week | Task | Details |
|---|---|---|
| 7-8 | `ChatOrchestrator` | Agentic loop, TPM budget, context window validation |
| 8 | SSE streaming | `sse-starlette` handler, chunking, heartbeat |
| 8-9 | Chat memory + transcripts | Store/recall memory, transcript persistence |
| 9 | Phase 2 tools (15 content) | Content CRUD tools via ContentStore adapter |
| 9 | Orchestrator integration tests | Mock provider, verify tool execution loop |

**Week 9 Goal:** Full agentic loop functional. `POST /api/v1/chat` with tool-calling model correctly executes tools and returns results.

### 15.7 Phase 5 — REST API (Weeks 9-11)

| Week | Task | Details |
|---|---|---|
| 9-10 | Chat/assistant/tool routes | Full CRUD for all core resources |
| 10 | Threads, teams, approvals | Multi-agent + HITL routes |
| 10-11 | Workflow, voice, slash commands | Remaining route modules |
| 10-11 | Remaining providers (7) | Kimi, DigitalOcean, etc. |
| 11 | Phase 3 tools (18 AI) | Provider-dependent tools |

**Week 11 Goal:** All REST endpoints documented and functional. All 13 providers registered. ~60 tools operational.

### 15.8 Phase 6 — Polish & Coverage (Weeks 11-12)

| Week | Task | Details |
|---|---|---|
| 11-12 | Phase 4-5 tools | External integration + remaining tools (~90 remaining) |
| 12 | Guardrails + security | Layer I jailbreak detection, prompt injection blocking |
| 12 | Cost calculator | Per-model pricing, usage tracking |
| 12 | Full test coverage | Target: 80%+ line coverage |
| 12 | Documentation | README, API docs, architecture decision records |
| 12 | Performance benchmarking | Load test vs PHP implementation |

**Week 12 Goal:** Production-ready release candidate. All ~195 tools ported. Documentation complete. Test suite green at 80%+ coverage.

---

## 16. Risk Analysis & Mitigation

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **Tool behavior divergence** — Python tool produces different output than PHP | Medium | High | Shared test fixtures with identical inputs/outputs. Cross-reference PHP test expectations during port. |
| **Provider API drift** — Provider APIs change between PHP and Python client versions | Medium | Medium | Pin SDK versions. Monitor provider changelogs. Both PHP and Python clients call same REST API — minimal divergence risk. |
| **SSE streaming edge cases** — Connection handling differs between PHP/Apache and Python/ASGI | Medium | Medium | Standardize on `sse-starlette` which handles reconnection, heartbeats. Write integration tests with `httpx-sse`. |
| **MCP SDK v2 breaking changes** — v2 is in alpha; API may change before stable | Medium | Medium | Pin `mcp>=1.27,<2` initially. Track v2 changelog. Budget 1-2 days for v2 migration when stable. |
| **Performance regression** — Async Python slower than expected vs PHP/Guzzle | Low | Low | Async Python with connection pooling should match or exceed synchronous PHP. Benchmark early (Week 1) to catch surprises. |
| **Database migration complexity** — WordPress schema → PostgreSQL normalized schema | Medium | Medium | Alembic handles schema. Data migration is Phase 0 prerequisite — one-time script. |
| **Frontend compatibility** — JS chat UI breaks on new API | Low | Low | API contract preserved. Only header format changes. Test with existing JS against FastAPI test client. |
| **Scope creep** — Pro tools (~795) creep into base port scope | Medium | High | Strict scope gate: only base (~195) tools. Pro tools are a separate follow-up project. |
| **Skills/knowledge gap** — Python team unfamiliar with PHP codebase | Medium | Medium | PHP extraction (`lib/core/`) serves as specification. Tool inputs/outputs are documented. Pairing sessions for complex tools. |

### 16.1 Go/No-Go Criteria

| Milestone | Go Criteria |
|---|---|
| End of Week 1 | All 9 domain contracts defined and agreed upon |
| End of Week 3 | Docker Compose boots; `POST /api/v1/auth/token` works |
| End of Week 5 | MCP Inspector connects and lists 26+ tools |
| End of Week 7 | ProviderRouter resolves and calls 6+ providers |
| End of Week 9 | Agentic loop executes tools end-to-end |
| End of Week 12 | All ~195 tools ported, 80%+ test coverage, docs complete |

---

## 17. Testing Strategy

### 17.1 Test Pyramid

```
        ┌─────────┐
        │   E2E   │   ~5%   — Full chat flow, SSE streaming, multi-turn
       ┌┴─────────┴┐
       │    API    │  ~20%   — FastAPI TestClient route tests
      ┌┴───────────┴┐
      │   Unit      │  ~75%   — Tool tests, provider mock tests, orchestrator tests
      └─────────────┘
```

### 17.2 Test Categories

#### Tool Unit Tests (75% of tests)

```python
import pytest
from nvoos.tools.content.create_post import CreatePostTool
from nvoos.tools.base import ToolResult

@pytest.mark.asyncio
async def test_create_post_success(mock_content_store, mock_auth, mock_errors, mock_events):
    """Create post with valid arguments."""
    tool = CreatePostTool(mock_content_store, mock_auth, mock_errors, mock_events)
    mock_auth.get_current_user.return_value = UserInfo(id="u1", username="test")
    mock_auth.authorize.return_value = True
    mock_content_store.create.return_value = ContentItem(id="p1", title="Test", url="/p1")

    result = await tool.execute({"title": "Test", "content": "Hello"}, {})

    assert result.success
    assert result.data["post_id"] == "p1"
    mock_content_store.create.assert_called_once()

@pytest.mark.asyncio
async def test_create_post_unauthorized(mock_content_store, mock_auth, mock_errors):
    """Create post without capability returns error."""
    tool = CreatePostTool(mock_content_store, mock_auth, mock_errors, mock_events)
    mock_auth.get_current_user.return_value = UserInfo(id="u1", username="test")
    mock_auth.authorize.return_value = False
    mock_errors.access_denied.return_value = "Access denied"

    result = await tool.execute({"title": "Test"}, {})

    assert not result.success
    assert "Access denied" in result.error
    mock_content_store.create.assert_not_called()

@pytest.mark.asyncio
async def test_create_post_invalid_input(mock_content_store, mock_auth, mock_errors, mock_events):
    """Missing required field returns error."""
    tool = CreatePostTool(mock_content_store, mock_auth, mock_errors, mock_events)
    mock_auth.get_current_user.return_value = UserInfo(id="u1", username="test")
    mock_auth.authorize.return_value = True

    result = await tool.execute({}, {})  # Missing "title"

    assert not result.success
```

#### Provider Mock Tests

```python
import respx
import httpx

@pytest.mark.asyncio
async def test_openai_chat_completion(mock_openai_settings):
    """OpenAI provider returns valid chat completion."""
    mock_route = respx.post("https://api.openai.com/v1/chat/completions").mock(
        return_value=httpx.Response(200, json={
            "choices": [{"message": {"content": "Hello!", "tool_calls": []}, "finish_reason": "stop"}],
            "usage": {"prompt_tokens": 10, "completion_tokens": 5, "total_tokens": 15},
            "model": "gpt-4o",
        })
    )

    client = OpenAIClient(mock_openai_settings, httpx.AsyncClient(), mock_token_counter)
    result = await client.chat_completion(
        messages=[{"role": "user", "content": "Hi"}],
        model="gpt-4o",
    )

    assert result.content == "Hello!"
    assert result.usage["total_tokens"] == 15
    assert mock_route.called
```

#### API Integration Tests

```python
from fastapi.testclient import TestClient
from nvoos.main import app

client = TestClient(app)

def test_chat_endpoint_requires_auth():
    """Chat endpoint returns 401 without auth."""
    response = client.post("/api/v1/chat", json={
        "messages": [{"role": "user", "content": "Hello"}],
    })
    assert response.status_code == 401

def test_chat_endpoint_with_token(auth_headers):
    """Chat endpoint works with valid JWT."""
    response = client.post(
        "/api/v1/chat",
        json={"messages": [{"role": "user", "content": "Hello"}]},
        headers=auth_headers,
    )
    assert response.status_code == 200
    assert "content" in response.json()
```

#### SSE Streaming Tests

```python
import httpx
from httpx_sse import connect_sse

@pytest.mark.asyncio
async def test_chat_stream_endpoint(auth_headers):
    """SSE stream returns valid event frames."""
    async with httpx.AsyncClient(app=app, base_url="http://test") as client:
        async with connect_sse(
            client, "POST", "/api/v1/chat/stream",
            json={"messages": [{"role": "user", "content": "Hello"}]},
            headers=auth_headers,
        ) as event_source:
            events = []
            async for sse in event_source.aiter_sse():
                events.append(sse)
                if sse.event == "done":
                    break

            assert len(events) > 0
            assert any(e.event == "message" for e in events)
            assert events[-1].event == "done"
```

### 17.3 Test Coverage Targets

| Module | Target | Notes |
|---|---|---|
| `nvoos.tools.*` | 90%+ | Every tool has valid/invalid input tests |
| `nvoos.domain.*` | 95%+ | Contracts and entities are simple |
| `nvoos.application.*` | 85%+ | Orchestrator is the most complex |
| `nvoos.infrastructure.providers.*` | 80%+ | Mock HTTP for all providers |
| `nvoos.api.*` | 85%+ | Route-level integration tests |
| `nvoos.infrastructure.storage.*` | 75%+ | Requires test DB |
| **Overall** | **80%+** | Minimum for production release |

---

## 18. Key Decisions Record

| # | Decision | Options Considered | Choice | Rationale |
|---|---|---|---|---|
| 1 | Web framework | FastAPI vs Django REST vs Litestar | **FastAPI** | Async-native, Pydantic v2 integration, auto OpenAPI docs, largest ecosystem |
| 2 | MCP implementation | Official SDK vs custom | **Official `mcp` SDK** | Spec compliance, maintained by Anthropic, Streamable HTTP transport |
| 3 | SSE library | `sse-starlette` vs raw Starlette | **`sse-starlette`** | Battle-tested, handles reconnection/heartbeats, active maintenance |
| 4 | ORM | SQLAlchemy 2.0 vs Django ORM vs Tortoise | **SQLAlchemy 2.0** | Mature, async-native, Alembic migrations, PostgreSQL JSONB support |
| 5 | Cache | Redis vs Memcached | **Redis** | TTL support, pub/sub for events, also used as Celery broker |
| 6 | Background jobs | Celery vs ARQ vs RQ | **Celery** | Industry standard, scheduling (Beat), retries, monitoring (Flower) |
| 7 | Auth | `python-jose` vs `PyJWT` vs `authlib` | **`python-jose`** | JWT + JWS + JWE + JWK, widely used in FastAPI tutorials |
| 8 | Validation | Pydantic v2 vs dataclasses + validators | **Pydantic v2** | Integrated with FastAPI, JSON Schema generation, fast (Rust core) |
| 9 | Python version | 3.10 vs 3.11 vs 3.12 | **3.11+** | Required by SQLAlchemy 2.0 async (greenlet), task groups, faster |
| 10 | Architecture | Hexagonal vs Clean vs Layered | **Hexagonal (Ports & Adapters)** | Same as existing PHP extraction; proven for this exact problem |
| 11 | Tool execution model | Sync vs async | **Async** | All providers use HTTP; `asyncio` is natural fit |
| 12 | MCP transport | Streamable HTTP vs SSE vs STDIO | **Streamable HTTP** | MCP spec recommends for production; SSE being superseded |
| 13 | Container orchestration | Docker Compose vs Kubernetes | **Docker Compose** (dev), **Kubernetes** (production) | Compose for local dev; K8s manifests provided for production |
| 14 | Frontend | Reuse JS vs React rewrite | **Reuse JS** (Phase A), **Extract later** (Phase D) | Minimizes scope; API contract is identical |

---

## 19. Open Questions & Next Steps

### 19.1 Questions for Engineering Leadership

1. **License:** The PHP `lib/core` is MIT. Should the Python port also be MIT, or GPL-3.0 to match the WordPress plugin?

2. **Pro tools scope:** This proposal covers only the base ~195 tools. Should Pro tools (~795) be included in a Phase 2 follow-up, or is the base port the complete deliverable?

3. **Database choice:** PostgreSQL is the primary choice. Should we also support SQLite for local development/single-user deployments?

4. **Deployment target:** Is the primary target self-hosted (Docker Compose), cloud (Kubernetes), or serverless (AWS Lambda / GCP Cloud Run)?

5. **MCP SDK v2 timing:** v2 targets beta June 30, 2026, stable July 27, 2026. Should we wait for v2 stable before Phase 2 (MCP integration), or build against v1.27+ and migrate later?

6. **Frontend extraction:** Should the JS chat UI be extracted to a standalone repo as part of this project, or is that a separate initiative?

7. **Team allocation:** Who is the primary engineer? Is this a solo effort or a pair/team?

### 19.2 Immediate Next Steps

1. **Review and approve** this proposal with engineering leadership
2. **Resolve open questions** (license, scope, deployment target)
3. **Create GitHub repository** `nvdigitalsolutions/nvoos-fastapi`
4. **Begin Phase 0** — project scaffold, domain contracts, entities
5. **Set up CI/CD** — GitHub Actions with PostgreSQL + Redis services
6. **Schedule checkpoint reviews** at the end of each phase

---

## 20. Appendices

### 20.1 Glossary

| Term | Definition |
|---|---|
| **MCP** | Model Context Protocol — JSON-RPC 2.0 protocol for AI tool interaction |
| **FastMCP** | High-level Python API in the `mcp` SDK for rapid MCP server creation |
| **Hexagonal Architecture** | Ports & Adapters pattern — domain logic at center, infrastructure as pluggable adapters |
| **Protocol** | Python's structural subtyping — like an interface but duck-typed |
| **Agentic Loop** | The iterative process: send messages to LLM → receive tool calls → execute tools → feed results back → repeat |
| **SSE** | Server-Sent Events — HTTP streaming protocol for real-time updates |
| **Streamable HTTP** | MCP transport using HTTP POST with optional SSE responses |
| **TPM** | Tokens Per Minute — rate limit budget for AI provider API calls |
| **HITL** | Human-in-the-Loop — approval workflow requiring human confirmation |
| **ACP** | Agent Client Protocol — JSON-RPC 2.0 specification for agent-to-agent communication |
| **CCT** | Custom Content Type — JetEngine's database table abstraction (WordPress-specific) |
| **CPT** | Custom Post Type — WordPress content type registration |

### 20.2 PHP → Python Library Mapping

| PHP Library | Python Equivalent | Notes |
|---|---|---|
| `symfony/http-client` / Guzzle | `httpx` | Async HTTP client |
| `symfony/cache` | `redis-py` (async) | Cache abstraction |
| `symfony/validator` | Pydantic v2 | Data validation |
| `symfony/filesystem` | `pathlib` + `shutil` | Filesystem operations |
| `symfony/process` | `subprocess` + `asyncio.create_subprocess_exec` | Process execution |
| `league/oauth2-client` | `httpx-auth` / custom | OAuth2 client |
| `psr/event-dispatcher` | Custom `EventDispatcher` protocol | Event bus |
| `rahul900day/tiktoken-php` | `tiktoken` | Token counting |
| `guzzlehttp/psr7` | Built-in `email` + `multipart` | HTTP message abstractions |
| `nyholm/psr7` | Starlette `Request`/`Response` | PSR-7 implementation |
| WordPress `WP_Error` | Python exceptions | Typed error hierarchy |
| WordPress `get_option`/`update_option` | `SettingsStore` → SQLAlchemy | Key-value settings |
| WordPress `WP_Query` | SQLAlchemy `select()` with filters | Content queries |
| WordPress `wp_remote_get`/`wp_remote_post` | `httpx.AsyncClient.get()`/`.post()` | HTTP requests |
| Action Scheduler | Celery tasks | Background job scheduling |

### 20.3 Existing Artifacts to Reference

| Artifact | Path | Use During Port |
|---|---|---|
| Cross-platform extraction architecture | `docs/project/proposals/cross-platform-extraction-architecture.md` | Architecture reference |
| Cross-platform gap analysis | `docs/project/proposals/cross-platform-extraction-gap-analysis.md` | Current state of extraction |
| Cross-platform context file | `.context/cross-platform-extraction.md` | Hexagonal rules for agents |
| Domain contracts (PHP) | `lib/core/src/Domain/Contract/` | Exact interface signatures to port |
| Framework-agnostic tools (PHP) | `lib/core/src/Tool/` | Tool behavior specifications |
| REST API README | `includes/rest/README.md` | Full API surface to replicate |
| Main architecture doc | `CLAUDE.md` | Architecture patterns, hooks, orchestration |
| MCP SDK (Python) | `https://github.com/modelcontextprotocol/python-sdk` | FastMCP API reference |
| FastAPI docs | `https://fastapi.tiangolo.com/` | Framework reference |
| Existing REST API | `includes/rest/class-wp-mcp-ai-rest-*.php` | Request/response shapes |
| Existing tool tests | `tests/test-*.php` | PHP test expectations → Python test specs |

### 20.4 Dependency Graph (Startup Order)

```
1. config.py         — pydantic-settings (reads env vars)
2. domain/contracts/ — Python Protocols (zero deps)
3. domain/entities/  — Pydantic models (zero deps)
4. domain/errors.py  — Exception classes (zero deps)
5. domain/events.py  — Event dataclasses (zero deps)
6. infrastructure/   — Adapters (depend on contracts + entities)
7. tools/base.py     — AbstractTool (depends on contracts)
8. application/      — Services (depend on contracts + infrastructure)
9. api/deps.py       — FastAPI DI wiring (depends on application)
10. api/*.py         — Route modules (depend on deps + schemas)
11. main.py          — FastAPI app (mounts everything)
12. mcp_server.py    — FastMCP integration (depends on tool registry)
```

---

**Document History:**

| Version | Date | Author | Changes |
|---|---|---|---|
| 1.0.0 | 2026-06-25 | AI Agent (Zed) | Initial comprehensive plan |

---

*End of document.*
