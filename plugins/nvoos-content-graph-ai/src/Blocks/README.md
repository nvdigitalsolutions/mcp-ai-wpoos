# Blocks

## Purpose

Gutenberg blocks for the AI addon:

- **Wave D-UI-2:** `nvoos-content-graph-ai/chat` and `nvoos-content-graph-ai/chat-bubble` — server-rendered wrappers around the `[nvoos_content_graph_chat]` widget (`src/Frontend/ChatShortcode.php`). Aligned ports of the base plugin's `mcp-ai-wpoos/chat*` blocks: same attribute vocabulary (minus the attributes the CG widget has no counterpart for), same floating-bubble behaviour contract, ecosystem-prefixed class names.
- **Wave D-UI-4 close-out:** `nvoos-content-graph-ai/assistant-selector`, `nvoos-content-graph-ai/tools-grid`, `nvoos-content-graph-ai/knowledge-base`, and `nvoos-content-graph-ai/assistant-builder` — aligned ports of the base plugin's assistant builder block set. The tools grid resolves tools per install mode (base registry monolith / nvoos-core registry via `CoreBridge` standalone); the builder's create flow submits to the per-mode create action (`wp_mcp_ai_create_assistant` monolith / `nvoos_cg_ai_create_assistant` standalone). The Build Assistant page's Prompt tab embeds the tools grid + knowledge base components via their static render methods.

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
| `NvoosContentGraphAi\Blocks\AssistantSelectorBlock` | `AssistantSelectorBlock.php` | `Blocks::register_blocks()` + `AssistantBuilderBlock::render()` + admin embeds |
| `NvoosContentGraphAi\Blocks\ToolsGridBlock` | `ToolsGridBlock.php` | `Blocks::register_blocks()` + `AssistantBuilderBlock::render()` + `BuildAssistantPage` Prompt tab |
| `NvoosContentGraphAi\Blocks\KnowledgeBaseBlock` | `KnowledgeBaseBlock.php` | `Blocks::register_blocks()` + `AssistantBuilderBlock::render()` + `BuildAssistantPage` Prompt tab |
| `NvoosContentGraphAi\Blocks\AssistantBuilderBlock` | `AssistantBuilderBlock.php` | `Blocks::register_blocks()` (render callback + metadata) |

## Inputs / Outputs / Neighbors

- **Reads from:** block attributes (assistant/guests/provider/model/appearance/behaviour/colors; tools/KB/builder section toggles), the active tool registry (per install mode), the `mcp_ai_assistant` post type
- **Writes to:** block HTML (selector / tools grid / knowledge base / builder markup, embedded widget), registered block types, block category, frontend assets
- **Upstream callers:** WordPress block editor / block rendering, `BuildAssistantPage::render_prompt_tab()` (admin embed)
- **Downstream collaborators:** `src/Frontend/ChatShortcode.php` (embedded widget), `src/CoreBridge.php` (standalone tool registry), `assets/js/blocks/content-graph-ai-assistant-blocks.js` (frontend behaviour), `assets/css/blocks/content-graph-ai-assistant-blocks.css` (block styles), `assets/js/blocks/content-graph-ai-blocks.js` (editor)
- **Events fired:** `nvoos-cg-bubble:open` / `nvoos-cg-bubble:close`, `nvoos-cg-selector:change` / `nvoos-cg-selector:start`, `nvoos-cg-tools-grid:change`, `nvoos-cg-kb:change` (frontend custom events)

## Conventions

- Block names/category are ecosystem-specific — never collide with the base's `mcp-ai-wpoos/*` blocks in monolith installs.
- Registration is guarded by `function_exists( 'register_block_type' )` and idempotent against the block registry.
- Render callbacks accept a nullable `WP_Block` (third argument) so admin pages can embed the same markup outside a block context.
- Capability gates mirror the base blocks: `edit_posts` for tools grid + builder, `upload_files` for knowledge base, none for the selector.
- All attribute-derived markup is escaped at output; colors go through `sanitize_hex_color`; tool slugs through `sanitize_key`.

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — escaping

## See Also

- Parent: [`../`](../) — src root
- Widget: [`../Frontend/ChatShortcode.php`](../Frontend/ChatShortcode.php)
- Assets: [`../../assets/js/blocks/content-graph-ai-blocks.js`](../../assets/js/blocks/content-graph-ai-blocks.js), [`../../assets/js/blocks/content-graph-ai-assistant-blocks.js`](../../assets/js/blocks/content-graph-ai-assistant-blocks.js), [`../../assets/css/blocks/content-graph-ai-assistant-blocks.css`](../../assets/css/blocks/content-graph-ai-assistant-blocks.css)
- Admin embed: [`../Admin/AssistantPages/BuildAssistantPage.php`](../Admin/AssistantPages/BuildAssistantPage.php)
