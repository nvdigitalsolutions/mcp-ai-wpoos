# Implementation Plan — Proposal 091 (Dify Parity Gap-Fill, Tracks A–E)

Companion to [`091-dify-parity-gap-fill-proposal.md`](./091-dify-parity-gap-fill-proposal.md). Tracks are independent within their phases and can be staffed in parallel. All tracks must pass the house gates: phpcs (both standards), PHPUnit (Docker runner, WP 6.9 / PHP 8.2), JS typecheck/build where touched, and the canonical-envelope / two-gate sanitisation rules for anything tool- or REST-facing.

Gantt (weeks): Track A 0–3 · Track B 3–5 · Track C 5–8 · Track D 8–9 · Track E 9–10.

---

## Track A (Phase 1, weeks 0–3) — Workflow DSL export/import

### A.0 Kickoff audit (day 0)

The base plugin already ships raw JSON export/import: `includes/rest/class-wp-mcp-ai-rest-workflow-cpt-controller.php` (`POST /mcp-ai/v1/orchestration/workflows/{id}/export`, `POST /import`, `mcp_ai_workflow` CPT, `manage_options` + nonce) over the Engine V2 vocabulary (`tool|action|agent` nodes, `includes/class-wp-mcp-ai-workflow-engine-v2.php`). Auditors confirm the exact export/import payload shape and which node-param keys can carry credentials, then build the DSL upgrade against it. **Backward-compatibility gate:** pre-upgrade exports must still import after the upgrade.

### A.1 DSL codec (base — new)

| File | Change |
|---|---|
| `includes/class-wp-mcp-ai-workflow-dsl-codec.php` | **New (base, beside the Engine V2 class).** `DSL_SCHEMA_VERSION` constant (`1`); `encode_workflow( array $workflow ): string` → DSL v1 **JSON** envelope `{ schema_version, meta, variables, nodes, edges }` over the Engine V2 node shape (optional cosmetic YAML wrapper if a bundled emitter exists — D1); `decode_workflow( string $dsl, bool $dry_run ): array|WP_Error`; `validate_workflow( array $workflow ): array` → `{ path, message, severity }` findings (errors block, warnings do not); `redact_credentials( array $workflow ): array` → masks credential-bearing node params (keys matching `*_credential|*_api_key|*_token`) with `‹redacted›` **by default** on export; `accept_legacy_json( array $json ): array|WP_Error` → the pre-upgrade JSON shape normalised into DSL v1 (backward-compat path). Canonical-form normalisation so export→import is idempotent; error paths return `WP_Error` with `status` codes (never partial writes). |

D1 approved 2026-10-27 — canonical format is the vendor-neutral NV oOS DSL; JSON is the wire format (no new YAML dependency).

### A.2 Dify import adapter (Pro — D1 approved)

| File | Change |
|---|---|
| `addons/pro/includes/class-wp-mcp-ai-pro-workflow-dify-adapter.php` | **New.** Best-effort `dify_to_canonical( string $dify_yaml ): array|WP_Error` mapping Dify DSL nodes (llm, knowledge-retrieval, code, if-else, iteration, http-request, tools) onto Engine V2 node types; unmappable nodes become a `foreign` stub node carrying the original YAML inline; every mapping shortfall is reported in the dry-run findings (honest degradation, no silent drops). One-way only — no write-to-Dify (D1). |

### A.3 REST surface (base upgrade)

| File | Change |
|---|---|
| `includes/rest/class-wp-mcp-ai-rest-workflow-cpt-controller.php` | **Upgrade the existing export/import routes to DSL v1.** `export` returns the DSL YAML (JSON via `?format=json`) with redaction applied; `import` accepts DSL v1 **and** the legacy JSON shape (via `accept_legacy_json()`), gains `dry_run` (validation report + canonical preview, no write) and `source=dify` delegation to the Pro adapter when present; findings returned in the canonical envelope. Existing route paths and capabilities unchanged. |
| `addons/pro/includes/class-wp-mcp-ai-pro-module-registry.php` | Register the Dify adapter bridge (`source=dify` hook) next to the workflow bridge entries (`services/class-wp-mcp-ai-pro-workflow-bridge.php`); no new Pro REST controller needed (base routes carry the DSL). |

### A.4 Builder UI — Pro SPA (primary) + base admin page

