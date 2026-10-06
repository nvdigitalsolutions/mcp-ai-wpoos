# Figma MCP × Elementor MCP — Implementation Plan

**Date:** October 6, 2026
**Status:** 🚧 IN PROGRESS (Phases 0–2 shipped this session; Phase 3 deferred on live endpoints)
**Proposal:** [`059-figma-mcp-elementor-mcp-design-to-build-proposal.md`](./059-figma-mcp-elementor-mcp-design-to-build-proposal.md)

---

## Approach

059 ships standalone — its hard dependencies (the Pro MCP Apps subsystem, OAuth
machinery, and the Elementor MCP connection recipe) already exist and are
verified in the `design-elementor-mcp-connection` skill. The pipeline itself
needs **no new PHP subsystem**: it is agent behavior (stage order, gates,
manifest contract), which this repo ships as **bundled Agent Skills** —
discovered from `includes/bundled-skills/`, installed into the uploads skill
store (`WP_MCP_AI_Skill_Registry::install_bundled_skills()`, companion files
supported via `collect_companion_files()` with the `md`/`json`/`yaml` extension
allowlist), mirrored under `.agents/skills/` for coding-time agents, and
auto-generated into the OKF skill-knowledge bundle.

Deliverables this session:

1. **Bundled skill `design-figma-to-elementor`** (both locations, identical):
   - `SKILL.md` — the 7-stage pipeline guide, normative rules, safety model
     (lean; < 500 lines per the Anthropic skill-authoring standard).
   - `reference/build-manifest.md` — the plan artifact spec (what the agent
     emits and the human approves).
   - `reference/build-manifest.schema.json` — JSON Schema (draft-07) for the
     manifest.
   - `reference/design-file-contract.md` — the design-for-the-pipeline contract
     (a 059 deliverable; 057 adopts it later).
2. **Proposal 059 standalone edits** — D6/§4/Phase 3 reframed so 057/058 are
   optional upgrades, not prerequisites.
3. **User guide** `docs/user-guides/figma-to-elementor-pipeline.md`.
4. **Skill-count documentation** — AGENTS.md + copilot instructions 62 → 63.

Research backing for the design choices (cited in the proposal): Figma's
mandatory `get_design_context`-first integration rules; Monday Engineering's
single-responsibility pipeline nodes with a dedicated token-resolution node;
the Evalogical post-mortem (agents hardcode colors without a mandatory token
source); Elementor MCP's draft-only generation + role-bound permissions;
Anthropic's Agent Skills authoring standards (name + description drive
activation; lean body, reference material in companion files).

---

## Phase 0: Skill scaffolding & manifest schema — 4 stories

| Story | Deliverable | Files |
|---|---|---|
| 0.1 SKILL.md | Front matter (type/name/description/license/metadata), when-to-use, mental model, hard rules, 7-stage guide, safety, troubleshooting | `includes/bundled-skills/design-figma-to-elementor/SKILL.md` |
| 0.2 Build-manifest reference | Manifest spec: page map, sections, widget mapping, token mapping, assets, approvals + example | `…/design-figma-to-elementor/reference/build-manifest.md` |
| 0.3 Manifest JSON Schema | Draft-07 schema; validated JSON | `…/design-figma-to-elementor/reference/build-manifest.schema.json` |
| 0.4 Design-file contract | Frame/section/variable naming, auto-layout, text styles — the runtime contract Scope enforces | `…/design-figma-to-elementor/reference/design-file-contract.md` |

**Gate:** SKILL.md front matter matches the shipped format exactly (mirrors
`design-elementor-mcp-connection`); schema parses as valid JSON; SKILL.md body
< 500 lines; identical mirror copy under `.agents/skills/`.

---

## Phase 1: Standalone proposal edits & docs — 3 stories

| Story | Deliverable | Files |
|---|---|---|
| 1.1 Standalone edits | D6, §4 heading + first bullet, Phase 3 story 9 — 057/058 reframed as optional upgrades; contract owned by 059 | `docs/project/proposals/059-…-proposal.md` |
| 1.2 User guide | Practical walkthrough: wire both MCP Apps, run Scope→Read→Plan, approve manifest, build to draft, verify, human publish | `docs/user-guides/figma-to-elementor-pipeline.md` |
| 1.3 Count updates | Skill inventory 62 → 63 (`.agents/skills` count references) | `AGENTS.md`, `.github/copilot-instructions.md` |
| 1.4 Split topology | Topology B (Figma MCP on another client, e.g. Claude Code): proposal D8 + §2, SKILL.md "Two topologies" + stage-split + troubleshooting rows, user-guide section. Handoff channels verified in-repo: A2A (`includes/a2a/`) artifacts, per-toolkit MCP servers (`addons/pro/includes/mcp-servers/`), paste | proposal 059, `SKILL.md` (both mirrors), user guide |

