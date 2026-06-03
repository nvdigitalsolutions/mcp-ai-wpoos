# Architecture Refactor Proposal — NV oOS

**Date:** March 2026 (updated 2026-06-03)  
**Status:** In Progress — Phase 1–2 partially implemented; `lib/core` extraction operational  
**Scope:** Feasibility analysis and complete phased plan for the four architectural improvements identified in the design review.

> **📌 Update (2026-06-03):** The internal layer boundaries from this proposal (Phases 1–2) have informed the cross-platform extraction now live in `lib/core/`. See [`../proposals/cross-platform-extraction-gap-analysis.md`](../proposals/cross-platform-extraction-gap-analysis.md) for the current state of the downstream extraction.

---

## Context

NV oOS has grown from a focused AI assistant plugin into a substantial product codebase with:

- **~431 tool implementations** across base (`includes/tools/`, 229 files) and pro (`addons/pro/includes/tools/`, 202 files)
- **A 2,396-line monolithic entrypoint** (`mcp-ai-wpoos.php`) that mixes constants, the core class, activation hooks, cron setup, upload filters, cache invalidation hooks, and admin notices
- **10 build-config files** at the repo root (`webpack.config.js`, `webpack.config.tma-*.js`, `cosmos.webpack.config.js`, `esbuild.config.js`, `esbuild.config.pro.js`, `babel.config.js`, `jest.config.js`, `cleancss.config.js`) with no single document explaining what each one owns
- **Partially-established domain structure** in `includes/` — `services/`, `repositories/`, `interfaces/` directories exist but WordPress glue code (`add_action`, `add_filter`, `get_option`, capability checks) remains scattered throughout classes across all layers
- **Multiple frontend surface areas** — chat UI, workflow builder (React/JSX), four Telegram Mini Apps (TMA), and the admin dashboard — each using a different build entry path

The goal of this refactor is **not a rewrite**. It is incremental hardening that makes continued feature growth safer, easier to debug, and easier to onboard new contributors to.

---

## Improvement Areas

### 1 · Tighten the plugin entrypoint and boot flow
### 2 · Draw a harder line between domain logic and WordPress glue
### 3 · Reduce build-system complexity
### 4 · Standardize module boundaries and naming

---

## Feasibility & Difficulty Assessment

| # | Area | Effort Estimate | Risk | Reversibility | Feasibility |
|---|------|----------------|------|---------------|-------------|
| 1 | Entrypoint / boot flow | Medium (2–4 weeks) | Medium — activation hooks and load-order are sensitive | High — changes are additive, old functions can be kept as shims | ✅ High |
| 2 | Domain / WordPress boundary | High (6–12 weeks) | High — touches almost every class | High — can be done class-by-class without breaking the public API | ✅ Feasible with discipline |
| 3 | Build-system simplification | Low–Medium (1–2 weeks) | Low — no PHP impact | High — build outputs stay the same | ✅ High |
| 4 | Module boundary/naming | High (ongoing) | Low–Medium — naming changes are mechanical but widespread | High — rename → add alias → remove alias over releases | ✅ Feasible incrementally |

### Key Risks

1. **Activation / load-order sensitivity** — WordPress activation hooks, `plugins_loaded` priority, and multisite `wp_initialize_site` are all wired directly in `mcp-ai-wpoos.php`. Any refactor of the boot sequence must be exhaustively tested against both single-site and network activation.
2. **Pro addon coupling** — `addons/pro/mcp-ai-wpoos-pro.php` (1,885 lines) hooks into the base plugin's boot sequence via `wp_mcp_ai_bootstrapped`. Refactoring the boot flow must preserve or replace this contract.
3. **431 tool classes** — Many tools contain inline WordPress calls (`get_current_user_id()`, `current_user_can()`, `get_post()`, etc.). Moving these to an infrastructure layer is high-value but requires a well-defined migration path to avoid regressions.
4. **Four active TMA webpack configs** — Consolidating build configs risks breaking the individual TMA apps if entry points or externals differ between them. Each config must be audited before merging.

---

## Complete Phased Plan

The plan is divided into four phases aligned with the four improvement areas. Each phase is independently deliverable. Phases 1 and 3 are lower-risk and can proceed in parallel. Phases 2 and 4 are higher-effort and should follow Phase 1.

---

### Phase 1 · Entrypoint & Boot Flow Cleanup

**Goal:** `mcp-ai-wpoos.php` should read as a thin dispatcher. Every substantive concern moves to a dedicated class or file.

