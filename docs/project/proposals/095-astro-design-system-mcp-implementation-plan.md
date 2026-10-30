# Proposal 095 — Implementation Plan: Astro Design System + `nvoos-astro-mcp`

**Companion to:** `095-astro-design-system-mcp-proposal.md` (design, options, decisions) · `095-astro-design-system-mcp-research.md` (verified sources)
**Date:** 2026-10-30
**Ground rules (092/058 conventions, carried forward):**
- The Astro app binds only to the Payload envelope (`nvoos-commerce/v1` via same-origin `/api/*`). No WP-specific endpoints in `src/` — the CI grep gate (`tests/unit/contract-grep.test.ts`) keeps enforcing this, including inside `mcp/`.
- Generated artifacts (`src/styles/generated/tokens.css`, `src/lib/tokens.ts`) are **committed**; CI fails on drift (same discipline as the tracked JS bundles).
- Every bulk token write is transactional: validate → dry-run → preview → all-or-nothing → rollback snapshot (058 §3.4).
- Hermetic loop only: `scripts/fixture-server.mjs` (`:8123`) for contract/media; no live site needed.

---

## 1. File map (all under `examples/nvoos-astro-velocity/` unless noted)

```
src/tokens/
  core.json                 # palette, radius, border, fluid type/space (Utopia clamps)      [P0]
  semantic.json             # surface/ink/line/accent/danger/success, mode-aware             [P0]
  component.json            # btn/card/field/badge component tokens                          [P0]
  themes/default.json       # light + dark modes (semantic values per mode)                 [P1]
  themes/<brand>.json       # brand overrides of semantic tokens (multi-tenancy)            [P1]
src/styles/
  generated/tokens.css      # COMMITTED generator output: :root + [data-theme] + @property  [P0]
  global.css                # shrinks to reset/layout/aliases; --nv-* names → var aliases   [P0-P1]
  no-fouc.js (inline in Layout) # theme boot before first paint (Starlight pattern)         [P1]
src/lib/
  tokens.ts                 # COMMITTED generated typed constants                           [P0]
  tokens/service.ts         # load/validate/merge/apply themes + snapshot store             [P0-P1]
  tokens/dtcg.ts            # DTCG 2025.10 validation (058 rules): per-type checks, refs     [P0]
  commerce/client.ts        # UNCHANGED — reused by MCP CMS tools as-is                     [P3]
scripts/
  build-tokens.mjs          # zero-dep generator: JSON → tokens.css + tokens.ts             [P0]
  check-tokens.mjs          # CI drift gate (generator output vs committed files)           [P0]
  mcp-entry.mjs             # BFF /mcp mount: @modelcontextprotocol/node + auth              [P2]
mcp/
  server.ts                 # McpServer registry: design + cms + ops slices, resources      [P2-P3]
  tools/design.ts           # tokens_*, theme_*, contrast_check                              [P2]
  tools/cms.ts              # cms_* wrappers over src/lib/commerce/client.ts                 [P3]
  tools/ops.ts              # rebuild_trigger, preview_url, site_status, screenshot          [P3-P4]
  auth.ts                   # bearer (NVOOS_MCP_TOKEN) + manager-cap check via /api/auth/me [P2]
  stdio.ts                  # npx nvoos-astro-mcp entry (filesystem access to src/tokens/)  [P2]
src/pages/
  tokens/index.astro        # dev token browser: swatches, scales, theme preview, JSON       [P0-P1]
  manage/… (existing)       # Design System tab added in P4 (token editor, theme picker)     [P4]
tests/
  unit/tokens.test.ts       # generator: refs, aliases, modes, reject-invalid fuzz           [P0]
  unit/datcg.test.ts        # validator matrix (058 parity: hex/dimension/ref/reject)        [P0]
  unit/mcp-tools.test.ts    # per-tool unit tests (incl. transactional failure paths)       [P2-P3]
  mcp/handshake.test.ts     # initialize → tools/list → tools/call conformance (SDK client)  [P2]
  mcp/auth.test.ts          # auth matrix: no-token/read-only/bearer/manager/cap             [P2-P3]
  e2e/theme.spec.ts         # data-theme switching (JS + no-JS), no-FOUC, brand theme        [P1]
  e2e/rebrand-loop.spec.ts  # assistant-style rebrand flow against fixture server           [P3]
package.json                # scripts: build:tokens, check:tokens, mcp:stdio; dep: SDK v2   [P0/P2]
```

