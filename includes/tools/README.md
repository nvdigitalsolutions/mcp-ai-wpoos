# Tools

## Purpose

Houses every built-in tool implementation that the LLM agentic loop, REST `/tools` endpoint, WP-CLI `mcp-ai tool` command, and the chat surfaces can invoke — one PHP class per tool, one tool per responsibility.

## Tier

| | |
|---|---|
| **Distribution** | Base (Pro adds further tools under `addons/pro/includes/tools/`) |
| **PHP target** | 7.4+ |
| **Loaded by** | [`includes/tools-init.php`](../tools-init.php) → `WP_MCP_AI_Tool_Registry::get_instance()->init()` at `plugins_loaded` priority 20; orchestration tools loaded by [`includes/orchestration-init.php`](../orchestration-init.php); validated tools loaded by [`includes/validators/validated-tools-init.php`](../validators/validated-tools-init.php) |
| **Optional dependencies** | JetEngine, WooCommerce, Elementor, Rank Math, WPCode, Crawl4AI, Symfony Validator (for `*_validated` siblings) |

## Public Surface

The folder's external contract is the **tool slug** registered with `WP_MCP_AI_Tool_Registry` — callers should resolve tools by slug, never by class name. The live registry is the authoritative count; do not enumerate classes here.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Registry::get_tools()` | `includes/class-wp-mcp-ai-tool-registry.php` | `includes/rest/`, `includes/cli/`, services |
| `WP_MCP_AI_Tool_*` classes (one per file) | `class-wp-mcp-ai-tool-*.php` | Registry only — never instantiated directly by callers |
| `WP_MCP_AI_Tool_Image_Base` | `class-wp-mcp-ai-tool-image-base.php` | Image-generation tools in this folder |
| Response composition traits | `trait-wp-mcp-ai-tool-{chat,image,audio,video,document,email,math,chart-accessibility,content-media,product-card,envelope,restrict-from-chat-client}-response.php` | Tools in this folder and Pro |
| Markup-aware tools | implement `WP_MCP_AI_Markup_Aware_Tool_Interface` (see [`includes/markup/`](../markup/)) | `WP_MCP_AI_Markup_Loop_Interceptor` |

Tool categories (illustrative — see [`docs/reference/tools/tool-reference.md`](../../docs/reference/tools/tool-reference.md) for the full catalogue):

- **Content** — `create_post`, `save_post`, `delete_post`, `search_content`, `get_recent_posts`, `update_term`, `create_term`, `list_terms`, `list_taxonomies`
- **Media / vision** — `generate_openai_image`, `generate_gemini_image`, `analyze_image`, `crop_image`, `resize_image`, `vectorize_image`, `extract_image_text`
- **Audio / video** — `transcribe_openai_audio`, `generate_openai_speech`, `generate_music`, `generate_veo_video`, `generate_sora_video`
- **WordPress ops** — `get_site_health`, `get_system_logs`, `purge_cache`, `list_cron_jobs`, `create_cron_job`
- **Agentic / orchestration** (`orchestration/`) — `create_task_plan`, `manage_autonomous_session`, `check_exit_conditions`, `analyze_loop_health`
- **Reasoning harness** (`harness/`) — `apply_prompt_cue`, `record_reflection`, `self_consistency_vote`, `retrieve_with_provenance`
- **Memory / context** — `recall_memory`, `store_agent_context`, `prioritize_context`, `manage_context_lifecycle`, `mine_agent_memory`
- **Integrations** — Erlang-C, Open-Meteo, GDACS, ReliefWeb, HuggingFace datasets, Flowhub, PayHere, Crawl4AI, Site Kit, Rank Math

`*_validated` siblings (e.g. `class-wp-mcp-ai-tool-create-post-validated.php`) extend `WP_MCP_AI_Validated_Tool` from [`includes/validators/`](../validators/) and accept the same slug — they replace the non-validated version when Symfony Validator is available.

## Inputs / Outputs / Neighbors

