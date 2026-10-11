# Proposal 067 — Implementation Plan: Cluster Fine-Tuning & Model Foundry

**Status:** 🔮 PENDING (plan for review — no code merged)
**Date:** 2026-10-11
**Scope:** Phase 1 = Pro addon. Phases 0/2 = new `addons/train-worker` sidecar + repo configs. Phase 3 = base read-side seams + Pro writes. Phases 4–6 = Pro + portability engine.
**Related:** [Proposal](067-cluster-fine-tuning-model-foundry.md) · `docs/features/llm-harness.md` (Layers A–H) · Proposal 056 · `mcp-ai-wpoos-assistant-portability` skill · `mcp-ai-wpoos-updates` skill (Track B — model catalog) · `mcp-ai-wpoos-test-suite` skill (patterns 58/63/67)

---

## Research Foundation — Industry Standards Applied

Every phase below is pinned to researched practice. Where a standard conflicts
with this repo's conventions, the repo convention wins and the deviation is
recorded in the Phase-0 ADR.

| # | Industry standard | Source | Landed in |
|---|---|---|---|
| R1 | Curation pipeline order: language/format filter → exact dedup → fuzzy dedup (MinHash LSH) → heuristic filters → quality pass → PII removal → **decontamination** → sharded output | NVIDIA "Mastering LLM Techniques"; FineWeb (arXiv 2406.17557) | Phase 1 pipeline order |
| R2 | Semantic (embedding-based) dedup catches near-duplicates MD5 misses; log **lineage** (which rows were included/filtered and why) | LlmOps fine-tuning-curation course | Phase 1 dedup + provenance manifest |
| R3 | LoRA defaults: `r=16, alpha=32` (α/r ≈ 2), `target_modules=all-linear` (q,k,v,o,gate,up,down), LR `2e-4` SFT / `1e-4` QLoRA (never >3e-4), 2–3 epochs, short warmup + cosine decay | Unsloth docs; Raschka "Practical Tips"; Tensoria | Phase 2 hyperparameter policy |
| R4 | DPO rows need exactly `chosen`/`rejected`; GRPO rows need `completions` lists of 3+ ordered by quality; rejection-sampling generation at temp 0.8–1.0 / top-p 0.9–0.95 | Neural Base DPO/GRPO courses | Phase 1 preference schema |
| R5 | Quantization: `Q4_K_M` is the reliable default; imatrix (importance-matrix) calibration required at ≤3 bits; always measure perplexity **and** validate on your own task before deploy | llama.cpp quantize README; arXiv 2601.14277 | Phase 2 quant stage |
| R6 | Golden set sizing: 50 cases detect large regressions; 200 gives statistical confidence on 3–5% deltas; >500 is diminishing returns | Galtea LLM-eval guide | Phase 4 golden-set governance |
| R7 | Keep ~20% of the golden set as a holdout so training/prompt tuning can't overfit the test set; run decontamination (overlap check vs training corpus) | Latitude; QASkills golden-dataset guide | Phases 1 + 4 |
| R8 | LLM-as-judge: separate judges per concern, decompose rubrics into discrete checks, rotate positions to kill position bias, judge from a different model family, validate vs human baselines until correlation ≥0.85 | Openlayer; Comet; arXiv 2604.23178 | Phase 4 (extends `run_assistant_eval`) |
| R9 | Regression reading: IQR outlier rule for sets <50 (not z-score); read per-item deltas, not just aggregates; spot-check evaluator verdicts before trusting scores | Neural Base golden-sets; Langfuse | Phase 4 regression detector |
| R10 | Flywheel: mix ~80% original + ~20% fresh data on retrains (never discard the original set); rollback in <5 min is non-negotiable; monitor both the old test set and new production examples | Neural Base flywheel courses | Phases 3 + 5 |
| R11 | Model card (HF standard): description, intended uses/limitations, training-data provenance, eval metrics; `base_model` metadata required for fine-tunes/adapters/quants | HF Model Card Guidebook | Phase 3 card generator |
| R12 | Training-data provenance fields: source, collection method/date, license/ToS snapshot, personal-data flag, consent status, retention, hash, dataset version, downstream models | GDPR/copyright playbook (promise.legal) | Phase 1 manifest schema |
| R13 | PII redaction needs ML-based detection for free text (GLiNER/Presidio-style), not regex alone; manual review step for high-risk categories | ks-agents; Limina; Microsoft Presidio practice | Phase 1 PII scrub |
| R14 | Embedding fine-tune: `MultipleNegativesRankingLoss` + `MatryoshkaLoss`, 6–7k samples ≈ +7% retrieval, hard-negative mining, minutes on consumer GPU | Phil Schmid; Redis; daily.dev | Phase 6 |