**Target state:**
```
mcp-ai-wpoos.php
  └─ defines constants
  └─ PHP version gate
  └─ requires includes/class-wp-mcp-ai-plugin.php  (new)
  └─ calls WP_MCP_AI_Plugin::instance()->boot()

includes/class-wp-mcp-ai-plugin.php          (new – replaces inline WP_MCP_AI class)
includes/bootstrap/constants.php             (new – all define() calls)
includes/bootstrap/activation.php            (new – register_activation_hook, deactivation, uninstall)
includes/bootstrap/cron.php                  (new – cron schedules and handlers)
includes/bootstrap/hooks.php                 (new – add_action / add_filter calls that live globally)
```

**Steps:**

1. **Extract constants** — Move all `define()` calls into `includes/bootstrap/constants.php`. `mcp-ai-wpoos.php` does `require_once` of that file before anything else.
2. **Extract activation/deactivation/uninstall** — Move `wp_mcp_ai_activate()`, `wp_mcp_ai_deactivate()`, `wp_mcp_ai_uninstall()`, and their helpers into `includes/bootstrap/activation.php`. The `register_*_hook` calls stay in `mcp-ai-wpoos.php` but call into this file.
3. **Extract cron** — Move `wp_mcp_ai_ensure_cleanup_cron_scheduled()` and all cron event handlers into `includes/bootstrap/cron.php`.
4. **Extract global hooks** — Move all top-level `add_action` / `add_filter` calls (upload mime/size filters, cache invalidation hooks, admin notices) into `includes/bootstrap/hooks.php`. Each logical group gets its own well-named function inside that file.
5. **Extract WP_MCP_AI class** — Move the `WP_MCP_AI` class (~line 982 to ~line 1360) into `includes/class-wp-mcp-ai-plugin.php`. Rename or alias as needed for backward compatibility.
6. **Introduce a `Kernel` / boot sequence** — `WP_MCP_AI_Plugin::boot()` calls sub-methods in explicit order:
   - `boot_constants()`
   - `boot_services()` — service container, tool registry, REST endpoints
   - `boot_admin()` — admin pages, settings, diagnostics (only when `is_admin()`)
   - `boot_frontend()` — shortcodes, chat UI assets
   - `boot_rest()` — REST route registration
   - `boot_integrations()` — Elementor, JetEngine, WooCommerce checks
7. **Preserve Pro addon contract** — Keep the `wp_mcp_ai_bootstrapped` action fired at the same point in the boot sequence so `addons/pro/mcp-ai-wpoos-pro.php` continues to work unchanged.
8. **Update `mcp-ai-wpoos-base.php`** — This file is already a thin wrapper; ensure it still works after the entrypoint changes.
9. **Test:** Run the full PHPUnit suite (`composer run test`), activate/deactivate on single-site and multisite, confirm the Pro addon loads correctly.

**Acceptance criteria:**
- `mcp-ai-wpoos.php` is ≤ 150 lines.
- No `add_action` / `add_filter` calls remain at the global scope of `mcp-ai-wpoos.php` except for the primary boot hook (`plugins_loaded`).
- All existing PHPUnit tests continue to pass.

---

### Phase 2 · Domain / WordPress Boundary

**Goal:** WordPress-specific APIs (`add_action`, `get_option`, `current_user_can`, `wpdb`, `WP_Query`, etc.) must not appear inside domain/service/tool classes. All such calls belong in infrastructure adapters.

**Architectural layers:**

```
Domain/          Pure business logic — no WordPress, no HTTP, no DB
  ToolDefinition, ProviderConfig, AssistantConfig, WorkflowStep, …

Application/     Use-case orchestration — calls domain + infrastructure interfaces
  ChatService, ToolExecutionOrchestrator, AssistantService, …

Infrastructure/  Implements domain interfaces using WordPress / DB / HTTP / filesystem
  WP/            WordPress-specific adapters (options, post meta, capabilities)
  DB/            wpdb-based repositories
  HTTP/          wp_remote_* wrappers
  Providers/     OpenAI, Gemini, Anthropic, Ollama client wrappers

UI/              Admin pages, shortcodes, Elementor widgets, REST controllers
```

The `includes/interfaces/`, `includes/services/`, and `includes/repositories/` directories already exist and provide a good foundation. This phase formalises the rules and migrates existing classes to comply.

**Steps:**

