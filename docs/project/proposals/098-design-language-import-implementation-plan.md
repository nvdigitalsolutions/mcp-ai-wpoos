# Proposal 098 — Design Language Import: Implementation Plan

Companion to `098-design-language-import-proposal.md`. Each phase is a shippable PR against `alpha-working`; validation gates are hermetic (no live network in CI).

---

## Phase 0 — Static analyzer core + `design_analyze` MCP tool (this PR)

**Goal:** deterministic HTML+CSS → `DesignLanguage` extraction behind a hardened fetcher, exposed as the first open-world `/mcp` tool.

| Task | Detail | Gate |
|---|---|---|
| 0.1 `src/lib/design-import/types.ts` | `DesignLanguage`, `PaletteRoles`, `AdaptationBrief`, `Provenance` contracts per proposal §3.2 | Type-checked by `astro check` |
| 0.2 `src/lib/design-import/fetcher.ts` | SSRF-safe fetch: scheme allowlist (http/https), credential-in-URL rejection, DNS-resolve every hop → reject private/link-local/reserved (IPv4 + IPv6), redirect cap 5, timeout (AbortSignal), 2 MiB page cap / 512 KiB stylesheet cap, text/html + text/css only, `NVOOS_MCP_FETCH_ALLOW_PRIVATE` opt-in | Unit tests with injected fetch + real `dns` checks for `localhost`, `127.0.0.1`, `10.x`, `169.254.x`, `::1`, IPv6-mapped, credential URLs, redirect-to-private, oversize body |
| 0.3 `src/lib/design-import/analyzer.ts` | Pure extraction: colors (hex/rgb/hsl incl. alpha, frequency + saturation heuristics → roles), typography (font-family ranking, px scale), spacing (px values, 4/8-grid weighting), radii, shadow presence, component vocabulary (class-name lexicon), CSS custom-property harvesting, deterministic concept prose, `roleConfidence`, fail-loud `unassigned` pool | Unit tests against fixture HTML/CSS (editorial, e-commerce, empty, hostile) |
| 0.4 Mode detection | `github.com`/`astro.config` markers → `astro-theme`; `/wp-json` or wp-content/wp-includes markers → `wordpress`; else `generic` | Unit tests |
| 0.5 `mcp/tools/design.ts` → `design_analyze` | URL in → `{ source, mode, designLanguage }`; reads open; `OPEN_WORLD_READ_TOOL` preset added to `mcp/tools/util.ts` (`openWorldHint: true` — first open-world tool, proposal Q2) | MCP tool test against a local HTML server (`NVOOS_MCP_FETCH_ALLOW_PRIVATE=1` on that mount) + negative test (private URL blocked without the opt-in) |
| 0.6 Handshake pins | `EXPECTED_TOOLS` += `design_analyze`; the all-false `openWorldHint` loop gains an allowlist for this tool and asserts `true` for it | `tests/mcp/handshake.test.ts` green |
| 0.7 Docs | `mcp-ai-wpoos-astro-storefront` skill: tool count 21→22, tool list, open-world note, new env var; proposal status update | Skill under 100 KB |

**Exit criteria:** `npm test` green, `astro check` 0 errors, eslint clean, no CI network access (every fetch test injects its implementation or uses loopback with the explicit opt-in).

## Phase 1 — WordPress mode + Astro-theme mode

- **1.1 WordPress:** probe `/wp-json` (HEAD/GET), fetch `/wp/v2/themes/{stylesheet}` + rendered home HTML + `theme.json` when exposed; map presets → DTCG core/semantic draft (058 mapping precedent); theme.json absent → fall back to static analyzer. Fixtures: a stub WP REST + a `theme.json` (twenty-twenty-four-style).
- **1.2 Astro themes:** GitHub repo URL → shallow read-only clone (or GitHub raw/API fetch) of `src/tokens/**`, Tailwind v4 `@theme` blocks, and CSS variables; preserve license file into `provenance.licenseNotice`. No scraping, no SSRF surface.
- **1.3 Tests:** hermetic fixture repos (checked-in minimal theme trees) and stub REST responses.

**Exit criteria:** both modes pass fixture-based unit tests; `design_analyze` mode column reports correctly; clone path is network-free in CI (fixtures only).

## Phase 2 — Rendered capture + re-extraction diff

- **2.1 Playwright capture** for `generic` mode: render the page, read computed styles (color/typography/spacing per sampled element), merge with static analysis, bump confidence. Playwright already ships as the app's e2e dependency.
- **2.2 `design_compare` tool:** re-analyze a source and diff against a stored snapshot (token-level + concept-level), the Element Armory "re-run quarterly" discipline.
- **2.3 Politeness:** single page + bounded stylesheet fan-out, `robots.txt`-aware only as a note (no crawling yet), per-source result cache.

