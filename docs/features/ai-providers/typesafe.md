# TypeSafe (Jev) Decision Provider — Setup & Reference

> **NV oOS** supports TypeSafe's Jev "System One" model as a first-class **decision provider**. Jev is not a chat model — it returns typed, probabilistic decisions (choice / score / yes-no) about supplied state, in 70–500 ms, at $0.042 per million input tokens with **output free**. It powers the `typesafe_decide` tool and decision surfaces such as routing, triage, classification, and relevance filtering.

---

## Contents

1. [What Jev Is (and Is Not)](#what-jev-is-and-is-not)
2. [Prerequisites](#prerequisites)
3. [Quick Start](#quick-start)
4. [The Three Question Types](#the-three-question-types)
5. [OpenRouter Transport (no TypeSafe account)](#openrouter-transport-no-typesafe-account)
6. [Model Pinning](#model-pinning)
7. [Pricing & Rate Limits](#pricing--rate-limits)
8. [Security Considerations](#security-considerations)
9. [Known Limitations](#known-limitations)
10. [Diagnostics & Testing](#diagnostics--testing)
11. [Troubleshooting](#troubleshooting)

---

## What Jev Is (and Is Not)

Jev is TypeSafe's first "System One" model: it takes text or structured state plus typed questions and returns **constrained, probabilistic decisions**. It does **not** generate prose, code, summaries, or tool calls, and it cannot stream text. Because of this it is deliberately **excluded from the assistant chat-provider dropdowns** — selecting it as a chat provider would break chat by design.

Where it shines:

- Routing and triage (which team, which bucket, which model)
- Classification against a fixed set of options (up to 255 per question)
- Scoring against a 2–10 level rubric (returns a fractional position, e.g. `1.43`)
- Yes/no judgments with a calibrated probability (`noul`, 0–1)
- Confidence-gated cascades: Jev handles the easy majority, a frontier chat model handles the hard minority

---

## Prerequisites

- NV oOS base plugin active (the TypeSafe client, catalog entries, and `typesafe_decide` tool are in the **Base** distribution).
- Either:
  - A **TypeSafe API key** — early access is waitlisted; keys are issued at [console.typesafe.ai](https://console.typesafe.ai/settings/keys) after Google sign-in, **or**
  - An **OpenRouter API key** (use the `openrouter` transport of the tool — see below).
- PHP 7.4+ (base plugin requirement).

---

## Quick Start

1. In your WordPress admin, go to **NV oOS → Settings → Providers → TypeSafe (Jev)**.
2. Check **Enable TypeSafe Provider**.
3. Paste your TypeSafe API key (or set the `TYPESAFE_API_KEY` environment variable).
4. Choose a default model — `jev-latest` follows the newest release; pin `jev-1.13.0` when you tune confidence thresholds.
5. Click **Save Changes**.
6. Optionally go to **Tools → Provider Diagnostics** and click **Test TypeSafe Connection**.
7. Assign the `typesafe_decide` tool to an assistant and ask it to classify, score, or make yes/no judgments.

Example tool call:

```json
{
  "state": { "message": "I was charged twice for order A-104. Please refund the duplicate." },
  "questions": {
    "department": {
      "type": "choice",
      "instructions": "Which team should handle this ticket?",
      "criteria": { "billing": "Payment or subscription issues", "technical": "Bugs or integration problems" }
    },
    "frustration": {
      "type": "score",
      "instructions": "How frustrated does the customer appear?",
      "criteria": ["Calm, just stating facts", "Frustrated but civil", "Very angry, strong language"]
    },
    "refund_requested": { "type": "noul", "instructions": "The customer is explicitly asking for a refund" }
  }
}
```

---

## The Three Question Types

| Type | Returns | Criteria | Notes |
|------|---------|----------|-------|
| `choice` | Selected option + probability per option + `confidence` | Map of option → description (2–255 options) | Add an explicit `other` option so the model can abstain instead of picking the closest wrong answer |
| `score` | Fractional position on the rubric (e.g. `1.43`) + probabilities + `confidence` | Ordered list of 2–10 level descriptions | Level 0 is the first entry; a score is a weighted rubric position, not an accuracy percentage |
| `noul` | Probability of "yes" (0–1) | Omit | No separate confidence field — the probability is the belief. Near 0.5 means "cannot tell", not "medium intensity" |

Questions are evaluated **in parallel** over the same state, so batching many questions in one call costs tokens but almost no extra latency (speculative fan-out). Questions are independent — a later question cannot read an earlier answer; if a decision depends on a previous result, make a second call.

---

## OpenRouter Transport (no TypeSafe account)

While TypeSafe early access is waitlisted, an existing OpenRouter key reaches Jev through OpenRouter's **Decisions API** (a separate route from chat completions — chat SDKs will not work with it):

```json
{ "state": "...", "questions": { "q": { "type": "noul", "instructions": "..." } }, "transport": "openrouter" }
```

- The route is still alpha: if the endpoint is unavailable the tool returns a clean error with a "configure a TypeSafe key" action.
- Endpoint is filterable via `wp_mcp_ai_openrouter_decisions_endpoint` for sites that need to follow the route as it graduates out of alpha.

---

## Model Pinning

- `jev-latest` is a moving alias — answers can change under you when TypeSafe ships a new release.
- The response always reports the concrete versioned model id that answered (`model`), so you can audit which version produced a decision.
- When you tune confidence thresholds, pin `jev-1.13.0` in the provider settings.

---

## Pricing & Rate Limits

- **$0.042 per million input tokens; output is free** (input-only billing).
- Context limits: 64K tokens across state + all questions; 32K across state + the single longest question.
- Rate limits (documented as moving while GPU capacity lands): 250K tokens/second, 1,200 requests/minute. The client surfaces `retry-after` on 429 responses.

---

## Security Considerations

- **State is adversarial.** Jev treats supplied material at face value; user-controlled content in `state` can steer the answer (prompt injection). Never route consequential actions on Jev output alone — keep privileged operations behind the plugin's code-level gates (destructive-ops gate, capability checks).
- **"Zero hallucination" is schema conformance, not factual accuracy.** Jev cannot return a value outside your schema; it can return the wrong valid value. Its benchmarks are vendor-run against model-generated reference labels — evaluate on your own traffic before trusting thresholds.
- **Jev cannot look anything up.** It only knows the state you send. Retrieve and filter in code first, then send only the fields the question needs (context rot degrades accuracy).

---

## Known Limitations

Documented by TypeSafe for `jev-1.13`: unreliable counting and arithmetic, date ordering and windows, multi-step indirection, contradictory instructions/criteria, and noisy (irrelevant) state. Keep arithmetic, dates, and counting in code.

---

## Diagnostics & Testing

- **Admin:** Tools → Provider Diagnostics → TypeSafe (Jev) card shows enable state, key source (settings / WP 7.0 connector / env var / constant), selected model, and a connection test.
- **CLI:** `wp mcp-ai provider list`, `wp mcp-ai provider test typesafe`, `wp mcp-ai provider models typesafe`.

---

## Troubleshooting

| Symptom | Cause / Fix |
|---------|-------------|
| "No TypeSafe API key has been configured" | Set the key in Providers → TypeSafe or the `TYPESAFE_API_KEY` env var; or use `transport: openrouter` with an OpenRouter key. |
| "The OpenRouter decisions route is not available" | Alpha route not enabled for the account/region yet — configure a TypeSafe key, or filter `wp_mcp_ai_openrouter_decisions_endpoint`. |
| 429 / "rate limit exceeded" | Back off per the reported `retry-after`; batch questions into one call to reduce request count. |
| Provider absent from assistant dropdowns | Intentional: Jev is a decision provider, not a chat provider. Use the `typesafe_decide` tool. |
