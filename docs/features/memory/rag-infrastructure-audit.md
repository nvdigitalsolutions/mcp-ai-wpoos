# RAG Infrastructure Audit

**Version:** 1.1.29  
**Date:** June 11, 2026  
**Status:** Complete

## Overview

This document maps the NV oOS plugin's Retrieval-Augmented Generation (RAG) capabilities
across four industry-standard patterns. Each pattern is evaluated against the actual
codebase, with file references, tool names, and architectural notes.

---

## 1. Traditional RAG — Fast, Simple (FAQ-style)

> **Status:** ✅ **Fully Supported**

Traditional RAG retrieves relevant documents or memories, injects them into the prompt,
and returns an answer — no planning, no multi-step reasoning. Ideal for FAQ bots,
knowledge-base Q&A, and simple lookup tasks.

### Retrieval Pipeline

```
User query
    ├── semantic_content_search    (vector similarity against WP content)
    ├── search_content             (keyword WP_Query with relevance scoring)
    ├── retrieve_agent_memory      (transient store, tags, importance, TTL)
    ├── recall_memory              (hierarchical MemPalace-inspired recall)
    └── wake_up_context            (top-N memories prepended to system prompt)
                ↓
         Prompt assembly → LLM → Answer
```

### Tool Reference

| Tool | Slug | What it retrieves | File |
|------|------|-------------------|------|
| Semantic Content Search | `semantic_content_search` | WP posts/pages by vector similarity | `includes/tools/class-wp-mcp-ai-tool-semantic-content-search.php` |
| Content Search | `search_content` | WP content by keyword + taxonomy/meta filters | `includes/tools/class-wp-mcp-ai-tool-search-content.php` |
| Retrieve Agent Memory | `retrieve_agent_memory` | Stored memories with tags, importance, TTL | `includes/tools/class-wp-mcp-ai-tool-retrieve-agent-memory.php` |
| Recall Memory | `recall_memory` | Hierarchical memory recall (wing/room scoped) | `includes/tools/class-wp-mcp-ai-tool-recall-memory.php` |
| Wake-Up Context | `wake_up_context` | Top-N memories formatted as system-prompt block | `includes/tools/class-wp-mcp-ai-tool-wake-up-context.php` |
| Web Search | `web_search` | Public web results via 5 providers (DuckDuckGo, Brave, Tavily, Exa, Perplexity) | `includes/tools/class-wp-mcp-ai-tool-web-search.php` |

### Chat Memory REST API

The `mcp-ai/v1/chat-memory` namespace exposes memory CRUD for the chat client's
Memory Drawer:

| Method | Route | Purpose |
|--------|-------|---------|
| `GET` | `/chat-memory/preferences` | Read memory preferences |
| `POST` | `/chat-memory/preferences` | Update memory preferences |
| `POST` | `/chat-memory/wake-up` | Load memories at session boot |
| `GET` | `/chat-memory/recall` | Search stored memories |
| `POST` | `/chat-memory/store` | Store a new memory (with optional LLM summarization) |
| `PUT` | `/chat-memory/update` | Update existing memory |
| `DELETE` | `/chat-memory/delete` | Remove a memory |
| `GET` | `/chat-memory/audit` | List memory changes |
| `GET` | `/chat-memory/session-replay` | Replay a transcript session |

**Controller:** `includes/rest/class-wp-mcp-ai-rest-chat-memory-controller.php`

### Storage Architecture

Memories are stored as WordPress transients with JSON metadata:
- **Backing store:** `wp_options` (autoloaded where appropriate)
- **Optional durable mirror:** JetEngine CCT `ai_agent_memories`
- **Optional graph mirror:** Graphify `memory:*` nodes with weighted retrieval
- **TTL:** Configurable per-memory (default 30 days)
- **Importance levels:** `low`, `medium`, `high`, `critical`
- **Tags:** Free-form string tags for filtering

### Key Features

- **LLM summarization:** Optional on-store summarization via GPT-4o-mini
  (reduces token usage for long-form auto-captured memories)
- **Context compression:** TTL-aware semantic chunking (150–1000 token chunks,
  10–20% overlap)
- **Multi-factor scoring:** recency (30%) + frequency (20%) + importance (40%)
  + TTL (10%) with exponential decay
