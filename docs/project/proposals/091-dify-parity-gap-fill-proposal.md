# Proposal 091 — Dify Parity Gap-Fill: Workflow DSL, RAG Pipeline, Prompt Versioning, Fallback Chains, Span + Annotation Coverage

**Status:** ⏳ ACTIVE — decisions D1–D5 approved 2026-10-27 (implementation pending)
**Date:** 2026-10-27
**Estimated Effort:** ~55 working days (~11 weeks, parallelizable to the 10-week gantt below)
**Priority:** HIGH (Workflow DSL, RAG) / MEDIUM (Prompt versioning, Fallback chains, Span + annotation coverage)
**Related:** Internal Dify-vs-NV-oOS capability scorecard (2026-10-27); Proposal 056 (ECC harness — `CascadeRouter` + `run_assistant_eval`); `lib/core/src/Application/Provider/ProviderRouter.php`; `plugins/nvoos-content-graph-ai/src/Embeddings/{EmbeddingService,RagRetriever}.php`; companion plan [`091-dify-parity-gap-fill-implementation-plan.md`](./091-dify-parity-gap-fill-implementation-plan.md)

## Executive Summary

An internal capability scorecard against Dify (0 = missing, 1 = partial, 2 = parity, 3 = exceeds) rates NV oOS at parity or better on four axes — Workflow builder (2–2), Observability (3–3), BaaS API (3–3), and **Agent + tools (3 vs 2, our only lead)** — but behind on five. Four of the five trails are enterprise polish (prompt versioning, RAG depth, eval/annotations, ordered fallbacks); one is a hard absence (workflow DSL export/import, 0 vs 3). This proposal closes all five with a three-phase, ~10-week program, explicitly **skipping** Dify's sandboxed agent OS and cloud marketplace (its home turf, not ours). Every track follows a researched industry standard, is scoped to the WordPress plugin surface, and preserves the house rules (local-first defaults, opt-in external calls, WPCS, canonical envelopes, capability gates).

## Problem Statement

| Axis | Dify | NV oOS | Gap |
|---|---|---|---|
| Workflow builder | 2 | 2 | — |
| **Workflow DSL export/import** | 3 | **0** | The only hard absence. Workflow investments are locked inside one site; no git-ops, sharing, or AI-editable artifacts. |
| **Prompt IDE + versioning** | 3 | 1 | Prompts live in assistant post meta with no history; edits are irreversible and unauditable. |
| **RAG pipeline** | 3 | 1 | Content Graph AI has embeddings + keyword fallback but no chunking strategies, no hybrid fusion, no rerank, and no retrieval evaluation. |
| Model fallback | 3 | 2 | Health-based failover + `CascadeRouter` exist (Proposal 056); missing are user-configurable **ordered** fallback chains with cooldowns. |
| Observability | 3 | 3 | — (audit logger, cost tracker, analytics, token tracking) |
| Agent + tools | 2 | 3 | Our wedge. |
| BaaS API | 3 | 3 | — |
| **Eval / annotations** | 3 | 1 | `run_assistant_eval` exists but there is no annotation capture feeding eval datasets. |

## Industry Research Summary (2026)

### 1. Workflow DSL export/import

