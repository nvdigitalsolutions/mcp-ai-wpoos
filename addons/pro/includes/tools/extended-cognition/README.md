# Extended Cognition

## Purpose

Houses 10 extended cognition tools implementing Clark & Chalmers' "active sensing loop": multi-modal sensory analysis (camera + screen + audio + motion), camera visual capture, screen capture, audio capture with transcription, motion/device context, sensor permission management, sensory context memory, object detection, product recognition, and video feed analysis — enabling AI agents to actively request perceptual access to the user's environment.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry via Extended Cognition toolkit (`wp_mcp_ai_ext_cog_get_settings()`) |
| **Optional dependencies** | HTTPS required (except WP_DEBUG); browser SSE-based sensor queue; `WP_MCP_AI_Ext_Cog_Sensor_Session` for session/polling |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Ext_Cog_Tool_Interface` | `interface-wp-mcp-ai-ext-cog-tool.php` | all tools |
| `WP_MCP_AI_Ext_Cog_Sensor_Access` (trait) | `trait-wp-mcp-ai-ext-cog-sensor-access.php` | all tools |
| `WP_MCP_AI_Tool_Ext_Cog_Analyze_Sensory_Input` | `class-wp-mcp-ai-tool-ext-cog-analyze-sensory-input.php` | tool registry |
| `WP_MCP_AI_Tool_Ext_Cog_Capture_Audio` | `class-wp-mcp-ai-tool-ext-cog-capture-audio.php` | tool registry |
| `WP_MCP_AI_Tool_Ext_Cog_Capture_Screen` | `class-wp-mcp-ai-tool-ext-cog-capture-screen.php` | tool registry |
| `WP_MCP_AI_Tool_Ext_Cog_Capture_Visual` | `class-wp-mcp-ai-tool-ext-cog-capture-visual.php` | tool registry |
| `WP_MCP_AI_Tool_Ext_Cog_Get_Motion_Context` | `class-wp-mcp-ai-tool-ext-cog-get-motion-context.php` | tool registry |
| `WP_MCP_AI_Tool_Ext_Cog_Manage_Sensor_Permissions` | `class-wp-mcp-ai-tool-ext-cog-manage-sensor-permissions.php` | tool registry |
| `WP_MCP_AI_Tool_Ext_Cog_Remember_Sensory_Context` | `class-wp-mcp-ai-tool-ext-cog-remember-sensory-context.php` | tool registry |
| `WP_MCP_AI_Tool_Ext_Cog_Detect_Objects` | `class-wp-mcp-ai-tool-ext-cog-detect-objects.php` | tool registry |
| `WP_MCP_AI_Tool_Ext_Cog_Recognize_Products` | `class-wp-mcp-ai-tool-ext-cog-recognize-products.php` | tool registry |
| `WP_MCP_AI_Tool_Ext_Cog_Analyze_Video_Feed` | `class-wp-mcp-ai-tool-ext-cog-analyze-video-feed.php` | tool registry, AS callbacks |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_ext_cog_get_settings()` (sensor toggles, rate limits, guest access); browser sensor queue via SSE polling; `WP_MCP_AI_Ext_Cog_Sensor_Session` CPT
- **Writes to:** Sensor session CPT (capture requests, responses); optionally WordPress media attachments (when `store=true`)
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `WP_MCP_AI_Ext_Cog_Sensor_Session` (session lifecycle, request/response queue); browser SSE endpoint for push/poll
- **Events fired:** None explicit
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Ext_Cog_Tool_Interface` (get_slug, get_name, get_description, get_required_capability, get_definition, execute).
- All tools use the `WP_MCP_AI_Ext_Cog_Sensor_Access` trait for shared permission-check logic.
- Tool slugs use `ext_cog_` prefix; category tag is `extended-cognition`.
- HTTPS is required for all sensor tools (except when `WP_DEBUG` is enabled).
- Sensor capture follows request → push → poll → consume pattern with configurable timeouts (3-60s).
- Rate limiting is enforced per-sensor via `WP_MCP_AI_Ext_Cog_Sensor_Session::check_rate_limit()`.
- Guest access is gated by `guest_access` setting and `guest_request` context flag.
- Multi-modal analysis tool (`analyze_sensory_input`) composites multiple sensors simultaneously.
- Vision recognition tools (1.8.0+) delegate to `WP_MCP_AI_HF_Vision_Inference_Service` and fall back to local Ollama models.
- `remember_sensory_context` stores lightweight metadata in options; base64 images are saved as media attachments to avoid bloating `wp_options`.
- `analyze_video_feed` sync path is `public` so Action Scheduler callbacks can invoke it without Reflection.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/extended-cognition/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
