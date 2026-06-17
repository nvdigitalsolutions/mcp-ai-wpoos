# Confidence Decay + Contradiction Detection

> **Status:** Phase 5 of the Memory Layer 2026 Enhancements — shipped in NV oOS 1.1.20.
> **Prerequisites:** Phase 2 CCT schema v2 (see [`cct-schema-v2.md`](cct-schema-v2.md)).
> **Files touched:**
> - `includes/services/class-wp-mcp-ai-memory-tier-manager.php` (extended)
> - `includes/services/class-wp-mcp-ai-memory-contradiction-detector.php` (new)
> - `includes/tools/class-wp-mcp-ai-tool-store-agent-context.php` (additive integration)

---

## Overview

Phase 5 layers two strictly-additive behaviours on top of the existing memory
lifecycle:

| Subsystem | Default | Reversible? | Mutates data? |
|---|---|---|---|
| Confidence decay sweep | **ON** | filter kill-switch | yes (`confidence_score`) |
| Strengthen on access | exposed helper (called by Phase 4 RRF) | n/a | yes (`confidence_score`, `last_accessed_at`) |
| Contradiction detection | **ON** | filter kill-switch | no — detection emits events only |
| Auto-supersession | **OFF** | filter opt-in | yes (`superseded_by`, `memory_tier → archival`) |

Decay and detection both consume the Phase 2 schema fields (`confidence_score`,
`last_accessed_at`, `superseded_by`) and tolerate legacy rows without those
fields using the documented Phase 2 fallbacks.

---

## 1. Confidence decay

### Formula

Phase 5 implements an Ebbinghaus-inspired exponential decay curve:

```text
days        = ( now - last_accessed_at ) / 86400
factor      = exp( -days * ln(2) / half_life_days )
new_score   = max( floor, base * factor )
```

`base` is the row's current `confidence_score`, defaulting to `1.0` for legacy
rows (Phase 2 fallback). `floor` defaults to `0.1`; `half_life_days` defaults
to `30`.

### Worked examples

With default `half_life_days = 30` and `floor = 0.1`, starting from
`base = 1.0`:

| Days since access | `factor` | `new_score` |
|---:|---:|---:|
| 0 | 1.000 | 1.000 |
| 1 | 0.977 | 0.977 |
| 7 | 0.851 | 0.851 |
| 30 (one half-life) | 0.500 | 0.500 |
| 60 (two half-lives) | 0.250 | 0.250 |
| 90 | 0.125 | 0.125 |
| 180 | 0.016 | **0.100** (floored) |
| 365 | 2.4 × 10⁻⁴ | **0.100** (floored) |

### Algorithm steps (per row)

1. Resolve `base` confidence — read `confidence_score` from the row; if empty
   (legacy row), default to `1.0`.
2. Resolve `last_accessed_at` — fall back to `stored_at`, then
   `transaction_time`, then "now" if all are missing or malformed.
3. Compute `days_since` and apply the curve above.
4. If `|base − new_score| ≤ 0.001` (`DECAY_WRITE_EPSILON`), skip the write —
   prevents a daily cron from churning rows that are already at the floor.
5. Otherwise, update the CCT row's `confidence_score` and emit
   `wp_mcp_ai_memory_decayed( $context_id, $old, $new )`.

### Performance bounds

- Batches of `wp_mcp_ai_memory_decay_batch_size` rows (default **100**) — keeps
  any one PHP request well under WordPress's typical 60-second cron budget.
- Hard cap of `wp_mcp_ai_memory_decay_max_per_sweep` rows per sweep (default
  **1000**) — daily cron stays under ~30s on typical sites even when the
  corpus exceeds 10,000 rows.
- The skip-when-floored rule keeps long-tail rows from contributing to the
  per-sweep budget after they've stabilised.

---

## 2. Strengthen on access

The inverse of decay. `WP_MCP_AI_Memory_Tier_Manager::strengthen_on_access()`
is a public static helper that Phase 4's RRF fusion service calls whenever a
record is returned in a result set. The contract:

