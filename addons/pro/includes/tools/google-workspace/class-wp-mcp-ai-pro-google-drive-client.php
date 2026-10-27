<?php
/**
 * Shared Google Drive API client for the Google Workspace tool family.
 *
 * Centralises OAuth credential resolution, token refresh, HTTP transport,
 * metadata retrieval, Google Docs plain-text export, and folder-child listing
 * so the Drive tools stay thin canonical-envelope wrappers.
 *
 * Self-contained by design (mirrors WP_MCP_AI_Pro_Gmail_Client): the Drive
 * tools must not depend on classes that may not exist in every branch.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Drive API client helpers shared by the Drive tool family.
 */
class WP_MCP_AI_Pro_Google_Drive_Client {

	const TOKEN_ENDPOINT  = 'https://oauth2.googleapis.com/token';
	const DRIVE_API_BASE  = 'https://www.googleapis.com/drive/v3';
	const SHEETS_API_BASE = 'https://sheets.googleapis.com/v4';

	/**
	 * MIME types of Google Docs files that can be exported to plain text.
	 *
	 * Keep deliberately narrow: drawings, forms, sites, and Jamboard files
	 * do not export to text/plain and would only produce API errors.
	 */
	const TEXT_EXPORTABLE_MIME_TYPES = array(
		'application/vnd.google-apps.document',
		'application/vnd.google-apps.spreadsheet',
		'application/vnd.google-apps.presentation',
	);

