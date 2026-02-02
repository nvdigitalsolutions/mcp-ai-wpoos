<?php
/**
 * Tool that searches Google Drive files using stored OAuth credentials.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Provides an assistant tool for searching Google Drive files via the Drive REST API.
 */
class WP_MCP_AI_Tool_Search_Drive implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	const DRIVE_API_BASE = 'https://www.googleapis.com/drive/v3';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_drive';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Google Drive Files', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Searches the configured Google Drive and returns recent matches, including file names, types, and metadata.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Google Drive connection ID from Remote Sites. If not provided, uses settings-based credentials.', 'mcp-ai-wpoos' ),
				),
				'query'         => array(
					'type'        => 'string',
					'description' => __( 'Google Drive search query string. Supports the same syntax as the Drive web interface (e.g., "name contains \'report\'" or "mimeType = \'application/pdf\'").', 'mcp-ai-wpoos' ),
				),
				'max_results'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of files to return (1-50).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'page_token'    => array(
					'type'        => 'string',
					'description' => __( 'Page token returned by a previous Drive search response to fetch the next page of results.', 'mcp-ai-wpoos' ),
				),
				'folder_id'     => array(
					'type'        => 'string',
					'description' => __( 'Optional folder ID to limit search to a specific folder.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'query' ),
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

		// Get capability setting from admin configuration.
		$settings            = WP_MCP_AI_Admin_Settings::get_settings();
		$default_capability  = isset( $settings['search_drive_capability'] ) ? $settings['search_drive_capability'] : 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_search_drive_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_drive_forbidden', __( 'You do not have permission to search Google Drive.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_drive_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Check if connection_id is provided.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		$client_id       = '';
		$client_secret   = '';
		$refresh_token   = '';
		$configured_user = '';
		$folder_id       = '';

		if ( ! empty( $connection_id ) ) {
			// Load credentials from Remote Sites connection.
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( empty( $connection ) ) {
				return new WP_Error(
					'wp_mcp_ai_drive_connection_not_found',
					sprintf(
						/* translators: %s: connection ID. */
						__( 'Google Drive connection "%s" not found. Please check your connection settings.', 'mcp-ai-wpoos' ),
						$connection_id
					)
				);
			}

			if ( ! empty( $connection['connection_type'] ) && 'google_drive' !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_drive_wrong_connection_type',
					sprintf(
						/* translators: %s: connection type. */
						__( 'Connection "%s" is not a Google Drive connection. Please use a Google Drive connection type.', 'mcp-ai-wpoos' ),
						$connection['connection_type']
					)
				);
			}

			$client_id       = isset( $connection['client_id'] ) ? trim( (string) $connection['client_id'] ) : '';
			$client_secret   = isset( $connection['client_secret'] ) ? trim( (string) $connection['client_secret'] ) : '';
			$refresh_token   = isset( $connection['refresh_token'] ) ? trim( (string) $connection['refresh_token'] ) : '';
			$configured_user = isset( $connection['user_email'] ) ? trim( (string) $connection['user_email'] ) : '';
			$folder_id       = isset( $connection['folder_id'] ) ? trim( (string) $connection['folder_id'] ) : '';

			// Decrypt encrypted fields.
			if ( ! empty( $client_secret ) ) {
				$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $client_secret );
			}
			if ( ! empty( $refresh_token ) ) {
				$refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $refresh_token );
			}
		} else {
			// Fall back to settings-based credentials for backward compatibility.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			$client_id       = isset( $settings['google_drive_client_id'] ) ? trim( (string) $settings['google_drive_client_id'] ) : '';
			$client_secret   = isset( $settings['google_drive_client_secret'] ) ? trim( (string) $settings['google_drive_client_secret'] ) : '';
			$refresh_token   = isset( $settings['google_drive_refresh_token'] ) ? trim( (string) $settings['google_drive_refresh_token'] ) : '';
			$configured_user = isset( $settings['google_drive_user_email'] ) ? trim( (string) $settings['google_drive_user_email'] ) : '';
		}

		if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
			return new WP_Error(
				'wp_mcp_ai_drive_missing_credentials',
				__( 'Google Drive API credentials are not configured. Add the client ID, client secret, and refresh token either in a Google Drive connection (Remote Sites) or in the NV oOS settings.', 'mcp-ai-wpoos' )
			);
		}

		$query = isset( $arguments['query'] ) ? trim( (string) $arguments['query'] ) : '';

		if ( '' === $query ) {
			return new WP_Error( 'wp_mcp_ai_drive_missing_query', __( 'A Google Drive search query is required.', 'mcp-ai-wpoos' ) );
		}

		// Load timeout from settings.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$timeout  = isset( $settings['request_timeout'] ) ? max( 5, absint( $settings['request_timeout'] ) ) : 30;

		$max_results       = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 10;
		$max_results_limit = isset( $settings['search_drive_max_results'] ) ? absint( $settings['search_drive_max_results'] ) : 50;
		if ( $max_results < 1 ) {
			$max_results = 1;
		}
		if ( $max_results > $max_results_limit ) {
			$max_results = $max_results_limit;
		}

		$page_token = isset( $arguments['page_token'] ) ? trim( (string) $arguments['page_token'] ) : '';

		// Check if folder_id is provided in arguments (overrides connection setting).
		if ( isset( $arguments['folder_id'] ) && '' !== trim( (string) $arguments['folder_id'] ) ) {
			$folder_id = trim( (string) $arguments['folder_id'] );
		}

		// Modify query if folder_id is set.
		if ( '' !== $folder_id ) {
			$query = sprintf( "('%s' in parents) and (%s)", $folder_id, $query );
		}

		$access_token = $this->request_access_token( $client_id, $client_secret, $refresh_token, $timeout );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$list_url = $this->build_files_list_url( $query, $max_results, $page_token );

		$response = wp_remote_get(
			$list_url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Admin_Settings::log( 'Google Drive search request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error( 'wp_mcp_ai_drive_http_error', __( 'The Google Drive search request failed.', 'mcp-ai-wpoos' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			WP_MCP_AI_Admin_Settings::log( 'Google Drive search returned unexpected status.', array( 'status' => $status_code ) );

			return new WP_Error(
				'wp_mcp_ai_drive_http_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Google Drive returned an unexpected HTTP status: %d.', 'mcp-ai-wpoos' ),
					$status_code
				),
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		$body         = wp_remote_retrieve_body( $response );
		$list_payload = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $list_payload ) ) {
			WP_MCP_AI_Admin_Settings::log( 'Google Drive search returned invalid JSON.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_drive_invalid_json', __( 'Google Drive returned an invalid response.', 'mcp-ai-wpoos' ) );
		}

		$files = array();
		if ( ! empty( $list_payload['files'] ) && is_array( $list_payload['files'] ) ) {
			foreach ( $list_payload['files'] as $file ) {
				if ( empty( $file['id'] ) ) {
					continue;
				}

				$file_info = $this->format_file_info( $file );
				if ( ! empty( $file_info ) ) {
					$files[] = $file_info;
				}
			}
		}

		return $this->format_collection_response(
			$files,
			count( $files ),
			'file',
			array(
				'next_page_token' => isset( $list_payload['nextPageToken'] ) ? (string) $list_payload['nextPageToken'] : '',
				'connection'      => $configured_user ? $configured_user : __( 'Connected', 'mcp-ai-wpoos' ),
			)
		);
	}

	/**
	 * Request an access token from Google's OAuth endpoint using a stored refresh token.
	 *
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret.
	 * @param string $refresh_token Refresh token for the Drive API.
	 * @param int    $timeout       Request timeout in seconds.
	 * @return string|WP_Error
	 */
	protected function request_access_token( $client_id, $client_secret, $refresh_token, $timeout ) {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => $timeout,
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Admin_Settings::log( 'Google Drive token request failed.', array( 'error' => $response->get_error_message() ) );

			return new WP_Error( 'wp_mcp_ai_drive_token_error', __( 'Failed to refresh the Google Drive access token.', 'mcp-ai-wpoos' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			WP_MCP_AI_Admin_Settings::log( 'Google Drive token request returned unexpected status.', array( 'status' => $status_code ) );

			return new WP_Error(
				'wp_mcp_ai_drive_token_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The Google Drive token endpoint returned an unexpected status: %d.', 'mcp-ai-wpoos' ),
					$status_code
				),
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		$body    = wp_remote_retrieve_body( $response );
		$payload = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || empty( $payload['access_token'] ) ) {
			WP_MCP_AI_Admin_Settings::log( 'Google Drive token response returned invalid JSON.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_drive_token_invalid', __( 'Google Drive returned an invalid token response.', 'mcp-ai-wpoos' ) );
		}

		return (string) $payload['access_token'];
	}

	/**
	 * Format file information from Drive API response.
	 *
	 * @param array $file File data from API.
	 * @return array
	 */
	protected function format_file_info( $file ) {
		$file_id   = isset( $file['id'] ) ? (string) $file['id'] : '';
		$file_name = isset( $file['name'] ) ? (string) $file['name'] : '';
		$mime_type = isset( $file['mimeType'] ) ? (string) $file['mimeType'] : '';

		return array(
			'id'             => $file_id,
			'name'           => $file_name,
			'mimeType'       => $mime_type,
			'createdTime'    => isset( $file['createdTime'] ) ? (string) $file['createdTime'] : '',
			'modifiedTime'   => isset( $file['modifiedTime'] ) ? (string) $file['modifiedTime'] : '',
			'size'           => isset( $file['size'] ) ? (int) $file['size'] : 0,
			'webViewLink'    => isset( $file['webViewLink'] ) ? (string) $file['webViewLink'] : '',
			'webContentLink' => isset( $file['webContentLink'] ) ? (string) $file['webContentLink'] : '',
			'iconLink'       => isset( $file['iconLink'] ) ? (string) $file['iconLink'] : '',
			'thumbnailLink'  => isset( $file['thumbnailLink'] ) ? (string) $file['thumbnailLink'] : '',
			'owners'         => isset( $file['owners'] ) ? $file['owners'] : array(),
			'permissions'    => isset( $file['permissions'] ) ? $file['permissions'] : array(),
			'shared'         => isset( $file['shared'] ) ? (bool) $file['shared'] : false,
			'description'    => isset( $file['description'] ) ? (string) $file['description'] : '',
		);
	}

	/**
	 * Build the Drive files list endpoint URL with query parameters.
	 *
	 * @param string $query       Search query.
	 * @param int    $max_results Maximum number of results to return.
	 * @param string $page_token  Optional page token.
	 * @return string
	 */
	protected function build_files_list_url( $query, $max_results, $page_token ) {
		$base = self::DRIVE_API_BASE . '/files';

		$params = array(
			'q'        => $query,
			'pageSize' => $max_results,
			'fields'   => 'nextPageToken, files(id, name, mimeType, createdTime, modifiedTime, size, webViewLink, webContentLink, iconLink, thumbnailLink, owners, permissions, shared, description)',
			'orderBy'  => 'modifiedTime desc',
		);

		if ( '' !== $page_token ) {
			$params['pageToken'] = $page_token;
		}

		return add_query_arg( $params, $base );
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'integration_external',

			'pattern_compatibility' => array( 'skill_router' ),

			'profession_tags'       => array( 'office_manager', 'researcher' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Calls Google Drive API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
