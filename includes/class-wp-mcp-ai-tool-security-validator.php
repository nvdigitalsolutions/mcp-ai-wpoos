<?php
/**
 * Security validator for tool execution.
 *
 * Provides centralized capability checking and input sanitization
 * for all tool executions through the POST /tools endpoint.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates tool security before execution.
 */
class WP_MCP_AI_Tool_Security_Validator {
	/**
	 * Default required capability for tools without explicit capability checks.
	 */
	const DEFAULT_REQUIRED_CAPABILITY = 'read';

	/**
	 * Tools that should be accessible to any authenticated user.
	 *
	 * @var array
	 */
	protected static $public_tools = array(
		'count_tokens',
		'web_search',
		'get_recent_posts',
		'search_content',
		'get_open_meteo_forecast',
		'get_gdacs_events',
		'get_nhc_active_storms',
		'reliefweb_reports',
		'get_import_duty',
	);

	/**
	 * Tools that generate tokens or credentials require manage_options.
	 *
	 * @var array
	 */
	protected static $credential_tools = array(
		'generate_simple_jwt_token',
		'generate_auth0_token',
	);

	/**
	 * Tools that proxy requests to other systems inherit security from those systems.
	 *
	 * @var array
	 */
	protected static $proxy_tools = array(
		'invoke_jetengine_route',
	);

	/**
	 * Tools that access user documents/files require permission validation.
	 *
	 * @var array
	 */
	protected static $document_tools = array(
		'submit_document_prompt',
	);

	/**
	 * Validate tool execution request for security.
	 *
	 * @param WP_MCP_AI_Tool_Interface $tool      Tool instance.
	 * @param array                    $arguments Tool arguments.
	 * @param array                    $context   Execution context.
	 * @return true|WP_Error True if validation passes, WP_Error otherwise.
	 */
	public static function validate_tool_execution( $tool, array $arguments, array $context ) {
		$tool_slug = $tool->get_slug();
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		// Validate user authentication.
		$auth_check = self::validate_authentication( $user_id, $context );
		if ( is_wp_error( $auth_check ) ) {
			return $auth_check;
		}

		// Validate user capability for tool execution.
		$capability_check = self::validate_capability( $tool_slug, $user_id, $context );
		if ( is_wp_error( $capability_check ) ) {
			return $capability_check;
		}

		// Validate tool arguments for security issues.
		$sanitization_check = self::validate_arguments( $tool_slug, $arguments, $context );
		if ( is_wp_error( $sanitization_check ) ) {
			return $sanitization_check;
		}

		// Additional validation for specific tool categories.
		if ( in_array( $tool_slug, self::$document_tools, true ) ) {
			$document_check = self::validate_document_access( $arguments, $user_id );
			if ( is_wp_error( $document_check ) ) {
				return $document_check;
			}
		}

		/**
		 * Fires after built-in security validation passes.
		 *
		 * Allows third-party code to add additional validation.
		 *
		 * @param string                   $tool_slug Tool identifier.
		 * @param array                    $arguments Tool arguments.
		 * @param array                    $context   Execution context.
		 * @param WP_MCP_AI_Tool_Interface $tool      Tool instance.
		 *
		 * @return true|WP_Error True if validation passes, WP_Error to block execution.
		 */
		$custom_validation = apply_filters( 'wp_mcp_ai_validate_tool_execution', true, $tool_slug, $arguments, $context, $tool );
		if ( is_wp_error( $custom_validation ) ) {
			return $custom_validation;
		}

		return true;
	}

