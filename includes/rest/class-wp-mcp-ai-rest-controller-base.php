<?php
/**
 * Base REST Controller for WP oOS Plugin
 *
 * Provides common functionality for all REST API controllers.
 * Part of architectural refactoring to split god object pattern.
 *
 * @package WP_MCP_AI
 * @subpackage REST
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base REST Controller Class
 *
 * Provides shared functionality for specialized REST controllers:
 * - Authentication context access
 * - Common validation helpers
 * - Error formatting
 * - Response building
 *
 * @since 1.0.0
 */
abstract class WP_MCP_AI_REST_Controller_Base {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Authentication handler.
	 *
	 * @var WP_MCP_AI_REST_Authenticator
	 */
	protected $authenticator;

	/**
	 * Request validator.
	 *
	 * @var WP_MCP_AI_REST_Validator
	 */
	protected $validator;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_REST_Authenticator $authenticator Authentication handler.
	 * @param WP_MCP_AI_REST_Validator     $validator     Request validator.
	 */
	public function __construct( WP_MCP_AI_REST_Authenticator $authenticator, WP_MCP_AI_REST_Validator $validator ) {
		$this->authenticator = $authenticator;
		$this->validator     = $validator;
	}

	/**
	 * Register routes for this controller.
	 *
	 * Must be implemented by child classes to define their endpoints.
	 *
	 * @return void
	 */
	abstract public function register_routes();

	/**
	 * Get the REST namespace for this controller.
	 *
	 * @return string REST API namespace.
	 */
	protected function get_namespace() {
		return self::REST_NAMESPACE;
	}

	/**
	 * Get authenticated user ID.
	 *
	 * @return int WordPress user ID, 0 if not authenticated.
	 */
	protected function get_authenticated_user_id() {
		return $this->authenticator->get_authenticated_user_id();
	}

	/**
	 * Get authentication context.
	 *
	 * @return array Authentication context with user_id, token info, etc.
	 */
	protected function get_auth_context() {
		return $this->authenticator->get_auth_context();
	}

	/**
	 * Build error response with actionable guidance.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @param array  $actions Optional actionable guidance for client.
	 * @return WP_Error Error object.
	 */
	protected function error( $code, $message, $status = 400, $actions = array() ) {
		$data = array( 'status' => $status );

		if ( ! empty( $actions ) ) {
			$data['actions'] = $actions;
		}

		return new WP_Error( $code, $message, $data );
	}

	/**
	 * Build successful response.
	 *
	 * @param array $data   Response data.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response REST response object.
	 */
	protected function success( $data, $status = 200 ) {
		return new WP_REST_Response( $data, $status );
	}

	/**
	 * Sanitize and validate assistant ID.
	 *
	 * @param mixed $assistant_id Assistant ID to validate.
	 * @return int|WP_Error Sanitized assistant ID or error.
	 */
	protected function sanitize_assistant_id( $assistant_id ) {
		$assistant_id = absint( $assistant_id );

		if ( ! $assistant_id ) {
			return $this->error(
				'wp_mcp_ai_invalid_assistant_id',
				__( 'Invalid assistant ID provided.', 'wp-mcp-ai' ),
				400,
				array(
					'provide_valid_id' => __( 'Provide a valid numeric assistant ID.', 'wp-mcp-ai' ),
				)
			);
		}

		return $assistant_id;
	}

	/**
	 * Check if current user has capability.
	 *
	 * @param string $capability Capability to check.
	 * @param int    $user_id    Optional user ID, defaults to current user.
	 * @return bool Whether user has capability.
	 */
	protected function user_has_capability( $capability, $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = $this->get_authenticated_user_id();
		}

		if ( ! $user_id ) {
			return false;
		}

		return user_can( $user_id, $capability );
	}

	/**
	 * Ensure request parameter exists and is not empty.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param string          $param   Parameter name.
	 * @return mixed|WP_Error Parameter value or error if missing.
	 */
	protected function require_param( WP_REST_Request $request, $param ) {
		$value = $request->get_param( $param );

		if ( null === $value || '' === $value ) {
			return $this->error(
				'wp_mcp_ai_missing_param',
				sprintf(
					/* translators: %s: parameter name */
					__( 'Missing required parameter: %s', 'wp-mcp-ai' ),
					$param
				),
				400,
				array(
					'provide_param' => sprintf(
						/* translators: %s: parameter name */
						__( 'Provide the "%s" parameter in your request.', 'wp-mcp-ai' ),
						$param
					),
				)
			);
		}

		return $value;
	}

	/**
	 * Common permission check for authenticated endpoints.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True if authenticated, error otherwise.
	 */
	protected function permissions_check_authenticated( WP_REST_Request $request ) {
		$user_id = $this->get_authenticated_user_id();

		if ( ! $user_id ) {
			return $this->error(
				'wp_mcp_ai_unauthorized',
				__( 'You must be authenticated to access this endpoint.', 'wp-mcp-ai' ),
				401,
				array(
					'authenticate' => __( 'Provide valid authentication credentials.', 'wp-mcp-ai' ),
				)
			);
		}

		return true;
	}

	/**
	 * Common permission check for admin-only endpoints.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True if user is admin, error otherwise.
	 */
	protected function permissions_check_admin( WP_REST_Request $request ) {
		$user_id = $this->get_authenticated_user_id();

		if ( ! $user_id || ! $this->user_has_capability( 'manage_options', $user_id ) ) {
			return $this->error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to access this endpoint.', 'wp-mcp-ai' ),
				403,
				array(
					'request_access' => __( 'Administrator access is required.', 'wp-mcp-ai' ),
				)
			);
		}

		return true;
	}

	/**
	 * Log debug information.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	protected function log_debug( $message, $context = array() ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::debug( $message, $context );
		}
	}

	/**
	 * Log error information.
	 *
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 * @return void
	 */
	protected function log_error( $message, $context = array() ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::error( $message, $context );
		}
	}

	/**
	 * Validate that request content type is JSON.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error True if valid, error otherwise.
	 */
	protected function validate_json_content_type( WP_REST_Request $request ) {
		$content_type = $request->get_content_type();

		if ( ! $content_type || 0 !== strpos( $content_type['value'], 'application/json' ) ) {
			return $this->error(
				'wp_mcp_ai_invalid_content_type',
				__( 'Request must use application/json content type.', 'wp-mcp-ai' ),
				400,
				array(
					'set_content_type' => __( 'Set Content-Type header to application/json.', 'wp-mcp-ai' ),
				)
			);
		}

		return true;
	}
}