- **Dify's DSL is a YAML app definition** — app metadata, dependencies, workflow variables/features, and a node/edge graph — exportable and importable across workspaces, with imports validated against the platform's DSL version. Exports prompt about Secret-type environment variables before including them ([Dify docs — Manage Apps](https://docs.dify.ai/en/cloud/use-dify/workspace/app-management); [Dify — Introducing Dify Workflow](https://dify.ai/blog/dify-ai-workflow); [dify-workflow-dsl-skill](https://github.com/yzmw123/dify-workflow-dsl-skill)).
- **n8n's community treats JSON export + Git as the version-control path** — one file per workflow, semantic versioning with Git tags, structured directories ([n8n workflow version control best practices](https://ones.com/blog/mastering-n8n-workflow-version-control-best-practices/); [n8n workflow docs: naming, Git & best practices](https://evalics.com/blog/n8n-workflow-documentation-best-practices-complete-guide); [n8n-as-code](https://n8nascode.dev/)).
- **Standards distilled for our DSL:** (1) a versioned schema with a `schema_version` header and explicit forward-compatibility rules; (2) round-trip fidelity — export→import reproduces the workflow exactly; (3) **credential redaction by default on export** (Dify's secret-variable prompt, applied by default rather than asked); (4) import validation with actionable, per-node error reports (the "import blockers" pattern) and a dry-run mode; (5) plain-text YAML/JSON so artifacts are git-diffable and AI-editable.

### 2. RAG pipeline

- **The 2026 consensus pipeline is three-stage:** BM25 + dense retrieval in parallel → **Reciprocal Rank Fusion (RRF)** → cross-encoder rerank of the top 50–100 candidates → top 5–10 chunks to the LLM ([indexical.dev — hybrid search reranking strategies](https://indexical.dev/knowledge/what_are_the_best_hybrid_search_reranking_strategies_for_production_rag_systems_in_2026.php); [lushbinary.com — RAG production guide](https://lushbinary.com/blog/rag-retrieval-augmented-generation-production-guide/); [stack.ai — RAG best practices](https://www.stackai.com/insights/retrieval-augmented-generation-(rag)-best-practices-for-enterprise-ai-chunking-embeddings-reranking-and-hybrid-search-optimization)).
- **Decision rules:** retrieval misses exact terms → add hybrid search; precision is low → add reranking; chunks lose context → contextual/recursive chunking ([atlan.com — advanced RAG techniques](https://atlan.com/know/advanced-rag-techniques/)). Chunking quality gates retrieval more than the embedding model does; query rewriting and metadata filtering are cheap wins ([meilisearch.com — 9 RAG techniques](https://www.meilisearch.com/blog/rag-techniques)).
- **Retrieval is evaluated separately from generation:** RAGAS `context_precision` / `context_recall` measure the retriever; `faithfulness` / `answer_relevancy` measure the generator ([Ragas — available metrics](https://docs.ragas.io/en/stable/concepts/metrics/available_metrics/); [Confident AI — RAG evaluation metrics](https://www.confident-ai.com/blog/rag-evaluation-metrics-answer-relevancy-faithfulness-and-more)). **Hit-testing** = running the retriever alone over a golden question→chunk set and reporting those retrieval metrics.

### 3. Prompt versioning

- **The industry pattern is a prompt registry decoupled from code:** a prompt has a name, immutable version numbers, and a `production` label the runtime fetches; promotion is an explicit act, never an edit ([Langfuse — prompt management overview](https://langfuse.com/docs/prompt-management/overview); [Langfuse — get started](https://langfuse.com/docs/prompt-management/get-started); [Langfuse versioning & deployment](https://www.icertglobal.com/community/llm-prompt-versioning-and-deployment-with-langfuse)).
- **CI evaluation on prompt change is the companion practice** — promptfoo in CI for pre-merge regression tests, a hosted registry for production promotion ([Developers Digest — prompt management tools compared](https://www.developersdigest.tech/blog/prompt-management-tools-compared)).
- **"Lite" scope for us:** snapshot-on-save version history + restore + production label. Full A/B testing and CI eval harnesses are out of scope (deliberately — see Track B).

### 4. Ordered fallback chains

- **LiteLLM's reliability model is the reference:** an ordered fallback list tried in sequence on failure; retries with exponential backoff honoring `Retry-After`; per-deployment **cooldowns**; optional weighted failover inside a model group before escalating across groups ([LiteLLM — reliability](https://docs.litellm.ai/docs/proxy/reliability); [LiteLLM — routing](https://docs.litellm.ai/docs/routing); [LiteLLM — retries/fallbacks](https://docs.litellm.ai/docs/completion/reliable_completions); [DeepWiki — LiteLLM fallbacks/retries](https://deepwiki.com/BerriAI/litellm/7.1-fallbacks-and-retries)).
- **Operational cautions:** fall back only to capability-comparable models — a mid-turn fallback to a weaker model corrupts reasoning-heavy turns (LiteLLM's reasoning-content affinity flags this) — and test real failure modes, verifying parity with one alternate before chaining many ([Statsig — provider fallbacks](https://www.statsig.com/perspectives/providerfallbacksllmavailability)).
- **Our gap is narrow:** health-based failover + `CascadeRouter` already exist; the missing piece is the *user-configurable, per-assistant ordered chain with cooldowns and Retry-After respect*.

### 5. Span + annotation coverage

- **OpenTelemetry GenAI semantic conventions standardize LLM telemetry** under the `gen_ai.*` namespace — span attributes (`gen_ai.request.model`, `gen_ai.usage.input_tokens`/`output_tokens`, `gen_ai.operation.name`), events, and metrics; agent traces nest `invoke_agent` → chat → `execute_tool` spans. Vendor-neutral: Datadog, MLflow, and Elastic consume OTLP natively ([OpenTelemetry — GenAI observability](https://opentelemetry.io/blog/2026/genai-observability/); [OpenObserve — practical guide](https://openobserve.ai/blog/opentelemetry-genai-semantic-conventions/); [Greptime — OTel traces LLM calls, agents, MCP tools](https://greptime.com/blogs/2026-05-09-opentelemetry-genai-semantic-conventions); [MLflow — GenAI semconv](https://mlflow.org/docs/latest/genai/tracing/opentelemetry/genai-semconv/)).
- **Annotations are the eval flywheel:** human ratings/comments captured on traces become eval datasets (Langfuse/LangSmith pattern); NV oOS already ships `run_assistant_eval` (Proposal 056), which would consume them.

## Proposed Solution

Three phases, five tracks (full file-level detail in the companion implementation plan):

**Phase 1 (weeks 0–3) — Track A: Workflow DSL export/import.**
The base plugin already has raw JSON export/import (`class-wp-mcp-ai-rest-workflow-cpt-controller.php` — `POST /{id}/export`, `POST /import`, `mcp_ai_workflow` CPT, Engine V2 node vocabulary) — the scorecard's 0 is because it is not a versioned DSL: no `schema_version`, no validation report, no credential redaction, no YAML. Track A **upgrades** that surface (base) into the NV oOS Workflow DSL and adds the UI where builders live: the **Pro SPA `WorkflowsPage`/`WorkflowCanvas`** (export/import buttons + dry-run validation report panel) and the base admin DAG builder page. An optional best-effort **Dify YAML import adapter** (Pro, **D1 approved**) maps Dify's DSL onto our Engine V2 workflow array so teams migrating from Dify can bring workflows with them. Export covers saved workflows **and the bundled workflow presets directly** — presets are canonical workflow arrays already (`class-wp-mcp-ai-pro-workflow-presets.php`), so a preset exports without a save-first round trip (Pro wires the preset source into the base export route).

**Phase 2 (weeks 3–8) — Track B: Prompt versioning lite (weeks 3–5) + Track C: RAG upgrades (weeks 5–8).**
- Track B: immutable prompt snapshots on assistant save (capped history), restore, and an explicit `production` label; REST + WP-CLI + a version dropdown on the assistant edit screen — **plus the Pro assistant Build/Test admin pages and the Pro SPA assistant surfaces** get the same version picker/restore/promote controls so Pro users never leave their surface. No A/B harness (out of scope).
- Track C (in `nvoos-content-graph-ai`): chunking strategies (fixed + recursive/heading-aware with overlap), hybrid keyword+vector retrieval fused with RRF (k=60), a default score-based rerank that requires **no external API** (with an optional opt-in external rerank provider seam), and a hit-test runner reporting RAGAS-style `context_precision`/`context_recall` over a golden set. **Pro alignment:** the shared local reranker becomes a first-class strategy for the existing Pro `typesafe_rerank` tool, and Pro ships the chunking/rerank settings surface.

**Phase 3 (weeks 8–10) — Track D: ordered fallback chains (weeks 8–9) + Track E: span + annotation coverage (weeks 9–10).**
- Track D: per-assistant ordered fallback chains (assistant meta → `ProviderRouter` chain + cooldowns + `Retry-After`), mirrored on the legacy engine (`WP_MCP_AI_Cascade_Executor` side) — **with the chain picker surfaced in the Pro settings pages and the Pro SPA settings surface**, not only base. `CascadeRouter` stays untouched.
- Track E: `gen_ai.*`-convention span events emitted on the existing audit/observability rails, an **opt-in, off-by-default** OTLP HTTP exporter (SSRF-guarded endpoint setting), and an annotation store (ratings + comments keyed by transcript/turn) exportable as eval datasets for `run_assistant_eval` — **plus Pro observability surfaces: span/annotation widgets on the Pro analytics service page and dashboards REST controller, and a Pro eval-dataset tool consuming the annotations.**

**Explicit skip:** sandboxed agent OS and cloud marketplace — Dify's moat; fighting it would spend our weakest effort against their strongest, instead of owning WordPress-native, self-hosted, agent-first.

### Pro addon coverage (`addons/pro`) — every track lands in Pro

This proposal guarantees the Pro addon receives each upgrade, not just base/standalone:

| Track | `addons/pro` deliverables |
|---|---|
| A — Workflow DSL | Export/Import controls + dry-run validation report panel on the Pro SPA `WorkflowsPage`/`WorkflowCanvas` (`features/workflows/`, vitest-covered) — **including an Export button on the Presets tab (no save-first round trip)**; Dify YAML import adapter (Pro-only, behind D1); parity buttons on the Pro-side workflow surfaces |
| B — Prompt versioning | Version picker + restore/promote on the Pro assistant Build/Test admin pages and any Pro SPA assistant config surface |
| C — RAG upgrades | `typesafe_rerank` gains a `strategy: local\|provider` argument defaulting to the new shared local reranker; Pro chunking/rerank settings section; hit-test report rendering in the Pro console |
| D — Fallback chains | Ordered fallback-chain picker on the Pro settings pages + Pro SPA settings surface; global default setting |
| E — Spans/annotations | Span/annotation widgets on the Pro analytics service page + `WP_MCP_AI_Pro_Dashboards_REST_Controller`; Pro annotation-dataset export tool feeding `run_assistant_eval` |

## Benefits

- **Closes the only 0 axis.** Workflows become git-ops-able, shareable, and AI-editable artifacts; the Dify import adapter turns "you'll lose your workflows" into a migration path.
- **RAG reaches the industry-standard pipeline** (hybrid + RRF + rerank) with measurable retrieval quality via hit-testing — and stays local-first by default.
- **Prompt edits become auditable and reversible**; production prompts are promoted explicitly, not mutated in place.
- **Provider outages degrade gracefully** along explicitly configured chains with cooldowns, instead of silent quality cliffs.
- **Vendor-neutral observability** (OTel GenAI conventions) plus an annotation → eval-dataset flywheel that feeds the existing eval tool.
- Compliance preserved throughout: no new required external calls (rerank provider, OTLP exporter, and Dify adapter are all opt-in); no fake urgency or pricing implications; free-tier functionality is never paywalled.

## Implementation Plan

Phases, file-level change tables, validation gates, and rollout notes:
**[`091-dify-parity-gap-fill-implementation-plan.md`](./091-dify-parity-gap-fill-implementation-plan.md)**

## Effort Estimation

| Track | Scope | Estimate |
|---|---|---|
| A — Workflow DSL | codec + REST + WP-CLI + admin UI + tests (+ Dify adapter behind D1) | ~15 working days |
| B — Prompt versioning lite | snapshots + restore + production label + REST/WP-CLI/admin | ~8 working days |
| C — RAG upgrades | chunking + hybrid/RRF + rerank + hit-test runner | ~15 working days |
| D — Ordered fallbacks | router chain + cooldowns + legacy mirror + settings | ~7 working days |
| E — Spans + annotations | span emitter + OTLP exporter + annotation store | ~8 working days |
| Integration + buffer | cross-track QA, docs, changelog | ~2 working days |
| **Total** | | **~55 working days (~11 weeks; parallelizable to 10)** |

Dependencies: Track A touches the Pro Workflow Builder (module registry registration); Track C touches the standalone `nvoos-content-graph-ai` plugin (its own test matrix); Tracks D/E touch `lib/core` (unit tests) and base-plugin observers. Tracks are independent of each other and can be staffed in parallel within their phases.

## Success Metrics

1. **Scorecard re-rating:** DSL 0→3; Prompt IDE + versioning 1→2; RAG pipeline 1→2; Model fallback 2→3; Eval / annotations 1→2.
2. **DSL round-trip fidelity:** export→import reproduces all 10 bundled workflow presets byte-for-byte semantically (100% fidelity gate).
3. **DSL validation:** an import of a hand-edited DSL containing one deliberate error returns an actionable per-node validation report (no silent partial imports).
4. **Hit-testing:** `context_precision` / `context_recall` reported for the default pipeline before/after Track C; target ≥ +15% precision at equal recall on the golden set.
5. **Fallback drills:** synthetic provider-outage tests show zero failed turns once the chain + cooldown engage; a cooled-down provider is not retried for the cooldown TTL.
6. **Prompt restore:** any snapshot restores the exact prompt bytes; promotion is the only way the `production` label moves.
7. **House gates:** phpcs clean, PHPUnit green (Docker runner), JS typecheck/build green on every track; new tools/endpoints carry capability checks, nonces, and canonical envelopes.

## Decisions — approved 2026-10-27

- **D1 — DSL schema ownership: NV oOS DSL is canonical.** The vendor-neutral NV oOS DSL v1 (`schema_version`-gated) is the canonical workflow format. Wire format is **JSON** (no new PHP YAML dependency); a cosmetic YAML output mode is optional if a YAML emitter is already available in the dependency tree. The Dify schema is supported only through the Pro **import adapter** (one-way, best-effort, honest degradation via `foreign` stub nodes + dry-run findings). No write-to-Dify support.
- **D2 — Rerank default: local.** The default reranker is the local score-based implementation (RRF + normalized cosine blend — documented as a bounded cross-encoder approximation, not parity). An external rerank provider is opt-in only, settings-gated and SSRF-guarded, off by default.
- **D3 — Fallback chain storage: assistant meta + global default.** Per-assistant meta (`wp_mcp_ai_fallback_chain`) wins when set; a global default setting applies otherwise. Surfaces ship in base settings, Pro settings, and the Pro SPA settings.
- **D4 — OTLP exporter: opt-in, off by default.** `wp_mcp_ai_otlp_endpoint` defaults empty = exporter does nothing and no data leaves the site. Only an explicitly configured, allowlisted endpoint (SSRF-guarded) activates it.
- **D5 — Annotation storage: transcript post meta.** Ratings/comments are stored as post meta on transcripts (existing meta patterns). The `run_assistant_eval` dataset export consumes that shape; a dedicated CPT is only revisited if query-heavy annotation analytics become a requirement.

## Open Questions / Risks

- **Workflow export/import already exists (raw JSON).** Base already exposes `POST /mcp-ai/v1/orchestration/workflows/{id}/export` and `/import` (`class-wp-mcp-ai-rest-workflow-cpt-controller.php`) over the `mcp_ai_workflow` CPT with the Engine V2 node vocabulary (`tool|action|agent`). Track A upgrades these routes to DSL v1 (schema_version, validation, redaction, dry-run, YAML) while keeping the existing JSON shape importable — existing exports must still import after the upgrade (backward-compatibility gate).
- **YAML dependency** — resolved by D1: JSON is the canonical wire format (no new YAML dependency); YAML output is a cosmetic optional mode only if a bundled emitter exists.
- **Rerank without an API** — local score-based rerank is a bounded approximation of a cross-encoder; document the boundary honestly rather than claiming parity.
- **Track C lives in the standalone plugin** — `nvoos-content-graph-ai` has its own PHPUnit matrix and no access to base-plugin test helpers; the hit-test CLI must run in standalone mode (no monolith assumptions).
- **Obsolescence risk** — Dify evolves fast; the scorecard and the Dify adapter must be re-verified against Dify's current docs before any public comparison is made (compliance rule: no unverified competitive claims).

## References

1. Dify docs — Manage Apps (DSL export/import): https://docs.dify.ai/en/cloud/use-dify/workspace/app-management
2. Dify — Introducing Dify Workflow: https://dify.ai/blog/dify-ai-workflow
3. dify-workflow-dsl-skill (DSL anatomy): https://github.com/yzmw123/dify-workflow-dsl-skill
4. n8n workflow version control best practices: https://ones.com/blog/mastering-n8n-workflow-version-control-best-practices/
5. n8n workflow docs — naming, Git & best practices: https://evalics.com/blog/n8n-workflow-documentation-best-practices-complete-guide
6. n8n-as-code: https://n8nascode.dev/
7. indexical.dev — hybrid search reranking strategies for production RAG: https://indexical.dev/knowledge/what_are_the_best_hybrid_search_reranking_strategies_for_production_rag_systems_in_2026.php
8. Lushbinary — RAG production guide (BM25 + RRF): https://lushbinary.com/blog/rag-retrieval-augmented-generation-production-guide/
9. Stack AI — RAG best practices (chunking, embeddings, reranking, hybrid): https://www.stackai.com/insights/retrieval-augmented-generation-(rag)-best-practices-for-enterprise-ai-chunking-embeddings-reranking-and-hybrid-search-optimization
10. Atlan — 12 advanced RAG techniques: https://atlan.com/know/advanced-rag-techniques/
11. Meilisearch — 9 advanced RAG techniques: https://www.meilisearch.com/blog/rag-techniques
12. Ragas — available metrics: https://docs.ragas.io/en/stable/concepts/metrics/available_metrics/
13. Confident AI — RAG evaluation metrics: https://www.confident-ai.com/blog/rag-evaluation-metrics-answer-relevancy-faithfulness-and-more
14. Langfuse — prompt management overview: https://langfuse.com/docs/prompt-management/overview
15. Langfuse — prompt management get started (production label): https://langfuse.com/docs/prompt-management/get-started
16. iCert Global — LLM prompt versioning with Langfuse: https://www.icertglobal.com/community/llm-prompt-versioning-and-deployment-with-langfuse
17. Developers Digest — prompt management tools compared: https://www.developersdigest.tech/blog/prompt-management-tools-compared
18. LiteLLM — reliability (fallbacks/cooldowns): https://docs.litellm.ai/docs/proxy/reliability
19. LiteLLM — router load balancing: https://docs.litellm.ai/docs/routing
20. LiteLLM — retries/fallbacks: https://docs.litellm.ai/docs/completion/reliable_completions
21. DeepWiki — LiteLLM fallbacks, retries, exception mapping: https://deepwiki.com/BerriAI/litellm/7.1-fallbacks-and-retries
22. Statsig — provider fallbacks for LLM availability: https://www.statsig.com/perspectives/providerfallbacksllmavailability
23. OpenTelemetry — GenAI observability blog: https://opentelemetry.io/blog/2026/genai-observability/
24. OpenObserve — OTel GenAI semantic conventions practical guide: https://openobserve.ai/blog/opentelemetry-genai-semantic-conventions/
25. Greptime — how OTel traces LLM calls, agents, MCP tools: https://greptime.com/blogs/2026-05-09-opentelemetry-genai-semantic-conventions
26. MLflow — GenAI semantic conventions: https://mlflow.org/docs/latest/genai/tracing/opentelemetry/genai-semconv/
