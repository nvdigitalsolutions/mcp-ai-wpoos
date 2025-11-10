<?php
/**
 * JSON-RPC 2.0 Batch Request Handler
 *
 * Implements batch processing for MCP protocol requests.
 * Proof of concept for modernization roadmap Phase 2.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Batch_Handler class
 *
 * Processes multiple JSON-RPC requests in a single HTTP call.
 * Maintains request/response correlation and proper error isolation.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Batch_Handler {

	/**
	 * MCP REST controller instance
	 *
	 * @var WP_MCP_AI_REST
	 */
	private $rest_controller;

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_REST $rest_controller REST controller instance.
	 */
	public function __construct( $rest_controller = null ) {
		$this->rest_controller = $rest_controller;
	}

	/**
	 * Process a batch of JSON-RPC 2.0 requests
	 *
	 * @param array $requests Array of JSON-RPC request objects.
	 * @return array Array of JSON-RPC response objects.
	 */
	public function process_batch( $requests ) {
		// Validate batch format.
		if ( ! is_array( $requests ) || empty( $requests ) ) {
			return array(
				'jsonrpc' => '2.0',
				'error'   => array(
					'code'    => -32600,
					'message' => 'Invalid Request: Batch must be a non-empty array',
				),
				'id'      => null,
			);
		}

		$responses = array();

		foreach ( $requests as $index => $request ) {
			try {
				// Validate individual request.
				$validation_error = $this->validate_request( $request );
				if ( $validation_error ) {
					$responses[] = $validation_error;
					continue;
				}

				// Process the request.
				$result = $this->process_single_request( $request );

				// Build successful response.
				$responses[] = array(
					'jsonrpc' => '2.0',
					'id'      => $request['id'] ?? null,
					'result'  => $result,
				);

			} catch ( Exception $e ) {
				// Build error response.
				$responses[] = array(
					'jsonrpc' => '2.0',
					'id'      => $request['id'] ?? null,
					'error'   => array(
						'code'    => $e->getCode() ?: -32603,
						'message' => $e->getMessage(),
						'data'    => array(
							'request_index' => $index,
						),
					),
				);
			}
		}

		return $responses;
	}

	/**
	 * Validate a JSON-RPC 2.0 request
	 *
	 * @param mixed $request Request to validate.
	 * @return array|null Error response if invalid, null if valid.
	 */
	private function validate_request( $request ) {
		// Must be an object/array.
		if ( ! is_array( $request ) ) {
			return array(
				'jsonrpc' => '2.0',
				'error'   => array(
					'code'    => -32600,
					'message' => 'Invalid Request: Request must be an object',
				),
				'id'      => null,
			);
		}

		// Must have jsonrpc version.
		if ( ! isset( $request['jsonrpc'] ) || '2.0' !== $request['jsonrpc'] ) {
			return array(
				'jsonrpc' => '2.0',
				'error'   => array(
					'code'    => -32600,
					'message' => 'Invalid Request: jsonrpc version must be "2.0"',
				),
				'id'      => $request['id'] ?? null,
			);
		}

		// Must have method.
		if ( ! isset( $request['method'] ) || ! is_string( $request['method'] ) ) {
			return array(
				'jsonrpc' => '2.0',
				'error'   => array(
					'code'    => -32600,
					'message' => 'Invalid Request: method is required and must be a string',
				),
				'id'      => $request['id'] ?? null,
			);
		}

		// Params optional but must be array if present.
		if ( isset( $request['params'] ) && ! is_array( $request['params'] ) ) {
			return array(
				'jsonrpc' => '2.0',
				'error'   => array(
					'code'    => -32600,
					'message' => 'Invalid Request: params must be an array if provided',
				),
				'id'      => $request['id'] ?? null,
			);
		}

		return null;
	}

	/**
	 * Process a single JSON-RPC request
	 *
	 * @param array $request Validated JSON-RPC request.
	 * @return mixed Request result.
	 * @throws Exception If method not found or execution fails.
	 */
	private function process_single_request( $request ) {
		$method = $request['method'];
		$params = $request['params'] ?? array();

		// Route to appropriate handler based on method.
		switch ( $method ) {
			case 'initialize':
				return $this->handle_initialize( $params );

			case 'tools/list':
				return $this->handle_tools_list( $params );

			case 'tools/call':
				return $this->handle_tools_call( $params );

			case 'resources/list':
				return $this->handle_resources_list( $params );

			case 'prompts/list':
				return $this->handle_prompts_list( $params );

			default:
				throw new Exception(
					sprintf( 'Method not found: %s', $method ),
					-32601
				);
		}
	}

	/**
	 * Handle initialize method
	 *
	 * @param array $params Request parameters.
	 * @return array Initialization response.
	 */
	private function handle_initialize( $params ) {
		return array(
			'protocolVersion' => '2024-11-05',
			'capabilities'    => array(
				'tools'     => array(
					'listChanged' => true,
				),
				'resources' => array(
					'subscribe'   => true,
					'listChanged' => true,
				),
				'prompts'   => array(
					'listChanged' => true,
				),
				'logging'   => array(),
			),
			'serverInfo'      => array(
				'name'    => 'WP Open Operator System',
				'version' => WP_MCP_AI_VERSION,
			),
		);
	}

	/**
	 * Handle tools/list method
	 *
	 * @param array $params Request parameters.
	 * @return array Tools list response.
	 */
	private function handle_tools_list( $params ) {
		$registry = wp_mcp_ai_get_tool_registry();
		$tools    = array();

		foreach ( $registry->get_all_tools() as $slug => $tool_instance ) {
			$definition = $tool_instance->get_definition();

			$tools[] = array(
				'name'        => $slug,
				'description' => $definition['description'] ?? '',
				'inputSchema' => $definition['parameters'] ?? array(
					'type'       => 'object',
					'properties' => array(),
				),
			);
		}

		return array(
			'tools' => $tools,
		);
	}

	/**
	 * Handle tools/call method
	 *
	 * @param array $params Request parameters.
	 * @return array Tool execution result.
	 * @throws Exception If tool execution fails.
	 */
	private function handle_tools_call( $params ) {
		if ( ! isset( $params['name'] ) ) {
			throw new Exception( 'Tool name is required', -32602 );
		}

		$tool_name = sanitize_key( $params['name'] );
		$arguments = $params['arguments'] ?? array();

		$registry = wp_mcp_ai_get_tool_registry();
		$tool     = $registry->get_tool( $tool_name );

		if ( ! $tool ) {
			throw new Exception(
				sprintf( 'Tool not found: %s', $tool_name ),
				-32601
			);
		}

		// Execute tool.
		$result = $tool->execute( $arguments, array() );

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => is_string( $result ) ? $result : wp_json_encode( $result ),
				),
			),
		);
	}

	/**
	 * Handle resources/list method
	 *
	 * @param array $params Request parameters.
	 * @return array Resources list response.
	 */
	private function handle_resources_list( $params ) {
		// Placeholder - implement resource listing.
		return array(
			'resources' => array(),
		);
	}

	/**
	 * Handle prompts/list method
	 *
	 * @param array $params Request parameters.
	 * @return array Prompts list response.
	 */
	private function handle_prompts_list( $params ) {
		// Placeholder - implement prompt listing.
		return array(
			'prompts' => array(),
		);
	}
}
