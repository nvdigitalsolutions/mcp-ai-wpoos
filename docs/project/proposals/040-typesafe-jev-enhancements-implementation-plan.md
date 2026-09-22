# Implementation Plan 040 — TypeSafe Jev: Fidelity, Tooling, Service & Cost Enhancements

**Status:** 🚧 In progress — Phases 0–2 implemented (branch `add/typesafe-jev-enhancements-impl`), Phase 3 partially shipped (spend-visibility aliases + gateway access docs landed with Phases 0/1); extraction tools remain stretch
**Date:** 2026-09-22
**Proposal:** [`040-typesafe-jev-enhancements.md`](./040-typesafe-jev-enhancements.md)
**Branch:** `add/typesafe-jev-enhancements-impl` (PR against `alpha-working` per repo convention)

## Design decisions (locked)

1. **No new provider surface.** Everything lands inside the existing
   `Interface_WP_MCP_AI_Decision_Client` seam, the `typesafe` settings
   subtab, and the existing tool/registry conventions. Chat-provider
   selection surfaces stay untouched (same exclusion list as plan 039
   decision 2).
2. **All `typesafe_decide` changes are additive.** The shipped schema
   (`state`, `questions`, `model`, `transport`) gains optional
   `min_confidence` (map of question name → floor) and `weights` (map of
   score-question name → weight, composite scoring). Answers below a floor
   are still returned, flagged `below_threshold: true` — values stay
   visible per TypeSafe's self-consistency cookbook.
3. **Structured fields (`EntryType`) support.** `instructions`, Choice
   option values, Score level entries, and Noul `criteria.true/false`
   accept `string|object|array`. Sanitisation extends the existing
   recursive walk (two-gate, gate one): strings via `sanitize_text_field`,
   arrays walked recursively, keys via `sanitize_key`; plain JSON only —
   no HTML is ever passed in structured fields.
4. **OpenRouter decisions model normalization.** Bridge default becomes
   `typesafe/jev-1.13` (the empirically confirmed ID). Incoming `model`
   overrides for the `openrouter` transport are normalized: strip a
   `typesafe/` prefix, map `jev-latest` → `jev-1.13`. **Gate:** one live
   call must confirm the alias behavior before this ships; the constant
   ships as `typesafe/jev-1.13` either way.
5. **Retry policy.** Bounded (default 2 attempts, filterable
   `wp_mcp_ai_typesafe_retry_attempts`, 0 disables), only on 429/5xx,
   exponential backoff honouring `retry-after`, never on 4xx auth errors.
   Applies to both the native client and the OpenRouter decisions bridge.
6. **Decision cache is opt-in, advisory, and bounded.**
   `enable_typesafe_cache` setting (default off), transient key
   `wp_mcp_ai_ts_dec_<md5(provider|model|state|questions)>`, TTL filter
   `wp_mcp_ai_typesafe_cache_ttl` (default 300 s), version prefix bumped
   on any TypeSafe settings save. Cache hits are marked `cached: true` in
   the envelope, recorded at $0 usage, and never used to gate
   state-changing operations.
7. **New tools follow the current tool contract** (v1.1.83 gate): canonical
   envelope, two-gate sanitisation, `WP_MCP_AI_Tool_Usage_Guidance_Interface`
   guidance, `ai_ml` preset + coverage-manifest + `tool-status.txt` entries,
   `manage_options` declared and enforced (mirrors `typesafe_decide`).
8. **Every new Pro seam fails open** — identical pattern to
   `WP_MCP_AI_Pro_Jev_Classifier` (any Jev error or missing credential ⇒
   unfiltered/unmodified pass-through, silent).
9. **Third-party gateways are documented, never defaulted.** The endpoint
   filter enables them; tokenra.io and the AI gateways are listed as
   reseller access paths with caveats in `docs/features/ai-providers/typesafe.md`.

## Phase 0 — API fidelity fixes (Base) ✅ Complete

> Implemented on `add/typesafe-jev-enhancements-impl` (commit `3c5a793564`).
> Deviation: the decision-cache key embeds endpoint/base/model/payload instead
> of a settings-save version bump — functionally equivalent invalidation, no
> save-hook surface.

