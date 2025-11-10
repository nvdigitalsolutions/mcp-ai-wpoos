<?php
/**
 * Interface that all WP oOS tools must implement.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared interface for tool providers.
 */
interface WP_MCP_AI_Tool_Interface {
	/**
	 * Unique slug for the tool.
	 *
	 * @return string
	 */
	public function get_slug();

	/**
	 * Human readable name for the tool.
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Description of what the tool does.
	 *
	 * @return string
	 */
	public function get_description();

	/**
	 * JSON schema describing accepted parameters.
	 *
	 * @return array
	 */
	public function get_parameters_schema();

	/**
	 * Execute the tool with supplied arguments.
	 *
	 * @param array $arguments Parsed arguments from the assistant.
	 * @param array $context   Contextual data about the request.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() );
}

/**
 * Optional interface for tools that expose predefined shortcut tasks.
 */
interface WP_MCP_AI_Tool_Shortcuts_Interface {
	/**
	 * Provide shortcut task metadata for this tool.
	 *
	 * Returning `null` signals that the tool does not expose any predefined
	 * shortcuts and that the shortcode renderer should not add fallback
	 * buttons automatically.
	 *
	 * @return array[]|null Array of associative arrays containing task metadata
	 *                      or null to opt out of automatic shortcut creation.
	 */
	public function get_shortcut_tasks();
}

/**
 * Optional interface for tools that want to control automatic fallback shortcuts.
 */
interface WP_MCP_AI_Tool_Fallback_Shortcut_Interface {
	/**
	 * Decide whether a fallback shortcut should be registered automatically.
	 *
	 * Returning false opts the tool out of the generic fallback entry that
	 * mirrors the tool slug, while still allowing the global "What can you do?"
	 * shortcut to be appended later in the process.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return bool
	 */
	public function should_register_fallback_shortcut( $assistant_id );
}

/**
 * Optional interface for tools that expose capability flags for orchestration.
 *
 * Capability flags provide metadata beyond grouping to help orchestrate
 * agentic workflows without errors by identifying tool requirements and
 * characteristics.
 */
interface WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Retrieve capability flags for this tool.
	 *
	 * Capability flags help orchestrate agentic workflows by providing
	 * metadata about tool requirements and characteristics.
	 *
	 * Standard flags:
	 * - 'requires-credentials': Tool requires external API credentials
	 * - 'requires-plugin': Tool requires a specific WordPress plugin
	 * - 'local-only': Tool only works locally (no external API calls)
	 * - 'read-only': Tool only reads data, does not modify state
	 * - 'write': Tool modifies data or state
	 * - 'async': Tool may take significant time to complete
	 * - 'rate-limited': Tool is subject to rate limiting
	 * - 'cacheable': Tool results can be cached
	 *
	 * @return array<string> Array of capability flag strings.
	 */
	public function get_capability_flags();
}
