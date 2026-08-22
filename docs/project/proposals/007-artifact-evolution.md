# Proposal: Artifact Evolution — Darwinian Self-Improvement for Skills, Prompts & Roles

| | |
|---|---|
| **Status** | Implemented — Phases A–G complete |
| **Category** | Pro Feature — Agent Infrastructure (Base scaffolding + Pro proposer) |
| **Tier** | Base (evolution engine, population, gating) + Pro (proposer upgrade, shadow A/B, lineage UI) |
| **Introduced** | Drafted August 2026 |
| **Depends on** | Continual Harness Evolver (v1.1.22), Meta-Harness (v1.1.39), Skill Registry (v1.7.0), Eval Suite Registry (v1.3.0) |

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Research Landscape](#research-landscape)
3. [Current State Analysis](#current-state-analysis)
4. [Proposed Architecture](#proposed-architecture)
5. [Phase-by-Phase Implementation Plan](#phase-by-phase-implementation-plan)
6. [Consolidated File Change Map](#consolidated-file-change-map)
7. [Risk Assessment](#risk-assessment)
8. [Dependencies Between Phases](#dependencies-between-phases)
9. [Total Estimated Effort](#total-estimated-effort)
10. [References](#references)

---

## Executive Summary

Imbue's **Darwinian Evolver** (Feb 2026) demonstrated that LLM-driven evolutionary search — populations of candidate solutions, fitness-weighted selection, failure-driven mutation, and post-mutation verification — achieves 2–3x performance improvements over base-model capabilities on code and prompt optimization, including state-of-the-art ARC-AGI-2 results. Nous Research's Hermes Agent issue #337 maps the same pattern onto the *instructions layer* (skills, system prompts, tool-use patterns) and argues it is the highest-leverage target for self-improvement: instructions are text an LLM can meaningfully mutate, evaluate against real tasks, and deploy immediately.

NV oOS already ships two self-improving subsystems:

1. **Continual Harness Evolver** (`includes/agents/`) — failure-signature-driven mutation of system prompts, roles, skills, and memory via a Refiner LLM (v1.1.22).
2. **Meta-Harness** (`includes/harness/`) — execution-trace capture, profile search, a Pro coding-agent proposer, population archive with lineage, and auto-deploy with rollback (v1.1.39).

However, a code-level audit (August 2026) found that these systems do not yet form a real evolutionary loop:

- **Mutations are never scored.** The Evolver mutates → stores → stops. The Eval Suite Registry and scheduler that could score artifacts are wired only to harness *profiles*, not to skills/prompts.
- **No populations for artifacts.** Only harness profiles have competing variants and lineage. Skills, prompts, and roles mutate single-lineage.
- **The tool/engine contract is broken.** `evolve_harness` calls `analyze_failures()` (which does not exist) and passes three arguments to a zero-argument `evolve()`; the constructor receives its arguments swapped (`assistant_id` where `session_id` is expected). Component filtering and `dry_run` are silently ignored.
- **Evolved artifacts are write-only.** The evolved system prompt (`_wp_mcp_ai_evolved_system_prompt`) is read by nothing except the manual bootstrap operation; evolved skills land in a parallel option store the file-based Skill Registry never reads.
- **No admission gating.** arXiv 2608.05810 shows self-evolved skill pools degrade past a critical size: defective skills contaminate later distillations through the decision context, and the contamination is **structurally irreversible** — post-hoc rollback recovers only a small fraction of lost performance. Skill admission must be pre-commit.
- **No cost controls on the base evolver** and **no sanitization** of Refiner-generated skill code before storage.

This proposal defines a 7-phase plan that upgrades the existing subsystems into a complete, gated, Darwinian evolution loop for skills, prompts, and roles — with populations and fitness-weighted selection (Imbue), pre-commit admission gating (VaG), learning-log-aware and crossover mutators, post-mutation verification, and eval-gated deployment with rollback and drift detection. Total estimated effort: **~15 days across 3 iterations**. Phase A (wiring repair + safety) is small enough to land immediately and is implemented with this proposal.

**Core thesis:** NV oOS should not just mutate its own scaffolding — it should *evolve* it: competing variants, scored fitness, gated admission, and reversible deployment.

---

## Research Landscape

### Thread 1: Evolutionary Program Search (2023–2026)

| System | Search mechanism | Key innovation |
|--------|-----------------|----------------|
| **Promptbreeder** (Fernando et al., DeepMind, 2023) | Mutates a population of task-prompts; mutation-prompts are themselves evolved | Self-referential mutation; thinking-style seeds; elitist selection |
| **ADAS / Meta Agent Search** (Hu et al., Meta, ICLR 2025) | A meta agent programs new agents in code | Archive of past discoveries prevents re-exploration |
| **Darwin Gödel Machines** (Sakana, 2025) | Self-improving coding agents; parent-proportional selection | File-system-based code editing; novelty-aware sampling |
| **AlphaEvolve** (Novikov et al., Google, 2025) | LLM-guided mutation with tournament selection | `# EVOLVE-BLOCK` markers for bounded edits |
| **Darwinian Evolver** (Imbue, Feb 2026) | Population + sigmoid fitness + novelty bonus + LLM mutators | Dynamic percentile midpoint; learning log; crossover; post-mutation verification |
| **VaG** (Shang et al., Aug 2026) | Pre-commit gating of distilled skills | Three heterogeneous critics + marginal-gain subset selection |

### Thread 2: The Darwinian Evolver Pattern (Imbue, Feb 2026)

The key mechanics, all confirmed by ablation in the Imbue blog post and repo:

1. **Population + weighted selection** — sampling weight = sigmoid-scaled fitness × novelty bonus. The sigmoid midpoint is set dynamically to the Nth percentile of current population scores each iteration, which keeps selection pressure in the high-gradient range for the entire run. Novelty bonus (scaled by number of existing children) prevents over-exploiting a single branch; all weights are strictly positive, so low scorers are occasionally sampled (escape from local maxima).
2. **Failure-driven mutation** — the mutator LLM is shown the parent artifact plus concrete failure cases (not random perturbation). Batch mutations over multiple failure cases ≈ mini-batch SGD.
3. **Separate train/score datasets** — the failure cases shown to the mutator (train) are disjoint from the dataset used to assign fitness (score), discouraging overfitting to the shown cases.
4. **Learning log** — past mutations represented as diffs with observed score deltas, drawn from the parent's local neighborhood (ancestors/siblings). Provides a direct *differential* signal: which change caused which effect.
5. **Crossover** — ~25% of mutations combine multiple parents, transferring discoveries between lineages.
6. **Post-mutation verification** — a mini-evaluation of the child on only the parent's failure cases, before full scoring. Imbue reports >10x reduction in time and cost from this filter alone.

Empirical properties: evolution works even when the mutator improves the artifact only ~20% of the time; it is robust to non-deterministic evaluation; and it is open-ended — improvement continues as long as scoring does not saturate.

### Thread 3: Self-Evolution Safety — Pre-Commit Gating (VaG)

Shang et al. (arXiv 2608.05810, Aug 2026) found that self-evolving agents that distill skills from trajectories hit a **capability–contamination phase transition**: past a critical pool size, newly added skills degrade performance. The cause is structural — once a defective skill enters the decision context, it becomes reference material for distilling later skills, forming cross-round contamination chains. The contamination is **irreversible**: removing the source skill after the fact cannot erase the flawed reasoning its descendants inherited (post-hoc rollback recovered only a small fraction of the loss).

Their fix, **Verifier-as-Gatekeeper (VaG)**, gates every skill **before** admission with three heterogeneous critics — *structural validity*, *behavioral harmlessness*, *semantic consistency* — plus **marginal-gain subset selection** that rejects candidates which do not improve over the incumbent. Result: monotonic improvement every round with a pool ~5x smaller, and the frozen pool transfers positively to other model backbones without re-evolution.

### Thread 4: Production Practice (2026)

The industry consensus loop (Arthur, futureagi, Galileo, 2026): offline eval suites in CI → span-attached online scoring → drift detection → **eval-gated deploys with automatic rollback** → human review queue for low-confidence changes. Hermes Agent #337 adds the pragmatic phasing: manual evaluation → automated evaluation via a batch runner → continuous improvement with A/B testing, and names the critical open question: *"Evaluation is hard — bad evaluation → bad evolution."*

---

## Current State Analysis

### What NV oOS Already Has (Readiness Score: 6/10)

| Darwinian pattern | NV oOS equivalent | Maturity | Gap |
|-------------------|-------------------|----------|-----|
| Execution traces | Meta-Harness Trace Capture + Store; Audit Trail | ✅ Production | Not wired to artifact scoring |
| Fitness function | Eval Suite Registry + Eval Scheduler + Eval Runner | ✅ Production | Wired to profiles only |
| LLM mutator | Continual Harness Refiner (base); Pro Coding-Agent Proposer | ⚠️ Partial | Single-lineage, no learning log, no crossover |
| Population | `WP_MCP_AI_Harness_Population` (profiles, lineage) | ✅ Production | Profiles only — not skills/prompts/roles |
| Deployment + rollback | `WP_MCP_AI_Harness_Auto_Deploy` | ✅ Production | Profiles only |
| Admission critics | Guardrails, Necessity Gate, Citation Verifier, PII Filter | ✅ Production | Not applied to evolved artifacts |
| Tool surface | `evolve_harness` tool + Bootstrap bundles | ⚠️ Partial | API contract mismatch (see below) |
| Cost controls | Pro proposer transient budget | ⚠️ Partial | Base evolver has none |

### Verified Wiring Defects (Audit, August 2026)

| # | Defect | Evidence |
|---|--------|----------|
| G1 | Constructor argument order swap | Tool and adapter call `new WP_MCP_AI_Agent_Harness_Evolver( $assistant_id, $session_id )`; the constructor signature is `( $session_id, $assistant_id )` |
| G2 | `analyze_failures()` does not exist | Grep across `*.php`: no definition; `handle_analyze()` would fatal |
| G3 | `evolve()` accepts zero arguments | `handle_evolve()` passes `( $component, $window_length, $dry_run )`; PHP silently ignores them — component filtering and dry-run are no-ops |
| G4 | Evolved prompt is write-only | `_wp_mcp_ai_evolved_system_prompt` written by `evolve_prompt()`; read only by the manual `bootstrap` tool op |
| G5 | Evolved skills are a parallel store | `wp_mcp_ai_evolved_skills` option never read by `WP_MCP_AI_Skill_Registry::load_skills()` |
| G6 | Refiner skill `code` stored raw | `evolve_skills()` lines ~806/~829 store the Refiner's `code` with no sanitization or PII scrubbing |
| G7 | No cost guard on base evolver | `call_refiner()` makes unbounded provider calls; the Pro proposer has a transient cost ceiling but the evolver does not |
| G8 | No pre-commit admission | Evolved artifacts bypass all harness layers; contamination risk (VaG) unaddressed |

---

## Proposed Architecture

The enhancement upgrades the existing subsystems into a closed evolutionary loop. New components are marked (New); existing components are reused.

```
┌────────────────────── Artifact Evolution Loop ─────────────────────────┐
│                                                                        │
│  ┌── Telemetry (existing) ──────────────────────────────────────────┐  │
│  │  Audit Trail   Meta-Harness Trace Capture/Store   Eval Run Store │  │
│  └──────────────────────────────┬───────────────────────────────────┘  │
│                                 │ failure cases + eval results          │
│                                 ▼                                       │
│  ┌── Artifact Population (New, Phase C) ─────────────────────────────┐ │
│  │  Per-artifact-type pools: skills / prompts / roles / memory       │ │
│  │  Sigmoid fitness × novelty bonus   dynamic percentile midpoint    │ │
│  │  Lineage: parent_hash / children   global archive (cross-site)    │ │
│  └──────────┬─────────────────────────────┬──────────────────────────┘ │
│             │ select parents              │ archive children            │
│             ▼                             │                             │
│  ┌── Mutators (Phase D) ──────────────────┴──────────────────────────┐ │
│  │  Failure-driven (existing Refiner, fixed)                         │ │
│  │  Learning-log-aware (diffs + score deltas)   Crossover (2+ parents)│ │
│  │  Self-referential mutation prompts (Promptbreeder)                │ │
│  └──────────────────────────┬────────────────────────────────────────┘ │
│                             │ child candidate                           │
│                             ▼                                           │
│  ┌── Post-Mutation Verification (New, Phase B) ──────────────────────┐ │
│  │  Mini-eval on parent failure cases only → dismiss non-improvers   │ │
│  │  (>10x cost reduction per Imbue)                                  │ │
│  └──────────────────────────┬────────────────────────────────────────┘ │
│                             ▼                                           │
│  ┌── Admission Gate (New, Phase E — VaG) ────────────────────────────┐ │
│  │  Critic 1: structural validity   → Skill Parser + Output Guardrail │ │
│  │  Critic 2: behavioral harmlessness → Guardrails + Necessity Gate   │ │
│  │  Critic 3: semantic consistency  → Citation Verifier + eval margin │ │
│  │  Marginal-gain subset selection (reject non-improving candidates) │ │
│  └──────────────────────────┬────────────────────────────────────────┘ │
│                             ▼ full eval (holdout score set)             │
│  ┌── Deployment (Phase F) ───────────────────────────────────────────┐ │
│  │  Auto-Deploy extension: promote variant + rollback                │ │
│  │  Holdout regression gate   shadow A/B (session-hash bucketing)    │ │
│  │  Drift detection → automatic rollback (existing regression detector)│ │
│  └────────────────────────────────────────────────────────────────────┘ │
│                                                                        │
│  ┌── Governance (Phase G, cross-cutting) ────────────────────────────┐ │
│  │  Unified evolution budget (base + pro)   audit-trail events       │ │
│  │  Human approval queue   admin lineage graph                       │ │
│  └────────────────────────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────────────────────┘
```

### Design Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| **Artifacts first, profiles later** | Instructions (skills/prompts/roles) are the highest-leverage, immediately deployable target; profile search already exists | Hermes #337 "the instructions layer is the sweet spot" |
| **Opt-in everywhere, default off** | Harness convention: every new layer defaults off in `Harness_Profile::defaults()`; evolution must never self-enable | `includes/harness/README.md` conventions |
| **Pre-commit gating, never post-hoc rollback** | Contamination chains are irreversible once admitted | VaG (arXiv 2608.05810) |
| **Separate train (failure replay) / score (eval suites) datasets** | Prevents overfitting to mutator-visible cases | Imbue; Hermes #337 "holdout sets" |
| **Post-mutation verification before full eval** | >10x cost/time reduction; cheap because failure-case replay reuses the Trace Store | Imbue |
| **Human approval before promotion** | Matches existing Meta-Harness proposal flow; production consensus | CoSAI Principle 1; Arthur/futureagi |
| **Skill code stays inert text** | Refiner-generated "code" is instructional content, never executed; executable behavior stays behind `edit_plugins` + sandbox | Existing Skill Registry PHP-execution blocks; G6 fix |
| **Population storage reuses the lineage pattern** | `WP_MCP_AI_Harness_Population` already implements hash/lineage/cap logic; generalize rather than duplicate | `includes/harness/class-wp-mcp-ai-harness-population.php` |

---

## Phase-by-Phase Implementation Plan

### Phase A: Wiring Repair & Safety Foundation (Base — 2 days) ✅ implemented

Repair the tool/engine contract and close the safety gaps before anything else. **This is the phase implemented with this proposal.**

**Task A.1 — Fix the `evolve_harness` ↔ Evolver contract (0.5 day)**
- Fix constructor argument order at both call sites (`includes/tools/class-wp-mcp-ai-tool-evolve-harness.php`, `lib/wordpress-adapter/src/Tool/EvolveHarnessTool.php`), plus a defensive normalization guard in the Evolver constructor.
- Add `analyze_failures( $component, $window_length )` to `WP_MCP_AI_Agent_Harness_Evolver` (delegates to existing `detect_failure_signatures()`; graceful when no audit trail exists).
- Extend `evolve()` with optional `$component`, `$window_length`, `$dry_run` parameters (PHP 7.4-safe, backward compatible). Component filtering and dry-run now actually work. Result gains `changes_applied` + `summary` keys consumed by both tool copies.
- Deliverable: `analyze` no longer fatals; `evolve` honors component/dry-run.

**Task A.2 — Evolved prompt consumption (0.25 day)**
- New `WP_MCP_AI_Evolved_Prompt_Resolver` (`includes/agents/`): self-registers on `wp_mcp_ai_resolved_system_prompt` (priority 15), swaps in `_wp_mcp_ai_evolved_system_prompt` only when the `wp_mcp_ai_harness_use_evolved_prompt` filter returns true (default false).

**Task A.3 — Evolved skills merge into the Skill Registry (0.25 day)**
- `WP_MCP_AI_Skill_Registry::load_skills()` merges evolved skills when the `wp_mcp_ai_skill_registry_include_evolved` filter is true (default false), via a new static `WP_MCP_AI_Agent_Harness_Evolver::get_evolved_skills()` normalizer (registry shape: `name` / `description` / `instructions` / `evolved`).

**Task A.4 — Sanitization & PII scrubbing of Refiner output (0.25 day)**
- `evolve_skills()` and `evolve_roles()` run Refiner output through `WP_MCP_AI_PII_Filter::scrub()` (when loaded) and `sanitize_textarea_field()`; skill `code` remains inert instructional text, scrubbed for secrets.

**Task A.5 — Evolution budget guard (0.25 day)**
- Transient-backed per-assistant budget (`wp_mcp_ai_evolution_budget_*`, hourly window, default $5.00 via `wp_mcp_ai_harness_evolution_budget_usd` filter). Enforced at the start of `evolve()` and per call in `call_refiner()` with cost estimation from provider response usage/cost or a flat fallback.

**Task A.6 — Tests & docs (0.5 day)**
- New `tests/test-harness-evolver.php`: signature detection (all four failure signatures), graceful analyze, disabled-by-default, invalid component, budget gate, warmup/frequency logic, evolved-skill normalization.
- Extend `tests/test-tool-evolve-harness.php`: `analyze` no-fatal regression test; `evolve` disabled-by-default envelope.
- Update `includes/agents/README.md` and `includes/tools/README.md` public surfaces.

### Phase B: Fitness Harness — Score Artifacts (Base + Pro — 3 days)

- **B.1** Extend `WP_MCP_AI_Eval_Suite_Registry` with artifact-scoped suites: a suite declares the skill/prompt variant it scores (`artifact_type`, `artifact_id`) alongside `input`/`expected`/`verifier`.
- **B.2** Failure-case replay: seed eval cases from Meta-Harness Trace Store failures (PII-scrubbed). Train set = replay cases (mutator feedback); score set = curated suites.
- **B.3** Post-mutation verification: child runs only the parent's failure cases before full evaluation; dismiss non-improvers. Mirrors `WP_MCP_AI_Harness_Search_Engine::evaluate_candidate()`.
- **B.4** `WP_MCP_AI_Eval_Run_Store` gains artifact-dimension indexing so drift/regression detection works per artifact.

### Phase C: Artifact Population & Selection (Base — 2 days)

- **C.1** New `WP_MCP_AI_Artifact_Population` (sibling of `WP_MCP_AI_Harness_Population`, same hash/lineage/cap pattern): per-artifact-type pools, `score`, `parent_hash`, `children`, `children_count` (novelty), `eval` payload.
- **C.2** Imbue-style sampling: `weight = sigmoid(sharpness × (score − percentile_midpoint)) × novelty_bonus`; dynamic midpoint per iteration (Nth percentile, default 50th); strictly positive minimum weights; configurable sharpness (5–20) and novelty weight via filters.
- **C.3** Global archive with cross-assistant transfer (like the profile population) and MAX_POPULATION-style pruning.

### Phase D: Mutators & Learning Log (Base refactor + Pro upgrade — 3 days)

- **D.1** Refactor the Evolver's four passes into `Artifact_Mutator` implementations: failure-driven (current, fixed), learning-log-aware (last N diff+score-delta pairs from the lineage neighborhood), crossover (2+ parents, ~25% of mutations), self-referential mutation-prompt evolution (Promptbreeder).
- **D.2** Learning log persistence: each mutation stored as a diff (or change description) with observed score delta — not a full snapshot.
- **D.3** Pro: `WP_MCP_AI_Pro_Harness_Proposer` becomes a mutator backend via the existing `wp_mcp_ai_harness_proposer` filter (it already does causal hypothesis + self-critique).

### Phase E: Pre-Commit Admission Gate (VaG) (Base + Pro — 3 days)

- **E.1** Three critics mapped to existing layers: structural validity (Skill Parser + `WP_MCP_AI_Output_Guardrail`), behavioral harmlessness (`WP_MCP_AI_Guardrails` + `WP_MCP_AI_Necessity_Gate` + `WP_MCP_AI_PII_Filter`), semantic consistency (`WP_MCP_AI_Citation_Verifier` + eval-suite verifiers).
- **E.2** Marginal-gain subset selection: a candidate is admitted only if it improves over the incumbent on the score set. Targets the 5x-smaller-pool result.
- **E.3** Pool size caps per assistant and per site; admission is pre-commit only — no post-hoc rollback of admitted skills.

### Phase F: Deployment, Shadow A/B & Drift (Base — 2 days) ✅ implemented

Implemented in Base (deviation from the Pro tag below — every dependency already lives in Base). See `007-artifact-evolution-implementation-plan.md` § Phase F for the task-level spec.

- **F.1** `WP_MCP_AI_Artifact_Deploy` promotes artifact variants (prompt/skill) with rollback and an immutable append-only audit trail.
- **F.2** Holdout regression gate before promotion (fail closed; pre-computed or inline verification-gate evidence).
- **F.3** Shadow mode: `WP_MCP_AI_Artifact_Shadow` serves a registered variant to X% of sessions via session-hash bucketing; every serve decision is recorded for Trace Store comparison.
- **F.4** Drift detection wires `WP_MCP_AI_Eval_Regression_Detector` to artifact deployments; automatic rollback on threshold breach is opt-in.

### Phase G: Governance & Observability (Base + Pro — 1 day) ✅ implemented

- **G.1** Unified evolution budget and rate limits across all mutation paths (`WP_MCP_AI_Evolution_Governor`: shared hourly budget with Phase A continuity, per-path rate limits, site-wide cap; wired into the Continual Harness evolver and the search engine, gating the pluggable proposer path in Base).
- **G.2** Admin lineage graph per artifact (`WP_MCP_AI_Artifact_Lineage`) + human approval queue (`WP_MCP_AI_Artifact_Approval_Queue`: promote/rollback items, approve → Phase F deploy, reject, TTL, caps) + admin governance metabox on the assistant screen (governor report, pending queue with nonce'd approve/reject, lineage tree).
- **G.3** EU AI Act / NIST AI RMF / CoSAI alignment documented in `docs/operations/compliance/EU_AI_ACT_2026.md` (new "Self-Evolution Governance" section).
- **G.4** ✅ implemented (follow-up) — Settings surface: the self-evolution opt-in switches are exposed on Settings → Orchestration Layer (`WP_MCP_AI_Section_Orchestration`, new "Self-Evolution (Artifact Evolution)" field group) and applied to the runtime filters by `WP_MCP_AI_Evolution_Settings_Bridge` (priority 5, loaded from `harness-init.php` so the overrides are active on REST/frontend requests, not only wp-admin). Unsaved settings never override the code-level defaults — the "opt-in everywhere, default off" invariant holds with the UI too.

---

## Consolidated File Change Map

| File | Phase | Change |
|------|-------|--------|
| `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php` | A | Constructor guard; `analyze_failures()`; parameterized `evolve()`; dry-run honoring passes; sanitization; budget; `get_evolved_skills()` |
| `includes/agents/class-wp-mcp-ai-evolved-prompt-resolver.php` | A (new) | Opt-in evolved-prompt consumption on `wp_mcp_ai_resolved_system_prompt` |
| `includes/agents-init.php` | A | Require + register resolver |
| `includes/tools/class-wp-mcp-ai-tool-evolve-harness.php` | A | Constructor order fix |
| `lib/wordpress-adapter/src/Tool/EvolveHarnessTool.php` | A | Constructor order fix |
| `includes/class-wp-mcp-ai-skill-registry.php` | A | Opt-in evolved-skill merge in `load_skills()` |
| `includes/agents/README.md`, `includes/tools/README.md` | A | Public surface + new hooks |
| `tests/test-harness-evolver.php` | A (new) | Signature detection, gates, normalization |
| `tests/test-tool-evolve-harness.php` | A | No-fatal regression tests |
| `includes/measurement/eval/class-wp-mcp-ai-eval-suite.php` | B | `artifact_type` / `artifact_id` scoping + getters + `to_array()` |
| `includes/measurement/eval/class-wp-mcp-ai-eval-suite-registry.php` | B | `get_suites_for_artifact()` / `get_general_suites()` |
| `includes/measurement/eval/class-wp-mcp-ai-eval-run-store.php` | B | Artifact fields on records + bounded artifact index + `get_runs_for_artifact()` |
| `includes/measurement/verifiers/class-wp-mcp-ai-artifact-replay-verifier.php` | B (new) | Deterministic replay verifier (baseline: non-empty output; per-case rules) |
| `includes/harness/class-wp-mcp-ai-artifact-failure-replay.php` | B (new) | Failure-case replay from trace runs → eval cases / artifact-scoped suite |
| `includes/harness/class-wp-mcp-ai-artifact-verification-gate.php` | B (new) | Post-mutation verification: incumbent vs candidate, `improve` / `no_regression` modes |
| `includes/harness/harness-init.php` | B | Loads the new classes; registers the replay verifier |
| `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php` | B | Opt-in verification wired into `evolve_prompt()` (reject-on-no-improvement), budget-shared generators |
| `tests/test-artifact-failure-replay.php`, `tests/test-artifact-verification-gate.php`, `tests/test-eval-artifact-scoping.php` | B (new) | Phase B suites |
| `includes/harness/class-wp-mcp-ai-artifact-population.php` | C (new) | Population + weighted sampling (sigmoid fitness × novelty, dynamic percentile midpoint, lineage, FIFO cap) |
| `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php` | C | Archives incumbent/candidate into the population after verification |
| `includes/harness/class-wp-mcp-ai-artifact-mutator.php` | D (new) | Mutator abstraction + learning log |
| `includes/harness/class-wp-mcp-ai-artifact-mutator.php` | D (new) | Failure-driven / learning-log / crossover mutators + line-diff helper |
| `includes/harness/class-wp-mcp-ai-artifact-learning-log.php` | D (new) | Differential mutation log with lineage-neighborhood retrieval |
| `includes/harness/class-wp-mcp-ai-artifact-admission-gate.php` | E (new) | Pre-commit VaG gate: structural / harmlessness / marginal-gain critics |
| `includes/harness/class-wp-mcp-ai-artifact-population.php` | E | `enforce_per_assistant_cap()` — score-ordered per-assistant eviction |
| `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php` | E | Admission verdict attached to verification; rejection + cap enforcement wired |
| `includes/measurement/eval/class-wp-mcp-ai-eval-suite-registry.php` | B | Artifact-scoped suites |
| `includes/harness/class-wp-mcp-ai-artifact-deploy.php` | F (new) | Gated promotion with holdout + rollback + append-only audit trail + drift detection with opt-in auto-rollback |
| `includes/harness/class-wp-mcp-ai-artifact-shadow.php` | F (new) | Session-hash shadow serving with bounded stats |
| `includes/agents/class-wp-mcp-ai-evolved-prompt-resolver.php` | F | Shadow consultation on `wp_mcp_ai_resolved_system_prompt` (default off) |
| `includes/harness/harness-init.php` | F | Loads the deploy + shadow classes |
| `tests/test-artifact-deploy.php`, `tests/test-artifact-shadow.php` | F (new) | Phase F suites |
| `includes/harness/class-wp-mcp-ai-evolution-governor.php` | G (new) | Unified budget/rate-limit/site-cap governor across mutation paths |
| `includes/harness/class-wp-mcp-ai-artifact-approval-queue.php` | G (new) | Human approval queue (promote/rollback items) executing via the Phase F deploy class |
| `includes/harness/class-wp-mcp-ai-artifact-lineage.php` | G (new) | Lineage graph payload + ASCII renderer + canonical content addressing |
| `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-artifact-governance.php` | G (new) | Admin surface: governor report, queue decisions, lineage tree |
| `includes/assistants/class-wp-mcp-ai-assistant-cpt.php`, `includes/assistants/metaboxes-loader.php` | G | Metabox registration |
| `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php` | G | Governor delegation; opt-in queue-for-approval routing |
| `includes/harness/class-wp-mcp-ai-harness-search-engine.php` | G | Governor gates on the search + proposer paths |
| `tests/test-evolution-governor.php`, `tests/test-artifact-approval-queue.php`, `tests/test-artifact-lineage.php`, `tests/test-metabox-artifact-governance.php` | G (new) | Phase G suites |
| `docs/operations/compliance/EU_AI_ACT_2026.md` | G | Self-Evolution Governance section (EU AI Act / NIST AI RMF / CoSAI) |
| `addons/pro/includes/harness/…` | D | Proposer mutator backend |
| `docs/features/artifact-evolution.md` | G | Feature reference doc |

---

## Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|------------|
| **Bad evaluation → bad evolution** (the VaG/Imbue critical challenge) | High | Train/score split; holdout gate; post-mutation verification; human approval before promotion; admission gate never auto-applies |
| **Skill contamination chains** | High | Pre-commit VaG gate (Phase E) is mandatory before any auto-application; pool caps |
| **API cost blowout** | Medium | Unified budget (Phase A base + G unified); rate limits; post-mutation verification reduces eval spend >10x |
| **Overfitting to eval sets** | Medium | Separate train/score sets; holdout regression gate; shadow A/B in production |
| **Prompt injection via Refiner output** | Medium | PII scrub + sanitization (A.4); evolved content is inert text; guardrails pre-screen; admission critics re-check |
| **Regression after promotion** | Medium | Rollback (existing Auto-Deploy); drift detection with automatic rollback (F.4) |
| **Scope creep / open-ended self-improvement** | High | Strict phase gates; every layer opt-in; evolution disabled by default |

---

## Dependencies Between Phases

```
A (wiring + safety) ──► B (fitness) ──► C (population) ──► D (mutators) ──► E (gate) ──► F (deploy)
        │                     │                │                  │
        └── G (governance, cross-cutting) ◄────────────────────────┘
```

- A is independent and lands first (implemented with this proposal).
- B and C are parallelizable after A; C depends on B's scores.
- D depends on C (mutators need the population); E depends on B (gate needs scores); F depends on E (deployment follows admission).

## Total Estimated Effort

| Phase | Days | Tier |
|-------|------|------|
| A — Wiring repair & safety | 2 | Base ✅ done |
| B — Fitness harness | 3 | Base + Pro |
| C — Population & selection | 2 | Base |
| D — Mutators & learning log | 3 | Base + Pro |
| E — Pre-commit admission gate | 3 | Base + Pro |
| F — Deployment, shadow A/B, drift | 2 | Pro |
| G — Governance & observability | 1 | Base + Pro |
| **Total** | **~15 days** | |

---

## References

### Primary Research

- Imbue (Feb 2026). *LLM-based Evolution as a Universal Optimizer.* https://imbue.com/blog/2026-02-27-darwinian-evolver — and the open-source `imbue-ai/darwinian_evolver` repository (AGPL-3.0; studied, not imported).
- NousResearch (Mar 2026). *Feature: Evolutionary Self-Improvement — Auto-Evolving Skills & Prompts via LLM-Driven Search.* hermes-agent issue #337. https://github.com/NousResearch/hermes-agent/issues/337
- Shang, Xu, Sun, et al. (Aug 2026). *When Self-Evolution Backfires: Pre-Commit Gating against Skill Contamination in LLM Agents.* arXiv:2608.05810.
- Fernando et al. (2023). *Promptbreeder: Self-Referential Self-Improvement Via Prompt Evolution.* arXiv:2309.16797.
- Hu et al. (2025). *Automated Design of Agentic Systems.* ICLR 2025. arXiv:2408.08435.
- Wang et al. (2023). *Voyager: An Open-Ended Embodied Agent with Large Language Models.* arXiv:2305.16291.
- Karten et al. (2026). *Continual Harness: A Continual Learning System for General-purpose AI Agent Self-Improvement.* arXiv:2603.04586 (the existing Evolver's reference).

### NV oOS Internal Documentation

- `docs/project/proposals/006-meta-harness-auto-optimization.md` — the profile-search counterpart this proposal extends.
- `docs/features/llm-harness.md` — Layers A–J reference.
- `docs/features/meta-harness-auto-optimization.md` — Meta-Harness Phases 0–7 reference.
- `.context/pro-vs-base.md` — Base vs Pro placement rules.
- `docs/operations/compliance/EU_AI_ACT_2026.md` — compliance mapping.
