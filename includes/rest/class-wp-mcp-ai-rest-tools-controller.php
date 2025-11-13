<?php
/**
 * Tools Controller for REST API
 *
 * Handles tool-related endpoints including tool listing and execution.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tools Controller Class
 *
 * Manages all tool-related REST API endpoints:
 * - GET /tools - List available tools
 * - POST /tools - Execute a specific tool
 */
class WP_MCP_AI_REST_Tools_Controller extends WP_MCP_AI_REST_Controller_Base {
	/**
	 * Reference to the main REST controller for shared functionality.
	 *
	 * @var WP_MCP_AI_REST
	 */
	private $main_controller;

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Document prompt tool slug constant.
	 *
	 * @var string
	 */
	const DOCUMENT_PROMPT_TOOL_SLUG = 'document_prompt_helper';

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_REST                    $main_controller Main REST controller.
	 * @param WP_MCP_AI_REST_Authenticator|null $authenticator   Authentication handler (optional, for DI).
	 * @param WP_MCP_AI_REST_Validator|null     $validator       Request validator (optional, for DI).
	 */
	public function __construct( $main_controller = null, $authenticator = null, $validator = null ) {
		parent::__construct( $authenticator, $validator );
		$this->main_controller = $main_controller;
		$this->registry        = WP_MCP_AI_Tool_Registry::get_instance();
	}

	/**
	 * Register tools routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/tools',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_tools_list' ),
					'args'                => array(
						'assistant_id' => array(
							'description'       => __( 'ID of the assistant to list tools for. Returns all tools if omitted.', 'wp-mcp-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_tool_request' ),
					'args'                => array(
						'assistant_id' => array(
							'description'       => __( 'ID of the assistant context for tool execution.', 'wp-mcp-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'tool'         => array(
							'description'       => __( 'Slug of the tool to execute.', 'wp-mcp-ai' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'arguments'    => array(
							'description' => __( 'Arguments to pass to the tool execution.', 'wp-mcp-ai' ),
							'type'        => 'object',
							'required'    => false,
							'default'     => array(),
						),
					),
				),
			),
			true
		);
	}

	/**
	 * Handle GET /tools - List available tools.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_tools_list( WP_REST_Request $request ) {
		$assistant_id = $this->main_controller->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
		$scoped_id    = $this->main_controller->apply_token_assistant_scope( $assistant_id );

		if ( is_wp_error( $scoped_id ) ) {
			return $scoped_id;
		}

		$assistant_id = $scoped_id;

		if ( ! $assistant_id ) {
			// Return all tools if no assistant specified.
			$tools = $this->registry->get_tools();
		} else {
			// Get tools allowed for this assistant.
			$assistant_post = $this->main_controller->validate_assistant_access( $assistant_id );

			if ( is_wp_error( $assistant_post ) ) {
				return $assistant_post;
			}

			$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
			$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

			$tools = array();
			foreach ( $allowed_tools as $tool_slug ) {
				$tool = $this->registry->get_tool( $tool_slug );
				if ( $tool ) {
					$tools[] = $tool;
				}
			}
		}

		// Convert tools to a simple array format.
		$tools_list = array();
		foreach ( $tools as $tool ) {
			try {
				$schema = $tool->get_parameters_schema();

				// Validate that the schema is a valid array.
				if ( ! is_array( $schema ) ) {
					WP_MCP_AI_Logger::log_event(
						'error',
						'Tool returned invalid schema',
						array(
							'tool_slug'   => $tool->get_slug(),
							'schema_type' => gettype( $schema ),
						)
					);
					continue;
				}

				$tools_list[] = array(
					'name'        => $tool->get_slug(),
					'description' => $tool->get_description(),
					'inputSchema' => $schema,
				);
			} catch ( Exception $e ) {
				// Log the error and skip this tool.
				WP_MCP_AI_Logger::log_event(
					'error',
					'Tool schema generation failed',
					array(
						'tool_slug' => $tool->get_slug(),
						'error'     => $e->getMessage(),
						'trace'     => $e->getTraceAsString(),
					)
				);
				continue;
			} catch ( Error $e ) {
				// Catch PHP 7+ errors as well.
				WP_MCP_AI_Logger::log_event(
					'error',
					'Tool schema generation failed with PHP Error',
					array(
						'tool_slug' => $tool->get_slug(),
						'error'     => $e->getMessage(),
						'trace'     => $e->getTraceAsString(),
					)
				);
				continue;
			}
		}

		return rest_ensure_response(
			array(
				'tools' => $tools_list,
			)
		);
	}

	/**
	 * Handle POST /tools - Execute a specific tool.
	 *
	 * Temporarily grants access to the document prompt helper when the payload includes attachments.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_tool_request( WP_REST_Request $request ) {
		$assistant_id = $this->main_controller->resolve_assistant_id( $request->get_param( 'assistant_id' ) );
		$scoped_id    = $this->main_controller->apply_token_assistant_scope( $assistant_id );
		if ( is_wp_error( $scoped_id ) ) {
			return $scoped_id;
		}

		$assistant_id = $scoped_id;

		if ( ! $assistant_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_assistant', __( 'No assistant was provided and no default assistant is configured.', 'wp-mcp-ai' ), array( 'status' => 400 ) );
		}

		$assistant_post = $this->main_controller->validate_assistant_access( $assistant_id );
		if ( is_wp_error( $assistant_post ) ) {
			return $assistant_post;
		}

		$assistant_config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		$raw_tool         = $request->get_param( 'tool' );
		$arguments        = $request->get_param( 'arguments' );
		$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

		$tool_candidates = $this->main_controller->generate_tool_slug_candidates( $raw_tool );

		if ( $this->main_controller->candidates_include_slug( $tool_candidates, self::DOCUMENT_PROMPT_TOOL_SLUG ) && ! in_array( self::DOCUMENT_PROMPT_TOOL_SLUG, $allowed_tools, true ) ) {
			if ( $this->main_controller->tool_arguments_include_document_payload( $arguments ) ) {
				$assistant_config = $this->main_controller->ensure_tool_in_config( $assistant_config, self::DOCUMENT_PROMPT_TOOL_SLUG );
				$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();
			}
		}

		$tool_slug = $this->main_controller->resolve_tool_slug_from_candidates( $tool_candidates, $allowed_tools );

		if ( ! in_array( $tool_slug, $allowed_tools, true ) ) {
			return new WP_Error( 'wp_mcp_ai_tool_forbidden', __( 'This assistant is not allowed to execute the requested tool.', 'wp-mcp-ai' ), array( 'status' => 403 ) );
		}

		$tool = $this->registry->get_tool( $tool_slug );
		if ( ! $tool ) {
			return new WP_Error( 'wp_mcp_ai_tool_missing', __( 'The requested tool is not registered.', 'wp-mcp-ai' ), array( 'status' => 404 ) );
		}

		$auth_context = $this->get_auth_context();
		$user_id      = isset( $auth_context['user_id'] ) ? absint( $auth_context['user_id'] ) : 0;

		$context = array(
			'user_id'          => $user_id,
			'assistant_id'     => $assistant_id,
			'request'          => $request,
			'assistant_config' => $assistant_config,
		);

		if ( ! empty( $auth_context['token_authenticated'] ) ) {
			$context['token_authenticated'] = true;
			$context['token_type']          = $auth_context['token_type'];

			if ( ! empty( $auth_context['token_context'] ) ) {
				$context['token_context'] = $auth_context['token_context'];
			}
		}

		if ( empty( $context['user_id'] ) && empty( $auth_context['token_authenticated'] ) ) {
			return new WP_Error( 'wp_mcp_ai_anonymous_user', __( 'You must be logged in to execute tools.', 'wp-mcp-ai' ), array( 'status' => rest_authorization_required_code() ) );
		}

		/**
		 * Fires immediately before executing a registered tool.
		 *
		 * @param string           $tool_slug Tool identifier.
		 * @param array            $arguments Arguments passed in the request.
		 * @param array            $context   Execution context including user_id and assistant_id.
		 */
		$prepared_arguments = is_array( $arguments ) ? $arguments : array();

