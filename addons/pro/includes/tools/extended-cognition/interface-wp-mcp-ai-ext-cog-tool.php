<?php
/**
 * Extended Cognition Tool Interface
 *
 * Defines the contract that every Extended Cognition tool must satisfy.
 * The tool registry uses this interface to duck-type tools regardless
 * of whether they extend a shared base class.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for Extended Cognition tool classes.
 *
 * Every tool MUST implement get_slug(), get_name(), get_description(),
 * get_required_capability(), get_definition(), and execute().  The
 * trait WP_MCP_AI_Ext_Cog_Sensor_Access provides the shared permission
 * check; tools that need it should `use` the trait.
 *
 * @since 1.8.1
 */
interface WP_MCP_AI_Ext_Cog_Tool_Interface {

	/**
	 * Get the unique tool slug.
	 *
	 * @return string
	 */
	public function get_slug();

	/**
	 * Get the human-readable tool name.
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Get the tool description (shown to AI assistants).
	 *
	 * @return string
	 */
	public function get_description();

	/**
	 * Get the required WordPress capability.
	 *
	 * @return string
	 */
	public function get_required_capability();

	/**
	 * Get the full tool definition array (name, description, input_schema, etc.).
	 *
	 * @return array
	 */
	public function get_definition();

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments (already sanitized by the registry).
	 * @param array $context   Execution context (user_id, guest_request, etc.).
	 * @return array|WP_Error Canonical envelope — success array or WP_Error.
	 */
	public function execute( array $arguments = array(), array $context = array() );
}