---

## Phase 0 — Bake-Off, ADR & Conventions (3–4 days)

Validates the article's framework claims on **our** eval harness before any
product code is written. Output is an ADR, not shipped code.

### 0.1 — ADR + framework matrix

Files:

| File | Purpose |
|------|---------|
| `docs/project/architecture-decisions/ADR-fine-tuning-framework-selection.md` | Records Unsloth-default / Axolotl-scale-out / TorchTune-extensibility decision, hardware tiers, and any deviation from R1–R14 |

Acceptance criteria:

- [ ] One identical job (Qwen3 4B-class QLoRA, corpus from a real Layer-G suite export) run through Unsloth, Axolotl, and TorchTune; wall-clock, VRAM, and **Layer-G suite scores** tabled.
- [ ] Hyperparameter policy (R3) written into the ADR as the default YAML block.
- [ ] Decision recorded on: `mcp_ai_model` as base CPT vs Pro table; registry merge point into `WP_MCP_AI_Model_Service`; train-worker home (`addons/train-worker` sidecar vs `addons/pro/services/` — the `yfinance/app.py` precedent).
- [ ] Cost table per GPU tier (4090 → 8× H100) captured for the budget-enforcement cap.

### 0.2 — Bake-off harness script

Files:

| File | Purpose |
|------|---------|
| `bin/model-foundry/bakeoff.sh` | Wraps framework CLIs so all three consume the same dataset + config concepts |
| `bin/model-foundry/README.md` | Runbook: GPU setup, dataset path, scoring step via the existing eval runner |

Acceptance criteria:

- [ ] Reproducible from a fresh checkout (documented commands only — no manual GUI steps).
- [ ] Scoring step reuses `WP_MCP_AI_Eval_Runner` via a WP-CLI one-off, not a bespoke scorer.

Validation: manual GPU run (rented 4090/A100). No PHPUnit in this phase.

---

## Phase 1 — Corpus Foundry (Pro, PHP — data out) (4–5 days)

### 1.1 — Folder + governance engine

Files:

| File | Purpose |
|------|---------|
| `addons/pro/includes/model-foundry/README.md` | Folder purpose, public surface, neighbors, context cross-refs (folder-readme convention) |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-corpus-governance.php` | Provenance manifest builder (R12), consent gate, PII scrub orchestration, dedup, holdout split (R7) |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-corpus-deduper.php` | Exact (MD5) + semantic (embedding-distance, reuse of the configured embedding provider — R2) |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-corpus-schema-registry.php` | Versioned row schemas: `chat_v1`, `trajectory_v1`, `preference_v1`, `docs_v1` |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-corpus-pii.php` | Two-tier scrub: deterministic (`WP_MCP_AI_PII_Filter::scrub()`) + optional provider-backed free-text pass (R13), with a high-risk manual-review quarantine flag |
| `addons/pro/tests/test-model-foundry-governance.php` | Manifest fields, consent rejection, holdout math, dedup behaviour |

Pipeline order (R1) enforced by `WP_MCP_AI_Corpus_Governance::build_corpus()`:

```
format filter → exact dedup → semantic dedup → heuristic caps (per-case char
cap, skip reasons) → PII scrub → decontamination vs holdout → split
(train / holdout 80/20) → provenance manifest + sharded JSONL
```

Acceptance criteria:

