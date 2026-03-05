<?php
/**
 * Tool that uploads a file to Microsoft OneDrive.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for uploading files to Microsoft OneDrive via the Microsoft Graph API.
 */
class WP_MCP_AI_Pro_Tool_Upload_OneDrive_File implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Default timeout for Microsoft Graph API requests.
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Always true - no dependencies.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'upload_onedrive_file';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Upload OneDrive File', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Uploads a file to Microsoft OneDrive using the Microsoft Graph API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'token'        => array(
					'type'        => 'string',
					'description' => __( 'Microsoft Graph API access token (Bearer token) used for authentication.', 'mcp-ai-wpoos-pro' ),
				),
				'file_path'    => array(
					'type'        => 'string',
					'description' => __( 'Destination path including filename, e.g. "Documents/report.docx".', 'mcp-ai-wpoos-pro' ),
				),
				'content'      => array(
					'type'        => 'string',
					'description' => __( 'Base64 encoded file content to upload.', 'mcp-ai-wpoos-pro' ),
				),
				'content_type' => array(
					'type'        => 'string',
					'description' => __( 'MIME type of the file (default: application/octet-stream).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'token', 'file_path', 'content' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_upload_onedrive_file_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to upload OneDrive files.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$token = isset( $arguments['token'] ) ? $this->sanitize_token( $arguments['token'] ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wp_mcp_ai_missing_onedrive_token', __( 'A valid Microsoft Graph API access token is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$file_path = isset( $arguments['file_path'] ) ? sanitize_text_field( $arguments['file_path'] ) : '';

		if ( '' === $file_path ) {
			return new WP_Error( 'wp_mcp_ai_missing_file_path', __( 'A destination file path is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$content = isset( $arguments['content'] ) ? $arguments['content'] : '';

		if ( '' === $content ) {
			return new WP_Error( 'wp_mcp_ai_missing_file_content', __( 'File content must be provided.', 'mcp-ai-wpoos-pro' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded_content = base64_decode( $content, true );

		if ( false === $decoded_content ) {
			return new WP_Error( 'wp_mcp_ai_invalid_file_content', __( 'The file content is not valid base64 encoded data.', 'mcp-ai-wpoos-pro' ) );
		}

		$content_type = isset( $arguments['content_type'] ) ? sanitize_text_field( $arguments['content_type'] ) : 'application/octet-stream';

		// URL-encode each path segment individually, preserving '/' as the separator.
		$encoded_path = implode( '/', array_map( 'rawurlencode', explode( '/', $file_path ) ) );
		$endpoint     = 'https://graph.microsoft.com/v1.0/me/drive/root:/' . $encoded_path . ':/content';

		WP_MCP_AI_Logger::log_event(
			'onedrive_upload_file_request',
			'Uploading file to OneDrive.',
			array(
				'endpoint'  => $endpoint,
				'file_path' => $file_path,
			)
		);

		$response = wp_remote_request(
			$endpoint,
			array(
				'method'  => 'PUT',
				'headers' => array(
					'Content-Type'  => $content_type,
					'Authorization' => 'Bearer ' . $token,
				),
				'timeout' => apply_filters( 'wp_mcp_ai_upload_onedrive_file_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $decoded_content,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'OneDrive upload file request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_onedrive_http_error',
				__( 'The Microsoft Graph API request failed to send.', 'mcp-ai-wpoos-pro' ),
				array( 'error' => $response )
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( null === $decoded ) {
			$decoded = array();
		}

		if ( 200 !== $code && 201 !== $code ) {
			$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Microsoft Graph API returned an error.', 'mcp-ai-wpoos-pro' );

			WP_MCP_AI_Logger::log_error(
				'OneDrive upload file request was not successful.',
				array(
					'http_code' => $code,
					'file_path' => $file_path,
					'error'     => $message,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_onedrive_api_error',
				esc_html( $message ),
				array(
					'code'     => $code,
					'response' => $decoded,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Sanitize a Microsoft Graph API access token.
	 *
	 * @param string $token Raw token value.
	 * @return string
	 */
	protected function sanitize_token( $token ) {
		if ( ! is_string( $token ) && ! is_numeric( $token ) ) {
			return '';
		}

		$token = trim( (string) $token );

		if ( '' === $token ) {
			return '';
		}

		return $token;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Uploads files to OneDrive.
			'external-api',         // Calls Microsoft Graph API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
