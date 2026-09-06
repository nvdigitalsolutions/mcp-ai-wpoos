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

	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	const DRIVE_API_BASE = 'https://www.googleapis.com/drive/v3';

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
	 * @param string $connection_id Optional Remote Sites connection ID. Empty falls back to settings.
	 * @return array|WP_Error Credentials array with keys client_id, client_secret,
	 *         refresh_token, configured_user and source — or WP_Error.
	 */
	public static function resolve_credentials( $connection_id ) {
		$connection_id = sanitize_key( (string) $connection_id );

		$client_id       = '';
		$client_secret   = '';
		$refresh_token   = '';
		$configured_user = '';
		$source          = 'settings';

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

			$source = 'connection';
		} else {
			$settings = self::get_settings();

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

		return array(
			'client_id'       => $client_id,
			'client_secret'   => $client_secret,
			'refresh_token'   => $refresh_token,
			'configured_user' => $configured_user,
			'source'          => $source,
		);
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
				'fields'   => 'nextPageToken, files(id, name, mimeType, size)',
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
