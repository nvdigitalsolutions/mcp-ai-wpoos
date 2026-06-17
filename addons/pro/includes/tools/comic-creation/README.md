# Comic Creation

## Purpose

Houses 12 comic creation tools forming a complete digital comic pipeline: script generation, character sheet generation, panel image generation, panel breakdown, speech bubble metadata, comic style application, panel inking, panel coloring, panel lettering, page layout compositing, panel upscaling, and CBZ export.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry via `comic_creation` toolkit |
| **Optional dependencies** | GD or Imagick for image compositing; `mcp_ai_comic_script` and `mcp_ai_comic_panel` CPTs |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Generate_Comic_Script` | `class-wp-mcp-ai-tool-generate-comic-script.php` | tool registry |
| `WP_MCP_AI_Tool_Generate_Character_Sheet` | `class-wp-mcp-ai-tool-generate-character-sheet.php` | tool registry |
| `WP_MCP_AI_Tool_Generate_Comic_Panel` | `class-wp-mcp-ai-tool-generate-comic-panel.php` | tool registry |
| `WP_MCP_AI_Tool_Breakdown_Comic_Panels` | `class-wp-mcp-ai-tool-breakdown-comic-panels.php` | tool registry |
| `WP_MCP_AI_Tool_Add_Speech_Bubbles` | `class-wp-mcp-ai-tool-add-speech-bubbles.php` | tool registry |
| `WP_MCP_AI_Tool_Apply_Comic_Style` | `class-wp-mcp-ai-tool-apply-comic-style.php` | tool registry |
| `WP_MCP_AI_Tool_Ink_Comic_Panel` | `class-wp-mcp-ai-tool-ink-comic-panel.php` | tool registry |
| `WP_MCP_AI_Tool_Colorize_Comic_Panel` | `class-wp-mcp-ai-tool-colorize-comic-panel.php` | tool registry |
| `WP_MCP_AI_Tool_Letter_Comic_Panel` | `class-wp-mcp-ai-tool-letter-comic-panel.php` | tool registry |
| `WP_MCP_AI_Tool_Create_Comic_Layout` | `class-wp-mcp-ai-tool-create-comic-layout.php` | tool registry |
| `WP_MCP_AI_Tool_Upscale_Comic_Page` | `class-wp-mcp-ai-tool-upscale-comic-page.php` | tool registry |
| `WP_MCP_AI_Tool_Export_Comic_Cbz` | `class-wp-mcp-ai-tool-export-comic-cbz.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `mcp_ai_comic_script` CPT (scripts); `mcp_ai_comic_panel` CPT (panels); panel post meta (`_speech_bubbles`, `_generated_image_id`, etc.)
- **Writes to:** `mcp_ai_comic_script` and `mcp_ai_comic_panel` CPTs; panel post meta; WordPress media attachments
- **Upstream callers:** Pro tool registry, orchestrator, sequential/orchestrator patterns
- **Downstream collaborators:** `WP_MCP_AI_Logger` (event logging); GD/Imagick (image compositing); WordPress media library
- **Events fired:** None explicit
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- All tools carry `pro` and `pro-tool` capability flags.
- Tools follow a pipeline pattern: script → panels → style/ink/color/letter → layout → upscale → export.
- Panel tools operate on `mcp_ai_comic_panel` post type; layout tool composites panels into pages.
- Speech bubbles stored as JSON post meta under `_speech_bubbles` key.
- Image generation tools require GD or Imagick; compositing tools flag `may-timeout`.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/comic-creation/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
