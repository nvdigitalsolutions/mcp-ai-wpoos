<?php
/**
 * STDIO Transport for MCP Protocol.
 *
 * Implements MCP over STDIO transport for local agent integration.
 * Reads JSON-RPC 2.0 requests from stdin and writes responses to stdout.
 *
 * This transport is designed for MCP clients like Claude Desktop that
 * communicate via stdin/stdout rather than HTTP.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * STDIO Transport class for MCP protocol communication.
 *
 * Provides a bridge between local MCP clients (like Claude Desktop)
 * and the WordPress MCP server by communicating over stdin/stdout.
 *
 * Usage:
 *   wp mcp-ai stdio
 *
 * The transport reads newline-delimited JSON-RPC 2.0 messages from stdin
 * and writes responses to stdout, following the MCP 2024-11-05 specification.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_STDIO_Transport {

	/**
	 * Maximum line length for stdin reads.
	 *
	 * @var int
	 */
	const MAX_LINE_LENGTH = 1048576; // 1MB.

	/**
	 * MCP protocol version.
	 *
	 * @var string
	 */
	const PROTOCOL_VERSION = '2024-11-05';

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Assistant ID for scoped operations.
	 *
	 * @var int
	 */
	private $assistant_id = 0;

	/**
	 * Flag indicating if the server should continue running.
	 *
	 * @var bool
	 */
	private $running = true;

	/**
	 * Constructor.
	 *
	 * @param int $assistant_id Optional assistant ID for scoped operations.
	 */
	public function __construct( $assistant_id = 0 ) {
		$this->assistant_id = absint( $assistant_id );
		$this->registry     = WP_MCP_AI_Tool_Registry::get_instance();
	}

	/**
	 * Start the STDIO transport loop.
	 *
	 * Reads JSON-RPC 2.0 messages from stdin and processes them.
	 * Each line is expected to be a complete JSON-RPC message.
	 *
	 * @return void
	 */
	public function run() {
		// Disable output buffering for real-time communication.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Set stdin to non-blocking mode for graceful shutdown.
		if ( function_exists( 'stream_set_blocking' ) ) {
			stream_set_blocking( STDIN, false );
		}

		$this->log_debug( 'STDIO transport started' );

		while ( $this->running ) {
			$line = $this->read_line();

			if ( null === $line ) {
				// No input available, wait a bit.
				usleep( 10000 ); // 10ms.
				continue;
			}

			if ( '' === trim( $line ) ) {
				// Empty line, skip.
				continue;
			}

			$response = $this->process_message( $line );

			if ( null !== $response ) {
				$this->write_response( $response );
			}
		}

		$this->log_debug( 'STDIO transport stopped' );
	}

	/**
	 * Read a line from stdin.
	 *
	 * @return string|null Line content or null if no input available.
	 */
	protected function read_line() {
		$line = fgets( STDIN, self::MAX_LINE_LENGTH );

		if ( false === $line ) {
			// Check if stdin is closed (EOF).
			if ( feof( STDIN ) ) {
				$this->running = false;
				return null;
			}
			return null;
		}

		return $line;
	}

	/**
	 * Write a response to stdout.
	 *
	 * @param array $response Response data to write.
	 * @return void
	 */
	protected function write_response( array $response ) {
		$json = wp_json_encode( $response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			$this->log_debug( 'Failed to encode response to JSON' );
			return;
		}

		// Write response followed by newline.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped.
		fwrite( STDOUT, $json . "\n" );

		// Flush output immediately.
		if ( function_exists( 'fflush' ) ) {
			fflush( STDOUT );
		}
	}

	/**
	 * Process a JSON-RPC 2.0 message.
	 *
	 * @param string $line Raw message line.
	 * @return array|null Response array or null for notifications.
	 */
	protected function process_message( $line ) {
		$message = json_decode( trim( $line ), true );

		if ( null === $message || ! is_array( $message ) ) {
			return $this->error_response( null, -32700, 'Parse error: Invalid JSON' );
		}

		// Validate JSON-RPC 2.0 structure.
		if ( ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] ) {
			return $this->error_response(
				isset( $message['id'] ) ? $message['id'] : null,
				-32600,
				'Invalid Request: jsonrpc field must be "2.0"'
			);
		}

		if ( ! isset( $message['method'] ) || ! is_string( $message['method'] ) ) {
			return $this->error_response(
				isset( $message['id'] ) ? $message['id'] : null,
				-32600,
				'Invalid Request: method field is required and must be a string'
			);
		}

		$method = $message['method'];
		$params = isset( $message['params'] ) ? $message['params'] : array();
		$id     = isset( $message['id'] ) ? $message['id'] : null;

		$this->log_debug( 'Processing method: ' . $method );

		// Route to appropriate handler.
		$result = $this->route_method( $method, $params );

		if ( is_wp_error( $result ) ) {
			$code = -32603;
			if ( 'wp_mcp_ai_method_not_found' === $result->get_error_code() ) {
				$code = -32601;
			}
			return $this->error_response( $id, $code, $result->get_error_message() );
		}

		// If this is a notification (no id), don't return a response.
		if ( null === $id ) {
			return null;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Route a method to the appropriate handler.
	 *
	 * @param string $method Method name.
	 * @param array  $params Method parameters.
	 * @return mixed|WP_Error Result or error.
	 */
	protected function route_method( $method, $params ) {
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

			case 'shutdown':
				$this->running = false;
				return array( 'shutdown' => true );

			default:
				return new WP_Error(
					'wp_mcp_ai_method_not_found',
					sprintf(
						/* translators: %s: method name */
						__( 'Method not found: %s', 'wp-mcp-ai' ),
						$method
					)
				);
		}
	}

	/**
	 * Handle initialize request.
	 *
	 * @param array $params Request parameters.
	 * @return array Initialize response.
	 */
	protected function handle_initialize( $params ) {
		$site_name = get_bloginfo( 'name' );
		$site_desc = get_bloginfo( 'description' );

		if ( ! empty( $site_desc ) ) {
			$instructions = sprintf(
				/* translators: 1: site name, 2: site description */
				__( 'This is a WordPress site (%1$s). %2$s. You can use the available tools to interact with WordPress content, users, and functionality.', 'wp-mcp-ai' ),
				$site_name,
				$site_desc
			);
		} else {
			$instructions = sprintf(
				/* translators: %s: site name */
				__( 'This is a WordPress site (%s). You can use the available tools to interact with WordPress content, users, and functionality.', 'wp-mcp-ai' ),
				$site_name
			);
		}

		$response = array(
			'protocolVersion' => self::PROTOCOL_VERSION,
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => true ),
				'resources' => array(
					'subscribe'   => false,
					'listChanged' => true,
				),
				'prompts'   => array( 'listChanged' => true ),
			),
			'serverInfo'      => array(
				'name'    => 'WP oOS',
				'version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'dev',
			),
			'instructions'    => $instructions,
		);

		// Include tools in initialize response for better client compatibility.
		$tools_result = $this->handle_tools_list( $params );
		if ( ! is_wp_error( $tools_result ) && isset( $tools_result['tools'] ) ) {
			$response['tools'] = $tools_result['tools'];
		}

		return $response;
	}

	/**
	 * Handle tools/list request.
	 *
	 * @param array $params Request parameters.
	 * @return array|WP_Error Tools list or error.
	 */
	protected function handle_tools_list( $params ) {
		$assistant_id = $this->assistant_id;

		if ( isset( $params['assistant_id'] ) ) {
			$assistant_id = absint( $params['assistant_id'] );
		}

		if ( $assistant_id ) {
			// Get tools allowed for this assistant.
			$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
			$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

			$tools = array();
			foreach ( $allowed_tools as $tool_slug ) {
				$tool = $this->registry->get_tool( $tool_slug );
				if ( $tool ) {
					$tools[] = $tool;
				}
			}
		} else {
			// Return all tools.
			$tools = $this->registry->get_tools();
		}

		// Convert tools to MCP format.
		$mcp_tools = array();
		foreach ( $tools as $tool ) {
			try {
				$schema = $tool->get_parameters_schema();

				if ( ! is_array( $schema ) ) {
					continue;
				}

				$mcp_tools[] = array(
					'name'        => $tool->get_slug(),
					'description' => $tool->get_description(),
					'inputSchema' => $schema,
				);
			} catch ( Exception $e ) {
				$this->log_debug( 'Tool schema error: ' . $e->getMessage() );
				continue;
			}
		}

		return array( 'tools' => $mcp_tools );
	}

	/**
	 * Handle tools/call request.
	 *
	 * @param array $params Request parameters.
	 * @return array|WP_Error Tool result or error.
	 */
	protected function handle_tools_call( $params ) {
		if ( ! isset( $params['name'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_params',
				__( 'Missing required parameter: name', 'wp-mcp-ai' )
			);
		}

		$tool_name = sanitize_text_field( $params['name'] );
		$arguments = isset( $params['arguments'] ) ? $params['arguments'] : array();

		$tool = $this->registry->get_tool( $tool_name );

		if ( ! $tool ) {
			return new WP_Error(
				'wp_mcp_ai_tool_not_found',
				sprintf(
					/* translators: %s: tool name */
					__( 'Tool not found: %s', 'wp-mcp-ai' ),
					$tool_name
				)
			);
		}

		// Check if tool is allowed for assistant.
		if ( $this->assistant_id ) {
			$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );
			$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

			if ( ! in_array( $tool_name, $allowed_tools, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_tool_not_allowed',
					sprintf(
						/* translators: %s: tool name */
						__( 'Tool not allowed for this assistant: %s', 'wp-mcp-ai' ),
						$tool_name
					)
				);
			}
		}

		// Build execution context.
		$context = array(
			'transport'    => 'stdio',
			'assistant_id' => $this->assistant_id,
		);

		try {
			$result = $tool->execute( $arguments, $context );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Convert result to MCP text content format.
			return array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $this->convert_to_text( $result ),
					),
				),
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_tool_execution_failed',
				sprintf(
					/* translators: 1: tool name, 2: error message */
					__( 'Tool execution failed (%1$s): %2$s', 'wp-mcp-ai' ),
					$tool_name,
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Handle resources/list request.
	 *
	 * @param array $params Request parameters.
	 * @return array Resources list.
	 */
	protected function handle_resources_list( $params ) {
		$resources = array();

		$assistant_id = $this->assistant_id;
		if ( isset( $params['assistant_id'] ) ) {
			$assistant_id = absint( $params['assistant_id'] );
		}

		if ( $assistant_id ) {
			$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

			// Add memory files as resources.
			if ( isset( $config['memory_files'] ) && is_array( $config['memory_files'] ) ) {
				foreach ( $config['memory_files'] as $file_id ) {
					$file_id = absint( $file_id );
					if ( ! $file_id ) {
						continue;
					}

					$attachment = get_post( $file_id );
					if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
						continue;
					}

					$attachment_url = wp_get_attachment_url( $file_id );
					if ( empty( $attachment_url ) ) {
						continue;
					}

					$resources[] = array(
						'uri'         => $attachment_url,
						'name'        => get_the_title( $attachment ),
						'description' => get_post_field( 'post_excerpt', $attachment ),
						'mimeType'    => get_post_mime_type( $attachment ),
					);
				}
			}
		}

		return array( 'resources' => $resources );
	}

	/**
	 * Handle prompts/list request.
	 *
	 * @param array $params Request parameters.
	 * @return array Prompts list.
	 */
	protected function handle_prompts_list( $params ) {
		$prompts = array();

		$query = new WP_Query(
			array(
				'post_type'              => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => true,
			)
		);

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$prompts[] = array(
					'name'        => $post->post_name,
					'description' => get_the_title( $post ),
					'arguments'   => array(),
				);
			}
		}

		return array( 'prompts' => $prompts );
	}

	/**
	 * Convert a value to text for MCP response.
	 *
	 * @param mixed $value Value to convert.
	 * @return string Text representation.
	 */
	protected function convert_to_text( $value ) {
		if ( is_string( $value ) ) {
			return $value;
		}

		if ( is_scalar( $value ) || is_null( $value ) ) {
			$encoded = wp_json_encode( $value );
			if ( false === $encoded ) {
				return '[Unable to serialize scalar value]';
			}
			return $encoded;
		}

		$json = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $json ) {
			return '[Unable to serialize result]';
		}

		return $json;
	}

	/**
	 * Create a JSON-RPC error response.
	 *
	 * @param mixed  $id      Request ID or null.
	 * @param int    $code    Error code.
	 * @param string $message Error message.
	 * @return array Error response.
	 */
	protected function error_response( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * Log a debug message to stderr.
	 *
	 * @param string $message Message to log.
	 * @return void
	 */
	protected function log_debug( $message ) {
		if ( defined( 'WP_MCP_AI_DEBUG' ) && WP_MCP_AI_DEBUG ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped.
			fwrite( STDERR, '[WP oOS STDIO] ' . $message . "\n" );
		}
	}

	/**
	 * Stop the transport loop gracefully.
	 *
	 * @return void
	 */
	public function stop() {
		$this->running = false;
	}
}
