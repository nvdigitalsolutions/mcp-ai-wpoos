# Memory RRF Fusion Retrieval (Phase 4)

> Status: Phase 4 of the 2026 Memory Layer Enhancements — shipped in NV oOS 1.1.20.

## What this is

`WP_MCP_AI_Memory_RRF_Fusion_Service` adds a **Reciprocal Rank Fusion (RRF)**
retrieval path on top of the existing cosine + MemPalace booster pipeline.

It combines three independent candidate streams — BM25 keyword relevance,
vector cosine similarity, and graph neighbourhood proximity — into a single
ranked result list using the documented formula:

```
fused_score(d) = Σ_streams 1 / ( k + rank_stream(d) + 1 )
```

After fusion the per-record `confidence_score` (Phase 2 schema v2 field) is
applied as a multiplier, and a per-session diversification cap keeps any one
chat session from saturating the result list.

This is **strictly additive**. The legacy `search_context()` method and its
`boost_breakdown` keys are preserved verbatim so existing UI (notably the
chat-memory drawer) keeps rendering. The new path is reached through:

- `WP_MCP_AI_Vector_Context_Service::search_context_rrf()` — public wrapper
  that falls through to `search_context()` when RRF is disabled.
- The `use_rrf` argument on `semantic_context_search` and `recall_memory`.

## Why three streams

The 2024-2026 hybrid-retrieval consensus (Pinecone, Weaviate, Mem0, Vespa,
the Vektor 2026 blog series, and the LlamaIndex / LangChain reference
implementations) all converge on the same conclusion: **lexical, semantic,
and structural signals fail in different places**, so combining them by rank
rather than by raw score produces dramatically more stable results than any
single retriever — without needing the score-normalisation calibration that
weighted-sum fusion requires.

- **BM25** catches exact terminology — proper nouns, identifiers, numeric
  literals, code fragments. Cosine similarity often misses these because
  the embedding space encodes meaning, not surface form.
- **Vector cosine** catches paraphrases, near-synonyms, and cross-language
  matches that BM25 simply cannot see.
- **Graph proximity** (via the optional Graphify addon) catches structural
  relevance — "memories about *this client*" or "memories linked to *this
  decision*" — which neither term matching nor embedding similarity can
  surface on its own.

RRF was chosen over weighted-sum fusion because:

1. It is calibration-free. Stream scores live in different ranges (BM25 in
   `MATCH(...) AGAINST` produces small floats, vector cosine is `[-1, 1]`,
   graph proximity is a weighted node score), so a weighted sum requires
   per-stream normalisation. RRF only uses *rank order*, which is unit-free.
2. The `k=60` empirical sweet spot is the most-replicated finding in modern
   hybrid-search literature (Cormack, Clarke & Buettcher 2009; Pinecone &
   Weaviate hybrid-search docs 2023-2026; Mem0 retrieval guide 2026).
