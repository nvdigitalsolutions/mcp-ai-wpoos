<?php
/**
 * Base REST API controller for NV oOS.
 *
 * Provides common functionality for all REST controllers including error handling,
 * response formatting, authentication, and request validation.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for REST controllers.
 *
 * Implements the Template Method pattern to provide consistent behavior across all REST controllers.
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
	 * Security manager.
	 *
	 * @var WP_MCP_AI_Security_Manager
	 */
	protected $security_manager;

	/**
	 * Tracks authentication details for the current request.
	 *
	 * @var array
	 */
	protected $auth_context = array();

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_REST_Authenticator|null $authenticator Authentication handler (optional, for DI).
	 * @param WP_MCP_AI_REST_Validator|null     $validator     Request validator (optional, for DI).
	 */
	public function __construct( $authenticator = null, $validator = null ) {
		$container              = wp_mcp_ai_container();
		$this->authenticator    = $authenticator ?? $container->get( 'rest.authenticator' );
		$this->validator        = $validator ?? $container->get( 'rest.validator' );
		$this->security_manager = new WP_MCP_AI_Security_Manager();
	}

	/**
	 * Register REST API routes.
	 *
	 * Must be implemented by child controllers.
	 */
	abstract public function register_routes();

	/**
	 * Format error response.
	 *
	 * Provides consistent error response format across all controllers.
	 *
	 * @param string $code         Error code.
	 * @param string $message      Human-readable error message.
	 * @param int    $status       HTTP status code (default: 400).
	 * @param array  $actions      Optional actions for error recovery.
	 * @return WP_Error WP_Error instance.
	 */
	protected function error( $code, $message, $status = 400, $actions = array() ) {
		$data = array(
			'status' => $status,
		);

		if ( ! empty( $actions ) ) {
			$data['actions'] = $actions;
		}

		return new WP_Error( $code, $message, $data );
	}

	/**
	 * Format success response.
	 *
	 * Provides consistent success response format across all controllers.
	 *
	 * @param mixed $data   Response data.
	 * @param int   $status HTTP status code (default: 200).
	 * @return WP_REST_Response REST response instance.
	 */
	protected function success( $data, $status = 200 ) {
		$response = new WP_REST_Response( $data, $status );

		// Add version header.
		$headers = array(
			'X-WP-MCP-AI-Version' => WP_MCP_AI_VERSION,
		);

		// Add security headers.
		$security_headers = $this->security_manager->get_security_headers();
		$headers          = array_merge( $headers, $security_headers );

		$response->set_headers( $headers );
		return $response;
	}

	/**
	 * Permission callback for authenticated requests.
	 *
	 * Checks if the request is authenticated via Bearer token, WordPress cookie, or guest token.
	 * Also applies security manager checks (IP filtering, HTTPS requirement, role/capability checks).
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return bool|WP_Error True if authenticated, WP_Error otherwise.
	 */
	protected function permissions_check_authenticated( WP_REST_Request $request ) {
		// Step 1: Check IP and HTTPS requirements (before authentication).
		// These checks don't require a user context.
		$security_check = $this->security_manager->check_ip_access( $this->get_client_ip() );
		if ( is_wp_error( $security_check ) ) {
			return $security_check;
		}

		$https_check = $this->security_manager->check_https_requirement();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		// Step 2: Authenticate the request.
		$auth_result = $this->authenticator->authenticate( $request );

		if ( is_wp_error( $auth_result ) ) {
			// Log authentication failure.
			$this->security_manager->log_security_event(
				'auth_failure',
				array(
					'error_code' => $auth_result->get_error_code(),
					'endpoint'   => $request->get_route(),
				),
				0
			);
			return $auth_result;
		}

		// Store auth context for use in the request handler.
		$this->auth_context = $auth_result;

		// Get authenticated user ID.
		$authenticated_user_id = isset( $this->auth_context['user_id'] ) ? absint( $this->auth_context['user_id'] ) : 0;

		// Step 3: Check role and capability requirements for authenticated users.
		if ( $authenticated_user_id > 0 ) {
			// Check role access.
			if ( ! $this->security_manager->check_role_access( $authenticated_user_id ) ) {
				$this->security_manager->log_security_event( 'role_denied', array( 'endpoint' => $request->get_route() ), $authenticated_user_id );
				return $this->error(
					'insufficient_role',
					__( 'Access denied: Your user role does not have permission to access this resource.', 'mcp-ai-wpoos' ),
					403
				);
			}

			// Check capability requirement.
			if ( ! $this->security_manager->check_capability_requirement( $authenticated_user_id ) ) {
				$this->security_manager->log_security_event( 'capability_denied', array( 'endpoint' => $request->get_route() ), $authenticated_user_id );
				return $this->error(
					'insufficient_capability',
					__( 'Access denied: You do not have sufficient capabilities to access this resource.', 'mcp-ai-wpoos' ),
					403
				);
			}

			// Log successful authentication if enabled.
			$this->security_manager->log_security_event(
				'auth_success',
				array(
					'method'   => $this->auth_context['token_type'] ?? 'wordpress',
					'endpoint' => $request->get_route(),
				),
				$authenticated_user_id
			);
		}

		return true;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',  // Proxy/load balancer.
			'HTTP_X_REAL_IP',        // Nginx proxy.
			'REMOTE_ADDR',           // Direct connection.
		);

		foreach ( $ip_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Get first IP if multiple (X-Forwarded-For can contain multiple IPs).
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Permission callback for requests requiring manage_options capability.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return bool|WP_Error True if user has manage_options capability, WP_Error otherwise.
	 */
	protected function permissions_check_admin( WP_REST_Request $request ) {
		// First check authentication.
		$auth_check = $this->permissions_check_authenticated( $request );
		if ( is_wp_error( $auth_check ) ) {
			return $auth_check;
		}

		// Then check capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ),
				403
			);
		}

		return true;
	}

	/**
	 * Sanitize integer parameter.
	 *
	 * @param mixed $value Parameter value.
	 * @return int Sanitized integer value.
	 */
	protected function sanitize_int( $value ) {
		return absint( $value );
	}

	/**
	 * Sanitize string parameter.
	 *
	 * @param mixed $value Parameter value.
	 * @return string Sanitized string value.
	 */
	protected function sanitize_string( $value ) {
		return sanitize_text_field( $value );
	}

	/**
	 * Sanitize array parameter.
	 *
	 * @param mixed $value Parameter value.
	 * @return array Sanitized array value.
	 */
	protected function sanitize_array( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_map( 'sanitize_text_field', $value );
	}

	/**
	 * Get current user ID from auth context.
	 *
	 * @return int Current user ID, or 0 if not authenticated.
	 */
	protected function get_current_user_id() {
		return isset( $this->auth_context['user_id'] ) ? absint( $this->auth_context['user_id'] ) : 0;
	}

	/**
	 * Check if current request is from a guest.
	 *
	 * @return bool True if guest request, false otherwise.
	 */
	protected function is_guest_request() {
		return isset( $this->auth_context['is_guest'] ) && $this->auth_context['is_guest'];
	}

	/**
	 * Get request parameter with default value.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @param string          $key     Parameter key.
	 * @param mixed           $default Default value if parameter not set.
	 * @return mixed Parameter value or default.
	 */
	protected function get_param( WP_REST_Request $request, $key, $default = null ) {
		$value = $request->get_param( $key );
		return null !== $value ? $value : $default;
	}

	/**
	 * Validate required parameters.
	 *
	 * @param WP_REST_Request $request  REST request object.
	 * @param array           $required Array of required parameter keys.
	 * @return true|WP_Error True if all required parameters present, WP_Error otherwise.
	 */
	protected function validate_required_params( WP_REST_Request $request, array $required ) {
		$missing = array();

		foreach ( $required as $param ) {
			if ( null === $request->get_param( $param ) ) {
				$missing[] = $param;
			}
		}

		if ( ! empty( $missing ) ) {
			return $this->error(
				'missing_parameters',
				sprintf(
					/* translators: %s: comma-separated list of missing parameter names */
					__( 'Missing required parameter(s): %s', 'mcp-ai-wpoos' ),
					implode( ', ', $missing )
				),
				400
			);
		}

		return true;
	}

	/**
	 * Log debug message if logging is enabled.
	 *
	 * @param string $message Log message.
	 * @param string $context Optional context identifier.
	 */
	protected function log( $message, $context = '' ) {
		if ( ! WP_MCP_AI_Error_Tracking_Service::is_logging_enabled() ) {
			return;
		}

		$prefix = $context ? "[{$context}] " : '';
		error_log( "[NV oOS REST] {$prefix}{$message}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional production logging; caller already gates on is_logging_enabled().
	}
}
