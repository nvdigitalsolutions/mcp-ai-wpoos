<?php
/**
 * Tool that reads a single Google Drive file (or folder) by ID.
 *
 * Returns trimmed metadata for every file type. Google Docs-family files
 * (Docs, Sheets, Slides) are exported to plain text with a character cap.
 * Folders return a listing of their direct children. Binary files return
 * metadata plus links — no content is streamed into chat context.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-envelope.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once __DIR__ . '/class-wp-mcp-ai-pro-google-drive-client.php';

/**
 * Provides an assistant tool for reading a single Google Drive file or folder.
 */
class WP_MCP_AI_Pro_Tool_Get_Drive_File implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	use WP_MCP_AI_Tool_Envelope;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_drive_file';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Google Drive File', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Reads a single Google Drive file or folder by ID. Google Docs are exported to plain text (capped at max_chars with a truncated flag). Google Sheets return tabular text via the Sheets API (tab names in data.tabs, a specific tab via sheet_name). Folders return their direct children. Binary files return metadata and links only. Use file IDs from search_drive results.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Reading a known Drive file or folder by ID: Docs and Slides export to text, Sheets read as tabular text with tab names, folders list children, binary files return metadata.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Discovering files by name or query; use search_drive. Discovering connection IDs; use list_drive_connections.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'search_drive', 'list_drive_connections' ),
			'notes'           => __( 'Docs text is capped at max_chars (100-50000, default 4000). Sheets are read via the Sheets API as tabular text with a data.tabs list; use sheet_name to target a specific tab. Binary files return metadata and links only, never content. Reads are limited to the resolved connection\'s scoped folder tree when the connection has a Folder ID set, and to the connections enabled for the assistant in the assistant editor.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'file_id'       => array(
					'type'        => 'string',
					'description' => __( 'Google Drive file or folder ID to read. Obtain IDs from search_drive results.', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Google Drive connection ID from Remote Sites. If not provided, uses settings-based credentials; when those are not configured, the only enabled Remote Sites Google Drive connection is used automatically (if several are enabled, pass one explicitly). Call list_drive_connections to discover IDs.', 'mcp-ai-wpoos-pro' ),
				),
				'max_chars'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum body characters to return for exported Google Docs text (100-50000). Longer text is truncated at a word boundary and flagged with truncated=true.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 100,
					'maximum'     => 50000,
					'default'     => 4000,
				),
				'max_children'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum direct children to return when the file is a folder (1-100).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 50,
				),
				'sheet_name'    => array(
					'type'        => 'string',
					'description' => __( 'Optional worksheet (tab) name for spreadsheets. When omitted, the first worksheet is read and all tab names are returned in the data.tabs field. Obtain exact tab names from a prior call that returns data.tabs.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'file_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$required_capability = apply_filters( 'wp_mcp_ai_get_drive_file_capability', 'manage_options', $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) { // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Capability resolved via the wp_mcp_ai_get_drive_file_capability filter (default manage_options).
			return new WP_Error( 'wp_mcp_ai_drive_forbidden', __( 'You do not have permission to read Google Drive files.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_drive_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Gate 1 — sanitise at entry.
		$file_id       = isset( $arguments['file_id'] ) ? sanitize_text_field( $arguments['file_id'] ) : '';
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$max_chars     = isset( $arguments['max_chars'] ) ? absint( $arguments['max_chars'] ) : 4000;
		$max_children  = isset( $arguments['max_children'] ) ? absint( $arguments['max_children'] ) : 50;
		$sheet_name    = isset( $arguments['sheet_name'] ) ? sanitize_text_field( $arguments['sheet_name'] ) : '';

		if ( '' === $file_id ) {
			return new WP_Error( 'wp_mcp_ai_drive_missing_file_id', __( 'A Google Drive file ID is required. Obtain IDs from search_drive results.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( $max_chars < 100 ) {
			$max_chars = 100;
		}
		if ( $max_chars > 50000 ) {
			$max_chars = 50000;
		}
		if ( $max_children < 1 ) {
			$max_children = 1;
		}
		if ( $max_children > 100 ) {
			$max_children = 100;
		}

		$credentials = WP_MCP_AI_Pro_Google_Drive_Client::resolve_credentials( $connection_id, $context );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$resolved_id   = isset( $credentials['connection_id'] ) ? (string) $credentials['connection_id'] : '';
		$resolved_name = isset( $credentials['connection_name'] ) ? (string) $credentials['connection_name'] : '';

		// Resolve the connection's scoped folder (the hard limit this read must
		// stay inside). The settings fallback has no folder scope.
		$scoped_folder_id = '';
		if ( '' !== $resolved_id && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $resolved_id );
			if ( is_array( $connection ) && isset( $connection['folder_id'] ) ) {
				$scoped_folder_id = trim( (string) $connection['folder_id'] );
			}
		}

		$timeout = WP_MCP_AI_Pro_Google_Drive_Client::get_request_timeout();

		$access_token = WP_MCP_AI_Pro_Google_Drive_Client::request_access_token(
			$credentials['client_id'],
			$credentials['client_secret'],
			$credentials['refresh_token'],
			$timeout
		);
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$metadata = WP_MCP_AI_Pro_Google_Drive_Client::get_metadata( $file_id, $access_token, $timeout );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		// Folder scope enforcement: a connection-scoped folder limits reads to
		// that folder's tree. Unverifiable chains fail closed.
		if ( '' !== $scoped_folder_id ) {
			$in_scope = WP_MCP_AI_Pro_Google_Drive_Client::is_file_within_folder_tree( $metadata, $file_id, $scoped_folder_id, $access_token, $timeout );
			if ( is_wp_error( $in_scope ) ) {
				return $in_scope;
			}
			if ( ! $in_scope ) {
				return new WP_Error(
					'wp_mcp_ai_drive_file_outside_scope',
					sprintf(
						/* translators: 1: connection name, 2: file name. */
						__( 'The requested file "%2$s" is outside the folder this Drive connection "%1$s" is scoped to. This assistant may only read files inside the connection\'s scoped folder tree.', 'mcp-ai-wpoos-pro' ),
						$resolved_name,
						isset( $metadata['name'] ) ? (string) $metadata['name'] : $file_id
					),
					array( 'status' => 403 )
				);
			}
		}

		$mime_type = isset( $metadata['mimeType'] ) ? (string) $metadata['mimeType'] : '';
		$size      = isset( $metadata['size'] ) ? absint( $metadata['size'] ) : 0;

		$data = array(
			'id'               => isset( $metadata['id'] ) ? (string) $metadata['id'] : $file_id,
			'name'             => isset( $metadata['name'] ) ? (string) $metadata['name'] : '',
			'mime_type'        => $mime_type,
			'type_label'       => WP_MCP_AI_Pro_Google_Drive_Client::get_type_label( $mime_type ),
			'is_folder'        => WP_MCP_AI_Pro_Google_Drive_Client::is_folder( $mime_type ),
			'exportable'       => WP_MCP_AI_Pro_Google_Drive_Client::is_text_exportable( $mime_type ),
			'connection_id'    => $resolved_id,
			'connection_name'  => $resolved_name,
			'size'             => $size,
			'size_formatted'   => WP_MCP_AI_Pro_Google_Drive_Client::format_size( $size ),
			'created_time'     => isset( $metadata['createdTime'] ) ? (string) $metadata['createdTime'] : '',
			'modified_time'    => isset( $metadata['modifiedTime'] ) ? (string) $metadata['modifiedTime'] : '',
			'parents'          => isset( $metadata['parents'] ) && is_array( $metadata['parents'] ) ? array_values( array_map( 'strval', $metadata['parents'] ) ) : array(),
			'shared'           => isset( $metadata['shared'] ) ? (bool) $metadata['shared'] : false,
			'trashed'          => isset( $metadata['trashed'] ) ? (bool) $metadata['trashed'] : false,
			'description'      => isset( $metadata['description'] ) ? (string) $metadata['description'] : '',
			'web_view_link'    => isset( $metadata['webViewLink'] ) ? (string) $metadata['webViewLink'] : '',
			'web_content_link' => isset( $metadata['webContentLink'] ) ? (string) $metadata['webContentLink'] : '',
			'icon_link'        => isset( $metadata['iconLink'] ) ? (string) $metadata['iconLink'] : '',
			'thumbnail_link'   => isset( $metadata['thumbnailLink'] ) ? (string) $metadata['thumbnailLink'] : '',
			'body'             => '',
			'note'             => '',
		);

		if ( $data['is_folder'] ) {
			$children = WP_MCP_AI_Pro_Google_Drive_Client::list_children( $file_id, $access_token, $timeout, $max_children );
			if ( is_wp_error( $children ) ) {
				$data['note'] = $children->get_error_message();

				return $this->format_success_response(
					__( 'Google Drive folder retrieved, but the child listing failed.', 'mcp-ai-wpoos-pro' ),
					array( 'data' => $data )
				);
			}

			$rows = array();
			if ( ! empty( $children['files'] ) && is_array( $children['files'] ) ) {
				foreach ( $children['files'] as $child ) {
					if ( empty( $child['id'] ) ) {
						continue;
					}
					$child_mime = isset( $child['mimeType'] ) ? (string) $child['mimeType'] : '';
					$child_size = isset( $child['size'] ) ? absint( $child['size'] ) : 0;
					$rows[]     = array(
						'id'             => (string) $child['id'],
						'name'           => isset( $child['name'] ) ? (string) $child['name'] : '',
						'mime_type'      => $child_mime,
						'type_label'     => WP_MCP_AI_Pro_Google_Drive_Client::get_type_label( $child_mime ),
						'size'           => $child_size,
						'size_formatted' => WP_MCP_AI_Pro_Google_Drive_Client::format_size( $child_size ),
					);
				}
			}

			$data['children']           = $rows;
			$data['children_count']     = count( $rows );
			$data['children_truncated'] = ! empty( $children['nextPageToken'] );
		} elseif ( 'application/vnd.google-apps.spreadsheet' === $mime_type ) {
			// Native Google Sheets cannot be exported via files/export to
			// text/plain (Drive returns HTTP 400). Read them through the Sheets
			// API instead, which also lets us enumerate tabs and target a
			// specific worksheet by name.
			$sheet_result = $this->read_sheet( $file_id, $access_token, $timeout, $sheet_name, $max_chars, $data );
			$data         = $sheet_result['data'];

			if ( is_wp_error( $sheet_result['status'] ) ) {
				$data['note'] = $sheet_result['status']->get_error_message();

				return $this->format_success_response(
					__( 'Google Drive spreadsheet retrieved, but the worksheet read failed.', 'mcp-ai-wpoos-pro' ),
					array( 'data' => $data )
				);
			}
			if ( false === $sheet_result['status'] ) {
				$data['note'] = $sheet_result['note'];

				return $this->format_success_response(
					__( 'Google Drive spreadsheet retrieved, but the worksheet read failed.', 'mcp-ai-wpoos-pro' ),
					array( 'data' => $data )
				);
			}
		} elseif ( $data['exportable'] ) {
			$export = WP_MCP_AI_Pro_Google_Drive_Client::export_text( $file_id, $access_token, $timeout );
			if ( is_wp_error( $export ) ) {
				$data['note'] = $export->get_error_message();

				return $this->format_success_response(
					__( 'Google Drive file retrieved, but the text export failed.', 'mcp-ai-wpoos-pro' ),
					array( 'data' => $data )
				);
			}

			$cut = WP_MCP_AI_Pro_Google_Drive_Client::truncate_text( $export, $max_chars );

			$data['body']       = $cut['text'];
			$data['truncated']  = $cut['truncated'];
			$data['body_chars'] = WP_MCP_AI_Pro_Google_Drive_Client::mb_strlen_safe( $cut['text'] );
		} else {
			$data['note'] = __( 'Binary or non-exportable file. Use web_view_link to open it in a browser, or web_content_link to download when publicly accessible.', 'mcp-ai-wpoos-pro' );
		}

		return $this->format_success_response(
			__( 'Google Drive file retrieved.', 'mcp-ai-wpoos-pro' ),
			array( 'data' => $data )
		);
	}

	/**
	 * Read a Google Sheets spreadsheet via the Sheets API (v4).
	 *
	 * Native Google Sheets cannot be exported through Drive's files/export to
	 * text/plain (HTTP 400), so this path uses the Sheets API's
	 * spreadsheets.get and spreadsheets.values.get endpoints instead. It
	 * enumerates the worksheet tabs, selects the requested tab (or the first),
	 * and renders the values matrix as tab-separated text.
	 *
	 * @param string $file_id      Spreadsheet (Drive file) ID.
	 * @param string $access_token OAuth access token.
	 * @param int    $timeout      Request timeout in seconds.
	 * @param string $sheet_name   Optional worksheet name (empty reads the first).
	 * @param int    $max_chars    Character cap for the rendered body.
	 * @param array  $data         The partially-built response data array.
	 * @return array{status:true|false|WP_Error, data:array, note:string}
	 */
	private function read_sheet( $file_id, $access_token, $timeout, $sheet_name, $max_chars, $data ) {
		$tabs     = array();
		$fallback = '';

		$metadata = WP_MCP_AI_Pro_Google_Drive_Client::get_sheets_metadata( $file_id, $access_token, $timeout );
		if ( is_wp_error( $metadata ) ) {
			return array(
				'status' => $metadata,
				'data'   => $data,
				'note'   => '',
			);
		}

		if ( isset( $metadata['sheets'] ) && is_array( $metadata['sheets'] ) ) {
			foreach ( $metadata['sheets'] as $sheet ) {
				if ( isset( $sheet['properties']['title'] ) ) {
					$title  = (string) $sheet['properties']['title'];
					$tabs[] = $title;
					if ( '' === $fallback ) {
						$fallback = $title;
					}
				}
			}
		}

		$data['tabs']       = $tabs;
		$data['tabs_count'] = count( $tabs );
		$data['sheet_name'] = '';

		// Select the target tab: the requested name (normalised match) when it
		// resolves, otherwise the first worksheet.
		$target = $fallback;
		if ( '' !== $sheet_name ) {
			$target = $sheet_name;
			$needle = self::normalise_sheet_name( $sheet_name );
			foreach ( $tabs as $title ) {
				if ( self::normalise_sheet_name( $title ) === $needle ) {
					$target = $title;
					break;
				}
			}
		}

		if ( '' === $target ) {
			return array(
				'status' => false,
				'data'   => $data,
				'note'   => __( 'The spreadsheet has no readable worksheet tabs.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$data['sheet_name'] = $target;

		$values = WP_MCP_AI_Pro_Google_Drive_Client::get_sheet_values( $file_id, $target, $access_token, $timeout );
		if ( is_wp_error( $values ) ) {
			return array(
				'status' => $values,
				'data'   => $data,
				'note'   => '',
			);
		}

		$matrix = isset( $values['values'] ) && is_array( $values['values'] ) ? $values['values'] : array();
		$text   = self::matrix_to_tsv( $matrix );
		$cut    = WP_MCP_AI_Pro_Google_Drive_Client::truncate_text( $text, $max_chars );

		$data['body']       = $cut['text'];
		$data['truncated']  = $cut['truncated'];
		$data['body_chars'] = WP_MCP_AI_Pro_Google_Drive_Client::mb_strlen_safe( $cut['text'] );
		$data['row_count']  = count( $matrix );

		return array(
			'status' => true,
			'data'   => $data,
			'note'   => '',
		);
	}

	/**
	 * Normalise a worksheet name for case/whitespace-insensitive matching.
	 *
	 * @param string $name Worksheet name.
	 * @return string Normalised name (lowercased, whitespace collapsed).
	 */
	private static function normalise_sheet_name( $name ) {
		$name = (string) $name;
		if ( function_exists( 'mb_strtolower' ) ) {
			$name = mb_strtolower( $name );
		} else {
			$name = strtolower( $name );
		}

		return trim( (string) preg_replace( '/\s+/', ' ', $name ) );
	}

	/**
	 * Convert a Sheets values matrix to tab-separated text.
	 *
	 * @param array<int,array<int,mixed>> $matrix Raw values matrix.
	 * @return string Tab-separated lines, one per row.
	 */
	private static function matrix_to_tsv( $matrix ) {
		if ( empty( $matrix ) ) {
			return '';
		}

		$lines = array();
		foreach ( $matrix as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cells = array();
			foreach ( $row as $cell ) {
				if ( is_bool( $cell ) ) {
					$cells[] = $cell ? 'TRUE' : 'FALSE';
				} elseif ( is_scalar( $cell ) || null === $cell ) {
					$cells[] = (string) $cell;
				} else {
					$cells[] = (string) wp_json_encode( $cell );
				}
			}
			$lines[] = implode( "\t", $cells );
		}

		return implode( "\n", $lines );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Calls the Google Drive API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
			'pii-data',             // Document bodies may contain personal data.
		);
	}
}
