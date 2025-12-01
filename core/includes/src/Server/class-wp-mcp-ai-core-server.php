<?php
/**
 * MCP Core Server - Tool registry and dispatcher.
 *
 *
 * @package WP_MCP_AI_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MCP Server for managing and executing tools.
 *
 * This class provides the core MCP server functionality including:
 * - Tool registration and lookup
 * - Tool execution with authorization checks
 * - REST API endpoint registration
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Core_Server {
	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Core_Server
	 */
	protected static $instance = null;

	/**
	 * Registered tools keyed by slug.
	 *
	 * @var WP_MCP_AI_Core_Tool_Interface[]
	 */
	protected $tools = array();

	/**
	 * Whether the server has been initialized.
	 *
	 * @var bool
	 */
	protected $initialized = false;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_MCP_AI_Core_Server
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor to enforce singleton.
	 */
	private function __construct() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * The singleton pattern requires preventing deserialization.
	 * This is a standard PHP pattern and using a generic Exception is acceptable.
	 *
	 * @throws \RuntimeException Always throws exception to prevent unserialization.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize singleton' );
	}

	/**
	 * Initialize the MCP server.
	 *
	 * Registers REST API endpoints and sets up hooks.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		if ( $this->initialized ) {
			return;
		}

		$this->initialized = true;

		// Register REST API endpoints.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @since 1.0.0
	 */
	public function register_rest_routes() {
		// List available tools.
		register_rest_route(
			'mcp-ai-core/v1',
			'/tools',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_list_tools' ),
				'permission_callback' => array( $this, 'check_permissions' ),
			)
		);

		// Execute a tool.
		register_rest_route(
			'mcp-ai-core/v1',
			'/tools/(?P<slug>[a-z0-9_-]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_execute_tool' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Check permissions for REST API requests.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True if allowed, error otherwise.
	 */
	public function check_permissions( $request ) {
		/**
		 * Filter whether the current user can access MCP tools.
		 *
		 * @since 1.0.0
		 *
		 * @param bool            $can     Whether user can access. Default checks edit_posts capability.
		 * @param WP_REST_Request $request The REST request object.
		 */
		$can = apply_filters( 'wp_mcp_ai_can_access_tools', current_user_can( 'edit_posts' ), $request );

		if ( ! $can ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to access MCP tools.', 'wp-mcp-ai-core' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle GET /tools request.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request (unused but required by WP REST API).
	 * @return WP_REST_Response
	 */
	public function handle_list_tools( $request ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$tools = array();

		foreach ( $this->tools as $slug => $tool ) {
			$tools[] = array(
				'name'        => $tool->get_slug(),
				'description' => $tool->get_description(),
				'parameters'  => $tool->get_parameters_schema(),
			);
		}

		return rest_ensure_response( array( 'tools' => $tools ) );
	}

	/**
	 * Handle POST /tools/{slug} request.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_execute_tool( $request ) {
		$slug      = $request->get_param( 'slug' );
		$arguments = $request->get_json_params();

		// Remove slug from arguments if present.
		unset( $arguments['slug'] );

		$context = array(
			'user_id' => get_current_user_id(),
			'source'  => 'rest-api',
		);

		$result = $this->execute_tool( $slug, $arguments, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'result' => $result ) );
	}

	/**
	 * Register a tool.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_MCP_AI_Core_Tool_Interface|string $tool Tool instance or class name.
	 * @return bool True if registered, false otherwise.
	 */
	public function register_tool( $tool ) {
		// If class name string, instantiate.
		if ( is_string( $tool ) ) {
			if ( ! class_exists( $tool ) ) {
				return false;
			}
			$tool = new $tool();
		}

		// Verify interface implementation.
		if ( ! $tool instanceof WP_MCP_AI_Core_Tool_Interface ) {
			return false;
		}

		$slug = sanitize_key( $tool->get_slug() );

		if ( empty( $slug ) ) {
			return false;
		}

		$this->tools[ $slug ] = $tool;

		return true;
	}

	/**
	 * Unregister a tool.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Tool slug.
	 */
	public function unregister_tool( $slug ) {
		$slug = sanitize_key( $slug );
		unset( $this->tools[ $slug ] );
	}

	/**
	 * Get a registered tool.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Tool slug.
	 * @return WP_MCP_AI_Core_Tool_Interface|null Tool or null if not found.
	 */
	public function get_tool( $slug ) {
		$slug = sanitize_key( $slug );
		return isset( $this->tools[ $slug ] ) ? $this->tools[ $slug ] : null;
	}

	/**
	 * Get all registered tools.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_MCP_AI_Core_Tool_Interface[]
	 */
	public function get_tools() {
		return array_values( $this->tools );
	}

	/**
	 * Check if a tool is registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Tool slug.
	 * @return bool
	 */
	public function is_tool_registered( $slug ) {
		$slug = sanitize_key( $slug );
		return isset( $this->tools[ $slug ] );
	}

	/**
	 * Execute a tool.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug      Tool slug.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return mixed|WP_Error Tool result or error.
	 */
	public function execute_tool( $slug, $arguments = array(), $context = array() ) {
		$slug = sanitize_key( $slug );
		$tool = $this->get_tool( $slug );

		if ( ! $tool ) {
			return new WP_Error(
				'wp_mcp_ai_tool_not_found',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Tool "%s" not found.', 'wp-mcp-ai-core' ),
					$slug
				),
				array( 'status' => 404 )
			);
		}

		// Get the current user for authorization checks.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$user    = $user_id ? get_userdata( $user_id ) : null;

		/**
		 * Filter whether the tool can be executed.
		 *
		 * @since 1.0.0
		 *
		 * @param bool                             $can_run   Whether the tool can run. Default true.
		 * @param WP_MCP_AI_Core_Tool_Interface    $tool      The tool instance.
		 * @param array                            $arguments Tool arguments.
		 * @param WP_User|null                     $user      Current user or null.
		 */
		$can_run = apply_filters( 'wp_mcp_ai_can_run_tool', true, $tool, $arguments, $user );

		if ( ! $can_run ) {
			return new WP_Error(
				'wp_mcp_ai_tool_forbidden',
				__( 'You do not have permission to run this tool.', 'wp-mcp-ai-core' ),
				array( 'status' => 403 )
			);
		}

		/**
		 * Filter whether rate limiting allows tool execution.
		 *
		 * @since 1.0.0
		 *
		 * @param bool     $allow    Whether to allow execution. Default true.
		 * @param string   $slug     Tool slug.
		 * @param WP_User  $user     Current user.
		 * @param array    $context  Execution context.
		 */
		$rate_limit_allow = apply_filters( 'wp_mcp_ai_rate_limit_allow', true, $slug, $user, $context );

		if ( ! $rate_limit_allow ) {
			return new WP_Error(
				'wp_mcp_ai_rate_limited',
				__( 'Rate limit exceeded. Please try again later.', 'wp-mcp-ai-core' ),
				array( 'status' => 429 )
			);
		}

		// Execute the tool.
		return $tool->execute( $arguments, $context );
	}

	/**
	 * Get tool definition for LLM payload.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Tool slug.
	 * @return array|null Tool definition or null.
	 */
	public function get_tool_definition( $slug ) {
		$tool = $this->get_tool( $slug );

		if ( ! $tool ) {
			return null;
		}

		return array(
			'name'        => $tool->get_slug(),
			'description' => $tool->get_description(),
			'parameters'  => $tool->get_parameters_schema(),
		);
	}

	/**
	 * Get all tool definitions for LLM payload.
	 *
	 * @since 1.0.0
	 *
	 * @return array Array of tool definitions.
	 */
	public function get_all_tool_definitions() {
		$definitions = array();

		foreach ( $this->tools as $slug => $tool ) {
			$definitions[] = $this->get_tool_definition( $slug );
		}

		return array_filter( $definitions );
	}

	/**
	 * Get capability flags for a tool.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Tool slug.
	 * @return array<string> Array of capability flags.
	 */
	public function get_tool_capability_flags( $slug ) {
		$tool = $this->get_tool( $slug );

		if ( ! $tool ) {
			return array();
		}

		if ( $tool instanceof WP_MCP_AI_Core_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();
			return is_array( $flags ) ? $flags : array();
		}

		return array();
	}
}
