<?php
/**
 * Optional interface for tools that define specific execution rules.
 *
 *
 * @package WP_MCP_AI_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional interface for tools that define specific execution rules.
 *
 * @since 1.0.0
 */
interface WP_MCP_AI_Core_Tool_Rules_Interface {
	/**
	 * Retrieve tool-specific execution rules.
	 *
	 * Rules can define model requirements, parameter constraints,
	 * rate limits, timeout constraints, and dependencies.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of tool-specific rules.
	 */
	public function get_tool_rules();
}
