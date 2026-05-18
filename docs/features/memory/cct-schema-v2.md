# Agent Memory CCT Schema v2

> Status: Phase 2 of the 2026 Memory Layer Enhancements — shipped in NV oOS 1.1.20.

## What this is

The agent-memory CCT (`ai_agent_memories`) gains five new meta fields that
power the downstream phases (3 — auto-capture, 5 — decay + contradictions,
7 — Memory Health). Each field is **strictly additive**: legacy rows without
the field continue to read with documented defaults, and legacy event payloads
that omit the field continue to write successfully.

## New fields

| Field | Type | Default for legacy rows | Used by |
|---|---|---|---|
| `content_hash` | text (64-char hex SHA-256) | computed lazily from normalised content | Phase 3 dedup, Phase 5 contradiction detection |
| `confidence_score` | text (float 0.0–1.0) | `1.0` | Phase 5 decay, retrieval ranking |
| `last_accessed_at` | datetime-local | `stored_at` / `transaction_time` | Phase 5 decay, Phase 7 health diagnostics |
| `superseded_by` | text (context_id) | `''` | Phase 5 contradiction resolution |
| `auto_captured` | number (0/1) | `0` | Phase 3 distinguishes auto vs explicit |

All five appear in the JetEngine admin UI for new CCT installs immediately;
existing CCT instances get them after the one-shot migrator runs (see below).

## `content_hash` semantics

The hash is computed over **normalised** content:

1. Lower-case (UTF-8 safe via `mb_strtolower` when available).
2. Collapse runs of whitespace to single spaces.
3. Trim.
4. SHA-256.

Result: a 64-char hex string. `"Hello   WORLD"` and `"hello world"` hash to
the same value, so Phase 3's 5-minute dedup window will treat them as one
record.

Empty content yields an empty hash — callers should not key dedup on a record
without content.

The hash can be supplied directly by Phase 3's auto-capture service (which
computes it before the dedup-window check). When supplied, it overrides the
bridge's auto-computation.

## Backward compatibility

### Event payload (caller side)

Existing callers of `do_action( 'wp_mcp_ai_memory_stored', $event )` continue
to work unchanged. The bridge fills in v2 defaults from the existing payload
fields:

```php
do_action( 'wp_mcp_ai_memory_stored', array(
    // Pre-v2 payload — no v2 fields supplied.
    'context_id' => 'ctx_abc',
    'agent_id'   => 'agent_1',
    'content'    => 'The capital of France is Paris.',
    'stored_at'  => '2026-11-15 10:00:00',
    // ... wing, room, sensitivity, etc.
) );
// → bridge writes:
//    content_hash     = sha256( "the capital of france is paris." )
//    confidence_score = "1.0"
//    last_accessed_at = "2026-11-15 10:00:00"
//    superseded_by    = ""
//    auto_captured    = 0
```

### Pre-existing CCT rows (database side)

Rows written before v2 don't have the new fields. Consumers must use these
fallbacks:

```php
$confidence = '' !== $row['confidence_score']
    ? (float) $row['confidence_score']
    : 1.0;

$last_accessed = '' !== $row['last_accessed_at']
    ? $row['last_accessed_at']
    : ( $row['transaction_time'] ?? $row['stored_at'] );

$auto_captured = ! empty( $row['auto_captured'] );
```

The Phase 5 decay sweep and Phase 3 dedup service already include these
fallbacks; downstream readers should adopt the same pattern.

## Migrator

`WP_MCP_AI_Agent_Memory_CCT_Migrator` runs once per admin pageview when the
stored schema version (`wp_mcp_ai_memory_cct_schema_version` option) is below
the current target (`2`).

### Behaviour

- **Idempotent.** After a successful run, the option is set to `2` and subsequent
  pageviews skip the upgrade path.
- **Admin-only.** Skipped for non-`manage_options` users.
- **Best-effort.** When JetEngine is unavailable, the CCT module is disabled,
  or the upgrade transaction throws, the migrator records the failure to
  `WP_MCP_AI_Logger` and leaves the version option untouched — data writes
  continue to work because they don't depend on the admin UI schema.
- **Forward-compatible.** The migrator re-reads `get_meta_fields()` and
  `get_cct_args()` via reflection, so future schema versions ride on the
  current source of truth without a new migrator class.

### Filters

| Filter | Default | Purpose |
|---|---|---|
| `wp_mcp_ai_memory_cct_migrator_enabled` | `true` | Master kill-switch. Disables admin-UI refresh; data writes unaffected. |

### Headless invocation

```php
$result = WP_MCP_AI_Agent_Memory_CCT_Migrator::maybe_run();
// $result['ran']           — whether the upgrade was attempted
// $result['succeeded']     — whether it completed
// $result['from_version']  — pre-run version
// $result['to_version']    — post-run version
// $result['message']       — human-readable status
```

### Version accessors

```php
$installed = WP_MCP_AI_Agent_Memory_CCT_Migrator::get_installed_version(); // 0..2
$target    = WP_MCP_AI_Agent_Memory_CCT_Migrator::get_target_version();    // 2
```

The Phase 7a Memory Health subtab uses these to surface a yellow "Schema
upgrade pending" badge when `$installed < $target`.

## Tests

`tests/test-agent-memory-cct-schema-v2.php` — 14 cases covering field
declarations, default values for legacy events, override semantics, hash
stability under normalisation, migrator idempotence, capability gate,
graceful failure without JetEngine, kill-switch, version accessors, and
backward-compat with the existing `wp_mcp_ai_memory_cct_record` filter.