- [ ] Manifest contains every R12 field, including `consent_status`, `personal_data_flag`, `dataset_version`, and a SHA-256 per shard.
- [ ] Non-consented assistants are rejected with a canonical `WP_Error` before any row is read (fail-closed).
- [ ] Holdout rows are **never** emitted into train shards (asserted in tests).
- [ ] Semantic dedup uses the site's configured embedding provider, and degrades to exact-only when no embedding provider is configured (no fatal).
- [ ] All exports land under `wp-content/uploads/mcp-ai/model-foundry/<assistant_id>/<corpus_version>/` with `.htaccess` + `index.php` guards (Layer-H precedent).
- [ ] Two-gate sanitisation + canonical envelope on every public entry point; PHP 8.1 syntax (Pro floor).

### 1.2 — Exporter tools

Files:

| File | Purpose |
|------|---------|
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-tool-export-trajectory-corpus.php` | `export_trajectory_corpus` — harness trace store → multi-turn rows incl. `tool_calls`/`tool` messages |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-tool-export-preference-pairs.php` | `export_preference_pairs` — judged runs + opt-in user thumbs → chosen/rejected (R4) |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-tool-export-plugin-docs-corpus.php` | `export_plugin_docs_corpus` — docs-hub markdown per plugin → text rows with provenance |
| `addons/pro/includes/harness-init.php` | Register the three tools via `wp_mcp_ai_pro_tools` (Layer-H pattern) |
| `addons/pro/tests/test-model-foundry-exporters.php` | Row-shape assertions, caps, skip reasons, capability gates |
| `tests/tools/.coverage-manifest.txt` + presets helper | Manifest + preset membership (test-suite pattern 58) |

Acceptance criteria:

- [ ] `trajectory_v1` rows are OpenAI tool-calling shaped (`role: tool` answers `tool_calls` ids) — asserted against a fixture trace.
- [ ] `preference_v1` rows carry exactly `chosen` + `rejected` (no lists — R4); pairs with a judge margin below threshold are skipped with `skipped_low_margin`.
- [ ] `docs_v1` rows carry per-document provenance (plugin slug, doc path, hash).
- [ ] Every exporter: `manage_options`, `dry_run`, `max_cases` (hard cap), per-case char cap, skip-reason transparency.
- [ ] PII scrub runs before write; scrubbed-row evidence count returned in the envelope.

### 1.3 — Extend Layer H for the new formats

Files:

| File | Purpose |
|------|---------|
| `addons/pro/includes/harness/class-wp-mcp-ai-tool-export-fine-tune-curriculum.php` | Accept `format: trajectory_v1` alongside `openai_chat_jsonl` (backward compatible — existing default unchanged) |

Acceptance criteria:

- [ ] Existing Layer-H tests pass unchanged (`addons/pro/tests/test-harness-fine-tune-curriculum.php`).
- [ ] `trajectory_v1` output reuses the governance engine (dedup + manifest).

Validation: Docker PHPUnit (WP 6.9 + WP 7.1), `addons/pro` phpcs run, `composer run docs:check-folder-readmes`.

---

## Phase 2 — Train Worker (Python sidecar) (6–10 days)

A media-worker-pattern sidecar. Express job API (Node, reusing media-worker
conventions: token auth, SSRF guard, Helmet, rate limits) + Python runners
that shell out to the frameworks. GPL-licensed so framework deps stay out of
the Pro addon's proprietary tree.

### 2.1 — Scaffold + job API

Files:

| File | Purpose |
|------|---------|
| `addons/train-worker/README.md` | Purpose, env vars, GPU requirements, security model |
| `addons/train-worker/package.json` | Node 22, zero runtime deps beyond express/helmet-class (media-worker parity) |
| `addons/train-worker/src/server.js` | `POST /jobs` (submit), `GET /jobs/:id`, `POST /jobs/:id/cancel`, `GET /healthz`; `X-Train-Token` timing-safe auth (media-worker pattern) |
| `addons/train-worker/src/jobs.js` | Job state machine: `queued → prepare → train → eval_callback → export → done / failed / cancelled` |
| `addons/train-worker/requirements.txt` | Pinned: unsloth, axolotl, torchtune, transformers, datasets, llama-cpp-python |
| `addons/train-worker/test/api.test.js` | node:test — auth, state machine, cancel semantics, size caps, SSRF validation of any URL argument |
| `.github/workflows/phpunit.yml` (or new `train-worker.yml`) | CI: node:test + `python -m pytest` on the runner stages |

Acceptance criteria:

- [ ] Job body: corpus URL(s) (worker pulls, never WordPress pushes to an arbitrary host), base model, framework, YAML config path, quantization spec, callback URL + nonce.
- [ ] Corpus fetch honours an allowlist (uploads dir URL) and a max-download cap.
- [ ] One job per GPU by default; queue with FIFO fairness; cancel is cooperative and cleans temp state.
- [ ] All secrets via env (`OLLAMA_HOST`, `HF_TOKEN` optional, callback nonce per job) — nothing persisted in job payloads.

### 2.2 — Framework backends + hyperparameter policy

Files:

| File | Purpose |
|------|---------|
| `addons/train-worker/src/python/unsloth_runner.py` | Default: QLoRA SFT, GRPO, QAT; GGUF export stage |
| `addons/train-worker/src/python/axolotl_runner.py` | YAML-driven multi-GPU (FSDP2) + multimodal |
| `addons/train-worker/src/python/torchtune_runner.py` | DoRA + custom-loop escape hatch |
| `addons/train-worker/configs/defaults.yaml` | The R3 policy block (r=16, α=32, all-linear, LR 2e-4/1e-4 QLoRA, 2–3 epochs, warmup + cosine) |
| `addons/train-worker/configs/<plugin>-<assistant>.yaml` | Per-target configs, repo-committed (starts with one example, e.g. `docs-hub-qa.yaml`) |
| `addons/train-worker/src/python/quant.py` | llama.cpp quantize: `Q4_K_M` default + imatrix calibration from a cluster-representative corpus (R5); optional QAT flag |
| `addons/train-worker/tests/` | pytest: schema validation of YAML configs, runner arg construction, quant stage outputs |

Acceptance criteria:

- [ ] Every runner emits the same result artifact set: LoRA adapter (safetensors), merged GGUF (when requested), `train-metadata.json` (framework, config hash, dataset hash, wall-clock, GPU, loss curve summary).
- [ ] YAML configs validated against a JSON Schema before any GPU work starts (fail fast on typos).
- [ ] Quant stage refuses to ship a GGUF whose perplexity delta vs the 8-bit baseline exceeds a configurable threshold (R5) — unless `force` is set and logged.
- [ ] GRPO mode requires `completions`-shaped data (R4); DPO mode requires exact `chosen`/`rejected` — wrong shapes fail in `prepare`, not mid-train.

### 2.3 — WordPress driver (Pro)

Files:

| File | Purpose |
|------|---------|
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-train-worker-client.php` | HTTP client: submit/poll/cancel, SSRF-guarded endpoint, callback nonce verification |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-train-job-store.php` | Job state persisted in a Pro table (or CCT); callback handler class |
| `addons/pro/tests/test-train-worker-client.php` | Mock-HTTP client tests: zero-HTTP assertions, nonce replay rejection, timeout behaviour (checkout-suite conventions) |

Acceptance criteria:

- [ ] Callback endpoint (`admin-post` or Pro REST) verifies the per-job nonce before writing any state.
- [ ] A stuck job (> N hours) surfaces as `stale` with a WP-CLI retry path — never silently.

Validation: `npm test` + `pytest` in `addons/train-worker/`; Docker PHPUnit for the Pro driver.

---

## Phase 3 — Registry, Serving & Model Cards (5–8 days)

### 3.1 — Registry (base read-side + Pro writes)

Files:

| File | Purpose |
|------|---------|
| `includes/post-types/class-wp-mcp-ai-model-post-type.php` (base, pending ADR) | `mcp_ai_model` CPT: base model, adapter + GGUF paths, checksums, config hash, provenance hash, eval score at promotion, status (`candidate` / `promoted` / `rolled_back`) |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-model-registry.php` | Pro write path: register artifact set from a train job result |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-model-card-generator.php` | HF-standard model card (R11): description, intended uses/limitations, data provenance, eval metrics, `base_model` metadata — rendered as the model's admin panel + an exportable `MODEL_CARD.md` |
| `tests/test-model-post-type.php` (base) + `addons/pro/tests/test-model-registry.php` | CPT contract, checksum validation, promotion-state transitions |

Acceptance criteria:

- [ ] Checksum mismatch on any artifact → registration refused (supply-chain guard from the proposal's risk table).
- [ ] Status transitions are a closed set: `candidate → promoted → rolled_back` (and `candidate → rejected`); no direct jumps.
- [ ] Model card is auto-generated from `train-metadata.json` + corpus manifest — no freehand metadata required to register.

### 3.2 — Serving + catalog integration

Files:

| File | Purpose |
|------|---------|
| `includes/class-wp-mcp-ai-model-service.php` | Merge point: site-local registry models join the static catalog (`includes/data/model-catalog.json`) at runtime — catalog file itself untouched (Track B invariants preserved) |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-model-deployer.php` | Targets: Ollama (`ollama create` from GGUF), vLLM (OpenAI-compatible endpoint), LM Studio, Embedded addon (`WP_MCP_AI_Embedded_Client` GGUF path) |
| `includes/admin/class-wp-mcp-ai-admin-assistant-*.php` (assistant edit screen) | Model dropdown gains a "Site-local fine-tuned" group |
| `addons/pro/tests/test-model-deployer.php` | Deploy target mapping, failure envelopes (no live GPU in CI) |

