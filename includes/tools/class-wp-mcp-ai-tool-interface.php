<?php
/**
 * Interface that all MCP AI tools must implement.
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
