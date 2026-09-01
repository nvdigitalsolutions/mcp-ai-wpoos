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
class WP_MCP_AI_Pro_Tool_Get_Drive_File implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'Reads a single Google Drive file or folder by ID. Google Docs, Sheets, and Slides are exported to plain text (capped at max_chars with a truncated flag). Folders return their direct children. Binary files return metadata and links only. Use file IDs from search_drive results.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Optional Google Drive connection ID from Remote Sites. If not provided, uses settings-based credentials.', 'mcp-ai-wpoos-pro' ),
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

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
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

		$credentials = WP_MCP_AI_Pro_Google_Drive_Client::resolve_credentials( $connection_id );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
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

		$mime_type = isset( $metadata['mimeType'] ) ? (string) $metadata['mimeType'] : '';
		$size      = isset( $metadata['size'] ) ? absint( $metadata['size'] ) : 0;

		$data = array(
			'id'               => isset( $metadata['id'] ) ? (string) $metadata['id'] : $file_id,
			'name'             => isset( $metadata['name'] ) ? (string) $metadata['name'] : '',
			'mime_type'        => $mime_type,
			'type_label'       => WP_MCP_AI_Pro_Google_Drive_Client::get_type_label( $mime_type ),
			'is_folder'        => WP_MCP_AI_Pro_Google_Drive_Client::is_folder( $mime_type ),
			'exportable'       => WP_MCP_AI_Pro_Google_Drive_Client::is_text_exportable( $mime_type ),
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