- **Batch operations:** Bulk update, delete, export, import, tag management
  via `batch_manage_memory` tool

---

## 2. Agentic RAG — Planning Before Retrieval (Research)

> **Status:** ✅ **Fully Supported**

Agentic RAG goes beyond simple retrieval: the agent plans *what* to retrieve, decomposes
complex questions into sub-queries, executes multi-step tool chains, and synthesizes
results. Used for deep research, competitive analysis, compliance audits, and
multi-source reporting.

### Research Pipeline (deep_research example)

```
Topic → Plan phases
    ├── Phase 1: recall_prior_research    (check agent memory for past findings)
    ├── Phase 2: web_search               (5 providers: DuckDuckGo/Brave/Tavily/Exa/Perplexity)
    ├── Phase 3: gather_site_content      (semantic_content_search for authoritative site content)
    ├── Phase 4: maybe_rerank_results     (Jina/Cohere relevance reranking if LibreChat active)
    └── Phase 5: Synthesize → Report
```

### Orchestration Tools

| Tool | Purpose | File |
|------|---------|------|
| `deep_research` | Multi-phase research with prior recall | `includes/tools/class-wp-mcp-ai-tool-deep-research.php` |
| `generate_research_report` | Orchestrated research report generation | `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-generate-research-report.php` |
| `research_blog_post` | Blog post research with media strategy | `addons/pro/includes/tools/research/class-wp-mcp-ai-tool-research-blog-post.php` |
| `research_page` | Page research with type-specific focus | `addons/pro/includes/tools/research/class-wp-mcp-ai-tool-research-page.php` |
| `research_company` | Company research (CRM toolkit) | `addons/pro/includes/tools/crm/class-wp-mcp-ai-tool-research-company.php` |
| `research_eca` | ECA (extra-curricular activity) research | `addons/pro/includes/tools/eca-management/class-wp-mcp-ai-tool-research-eca.php` |
| `research_quiz_topic` | Quiz topic research with question generation | `addons/pro/includes/tools/quiz-management/class-wp-mcp-ai-tool-research-quiz-topic.php` |

### Memory Lifecycle Tools

| Tool | Purpose |
|------|---------|
| `manage_context_lifecycle` | TTL refresh, on-demand compression, context merging, health analysis, unused pruning |
| `memory_audit_trail` | Version history and compliance audit trail |
| `batch_manage_memory` | Bulk update, delete, export, import, tag management |

### Web Search Reranker

When the LibreChat addon is active and `enable_web_search` is on, all web search
results pass through a relevance reranker before being returned:

- **Jina AI Reranker:** `jina-reranker-v2-base-multilingual` — free tier, no API key required
- **Cohere Rerank:** `rerank-english-v3.0` — requires API key (uses NV oOS core key slot or `wp_mcp_ai_cohere_rerank_api_key` filter)
- **Integration point:** `maybe_rerank_results()` in `class-wp-mcp-ai-tool-web-search.php`
- **Scoring:** Reorders results by query-document relevance, adds `reranked: true` flag to result

---

## 3. Graph RAG — Relationships (Healthcare, CRM, Knowledge Graphs)

> **Status:** ✅ **Fully Supported via Graphify Addon**

Graph RAG retrieves information by traversing a knowledge graph of entities and
relationships. Instead of treating documents as flat text, it follows edges
(links, taxonomies, authorship, semantic topics) to surface connected context.
Ideal for healthcare records, CRM pipelines, legal documents, and any domain
where *relationships* between entities are as important as the entities themselves.

### Graphify Knowledge Graph

The Graphify addon (`addons/graphify/`) builds a knowledge graph from WordPress
content and exposes it through 8 AI tools.

**Graph construction:** `graphify_build_graph` — auto-discovers posts, terms,
users, media; extracts structural links (internal links, taxonomies, authorship)
and AI-powered semantic entities/topics.

### Graph RAG Retrieval Tool

**`graphify_retrieve_context`** — the flagship RAG tool:

```
Question → 
    ├── Full-text node search (MySQL LIKE on labels)
    ├── Multi-hop BFS/DFS graph traversal (1–3 hops)
    ├── Optional: Vector similarity (cosine over float32 embeddings)
    └── Returns: nodes[], edges[], context_text (pre-formatted for prompt injection)
```

**File:** `addons/graphify/includes/tools/class-nvoos-graphify-tool-retrieve-context.php`

