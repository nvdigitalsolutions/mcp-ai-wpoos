# Content Graph Platform — Extraction Completion Plan

**Date:** 2026-08-31
**Status:** Proposed (awaiting approval)
**Scope:** Finish the `nvoos-content-graph-ai-platform` extraction so the Platform addon owns its business logic and no longer requires the `mcp-ai-wpoos` base plugin at runtime.
**Supersedes:** `plugins/nvoos-content-graph-ai-platform/MIGRATION-GAPS.md` (2026-08-03 — stale, see §2)
**Source of truth for the target architecture:** `docs/project/proposals/nvoos-base-restructuring-roadmap.md` (§3, §5, §7)

---

## 1. Goal and End State

Restore the roadmap's "either/or" principle (§7: *Separate Plugins, No Forced Upgrade*):

- **End state A — modular stack:** `nvoos-content-graph` + `nvoos-content-graph-ai` + `nvoos-content-graph-ai-platform` work together with **no `mcp-ai-wpoos` present**. Platform business logic lives in `plugins/nvoos-content-graph-ai-platform/src/`.
- **End state B — monolith:** `mcp-ai-wpoos` users keep every feature. During transition, the base plugin keeps its own copies of the logic (shim-preferred — see §5); at cutover it loads the Platform addon's classes (meta-plugin mode, roadmap Phase 5).
- **End state C — no silent breakage:** activating the Platform addon without the base plugin must never produce dead menus or `class_exists()`-skipped features (the current failure mode).

Non-goals for this plan: moving the assistant CPT into `nvoos-content-graph` core (separate roadmap workstream), deprecating `addons/graphify/` (legacy), extracting extended/pro tools (roadmap Phases 2–4), and rebuilding Platform subsystems on `nvoos/core` contracts (a future optional refactor, not extraction).

---

## 2. Current Reality (as of alpha-working @ v1.1.66, 2026-08-31)

The gap analysis in `MIGRATION-GAPS.md` is **out of date**. What actually exists now:

### Base plugin (`mcp-ai-wpoos`) owns ALL business logic

| Subsystem | Location | Notes |
|---|---|---|
| Assistants/Agents | `includes/assistants/` (CPT + metaboxes), `includes/agents/` (12 classes: role base/planner/critic/executor, approval gate, audit trail, capability boundary, code sandbox, harness bootstrap/evolver, evolved prompt resolver), `includes/class-assistant-cpt.php`, `includes/agents-init.php` | Assistant CPT is base-chat-critical |
| Skills | `includes/class-wp-mcp-ai-skill-registry.php`, `-skill-parser.php`, `-skill-pack-registry.php` | Used by base chat flow **and** Pro addon (`addons/pro/includes/skills-manager-init.php`) |
| Slash Commands | `includes/slash-commands/` — 7 classes + `commands/` + `slash-commands-init.php` | Largest subsystem (~14.5K lines) |
| Harness | `includes/harness/` — 16 classes + `harness-init.php` | Deeply woven into chat/agentic loop; gated by per-assistant profile (default off) |
| Measurement | `includes/measurement/` — 30+ files (registry, collectors, observers, budgets, eval, verifiers, rewards, exporters, event store, retention) + bootstrap | Observers attach to chat/tool/SSE events — must stay registered regardless of which plugin provides them |
| Professions | `includes/professions/` — CPT, 5 seeders, CLI, playbook, dataset mappings, metaboxes, `professions-init.php` | Also `includes/teams/` (team CPT, seeder, init) and `includes/knowledge-base/` (profession documents/playbooks/professions/teams) |
| A2A | `includes/a2a/` — 7 classes | Loaded via explicit requires in `includes/bootstrap/loader.php` L884–909 |
| ACP | `includes/acp/` — 4 classes + `transport/` | JSON-RPC dispatcher/server/sessions |
| Federation | `includes/class-wp-mcp-ai-federation.php`, `-federation-settings.php`, `-federation-wellknown.php`, `-federation-directory-rest.php`, `-federation-peer-verifier.php`, `-federation-rate-limiter.php` + mesh classes (`class-wp-mcp-ai-mesh-router.php`, `-mesh-peer-sync.php`, `-mesh-peer-tester.php`, `-mesh-peer-test-rest.php`) | **Built AFTER the gap doc** — Federation is no longer a "nothing exists" gap |
| Blueprints | ❌ Nothing general-purpose in `includes/`. Pro has `addons/pro/includes/admin/class-wp-mcp-ai-unified-blueprints-page.php` + `tool-import-*-blueprint.php` (55 assistant JSON templates) | True remaining greenfield |

