<?php
/**
 * Optional interface for NV oOS tools that self-declare as WordPress Abilities.
 *
 * Tools implementing this interface are automatically registered as Abilities
 * by the Ability Registrar. This is additive — tools without this interface
 * continue to work through the existing custom tool registry.
 *
 * @package WP_MCP_AI
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional ability interface for tool providers.
 *
 * @since 2.0.0
 */
interface WP_MCP_AI_Tool_Ability_Interface {

	/**
	 * Get the ability identifier (without namespace prefix).
	 *
	 * Example: 'get-post' is registered as 'nvoos/get-post'.
	 *
	 * @return string Ability name in kebab-case.
	 */
	public function get_ability_identifier();

	/**
	 * Get the ability category slug.
	 *
	 * Must match a category registered via wp_register_ability_category().
	 *
	 * @return string Category slug.
	 */
	public function get_ability_category();

	/**
	 * Get the JSON Schema for the ability's output.
	 *
	 * Return an empty array to fall back to the generic canonical envelope
	 * schema provided by the bridge.
	 *
	 * @return array JSON Schema array.
	 */
	public function get_output_schema();

	/**
	 * Whether this ability should be publicly exposed via the MCP adapter.
	 *
	 * Sets meta.mcp.public on the ability registration. Defaults to true
	 * for discovery tools; set to false for internal-only abilities.
	 *
	 * @return bool True if the ability should be MCP-public.
	 */
	public function is_public_ability();
}
