# Fashion Studio Assistant Tools

## Purpose

This folder houses the eight Pro registry tools that expose the NV oOS Media
Studio AI fashion pipeline (`fashion_*`) to assistants, and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 7.4+ |
| **Loaded by** | `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php` (main `$pro_tools` map) |
| **Optional dependencies** | NV oOS Media Studio addon (`NV_oOS_Media_Studio_AI_Service`) — tools self-gate via `is_available()` |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Fashion_Transform_Tool` (abstract) | `class-wp-mcp-ai-fashion-transform-tool.php` | the six transform tools in this folder |
| `WP_MCP_AI_Tool_Fashion_Onmodel_Generate` | `class-wp-mcp-ai-tool-fashion-onmodel-generate.php` | tool registry, Workflow Builder presets |
| `WP_MCP_AI_Tool_Fashion_Model_Swap` | `class-wp-mcp-ai-tool-fashion-model-swap.php` | tool registry |
| `WP_MCP_AI_Tool_Fashion_Background_Generate` | `class-wp-mcp-ai-tool-fashion-background-generate.php` | tool registry |
| `WP_MCP_AI_Tool_Fashion_Recolor` | `class-wp-mcp-ai-tool-fashion-recolor.php` | tool registry |
| `WP_MCP_AI_Tool_Fashion_Packshot` | `class-wp-mcp-ai-tool-fashion-packshot.php` | tool registry |
| `WP_MCP_AI_Tool_Fashion_Virtual_Tryon` | `class-wp-mcp-ai-tool-fashion-virtual-tryon.php` | tool registry |
| `WP_MCP_AI_Tool_Fashion_Batch_Job` | `class-wp-mcp-ai-tool-fashion-batch-job.php` | tool registry |
| `WP_MCP_AI_Tool_Fashion_Identity_Manage` | `class-wp-mcp-ai-tool-fashion-identity-manage.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** tool arguments (attachment IDs, transform guidance), the
  Media Studio settings option, the fashion identity/job post meta.
- **Writes to:** the Media Library (via the base AI service), the fashion
  model/batch custom post types, user meta (one-time disclosure ack).
- **Upstream callers:** `wp_mcp_ai_pro_register_tools()` (registry), the Pro
  Workflow Builder (`WP_MCP_AI_Pro_Workflow_Presets`), assistant chat.
- **Downstream collaborators:** `NV_oOS_Media_Studio_AI_Service`
  (`addons/media-studio/includes/ai/`), `WP_MCP_AI_Fashion_Batch`,
  `WP_MCP_AI_Fashion_Model_CPT` (`addons/pro/includes/fashion/`).
- **Events fired:** none (tools return envelopes; no hooks emitted).
- **Events listened to:** none (the base service owns the seams:
  `nvoos_media_studio_execute_tool`, `nvoos_media_studio_video_generate`).

## Conventions

- Every tool implements `WP_MCP_AI_Tool_Interface` +
  `WP_MCP_AI_Tool_Usage_Guidance_Interface`; schemas use
  `additionalProperties => false`.
- Availability is declared via static `is_available()` /
  `get_unavailable_reason()` (checked by the Pro registration loop); every
  `execute()` additionally guards on the Media Studio classes and returns
  `nvoos_ms_service_missing` otherwise.
- Capability escalation is per-action inside `execute()`: batch create and
  identity create/update require `upload_files`; identity delete and
  `set_consent` require `manage_options`.
- Transform tools never hold provider credentials — the base service handles
  provider calls and output escaping.

## Tests

```bash
vendor/bin/phpunit --filter "Test_Fashion_Tools" --no-coverage
```

Base-service video coverage lives in
`addons/media-studio/tests/test-ai-service.php`; SPA coverage in
`addons/media-studio/src/__tests__/fashion-studio.test.tsx`.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (always)
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — canonical envelope + registry contracts
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro gating rules
- [docs/project/plans/media-studio-fashion-photography-implementation.md](../../../../docs/project/plans/media-studio-fashion-photography-implementation.md) — Phase 5 spec

## See Also

- Upstream parent: [`addons/pro/includes/tools/`](../)
- Sibling folders: `image-production/`, `video-production/`, `crm/`
- Bridge module: `addons/pro/includes/fashion/` (CPT, batch, REST)