```php
$new = WP_MCP_AI_Memory_Tier_Manager::strengthen_on_access(
    $context_id,
    $current_confidence = null  // optional pre-fetched value
);
// → float new confidence on success
// → false when JetEngine is unavailable (event still fires for audit listeners)
```

Behaviour:

1. Reads the current `confidence_score` from the CCT row (or accepts a
   caller-supplied value to save one read).
2. Computes `new = min( 1.0, current + apply_filters(
   'wp_mcp_ai_memory_access_strengthen', 0.05 ) )`.
3. Emits `wp_mcp_ai_memory_strengthened( $context_id, $old, $new, $timestamp )`.
4. Writes `{ confidence_score: $new, last_accessed_at: now() }` back to the
   CCT row via JetEngine's `update_item()`.

The Phase 4 RRF fusion service invokes this helper on every result row it
returns, so frequently-recalled memories resist decay even as the daily sweep
runs against them. Phase 5 does **not** call the helper itself — it only
exposes the API.

---

## 3. Contradiction detection

### What it does

For every newly-stored memory record (post-privacy-filter, post-transform,
pre-persistence), the detector asks: "is there an existing record that says
something different about the same thing?" When yes, it emits
`wp_mcp_ai_memory_contradiction_detected( $existing_id, $new_id, $reason )`
without mutating any data.

### Detection signal — top-K only

1. Resolve top-K candidates from the most relevant retrieval path available:
   - **Preferred:** Phase 4 RRF fusion (`WP_MCP_AI_Memory_RRF_Fusion_Service`)
     when loaded.
   - **Fallback:** the `wp_mcp_ai_recall_memory_candidates` filter pool, which
     already mirrors CCT rows through `WP_MCP_AI_Agent_Memory_CCT_Reader`.
   - **Override:** the `wp_mcp_ai_memory_contradiction_candidates` filter lets
     callers (and tests) substitute their own pre-scored candidate list.
2. For each candidate, resolve a similarity score in `[0, 1]`:
   - Use the candidate's `similarity` / `final` / `similarity_score` /
     `rrf_score` key when present (Phase 4 emits one of these).
   - Otherwise, fall back to **token-level Jaccard** over normalised content
     so detection still runs portably without an embeddings pipeline.
3. Drop candidates whose similarity is `≤
   wp_mcp_ai_memory_contradiction_similarity_threshold` (default `0.85`).
4. For survivors, classify the conflict:
   - **Key/value conflict:** same `metadata.key`, different `metadata.value`.
   - **Title-match + content diverges:** identical title (case-insensitive)
     AND content-token Jaccard `<
     wp_mcp_ai_memory_contradiction_jaccard_threshold` (default `0.4`).
5. Emit the `detected` event once per qualifying pair.

### Why off-by-default for auto-supersession

Supersession **demotes** the older record to `archival` and sets its
`superseded_by` pointer to the new record's `context_id`. Demoted records are
not deleted (verbatim discipline preserves them), but they leave the active
retrieval pool — so a wrong supersession decision silently hides a still-
useful memory until an operator finds it through the archive.

That makes supersession **data-destruction-adjacent**:

- The decision quality is bounded by the similarity heuristic. With a
  miscalibrated threshold or a sparse vector index, two records about
  unrelated topics can score above 0.85.
- Reversal requires manual operator action: clearing `superseded_by` and
  promoting the row back from `archival`.
- The downstream effect is invisible to the chat surface (the demoted record
  simply stops appearing in recall) so the failure mode is detect-late.

Phase 7a's Memory Health UI will surface every `detected` event and let an
operator approve or reject each supersession. **Until that UI ships,
auto-supersession stays off.** The off-by-default switch is deliberate and
should remain off in production until the manual resolution path exists.

When you do enable it, the detector also fires
`wp_mcp_ai_memory_contradiction_resolved( $existing_id, $new_id )` after each
mutation so audit listeners can record the change.

---

## 4. Integration in `store_agent_context`

