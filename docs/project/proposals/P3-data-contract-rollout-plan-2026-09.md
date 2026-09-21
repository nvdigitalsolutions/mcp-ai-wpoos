# P3 Data-Contract Rollout — ID-Handoff Verification Plan

**Status:** IN PROGRESS — Wave 1 (base families) landed via PRs #6729–#6731; Pro `schedule_id` wave (#6732) queued; docs sweep in progress.
**Related:** [Unix Theory Compliance Enhancement Proposal](UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md) (Phase P3).
**Problem class:** multi-step MCP workflows fail because state (IDs) does not flow between tool calls — the model compensates by searching for records "another way". The P3 infrastructure (interface + registry helper + description suffix) has shipped, but zero production tools implement it.

## Goals

Close three gaps:

| Gap | Fix |
|---|---|
| A. Zero production tools implement `WP_MCP_AI_Tool_Data_Contract_Interface` | Wave-based annotation rollout |
| B. No registry-wide symmetry test (producer key ↔ consumer schema) | Static "honesty" suite + deterministic round-trip suite |
| C. Usage-guidance notes don't document ID handoffs | Handoff-note fixes in the same wave |

## Design decisions

**D1 — Contract vocabulary = exact parameter key names.** A contract label must be the *exact key* that flows between tools: `job_id`, `post_id`, `assistant_id`, `room_id`, `schedule_id`, … A tool `produces X` iff its documented success payload carries key `X`; a tool `consumes X` iff its parameter schema has property `X`. No production tool implements the interface yet, so this is a free, back-compat-safe tightening. Touch point: update the docblock example in `includes/interfaces/interface-wp-mcp-ai-tool.php` from `post_object` → `post_id`; **leave the fixtures in `tests/test-tool-data-contract.php` untouched** — they test the mechanism, not the vocabulary.

**D2 — Scope = ID-bearing CRUD families only.** The ~1,585-tool registry will *not* be fully annotated. Qualifying rule: a tool joins if (a) its primary output is an identifier another tool consumes, or (b) an identifier produced by another tool is its primary input. Everything else stays contract-less **by design** (no suffix, no token cost).

**D3 — Three test layers, no new PHPCS sniff.**

- **L1 static honesty** (CI, fast): every `consumes` key appears in that tool's own parameter schema; every annotated tool's contract is listed in the manifest.
- **L2 round-trip execution** (CI, deterministic, no LLM): create → extract produced key from the real envelope → get by that key → assert identity → update/delete where the family has them. Extends the existing `test_complete_cron_workflow` pattern.
- **L3 payload regression**: real annotated tools flow through `WP_MCP_AI_Tool_Service::build_tools_payload()` — suffix present, OpenAI strict schema intact.

**D4 — Manifest file = single source of truth.** The Phase 0 inventory is committed as a fixture (`tests/fixtures/tool-contract-manifest.php`) that the L1 suite checks against in both directions. Unvetted annotations fail CI; legitimate producer-without-consumer leaves are declared explicitly.

## Phases

| Phase | Description | Effort | Status |
|---|---|---|---|
| **0** | Inventory + vocabulary + manifest fixture + D1 docblock tweak | S | ✅ Done — inventory found and fixed three live asymmetries (`ID` vs `post_id` on posts, `data.id` vs `vector_store_id`, missing handoff notes on cron guidance) |
| **1** | L1 static honesty suite (`tests/test-tool-id-handoff-contract.php`) | S | ✅ Done (#6729) |
| **2** | Wave 1 rollout — cron, posts, terms, assistants, vector stores, batches (+ webchat deferred: registration gated behind `enable_webchat_integration`) | S per cluster | ✅ Done (#6729–#6731) |
| **3** | L2 round-trip execution suite + shared assertion trait | S | ✅ Done (#6729, #6730, #6732 drivers) |
| **4** | Waves 2–3 — Pro families (`schedule_id` landed in #6732) + remaining toolkit CPTs; byte-identical CG Pro port for any Pro files that have mirrors | M | 🟡 Wave 2a in PR #6732; CG port not needed for schedule tools (not yet ported there) |
| **5** | Docs: `.context/tool-registry.md`, `docs/reference/tools/tool-reference.md`, proposal status update; completeness sweep | XS | 🟡 Docs landed with this PR; completeness sweep pending |

## Wave 1 families (Phase 2)

| Family | Contract | Produces | Consumes |
|---|---|---|---|
| Cron | `job_id` | `create_cron_job`, `create_cron_job_validated` | `get_cron_job`, `delete_cron_job` |
| Posts | `post_id` | `create_post`, `create_post_validated`, `save_post` | `get_post`, `delete_post`, `save_post` |
| Terms | `term_id` | `create_term` | `update_term` (delete-term TBD) |
| Assistants | `assistant_id` | `create_assistant`, `create_assistant_validated`, `duplicate_assistant` | get/update/delete + `import_assistant` |
| Vector stores | `vector_store_id` | `create_vector_store` | `get_vector_store`, `manage_vector_store_files` |
| Batches | `batch_id` | `create_batch` | `get_batch_status` |
| Webchat rooms | `room_id` | `create_webchat_room` | `get_webchat_room`, `get_webchat_messages`, `save_webchat_message` |

*(Final producer/consumer lists come from the Phase 0 inventory — never guess; read `execute()` return arrays and `get_parameters_schema()` from source.)*

## Cluster workflow

Per the test-suite skill: branch `fix/<cluster-slug>` from `origin/alpha-working`, stage explicit paths only, validate on WP 6.9 + 7.1 (cross-worktree one-off runner when `oos-wp` mounts a different worktree) + phpcs, PR base `alpha-working`, user merges manually. Manifest entries land in the same PR as their annotations (keeps the reverse drift check green).

## Risks

| Risk | Mitigation |
|---|---|
| Inventory misreads a return key (contract lies) | L2 round-trip tests execute the real path and fail loudly |
| Description suffix breaks a provider | Additive only; strict-schema tests exist; `wp_mcp_ai_tool_data_contract_description_suffix` kill-switch per tool |
| Pro / CG Pro drift | Wave 4 port is byte-identical with its own verifier step |
| Scope creep to all tools | D2 rule + manifest reverse check fails unvetted annotations |
| Token cost growth | Only ID-bearing families get the suffix; measure on wave 1 |

## Non-goals

- No new PHPCS sniff, no registry API changes, no orchestrator/agentic-loop code changes.
- No live-LLM E2E in CI (manual periodic only).
- No annotation of stateless utilities, image/video generators, or search tools.