	/**
	 * Resolve Drive credentials from a Remote Sites connection or from base settings.
	 *
	 * When the execution context carries an assistant whose Remote Site
	 * Connections are restricted (the assistant editor metabox), resolution is
	 * limited to the enabled connections and the settings fallback is refused.
	 *
	 * @param string $connection_id Optional Remote Sites connection ID. Empty falls back to settings.
	 * @param array  $context       Optional execution context (assistant_id is honoured).
	 * @return array|WP_Error Credentials array with keys client_id, client_secret,
	 *         refresh_token, configured_user and source — or WP_Error.
	 */
	public static function resolve_credentials( $connection_id, array $context = array() ) {
		$connection_id = sanitize_key( (string) $connection_id );

		// The tool schema documents the fallback as "settings-based
		// credentials"; assistants sometimes pass the literal string
		// "settings" instead of omitting the argument. Treat it as the
		// settings fallback so the call self-corrects instead of failing
		// with connection_not_found.
		if ( 'settings' === $connection_id ) {
			$connection_id = '';
		}

		$client_id       = '';
		$client_secret   = '';
		$refresh_token   = '';
		$configured_user = '';
		$source          = 'settings';
		$resolved_id     = '';
		$resolved_name   = '';

		if ( '' !== $connection_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			}

			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( empty( $connection ) ) {
				return new WP_Error(
					'wp_mcp_ai_drive_connection_not_found',
					sprintf(
						/* translators: %s: connection ID. */
						__( 'Google Drive connection "%s" not found. Call list_drive_connections to see available connection IDs.', 'mcp-ai-wpoos-pro' ),
						$connection_id
					)
				);
			}

			$connection_type = isset( $connection['connection_type'] ) ? sanitize_key( $connection['connection_type'] ) : '';
			if ( 'google_drive' !== $connection_type ) {
				return new WP_Error(
					'wp_mcp_ai_drive_wrong_connection_type',
					sprintf(
						/* translators: %s: connection type. */
						__( 'Connection "%s" is not a Google Drive connection. Please use a Google Drive connection type.', 'mcp-ai-wpoos-pro' ),
						$connection_type
					)
				);
			}

			// Assistant-level restriction: an assistant with a configured Remote
			// Site Connections list may only use connections from that list.
			if ( ! self::is_connection_enabled_for_assistant( $connection_id, $context ) ) {
				return new WP_Error(
					'wp_mcp_ai_drive_connection_not_enabled',
					sprintf(
						/* translators: %s: connection name. */
						__( 'Google Drive connection "%s" is not enabled for this assistant. Ask the user to enable it in the assistant editor under Remote Site Connections, or use one of the connections returned by list_drive_connections.', 'mcp-ai-wpoos-pro' ),
						isset( $connection['name'] ) ? (string) $connection['name'] : $connection_id
					),
					array( 'status' => 403 )
				);
			}

			$client_id       = isset( $connection['client_id'] ) ? trim( (string) $connection['client_id'] ) : '';
			$client_secret   = isset( $connection['client_secret'] ) ? trim( (string) $connection['client_secret'] ) : '';
			$refresh_token   = isset( $connection['refresh_token'] ) ? trim( (string) $connection['refresh_token'] ) : '';
			$configured_user = isset( $connection['user_email'] ) ? trim( (string) $connection['user_email'] ) : '';

			// Decrypt encrypted fields.
			if ( '' !== $client_secret ) {
				$client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $client_secret );
			}
			if ( '' !== $refresh_token ) {
				$refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $refresh_token );
			}

			$source        = 'connection';
			$resolved_id   = $connection_id;
			$resolved_name = isset( $connection['name'] ) ? trim( (string) $connection['name'] ) : '';
		} else {
			$settings = self::get_settings();

			$client_id       = isset( $settings['google_drive_client_id'] ) ? trim( (string) $settings['google_drive_client_id'] ) : '';
			$client_secret   = isset( $settings['google_drive_client_secret'] ) ? trim( (string) $settings['google_drive_client_secret'] ) : '';
			$refresh_token   = isset( $settings['google_drive_refresh_token'] ) ? trim( (string) $settings['google_drive_refresh_token'] ) : '';
			$configured_user = isset( $settings['google_drive_user_email'] ) ? trim( (string) $settings['google_drive_user_email'] ) : '';

			// A restricted assistant cannot use the site-wide settings fallback:
			// the assistant editor can only grant named Remote Sites connections.
			// Checked only when settings credentials are actually configured, so
			// a restricted assistant without them can still auto-resolve below.
			if ( null !== self::get_allowed_connection_ids( $context ) && '' !== $client_id && '' !== $client_secret && '' !== $refresh_token ) {
				return new WP_Error(
					'wp_mcp_ai_drive_settings_not_allowed',
					__( 'This assistant is restricted to specific Remote Sites connections, so the site-wide Google Drive settings cannot be used. Enable a Google Drive connection for the assistant in the assistant editor (Remote Site Connections) and pass its connection_id.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}
		}

		// Self-correcting fallback: when no connection was named and the
		// settings-based credentials are absent, resolve to the Remote Sites
		// Drive connection instead of failing. Pro sites commonly keep Drive
		// credentials only inside a named connection, and an omitted
		// connection_id must then use that connection rather than report the
		// integration as unconfigured (which previously led assistants to
		// disable or remove the perfectly valid connection). A restricted
		// assistant only ever auto-resolves to one of its allowed connections.
		if ( '' === $connection_id && ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) ) {
			$auto = self::resolve_auto_connection( $context );
			if ( is_wp_error( $auto ) ) {
				return $auto;
			}
			if ( is_array( $auto ) ) {
				$client_id       = $auto['client_id'];
				$client_secret   = $auto['client_secret'];
				$refresh_token   = $auto['refresh_token'];
				$configured_user = $auto['configured_user'];
				$source          = 'connection';
				$resolved_id     = $auto['id'];
				$resolved_name   = $auto['name'];
			} elseif ( null !== self::get_allowed_connection_ids( $context ) ) {
				return new WP_Error(
					'wp_mcp_ai_drive_no_allowed_connection',
					__( 'None of the Google Drive connections enabled for this assistant have usable credentials. Enable a Drive connection in the assistant editor (Remote Site Connections) and complete its OAuth flow under Remote Sites.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}
		}

		if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
			$message = __( 'Google Drive API credentials are not configured. Add the client ID, client secret, and refresh token either in a Google Drive connection (Remote Sites) or in the NV oOS settings.', 'mcp-ai-wpoos-pro' );

			if ( '' !== $connection_id ) {
				$message = sprintf(
					/* translators: %s: connection ID. */
					__( 'Google Drive connection "%s" has no usable credentials. Complete its OAuth flow under Remote Sites, or pass another connection_id (see list_drive_connections).', 'mcp-ai-wpoos-pro' ),
					$connection_id
				);
			}

			return new WP_Error( 'wp_mcp_ai_drive_missing_credentials', $message );
		}

		return array(
			'client_id'       => $client_id,
			'client_secret'   => $client_secret,
			'refresh_token'   => $refresh_token,
			'configured_user' => $configured_user,
			'source'          => $source,
			'connection_id'   => $resolved_id,
			'connection_name' => $resolved_name,
		);
	}

	/**
	 * Enumerate the Remote Sites Google Drive connections that auto-resolution
	 * considers: enabled, typed google_drive, with complete credentials.
	 *
	 * When the context carries a restricted assistant, only connections from
	 * the assistant's Remote Site Connections list are considered — the same
	 * rules resolve_auto_connection() applies.
	 *
	 * @param array $context Optional execution context (assistant_id is honoured).
	 * @return array<string,array> Connection ID => connection array.
	 */
	public static function get_auto_connection_candidates( array $context = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$allowed_ids = self::get_allowed_connection_ids( $context );
		$candidates  = array();

		foreach ( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() as $candidate_id => $connection ) {
			if ( ! is_array( $connection ) ) {
				continue;
			}

			if ( null !== $allowed_ids && ! in_array( (string) $candidate_id, $allowed_ids, true ) ) {
				continue;
			}

			$connection_type = isset( $connection['connection_type'] ) ? sanitize_key( $connection['connection_type'] ) : '';
			if ( 'google_drive' !== $connection_type || empty( $connection['enabled'] ) ) {
				continue;
			}

			$candidate_client_id = isset( $connection['client_id'] ) ? trim( (string) $connection['client_id'] ) : '';
			$candidate_secret    = isset( $connection['client_secret'] ) ? trim( (string) $connection['client_secret'] ) : '';
			$candidate_refresh   = isset( $connection['refresh_token'] ) ? trim( (string) $connection['refresh_token'] ) : '';

			if ( '' === $candidate_client_id || '' === $candidate_secret || '' === $candidate_refresh ) {
				continue;
			}

			$candidates[ (string) $candidate_id ] = $connection;
		}

		return $candidates;
	}

	/**
	 * Find the Remote Sites Google Drive connection to use when no
	 * connection_id was given and the settings-based credentials are absent.
	 *
	 * Returns null when no usable connection exists, the single enabled
	 * connection when exactly one does, or a WP_Error listing the candidates
	 * when several are enabled (ambiguous — the caller must name one).
	 *
	 * When the context carries a restricted assistant, only connections from
	 * the assistant's Remote Site Connections list are considered.
	 *
	 * @param array $context Optional execution context (assistant_id is honoured).
	 * @return array|null|WP_Error
	 */
	private static function resolve_auto_connection( array $context = array() ) {
		$candidates = self::get_auto_connection_candidates( $context );

		if ( empty( $candidates ) ) {
			return null;
		}

		if ( count( $candidates ) > 1 ) {
			$labels = array();
			foreach ( $candidates as $candidate_id => $connection ) {
				$labels[] = sprintf(
					'%1$s (%2$s)',
					(string) $candidate_id,
					isset( $connection['name'] ) ? (string) $connection['name'] : (string) $candidate_id
				);
			}

			return new WP_Error(
				'wp_mcp_ai_drive_ambiguous_connection',
				sprintf(
					/* translators: %s: comma-separated connection IDs and names. */
					__( 'Multiple Google Drive connections are enabled and healthy, but no connection_id was given — the tool will not guess which account to use. Pass one of: %s. Call list_drive_connections for their names and enabled state.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', $labels )
				)
			);
		}

		$auto_id         = (string) array_key_first( $candidates );
		$auto_connection = $candidates[ $auto_id ];

		return array(
			'id'              => $auto_id,
			'name'            => isset( $auto_connection['name'] ) ? trim( (string) $auto_connection['name'] ) : $auto_id,
			'client_id'       => trim( (string) $auto_connection['client_id'] ),
			'client_secret'   => WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( trim( (string) $auto_connection['client_secret'] ) ),
			'refresh_token'   => WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( trim( (string) $auto_connection['refresh_token'] ) ),
			'configured_user' => isset( $auto_connection['user_email'] ) ? trim( (string) $auto_connection['user_email'] ) : '',
		);
	}

	/**
	 * Resolve the assistant's allowed connection IDs from the execution context.
	 *
	 * Returns null when unrestricted (no assistant context, or the assistant
	 * has no configured Remote Site Connections list) — mirroring the
	 * remote_wp_connection semantics — or the allowed connection IDs as strings.
	 *
	 * @param array $context Execution context.
	 * @return array|null Allowed connection IDs, or null when unrestricted.
	 */
	public static function get_allowed_connection_ids( array $context ) {
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		if ( ! $assistant_id ) {
			return null;
		}

		$enabled_connections = get_post_meta( $assistant_id, '_wp_mcp_ai_pro_remote_connections', true );

		if ( ! is_array( $enabled_connections ) ) {
			// Not configured — legacy behaviour allows all connections.
			return null;
		}

		return array_map( 'strval', $enabled_connections );
	}

	/**
	 * Whether a connection is enabled for the assistant in the execution context.
	 *
	 * @param string $connection_id Connection ID.
	 * @param array  $context       Execution context.
	 * @return bool True when allowed (or unrestricted), false otherwise.
	 */
	public static function is_connection_enabled_for_assistant( $connection_id, array $context ) {
		$allowed_ids = self::get_allowed_connection_ids( $context );

		if ( null === $allowed_ids ) {
			return true;
		}

		return in_array( (string) $connection_id, $allowed_ids, true );
	}

	/**
	 * Whether a Drive file belongs to the given folder tree.
	 *
	 * The scoped folder itself always counts as in scope. Otherwise the file's
	 * parent chain is walked (bounded breadth-first) looking for the scoped
	 * folder. Files with no parents — and chains that cannot be verified — are
	 * treated as out of scope (fail closed).
	 *
	 * @param array  $metadata     Files.get metadata for the file (must include parents).
	 * @param string $file_id      Drive file ID.
	 * @param string $folder_id    Scoped folder ID.
	 * @param string $access_token OAuth access token.
	 * @param int    $timeout      Request timeout in seconds.
	 * @return bool|WP_Error True when in scope, false when outside, WP_Error when
	 *                       the chain could not be verified.
	 */
	public static function is_file_within_folder_tree( array $metadata, $file_id, $folder_id, $access_token, $timeout ) {
		$file_id   = (string) $file_id;
		$folder_id = (string) $folder_id;

		if ( $file_id === $folder_id ) {
			return true;
		}

		$queue     = array( $file_id );
		$visited   = array();
		$max_depth = 8;

		while ( ! empty( $queue ) && $max_depth > 0 ) {
			$next = array();

			foreach ( $queue as $current_id ) {
				$current_id = (string) $current_id;

				if ( isset( $visited[ $current_id ] ) ) {
					continue;
				}
				$visited[ $current_id ] = true;

				if ( $current_id === $file_id ) {
					// The starting node's parents come from the metadata we already hold.
					$parent_ids = isset( $metadata['parents'] ) && is_array( $metadata['parents'] )
						? array_map( 'strval', $metadata['parents'] )
						: array();
				} else {
					$parent_meta = self::get_metadata( $current_id, $access_token, $timeout );
					if ( is_wp_error( $parent_meta ) ) {
						return $parent_meta;
					}

					$parent_ids = isset( $parent_meta['parents'] ) && is_array( $parent_meta['parents'] )
						? array_map( 'strval', $parent_meta['parents'] )
						: array();
				}

				foreach ( $parent_ids as $parent_id ) {
					if ( $folder_id === $parent_id ) {
						return true;
					}
					$next[] = $parent_id;
				}
			}

			$queue = $next;
			--$max_depth;
		}

		return false;
	}

	/**
	 * Request an access token from Google's OAuth endpoint using a stored refresh token.
	 *
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret.
	 * @param string $refresh_token Refresh token for the Drive API.
	 * @param int    $timeout       Request timeout in seconds.
	 * @return string|WP_Error Access token or WP_Error.
	 */
	public static function request_access_token( $client_id, $client_secret, $refresh_token, $timeout ) {
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
			$response_body = wp_remote_retrieve_body( $response );
			WP_MCP_AI_Admin_Settings::log(
				'Google Drive token request returned unexpected status.',
				array(
					'status' => $status_code,
					'body'   => $response_body,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_drive_token_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The Google Drive token endpoint returned an unexpected status: %d.', 'mcp-ai-wpoos-pro' ),
					$status_code
				),
				array(
					'status' => $status_code,
					'body'   => $response_body,
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
	 * Perform an authenticated JSON Drive API request and decode the response.
	 *
	 * @param string     $method       HTTP method: GET or POST.
	 * @param string     $path         Path relative to /drive/v3/ (e.g. "files/abc").
	 * @param string     $access_token OAuth access token.
	 * @param int        $timeout      Request timeout in seconds.
	 * @param array      $query        Optional query parameters.
	 * @param array|null $body         Optional JSON body for POST requests.
	 * @return array|WP_Error Decoded JSON payload or WP_Error.
	 */
	public static function request( $method, $path, $access_token, $timeout, $query = array(), $body = null ) {
		$url = sprintf( '%s/%s', self::DRIVE_API_BASE, $path );
		if ( ! empty( $query ) ) {
			$url = self::append_query_string( $url, $query );
		}

		$args = array(
			'timeout' => $timeout,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $access_token,
			),
		);

		if ( 'POST' === strtoupper( (string) $method ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( null === $body ? array() : $body );
			$response                        = wp_remote_post( $url, $args );
		} else {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Admin_Settings::log(
				'Google Drive API request failed.',
				array(
					'path'  => $path,
					'error' => $response->get_error_message(),
				)
			);

			return new WP_Error( 'wp_mcp_ai_drive_http_error', __( 'The Google Drive API request failed.', 'mcp-ai-wpoos-pro' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			WP_MCP_AI_Admin_Settings::log(
				'Google Drive API request returned unexpected status.',
				array(
					'path'   => $path,
					'status' => $status_code,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_drive_http_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Google Drive returned an unexpected HTTP status: %d.', 'mcp-ai-wpoos-pro' ),
					$status_code
				),
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		$body_raw = wp_remote_retrieve_body( $response );
		$payload  = json_decode( $body_raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			WP_MCP_AI_Admin_Settings::log(
				'Google Drive API request returned invalid JSON.',
				array(
					'path' => $path,
					'body' => $body_raw,
				)
			);

			return new WP_Error( 'wp_mcp_ai_drive_invalid_json', __( 'Google Drive returned an invalid response.', 'mcp-ai-wpoos-pro' ) );
		}

		return $payload;
	}

	/**
	 * Perform an authenticated raw (non-JSON) Drive API request.
	 *
	 * Used for the files/export endpoint, which returns raw text bytes.
	 *
	 * @param string $path         Path relative to /drive/v3/ (e.g. "files/abc/export").
	 * @param string $access_token OAuth access token.
	 * @param int    $timeout      Request timeout in seconds.
	 * @param array  $query        Optional query parameters.
	 * @return string|WP_Error Raw response body or WP_Error.
	 */
	public static function request_raw( $path, $access_token, $timeout, $query = array() ) {
		$url = sprintf( '%s/%s', self::DRIVE_API_BASE, $path );
		if ( ! empty( $query ) ) {
			$url = self::append_query_string( $url, $query );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Admin_Settings::log(
				'Google Drive raw request failed.',
				array(
					'path'  => $path,
					'error' => $response->get_error_message(),
				)
			);

			return new WP_Error( 'wp_mcp_ai_drive_http_error', __( 'The Google Drive API request failed.', 'mcp-ai-wpoos-pro' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			WP_MCP_AI_Admin_Settings::log(
				'Google Drive raw request returned unexpected status.',
				array(
					'path'   => $path,
					'status' => $status_code,
				)
			);

			return new WP_Error(
				'wp_mcp_ai_drive_http_status',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Google Drive returned an unexpected HTTP status: %d.', 'mcp-ai-wpoos-pro' ),
					$status_code
				),
				array(
					'status' => $status_code,
					'body'   => wp_remote_retrieve_body( $response ),
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$body = wp_check_invalid_utf8( $body );
		}

		return str_replace( "\x00", '', $body );
	}

	/**
	 * Read a range of cell values from a Google Sheets spreadsheet.
	 *
	 * Uses the Google Sheets API (v4) rather than the Drive export endpoint so
	 * a specific worksheet/tab can be read from a multi-sheet workbook. The
	 * `spreadsheets.values.get` endpoint returns values as a JSON matrix, which
	 * sidesteps the CSV export limitations (single-sheet default, 400 on
	 * native Google Sheets without a gid).
	 *
	 * @param string $spreadsheet_id Spreadsheet (Drive file) ID.
	 * @param string $range          A1 range or sheet name (e.g. "Menu", "Menu!A1:Z1000").
	 * @param string $access_token   OAuth access token.
	 * @param int    $timeout        Request timeout in seconds.
	 * @return array<string,mixed>|WP_Error array{values: string[][]} or WP_Error.
	 * @since 1.6.0
	 */
	public static function get_sheet_values( $spreadsheet_id, $range, $access_token, $timeout ) {
		$path = 'spreadsheets/' . rawurlencode( (string) $spreadsheet_id ) . '/values/' . rawurlencode( (string) $range );
		$url  = sprintf( '%s/%s', self::SHEETS_API_BASE, $path );
		$url  = self::append_query_string( $url, array( 'valueRenderOption' => 'UNFORMATTED_VALUE' ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wp_mcp_ai_drive_http_error', __( 'The Google Sheets API request failed.', 'mcp-ai-wpoos-pro' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$body       = wp_remote_retrieve_body( $response );
			$google_msg = self::extract_google_error_message( $body );

			// A 400 (as opposed to 401/403/404) is almost always a scope or
			// request-shape problem — the Sheets API (sheets.googleapis.com/v4)
			// needs the spreadsheets.readonly scope, which the Drive token may
			// not carry. Surface that hint distinctly so it isn't mistaken for
			// a file-lookup failure. When Google returns a structured error body
			// (e.g. "Request had insufficient authentication scopes"), surface
			// its actual message rather than a bare status code.
			if ( '' !== $google_msg ) {
				$message = sprintf(
					/* translators: 1: HTTP status code, 2: Google's error message */
					__( 'Google Sheets returned HTTP %1$d: %2$s. If this is a scope problem, re-authorise the Drive connection to include the "spreadsheets.readonly" scope.', 'mcp-ai-wpoos-pro' ),
					$status_code,
					$google_msg
				);
			} else {
				$message = 400 === $status_code
					? __( 'Google Sheets rejected the request (HTTP 400). The API token needs the "spreadsheets.readonly" scope and a valid sheet name; check the workspace connection scopes.', 'mcp-ai-wpoos-pro' )
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'Google Sheets returned an unexpected HTTP status: %d.', 'mcp-ai-wpoos-pro' ), $status_code );
			}

			return new WP_Error(
				'wp_mcp_ai_drive_http_status',
				$message,
				array(
					'status' => $status_code,
					'body'   => $body,
				)
			);
		}

		$body_raw = wp_remote_retrieve_body( $response );
		$payload  = json_decode( $body_raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			return new WP_Error( 'wp_mcp_ai_drive_invalid_json', __( 'Google Sheets returned an invalid response.', 'mcp-ai-wpoos-pro' ) );
		}

		return $payload;
	}

	/**
	 * Extract Google's human-readable error message from a non-2xx response body.
	 *
	 * The Sheets/Drive APIs return a JSON envelope like
	 * `{"error":{"code":403,"message":"…","status":"PERMISSION_DENIED"}}`.
	 * Return that message (with the status tag when present) so callers can
	 * distinguish a scope denial from a file-not-shared failure instead of
	 * surfacing a bare HTTP status code.
	 *
	 * @param string $body Raw response body.
	 * @return string Google's message (or empty string when the body is not a
	 *                recognised Google error envelope).
	 */
	private static function extract_google_error_message( $body ) {
		$body    = trim( (string) $body );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['error'] ) || ! is_array( $decoded['error'] ) ) {
			return '';
		}

		$message = isset( $decoded['error']['message'] ) ? trim( (string) $decoded['error']['message'] ) : '';
		$status  = isset( $decoded['error']['status'] ) ? trim( (string) $decoded['error']['status'] ) : '';

		if ( '' === $message ) {
			return $status;
		}
		if ( '' === $status ) {
			return $message;
		}

		return $message . ' (' . $status . ')';
	}

	/**
	 * List the worksheet (tab) names and their IDs for a spreadsheet.
	 *
	 * @param string $spreadsheet_id Spreadsheet (Drive file) ID.
	 * @param string $access_token   OAuth access token.
	 * @param int    $timeout        Request timeout in seconds.
	 * @return array|WP_Error Sheet metadata (sheets[].properties) or WP_Error.
	 * @since 1.6.0
	 */
	public static function get_sheets_metadata( $spreadsheet_id, $access_token, $timeout ) {
		$path = 'spreadsheets/' . rawurlencode( (string) $spreadsheet_id );
		$url  = sprintf( '%s/%s', self::SHEETS_API_BASE, $path );
		$url  = self::append_query_string( $url, array( 'fields' => 'sheets.properties' ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wp_mcp_ai_drive_http_error', __( 'The Google Sheets API request failed.', 'mcp-ai-wpoos-pro' ), $response );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			$body       = wp_remote_retrieve_body( $response );
			$google_msg = self::extract_google_error_message( $body );

			if ( '' !== $google_msg ) {
				$message = sprintf(
					/* translators: 1: HTTP status code, 2: Google's error message */
					__( 'Google Sheets returned HTTP %1$d: %2$s. If this is a scope problem, re-authorise the Drive connection to include the "spreadsheets.readonly" scope.', 'mcp-ai-wpoos-pro' ),
					$status_code,
					$google_msg
				);
			} else {
				$message = 400 === $status_code
					? __( 'Google Sheets rejected the request (HTTP 400). The API token needs the "spreadsheets.readonly" scope and a valid sheet name; check the workspace connection scopes.', 'mcp-ai-wpoos-pro' )
					/* translators: %d: HTTP status code. */
					: sprintf( __( 'Google Sheets returned an unexpected HTTP status: %d.', 'mcp-ai-wpoos-pro' ), $status_code );
			}

			return new WP_Error(
				'wp_mcp_ai_drive_http_status',
				$message,
				array(
					'status' => $status_code,
					'body'   => $body,
				)
			);
		}

		$body_raw = wp_remote_retrieve_body( $response );
		$payload  = json_decode( $body_raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			return new WP_Error( 'wp_mcp_ai_drive_invalid_json', __( 'Google Sheets returned an invalid response.', 'mcp-ai-wpoos-pro' ) );
		}

		return $payload;
	}

	/**
	 * Fetch trimmed metadata for a single Drive file.
	 *
	 * @param string $file_id      Drive file ID.
	 * @param string $access_token OAuth access token.
	 * @param int    $timeout      Request timeout in seconds.
	 * @return array|WP_Error File metadata or WP_Error.
	 */
	public static function get_metadata( $file_id, $access_token, $timeout ) {
		return self::request(
			'GET',
			'files/' . rawurlencode( $file_id ),
			$access_token,
			$timeout,
			array(
				'fields' => 'id,name,mimeType,size,createdTime,modifiedTime,parents,shared,description,webViewLink,webContentLink,iconLink,thumbnailLink,trashed',
			)
		);
	}

	/**
	 * Export a Google Docs-family file to plain text.
	 *
	 * @param string $file_id      Drive file ID.
	 * @param string $access_token OAuth access token.
	 * @param int    $timeout      Request timeout in seconds.
	 * @return string|WP_Error Plain-text export or WP_Error.
	 */
	public static function export_text( $file_id, $access_token, $timeout ) {
		return self::request_raw(
			'files/' . rawurlencode( $file_id ) . '/export',
			$access_token,
			$timeout,
			array( 'mimeType' => 'text/plain' )
		);
	}

	/**
	 * List the direct children of a Drive folder.
	 *
	 * @param string $folder_id    Drive folder ID.
	 * @param string $access_token OAuth access token.
	 * @param int    $timeout      Request timeout in seconds.
	 * @param int    $max_children Maximum children to return.
	 * @return array|WP_Error Children list payload or WP_Error.
	 */
	public static function list_children( $folder_id, $access_token, $timeout, $max_children ) {
		return self::request(
			'GET',
			'files',
			$access_token,
			$timeout,
			array(
				'q'        => sprintf( "'%s' in parents and trashed = false", $folder_id ),
				'pageSize' => $max_children,
				'orderBy'  => 'name',
				'fields'   => 'nextPageToken, files(id, name, mimeType, size, modifiedTime)',
			)
		);
	}

	/**
	 * Whether a MIME type belongs to the text-exportable Google Docs family.
	 *
	 * @param string $mime_type MIME type.
	 * @return bool True when the file can be exported to plain text.
	 */
	public static function is_text_exportable( $mime_type ) {
		return in_array( strtolower( (string) $mime_type ), self::TEXT_EXPORTABLE_MIME_TYPES, true );
	}

	/**
	 * Whether a MIME type represents a Drive folder.
	 *
	 * @param string $mime_type MIME type.
	 * @return bool True for Drive folders.
	 */
	public static function is_folder( $mime_type ) {
		return 'application/vnd.google-apps.folder' === strtolower( (string) $mime_type );
	}

	/**
	 * Human-readable type label for a Drive MIME type.
	 *
	 * @param string $mime_type MIME type.
	 * @return string Type label.
	 */
	public static function get_type_label( $mime_type ) {
		$mime_type = strtolower( (string) $mime_type );

		if ( self::is_folder( $mime_type ) ) {
			return 'folder';
		}
		if ( self::is_text_exportable( $mime_type ) ) {
			$labels = array(
				'application/vnd.google-apps.document'     => 'document',
				'application/vnd.google-apps.spreadsheet'  => 'spreadsheet',
				'application/vnd.google-apps.presentation' => 'presentation',
			);

			return isset( $labels[ $mime_type ] ) ? $labels[ $mime_type ] : 'google-file';
		}
		if ( 0 === strpos( $mime_type, 'image/' ) ) {
			return 'image';
		}
		if ( 0 === strpos( $mime_type, 'audio/' ) ) {
			return 'audio';
		}
		if ( 0 === strpos( $mime_type, 'video/' ) ) {
			return 'video';
		}
		if ( 'application/pdf' === $mime_type ) {
			return 'pdf';
		}
		if ( in_array( $mime_type, array( 'application/zip', 'application/x-zip-compressed', 'application/x-tar', 'application/gzip' ), true ) ) {
			return 'archive';
		}

		return 'file';
	}

	/**
	 * Format a byte count as a human-readable size.
	 *
	 * @param int $bytes Byte count.
	 * @return string Human-readable size.
	 */
	public static function format_size( $bytes ) {
		$bytes = absint( $bytes );

		if ( $bytes >= 1073741824 ) {
			return number_format( $bytes / 1073741824, 1 ) . ' GB';
		}
		if ( $bytes >= 1048576 ) {
			return number_format( $bytes / 1048576, 1 ) . ' MB';
		}
		if ( $bytes >= 1024 ) {
			return number_format( $bytes / 1024, 1 ) . ' KB';
		}

		return $bytes . ' B';
	}

	/**
	 * Truncate text to a character cap, preferring a word boundary.
	 *
	 * @param string $text      Text to truncate.
	 * @param int    $max_chars Maximum characters.
	 * @return array Array with keys text and truncated.
	 */
	public static function truncate_text( $text, $max_chars ) {
		$text      = (string) $text;
		$max_chars = absint( $max_chars );

		if ( $max_chars < 1 || self::mb_strlen_safe( $text ) <= $max_chars ) {
			return array(
				'text'      => $text,
				'truncated' => false,
			);
		}

		$cut        = self::mb_substr_safe( $text, 0, $max_chars );
		$last_space = self::mb_strrpos_safe( $cut, ' ' );
		if ( false !== $last_space && $last_space > (int) floor( $max_chars * 0.5 ) ) {
			$cut = self::mb_substr_safe( $cut, 0, $last_space );
		}

		return array(
			'text'      => rtrim( $cut ),
			'truncated' => true,
		);
	}

	/**
	 * Append query parameters to a URL, expanding list values into repeated parameters.
	 *
	 * @param string $url        Base URL.
	 * @param array  $parameters Query parameters. Array values expand into repeated keys.
	 * @return string URL with query string appended.
	 */
	public static function append_query_string( $url, $parameters ) {
		$parts = array();

		foreach ( $parameters as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( null === $item || '' === $item ) {
						continue;
					}

					$parts[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $item );
				}
			} elseif ( null !== $value && '' !== $value ) {
				$parts[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
			}
		}

		if ( empty( $parts ) ) {
			return $url;
		}

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $parts );
	}

	/**
	 * Resolve the plugin settings array.
	 *
	 * @return array Plugin settings.
	 */
	private static function get_settings() {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
		}

		return WP_MCP_AI_Admin_Settings::get_settings();
	}

	/**
	 * Resolve the configured HTTP request timeout.
	 *
	 * @return int Timeout in seconds (minimum 5).
	 */
	public static function get_request_timeout() {
		$settings = self::get_settings();

		return isset( $settings['request_timeout'] ) ? max( 5, absint( $settings['request_timeout'] ) ) : 30;
	}

	/**
	 * Multibyte-safe string length.
	 *
	 * @param string $text Text to measure.
	 * @return int Character count.
	 */
	public static function mb_strlen_safe( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	/**
	 * Multibyte-safe substring.
	 *
	 * @param string   $text   Text to slice.
	 * @param int      $start  Start offset.
	 * @param int|null $length Optional length.
	 * @return string Sliced text.
	 */
	public static function mb_substr_safe( $text, $start, $length = null ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( (string) $text, $start, $length, 'UTF-8' );
		}

		return null === $length ? substr( (string) $text, $start ) : substr( (string) $text, $start, $length );
	}

	/**
	 * Multibyte-safe last position of a needle.
	 *
	 * @param string $haystack Text to search.
	 * @param string $needle   Needle to find.
	 * @return int|false Position or false when not found.
	 */
	public static function mb_strrpos_safe( $haystack, $needle ) {
		if ( function_exists( 'mb_strrpos' ) ) {
			return mb_strrpos( (string) $haystack, (string) $needle, 0, 'UTF-8' );
		}

		return strrpos( (string) $haystack, (string) $needle );
	}
}