### Platform addon (`nvoos-content-graph-ai-platform` v1.0.2)

- Admin UI for all 10 subsystems; explicit bridges only for **Agents** (`CptBridge`), **Skills** (`SkillBridge`), **Slash Commands** (`SlashCommandBridge`); implicit `class_exists` delegation elsewhere.
- `FederationService.php` + `BlueprintService.php` are admin stubs.
- Own CPTs (Project/Resource/Template), `Schema`, one integration test (`tests/test-platform-integration.php`).
- PSR-4 via `composer.json`; hard runtime dependency on base plugin classes **not declared** in `Requires Plugins`.

### Seams

Everything is loaded through `includes/bootstrap/loader.php` — the extraction must modify its require blocks (measurement L182–277, a2a L884–909, federation L910–935, harness L812–821, professions/teams L824–829, agents L477–481).

---

## 3. Guiding Principles ("carefully" defined)

1. **Data stability is sacred.** Option keys, table names, CPT slugs, post meta keys, and REST routes **do not change** during extraction. Renames require migrators — out of scope here.
2. **Hook names are public surface.** Keep `wp_mcp_ai_*` hook names firing with identical payloads for the transition. New platform-native hooks get the `nvoos_content_graph_ai_platform/` prefix only for *new* features.
3. **Behaviour-preserving, not behaviour-improving.** Extraction PRs must not "fix while moving". Bug fixes go in separate PRs after the move.
4. **Shim-first, delete-later.** During transition the base plugin prefers the Platform addon's classes when the addon is active, and falls back to its own copy when not (§5). Copies are deleted only at cutover (§8).
5. **One subsystem per PR**, with characterization tests before the move and green CI after.
6. **PHP floors respected:** Platform addon is PHP 8.1+; base plugin is PHP 7.4+. Shim files in the base plugin must stay PHP 7.4-compatible.
7. **Never merge extraction work that breaks the monolith test suite.** `composer run test` (base) and `composer run test` (platform, in `plugins/nvoos-content-graph-ai-platform/`) must both pass at every merge.
8. **Docs move with code.** Update each folder's `README.md` (folder-readme convention, `composer run docs:check-folder-readmes`), `AGENTS.md` ownership inventory, and the living tracker (§9).

---

## 4. Strategy Decision

Three options were considered:

| Option | Description | Verdict |
|---|---|---|
| A. **Port with shims** | Copy logic into Platform `src/` re-namespaced `NvoosContentGraphAiPlatform\*`; base plugin keeps its copy but prefers the addon's via `class_alias()` shims when the addon is active. | ✅ **Chosen** — never breaks either user group; either/or achieved at cutover |
| B. **Hard move** | Physically move files, base requires the addon. | ❌ Breaks "either/or" immediately; forced upgrade; risks Pro addon and chat flow |
| C. **Reimplement on nvoos/core** | Rewrite subsystems on framework contracts. | ❌ Not extraction; large rewrite; revisit post-cutover if cross-platform parity is desired |

**Naming rule for ported classes:** new canonical name in the Platform namespace; the base plugin exposes a `class_alias` from the old `WP_MCP_AI_*` name guarded by `! class_exists( $old, false )`. The base plugin's own copy of each file remains until the cutover phase deletes it — this means **two copies exist during transition** (accepted cost, bounded by the phase gates in §6–8).

---

## 5. Shim Design (transition mechanism)

