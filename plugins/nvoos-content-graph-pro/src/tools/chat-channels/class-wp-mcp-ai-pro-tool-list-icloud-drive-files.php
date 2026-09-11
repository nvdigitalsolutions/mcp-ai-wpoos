<?php
/**
 * tools/chat-channels/class-wp-mcp-ai-pro-tool-list-icloud-drive-files.php (ecosystem port — Wave F5, chat-channels tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/chat-channels/class-wp-mcp-ai-pro-tool-list-icloud-drive-files.php` for the
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
 * Provides a tool for listing iCloud Drive files and folders via a configured gateway API.
 */
class WP_MCP_AI_Pro_Tool_List_Icloud_Drive_Files implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Default timeout for iCloud gateway API requests (seconds).
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Maximum items per request.
	 */
	const MAX_LIMIT = 100;

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
		return 'list_icloud_drive_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List iCloud Drive Files', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists files and folders from an iCloud Drive account via a configured iCloud gateway API. iCloud does not provide a direct third-party REST API; this tool communicates with a gateway service that bridges to Apple CloudKit or iCloud services.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Base URL of the iCloud gateway API endpoint (must be HTTPS).', 'nvoos-content-graph-pro' ),
				),
				'api_key'     => array(
					'type'        => 'string',
					'description' => __( 'API key or bearer token for the iCloud gateway.', 'nvoos-content-graph-pro' ),
				),
				'folder_id'   => array(
					'type'        => 'string',
					'description' => __( 'Optional folder identifier to list contents of. Omit for root.', 'nvoos-content-graph-pro' ),
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of items to retrieve (1-100).', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 25,
				),
				'offset'      => array(
					'type'        => 'string',
					'description' => __( 'Optional pagination cursor returned by a previous response.', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'gateway_url', 'api_key' ),
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
		$required_capability = apply_filters( 'wp_mcp_ai_list_icloud_drive_files_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list iCloud Drive files.', 'nvoos-content-graph-pro' ) );
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

		// Build query parameters.
		$query_params = array(
			'limit' => $this->resolve_limit( $arguments ),
		);

		if ( ! empty( $arguments['folder_id'] ) && is_string( $arguments['folder_id'] ) ) {
			$query_params['folderId'] = sanitize_text_field( $arguments['folder_id'] );
		}

		if ( ! empty( $arguments['offset'] ) && is_string( $arguments['offset'] ) ) {
			$query_params['offset'] = sanitize_text_field( $arguments['offset'] );
		}

		$endpoint = add_query_arg( $query_params, $gateway_url );

		WP_MCP_AI_Logger::log_event(
			'icloud_list_files_request',
			'Listing iCloud Drive files and folders.',
			array(
				'gateway_url' => $gateway_url,
				'folder_id'   => isset( $query_params['folderId'] ) ? $this->mask_sensitive_value( $query_params['folderId'] ) : '(root)',
				'limit'       => $query_params['limit'],
			)
		);

		$response = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
				'timeout' => apply_filters( 'wp_mcp_ai_list_icloud_drive_files_timeout', self::DEFAULT_TIMEOUT, $context, $arguments ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error( 'iCloud Drive list files request failed.', array( 'error' => $response->get_error_message() ) );

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
				'iCloud Drive list files request was not successful.',
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
	 * Resolve the limit parameter with bounds checking.
	 *
	 * @param array $arguments Tool arguments.
	 * @return int
	 */
	protected function resolve_limit( $arguments ) {
		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 25;

		if ( $limit < 1 ) {
			$limit = 1;
		} elseif ( $limit > self::MAX_LIMIT ) {
			$limit = self::MAX_LIMIT;
		}

		return $limit;
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