### 0.1 Changed files

| File | Change |
|---|---|
| `includes/class-wp-mcp-ai-typesafe-client.php` | `validate_question()` (currently L214–293): allow optional structured `criteria` on `noul`; accept `string|object|array` instructions per `EntryType`. `build_payload()` (L303–347): stop stripping noul criteria; pass structured fields through the sanitisation walk. `decide()` (L359–457): bounded retry loop around the HTTP call (429/5xx only, honour `retry-after`, `wp_mcp_ai_typesafe_retry_attempts`). New `get_endpoint()` honouring `wp_mcp_ai_typesafe_endpoint` (default `/v1/systemone`). New cache layer: `maybe_get_cached()` / `cache_decision()` helpers in `decide()` (opt-in, key + TTL per decision 6). |
| `includes/tools/class-wp-mcp-ai-tool-typesafe-decide.php` | Schema: optional `min_confidence` (map question→float 0–1) and `weights` (map score-question→positive float). `sanitize_questions()`: accept structured noul criteria. `execute()`: after normalize, (a) attach `below_threshold` flags for answers under their floor, (b) compute `composite` when `weights` given (sum of weighted scores ÷ sum of weights — code-only, no extra API call), (c) attach advisory `warnings` when estimated input tokens exceed `wp_mcp_ai_typesafe_warn_tokens` (default 24000; estimate via `WP_MCP_AI_Token_Usage_Service` / `count_tokens` heuristic — strlen/4 fallback). Envelope gains optional `composite`, `warnings`, and per-answer `below_threshold`; `cached` flag surfaces from the client. |
| `includes/class-wp-mcp-ai-openrouter-client.php` | `DECISIONS_DEFAULT_MODEL` → `typesafe/jev-1.13`; `create_decision()` normalizes the model override (prefix strip + `jev-latest` → `jev-1.13`); shared retry helper honoured. |
| `includes/data/model-catalog.json` | Add `jev-preview` alias entry for provider `typesafe` (same cost/context/flags as the jev entries, `notes` flags it as the preview alias). Bump `version`/`updated_at`. |
| `includes/admin/sections/class-wp-mcp-ai-section-providers.php` | `typesafe` subtab group gains: `typesafe_model` option `jev-preview`; new `enable_typesafe_cache` (checkbox, default false) with TTL hint; new `typesafe_endpoint` (text, optional, placeholder `/v1/systemone`, description notes gateway/reseller routes). |
| `includes/class-wp-mcp-ai-token-usage-service.php` | Record `cached` (bool) on typesafe usage entries; cache hits record `input_tokens: 0, output_tokens: 0` (spend $0). |
| `includes/admin/class-wp-mcp-ai-provider-diagnostics.php` | TypeSafe card shows cache state + retry setting; probe unchanged. |

### 0.2 Tests

| File | Coverage |
|---|---|
| `tests/test-typesafe-client.php` (extend) | Noul criteria serialized with `criteria.true/false`; structured instructions/criteria accepted and walked; string instructions still work; retry: 429 with `retry-after` retries then succeeds; 429 without header retries with backoff up to `wp_mcp_ai_typesafe_retry_attempts`; 401 never retried; cache: hit returns `cached: true` envelope without an HTTP call (`pre_http_request` counter), TTL expiry re-fetches, settings-version bump invalidates; endpoint filter changes the URL path. |
| `tests/test-typesafe-decide-tool.php` (extend) | `min_confidence` flags answers below floor without dropping them; `weights` computes the expected composite (fractional score values); `warnings` emitted above the token threshold; `cached` flag passthrough; schema declares the new properties. |
| `tests/test-openrouter-client.php` (extend) | Default model sent is `typesafe/jev-1.13`; `model: "jev-latest"` override normalized to `typesafe/jev-1.13`; `model: "typesafe/jev-1.13"` passes through unchanged. |

### 0.3 Docs

- `docs/features/ai-providers/typesafe.md`: new sections — structured fields, noul criteria, `min_confidence` + composite scoring, caching, retries, endpoint override, gateway access paths (Vercel AI Gateway, Netlify, AIMLAPI, LiteLLM, tokenra.io with reseller caveats), `jev-preview`, jaggedness link.
- `docs/reference/tools/tool-reference.md`: update the `typesafe_decide` entry for the new arguments.

