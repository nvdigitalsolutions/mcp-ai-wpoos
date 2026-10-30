# Proposal 095 — Astro Design System + MCP Server: The Assistant-Controllable Storefront (Design Ops & CMS Tools for the Astro CMS)

**Status:** PENDING
**Date:** 2026-10-30
**Relates to:** Proposal 092 (Astro storefront — §3.9 promised the "Astro MCP server — agent-driven storefront edits" node; this is its design) · Proposal 058 (DTCG token import/export pipeline + validator discipline) · Proposal 047/048 (NV oOS Design System addon) · Proposal 085/090 (backend contract invariance) · `design-elementor-mcp-connection` skill (MCP Apps client-side pattern) · `mcp-ai-wpoos-astro-storefront` skill (storefront ops)
**Author:** NV oOS coding agent (Zed)

---

## 1. Problem Statement

The storefront that Proposal 092 shipped is functionally complete but **stylistically raw**. Three structural gaps sit in the design layer:

1. **There is no design system.** `src/styles/global.css` is a single ~300-line file with eight flat `--nv-*` custom properties, hand-written values, no typographic or spacing scale, no dark mode, no theme concept, and no component layer — the "basic theme" problem. Every visual decision is a code change plus a full mirror deploy.
2. **The promised agent-control node is missing.** 092 §3.9 drew an "Astro MCP server — agent-driven storefront edits" into the end-state diagram, and §2.5 leaned on Astro's official MCP server for the AI-build loop. Research (see `095-astro-design-system-mcp-research.md`) shows the official **Astro Docs MCP** (`https://mcp.docs.astro.build/mcp`) only answers *documentation* questions — it cannot read or change a site. Nothing upstream lets an NV oOS assistant touch tokens, themes, or CMS content on this storefront. The WordPress side already has this capability for Elementor sites (the MCP Apps subsystem), so the Astro side is the missing half.
3. **The token investment is stranded.** Proposal 058 gave the NV oOS Design System addon a DTCG 2025.10 exporter, presets, and a transactional import pipeline — but the storefront consumes none of it. One palette change in WordPress restyles the console, yet the *public* storefront (the highest-visibility surface) is edited by hand in CSS.

Multi-tenancy sharpens all three: 092 Phase 4 (`nvoos-astro-starter`) productizes this storefront for other NV clients, which turns "rebrand the storefront" into a *recurring sales operation*, not a one-off build. It should be an assistant-driven, token-level operation with preview and rollback — exactly the job description of an MCP server.

## 2. Research & Industry Standards

Full sources in `095-astro-design-system-mcp-research.md`. The load-bearing findings:

### 2.1 Design tokens — DTCG 2025.10 is the repo standard, and the Astro ecosystem agrees

- The W3C Design Tokens Community Group spec (DTCG 2025.10) is already this repo's token format (058 §3.3, exporter in `addons/nvoos-design-system/`). The Astro team's own products use the same pattern: **Starlight** (the docs theme) ships `--sl-*` design tokens with `data-theme` + `color-scheme` switching and a no-FOUC inline script — verified live on docs.astro.build. Astro's styling guide is explicit that custom properties are the theming primitive; scoped component styles sit on top of global tokens.
- **Style Dictionary 5** is the industry build tool (DTCG → CSS/platforms), but 058 §3.3 already flagged that full DTCG 2025.10 support is still landing there. For a single CSS/TS output pair, a dependency-light generator script is the honest first move; SD5 is the documented upgrade path when multi-platform output (email, spa-v2) joins.
- **Utopia** fluid `clamp()` scales are the 2026 standard for type/space scales that survive from phone to desktop without breakpoints.
- Theme anatomy from the Astro themes marketplace (astro.build/themes): themes are *folder-level compositions* of tokens, layouts, and components — not CSS patches. The equivalent here is a **brand theme = a DTCG override file over the semantic layer**, not a second stylesheet.

