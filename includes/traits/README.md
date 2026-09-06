# Traits

## Purpose

Houses reusable PHP `trait` mixins that supply cross-cutting behaviour (queue defaults, inline-async ticking, attachment resolution, vision request timeouts, WordPress-native hook plumbing, Node.js bridge, SVG vectorization) to tools and services without forcing inheritance.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` (each trait is `require_once`d before its first consumer) |
| **Optional dependencies** | Node.js binary (only for `WP_MCP_AI_NodeJS_Subprocess` and `WP_MCP_AI_SVG_Vectorizer`) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Queue_Trait` | `trait-wp-mcp-ai-tool-queue.php` | tools that implement `WP_MCP_AI_Tool_Queue_Interface` |
| `WP_MCP_AI_Inline_Async_Tick_Trait` | `trait-wp-mcp-ai-inline-async-tick.php` | `services/class-wp-mcp-ai-tool-async-executor.php`, `services/class-wp-mcp-ai-transcript-mining-job.php`, `services/class-wp-mcp-ai-gemini-video-generation-service.php`, `crawler/`, `harness/` |
| `WP_MCP_AI_Tool_WordPress_Native` | `trait-wp-mcp-ai-tool-wordpress-native.php` | tools that auto-register `save_post`/comment/user hooks (e.g. `auto_categorize_content`, `2fa_setup_assistant`) |
| `WP_MCP_AI_Attachment_File_Resolver` | `trait-wp-mcp-ai-attachment-file-resolver.php` | media-aware tools (`analyze_image`, `analyze_video`, etc.) |
| `WP_MCP_AI_Vision_Request_Timeout` | `trait-wp-mcp-ai-vision-request-timeout.php` | vision analysis tools (`generate_image_alt_text`, `generate_image_caption`, `analyze_image`, `extract_image_text`) and `WP_MCP_AI_Self_Hosted_OCR_Client` |
| `WP_MCP_AI_NodeJS_Subprocess` | `trait-wp-mcp-ai-nodejs-subprocess.php` | tools/services that shell out to Node (vectorizer, harness eval) |
| `WP_MCP_AI_SVG_Vectorizer` | `trait-wp-mcp-ai-svg-vectorizer.php` | image-to-SVG tools (composes `WP_MCP_AI_NodeJS_Subprocess`) |

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress object cache + transients (lock primitives in the inline-async trait), `$_SERVER` (FastCGI detection), attachment post meta (file resolver), the `wp_mcp_ai_settings` option and `WP_MCP_AI_Resource_Manager` (vision request timeout).
- **Writes to:** transients, object-cache locks, child-process I/O (Node.js), and whatever the composing class's `execute()` writes — traits do not persist directly.
- **Upstream callers:** every consumer class declares the trait via `use` at the top of its class body. See `services/` and `tools/` for ~hundreds of `use` sites.
- **Downstream collaborators:** trait methods call into `interfaces/` contracts and `infrastructure/` adapters where possible, falling back to direct WordPress functions for legacy compatibility.
- **Events fired:** `wp_mcp_ai_inline_kick_completed` (from `WP_MCP_AI_Inline_Async_Tick_Trait` on every kick), `wp_mcp_ai_vision_request_timeout` (filter — from `WP_MCP_AI_Vision_Request_Timeout` on every resolved vision timeout).
- **Events listened to:** `wp_mcp_ai_inline_kick_enabled` (filter — global / per-job kill switch).

## Conventions

- Each file declares **one** trait. File and trait naming follow the project-wide rules in [`.context/conventions.md`](../../.context/conventions.md). Both `WP_MCP_AI_Foo` and `WP_MCP_AI_Foo_Trait` legacy variants are present — do not rename existing traits.
- Traits provide **defaults**, not policy: methods should be small, side-effect-light, and overridable. If a trait grows internal state machines, promote it to a service in `services/`.
- Traits may use WordPress APIs (this is not the `domain/` layer), but must `function_exists()`-guard anything that disappears under CLI / FastCGI / `disable_functions` (see the rationale block in `trait-wp-mcp-ai-inline-async-tick.php`).
- All hooks fired from traits must use the documented filter/action names so observers (e.g. Pro measurement bootstrap) can subscribe without grepping for trait internals.

## Tests

```bash
vendor/bin/phpunit tests/test-tool-envelope-trait.php
vendor/bin/phpunit tests/test-inline-async-tick-trait.php
vendor/bin/phpunit tests/test-image-response-trait.php
```

The Node.js subprocess and SVG vectorizer traits rely on a Node binary being present and are covered indirectly through their consumer tool tests.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — sanitisation rules for inputs traits receive
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — canonical envelope; the queue trait fills it in
- [`CLAUDE.md`](../../CLAUDE.md) — tool authoring rules referenced by composing classes

## See Also

- Upstream parent: [`includes/`](../)
- Sibling folders: [`interfaces/`](../interfaces/) (paired contracts — `WP_MCP_AI_Tool_Queue_Interface` etc.), [`tools/`](../tools/), [`services/`](../services/) (primary consumers)
