# Proposal 039 — TypeSafe Jev: Decision Provider (System One)

**Status:** 🔨 In progress — proposal approved, implementation underway
**Date:** 2026-09-21
**Scope:** Base plugin (`includes/`) + Pro follow-ups (`addons/pro/`)
**Related:** None prior. Research sources: TypeSafe docs (`docs.typesafe.ai`), OpenRouter model page (`openrouter.ai/typesafe/jev-1.13`), launch coverage (dev.to Valyu guide, digidai.github.io review).

## Summary

Add **Jev**, TypeSafe's "System One" decision model, to NV oOS as a new
**decision provider** — deliberately *not* as a chat provider. Jev returns
typed, probabilistic decisions (Choice / Score / Noul) from supplied state
instead of generated prose, in 70–500 ms at $0.042/M input tokens with output
free. It is distributed natively via `POST https://api.typesafe.ai/v1/systemone`
and via **OpenRouter's Decisions API** (a separate route from chat completions).

The plugin gains: a native TypeSafe client, an OpenRouter decisions bridge, a
provider settings subtab, model-catalog entries, and a base tool
(`typesafe_decide`) that exposes the three question primitives to assistants.

## Motivation / Gap Analysis

NV oOS is a chat-assistant framework, but a meaningful fraction of its
workloads are *decisions*, not prose:

- **Routing / triage** — the Language Model Router and support-style tools
  currently pay full chat-model prices and latency to pick a category.
- **Guardrails** — moderation and destructive-op pre-checks need a fast,
  cheap, confidence-carrying semantic check *below* the code-level gates.
- **Research / content pipelines** — Pro research tools classify documents
  against rubrics; doing that with a generative model is the dominant cost
  (the 1kpapers launch-week project: $3.99 of summaries vs $0.08 of Jev
  classifications for the same corpus).
- **Cascade economics** — TypeSafe's pattern: Jev routes the easy majority,
  a frontier model handles the hard minority. Industry reports put the
  equivalent pipeline at ~1/5th the cost with most requests answered in
  under half a second.

None of these fit the existing `Interface_WP_MCP_AI_Provider_Client`
(chat + streaming + tool calling). Forcing Jev behind it would break chat.
OpenRouter itself documents: *"chat completions SDKs will not work with it."*

## Design

### New seam: decision clients (separate from chat clients)

```
Interface_WP_MCP_AI_Decision_Client        (new) — decide( $state, $questions, $opts )
├── WP_MCP_AI_Typesafe_Client              (new) — native  POST {base}/v1/systemone
└── WP_MCP_AI_OpenRouter_Client::create_decision()  (extended) — OpenRouter Decisions route
WP_MCP_AI_Tool_Typesafe_Decide             (new base tool) — canonical envelope + two-gate sanitisation
```

- The decision contract is intentionally **not** a superset of the chat
  contract: no streaming, no tool calls, no text generation. Assistants keep
  their chat provider; `typesafe_decide` is an orthogonal capability.
- The OpenRouter bridge reuses the existing OpenRouter key, base URL, headers
  and error conventions, so sites already configured for OpenRouter can use
  Jev **without a TypeSafe account** (important while TypeSafe early access
  is waitlisted).

### Wire format (verified against docs + OpenRouter guidance)

Request: flat `{ "model": "jev-1.13.0", "state": <string|object|array>,
"questions": { "<name>": { "type": "choice|score|noul", "instructions": "...",
"criteria": ... } } }`.

Response: `{ "model": "<versioned-id>", "answers": { "<name>": { choice|score|noul
+ probabilities/confidence } }, "usage": { "input_tokens": n } }`.

- 64K tokens across state + all questions; 32K for state + longest question.
- Billing is input-only → cost calculator records $0 output rate.
- The response reports the concrete versioned model; we log it (pin-hygiene).

### Settings & surfaces

- New provider subtab **TypeSafe (Jev) — Decision Model** with
  `enable_typesafe`, `typesafe_api_key`, `typesafe_model` (default
  `jev-latest`, description advises pinning `jev-1.13.0`), `typesafe_base_url`
  (default `https://api.typesafe.ai`).
- `typesafe` joins **decision-appropriate** surfaces only: provider diagnostics,
  CLI provider command, cost calculator, token-usage labels, and the WP 7.0
  connectors bridge. It is deliberately **excluded** from every chat-provider
  surface (assistant provider dropdowns, onboarding wizard, default-provider
  validation, chat-client maps) — selecting Jev as a chat provider would break
  chat by design.
- Credential resolution is free: the credential resolver is provider-generic
  and already reads `typesafe_api_key` / `TYPESAFE_API_KEY`.
- Model catalog: `typesafe` entries (`jev-1.13.0`, `jev-latest`) with
  `supports_streaming: false`, `supports_function_calling: false`, output
  cost 0 and decision-only notes; an OpenRouter entry `typesafe/jev-1.13`
  flagged as requiring the Decisions route.

### Cloudflare — deferred pending verification

Cloudflare's catalog lists Jev as a third-party model, but there is no
evidence its Workers AI serves the weights. We add no Cloudflare wiring until
that is verified; the TypeSafe-native + OpenRouter transports are
unaffected.

## Tool contract (`typesafe_decide`)

- Schema: `state` (string|object|array), `questions` (map of
  `{type, instructions, criteria}`), optional `model`, optional `transport`
  (`typesafe|openrouter`), optional `confidence` floor filters.
- Two-gate sanitisation at entry (every `instructions`/`criteria` string);
  canonical success-array / `WP_Error` envelope at exit (P0 + sniffs).
- Adversarial-state warning in the description: `state` is prompt-injection
  surface; consequential actions must stay behind code gates (consistent
  with TypeSafe's own threat-model note and NV oOS security infrastructure).

## Testing

- `tests/test-typesafe-client.php` — request-shape (flat body), response
  parsing incl. fractional scores, 429/`retry-after`, missing-key WP_Error,
  input-only usage.
- `tests/test-typesafe-decide-tool.php` — sanitisation, envelope, capability,
  error paths.
- Settings-subtab checkbox test + catalog integrity test.
- Existing suite stays green (provider addition is additive).

## Risks & Mitigations

- **Waitlisted access.** Mitigated by the OpenRouter bridge (existing keys work).
- **Moving target** (`jev-latest` alias, SDK schema churn). We pin model IDs,
  version the body shape, log the answered version.
- **OpenRouter Decisions route is alpha.** Configurable endpoint; clean
  `WP_Error` with a "configure TypeSafe key" action when the route is absent.
- **Self-run benchmarks; "zero hallucination" = schema conformance only.**
  Docs are explicit: Jev judges supplied material, it does not verify it.
- **Adversarial state.** Documented on the tool; privileged decisions stay
  gated in code.
