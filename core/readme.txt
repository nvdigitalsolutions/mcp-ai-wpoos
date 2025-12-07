=== WP MCP AI Core ===
Contributors: nvdigitalsolutions
Tags: mcp, ai, assistant, tools, wordpress
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Core MCP (Model Context Protocol) server framework for WordPress. Provides a stable API for AI tool integration.

== Description ==

WP MCP AI Core is an open-source MCP server implementation for WordPress. It provides a stable foundation for AI assistant integrations, allowing WordPress to act as an MCP server that exposes tools for AI models to interact with.

= Key Features =

* **MCP Server Engine** - Full MCP protocol implementation with REST API endpoints
* **Tool Registry** - Extensible tool registration system for AI assistants
* **Baseline Tools** - Posts, Media, Users, and Taxonomies tools included
* **Authentication** - Flexible auth hooks for custom authentication schemes
* **Rate Limiting** - Filterable rate limiting for API protection
* **Extension API** - Stable public API for add-ons and third-party plugins

= For Developers =

Register custom tools using the stable public API:

`
add_action( 'wp_mcp_ai_register_tools', function( $server ) {
    $server->register_tool( new My_Custom_Tool() );
});
`

Implement the `WP_MCP_AI_Core_Tool_Interface` to create your own tools:

`
class My_Custom_Tool implements WP_MCP_AI_Core_Tool_Interface {
    public function get_slug() { return 'my_tool'; }
    public function get_name() { return 'My Tool'; }
    public function get_description() { return 'Does something useful.'; }
    public function get_parameters_schema() { return array(); }
    public function execute( array $arguments = array(), array $context = array() ) {
        // Your tool logic here
        return array( 'result' => 'success' );
    }
}
`

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-mcp-ai-core/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Configure settings via Settings > WP MCP AI Core (if available)

== Frequently Asked Questions ==

= What is MCP? =

MCP (Model Context Protocol) is a standard protocol for AI assistants to interact with external tools and services. This plugin makes your WordPress site an MCP server.

= What tools are included? =

The Core plugin includes baseline tools for:
* Posts - Query, create, update, and delete posts
* Media - List, search, and upload media files
* Users - Query user information (respects capabilities)
* Taxonomies - List taxonomies and terms

= Can I create custom tools? =

Yes! Implement the `WP_MCP_AI_Core_Tool_Interface` and register your tool using the `wp_mcp_ai_register_tools` action hook.

= Is this plugin secure? =

Yes. All API endpoints require authentication, tool execution respects WordPress capabilities, and rate limiting is built-in. Additional security features are available in Pro add-ons.

== Screenshots ==

1. Tool registry showing registered tools
2. REST API endpoint documentation
3. Sample tool execution

== Changelog ==

= 1.0.0 =
* Initial release
* MCP server engine
* Baseline tools (Posts, Media, Users, Taxonomies)
* REST API endpoints
* Extension API for add-ons

== Upgrade Notice ==

= 1.0.0 =
Initial release.

== Privacy Policy ==

**WP MCP AI Core** does not collect, store, or transmit any data externally.

= What Data Does This Plugin Handle? =

**Locally Stored (Your WordPress Database):**
* Plugin configuration settings
* Tool registry and definitions
* API endpoint configurations

**No External Communication:**
* No analytics or telemetry
* No tracking scripts or cookies
* No phone-home functionality
* All data remains on your WordPress installation

= Integration with AI Services =

This plugin provides a framework only. When you integrate AI services (like OpenAI, Google Gemini, or others) through extensions or custom code, those integrations may transmit data to external services. Review the privacy policies of any AI providers you choose to integrate.

= GDPR Compliance =

The core plugin:
* Stores no personal data
* Makes no external API calls
* Processes only data you explicitly configure
* Provides hooks for extensions to implement GDPR controls

**You are responsible for** reviewing the privacy implications of any extensions, add-ons, or AI service integrations you configure.
