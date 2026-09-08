# Use Cases & Quickstarts Rev 3.0 — Docs Refresh Plan

**Status:** Executed 2026-09-08
**Scope:** `docs/getting-started/USE_CASES_AND_QUICKSTARTS.md` (+ fact sheet + cross-refs)
**Branch:** `chore/use-cases-rev3-docs-refresh`

## 1. Context

Rev 2.0 of `USE_CASES_AND_QUICKSTARTS.md` was written against plugin 1.1.19
(May 2026). The plugin is now at 1.1.72 (Sep 7, 2026) — a 53-release window
(v1.1.20 → v1.1.72) that landed ~30 major capability groups. The doc's counts,
versions, pricing, subsystem descriptions, and repo links are all stale, and
the docs tree was reorganized (`docs/developer/`, `docs/operations/`,
`docs/project/`) without updating the doc's links.

Industry-standards grounding (researched via web search before this plan):

- **Diátaxis** (`diataxis.fr`): separate tutorials / how-to / reference /
  explanation. This doc stays task-oriented; deep reference stays in the
  dedicated docs and is linked, not inlined.
- **Quickstart patterns** (Fern, Document360, Pluralsight, Supabase):
  time-to-first-value, copy-paste steps, numbered procedures with a
  verification step, prerequisites up front, per-guide time estimates.
- **Drift prevention / docs-as-product** (Fern maintenance guide, Author-it,
  Cinfinity): update the single source of truth (fact sheet) first, version
  stamping, automated link checks.
- **Google Developer Style** (`developers.google.com/style`): prescriptive,
  imperative numbered steps — kept as the doc's voice.

## 2. Work items

### P0 — Correctness pass

- [x] Header + Document History: Rev 3.0, tested against 1.1.72, new history row.
- [x] Counts sweep: ~303 base + ~1,265 Pro (~1,568 total), 15 providers,
      27 addons, 74 base + 41 Pro bundled skills, model catalog `2026.09.05`
      (228 entries).
- [x] Cost Considerations rebuilt from catalog `2026.09.05`: drop retired
      DeepSeek/imagen-4/gemini-3.1-flash rows; add deepseek-v4 family, gpt-5.6
      family, claude-opus-5, gemini-3.6/3.7/3.8-flash, kimi-k3, gpt-image-2;
      fix drifted prices (gpt-5-pro, gpt-4.1 family, gemini-3.1-pro, sonnet-4-6
      output, claude-opus-5 input).
- [x] Fix every broken link to post-reorg paths.
- [x] Subsystem facts: Chat SPA v0.7.0 (+ Phase 8), Docs Hub v0.4.3,
      33 toolkit MCP servers, OAuth 2.0 MCP auth.
- [x] Rewrite "What's New" as "May 2026 → September 2026", themed by capability.

### P1 — Structural enhancements

- [x] New use cases: Google Workspace automation (§2.x), Vision Analysis
      (§3.x), Workflow Builder + Pro Schedule Manager (§4.x), Deep Research
      (§5.x), Pro SPA v2 (§13.x), Content Graph ecosystem (§6.x).
- [x] Update §6.0 Toolkit MCP Servers: 33 servers, OAuth 2.0 (PKCE), JSON-RPC
      HTTP-200 compat, legacy HTTP+SSE transport, Content Graph Pro port.
- [x] Update §13 Chat SPA: v0.7.0, Phase 8, current bundle-size wording.
- [x] Update §14 Docs Hub: v0.4.3 (wp.org pass), link-fixer engine.
- [x] Compliance Posture: point at `docs/operations/security/SECURITY_POSTURE.md`
      and `docs/operations/compliance/*`.
- [x] Fresh-install provider defaults reflected in quickstart step 1s.

### P2 — Consistency & integration sweep

- [x] Fact sheet `_USE_CASES_FACT_SHEET.md` fully refreshed (Rev 3.0).
- [x] `docs/DOCUMENTATION_INDEX.md` stale May-17 note + file description.
- [x] `docs/QUICK_REFERENCE.md` cross-ref note.
- [x] `docs/getting-started/README.md` description.
- [x] Quick Reference matrix: new rows for the new use cases.
- [x] Troubleshooting + Best Practices additions (rate-limit Lift, nonce
      self-heal, ecosystem-addon selection).

### P3 — Polish

- [x] TOC regeneration, reading-time recalc.
- [x] Roadmap check: AI Tool Builder still "Coming Soon - Phase 2.9" (verified
      `class-wp-mcp-ai-ai-tool-builder-settings-page.php`) — kept in roadmap.

## 3. Execution log

| Step | Status | Notes |
|---|---|---|
| Fact sheet Rev 3.0 | ✅ | All counts re-derived from live files |
| Guide Rev 3.0 | ✅ | P0 + P1 + P2 + P3 edits applied |
| Cross-ref sweep | ✅ | DOCUMENTATION_INDEX, getting-started README |
| Validation | ✅ | Stale-value grep, link existence, catalog price diff |

## 4. Commit breakdown

1. `docs: refresh use-cases fact sheet to Rev 3.0 (v1.1.72 ground truth)`
2. `docs: refresh use cases & quickstarts guide to Rev 3.0`
3. `docs: sync documentation index cross-references for use-cases Rev 3.0`

## 5. Validation

- `git grep` for stale tokens (`~830`, `~195 base`, `~635 Pro`, `2026.05.04`,
  `1.1.19`, `v0.6.0`, `v0.3.9`) in both files — remaining hits must be
  intentional history only.
- Link existence check for every `docs/...` path in the guide.
- Price rows diffed against `includes/data/model-catalog.json` (per-1K units).

## 6. Open items

- OI-1: The guide's cost tables cover headline models only; a full per-provider
  price appendix remains in the model catalog docs (`docs/reference/models/`).
- OI-2: Quick Reference matrix time/cost estimates are editorial, not measured;
  refresh them from usage data if that becomes available.
