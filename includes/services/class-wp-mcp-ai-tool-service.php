<?php
/**
 * Tool Service
 *
 * Handles tool execution and validation.
 * Extracted from WP_MCP_AI_REST as part of service layer refactoring.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool Service class
 *
 * Responsible for:
 * - Tool execution workflows
 * - Tool validation
 * - Tool payload building
 * - Tool capability checking
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Service {

	/**
	 * Tool Registry instance
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Tool Execution Orchestrator instance
	 *
	 * @var WP_MCP_AI_Tool_Execution_Orchestrator|null
	 */
	private $orchestrator;

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Tool_Registry $registry Tool registry.
	 */
	public function __construct( WP_MCP_AI_Tool_Registry $registry ) {
		$this->registry     = $registry;
		$this->orchestrator = null; // Lazy loaded.
	}

	/**
	 * Execute a tool
	 *
	 * @param string $tool_name Tool name/slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return mixed|WP_Error Tool result or error.
	 */
	public function execute_tool( $tool_name, $arguments, $context = array() ) {
		// Validate tool exists.
		if ( ! $this->registry->is_tool_registered( $tool_name ) ) {
			return new WP_Error(
				'wp_mcp_ai_tool_not_found',
				sprintf(
					/* translators: %s: tool name */
					__( 'Tool "%s" not found.', 'wp-mcp-ai' ),
					$tool_name
				),
				array( 'status' => 404 )
			);
		}

		// Check if user has required capability for tool.
		$tool_capability = $this->registry->get_tool_capability( $tool_name );
		if ( $tool_capability && ! current_user_can( $tool_capability ) ) {
			return new WP_Error(
				'wp_mcp_ai_tool_permission_denied',
				sprintf(
					/* translators: %s: tool name */
					__( 'You do not have permission to use the "%s" tool.', 'wp-mcp-ai' ),
					$tool_name
				),
				array( 'status' => 403 )
			);
		}

		// Execute tool via orchestrator (handles sync/async routing).
		try {
			$orchestrator = $this->get_orchestrator();
			$result       = $orchestrator->execute_tool( $tool_name, $arguments, $context );

			// Log successful execution.
			WP_MCP_AI_Logger::log_event(
				'tool_executed',
				sprintf( 'Tool "%s" executed successfully', $tool_name ),
				array(
					'tool'         => $tool_name,
					'assistant_id' => $context['assistant_id'] ?? null,
					'user_id'      => get_current_user_id(),
				)
			);

			return $result;

		} catch ( Exception $e ) {
			// Log error.
			WP_MCP_AI_Logger::log_error(
				sprintf( 'Tool execution failed: %s', $e->getMessage() ),
				array(
					'tool'      => $tool_name,
					'exception' => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				)
			);

			return new WP_Error(
				'wp_mcp_ai_tool_execution_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Build tools payload for LLM
	 *
	 * Converts assistant tool configuration to LLM-compatible format.
	 *
	 * @param array $assistant_config Assistant configuration.
	 * @return array|WP_Error Tools payload or error.
	 */
	public function build_tools_payload( $assistant_config ) {
		$enabled_tools = array();

		if ( isset( $assistant_config['tools'] ) && is_array( $assistant_config['tools'] ) ) {
			$enabled_tools = $assistant_config['tools'];
		}

		if ( empty( $enabled_tools ) ) {
			return array(); // No tools enabled.
		}

		$tools_payload = array();

		foreach ( $enabled_tools as $tool_slug ) {
			$tool_definition = $this->registry->get_tool_definition( $tool_slug );

			if ( ! $tool_definition ) {
				continue; // Skip if tool not found.
			}

			// Convert to OpenAI function calling format.
			$tools_payload[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => $tool_slug,
					'description' => $tool_definition['description'] ?? '',
					'parameters'  => $tool_definition['parameters'] ?? array(
						'type'       => 'object',
						'properties' => array(),
					),
				),
			);
		}

		return $tools_payload;
	}

	/**
	 * Get list of available tools
	 *
	 * @param int|null $assistant_id Optional assistant ID to filter by capability.
	 * @return array List of tools.
	 */
	public function get_available_tools( $assistant_id = null ) {
		$all_tools = $this->registry->get_tools();
		$tools     = array();

		foreach ( $all_tools as $tool ) {
			if ( ! is_object( $tool ) || ! method_exists( $tool, 'get_slug' ) ) {
				continue;
			}

			$slug = $tool->get_slug();

			// Check capability if the tool has one.
			$capability = $this->registry->get_tool_capability( $slug );
			if ( $capability && ! current_user_can( $capability ) ) {
				continue; // User doesn't have required capability.
			}

			$tools[] = array(
				'slug'        => $slug,
				'name'        => method_exists( $tool, 'get_name' ) ? $tool->get_name() : $slug,
				'description' => method_exists( $tool, 'get_description' ) ? $tool->get_description() : '',
				'category'    => 'general', // Default category, can be enhanced later.
			);
		}

		return $tools;
	}

	/**
	 * Validate tool arguments
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_tool_arguments( $tool_name, $arguments ) {
		$tool_definition = $this->registry->get_tool_definition( $tool_name );

		if ( ! $tool_definition ) {
			return new WP_Error(
				'wp_mcp_ai_tool_not_found',
				sprintf(
					/* translators: %s: tool name */
					__( 'Tool "%s" not found.', 'wp-mcp-ai' ),
					$tool_name
				)
			);
		}

		// Get required parameters.
		$parameters = $tool_definition['parameters'] ?? array();
		$required   = $parameters['required'] ?? array();

		// Check required arguments are present.
		foreach ( $required as $param ) {
			if ( ! isset( $arguments[ $param ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_tool_argument',
					sprintf(
						/* translators: 1: parameter name, 2: tool name */
						__( 'Missing required argument "%1$s" for tool "%2$s".', 'wp-mcp-ai' ),
						$param,
						$tool_name
					)
				);
			}
		}

		return true;
	}

	/**
	 * Check if tool is enabled for assistant
	 *
	 * @param string $tool_slug    Tool slug.
	 * @param int    $assistant_id Assistant ID.
	 * @return bool True if enabled.
	 */
	public function is_tool_enabled_for_assistant( $tool_slug, $assistant_id ) {
		if ( ! $assistant_id ) {
			return false;
		}

		$config        = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		$enabled_tools = $config['tools'] ?? array();

		return in_array( $tool_slug, $enabled_tools, true );
	}

	/**
	 * Get tool execution statistics
	 *
	 * @param string   $tool_slug    Tool slug.
	 * @param int|null $assistant_id Optional assistant ID.
	 * @return array Tool statistics.
	 */
	public function get_tool_statistics( $tool_slug, $assistant_id = null ) {
		// This would integrate with usage tracking.
		// For now, return placeholder.
		return array(
			'tool'            => $tool_slug,
			'execution_count' => 0,
			'success_count'   => 0,
			'error_count'     => 0,
		);
	}

	/**
	 * Get tool execution orchestrator instance (lazy loaded)
	 *
	 * @return WP_MCP_AI_Tool_Execution_Orchestrator
	 */
	private function get_orchestrator() {
		if ( null === $this->orchestrator ) {
			if ( ! class_exists( 'WP_MCP_AI_Tool_Execution_Orchestrator' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-execution-orchestrator.php';
			}
			$this->orchestrator = new WP_MCP_AI_Tool_Execution_Orchestrator( $this->registry );
		}

		return $this->orchestrator;
	}
}