3. Each stream can fail silently (graph stream when Graphify is absent,
   BM25 when there's no FULLTEXT index) without breaking the others.

## Worked example

Suppose we have four memory records (`A`, `B`, `C`, `D`) and three streams
return them in these orders:

| Rank | BM25 | Vector | Graph |
|---|---|---|---|
| 0 | A | B | C |
| 1 | B | A | B |
| 2 | C | D | A |

With the default `k=60`:

```
score(A) = 1/(60+1) + 1/(60+2) + 1/(60+3)  ≈ 0.0489
score(B) = 1/(60+2) + 1/(60+1) + 1/(60+2)  ≈ 0.0490
score(C) = 1/(60+3) +     —    + 1/(60+1)  ≈ 0.0323
score(D) =     —    + 1/(60+3) +     —     ≈ 0.0159
```

Final ranking: **B > A > C > D**. `B` wins because it is "second-best
everywhere", while `A` was top in two streams but missing from the graph
result set's top slots, and `D` appeared in only one stream.

If `B`'s `confidence_score` were `0.5` (e.g. a Phase 5 decay-aware record),
the final scores would become:

```
score(A) ≈ 0.0489 * 1.0 = 0.0489   ← new winner
score(B) ≈ 0.0490 * 0.5 = 0.0245
```

This is the contract: **fused rank wins among equally-confident records;
confidence decay is the tiebreaker.**

## Result shape

`search_context_rrf()` returns the same envelope as the legacy
`search_context()` with one extra key per record:

```php
array(
    'context_id'       => 'ctx_abc',
    'context_type'     => 'fact',
    'title'            => '…',
    'content'          => '…',
    // legacy fields preserved at 0.0 so chat-memory-drawer.js keeps rendering:
    'similarity_score' => 0.0,
    'boost_score'      => 0.0,
    'final_score'      => 0.0234,
    'boost_breakdown'  => array(
        'keyword'     => 0,
        'temporal'    => 0,
        'exact_match' => 0,
    ),
    // new in Phase 4:
    'rrf_breakdown'    => array(
        'bm25_rank'        => 2,       // 0-based; null when stream missed
        'vector_rank'      => 1,
        'graph_rank'       => null,
        'fused_score'      => 0.0287,  // RRF before confidence weighting
        'confidence_score' => 0.85,
        'final_score'      => 0.0244,  // fused_score * confidence_score
        'session_id'       => 'sess_xyz',
    ),
);
```

The response envelope itself carries an additional `'method' => 'rrf_hybrid'`
field so callers can distinguish RRF results from legacy ones.

## Filters

| Filter | Default | Purpose |
|---|---|---|
| `wp_mcp_ai_memory_rrf_enabled` | `true` | Master kill-switch. False disables RRF site-wide and falls through to `search_context()`. |
| `wp_mcp_ai_memory_rrf_default_enabled` | `true` | Tool-level default for `semantic_context_search` / `recall_memory`. Per-call `use_rrf` argument still overrides. |
| `wp_mcp_ai_memory_rrf_k` | `60` | RRF constant. Larger `k` flattens score differences; smaller `k` rewards top-ranked items. Industry consensus is `60`. |
| `wp_mcp_ai_memory_rrf_streams` | `['bm25', 'vector', 'graph']` | Active stream list. Drop a label to disable that stream. |
| `wp_mcp_ai_memory_rrf_candidates_per_stream` | `20` | Per-stream candidate cap. Higher = more recall but more work. |
| `wp_mcp_ai_memory_rrf_session_diversity_cap` | `3` | Max records per `session_id` in the final ranked list. |
| `wp_mcp_ai_memory_rrf_use_confidence` | `true` | When false, skip the `confidence_score` multiplier and rank purely by fused RRF score. |
| `wp_mcp_ai_memory_rrf_graph_max_depth` | `2` | BFS depth passed to the Graphify bridge (forward-compat). |
| `wp_mcp_ai_memory_rrf_cache_ttl` | `300` | Object-cache TTL for fused scores, seconds. Set to `0` to disable caching. |
| `wp_mcp_ai_memory_rrf_bm25_min_chars` | `3` | Skip BM25 stream for queries shorter than this. |
| `wp_mcp_ai_memory_rrf_cache_bypass` | `false` | Per-request override to force a recompute, useful in tests. |

### Example: tune `k` for a small candidate pool

```php
// Reward top-ranked items more aggressively on a site with few memories.
add_filter( 'wp_mcp_ai_memory_rrf_k', function () { return 20; } );
```

### Example: disable the graph stream

```php
add_filter( 'wp_mcp_ai_memory_rrf_streams', function () {
    return array( 'bm25', 'vector' );
} );
```

### Example: extend the cache TTL

```php
// Hot working set — extend the cache to 30 minutes.
add_filter( 'wp_mcp_ai_memory_rrf_cache_ttl', function () { return 1800; } );
```

## Per-call routing

Both `semantic_context_search` and `recall_memory` accept an optional
`use_rrf` argument (boolean or null):

| `use_rrf` | `wp_mcp_ai_memory_rrf_enabled` | `wp_mcp_ai_memory_rrf_default_enabled` | Result |
|---|---|---|---|
| `null` (unset) | true | true | RRF path |
| `null` | true | false | Legacy path |
| `null` | false | (any) | Legacy path |
| `true` | (any) | (any) | RRF path |
| `false` | (any) | (any) | Legacy path |

`use_rrf=true` always wins — even when the master switch is off — so
operators can probe RRF behaviour against a single tool invocation on an
otherwise-RRF-disabled site.

## Disabling RRF (rolling back to the legacy booster pipeline)

The fastest rollback is a single filter:

```php
add_filter( 'wp_mcp_ai_memory_rrf_enabled', '__return_false' );
```

After this:

- `search_context_rrf()` transparently delegates to `search_context()`.
- `semantic_context_search` and `recall_memory` use their legacy ranking
  paths even when called with no explicit `use_rrf` argument.
- No REST response shape changes — `boost_breakdown` keys are preserved
  in their original (non-zero) form because the legacy booster pipeline
  is the one running.

This is the recommended emergency rollback if the booster output is needed
for any reason (a/b comparison, debugging, perf regression). Phase 4 was
designed so this filter is the single point of control.

## Performance notes

### Expected query times

| Scenario | First call | Cached call |
|---|---:|---:|
| BM25 LIKE fallback over 100 transient records | 5-15 ms | <1 ms |
| BM25 FULLTEXT against a CCT with index (1k rows) | 2-5 ms | <1 ms |
| Vector stream, 100 records, cached embeddings | 10-30 ms | 1-3 ms |
| Vector stream, 100 records, cold embeddings | 200-2000 ms (per OpenAI/Ollama batch) | n/a |
| Graph stream via Graphify (1k nodes) | 5-15 ms | <1 ms |
| **Fused result with all three streams (warm)** | **~25-50 ms** | **~2-5 ms** |

### Cache TTL recommendations

- **300s** (default): good for chat surfaces where the same agent re-runs
  similar queries within a session.
- **0s**: disables caching. Useful in tests and CI.
- **1800s** (30 min): good for long-running session-replay or memory-health
  dashboards where the candidate set is essentially static.
- **86400s** (24h): only appropriate when memory writes are rare. The cache
  is keyed on `query + agent_id + filters` and is **not** invalidated on
  memory write — relying on TTL alone means new memories won't surface
  until the cache expires.

### When to disable a stream

- **BM25 off** (`wp_mcp_ai_memory_rrf_streams` minus `'bm25'`): on sites
  with no JetEngine CCT *and* no FULLTEXT index, the LIKE fallback can be
  the slow stream over very large transient stores (>1k records). Disable
  it if vector + graph are sufficient for your retrieval pattern.
- **Vector off**: appropriate when the embedding provider is unavailable
  or slow. RRF on BM25+graph alone is still a meaningful upgrade over pure
  keyword search.
- **Graph off**: automatic when Graphify isn't installed. Explicitly
  disable when Graphify is loaded but the per-agent graph is sparse — the
  stream will return very few candidates and add no signal.

## Tests

`tests/test-memory-rrf-fusion-service.php` — 12 cases covering:

1. Master kill-switch fall-through to legacy `search_context()`.
2. Pure RRF math against the documented formula.
3. Session diversification cap collapses same-session hits.
4. Records with no session_id are not collapsed.
5. Missing Graphify yields a silent empty graph stream.
6. BM25 LIKE fallback when the CCT is absent.
7. Confidence weighting reorders equally-fused records.
8. Legacy `boost_breakdown` keys preserved in RRF response (all zero).
9. Backward-compat: `search_context()` shape has no `rrf_breakdown` key.
10. Per-call `use_rrf=false` routes to legacy.
11. Per-call `use_rrf=true` overrides master-off via the tool's
    `resolve_use_rrf()`.
12. Cache hit and bypass behaviour.
13. BM25 short-query gate skips stream silently.

## See also

- [Phase 1 — Privacy Filter](./privacy-filter.md)
- [Phase 2 — CCT Schema v2](./cct-schema-v2.md)
- [Active feature context](../../../.context/active/memory-layer-2026.md)
- Upstream references documented in
  [`docs/AGENT-MEMORY-COMPLETE-GUIDE.md`](../../AGENT-MEMORY-COMPLETE-GUIDE.md):
  hybrid retrieval section + the booster system the legacy path is
  preserved for.
