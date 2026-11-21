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
