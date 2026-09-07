<?php
/**
 * Toolkit MCP Server Interface (ecosystem port — Wave F2, MCP toolkit-server core slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/mcp-servers/interface-wp-mcp-ai-toolkit-server.php`
 * for the standalone `nvoos-content-graph-pro` addon. Kept
 * byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`. The base-owned seams (`WP_MCP_AI_Tool_Registry`,
 * `WP_MCP_AI_REST_Authenticator`, `WP_MCP_AI_Assistant_CPT`, the
 * metabox helper) stay served by the root classmap behind the
 * byte-identical `class_exists` guards — not copied.
 *
 * @package NvoosContentGraphPro
 * @since 1.2.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface contract for a per-toolkit MCP server.
 *
 * Implementations live in `addons/pro/includes/mcp-servers/servers/` and are
 * registered via the `wp_mcp_ai_register_toolkit_servers` action. Most servers
 * should extend `WP_MCP_AI_Toolkit_Server_Base` rather than implement this
 * interface directly.
 */
interface WP_MCP_AI_Toolkit_Server_Interface {

	/**
	 * Stable, kebab-case slug used in REST routes and option keys.
	 *
	 * Example: 'crm', 'health', 'architectural-design'.
	 *
	 * @return string
	 */
	public function get_slug();

	/**
	 * Human-readable, translated server name.
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * One-paragraph description of the server's domain and capabilities.
	 *
	 * @return string
	 */
	public function get_description();

	/**
	 * Server semantic version. Used in the discovery descriptor.
	 *
	 * @return string
	 */
	public function get_version();

	/**
	 * Native ingestion surfaces owned by this toolkit.
	 *
	 * Each surface descriptor MUST contain:
	 * - 'type'              : 'research_add' | 'consolidate_add'.
	 * - 'page_slug'         : Admin page slug (e.g. 'company-research').
	 * - 'entity_type'       : CPT/CCT slug the surface operates on.
	 * - 'class_ref'         : Fully-qualified class name of the page.
	 * - 'bound_assistant_id': Assistant post ID driving the surface (optional, may be 0).
	 * - 'label'             : Human-readable surface label.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces();

	/**
	 * Foreign ingestion surfaces this server mounts read-only.
	 *
	 * Each entry mirrors `ingestion_surfaces()` plus:
	 * - 'source_toolkit_slug': Slug of the toolkit that owns the surface.
	 * - 'read_only'          : Always true.
	 *
	 * The owning toolkit retains write authority; mounted surfaces appear in
	 * `resources/list` and `prompts/list` under a `_mounted/` namespace.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function mounted_surfaces();

	/**
	 * Candidate tool slugs this toolkit can expose via tools/list.
	 *
	 * The framework intersects this with the admin-configured allowlist before
	 * surfacing tools to MCP clients.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs();

	/**
	 * Whether this server is currently enabled by the site administrator.
	 *
	 * Disabled servers respond to discovery requests with `enabled: false` and
	 * reject JSON-RPC method calls with -32601 (method not found).
	 *
	 * @return bool
	 */
	public function is_enabled();
}
