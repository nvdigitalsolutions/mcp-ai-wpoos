<?php
/**
 * REST API Controller for Slash Commands
 *
 * Provides REST endpoint for executing slash commands via HTTP requests.
 *
 * @package WP_MCP_AI
 * @subpackage REST
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slash Command REST Controller
 *
 * Handles slash command execution via REST API with authentication,
 * rate limiting, and async support.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_REST_Slash_Command_Controller extends WP_REST_Controller {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->namespace = 'mcp-ai/v1';
		$this->rest_base = 'slash-command';
	}

	/**
	 * Register REST routes
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'execute_command' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_endpoint_args(),
				),
				'schema' => array( $this, 'get_item_schema' ),
			)
		);

		// List available commands.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/list',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_commands' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Execute slash command
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function execute_command( $request ) {
		$command = $request->get_param( 'command' );
		$async   = $request->get_param( 'async' );
		$user_id = get_current_user_id();

		// Log the REST API request.
		$this->log_request( 'execute_command', array(
			'command'  => $command,
			'async'    => $async,
			'user_id'  => $user_id,
			'ip'       => $request->get_header( 'x-forwarded-for' ) ?: $_SERVER['REMOTE_ADDR'],
			'endpoint' => $request->get_route(),
		) );

		if ( empty( $command ) ) {
			$this->log_error( 'missing_command', 'Command parameter is required' );
			return new WP_Error(
				'missing_command',
				__( 'Command parameter is required', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Get context from request.
		$context = array(
			'user_id'      => $user_id,
			'request_time' => current_time( 'mysql' ),
			'ip_address'   => $request->get_header( 'x-forwarded-for' ) ?: $_SERVER['REMOTE_ADDR'],
		);

		// Execute command.
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			$this->log_error( 'handler_not_initialized', 'Slash command handler not initialized' );
			return new WP_Error(
				'handler_not_initialized',
				__( 'Slash command handler not initialized', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Handle async execution.
		if ( $async ) {
			return $this->execute_async( $command, $context );
		}

		// Synchronous execution.
		$start_time = microtime( true );
		$result     = $handler->execute( $command, $context );
		$duration   = round( ( microtime( true ) - $start_time ) * 1000, 2 );

		if ( is_wp_error( $result ) ) {
			$this->log_error( $result->get_error_code(), $result->get_error_message(), array(
				'command'  => $command,
				'duration' => $duration . 'ms',
			) );
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$this->log_success( 'command_executed', array(
			'command'  => $command,
			'duration' => $duration . 'ms',
			'has_result' => ! empty( $result ),
		) );

		return new WP_REST_Response(
			array(
				'success' => true,
				'command' => $command,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Execute command asynchronously
	 *
	 * @param string $command Command to execute.
	 * @param array  $context Execution context.
	 * @return WP_REST_Response Response with job ID.
	 */
	private function execute_async( $command, $context ) {
		// Generate job ID.
		$job_id = 'slash_cmd_' . wp_generate_password( 12, false );

		// Store job data.
		set_transient(
			'wp_mcp_ai_slash_job_' . $job_id,
			array(
				'command' => $command,
				'context' => $context,
				'status'  => 'pending',
				'created' => time(),
			),
			HOUR_IN_SECONDS
		);

		// Schedule execution.
		wp_schedule_single_event(
			time(),
			'wp_mcp_ai_execute_async_slash_command',
			array( $job_id, $command, $context )
		);

		return new WP_REST_Response(
			array(
				'success' => true,
				'async'   => true,
				'job_id'  => $job_id,
				'status'  => 'pending',
			),
			202
		);
	}

	/**
	 * List available commands
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response with commands list.
	 */
	public function list_commands( $request ) {
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			return new WP_Error(
				'handler_not_initialized',
				__( 'Slash command handler not initialized', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// Get commands filtered by current user capability.
		$commands = $handler->get_commands( true );

		$formatted = array();
		foreach ( $commands as $name => $config ) {
			$formatted[] = array(
				'name'        => $name,
				'description' => $config['description'] ?? '',
				'usage'       => $config['usage'] ?? "/{$name}",
				'capability'  => $config['capability'] ?? 'edit_posts',
				'aliases'     => $config['aliases'] ?? array(),
			);
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'commands' => $formatted,
				'count'    => count( $formatted ),
			),
			200
		);
	}

	/**
	 * Check if user has permission to execute commands
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if has permission, error otherwise.
	 */
	public function check_permission( $request ) {
		// Check if user is authenticated.
		if ( ! is_user_logged_in() ) {
			$this->log_request( 'permission_check', array(
				'authenticated' => false,
				'has_bearer'    => ! empty( $request->get_header( 'authorization' ) ),
			) );

			// Try bearer token authentication.
			$auth_header = $request->get_header( 'authorization' );
			if ( $auth_header && 0 === strpos( $auth_header, 'Bearer ' ) ) {
				$token = substr( $auth_header, 7 );
				// Validate token (implement token validation logic).
				$user_id = $this->validate_bearer_token( $token );
				if ( $user_id ) {
					wp_set_current_user( $user_id );
					$this->log_success( 'bearer_auth', array( 'user_id' => $user_id ) );
				} else {
					$this->log_error( 'invalid_token', 'Bearer token validation failed' );
					return new WP_Error(
						'invalid_token',
						__( 'Invalid bearer token', 'mcp-ai-wpoos' ),
						array( 'status' => 401 )
					);
				}
			} else {
				$this->log_error( 'not_authenticated', 'No authentication provided' );
				return new WP_Error(
					'not_authenticated',
					__( 'Authentication required', 'mcp-ai-wpoos' ),
					array( 'status' => 401 )
				);
			}
		} else {
			$this->log_request( 'permission_check', array(
				'authenticated' => true,
				'user_id'       => get_current_user_id(),
			) );
		}

		// Check minimum capability.
		if ( ! current_user_can( 'read' ) ) {
			$this->log_error( 'insufficient_permission', 'User lacks read capability', array(
				'user_id' => get_current_user_id(),
			) );
			return new WP_Error(
				'insufficient_permission',
				__( 'Insufficient permissions', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate bearer token
	 *
	 * @param string $token Bearer token.
	 * @return int|false User ID or false if invalid.
	 */
	private function validate_bearer_token( $token ) {
		// Check if token matches assistant credential format.
		if ( preg_match( '/^cred_([a-zA-Z0-9]+)\.([a-zA-Z0-9]+)$/', $token, $matches ) ) {
			// Validate assistant credential.
			// TODO: Implement credential validation.
			return false;
		}

		// Check application token.
		$stored_token = get_option( 'wp_mcp_ai_api_token' );
		if ( $stored_token && hash_equals( $stored_token, $token ) ) {
			// Return admin user for valid API token.
			$admin_users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
			return ! empty( $admin_users ) ? $admin_users[0]->ID : false;
		}

		return false;
	}

	/**
	 * Get endpoint arguments
	 *
	 * @return array Arguments definition.
	 */
	private function get_endpoint_args() {
		return array(
			'command' => array(
				'description' => __( 'Slash command to execute (e.g., "/help")', 'mcp-ai-wpoos' ),
				'type'        => 'string',
				'required'    => true,
			),
			'async' => array(
				'description' => __( 'Execute command asynchronously', 'mcp-ai-wpoos' ),
				'type'        => 'boolean',
				'default'     => false,
			),
		);
	}

	/**
	 * Get schema for item
	 *
	 * @return array Schema definition.
	 */
	public function get_item_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'slash-command',
			'type'       => 'object',
			'properties' => array(
				'success' => array(
					'description' => __( 'Whether command executed successfully', 'mcp-ai-wpoos' ),
					'type'        => 'boolean',
				),
				'command' => array(
					'description' => __( 'Command that was executed', 'mcp-ai-wpoos' ),
					'type'        => 'string',
				),
				'result' => array(
					'description' => __( 'Command execution result', 'mcp-ai-wpoos' ),
					'type'        => 'mixed',
				),
			),
		);
	}

	/**
	 * Log REST API request
	 *
	 * @param string $action Action being performed.
	 * @param array  $data   Additional data to log.
	 */
	private function log_request( $action, $data = array() ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[SlashCommands:REST] %s | %s',
				$action,
				wp_json_encode( $data )
			) );
		}
	}

	/**
	 * Log success message
	 *
	 * @param string $message Success message.
	 * @param array  $data    Additional data to log.
	 */
	private function log_success( $message, $data = array() ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[SlashCommands:REST] ✅ %s | %s',
				$message,
				wp_json_encode( $data )
			) );
		}
	}

	/**
	 * Log error message
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param array  $data    Additional data to log.
	 */
	private function log_error( $code, $message, $data = array() ) {
		error_log( sprintf(
			'[SlashCommands:REST] ❌ %s: %s | %s',
			$code,
			$message,
			wp_json_encode( $data )
		) );
	}
}

/**
 * Register async command execution hook
 */
add_action(
	'wp_mcp_ai_execute_async_slash_command',
	function( $job_id, $command, $context ) {
		$handler = wp_mcp_ai_get_slash_command_handler();
		if ( ! $handler ) {
			return;
		}

		// Update job status.
		$job_data = get_transient( 'wp_mcp_ai_slash_job_' . $job_id );
		if ( ! $job_data ) {
			return;
		}

		$job_data['status'] = 'running';
		set_transient( 'wp_mcp_ai_slash_job_' . $job_id, $job_data, HOUR_IN_SECONDS );

		// Execute command.
		$result = $handler->execute( $command, $context );

		// Store result.
		$job_data['status']    = is_wp_error( $result ) ? 'failed' : 'completed';
		$job_data['result']    = $result;
		$job_data['completed'] = time();

		set_transient( 'wp_mcp_ai_slash_job_' . $job_id, $job_data, HOUR_IN_SECONDS );
	},
	10,
	3
);