1. **Audit and document** — Run a one-time grep for WordPress functions inside `includes/services/` and `includes/repositories/`. Produce a migration checklist (each file that needs changes).

   ```bash
   grep -rn "get_option\|update_option\|add_action\|current_user_can\|get_post\|WP_Query" \
     includes/services/ includes/repositories/ | wc -l
   ```

2. **Define infrastructure interfaces** — For each cross-cutting concern, add an interface under `includes/interfaces/`:
   - `Interface_WP_MCP_AI_Options_Store` — `get()`, `update()`, `delete()`
   - `Interface_WP_MCP_AI_Capability_Checker` — `current_user_can()`, `user_can()`
   - `Interface_WP_MCP_AI_Post_Repository` (already exists partially — formalise it)
   - `Interface_WP_MCP_AI_HTTP_Client` — `get()`, `post()`, `stream()`
   - `Interface_WP_MCP_AI_Provider_Client` — `chat()`, `stream()`, `list_models()`

3. **Implement WordPress adapters** — Create concrete classes under `includes/infrastructure/wp/`:
   - `WP_MCP_AI_WP_Options_Store implements Interface_WP_MCP_AI_Options_Store`
   - `WP_MCP_AI_WP_Capability_Checker implements Interface_WP_MCP_AI_Capability_Checker`
   - etc.

4. **Migrate services one-by-one** — For each service class that currently calls WordPress functions directly, replace those calls with injected interface calls. Use the DI container (`includes/class-wp-mcp-ai-container.php`) to wire them up.

5. **Migrate tools** — Tools are the highest-volume area (431 classes). Tools should not call `current_user_can()` inline; instead the tool registry/executor enforces capabilities before calling `execute()`. Add a `required_capability` return value to the tool interface and enforce it centrally in `WP_MCP_AI_Tool_Execution_Orchestrator`.

6. **Migrate provider clients** — `class-wp-mcp-ai-openai-client.php`, `class-wp-mcp-ai-gemini-client.php`, `class-wp-mcp-ai-anthropic-client.php`, etc. should implement `Interface_WP_MCP_AI_Provider_Client` and not call `get_option()` directly — settings should be injected at construction time.

7. **Update the container** — Register all infrastructure adapters in `includes/class-wp-mcp-ai-container.php`. The container is the single place where `new WP_MCP_AI_WP_Options_Store()` is created.

8. **Enforce via PHPCS rule** — Add a custom PHPCS sniff or a `phpcs.xml.dist` exclusion list that flags any new use of `get_option`, `current_user_can`, etc. inside `includes/services/` or `includes/repositories/`. This makes the rule machine-enforceable.

**Prioritisation within Phase 2:**

Because this is the largest phase, migrate in this order:
1. Provider clients (highest leverage — each wraps a complete AI provider)
2. Repository classes (already well-named, relatively small)
3. Service classes (medium complexity)
4. Tool classes (highest count — do as a batch with a scripted migration helper)

**Acceptance criteria:**
- Zero direct `get_option()` / `update_option()` / `current_user_can()` calls inside `includes/services/` or `includes/repositories/`.
- All provider clients implement `Interface_WP_MCP_AI_Provider_Client`.
- The capability check for tool execution is in exactly one place (`WP_MCP_AI_Tool_Execution_Orchestrator`).
- All existing PHPUnit tests pass.

---

### Phase 3 · Build-System Simplification

**Goal:** Every contributor should be able to answer "what do I run to build X?" in under 30 seconds by reading one document.

**Current state (10 config files):**

| File | Owned Surface |
|------|--------------|
| `webpack.config.js` | Admin JS (legacy bundle), workflow builder React app entry |
| `webpack.config.tma-builder.js` | TMA template builder React app |
| `webpack.config.tma-woo-shop.js` | TMA WooCommerce shop React app |
| `webpack.config.tma-shopify-jewelry.js` | TMA Shopify jewelry React app |
| `cosmos.webpack.config.js` | React Cosmos component preview (dev-only) |
| `esbuild.config.js` | Chat bundle + non-React JS (fast, minify/transpile) |
| `esbuild.config.pro.js` | Pro addon Node.js scripts (pdfkit, docx, exceljs) — platform:node, CJS |
| `babel.config.js` | Jest transform config |
| `jest.config.js` | Unit tests for JS modules |
| `cleancss.config.js` | CSS minification |

**Observations:**
- `webpack.config.js` and `webpack.config.tma-*.js` share React externals and loaders — the four TMA configs could be merged into one file using a config array or a `entries` map.
- `esbuild.config.js` (browser) and `esbuild.config.pro.js` (Node) serve genuinely different targets and should remain separate, but could be merged into a single file with named exports.
- `cosmos.webpack.config.js` is dev-only and already isolated; no action needed.
- The `npm run build:*` scripts in `package.json` are already well-named; the gap is only documentation.