**Gate:** all referenced file paths exist; counts consistent.

---

## Phase 2: Wiring recipes (docs, against shipped machinery) — 2 stories

| Story | Deliverable | Files |
|---|---|---|
| 2.1 Figma MCP recipe | Host allowlist entry (`mcp.figma.com`), OAuth (existing MCP Apps OAuth flow), quota notes, bridged `mcp_app_figma_*` expectations | `SKILL.md` §Wiring (authoritative; proposal Appendix) |
| 2.2 Elementor recipe pointer | Cross-reference the verified `design-elementor-mcp-connection` skill; least-privilege-user guidance | `SKILL.md` §Wiring |

**Gate:** recipe text consistent with the verified skill and the MCP Apps folder
contract (`MAX_APPS_PER_ASSISTANT = 10`, allowlist precedence, OAuth chain).
**Done:** Figma recipe (§Wiring steps 2–3: `mcp.figma.com`, OAuth, community
fallback) and Elementor pointer (§Wiring steps 1) shipped in SKILL.md this session.

---

## Phase 3: Live pipeline validation (deferred — requires live endpoints)

| Story | Deliverable | Notes |
|---|---|---|
| 3.1 End-to-end demo | Sample Figma page → draft Elementor page → human publish | Requires a Figma account + an Elementor 4.3+/WP 6.8+ site; run when available |
| 3.2 Verify-stage seed test | ≥90% catch rate on seeded mismatches | Requires live sites; deferred with Phase 3.1 |
| 3.3 Cost/usage wiring | Figma quota + Elementor op cost surfaced in run logs | Deferred; needs a real run to calibrate |

---

## Test Coverage Plan

No PHP code ships in Phase 0–1, so the checks are structural:

| Check | How |
|---|---|
| SKILL.md front matter parses (name/description present, no stray YAML) | Manual diff against `design-elementor-mcp-connection` header format |
| `build-manifest.schema.json` is valid JSON | `node -e "JSON.parse(...)"` gate |
| Companion files installable | extensions `md`/`json` ⊂ `ALLOWED_EXTRA_EXTENSIONS` (verified in registry source) |
| SKILL.md line budget | `< 500` lines (Anthropic standard) |
| Mirror parity | `diff` of `.agents/skills/` vs `includes/bundled-skills/` copies |

Live-pipeline tests (Phase 3) are explicitly deferred — they need external
accounts and are not runnable in CI.

---

## Deferred Items (tracked)

| Item | Status |
|---|---|
| Live end-to-end demo + Verify seed test | Blocked on Figma/Elementor endpoints; Phase 3 |
| Figma write-tools opt-in mode | Proposal open question 6 — intentionally not shipped |
| Elementor Global Colors sync (NDS stub) | Separate future optimization, not 059 scope |
| 057 adoption of the design-file contract | Parked per prioritization decision; contract is 059-owned |

---

## Validation Performed This Session

- Proposal 059 standalone edits applied (D6, §4, Phase 3).
- Schema JSON validated with Node (`build-manifest.schema.json` parses).
- SKILL.md line counts (193, under the 500-line budget) and mirror parity
  (`.agents/skills/` ≡ `includes/bundled-skills/`, `diff -r` clean) checked.
- Companion-file installability confirmed against the registry allowlist
  (`md`/`json` ⊂ `ALLOWED_EXTRA_EXTENSIONS`).
- Count references updated: coding-time skills 62 → 63 (AGENTS.md ×2,
  copilot-instructions.md ×2), design-* 33 → 34, runtime base bundled 75 → 76
  (`docs/features/agent-skills.md`, AGENTS.md). Verified against the live
  directory counts (34 design-*, 63 coding-time).
- Split topology added (Topology B — Figma MCP on another client): proposal
  059 D8 + §2, SKILL.md "Two topologies" + stage-split + troubleshooting,
  user guide. Handoff channels verified against `includes/a2a/` and
  `addons/pro/includes/mcp-servers/` folder contracts.
