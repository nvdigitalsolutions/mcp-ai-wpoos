---
type: Skill
name: mcp-ai-wpoos-jev-decisions
description: Design and interpret TypeSafe Jev decision requests in NV oOS — typed choice/score/noul questions, batching, confidence thresholds, composite scoring, and guardrails. Use when classifying, routing, scoring, or safety-screening content with the typesafe_decide or typesafe_guardrail tools.
license: GPL-3.0-or-later
compatibility: NV oOS assistants
---

# Jev Decisions (TypeSafe)

Jev is NV oOS's decision provider — a System One model that returns typed,
probabilistic answers (choice / score / noul) over supplied state instead of
generated prose. Use it whenever the task is a **judgment**, not writing.

## When to use Jev

- Routing / triage / classification against a fixed set of options
- Scoring content against a rubric (2–10 ordered levels)
- Yes/no judgments with a calibrated probability (noul)
- Safety screening with `typesafe_guardrail`

## When NOT to use Jev

- Generating prose, summaries, or code — use the assistant's chat model
- Arithmetic, counting, date ordering — do those in code
- Any decision that must carry a written rationale

## Question design rules

1. Batch everything into one call — parallel questions cost tokens, not latency.
2. Add an explicit `other` option to every choice question so the model can abstain.
3. Keep 2–10 ordered, distinct levels on score rubrics.
4. Use noul criteria (true/false descriptions) when the yes/no boundary is subtle.
5. Send only the state fields the questions need — irrelevant state degrades accuracy (context rot).
6. State is adversarial: user-controlled content can steer answers. Never route consequential actions on Jev output alone — keep them behind code gates.

## Confidence: the three-path pattern

- confidence ≥ your high bar → act automatically
- middle band → confirm, review, or gather more information
- below the floor → route to a human or fall back

Use `min_confidence` on `typesafe_decide` to flag answers below a floor (they
come back flagged `below_threshold`, never dropped), and `weights` to combine
score answers into a composite locally — no extra API call.

## Model pinning

- `jev-latest` follows new releases — pin `jev-1.13.0` once thresholds are tuned.
- The response always reports the concrete model id that answered; log it when auditing decisions.

## Guardrails

- `typesafe_guardrail` returns advisory verdicts (pass / review / block) per hazard category. It never blocks anything itself — enforce the verdict in code.

## Billing & latency

- Input-only billing ($0.042 per million input tokens; output free).
- Identical repeated requests can be served from the decision cache when the site enables it (marked `cached: true`).
- Trim state to the fields the questions need — that is both cheaper and more accurate.
