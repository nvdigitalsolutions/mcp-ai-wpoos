# Agentic Engineering Paper Review → Base+Pro Enhancement Plan

> **Status:** 📋 Proposed — awaiting review. None of the work items exist yet.
> **Created:** 2026-09-07
> **Inspired By:** *"The New SDLC With Vibe Coding — From ad-hoc prompting to Agentic Engineering"* (Osmani, Saboo, Kartakis — May 2026, `Day_1_v3.pdf`), the agentskills.io specification, and 2026 industry research on LLM-agent evaluation and model routing.
> **Related Components:** Measurement / Eval Harness (`includes/measurement/`), LLM Harness Layers A–J (`includes/harness/`), Meta-Harness (`addons/pro/includes/harness/`), Agent Skills (`includes/bundled-skills/`), Language Model Router, Pro Workflow Builder, Pro Schedule Manager.

---

## Executive Summary

The paper's central claim — **an agent = model + harness**, and the differentiator between "vibe coding" and "agentic engineering" is how deliberately the harness is configured — matches the NV oOS architecture almost line-for-line. The plugin already ships the harness (`includes/harness/`, Layers A–J), agent skills with progressive disclosure, an eval framework with verifiers/rubrics/regression detection, intelligent model routing, MCP **and** A2A, sandboxes, and observability. In most dimensions NV oOS is *ahead* of the paper's recommendations.

The honest gap analysis below identifies **eight** genuine opportunities — the largest being the paper's flagship requirement that we only partially cover today: **trajectory evaluation** ("a fluent output that skipped its verification steps is a more dangerous failure than one with a visible error") and **eval-gated shipping** ("require eval coverage with explicit rubrics as a precondition for any agent shipping"). The plan proposes 8 work items across 3 waves, following the existing measurement-PR sequencing pattern, with base/pro placement respecting `.context/pro-vs-base.md`.

---

## Part 1 — What the paper teaches (condensed)

1. **The spectrum.** Vibe coding → agentic engineering. The differentiator is not *whether* you use AI but how much structure, verification, and human judgment surrounds its output. Verification is the line: *tests* check deterministic parts, *evals* check the rest. "Without both, the practice is always vibe coding."
2. **Context engineering is the real skill.** Six context types every agent needs: Instructions, Knowledge, Memory, **Examples**, Tools, Guardrails. Static context (always loaded, expensive) vs dynamic context (on-demand, cheap) is a first-class architectural decision, "reviewed and versioned like any other configuration."
3. **Agent Skills** solve four problems (context rot, missing procedural memory, multi-agent overhead, vendor portability) via progressive disclosure: metadata at startup → full instructions on trigger → deep references on demand.
4. **The Harness.** "Agent = Model + Harness." Harness components: instructions/rule files, tools, sandboxes, orchestration, guardrails/hooks, observability. Evidence that the harness dominates outcomes: Terminal Bench 2.0 top-30 → top-5 on harness changes alone with no model change; LangChain +13.7 points from prompt/tools/middleware tweaks. "Most agent failures, examined honestly, are configuration failures."
5. **Tests AND evals.** Output evaluation checks the final artifact; **trajectory evaluation** checks the full sequence of tool calls and intermediate reasoning. Quality flywheel: evaluate → diagnose → optimize → verify → monitor, compounding each cycle.
6. **Factory model.** The developer's product is the *system that produces code*: specs, agents, tests/gates, feedback loops, guardrails. Conductor (hands-on) and orchestrator (async delegation) are two modes of the same role.
7. **The 80% problem.** AI does ~80% fast; the last 20% (edge cases, integration, subtle correctness) is where the risk lives, and its errors "look right."
8. **Economics.** Vibe coding = low CapEx / high OpEx (token burn, maintenance tax, security remediation). Agentic engineering = high CapEx / low OpEx. Context engineering is a *financial* lever; **intelligent model routing** (frontier for architecture/implementation, cheap models for deterministic tasks like test generation, code review, CI monitoring) is the other OpEx lever.
9. **Production agents.** Build-evaluate-deploy-observability in one loop. Open standards: **MCP** for tool access, **A2A** for cross-agent delegation.
10. **Where to start.** AGENTS.md; skills; *write tests and evals before generating code*; review every line that ships; leaders: make context engineering first-class (reviewed in PRs, versioned), set the bar at the eval not the demo, eval coverage gates shipping, distinguish prototype vs production work explicitly, invest in the harness as a shared asset.

