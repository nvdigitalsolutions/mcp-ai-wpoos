# `includes/Tools/` — MCP tool classes

Classes here live in the **global namespace** (`WP_MCP_AI_Tool_*`) and are loaded
explicitly by the tool registry from an absolute path, so they are intentionally
outside the addon's PSR-4 namespace.

## Public surface

| File | Slug | Capability | Phase |
|---|---|---|---|
| `class-wp-mcp-ai-tool-wifi-health-check.php` | `wifi_health_check` | `manage_options` | 0 |

## Registration

Registered via the **Base** filter `wp_mcp_ai_default_tools` — shape
`array<class_name => absolute_file_path>` — in `Plugin::register_tools()`.

The filter is added at **file scope** in `nvoos-wifi.php`, not on `plugins_loaded`,
because the registry may consume it before a late callback runs. Missing this is the
number-one "my tools don't appear" cause; the base plugin's own
`includes/tools-init.php` uses the same early-registration pattern.

Because this is a Base filter, WiFi tools work **without a Pro licence**
(Proposal 150 decision D-6). The Pro console integration is a separate, optional
manifest via `wp_mcp_ai_pro_toolkit_manifests`.

## Tool obligations (sniff-enforced at severity 5)

1. `execute()` returns the canonical envelope — a `'success' => true` array, or a
   `WP_Error`. **Never** `array( 'success' => false, … )`
   (`WPMCPAI.Tools.CanonicalReturnEnvelope`).
2. **Two-gate sanitisation:** sanitise every `$arguments[...]` at entry, escape
   every value at exit (`WPMCPAI.Tools.SanitizeAtEntry`).
3. Declare usage guidance (`WPMCPAI.Tools.ToolDescriptionGuidance`).
4. Every method carries a docblock.
5. Schema enums are built as `array_map( 'strval', array_keys( … ) )`, never
   hand-written string lists.

## Neighbours

- **Calls** `../Plugin` (composition root) and `../Infrastructure/Wp/Class_Result_Mapper`.
- **Discovered by** the base tool registry.

## Context files

`.context/tool-registry.md` · `.context/conventions.md`
