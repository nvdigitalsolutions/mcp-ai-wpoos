<?php
/**
 * Tool that searches Google Drive files using stored OAuth credentials.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';

/**
 * Provides an assistant tool for searching Google Drive files via the Drive REST API.
 */
class WP_MCP_AI_Pro_Tool_Search_Drive implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'Search Google Drive Files and Folders', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Searches Google Drive and returns matching files and folders with names, types, and metadata. Supports simple text queries (e.g., "report") or advanced Drive query syntax (e.g., "name contains \'invoice\'" or "mimeType = \'application/pdf\'"). Automatically excludes trashed items. Can include shared files and folders, and sort by creation or modification time.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Optional Google Drive connection ID from Remote Sites. If not provided, uses settings-based credentials.', 'mcp-ai-wpoos-pro' ),
				),
				'query'         => array(
					'type'        => 'string',
					'description' => __( 'Search query. Simple text (e.g., "360" or "report") searches all file and folder content. Advanced syntax supported: "name contains \'text\'" for names, "mimeType = \'application/pdf\'" for file types, "mimeType = \'application/vnd.google-apps.folder\'" for folders. Combine with "and"/"or". Auto-excludes trashed items unless "trashed = true" specified.', 'mcp-ai-wpoos-pro' ),
				),
				'item_type'     => array(
					'type'        => 'string',
					'description' => __( 'Filter results by type: "all" (default, includes both files and folders), "files" (files only), or "folders" (folders only).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'all', 'files', 'folders' ),
					'default'     => 'all',
				),
				'max_results'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of files to return (1-50).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'page_token'    => array(
					'type'        => 'string',
					'description' => __( 'Page token returned by a previous Drive search response to fetch the next page of results.', 'mcp-ai-wpoos-pro' ),
				),
				'folder_id'     => array(
					'type'        => 'string',
					'description' => __( 'Optional folder ID to limit search to a specific folder.', 'mcp-ai-wpoos-pro' ),
				),
				'include_shared' => array(
					'type'        => 'boolean',
					'description' => __( 'Include files and folders shared with the user. When true, searches both owned and shared items. Default is false (owned items only).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'sort_by'       => array(
					'type'        => 'string',
					'description' => __( 'Sort results by time: "modified" (default, most recently modified first) or "created" (most recently created/added first).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'modified', 'created' ),
					'default'     => 'modified',
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

		$required_capability = apply_filters( 'wp_mcp_ai_search_drive_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_drive_forbidden', __( 'You do not have permission to search Google Drive.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_drive_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
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
						__( 'Google Drive connection "%s" not found. Please check your connection settings.', 'mcp-ai-wpoos-pro' ),
						$connection_id
					)
				);
			}

			if ( ! empty( $connection['connection_type'] ) && 'google_drive' !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_drive_wrong_connection_type',
					sprintf(
						/* translators: %s: connection type. */
						__( 'Connection "%s" is not a Google Drive connection. Please use a Google Drive connection type.', 'mcp-ai-wpoos-pro' ),
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
				__( 'Google Drive API credentials are not configured. Add the client ID, client secret, and refresh token either in a Google Drive connection (Remote Sites) or in the NV oOS settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		$query = isset( $arguments['query'] ) ? trim( (string) $arguments['query'] ) : '';

		if ( '' === $query ) {
			return new WP_Error( 'wp_mcp_ai_drive_missing_query', __( 'A Google Drive search query is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Format query if it's a simple text search without Drive operators.
		$query = $this->format_drive_query( $query );

		// Apply item type filter if specified.
		$item_type = isset( $arguments['item_type'] ) ? trim( strtolower( (string) $arguments['item_type'] ) ) : 'all';
		$query     = $this->apply_item_type_filter( $query, $item_type );

		// Get include_shared parameter.
		$include_shared = isset( $arguments['include_shared'] ) ? (bool) $arguments['include_shared'] : false;

		// Load timeout from settings.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$timeout  = isset( $settings['request_timeout'] ) ? max( 5, absint( $settings['request_timeout'] ) ) : 30;

		$max_results = isset( $arguments['max_results'] ) ? absint( $arguments['max_results'] ) : 10;
		if ( $max_results < 1 ) {
			$max_results = 1;
		}
		if ( $max_results > 50 ) {
			$max_results = 50;
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

		// Get sort_by parameter.
		$sort_by = isset( $arguments['sort_by'] ) ? trim( strtolower( (string) $arguments['sort_by'] ) ) : 'modified';
		if ( ! in_array( $sort_by, array( 'modified', 'created' ), true ) ) {
			$sort_by = 'modified';
		}

		$access_token = $this->request_access_token( $client_id, $client_secret, $refresh_token, $timeout );
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$list_url = $this->build_files_list_url( $query, $max_results, $page_token, $include_shared, $sort_by );

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

			return new WP_Error( 'wp_mcp_ai_drive_http_error', __( 'The Google Drive search request failed.', 'mcp-ai-wpoos-pro' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$body         = wp_remote_retrieve_body( $response );
			$error_detail = json_decode( $body, true );

			// Extract detailed error message from Google's response if available.
			$error_message = '';
			if ( is_array( $error_detail ) && isset( $error_detail['error']['message'] ) ) {
				$error_message = $error_detail['error']['message'];
			}

			// Log the error with full details.
			WP_MCP_AI_Admin_Settings::log(
				'Google Drive search returned unexpected status.',
				array(
					'status'        => $status_code,
					'query'         => $query,
					'error_message' => $error_message,
					'body'          => $body,
				)
			);

			// Provide helpful error messages based on status code.
			if ( 400 === $status_code ) {
				$user_message = __( 'Google Drive query syntax error. The search query may be malformed.', 'mcp-ai-wpoos-pro' );
				if ( $error_message ) {
					/* translators: %s: Google's error message. */
					$user_message = sprintf( __( 'Google Drive query error: %s', 'mcp-ai-wpoos-pro' ), $error_message );
				} else {
					$user_message .= ' ' . __( 'Supported query examples: "report", "name contains \'invoice\'", "mimeType = \'application/pdf\'".', 'mcp-ai-wpoos-pro' );
				}
			} elseif ( 401 === $status_code || 403 === $status_code ) {
				$user_message = __( 'Google Drive authentication error. The access token may have expired or credentials are invalid.', 'mcp-ai-wpoos-pro' );
			} elseif ( 404 === $status_code ) {
				$user_message = __( 'Google Drive resource not found. The folder or file may have been deleted.', 'mcp-ai-wpoos-pro' );
			} else {
				/* translators: %d: HTTP status code. */
				$user_message = sprintf( __( 'Google Drive returned an unexpected HTTP status: %d.', 'mcp-ai-wpoos-pro' ), $status_code );
			}

			return new WP_Error(
				'wp_mcp_ai_drive_http_status',
				$user_message,
				array(
					'status'        => $status_code,
					'body'          => $body,
					'query'         => $query,
					'error_message' => $error_message,
				)
			);
		}

		$body         = wp_remote_retrieve_body( $response );
		$list_payload = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $list_payload ) ) {
			WP_MCP_AI_Admin_Settings::log( 'Google Drive search returned invalid JSON.', array( 'body' => $body ) );

			return new WP_Error( 'wp_mcp_ai_drive_invalid_json', __( 'Google Drive returned an invalid response.', 'mcp-ai-wpoos-pro' ) );
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

		return array(
			'files'           => $files,
			'next_page_token' => isset( $list_payload['nextPageToken'] ) ? (string) $list_payload['nextPageToken'] : '',
			'connection'      => $configured_user ? $configured_user : __( 'Connected', 'mcp-ai-wpoos-pro' ),
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

			return new WP_Error( 'wp_mcp_ai_drive_token_error', __( 'Failed to refresh the Google Drive access token.', 'mcp-ai-wpoos-pro' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			WP_MCP_AI_Admin_Settings::log( 'Google Drive token request returned unexpected status.', array( 'status' => $status_code ) );

			return new WP_Error(
				'wp_mcp_ai_drive_token_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The Google Drive token endpoint returned an unexpected status: %d.', 'mcp-ai-wpoos-pro' ),
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

			return new WP_Error( 'wp_mcp_ai_drive_token_invalid', __( 'Google Drive returned an invalid token response.', 'mcp-ai-wpoos-pro' ) );
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
	 * Format a Drive search query, converting simple text to proper Drive API syntax.
	 *
	 * Google Drive API requires specific query syntax with string values in single quotes.
	 * If the query doesn't contain Drive operators, wrap it as a fullText search.
	 * Best practice: Automatically filters out trashed files unless explicitly included.
	 *
	 * Examples:
	 *   "360" -> "fullText contains '360' and trashed = false"
	 *   "report" -> "fullText contains 'report' and trashed = false"
	 *   "name contains 'test'" -> "name contains 'test' and trashed = false"
	 *   "trashed = true" -> "trashed = true" (preserves explicit trash queries)
	 *
	 * @since 1.0.0
	 * @param string $query The user-provided query string.
	 * @return string Formatted Drive API query with proper syntax and trash filter.
	 */
	protected function format_drive_query( $query ) {
		// Check if query already contains Drive operators.
		$drive_operators = array( ' contains ', ' = ', ' != ', ' < ', ' > ', ' <= ', ' >= ', ' in ', ' and ', ' or ', ' not ' );
		$has_operators   = false;

		foreach ( $drive_operators as $operator ) {
			if ( false !== stripos( $query, $operator ) ) {
				$has_operators = true;
				break;
			}
		}

		// If query doesn't have operators, wrap as fullText search.
		if ( ! $has_operators ) {
			// Escape backslashes first, then single quotes per Drive API query string requirements.
			$escaped_query = str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $query );
			$query         = sprintf( "fullText contains '%s'", $escaped_query );
		}

		// Best practice: Filter out trashed files by default unless user explicitly queries for them.
		// Check if query already mentions 'trashed' to avoid duplication.
		if ( false === stripos( $query, 'trashed' ) ) {
			$query .= ' and trashed = false';
		}

		return $query;
	}

	/**
	 * Apply item type filter to query (files only, folders only, or all).
	 *
	 * @since 1.0.0
	 * @param string $query     The formatted query string.
	 * @param string $item_type The item type filter: 'all', 'files', or 'folders'.
	 * @return string Query with item type filter applied.
	 */
	protected function apply_item_type_filter( $query, $item_type ) {
		// Check if query already explicitly filters by mimeType to avoid conflicts.
		if ( false !== stripos( $query, 'mimeType' ) ) {
			return $query;
		}

		if ( 'files' === $item_type ) {
			// Exclude folders (folders have mimeType = 'application/vnd.google-apps.folder').
			$query .= " and mimeType != 'application/vnd.google-apps.folder'";
		} elseif ( 'folders' === $item_type ) {
			// Include only folders.
			$query .= " and mimeType = 'application/vnd.google-apps.folder'";
		}
		// 'all' or any other value: no filter, returns both files and folders.

		return $query;
	}

	/**
	 * Build the Drive files list endpoint URL with query parameters.
	 *
	 * @param string $query          Search query.
	 * @param int    $max_results    Maximum number of results to return.
	 * @param string $page_token     Optional page token.
	 * @param bool   $include_shared Whether to include shared files and folders.
	 * @param string $sort_by        Sort by 'modified' or 'created' time.
	 * @return string
	 */
	protected function build_files_list_url( $query, $max_results, $page_token, $include_shared = false, $sort_by = 'modified' ) {
		$base = self::DRIVE_API_BASE . '/files';

		// Determine sort order.
		$order_by = ( 'created' === $sort_by ) ? 'createdTime desc' : 'modifiedTime desc';

		$params = array(
			'q'        => $query,
			'pageSize' => $max_results,
			'fields'   => 'nextPageToken, files(id, name, mimeType, createdTime, modifiedTime, size, webViewLink, webContentLink, iconLink, thumbnailLink, owners, permissions, shared, description)',
			'orderBy'  => $order_by,
		);

		// Best practice: Include shared drives and shared items when requested.
		if ( $include_shared ) {
			$params['corpora']                   = 'allDrives';
			$params['includeItemsFromAllDrives'] = 'true';
			$params['supportsAllDrives']         = 'true';
		}

		if ( '' !== $page_token ) {
			$params['pageToken'] = $page_token;
		}

		return add_query_arg( $params, $base );
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
