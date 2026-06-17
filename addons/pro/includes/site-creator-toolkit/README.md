# Site Creator Toolkit

## Purpose

Pure-PHP service layer for the Site Creator toolkit's "extract design from mockups" pipeline — one orchestrator that turns mixed inputs (mockup images, HTML/CSS files, live URLs, free-text brief) into a normalized Design System JSON, and one I/O-free renderer that emits an install-ready PHP "site design snippet" from that JSON.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `addons/pro/includes/site-creator-toolkit-init.php` (admin path) and lazily required by the tool class `WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups` and its tests. The toolkit only loads when `enable_site_creator_toolkit` is on (and `enable_design_extractor` gates the vision-tokens path inside the tool). |
| **Optional dependencies** | The vision branch in `Design_Extractor_Service::extract()` is wired through the `wp_mcp_ai_design_extractor_vision` filter — production hooks it to the OpenAI/Gemini provider clients; tests can short-circuit with a fixture. WPCode is a downstream consumer (the rendered snippet is paste-compatible) but never required at runtime. |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Design_Extractor_Service::extract()` (+ `MAX_IMAGES`, `MAX_IMAGE_BYTES`, `SOURCE_WEIGHTS` constants) | `class-wp-mcp-ai-design-extractor-service.php` | `WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups`, design-extractor tests |
| `WP_MCP_AI_Design_Snippet_Renderer::render()` / `::render_tokens_css()` / `::render_interactions_js()` / `::render_interactions_css()` / `::render_jfb_skin_css()` / `::pick_skin_variant()` / `::sanitize_color()` | `class-wp-mcp-ai-design-snippet-renderer.php` | `WP_MCP_AI_Tool_Extract_Site_Design_From_Mockups`, snippet-renderer tests |
| `WP_MCP_AI_Design_Snippet_Renderer::SNIPPET_SHAPE_VERSION`, `::SKIN_VARIANTS`, `::TARGETS`, `::FEATURES` | same | The tool uses these to validate `targets`, `features`, `skin_variant` arguments |

Anything not listed (private helpers, internal merge logic, fixture shapes) is implementation detail.

## Inputs / Outputs / Neighbors

- **Reads from:** in-memory `$inputs` arrays passed by the calling tool (image bytes, HTML/CSS strings, URLs, brief text); the `wp_mcp_ai_design_extractor_vision` filter; `wp_mcp_ai_settings` is only consulted by callers, not by these classes.
- **Writes to:** nothing on disk. The renderer returns strings. Persistence (e.g. registering a `wp_site_template` post, dropping a file into `mu-plugins/`, or piping into WPCode) is the caller's responsibility.
- **Upstream callers:** [`../tools/site-creator-toolkit/class-wp-mcp-ai-tool-extract-site-design-from-mockups.php`](../tools/site-creator-toolkit/class-wp-mcp-ai-tool-extract-site-design-from-mockups.php) is the primary caller; tests load these classes directly.
- **Downstream collaborators:** none at the PHP level — both classes are deliberately pure functions / pure transformers. HTTP calls happen only through the `wp_mcp_ai_design_extractor_vision` filter that production wires to provider clients.
- **Events fired:** `apply_filters( 'wp_mcp_ai_design_extractor_vision', null, $image, $brief )` once per mockup image.
- **Events listened to:** none.

## Conventions

- **No I/O.** Both classes must remain unit-testable with golden-file fixtures — no `wp_remote_*`, no `file_*`, no DB writes. Everything that needs to do I/O does it from the tool or test wrapper.
- **Provenance is part of the contract.** The extractor records the winning source per token in `$result['_provenance']`; downstream consumers (including documentation tools) depend on that key — do not drop it.
- **Snippet shape is versioned.** Any backwards-incompatible change to the emitted PHP file structure must bump `WP_MCP_AI_Design_Snippet_Renderer::SNIPPET_SHAPE_VERSION` and add a golden-file fixture.
- **Token merging is weighted, not last-wins.** `SOURCE_WEIGHTS` (explicit `:root` tokens > vision > URL analysis) defines the merge order; new sources must register a weight rather than monkey-patching the merge.
- Constants on the renderer (`SKIN_VARIANTS`, `TARGETS`, `FEATURES`) are the **single source of truth** — the tool's input validation reads them directly via `array_intersect()`; do not duplicate the lists in the tool.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-tool-extract-site-design-from-mockups.php
```

The tool-level suite covers both classes end-to-end (the test bootstrap requires the extractor + renderer directly from this folder). Pure-PHP unit tests for the renderer's individual `render_*` helpers and the extractor's source-weighted merge logic are a known gap.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — sanitiser/escaper rules used when emitting CSS/JS
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — canonical return envelope used by the calling tool
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Site Creator is Pro-only
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat policy, two-gate sanitisation

## See Also

- Toolkit bootstrap: [`../site-creator-toolkit-init.php`](../site-creator-toolkit-init.php)
- Settings page: [`../admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php`](../admin/class-wp-mcp-ai-site-creator-toolkit-settings-page.php)
- Site template CPT (downstream persistence target): [`../class-wp-mcp-ai-site-template-cpt.php`](../class-wp-mcp-ai-site-template-cpt.php)
- Calling tool: [`../tools/site-creator-toolkit/class-wp-mcp-ai-tool-extract-site-design-from-mockups.php`](../tools/site-creator-toolkit/class-wp-mcp-ai-tool-extract-site-design-from-mockups.php)
