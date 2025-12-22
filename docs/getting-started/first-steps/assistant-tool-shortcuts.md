# Assistant prompt shortcuts

Assistant posts include a **Prompt Shortcuts** meta box that lets editors predefine labelled instructions, supply an optional description, and map each shortcut to a registered tool. These entries are stored as post meta and sanitised on save so only text values and valid tool slugs are persisted.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L893-L1048】【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L1740-L1805】

## Configuring shortcuts

1. Edit any assistant in **AI Assistants → All Assistants**.
2. Locate the **Prompt Shortcuts** meta box.
3. Provide a **Shortcut label** (the button text) and **Prompt text** (the instruction inserted into the chat composer).
4. Optionally fill in a **Description** to surface guidance in the chat UI and select an **Associated tool** so the shortcut is only shown when that integration is enabled for the assistant.
5. Click **Add shortcut** to create additional entries or **Remove shortcut** to delete unused prompts before updating the post.

The UI lists only the tools enabled for the assistant, keeping shortcuts relevant to the workflows you have allowed. Shortcuts are saved even when the associated tool is temporarily disabled, so re-enabling the tool later restores the prompt without additional edits.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L910-L1048】

## How shortcuts render in chat

When the `[mcp_ai_chat]` shortcode or Elementor widget loads, it merges the saved prompt shortcuts with any tasks advertised by the enabled tools. Duplicates are filtered out, a default “What can you do?” prompt is appended, and a fallback is provided when no shortcuts exist so the UI always has at least one helpful entry.【F:includes/class-wp-mcp-ai-shortcode.php†L430-L693】

Each shortcut becomes a button in the chat interface. Clicking the button inserts the prompt into the composer, focuses the textarea, and copies the content to the clipboard. If a description was supplied it is stored in the dataset, used for the button tooltip, and appended to the ARIA label so screen readers announce the extra context.【F:assets/js/chat.js†L600-L666】

## Extending or filtering shortcuts

Developers can adjust the shortcuts programmatically:

- `wp_mcp_ai_assistant_custom_tool_shortcuts` filters the saved prompts before they reach the UI, enabling site-specific defaults or conditional logic.【F:includes/class-wp-mcp-ai-shortcode.php†L464-L515】
- `wp_mcp_ai_tool_shortcut_tasks` and the slug-specific variant `wp_mcp_ai_tool_shortcut_tasks_{tool}` modify the shortcut tasks registered by each tool class.【F:includes/class-wp-mcp-ai-shortcode.php†L531-L545】
- Tools can implement `WP_MCP_AI_Tool_Fallback_Shortcut_Interface`, add a `should_register_fallback_shortcut()` method, or filter `wp_mcp_ai_tool_should_register_fallback_shortcut` to opt out of the automatic per-tool fallback button while retaining the global “What can you do?” entry.【F:includes/tools/class-wp-mcp-ai-tool-interface.php†L68-L90】【F:includes/class-wp-mcp-ai-shortcode.php†L546-L618】
- `wp_mcp_ai_default_tool_shortcut` controls the automatic “What can you do?” entry as well as the fallback when no other shortcuts exist.【F:includes/class-wp-mcp-ai-shortcode.php†L604-L688】

Combine these hooks to tailor the shortcut experience per environment, tenant, or user capability without patching the plugin.
