<?php
/**
 * Hybrid Execution Model for Tool Execution.
 *
 * Routes tool execution between client-side and server-side based on
 * tool capabilities, security requirements, and execution context.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Hybrid_Executor class.
 *
 * Determines optimal execution location (client vs server) for tools
 * and orchestrates parallel execution when possible.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Hybrid_Executor {

	/**
	 * Tools that are safe to execute client-side.
	 *
	 * These tools don't require privileged access, database operations,
	 * or sensitive data processing.
	 *
	 * @var array
	 */
	private $client_safe_tools = array(
		'client_summarize',
		'client_sentiment',
		'client_translate',
		'client_embed',
		'client_describe_image',
		'client_detect_objects',
		'client_transcribe_audio',
		'summarize_text',
		'analyze_sentiment',
		'extract_entities',
		'format_text',
		'validate_input',
		'calculate',
		'generate_html',
		'generate_chart',
		'generate_mermaid',
	);

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 */
	public function __construct() {
		/**
		 * Filter the list of client-safe tools.
		 *
		 * Allows customization of which tools can execute client-side.
		 *
		 * @since 1.2.0
		 *
		 * @param array $client_safe_tools Array of tool names safe for client execution.
		 */
		$this->client_safe_tools = apply_filters( 'wp_mcp_ai_client_safe_tools', $this->client_safe_tools );
	}

	/**
	 * Execute tools based on capability.
	 *
	 * Routes tools to client-side or server-side execution based on
	 * security requirements and execution context. Supports parallel
	 * execution of independent operations.
	 *
	 * @since 1.2.0
	 *
	 * @param array $tools     Array of tool names to execute.
	 * @param array $arguments Arguments for each tool (keyed by tool name).
	 * @param array $context   Execution context (user, capabilities, etc.).
	 * @return array Execution plan with client and server tool assignments.
	 */
	public function execute_hybrid( $tools, $arguments, $context ) {
		$client_tools = array();
		$server_tools = array();

		foreach ( $tools as $tool ) {
			if ( $this->can_run_client_side( $tool, $context ) ) {
				$client_tools[] = array(
					'name'       => $tool,
					'arguments'  => isset( $arguments[ $tool ] ) ? $arguments[ $tool ] : array(),
					'execute_on' => 'client',
				);
			} else {
				$server_tools[] = $tool;
			}
		}

		$response = array(
			'client_tools' => $client_tools,
			'server_tools' => $server_tools,
		);

		// Execute server tools if any.
		if ( ! empty( $server_tools ) ) {
			$response['server_results'] = $this->execute_server_tools(
				$server_tools,
				$arguments,
				$context
			);
		}

		return $response;
	}

	/**
	 * Check if a tool can run client-side.
	 *
	 * Determines if a tool is safe and suitable for client-side execution
	 * based on security requirements and capabilities.
	 *
	 * @since 1.2.0
	 *
	 * @param string $tool_name Tool name to check.
	 * @param array  $context   Execution context.
	 * @return bool True if tool can run client-side, false otherwise.
	 */
	private function can_run_client_side( $tool_name, $context ) {
		// Check if tool is in the client-safe list.
		if ( ! in_array( $tool_name, $this->client_safe_tools, true ) ) {
			return false;
		}

		// Additional checks can be added here, e.g., user permissions.
		// For now, rely on the client-safe list.

		/**
		 * Filter whether a specific tool can run client-side.
		 *
		 * @since 1.2.0
		 *
		 * @param bool   $can_run   Whether the tool can run client-side.
		 * @param string $tool_name Tool name.
		 * @param array  $context   Execution context.
		 */
		return apply_filters( 'wp_mcp_ai_can_run_client_side', true, $tool_name, $context );
	}

	/**
	 * Execute server-side tools.
	 *
	 * Handles execution of tools that require server-side processing,
	 * database access, or privileged operations.
	 *
	 * @since 1.2.0
	 *
	 * @param array $server_tools Array of tool names to execute server-side.
	 * @param array $arguments    Arguments for each tool.
	 * @param array $context      Execution context.
	 * @return array Execution results keyed by tool name.
	 */
	private function execute_server_tools( $server_tools, $arguments, $context ) {
		$results = array();

		// Get tool registry instance.
		$tool_registry = WP_MCP_AI_Tool_Registry::get_instance();

		foreach ( $server_tools as $tool_name ) {
			$tool = $tool_registry->get_tool( $tool_name );

			if ( ! $tool ) {
				$results[ $tool_name ] = array(
					'success' => false,
					'error'   => sprintf( 'Tool "%s" not found', $tool_name ),
				);
				continue;
			}

			$tool_arguments = isset( $arguments[ $tool_name ] ) ? $arguments[ $tool_name ] : array();

			try {
				$result = $tool->execute( $tool_arguments, $context );

				$results[ $tool_name ] = array(
					'success' => true,
					'result'  => $result,
				);
			} catch ( Exception $e ) {
				$results[ $tool_name ] = array(
					'success' => false,
					'error'   => $e->getMessage(),
				);
			}
		}

		return $results;
	}

	/**
	 * Determine if tools can be executed in parallel.
	 *
	 * Analyzes tool dependencies and determines if multiple tools
	 * can be executed simultaneously.
	 *
	 * @since 1.2.0
	 *
	 * @param array $tools Array of tool names.
	 * @return bool True if tools can run in parallel, false otherwise.
	 */
	public function can_parallel( $tools ) {
		// Simple heuristic: tools can run in parallel if they don't
		// modify the same resources. For now, assume all tools are independent.

		/**
		 * Filter whether tools can be executed in parallel.
		 *
		 * @since 1.2.0
		 *
		 * @param bool  $can_parallel Whether tools can run in parallel.
		 * @param array $tools        Array of tool names.
		 */
		return apply_filters( 'wp_mcp_ai_tools_can_parallel', true, $tools );
	}

	/**
	 * Get execution plan for tools.
	 *
	 * Returns detailed execution plan including estimated complexity
	 * and recommended execution strategy.
	 *
	 * @since 1.2.0
	 *
	 * @param array $tools   Array of tool names.
	 * @param array $context Execution context.
	 * @return array Execution plan with strategy and complexity estimate.
	 */
	public function get_execution_plan( $tools, $context ) {
		$client_tools = array();
		$server_tools = array();

		foreach ( $tools as $tool ) {
			if ( $this->can_run_client_side( $tool, $context ) ) {
				$client_tools[] = $tool;
			} else {
				$server_tools[] = $tool;
			}
		}

		return array(
			'client_tools' => $client_tools,
			'server_tools' => $server_tools,
			'can_parallel' => $this->can_parallel( $tools ),
			'strategy'     => count( $client_tools ) > count( $server_tools ) ? 'client-heavy' : 'server-heavy',
			'total_tools'  => count( $tools ),
			'client_count' => count( $client_tools ),
			'server_count' => count( $server_tools ),
		);
	}
}