### 2.2 MCP — the server/client split, resolved by precedent

- **Server vs client: we build a server.** The assistant is the MCP *client* (the NV oOS MCP Apps subsystem already implements the full client stack — connection, discovery, tool bridging, auth mapping — for Elementor MCP today). The storefront/BFF hosts the MCP *server*, exactly like Elementor MCP lives on the WordPress site it controls. One protocol, two transport profiles of the same tool registry:
  - **Streamable HTTP** (spec 2026-07-28, single POST/GET endpoint, `Mcp-Session-Id`, `MCP-Protocol-Version` header, mandatory `Origin`/Host validation against DNS rebinding) — served by the existing BFF (`scripts/serve.mjs`) at `/mcp`. This is what NV oOS assistants (and any spec client) connect to.
  - **stdio** — a local `npx nvoos-astro-mcp` profile for coding agents (Zed/Claude Code) that edits the token/theme files in-repo during development.
- **SDK choice: `@modelcontextprotocol/server` (SDK v2)** — the stable line implementing exactly the 2026-07-28 protocol version the NV oOS MCP Apps client already speaks (`PROTOCOL_VERSION = '2026-07-28'`), and it ships `@modelcontextprotocol/node`, a thin Streamable-HTTP wrapper for plain Node `IncomingMessage`/`ServerResponse` — a drop-in for the BFF's `createServer(route)` listener. No new hosting, no framework migration.
- **Precedents verified in the wild:** Elementor MCP (`msrbuilds/elementor-mcp`, 759★, GPL-2.0, per-tool WordPress capability checks, application-password auth — the pattern the MCP Apps skill documents); `wp-astro-mcp` (headless WP → Astro frontend generation via MCP, GitHub-publishing workflow); `midudev/mcp-higgsfield-landing` (an Astro marketing site built end-to-end through MCP + Claude Code); `morinokami/astro-mcp` (dev-time Astro helper server). **None of them provide assistant-controlled theming + CMS ops on a live Astro storefront** — the gap this proposal fills.
- **Astro's own agent posture** validates the direction: the official Astro Docs MCP, plus Astro 7's AI-agent background mode for `astro dev`/`astro preview` and the `/_astro/status` health endpoint are all first-party features for exactly this workflow.

### 2.3 CMS integration — bind to the contract, not to new backends

- The Astro CMS (`/manage`) and this MCP server share one data layer: the Payload-shaped `nvoos-commerce/v1` contract the storefront already binds to (092 §3.2 invariant). CMS tools are **thin wrappers over the contract** — no new REST surface, no WP-awareness in `src/` (the CI grep gate stays intact), and the Laravel flip (085/090) changes nothing.
- Auth inheritance: writes ride the contract's own manager auth (`POST /api/auth/login`, `canManage` = the `manage_astro` cap). The MCP layer adds a bearer token for the *server* and keeps the same least-privilege rules the Elementor MCP guide prescribes (dedicated low-privilege credentials, revocable per-client).
- Import UX standard (058 §3.4, carried forward): every bulk token write is **validate → dry-run diff → preview → all-or-nothing apply → one-click rollback**. Never a partial write, never an unvalidated string into generated CSS.

## 3. Proposed Design

### 3.1 The two deliverables

| Deliverable | Location | Role |
|---|---|---|
| **NV oOS Astro Design System (ADS)** | `examples/nvoos-astro-velocity/src/tokens/` + generator + `/tokens` page | DTCG-sourced, three-layer token system (core → semantic → component), light/dark + brand themes, component layer, CI-drift-gated generated CSS/TS |
| **nvoos-astro-mcp** (MCP server) | `examples/nvoos-astro-velocity/mcp/` (stdlib + BFF mount) | One tool registry, two transports: design tools (tokens/themes/contrast) + CMS tools (contract CRUD) + ops tools (rebuild/status) |

### 3.2 Token architecture