## Phase 1 — Base tools ✅ Complete

> Implemented on `add/typesafe-jev-enhancements-impl` (commit `5ce809d4e8`).
> Deviation: the bundled skill ships as `mcp-ai-wpoos-jev-decisions`
> (plugin-domain naming family, per owner decision) — the sync-script flow is
> unchanged.

### 1.1 `typesafe_guardrail` (Base tool)

- File: `includes/tools/class-wp-mcp-ai-tool-typesafe-guardrail.php`; registry entry in `includes/class-wp-mcp-ai-tool-registry.php` (`load_default_tools()`); `ai_ml` preset; coverage manifest; `tool-status.txt` → `beta`.
- Contract: `state` (message/content to screen), `hazards` (map of category → `{instructions, thresholds: {review, block}}` or a built-in default hazard set), optional `model`/`transport` passthrough. Each hazard is one noul question (default set mirrors the LLM-guardrails cookbook: harassment, prompt-injection, self-harm, PII disclosure, etc.).
- Exit: canonical envelope with per-category `{probability, verdict: pass|review|block}` derived in code from the thresholds; `block` implies the caller must not forward the content — the tool never blocks anything itself (advisory, code gates remain).
- Capability `manage_options` (mirrors `typesafe_decide`); flags `external-api`, `read-only`, `requires-capability`, `network-dependent`; guidance interface present.

### 1.2 Bundled skill `jev-decisions` (Base)

- File: `includes/bundled-skills/jev-decisions/SKILL.md` (YAML frontmatter `type: Skill`, name/description per the existing bundled-skill convention; regenerates into the `skill-knowledge` OKF bundle per the existing generator).
- Content: when to use Jev vs. chat models, question design (explicit `other` option, 2–10 score levels, noul criteria), batching/fan-out (parallel questions ≈ no extra latency), confidence three-path thresholds, pinning, adversarial-state caveat, composite scoring with `weights`.

## Phase 2 — Pro integrations (separate cluster, post-merge) ✅ Complete

> Implemented on `add/typesafe-jev-enhancements-impl` (commit `cac689951f`).
> Deviations: the guardrail seam reuses the existing Layer I
> `wp_mcp_ai_pre_chat_message` pre-screen filter (no new base hook); the eval
> harness accepts inline labeled examples only (no CPT persistence — report-only);
> skill selection ships as the `typesafe_skill_select` tool (advisory, used
> before `load_skill`) instead of modifying the base `load_skill` flow.

| Item | File(s) | Notes |
|---|---|---|
| Guest-chat moderation seam | New service `addons/pro/includes/services/class-wp-mcp-ai-pro-jev-guardrail.php` + seam in the guest-chat path | Calls the base guardrail (fail-open: on any error the message passes); gated by an opt-in setting on the TypeSafe subtab (`enable_jev_guest_guardrail`, default off). Reuses the plan-039 Phase-2 stretch intent. |
| `typesafe_rerank` tool (Pro) | New `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-typesafe-rerank.php` | Generalizes `filter_sources_by_relevance()`: candidates (search results, vector-store hits, media) + query → one batched score question → reorder + drop below a floor, keep-min preserved; fails open. Registered per Pro tool conventions. |
| Decision evals | New Pro service `WP_MCP_AI_Pro_Jev_Eval` + tool `typesafe_eval` + CLI `wp mcp-ai jev eval` | Labeled examples (toolkit CPT or option group, Pro-only), runs Jev over the set, reports accuracy + accuracy-by-confidence-bucket + label agreement (self-consistency cookbook). Never trains anything — report-only. |
| Skill selection | New Pro helper `suggest_skills()` (rank + re-check over the bundled skill catalog) integrated into the `load_skill` flow | Two-request pattern per the skill-suggestion cookbook; advisory only. |
| Citation checking seam | `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-generate-research-report.php` + `eca-management/class-wp-mcp-ai-tool-research-eca.php` | Opt-in setting `enable_jev_citation_check` (default off); one choice question per citation vs. its source passage; failures fail open; results surface in the report envelope. |
| Extraction tools (stretch) | `typesafe_extract_dates` + pre-parsed value extraction in CRM/medical toolkits | Deferred until the toolkit owners sign off; sketched in the proposal only. |