Acceptance criteria:

- [ ] Zero new provider clients — deployed models route through existing Ollama/vLLM/LM Studio/Embedded providers.
- [ ] `tests/test-model-catalog.php` and the Track-B suites stay green (registry is a runtime merge, not a catalog edit).
- [ ] Assistant saves with a local model → cascade pairing hint (`wp_mcp_ai_cascade_tier_1_model`) is offered in the UI and resolved through the existing Proposal-056 seams.
- [ ] Rollback = re-pointing the assistant at the previous artifact; the previous model remains registered (R10: <5 min rollback, non-negotiable).

Validation: Docker PHPUnit both WP versions; phpcs; `composer run docs:check-folder-readmes`.

---

## Phase 4 — Eval Gate & Promotion (3–5 days)

### 4.1 — Golden-set governance + candidate evals

Files:

| File | Purpose |
|------|---------|
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-golden-set-manager.php` | Golden-set versioning as JSON fixtures (R6/R7): 50–200 cases per assistant, 20% holdout enforced, decontamination overlap check vs the training corpus |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-candidate-evaluator.php` | Runs `WP_MCP_AI_Eval_Runner` against a candidate adapter via the existing `wp_mcp_ai_harness_eval_generator` seam |

Acceptance criteria:

- [ ] Holdout rows can never have been present in the train shards of the same corpus version (decontamination asserted).
- [ ] Candidate evals record per-suite + per-case results into `WP_MCP_AI_Eval_Run_Store` (trend history intact).
- [ ] Judge standards (R8): rubric decomposition per concern (tool selection / tool use / response quality), position rotation for pairwise cases, judge from a different model family than the candidate, human-calibration threshold documented (target ≥0.85 correlation before auto-promote trust).

