# ADR 001 — Module Boundaries and Layer Conventions

**Date:** March 2026
**Status:** Accepted
**Deciders:** NV Digital Solutions core team

---

## Context

NV oOS has grown to ~431 tool classes plus hundreds of service, repository, admin, and REST controller files spread across `includes/`. Without explicit layer conventions, WordPress-specific APIs (`add_action`, `get_option`, `wpdb`, `current_user_can`) have proliferated into domain and service classes, making unit testing difficult and obscuring business logic from infrastructure details.

This ADR establishes the four-layer architecture described in [ARCHITECTURE_REFACTOR_PROPOSAL.md](ARCHITECTURE_REFACTOR_PROPOSAL.md) and defines the authoritative rules for where each type of code lives.

---

## Decision

The codebase is organised into **four layers**. Each layer has strict rules about what WordPress (or other framework) APIs it may use.

### Layer Map

```
includes/
  bootstrap/          Plugin boot sequence (constants, autoload, helpers, loader,
                      activation, cron, hooks). WordPress glue is permitted here.

  domain/             Pure PHP value objects, enums, and exceptions.
                      ❌ No WordPress APIs.
                      ❌ No database calls.
                      ❌ No HTTP calls.
                      ✅ No external dependencies beyond the PHP standard library.

  application/        Use-case orchestration services (previously includes/services/).
                      May call domain types and infrastructure interfaces.
                      ❌ No direct WordPress calls (use injected interfaces).
                      ❌ No direct wpdb or HTTP calls.
                      ✅ Calls domain types.
                      ✅ Calls infrastructure via interfaces from includes/interfaces/.

  infrastructure/
    wp/               WordPress adapter implementations of domain interfaces.
                      ✅ May call get_option, add_action, current_user_can, etc.
    db/               wpdb-based repository implementations.
                      ✅ May call $wpdb->query, $wpdb->get_results, etc.
    http/             wp_remote_* wrappers implementing HTTP client interfaces.
                      ✅ May call wp_remote_get, wp_remote_post, etc.
    providers/        AI provider client implementations (OpenAI, Gemini, Anthropic,
                      Ollama, LM Studio, HuggingFace, Cloudflare).
                      ✅ May call WordPress HTTP helpers.
                      ❌ Must not call get_option() directly — settings are injected.

  tools/              Tool implementations (431 classes). Use-case layer.
                      Should not call current_user_can() inline — capability checks
                      are enforced centrally by WP_MCP_AI_Tool_Execution_Orchestrator.
                      ✅ May call domain types.
                      ✅ May call application services.

  admin/              Admin pages, settings renderers, AJAX handlers.
                      ✅ WordPress admin APIs are permitted.

  rest/               REST API controllers (WP_REST_Controller subclasses).
                      ✅ WordPress REST APIs are permitted.

  interfaces/         PHP interfaces only. No implementations.
                      ❌ No WordPress APIs.

  integrations/       Optional third-party plugin bridges (JetEngine, WooCommerce,
                      Elementor, etc.). WordPress APIs permitted.

  elementor/          Elementor widget integrations. WordPress/Elementor APIs permitted.
```

---

## Interface Catalogue

The following interfaces, defined in `includes/interfaces/`, decouple application and domain layers from WordPress infrastructure:

| Interface | Purpose |
|---|---|
| `Interface_WP_MCP_AI_Options_Store` | Read/write plugin options (`get`, `update`, `delete`) |
| `Interface_WP_MCP_AI_Capability_Checker` | User capability checks (`current_user_can`, `user_can`) |
| `Interface_WP_MCP_AI_Post_Repository` | Post CRUD operations (`get`, `save`, `delete`, `query`) |
| `Interface_WP_MCP_AI_HTTP_Client` | HTTP requests (`get`, `post`, `stream`) |
| `Interface_WP_MCP_AI_Provider_Client` | AI provider operations (`chat`, `stream`, `list_models`) |
| `WP_MCP_AI_Tool_Interface` | Tool contract (`get_slug`, `execute`, `get_definition`) |

Concrete implementations live in `includes/infrastructure/wp/` (WordPress adapters) or the corresponding sub-directory.

---

## Enforcement Rules

1. **No `get_option` / `update_option` / `current_user_can` / `add_action` inside `includes/domain/` or `includes/application/`.** Violations should be caught by a custom PHPCS sniff (see Phase 2 implementation roadmap).

2. **AI provider clients live in `includes/infrastructure/providers/`.** They implement `Interface_WP_MCP_AI_Provider_Client` and receive settings via constructor injection rather than calling `get_option()` directly.

3. **Tool capability checks are enforced in one place:** `WP_MCP_AI_Tool_Execution_Orchestrator`. Individual tool `execute()` methods should not call `current_user_can()`.

4. **The DI container (`includes/class-wp-mcp-ai-container.php`) is the single construction point** for infrastructure adapters. No other code should call `new WP_MCP_AI_WP_Options_Store()` directly.

5. **`includes/domain/` files must not import or extend any WordPress class.** They may use PHP native types only.

---

## Migration Strategy

Because the codebase is large, migration is incremental:

1. New code must comply with these rules from day one.
2. Existing violations are tracked in a migration checklist (see Phase 2 in `ARCHITECTURE_REFACTOR_PROPOSAL.md`).
3. Each migrated file is noted in the checklist. No file is migrated without a corresponding PHPUnit test passing.

---

## Consequences

**Good:**
- Domain and application logic becomes unit-testable without a WordPress environment.
- AI provider clients can be swapped without touching business logic.
- New contributors can orient themselves using folder names alone.
- PHPCS enforcement prevents regression.

**Neutral:**
- Injecting interfaces adds a small amount of boilerplate to constructor signatures.
- The DI container grows as more adapters are registered.

**Bad (accepted):**
- Migrating 431 tool classes is a high-volume mechanical task.
- Some older code will remain non-compliant until explicitly migrated.

---

## Related Documents

- [ARCHITECTURE_REFACTOR_PROPOSAL.md](ARCHITECTURE_REFACTOR_PROPOSAL.md)
- [BUILD_MATRIX.md](BUILD_MATRIX.md)
- [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md)
- [../proposals/cross-platform-extraction-gap-analysis.md](../proposals/cross-platform-extraction-gap-analysis.md) — downstream extraction implementing these layer boundaries at `lib/core/`

---

_This ADR is referenced by the PHPCS configuration. Update it if the layer rules change._
