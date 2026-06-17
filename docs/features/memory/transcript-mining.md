# Retroactive Transcript Mining

> **Status:** Shipped in NV oOS (unreleased, May 3–4 2026 sprint).  
> **Last reviewed:** May 2026 · Version: 1.x

Retroactive Transcript Mining lets site administrators extract agent memories from _existing_ chat transcripts — transcripts that were recorded before the durable memory bridge was activated, or transcripts for which the live memory-capture path was not triggered.

---

## Table of Contents

1. [Architecture overview](#architecture-overview)
2. [Background job (`WP_MCP_AI_Transcript_Mining_Job`)](#background-job)
3. [REST API](#rest-api)
4. [The `transcripts` source on `mine_agent_memory`](#the-transcripts-source)
5. [Provenance metadata](#provenance-metadata)
6. [De-duplication](#de-duplication)
7. [WordPress filters](#wordpress-filters)
8. [Admin UI](#admin-ui)
9. [PHPUnit test coverage](#phpunit-test-coverage)

---

## Architecture overview

```
Admin UI / REST call
       │
       ▼
WP_MCP_AI_Transcript_Mining_Job::enqueue()
       │  stores state in transient wp_mcp_ai_tx_mine_job_{id}
       │
       ▼ (WP-Cron: wp_mcp_ai_transcript_mining_tick)
WP_MCP_AI_Transcript_Mining_Job::handle_tick()
       │  pops up to 10 sessions per tick
       │
       ▼
mine_agent_memory  (source = transcripts)
       │  queries transcripts CCT or transient store
       │  deduplicates against existing memories
       │
       ▼
store_agent_context  (one call per extracted item)
       │
       ▼
ai_agent_memories CCT  (if JetEngine active)
```

---

## Background job

**Class:** `WP_MCP_AI_Transcript_Mining_Job`  
**File:** `includes/services/class-wp-mcp-ai-transcript-mining-job.php`

### State model

Job state is stored in a WordPress transient with the prefix `wp_mcp_ai_tx_mine_job_` and a 6-hour TTL (`STATE_TTL = 21600`). The transient holds:

```json
{
  "id": "<uuid>",
  "status": "queued | running | completed | failed | cancelled",
  "sessions": ["__auto__", "session_key_1", "session_key_2"],
  "processed": 12,
  "total": 50,
  "errors": [],
  "created_at": 1746300000,
  "updated_at": 1746300120
}
```

**Key constants:**

| Constant | Value | Description |
|----------|-------|-------------|
| `CRON_HOOK` | `wp_mcp_ai_transcript_mining_tick` | WP-Cron hook name |
| `STATE_PREFIX` | `wp_mcp_ai_tx_mine_job_` | Transient key prefix |
| `STATE_TTL` | `21600` (6h) | Transient TTL in seconds |
| `MAX_TOTAL_SESSIONS` | `500` | Hard cap on sessions per job |
| `DEFAULT_SESSIONS_PER_TICK` | `10` | Sessions processed per cron tick |

### The `__auto__` sentinel

If the session list contains the special string `__auto__`, the underlying `mine_agent_memory` tool resolves its own session set on every tick (subject to the `transcript_query` parameters passed at job creation). This is useful for broad-sweep jobs where you want the tool's own discovery logic to decide which sessions to mine, rather than enumerating them in advance.

### Job lifecycle

1. **Enqueue** — `enqueue( array $session_keys, array $transcript_query_args )` writes the initial state transient and schedules the first `wp_mcp_ai_transcript_mining_tick` event.
2. **Tick** — `handle_tick( string $job_id )` pops up to `DEFAULT_SESSIONS_PER_TICK` sessions, calls `mine_agent_memory` for each, records errors, and reschedules itself until the queue is empty.
3. **Complete** — the tick sets `status = completed` when the queue is empty.
4. **Cancel** — `cancel( string $job_id )` sets `status = cancelled` and unschedules the next tick.

---

## REST API

**Controller:** `WP_MCP_AI_REST_Transcript_Mining_Controller`  
**File:** `includes/rest/class-wp-mcp-ai-rest-transcript-mining-controller.php`  
**Namespace:** `mcp-ai/v1`  
**Required capability:** `manage_options`

### Endpoints

#### `POST /mcp-ai/v1/transcript-mining/jobs`

Enqueue a new mining job.

**Request body (JSON):**

```json
{
  "session_keys": ["__auto__"],
  "assistant_id": 42,
  "since": "2026-04-01",
  "until": "2026-05-01",
  "min_messages": 3,
  "only_unextracted": true,
  "posts_per_page": 50
}
```

- `session_keys` — explicit session key list. Include `"__auto__"` to delegate session discovery to the tool.
- All other fields are forwarded as `transcript_query` args to `mine_agent_memory`.

**Response:**

```json
{
  "id": "a1b2c3d4",
  "status": "queued",
  "sessions": ["__auto__"],
  "processed": 0,
  "total": null
}
```

#### `GET /mcp-ai/v1/transcript-mining/jobs/{id}`

Poll job progress.

**Response:**

```json
{
  "id": "a1b2c3d4",
  "status": "running",
  "processed": 20,
  "total": 50,
  "errors": []
}
```

#### `POST /mcp-ai/v1/transcript-mining/jobs/{id}/cancel`

Cancel a queued or running job.

**Response:** Same shape as the poll response, with `status = "cancelled"`.

---

## The `transcripts` source

**Tool:** `WP_MCP_AI_Tool_Mine_Agent_Memory`  
**File:** `includes/tools/class-wp-mcp-ai-tool-mine-agent-memory.php`

The `mine_agent_memory` tool accepts `source = transcripts` alongside the existing `posts`, `urls`, and `text` sources.

### `transcript_query` parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `assistant_id` | `int` | — | Filter to a specific assistant's transcripts. |
| `user_id` | `int` | — | Filter to a specific user's transcripts. |
| `since` | `string` | — | ISO 8601 date; only transcripts after this date. |
| `until` | `string` | — | ISO 8601 date; only transcripts before this date. |
| `session_keys` | `array` | — | Explicit list of session keys to process. |
| `min_messages` | `int` | `1` | Skip sessions with fewer than this many messages. |
| `only_unextracted` | `bool` | `false` | Skip sessions whose memories have already been extracted. |
| `posts_per_page` | `int` | `20` | Maximum sessions to process; hard cap is **50**. |

---

## Provenance metadata

Every extracted memory item carries provenance fields that link it back to its source transcript:

| Field | Type | Description |
|-------|------|-------------|
| `transcript_session_key` | `string` | The session key of the transcript this item was mined from. |
| `assistant_id` | `int` | The assistant post ID associated with the session. |
| `message_range` | `array` | `{ "from": int, "to": int }` — zero-indexed message indices that contributed to this item. |
| `content_hash` | `string` | SHA-256 hex of the extracted text, used for de-duplication. |

These fields are stored alongside the extracted memory in `store_agent_context` and surfaced in the **Audit** tab of the Memory Drawer.

---

## De-duplication

Before a transcript item is persisted, the tool scans the existing memory store for a matching `content_hash`. The scan window defaults to the most recent **1000** memories but is filterable:

```php
add_filter( 'wp_mcp_ai_mine_transcripts_dedupe_scan_limit', function ( $limit ) {
    return 2000; // widen the scan window
} );
```

Items with a matching hash are skipped; a `skipped_duplicate` counter is incremented in the tool result.

---

## WordPress filters

| Filter | Arguments | Description |
|--------|-----------|-------------|
| `wp_mcp_ai_mine_transcripts_sessions` | `array $sessions, array $query_args` | Mutate or replace the session list before mining begins. |
| `wp_mcp_ai_mine_transcripts_session_messages` | `array $messages, string $session_key` | Mutate the message array for a session before item extraction. |
| `wp_mcp_ai_mine_transcripts_dedupe_scan_limit` | `int $limit` | Override the de-duplication scan window (default `1000`). |

### Example — exclude specific sessions

```php
add_filter( 'wp_mcp_ai_mine_transcripts_sessions', function ( $sessions, $query_args ) {
    return array_filter( $sessions, fn( $s ) => strpos( $s, 'guest_' ) !== 0 );
}, 10, 2 );
```

---

## Admin UI

The **NV oOS → Orchestration** dashboard surfaces the Transcript Mining section when at least one job has been enqueued. It shows the five most recent jobs with their status, progress bar, session counts, and a Cancel button for running jobs.

A **Mine Transcripts** button on the dashboard opens a modal to configure and enqueue a new job without leaving the admin area.

---

## PHPUnit test coverage

| Test file | Cases | What it covers |
|-----------|-------|---------------|
| `tests/test-transcript-mining-job.php` | 12 | `enqueue`, `handle_tick`, `cancel`, `get_progress`, state TTL, `__auto__` sentinel, `MAX_TOTAL_SESSIONS` guard |
| `tests/test-rest-transcript-mining.php` | 9 | POST, GET, cancel endpoints; capability checks; invalid job ID; malformed body |
| `tests/test-mine-transcripts-source.php` | 8 | `collect_from_transcripts`, `build_transcript_items_from_messages`, de-dupe hash, provenance fields, `only_unextracted` filter |


---

## Troubleshooting

### Job stays at "queued" and never executes

Three compounding root causes were identified in PRs #4804 and #4826 (fixed in v1.1.15). If you are running an older version, check all three:

**Root cause 1 — Future cron timestamp**

`wp_schedule_single_event()` was called with a timestamp in the future (e.g. `time() + 30`), causing WordPress to defer firing the event by one full cron cycle (typically 1 minute) rather than executing it immediately.

*Fix:* The job now schedules with `time()` as the timestamp (i.e., "fire as soon as WP-Cron next runs").

**Root cause 2 — Missing `spawn_cron()` call**

After scheduling the cron event the code did not call `spawn_cron()`, so the cron hook only ran on the next natural page load. On low-traffic sites this could delay execution indefinitely.

*Fix:* `spawn_cron()` is now called immediately after `wp_schedule_single_event()` to kick off the cron runner without waiting for a page load.

**Root cause 3 — Transient key namespace collision**

The tick handler looked up the job state using a slightly different key than the enqueue handler wrote it under. The result was that `get_transient()` returned `false` and the tick handler silently skipped the job.

*Fix:* Both the enqueue and tick code paths now use the canonical `wp_mcp_ai_tx_mine_job_{$job_id}` key via the `STATE_PREFIX` constant.

### How to verify a job is running

1. Enqueue a job via `POST /mcp-ai/v1/transcript-mining/jobs`.
2. Poll `GET /mcp-ai/v1/transcript-mining/jobs/{id}` every 5 seconds.
3. The `status` field should transition `queued → running → completed` within 60–90 seconds on a standard WP-Cron setup.
4. If the job remains `queued` after 2 minutes, inspect the cron queue:
   ```bash
   wp cron event list | grep wp_mcp_ai_transcript_mining_tick
   ```
   If the hook is listed but not firing, ensure WP-Cron is active (`DISABLE_WP_CRON` is not `true` in `wp-config.php`) or configure a real system cron.

### Job completes with 0 sessions extracted

- Confirm the assistant has at least one transcript stored in the JetEngine `ai_chat_transcripts` CCT.
- Verify the `only_unextracted` flag is `false` if you want to re-extract already-processed sessions.
- Check that the `posts_per_page` parameter does not exceed 50 (the enforced maximum).
- Review the `wp_mcp_ai_mine_transcripts_sessions` filter — a third-party hook may be filtering all sessions out.