### Graph Exploration Tools

| Tool | Purpose |
|------|---------|
| `graphify_query_graph` | BFS/DFS traversal from seed nodes, depth 1–3 |
| `graphify_get_node` | Full node details including degree, community, neighbors |
| `graphify_get_neighbors` | Direct connections with relation-type filtering |
| `graphify_get_community` | Modularity-based topic clusters |
| `graphify_god_nodes` | High-degree content pillars (hub pages) |
| `graphify_content_gaps` | Orphan nodes, thin communities, hubless clusters |
| `graphify_graph_stats` | Aggregate statistics: nodes, edges, communities, confidence |
| `graphify_resolve_external` | Wikidata QID / schema.org URL resolution + auto-ingest |

### Graph Memory Bridge

The memory bridge (`NV_oOS_Graphify_Memory_Bridge`) powers the `wake_up_context`
tool's `mode=graph` path. It retrieves memories using a weighted multi-signal
approach:

| Signal | Weight | Description |
|--------|--------|-------------|
| Room anchor | 0.6 | Memories in the specified room via `MEMBER_OF` edges |
| Wing anchor | 0.4 | Memories in the specified wing via `MEMBER_OF` edges |
| Keyword match | 0.5 | MySQL `LIKE` on memory node labels |
| Agent anchor | 0.1 | Memories owned by the agent via `OBSERVED_BY` edges |
| Vector cosine | 1.0× | Cosine similarity when embeddings are enabled |

**File:** `addons/graphify/includes/class-nvoos-graphify-memory-bridge.php`

### Embeddings Subsystem

- **Storage:** Custom MySQL table `nvoos_graph_node_embeddings`
- **Format:** float32-packed BLOB, variable dimensions
- **Models:** OpenAI `text-embedding-3-small` (default), `text-embedding-3-large`, `text-embedding-ada-002`
- **Search:** Pure-PHP cosine similarity (no pgvector, no Pinecone, no Weaviate)
- **Threshold:** 0.5 minimum cosine similarity

**File:** `addons/graphify/includes/class-nvoos-graphify-embeddings.php`

---

## 4. Vectorless RAG — BM25 Keyword Matching

> **Status:** ✅ **Fully Supported**

Vectorless RAG uses keyword-based retrieval (BM25, TF-IDF) instead of or alongside
vector embeddings. It's faster, requires no embedding API calls, and excels at
exact-match queries (names, codes, IDs, dates). Ideal for CRM lead search,
healthcare record lookup, and policy document retrieval.

### BM25 Implementation

The plugin ships a **full Okapi BM25 scorer** in two shared traits:

- **Pro:** `addons/pro/includes/traits/trait-wp-mcp-ai-relevance-search.php`
  (`WP_MCP_AI_CRM_Relevance_Search`)
- **Base:** `includes/traits/trait-wp-mcp-ai-relevance-search.php`
  (`WP_MCP_AI_Relevance_Search`)

### BM25 Formula

```
score = Σ IDF(qᵢ) × (tf × (k₁ + 1)) / (tf + k₁ × (1 − b + b × dl/avgdl))

k₁ = 1.5   (term frequency saturation)
b  = 0.75  (document length normalization)
```

### Key Methods

| Method | Purpose |
|--------|---------|
| `tokenize_query()` | Lowercase, split on whitespace/punctuation, remove 60+ English stop words |
| `compute_corpus_stats()` | Computes N, avgdl, per-token IDF, per-document lengths across all candidates |
| `compute_bm25_score()` | Full BM25 single-document scoring against IDF map + avgdl |
| `rank_by_bm25()` | Sorts entire candidate set by BM25 score, attaches `relevance_score` to each record |
| `compute_relevance_score()` | Simpler TF-IDF proxy (faster, for <5K records) |

### Algorithm Selection

Each consumer tool accepts an `algorithm` parameter:
- **`tfidf`** (default): Fast, uses token-length IDF proxy. Recommended for <5K records.
- **`bm25`**: Industry-standard with TF saturation + document length normalization. Recommended for long-form content or >1K records.

### Consumer Tools (8 total)

