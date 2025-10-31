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
 * Optional interface for tools that expose predefined prompt tasks.
 */
interface WP_MCP_AI_Tool_Prompts_Interface {
    /**
     * Provide prompt task metadata for this tool.
     *
     * @return array[] Array of associative arrays containing task metadata.
     */
    public function get_prompt_tasks();
}

/**
 * Legacy interface for backwards compatibility with shortcut terminology.
 */
interface WP_MCP_AI_Tool_Shortcuts_Interface extends WP_MCP_AI_Tool_Prompts_Interface {}