### 4.2 — Regression detector + promotion engine

Files:

| File | Purpose |
|------|---------|
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-regression-detector.php` | IQR outlier rule for sets <50, z-score above (R9); per-item deltas; spot-check readout |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-promotion-gate.php` | Promote / keep-baseline / reject decision, audit event, rollback wiring |

Acceptance criteria:

- [ ] Promotion is impossible without a passing suite run on the candidate (asserted in tests).
- [ ] Regression threshold is per-suite, read from the existing suite metadata — no global magic number.
- [ ] Every promotion writes an audit-log entry (who, what, scores, corpus hash) via the existing audit logger.

Validation: Docker PHPUnit; adjacent harness suites (`test-harness-*`) stay green.

---

## Phase 5 — Automation + Fleet Sharing (4–6 days)

### 5.1 — Schedules, workflows, WP-CLI

Files:

| File | Purpose |
|------|---------|
| `addons/pro/includes/model-foundry/schedule-presets.php` | Pro Schedule Manager presets: nightly corpus refresh, weekly train, weekly eval (all default OFF) |
| `addons/pro/includes/model-foundry/workflow-presets.php` | Workflow Builder preset: `corpus → train → eval → promote` DAG |
| `includes/wp-cli/class-wp-mcp-ai-wp-cli-model-foundry.php` (or Pro WP-CLI section) | `wp mcp-ai model-foundry corpus|train|promote|rollback|status` |

