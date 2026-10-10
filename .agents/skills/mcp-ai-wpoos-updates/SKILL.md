---
type: Skill
name: mcp-ai-wpoos-updates
description: "Operational guide for the three recurring NV oOS maintenance tracks: (1) Docs & Release Catch-Up — plan-first per-version docs, changelog, and version-bump passes; (2) Model Catalog & Config Updates — the monthly model-config refresh across the catalog and its derived files; (3) PR Deferred-Item Sweep — review merged/closed PRs for deferred work and file GitHub issues for what is still incomplete. Use when asked to 'catch up docs', 'update the model configs', or 'review the week's PRs for deferred work'."
license: Proprietary. See LICENSE.txt
metadata:
  plugin: mcp-ai-wpoos
  plugin-version: "1.1.99"
  plugin-version-tested: "1.1.99"
  last-updated: "2026-10-07"
---

# NV oOS Updates — Docs Catch-Up, Model Catalog & PR Deferred-Item Sweeps

Playbook for the three recurring update tracks in this repo, distilled from the

executed catch-up plans (`docs/project/plans/v1.1.58-docs-catch-up.md` through
`v1.1.83-post-docs-catch-up.md`), the model-catalog process docs (July 2026 and
September 2026 runs), and the executed 2026-09-17 PR deferred-item sweep
(issues #6646–#6655). The workflows implement industry standards — Keep a
Changelog, SemVer commit separation, and deprecation-driven LLM model lifecycle
management — adapted to this repo's conventions.

## When to use this skill

**Track A — Docs & Release Catch-Up:**
- "Catch up docs/release notes for vX.Y.Z" / "do the same exercise for this version"
- A window of PRs merged on `alpha-working` has no release-note coverage
- Version bump + changelog + agent-context sweep for a new release

**Track B — Model Catalog & Config Updates:**
- "Update model configs" / "refresh the model catalog" / "new model lineup"
- Provider deprecations, price changes, new default models
- The monthly model-update exercise

**Track C — PR Deferred-Item Sweep:**
- "Review all the PRs for the past week to find deferred issues"
- "Add deferred/incomplete items to the issues list"
- A PR window has follow-up notes that were never re-tracked

Tracks A and B can run in the same window: Track B's catalog changes always get
a changelog mention, folded into Track A's release surfaces. Track C runs
weekly (or on demand), independent of any release.

---

## Track A — Docs & Release Catch-Up

### A0. Orientation (before touching anything)

1. **Check `git log` first** — another agent may have already executed parts of
   the window. Compare against the latest executed plan's execution log.
2. **Read `docs/project/plans/docs-catch-up-open-items.md` FIRST** — it holds
   every standing open item (OI-1 `@since` reconciliation, OI-2 Docker count
   re-derivation, OI-3 test-suite cross-ref, OI-4 wave residuals). Parked items
   stay parked; new finds get *recorded* there, never fixed in-pass.
3. **Read the template plans** — the latest executed plan (`1.2.3-docs-catch-up.md`,
   with `1.2.2-docs-catch-up.md` as the previous pass) plus the
   `v1.1.83-post-docs-catch-up.md` post-window precedent (executed when PRs
   merged after the catch-up but before the next version bump) and
   `v1.1.58`/`v1.1.59` for the original structure.
4. **Identify the PR window** — everything merged on `alpha-working` after the
   previous catch-up merge. Classify every PR: production-touching (file + change
   table), test-only, docs-only, build-only, closed-unmerged docs PRs.
5. **Create the plan** `docs/project/plans/[VERSION]-docs-catch-up.md` if none
   exists — or `[VERSION]-post-docs-catch-up.md` when a catch-up already merged
   for the current version and a new PR window landed on the same line (the
   v1.1.83 post pass is the precedent: extend the current-release changelog
   section in place, re-derive the count delta, and sweep the previous pass's
   "unchanged" claims — they can be falsified by post-window merges). Use the
   v1.1.83 structure:
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

- **A version jump is user-directed and recorded, never inferred.** The 1.2.1
  pass executed the jump from 1.1.99 (the ROADMAP's planned 1.2.0 scope was
  delivered early across v1.1.1–v1.1.35, so the first 1.2-line release is
  numbered 1.2.1) — when the user names a target version, run the whole pass
  against it and resolve the open-items tracker's "Blocked on: version-jump
  decision" line with the decision instead of re-raising it.
- **A tag-pending addon ZIP is not stale yet.** The v1.2.1 window bumped the
  docs-hub addon 0.5.1 → 0.5.2 but the `docs-hub-v0.5.2` tag (which triggers
  the build) was a post-merge follow-up — the v0.5.1 ZIP stayed in `build/`
  until the new artifact landed (the v1.1.93/v1.1.99 superseded-addon-ZIP
  precedents). **The v1.2.2 pass executed the rule's completion:** once the
  v0.5.2 **and** v0.5.3 ZIPs had built (the `docs-hub-v0.5.3` tag pushed +
  0.5.3 live on the directory — verify with `git tag`), BOTH older docs-hub
  ZIPs (v0.5.1 + v0.5.2) became removable together with the superseded
  content-graph v1.0.8 ZIP (v1.0.10 built) — 4 addon ZIP files on top of
  the 30-file previous-version oOS set.

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
- **README keeps the last 12 releases in full.** The `## 📜 Release History`
  section holds exactly the 12 most recent releases. Each new release adds a
  new `### vX.Y.Z — Date` + `#### Title` entry at the top of that section; the
  oldest entry is demoted to a one-line row in the `### 📋 Previous Releases`
  table (newest first). Full per-release detail lives in `CHANGELOG.md` only —
  the README never grows past 12 detailed entries, and the Overview keeps a
  single current-release "What's New at a Glance" block. (Adopted 2026-09-22
  to stop the README's duplicated changelog blocks from growing unboundedly.)
- **Anchor hygiene:** headings must not carry VS16 (U+FE0F) emoji — GitHub's
  slugger strips the base emoji but keeps the invisible VS16, producing broken
  `#...` anchors (e.g. `### ⚠️ Warranty` resolves to `#️-warranty--safe-use`).
  Use the no-VS16 form (`⚠`, `⚙`, `🗨`, `🛡`) and keep TOC links as the plain
  `#-slug` form; never add manual `<a id="...">` anchors.
- **Provider-count figure tracks model-catalog chat providers only.**
  Media-generation providers (Sora, Veo, Runway, Higgsfield, …) are **not**
  counted in the "N providers" versioning line — that figure follows the
  model catalog (`WP_MCP_AI_Model_Config::get_all_provider_slugs()`), and media
  providers live outside the catalog. When a media-provider PR lands, verify
  the catalog is untouched and keep the provider count unchanged (the v1.1.86
  Higgsfield pass is the precedent).
- **Dual-layer tool PRs count the plugin registry only.** When a PR registers
  the same tools in `includes/class-wp-mcp-ai-tool-registry.php` **and** adds
  framework-agnostic wrappers under `lib/core/src/Tool/` via
  `includes/bootstrap/oos-bridge.php`, the base/Pro count delta is the
  **registry** registration count — `lib/core` keeps its own registry and its
  tools are never counted in the base/Pro totals (the v1.1.86 Higgsfield
  pass: +4 base, not +8).
- **Release-sync PRs are build-classified.** "Merge alpha-working into main
  (squashed)" PRs are tree-identical to `alpha-working` — classify them
  build/release-only (no production file table); their only real effect is
  dropping already-stale build ZIPs (the v1.1.86 pass's #6770 is the
  precedent).
- **The stale-ZIP set follows the full build set, not just the wp.org root.**
  When a release ships, remove the whole previous-version artifact set — root
  `build/` ZIPs + `.sha256` pairs **plus** `build/optional-components/` and
  `build/toolkit-addons/` (the v1.1.85 pass's "30 files" shape — 9 + 2 + 19 —
  repeated by the v1.1.87 pass for the 1.1.85 set).
- **An introducing PR may pre-stage the readme.txt release entry.** A feature
  PR can add a `= X.Y.Z - Unreleased =` changelog paragraph before the
  catch-up runs (the v1.1.87 window's #6780 did) — the catch-up converts it
  into the dated release entry and extends it with the rest of the window;
  never keep two entries for the same version.
- **A PR merged after a catch-up may stage its changelog entry into the
  just-shipped release section instead of the next one.** The v1.2.3
  window's #6986 inserted its CORS-guard block into the already-shipped
  `[1.2.2]` CHANGELOG section post-merge (verified: the #6983 catch-up
  tree has no CORS text) — the next catch-up relocates the block into its
  own release section and records the relocation in the plan (the same
  falsification logic as the #6787 rule, applied to changelog sections
  rather than window maps; `.context`/skill/deploy-doc stamps written
  in-window with the wrong version get the same relocation/correction
  treatment).
- **Sub-project window PRs are flagged-not-edited even when they carry their
  own changelog surfaces.** PRs that update `plugins/nvoos-content-graph`'s
  own CHANGELOG.md/readme.txt/.pot (the v1.1.87 window's #6782/#6783) keep
  their own version track (1.0.8) — the catch-up notes them in the plan and
  the changelog's sub-project paragraph but edits nothing under `plugins/`.
- **Wrong-version `@since` groups with unusual tags record like any other OI-1
  group.** The v1.1.87 window shipped `@since 2.12.0` ×139 (outbound-booking
  toolkit + Upwork files) — record the group with its counts and locations;
  parked reconciliation (OI-1) still applies, no in-pass fixes.
- **A PR merged between the previous plan's window map and its catch-up merge
  belongs to the new window when the previous changelog never covered it.**
  The tree diff alone misses such PRs (they sit inside the previous catch-up's
  tree). Cross-check the previous changelog for the PR number during
  orientation — the v1.1.88 window's #6787 (gateway express bump) merged
  24 min before the #6788 catch-up and was absent from the 1.1.87 changelog.
- **A PR may explicitly defer its own changelog entry to the catch-up.** The
  v1.1.88 window's #6792 (decision-model Phase A) carried a "CHANGELOG entry
  at merge time (v1.1.88+ window)" note — grep PR bodies for
  changelog/release-note deferrals during orientation; the catch-up owns the
  entry.
- **Addon sub-projects with their own version tracks may update `ADDON_INVENTORY.md`
  in-window** (the v1.1.88 window's #6797 set the saas-controller row to 0.3.0)
  — the pass refreshes only the header stamp, and the addon's versioning goes
  in the changelog's sub-project paragraph, not a new inventory row.
- **In-window skill updates are not proof the skills are current.** The v1.1.88
  window updated `design-crm` (#6790) and `design-elementor-mcp-connection`
  (#6799) in place, yet #6793 then reverted the URL canonical form #6790 had
  just documented, the bundled `design-crm` copy was two windows behind the
  Zed copy, and the elementor skill's security rules still claimed inline
  secrets were plaintext after #6798 encrypted them. After mapping the window,
  diff each updated skill against the code it documents and `diff -q` the
  Zed/bundled pairs — the catch-up owns the reconciliation.
- **A PR may pre-stage a `## [Unreleased]` CHANGELOG section, not just a
  readme.txt entry.** The v1.1.89 window's #6809 added an `[Unreleased]`
  block (the Google Classroom content) at the top of `CHANGELOG.md` before the
  catch-up ran — the catch-up converts it into the dated release section and
  extends it with the rest of the window; never keep two entries for the same
  version (the same rule as the readme.txt pre-staging).
- **Verify addon rows in `ADDON_INVENTORY.md` against the addon's own version
  constant.** The v1.1.89 window's #6810 updated the Design System row
  in-window but wrote **0.2.0** while the addon's header + `NVOOS_DESIGN_SYSTEM_VERSION`
  + PR body say **0.3.0** (the proposals README even said "v0.2.0 → v0.3.0").
  The catch-up corrects a factually wrong in-window row version in-pass (the
  "refreshes only the header stamp" rule assumes the row was right).
- **Addon-level wrong-version `@since` waves record like any other OI-1 group.**
  #6810 shipped 33 × `@since 0.2.0` across the renamed/new
  `addons/nvoos-design-system/` files while the addon shipped **0.3.0** (2
  files correctly tagged 0.3.0) — the addon-version convention (SaaS Controller
  precedent) requires tags to match the shipped addon version. Record the
  group with its counts; parked reconciliation applies.
- **Tools registered by standalone addons via `wp_mcp_ai_register_tools` are
  described in the changelog but never counted in base/Pro totals.** The
  v1.1.89 window's 8 `nds_*` email tools (Design System addon) join the same
  registry the algorave/embedded/graphify/page-agent addon tools use — the
  base/Pro count lines track plugin + `addons/pro` registrations only (the
  algorave "9 tools" entry is the precedent: described, not counted).
- **Folder/file `@since` conventions override the wrong-version rule.** The new
  `includes/google/` Classroom files carry `@since 1.0.0` ×71 like their
  Calendar siblings (which already had 31), and the Pro remote-site files'
  `@since 1.0.0` additions follow each file's dominant pre-existing tag —
  local convention, not a new OI-1 group.
- **Track B: the migration-trigger line now covers per-provider settings keys.**
  #6805 extended `WP_MCP_AI_Model_Catalog_Migration` to rewrite
  `deepseek_model`/`default_gemini_model`/`anthropic_model`/`kimi_model`
  alongside `default_model` — the rewrite still only runs on a catalog
  version bump, so a migration-behavior change without a bump is dormant until
  the next one.
- **The plugin skill keeps a companion `RELEASE-NOTES.md` and must stay under
  the Zed 100KB skill-size limit.** #6822 moved the per-version release-note
  tail out of `mcp-ai-wpoos-plugin/SKILL.md` (103KB → 46KB) into a companion
  `RELEASE-NOTES.md` — **future version notes append to RELEASE-NOTES.md, not
  SKILL.md**. When a structural change like this lands on the Zed copy, sync
  the base bundled copy byte-identical (SKILL.md + companion) — stamp-only is
  not enough; the CG AI platform bundled copy is a deliberately trimmed
  variant and keeps stamp-only treatment.
- **In-window tool-reference edits may update the header note without the
  count line.** #6824 added the RF-DETR entries + the "+2 Pro in v1.1.90"
  note but left the main count line at ~1,299/~1,646 — the catch-up
  reconciles the count line (and the Last Updated stamp) in-pass.
- **An introducing PR may complete the A5 skill-count bookkeeping itself.**
  #6820 (the dependabot-loop skill) bumped 60 → 61 in `AGENTS.md` §1,
  `.github/copilot-instructions.md`, and the `README.md` repo map in-window —
  the catch-up verifies (grep for the old count) rather than assuming the
  fold-in is owed.
- **An in-window addon version bump must be verified against every
  runtime-reported version string, not just the package manifests.** The
  v1.1.95 window's #6881 bumped the media worker to 3.4.0 in `package.json` +
  `package-lock.json` but left `src/index.js`'s two health-endpoint `version:`
  fields and `src/status/handlers.js`'s `WORKER_VERSION` at 3.3.0 (a deployed
  3.4.0 worker reported 3.3.0 on `/api/health*` and in status heartbeats — the
  #6849 precedent had kept all five in sync; `WORKER_VERSION` even carries the
  comment "kept in sync with package.json by the release process"). Fixed
  post-window by #6895. When an addon's version moves in-window, grep the
  addon tree for the old version and treat every non-historical hit (health
  payloads, status constants, headers) as a release-blocking miss — the
  ADDON_INVENTORY row update alone is not proof the addon is consistent.
- **The partial-bump rule covers test-file version pins too.** The v1.1.99
  window's #6952 bumped toolkit-shell to 0.3.0 everywhere (package.json,
  plugin header, runtime constants) but left the three test files defining
  `NVOOS_TOOLKIT_SHELL_VERSION` at 0.2.0 — the catch-up fixes such pins
  in-pass (a separate `fix:` commit), since tests pinning the old version
  are exactly the kind of silent drift the rule exists to catch.
- **An in-window SPA addon version bump supersedes its committed
  `build/nvoos-<slug>-v<old>.zip` before the workflow has built the new
  one.** The `build-spa-addons.yml` run on the bump's merge commit produces
  the `v<new>` ZIP asynchronously (a later `build: add SPA addon ZIPs`
  commit on alpha-working) — the catch-up removes the superseded old ZIP
  (the v1.1.93 superseded-addon-zip precedent) and notes the in-flight run
  in the plan; a build that fails simply leaves no current ZIP, which is
  still the correct state (the old artifact is stale by definition).

### A3. Commit structure (mirror v1.1.58–v1.1.83)

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
  map row. (The 2026-09-07 `mcp-ai-wpoos-wporg-submission` skill is the
  example — wp.org submission/PCP/screenshot playbook; see that skill for
  the submission track itself.)
- **The introducing PR may skip the count bookkeeping — fold it into the
  release.** Feature PRs sometimes add a new skill without the `AGENTS.md` §1 /
  `.github/copilot-instructions.md` / `README.md` repo-map count updates (the
  v1.1.86 pass: #6761 added `design-elementor-mcp-connection` without them).
  During orientation, diff the introducing PR's file list against the count
  surfaces; the catch-up pass owns the fold-in and states it in the
  changelog's Versioning line (never post-hoc edit an executed release entry).
- **Leave historical per-version count lines untouched** — old release entries
  keep the counts they shipped with.

### A6. Validation

- `php -l` on every bumped PHP file; JSON-parse `package.json`.
- `git grep` sweeps for the old version string and stale counts — remaining hits
  must be intentional history only (`@since` docblocks, per-version blocks,
  plans, archive).
- Verify every claim in the plan (file paths, counts, versions) against the
  actual PRs before marking executed.
- Re-run the README anchor check: every `](#...)` target must resolve under
  github-slugger rules (emoji stripped, spaces → hyphens, `&` → `--`), and the
  Release History section must hold exactly the last 12 releases.

### A7. Branch + PR (required)

- **Do NOT commit directly to `alpha-working`.**
- Cut the branch from a **fresh** `origin/alpha-working`
  (`git fetch origin alpha-working && git checkout -b chore/[VERSION]-docs-catch-up origin/alpha-working`),
  commit there, push the branch.
- Open the PR **against `alpha-working`** — never against `main`. A branch cut
  from the alpha line but targeted at `main` (the default base) reports a huge
  conflict list across every file where the two lines have diverged — the fix
  is `gh pr edit <n> --base alpha-working`, not conflict resolution.
  (Incident: PR #6926, 2026-10-06, ~5k-file "conflict" that vanished on re-base.)
- **Keep every commit message free of CI-skip markers** (the bracketed
  `skip ci` / `no ci` forms). GitHub skips **all** workflows for a merge push
  when a marker appears anywhere in the squashed message — including bullets
  inherited from commits merged into the PR branch. This repo's build commits
  carry such markers routinely, so an absorbed commit can silently suppress
  the post-merge syncs. (Incident: the #6925 merge push ran zero workflows and
  the mcp-gateway mirror sync never fired.) Recovery: merge a clean follow-up
  PR touching the same paths, or dispatch manually —
  `gh workflow run sync-mcp-gateway.yml --ref alpha-working`.
- PR structure: Summary / What's in / Validation / Notes, listing deferred
  open items explicitly.

---

## Track B — Model Catalog & Config Updates

### B0. Orientation

- **Read** `docs/reference/models/model-update-process-2026-07.md` (13-phase
  checklist, 24-file map) and `docs/reference/models/keeping-the-model-catalog-up-to-date.md`
  (catalog architecture, loader, migration).
- **Check `git log` on the catalog + derived files first** — another agent or a
  drift-fix commit may have already landed part of the window
  (`git log --oneline -10 -- includes/data/model-catalog.json`).
- **Verify the previous run's process-doc claims against the actual files.**
  The previous run's "This Month's Changes" section is a plan of record, **not**
  proof the values are in the files — a later drift-fix commit can revert a
  documented fix (the July 2026 run: the doc said Claude Opus 4.5/4.6/4.7 input
  was fixed $15 → $5, but the cost calculator still shipped $15; the catalog
  also shipped `deepseek-v4-flash` input 10× too low at $0.014/1M). Diff each
  claim against the JSON + cost tables before trusting it.
- **Scope boundary (as established by the July 2026 run):** edit only
  `includes/` + `addons/pro/` + `tests/` + `assets/examples/` + the two model
  docs. `lib/core/` (`Infrastructure/Token/TokenBudgetManager.php`,
  `Infrastructure/Cost/CostCalculator.php`, `Infrastructure/Provider/KimiClient.php`,
  `Tool/CountTokensTool.php`, `Tool/GenerateGeminiImageTool.php`,
  `Tool/EditGeminiImageTool.php`), `lib/wordpress-adapter/src/Tool/RunGeminiManagedAgentTool.php`,
  and the sub-project `plugins/nvoos-content-graph-ai/` (its own mirrored
  `src/Model/model-catalog.json`, `ModelCatalogMigration.php`, and
  `Analytics/UsageTracker.php`) mirror model data but keep their own tracks —
  **flag them in the PR notes, do not edit**.
- **Windows shell:** `sh` mangles double-quoted `php -r` one-liners. Always use
  single quotes around the PHP code:
  `php -r 'json_decode(file_get_contents("includes/data/model-catalog.json"), true); echo json_last_error_msg();'`
- **Single source of truth:** `includes/data/model-catalog.json` — a
  `version` + `updated_at` + `models[]` array. Entry keys are the JSON's actual
  field names: `model_name`, `provider`, `tpm_limit`/`rpm_limit`/`tpd_limit`/
  `rpd_limit`, `context_window`, `max_output_tokens`, `tier`,
  `supports_streaming`/`supports_function_calling`/`supports_vision`,
  `cost_per_1k_input_tokens`/`cost_per_1k_output_tokens`, `fallback_model`,
  `status`, `sunset_date`, `notes`.
- **Migration trigger:** `WP_MCP_AI_Model_Catalog_Migration` runs once per
  catalog `version` bump and rewrites stored references (`wp_mcp_ai_model_configs`
  option, assistant `_wp_mcp_ai_model` meta, `wp_mcp_ai_settings.default_model`
  plus the per-provider keys `deepseek_model`/`default_gemini_model`/
  `anthropic_model`/`kimi_model` since #6805).
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
   Tactics learned in the September 2026 run:
   - **Fire searches sequentially, not in parallel** — the search API
     rate-limits the second call of a parallel batch, forcing retries.
   - **Cross-check every model against ≥2 sources** and prefer the provider's
     own docs/changelog over aggregator trackers; trackers disagree (one claimed
     Gemini 2.5 was dead when Google listed "no shutdown date announced").
   - Useful 2026 trackers found: felloai.com's discontinued-models list,
     digitalapplied.com's monthly release tracker, OpenRouter per-model pages
     (pricing + context windows), and the providers' changelog pages.
   - **Log pricing with source + date** as you go (e.g. "Gemini 3.8 Flash
     $0.75/$3.75 intro through 2026-12-31, then $1.50/$7.50 — ai.google.dev
     changelog"). Intro/promo pricing is common — record the expiry so the next
     run knows to re-check it (DeepSeek V4-Pro promo, GPT-5.6 Sol promo,
     Gemini Flash intro rates).
2. **Catalog JSON.** Add new models with full metadata; mark deprecated models
   `status: deprecated` + `sunset_date` + `fallback_model` successor; bump
   `version`/`updated_at`; validate: `php -r "json_decode(file_get_contents('includes/data/model-catalog.json'), true); echo json_last_error_msg();"`.
   Editing tactics:
   - Edit **surgically per provider section** with `edit_file` (version bump,
     then one batch per provider), and validate the JSON + dump the changed
     entries after each batch — a whole-file rewrite loses the hand-formatting.
   - **`model_name` is unique only per provider.** `deepseek-coder` exists under
     both `deepseek` (API, removed) and `ollama` (local tag, kept). When removing
     an ID, target the full entry block including its `"provider"` field, then
     verify with a one-liner that prints provider + status for every remaining
     occurrence.
   - Removal checklist: sunset passed → remove + migration-map entry;
     retired-without-successor → keep deprecated until the provider documents one.
     Keep `gpt-4.1`, `gpt-4o`, `gpt-4o-mini`, `gpt-4.1-mini`, `gpt-4.1-nano`
     ACTIVE (pinned-ID tests).
   - One-line verification pattern used this run:
     `php -r '$d = json_decode(file_get_contents("includes/data/model-catalog.json"), true); $names = array_column($d["models"], "model_name"); foreach ([...ids...] as $n) { echo $n, ": ", in_array($n, $names, true) ? "PRESENT" : "MISSING", "\n"; }'`
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
   **Defaults live in more than one layer** — when changing one, sweep the rest
   (this is the #6255 three-layer lesson applied to models): the settings-base
   default, the section field `'default'`/`'options'` (`section-kimi.php`,
   `section-providers.php`), the runtime fallback in consuming code (provider
   clients' `DEFAULT_MODEL` consts, tool `DEFAULT_MODEL` consts like
   `class-wp-mcp-ai-tool-generate-openai-image.php`, sanitize fallbacks), and
   the tests that pin each layer.
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
    `git --no-pager diff --stat` review. Then the Docker test loop below and the
    test-drift sweep in B1b.

    **Docker test loop (learned in the September 2026 run):**

    1. The `oos-wp` container bind-mounts **one** worktree. Check which one:
       `docker inspect oos-wp --format '{{json .Mounts}}'` — if the mounted path
       isn't the worktree you're editing, `docker exec oos-wp phpunit` tests the
       wrong code. Use the cross-worktree one-off runner instead (details in the
       `mcp-ai-wpoos-test-suite` skill, "Cross-worktree runs"):
       - Build a **Linux** vendor into a named volume — the Windows-host vendor/
         breaks inside Linux (`Class "PHPUnit\TextUI\Application" not found`):
         `docker run --rm -v <worktree>:/app:ro -v <name>-vendor:/app/vendor composer:2 sh -c 'cd /app && composer install --no-interaction --prefer-dist --no-progress'`
       - Run tests in one invocation (catches cross-suite interference):
         `docker run --rm -e WP_CORE_DIR=/var/www/html -e WP_DB_HOST=db -e WP_DB_NAME=wordpress_test -e WP_DB_USER=wordpress -e WP_DB_PASSWORD=wordpress -v oos-wp_wp_core:/var/www/html -v <worktree>:/var/www/html/wp-content/plugins/mcp-ai-wpoos -v <name>-vendor:/var/www/html/wp-content/plugins/mcp-ai-wpoos/vendor --network oos-wp_default wordpress:6.9-php8.2-apache sh -c 'cd /var/www/html/wp-content/plugins/mcp-ai-wpoos && php -d memory_limit=1G vendor/bin/phpunit <files> --no-coverage'`
       - **Never two concurrent phpunit runs** — they share the `wordpress_test` DB.
       - Clean up afterwards: `docker volume rm <name>-vendor`, remove temp
         worktrees with `git worktree remove` — never stage the artifacts.
    2. **phpcs gate counts ERRORS only** (warnings don't fail CI). Run it in the
       same one-off style with `php:8.2-cli` (that image has no git): mount the
       worktree at `/app` + the vendor volume at `/app/vendor`, then
       `php vendor/bin/phpcs --standard=phpcs.xml.dist --error-severity=1 --warning-severity=1 --report=summary <changed files>`.
       To compare against HEAD, use `git worktree add ../tmp HEAD` and run phpcs
       inside that worktree — checking files copied outside the tree skews
       path-based rules. Some test files carry pre-existing docblock errors; only
       fix what your change introduces (net-error-count must not grow).
    3. **phpcbf first, then re-test.** New array keys break WPCS array alignment
       (`=>` column). Run `php vendor/bin/phpcbf --standard=phpcs.xml.dist --error-severity=1 --warning-severity=1`
       over the changed files — it edits them in place — then **re-run the PHPUnit
       batch** (phpcbf touches code, so the pre-phpcbf test run is no longer proof).
    4. **Batch-edit gotcha:** large `edit_file` batches can mangle overlapping
       regions (this run deleted a test function's signature by accident). After
       every multi-edit batch: `php -l` each file, re-read the edited regions, and
       keep new PHP assertions float-safe (`assertEqualsWithDelta`, not
       `assertEquals`, for cost math — 0.42 !== 0.42000000000000004).

    Run the model suites: `tests/test-model-catalog.php`,
    `tests/test-model-config.php`, `tests/test-model-selector.php`,
    `tests/test-token-budget-manager.php`, `tests/test-model-rate-limits-cct.php`,
    `tests/test-model-pricing-checker.php`, `tests/test-model-discovery-service.php`
    — plus every drift file from B1b in the same invocation.

### B1b. Test drift files & file surface beyond the process-doc map

The July 2026 process doc's 24-file map misses several files the September 2026
run had to touch. Grep for the IDs you changed across `tests/`,
`addons/pro/tests/`, and `tests/js/`, then update:

| File | What it pins |
|---|---|
| `tests/test-model-catalog.php` | Dropdown keys (mirror the admin Providers dropdowns), user-pinned ACTIVE ids, removed-ids-absent list |
| `tests/test-model-config.php` | `get_models_by_provider()` per provider (e.g. deepseek list after removals) |
| `tests/test-cost-calculator.php` | Exact per-model price assertions (per 1M) |
| `tests/test-deepseek-client.php` | `MODELS_WITHOUT_TOOL_CALLING`, `get_model()` settings round-trip, `build_payload` model ids |
| `tests/test-kimi-client.php` | `DEFAULT_MODEL`, `MODELS_WITH_TOOL_CALLING`, context windows |
| `tests/test-kimi-integration.php` | Sanitize-to-default fallback (section default) |
| `tests/test-admin-settings.php` | `default_gemini_model`, `openai_image_model` |
| `tests/test-onboarding-wizard.php` | Per-provider fallback map |
| `tests/test-image-tool-settings.php` + `tests/test-openai-image-tool.php` | Image tool `DEFAULT_MODEL` const + schema fallback |
| `tests/test-model-rate-limits-cct.php` | Image-model presence/absence assertions |
| `addons/pro/tests/test-oos-composition-service.php` | Seeded `_wp_mcp_ai_model` ids |

Files the 24-file map misses (all touched in the September 2026 run):

- `includes/services/class-wp-mcp-ai-model-service.php` — per-provider dropdown
  lists (watch for duplicate-key mislabels, e.g. `gemini-2.5-flash` reassigned)
- `includes/admin/sections/class-wp-mcp-ai-section-providers.php` — provider
  fallback dropdowns + default-model descriptions
- `includes/admin/sections/class-wp-mcp-ai-section-kimi.php` — Kimi dropdown,
  sanitize fallback; **dropdown defaults must exist in the catalog** (the file
  referenced `kimi-k2.7-code` which the catalog lacked)
- `includes/class-wp-mcp-ai-deepseek-client.php` — `MODELS_WITHOUT_TOOL_CALLING`,
  `MODEL_CONTEXT_WINDOWS`, docblocks
- `includes/class-wp-mcp-ai-kimi-client.php` — `DEFAULT_MODEL`, tool-calling
  lists, context map
- `includes/admin/class-wp-mcp-ai-onboarding-wizard.php` — provider fallback map
- `includes/admin/class-wp-mcp-ai-provider-diagnostics.php` — test-connection
  fallback ids per provider
- `includes/services/class-wp-mcp-ai-gemini-managed-agent-service.php` —
  `DEFAULT_MODEL` (+ docblock `@type` example)
- `includes/tools/class-wp-mcp-ai-tool-run-gemini-managed-agent.php` — schema
  default + runtime fallback
- `includes/tools/class-wp-mcp-ai-tool-deep-research.php` — per-provider fallback
  ids in the resolver + class docblock
- `includes/tools/class-wp-mcp-ai-tool-discover-new-models.php` — known-Anthropic
  static list
- `includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php` —
  `DEFAULT_MODEL` const + per-model pricing tables
- `includes/cli/class-wp-mcp-ai-cli-chat-command.php` — docblock examples
- `addons/pro/includes/admin/class-wp-mcp-ai-ext-cog-settings.php` — vision-model
  dropdown + allowed-models list
- `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php` —
  provider fallback map
- `addons/pro/includes/tools/extended-cognition/class-wp-mcp-ai-tool-ext-cog-analyze-sensory-input.php` —
  schema `enum`
- The 9 pro research tools (`addons/pro/includes/tools/{research,places,quiz-management,eca-management,orchestration}/`) —
  identical `deepseek-chat` fallback pattern; grep `deepseek-chat\|gemini-3.5-flash\|claude-mythos-preview`
  and fix in one sweep

### B2. Hard rules & invariants (do not break)

- The catalog JSON is the single source of truth — never hand-edit a derived
  file without a matching catalog entry.
- `model_name` is unique only per provider — always remove/edit entries by their
  full block (including `provider`), never by name match alone.
- `status` ∈ `active` | `deprecated` | `legacy`; only `active` entries appear in
  fallback dropdowns.
- Migration-map targets must exist in the catalog.
- `tpd_limit`/`rpd_limit` of `0` means "no limit" — keep the keys present.
- **Units are per 1K in the catalog JSON and usage tracker, per 1M in the cost
  calculator.** After editing, dump the values with a one-liner — the September
  2026 run found real drifts this way: catalog `deepseek-v4-flash` 10× too low,
  usage-tracker `gemini-2.5-flash` 10× too low, cost-calculator Opus inputs 3×
  too high. Trust the provider's current pricing page over any previous doc.
- **Provider-enable invariant (PR #6255):** fresh installs disable cloud
  providers by default; `WP_MCP_AI_Model_Config::get_available_providers()` lists
  only enabled + credentialed providers. New providers must respect it.
- **User-pinned defaults must stay active** — `tests/test-model-catalog.php`
  asserts `gpt-4o` (and the other gpt-4.1 family ids) stay ACTIVE (users pin
  their IDs as fallback targets). Do not flip pinned/fallback-referenced IDs to
  inactive without a migration mapping.
- **Dropdown defaults must exist in the catalog** — if a section/file references
  an ID the catalog lacks (e.g. `kimi-k2.7-code` before this run), either add the
  ID to the catalog or change the default; don't leave the mismatch.
- Cost calculator and usage tracker use different units (per 1M vs per 1K) —
  do not cross-copy values.
- Never auto-apply discovery suggestions.
- If a change is security-adjacent (URLs, credential resolution), re-read
  `.context/security-checklist.md` before touching provider clients.

### B3. Release integration & run documentation

- Catalog changes always get a changelog mention (see v1.1.69 "model catalog
  fixes — #6274" and v1.1.40 "July 2026 model catalog").
- If the update ships in a release window, run it through Track A's P0 surfaces
  and the P3 version bump; a standalone monthly catalog refresh can ship as its
  own branch + PR without a full version bump (record it for the next catch-up).
- **Record the run in the model docs** (the September 2026 run did this): append
  a "This Month's Changes" section to
  `docs/reference/models/model-update-process-2026-07.md` (new models, status
  changes, default changes, pricing fixes, migration-map changes) and bump the
  "Last reviewed" line in
  `docs/reference/models/keeping-the-model-catalog-up-to-date.md`. If the real
  derived-file count outgrows the "~24 files" description, update that too.

---

## Track C — PR Deferred-Item Sweep (weekly review → issues list)

Reviews a window of merged/closed PRs for work the PRs explicitly deferred
("follow-up", "out of scope", "parked", "next cluster") and files GitHub issues
for what is still incomplete and untracked. Executed once for 2026-09-10 →
2026-09-17 and back one week (2026-09-03 → 2026-09-10): filed #6646–#6655,
closed-as-complete #6389.

### C0. Orientation

- **Use the `gh` CLI, not the GitHub API MCP tools** — the MCP GitHub tools
  fail with "Authentication Failed: Bad credentials" in this environment while
  `gh pr/issue` works. `gh auth status` confirms the token.
- Check `git log --all` first (direct commits from closed PRs, e.g. #6614's
  release commit, only show with `--all`).
- The sweep files issues; it never fixes code.
- Scope = the requested window (default: last 7 days) **plus one window back**
  when asked — port-wave PRs defer work that lands a week later.

### C1. Harvest

1. Dump the window's PRs (bounded windows use `updated:START..END`):
   ```
   gh pr list --repo nvdigitalsolutions/mcp-ai-wpoos --state all --limit 200 \
     --search "updated:>=YYYY-MM-DD" \
     --json number,title,body,state,mergedAt \
     --jq '.[] | "=== #" + (.number|tostring) + " [" + .state + "] " + .title + "\n" + (.body // "") + "\n"' \
     > /tmp/pr_bodies.txt
   ```
   (`/tmp` persists between terminal calls in this environment — one dump per
   window, then grep it repeatedly.)
2. Grep for deferral language (case-insensitive, always `-B 3` for context):
   ```
   grep -n -i -B 3 "defer|follow-up|followup|todo|out of scope|not addressed|future work|future pr|later pr|next pr|remaining work|parked|postponed|known limitation|left for|tracked in|issue candidate" /tmp/pr_bodies.txt
   ```
3. Read every matched section in full — a bare keyword hit is not a deferred
   item (e.g. "JS display toggles now defer to the stylesheet").
4. Separately list CLOSED-without-merge PRs (`grep "\[CLOSED\]"`) — their
   content never landed, so their plans/fixes may exist nowhere else.

### C2. Triage each candidate

- **Resolved by a later PR?** Verify with `gh pr view` / `git log --all --grep`
  (#6587's Stripe-424 follow-up → done by #6589; #6555's lib/core mirrors →
  done by #6608).
- **Tracked already?** Check the three in-repo trackers plus the issue list:
  `docs/project/ecosystem-port-tracker.md` (a cluster row ending "Remaining:
  —" means the wave completed), `docs/project/plans/docs-catch-up-open-items.md`
  (OI-1…OI-5), `docs/legal/COMPLIANCE-CHECKLIST.md` (legal ops), and
  `gh issue list --state open`. Tracked ⇒ no new issue.
- **Byte-identical quirks are NOT bugs to file.** The port policy pins upstream
  quirks as-is (unkeyed `$arguments['blueprint']` read, dead `ok` key). Only
  file *latent bugs* explicitly flagged "follow-up issue candidate" in PR
  bodies or the tracker.
- **Closed plan-only PRs lose their content** — recover the plan from the PR
  body when filing (issue #6654 recovered #6519 this way).
- **Verify every claim before filing**: `git tag --sort=-creatordate` for
  missing release tags, grep root `CHANGELOG.md`/`README.md` for stale version
  stamps, `ls build/` for ZIPs, grep `plugins/` for missing port files.

### C3. File the issues

- One issue per deferred item. Labels: `status:needs-triage` + area labels
  (`area:pro`, `area:core`, `area:tools`, `area:docs`, `area:frontend`,
  `area:ci-cd`, `area:integrations`) + `bug`/`proposal` when apt.
- Body structure that survived review:
  1. `## Source` — PR number/title + the exact deferred quote.
  2. `## Still incomplete` — verified evidence, **with the verification date**.
  3. `## Suggested scope` — a concrete follow-up plan.
  For multi-part deferrals add `## Status of each deferred item` marking what
  later PRs completed (see issue #6649's structure).
- Write bodies to `/tmp/issueN.md` with a **quoted** heredoc (`<<'EOF'`) so
  backticks/`$` stay literal under `sh`, then:
  ```
  gh issue create --repo nvdigitalsolutions/mcp-ai-wpoos --title "..." \
    --body-file /tmp/issueN.md --label "area:pro,status:needs-triage"
  ```
- Housekeeping: close stale-but-complete issues found during the sweep, with a
  closing comment referencing the completing PRs (#6389 closed after #6447 /
  #6448 landed both of its items).

### C4. Report back

Summarize in three tables: (1) filed issues with numbers, (2) already-tracked
(no action), (3) verified-resolved (no action). Offer the same sweep for the
previous window — the user will usually want it back-dated.

### Known deferral hotspots (where this repo parks work)

| Hotspot | What to expect |
|---|---|
| Ecosystem-port PRs | "Deferred (tracker-documented)" = in-wave, usually completed by a later sub-cluster — confirm the tracker row before filing |
| Docs catch-up PRs | OI-1/OI-2 parked ⇒ already issues #5968/#5967; new OI groups are *recorded in the open-items tracker, not fixed* — file an issue only when a new OI has no GitHub issue (OI-5 → #6651) |
| Release/sub-project PRs | Root docs + tags deferred to "next catch-up" and silently dropped when the PR is closed unmerged (#6614 → #6646) |
| Dependency-bump PRs | Cross-major bumps deferred "to a separate PR" (AI SDK v5) — almost never re-tracked (#6592 → #6647) |
| Legal/compliance PRs | Out-of-repo ops deferred into `docs/legal/COMPLIANCE-CHECKLIST.md` — a static checklist nobody revisits; file an umbrella issue (#6507/#6607 → #6655) |

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
  enforces it end-to-end across the ~45 derived + test-drift files.

## Shared guardrails (both tracks)

- `git log` first; another agent may own part of the scope.
- **Verify previous runs' documented claims against the actual files** — docs
  are plans of record, not proof; drift-fix commits can revert documented
  changes (see B0).
- Read the per-folder `README.md` before editing `includes/<folder>/` files.
- Every claim (paths, counts, versions) is verified against the actual PRs.
- Never commit to `alpha-working` directly; branch + PR; exclude unrelated
  working-tree noise from commits.
- **README anchor links**: after any `README.md` edit that touches headings or
  the TOC, run `python3 bin/validate-readme-anchors.py` (also enforced by the
  `link-check.yml` CI job). TOC links use GitHub's visible slug form: emoji
  headings keep the leading hyphen (`## 🧩 Overview` → `#-overview`), `&`
  becomes a double hyphen (`#-warranty--safe-use`), and hyphens are never
  collapsed or trimmed. Headings whose emoji carries a variation selector or
  ZWJ (`🛡️`, `🧑‍💻`) only resolve via GitHub's hidden fallback anchors —
  always link the visible form the validator computes.

## References

- Plan templates: `docs/project/plans/v1.1.58-docs-catch-up.md`,
  `docs/project/plans/v1.1.59-docs-catch-up.md`,
  `docs/project/plans/v1.1.83-docs-catch-up.md`,
  `docs/project/plans/v1.1.83-post-docs-catch-up.md`,
  `docs/project/plans/v1.1.84-docs-catch-up.md`,
  `docs/project/plans/v1.1.85-docs-catch-up.md`,
  `docs/project/plans/v1.1.86-docs-catch-up.md`,
  `docs/project/plans/v1.1.87-docs-catch-up.md`,
  `docs/project/plans/v1.1.88-docs-catch-up.md`,
  `docs/project/plans/v1.1.89-docs-catch-up.md`,
  `docs/project/plans/v1.1.90-docs-catch-up.md`,
  `docs/project/plans/v1.1.91-docs-catch-up.md`,
  `docs/project/plans/v1.1.92-docs-catch-up.md`,
  `docs/project/plans/v1.1.93-docs-catch-up.md`,
  `docs/project/plans/v1.1.94-docs-catch-up.md`,
  `docs/project/plans/v1.1.95-docs-catch-up.md`,
  `docs/project/plans/v1.1.97-docs-catch-up.md`,
  `docs/project/plans/v1.1.98-docs-catch-up.md`,
  `docs/project/plans/v1.1.99-docs-catch-up.md`,
  `docs/project/plans/1.2.3-docs-catch-up.md` (latest executed — the
  1.2.3 pass over PRs #6984–#6996: the CORS same-origin enforcement
  (#6986 — the 12th security class `WP_MCP_AI_CORS_Guard` overriding
  core's origin reflection, the `cors_allowed_origins` setting, zero
  tool-count change), the standalone-SPA WordPress login (#6993,
  Proposal 064 — the fourth auth mode via an Application Password +
  the OOS streaming-CORS fix), the SPA REST auth fixes (#6990 —
  credential-scoped transcripts/approvals + session-nonce re-bind,
  closes #6987/#6985), the schedule-manager multi-recipient
  `notify_email` + quick wins (#6991 — closes #6642/#6651; OI-5 closed,
  OI-4 item 5 fixed), the standalone-SPA cookie mode + `serve.mjs`
  proxy + chunk splitting (#6994), the `.env` loading (#6996), the npm
  ESM-dist fixes + Vite demo (#6984 — alpha.4 publish deferred), the
  CORS/Velocity docs (#6989) + triage snapshot (#6992), the build fixes
  (#6988 `git init -b main`; #6995 deploy-tree layout), **two changelog
  pre-staging anomalies caught-up** (the #6986 CORS block post-hoc
  inserted into the shipped [1.2.2] section — relocated into [1.2.3]
  and recorded as a new scope rule; the #6993 [Unreleased] section —
  converted), one new OI-1 group (54: `@since 1.2.2` ×14 — cors-guard
  ×12 + schedule-manager/plan-tool ×2, one-behind), the security-class
  count 11 → 12 surfaces (copilot-instructions ×2, CLAUDE.md ×2,
  FOR_REVIEWERS ×2 stale-10 corrections, EU_AI_ACT), three skill
  reconciliations (plugin ×3 + RELEASE-NOTES + the CORS entry version
  fix; spa-ui — the fourth auth mode + proxy/env/deploy notes; updates),
  the stale 1.2.1 build-set removal (30 files — 9 + 2 + 19) with the
  ollama-demo.json URL carry, zero tool-count change, zero catalog
  diff, and zero skill-count change (66))

Preceding windows:
  `docs/project/plans/1.2.2-docs-catch-up.md` (executed — the
  1.2.2 pass over PRs #6966–#6982 + the direct `c9d54bd273` CI commit: the
  chat-profile system (#6977, Proposal 015 — server-enforced read-only mode,
  the 11th security class, `GET/POST /mcp-ai/v1/chat-profile` deliberately
  not a tool, zero tool-count change), the F&B assistant packs (#6982,
  Proposal 062 Phase 4 — four importable bundles + the seeder, no new
  slugs), the Google OAuth production readiness (#6981 — `drive.file`
  unification, Calendar Minimal, upstream revocation), the Pro SPA v2 fixes
  (#6974 dead-class default assistant + non-admin visibility; #6976 sidebar
  toggle + live sessions; #6979 TS parity + full JS rebuild), the test drift
  fixes (#6978/#6980 — both lessons skill-encoded), the CI/build fixes
  (#6970 SPA tag-release + new release-spa-addons.yml; #6972 demo-videos
  manual-only), the docs-hub 0.5.3 pre-submission pass (#6966/#6967/#6968 —
  tag pushed + LIVE on the wp.org directory) and content-graph 1.0.10
  (#6973 — excluded-source pruning; run Rebuild Graph once), Proposal 063
  (#6969, docs only), three new OI-1 groups (51: `@since 2.2.0` ×84
  chat-profile wave; 52: `@since 2.1.1` ×1 pro helper; 53: `@since 1.2.0`
  ×1 oauth-manager) + the group-50 extension (#6982's 1.6.0 ×3), the
  OI-4 extension (#6974's pagination-fields fixture accumulation — **closed
  by the 1.2.3 window's #6991**),
  five skill reconciliations (plugin ×3 + RELEASE-NOTES + the stale
  Architecture count line; wporg-submission Fifth pass; checkout-integration
  0.5.3/1.0.10 refs; test-suite 68 → 69 patterns; updates), the
  FOR_REVIEWERS pre-existing drift corrections (Chat SPA 0.7.0, SaaS
  Controller 0.3.0, Comic Reader 0.5.0, MCP Gateway 0.1.1, Schedule
  Anything SPA 0.2.0, Design System rename, Media Studio 0.6.1, Toolkit
  Shell 0.3.0), the security-class count 10 → 11 surfaces
  (copilot-instructions ×2, CLAUDE.md ×2), the stale 1.1.99 build-set
  removal (30 files — 9 + 2 + 19) + the superseded docs-hub
  v0.5.1/v0.5.2 + content-graph v1.0.8 ZIPs (4 files — the tag-pending
  rule's completion), zero tool-count change, zero catalog diff, and zero
  skill-count change (66))

Preceding windows:
  `docs/project/plans/1.2.1-docs-catch-up.md` (executed — the
  1.2.1 pass over PRs #6954–#6964 + the five direct Proposal-061 doc
  commits: the **version-jump decision executed** (1.1.99 → 1.2.1 — the
  ROADMAP's planned 1.2.0 scope was delivered early across v1.1.1–v1.1.35,
  so the first 1.2-line release is numbered 1.2.1; the OI-1 "Blocked on"
  line records the decision), the Food & Beverage Management Pro Toolkit
  (#6962, Proposal 062 — 21 gated `fnb_*` Pro tools, 20/20 Check-totals
  oracle, phases 4/5b/6/8/9/10 deferred in the implementation plan), the
  Docs Hub content tools (#6964 — 5 `docs_hub_*` Pro tools), the MCP
  server identity tool (#6956 — `mcp_server_info` +1 base, `_meta`
  block, bridge-name seam; CG-AI sync = tracker Pending row), the Docs
  Hub checkout upsell (#6963 — addon 0.5.1 → 0.5.2; the v0.5.2 ZIP is
  tag-pending so `nvoos-docs-hub-v0.5.1.zip` stays until the new build
  lands), the skill-catalogue gap fixes (#6954 — notes pre-staged into
  the 1.1.99 section in-window), the Elementor autoload fix (#6961),
  content-graph 1.0.9 + checkout polish (#6959/#6958 — sub-project,
  flag-not-edited), three new coding-time skills in-window (spa-ui,
  toolkit-creation, checkout-integration — 63 → 66 with the count
  bookkeeping landing with the introducing PRs), two in-window skill
  updates (#6960 wporg-submission + test-suite 67 → 68 patterns),
  two new OI-1 groups (49: `@since 1.2.0` ×1 in mcp-server-info.php;
  50: `@since 1.6.0` ×63 across the new food-beverage folder) with
  convention notes (docs-hub 0.5.2 ×39 addon-version correct; CG 1.0.9
  sub-project correct; skill-catalogue 1.11.0 ×6 file family; doc-gen
  2.10.0 ×29 folder's 2.x family), OI-2 figure moved to ~353/~1,339/~1,692,
  OI-8 extended (+27 new slugs) and OI-10 extended (32nd toolkit), the
  stale 1.1.98 build-set removal (30 files — 9 + 2 + 19, no
  ollama-demo.json carry — already at 1.1.99), tool count +1 base
  +26 Pro, and the #6955/#6962/#6963 skill-count surfaces verified
  66 in-window)

Preceding windows:
  `docs/project/plans/v1.1.99-docs-catch-up.md` (the v1.1.99
  pass over PRs #6936 + #6938–#6952: the SPA UI stack enhancement (#6952,
  Proposal 060 — toolkit-shell 0.2.0 → 0.3.0 + schedule-anything-spa →
  0.2.0 with shipped vitest suites; the addon partial-bump rule fired on
  the three toolkit-shell test files still pinning 0.2.0 — fixed in-pass),
  the MCP Gateway OAuth 2.1 resource server (#6945, Phase 1 — RFC 9728
  metadata + challenges, zero-dep JWT, scope site-binding, no passthrough;
  Phases 2–5 deferred; gateway stays 0.1.1 with an Unreleased changelog
  section), the Docs Hub Playground demo + wp.org Live Preview
  (#6944/#6946–#6949), the gateway listing prep + icon + Claude Code
  plugin (#6941–#6943), the Figma skills catalogue (#6938 — the
  agent-skills.md catalogue-section fold-in owed), the Gmail body fixes
  (#6939), the Complete-ZIP blueprint stripping (#6936/#6940 — #6936
  merged 14 s before the #6935 catch-up and joined this window per the
  #6787 rule), the Fleet Operator token masking (#6950), the #6941
  deferral executed in-pass (.env.example `STATUS_EXTERNAL_TARGETS` +
  `.context/media-worker.md` external-targets notes), four skill
  reconciliations (test-suite description 66 → 67, playground-demos
  description + docs-hub demo, plugin ×3 + RELEASE-NOTES v1.1.99 section
  with the bundled-base sync, the gateway addon skill's OAuth section),
  the proposals-README 060 entry, the stale 1.1.97 build-set removal
  (6 root files) + the superseded nvoos-toolkit-shell-v0.2.0.zip (the
  v0.3.0 ZIP lands via the in-flight build-spa-addons run), zero
  tool-count change, and **no new OI-1 group** (the window's `@since
  0.3.0` ×12 are the toolkit-shell addon convention; #6937's post-window
  1.1.98/1.1.0 tags were already reconciled))

Preceding windows:
  `docs/project/plans/v1.1.98-docs-catch-up.md` (the v1.1.98
  pass over PRs #6927–#6934: the Figma-to-Elementor design-to-build pipeline
  (#6934, Proposals 057–059 — docs + the new `design-figma-to-elementor`
  skill, coding-time 62 → 63, base bundled 75 → 76, the README repo-map
  fold-in owed — A5's "the introducing PR may skip the count bookkeeping"
  rule fired again: AGENTS.md/copilot-instructions/agent-skills.md were
  updated in-window but the README row was missed; Phase 3 demo/verify
  deferred on live endpoints), the Unified Blueprints release-build + gating
  fixes (#6933 — root-anchored `examples` exclusions, toolkit-enablement
  gating), the gateway sync dispatch + changelog (#6927), the dependabot
  sweep (#6931 — 138/166 alerts via bounded in-major bumps, 28 deferred in
  #6930, dashboard dismissed same-day), three skill reconciliations (plugin
  ×3 + RELEASE-NOTES with the bundled-base sync of #6932's cross-reference,
  ai-assistant-admin bundled ← Zed — the bundled copy was still the
  pre-v1.1.79 rewrite, updates, dependabot-loop reference), the
  proposals-README 057/058/059 entries, the stale 1.1.96 build-set removal
  (30 files — 9 + 2 + 19) with the ollama-demo.json URL carry, zero
  tool-count change, and **no new OI-1 group** (zero added `@since` tags
  in-window))

Preceding windows:
  `docs/project/plans/v1.1.97-docs-catch-up.md` (the v1.1.97
  pass over PRs #6898–#6914: the ECC-inspired agent-harness enhancements
  (#6912, Proposal 056 — cascade routing on both engines via the lib/core
  `CascadeRouter` + the legacy `WP_MCP_AI_Cascade_Executor` gate with Pro Jev
  classifier wiring, the +2 base tools `run_assistant_eval` + `scan_assistant_security`,
  hook profiles, the session distiller's canonical `wp_mcp_ai_memory_stored`
  event, the +1 Pro `suggest_workflows_from_history` miner — all inert by
  default, CREDITS attribution), the OOS parity-gap closure (#6913 — 428/429
  gate envelopes via `translate_gate_exception()`, `apply_pre_response_render()`
  on all three surfaces, the agentic-iteration bridge, the parity script
  35 → 38 features + the `parity-check` CI job), the gateway protocol
  negotiation (#6914), the 23-package public npm publish wave (#6898/#6904–#6909/#6911
  — the v1.1.96-deferred publish executed), tool counts +2 base +1 Pro →
  ~352/~1,313/~1,665, four skill reconciliations (plugin ×3 + RELEASE-NOTES,
  test-suite 65 → 66 patterns with the parity-check gate, updates, the
  workflow-builder cross-reference), the proposals-README 056 entry, the
  stale 1.1.95 build-set removal (30 files — 9 + 2 + 19) with the
  ollama-demo.json URL carry, and **no new OI-1 group** (all 30 in-window
  `@since` tags correctly pre-tagged 1.1.97). **Post-window:** #6925 (branched off this catch-up and squash-merged — PR #6924 closed as superseded) added the non-force gateway mirror sync + bumped the gateway addon 0.1.0 → 0.1.1; the 0.1.1 pins were reconciled into the current-release surfaces by the `chore/1.1.97-post-gateway-bump-docs` follow-up — when a squash of a catch-up branch lands with an addon bump of its own, sweep the just-written version pins before closing the superseded PR)

Preceding windows:
  `docs/project/plans/v1.1.96-docs-catch-up.md` (the v1.1.96
  pass over PRs #6895–#6897: the `@nvdigitalsolutions/nvoos-mcp-bridge` npx
  package + Fleet Operator editor config generators + the `addons/mcp-gateway/`
  public fleet MCP endpoint (Proposals 054/055, addon 0.1.0 → inventory #31,
  addon count 29 → 30, the npm-publish/mirror/Velocity/directory deferrals
  noted), the per-request memory cut + bootstrap hardening (#6896 — the lazy
  tool registry with third-party-wins slug conflicts, the gated OOS pre-warm,
  the catalog-migration `filemtime` short-circuit, the `autoload=false` Pro
  blob options + one-time repair, the bootstrap-integrity guard + fail-soft
  loader, the updater `VERIFY_FILES` +17, the new Site Health
  `wp_mcp_ai_file_integrity` test, 9 dead FF/Yahoo registry entries removed,
  the `Inline_Async_Tick_Trait` double-declaration fatal fixed — 42 MB vs
  74 MB A/B), the worker runtime-version completion (#6895), a new OI-1 group
  (48: `@since 1.2.0` ×1 in the registry's `wp_mcp_ai_tools_init` docblock),
  three skill reconciliations (plugin ×3 + RELEASE-NOTES with the npx-bridge
  transport pointers, test-suite 64 → 65 patterns, updates), the
  FOR_REVIEWERS addon-count 29 → 30 + Fleet Operator 1.0.0 + MCP Gateway rows,
  the ADDON_INVENTORY Fleet Operator row correction (0.1.0 → 1.0.0) + new
  MCP Gateway row #31, the stale 1.1.94 build-set removal (30 files — 9 + 2
  + 19), and zero tool-count change (no registrations))

Preceding windows:
  `docs/project/plans/v1.1.95-docs-catch-up.md` (the v1.1.95
  pass over PRs #6874–#6893:the nine-toolkit hardening wave (#6875/#6876/
  #6882/#6883/#6885/#6886/#6887/#6889/#6892/#6893 — the per-toolkit audit loop
  under the new `mcp-ai-wpoos-toolkit-audit` skill, 62 skills; email-marketing
  verified clean #6891; confirmed fatals: unguarded `shell_exec()`, the
  nonexistent Gemini `generate_content()` + global `wp_mcp_ai_chat_completion()`,
  array-content `preg_match()`/`trim()` fatals, the media `year_month` traversal
  closed, the never-loadable loose incident tools), the real image processing
  for the four placeholder tools (#6881, issue #6877 — Media Worker 3.3.0 →
  3.4.0, the `/api/image/enhance|upscale|edit` routes + the two new Pro traits),
  the `list_mcp_tools` toolkit labels + Newsletter bootstrap fix (#6874 — the
  pre-staged [Unreleased] CHANGELOG converted), the inter-step SSE keepalives
  (#6879 + the Phase 4b offload proposal), the fixture swaps (#6886/#6890),
  a new OI-1 group (47: `@since 1.9.5` ×1 in the keepalive test — plus
  convention notes for the 1.4.0/2.10.0/2.7.0/2.1.0/1.5.0/1.1.55 additions),
  the OI-9 extension (the #6883 port-wide unguarded-resolver sweep queue),
  two skill reconciliations (test-suite description 63 → 64 patterns, the
  README repo-map 61 → 62 fold-in), the FOR_REVIEWERS addon-count 27 → 29 +
  Media Worker 3.4.0 corrections, the proposals-README SSE entry, the
  ADDON_INVENTORY Media Worker row, the stale 1.1.93 build-set removal
  (30 files — 9 + 2 + 19), and zero tool-count change (no registrations);
  post-window #6895 fixed the worker's three missed runtime version strings
  (the partial-bump miss now codified as an A2 scope rule))

Preceding windows:
  `docs/project/plans/v1.1.94-docs-catch-up.md` (the v1.1.94
  pass over PRs #6865–#6872: the ChatGPT plugin addon + OAuth 2.1
  resource-server contract (#6871 — `addons/chatgpt-plugin/` 0.1.0,
  `WP_MCP_AI_OAuth_Resource_Server` + the two `/.well-known/` endpoints, 401
  `WWW-Authenticate` on the MCP route, per-tool `securitySchemes`,
  `nvoos_get_profile` +1 base, RFC-9728 token acceptance, proposals 053,
  inventory #30), the Decision-Scope Guard (#6866, Proposal 052 — domain +
  authority ceilings, banned domains fail closed, the
  `WPMCPAI.Decisions.ScopeDeclared` severity-5 sniff), the toolkit MCP grant
  runtime enforcement + tool exposure + OOS chat parity (#6872 — the
  pre-staged [Unreleased] CHANGELOG converted), the FlowHub MCP OAuth login
  proxy carry (#6867), the safe-mode REST error masking admin opt-in +
  correlation `ref` (#6869, closes #6860), the Site Health fatal/false-positive
  fixes + Docker hardening (#6870), the markdown-it bounded-floor bump
  (#6868), the test-drift fixes (#6865), a new OI-1 group (46: `@since 1.4.0`
  ×1 in `assistant-cpt.php` — the mcp-servers folder's module tags are the
  convention, group-23 extension: `@since 2026.10` ×6 in the
  decision-scope-guard), the in-window readme.txt/mcp-ai-wpoos.php provider
  header 15 → 18 correction (v1.1.93-pass missed spot), four skill
  reconciliations (plugin ×3 + RELEASE-NOTES, elementor ×2,
  test-suite patterns 61–63, dependabot-loop bounded-floor case, updates),
  the in-window ADDON_INVENTORY #30 entry + header refresh, the proposals
  README 052 move + 053 addition, the stale 1.1.92 build-set removal
  (30 files — 9 + 2 + 19), and the OI-8 extension (`nvoos_get_profile` missing
  from tool-status.txt))

Preceding windows:
  `docs/project/plans/v1.1.93-docs-catch-up.md` (the v1.1.93
  pass over PRs #6849–#6863: the media-worker fleet status monitoring (#6849 —
  worker 3.2.0 → 3.3.0, the opt-in STATUS_ENABLED status module, +2 base tools
  `get_fleet_status`/`get_site_uptime`, `GET /mcp-ai/v1/status/sites`, the Pro
  Status Dashboard fleet section), the WP-CLI
  parity & hardening (#6852, Proposal 050 — dispatcher extraction, `tool
  call`, 11 new commands, `manage_options` gating, `docs/operations/wp-cli.md`),
  the FlowHub MCP Apps surfacing + proxy carry (#6854/#6861), the Financial
  Planner OpenStock parity (#6857, Proposal 051 — Finnhub provider, +3 Pro
  tools incl. the orphaned blueprint registration), the October 2026 Track B
  model-catalog refresh (#6863 — v2026.10.03, 238 models, **18 providers —
  Z.AI joins**, provider-count line 15 → 18 with the FOR_REVIEWERS
  provider-list fix), the Media Studio 0.6.1 `/ai/generate` fix
  (#6853/#6858), the schedule create/save trigger fixes (#6855/#6856), the
  validated-tool `auto` aspect-ratio fix (#6859), the undici 7.30.0 advisory
  (#6850), the preset/manifest repairs (#6851), the in-window dependabot-skill
  edit (#6862), four new OI-1 groups (42: `@since 1.1.90` financial wave ×62;
  43: `@since 1.3.0` Pro CLI ×25; 44: `@since 1.2.0` base CLI + tests ×11; 45:
  `@since 1.1.92` remote-site-manager/mcp-app-registry ×6 one-behind), six
  skill reconciliations (plugin ×3 + RELEASE-NOTES, elementor ×2,
  schedule-manager ×2 + drift repair, analytics-reporting/media-workflow/
  product-research CLI canonicalization ×2 each, test-suite patterns 58–60,
  updates), the in-window tool-reference partial count edit reconciled (+3
  Pro), the ADDON_INVENTORY Media Worker 3.2.0 → 3.3.0 row correction, the
  stale 1.1.91 build-set removal (6 files) + superseded media-studio and
  saas-controller addon ZIPs (5 files), and the OI-8 extension (5 new slugs
  missing from tool-status.txt)),
  `docs/project/plans/v1.1.92-docs-catch-up.md` (the v1.1.92
  pass over PRs #6839–#6847: the Media Studio fashion production suite
  (#6839/#6844 — the squash-merged chain; addon 0.1.0 → 0.6.0, 8 `fashion_*`
  Pro tools self-gated on the Media Studio AI service, the 10th Workflow
  Builder preset category, +8 Pro tool count), the inline vision data URLs +
  payload optimization (#6846, pre-staged [Unreleased] CHANGELOG block
  converted), the provider content/credential fixes (#6845), the Pro coverage
  manifest regeneration (#6847), four new OI-1 groups (38–41), five skill
  reconciliations, the stale 1.1.90 build-set removal (30 files), and the OI-8
  extension),
  `docs/project/plans/v1.1.89-docs-catch-up.md` (the v1.1.89
  pass over PRs #6802 + #6804–#6810: the Google Classroom ECA integration
  (proposal 046, 12 flag-gated Pro tools + base foundation + new webhook
  route), the Design System addon rename + token-driven email module
  (proposal 047/048, 8 addon-provided nds tools, 0.1.0 → 0.3.0), the Upwork
  MCP connection mode (#6804), the vision/remote/EZuite live-site fixes
  (#6805), the MCP legacy-dialect handshake cache (#6802), the CodeQL closure
  + dependency advisory patches (#6807/#6806), the coverage-manifest repair
  (#6808), three skill reconciliations (design-elementor-mcp-connection
  bundled sync + stamps, design-email-marketing bundled sync, design-crm MCP
  mode), the in-window ADDON_INVENTORY version correction (0.2.0 → 0.3.0),
  the stale 1.1.87 build-set removal (30 files), and +12 Pro tool-count)
- Standing open items: `docs/project/plans/docs-catch-up-open-items.md`
- Executed PR deferred-item sweep (2026-09-17): issues #6646–#6655; closed
  #6389 as complete
- Model process: `docs/reference/models/model-update-process-2026-07.md`,
  `docs/reference/models/keeping-the-model-catalog-up-to-date.md`
- Cross-worktree Docker test runner + phpcs details:
  `.agents/skills/mcp-ai-wpoos-test-suite/SKILL.md`
- File-update checklist when adding skills/context: `AGENTS.md` §6
