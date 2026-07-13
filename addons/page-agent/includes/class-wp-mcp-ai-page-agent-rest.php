<?php
/**
 * Page Agent REST API Endpoints
 *
 * Provides REST bridges that allow the client-side Page Agent to:
 *  - Execute NV oOS tools through the tool registry
 *  - Record DOM snapshots for debugging/context
 *  - Retrieve active configuration
 *
 * @package NV_oOS_Page_Agent
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the Page Agent addon.
 *
 * Registers routes under the `nvoos-page-agent/v1` namespace.
 *
 * @since 0.1.0
 */
class WP_MCP_AI_Page_Agent_REST {

	/**
	 * REST API namespace.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const NAMESPACE = 'nvoos-page-agent/v1';

	/**
	 * Register all REST routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_routes() {
		// POST /execute-tool — dispatch a tool call through NV oOS tool registry.
		register_rest_route(
			self::NAMESPACE,
			'/execute-tool',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_execute_tool' ),
				'permission_callback' => array( $this, 'check_execute_permission' ),
				'args'                => array(
					'tool'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Tool slug to execute.', 'nvoos-page-agent' ),
					),
					'arguments' => array(
						'required'          => false,
						'type'              => 'object',
						'default'           => array(),
						'sanitize_callback' => array( $this, 'sanitize_tool_arguments' ),
						'description'       => __( 'Arguments to pass to the tool.', 'nvoos-page-agent' ),
					),
				),
			)
		);

		// POST /dom-snapshot — record a client-side DOM snapshot.
		register_rest_route(
			self::NAMESPACE,
			'/dom-snapshot',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_dom_snapshot' ),
				'permission_callback' => array( $this, 'check_execute_permission' ),
				'args'                => array(
					'url'         => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
						'description'       => __( 'URL of the page being snapshotted.', 'nvoos-page-agent' ),
					),
					'interactive' => array(
						'required'          => false,
						'type'              => 'array',
						'default'           => array(),
						'sanitize_callback' => array( $this, 'sanitize_interactive_elements' ),
						'description'       => __( 'Extracted interactive elements from the DOM.', 'nvoos-page-agent' ),
					),
					'timestamp'   => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'ISO 8601 timestamp of the snapshot.', 'nvoos-page-agent' ),
					),
				),
			)
		);

		// GET /config — return current addon configuration.
		register_rest_route(
			self::NAMESPACE,
			'/config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_get_config' ),
				'permission_callback' => array( $this, 'check_config_permission' ),
			)
		);
	}

	/**
	 * Permission check for tool execution and DOM snapshots.
	 *
	 * Requires at least 'edit_posts' capability and a valid WordPress nonce.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error True if allowed, WP_Error otherwise.
	 */
	public function check_execute_permission( $request ) {
		// Verify WordPress nonce.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Invalid nonce.', 'nvoos-page-agent' ),
				array( 'status' => 403 )
			);
		}

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to execute tools.', 'nvoos-page-agent' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission check for reading configuration.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error True if allowed, WP_Error otherwise.
	 */
	public function check_config_permission( $request ) {
		// Config endpoint requires nonce verification as well.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Invalid nonce.', 'nvoos-page-agent' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle tool execution requests.
	 *
	 * Dispatches the requested tool through the NV oOS tool registry.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_execute_tool( $request ) {
		$tool_slug = $request->get_param( 'tool' );
		$arguments = $request->get_param( 'arguments' );

		$registry = function_exists( 'wp_mcp_ai_get_tool_registry' )
			? wp_mcp_ai_get_tool_registry()
			: null;

		if ( ! $registry ) {
			return new WP_Error(
				'tool_registry_unavailable',
				__( 'Tool registry is not available.', 'nvoos-page-agent' ),
				array( 'status' => 500 )
			);
		}

		// Get the tool instance.
		$tool = $registry->get_tool( $tool_slug );
		if ( ! $tool ) {
			return new WP_Error(
				'tool_not_found',
				sprintf(
					/* translators: %s: tool slug */
					__( 'Tool "%s" not found.', 'nvoos-page-agent' ),
					$tool_slug
				),
				array( 'status' => 404 )
			);
		}

		if ( ! $tool instanceof WP_MCP_AI_Tool_Interface ) {
			return new WP_Error(
				'tool_invalid',
				__( 'Invalid tool implementation.', 'nvoos-page-agent' ),
				array( 'status' => 500 )
			);
		}

		// Check capability.
		$required_cap = $tool->get_required_capability();
		if ( ! empty( $required_cap ) && ! current_user_can( $required_cap ) ) {
			return new WP_Error(
				'tool_forbidden',
				__( 'You do not have permission to execute this tool.', 'nvoos-page-agent' ),
				array( 'status' => 403 )
			);
		}

		// Build context.
		$context = array(
			'source'   => 'page-agent-bridge',
			'endpoint' => 'nvoos-page-agent/v1/execute-tool',
			'user_id'  => get_current_user_id(),
		);

		// Execute the tool.
		$result = $tool->execute( $arguments, $context );

		// Wrap WP_Error responses.
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Handle DOM snapshot recording.
	 *
	 * Stores the snapshot as a transient for debugging/context sharing.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_dom_snapshot( $request ) {
		$url              = $request->get_param( 'url' );
		$interactive_els  = $request->get_param( 'interactive' );
		$timestamp        = $request->get_param( 'timestamp' );

		$snapshot = array(
			'url'         => $url,
			'interactive' => $interactive_els,
			'timestamp'   => $timestamp ?: current_time( 'c' ),
			'recorded_at' => current_time( 'c' ),
		);

		// Store the snapshot for 1 hour.
		$transient_key = 'nvoos_page_agent_dom_snapshot_' . md5( $url . get_current_user_id() );
		set_transient( $transient_key, $snapshot, HOUR_IN_SECONDS );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'DOM snapshot recorded.', 'nvoos-page-agent' ),
				'data'    => array(
					'key'     => $transient_key,
					'expires' => HOUR_IN_SECONDS,
				),
			)
		);
	}

	/**
	 * Return the current addon configuration.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_get_config( $request ) {
		$settings = WP_MCP_AI_Page_Agent::get_settings();

		return rest_ensure_response(
			array(
				'enabled'   => $settings['enabled'],
				'model'     => $settings['model'],
				'language'  => $settings['language'],
				'max_steps' => $settings['max_steps'],
				'rest_url'  => rest_url( self::NAMESPACE ),
			)
		);
	}

	/**
	 * Sanitize tool arguments recursively.
	 *
	 * @since 0.1.0
	 *
	 * @param array|mixed $arguments The raw tool arguments.
	 * @return array Sanitized arguments.
	 */
	public function sanitize_tool_arguments( $arguments ) {
		if ( ! is_array( $arguments ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $arguments as $key => $value ) {
			$key = sanitize_key( $key );

			if ( is_string( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( $value );
			} elseif ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_tool_arguments( $value );
			} elseif ( is_numeric( $value ) ) {
				$sanitized[ $key ] = $value;
			} elseif ( is_bool( $value ) ) {
				$sanitized[ $key ] = (bool) $value;
			} else {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize interactive element arrays from DOM snapshots.
	 *
	 * @since 0.1.0
	 *
	 * @param array|mixed $elements The raw elements array.
	 * @return array Sanitized elements.
	 */
	public function sanitize_interactive_elements( $elements ) {
		if ( ! is_array( $elements ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$sanitized[] = array(
				'tag'       => isset( $element['tag'] ) ? sanitize_key( $element['tag'] ) : '',
				'text'      => isset( $element['text'] ) ? sanitize_text_field( $element['text'] ) : '',
				'id'        => isset( $element['id'] ) ? sanitize_key( $element['id'] ) : '',
				'class'     => isset( $element['class'] ) ? sanitize_html_class( $element['class'] ) : '',
				'href'      => isset( $element['href'] ) ? esc_url_raw( $element['href'] ) : '',
				'type'      => isset( $element['type'] ) ? sanitize_key( $element['type'] ) : '',
				'name'      => isset( $element['name'] ) ? sanitize_key( $element['name'] ) : '',
				'value'     => isset( $element['value'] ) ? sanitize_text_field( $element['value'] ) : '',
				'role'      => isset( $element['role'] ) ? sanitize_key( $element['role'] ) : '',
				'ariaLabel' => isset( $element['ariaLabel'] ) ? sanitize_text_field( $element['ariaLabel'] ) : '',
			);
		}

		return $sanitized;
	}
}