| File | Change |
|---|---|
| `addons/pro/assets/spa-v2/src/features/workflows/WorkflowsPage.tsx` | Export / Import buttons per workflow; import modal (paste/upload) with a **dry-run-first** validation-report panel rendering the findings table; confirm-to-import; credential-redaction notice on export; `source=dify` toggle when the Pro adapter is active. |
| `addons/pro/assets/spa-v2/src/features/workflows/workflow-graph.ts` | DSL types + a `validate_dsl_findings` normaliser (pure, unit-testable) for the report panel. |
| `addons/pro/assets/spa-v2/src/features/workflows/__tests__/` | Extend the workflow test set: export button wires the DSL payload; dry-run report renders error vs warning rows; import confirm flow (vitest). |
| `includes/admin/class-wp-mcp-ai-admin-dag-builder.php` | Parity Export/Import buttons + dry-run findings panel on the base admin DAG builder page (same REST contract). |
| WP-CLI command file (`wp mcp-ai workflow` commands — confirm exact file) | `wp mcp-ai workflow export <id|slug> [--format=json|yaml] [--include-credentials]`, `wp mcp-ai workflow import <file> [--dry-run] [--source=dify]`, `wp mcp-ai workflow validate <file>`. |

### A.5 Tests + validation

| File | Change |
|---|---|
| `tests/test-workflow-dsl.php` | **New (base).** Round-trip fidelity across the bundled presets + Engine V2 fixtures; redaction masks tokens and `--include-credentials` restores them; one-error fixture returns an actionable finding and blocks import; dry-run never writes; **legacy JSON still imports** (backward-compat gate); capability/nonce rejection; JSON mode parity. |
| `addons/pro/tests/test-pro-workflow-dify-adapter.php` | **New.** Sample Dify DSL fixtures → expected Engine V2 maps; unmappable-node stub + finding. |

Validation: phpcs 0 errors; base + Pro PHPUnit via the Docker one-off runner; spa-v2 tsc + vitest; manual smoke — export a preset from the Pro SPA, delete it, import the file, run the workflow unchanged.

---

## Track B (Phase 2, weeks 3–5) — Prompt versioning lite

### B.1 Version store (base plugin — assistants are core)

| File | Change |
|---|---|
| `includes/assistants/class-wp-mcp-ai-prompt-versions.php` | **New.** Snapshot-on-save hook (`save_post_mcp_ai_assistant` → snapshot the system prompt + instruction-relevant meta, skipping if unchanged); immutable snapshots stored as post meta `_wp_mcp_ai_prompt_versions` (list capped at 50, oldest dropped); API: `snapshot( $assistant_id )`, `list_versions( $assistant_id )`, `get_version( $assistant_id, $v )`, `restore( $assistant_id, $v )`, `promote( $assistant_id, $v )` (sets the `production` label — the only way it moves), `get_production( $assistant_id )`. Hooks: `wp_mcp_ai_prompt_version_created`, `wp_mcp_ai_prompt_version_restored`. Restore writes the snapshot bytes back exactly (no re-sanitisation drift). |

### B.2 Surfaces

| File | Change |
|---|---|
| Assistant REST controller (`includes/rest/` — the controller that serves `mcp-ai/v1/assistants`, confirm exact file) | Add `GET /mcp-ai/v1/assistants/{id}/prompt-versions`, `POST .../{id}/prompt-versions/{v}/restore`, `POST .../{id}/prompt-versions/{v}/promote` — object-level capability checks, canonical envelope. |
| Assistant admin edit screen (admin/assistants page — confirm exact file) | Version dropdown (list + who/when), Restore and Promote buttons, `production` badge on the active version. |
| Pro assistant Build/Test admin pages (`addons/pro/includes/admin/` — confirm exact files) | **Pro.** The same version picker, Restore/Promote controls and `production` badge on the Pro assistant surfaces (users stay in Pro). |
| Pro SPA assistant config surface (`addons/pro/assets/spa-v2/src/` — confirm exact path, only if prompt editing exists there) | **Pro.** Same controls in the SPA assistant settings; skip if the SPA has no prompt editor. |
| WP-CLI assistant commands | `wp mcp-ai assistant prompt-versions list|restore|promote <assistant_id> [--version=N]`. |

### B.3 Tests + validation

| File | Change |
|---|---|
| `tests/test-prompt-versions.php` | **New.** Snapshot-on-save (and skip-when-unchanged); restore is bit-exact; promotion moves the label exclusively; 50-version cap eviction; capability checks on REST. |

