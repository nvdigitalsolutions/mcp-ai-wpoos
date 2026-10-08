<?php
/**
 * Food & Beverage Management Toolkit — Draft Writer.
 *
 * Persists assistant outputs as drafts (G-12). Google Drive mode creates a
 * file in the configured Drafts folder via the Drive client; local mode
 * (demo/CSV) writes a markdown file under the configured local Drafts dir.
 * Never sends, orders or changes records.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * F&B draft writer.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Draft_Writer {

	/**
	 * Save a draft (markdown content) and return its location.
	 *
	 * @param string $title     Draft title (report convention).
	 * @param string $content   Markdown content.
	 * @return array|WP_Error array{success,title,location,mode,status}
	 */
	public static function save_draft( $title, $content ) {
		$title   = sanitize_text_field( $title );
		$content = wp_strip_all_tags( $content );
		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_fnb_empty_draft_title', __( 'Draft title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$drafts_folder = WP_MCP_AI_Fnb_Settings::get( 'drafts_folder_id', '' );
		$adapter       = WP_MCP_AI_Fnb_Settings::get( 'adapter', 'drive' );

		if ( '' !== $drafts_folder && 'csv' !== $adapter && ! defined( 'WP_MCP_AI_FNB_USE_FIXTURES' ) ) {
			return self::save_to_drive( $title, $content, $drafts_folder );
		}

		return self::save_local( $title, $content );
	}

	/**
	 * Save a draft into a Drive folder (Google Doc via text upload).
	 *
	 * @param string $title     Draft title.
	 * @param string $content   Markdown content.
	 * @param string $folder_id Drafts folder ID.
	 * @return array|WP_Error
	 */
	private static function save_to_drive( $title, $content, $folder_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Google_Drive_Client' ) ) {
			return new WP_Error( 'wp_mcp_ai_fnb_no_drive_client', __( 'The Google Workspace toolkit (Drive client) is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$credentials = WP_MCP_AI_Pro_Google_Drive_Client::resolve_credentials( WP_MCP_AI_Fnb_Settings::get( 'connection_id', '' ) );
		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$timeout      = WP_MCP_AI_Pro_Google_Drive_Client::get_request_timeout();
		$access_token = WP_MCP_AI_Pro_Google_Drive_Client::request_access_token(
			$credentials['client_id'],
			$credentials['client_secret'],
			$credentials['refresh_token'],
			$timeout
		);
		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$boundary = 'fnb_boundary_' . wp_generate_password( 16, false );
		$meta     = wp_json_encode(
			array(
				'name'     => $title,
				'mimeType' => 'application/vnd.google-apps.document',
				'parents'  => array( $folder_id ),
			)
		);
		$body     = "--{$boundary}\r\n"
			. "Content-Type: application/json; charset=UTF-8\r\n\r\n"
			. $meta . "\r\n"
			. "--{$boundary}\r\n"
			. "Content-Type: text/plain\r\n\r\n"
			. $content . "\r\n"
			. "--{$boundary}--\r\n";

		$response = wp_remote_post(
			'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'multipart/related; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'wp_mcp_ai_fnb_drive_upload_failed', /* translators: %d: HTTP status */ sprintf( __( 'Drive upload failed (HTTP %d).', 'mcp-ai-wpoos-pro' ), $code ) );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$file_id = is_array( $decoded ) && isset( $decoded['id'] ) ? $decoded['id'] : '';

		return array(
			'success'  => true,
			'title'    => $title,
			'location' => 'https://drive.google.com/open?id=' . rawurlencode( $file_id ),
			'mode'     => 'google_drive',
			'status'   => 'Draft',
		);
	}

	/**
	 * Save a draft locally (demo/CSV mode).
	 *
	 * @param string $title   Draft title.
	 * @param string $content Markdown content.
	 * @return array|WP_Error
	 */
	private static function save_local( $title, $content ) {
		$dir = WP_MCP_AI_Fnb_Settings::get( 'csv_data_dir', '' );
		$dir = '' !== $dir ? untrailingslashit( $dir ) : __DIR__ . '/drafts';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$slug = sanitize_title( $title );
		$file = trailingslashit( $dir ) . $slug . '.md';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, $content );

		return array(
			'success'  => true,
			'title'    => $title,
			'location' => $file,
			'mode'     => 'local',
			'status'   => 'Draft',
		);
	}

	/**
	 * List existing drafts.
	 *
	 * @return array|WP_Error
	 */
	public static function list_drafts() {
		$dir = WP_MCP_AI_Fnb_Settings::get( 'csv_data_dir', '' );
		$dir = '' !== $dir ? untrailingslashit( $dir ) : __DIR__ . '/drafts';

		$items = array();
		if ( is_dir( $dir ) ) {
			$files = glob( trailingslashit( $dir ) . '*.md' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					$items[] = array(
						'name'  => basename( $file ),
						'path'  => $file,
						'bytes' => (int) filesize( $file ),
					);
				}
			}
		}

		return array(
			'success' => true,
			'mode'    => 'local',
			'drafts'  => $items,
			'count'   => count( $items ),
		);
	}
}