**Steps:**

1. **Write `docs/BUILD_MATRIX.md`** — A table mapping each `npm run build:*` script to: what it builds, which config file it uses, what it outputs, and when to run it. This is the single source-of-truth build doc. (1–2 hours, zero risk.)

2. **Merge the four TMA webpack configs** — Replace `webpack.config.tma-builder.js`, `webpack.config.tma-woo-shop.js`, and `webpack.config.tma-shopify-jewelry.js` with a single `webpack.config.tma.js` that exports an array of configs (one per TMA). Update `package.json` scripts to point to the new file. Remove the three old files.

3. **Optionally merge the two esbuild configs** — Combine `esbuild.config.js` and `esbuild.config.pro.js` into a single `esbuild.config.js` with named `buildBrowser()` and `buildNode()` functions. Update `package.json` scripts. This is optional — the current two-file split is already clear.

4. **Audit `webpack.config.js`** — Check if the React-based workflow builder entry could be moved to a dedicated `webpack.config.workflow.js` so `webpack.config.js` becomes purely the admin JS legacy bundle. This clarifies ownership.

5. **Add a `build:all` script** (if not already present as `build:full`) that runs all build steps in the correct order and documents it in `BUILD_MATRIX.md`.

**Acceptance criteria:**
- TMA builds are driven by one config file.
- `docs/BUILD_MATRIX.md` exists and covers all 10 config files / all `build:*` npm scripts.
- `npm run build` produces the same output as before.

---

### Phase 4 · Module Boundaries and Naming Conventions

**Goal:** Folder names describe responsibility, not history. A new contributor can predict where a class lives from its name and responsibility without grep.

**Current naming issues:**
- `includes/` is a flat dump of ~150+ class files at the top level alongside subdirectories. It is unclear which classes are "core", which are "infrastructure", and which are "WordPress glue".
- `core/` at the repo root (`core/mcp-ai-wpoos-core.php`, `core/includes/`) duplicates the `includes/` namespace.
- `shared/src/` contains both PHP (`class-wp-mcp-ai-shared-logger.php`) and JS (`nvoos-*` packages) — these are unrelated and could be separated.
- Class naming is consistent (`WP_MCP_AI_*`) but file responsibility is not always clear from the name — e.g. `class-wp-mcp-ai-optional-components.php` could be anything.

**Target folder structure (inside `includes/`):**

```
includes/
  bootstrap/          ← new (Phase 1): constants, activation, cron, hooks
  domain/             ← pure PHP, no WordPress — value objects, enums, exceptions
  application/        ← use-case orchestration (currently scattered in services/)
  infrastructure/
    wp/               ← WordPress adapter implementations
    db/               ← wpdb-based repositories
    http/             ← wp_remote_* wrappers
    providers/        ← AI provider clients (OpenAI, Gemini, Anthropic, Ollama, …)
  tools/              ← unchanged (431 tool classes)
  admin/              ← unchanged (admin pages, settings)
  rest/               ← REST controllers (currently in includes/rest/)
  interfaces/         ← unchanged (expand in Phase 2)
  integrations/       ← optional third-party plugin bridges (unchanged)
  elementor/          ← unchanged
```

**Steps:**

1. **Write a naming convention ADR** — A short Architecture Decision Record (`docs/ADR_001_module_boundaries.md`) that defines the four layers, which folders map to which layer, and the rule about WordPress functions. This creates the authoritative reference.

2. **Create `includes/domain/`** — Move pure value objects and enums (e.g. `class-wp-mcp-ai-risk-level-constants.php`, `class-wp-mcp-ai-toolkit-constants.php`, `class-wp-mcp-ai-model-config.php`) here first. These are the safest moves — no dependencies change.

3. **Create `includes/infrastructure/providers/`** — Move the AI provider client files (`class-wp-mcp-ai-openai-client.php`, `class-wp-mcp-ai-gemini-client.php`, etc.) here. Update `require_once` paths in the container and bootstrap files. This is low-risk because these files are only referenced from a small number of places.

4. **Normalise `includes/services/` → `includes/application/`** — The `services/` directory already has 40+ classes. Rename the folder to `application/` in a single commit after Phase 2 is complete (so the files are already clean). Keep `services/` as a symlink or PHP alias directory for one minor version to avoid breaking any external references.