Acceptance criteria:

- [ ] Presets registered through the same seams as existing presets (covered by the preset-accounting regression suites — test-suite pattern 58).
- [ ] WP-CLI commands gate on `manage_options`; `status` is read-only; `rollback` re-points without deleting artifacts.
- [ ] v2 retrains mix ~80% original + ~20% fresh rows per R10 (enforced in the corpus builder, asserted in tests).

### 5.2 — Portability bundle v2

Files:

| File | Purpose |
|------|---------|
| `includes/portability/…` (portability engine — per the assistant-portability skill) | Bundle format v2: optional `model_artifacts` section (adapter + config manifest + provenance hash), credential-redaction rules extended (no trainer secrets, no callback nonces) |
| `addons/pro/includes/model-foundry/class-wp-mcp-ai-model-bundle-codec.php` | Encode/decode the model-artifact section; round-trip fidelity tests |

Acceptance criteria:

- [ ] v1 bundles remain importable unchanged (backward compatibility asserted).
- [ ] Model artifacts import as `candidate` status only — never `promoted` — so a foreign model must pass the local eval gate (Phase 4) first.
- [ ] Credential-redaction round-trip: importing a v2 bundle can never recreate trainer tokens (asserted like the v1 credential tests).

Validation: full portability suites (`test-assistant-portability*`) + new bundle-v2 suites.

---

## Phase 6 — Stretch: Embeddings, Multimodal, GRPO Profiles, Fleet Registry (5–10 days, scoped by Phase 0)

| Work | Notes |
|---|---|
| `export_embedding_training_set` + embedding fine-tune job | R14: `MultipleNegativesRankingLoss` + `MatryoshkaLoss`, hard-negative mining from Graphify graph; 6–7k pairs target; re-embeddings land in the Graphify embeddings table |
| Multimodal Axolotl tier | Qwen2-VL-class tuning for Pro vision toolkits (RF-DETR catalogs, product photography); A100 80GB tier minimum per the proposal's hardware table |
| GRPO reasoning profiles | Per-role (BMAD analyst/QA) reasoning adapters on Unsloth; completion lists 3+ quality-ordered (R4) |
| Fleet model registry | Central registry on the nvoos.pro fleet (gateway/monitoring backbone) — deferred until bundle-v2 sharing proves demand |

---

## Cross-Cutting Deliverables (every phase)

- [ ] `docs/features/model-foundry.md` — canonical feature doc (harness doc style), updated per phase.
- [ ] `CHANGELOG.md` + tool-reference entries for every new tool slug.
- [ ] `mcp-ai-wpoos-updates` skill: Track B gains a "site-local registry" section (the monthly refresh must not clobber registry models).
- [ ] `mcp-ai-wpoos-assistant-portability` skill: bundle-v2 section.
- [ ] New coding-time skill `mcp-ai-wpoos-model-foundry` — **shipped with Phase 1** (2026-10-11, Corpus Foundry operational playbook: governance contract, schemas, registration pattern, validation gates); Phases 2/3 append the train-worker and registry/serving sections as they land (per the skills convention).
- [ ] `ADDON_INVENTORY.md` row for `addons/train-worker` (media-worker pattern).
- [ ] Folder READMEs for every new PHP-bearing folder (enforced by `composer run docs:check-folder-readmes`).

## Effort Rollup

