# Agent Memory Management - Complete Guide

**Version:** 1.1.0  
**Date:** February 18, 2026  
**Status:** Production Ready

## Table of Contents

1. [Overview](#overview)
2. [Complete CRUD Operations](#complete-crud-operations)
3. [Batch Operations](#batch-operations)
4. [Audit Trail & Versioning](#audit-trail--versioning)
5. [Industry Best Practices](#industry-best-practices)
6. [API Reference](#api-reference)
7. [Examples](#examples)
8. [Best Practices](#best-practices)

---

## Overview

The Agent Memory Management system now provides enterprise-grade capabilities for managing AI agent memory/context with complete CRUD operations, batch processing, versioning, and audit trails.

### Key Capabilities

- **Full CRUD**: Create, Read, Update, Delete individual memories
- **Batch Operations**: Bulk operations on multiple memories
- **Versioning**: Track all changes with version history
- **Audit Trail**: Complete compliance-ready change tracking
- **Export/Import**: Backup and restore memory data
- **Tag Management**: Organize memories with tags
- **Health Monitoring**: Track memory usage and patterns

---

## Complete CRUD Operations

### Create (Store Memory)

**Tool:** `store_agent_context`

```json
{
  "agent_id": 123,
  "context_type": "learning",
  "context_data": {
    "title": "Machine Learning Best Practices",
    "content": "Key insights about ML optimization...",
    "importance": "high",
    "tags": ["ml", "optimization"],
    "metadata": {
      "source": "research",
      "confidence": 0.95
    }
  },
  "wing": "client-acme",
  "room": "training-pipeline",
  "verbatim": false,
  "ttl": 2592000
}
```

#### Hierarchical scoping (wings & rooms)

Inspired by [MemPalace](https://github.com/MemPalace/mempalace), every stored memory can be tagged with two optional hierarchical scope fields:

- **`wing`** — high-level scope, typically a project, person, or domain (`"client-acme"`, `"personal"`, `"team-alpha"`).
- **`room`** — sub-scope inside a wing, typically a topic cluster (`"auth-flows"`, `"billing"`, `"onboarding"`).

Drawers (the original content of each record) live unchanged inside `context_data.content`. Wings and rooms are stored on the record and mirrored in the agent index so retrieval can scope candidates **before** semantic ranking runs, dramatically improving recall on shared memory pools.

When `wing` and/or `room` are omitted, the memory is unscoped and visible to all retrievals for the agent — the previous default behaviour.

#### Verbatim-storage discipline

Set `verbatim: true` to declare that the supplied `content` must be stored exactly as provided. The plugin then:

1. Skips the `wp_mcp_ai_memory_pre_store_transform` filter so summarisers, redactors, or paraphrasers cannot alter the text.
2. Persists the `verbatim` flag on the record so downstream consumers can honour the same contract.

Use this for raw quotes, decisions, transcripts, or any text whose phrasing matters. The recommended pattern is **store raw, summarise on read**: write verbatim, then derive summaries at retrieval time when context budgets demand it.

#### `wp_mcp_ai_memory_pre_store_transform` filter

Plugins that want to transform context before it is persisted can hook this filter:

```php
add_filter(
    'wp_mcp_ai_memory_pre_store_transform',
    function ( $context_data, $verbatim, $context_type, $agent_id, $arguments, $context ) {
        if ( $verbatim ) {
            return $context_data; // Verbatim contract — must not modify.
        }
        // Example: append a content hash to metadata.
        $context_data['metadata']['content_sha1'] = sha1( $context_data['content'] );
        return $context_data;
    },
    10,
    6
);
```

The verbatim contract is enforced unconditionally by the tool itself: even if a poorly-behaved listener returns modified data, the tool will discard it whenever `verbatim === true`.

### Read (Retrieve Memory)

**Tool:** `retrieve_agent_memory`

```json
{
  "agent_id": 123,
  "query": "machine learning",
  "filters": {
    "importance": ["high", "critical"],
    "context_types": ["learning", "insight"],
    "wing": "client-acme",
    "room": "training-pipeline"
  },
  "limit": 10
}
```

The optional `wing` and `room` filters are applied **before** semantic ranking, so they constrain the candidate pool rather than just re-ordering it. Combine them with `query` for the MemPalace-style "search this project, this topic, semantically" pattern.

#### Hybrid retrieval scoring

When semantic search runs through `WP_MCP_AI_Vector_Context_Service` (e.g. via `semantic_context_search`), each candidate's cosine similarity is layered with three optional, additive boosters:

| Booster        | Default weight | Filter (weight)                                     | Filter (final value)                          |
|----------------|---------------:|-----------------------------------------------------|-----------------------------------------------|
| Keyword overlap | 0.10           | `wp_mcp_ai_memory_score_boost_keyword_weight`       | `wp_mcp_ai_memory_score_boost_keyword`        |
| Temporal proximity | 0.05        | `wp_mcp_ai_memory_score_boost_temporal_weight`<br>`wp_mcp_ai_memory_score_boost_temporal_half_life` | `wp_mcp_ai_memory_score_boost_temporal`       |
| Tag/wing/room exact-match | 0.10  | `wp_mcp_ai_memory_score_boost_exact_match_weight`   | `wp_mcp_ai_memory_score_boost_exact_match`    |

Total booster contribution is capped (default `0.25`) via `wp_mcp_ai_memory_score_boost_total_cap`. To recover **pure** cosine-similarity ranking, set every weight filter to `0`:

```php
add_filter( 'wp_mcp_ai_memory_score_boost_keyword_weight',     '__return_zero' );
add_filter( 'wp_mcp_ai_memory_score_boost_temporal_weight',    '__return_zero' );
add_filter( 'wp_mcp_ai_memory_score_boost_exact_match_weight', '__return_zero' );
```

Search responses include both `similarity_score` (raw cosine) and `final_score` (after boosters) plus a `boost_breakdown` field for debugging.

#### Phase 2: Bulk ingest (`mine_agent_memory`)

Mirrors MemPalace's `mempalace mine` workflow. One tool call ingests many records into the existing memory store, scoped to a wing/room and stored **verbatim by default**.

| Source   | Behaviour                                                                                  |
|----------|---------------------------------------------------------------------------------------------|
| `posts`  | Runs a `WP_Query` against the chosen post type and stores each post's content + permalink. |
| `urls`   | Fetches each URL via `wp_safe_remote_get`, strips scripts/styles, stores the plain text.   |
| `text`   | Stores caller-supplied `{title, content, tags?, metadata?}` items as-is.                   |

Long content is auto-chunked at `chunk_size` (default 4000 chars), preserving word boundaries; each chunk shares the source title with a `(part N/M)` suffix. The total number of records created in a single run is capped at 200. `dry_run: true` plans without writing.

```json
{
  "agent_id": 123,
  "source": "posts",
  "wing": "client-acme",
  "room": "onboarding",
  "post_query": { "post_type": "kb_article", "posts_per_page": 50 },
  "tags": ["mined", "kb"],
  "verbatim": true
}
```

Every write goes through `store_agent_context`, so `wp_mcp_ai_memory_pre_store_transform`, sanitization, and the verbatim contract all behave identically to a single-record store.

#### Phase 2: Session wake-up (`wake_up_context`)

A read-only tool that builds a labelled, token-budgeted memory block ready to prepend to the assistant's system prompt at session boot.

```json
{
  "agent_id": 123,
  "wing": "client-acme",
  "top_n": 5,
  "token_budget": 800,
  "include_content": true
}
```

Returns a `system_block` string plus accounting fields (`tokens_used`, `truncated`, `memories_loaded`). The block is bracketed by `=== Persistent Memory (auto-loaded at session start) ===` headers so the LLM can see clearly where its persistent memory begins and ends.

| Filter                                | Purpose                                                          |
|---------------------------------------|------------------------------------------------------------------|
| `wp_mcp_ai_wake_up_top_n`             | Override the maximum number of memories considered.              |
| `wp_mcp_ai_wake_up_token_budget`      | Override the token budget (≈4 chars per token).                  |
| `wp_mcp_ai_wake_up_system_block`      | Reformat or wrap the rendered block before it is returned.       |

Memories that overflow the budget are dropped (lowest-priority first) and reported in the `truncated` count, so the call is always TPM-safe. Pair this with `mine_agent_memory` and a wing per project to recreate MemPalace's "session loads only this client's drawers" experience.

#### Phase 3: Pluggable embedding provider (local-first via Ollama)

Embedding generation in `WP_MCP_AI_Vector_Context_Service` is now resolved through a pluggable provider that implements `WP_MCP_AI_Embedding_Provider_Interface` (`get_id()`, `get_model()`, `is_available()`, `embed( $text )`). Two providers ship in core:

| Provider                                     | When picked by default                                 | Default model              |
|----------------------------------------------|--------------------------------------------------------|----------------------------|
| `WP_MCP_AI_Embedding_Provider_OpenAI`        | `openai_api_key` is set (auto-selected when both are configured — preserves prior behaviour). | `text-embedding-3-small`   |
| `WP_MCP_AI_Embedding_Provider_Ollama`        | Only `ollama_endpoint_url` is set (no OpenAI key), or `embedding_provider` setting is `ollama`. | `nomic-embed-text`         |

Set the explicit preference by saving `embedding_provider` (`'openai'` or `'ollama'`) in the plugin's settings option, or override entirely from PHP:

```php
add_filter( 'wp_mcp_ai_embedding_provider', function () {
    return new WP_MCP_AI_Embedding_Provider_Ollama( 'http://gpu-host.lan:11434', 'mxbai-embed-large' );
} );
```

Other filters:

| Filter                                              | Purpose                                                    |
|-----------------------------------------------------|------------------------------------------------------------|
| `wp_mcp_ai_embedding_provider_openai_model`         | Override the OpenAI embedding model id.                    |
| `wp_mcp_ai_embedding_provider_ollama_model`         | Override the Ollama embedding model id.                    |
| `wp_mcp_ai_embedding_provider_ollama_endpoint`      | Override the Ollama base URL.                              |
| `wp_mcp_ai_embedding_provider_ollama_timeout`       | HTTP timeout (seconds) for Ollama embedding calls.         |

Cache keys are now scoped to `{provider_id}:{model}:{md5(text)}`, so switching backends never returns vectors generated by a different model. Call `WP_MCP_AI_Vector_Context_Service::get_instance()->reset_embedding_provider()` to force re-resolution after a runtime settings change.

The Ollama provider POSTs to `{endpoint}/api/embeddings` via `wp_safe_remote_post` and accepts both the canonical `{embedding: [...]}` response and the plural `{embeddings: [[...]]}` form some forks return.

### Update (Edit Memory)

**Tool:** `manage_context_lifecycle` with action `update`

```json
{
  "action": "update",
  "agent_id": 123,
  "context_id": "ctx_abc123",
  "options": {
    "update_data": {
      "title": "Updated Title",
      "content": "Updated content...",
      "importance": "critical",
      "tags": ["ml", "optimization", "production"],
      "metadata": {
        "reviewed": true,
        "reviewer": "expert_ai"
      }
    }
  }
}
```

**Features:**
- Selective field updates (only update what you specify)
- Metadata merging (new metadata merges with existing)
- Update tracking (last_updated timestamp, update_count)
- Index synchronization (automatic index updates)

### Delete (Remove Memory)

**Tool:** `manage_context_lifecycle` with action `delete`

```json
{
  "action": "delete",
  "agent_id": 123,
  "context_id": "ctx_abc123"
}
```

**Features:**
- Safe deletion with verification
- Automatic index cleanup
- Returns deleted context info for confirmation
- Checks expiration status

---

## Batch Operations

**Tool:** `batch_manage_memory`

### Bulk Update

Update multiple memories at once:

```json
{
  "action": "bulk_update",
  "agent_id": 123,
  "context_ids": ["ctx_1", "ctx_2", "ctx_3"],
  "updates": {
    "importance": "high",
    "add_tags": ["reviewed", "production"],
    "metadata": {
      "batch_reviewed": true,
      "review_date": "2026-02-18"
    }
  },
  "options": {
    "dry_run": false
  }
}
```

**Or use filters instead of context_ids:**

```json
{
  "action": "bulk_update",
  "agent_id": 123,
  "filters": {
    "context_types": ["learning"],
    "tags": ["ml"],
    "importance": ["medium"]
  },
  "updates": {
    "importance": "high"
  }
}
```

### Bulk Delete

Delete multiple memories matching criteria:

```json
{
  "action": "bulk_delete",
  "agent_id": 123,
  "filters": {
    "context_types": ["note"],
    "tags": ["temporary"]
  },
  "options": {
    "dry_run": true
  }
}
```

### Export Memories

Export to JSON for backup:

```json
{
  "action": "export",
  "agent_id": 123,
  "filters": {
    "importance": ["high", "critical"]
  }
}
```

**Response includes:**
```json
{
  "success": true,
  "export_data": "{\"export_version\":\"1.0\",\"agent_id\":123,...}",
  "context_count": 45
}
```

### Import Memories

Restore from JSON backup:

```json
{
  "action": "import",
  "agent_id": 123,
  "export_data": "{\"export_version\":\"1.0\",...}",
  "options": {
    "dry_run": false
  }
}
```

### Tag Management

**Add Tags:**
```json
{
  "action": "tag_add",
  "agent_id": 123,
  "context_ids": ["ctx_1", "ctx_2"],
  "tags": ["important", "reviewed"]
}
```

**Remove Tags:**
```json
{
  "action": "tag_remove",
  "agent_id": 123,
  "filters": {
    "tags": ["temporary"]
  },
  "tags": ["temporary", "draft"]
}
```

**Replace Tags:**
```json
{
  "action": "tag_replace",
  "agent_id": 123,
  "context_ids": ["ctx_1"],
  "tags": ["production", "reviewed", "final"]
}
```

### Dry Run Mode

Preview changes before applying:

```json
{
  "action": "bulk_update",
  "agent_id": 123,
  "filters": {...},
  "updates": {...},
  "options": {
    "dry_run": true
  }
}
```

**Response shows what would change without actually modifying data.**

---

## Audit Trail & Versioning

**Tool:** `memory_audit_trail`

### Version History

Get all versions of a memory:

```json
{
  "action": "get_history",
  "agent_id": 123,
  "context_id": "ctx_abc123",
  "options": {
    "limit": 50
  }
}
```

**Response:**
```json
{
  "success": true,
  "versions": {
    "1": {
      "version": 1,
      "data": {...},
      "change_type": "create",
      "timestamp": "2026-02-15 10:00:00"
    },
    "2": {
      "version": 2,
      "data": {...},
      "change_type": "update",
      "timestamp": "2026-02-16 14:30:00"
    }
  },
  "total_versions": 2
}
```

### Compare Versions

See what changed between versions:

```json
{
  "action": "compare_versions",
  "agent_id": 123,
  "context_id": "ctx_abc123",
  "versions": {
    "from": 1,
    "to": 2
  }
}
```

**Response:**
```json
{
  "success": true,
  "differences": {
    "added": {},
    "removed": {},
    "modified": {
      "importance": {
        "from": "medium",
        "to": "high"
      },
      "tags": {
        "from": ["ml"],
        "to": ["ml", "production"]
      }
    }
  }
}
```

### Rollback to Previous Version

Restore a previous version:

```json
{
  "action": "rollback",
  "agent_id": 123,
  "context_id": "ctx_abc123",
  "version": 1
}
```

**Features:**
- Saves current state before rollback
- Adds rollback metadata
- Creates new version entry
- Logs in audit trail

### Audit Log

Get complete change log:

```json
{
  "action": "get_audit_log",
  "agent_id": 123,
  "options": {
    "limit": 100,
    "date_from": "2026-02-01",
    "date_to": "2026-02-18",
    "action_type": "update"
  }
}
```

**Response:**
```json
{
  "success": true,
  "entries": [
    {
      "context_id": "ctx_abc123",
      "action": "update",
      "metadata": {...},
      "timestamp": "2026-02-18 10:00:00",
      "user_id": 1
    }
  ],
  "total_entries": 45
}
```

### Audit Statistics

Get usage analytics:

```json
{
  "action": "get_stats",
  "agent_id": 123
}
```

**Response:**
```json
{
  "success": true,
  "stats": {
    "total_events": 250,
    "by_action": {
      "create": 50,
      "update": 120,
      "delete": 30,
      "access": 50
    },
    "by_hour": {...},
    "recent_24h": 45,
    "most_active_context": {
      "context_id": "ctx_abc123",
      "events": 25
    }
  }
}
```

---

## Industry Best Practices

This implementation follows 2025-2026 RAG best practices:

### 1. Hierarchical Memory Architecture ✅

- **Short-term memory**: Session-based with transients
- **Long-term memory**: Persistent with configurable TTL
- **Context compression**: TTL-aware compression policies
- **Prioritization**: Multi-factor scoring (recency, frequency, importance)

### 2. Observability & Monitoring ✅

- **Audit trail**: Every change tracked with user and timestamp
- **Version history**: Full history with 100-version retention
- **Health metrics**: Usage patterns, access frequency, expiration tracking
- **Analytics**: Statistics on memory usage and patterns

### 3. Batch Operations ✅

- **Bulk updates**: Efficient processing of multiple memories
- **Dry-run mode**: Safe preview before applying changes
- **Export/import**: Backup and disaster recovery
- **Tag management**: Organize and categorize at scale

### 4. Security & Governance ✅

- **Audit logging**: Compliance-ready change tracking
- **Version control**: Rollback capability for data integrity
- **Access tracking**: Who accessed what and when
- **Data validation**: Input sanitization and validation

### 5. Production-Ready ✅

- **Transient storage**: WordPress built-in caching with auto-cleanup
- **Error handling**: Comprehensive error messages and recovery
- **Index consistency**: Automatic synchronization
- **Performance**: Optimized queries and caching

---

## API Reference

### manage_context_lifecycle

Enhanced with new actions:

| Action | Description | Required Parameters |
|--------|-------------|---------------------|
| `refresh` | Update TTL | context_id, options.new_ttl |
| `compress` | Apply compression | context_id |
| `merge` | Combine contexts | context_ids, options.merge_title |
| `analyze` | Health metrics | agent_id |
| `prune` | Remove unused | options.prune_threshold |
| **`update`** | **Edit memory** | **context_id, options.update_data** |
| **`delete`** | **Remove memory** | **context_id** |

### batch_manage_memory

| Action | Description | Parameters |
|--------|-------------|------------|
| `bulk_update` | Update multiple | context_ids/filters, updates |
| `bulk_delete` | Delete multiple | context_ids/filters |
| `export` | Export to JSON | filters (optional) |
| `import` | Import from JSON | export_data |
| `tag_add` | Add tags | context_ids/filters, tags |
| `tag_remove` | Remove tags | context_ids/filters, tags |
| `tag_replace` | Replace tags | context_ids/filters, tags |

### memory_audit_trail

| Action | Description | Parameters |
|--------|-------------|------------|
| `get_history` | Version history | context_id, options.limit |
| `compare_versions` | Diff versions | context_id, versions |
| `rollback` | Restore version | context_id, version |
| `get_audit_log` | Change log | options (filters) |
| `get_stats` | Analytics | agent_id |

---

## Examples

### Example 1: Update and Track Changes

```javascript
// 1. Update memory
const updateResult = await manage_context_lifecycle({
  action: 'update',
  agent_id: 123,
  context_id: 'ctx_abc123',
  options: {
    update_data: {
      importance: 'critical',
      tags: ['production', 'reviewed']
    }
  }
});

// 2. View version history
const history = await memory_audit_trail({
  action: 'get_history',
  agent_id: 123,
  context_id: 'ctx_abc123'
});

// 3. Check audit log
const auditLog = await memory_audit_trail({
  action: 'get_audit_log',
  agent_id: 123,
  options: {
    action_type: 'update',
    limit: 10
  }
});
```

### Example 2: Batch Operations Workflow

```javascript
// 1. Preview changes (dry run)
const preview = await batch_manage_memory({
  action: 'bulk_update',
  agent_id: 123,
  filters: {
    tags: ['draft']
  },
  updates: {
    importance: 'high',
    add_tags: ['ready-for-review']
  },
  options: {
    dry_run: true
  }
});

// 2. Apply changes
if (preview.success && preview.updated_count > 0) {
  const result = await batch_manage_memory({
    ...preview, // Same parameters
    options: {
      dry_run: false
    }
  });
}

// 3. Export updated memories
const backup = await batch_manage_memory({
  action: 'export',
  agent_id: 123,
  filters: {
    tags: ['ready-for-review']
  }
});

// Save export_data to file
fs.writeFileSync('memories_backup.json', backup.export_data);
```

### Example 3: Version Management

```javascript
// 1. Make updates
await manage_context_lifecycle({
  action: 'update',
  agent_id: 123,
  context_id: 'ctx_abc123',
  options: {
    update_data: {
      content: 'Updated content v1'
    }
  }
});

// Later, make more updates
await manage_context_lifecycle({
  action: 'update',
  agent_id: 123,
  context_id: 'ctx_abc123',
  options: {
    update_data: {
      content: 'Updated content v2'
    }
  }
});

// 2. Compare versions
const diff = await memory_audit_trail({
  action: 'compare_versions',
  agent_id: 123,
  context_id: 'ctx_abc123',
  versions: {
    from: 1,
    to: 2
  }
});

// 3. Rollback if needed
if (needsRollback) {
  await memory_audit_trail({
    action: 'rollback',
    agent_id: 123,
    context_id: 'ctx_abc123',
    version: 1
  });
}
```

---

## Best Practices

### 1. Memory Management

- **Use appropriate TTL**: 7-90 days based on importance
- **Set importance levels**: Critical > High > Medium > Low
- **Tag consistently**: Use standardized tag taxonomy
- **Regular cleanup**: Use prune action monthly

### 2. Batch Operations

- **Always dry-run first**: Preview changes before applying
- **Use filters effectively**: Target specific memories precisely
- **Export regularly**: Backup critical memories
- **Monitor performance**: Limit batch sizes to 100-500

### 3. Versioning & Audit

- **Keep version history**: Useful for debugging and compliance
- **Monitor audit logs**: Track unusual patterns
- **Use rollback sparingly**: Only when necessary
- **Archive old versions**: Export and store externally if needed

### 4. Performance

- **Limit results**: Use pagination (limit parameter)
- **Filter strategically**: Reduce unnecessary data retrieval
- **Cache exports**: Reuse export data when possible
- **Monitor health**: Check health metrics regularly

### 5. Security

- **Sanitize inputs**: All data is validated and sanitized
- **Check permissions**: Ensure proper authorization
- **Audit access**: Track who accesses what
- **Review changes**: Periodic audit log reviews

---

## Troubleshooting

### Memory Not Updating

**Cause**: Context has expired  
**Solution**: Check TTL and refresh if needed

### Batch Operation Fails

**Cause**: No contexts match filters  
**Solution**: Verify filters with retrieve_agent_memory first

### Version History Missing

**Cause**: Version history has 1-year TTL  
**Solution**: Export important histories regularly

### Audit Log Incomplete

**Cause**: Limited to 1000 entries  
**Solution**: Export audit logs periodically

---

## Migration Guide

### From Basic Memory to Enhanced System

1. **No changes needed**: Existing memories work automatically
2. **Optional**: Add versioning by making updates
3. **Recommended**: Export existing memories for backup
4. **Best practice**: Start using tags for organization

---

## Graphify-Backed Memory (Phase 4a, optional)

When the optional **NV oOS Graphify** addon is active, agent memory is mirrored
into the Graphify knowledge graph in real time. This is **purely additive** —
the transient store remains the source of truth in Phase 4a, and everything
behaves identically to the standalone configuration when Graphify is not
installed.

### What gets projected

Every successful `store_agent_context` call (and therefore every item written
by `mine_agent_memory`) fires a new `wp_mcp_ai_memory_stored` action. Graphify
subscribes to that action and creates:

- a **`memory` node** keyed `memory:<context_id>`, carrying the verbatim
  content, importance, tags, wing/room scope, agent id, and stored/expires
  timestamps as node properties;
- an `OBSERVED_BY` edge to the corresponding `agent:<agent_id>` node;
- a `MEMBER_OF` edge to the `wing:<wing>` node when a wing is set;
- a `MEMBER_OF` edge to the composite `room:<wing>:<room>` node when a room
  is set, plus a `MEMBER_OF` edge from that room to the wing;
- a `DERIVED_FROM` edge to the source post node when the memory was ingested
  from a WordPress post (`content_source.type = "post"`);
- a vector embedding pushed through the same on-ingest cron pipeline used by
  Graphify's content embeddings, so memory vectors share the
  `embeddings` table and provider configuration with the rest of the graph.

If the Graphify addon is deactivated, the action still fires but has no
listener, and the system continues to operate from the transient store with
zero behaviour change.

### Graph-mode retrieval (`wake_up_context`)

`wake_up_context` accepts a new `mode` parameter:

| Mode        | Behaviour                                                                                                                                                     |
|-------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `auto`      | (default) Use graph traversal when the Graphify bridge is loaded; otherwise fall back to the transient + cosine path.                                         |
| `graph`     | Force graph traversal. Returns an error (`success: false`) when the Graphify addon is not active. Useful when callers explicitly want graph-ranked semantics. |
| `transient` | Force the legacy transient + cosine path even when Graphify is loaded — handy for debugging and side-by-side comparisons.                                     |

Graph traversal blends three signals into a single ranked list of memory
context ids:

1. **Anchor expansion** — every memory connected to the requested
   `agent:<agent_id>`, `wing:<wing>`, and `room:<wing>:<room>` nodes via the
   relations above is collected as a candidate. Wing/room anchors carry more
   weight than agent anchors so scoped recall feels precise.
2. **Keyword search** — when a `query` is supplied, `search_nodes()` runs a
   `LIKE` match over memory node labels and adds matches to the candidate
   set with a moderate boost.
3. **Vector similarity** — when a `query` is supplied and embeddings are
   enabled, the same `WP_MCP_AI_Vector_Context_Service` provider used for
   transient cosine search produces a query vector. Cosine matches against
   the Graphify `embeddings` table receive a positive boost.

If the graph yields no candidates the wake-up tool transparently falls back to
the legacy retrieval so an operator never sees an empty palace.

The response gains a new `retrieval_path` field (`graph` or `transient`) so
callers can observe which path serviced the request.

### Filters and hooks introduced in Phase 4a

| Hook                                    | Type   | When it fires                                                                                              |
|-----------------------------------------|--------|------------------------------------------------------------------------------------------------------------|
| `wp_mcp_ai_memory_stored`               | action | After every successful `store_agent_context` write; payload contract documented in the tool's PHPDoc.       |
| `wp_mcp_ai_wake_up_graph_context_ids`   | filter | After graph retrieval ranks ids and before each one is fetched. Use to inject, reorder, or drop memories. |
| `wp_mcp_ai_graph_score_weights`         | filter | Tunes the linear-combination weights for the three GraphRAG signals (anchor / keyword / vector). Default `{agent:0.1, wing:0.4, room:0.6, keyword:0.5, vector:1.0}` follows the keyword 0.4–0.5 / graph 0.2–0.3 / vector 0.3–0.4 recipe documented for Microsoft GraphRAG, Neo4j, and LlamaIndex PropertyGraphIndex. Per-query overrides also accepted via `args.weights`. |

### Standard memory-record fields (industry alignment)

Each `memory:<context_id>` node carries the conventional fields used by MemGPT/Letta, mem0, and LangMem so downstream tooling can ingest them without translation:

| Property      | Meaning                                                                                                  |
|---------------|----------------------------------------------------------------------------------------------------------|
| `created_at`  | Standard creation timestamp (alias of `stored_at`, kept for backwards compatibility).                     |
| `memory_type` | LangMem-style taxonomy (semantic / episodic / procedural / fact / …); mirrors `context_type`.            |
| `importance`  | `low` / `medium` / `high` / `critical`.                                                                  |
| `agent_id`    | Multi-tenant scoping field.                                                                              |
| `wing`/`room` | NV oOS scope fields used as anchors during graph retrieval.                                              |
| `tags`        | Free-form tag list.                                                                                      |
| `verbatim`    | `1` when the verbatim discipline was applied at ingest.                                                  |
| `expires_at`  | Optional TTL (mirrors transient-store expiry).                                                           |

### Retrieval-response observability (`via` provenance)

When graph retrieval services a `wake_up_context` request, every entry in `memories_loaded` carries a `via` array listing which signals matched — one or more of `agent`, `wing`, `room`, `keyword`, `vector`. This mirrors the retrieval-log conventions exposed by mem0 and Letta and lets operators debug *why* a particular memory was surfaced.

```jsonc
{
  "retrieval_path": "graph",
  "memories_loaded": [
    { "context_id": "ctx_…", "title": "…", "via": ["wing", "vector"] },
    { "context_id": "ctx_…", "title": "…", "via": ["room", "keyword"] }
  ]
}
```

### Visualising the palace

The Graphify admin "Graph Explorer" tab adds two new toolbar inputs and a
**Memory Palace** preset:

- **Agent ID** — the agent whose memories you want to highlight.
- **Wing** — an optional wing scope. Combine with the agent for the classic
  "Agent: X / Wing: Y" view.
- **Apply** — fades the rest of the graph and highlights the matching
  `memory` nodes (and their wing/agent anchor nodes).
- **Clear** — clears both inputs and removes the highlight.

The preset matches by node properties (`agent_id`, `wing`, `room`), so it
works on the initial graph render without first having to click each node to
load its edges.

### Test coverage

- `tests/test-mempalace-phase4a-graphify-bridge.php`
  - asserts the `wp_mcp_ai_memory_stored` payload contract,
  - asserts that `mine_agent_memory` produces one event per item,
  - asserts the absent-path: store still succeeds with no listener,
  - asserts the bridge handler is exception-safe,
  - asserts `mode: 'graph'` errors gracefully without Graphify,
  - asserts `mode: 'auto'` falls back to `transient` without Graphify,
  - asserts `mode: 'transient'` forces the legacy path even when Graphify is
    present,
  - exercises the graph happy-path by injecting an ordered id list through
    `wp_mcp_ai_wake_up_graph_context_ids` and verifying the renderer respects
    the graph order.

---

## Support

- **Documentation**: See `/docs/RAG-ENHANCED-MEMORY-MANAGEMENT.md`
- **Tool Reference**: See `/docs/tool-reference.md`
- **API Reference**: See `/docs/rest-api.md`
- **Issues**: GitHub Issues

---

**Last Updated:** February 18, 2026  
**Version:** 1.1.0  
**Status:** Production Ready