---

## Part 2 — Current state mapping (NV oOS vs the paper)

| Paper concept | NV oOS today | Verdict |
|---|---|---|
| Agent Skills + progressive disclosure | 74 base + 41 Pro `SKILL.md` skills, `load_skill` tool, skill packs, remote catalogues, OKF-conformant frontmatter | ✅ Exceeds |
| Six context types | Instructions (system prompts/cues) ✅ Knowledge (RAG, Graphify, OKF) ✅ Memory (Agent Memory CCT, MemPalace, Graphify bridge) ✅ Tools (registry) ✅ Guardrails (Layer I + 10 security classes) ✅ — **Examples missing** | ⚠️ 5/6 |
| Static vs dynamic context trade-off | Progressive disclosure checkbox, tiktoken pre-flight, token-budget tool capping, GSD 30% rule, chat-history context strategy (proposal 006) | ✅ |
| Harness (agent = model + harness) | LLM Harness Layers A–J (`includes/harness/`) + orchestration layer + Meta-Harness 7-phase pipeline + Darwinian evolution governor (`007-artifact-evolution.md`) | ✅ Exceeds |
| Tests + evals (output) | PHPUnit suite + eval harness: rule/schema/LLM-judge/external-peer/human verifiers, reward registry, abstention tracking, verifier-independence enforcement, counterfactual runner, regression detector, Pro rubric presets (`prompt_adherence`, `json_schema`, `citation_presence`), OTel exporter, WP-CLI with regression-aware exit codes | ✅ Strong |
| Trajectory evaluation | Trace capture (`WP_MCP_AI_Harness_Trace_Store`), failure-signature detection (`WP_MCP_AI_Agent_Harness_Evolver`: tool failures, stuck loops, budget exhaustion) — but **no trajectory verifier kind in the eval harness** and no golden-path comparison | ⚠️ Partial |
| Intelligent model routing | 9-provider Language Model Router, PSO 7-dimension optimization, attention-router fusion (RRF k=60), learned router (Pro) — but **no declarative task-class → model-tier presets** | ⚠️ Mostly |
| Sandboxes + hooks + guardrails | Docker sandbox (LibreChat), HumanEval sandbox (Pro), Layer I jailbreak/capability guardrails, destructive-ops gate, request/URL/concurrency/load guards | ✅ Exceeds |
| Observability | Metric event store, trace store, OTel JSON exporter, Measurement dashboard, Command Center | ✅ |
| MCP + A2A open standards | Both implemented (MCP JSON-RPC 2.0; A2A PR #4578: agent-card, task state machine, `delegate_to_a2a_agent`) | ✅ Exceeds |
| Conductor + orchestrator modes | Chat UI (conductor) + delegation system, async continuation, Workflow Builder, Schedule Manager (orchestrator) | ✅ |
| 80% problem mitigation | Self-refine loop (critique→revise), cues (`cite_or_abstain`, `state_uncertainty`), Harness Evolver | ✅ |
| **Eval-gated shipping** | WP-CLI regression-aware exit codes exist, but **no gate wired into assistant publish, workflow deploy, or schedule promotion** | ⚠️ Partial |
| Economics (CapEx/OpEx) | Budget enforcement, cost tracker, per-assistant cost ceilings, PSO cost sensitivity | ✅ |
| Prototype vs production boundary | **No explicit assistant mode** that changes gate/guardrail strictness | ❌ |

**Bottom line:** the paper's thesis validates the plugin's existing architecture (the "harness" naming even matches). The plan below is a *gap-closure* exercise, not a rebuild.

---

## Part 3 — Industry research & standards (web findings, Sep 2026)

**Trajectory evaluation**
- Confident AI (2026 guide): evaluate at three levels — end-to-end (did the task succeed?), trajectory-level (was the path efficient and sound?), component-level (which retriever/tool/sub-agent broke?). Use deterministic checks for tool correctness, LLM-as-judge for output-dependent quality.
- LangChain: "Trajectories vs. Outputs" — many teams start with output-only evals; LLM-as-judge is the practical default for trajectory evaluation at scale.
- Langfuse: evaluate *what the agent did* (trajectory), *how* (each step), and *whether the result is correct* (final response).
- NomadX (2026): **the atomic unit of agent testing is tool-call correctness: the right tool called with the right arguments.** Use judge panels, calibrate against a human golden set; humans set targets.
- Galileo: hierarchical rubrics with a three-tier taxonomy calibrated against human judgment; CI/CD integration with three trigger types + progressive canary deployment.

**Agent Skills specification**
- agentskills.io: progressive disclosure is a three-level loading model — Level 1 (name + description, ~50–100 tokens) stays loaded; Level 2 (SKILL.md body) loads on trigger; Level 3 (reference files) loads on demand. "Smaller files mean less use of context."
- Microsoft Agent Framework implements the same advertise → load → read resources → run scripts pattern, confirming cross-vendor convergence.

**Model routing**
- Digital Applied (2026): "Route each request to the cheapest model that can handle it" — routing cuts bills 40–85% with no visible quality loss.
- NeuralTrust: production systems benefit from **three tiers**, not a binary small/frontier split; RouteLLM for research-grade routing, LiteLLM for self-hosted infra.
- Morph: a lightweight pre-router classifies prompt difficulty (~430 ms) and sends easy tasks to cheap models, reserving frontier models for hard tasks.

**Eval gates in CI/CD**
- MLflow: every prompt change, model swap, or tool addition should run the eval suite automatically; regression gates block deployments failing per-metric thresholds.
- LangChain readiness checklist: promote capability evals with high pass rates into the regression suite; integrate regression evals into CI/CD with automated quality gates.

**Few-shot examples / context engineering**
- Anthropic ("Effective context engineering for AI agents"): examples are a core context type; avoid laundry-list edge-case stuffing — one strong example beats three similar ones.
- Prompt-caching guidance (2026): put static content first (system instructions, few-shot examples, tool definitions) and variable content last — directly actionable for the examples injector.

---

## Part 4 — The plan: 8 work items, 3 waves

Each item lists placement (Base/Pro), the gap it closes, implementation shape (following one-class-per-file + WPCS + folder-README conventions), tests, and the paper/industry basis.

### Wave 1 — Close the paper's core loop (Base-first, no UI risk)

#### W1. Trajectory verifier kind + suite wiring (Base)
- **Gap:** the paper's #1 verification requirement — evaluate *how* the agent got to the answer, not just the answer. The eval harness today verifies subjects (final outputs) only; verifier kinds are `rule`, `schema`, `llm_judge`, `external_peer`, `human`.
- **Build:**
  - `includes/measurement/verifiers/class-wp-mcp-ai-trajectory-verifier.php` — deterministic verifier whose subject is the tool-call sequence recorded by `WP_MCP_AI_Harness_Trace_Store` (or Audit Trail fallback). Criteria: `expected_tool_sequence` (exact / ordered / subset / prefix matching), `required_tools`, `forbidden_tools`, `max_tool_retries`, `max_loop_length` (reuses the Evolver's stuck-loop signature), and `verification_required` — flag when a draft was produced without the verifying step its cue declared (the paper's "skipped verification steps" failure mode).
  - Add `'trajectory'` to the documented verifier kinds in `interface-wp-mcp-ai-verifier.php`; register via the existing `wp_mcp_ai_register_verifiers` action (third-party pre-empt semantics preserved).
  - Extend `WP_MCP_AI_Eval_Case` generator contract so a generator may return `'trajectory' => array` alongside `output`; runner passes it to trajectory-kind verifiers untouched.
- **Tests:** `tests/measurement/test-trajectory-verifier.php` — golden-sequence pass, wrong-order fail, forbidden-tool fail, loop detection, skipped-verification detection; case-immutability and serialization.
- **Basis:** paper §Testing & QA ("trajectory evaluation checks the full sequence of tool calls"); NomadX ("atomic unit of agent testing is tool-call correctness"); Confident AI three-level model.

#### W2. Eval-gated shipping — gate service + assistant publish gate (Base API, Pro wiring)
- **Gap:** paper leaders §2 — "Set the bar at the eval, not the demo… Require eval coverage with explicit rubrics as a precondition for any agent shipping into a shared workflow." We have regression-aware WP-CLI exit codes but no *product* gate.
- **Build:**
  - `includes/measurement/class-wp-mcp-ai-eval-gate.php` — `WP_MCP_AI_Eval_Gate::check( $assistant_id )`: loads the assistant's pinned suites (`harness_profile.evals_enabled`), runs them against thresholds (`pass_rate`, `regression_detector` deltas), returns `passed|blocked|warned` with reasons. Filter `wp_mcp_ai_eval_gate_thresholds`.
  - Base hook `wp_mcp_ai_assistant_pre_publish` fired on the draft→publish transition; default: block when suites are pinned and failing, warn when no suites pinned (the paper's "eval coverage as precondition").
  - WP-CLI: `wp mcp-ai measurement gate-check --assistant-id=N --json`.
- **Pro wiring (same wave, separate PR):** surface the gate in the Assistant edit screen publish flow; wire into Workflow Builder deploy and Schedule Manager promotion (create/enable a schedule whose assistant is gate-blocked → warn, allow override with audit-log entry).
- **Tests:** gate pass/block/warn matrix; empty-suite behaviour; threshold filters; publish-hook integration.
- **Basis:** paper leaders §2; MLflow regression-gate guidance; LangChain readiness checklist.

#### W3. Few-shot examples store + injector (Base)
- **Gap:** the sixth context type (Examples) has no first-class home. Reflexion reflections (`record_reflection`) are dynamic memory, not curated static examples.
- **Build:**
  - `includes/examples/README.md` (folder convention) + `class-wp-mcp-ai-examples-store.php` (option-backed, per-assistant keyed storage: curated input/output pairs + optional expected tool calls; hard caps: e.g. 8 pairs) + `class-wp-mcp-ai-examples-injector.php` (assembles a compact few-shot block, **static content first** per prompt-caching guidance, sized by the existing tiktoken budget capping, never exceeding the assistant's token budget).
  - Harness profile key: `examples.enabled`, `examples.max_pairs` (sanitized/clamped like other profile keys).
  - Assistant edit-screen metabox (mirroring the Harness Profile metabox pattern).
- **Tests:** store CRUD + caps; injector ordering and budget compliance (mock the token estimator); profile sanitization.
- **Basis:** paper six context types; Anthropic context-engineering guidance; 2026 prompt-caching ordering practice.

### Wave 2 — Pro depth on verification & routing

#### W4. Groundedness verifier (Base) + hallucination rubric preset (Pro)
- **Gap:** the paper's scoring dimensions — task success, tool-use quality, trajectory compliance, **hallucination**, response quality — are only partially covered by today's presets (`prompt_adherence`, `json_schema`, `citation_presence`). Citations ≠ groundedness.
- **Build:**
  - Base `includes/measurement/verifiers/class-wp-mcp-ai-groundedness-verifier.php` — pluggable LLM-judge verifier (same callable pattern as `WP_MCP_AI_LLM_Judge_Verifier`), statement-level: does each claim have support in the provided context? Independence profile enforced (judge must differ from generator provider/model — already supported).
  - Pro `WP_MCP_AI_Pro_Rubric_Presets::hallucination()` → `pro_hallucination_rubric` slug, registered in `class-wp-mcp-ai-pro-measurement-bootstrap.php` alongside the existing three; criteria: claim extraction, support ratio, unsupported-claim count, abstention credit.
- **Tests:** base verifier abstains without callable; passes/fails with fixture judge; Pro preset factory + bootstrap registration (mirroring `tests/pro/measurement/test-pro-rubric-presets.php`).

#### W5. Trajectory-compliance rubric preset (Pro)
- **Build:** `WP_MCP_AI_Pro_Rubric_Presets::trajectory_compliance()` → `pro_trajectory_compliance_rubric` — combines W1's deterministic trajectory criteria with an optional LLM-judge "was the path efficient and sound?" criterion, weighted. Registered in the same bootstrap.
- **Tests:** Pro rubric preset suite — pass on golden trajectory, fail on forbidden-tool trajectory, partial credit on inefficient-but-correct paths.

#### W6. Task-class → model-tier routing presets (Base preset, Pro auto-learning)
- **Gap:** the paper's OpEx lever — "route deterministic, lower-complexity tasks (test generation, code review, CI monitoring) to smaller, faster, significantly cheaper models." Current routing is weight-based (PSO) and attention-based; there is no declarative, admin-legible tier map.
- **Build:**
  - Base: routing-tier preset table (site option): `task_class` → tier (`economy|balanced|premium`) with model-catalog resolution per provider; composed into the Language Model Router as an explicit, highest-precedence-but-overridable signal. Ship sensible defaults (`classification`, `extraction`, `summarization` → economy where a suitable model exists).
  - Admin: Orchestration Dashboard → Model Routing tab (read-only table + per-class tier dropdown).
  - Pro: extend the learned router to propose tier reassignments from harness trace outcomes (model per task-class error-rate/cost pairs), human-approved via the existing proposals queue.
- **Tests:** tier resolution per provider; precedence vs PSO weights; default-preset integrity against the model catalog.

### Wave 3 — Modes, economics, and positioning

#### W7. Prototype vs production assistant modes (Base)
- **Gap:** paper leaders §4 — "Make the boundary explicit… Teams that keep this distinction blurry produce prototypes that ship by accident."
- **Build:** `_wp_mcp_ai_assistant_mode` post meta (`prototype|production`, default `prototype`). Production mode enforces: eval gate pass on publish (W2), Layer-I guardrail strictness on, audit logging always on, and a production badge in the chat UI. Prototype mode keeps everything permissive and shows a "prototype — not for live use" watermark. Filter `wp_mcp_ai_assistant_production_requirements` for site-specific policy.
- **Tests:** mode persistence; production-requirement enforcement (gate + guardrails); watermark rendering.

#### W8. Skill economics observability (Base)
- **Gap:** the paper calls context engineering "a financial lever" — we have the lever (progressive disclosure) but no attribution proving its savings.
- **Build:** stock metrics `skill.load.count` (counter, Goodhart-paired with `chat.turn.error.count`) and `skill.load.estimated_tokens` (histogram) emitted when `load_skill` runs; a "Context economics" panel on the Measurement dashboard estimating tokens saved by progressive disclosure vs an always-loaded baseline (metadata-only math — no provider calls).
- **Tests:** metric definitions include counter pairing (registry audit); emitter fires on load_skill; estimator math.

### Documentation wave (part of Wave 3)

- Update `docs/features/llm-harness.md` with a "Why this architecture" section citing the paper's harness equation and the Terminal Bench 2.0 / LangChain harness-effect evidence — positions the existing Layers A–J as an industry-leading implementation, not an internal invention.
- Update `AGENTS.md` §1 with one line noting the 035 proposal's scope ownership so other coding agents don't duplicate it.
- Optional: register 035 in `docs/project/proposals/README.md` / `RELATED_PROPOSALS_INDEX.md`.

---

## Part 5 — Deliberate non-goals

1. **No rebuild of existing subsystems.** PSO, the Evolver, A2A, sandboxes, OTel, and the eval registry already exceed the paper's bar; W-items only compose with them.
2. **No compiler-team-style autonomy experiments.** The paper's A2A "agent teams" example is interesting but the plugin's human-in-the-loop model (approvals, gates, audit trail) is a deliberate strength; keep it.
3. **No bundled LLM-judge model or extra SDKs in Base.** Judge callables stay pluggable (today's pattern); Base ships only deterministic verifiers (W1, W3 are deterministic).
4. **No fine-tuning infrastructure beyond the existing Layer H JSONL exporter.** The paper itself treats fine-tuning as optional.
5. **No changes to the MCP/A2A wire protocols** — both already conform to the open standards the paper recommends adopting.

---

## Part 6 — Sequencing & validation

| Wave | PRs (suggested) | Base | Pro | Validation gate |
|---|---|---|---|---|
| 1 | trajectory verifier · eval gate service + CLI · examples store/injector · Pro gate wiring | ✅✅✅ | ✅ | `composer run test` (tests/measurement + new), `composer run lint`, WP-CLI gate-check demo |
| 2 | groundedness verifier · hallucination rubric · trajectory-compliance rubric · routing tiers | ✅ | ✅✅✅ | PHPUnit + rubric preset suite + counterfactual run on new rubrics |
| 3 | assistant modes · skill economics · docs | ✅✅ | — | `composer run ci:all` incl. `docs:check-folder-readmes` for `includes/examples/` |

- Follow the measurement-subsystem precedent: small, sequenced PRs; every verifier ships with its Goodhart pairing and counterfactual coverage where applicable.
- Base/pro placement rules per `.context/pro-vs-base.md`: measurement/eval infrastructure in Base, rubric presets and learned-routing in Pro, admin gate wiring in Pro.

---

## References

- Osmani, Saboo, Kartakis — *The New SDLC with Vibe Coding* (May 2026). Reviewed: `Day_1_v3.pdf`.
- [Confident AI — LLM Agent Evaluation Metrics in 2026](https://www.confident-ai.com/blog/llm-agent-evaluation-complete-guide)
- [LangChain — LLM Evaluation Framework: Trajectories vs. Outputs](https://www.langchain.com/resources/llm-evaluation-framework)
- [Langfuse — Agent Evaluation (metrics, strategies)](https://langfuse.com/guides/cookbook/example_pydantic_ai_mcp_agent_evaluation)
- [NomadX — How to Evaluate and Test AI Agents (2026)](https://nomadx.ae/blog/how-to-evaluate-ai-agents-2026/)
- [Galileo — Agent Evaluation Framework: Metrics, Rubrics, Benchmarks](https://galileo.ai/blog/agent-evaluation-framework-metrics-rubrics-benchmarks)
- [agentskills.io — Agent Skills Specification](https://agentskills.io/specification)
- [Microsoft Learn — Agent Skills (progressive disclosure)](https://learn.microsoft.com/en-us/agent-framework/agents/skills)
- [Anthropic — Effective context engineering for AI agents](https://www.anthropic.com/engineering/effective-context-engineering-for-ai-agents)
- [Digital Applied — LLM Model Routing in 2026](https://www.digitalapplied.com/blog/llm-model-routing-2026-cost-quality-optimization-engineering-guide)
- [NeuralTrust — LLM Model Routing](https://neuraltrust.ai/blog/llm-model-routing)
- [MLflow — AI Agent Evaluations: A Developer's Practical Guide](https://mlflow.org/articles/ai-agent-evaluations-a-developers-practical-guide/)
- [LangChain — Agent Evaluation Readiness Checklist](https://www.langchain.com/blog/agent-evaluation-readiness-checklist)
- Repo context: `docs/features/llm-harness.md`, `docs/reference/measurement/*`, `docs/project/proposals/CONTEXT-1-INSPIRED-ORCHESTRATION-ENHANCEMENTS.md` (format precedent), `docs/project/proposals/007-artifact-evolution.md`.
