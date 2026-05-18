# Memory Auto-Capture Service (Phase 3)

Phase 3 of the 2026 Memory Layer Enhancements. Provides a silent observer that
turns selected lifecycle events — tool executions and chat-request prompts —
into low-importance `observation`-typed memory records, with SHA-256 dedup so a
chatty agentic loop does not bloat the durable memory layer.

| Aspect | Value |
| --- | --- |
| Service class | `WP_MCP_AI_Memory_Auto_Capture_Service` |
| File | `includes/services/class-wp-mcp-ai-memory-auto-capture-service.php` |
| Default state | **OFF** — opt-in via filter |
| Hooks consumed | `wp_mcp_ai_tool_executed`, `wp_mcp_ai_before_chat_request` |
| Storage path | `WP_MCP_AI_Memory_Capture_Service::store()` (existing) |
| Tier | `recall` (subject to Phase 5 decay) |
| Importance | `0.3` (filterable) |
| Dedup window | 300s (filterable) |

---

## Why this is OFF by default

The Memory Layer 2026 roadmap delivers durable agent memory with bi-temporal
validity, contradiction detection, and decay (Phase 5). **Until that decay
tuning has been stress-tested in long-running production environments, opening
the auto-capture firehose creates a real risk of memory explosion.** Every
tool call from every agentic loop would otherwise spawn a new memory record,
and even with SHA-256 dedup the volume can dominate the durable store within
days on a busy site.

Auto-capture is therefore opt-in. To enable it for evaluation:

```php
add_filter( 'wp_mcp_ai_memory_auto_capture_enabled', '__return_true' );
```

When the master kill-switch is false (the default), `bootstrap()` is a no-op:
no hooks register, no filters fire, no transients are read. A disabled
deployment pays zero per-event cost.

---

## How it works

```
                 wp_mcp_ai_tool_executed
                 wp_mcp_ai_before_chat_request
                          │
                          ▼
        ┌──────────────────────────────────┐
        │ 1. tool allowlist / denylist gate │  silent skip on denylist hit
        └──────────────────────────────────┘
                          │
                          ▼
        ┌──────────────────────────────────┐
        │ 2. user gates                     │
        │    - guest? require filter         │
        │    - per-user meta opt-out         │
        │    - site-wide filter false?       │
        └──────────────────────────────────┘
                          │
                          ▼
        ┌──────────────────────────────────┐
        │ 3. normalise content              │
        │    (WP_MCP_AI_Agent_Memory_CCT_   │
        │     Bridge::normalise_for_hash)   │
        └──────────────────────────────────┘
                          │
                          ▼
        ┌──────────────────────────────────┐
        │ 4. REDACT (privacy filter)        │  ← redaction happens BEFORE
        │    WP_MCP_AI_Memory_Privacy_      │     hashing so secrets cannot
        │    Filter::redact()                │     contaminate the dedup key
        └──────────────────────────────────┘
                          │
                          ▼
        ┌──────────────────────────────────┐
        │ 5. SHA-256 hash + 32-char prefix  │
        │    transient key                  │
        └──────────────────────────────────┘
                          │
              ┌───────────┴────────────┐
              │ already in dedup window?│
              └───────────┬────────────┘
                YES                  NO
                 │                    │
                 ▼                    ▼
   fire wp_mcp_ai_         set transient with TTL
   memory_auto_           300s, store via
   capture_deduped         WP_MCP_AI_Memory_Capture_
   (silent skip)           Service::store(),
                           fire wp_mcp_ai_
                           memory_auto_captured
```

The dedup window collapses bursty observations (e.g. a loop that calls the
same tool with identical arguments 20 times in 10 seconds) to a single
record. The window is filterable and defaults to 5 minutes.

---

## Filter reference

