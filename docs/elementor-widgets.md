# Elementor widget catalogue

WP oOS bundles a set of Elementor widgets that cover the end-to-end assistant experience: onboarding copy, the chat interface, and operations dashboards. The plugin registers every widget automatically whenever Elementor is loaded so you can drop them into any Elementor layout without extra bootstrap code.【F:includes/class-wp-mcp-ai-elementor-integration.php†L12-L98】

## Activation requirements

The integration only spins up once Elementor finishes loading, ensuring the additional hooks stay dormant on non-Elementor sites and keeping the plugin lightweight on installs that rely on other builders.【F:includes/class-wp-mcp-ai-elementor-integration.php†L29-L46】 After Elementor fires `elementor/widgets/register`, the integration loads each widget class from `includes/elementor/` and registers it with the widgets manager.【F:includes/class-wp-mcp-ai-elementor-integration.php†L48-L98】

**Widget Activation Control**: Widgets can be enabled or disabled via the "Enable Elementor Widgets" checkbox in **Settings → Elementor** (or **WP oOS → Elementor** in the new dashboard). This setting defaults to enabled for backward compatibility. When disabled, no Elementor widgets are registered even if Elementor is active.【F:wp-mcp-ai.php†L545-L554】 See [ELEMENTOR-WIDGET-ACTIVATION-FIX.md](ELEMENTOR-WIDGET-ACTIVATION-FIX.md) for details.

## Chat surface widgets

### WP oOS Chat
- Mirrors the `[mcp_ai_chat]` shortcode in Elementor, including assistant selection, guest access, and upload controls.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L60-L92】【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L428-L474】
- Toggle optional panels to surface the assistant's saved defaults, memory files, tool assignments, and prompt shortcuts directly above the chat interface with per-section empty states and formatting controls.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L95-L845】
- Use the **Allow Guest Access** switcher to mint one-hour guest tokens for public visitors; the widget automatically forwards those tokens to the REST layer just like the shortcode.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L80-L92】【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L441-L444】【F:includes/class-wp-mcp-ai-rest.php†L289-L343】

### WP oOS Chat Intro
- Provides a headline, descriptive copy, and repeater-driven talking points to set expectations above the chat UI.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-intro-widget.php†L47-L126】
- Optionally adds a call-to-action button with external/rel controls so you can point visitors to documentation or signup flows before chatting.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-intro-widget.php†L127-L207】

### WP oOS Chat FAQ
- Lets editors maintain a collapsible FAQ next to the assistant via a repeater of question/answer pairs.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-faq-widget.php†L47-L124】
- Outputs semantic `<dl>` markup on the front end for accessible formatting while skipping blank entries automatically.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-faq-widget.php†L126-L150】

### WP oOS Usage & Timer
- Blends a configurable focus timer with token usage totals gathered from the per-user usage tracker, falling back gracefully when tracking is unavailable or visitors are logged out.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-usage-timer-widget.php†L48-L340】
- Generates inline JavaScript that counts down in real time and swaps in a completion message once the timer finishes.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-usage-timer-widget.php†L252-L318】

## Assistant configuration widgets

The chat widget now surfaces saved defaults, memory, tools, and prompt shortcuts inline, eliminating the need for separate cards when you want everything contextualised around the chat surface.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L95-L845】

### WP oOS Assistant Tools
- Reuses the existing assistant tools widget to catalogue the enabled tool set alongside any missing registrations, complete with copy-to-clipboard slug actions for quick referencing.【F:includes/elementor/class-wp-mcp-ai-elementor-assistant-tools-widget.php†L17-L211】

## Operations dashboard widgets

### WP oOS Tool Matrix
- Fetches every registered tool from the tool registry, groups them by focus area, and lists the capability string required to activate each integration.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php†L48-L440】
- Pulls the Send Group Email capability and recipient cap directly from the MCP settings (including filter overrides) so the dashboard always reflects the current enforcement rules.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php†L362-L440】
- Toggle capability notes directly inside Elementor to hide or reveal the required capability column depending on your audience.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php†L66-L124】

### WP oOS User Capability Snapshot
- Summarises the current operator’s account details, roles, JetEngine access, and highlighted capabilities such as `manage_options`, `upload_files`, and `manage_woocommerce`.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-capability-widget.php†L48-L392】
- Surfaces the configured Send Group Email capability and limit in-line so reviewers can confirm whether the current user may trigger bulk emails and how many recipients are allowed.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-capability-widget.php†L180-L306】
- Supports multisite by listing every site membership the user belongs to, plus capability checks for network admins when relevant.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-capability-widget.php†L240-L330】

### WP oOS User File List
- Lists the attachments owned by a selected operator so support teams can audit knowledge files without leaving Elementor.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-files-widget.php†L17-L214】
- Supports current-user and explicit user ID modes, optional file size and upload date metadata, and custom empty states for lean dashboards.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-files-widget.php†L71-L207】

### WP oOS Theme Preview
- Renders a mock conversation using the stored chat color tokens so teams can validate branding changes without leaving Elementor.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-theme-preview-widget.php†L48-L198】
- Optional token legend outputs every token label/value combination grouped by usage to accelerate QA reviews.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-theme-preview-widget.php†L100-L198】

### WP oOS Provider Quick Links
- Calls the bundled OpenAI usage and log tools to assemble provider-specific shortcuts for billing and telemetry dashboards.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-provider-links-widget.php†L114-L162】
- Emits notices when no links are available so you can still show the card grid layout without broken anchors.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-provider-links-widget.php†L132-L154】

### WP oOS Activity Feed
- Reads recent entries from the MCP logger, covering tool executions, chat interactions, and optional remote provider request logs when enabled.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-activity-feed-widget.php†L48-L167】
- Provides expandable JSON context blocks for deeper debugging while keeping the default feed scannable.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-activity-feed-widget.php†L168-L210】

## Extending the catalogue

Each widget is loaded from `includes/elementor/` and registered via the `wp_mcp_ai_provider_links_widget_links` filter or similar hooks when extensions need to add rows, links, or bespoke output. Drop a custom PHP class in the same directory and hook into `elementor/widgets/register` to extend the catalogue further without modifying core files.【F:includes/class-wp-mcp-ai-elementor-integration.php†L48-L98】【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-provider-links-widget.php†L152-L166】
