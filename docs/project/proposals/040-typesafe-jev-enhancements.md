# Proposal 040 — TypeSafe Jev: Fidelity, Tooling, Service & Cost Enhancements

**Status:** 📝 Proposed — awaiting review
**Date:** 2026-09-22
**Scope:** Base plugin (`includes/`) + Pro follow-ups (`addons/pro/`)
**Related:** [`039-typesafe-jev-decision-provider.md`](./039-typesafe-jev-decision-provider.md) (implemented in v1.1.83, PR #6728); implementation plan: [`040-typesafe-jev-enhancements-implementation-plan.md`](./040-typesafe-jev-enhancements-implementation-plan.md)

## Summary

Proposal 039 shipped Jev as a first-class **decision provider** with a native
client, an OpenRouter Decisions bridge, the `typesafe_decide` base tool, and
three Pro seams (cascade routing, research source filtering, fail-open
classifier). A follow-up review against TypeSafe's **current** documentation
(patterns, primitives incl. "Advanced: structure", models, confidence, and
20+ official cookbooks), OpenRouter field reports, and community integrations
found that the shipped integration lags the live API in six places and leaves
a set of officially-documented, high-value patterns unimplemented.

This proposal closes the fidelity gaps, adds the highest-value official
patterns as first-class tools/services, and hardens the cost story (decision
caching, pre-flight token estimation, spend visibility) — all behind the
plugin's existing conventions (canonical envelope, two-gate sanitisation,
usage guidance interface, coverage manifest).

## Motivation / Gap Analysis

### A. Fidelity gaps vs. the current TypeSafe API (verified against docs.typesafe.ai)

