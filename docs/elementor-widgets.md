# Elementor widget catalogue

WP MCP AI bundles a set of Elementor widgets that cover the end-to-end assistant experience: onboarding copy, the chat interface, and operations dashboards. The plugin registers every widget automatically whenever Elementor is loaded so you can drop them into any Elementor layout without extra bootstrap code.【F:includes/class-wp-mcp-ai-elementor-integration.php†L12-L98】

## Activation requirements

The integration only spins up once Elementor finishes loading, ensuring the additional hooks stay dormant on non-Elementor sites and keeping the plugin lightweight on installs that rely on other builders.【F:includes/class-wp-mcp-ai-elementor-integration.php†L29-L46】 After Elementor fires `elementor/widgets/register`, the integration loads each widget class from `includes/elementor/` and registers it with the widgets manager.【F:includes/class-wp-mcp-ai-elementor-integration.php†L48-L98】

## Chat surface widgets

### MCP AI Chat
- Mirrors the `[mcp_ai_chat]` shortcode in Elementor, including assistant selection, guest access, and upload controls.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L17-L138】
- Use the **Allow Guest Access** switcher to mint one-hour guest tokens for public visitors; the widget automatically forwards those tokens to the REST layer just like the shortcode.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L79-L138】【F:includes/class-wp-mcp-ai-rest.php†L289-L343】

### MCP AI Chat Intro
- Provides a headline, descriptive copy, and repeater-driven talking points to set expectations above the chat UI.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-intro-widget.php†L47-L126】
- Optionally adds a call-to-action button with external/rel controls so you can point visitors to documentation or signup flows before chatting.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-intro-widget.php†L127-L207】

### MCP AI Chat FAQ
- Lets editors maintain a collapsible FAQ next to the assistant via a repeater of question/answer pairs.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-faq-widget.php†L47-L124】
- Outputs semantic `<dl>` markup on the front end for accessible formatting while skipping blank entries automatically.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-faq-widget.php†L126-L150】

### MCP AI Usage & Timer
- Blends a configurable focus timer with token usage totals gathered from the per-user usage tracker, falling back gracefully when tracking is unavailable or visitors are logged out.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-usage-timer-widget.php†L48-L340】
- Generates inline JavaScript that counts down in real time and swaps in a completion message once the timer finishes.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-usage-timer-widget.php†L252-L318】

## Assistant configuration widgets

### MCP AI Assistant Defaults
- Surfaces the assistant's provider, model, and temperature configuration directly in Elementor so editors can cross-check the chat settings against what was saved in the CPT.【F:includes/elementor/class-wp-mcp-ai-elementor-assistant-defaults-widget.php†L17-L164】
- Optional system prompt output mirrors the saved prompt text with rich formatting so onboarding pages can document tone and guardrails without copying values manually.【F:includes/elementor/class-wp-mcp-ai-elementor-assistant-defaults-widget.php†L120-L164】

### MCP AI Assistant Base Knowledge
- Lists every Media Library file attached as memory along with optional file sizes, making it easy to audit what reference material the assistant receives before each request.【F:includes/elementor/class-wp-mcp-ai-elementor-assistant-base-knowledge-widget.php†L17-L209】
- Displays the configured vector store identifier when present so teams can align external retrieval systems with on-site documentation.【F:includes/elementor/class-wp-mcp-ai-elementor-assistant-base-knowledge-widget.php†L168-L209】

### MCP AI Assistant Prompt Shortcuts
- Renders the saved shortcut labels, descriptions, and prompt payloads so stakeholders can publish ready-made task ideas next to the chat surface or in internal runbooks.【F:includes/elementor/class-wp-mcp-ai-elementor-assistant-prompt-shortcuts-widget.php†L17-L205】
- Optionally includes the associated tool name for each shortcut by querying the live tool registry, helping teams understand which integrations each prompt relies on.【F:includes/elementor/class-wp-mcp-ai-elementor-assistant-prompt-shortcuts-widget.php†L153-L205】

### MCP AI Assistant Tools
- Reuses the existing assistant tools widget to catalogue the enabled tool set alongside any missing registrations, complete with copy-to-clipboard slug actions for quick referencing.【F:includes/elementor/class-wp-mcp-ai-elementor-assistant-tools-widget.php†L17-L211】

## Operations dashboard widgets

### MCP AI Tool Matrix
- Fetches every registered tool from the tool registry, groups them by focus area, and lists the capability string required to activate each integration.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php†L48-L440】
- Pulls the Send Group Email capability and recipient cap directly from the MCP settings (including filter overrides) so the dashboard always reflects the current enforcement rules.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php†L362-L440】
- Toggle capability notes directly inside Elementor to hide or reveal the required capability column depending on your audience.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php†L66-L124】

### MCP AI User Capability Snapshot
- Summarises the current operator’s account details, roles, JetEngine access, and highlighted capabilities such as `manage_options`, `upload_files`, and `manage_woocommerce`.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-capability-widget.php†L48-L392】
- Surfaces the configured Send Group Email capability and limit in-line so reviewers can confirm whether the current user may trigger bulk emails and how many recipients are allowed.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-capability-widget.php†L180-L306】
- Supports multisite by listing every site membership the user belongs to, plus capability checks for network admins when relevant.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-capability-widget.php†L240-L330】

### MCP AI User File List
- Lists the attachments owned by a selected operator so support teams can audit knowledge files without leaving Elementor.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-files-widget.php†L17-L214】
- Supports current-user and explicit user ID modes, optional file size and upload date metadata, and custom empty states for lean dashboards.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-files-widget.php†L71-L207】

### MCP AI Theme Preview
- Renders a mock conversation using the stored chat color tokens so teams can validate branding changes without leaving Elementor.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-theme-preview-widget.php†L48-L198】
- Optional token legend outputs every token label/value combination grouped by usage to accelerate QA reviews.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-theme-preview-widget.php†L100-L198】

### MCP AI Provider Quick Links
- Calls the bundled OpenAI usage and log tools to assemble provider-specific shortcuts for billing and telemetry dashboards.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-provider-links-widget.php†L114-L162】
- Emits notices when no links are available so you can still show the card grid layout without broken anchors.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-provider-links-widget.php†L132-L154】

### MCP AI Activity Feed
- Reads recent entries from the MCP logger, covering tool executions, chat interactions, and optional remote provider request logs when enabled.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-activity-feed-widget.php†L48-L167】
- Provides expandable JSON context blocks for deeper debugging while keeping the default feed scannable.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-activity-feed-widget.php†L168-L210】

## Extending the catalogue

Each widget is loaded from `includes/elementor/` and registered via the `wp_mcp_ai_provider_links_widget_links` filter or similar hooks when extensions need to add rows, links, or bespoke output. Drop a custom PHP class in the same directory and hook into `elementor/widgets/register` to extend the catalogue further without modifying core files.【F:includes/class-wp-mcp-ai-elementor-integration.php†L48-L98】【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-provider-links-widget.php†L152-L166】