New base-plugin file: `includes/platform-shims/class-wp-mcp-ai-platform-shims.php`, loaded from `loader.php` **after** the Platform addon would have booted (platform loads at `plugins_loaded` priority 10; shims must run after that, e.g. on `wp_loaded` or at `plugins_loaded` priority 99 — verify ordering, since loader runs inside the base plugin's own boot).

Per extracted class:

```php
// PHP 7.4-compatible.
if ( ! class_exists( 'WP_MCP_AI_A2A_Agent_Card', false ) ) {
    class_alias( '\NvoosContentGraphAiPlatform\A2A\AgentCard', 'WP_MCP_AI_A2A_Agent_Card' );
}
```

Rules:
- Shims are **additive and fail-safe** — if the Platform addon is absent, `class_alias` is skipped and the base loader falls back to its legacy require of the local copy.
- Legacy copies get a docblock banner: `@deprecated — canonical implementation moved to nvoos-content-graph-ai-platform/src/... (extraction plan §6). Bug fixes must be applied there and ported back if critical.`
- Pro addon consumers (`addons/pro/includes/...`) are untouched: they keep calling `WP_MCP_AI_*` names, which the alias resolves.
- Base loader's per-class `require_once` blocks for extracted classes become conditional: require the legacy copy **only when the alias target class is absent**.

---

## 6. Phased Execution

### Phase 0 — Stabilise and instrument (1–2 weeks)

**Goal:** stop the silent-failure mode and build the safety net before moving a line of logic.

1. **Loud dependency guard** in `plugins/nvoos-content-graph-ai-platform/src/Plugin.php`:
   - On `plugins_loaded`, detect whether the base plugin's classes are available. If not, show an admin notice naming each subsystem that is bridged-only, and log a `warning` via the platform logger. Flip this logic at cutover: after extraction, the notice becomes "base plugin detected — running in compatibility mode" (transition only, removed at Phase 5).
2. **Characterization tests.** Add to `plugins/nvoos-content-graph-ai-platform/tests/` a contract suite per subsystem capturing the public surface that must survive the move: key function names/signatures, option keys, CPT slugs, hook names fired, REST routes registered. These tests run against the *base-plugin* implementations today and must keep passing against the *platform* implementations after each wave.
3. **Refresh `MIGRATION-GAPS.md`** into the living tracker (§9) — it currently claims Federation/Blueprints "don't exist" and misses `includes/agents/`, `includes/teams/`, `includes/knowledge-base/`, and the mesh classes.
4. **CI for the Platform addon.** Add `.github/workflows/phpunit-platform.yml` (PHP 8.1, WP 6.5–6.9) running the addon's suite with the base plugin **active** (current state) and, later, **inactive** (standalone gate).
5. **Inventory dependencies.** Grep every consumer of the to-be-moved classes outside their own directories (base `includes/`, `addons/pro/`, `tests/`) and record the call sites in the tracker. This is the blast-radius list each wave must keep green.

**Exit gate:** guard ships, characterization tests green in CI, tracker updated, dependency inventory complete for Wave A subsystems.

### Phase 1 — Wave A: self-contained subsystems (2–3 weeks)

Order by coupling (lowest first): **A2A → ACP → Professions (+Teams, Knowledge-base) → Federation**.

1. **A2A** (`includes/a2a/` → `src/A2A/`): 7 classes, clean API. Port `AgentCard`, `WellKnown`, `TaskManager`, `MessageTranslator`, `Client`, `PushNotifications`, `WebhookHandler`.
2. **ACP** (`includes/acp/` + `transport/` → `src/ACP/`): dispatcher, server, session bridge/manager, transport.
3. **Professions** (`includes/professions/`, `includes/teams/`, `includes/knowledge-base/` → `src/Professions/` + `src/Teams/` + `src/KnowledgeBase/`): CPTs, seeders, CLI, dataset mappings. **CPT slugs and seeded data must remain byte-identical** (principle 1).
4. **Federation** (base federation + mesh classes → `src/Federation/`): port the fresh implementation as canonical. This is the *only* subsystem where the plan also adds features — the roadmap's registry/handshake/client/server decomposition can land on top of the ported code in the same or a follow-up PR.

Per-subsystem procedure (repeated 4×):
- Create `src/{Subsystem}/` domain classes (namespace `NvoosContentGraphAiPlatform\{Subsystem}\...`), porting 1:1.
- Add shims (§5) for every `WP_MCP_AI_*` class moved.
- Make base loader requires conditional on alias absence.
- Move/extend characterization tests; run base suite + platform suite.
- Update folder READMEs + tracker.

**Exit gate:** base suite green with and without the Platform addon active; platform suite green; no `class_exists` silent skips remaining for these 4 subsystems.

### Phase 2 — Wave B: skills + slash commands (3–4 weeks)

1. **Skills** (`class-wp-mcp-ai-skill-registry.php`, `-skill-parser.php`, `-skill-pack-registry.php` → `src/Skills/`): high blast radius — consumed by base chat flow, Pro `skills-manager-init.php`, bundled-skills loader. Shim-first is essential; Pro addon must keep working unmodified.
2. **Slash Commands** (`includes/slash-commands/` → `src/SlashCommands/`): ~14.5K lines. Port in slices: parser + validator → handler + registry → toolkit-manager + audit → performance-optimizer + workflow-orchestrator → `commands/` (built-ins last, most numerous). Each slice is its own PR with its own shims. Note the public functions `wp_mcp_ai_execute_slash_command()`, `wp_mcp_ai_register_slash_command()` — wrap them in the platform namespace and alias the global functions in the base shim file.

**Exit gate:** chat flow + Pro addon + slash-command tests all green on the monolith with the addon active; platform suite green standalone for these two subsystems.

### Phase 3 — Wave C: agents + harness + measurement (4–6 weeks)

Highest coupling to the chat/agentic loop — do last, in three slices:

1. **Harness** (`includes/harness/` → `src/Harness/`): layers A–F, all profile-gated (default off). Port layer by layer; the `harness-init.php` hook registrations move with them. Keep `wp_mcp_ai_*` hook names firing (principle 2).
2. **Measurement** (`includes/measurement/` → `src/Measurement/`): observers must stay registered no matter which plugin owns them — verify chat-turn/tool-execution/SSE observers still attach when the addon is active and the base copies are shimmed. Port registry/collectors first, observers second, budgets/eval/verifiers/rewards last.
3. **Agents** (`includes/agents/`, `includes/class-assistant-cpt.php` + `includes/assistants/`): the assistant CPT is base-chat-critical. **Recommended decision:** keep the assistant CPT itself in the base plugin (it is the monolith's core content model), and extract only the *role system* (`includes/agents/` — planner/critic/executor, approval gate, audit trail, capability boundary, code sandbox, harness bootstrap/evolver, evolved prompt resolver) to `src/Agents/`. Moving the CPT to `nvoos-content-graph` core is a separate roadmap workstream — flag, don't do it here.

**Exit gate:** full base suite green (monolith active with addon active and inactive), agentic-loop integration tests green, platform standalone suite green.

### Phase 4 — Blueprints greenfield (2–4 weeks)

Build the general-purpose Blueprint system directly in `src/Blueprints/` (no base plugin involvement — respects either/or from day one):

1. `BlueprintRegistry` — CRUD for blueprint records (own CPT `nvoos_platform_blueprint` or reuse the existing `TemplateCpt` — decide; reusing avoids a new slug).
2. `BlueprintExporter` — agent config → versioned blueprint JSON (model the schema on Pro's existing assistant JSON examples, e.g. `addons/pro/includes/tools/*/examples/*.json`).
3. `BlueprintImporter` — blueprint JSON → agent, with capability/tool validation against the target registry.
4. `BlueprintValidator` — JSON Schema validation, version checks, destructive/irreversible-field guards.
5. REST endpoints (`nvoos-content-graph/v1/platform/blueprints` — read for `edit_posts`, write for `manage_options`).
6. Agent-flow integration: save-as-blueprint and create-from-blueprint in `src/Agents/Admin/`.
7. Respect the base plugin's Pro "unified blueprints page" as a content source for seed templates, not as a dependency.

**Exit gate:** full blueprint lifecycle test (export → import → deploy) green; REST endpoint tests green; wp.org review-style security pass (nonces, caps, escaping).

### Phase 5 — Standalone cutover + meta-plugin (1–2 weeks)

1. **Delete base copies** of all extracted classes (after ≥1 full release with shims in the wild), and reduce the base loader to: shim require + conditional legacy fallback for any straggler.
2. **Declared dependencies:** Platform addon keeps `Requires Plugins: nvoos-content-graph, nvoos-content-graph-ai`. Nothing declares the base plugin anymore.
3. **Meta-plugin mode (roadmap Phase 5):** optionally, `mcp-ai-wpoos.php` gains a `WP_MCP_AI_META_MODE` constant path that declares `Requires Plugins` for the trio and deactivates its own platform-subsystem loaders — documented, opt-in, not forced.
4. **Versions:** Platform addon → 2.0.0; base plugin → 2.0.0 when meta-mode ships.
5. **Docs:** update roadmap §7 status, `readme.txt` (both plugins), migration guide for base-plugin users, `AGENTS.md` ownership inventory, all folder READMEs.

---

## 7. Testing Strategy

| Layer | What | When |
|---|---|---|
| Characterization/contract tests | Public surface snapshot per subsystem (hooks, options, CPTs, routes, functions) | Phase 0, before each wave |
| Platform unit tests | Ported classes in isolation (mock WP) | With each wave PR |
| Platform integration tests | Addon active + base plugin **inactive** (standalone proof) | From Phase 1 onward |
| Base regression suite | Full `composer run test` on every extraction PR (both addon-active and addon-inactive matrices) | Every merge |
| CI | `phpunit.yml` (base) + new `phpunit-platform.yml` (addon) | Phase 0 |
| Manual smoke | Admin pages for each subsystem under: (a) monolith only, (b) monolith + platform, (c) platform standalone | Per wave |

Regression catch-net rule: if a base test must change because a class moved, the change must be to the test's *fixture wiring*, never to an assertion, unless the assertion encoded a bug.

---

## 8. Risk Register

| # | Risk | Likelihood | Mitigation |
|---|---|---|---|
| 1 | Pro addon breaks (skills/slash-command consumers) | Medium | Shim-first (§5); Pro untouched; blast-radius inventory (Phase 0.5) |
| 2 | Divergence between base copy and platform port during transition | High | Legacy copies marked deprecated, bug fixes land in platform first; cutover bounded to ≤2 releases |
| 3 | Hook/option/CPT rename drift sneaks into a port | Medium | Characterization tests; principle 1 enforced in review checklist |
| 4 | Chat/agentic loop regression (harness/measurement observers) | High | Wave C last; observers tested explicitly; base suite gate |
| 5 | Two copies = doubled PHPCS/i18n surface | Low | Both dirs already under phpcs; `text-domain` stays `nvoos-content-graph-ai-platform` in ports — new strings use the new domain, legacy copies keep `wp-mcp-ai` domain |
| 6 | Plan outlived by parallel agent work (multi-agent repo) | Medium | `AGENTS.md` ownership entry; tracker file updated per PR; check `git log` before starting each wave |
| 7 | Platform addon CI flaky in docker | Medium | Reuse base CI patterns (`.github/workflows/phpunit.yml`) rather than inventing new infra |

---

## 9. Living Tracker

Replace `plugins/nvoos-content-graph-ai-platform/MIGRATION-GAPS.md` with a tracker kept current per PR:

```markdown
| Subsystem | Base source | Platform target | Bridge | Shims | Tests moved | Status |
|---|---|---|---|---|---|---|
| Agents (roles) | includes/agents/ | src/Agents/ | CptBridge | — | — | 🟡 |
| Assistant CPT | includes/assistants/ | (stays in base — decision §6 Phase 3) | — | — | — | ⏸️ |
| Skills | includes/class-wp-mcp-ai-skill-*.php | src/Skills/ | SkillBridge | — | — | 🟡 |
| Slash Commands | includes/slash-commands/ | src/SlashCommands/ | SlashCommandBridge | — | — | 🟡 |
| Harness | includes/harness/ | src/Harness/ | — | — | — | 🟡 |
| Measurement | includes/measurement/ | src/Measurement/ | — | — | — | 🟡 |
| Professions+Teams+KB | includes/professions|teams|knowledge-base/ | src/Professions|Teams|KnowledgeBase/ | — | — | — | 🟡 |
| A2A | includes/a2a/ | src/A2A/ | — | — | — | 🟡 |
| ACP | includes/acp/ | src/ACP/ | — | — | — | 🟡 |
| Federation | includes/class-wp-mcp-ai-federation*.php + mesh | src/Federation/ | — | — | — | 🟡 (built since gap doc) |
| Blueprints | — | src/Blueprints/ | — | — | — | 🔴 greenfield |
```

---

## 10. Open Decisions (owner needed)

1. **Assistant CPT home** — keep in base plugin (recommended for this plan) vs. move to `nvoos-content-graph` core per roadmap §3. Blocks Phase 3.3 scope.
2. **Blueprint record storage** — new CPT vs. reuse existing `TemplateCpt` in `src/PostTypes/`. Blocks Phase 4.1.
3. **Meta-plugin mode** — opt-in constant vs. always-on bundling for the monolith at Phase 5. Blocks Phase 5.3.
4. **Deprecation window length** — 1 vs. 2 releases before deleting base copies. Affects Phase 5.1 timing.
5. **Naming** — keep `WP_MCP_AI_*` aliases forever (recommended) vs. sunset them at v3.0.0.

---

## 11. Estimated Timeline

| Phase | Work | Est. |
|---|---|---|
| 0 | Stabilise + instrument | 1–2 wks |
| 1 | Wave A: A2A, ACP, Professions/Teams/KB, Federation | 2–3 wks |
| 2 | Wave B: Skills, Slash Commands | 3–4 wks |
| 3 | Wave C: Harness, Measurement, Agents (roles) | 4–6 wks |
| 4 | Blueprints greenfield | 2–4 wks |
| 5 | Cutover + meta-plugin + docs | 1–2 wks |
| | **Total** | **~13–21 weeks** |

Parallelisable pairs (disjoint write scopes): Wave A subsystems can run concurrently (4 agents max, one per subsystem); Skills and Slash Commands ports touch `loader.php` but different blocks — coordinate on that file only.
