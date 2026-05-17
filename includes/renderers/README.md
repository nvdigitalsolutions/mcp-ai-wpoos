# Renderers

## Purpose

Holds server-side renderers that turn a structured tool envelope into chat-safe / dashboard-safe HTML so multiple surfaces (Gutenberg blocks, Elementor widgets, Pro tools) share one escape-correct rendering path.

## Tier

| | |
|---|---|
| **Distribution** | Base (renderers may gracefully degrade to a "Pro required" notice when invoked without the Pro addon) |
| **PHP target** | 7.4+ |
| **Loaded by** | On demand by the consumer (no init file). Consumers `require_once WP_MCP_AI_PATH . 'includes/renderers/<file>.php'` before calling the static `render()` — see `WP_MCP_AI_Scheduled_Result_Block::render()`, `WP_MCP_AI_Elementor_Scheduled_Result_Widget::render()`, and `WP_MCP_AI_Pro_Tool_Render_Schedule_Result::execute()` for the canonical wiring |
| **Optional dependencies** | NV oOS Pro (specific renderers may delegate to Pro services; absence is handled by a "Pro required" notice) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Scheduled_Result_Renderer::render( $schedule_id, $opts )` | `class-wp-mcp-ai-scheduled-result-renderer.php` | [`includes/blocks/scheduled-result/render.php`](../blocks/scheduled-result/render.php), [`includes/blocks/class-wp-mcp-ai-scheduled-result-block.php`](../blocks/class-wp-mcp-ai-scheduled-result-block.php), [`includes/elementor/class-wp-mcp-ai-elementor-scheduled-result-widget.php`](../elementor/class-wp-mcp-ai-elementor-scheduled-result-widget.php), `addons/pro/includes/tools/class-wp-mcp-ai-pro-tool-render-schedule-result.php` |
| `WP_MCP_AI_Scheduled_Result_Renderer::MODES` | same | All call-sites — canonical list of render modes (`summary-card`, `list`, `table`, `metric`, `timeline`, `raw`) |

This folder is intentionally small — it acts as the **single source of truth** for any markup that is shared across the block editor, Elementor, and a tool surface, so escaping rules can never drift between them.

## Inputs / Outputs / Neighbors

- **Reads from:** A schedule ID + an options array; the latest schedule-result envelope produced by `WP_MCP_AI_Pro_Schedule_Manager`; current-user authorisation state (logged-in + the `read_private_posts` capability) for public-render gating — see [`.context/security-checklist.md`](../../.context/security-checklist.md)
- **Writes to:** nothing — returns a fully escaped HTML string
- **Upstream callers:** the Gutenberg block, the Elementor widget, and the Pro `render_schedule_result` tool
- **Downstream collaborators:** `WP_MCP_AI_Pro_Schedule_Manager` (envelope source and public-render redaction); WordPress i18n + escaping helpers
- **Events fired:** none
- **Events listened to:** none — invocation is purely synchronous

## Conventions

Folder-specific deltas:

- Every public entry-point is a `public static function render( …​ )` that returns a **string** — renderers MUST NOT echo, MUST NOT set headers, and MUST NOT mutate state.
- Renderers MUST escape every interpolated value at the output boundary; the chat-/block-side callers may therefore `echo` the result unescaped (with the explicit `phpcs:ignore … OutputNotEscaped` comment).
- When a renderer depends on Pro state, it MUST detect the missing class and return a localised `wrap_notice()` string — never fatal, never `WP_Error`.
- Public-content gating MUST be applied inside the renderer (e.g. redact non-public envelopes for anonymous visitors). Callers are not expected to know the gating rules.
- Render modes are an enumerated constant (e.g. `self::MODES`); unknown modes fall back to a safe default (`summary-card`).

## Tests

Tests for the only current renderer live in the Pro test suite because the renderer's primary consumer is Pro:

```bash
vendor/bin/phpunit addons/pro/tests/test-pro-schedule-result-renderer.php
```

The base-version code path (the "Pro required" notice) is asserted by the same test class via the `test_no_pro_yields_pro_required_notice` case.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — escape-on-output rules (always)
- [`.context/chat-ui.md`](../../.context/chat-ui.md) — for chat-side surfaces that consume rendered HTML
- [`.context/pro-vs-base.md`](../../.context/pro-vs-base.md) — Pro fallback / degradation pattern

## See Also

- Sibling subsystems: [`includes/markup/`](../markup/) — interactive elicitation (markup the user *creates*), distinct from this folder which renders markup the *server* produces
- Consumers: [`includes/blocks/`](../blocks/), [`includes/elementor/`](../elementor/), `addons/pro/includes/tools/`