Located in `class-wp-mcp-ai-tool-store-agent-context.php`, the detector hook
runs **after** the privacy filter + the
`wp_mcp_ai_memory_pre_store_transform` filter and **before** the transient
write. Order matters: detection must see the same content that will be
persisted (post-redaction, post-transform), not the raw input.

```php
if ( (bool) apply_filters( 'wp_mcp_ai_memory_contradiction_detection_on_store', true )
    && class_exists( 'WP_MCP_AI_Memory_Contradiction_Detector' )
) {
    try {
        WP_MCP_AI_Memory_Contradiction_Detector::get_instance()->detect(
            array_merge(
                $context_data,
                array(
                    'context_id' => $context_id,
                    'agent_id'   => $agent_id,
                    'wing'       => $wing,
                    'room'       => $room,
                )
            )
        );
    } catch ( Throwable $e ) {
        // Detector failures are logged but NEVER block the store.
    }
}
```

The `try/catch` is load-bearing. The detector calls third-party-extensible
filter chains (RRF service, candidate filter) and any one of those can throw.
A detector-side fatal must never prevent a user from saving a memory.

---

## 5. Integration with Phase 4 RRF

When Phase 4 ships, `WP_MCP_AI_Memory_RRF_Fusion_Service` consumes
`confidence_score` directly as part of its retrieval ranking. The pipeline:

```text
store_agent_context
   → privacy filter (Phase 1)
   → pre-store transform
   → contradiction detector (Phase 5, detection only)
   → CCT bridge writes (Phase 2 schema)
        ↓
RRF fusion (Phase 4) reads back
   - confidence_score (Phase 5 decay-aware)
   - last_accessed_at (Phase 5 strengthen-aware)
   - superseded_by (Phase 5 contradiction-aware — filters out superseded rows)
   → strengthen_on_access() on every returned result (Phase 5)
```

The detector does NOT call into RRF synchronously to compute embeddings — it
re-uses RRF's existing candidate pool (cached and indexed) so detection
overhead per write stays within the per-row cost RRF already amortises.

---

## 6. Integration with Phase 7a Memory Health UI

Phase 7a surfaces three diagnostics derived from this phase:

1. **Decay distribution** — histogram of `confidence_score` across the
   corpus, computed from CCT rows. Lets operators spot a misconfigured
   half-life that's collapsing the entire store to the floor.
2. **Pending contradictions** — `wp_mcp_ai_memory_contradiction_detected`
   events are buffered to a recent-activity log. The UI displays them with
   "approve supersession" / "dismiss" actions per row.
