---
type: Skill
name: mcp-ai-wpoos-updates
description: Operational guide for the two recurring NV oOS maintenance tracks — (1) Docs & Release Catch-Up: the plan-first per-version documentation/context/version-bump pass (plan template, P0–P3 work items, commit breakdown, branch+PR conventions, validation sweeps, stale-ZIP housekeeping, skill-count bookkeeping); (2) Model Catalog & Config Updates: the monthly model-config refresh across includes/data/model-catalog.json and its ~24 derived files (migration map, settings defaults, selector/router, provider clients, token budgets, cost tables, admin placeholders, Pro tool enums). Use when asked to "catch up docs", "do the docs catch-up for version X", "complete the release notes", "update the model configs", "refresh the model catalog", or "do the same monthly model exercise".
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.70"
  plugin-version-tested: "1.1.70"
  last-updated: "2026-09-05"
---

# NV oOS Updates — Docs Catch-Up & Model Catalog Maintenance

Playbook for the two recurring update tracks in this repo, distilled from the
executed catch-up plans (`docs/project/plans/v1.1.58-docs-catch-up.md` through
`v1.1.70-docs-catch-up.md`) and the model-catalog process docs. The workflows
implement industry standards — Keep a Changelog, SemVer commit separation, and
deprecation-driven LLM model lifecycle management — adapted to this repo's
conventions.

## When to use this skill

**Track A — Docs & Release Catch-Up:**
- "Catch up docs/release notes for vX.Y.Z" / "do the same exercise for this version"
- A window of PRs merged on `alpha-working` has no release-note coverage
- Version bump + changelog + agent-context sweep for a new release

**Track B — Model Catalog & Config Updates:**
- "Update model configs" / "refresh the model catalog" / "new model lineup"
- Provider deprecations, price changes, new default models
- The monthly model-update exercise

Both tracks can run in the same window: Track B's catalog changes always get a
changelog mention, folded into Track A's release surfaces.

---

## Track A — Docs & Release Catch-Up

### A0. Orientation (before touching anything)

1. **Check `git log` first** — another agent may have already executed parts of
   the window. Compare against the latest executed plan's execution log.
2. **Read `docs/project/plans/docs-catch-up-open-items.md` FIRST** — it holds
   every standing open item (OI-1 `@since` reconciliation, OI-2 Docker count
   re-derivation, OI-3 test-suite cross-ref, OI-4 wave residuals). Parked items
   stay parked; new finds get *recorded* there, never fixed in-pass.
3. **Read the template plans** — the latest executed plan (e.g.
   `v1.1.70-docs-catch-up.md`) plus `v1.1.58`/`v1.1.59` for the original
   structure.
4. **Identify the PR window** — everything merged on `alpha-working` after the
   previous catch-up merge. Classify every PR: production-touching (file + change
   table), test-only, docs-only, build-only, closed-unmerged docs PRs.
5. **Create the plan** `docs/project/plans/[VERSION]-docs-catch-up.md` if none
   exists, with the v1.1.70 structure:
   1. Context — PR table + scope rules
   2. Work items (P0–P3 + Verify-only)
   3. Execution log (stamped when run)
   4. Commit breakdown
   5. Validation
   6. Open items — point at the standing tracker, add only new items
6. **Read every PR description in the window.** `@since` tags that don't match
   the shipping version go to OI-1 as a new group (recorded, **not** fixed).
   Residual test-suite findings from PR notes go to OI-4 (hand to the test-suite
   workstream, not the docs pass).

### A1. Work items by priority

| Priority | Scope | Files |
|---|---|---|
| **P0** Release-note surfaces (user-facing) | Changelog, wp.org readme, repo readme, quick reference, doc index, reviewer notes | `CHANGELOG.md`, `readme.txt`, `README.md`, `docs/QUICK_REFERENCE.md`, `docs/DOCUMENTATION_INDEX.md`, `docs/project/FOR_REVIEWERS.md` |
| **P1** Agent context files | Subsystem context stamps + notes, agent configs, skill copies | `.context/tool-registry.md`, `.context/security-checklist.md`, `.context/testing.md`, `.context/rest-api.md`, `CLAUDE.md`, `MAINTAINER_MAP.md`, `.bmad/README.md`, all three `mcp-ai-wpoos-plugin` SKILL.md copies, `.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md` |
| **P2** Operational & feature docs | Inventory stamps, open-items tracker, this plan | `docs/project/ADDON_INVENTORY.md`, `docs/reference/tools/tool-reference.md`, `docs/project/plans/docs-catch-up-open-items.md`, `docs/developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md`, the plan doc itself |
| **P3** Version bump (mechanical) | Version headers + constants only | `mcp-ai-wpoos.php`, `includes/bootstrap/constants.php`, `addons/pro/mcp-ai-wpoos-pro.php`, `package.json` — **never** `package-lock.json` |
| Verify-only | Confirm no change; list in the plan | Skill counts, tool counts, addon/toolkit counts, proposals index, Docs Hub changelog |

