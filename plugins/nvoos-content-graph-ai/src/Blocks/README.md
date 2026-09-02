# Blocks

## Purpose

Gutenberg blocks for the AI addon (Wave D-UI-2): `nvoos-content-graph-ai/chat` and `nvoos-content-graph-ai/chat-bubble` — server-rendered wrappers around the `[nvoos_content_graph_chat]` widget (`src/Frontend/ChatShortcode.php`). Aligned ports of the base plugin's `mcp-ai-wpoos/chat*` blocks: same attribute vocabulary (minus the attributes the CG widget has no counterpart for), same floating-bubble behaviour contract, ecosystem-prefixed class names.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `NvoosContentGraphAi\Plugin::register()` (both install modes) |
| **Optional dependencies** | WordPress block editor (registration guarded) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Blocks\Blocks` | `Blocks.php` | `Plugin::register()` — block + category + asset registration |
| `NvoosContentGraphAi\Blocks\ChatBlock` | `ChatBlock.php` | `Blocks::register_blocks()` (render callback + metadata) |
| `NvoosContentGraphAi\Blocks\ChatBubbleBlock` | `ChatBubbleBlock.php` | `Blocks::register_blocks()` (render callback + metadata) |

## Inputs / Outputs / Neighbors

- **Reads from:** block attributes (assistant/guests/provider/model/appearance/behaviour/colors)
- **Writes to:** block HTML (bubble shell + CSS custom properties + embedded widget), registered block types, block category, bubble frontend assets
- **Upstream callers:** WordPress block editor / block rendering
- **Downstream collaborators:** `src/Frontend/ChatShortcode.php` (embedded widget), `assets/js/content-graph-ai-chat-bubble.js` (toggle behaviour), `assets/css/content-graph-ai-chat-bubble.css` (shell styles), `assets/js/blocks/content-graph-ai-blocks.js` (editor)
- **Events fired:** `nvoos-cg-bubble:open` / `nvoos-cg-bubble:close` (frontend custom events)

## Conventions

- Block names/category are ecosystem-specific — never collide with the base's `mcp-ai-wpoos/*` blocks in monolith installs.
- Registration is guarded by `function_exists( 'register_block_type' )` and idempotent against the block registry.
- The bubble initialises the embedded widget eagerly (the CG widget is small); the base's lazy-init deferral is unnecessary (documented deviation).
- All attribute-derived markup is escaped at output; colors go through `sanitize_hex_color`.

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping

## See Also

- Parent: [`../`](../) — src root
- Widget: [`../Frontend/ChatShortcode.php`](../Frontend/ChatShortcode.php)
- Assets: [`../../assets/js/blocks/content-graph-ai-blocks.js`](../../assets/js/blocks/content-graph-ai-blocks.js), [`../../assets/js/content-graph-ai-chat-bubble.js`](../../assets/js/content-graph-ai-chat-bubble.js), [`../../assets/css/content-graph-ai-chat-bubble.css`](../../assets/css/content-graph-ai-chat-bubble.css)
