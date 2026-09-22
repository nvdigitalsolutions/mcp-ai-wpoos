# TypeSafe (Jev) Decision Provider — Setup & Reference

> **NV oOS** supports TypeSafe's Jev "System One" model as a first-class **decision provider**. Jev is not a chat model — it returns typed, probabilistic decisions (choice / score / yes-no) about supplied state, in 70–500 ms, at $0.042 per million input tokens with **output free**. It powers the `typesafe_decide` tool and decision surfaces such as routing, triage, classification, and relevance filtering.

---

## Contents

1. [What Jev Is (and Is Not)](#what-jev-is-and-is-not)
2. [Prerequisites](#prerequisites)
3. [Quick Start](#quick-start)
4. [The Three Question Types](#the-three-question-types)
5. [Structured Fields & Noul Criteria](#structured-fields--noul-criteria)
6. [Confidence Floors & Composite Scoring](#confidence-floors--composite-scoring)
7. [Guardrails (`typesafe_guardrail`)](#guardrails-typesafe_guardrail)
8. [Decision Cache](#decision-cache)
9. [Retries](#retries)
10. [OpenRouter Transport (no TypeSafe account)](#openrouter-transport-no-typesafe-account)
11. [Endpoint Override & Gateway Access](#endpoint-override--gateway-access)
12. [Model Pinning](#model-pinning)
13. [Pricing & Rate Limits](#pricing--rate-limits)
14. [Security Considerations](#security-considerations)
15. [Known Limitations](#known-limitations)
16. [Diagnostics & Testing](#diagnostics--testing)
17. [Pro Integrations (cascade routing, guardrails & decision tools)](#pro-integrations-cascade-routing-guardrails--decision-tools)
18. [Troubleshooting](#troubleshooting)

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
- An **administrator account** — `typesafe_decide` execution is gated on the `manage_options` capability, and its declared capability metadata matches.
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
7. Assign the `typesafe_decide` tool to an assistant — it ships in the **AI/ML Operations** (`ai_ml`) preset — and ask it to classify, score, or make yes/no judgments.

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

## Structured Fields & Noul Criteria

Per TypeSafe's "Advanced: structure" documentation, `instructions`, Choice option values, Score level descriptions, and Noul criteria all accept **JSON structure** in addition to plain strings. The client and tool accept and sanitise these recursively (plain JSON only — HTML is stripped at entry).

- **Noul criteria** — optional `true`/`false` boundary descriptions pin down subtle yes/no questions:

```json
{ "type": "noul", "instructions": "Is this message urgent?",
  "criteria": { "true": "Explicitly time-sensitive, e.g. deadlines or outages", "false": "No urgency expressed" } }
```

- **Structured instructions** — label multiple parts of a judgment with keys:

```json
{ "instructions": { "question": "Does the claimed sender identity conflict with the sending domain?",
    "compare": ["ticket.sender.display_name", "ticket.sender.email"] } }
```

- **Structured Choice option values** — walking a taxonomy: each option's value can carry its child subtree so the model sees what lives under a branch before committing (hierarchical classification pattern).

---

## Confidence Floors & Composite Scoring

- **`min_confidence`** — a per-question floor (0–1). Answers below their floor are **not dropped**: they come back flagged `below_threshold: true` so the caller can implement the three-path pattern (high confidence → act; medium → confirm/review; low → human). Noul answers carry no confidence field — gate them on the probability itself.
- **`weights`** — composite scoring: pass positive weights for score questions and the tool returns a weighted-average `composite` computed locally (no extra API call). TypeSafe's pattern is to keep judgments atomic and combine them in code; this argument codifies it.

---

## Guardrails (`typesafe_guardrail`)

A second base tool screens content for hazards with one noul question per category (batched into a single decision call) and thresholds the probabilities into advisory verdicts:

- `pass` / `review` / `block` per category, plus an `overall` verdict.
- Default hazard set: prompt injection, harassment, self-harm, sensitive PII, illegal activity — or pass a custom `hazards` map with your own `instructions`, `review`, and `block` floors.
- **Advisory only**: the tool never blocks or suppresses content itself; enforcement must stay in the caller's code gates. Following TypeSafe's guidance, thresholds should scale with risk (higher block floors for high-stakes surfaces).

---

## Decision Cache

Enable **Cache TypeSafe Decisions** on the TypeSafe subtab to serve identical (model, state, questions) requests from a short-lived transient cache:

- Opt-in, default off. TTL default 300 s (filter `wp_mcp_ai_typesafe_cache_ttl`).
- Cache hits are marked `cached: true` in the response and record **zero** usage ($0).
- The cache key embeds the endpoint, base URL, model, and full payload, so any settings or question change invalidates it automatically.
- Decisions are advisory — the cache is never used to gate state-changing operations.

---

## Retries

Both the native client and the OpenRouter decisions bridge retry transient failures (429/5xx) with bounded attempts (default 2, filter `wp_mcp_ai_typesafe_retry_attempts`), honouring the `retry-after` header with exponential backoff (sleep filterable via `wp_mcp_ai_typesafe_retry_sleep`). 4xx auth errors and transport errors are never retried.

---

## OpenRouter Transport (no TypeSafe account)

While TypeSafe early access is waitlisted, an existing OpenRouter key reaches Jev through OpenRouter's **Decisions API** (a separate route from chat completions — chat SDKs will not work with it):

```json
{ "state": "...", "questions": { "q": { "type": "noul", "instructions": "..." } }, "transport": "openrouter" }
```

- The route is still alpha: if the endpoint is unavailable the tool returns a clean error with a "configure a TypeSafe key" action.
- Endpoint is filterable via `wp_mcp_ai_openrouter_decisions_endpoint` for sites that need to follow the route as it graduates out of alpha.

---

## Endpoint Override & Gateway Access

- **`typesafe_endpoint`** setting (or the `wp_mcp_ai_typesafe_endpoint` filter) overrides the endpoint path relative to the base URL. The default is `/v1/systemone`; third-party gateways and resellers may serve Jev on a different route (e.g. `/v1/decisions`). Verify a reseller's terms and pricing before switching — they add their own margin.
- **Alternative access while TypeSafe early access is waitlisted:** Vercel AI Gateway (reportedly issues keys without the waitlist), Netlify AI Gateway, AIMLAPI, and LiteLLM all list Jev. Documented as access paths, not defaults.
- **OpenRouter model ids:** the bridge defaults to `typesafe/jev-1.13` — the `typesafe/jev-latest` alias does not exist on OpenRouter. Unprefixed/alias overrides (`jev-latest`, `jev-1.13`) are normalised automatically.

---

## Model Pinning

- `jev-latest` is a moving alias — answers can change under you when TypeSafe ships a new release.
- `jev-preview` follows the most recent release whether or not it is official (moves ahead of `jev-latest` when a preview build is available).
- The response always reports the concrete versioned model id that answered (`model`), so you can audit which version produced a decision.
- When you tune confidence thresholds, pin `jev-1.13.0` in the provider settings. See also TypeSafe's [jev-1.13 jaggedness notes](https://docs.typesafe.ai/model-jaggedness/jev-1.13).

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

- **Admin:** Tools → Provider Diagnostics → TypeSafe (Jev) card shows enable state, key source (settings / WP 7.0 connector / env var / constant), selected model, decision-cache state, and a connection test.
- **CLI:** `wp mcp-ai provider list`, `wp mcp-ai provider test typesafe`, `wp mcp-ai provider models typesafe`.
- **Large requests:** the tool attaches advisory `warnings` when the estimated input exceeds the threshold (default 24,000 tokens, filter `wp_mcp_ai_typesafe_warn_tokens`) — billing is input-only, so trim state to the fields the questions need.

---

## Pro Integrations (cascade routing, guardrails & decision tools)

When the Pro addon is active, Jev also powers several opt-in decision surfaces and three new tools:

1. **Cascade routing in model comparison.** `POST /mcp-ai-pro/v1/threads/{id}/compare-models` accepts `jev_routing: true`; the response then carries a `routing` decision (task type, complexity score, frontier-model need) so callers can present or gate comparisons accordingly. Routing only — Jev never answers instead of the models.
2. **Research source filtering.** Enable **Jev Research Source Filtering** on the TypeSafe subtab and the Pro research tools (`research_eca`, `generate_research_report`) will ask Jev to score each search source's relevance to the query, drop clearly irrelevant ones (never below a 5-source floor), and reorder the survivors most-relevant-first before building their prompts. Every step fails open — on any Jev error the unfiltered sources are used.
3. **Guest-chat guardrail.** Enable **Jev Guest-Chat Guardrail** and every chat message is screened against the Jev hazard set (prompt injection, harassment, self-harm, sensitive PII, illegal activity) before it reaches the model. Only a high-confidence `block` verdict vetoes the message; `review` verdicts pass through advisory. Fails open on any Jev error.
4. **Citation checking.** Enable **Jev Citation Checking** and the Pro research tools ask Jev whether each cited source passage supports the claim it is cited for, attaching the checks to the report envelope (fails open — checks are simply omitted on error).
5. **Pro decision tools** — `typesafe_rerank` (general candidate re-ranking with a keep-minimum floor), `typesafe_eval` (calibration harness: overall + per-confidence-bucket accuracy on inline labeled examples, report-only), and `typesafe_skill_select` (two-stage rank + re-check over the bundled skill catalog for `load_skill`). All are `manage_options`-gated and use either transport.

---

## Troubleshooting

| Symptom | Cause / Fix |
|---------|-------------|
| "No TypeSafe API key has been configured" | Set the key in Providers → TypeSafe or the `TYPESAFE_API_KEY` env var; or use `transport: openrouter` with an OpenRouter key. |
| "The OpenRouter decisions route is not available" | Alpha route not enabled for the account/region yet — configure a TypeSafe key, or filter `wp_mcp_ai_openrouter_decisions_endpoint`. |
| 429 / "rate limit exceeded" | Back off per the reported `retry-after`; batch questions into one call to reduce request count. |
| Provider absent from assistant dropdowns | Intentional: Jev is a decision provider, not a chat provider. Use the `typesafe_decide` tool. |
