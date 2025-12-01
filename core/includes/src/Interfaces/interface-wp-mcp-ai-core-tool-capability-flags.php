<?php
/**
 * Optional interface for tools that expose capability flags.
 *
 *
 * @package WP_MCP_AI_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional interface for tools that expose capability flags.
 *
 * Capability flags help orchestrate agentic workflows by providing
 * metadata about tool requirements and characteristics.
 *
 * @since 1.0.0
 */
interface WP_MCP_AI_Core_Tool_Capability_Flags_Interface {
	/**
	 * Retrieve capability flags for this tool.
	 *
	 * Standard flags include:
	 * - 'read-only': Tool only reads data
	 * - 'write': Tool creates or modifies data
	 * - 'requires-credentials': Tool needs API credentials
	 * - 'requires-plugin': Tool needs a WordPress plugin
	 * - 'external-api': Tool makes external HTTP requests
	 * - 'async': Tool may take significant time
	 *
	 * @since 1.0.0
	 *
	 * @return array<string> Array of capability flag strings.
	 */
	public function get_capability_flags();
}
