<?php
/**
 * tools/chat-channels/class-wp-mcp-ai-pro-tool-upload-icloud-drive-file.php (ecosystem port — Wave F5, chat-channels tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-upload-icloud-drive-file.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the base-owned interface/logger/restrict/cron-manager
 * requires gain exists-check seams resolving from the addon's D8-compat `src/` copies.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
// Standalone seam (documented deviation): the base-owned logger require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}

/**
 * Provides a tool for uploading files to iCloud Drive via a configured gateway API.
 */
class WP_MCP_AI_Pro_Tool_Upload_Icloud_Drive_File implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for iCloud gateway API requests (seconds).
	 */
	const DEFAULT_TIMEOUT = 30;

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
		return 'upload_icloud_drive_file';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Upload iCloud Drive File', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Uploads a file to iCloud Drive via a configured iCloud gateway API.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'gateway_url'  => array(
					'type'        => 'string',
					'description' => __( 'Base URL of the iCloud gateway API endpoint (must be HTTPS).', 'nvoos-content-graph-pro' ),
				),
				'api_key'      => array(
					'type'        => 'string',
					'description' => __( 'API key or bearer token for the iCloud gateway.', 'nvoos-content-graph-pro' ),
				),
				'file_name'    => array(
					'type'        => 'string',
					'description' => __( 'Destination filename in iCloud Drive.', 'nvoos-content-graph-pro' ),
				),
				'content'      => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded file content.', 'nvoos-content-graph-pro' ),
				),
				'folder_id'    => array(
					'type'        => 'string',
					'description' => __( 'Optional target folder ID in iCloud Drive. Omit for root.', 'nvoos-content-graph-pro' ),
				),
				'content_type' => array(
					'type'        => 'string',
					'description' => __( 'MIME type of the file (default: application/octet-stream).', 'nvoos-content-graph-pro' ),
					'default'     => 'application/octet-stream',
				),
			),
			'required'             => array( 'gateway_url', 'api_key', 'file_name', 'content' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool result or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_upload_icloud_drive_file_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to upload files to iCloud Drive.', 'nvoos-content-graph-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-pro' ) );
		}

		// Validate and sanitize required parameters.
		$gateway_url = isset( $arguments['gateway_url'] ) ? esc_url_raw( trim( $arguments['gateway_url'] ) ) : '';
		if ( '' === $gateway_url ) {
			return new WP_Error( 'wp_mcp_ai_missing_icloud_gateway_url', __( 'A valid iCloud gateway API URL is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( ! filter_var( $gateway_url, FILTER_VALIDATE_URL ) || 0 !== strpos( $gateway_url, 'https://' ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_icloud_gateway_url', __( 'The iCloud gateway API URL must be a valid HTTPS URL.', 'nvoos-content-graph-pro' ) );
		}

		$api_key = isset( $arguments['api_key'] ) ? $this->sanitize_api_key( $arguments['api_key'] ) : '';
		if ( '' === $api_key ) {
			return new WP_Error( 'wp_mcp_ai_missing_icloud_api_key', __( 'A valid iCloud gateway API key is required.', 'nvoos-content-graph-pro' ) );
		}

		$file_name = isset( $arguments['file_name'] ) ? sanitize_text_field( trim( $arguments['file_name'] ) ) : '';
		if ( '' === $file_name ) {
			return new WP_Error( 'wp_mcp_ai_missing_icloud_file_name', __( 'A destination filename is required.', 'nvoos-content-graph-pro' ) );
		}

		$content = isset( $arguments['content'] ) && is_string( $arguments['content'] ) ? trim( $arguments['content'] ) : '';
		if ( '' === $content ) {
			return new WP_Error( 'wp_mcp_ai_missing_icloud_content', __( 'Base64-encoded file content is required.', 'nvoos-content-graph-pro' ) );
		}

		// Validate base64 content.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded_content = base64_decode( $content, true );
		if ( false === $decoded_content ) {
			return new WP_Error( 'wp_mcp_ai_invalid_icloud_content', __( 'The provided content is not valid base64.', 'nvoos-content-graph-pro' ) );
		}

		$content_type = isset( $arguments['content_type'] ) && is_string( $arguments['content_type'] )
			? sanitize_text_field( trim( $arguments['content_type'] ) )
			: 'application/octet-stream';

		// Build the request payload.
		$payload = array(
			'fileName'    => $file_name,
			'content'     => $content,
			'contentType' => $content_type,
		);

		if ( ! empty( $arguments['folder_id'] ) && is_string( $arguments['folder_id'] ) ) {
			$payload['folderId'] = sanitize_text_field( $arguments['folder_id'] );
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return new WP_Error( 'wp_mcp_ai_icloud_encoding_error', __( 'Failed to encode the iCloud Drive request payload.', 'nvoos-content-graph-pro' ) );
		}

		WP_MCP_AI_Logger::log_event(
			'icloud_upload_file_request',
			'Uploading file to iCloud Drive.',
			array(
				'gateway_url' => $gateway_url,
				'file_name'   => $file_name,
				'folder_id'   => isset( $payload['folderId'] ) ? $this->mask_sensitive_value( $payload['folderId'] ) : '(root)',
			)
		);

		$response = wp_remote_post(
			$gateway_url,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_upload_icloud_drive_file_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'iCloud Drive upload file request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error(
				'wp_mcp_ai_icloud_http_error',
				__( 'The iCloud Drive gateway API request failed.', 'nvoos-content-graph-pro' ),
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
			$message_text = __( 'The iCloud Drive gateway API returned an error.', 'nvoos-content-graph-pro' );

			if ( is_array( $decoded ) ) {
				foreach ( array( 'message', 'error', 'errorMessage', 'detail' ) as $key ) {
					if ( isset( $decoded[ $key ] ) && is_string( $decoded[ $key ] ) ) {
						$message_text = $decoded[ $key ];
						break;
					}
				}
			}

			WP_MCP_AI_Logger::log_error(
				'iCloud Drive upload file request was not successful.',
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
			'write',                // Uploads files.
			'external-api',         // Calls iCloud gateway API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
