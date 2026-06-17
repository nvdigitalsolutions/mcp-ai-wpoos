# Video Production Assistant Blueprints

> Curated mcp_ai_assistant preset files installable via the
> `import_video_production_blueprint` tool and the shared `WP_MCP_AI_Blueprint_Installer`.

Each `.json` file is a self-contained Assistant blueprint — a preset configuration
of the mcp_ai_assistant CPT that wires together role-specific instructions,
an allowlisted subset of video production tools, and platform-optimisation rules.

## Available blueprints

| Slug | Name | Role | Tool Count |
|---|---|---|---|
| `video-editor` | Video Editor | Post-production specialist | 16 |
| `production-manager` | Video Production Manager | End-to-end coordinator | 19 |

### Video Editor

Post-production specialist handling video trimming, merging, format conversion,
caption generation, watermarking, and platform-specific optimisation. Use when
you need a focused editor that understands aspect ratios, codecs, and export
settings for YouTube, Instagram, TikTok, and web delivery.

**Key tools**: `trim_video`, `merge_videos`, `generate_video_captions`,
`optimize_for_platform`, `compress_video`, `add_watermark_to_video`.

### Video Production Manager

End-to-end video production coordinator. Manages projects from concept through
delivery, including AI video generation (Veo, Sora), editing, captioning, and
multi-platform distribution. Also handles Remotion programmatic videos and
text-to-speech narration. Use when you need a producer that can plan, generate,
edit, and distribute in a single session.

**Key tools**: `generate_veo_video`, `generate_sora_video`, `create_remotion_video`,
`generate_openai_speech`, `create_video_from_images`.

## Blueprint format

Video production blueprints use the direct WordPress-style (Healthcare) schema:

```json
{
  "$schema": "https://schemas.nvdigitalsolutions.com/mcp-ai/assistant-blueprint.schema.json",
  "blueprint_id": "video-production-editor",
  "name": "Human-readable name",
  "description": "Short summary",
  "post_title": "Assistant CPT post_title",
  "post_status": "publish",
  "post_content": "Assistant-level instructions (post_content)",
  "meta_input": {
    "_wp_mcp_ai_provider": "openai",
    "_wp_mcp_ai_model": "gpt-4.1",
    "_wp_mcp_ai_temperature": 0.3,
    "_wp_mcp_ai_system_prompt": "Full system prompt",
    "_wp_mcp_ai_tools": ["tool_slug", "..."],
    "mcp_ai_required_capability": "edit_posts"
  }
}
```

## Tool

- **`import_video_production_blueprint`** — [`class-wp-mcp-ai-tool-import-video-production-blueprint.php`](class-wp-mcp-ai-tool-import-video-production-blueprint.php)
  - Delegates to `WP_MCP_AI_Blueprint_Installer` (shared installer).
  - Parameters: `blueprint` (enum of slugs above), `overwrite` (bool).
  - Creates or updates an `mcp_ai_assistant` CPT entry.
  - Requires the Video Production Toolkit to be enabled in plugin settings.

## Usage

Via the chat interface:

```
import_video_production_blueprint: video-editor
```

Or via the REST `/tools` endpoint:

```json
{
  "tool": "import_video_production_blueprint",
  "arguments": { "blueprint": "video-editor", "overwrite": true }
}
```

## Adding a new blueprint

1. Create a new `.json` file in this directory following the schema above.
2. Add the slug to `BLUEPRINT_SLUGS` in the import tool class.
3. Add a row to the table in this README.

The `enum` in the tool's parameter schema ensures the LLM only requests valid
blueprints.  No other registration is needed — the tool discovers files by
listing the directory.

## Related

- [Video Production Toolkit README](../README.md)
- [`WP_MCP_AI_Blueprint_Installer`](../../orchestration/class-wp-mcp-ai-blueprint-installer.php)
- [CRM examples](../../crm/examples/)
- [Healthcare examples](../../healthcare/examples/)