## Phase 3 — Cost & reach polish (Base) ✅ Partial

> Spend-visibility aliases (`prompt_tokens`/`completion_tokens` + `cached`
> zeroed usage) and the gateway access docs shipped with Phases 0/1. The
> usage-monitor dashboard line item remains release-window work.

| Item | File(s) | Notes |
|---|---|---|
| Spend visibility | `includes/services/class-wp-mcp-ai-token-usage-service.php` + usage-monitor dashboard surfaces | TypeSafe decision line item with cache-hit ($0) accounting; per-assistant decision totals where the existing provider breakdown lives. |
| Gateway access docs | `docs/features/ai-providers/typesafe.md` | Verified paths + reseller caveats (see 0.3). |

## Explicitly NOT changed

- Chat-provider selection surfaces (dropdowns, onboarding wizard, default-provider validation, router/dispatcher maps) — unchanged from plan 039 decision 2.
- NV Cloud passthrough — still blocked on OpenRouter GA'ing the Decisions route (unchanged deferred item).
- Cloudflare direct — still unverified whether Workers AI serves Jev weights.
- Content Graph port — remains the ecosystem-port loop's cluster (interface + client + tool + catalog → `plugins/nvoos-content-graph-ai`), now additionally includes Phase 0's fidelity changes when that cluster runs.
- No schema removal or renaming: every existing `typesafe_decide` call keeps working.

## Verification gates

1. `vendor/bin/phpunit tests/test-typesafe-client.php tests/test-typesafe-decide-tool.php tests/test-openrouter-client.php` (Phase 0).
2. New-tool suites + `tests/test-assistant-tool-presets.php` (preset `ai_ml` additions) + coverage-manifest gate (Phase 1).
3. Pro: extended `addons/pro/tests/test-pro-jev-classifier.php`-style suites for the guardrail seam, rerank, eval, skill selection, citation check — each fail-open path asserted (Phase 2).
4. `composer run lint` (WPCS + `WPMCPAI.Tools.CanonicalReturnEnvelope` + `WPMCPAI.Tools.SanitizeAtEntry` + `WPMCPAI.Tools.ToolDescriptionGuidance` on every new tool class).
5. Full suite in Docker before each PR (per test-suite skill gate).
6. One live TypeSafe + one live OpenRouter smoke call (documented in the PR body) to confirm the alias fix and structured-field wire format.

## Risk register

| Risk | Mitigation |
|---|---|
| OpenRouter alias conflict between reports | Live-call gate (decision 4); default `typesafe/jev-1.13` either way |
| Structured fields widen sanitisation surface | Recursive two-gate walk, plain JSON only, escape at exit unchanged |
| Cache staleness / alias drift | Opt-in, short TTL, settings-version bump, `cached` flag surfaced, never used for privileged gates |
| Retry amplifies cost | Bounded attempts, 429/5xx only, honour `retry-after`, no 4xx retries |
| Reseller gateways (tokenra.io, gateways) have own terms/margins | Documented access paths only; never defaulted |
| Eval harness scope creep | Pro-only, report-only, CLI-first |
| Guardrail false blocks | Advisory verdicts only; enforcement stays in code gates; conservative thresholds |

## Sequence & branches

1. ~~This branch: proposal + plan docs (this PR).~~ ✅ PR #6746
2. ~~Cluster A (Phase 0, Base)~~ ✅ `add/typesafe-jev-enhancements-impl` — commit `3c5a793564`
3. ~~Cluster B (Phase 1, Base)~~ ✅ same branch — commit `5ce809d4e8`
4. ~~Cluster C (Phase 2, Pro)~~ ✅ same branch — commit `cac689951f`
5. Cluster D (Phase 3 remainder: usage-monitor line item + release-note counts) — release-window work.
6. Deferred sweeps: extraction tools, CG port (fidelity changes included), NV Cloud when OpenRouter GA's.
