# Capture

## Purpose

Houses a single abstract base class (`WP_MCP_AI_Pro_Capture_Tool_Base`) that provides shared scaffolding for per-toolkit MemPalace capture tools. Each sub-toolkit extends this class with ~80 lines of toolkit-specific configuration (wing prefix, room enum, defaults) while the base handles schema generation, argument validation, wing-prefix sprawl prevention, and delegation to `WP_MCP_AI_Memory_Capture_Service`.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry (via per-toolkit concrete subclasses) |
| **Optional dependencies** | `WP_MCP_AI_Memory_Capture_Service` must be loaded |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Pro_Capture_Tool_Base` | `class-wp-mcp-ai-pro-capture-tool-base.php` | `crm_capture_interaction`, `pm_capture_decision`, `health_capture_encounter`, and other per-toolkit capture tools |

## Inputs / Outputs / Neighbors

- **Reads from:** Per-toolkit settings (via subclasses), MemPalace wing/room configuration
- **Writes to:** MemPalace memory store via `WP_MCP_AI_Memory_Capture_Service::store()`
- **Upstream callers:** Per-toolkit capture tool subclasses (~6 concrete implementations)
- **Downstream collaborators:** `WP_MCP_AI_Memory_Capture_Service`
- **Events fired:** None (delegated to service)
- **Events listened to:** None

## Conventions

- This is an `abstract` class — never instantiated directly.
- Subclasses must implement 5 abstract methods: `get_wing_prefix()`, `get_wing_key_name()`, `get_room_enum()`, `get_capture_defaults()`, plus the standard tool slug/name/description.
- Wing/room sprawl is prevented: `get_room_enum()` requires a closed list; free-text rooms are rejected.
- Doc Gen / Multilingual toolkits may opt into two-record discipline (verbatim archival + summary recall) via `allow_summarisation` in defaults.
- Implements `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/capture/
```

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Pro tools index