| Phase | Effort | People | Exit gate |
|---|---|---|---|
| 0 | 3–4 d | 1 | ADR committed; bake-off scores tabled |
| 1 | 4–5 d | 1 | Exporters + governance PHPUnit green |
| 2 | 6–10 d | 1–2 | Corpus → adapter + GGUF on a real GPU |
| 3 | 5–8 d | 1–2 | Trained model selectable + answers in chat |
| 4 | 3–5 d | 1 | Promotion impossible without passing eval |
| 5 | 4–6 d | 1 | Unattended nightly loop + bundle-v2 round-trip |
| 6 | 5–10 d | 1 | Scoped after Phase 0 |

## Open Questions (carried from the proposal)

1. Phase-0 bake-off GPU: rented 4090/A100 (~3 days) or on-prem?
2. `mcp_ai_model` as base CPT vs Pro table — ADR will recommend; needs approval either way.
3. `export_preference_pairs` user-thumbs consent default: opt-in or opt-out per assistant?
4. MoE cheap-tier alternative (Qwen3 30B-A3B) vs per-assistant adapters — Phase 0 decides with data.
5. Central fleet registry in Phase 6: yes (nvoos.pro backbone) or bundle-only sharing?

## References

- NVIDIA — Mastering LLM Techniques: Text Data Processing: https://developer.nvidia.com/blog/mastering-llm-techniques-data-preprocessing/
- FineWeb (arXiv 2406.17557): https://arxiv.org/html/2406.17557v1
- LlmOps — Fine-tuning data curation: https://theneuralbase.com/llmops/learn/intermediate/fine-tuning-data-curation/
- Unsloth — LoRA hyperparameters guide: https://unsloth.ai/docs/get-started/fine-tuning-llms-guide/lora-hyperparameters-guide
- Raschka — Practical Tips for Finetuning LLMs Using LoRA: https://magazine.sebastianraschka.com/p/practical-tips-for-finetuning-llms
- Neural Base — DPO/GRPO preference-data courses: https://theneuralbase.com/dpo/learn/intermediate/task-specific-preference-data/ · https://theneuralbase.com/huggingface-fine-tuning/learn/intermediate/grpo-vs-dpo-when-each/
- llama.cpp — quantize README: https://github.com/ggml-org/llama.cpp/blob/master/tools/quantize/README.md
- Unified evaluation of llama.cpp quantization (arXiv 2601.14277): https://arxiv.org/html/2601.14277v1
- Galtea — complete guide for LLM evaluations: https://galtea.ai/blog/llm-evaluation-complete-guide
- Latitude — managing data quality for LLM evals: https://latitude.so/blog/managing-data-quality-for-llm-evals
- QASkills — golden dataset for LLM evaluation: https://qaskills.sh/blog/golden-dataset-llm-evaluation-guide
- Openlayer — LLM-as-judge guide: https://www.openlayer.com/blog/llm-as-judge-evaluation-guide
- Comet — LLM-as-a-Judge: https://www.comet.com/site/blog/llm-as-a-judge/
- Judging the Judges (arXiv 2604.23178): https://arxiv.org/html/2604.23178v1
- Langfuse — golden dataset evaluation: https://langfuse.com/resources/engineering/golden-dataset-evaluation
- Neural Base — data flywheel courses: https://theneuralbase.com/fine-tuning-fundamentals/learn/advanced/data-flywheel-from-production/
- Hugging Face — Model Card Guidebook: https://huggingface.co/docs/hub/en/model-card-guidebook
- promise.legal — GDPR & copyright in AI training playbook: https://blog.promise.legal/startup-central/gdpr-copyright-in-generative-ai-training-a-practical-playbook-for-transparency-consent-and-dataset-governance/
- Phil Schmid — fine-tune embedding models for RAG: https://www.philschmid.de/fine-tune-embedding-model-for-rag
- Redis — get better RAG by fine-tuning embedding models: https://redis.io/blog/get-better-rag-by-fine-tuning-embedding-models/
- Spheron — Axolotl vs Unsloth vs TorchTune (source article): https://www.spheron.network/blog/axolotl-vs-unsloth-vs-torchtune/
