<?php
/**
 * Interface that all WP MCP AI Core tools must implement.
 *
 *
 * @package WP_MCP_AI_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool interface for MCP Core tools.
 *
 * This interface defines the contract that all tools must implement
 * to be registered with the MCP server.
 *
 * @since 1.0.0
 */
interface WP_MCP_AI_Core_Tool_Interface {
	/**
	 * Unique slug for the tool.
	 *
	 * The slug is used to identify the tool in API calls and should be
	 * URL-safe (lowercase letters, numbers, underscores, dashes).
	 *
	 * @since 1.0.0
	 *
	 * @return string Tool slug.
	 */
	public function get_slug();

	/**
	 * Human-readable name for the tool.
	 *
	 * @since 1.0.0
	 *
	 * @return string Tool name.
	 */
	public function get_name();

	/**
	 * Description of what the tool does.
	 *
	 * This description is used by AI models to understand when to use the tool.
	 * It should be clear and concise.
	 *
	 * @since 1.0.0
	 *
	 * @return string Tool description.
	 */
	public function get_description();

	/**
	 * JSON Schema describing accepted parameters.
	 *
	 * Returns a JSON Schema (draft-07) compliant object describing
	 * the tool's input parameters.
	 *
	 * Example:
	 * ```php
	 * return array(
	 *     'type'       => 'object',
	 *     'properties' => array(
	 *         'post_id' => array(
	 *             'type'        => 'integer',
	 *             'description' => 'The post ID to retrieve',
	 *         ),
	 *     ),
	 *     'required' => array( 'post_id' ),
	 * );
	 * ```
	 *
	 * @since 1.0.0
	 *
	 * @return array JSON Schema for parameters.
	 */
	public function get_parameters_schema();

	/**
	 * Execute the tool with supplied arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array $arguments Parsed arguments from the assistant.
	 * @param array $context   Contextual data about the request.
	 *                         May include 'user_id', 'assistant_id', etc.
	 * @return mixed|WP_Error Tool result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() );
}