| Filter | Type | Default | Purpose |
| --- | --- | :---: | --- |
| `wp_mcp_ai_memory_auto_capture_enabled` | bool | `false` | **Master kill-switch.** When false, `bootstrap()` is a no-op (no hooks registered). |
| `wp_mcp_ai_memory_auto_capture_dedup_window` | int (seconds) | `300` | TTL for the SHA-256 dedup transient. |
| `wp_mcp_ai_memory_auto_capture_importance` | float (0.0–1.0) | `0.3` | Importance value written into the capture envelope. Auto-captures are observations, not user-curated facts; clamped to [0, 1]. |
| `wp_mcp_ai_memory_auto_capture_tool_allowlist` | string[] | `[]` | When non-empty, ONLY listed tools are captured. Use this for narrow A/B trials. |
| `wp_mcp_ai_memory_auto_capture_tool_denylist` | string[] | see below | Tools never captured. Default set blocks every memory retrieval / mutation tool. |
| `wp_mcp_ai_memory_auto_capture_guests_allowed` | bool | `false` | Whether captures fire for user_id 0. |
| `wp_mcp_ai_memory_auto_capture_wing` | string | `'auto'` | Wing assigned to auto-captures. |
| `wp_mcp_ai_memory_auto_capture_room` | string | `'unscoped'` | Room assigned to auto-captures. |

### Default denylist

The default denylist blocks every tool that reads, mutates, or audits memory
records:

```
recall_memory
wake_up_context
retrieve_agent_memory
semantic_context_search
store_agent_context           ← already an explicit write path
mine_agent_memory
batch_manage_memory
manage_context_lifecycle
memory_audit_trail
```

This is the single most important gate in the service. Removing any of these
risks a feedback loop where every retrieval spawns a memory of itself, which
surfaces in the next retrieval, which spawns another memory, etc.

### Filter examples

```php
// Enable auto-capture in a dev environment.
add_filter( 'wp_mcp_ai_memory_auto_capture_enabled', '__return_true' );

// Widen the dedup window to 30 minutes for chatty support workflows.
add_filter( 'wp_mcp_ai_memory_auto_capture_dedup_window', static function () {
    return 30 * MINUTE_IN_SECONDS;
} );

// Trial auto-capture for a single research tool only.
add_filter( 'wp_mcp_ai_memory_auto_capture_tool_allowlist', static function () {
    return array( 'crawl4ai_extract' );
} );

// Route auto-captures into a per-assistant wing.
add_filter( 'wp_mcp_ai_memory_auto_capture_wing', static function ( $wing, $args ) {
    if ( ! empty( $args['context_meta']['assistant_id'] ) ) {
        return 'assistant/' . $args['context_meta']['assistant_id'];
    }
    return $wing;
}, 10, 2 );
```

---

## Events emitted

Both events fire from the singleton's `capture()` method. Listeners may run
arbitrary side effects (audit trail enrichment, metrics, etc.).

| Action | Args | When |
| --- | --- | --- |
| `wp_mcp_ai_memory_auto_captured` | `$context_id, $sha256, $source` | After a fresh record is persisted. |
| `wp_mcp_ai_memory_auto_capture_deduped` | `$sha256, $source` | When an observation collides with a record already inside the dedup window. |

`$source` is one of `tool_execution`, `chat_request`, or a custom label when
calling `capture()` directly.

---

## Envelope shape

Auto-captures are persisted via the existing
`WP_MCP_AI_Memory_Capture_Service::store()` method with this envelope:

```php
array(
    'agent_id'      => 'user_42',                   // or int assistant ID
    'wing'          => 'auto',                      // filterable
    'room'          => 'unscoped',                  // filterable
    'tier'          => 'recall',                    // subject to Phase 5 decay
    'context_type'  => 'observation',
    'importance'    => 0.3,                         // filterable
    'content'       => '<redacted, normalised>',    // redacted BEFORE hash
    'source'        => 'auto_capture:tool_execution',
    'verbatim'      => false,
    'auto_captured' => true,                        // Phase 2 CCT field
    'content_hash'  => '<sha256 hex>',              // Phase 2 CCT field
    'tags'          => array( 'auto-capture', 'source:tool_execution' ),
    'metadata'      => array( 'tool_slug' => 'create_post', ... ),
)
```