Explicitly out of scope (documented in the proposal): A/B harness, CI prompt evaluation, prompt variable interpolation UI.

---

## Track C (Phase 2, weeks 5–8) — RAG upgrades (standalone `plugins/nvoos-content-graph-ai`)

### C.1 Chunking strategies

| File | Change |
|---|---|
| `plugins/nvoos-content-graph-ai/src/Embeddings/ChunkingStrategy.php` | **New.** `fixed( $text, $size, $overlap )`, `recursive( $text, $size, $overlap )` (heading/paragraph-aware splits, context-preserving), shared `chunk_document( $text, array $settings ): array` dispatcher. Defaults: size 800 tokens-equivalent, overlap 15%; settings-driven (see C.4). |
| `plugins/nvoos-content-graph-ai/src/Embeddings/EmbeddingService.php` | Route indexing through the chunker (currently single-shot embeddings); keep the single-shot path for non-RAG embeddings (`create_text_embeddings` tool). Chunk-level provenance (doc id + chunk index) stored with each embedding row so hit-tests can attribute correctly. |

### C.2 Hybrid retrieval + RRF

| File | Change |
|---|---|
| `plugins/nvoos-content-graph-ai/src/Embeddings/RagRetriever.php` | Add hybrid mode: keyword candidates (existing keyword-search fallback path) + vector candidates run in parallel → **RRF fusion, k=60** → fused ranking. Vector-only and keyword-only modes remain (degraded modes when one index is unavailable — the existing `graph_context_mode` behaviour is preserved). |
| `plugins/nvoos-content-graph-ai/src/Embeddings/Reranker.php` | **New.** Default: score-based rerank over the fused top-50 (normalized cosine + keyword-score blend — the documented local approximation of a cross-encoder; no external API; D2 approved). Optional seam `wp_mcp_ai_rerank_provider` for an opt-in external rerank client (settings-gated, SSRF-guarded, off by default). |

### C.3 Hit-test runner

| File | Change |
|---|---|
| `plugins/nvoos-content-graph-ai/src/Embeddings/HitTestRunner.php` | **New.** Golden-set format (JSONL: `{ query, gold_chunk_ids[] }`); runs the retriever alone (no LLM); reports RAGAS-style `context_precision`, `context_recall`, `MRR`, `hit@k` per query + summary; outputs a table + JSON. Pure retrieval — no generation costs. |
| `plugins/nvoos-content-graph-ai/src/Cli/GraphCommand.php` | `wp cg-ai rag hit-test --golden=<file> [--k=5] [--json]`; must run in standalone mode (no monolith assumptions). |

### C.4 Settings

