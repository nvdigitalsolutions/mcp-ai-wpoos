# 161. The marker interfaces: one reader per seam, not six ports

**Status:** proposed, 2026-11-21. Answers 149 §136. No code ships with this
document; §6 gives the phased plan and §8 lists the questions that need an owner.

## 1. What §136 left open

WordPress reads six optional marker interfaces across its tool corpus. Core
declares four of them and consults **none**, and two have no core contract. §136
recorded the gap and deliberately did not implement it, because "enforcing them
changes tool behaviour for every servable row at once and needs its own decision
on how each seam maps to the Laravel runtime."

This document is that decision.

## 2. What the six actually do (verified from the tree)

| Seam | Declaration | What WordPress does with it | Consumers | Reader in WordPress |
|---|---|---|---|---|
| Data contract | `get_data_contract()` — `produces`, `consumes` | flow wiring: which named key a tool emits and accepts | 78 | `class-wp-mcp-ai-tool-registry.php:1204` |
| LLM sanitizer | `sanitize_for_llm( $result )` | strips base64, duplicate and verbose fields from the **LLM-facing copy**; the full result stays for display | 30 | `class-wp-mcp-ai-rest.php:4428` |
| Model requirements | capability flags (`vision`, `function-calling`, …) | filters model compatibility and dropdowns | 30 | model selector |
| Rules | usage guidance | prepended to the tool description the model sees | 22 | prompt builder |
| Safety profile | `get_irreversibility_score()` 0–1, `get_minimum_necessity()` | picks a stronger model at ≥ 0.5; feeds the necessity gate | 17 | `class-wp-mcp-ai-model-selector.php:627`, `harness/class-wp-mcp-ai-necessity-gate.php:373` |
| Flow stage | pipeline position | orders tools inside a flow | 1 | flow runner |

Three of these have a live consumer with a visible effect: context hygiene, model
selection, and the necessity gate.

## 3. The gap is inert today, and that cuts both ways

A grep of `lib/core/src`, `lib/laravel-adapter/src` and the app for any of the six
method names returns **zero files**.

So nothing is broken today — and, more importantly, **porting the contracts alone
would change nothing**, because the implementations live on WordPress tool classes
and core tools declare none of them. The divergence is prospective: a ported tool
whose WordPress twin sanitizes a 2 MB base64 result, or declares irreversibility
≥ 0.5 so the selector picks a stronger model, silently loses that guard in the
Laravel runtime, and no readiness value shows it.

That reframes the work. It is not "port six interfaces"; it is "decide which
guards must exist here, and where they are read".

## 4. Industry practice: the declaration belongs on the tool, the decision at the boundary