`auto_captured` and `content_hash` are written into the Phase 2 CCT schema
fields of the same name. `tier = recall` means the record is eligible for
Phase 5 decay (Phase 5 promotes high-importance observations to `core` and
demotes stale ones to `archival`).

---

## Scope (wing / room) notes

Auto-captures are **unscoped** by default — every record lands in
`wing = "auto", room = "unscoped"`. This is intentional:

- There is no user-supplied scope when an observation is harvested from a
  hook.
- A dedicated `auto` wing makes it trivial to filter auto-captures out of
  recall queries when desired (`agent_recall_context` with
  `wing != "auto"`).
- The Phase 7a Memory Health UI will introduce admin-defined scope rules
  (e.g. "route tool slug X into wing Y/Z"). Until that ships, use the
  `wp_mcp_ai_memory_auto_capture_wing` / `_room` filters to customise the
  scope per call site.

---

## How to enable for production

Auto-capture is safe to enable **only after** the following are in place.
Treat this as a checklist:

1. **Phase 5 decay tuning has been validated for your retention policy.**
   - Confirm `wp_mcp_ai_memory_decay_*` filters match your desired
     half-life and floor.
   - Run the decay sweep dry-run for at least one full retention cycle in
     staging and verify the recall-tier population stays bounded.
2. **CCT schema v2 migration has run** (Phase 2). Check
   `get_option( 'wp_mcp_ai_memory_cct_schema_version' )` returns at least
   `2`. The `auto_captured` and `content_hash` fields are required for
   downstream contradiction detection.
3. **Privacy filter is enabled** (Phase 1, default). Confirm
   `apply_filters( 'wp_mcp_ai_memory_privacy_filter_enabled', true )` is
   not overridden. Redaction MUST run before hashing — otherwise embedded
   secrets become part of the dedup key and identical-prose duplicates
   slip through.
4. **Per-user opt-out works.** Verify that setting
   `update_user_meta( $uid, 'wp_mcp_ai_chat_memory_enabled', 0 )` stops
   captures for that user.
5. **Denylist covers every retrieval tool your deployment uses.** If you
   have custom memory-touching tools, append them to the denylist via
   `wp_mcp_ai_memory_auto_capture_tool_denylist`.
6. **Dedup window matches your workload.** 5 minutes is a conservative
   default. Conversational workloads with high prompt repetition benefit
   from widening to 30–60 minutes.
7. **Storage capacity headroom is monitored.** Auto-capture is observable
   via the Phase 6 provenance tracer (`trace_memory_provenance` with
   `source = auto_capture:*`). Set up alerts on auto-capture growth rate
   in your Memory Health dashboard (Phase 7a).

Once all seven boxes are checked:

```php
add_filter( 'wp_mcp_ai_memory_auto_capture_enabled', '__return_true' );
```

Roll out gradually — start with the allowlist limited to one or two
high-signal tools, monitor for a week, then widen.

---

## Test coverage

See `tests/test-memory-auto-capture-service.php`. The suite exercises:

- Default-off contract (no hooks registered).
- Bootstrap idempotence.
- Master kill-switch.
- Denylist / allowlist gates.
- SHA-256 dedup within window, plus expiry-allows-recapture.
- Redaction-before-hash (secrets in content do not contaminate dedup key).
- Guest gate (off by default; opt-in via filter).
- Per-user meta opt-out and site-wide filter kill-switch.
- Envelope shape (`auto_captured`, `importance`, `tier`, `content_hash`).
- `wp_mcp_ai_memory_auto_captured` and
  `wp_mcp_ai_memory_auto_capture_deduped` action firing.
- Chat-request capture extracts the latest user message.

All fake secret fixtures are constructed at runtime via `str_repeat()` and
string concatenation to avoid GitHub Secret Scanning false positives — see
`fake_openai_key()` in the test file.