5. **Clean up `core/`** — The `core/` directory at the repo root appears to duplicate some includes functionality. Document what it uniquely owns, either absorb it into `includes/` or make its role explicit in the README. Do not delete until fully audited.

6. **Separate `shared/src/` by type** — Move PHP files to `includes/shared/` and document JS packages under `packages/README.md`. The current mixed `shared/src/` directory is confusing.

7. **Add a `CODEOWNERS`-style responsibility map** — Extend `CODEOWNERS` (already present at the repo root) so each new folder has an explicit owner. This prevents ownership ambiguity as the team grows.

**Acceptance criteria:**
- `docs/ADR_001_module_boundaries.md` exists and is linked from `docs/DOCUMENTATION_INDEX.md`.
- No PHP file in `includes/domain/` imports a WordPress function.
- AI provider clients live in `includes/infrastructure/providers/`.
- PHPUnit tests continue to pass after each file move.

---

## Recommended Execution Order

| Order | Phase | Rationale |
|-------|-------|-----------|
| 1st | Phase 3 (Build simplification) | Lowest risk, immediate contributor experience win, unblocks documentation debt |
| 2nd | Phase 1 (Entrypoint cleanup) | Enables all subsequent work by making the boot sequence explicit and testable |
| 3rd | Phase 4 (Naming/boundaries – domain layer only) | Move constants and value objects; no logic changes |
| 4th | Phase 2 (Domain/WordPress boundary – provider clients) | Start with highest-leverage classes |
| 5th | Phase 4 (Naming – infrastructure/application rename) | Follow Phase 2 migration naturally |
| 6th | Phase 2 (Domain/WordPress boundary – tools) | Largest volume; do last when the patterns are proven |

---

## What Not to Do

- **Do not rewrite the tool registry or REST endpoint system** as part of this refactor. They work, and changing their public API would break downstream integrations including the Pro addon.
- **Do not change `WP_MCP_AI_*` class naming** in a single large commit. Each rename must be a two-step: add a class alias (PHP `class_alias()`), then remove the old name in a follow-up release.
- **Do not consolidate base and pro** into a single folder tree. The current two-directory split (base `includes/`, pro `addons/pro/includes/`) maps cleanly to the WordPress.org distribution model and should be preserved.
- **Do not change `esbuild.config.pro.js` to target the browser** — it deliberately targets Node.js (`platform: 'node'`) for the document-generation scripts and must remain separate in that sense even if the files are physically merged.

---

## Testing Strategy

Each phase must be validated by:

1. **PHPUnit suite** (`composer run test`) — must pass with zero new failures.
2. **PHPCS lint** (`composer run lint`) — must pass on all modified files.
3. **Manual activation test** — single-site and multisite install/activate/deactivate.
4. **Pro addon smoke test** — confirm Pro tools, REST endpoints, and admin pages load correctly after any boot-sequence change.
5. **JS build verification** (`npm run build`) — output files must exist and match their pre-refactor hashes for unchanged bundles.

---

## Open Questions

1. **Should `core/` be deprecated?** The `core/` directory at the repo root (`core/mcp-ai-wpoos-core.php`) seems to be the original monorepo core package. Clarify whether it is still referenced by any production path before scheduling its removal.
2. **DI container scope** — `includes/class-wp-mcp-ai-container.php` already exists. Should Phase 2 expand it to be the canonical construction point for all infrastructure adapters, or keep manual `require_once` + `new` patterns for simpler classes?
3. **PHP 7.4 compatibility** — Any introduction of named arguments, union types, or `readonly` properties must be guarded since the plugin supports PHP 7.4+. Phase 2 interface definitions must use PHP 7.4-compatible syntax.
4. **Build matrix ownership** — Who owns the decision when two build tools can both handle a given asset type? A short ADR should be written for this during Phase 3.

---

## Summary

| Phase | Difficulty | Feasibility | Time Estimate | Recommended Start |
|-------|-----------|-------------|---------------|-------------------|
| 1 – Boot flow | Medium | High | 2–4 weeks | After Phase 3 |
| 2 – Domain boundary | High | High (incremental) | 6–12 weeks | After Phase 1 |
| 3 – Build simplification | Low | High | 1–2 weeks | Immediately |
| 4 – Naming / modules | Medium | High (incremental) | 4–8 weeks | Alongside Phase 2 |

The changes are incremental, reversible, and individually deliverable. No phase requires a feature freeze. The existing test suite and PHPCS configuration provide a reliable safety net throughout.