1. **Noul criteria dropped.** The API accepts optional `criteria.true` /
   `criteria.false` to pin subtle yes/no boundaries.
   `WP_MCP_AI_Typesafe_Client::validate_question()` returns early for `noul`
   and `build_payload()` strips criteria for noul questions; the tool's
   `sanitize_questions()` does the same. Community integrations (e.g.
   oh-my-pi's OpenRouter transport) explicitly normalize noul criteria
   "to carry both true and false" — we do the opposite.
2. **Structured fields rejected.** The API accepts
   `string|object|array|null` (`EntryType`) for `instructions`, Choice
   option values, Score level descriptions, and Noul criteria. The client
   requires `instructions` to be a string. This blocks the officially
   documented **hierarchical classification** walk (option values carry the
   child subtree as JSON), structured rubrics, and the SDE-cascade pattern.
3. **`jev-preview` alias missing** from the settings model select and the
   model catalog (only `jev-latest` / `jev-1.13.0` are offered).
4. **OpenRouter default model likely broken.** The bridge constant
   `DECISIONS_DEFAULT_MODEL = 'typesafe/jev-latest'`, but field
   measurements report only `typesafe/jev-1.13` exists on OpenRouter
   ("there is no typesafe/jev-latest alias … that alias throws an error").
   A `model: "jev-latest"` override on the `openrouter` transport is also
   sent un-prefixed. (Verify empirically with a live call before locking
   the fix; the plan defaults to `typesafe/jev-1.13`.)
5. **Confidence floor filters dropped from the shipped tool.** Proposal 039's
   tool contract listed optional `confidence` floor filters; the shipped
   schema has none. TypeSafe's own confidence guidance is a three-path
   pattern (high → act, medium → confirm/review, low → human) with
   thresholds scaled to risk — currently the assistant must hand-parse
   `answers` to implement it.
6. **No automatic retry.** TypeSafe's SDKs retry with backoff by default and
   honour `retry-after`; the WP client only surfaces 429s as errors.

### B. Officially documented patterns without a first-class surface

| Pattern / Cookbook (TypeSafe docs) | Current state in NV oOS |
|---|---|
| Confidence-gated routing | Hand-rolled per caller; no `min_confidence` seam |
| Composite scoring (weights in code) | Not exposed; each caller re-implements |
| LLM guardrails (hazard screening, pass/review/block) | Listed as Phase-2 stretch in plan 039; `moderate_content` exists but no fast/cheap Jev path |
| Re-ranking / line-by-line search | Internal to the Pro research filter only |
| Skill suggestion (rank + re-check over a catalog) | No Jev path for the 74+41 bundled skills |
| Self-consistency / calibration evals | Docs advise "evaluate on your own traffic"; no harness exists |
| Date / pre-parsed value extraction | No tool; maps to CRM + medical-record toolkits |
| Citation checking | No seam in research/report tools |

### C. Cost & reach gaps (input-only billing makes these high-leverage)

- **No decision caching.** Identical (model, state, questions) requests
  re-bill and re-latency; industry standard is a short-TTL content-addressed
  cache for advisory decisions.
- **No pre-flight token estimation.** Cost = input tokens; nothing warns
  before sending oversized `state` against the 32K/64K budgets.
- **No spend visibility for decisions.** Usage exists but no cache-hit
  ($0) accounting or per-assistant decision totals.
- **Only the base URL is configurable.** The endpoint path is a fixed
  constant (`/v1/systemone`), so the waitlist workarounds that already
  serve Jev on different routes (tokenra.io `/v1/decisions`; Vercel AI
  Gateway — reportedly issues keys without the waitlist — Netlify AI
  Gateway, AIMLAPI, LiteLLM) cannot be reached through settings.

## Design

Four workstreams, all inside existing seams — no new provider surface, no
change to chat-provider selection, no schema break to `typesafe_decide`
(all changes are additive or error-tolerant).

### Workstream 1 — API fidelity fixes (Base)

- Accept optional structured `criteria` on `noul`; accept
  `string|object|array` for `instructions`, choice option values, score
  levels, and noul criteria, with the two-gate sanitisation extended to
  recursive structure (strings via `sanitize_text_field`, arrays walked).
- Add `jev-preview` to the settings model select and the model catalog.
- OpenRouter bridge: default the decisions model to `typesafe/jev-1.13`,
  and normalize incoming model overrides (strip `typesafe/`, map
  `jev-latest` → `jev-1.13`) for the decisions route.
- Add a bounded retry (default 2 attempts) honouring `retry-after` with
  backoff, retrying only 429/5xx; filterable via
  `wp_mcp_ai_typesafe_retry_attempts`.
- Restore the confidence floor on the tool: optional `min_confidence` per
  question (map), with answers below the floor still returned (values stay
  visible, per TypeSafe's self-consistency cookbook) and flagged
  `below_threshold`.

### Workstream 2 — Tools & services (Base + Pro)

- **Composite scoring** (Base): `weights` arg on `typesafe_decide` combines
  score answers locally (no extra API call) into a weighted composite —
  the official pattern, done in code as prescribed.
- **`typesafe_guardrail`** (Base tool + Pro seam): hazard questions with
  per-category thresholds; returns pass/review/block per the LLM-guardrails
  cookbook. Pro wires it as the guest-chat moderation seam (the plan-039
  Phase-2 stretch item).
- **`typesafe_rerank`** (Pro): general re-ranking of search/vector-store
  result sets (score per candidate in one batched call), generalizing the
  research filter's retrieve-then-judge loop.
- **Decision evals** (Pro service + tool + CLI): store labeled examples,
  run Jev, report accuracy-by-confidence-bucket and agreement — an
  on-traffic calibration harness.
- **Skill selection** (Pro): two-stage rank + re-check over the bundled
  skill catalog, integrated with `load_skill`.
- **Citation checking** (Pro): opt-in seam in `generate_research_report` /
  `research_eca` verifying claims against source passages.
- **Extraction tools** (Pro, stretch): `typesafe_extract_dates` +
  pre-parsed value extraction for CRM/medical-record toolkits.

### Workstream 3 — Cost & reach (Base)

- **Decision cache**: opt-in (`enable_typesafe_cache`, default off),
  transient-backed, keyed on `md5(provider|model|state|questions)`,
  filterable TTL (`wp_mcp_ai_typesafe_cache_ttl`, default 300 s), version
  bumped on settings/model changes. Cache hits are marked `cached: true`,
  recorded at $0 usage, and never used to gate state-changing operations
  (decisions are advisory by contract).
- **Pre-flight estimation**: advisory `warnings` in the tool envelope when
  estimated input tokens exceed a filterable threshold; reuses
  `count_tokens`.
- **Endpoint filter**: `wp_mcp_ai_typesafe_endpoint` (path, default
  `/v1/systemone`) so gateway/reseller routes work through settings; docs
  list the verified access paths with their caveats (tokenra.io is a
  third-party reseller — document, don't default).
- **Spend visibility**: TypeSafe decision line in the usage monitor with
  cache-hit accounting.
- **Bundled skill** `jev-decisions` in `includes/bundled-skills/`
  (question design, batching/fan-out, thresholds, `other` option) —
  mirrors TypeSafe's own agent-skill distribution.

## Testing

- Extended `tests/test-typesafe-client.php` (noul criteria, structured
  fields, retry, cache) and `tests/test-typesafe-decide-tool.php`
  (`min_confidence` flags, composite weights, warnings, cache-hit
  envelope); `tests/test-openrouter-client.php` for model normalization.
- New suites per new tool (`typesafe_guardrail`, `typesafe_rerank`, eval),
  following the tool-suite conventions (metadata, schema, sanitisation,
  envelope, capability, error paths).
- Pro tests mirror the existing Jev suites (`test-pro-jev-classifier.php`
  pattern) for the guardrail seam, rerank, eval, skill selection, and
  citation seams — every seam fails open.
- Coverage manifest + `tool-status.txt` + preset (`ai_ml`) entries for
  every new tool slug.

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| OpenRouter alias reports conflict (some integrations use `typesafe/jev-latest`) | Verify with a live call before locking; default to the confirmed `typesafe/jev-1.13`; log the answered model either way |
| Structured fields widen the sanitisation surface | Recursive two-gate walk; only plain JSON (no HTML) allowed in structured fields; escape at exit unchanged |
| Cached decisions go stale (alias drift, policy changes) | Opt-in, short TTL, version-bumped on settings change, `cached` flag always surfaced, never used for privileged gates |
| Retry can amplify cost | Bounded attempts, 429/5xx only, honour `retry-after`, no retry on 4xx auth |
| Third-party gateways (tokenra.io etc.) are resellers with their own margins/terms | Document as access paths with caveats; never default to them; endpoint filter only |
| Eval harness becomes a support burden | Pro-only, minimal scope (labeled examples + bucket report), CLI-first |
| Non-English state accuracy (TypeSafe documents English-first) | Retain the existing caveat in tool descriptions; guardrail thresholds conservative |

## Sources

- TypeSafe docs: System One, [Primitives](https://docs.typesafe.ai/primitives.md), [Advanced: structure](https://docs.typesafe.ai/primitives/advanced.md), [Models](https://docs.typesafe.ai/models.md), [Confidence](https://docs.typesafe.ai/confidence.md), [Patterns](https://docs.typesafe.ai/patterns.md) (fan-out, confidence-routing, composite-scoring, intent-routing), Cookbooks ([parallel questions](https://docs.typesafe.ai/cookbooks/parallel_questions.md), [LLM guardrails](https://docs.typesafe.ai/cookbooks/llm_guardrails.md), [re-ranking](https://docs.typesafe.ai/cookbooks/rerank_typesafe.md), [line-by-line search](https://docs.typesafe.ai/cookbooks/semantic_find.md), [skill suggestion](https://docs.typesafe.ai/cookbooks/skill_suggestion.md), [self-consistency](https://docs.typesafe.ai/cookbooks/consistency_noul_cookbook.md), [hierarchical classification](https://docs.typesafe.ai/cookbooks/hierarchical_classification.md), [date extraction](https://docs.typesafe.ai/cookbooks/date_extraction_cookbook.md), [pre-parsed value extraction](https://docs.typesafe.ai/cookbooks/pre_parsed_value_extraction_cookbook.md), [citation check](https://docs.typesafe.ai/cookbooks/citation_check.md), [SDE cascade](https://docs.typesafe.ai/cookbooks/sde_cascade.md), [jaggedness](https://docs.typesafe.ai/model-jaggedness/jev-1.13.md))
- OpenRouter: [typesafe/jev-1.13](https://openrouter.ai/typesafe/jev-1.13) + [field measurements](https://www.reddit.com/r/hermesagent/comments/1wlfgg0/what_i_measured_calling_jev_typesafes_decision/) + [jevaiguide](https://jevaiguide.com/channels/openrouter/)
- Community integrations: pydantic-ai [#8552](https://github.com/pydantic/pydantic-ai/issues/8552), oh-my-pi [#12458](https://github.com/can1357/oh-my-pi/issues/12458), skillbox [#4](https://github.com/kitze/skillbox/issues/4)
- Access paths: [jevapi.org](https://jevapi.org/), [AIMLAPI](https://docs.aimlapi.com/api-references/decision-models/typesafe/jev)