		if ( 'run_openai_external_action' === $tool_slug ) {
			if ( empty( $prepared_arguments['action_type'] ) && ! empty( $assistant_config['external_action_type'] ) ) {
				$prepared_arguments['action_type'] = $assistant_config['external_action_type'];
			}

			if ( empty( $prepared_arguments['identifier'] ) && ! empty( $assistant_config['external_action_identifier'] ) ) {
				$prepared_arguments['identifier'] = $assistant_config['external_action_identifier'];
			}
		}

		// Orchestration Layer: Wrap in try-catch to handle budget enforcement.
		try {
			do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $prepared_arguments, $context );

			$result = $tool->execute( $prepared_arguments, $context );

			if ( is_wp_error( $result ) ) {
				WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $prepared_arguments, $result, $context );
				return $result;
			}

			$result = apply_filters( 'wp_mcp_ai_tool_output', $result, $tool_slug, $prepared_arguments, $context );

			// Orchestration Layer: Adjust result to fit within budget constraints.
			if ( class_exists( 'WP_MCP_AI_Tool_Token_Limits' ) ) {
				$result = WP_MCP_AI_Tool_Token_Limits::adjust_tool_result_for_budget( $result, $tool_slug, $context );
			}

			WP_MCP_AI_Logger::log_tool_execution( $tool_slug, $prepared_arguments, $result, $context );

			/**
			 * Fires after a registered tool has completed execution.
			 *
			 * @param string           $tool_slug Tool identifier.
			 * @param array            $arguments Arguments passed in the request.
			 * @param array            $context   Execution context including user_id and assistant_id.
			 * @param mixed            $result    Tool result after filters have been applied.
			 */
			do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $prepared_arguments, $context, $result );

		} catch ( Exception $e ) {
			// Orchestration Layer: Budget constraint violation.
			WP_MCP_AI_Logger::log_error(
				'Tool execution blocked by orchestration layer',
				array(
					'tool_slug' => $tool_slug,
					'error'     => $e->getMessage(),
					'context'   => $context,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_budget_exceeded',
				$e->getMessage(),
				array( 'status' => 429 )
			);
		}

		return rest_ensure_response(
			array(
				'assistant_id' => $assistant_id,
				'tool'         => $tool_slug,
				'result'       => $result,
			)
		);
	}
}