```
src/tokens/
  core.json          # primitives: palette, radius, border, fluid type + space (Utopia clamps)
  semantic.json      # surface / ink / line / accent / danger / success ... (mode-aware)
  component.json     # btn / card / field / badge ... (component tokens)
  themes/
    default.json     # light + dark modes (semantic values per mode)
    <brand>.json     # optional brand overrides of semantic tokens (multi-tenancy)
src/styles/
  generated/tokens.css   # committed build output: :root + [data-theme] blocks + @property types
  global.css             # shrinks to layout/reset only; --nv-* names become aliases
src/lib/tokens.ts        # generated typed constants (TS consumers)
```

- **Layers:** components reference *semantic* tokens; semantic tokens reference *core* tokens (DTCG `$value` references). The `--nv-*` names from the current `global.css` are kept as **legacy aliases** over the semantic layer, mirroring the NDS addon's legacy-alias setting — zero visual regression on day one, full freedom underneath.
- **Themes:** `data-theme` attribute on `<html>` + `color-scheme`, no-FOUC inline script (the Starlight pattern). Brand themes are DTCG override files — the unit of the 092-Phase-4 "rebrand a client storefront" operation.
- **Generator:** a ~150-line zero-dependency script (`scripts/build-tokens.mjs`) emitting `tokens.css` + `tokens.ts` + validating references/types (DTCG validation rules from 058: per-type checks, reference resolution, reject-on-parse-error). Style Dictionary 5 is the documented upgrade path; not a Phase 0 dependency.
- **Round-trip with WordPress NDS:** the NDS addon's DTCG export is a first-class import source; Phase 4 adds push-back, so one palette restyles console *and* storefront (058's vision, extended).

### 3.3 The MCP server (`nvoos-astro-mcp`)

```
NV oOS assistant (client)                      nvoos-astro-velocity (server)
──────────────────────────                    ──────────────────────────────
MCP Apps → mcp_app_astro_* bridged tools ────► BFF route /mcp  (Streamable HTTP, SDK v2
Remote Sites connection (bearer token)          @modelcontextprotocol/node wrapper)
                                                 ├─ tokens/theme service (DTCG JSON + snapshots)
                                                 ├─ contract client (reuses src/lib/commerce/client.ts)
                                                 └─ ops (rebuild webhook, status)
Zed / Claude Code (local dev) ─── stdio ─────► same tool registry via npx nvoos-astro-mcp
                                                 (filesystem access to src/tokens/)
```

**Tool catalog (v1 target — ~22 tools + 3 resources):**

| Slice | Tools | Notes |
|---|---|---|
| Design | `tokens_get`, `tokens_set`, `tokens_import_dtcg`, `tokens_rollback`, `theme_list`, `theme_get`, `theme_create`, `theme_apply`, `theme_delete`, `contrast_check` | `tokens_set`/`tokens_import_dtcg` are transactional (058 §3.4): validate → dry-run diff → preview → all-or-nothing apply → rollback snapshot |
| CMS | `cms_list`, `cms_get`, `cms_create`, `cms_update`, `cms_delete`, `media_upload` | Thin wrappers over `nvoos-commerce/v1` (products, categories, brands, colors, media, globals, pages). Zero new backend surface |
| Ops | `rebuild_trigger`, `preview_url`, `site_status`, `screenshot` (opt-in, Phase 4) | Reuses the bridge rebuild webhook + mirror workflow; `screenshot` via Playwright for visual verification |
| Resources | `tokens://active`, `theme://active`, `site://status` | MCP resources make current state visible without a tool round-trip |