### A2. Scope rules (non-negotiable)

- **Historical per-version entries stay untouched.** Old CHANGELOG/README blocks
  (`[1.1.XX]` and earlier) are history and are never rewritten.
- **Tool counts use `~`** with the live-registry caveat; counts change only when
  tools are added/removed (delta-derived — OI-2's live re-derivation is parked).
- **Sub-projects keep their own tracks:** Media Worker v3.x, Docs Hub 0.4.x,
  standalone plugins (nvoos-content-graph 1.0.x, -ai 1.0.x, -ai-platform 2.0.x).
- **Parked items stay parked:** OI-1 `@since` reconciliation and OI-2 Docker
  count derivation are user-deferred — record, never re-attempt.
- **Exclude unrelated working-tree noise** (vendor/, other agents' untracked
  work, backup dirs) from every commit.

### A3. Commit structure (mirror v1.1.58–v1.1.70)

Separate commits, in this order:

1. `chore: bump version to [VERSION]` — version headers, constants, Pro addon,
   `package.json`.
2. `docs: add [VERSION] changelog and release notes` — `CHANGELOG.md`,
   `README.md`, `readme.txt`, `docs/QUICK_REFERENCE.md`,
   `docs/DOCUMENTATION_INDEX.md`, `docs/project/FOR_REVIEWERS.md`.
3. `docs: update agent context for [VERSION] changes` — `.context/*`, `CLAUDE.md`,
   `MAINTAINER_MAP.md`, affected `.agents/skills/*`.
4. `docs: reconcile [VERSION] reference surfaces` — `ADDON_INVENTORY.md`,
   proposals index, addon READMEs, tool-reference header, the plan doc itself.
5. **Optional:** `docs: reconcile remaining [VERSION] tool counts` — only when
   counts changed; grep for stale counts (e.g. `~265`, `~1,508`) and skip
   historical per-version blocks, plans, and proposals.

### A4. Housekeeping (when requested)

- Remove stale build ZIPs: delete all `build/**/*[OLD_VERSION]*` (ZIPs **and**
  `.sha256` files). Commit: `chore: remove stale [OLD_VERSION] build ZIPs [skip ci]`.

### A5. New coding-time skill bookkeeping (when a skill is added)

Per `AGENTS.md` §6, when adding a skill under `.agents/skills/[slug]/`:

- Update the skill count (e.g. 53 → 54) in `AGENTS.md` §1 (inventory row + the
  coding-time-vs-runtime paragraph), `.github/copilot-instructions.md`
  (multi-agent-awareness bullet + repo-tree comment), and the `README.md` repo
  map row.
- Fold the new skill + count into the **next** release's changelog/README
  "Versioning" line (do not post-hoc edit an executed release entry).
- Leave historical per-version count lines untouched.

### A6. Validation

- `php -l` on every bumped PHP file; JSON-parse `package.json`.
- `git grep` sweeps for the old version string and stale counts — remaining hits
  must be intentional history only (`@since` docblocks, per-version blocks,
  plans, archive).
- Verify every claim in the plan (file paths, counts, versions) against the
  actual PRs before marking executed.

### A7. Branch + PR (required)

- **Do NOT commit directly to `alpha-working`.**
- `git checkout -b chore/[VERSION]-docs-catch-up`, commit there, reset local
  `alpha-working` to `origin/alpha-working`, push the branch.
- Open a PR against `alpha-working` with Summary / What's in / Validation /
  Notes structure, listing deferred open items explicitly.

---

## Track B — Model Catalog & Config Updates

### B0. Orientation

- **Read** `docs/reference/models/model-update-process-2026-07.md` (13-phase
  checklist, 24-file map) and `docs/reference/models/keeping-the-model-catalog-up-to-date.md`
  (catalog architecture, loader, migration).
- **Single source of truth:** `includes/data/model-catalog.json` — a
  `version` + `updated_at` + `models[]` array. Entry keys are the JSON's actual
  field names: `model_name`, `provider`, `tpm_limit`/`rpm_limit`/`tpd_limit`/
  `rpd_limit`, `context_window`, `max_output_tokens`, `tier`,
  `supports_streaming`/`supports_function_calling`/`supports_vision`,
  `cost_per_1k_input_tokens`/`cost_per_1k_output_tokens`, `fallback_model`,
  `status`, `sunset_date`, `notes`.
- **Migration trigger:** `WP_MCP_AI_Model_Catalog_Migration` runs once per
  catalog `version` bump and rewrites stored references (`wp_mcp_ai_model_configs`
  option, assistant `_wp_mcp_ai_model` meta, `wp_mcp_ai_settings.default_model`).
  **Always bump `version` + `updated_at` when editing the JSON.**
- **Discovery is suggestion-only:** the daily `wp_mcp_ai_model_catalog_discovery`
  cron writes diffs to the Suggestions panel — they are **never** auto-applied.

### B1. The 13-phase checklist (run monthly, or on provider model changes)

1. **Research (web).** Check each major provider for new/deprecated models:
   OpenAI deprecations page (`developers.openai.com/api/docs/deprecations`),
   Anthropic model overview (`platform.claude.com/docs/en/about-claude/models/overview`),
   Google Gemini models blog (`blog.google`), DeepSeek pricing
   (`api-docs.deepseek.com/quick_start/pricing`). Search patterns:
   `"[Provider] API models deprecated discontinued [MONTH] [YEAR]"`.
2. **Catalog JSON.** Add new models with full metadata; mark deprecated models
   `status: deprecated` + `sunset_date` + `fallback_model` successor; bump
   `version`/`updated_at`; validate: `php -r "json_decode(file_get_contents('includes/data/model-catalog.json'), true); echo json_last_error_msg();"`.
3. **Migration map** — `includes/class-wp-mcp-ai-model-catalog-migration.php`
   `get_legacy_id_map()`: add legacy-ID → successor entries. **Rule:** target
   must exist in the catalog; a removed ID without a documented successor is
   not auto-rewritten.
4. **Settings defaults** — `includes/admin/class-wp-mcp-ai-admin-settings-base.php`
   `get_default_settings()` keys: `default_model`, `default_gemini_model`,
   `nvidia_model`, `cloudflare_model`, `high_token_fallback_model`,
   `openai_realtime_model`, `gemini_live_model`, `gemini_image_model`,
   `openai_image_model`. Also `includes/admin/class-wp-mcp-ai-admin-settings.php`
   (`get_default_model()` fallback + hardcoded dropdown fallback).
5. **Model selector** — `includes/class-wp-mcp-ai-model-selector.php`:
   `get_default_light_model()`, `get_default_advanced_model()`,
   `check_tpm_and_suggest_fallback()`, `get_high_capacity_fallback_model()`.
6. **Language model router** — `includes/class-wp-mcp-ai-language-model-router.php`:
   draft + verification tier maps (17 providers) + fallback defaults.
7. **Provider clients** — `includes/class-wp-mcp-ai-anthropic-client.php`
   (`list_models()` static list, `resolve_model()`, `test_connection()`) and
   `includes/class-wp-mcp-ai-gemini-live-client.php` (`DEFAULT_MODEL`).
8. **Token budget service** — `includes/services/class-wp-mcp-ai-token-budget-service.php`:
   `$model_limits`, `$default_tpm_limits`, `$model_max_output_tokens`,
   `get_higher_limit_models()`, `resolve_tiktoken_encoding()`.
9. **Cost tables** — `includes/class-wp-mcp-ai-cost-calculator.php` (per 1M
   tokens) and `includes/class-wp-mcp-ai-usage-tracker.php`
   `get_fallback_pricing()` (per 1K). Add new models, drop retired ones, remove
   expired promotional pricing (e.g. DeepSeek V4-Pro promo).
10. **Admin UI placeholders** — bulk `sed` over
    `includes/admin/class-wp-mcp-ai-admin-profession-settings.php`,
    `-admin-team-settings.php`, `sections/class-wp-mcp-ai-section-orchestration.php`,
    `includes/cli/class-wp-mcp-ai-cli-assistant-command.php`,
    `includes/class-wp-mcp-ai-model-rate-limits-cct.php`,
    `includes/class-wp-mcp-ai-token-tracking-database.php`. Also
    `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` (test-connection
    defaults) and `includes/class-wp-mcp-ai-default-assistants.php` (pre-built
    assistant `model` values).
11. **Pro tool files** — `grep -rn "gpt-4o\|gemini-1.5\|claude-3-haiku\|claude-3-5-sonnet" addons/pro/includes/tools/`:
    fix `get_model()`/`get_research_model()` fallbacks, schema `enum` arrays,
    docblocks, `get_definition()` enums.
12. **Example files** — `assets/examples/model-config-capability-filtering.php`,
    `addons/pro/includes/metaboxes/class-wp-mcp-ai-media-template-metabox-operation.php`.
13. **Validate** — JSON validity (above), `php -l` on every changed PHP file,
    `git --no-pager diff --stat` review. Run the model suites:
    `tests/test-model-catalog.php`, `tests/test-model-config.php`,
    `tests/test-model-selector.php`, `tests/test-token-budget-manager.php`,
    `tests/test-model-rate-limits-cct.php`, `tests/test-model-pricing-checker.php`,
    `tests/test-model-discovery-service.php`.

### B2. Hard rules & invariants (do not break)

- The catalog JSON is the single source of truth — never hand-edit a derived
  file without a matching catalog entry.
- `status` ∈ `active` | `deprecated` | `legacy`; only `active` entries appear in
  fallback dropdowns.
- Migration-map targets must exist in the catalog.
- `tpd_limit`/`rpd_limit` of `0` means "no limit" — keep the keys present.
- **Provider-enable invariant (PR #6255):** fresh installs disable cloud
  providers by default; `WP_MCP_AI_Model_Config::get_available_providers()` lists
  only enabled + credentialed providers. New providers must respect it.
- **User-pinned defaults must stay active** — `tests/test-model-catalog.php`
  asserts `gpt-4o` stays ACTIVE (users pin its ID as a fallback target). Do not
  flip pinned/fallback-referenced IDs to inactive without a migration mapping.
- Cost calculator and usage tracker use different units (per 1M vs per 1K) —
  do not cross-copy values.
- Never auto-apply discovery suggestions.
- If a change is security-adjacent (URLs, credential resolution), re-read
  `.context/security-checklist.md` before touching provider clients.

### B3. Release integration

- Catalog changes always get a changelog mention (see v1.1.69 "model catalog
  fixes — #6274" and v1.1.40 "July 2026 model catalog").
- If the update ships in a release window, run it through Track A's P0 surfaces
  and the P3 version bump; a standalone monthly catalog refresh can ship as its
  own branch + PR without a full version bump (record it for the next catch-up).

---

## Industry-standards grounding (why the workflow looks like this)

- **Keep a Changelog** (`keepachangelog.com`): a curated, chronological list of
  notable changes for humans — never raw commit-log diffs. This is why Track A
  reads PR descriptions and classifies production vs test/docs/build PRs before
  writing the changelog.
- **SemVer discipline**: mechanical version bumps are a separate commit from
  content commits, and `package-lock.json` is never hand-edited — reproducible
  release hygiene.
- **LLM model lifecycle management** (UiPath AI Trust Layer lifecycle;
  valuestreamai 2026 lifecycle guide): deprecation target dates set at
  registration time, sunset-driven migration of stored references, a single
  registry source of truth, and provider suggestions that are never
  auto-applied. The repo's `status`/`sunset_date`/`fallback_model` fields and
  the version-gated migration routine already implement this — Track B just
  enforces it end-to-end across the ~24 derived files.

## Shared guardrails (both tracks)

- `git log` first; another agent may own part of the scope.
- Read the per-folder `README.md` before editing `includes/<folder>/` files.
- Every claim (paths, counts, versions) is verified against the actual PRs.
- Never commit to `alpha-working` directly; branch + PR; exclude unrelated
  working-tree noise from commits.

## References

- Plan templates: `docs/project/plans/v1.1.58-docs-catch-up.md`,
  `docs/project/plans/v1.1.59-docs-catch-up.md`,
  `docs/project/plans/v1.1.70-docs-catch-up.md` (latest executed)
- Standing open items: `docs/project/plans/docs-catch-up-open-items.md`
- Model process: `docs/reference/models/model-update-process-2026-07.md`,
  `docs/reference/models/keeping-the-model-catalog-up-to-date.md`
- File-update checklist when adding skills/context: `AGENTS.md` §6
