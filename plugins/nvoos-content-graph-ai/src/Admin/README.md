# Admin — AI addon admin surface

**Waves D-UI-4 + D-UI-5.** The Content Graph AI addon's admin UI: the AI
settings page/sections (with the ported settings shell), the assistant
custom post type, and the assistant builder pages. Everything here is
additive and, where the base plugin owns the same surface in monolith
installs, wired standalone-only (`! defined('WP_MCP_AI_PATH')`).

## Public surface

| File | Purpose |
|---|---|
| `AiSettingsPage.php` | AI settings page hosted inside the parent plugin's settings shell (provider selection, API keys, chat interface/settings sections); fires the public `nvoos_content_graph_ai/register_settings_sections` hook first so ecosystem consumers (platform addon, Wave E-UI) register alongside. |
| `Settings/` | The ported settings shell (Wave D-UI-5): `SettingsValidator` (base validator contract), `AiSection` (validate-then-sanitize section base), `SettingsRegistry` (facade forwarding to the parent plugin's registry — consumed, never modified). |
| `Sections/` | The settings-page sections (`ApiKeys`, `ChatInterface`, `ChatSettings`, `ProviderSelection`) — all extend `AiSection`; `ChatSettings` and `ProviderSelection` carry real validations. |
| `AssistantPostType.php` | Registers `mcp_ai_assistant` (byte-identical args to the base) + REST-visible meta + sanitizers. Standalone-only via `Plugin.php`. |
| `AssistantPages.php` | Hub registering the assistant builder submenu pages + their AJAX create actions. Standalone-only via `Plugin.php`. |
| `AssistantPages/` | The ported builder pages — Build, Add (Create), and Test Assistant (see that folder's README). |

## Neighbours

- `src/Rest/AssistantController.php` — the `mcp-ai/v1/assistants`
  directory (D5a) reads the same post type and meta keys this folder
  registers.
- `src/Frontend/ChatShortcode.php` + `src/Blocks/` — the chat widget and
  blocks the builder pages embed.

## Context files

Load alongside this folder: `.context/tool-registry.md`, the folder
convention note in `docs/developer/folder-readme-convention.md`, and the
ecosystem port tracker `docs/project/ecosystem-port-tracker.md` (Wave
D-UI rows).