**Auth & security (spec + repo discipline):**
- `Origin`/Host validation on the HTTP endpoint (spec 2026-07-28 requirement; SDK middleware provides Host validation).
- Read tools: public (they expose public catalog data). Writes: contract manager auth (`manage_astro` cap via `/api/auth/me`) **plus** server-level `NVOOS_MCP_TOKEN` bearer — two independent gates, least privilege on both ends, revocable per client (the Elementor MCP recipe).
- Token writes are CSS-injection vectors: DTCG per-type validation, size caps, reference resolution, reject-unknown-with-report (058 §3.3 discipline applied verbatim).
- All contract mutations flow through the existing WordPress audit trail; MCP tool executions are logged activity events (the repo's telemetry seam).
- NV oOS side: host allowlist entry required (`mcp_app_allowed_hosts`), Remote Sites connection stores the token encrypted at rest, assistant bundles export only non-secret `connection_ref` pointers.

### 3.4 Relationship to the Astro CMS (`/manage`)

The MCP server and the `/manage` admin share the same token service, the same contract client, and the same auth gate — one source of truth, three surfaces (admin UI, assistant chat, coding-agent stdio). Phase 4 adds a Design System tab to `/manage` (token editor + theme picker + DTCG import/export) mirroring the NDS admin UX from `addons/nvoos-design-system/`, so humans and assistants see identical behavior.

### 3.5 Explicit non-goals

- **Not** an Astro framework migration or a Tailwind adoption. The storefront keeps plain scoped CSS + custom properties (092's stack survives).
- **Not** a replacement for the `/manage` React admin — it stays the human UI; MCP is the assistant UI (092 §3.7 boundary).
- **Not** a headless-CMS product play (Decap/Keystatic/Tina): the CMS data layer is the commerce contract, already shipped.
- **Not** a new public REST API. `/mcp` speaks JSON-RPC, not REST; CMS writes never bypass the contract's own permission callbacks.
- **Not** the `wp-astro-mcp` feature set (WP→Astro generator, HTML→Markdown content layer, SQLite sync engine, multi-site management, GitHub publishing). 095 operates a storefront that already exists; those capabilities are either shipped in other forms (mirror workflow, bridge webhook) or belong to 092 Phase 4. Full boundary table + adopted craft patterns: `095-astro-design-system-mcp-research.md` §3.4.

## 4. Implementation Plan

| Phase | Scope | Exit criteria |
|---|---|---|
| **0 — Token spike** (~1–2 wks) | DTCG core/semantic/component token files; generator → `tokens.css` + `tokens.ts`; `--nv-*` legacy aliases; `/tokens` dev page; CI drift gate (generated files committed + verified) | `npm run check && npm run test` green; visual-diff e2e shows **zero pixel drift** vs pre-change snapshot; drift gate fails on stale output |
| **1 — Theme system** (~2 wks) | Light/dark modes, `data-theme` + no-FOUC switching, brand theme overrides, theme preview on `/tokens`, component styles refactored onto semantic tokens | Theme switch e2e passes (no-JS + JS paths); Lighthouse a11y gate unchanged (≥ 0.9); dark-mode contrast checks green |
| **2 — MCP server core** (~2–3 wks) | `mcp/` package (SDK v2, stdio + Streamable HTTP), BFF `/mcp` mount, bearer + manager auth, design tool slice (tokens/themes/contrast), snapshot/rollback store | Handshake conformance spec (initialize → tools/list → tools/call) in vitest; auth matrix (401 unauth'd writes, 200 authed); transactional-failure test leaves snapshots intact |
| **3 — CMS + ops + NV oOS wiring** (~2 wks) | CMS tool slice over the contract, `rebuild_trigger`/`site_status`, Remote Sites → MCP App recipe, end-to-end assistant demo ("rebrand to palette X, verify contrast, trigger rebuild" in chat) | Hermetic e2e against the fixture server (`:8123`) — assistant completes a full rebrand loop without touching `/manage`; contract grep gate still green |
| **4 — Admin surface + round-trip** (~2 wks) | Design System tab in `/manage`, NDS DTCG import/push-back, `screenshot`, docs + skill updates (`mcp-ai-wpoos-astro-storefront` gains an MCP section) | WordPress NDS export → MCP import → storefront restyle verified end-to-end; `/manage` and MCP show identical token state |

Total ~8–11 weeks, phases independently shippable; Phases 0–2 are parallelizable with 092's Phase 3–4 (disjoint file scopes: tokens/theme/MCP vs PWA retirement/generic starter).

## 5. Risks & Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| SDK v2 is young (stable 2026-07) | Medium | v2 is the line matching the plugin client's `2026-07-28` protocol; v1.x remains supported as documented fallback; handshake tests pin the wire contract |
| Global restyle regressions during tokenization | High | Legacy `--nv-*` aliases mean zero-visual-change rollout; e2e visual snapshots + LHCI gates in CI; phases gated on pixel-drift pass |
| `/mcp` on a public origin (DNS rebinding / token abuse) | High | Spec Origin/Host validation, bearer + manager double gate, allowlisted hosts on the client side, rate limits, audit trail; read-only by default |
| Token writes = CSS injection | Medium | 058's DTCG per-type validation + reference resolution + reject-unknown; snapshots make every write reversible |
| Scope creep into a new CMS or replacing `/manage` | High | §3.5 non-goals; CMS tools are wrappers over the existing contract only |
| Contract drift during 085/090 work | Low | Contract-only binding (092 §3.2); fixture-based tests; the mirror CI re-validates on every bridge change |
| Astro major-version churn | Low | App pinned to Astro 7.x already; generator + MCP server depend only on public, stable SDK APIs |

## 6. References

- [Astro Docs MCP](https://docs.astro.build/en/guides/build-with-ai/) — official docs MCP (kapa.ai-backed, Streamable HTTP) + agent background mode + `/_astro/status`
- [MCP spec — Transports (stdio, Streamable HTTP)](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports)
- [MCP TypeScript SDK v2](https://github.com/modelcontextprotocol/typescript-sdk) — `@modelcontextprotocol/server`, `@modelcontextprotocol/node`
- [Design Tokens W3C Community Group (DTCG)](https://tr.designtokens.org/) · [Style Dictionary](https://styledictionary.com/) · [Utopia](https://utopia.fyi/)
- [Astro themes marketplace](https://astro.build/themes/) · [Astro styling guide](https://docs.astro.build/en/guides/styling/)
- [msrbuilds/elementor-mcp](https://github.com/msrbuilds/elementor-mcp) · [vapvarun/wp-astro-mcp](https://github.com/vapvarun/wp-astro-mcp) · [midudev/mcp-higgsfield-landing](https://github.com/midudev/mcp-higgsfield-landing) · [morinokami/astro-mcp](https://github.com/morinokami/astro-mcp) · [starwind-ui](https://github.com/starwind-ui/starwind-ui)
- Repo: Proposal 092 §3.9/§2.5, 058, 047/048, 085, 090; `addons/nvoos-design-system/`; `addons/pro/includes/mcp-apps/`; `.agents/skills/design-elementor-mcp-connection/SKILL.md`; `.agents/skills/mcp-ai-wpoos-astro-storefront/SKILL.md`; `examples/nvoos-astro-velocity/scripts/serve.mjs`

## 7. Decisions (owner)

- **Q1 — Build the server:** approve the MCP-server direction (assistant = client via existing MCP Apps; storefront/BFF = server at `/mcp`), per §3.3.
- **Q2 — Endpoint posture:** `/mcp` public with bearer + manager double gate (recommended), vs `/_mcp` + VPN-only access.
- **Q3 — stdio profile:** include the local coding-agent stdio transport in scope (recommended — it is the same tool registry, ~0 marginal cost).
- **Q4 — Brand multi-tenancy now:** ship brand-theme override files in Phase 1 (recommended — it is the 092-Phase-4 rebrand primitive), vs storefront-only theming.
- **Q5 — NDS push-back:** schedule the WordPress→Astro→WordPress token round-trip in Phase 4 (recommended), or keep NDS→Astro import-only.