Runtime enforcement for agent tool calls has converged on interposition: a
deterministic layer that evaluates a declaration immediately **before execution**,
after the model has committed to a name and arguments, and allows, blocks or
escalates the call — independently of how the model justified it. The published
designs describe this as a policy enforcement point wrapping the dispatch layer
(Open Agent Passport, [arXiv 2603.20953](https://arxiv.org/html/2603.20953v1);
runtime policy enforcement for MCP agents, [MDPI 15(13):2829](https://www.mdpi.com/2079-9292/15/13/2829)).

The pattern is consistent: keep the *declaration* next to the tool, keep the
*decision* in one reviewed place. Per-tool decision code is the anti-pattern
precisely because it is unenforceable — nobody can review 78 scattered policies.

Core already has that boundary: annotations, the `ToolSafety` veto, approval
routing and schema validation all act at one place. The marker interfaces are
declarations, and two of the six are already expressed there in another form.

## 5. Decision

**Do not port six interfaces as six code paths.** Map each seam to the declaration
it really is, read by a boundary that already exists — and add a reader only where
the Laravel runtime has a consumer for it.

| Seam | Decision | Reason |
|---|---|---|
| LLM sanitizer | **Port as an optional declaration, phase 1** | The only seam with a concrete, immediate harm: context bloat costs money and truncates reasoning. The orchestrator is the sole place that can apply it. |
| Safety profile | **Do not port the numeric score.** Derive from annotations now; add `irreversibility` only if the approval policy needs a threshold | `readOnlyHint` and `destructiveHint` are already per-row, reviewed, and gate behaviour. A second numeric vocabulary would disagree with them. |
| Data contract | **Defer** until a core consumer chains tools | Today it would be an unread declaration — exactly the failure §136 recorded. |
| Model requirements | **Defer** until a core path filters tools by model capability | The catalogue exists (`nvoos_model_configs`), but nothing selects tools from it. |
| Rules | **Defer** | Prompt-facing; the core prompt builder does not consume it yet. |
| Flow stage | **Record only** | One consumer, and the flow runner is WordPress-side. |

## 6. Phase plan

**P1 — LLM sanitizer.** Add an optional `sanitizes_for_llm` declaration to the
core tool contract. The orchestrator applies it when building the tool message
list, and only there; the stored transcript keeps the full result. Default is
identity, so the change is invisible to any tool that does not declare it — this
is the one seam where the "changes every row at once" concern does not apply,
because it is opt-in per tool.

Verification for P1:

1. A tool returning a data URL has the payload stripped from the LLM copy and
   preserved in the transcript.
2. A tool without the declaration is byte-identical to today.
3. The auditor warns when a `ready` row returns binary payloads and declares no
   sanitizer (warning first; a gate once the corpus is clean).

**P2 — safety profile, as annotations.** Confirm every `destructive` and
`readOnly` row routes the way the WordPress necessity gate would. Only if an
approval threshold is genuinely numeric does the 0–1 score get a reader.

**P3–P6 — deferred seams.** Each gets a reader when its consumer exists. Recorded
here so a green readiness batch is never mistaken for behavioural equivalence.

## 7. What this means for readiness

A `ready` row asserts that the tool runs, is constructible and is annotated. It
does **not** assert that the port guards like its WordPress twin. P1 and P2 close
the two seams where that difference is observable; P3–P6 record the rest. Read
§3 as the standing caveat: the gap is inert, so a missing guard fails silently
rather than loudly.

## 8. Questions for the owner

1. **Is the approval policy's threshold numeric?** If yes, the 0–1 irreversibility
   score has a real consumer and P2 grows from "derive from annotations" to "port
   the score and reconcile it with annotations".
2. **Should a sanitizer declaration be required for tools returning binary
   payloads?** Recommendation: warn first, gate later. This is the only seam where
   a missing declaration has a measurable cost per call.
3. **Will the core workflow engine chain tools by named contract?** That answer
   decides whether P3 happens at all.

## 9. References

- 149 §136 — the gap, as recorded.
- 149 §137 — the contract audit method this reuses.
- `includes/interfaces/interface-wp-mcp-ai-tool.php` — data contract, model
  requirements, rules.
- `includes/interfaces/interface-wp-mcp-ai-tool-safety-profile.php` — irreversibility
  and minimum necessity.
- `includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php` — the sanitizer
  contract, including the list of what it is meant to strip.

## 10. Outcome of the open questions (2026-11-21)

§8 left two questions open. Both are answered below from the code, with the
research that shaped them. §8 stays as the record of what was asked; this section
supersedes its "open" status.

### 10.1 Is the approval threshold numeric? Yes — and it is already derived

The question assumed a numeric threshold would need a port. It does not: the
runtime already carries the number.

| Piece | Where | What it does |
|---|---|---|
| Scale and matrix | `lib/core/src/Domain/Service/Safety/ActionSafetyProfile.php` | irreversibility 0–1 × necessity → allow / warn / approval / skip / block; `computeRiskScore()` returns 0–2 |
| Flag mapping | `deriveIrreversibilityFromFlags()` | `irreversible` → 1.0; `financial-impact`, `external-communication`, `data-destruction`, `access-control-change` → 0.75; `write` + `state-changing` → 0.25 or 0.5; `read-only` → 0 |
| Decision | `NecessityGate::decide( $flags, $slug, $args, ?float $irreversibility = null )` | uses an explicit score **if a caller supplies one**, otherwise derives it from the flags |
| Enforcement | `NecessityGuard` (a registry guard) | blocks `block`, denies `approval_required` |
| Wiring | `NvoosServiceProvider` | the guard is added only when `oos.necessity_gate.enabled` is set |

So the numeric vocabulary is not missing — it is **derived from
`getCapabilityFlags()`**, which tools already declare and `MachineToolSafety`
already reads. Tools are not asked for a second score because `decide()` accepts an
explicit override when one is genuinely needed, one tool at a time.

**Recommendation: do not port `get_irreversibility_score()` or
`get_minimum_necessity()`.** A per-tool score would be a second vocabulary for the
same judgement, and the two can disagree — the failure §142.2 corrected for
annotations. The extension point already exists as the optional `$irreversibility`
argument.

**Leave the gate off by default, as it is.** Enabling it changes every call at
once and the false-positive rate is unmeasured. The measurement to watch when it
is enabled: how often `approval_required` fires for a tool whose annotations call
it safe. If that happens, the flag derivation and the annotations disagree, and
the annotations are the reviewed source.

Research note: numeric risk scores for agents are common and directionally
useful, but threshold calibration is weak. AgentRiskBOM ([arXiv
2606.21877](https://arxiv.org/html/2606.21877v1)) reports a Spearman rank
correlation of 0.73 against an independent scorer with only 46% categorical label
agreement — directional consistency, imperfect thresholds. That is an argument for
keeping the *decision* categorical and the *score* derived, which is what the code
already does.

### 10.2 Should a sanitizer be required for binary payloads? Yes, in two steps

Measured from `lib/core/src/Tool`: seven servable rows return an encoded binary
payload in their result envelope.

| Slug | Field |
|---|---|
| `create_image_variation` | `b64_json` |
| `edit_openai_image` | `b64_json` |
| `generate_openai_image` | `b64_json` (default response format) |
| `generate_openai_speech` | `audio_base64` |
| `generate_openai_speech_validated` | `audio_base64` |
| `generate_cloudflareai_image` | `image_base64` |
| `generate_music` | `audio_base64` |

Two apparent matches are **exempt**, and both are `pure` rather than `ready`:
`base64` (the encoded string *is* the product) and `generate_simple_jwt_token`
(base64 is internal to the JWT; the result is a token string). A declaration should
be required where the payload is incidental to the tool's purpose, not where it is
the purpose.

**Recommendation: warn first, gate after the seven declare.** A hard gate today
would fail an otherwise-clean manifest for behaviour nobody has ported yet. Order:

1. Phase 1 ships the opt-in reader (§6).
2. The auditor emits a **warning** naming any servable row that returns a binary
   field and declares no sanitizer.
3. Once the seven declare, the warning becomes a **gate** — the Proposal 160 §7
   pattern ("a `ready` row without evidence is a batch failure"), applied to the
   context contract instead of the evidence one.

Recommended independently of the declarations: **a size backstop in the reader.**
A declaration says what to strip; a backstop catches the tool that forgot to
declare, or the provider that returns an inline payload this runtime did not
anticipate. The published guidance is consistent — treat every tool result as
untrusted bulk input that must pass a size and shape gate before it enters the
model context (see the truncation references above).

### 10.3 The third question is unchanged

Whether the core workflow engine chains tools by named contract still decides the
data-contract seam. Nothing measured here changes that answer.

## 11. References for the outcome

- [Tool result truncation and summarization for production
  agents](https://www.technologyonthe.net/2026/10/tool-result-truncation-and.html) —
  "treat every tool response as untrusted bulk input that must pass a size and
  shape gate before it enters the model context".
- [Compress your agent's context without losing what
  matters](https://dev.to/mukundakatta/compress-your-agents-context-without-losing-what-matters-303) —
  intercept every tool result before it enters context; keep the full result for
  display.
- [AgentRiskBOM](https://arxiv.org/html/2606.21877v1) — numeric agent risk scores
  rank directionally but calibrate poorly against categorical labels.