- **Reads from:** `$arguments` (LLM-provided, validated/sanitised at entry); `$context` (user_id, assistant_id, request origin); WordPress core APIs; provider HTTP clients in [`includes/services/`](../services/) and `includes/class-wp-mcp-ai-*-client.php`
- **Writes to:** posts/terms/users/options/cron via WordPress core; provider APIs (OpenAI, Gemini, etc.) through the language-model router; transients for async polling
- **Upstream callers:** `WP_MCP_AI_Tool_Registry::execute_tool()` invoked by [`includes/rest/`](../rest/) (chat & `/tools` controllers), [`includes/cli/`](../cli/) (`mcp-ai tool` subcommand), the agentic loop, and the markup interceptor
- **Downstream collaborators:** [`includes/services/`](../services/), [`includes/repositories/`](../repositories/), [`includes/validators/`](../validators/), [`includes/markup/`](../markup/), [`includes/interfaces/`](../interfaces/), [`includes/traits/`](../traits/)
- **Events fired:** `wp_mcp_ai_tool_before_execute`, `wp_mcp_ai_tool_after_execute`, `wp_mcp_ai_tool_error`, plus per-tool action hooks
- **Events listened to:** `wp_mcp_ai_register_tools` (Symfony-validated tools), `wp_mcp_ai_tools_init` (orchestration tools)

## Conventions

Folder-specific deltas (canonical rules in [`.context/tool-registry.md`](../../.context/tool-registry.md)):

- One tool per file — file name matches `class-wp-mcp-ai-tool-{slug-with-hyphens}.php`; class name matches `WP_MCP_AI_Tool_{Studly_Slug}`.
- Every tool implements `WP_MCP_AI_Tool_Interface` from [`includes/interfaces/`](../interfaces/); shared response composition uses the local `trait-wp-mcp-ai-tool-*-response.php` traits.
- `execute()` returns the canonical success array or `WP_Error` — never `array( 'success' => false, … )`. PHPCS sniff `WPMCPAI.Tools.CanonicalReturnEnvelope` enforces this.
- Sanitise every `$arguments[…]` value at entry; escape on every output path. PHPCS sniff `WPMCPAI.Tools.SanitizeAtEntry` enforces this.
- Subdirectories carve out their own scope and have their own registration files: `orchestration/` (autonomous loops), `harness/` (reasoning harness).
- The `*_validated` variant of a tool MUST register the same slug as its non-validated sibling so the registry can swap them transparently.

## Tests

Tool tests live alongside the rest of the suite under `tests/`, named per-tool (no dedicated `tests/tools/` sub-suite — `tests/tools/.coverage-manifest.txt` only tracks coverage). Examples:

```bash
vendor/bin/phpunit tests/test-tool-registry.php
vendor/bin/phpunit tests/test-tool-create-post.php
vendor/bin/phpunit tests/test-tool-envelope-trait.php
vendor/bin/phpunit --filter '/Tool/' tests/
```

Coverage manifest: [`tests/tools/.coverage-manifest.txt`](../../tests/tools/.coverage-manifest.txt) — flags tools that still lack a dedicated unit test.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — sanitiser/escaper rules (always)
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — canonical return envelope, slug rules, capability gating
- [`.context/testing.md`](../../.context/testing.md) — how to add a tool test
- [`docs/reference/tools/tool-reference.md`](../../docs/reference/tools/tool-reference.md) — authoritative tool catalogue (live count via `WP_MCP_AI_Tool_Registry::get_tools()`)

## See Also

- Sibling surfaces: [`includes/rest/`](../rest/), [`includes/cli/`](../cli/) — the two other entry points that resolve tools by slug
- Collaborators: [`includes/validators/`](../validators/), [`includes/markup/`](../markup/), [`includes/services/`](../services/), [`includes/interfaces/`](../interfaces/), [`includes/traits/`](../traits/)
- Pro counterpart: `addons/pro/includes/tools/` (~635 additional tools)