Docs/plugin-side (this repo, outside the app):
- `.agents/skills/mcp-ai-wpoos-astro-storefront/SKILL.md` — new "Design system & MCP" section (P4).
- `docs/operations/deployment/nvoos-astro-velocity-setup.md` — `/mcp` env vars (`NVOOS_MCP_TOKEN`), allowlist note (P3).
- `docs/project/proposals/095-*` — status updates as phases land.

---

## 2. Phase 0 — Token spike (~1–2 wks)

**Goal:** DTCG source of truth + generated CSS/TS, zero visual change, CI-gated.

| # | Task | Notes |
|---|---|---|
| 0.1 | Author `core.json` / `semantic.json` / `component.json` | DTCG 2025.10: `$type`, `$value` (references allowed), `$description`. Palette = current `--nv-*` hex values; semantic layer maps 1:1 to the current CSS names |
| 0.2 | `scripts/build-tokens.mjs` | Zero-dep. Emits `tokens.css` (`:root` block, `@property` typed props for colors/dimensions — mirror the NDS addon's typed-props option), `tokens.ts` (typed const map), and validates: reference resolution, per-type value checks (hex colors, dimensions, fonts allowlist), reject-on-error (058 §3.3) |
| 0.3 | Legacy aliases in `global.css` | `--nv-ink: var(--nv-semantic-ink)` etc. Components keep their class names; **no visual change** |
| 0.4 | `src/lib/tokens.ts` + `tokens/service.ts` + `tokens/dtcg.ts` | Service: load files, resolve refs, merge theme overrides, snapshot store (in-memory + `.nvoos-token-snapshots/` JSON for rollback) |
| 0.5 | `/tokens` dev page | Token browser rendered from JSON: color swatches, type/space scale preview, raw JSON, DTCG validity report |
| 0.6 | CI gates | `check:tokens` (drift fail), `build:tokens` wired into `npm run build`, unit tests, e2e visual snapshot proving zero drift vs pre-change screenshots |

**Exit:** `npm run check && npm run lint && npm run test && npm run test:e2e` green; `check:tokens` fails on stale output; `/tokens` renders the full registry.

---

## 3. Phase 1 — Theme system (~2 wks)

**Goal:** modes + brand themes + component token adoption; assistant-relevant because theme = the rebrand unit.

| # | Task | Notes |
|---|---|---|
| 1.1 | `themes/default.json` with `light`/`dark` modes | Semantic values per mode; generator emits `:root` (light) + `[data-theme="dark"]` blocks + `color-scheme` |
| 1.2 | `data-theme` + no-FOUC boot | Inline script in the base layout (Starlight pattern): read stored/localStorage/prefers-color-scheme, set attribute pre-paint. SSR-safe (no `Astro.url` assumptions) |
| 1.3 | Brand theme overrides | `themes/<brand>.json` = DTCG file overriding semantic tokens; merge order: core → semantic → default mode → brand overrides → runtime MCP overrides |
| 1.4 | Component styles onto tokens | `.btn`, `.card`, `.field`, `.badge`, product card, PDP tokens in `component.json`; refactor the corresponding CSS blocks to `var(--nv-component-*)` |
| 1.5 | Theme preview on `/tokens` + theme e2e | `tests/e2e/theme.spec.ts`: toggle dark (JS), load with stored dark (no-FOUC check: no light flash), apply a brand theme, `prefers-color-scheme` fallback |
| 1.6 | Contrast gate | `contrast_check` utility (WCAG 2.2 ratios) run in CI against default + dark modes; fails below AA for text pairs |

**Exit:** dark mode + one brand theme ship visually; e2e passes JS and no-JS paths; LHCI a11y gate ≥ 0.9 unchanged.

---

## 4. Phase 2 — MCP server core (~2–3 wks)

**Goal:** protocol-conformant server with the design slice, mounted on the BFF, plus the local stdio profile.

| # | Task | Notes |
|---|---|---|
| 2.1 | Add deps | `@modelcontextprotocol/server` + `@modelcontextprotocol/node` (SDK v2 — matches the plugin client's `2026-07-28` protocol). `zod` if not present for Standard Schema inputs |
| 2.2 | `mcp/server.ts` registry | `McpServer({ name: 'nvoos-astro-mcp', version })`; registers design tools + resources; single registry shared by both transports |
| 2.3 | Design tools | `tokens_get` (path/group filter), `tokens_set` (transactional; returns dry-run diff when `dry_run: true`), `tokens_import_dtcg` (paste/file → validate → diff → apply), `tokens_rollback` (snapshot), `theme_list/get/create/apply/delete`, `contrast_check` |
| 2.4 | `mcp/auth.ts` | Read tools: optional bearer. Write tools: `NVOOS_MCP_TOKEN` bearer (constant-time compare) **AND** manager check via `/api/auth/me` (`canManage` = `manage_astro` cap) when a contract session cookie/token is presented; `--mcp-audit` activity log line per tool call |
| 2.5 | `scripts/mcp-entry.mjs` + BFF mount | Route `/mcp` in `serve.mjs` **before** the `/api` proxy branch (path is distinct — `/api/*` keeps proxying to the contract). Uses `@modelcontextprotocol/node` with Host validation; `Origin` validation when the header is present (browser clients); `NVOOS_MCP_ORIGINS` allowlist env for browser-origin use; session handling per spec (Mcp-Session-Id) |
| 2.6 | `mcp/stdio.ts` + `mcp:stdio` script | Same registry over `StdioServerTransport`; file-write tools (tokens/themes) operate directly on `src/tokens/` for coding agents (Zed/Claude Code); document config snippets in the app README |
| 2.7 | Conformance + unit tests | `tests/mcp/handshake.test.ts` (SDK client → in-process transport: initialize, tools/list contains every tool + correct schemas, tools/call round-trips); `tests/mcp/auth.test.ts` matrix; `tests/unit/mcp-tools.test.ts` incl. transactional-failure (invalid DTCG leaves snapshots + registry untouched) |
| 2.8 | Spec-conformance checklist | 202-Accepted notifications, `MCP-Protocol-Version` echo, 404 session termination, JSON + SSE response modes (SDK provides; tests assert the observable behavior) |

**Exit:** `npx nvoos-astro-mcp` lists design tools locally; `/mcp` on the hermetic server passes the handshake spec; auth matrix green; every write tool is transactional + audited.

---

## 5. Phase 3 — CMS + ops tools & NV oOS wiring (~2 wks)

**Goal:** the assistant can operate the storefront end-to-end from chat.

| # | Task | Notes |
|---|---|---|
| 3.1 | `mcp/tools/cms.ts` | `cms_list/get/create/update/delete` + `media_upload` — thin wrappers over `src/lib/commerce/client.ts` (reuses base-resolution + fixtures verbatim). Collection allowlist: products, categories, brands, colors, media, globals, pages. Writes: manager gate; contract 404/401 passthrough as typed tool errors |
| 3.2 | `mcp/tools/ops.ts` | `rebuild_trigger` (fires the bridge rebuild-webhook / mirror `repository_dispatch` — exact mechanism per `sync-nvoos-astro-velocity.yml`), `preview_url` (path → preview URL), `site_status` (health, contract reachability, last rebuild) |
| 3.3 | Fixture-backed e2e | `tests/e2e/rebrand-loop.spec.ts` against `:8123`: read tokens → propose palette → `tokens_set` dry-run → apply → verify rendered CSS + contrast → `cms_update` a product field → `rebuild_trigger` stub → verify status |
| 3.4 | NV oOS connection recipe | Document + verify: Remote Sites → `MCP Server` connection (`https://<site>/mcp`, auth_type `bearer`, token in Vault) → assistant MCP Apps `connection_ref` → `/mcp-app --discover` shows ~22 tools → chat call `mcp_app_astro_tokens_set` executes with `edit_posts` capability gating on the plugin side. Security Center allowlist entry for the storefront host |
| 3.5 | Env + deployment docs | `NVOOS_MCP_TOKEN` secret on Velocity; mirror workflow ships `mcp/` + `scripts/mcp-entry.mjs` (extend `sync-dist.mjs` import walk — the 2026-10-29 502 lesson); allowlist note in `docs/operations/deployment/nvoos-astro-velocity-setup.md` |

**Exit:** the recorded assistant demo completes a rebrand loop in chat (tokens → contrast → rebuild) with zero `/manage` clicks; contract grep gate still green; mirror deploy tree boots (smoke gate).

---

## 6. Phase 4 — Admin surface, NDS round-trip, polish (~2 wks)

| # | Task | Notes |
|---|---|---|
| 4.1 | `/manage` Design System tab | Token editor (grouped tables like the NDS admin page), theme picker, DTCG import/export buttons, live preview pane — same UX contract as `addons/nvoos-design-system/includes/admin/class-nvoos-nds-admin-page.php`; shares `src/lib/tokens/service.ts` with the MCP layer so human and assistant see identical state |
| 4.2 | NDS round-trip | Import: NDS DTCG export JSON → `tokens_import_dtcg` (semantic layer mapping table). Push-back (opt-in, Q5): serialize ADS semantic tokens → NDS import API. Both covered by fixture tests with pinned DTCG samples |
| 4.3 | `screenshot` tool + visual verification | Playwright-based, dev/opt-in flag; returns PNG; pairs with `contrast_check` for the assistant's self-verification loop |
| 4.4 | Docs + skill updates | `mcp-ai-wpoos-astro-storefront/SKILL.md` gains "Design system & MCP" section (token layout, generator, `/mcp` ops, auth matrix, failure patterns); app README; proposal statuses |

---

## 7. Test & CI matrix (new gates)

| Gate | Command | Phase |
|---|---|---|
| Token drift | `npm run check:tokens` (fails on uncommitted generator output) | P0 |
| Token unit | `vitest run tests/unit/tokens.test.ts tests/unit/datcg.test.ts` | P0 |
| MCP conformance | `vitest run tests/mcp/handshake.test.ts` | P2 |
| MCP auth matrix | `vitest run tests/mcp/auth.test.ts` | P2-P3 |
| MCP tool units | `vitest run tests/unit/mcp-tools.test.ts` | P2-P3 |
| Theme e2e | `npx playwright test tests/e2e/theme.spec.ts` (JS + no-JS) | P1 |
| Rebrand e2e | `npx playwright test tests/e2e/rebrand-loop.spec.ts` (fixture server) | P3 |
| Existing gates | `astro check`, eslint, contract grep, JS-disabled crawlability, LHCI (perf ≥ 0.8, a11y ≥ 0.9, BP ≥ 0.9, SEO ≥ 0.95), smoke on the assembled deploy tree | All |

Workflow: all new gates join the existing `sync-nvoos-astro-velocity.yml` test stage; nothing ships to the mirror with a stale token build or a failing handshake spec.

## 8. Adding an MCP tool — the drift checklist (repo convention, applied)

For every new tool added to `mcp/`:
1. `mcp/server.ts` registry entry with description + input schema (Standard Schema).
2. Capability flag/decision recorded in the tool's docblock (read vs write; which auth gates).
3. Unit test in `tests/unit/mcp-tools.test.ts` (success + failure + transactional path where applicable).
4. Handshake listing assertion updated in `tests/mcp/handshake.test.ts` (tools/list contains it; schema matches).
5. Auth matrix row in `tests/mcp/auth.test.ts` (no-token, read-token, manager, non-manager).
6. Catalog table update in this plan + the proposal §3.3 tool table.
7. e2e touch point if user-visible (rebrand loop or theme spec).

## 9. Security checklist (carried from the `wp-security-*` skills + MCP spec)

- [ ] `Origin` validated when present; Host validation on (`@modelcontextprotocol/node`); `NVOOS_MCP_ORIGINS` allowlist for browser clients.
- [ ] Bearer compare constant-time; token only from env, never committed; Vault-recorded (NV oOS side: Remote Sites encryption at rest).
- [ ] Write tools double-gated (bearer + `manage_astro`); read tools expose only public contract data.
- [ ] DTCG validation before ANY token write (CSS-injection surface): per-type checks, size caps, reference resolution, reject-with-report.
- [ ] Path traversal guards on token/theme names (no `..`, absolute paths, or slashes); file writes confined to `src/tokens/` (stdio mode).
- [ ] Rate limiting on `/mcp` (reuse the BFF's per-route patterns; writes additionally no-cache + audited).
- [ ] Tool-execution telemetry + activity log lines for every MCP call (plugin-side bridged tools already emit telemetry).
- [ ] Contract grep gate extended to `mcp/` (no WP-specific endpoints).

## 10. Effort & sequencing

| Phase | Effort | Parallelizable with | Blocker |
|---|---|---|---|
| 0 — Token spike | 1–2 wks | 092 P3 (PWA parity review) | none |
| 1 — Themes | 2 wks | 092 P4 (generic starter) | P0 |
| 2 — MCP core | 2–3 wks | — | P0 (+ P1 for theme tools to be meaningful) |
| 3 — CMS/ops + wiring | 2 wks | — | P2 |
| 4 — Admin + round-trip | 2 wks | 092 P4 | P2/P3 |

Total ~8–11 weeks of agent work, each phase a shippable PR against `alpha-working` (per the `mcp-ai-wpoos-git-ops` skill: staged commits, owner-merge, PR title `Proposal 095 — …`).
