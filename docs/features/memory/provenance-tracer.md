# Memory Provenance Tracer

> Status: Phase 6 of the 2026 Memory Layer Enhancements — shipped in NV oOS 1.1.20.

## What this is

A read-only tool (`trace_memory_provenance`) that returns the **full origin
chain** for a single memory record, identified by its `context_id`. It
assembles three independent sections — audit trail, version history, and
the Graphify graph neighbourhood reachable over `RECALLS` edges — into
one envelope so a human (or a downstream UI) can answer the question:

> **"Why does the agent know this?"**

It is the data layer behind:

- The Phase 7b Memory Drawer "Why?" row (one click on a memory chip
  surfaces this tool's output, formatted as a timeline + graph mini-view).
- GDPR / DSAR ("Right to Explanation") workflows — operators can export a
  single memory's full lineage.
- Engineering debugging — "where did this stale answer come from?".

The tool is **read-only**, requires `edit_posts`, and never fails because
of an absent optional dependency. Graphify being inactive degrades to
`graph.available = false` rather than an error.

## Slug & capability

| Attribute | Value |
|---|---|
| Slug | `trace_memory_provenance` |
| Required capability | `edit_posts` |
| Capability flags | `safe`, `local-only`, `read-only`, `idempotent`, `cacheable` |
| Modifies WordPress? | No |

## Input

```json
{
  "agent_id":         42,
  "context_id":       "ctx_a1b2c3d4",
  "max_depth":        5,
  "include_audit":    true,
  "include_versions": true,
  "include_graph":    true
}
```

| Key | Type | Required | Notes |
|---|---|---|---|
| `agent_id` | integer (≥1) | yes | Assistant post ID the memory belongs to. |
| `context_id` | string | yes | Must match `ctx_*`. |
| `max_depth` | integer (1..20) | no | Graph BFS depth. Default 5. Clamped by filter. |
| `include_audit` | boolean | no | Default true. |
| `include_versions` | boolean | no | Default true. |
| `include_graph` | boolean | no | Default true. |

## Output

On success, returns the canonical envelope:

```json
{
  "success": true,
  "message": "Provenance trace assembled.",
  "context_id": "ctx_a1b2c3d4",
  "agent_id": 42,
  "trace": {
    "audit": {
      "available": true,
      "events": [
        { "context_id": "ctx_a1b2c3d4", "action": "create", "timestamp": "2026-11-01 09:00:00", "metadata": { "source": { "type": "tool", "value": "store_agent_context" } }, "user_id": 0 },
        { "context_id": "ctx_a1b2c3d4", "action": "update", "timestamp": "2026-11-02 10:15:00", "metadata": { "source": { "type": "user", "value": "editor-1" } }, "user_id": 7 }
      ],
      "total": 2
    },
    "versions": {
      "available": true,
      "versions": [
        { "version": 1, "data": { "title": "v1", "content": "first revision" }, "change_type": "update", "timestamp": "2026-11-01 09:00:00" },
        { "version": 2, "data": { "title": "v2", "content": "second revision" }, "change_type": "update", "timestamp": "2026-11-02 10:15:00" }
      ],
      "total": 2
    },
    "graph": {
      "available": true,
      "nodes": [
        { "node_id": "memory:ctx_neighbor_1", "context_id": "ctx_neighbor_1", "depth": 1, "edge": "RECALLS" }
      ],
      "depth": 5
    }
  },
  "summary": {
    "first_seen":         "2026-11-01 09:00:00",
    "last_modified":      "2026-11-02 10:15:00",
    "modification_count": 2,
    "source_count":       2,
    "first_source":       { "type": "tool", "value": "store_agent_context" }
  }
}
```

When a section is suppressed by the caller, the key is still present but
shaped like `{ "available": false, "reason": "suppressed by caller" }`.

When Graphify is not loaded, the graph section becomes
`{ "available": false, "reason": "Graphify bridge unavailable" }` — the
top-level call still returns `success: true`.

## Errors

| Code | When | HTTP-equiv |
|---|---|---|
| `invalid_agent_id` | `agent_id` missing or ≤ 0 | 400 |
| `invalid_context_id` | `context_id` missing or doesn't start with `ctx_` | 400 |
| `memory_not_found` | No transient record, audit entries, or version history exist for that `context_id` under that agent | 404 |

Failure values are `WP_Error` instances — **never** `array( 'success' => false )`.

## Filters

| Filter | Args | Default | Purpose |
|---|---|---|---|
| `wp_mcp_ai_memory_provenance_max_depth` | `int $depth` | `5` (raw caller value) | Clamp graph BFS depth. Final result is also hard-capped at 20. |
| `wp_mcp_ai_memory_provenance_include_audit_default` | `bool` | `true` | Override default for the `include_audit` flag when the caller omits it. |
| `wp_mcp_ai_memory_provenance_include_versions_default` | `bool` | `true` | Same, for versions. |
| `wp_mcp_ai_memory_provenance_include_graph_default` | `bool` | `true` | Same, for graph. |
| `wp_mcp_ai_memory_provenance_summary` | `array $summary, string $context_id, int $agent_id` | as computed | Extensibility hook — let plugins inject extra summary keys (retention status, supersession chain, etc.) before the response is returned. |

### Example: tighten the default BFS depth on a slow site

```php
add_filter(
    'wp_mcp_ai_memory_provenance_max_depth',
    static function ( $depth ) {
        return min( 3, (int) $depth );
    }
);
```

### Example: turn off the graph section by default

```php
add_filter( 'wp_mcp_ai_memory_provenance_include_graph_default', '__return_false' );
```

### Example: enrich the summary with the live retention tier

```php
add_filter(
    'wp_mcp_ai_memory_provenance_summary',
    static function ( $summary, $context_id, $agent_id ) {
        $manager = WP_MCP_AI_Agent_Context_Manager::get_instance();
        $ctx     = $manager->retrieve_context( $agent_id, $context_id, true );
        $summary['retention_tier'] = is_array( $ctx ) && ! empty( $ctx['memory_tier'] )
            ? (string) $ctx['memory_tier']
            : 'unknown';
        return $summary;
    },
    10,
    3
);
```

## Events

| Action | Args | Fires when |
|---|---|---|
| `wp_mcp_ai_memory_provenance_traced` | `string $context_id, int $agent_id, array $summary` | Exactly once per **successful** trace. Never fires on validation or `memory_not_found` errors. |

This is the canonical observer hook for indexing provenance into an
external SIEM / DSAR system.

## Performance

The graph section's BFS is **O(depth × fan-out)** — for a typical Graphify
graph (fan-out 5–20 over `RECALLS`) the work envelope is:

| `max_depth` | Worst-case node visits |
|---:|---:|
| 1 | ≤ 20 |
| 3 | ≤ 8 000 |
| 5 | ≤ 3.2 M |
| 10 | unbounded for interactive use |

> **Recommendation:** keep `max_depth ≤ 5` for any synchronous UI surface.
> Larger values should run in a background job and stream their results,
> not block the chat thread.

Audit-trail and version-history fetches are **O(1)** transient reads.

## How Phase 7b consumes this

The Memory Drawer's "Why?" row (Phase 7b, sequential after Phase 6) issues
a single call to this tool every time the user expands a memory chip, with
the agent's current ID and the chip's `context_id`. The response is
rendered as:

1. A timeline of `trace.audit.events` (oldest first → newest).
2. A diff strip between adjacent `trace.versions[]` snapshots.
3. A small Cytoscape mini-view of `trace.graph.nodes` (when available).
4. A one-liner derived from `summary.first_source`:
   *"Captured by `store_agent_context` on 2026-11-01."*

When Graphify is inactive, item 3 is replaced with a "Graph layer not
installed" hint card; the rest of the drawer is unaffected.

## See also

- `docs/features/memory/privacy-filter.md` — the redactor that runs
  **before** any memory hits the data layer this tool reads from.
- `docs/AGENT-MEMORY-COMPLETE-GUIDE.md` — full reference for the memory
  CCT schema, transient layout, and tier vocabulary.
- `WP_MCP_AI_Tool_Memory_Audit_Trail` — the write-side companion that
  populates the audit log and version history transients consumed here.