| File | Change |
|---|---|
| CG AI settings page (confirm exact file — the plugin's settings shell) | Chunk strategy / size / overlap; hybrid on/off; rerank provider (default: local). All external options default off. |
| `addons/pro/includes/tools/jev/class-wp-mcp-ai-pro-tool-typesafe-rerank.php` | **Pro alignment.** New `strategy: local|provider` argument (default `local`) wiring the shared local reranker from `nvoos-content-graph-ai` when present; `provider` keeps the existing external path. Update usage guidance + related-tools notes. |
| Pro settings page (confirm exact file) | **Pro.** Chunk/rerank settings section mirroring the CG AI settings (external options default off). |
| `addons/pro/assets/spa-v2/src/` console (confirm exact path) | **Pro.** Hit-test report rendering (summary table + per-query findings) for golden sets run from the console. |

### C.5 Tests + validation

| File | Change |
|---|---|
| `plugins/nvoos-content-graph-ai/tests/...` (its own PHPUnit matrix) | Chunking units (fixed/recursive overlap boundaries); RRF determinism fixture; rerank ordering fixture; hit-test report fixture (golden set with known precision/recall); standalone-mode CLI smoke. |

Validation gate: hit-test before/after on a 20-query golden set — target ≥ +15% `context_precision` at equal `context_recall` (proposal success metric 4).

---

## Track D (Phase 3, weeks 8–9) — Ordered fallback chains

### D.1 Framework core (`lib/core`)

| File | Change |
|---|---|
| `lib/core/src/Application/Provider/ProviderRouter.php` | Add ordered-chain support: `set_fallback_chain( array $provider_slugs )`, `cooldown( $slug, $ttl )` / `is_cooled_down( $slug )`, and `Retry-After` header parsing in the health tracker. Semantics: on failure → mark cooldown → next entry in the chain; a cooled-down provider is skipped until the TTL expires. Health-based failover behaviour is unchanged when no chain is configured. |
| `lib/core/tests/Unit/Application/Provider/ProviderRouterTest.php` | Chain order respected on failure; cooldown TTL blocks retries and expires; Retry-After honoured; no chain → existing behaviour. |

### D.2 WordPress bridge + config

| File | Change |
|---|---|
| `includes/bridge/` (the WordPress adapter that composes the framework router — confirm exact file) | Map assistant meta `wp_mcp_ai_fallback_chain` (ordered provider slugs) → `set_fallback_chain()` at chat-composition time; global default setting `wp_mcp_ai_default_fallback_chain` used when assistant meta is empty (D3 approved). |
| Assistant settings surface (assistant edit screen / settings page) | Fallback-chain picker (ordered multi-select of enabled providers) + global default on the settings page. |
| Pro settings pages (`addons/pro/includes/admin/` — confirm exact files) | **Pro.** The same ordered fallback-chain picker in Pro settings + the global default section. |
| `addons/pro/assets/spa-v2/src/` settings surface (confirm exact path) | **Pro.** Fallback-chain picker in the Pro SPA settings; provider list fed from the runtime config. |
| `addons/pro/includes/class-wp-mcp-ai-pro-spa-config.php` | **Pro.** Expose `fallbackChain` (current assistant chain) + `enabledProviders` in the SPA runtime config. |

### D.3 Legacy engine mirror

| File | Change |
|---|---|
| Legacy provider dispatcher / `WP_MCP_AI_Cascade_Executor` (confirm exact file) | Mirror the same assistant-meta chain: ordered fallback + cooldown on the legacy execution path so both engines behave identically. `CascadeRouter` itself stays untouched. |

### D.4 Tests + validation

| File | Change |
|---|---|
| `tests/test-fallback-chains.php` | **New.** Meta → router mapping; synthetic provider-outage drill (mock failing client): chain engages in order, cooled-down provider skipped, zero failed turns once engaged; global default used when meta empty; capability-gated settings. |

Validation: drill tests must cover both engines (framework + legacy).

---

## Track E (Phase 3, weeks 9–10) — Span + annotation coverage

### E.1 GenAI span events

| File | Change |
|---|---|
| `includes/class-wp-mcp-ai-genai-span-emitter.php` | **New (base plugin).** Emits structured span events using OTel GenAI semantic-convention attribute names (`gen_ai.operation.name`, `gen_ai.request.model`, `gen_ai.usage.input_tokens`/`output_tokens`, `gen_ai.response.finish_reasons`, plus `nvoos.*` extension attributes) on the existing observability rails — audit-log events + a `wp_mcp_ai_genai_span` action hook that any consumer (including the OTLP exporter) can subscribe to. Zero cost when no subscriber: no-op early return. |
| Chat/tool call sites (chat orchestrator + tool executor — the same boundaries the cost tracker already observes, confirm exact hooks) | Emit `chat` / `execute_tool` spans around existing measured boundaries; reuse the cost tracker's token figures (single source of truth). |

### E.2 OTLP exporter (opt-in, D4)

| File | Change |
|---|---|
| `includes/observability/class-wp-mcp-ai-otlp-exporter.php` | **New.** Opt-in HTTP OTLP trace exporter (D4 approved): `wp_mcp_ai_otlp_endpoint` setting (default empty = exporter disabled); SSRF-guarded (scheme allowlist + the URL guard's host validation); batches span events and flushes on shutdown with a cap; headers per the OTLP/HTTP spec. Never active unless an endpoint is explicitly configured. |
| Settings page | OTLP endpoint field under Observability, with an explicit "disabled by default — no data leaves your site unless configured" note. |

### E.3 Annotation store

| File | Change |
|---|---|
| `includes/chat/class-wp-mcp-ai-annotation-store.php` | **New.** Captures ratings/comments from the chat surface (thumbs + optional comment) keyed by `{transcript_id, turn_index}`; stores as transcript post meta (D5 approved); REST `GET /mcp-ai/v1/annotations?assistant_id=` exports them as an eval dataset (JSONL: prompt, response, rating, comment) consumable by `run_assistant_eval`; `manage_options`-gated export. |
| Chat front-end (assets/js/chat.js — the existing rating control, confirm exact file) | Persist rating + optional comment through the store (guest chats excluded — consent/attribution rule). |
| `addons/pro/includes/admin/class-wp-mcp-ai-analytics-service-page.php` | **Pro.** Span/annotation widgets (trace summary counts + annotation totals). |
| `addons/pro/includes/rest/class-wp-mcp-ai-pro-dashboards-rest-controller.php` | **Pro.** Read-only span/annotation series endpoints for the widgets (existing capability gates; no new surface). |
| Pro eval-dataset tool (new, `addons/pro/includes/tools/` — confirm convention) | **Pro.** `export_annotation_dataset` (or an extension of the existing eval tool) producing the JSONL dataset consumable by `run_assistant_eval`. |

### E.4 Tests + validation

| File | Change |
|---|---|
| `tests/test-genai-spans.php` | **New.** Span events fire with correct `gen_ai.*` attribute names at chat + tool boundaries; no-op when no subscribers; action payload shape. |
| `tests/test-otlp-exporter.php` | **New.** Disabled by default; endpoint allowlist + SSRF rejection fixtures; batch cap. |
| `tests/test-annotation-store.php` | **New.** Store/export round-trip; guest chats excluded; dataset shape; capability gate. |

---

## Pro addon upgrade matrix (`addons/pro`) — completion checklist

Every track must land in the Pro addon before it counts as done:

| Track | `addons/pro` files touched | Gate |
|---|---|---|
| A | `assets/spa-v2/src/features/workflows/{WorkflowsPage.tsx,workflow-graph.ts}` + `__tests__/` extensions; `includes/class-wp-mcp-ai-pro-workflow-dify-adapter.php`; module-registry `source=dify` bridge | spa-v2 tsc + vitest; phpcs; Docker PHPUnit |
| B | Pro assistant Build/Test admin pages (confirm files); Pro SPA assistant config surface (only if it exists) | phpcs; Docker PHPUnit; vitest if SPA touched |
| C | `includes/tools/jev/class-wp-mcp-ai-pro-tool-typesafe-rerank.php`; Pro settings page; SPA console hit-test rendering | phpcs; Docker PHPUnit; vitest |
| D | Pro settings pages; SPA settings surface; `includes/class-wp-mcp-ai-pro-spa-config.php` | phpcs; Docker PHPUnit; vitest |
| E | `includes/admin/class-wp-mcp-ai-analytics-service-page.php`; `class-wp-mcp-ai-pro-dashboards-rest-controller.php`; new Pro eval-dataset tool | phpcs; Docker PHPUnit; vitest |

**Completion rule:** a track is NOT done until both its base/standalone work AND its `addons/pro` row above pass the house gates. `pro-spa.js` is rebuilt after any spa-v2 change.

## Cross-track rollout notes

- **No new required external calls.** The rerank provider, OTLP exporter, and Dify adapter are opt-in; the RAG default pipeline is fully local. wp.org readme `External Services` sections are unaffected unless an opt-in feature is enabled (document when any ships).
- **Base vs Pro placement.** Track B and Track E core ship in base with Pro surfaces layered on (Pro assistant pages, analytics page, dashboards REST). Track A is a base REST/codec upgrade with the primary UI in the **Pro SPA** (`features/workflows/`) plus the base admin DAG builder; the Dify adapter is Pro-only. Track D core is `lib/core` + base bridge with the config UI in **Pro settings + Pro SPA**. Track C ships in `nvoos-content-graph-ai` with **Pro alignment** (`typesafe_rerank` local strategy, Pro settings, console hit-test rendering). The Pro upgrade matrix below is the completion checklist.
- **Docs + changelog.** Each track updates `readme.txt`/`CHANGELOG.md`, the relevant `.context/*` file, and `docs/DOCUMENTATION_INDEX.md` per the versioned catch-up discipline.
- **Scorecard refresh.** After Track E lands, re-verify every axis of the Dify scorecard against Dify's current docs before the numbers are used anywhere public (compliance rule).
- **Deferred (explicit).** Sandboxed agent OS, cloud marketplace, full prompt A/B + CI eval harness, cross-encoder rerank by default, and any Dify-DSL write (export-to-Dify) support. Tracked in the proposal's open questions.
