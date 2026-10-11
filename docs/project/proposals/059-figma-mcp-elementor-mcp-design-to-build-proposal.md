# Figma MCP × Elementor MCP — Agentic Design-to-Build Pipeline Proposal

**Status:** Proposed
**Proposal ID:** 059
**Date:** 2026-10-06
**Related:** [041-mcp-apps-remote-sites-connection-type.md](041-mcp-apps-remote-sites-connection-type.md), [057-spa-toolkit-figma-design-file-proposal.md](057-spa-toolkit-figma-design-file-proposal.md), [058-nvoos-design-system-dtcg-token-import-proposal.md](058-nvoos-design-system-dtcg-token-import-proposal.md), skill `design-elementor-mcp-connection`

---

## Executive Summary

NV oOS Pro can already attach MCP servers to assistants as "MCP Apps" (up to 10 per assistant, Remote Sites connections, bridged `mcp_app_*` tools). Two of those servers — **Figma MCP** (reads the design: structure, tokens, screenshots) and **Elementor MCP** (writes the site: pages, sections, globals, responsive settings) — form a complete design-to-build loop that no proposal has yet defined. Elementor's own marketing frames it exactly this way: an agent that can "carry what it finds there straight into the page, section, or component it builds."

This proposal defines that loop as a **7-stage agentic pipeline** — Scope → Read → Plan → Tokenize → Build → Verify → Handoff — running on one NV oOS assistant with two MCP Apps (`figma` + `elementor`), governed by the repo's existing safety machinery: **draft-only writes** (Elementor MCP lands every generation as a draft), human approval at the two state-changing gates (build plan, publish), least-privilege WordPress users on the target site, and the NDS design-system registry as the token guard that stops agents from hardcoding colors (the failure mode industry case studies document).

This is deliberately **not** the token-import proposal (058) or the Figma file proposal (057) — it is the agentic build track that *consumes* them: 057 makes the Figma files pipeline-ready, 058 makes imported tokens real, and this proposal turns the agent loose to build the site with both MCPs.

**Decision required:** approve the 7-stage pipeline, the draft-first safety model, the two new wiring recipes (Figma MCP server + Elementor MCP server on one assistant), and the phased plan (Phase 0–3, ~4 weeks).

---

## Problem Statement

### 1. The two halves exist, the loop doesn't

- **Figma MCP** (official Dev Mode MCP server, hosted at `mcp.figma.com/mcp`) exposes structured design context — `get_design_context` (the mandated entry tool), `get_code`, `get_screenshot`, `get_variable_defs`, `get_metadata` — plus write tools for the canvas.
- **Elementor MCP** (official, Elementor Core/Pro 4.3.0+ on WP 6.8+) builds full pages, sections, Theme Builder parts, popups, **global classes and variables**, and tablet/mobile responsive settings as native Elementor structure — every generation landing as a draft.
- The NV oOS Pro MCP Apps subsystem can host both (verified recipes exist for Elementor; Figma MCP support is absent — a repo-wide grep finds zero Figma integration code). But nothing defines *what the agent should do with them, in what order, under what guardrails*. Without a pipeline spec, the assistant improvises.

### 2. Naive agents hardcode the design — the documented failure mode