3. **Recent decay sweeps** — last N sweep summaries (`sweep_completed`
   action's `decayed` count) so operators can see whether the daily cron is
   firing.

---

## 7. Filter surface (full)

### Decay

| Filter | Type | Default | Purpose |
|---|---|---|---|
| `wp_mcp_ai_memory_decay_enabled` | bool | `true` | Master kill-switch — when `false`, `decay_sweep()` is a no-op but the existing tier sweep still runs. |
| `wp_mcp_ai_memory_decay_half_life_days` | int | `30` | Days for `confidence_score` to halve. |
| `wp_mcp_ai_memory_decay_floor` | float | `0.1` | Minimum confidence value. |
| `wp_mcp_ai_memory_access_strengthen` | float | `0.05` | Additive bump per `strengthen_on_access()` call. |
| `wp_mcp_ai_memory_decay_batch_size` | int | `100` | Rows per inner chunk. |
| `wp_mcp_ai_memory_decay_max_per_sweep` | int | `1000` | Hard upper bound per sweep. |
| `wp_mcp_ai_memory_decay_candidates` | array | from tier-manager pool | Candidate-pool override hook. |

### Contradiction detector

| Filter | Type | Default | Purpose |
|---|---|---|---|
| `wp_mcp_ai_memory_contradiction_detection_enabled` | bool | `true` | Master kill-switch. |
| `wp_mcp_ai_memory_contradiction_detection_on_store` | bool | `true` | Whether `store_agent_context` invokes the detector. |
| `wp_mcp_ai_memory_contradiction_top_k` | int | `3` | Max candidates examined per detection pass. |
| `wp_mcp_ai_memory_contradiction_similarity_threshold` | float | `0.85` | Candidates ≤ threshold are skipped. |
| `wp_mcp_ai_memory_contradiction_jaccard_threshold` | float | `0.4` | Title-match conflict threshold. |
| `wp_mcp_ai_memory_contradiction_auto_supersede` | bool | **`false`** | Opt-in mutation of `superseded_by` + `memory_tier`. |
| `wp_mcp_ai_memory_contradiction_candidates` | array | from RRF / recall pool | Candidate-pool override hook. |

### Example: extending the half-life on a research deployment

```php
add_filter(
    'wp_mcp_ai_memory_decay_half_life_days',
    static function () {
        return 365; // Research notes stay fresh for a year.
    }
);
```

### Example: enabling auto-supersession in a controlled test deployment

```php
// Only after the operator has manually confirmed the threshold + Jaccard
// settings on a representative corpus.
add_filter( 'wp_mcp_ai_memory_contradiction_auto_supersede', '__return_true' );
```

---

## 8. Events emitted

| Action | Args | When |
|---|---|---|
| `wp_mcp_ai_memory_decayed` | `( string $context_id, float $old, float $new )` | Once per row whose `confidence_score` changed by more than `0.001` during a decay sweep. |
| `wp_mcp_ai_memory_strengthened` | `( string $context_id, float $old, float $new, string $timestamp )` | Once per `strengthen_on_access()` call, before the CCT write. |
| `wp_mcp_ai_memory_contradiction_detected` | `( string $existing_id, string $new_id, string $reason )` | Once per detected contradiction pair. `$reason` is `key_value_conflict` or `title_match_content_diverges`. |
| `wp_mcp_ai_memory_contradiction_resolved` | `( string $existing_id, string $new_id )` | Only when auto-supersession is enabled AND the detector applied the mutation. |

---

## 9. Tuning checklist for production rollout

Different deployments have wildly different "how stale is too stale" profiles.
Start with these baselines and adjust after observing one full decay cycle:

| Use case | Recommended `half_life_days` | Notes |
|---|---:|---|
| **Customer support assistant** | 90 | Customer preferences and SLAs shouldn't churn; quarterly tax / pricing rules are the working horizon. |
| **Dev / coding assistant** | 7 | Branch-specific notes go stale fast; PR-scoped facts shouldn't outlive the PR. |
| **Knowledge research assistant** | 365 | Long-form research findings should survive a full project cycle. |
| **Personal productivity / journaling** | 30 (default) | Sane default — adjust upward only if recall feels "thin". |
| **Compliance / audit log assistants** | ∞ (disable decay) | Audit memories must never decay — set `wp_mcp_ai_memory_decay_enabled` to `false` per-wing. |

Other knobs:

- **Floor.** Raise the floor to `0.3` if you want stale memories to still
  appear in RRF results (the higher the floor, the less aggressive decay's
  effect on ranking).
- **Strengthen.** A 0.05 bump per access works well when accesses are
  reasonably common. For low-traffic agents (a handful of recalls per day),
  consider `0.10`.
- **Top-K.** Lower `top_k` to `1` on very small memory stores (< 100 rows) —
  the detector's job is easier when there's less to scan.
- **Similarity threshold.** Calibrate by running detection in shadow mode
  (auto-supersede off) for a week and reviewing the surfaced contradictions
  in Phase 7a's Memory Health UI. A high false-positive rate means the
  threshold is too low; raise it before flipping auto-supersession on.

---

## 10. References

- Ebbinghaus, H. *Memory: A Contribution to Experimental Psychology* (1885) —
  the original exponential forgetting curve.
- [`rohitg00/agentmemory`](https://github.com/rohitg00/agentmemory) —
  contemporary contradiction-detection pattern.
- [`docs/AGENT-MEMORY-COMPLETE-GUIDE.md`](../../AGENT-MEMORY-COMPLETE-GUIDE.md) —
  the broader memory-layer architecture this phase plugs into.
- [`cct-schema-v2.md`](cct-schema-v2.md) — Phase 2 schema additions
  consumed by this phase.