	/**
	 * Validate that user is authenticated.
	 *
	 * @param int   $user_id User ID.
	 * @param array $context Execution context.
	 * @return true|WP_Error
	 */
	protected static function validate_authentication( $user_id, array $context ) {
		// Check for valid user ID or token authentication.
		if ( $user_id > 0 ) {
			return true;
		}

		// Allow token-authenticated requests.
		if ( ! empty( $context['token_authenticated'] ) ) {
			return true;
		}

		return new WP_Error(
			'wp_mcp_ai_authentication_required',
			__( 'You must be authenticated to execute tools.', 'wp-mcp-ai' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Validate user has required capability for tool.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param int    $user_id   User ID.
	 * @param array  $context   Execution context.
	 * @return true|WP_Error
	 */
	protected static function validate_capability( $tool_slug, $user_id, array $context ) {
		// Public tools accessible to any authenticated user.
		if ( in_array( $tool_slug, self::$public_tools, true ) ) {
			return true;
		}

		// Credential generation tools require manage_options.
		if ( in_array( $tool_slug, self::$credential_tools, true ) ) {
			if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error(
					'wp_mcp_ai_insufficient_permissions',
					__( 'You do not have permission to generate authentication credentials.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}
			return true;
		}

		// Proxy tools validate permissions through their target systems.
		if ( in_array( $tool_slug, self::$proxy_tools, true ) ) {
			// JetEngine proxy validates permissions internally.
			return true;
		}

		// Document tools require user authentication (validated above).
		if ( in_array( $tool_slug, self::$document_tools, true ) ) {
			return true;
		}

		// All other tools should have capability checks in their execute() methods.
		// We validate they have minimum 'read' capability as a safety net.
		if ( $user_id && user_can( $user_id, 'read' ) ) {
			return true;
		}

		// If token authenticated but no user_id, allow execution.
		// The tool's own execute() method will enforce specific permissions.
		if ( ! empty( $context['token_authenticated'] ) ) {
			return true;
		}

		return new WP_Error(
			'wp_mcp_ai_insufficient_permissions',
			__( 'You do not have permission to execute this tool.', 'wp-mcp-ai' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Validate and sanitize tool arguments.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @param array  $arguments Tool arguments.
	 * @param array  $context   Execution context.
	 * @return true|WP_Error
	 */
	protected static function validate_arguments( $tool_slug, array $arguments, array $context ) {
		// Check for SQL injection attempts in string arguments.
		foreach ( $arguments as $key => $value ) {
			if ( is_string( $value ) ) {
				$check = self::check_sql_injection( $value );
				if ( is_wp_error( $check ) ) {
					WP_MCP_AI_Logger::log_security_event(
						'sql_injection_attempt',
						array(
							'tool'      => $tool_slug,
							'argument'  => $key,
							'pattern'   => $check->get_error_data(),
							'user_id'   => isset( $context['user_id'] ) ? $context['user_id'] : 0,
						)
					);
					return $check;
				}
			}
		}

		// Check for path traversal attempts in file/path arguments.
		$file_keys = array( 'file', 'path', 'filename', 'directory', 'filepath' );
		foreach ( $file_keys as $key ) {
			if ( isset( $arguments[ $key ] ) && is_string( $arguments[ $key ] ) ) {
				$check = self::check_path_traversal( $arguments[ $key ] );
				if ( is_wp_error( $check ) ) {
					WP_MCP_AI_Logger::log_security_event(
						'path_traversal_attempt',
						array(
							'tool'      => $tool_slug,
							'argument'  => $key,
							'value'     => substr( $arguments[ $key ], 0, 100 ),
							'user_id'   => isset( $context['user_id'] ) ? $context['user_id'] : 0,
						)
					);
					return $check;
				}
			}
		}

		// Check for command injection in executable arguments.
		$exec_keys = array( 'command', 'cmd', 'exec', 'shell' );
		foreach ( $exec_keys as $key ) {
			if ( isset( $arguments[ $key ] ) && is_string( $arguments[ $key ] ) ) {
				$check = self::check_command_injection( $arguments[ $key ] );
				if ( is_wp_error( $check ) ) {
					WP_MCP_AI_Logger::log_security_event(
						'command_injection_attempt',
						array(
							'tool'      => $tool_slug,
							'argument'  => $key,
							'value'     => substr( $arguments[ $key ], 0, 100 ),
							'user_id'   => isset( $context['user_id'] ) ? $context['user_id'] : 0,
						)
					);
					return $check;
				}
			}
		}

		return true;
	}

	/**
	 * Validate user has access to referenced documents.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $user_id   User ID.
	 * @return true|WP_Error
	 */
	protected static function validate_document_access( array $arguments, $user_id ) {
		// Check attachment_id access.
		if ( isset( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			if ( $attachment_id > 0 ) {
				$check = self::check_attachment_access( $attachment_id, $user_id );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}
		}

		// Check attachment_ids access.
		if ( isset( $arguments['attachment_ids'] ) && is_array( $arguments['attachment_ids'] ) ) {
			foreach ( $arguments['attachment_ids'] as $attachment_id ) {
				$attachment_id = absint( $attachment_id );
				if ( $attachment_id > 0 ) {
					$check = self::check_attachment_access( $attachment_id, $user_id );
					if ( is_wp_error( $check ) ) {
						return $check;
					}
				}
			}
		}

		// Check structured attachments.
		if ( isset( $arguments['attachments'] ) && is_array( $arguments['attachments'] ) ) {
			foreach ( $arguments['attachments'] as $attachment ) {
				if ( ! is_array( $attachment ) ) {
					continue;
				}

				$attachment_id = 0;
				if ( isset( $attachment['attachment_id'] ) ) {
					$attachment_id = absint( $attachment['attachment_id'] );
				} elseif ( isset( $attachment['id'] ) && is_numeric( $attachment['id'] ) ) {
					$attachment_id = absint( $attachment['id'] );
				}

				if ( $attachment_id > 0 ) {
					$check = self::check_attachment_access( $attachment_id, $user_id );
					if ( is_wp_error( $check ) ) {
						return $check;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Check if user can access an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $user_id       User ID.
	 * @return true|WP_Error
	 */
	protected static function check_attachment_access( $attachment_id, $user_id ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_attachment',
				__( 'The specified attachment does not exist.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		// Allow if user owns the attachment.
		if ( $user_id > 0 && absint( $attachment->post_author ) === $user_id ) {
			return true;
		}

		// Allow if attachment is public.
		if ( 'publish' === $attachment->post_status || 'inherit' === $attachment->post_status ) {
			return true;
		}

		// Check read_post capability for private attachments.
		if ( $user_id > 0 && current_user_can( 'read_post', $attachment_id ) ) {
			return true;
		}

		return new WP_Error(
			'wp_mcp_ai_attachment_forbidden',
			__( 'You do not have permission to access this attachment.', 'wp-mcp-ai' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Check for SQL injection patterns.
	 *
	 * @param string $value String to check.
	 * @return true|WP_Error
	 */
	protected static function check_sql_injection( $value ) {
		$patterns = array(
			'/(\bUNION\b.*\bSELECT\b)/i',
			'/(\bSELECT\b.*\bFROM\b.*\bWHERE\b)/i',
			'/(\'.*\bOR\b.*\'.*=.*\')/i',
			'/(--|\#|\/\*|\*\/)/i',
			'/(\bDROP\b.*\bTABLE\b)/i',
			'/(\bINSERT\b.*\bINTO\b)/i',
			'/(\bUPDATE\b.*\bSET\b)/i',
			'/(\bDELETE\b.*\bFROM\b)/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $value ) ) {
				return new WP_Error(
					'wp_mcp_ai_sql_injection_detected',
					__( 'Potential SQL injection detected in tool arguments.', 'wp-mcp-ai' ),
					array(
						'status'  => 400,
						'pattern' => $pattern,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Check for path traversal attempts.
	 *
	 * @param string $value Path to check.
	 * @return true|WP_Error
	 */
	protected static function check_path_traversal( $value ) {
		$patterns = array(
			'/\.\.\//',
			'/\.\.\\\\/',
			'/%2e%2e%2f/i',
			'/%2e%2e\//',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $value ) ) {
				return new WP_Error(
					'wp_mcp_ai_path_traversal_detected',
					__( 'Path traversal attempt detected in tool arguments.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Check for command injection attempts.
	 *
	 * @param string $value Command to check.
	 * @return true|WP_Error
	 */
	protected static function check_command_injection( $value ) {
		$patterns = array(
			'/[;&|`$()]/',
			'/\n/',
			'/\r/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $value ) ) {
				return new WP_Error(
					'wp_mcp_ai_command_injection_detected',
					__( 'Command injection attempt detected in tool arguments.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}
}