Industry case studies are consistent: asking an LLM to "build this Figma design" produces output that *looks* right but hardcodes colors, overrides typography, and skips the design system (Evalogical post-mortem; Monday's engineering writeup). The fix in both cases was an explicit token/component source of truth the agent must resolve against. In this ecosystem that source already exists — the NDS registry (`--nds-*`, DTCG export) and the 057 Figma conventions — but the pipeline must make token resolution a **mandatory stage**, not a suggestion.

### 3. One half of the loop is state-changing — safety must be designed in, not bolted on

Elementor MCP writes to a production site. The agent inherits the WordPress user role of its application password (Elementor's documented permission model), so misconfiguration = too much power; absent gates = unreviewed pages. The repo's draft-first philosophy (email imports, tool dry-runs, Elementor MCP's own draft-only generation) must be the pipeline's spine: the agent never publishes, a human always does.

### 4. Design files are not pipeline-ready

Figma MCP accuracy depends on file structure (seamgen: "teams who invest in structured, well-named, tokenized design files are the ones positioned to benefit"). Unnamed frames, detached components, and raw hex values degrade the build. 057 defines the file conventions; this proposal defines the *runtime contract* the agent relies on (frame naming → page mapping, sections → sections, variables → tokens).

---

## Industry Research: Best Practices & Standards

Sources in full at the end.

### 1. Figma MCP servers

- **Official Dev Mode MCP server** (introduced 2025, beta-free, usage-based paid later): hosted remote server, **OAuth authentication**, remote setup now recommended over the desktop flow.
  - **Read group** (this pipeline's inputs): `get_design_context` (structured hierarchy + layout + text + components + variables — **the required first call**), `get_code` (HTML/CSS/React), `get_screenshot`, `get_variable_defs` (design tokens), `get_metadata`.
  - **Write group** (out of scope here, but noted): agents can create/modify frames, components, variables directly in Figma.
  - **Integration rules (Figma's own, mandatory):** run `get_design_context` first for the exact node(s); don't skip; fall back to other tools only when it fails or a selection is missing.
  - **Limits that shape the pipeline:** read-tool quotas per seat/plan; large design responses can blow client context limits → the pipeline must work node-by-node (per page/frame), never whole-file.
- **Community alternatives** (fallbacks): Framelink Figma MCP (uses the Figma REST API), GLips `figma-context-mcp`. Auth via Figma API token where OAuth is unavailable.

### 2. Elementor MCP

- **Official** (Elementor 4.3.0+, WP 6.8+; Abilities API + WordPress MCP Adapter): creates/edits **pages, sections, Theme Builder parts, popups, global classes and variables, responsive settings**; Pro exposes Forms, Popups, Loops, e-commerce widgets, Components, Dynamic Content, Headers/Footers. **Every generation lands as a draft.**
- **Permission model:** the agent cannot exceed the capabilities of the WordPress user whose application password it uses (WordPress MCP Adapter guidance: dedicated least-privilege user per workflow).
- **Third-party servers** (same app-password auth): `msrbuilds/elementor-mcp` (500+ tools), EMCP (509 tools, works on free Elementor 3.20+), `vapvarun/elementor-mcp` (106 widgets, one-call landing-page build), `Community-Tech-UK/mcp-wordpress-elementor` (71–82 unified WP+Elementor tools). The pipeline's Elementor stage is server-agnostic; tool names vary and are discovered at runtime (the repo's MCP Apps discovery already handles this).

### 3. Design→code agentic patterns

- **Mandatory first read:** Figma's integration rules make `get_design_context` the entry point — mirror this as pipeline stage order, not preference.
- **Single-responsibility pipeline nodes** (Monday engineering, 11-node flow): separate nodes for querying sources of truth, layout analysis, localization, and *resolving raw design values into semantic tokens*. This proposal's 7 stages map directly onto that decomposition.
- **A design-system guard against hardcoded output** (Evalogical): the fix for hardcoded-color agent output was an MCP describing components, valid props, mandatory tokens, and accessibility rules. Here, the NDS registry + 058's import + Elementor MCP's global-classes/variables tools play that role.
- **Design-for-the-pipeline** (seamgen, LogRocket): structured, well-named, tokenized files are the unlock; screenshots-only flows ("screenshot-and-describe") are the anti-pattern the tools eliminate.
- **Agentic UX floor** (from 057's research §6): observable, interruptible, reversible — the pipeline must show the plan before building, allow stop/retry per stage, and never publish without a human.

### 4. Safety standards (state-changing MCP)

- **Draft-first writes** — Elementor MCP's own default; the pipeline adds: agent *never* calls publish/status-transition tools.
- **Least privilege on both ends** — dedicated low-role WP user + application password on the Elementor site; bridged tools still pass the repo's per-tool capability gating.
- **Human gates at state-changing boundaries** — plan approval before any write; publish is human-only. This matches the repo's existing HITL/destructive-ops-gate philosophy.
- **Secret custody** — Figma OAuth (existing MCP Apps OAuth machinery: RFC 8414 discovery, DCR, PKCE, refresh — v1.1.91+) or a Figma PAT stored via the existing encrypted secrets store; Elementor app passwords via the Remote Sites encrypted `mcp_server` connection type (verified recipes in the `design-elementor-mcp-connection` skill).
- **Network guard** — `mcp.figma.com` and each Elementor host must be on the MCP App allowed-hosts list (`is_url_allowed()`); no bypasses.

---

## Proposed Solution

### 1. The pipeline (7 stages, one assistant, two MCP Apps)

```
Scope → Read → Plan → Tokenize → Build → Verify → Handoff
```

| # | Stage | MCP tools used | Output | Gate |
|---|---|---|---|---|
| 1 | **Scope** | assistant config (site, page target) | scoped work item: Figma node/page + target WP page + audience | — |
| 2 | **Read** | Figma MCP: `get_design_context` (first, per node), `get_variable_defs`, `get_screenshot` | structured design context + token list + reference screenshots | — |
| 3 | **Plan** | agent reasoning over Read output | **build manifest**: page map (frame→page, section→section), widget mapping per section, content copy, responsive notes (tablet/mobile), token mapping plan | **Approve plan (human)** |
| 4 | **Tokenize** | Elementor MCP global-classes/variables tools **or** 058 importer dry-run → NDS registry → Elementor globals | site-wide tokens applied (global classes + variables), never raw hex in widgets | Token diff shown |
| 5 | **Build** | Elementor MCP: create page → sections → widgets → responsive settings | page built **as draft**, sections per manifest | Progress observable (tasks drawer) |
| 6 | **Verify** | Figma MCP `get_screenshot` vs Elementor read-back/screenshot; diff report | verification report (per-section match, missing assets, spacing notes) | Human review |
| 7 | **Handoff** | none (agent stops) | human publishes via WP/Elementor; agent logs receipt (cost/usage badges) | **Publish = human only** |

**Stage-order rules (normative, from research §1/§3):** `get_design_context` is always the first Figma call per node; Tokenize always precedes Build; the agent never writes to Figma in this pipeline (write group out of scope); the agent never publishes.

### 2. Assistant wiring (recipes + topologies)

**Topology A — single assistant (default):** both MCP Apps on one NV oOS assistant; the pipeline runs end-to-end there.

**Topology B — split (Figma MCP lives on another client, e.g. Claude Code):** the design-reading half (Scope → Read → Plan) runs on the client that holds the Figma MCP; the site-building half (Tokenize → Build → Verify → Handoff) runs on the NV oOS assistant, which holds only the Elementor MCP App. The **build manifest is the handoff artifact** across the boundary, carried by one of three existing channels:

1. **A2A protocol** (`includes/a2a/`, base plugin) — the design-side agent sends a task to the NV oOS assistant (advertised via `.well-known/agent.json`) with the manifest + reference screenshots as **artifacts**; the assistant builds and replies through task state.
2. **NV oOS as an MCP server** (`addons/pro/includes/mcp-servers/` — per-toolkit JSON-RPC endpoints `/wp-json/mcp-ai-pro/v1/mcp/{slug}` + `/.well-known/mcp`, toolkit-scoped tokens) — Claude Code connects to the site as an MCP client and calls the assistant's tools (including bridged `mcp_app_elementor_*` tools) directly, passing the manifest as a tool argument.
3. **Paste** — the design-side agent emits the manifest JSON; the human pastes it into the NV oOS chat.

The manifest schema already anticipates the split: `tokens[]` carries resolved values (no live `get_variable_defs` needed on the build side) and `assets[]` + reference screenshots travel as part of the artifact — so **Verify in Topology B compares the draft against the carried references, not live Figma calls**. Stage order and the safety gates (plan approval, drafts only, human publish) are topology-independent.

**Wiring:**

- **Two MCP Apps on the assistant (Topology A):** label `figma` (server `https://mcp.figma.com/mcp`, `auth_type: oauth` via the existing MCP Authorization flow) and label `elementor` (Remote Sites `mcp_server` connection, `auth_type: basic` with `wp-username:application-password` — the verified recipe). Bridged tools appear as `mcp_app_figma_get_design_context` / `mcp_app_elementor_*` in chat.
- **Topology B needs only the `elementor` app** on the assistant; the Figma side is configured in the other client, and the handoff uses one of the three channels above.
- **New wiring work:** a documented **Figma MCP server recipe** (host allowlist entry, OAuth specifics, quota notes) — none exists today (verified). The OAuth machinery already handles discovery/DCR/PKCE/refresh, so the recipe is configuration + docs, not new auth code. Fallback recipe: community Figma MCP with `auth_type: header` PAT.
- **One pipeline, many sites:** EMCP-style multi-site setups supported by adding one Remote Sites connection per target site; the manifest names the site.
- **Security Center:** `mcp.figma.com` + each Elementor host added to MCP App Allowed Hosts; per-tool capability gating unchanged.

### 3. Tool mapping (read half → artifact, write half → action)

**Figma MCP → artifacts**

| Tool | Artifact in pipeline |
|---|---|
| `get_design_context` | node hierarchy, layout constraints, text content, component/variable refs → Plan inputs |
| `get_variable_defs` | design tokens → Tokenize inputs |
| `get_screenshot` | reference frames → Verify baselines |
| `get_code` | optional code fallback when structure is ambiguous (not the build path) |

**Elementor MCP → actions** (official tool names vary; discovered at runtime)

| Capability | Used at |
|---|---|
| read page / site settings | Read-back during Verify; plan feasibility checks |
| create page / section / widgets | Build |
| global classes + variables | Tokenize |
| responsive tablet/mobile settings | Build |
| Theme Builder parts / popups (Pro) | Build (extended scope) |

### 4. Integration with 057 & 058 (optional upgrades, not prerequisites)

- **059 owns the runtime contract** (a Phase 3 deliverable, shipped in the skill's `reference/design-file-contract.md`): frames named per WP page, top-level sections per Elementor section, variables (not raw hex) for every color/type/spacing decision. The Scope stage rejects files that don't meet the contract with a specific report. When 057 is built, it adopts and extends this contract rather than inventing its own.
- **058's importer becomes the tokenize path:** `get_variable_defs` output → DTCG-shaped JSON → 058 dry-run → NDS registry → Elementor MCP applies globals. When 058 isn't shipped yet, the pipeline falls back to Elementor MCP's own global-variables tools directly.
- **The Elementor Global Colors stub** (`class-nvoos-nds-integration-elementor.php`, "full bidirectional sync deferred") is not required by this proposal — Elementor MCP's global tools cover application. It remains a separate future optimization.

### 5. Safety & cost model

- Draft-only writes; publish is human-only; plan approval before any write.
- Least-privilege WP user on the target site (minimum role for the build; read-only role for Verify-only runs).
- Cost/usage: Figma read quotas (per seat/plan) and Elementor operations are surfaced via the repo's usage/cost tracking where available; per-run token/call budgets in the pipeline config.
- All state changes are activity-logged; approvals recorded (existing HITL/audit machinery).

---

## Architecture

```mermaid
flowchart LR
    subgraph NV oOS assistant
        AG[Assistant + MCP Apps<br/>figma (oauth) + elementor (basic)]
        PIPE[Pipeline: Scope→Read→Plan→<br/>Tokenize→Build→Verify→Handoff]
    end
    FIG[Figma MCP<br/>mcp.figma.com/mcp<br/>design context + variables + screenshots]
    ELE[Elementor MCP<br/>target WP site<br/>pages/sections/globals/responsive — drafts]
    NDS[NDS registry + 058 importer<br/>token guard]
    AG --> PIPE
    PIPE --> FIG
    PIPE --> NDS
    PIPE --> ELE
    PIPE -->|plan + publish gates| HUMAN[Human review / publish]
    ELE -->|drafts only| WP[WP drafts<br/>publish by human]
```

**Topology B (split):** the same pipeline splits across two agents — the Figma-holding client (Claude Code) runs Scope → Read → Plan and hands the **build manifest** (+ reference screenshots) to the NV oOS assistant via A2A artifacts, the per-toolkit MCP server endpoints, or paste; the assistant runs Tokenize → Build → Verify → Handoff with only the Elementor MCP App. The manifest is the boundary.

---

## Implementation Phases

### Phase 0 — Wiring & recipes (Week 1)
1. Document + verify the **Figma MCP recipe** on the MCP Apps subsystem: Remote Sites/OAuth flow against `mcp.figma.com/mcp`, allowlist entries, quota notes; confirm `mcp_app_figma_*` bridged tools appear in chat.
2. Package the **Elementor MCP recipe** as a reusable connection preset (Remote Sites `mcp_server` + basic auth + dedicated user guidance) — mostly docs on top of the verified skill flow.
3. Define the **build-manifest schema** (page map, sections, widget mapping, tokens, responsive) — the plan artifact the agent emits and the human approves.

### Phase 1 — Pipeline skeleton (Weeks 1–2)
4. Pipeline prompts/rules: stage order, `get_design_context`-first, tokenize-before-build, never-publish, Figma write-tools disabled for the pipeline.
5. Scope/Read/Plan stages working end-to-end against a sample 057-style Figma file; manifest approval UI (chat card or HITL bar reuse).

### Phase 2 — Tokenize, Build, Verify (Weeks 2–3)
6. Tokenize: `get_variable_defs` → 058 dry-run (or direct Elementor globals fallback) with token diff.
7. Build: page creation as drafts from an approved manifest (sections, widgets, responsive); per-stage progress in the tasks drawer; interrupt/retry per section.
8. Verify: screenshot compare (Figma vs rendered draft) + read-back report; failure = per-section fix loop, never silent.

### Phase 3 — Conventions, demo, docs (Weeks 3–4)
9. Publish the **design-for-the-pipeline contract** as a first-class deliverable (ships in the skill's `reference/design-file-contract.md`); 057, when built, adopts it.
10. End-to-end demo: one sample Figma page → draft page on a test site → human publishes; record the run (time, cost, approvals).
11. Docs: user guide "Build a site from Figma with NV oOS" (both MCP recipes, safety model, troubleshooting).

---

## Key Design Decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | Seven stages with normative order; `get_design_context` first, Tokenize before Build | Figma's own integration rules; Monday's single-responsibility-node evidence (§3) |
| D2 | Agent writes drafts only; publish is human-only | Elementor MCP's default + repo draft-first philosophy (§4) |
| D3 | NDS registry + 058 dry-run as the tokenize guard; Elementor globals as fallback | Evalogical failure mode: agents hardcode colors unless a token source of truth is mandatory (§3) |
| D4 | Figma write-tools disabled for this pipeline (read-only Figma side) | Scope discipline; the design file is source of truth, the agent doesn't edit it here |
| D5 | Server-agnostic Elementor stage (official or third-party MCP) | Tool names vary; discovery is runtime; recipes stay compatible (§2) |
| D6 | The pipeline defines its own minimal runtime contract (Phase 3 deliverable); 057 adopts and extends it later | 059 ships standalone — the contract is a 059 artifact, not a 057 dependency |
| D7 | One assistant, two MCP Apps, one Remote Sites connection per target site | Reuses the existing 10-app/connection architecture; multi-site via extra connections |
| D8 | The build manifest is the topology-agnostic handoff artifact — Topology A uses it as the internal plan; Topology B (Figma MCP on another client) exchanges it across the boundary via A2A artifacts, the per-toolkit MCP server endpoints, or paste. Stages 1–3 run on the design-side agent, 4–7 on the build side | The split is the realistic case (Figma MCP lives on Claude Code); the manifest schema already carries resolved tokens + assets so the build side needs no live Figma access |

---

## Risks & Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Agent hardcodes colors/typography despite token rules | Medium | Tokenize is a mandatory stage; Verify checks widget-level values against the manifest |
| Figma quotas/context limits break large-file runs | Medium | Node-by-node scoping (never whole-file); quotas surfaced; screenshot fallback for oversized nodes |
| Elementor MCP user has excessive privileges | Medium | Dedicated least-privilege user per workflow; bridged tools still per-tool capability-gated |
| Agent publishes or over-edits a live page | Low | Draft-only rule + tool-level restriction (no publish tools bridged); audit logging on all writes |
| Figma MCP beta → usage-based pricing changes cost model | Medium | Cost/usage badges; per-run budgets; community-server fallback recipe maintained |
| Design files not 057-conforming produce garbage | High | Scope-stage rejection with a concrete remediation report; demo ships with a conforming sample file |

---

## Success Metrics

- **2** verified MCP recipes (Figma + Elementor) with a working assistant wiring on a test site.
- **1** build-manifest schema, approved before any write in 100% of runs.
- **1** end-to-end demo: sample Figma page → draft Elementor page → human publish, with a recorded run log (stages, tokens applied, approvals, cost).
- **0** agent-initiated publishes; **0** raw-hex widget values in built pages (tokenized ≥ 95%).
- Verify stage catches ≥ 90% of deliberate mismatches in a seeded test (screenshot + read-back diff).
- Docs: design-for-the-pipeline contract + user guide published.

---

## Open Questions

1. **Figma auth preference** — OAuth (recommended by Figma) vs. community server + PAT fallback; which becomes the documented default for NV oOS users?
2. **Plan approval surface** — reuse the HITL approval bar, a new manifest card, or the existing slash-command flow?
3. **Multi-page scope** — one page per run (recommended for context/accuracy) or allow site-section batches with per-page manifests?
4. **Which Elementor server is the reference implementation** — official (Pro for advanced widgets) or a third-party (500+ tools) for the documented default recipe?
5. **Publish handoff** — should Verify produce a one-click "publish this draft" admin link for the human, or stay strictly out of the publish path?
6. **Figma write-tools** — permanently disabled for this pipeline (D4) or a future opt-in "agent updates the design from build feedback" mode?

---

## Decision Required

1. Approve the 7-stage pipeline and the normative stage-order rules.
2. Approve the draft-first safety model (agent never publishes; plan approval before writes; least-privilege users).
3. Approve the two MCP App wiring recipes (Figma OAuth; Elementor basic/app-password) and Phase 0–3 (~4 weeks).
4. Answer open questions 1 and 4 before Phase 0 starts.

---

## Sources

**Figma MCP**
- Figma — MCP server guide (integration rules, required flow): https://github.com/figma/mcp-server-guide
- Figma Learn — Guide to the Figma MCP server: https://help.figma.com/hc/en-us/articles/32132100833559-Guide-to-the-Figma-MCP-server
- Figma Dev Docs — Tools and prompts (get_design_context entry point): https://developers.figma.com/docs/figma-mcp-server/tools-and-prompts/
- Figma Blog — Introducing the Dev Mode MCP server (three initial tools): https://www.figma.com/blog/introducing-figma-mcp-server/
- mcpverdict — Figma MCP Server: setup and limits (OAuth, quotas, context limits): https://mcpverdict.com/mcp/servers/design/figma/

**Elementor MCP**
- Elementor — How to Build and Edit Your Site Using Elementor MCP (role-bound permissions, Pro tooling): https://elementor.com/help/how-to-build-and-edit-your-site-using-elementor-mcp/
- Elementor — Introducing the Elementor MCP: https://elementor.com/mcp/
- Elementor Blog — Elementor MCP beta (WordPress AI Abilities): https://elementor.com/blog/elementor-mcp-beta/
- The Plus Addons — Official 4.3 setup guide (drafts, globals, responsive, add-on abilities): https://theplusaddons.com/blog/what-is-the-elementor-mcp/
- Third-party servers: https://github.com/msrbuilds/elementor-mcp · https://emcptools.com/ · https://github.com/vapvarun/elementor-mcp · https://github.com/Community-Tech-UK/mcp-wordpress-elementor
- WordPress MCP Adapter + abilities: https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/

**Design→code agentic patterns**
- seamgen — Figma MCP: Complete Guide to Design-to-Code Automation (structured files = the unlock): https://www.seamgen.com/blog/figma-mcp-complete-guide-to-design-to-code-automation
- Monday Engineering — How We Use AI to Turn Figma Designs into Production Code (11 single-responsibility nodes, token resolution): https://engineering.monday.com/how-we-use-ai-to-turn-figma-designs-into-production-code/
- Evalogical — design-system MCP as the guard against hardcoded colors: https://medium.com/@nithin_94885/from-zero-to-launch-the-complete-ai-agent-workflow-for-building-web-apps-7fa64c653af6
- LogRocket — How to structure Figma files for MCP and AI-powered code generation: https://blog.logrocket.com/ux-design/design-to-code-with-figma-mcp/
- Figma Blog — Design Systems And AI: Why MCP Servers Are The Unlock: https://www.figma.com/blog/design-systems-ai-mcp/

**In-repo patterns** (authoritative)
- `design-elementor-mcp-connection` skill — verified Elementor MCP connection recipes, MCP Apps subsystem, allowlist, auth mapping, OAuth machinery (v1.1.91+)
- `addons/pro/includes/mcp-apps/` — registry/client/bridge/OAuth (code map in the skill)
- `addons/nvoos-design-system/includes/class-nvoos-nds-dtcg-exporter.php` — token guard's export side
- 057/058 proposals — file conventions (runtime contract) and importer (tokenize path)

---

**Note:** This proposal covers the agentic build track only. Token import is 058; the Figma file system is 057; Elementor Global Colors sync (the NDS stub) remains a separate future optimization. When in doubt, the MCP Apps source and the `design-elementor-mcp-connection` skill are authoritative over this document.