**Exit criteria:** computed-style capture covered by fixture pages (JS-heavy fixture); diff output pinned by tests; no crawl features (still single-document).

## Phase 3 — Adaptation engine

- **3.1 Content-first mapping:** given the user's content model (from 096 site analysis or the nvoos-commerce collections), generate the `adaptationBrief`: keep / replace / remix per design element, mapped onto the user's catalog, pages, and brand inputs.
- **3.2 AI-assisted role assignment:** LLM pass over the unassigned pool with the fail-loud contract preserved (model proposes, validator pins; proposals never silently promote to roles without confidence).
- **3.3 `design_plan` tool:** adaptation brief as structured output for assistants to execute via 095 tokens / 096 scaffold.

**Exit criteria:** deterministic brief for fixture designs; AI path gated by pinned fixtures + provider-absent fallback to the deterministic brief.

## Phase 4 — Theme emission + verification

- **4.1 Emission:** produce a `tokens_import_dtcg`-ready document (058 shape) and a runtime-theme candidate (theme_create payload); round-trip via the existing importers.
- **4.2 Attribution:** `NOTICE` manifest per import (MIT theme notices); extended into generated sites (096).
- **4.3 Verification gates:** WCAG contrast audit on the draft (reuse the 095 contrast machinery), token drift gate, and a manual review checkpoint before any apply.

**Exit criteria:** a full loop demo — analyze a fixture site → import tokens (dry-run) → contrast audit → brief — hermetic in CI.

## Phase 5 — Packaging, docs, status

- Skill `mcp-ai-wpoos-astro-storefront` final update; proposal statuses; 096 `tokens_apply`/`ads_generate` wiring notes; registry entry if the tool set outgrows the 095 server (deferred per Q5 in 095).

---

## Shipped status (2026-10-31, branch `feat/design-language-import`)

- **Phase 0 — shipped:** `src/lib/design-import/` (types, SSRF-safe fetcher,
  deterministic analyzer), `design_analyze` tool, handshake pins, unit tests.
- **Phase 1 — shipped:** WordPress mode (`modes/wp.ts` — theme slug + public
  `theme.json` → high-confidence roles/families) and Astro-theme mode
  (`modes/astro-theme.ts` — GitHub trees API + raw file reads, capped,
  LICENSE harvested).
- **Phase 2 — shipped:** `compare.ts` (re-extraction diff → `design_compare`)
  and opt-in rendered capture (`render.ts`, `NVOOS_MCP_FETCH_RENDER=1`,
  createRequire-loaded playwright, build-safe degrade).
- **Phase 3 — shipped:** `plan.ts` (deterministic content-first adaptation
  plan → `design_plan`); the LLM-assisted pass remains the documented
  `AdaptationProvider` seam (Phase 3.2, provider-absent fallback by design).
- **Phase 4 — shipped:** `theme.ts` (DTCG doc + runtime-theme descriptor +
  WCAG audit + attribution → `design_theme`, write-gated); contract roles map
  onto ADS keys (`text→ink`, `field.border→line`).
- **Phase 5 — partial:** skill updated; proposal README registration deferred
  (another agent's uncommitted edits); packaging/registry deferred with 096.
- **e2e:** `tests/e2e/design-import.spec.ts` — analyze → plan → compare →
  emit theme → verify → parallel-safe rollback → cleanup over `/mcp`;
  the pre-existing rebrand-loop spec hardened to explicit `snapshot_id`
  rollback + theme-pattern assertion (parallel-state interference).

## Validation matrix (every phase)

| Layer | Gate |
|---|---|
| Unit | `tests/unit/design-import.test.ts` (analyzer fixtures, fetcher SSRF matrix) |
| MCP | `tests/unit/mcp-tools.test.ts` (local-HTML server mount), `tests/mcp/handshake.test.ts` (annotations + tool list) |
| Types/styles | `npm run check` (8 GB heap on Windows), `npm run lint` |
| Contract | No changes to `nvoos-commerce/v1`; no fixture drift (`npm run sync:bridge-fixtures` stays no-op) |
| Security | SSRF matrix tests; no network in CI; open-world hint pinned |

## Sequencing with other agents

- 096 (Forge) owns content→site generation; 098 emits the design layer its `tokens_apply`/`ads_generate` consume — disjoint file scopes (`examples/nvoos-astro-forge/` vs `examples/nvoos-astro-velocity/src/lib/design-import/`).
- 095 owns the token runtime; 098 never writes tokens directly — it emits drafts for 095's importers.
- `docs/project/proposals/README.md` registration is deferred: the file carries another agent's uncommitted edits (2026-10-31).