| Tool | Toolkit | Default Field Weights |
|------|---------|----------------------|
| `crm_email_search_leads` | CRM | name:3.0, company:2.0, email:1.5 |
| `crm_email_search_correspondence` | CRM | name:3.0, company:2.0, email:1.5 |
| `crm_email_search_accounting` | CRM | name:3.0, company:2.0, email:1.5 |
| `search_medical_records` | Healthcare | Domain-specific overrides |
| `search_policies` | Healthcare | Domain-specific overrides |
| `search_prescriptions` | Healthcare | Domain-specific overrides |
| `search_content` | Core | title + content fields |
| `get_recent_posts` | Core | title + content fields |

Each consumer overrides `$default_field_weights` and `extract_searchable_text()`
for its domain. All 8 tools support `orderby=relevance` with configurable
`order=ASC|DESC` and optional `algorithm=bm25|tfidf`.

---

## Cross-Cutting Concerns

### Hybrid Retrieval Matrix

Several subsystems combine multiple retrieval modes:

| Subsystem | Keyword | Vector | Graph | File |
|-----------|---------|--------|-------|------|
| Memory Bridge (graph mode) | ✅ LIKE | ✅ Cosine | ✅ BFS | `addons/graphify/includes/class-nvoos-graphify-memory-bridge.php` |
| Graphify Retrieve Context | ✅ LIKE | ✅ Cosine (optional) | ✅ BFS/DFS | `addons/graphify/includes/tools/class-nvoos-graphify-tool-retrieve-context.php` |
| Wake-Up Context (auto mode) | ✅ Transient | ✅ Cosine | ✅ Graph (if Graphify active) | `includes/tools/class-wp-mcp-ai-tool-wake-up-context.php` |
| Deep Research | ✅ Web search | ✅ Semantic | — | `includes/tools/class-wp-mcp-ai-tool-deep-research.php` |
| Web Search + Reranker | ✅ Provider APIs | — | — | `includes/tools/class-wp-mcp-ai-tool-web-search.php` |

### Reranking

Post-retrieval reranking is available via:

1. **Multi-factor scoring** (all memory tools): recency + frequency + importance + TTL
2. **Jina/Cohere reranker** (web search, LibreChat): neural relevance reranking of raw search results
3. **BM25 reranking** (CRM/healthcare search tools): re-scores WP_Query results by BM25/TF-IDF

### Health & Monitoring

The Orchestration Dashboard provides:
- Memory Health Score (0–100)
- Active Contexts Count
- Average Age & Access Patterns
- Expiring Soon Warning
- RAG Architecture Feature Status

**File:** `includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php`

---

## Summary Matrix

| Pattern | Status | Key Tools | Requires |
|---------|--------|-----------|----------|
| **Traditional RAG** | ✅ Full | `semantic_content_search`, `search_content`, `retrieve_agent_memory`, `recall_memory`, `wake_up_context` | Base plugin |
| **Agentic RAG** | ✅ Full | `deep_research`, `generate_research_report`, `manage_context_lifecycle`, `batch_manage_memory` | Base + Pro (orchestration tools) |
| **Graph RAG** | ✅ Full | `graphify_retrieve_context`, `graphify_query_graph`, memory bridge (weighted graph+keyword+vector) | Graphify addon |
| **Vectorless RAG** | ✅ Full | BM25/TF-IDF trait shared by 8 tools across CRM, Healthcare, Core | Base (core tools) + Pro (CRM/healthcare) |

### What's NOT Needed

- **No external vector DB:** Embeddings stored in custom MySQL table, cosine similarity in pure PHP
- **No Neo4j:** Graphify builds and traverses the knowledge graph in MySQL
- **No LangChain/LlamaIndex dependency:** All retrieval logic is PHP-native
- **No Node.js server:** SPA bundles talk to WordPress REST endpoints directly

---

## Related Documentation

- [RAG-Enhanced Memory Management](RAG-ENHANCED-MEMORY-MANAGEMENT.md) — Memory lifecycle, compression, scoring
- [Agent Memory Complete Guide](AGENT-MEMORY-COMPLETE-GUIDE.md) — Full memory system reference
- [RRF Fusion](rrf-fusion.md) — Reciprocal Rank Fusion for multi-source retrieval
- [Transcript Mining](transcript-mining.md) — Extracting memories from chat transcripts

---

**Document Version:** 1.0  
**Last Updated:** June 11, 2026  
**Maintained by:** NV Digital Solutions
