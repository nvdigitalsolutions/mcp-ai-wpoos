<?php
/**
 * Tool for retrieving metadata and download information for an iCloud Drive file.
 *
 * iCloud does not expose a public REST API for third-party applications.
 * This tool communicates with a user-configured gateway/proxy service that
 * bridges requests to Apple CloudKit or iCloud Drive services.
 *
 * Industry references:
 * - https://developer.apple.com/documentation/cloudkit
 * - https://developer.apple.com/icloud/
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for retrieving iCloud Drive file metadata via a configured gateway API.
 */
class WP_MCP_AI_Pro_Tool_Get_iCloud_Drive_File implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for iCloud gateway API requests (seconds).
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true - no dependencies required.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_icloud_drive_file';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get iCloud Drive File', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves metadata and download information for a file from iCloud Drive via a configured iCloud gateway API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'gateway_url' => array(
					'type'        => 'string',
					'description' => __( 'Base URL of the iCloud gateway API endpoint (must be HTTPS).', 'mcp-ai-wpoos-pro' ),
				),
				'api_key'     => array(
					'type'        => 'string',
					'description' => __( 'API key or bearer token for the iCloud gateway.', 'mcp-ai-wpoos-pro' ),
				),
				'file_id'     => array(
					'type'        => 'string',
					'description' => __( 'File identifier in iCloud Drive.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'gateway_url', 'api_key', 'file_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_get_icloud_drive_file_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to retrieve iCloud Drive files.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate and sanitize required parameters.
		$gateway_url = isset( $arguments['gateway_url'] ) ? esc_url_raw( trim( $arguments['gateway_url'] ) ) : '';
		if ( '' === $gateway_url ) {
			return new WP_Error( 'wp_mcp_ai_missing_icloud_gateway_url', __( 'A valid iCloud gateway API URL is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! filter_var( $gateway_url, FILTER_VALIDATE_URL ) || 0 !== strpos( $gateway_url, 'https://' ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_icloud_gateway_url', __( 'The iCloud gateway API URL must be a valid HTTPS URL.', 'mcp-ai-wpoos-pro' ) );
		}

		$api_key = isset( $arguments['api_key'] ) ? $this->sanitize_api_key( $arguments['api_key'] ) : '';
		if ( '' === $api_key ) {
			return new WP_Error( 'wp_mcp_ai_missing_icloud_api_key', __( 'A valid iCloud gateway API key is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$file_id = isset( $arguments['file_id'] ) ? sanitize_text_field( trim( $arguments['file_id'] ) ) : '';
		if ( '' === $file_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_icloud_file_id', __( 'A file ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Build query parameters.
		$query_params = array(
			'fileId' => $file_id,
		);

		$endpoint = add_query_arg( $query_params, $gateway_url );

		WP_MCP_AI_Logger::log_event(
			'icloud_get_file_request',
			'Retrieving iCloud Drive file metadata.',
			array(
				'gateway_url' => $gateway_url,
				'file_id'     => $this->mask_sensitive_value( $file_id ),
			)
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_get_icloud_drive_file_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'iCloud Drive get file request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_icloud_http_error',
				__( 'The iCloud Drive gateway API request failed.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( $code < 200 || $code >= 300 ) {
			$message_text = __( 'The iCloud Drive gateway API returned an error.', 'mcp-ai-wpoos-pro' );

			if ( is_array( $decoded ) ) {
				foreach ( array( 'message', 'error', 'errorMessage', 'detail' ) as $key ) {
					if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
						$message_text = $decoded[ $key ];
						break;
					}
				}
			}

			WP_MCP_AI_Logger::log_error(
				'iCloud Drive get file request was not successful.',
				array(
					'http_code' => $code,
					'response'  => $decoded,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_icloud_api_error',
				esc_html( $message_text ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Sanitize an API key / bearer token.
	 *
	 * @param mixed $key Raw key value.
	 * @return string
	 */
	protected function sanitize_api_key( $key ) {
		if ( ! is_string( $key ) && ! is_numeric( $key ) ) {
			return '';
		}

		return trim( (string) $key );
	}

	/**
	 * Mask a sensitive value so it can be safely logged.
	 *
	 * @param string $value Sensitive value.
	 * @return string
	 */
	protected function mask_sensitive_value( $value ) {
		$value  = (string) $value;
		$length = strlen( $value );

		if ( 0 === $length ) {
			return '';
		}

		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		return substr( $value, 0, 2 ) . str_repeat( '*', $length - 4 ) . substr( $value, -2 );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read-only',            // Only reads data.
			'external-api',         // Calls iCloud gateway API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
