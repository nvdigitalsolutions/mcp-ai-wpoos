<?php
/**
 * Food & Beverage Management Toolkit - Media Worker client (parse offload).
 *
 * Routes the heavy file-parsing leg of the open-format ingestion pipeline
 * (Proposal 079) through the Media Worker sidecar when one is configured:
 * XLSX workbooks parse in Node (ExcelJS) instead of PhpSpreadsheet, and PDF
 * text extraction plus photo/scanned-PDF OCR run in the worker's existing
 * pdf/ocr machinery. Every failure falls back to the local PHP parsers -
 * this class only ever ADDS a faster path, it never removes one.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.7.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The base plugin ships the sidecar trait; the standalone Pro matrix does
// not. Degrade to "offload unavailable" rather than fataling (test-suite
// pattern 51: self-guard against absent base contracts).
if ( ! trait_exists( 'WP_MCP_AI_Media_Worker_Client' ) ) {
	$wp_mcp_ai_fnb_worker_trait = dirname( __DIR__, 5 ) . '/includes/traits/trait-wp-mcp-ai-media-worker-client.php';
	if ( file_exists( $wp_mcp_ai_fnb_worker_trait ) ) {
		require_once $wp_mcp_ai_fnb_worker_trait;
	}
}
if ( ! trait_exists( 'WP_MCP_AI_Media_Worker_Client' ) ) {
	return; // Base plugin absent (standalone Pro matrix): offload is unavailable.
}

/**
 * Media Worker client for F&B ingestion parsing.
 *
 * @since 1.7.0
 */
class WP_MCP_AI_Fnb_Worker_Client {

	use WP_MCP_AI_Media_Worker_Client;

	/**
	 * File extensions eligible for sidecar parsing. Text formats (CSV/TSV/
	 * JSON/JSONL) are intentionally excluded - parsing them is trivial PHP
	 * work and shipping them over HTTP is strictly worse.
	 *
	 * @var array<int,string>
	 */
	const HEAVY_EXTS = array( 'xlsx', 'xls', 'ods', 'pdf', 'png', 'jpg', 'jpeg', 'webp' );

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Fnb_Worker_Client|null
	 */
	private static $instance = null;

	/**
	 * Get the shared client instance.
	 *
	 * @return WP_MCP_AI_Fnb_Worker_Client
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Whether the given file should be parsed by the Media Worker.
	 *
	 * Cheap gates run first (setting, filter, extension) so the sidecar
	 * health check - an HTTP request - only happens for eligible files.
	 *
	 * @param string $path Absolute file path.
	 * @return bool
	 */
	public function should_offload( $path ) {
		$setting = class_exists( 'WP_MCP_AI_Fnb_Settings' )
			? WP_MCP_AI_Fnb_Settings::get( 'use_media_worker', 'auto' )
			: 'auto';
		if ( 'off' === $setting ) {
			return false;
		}

		/**
		 * Filter: disable F&B worker offload without touching settings.
		 *
		 * @param bool $enabled Whether sidecar parsing is allowed.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_fnb_worker_enabled', true ) ) {
			return false;
		}

		$ext = strtolower( (string) pathinfo( (string) $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::HEAVY_EXTS, true ) ) {
			return false;
		}

		return $this->is_sidecar_upload_supported();
	}

	/**
	 * Parse a file through the sidecar's ingest route (multipart upload).
	 *
	 * @param string $path    Absolute file path.
	 * @param array  $options Optional parse options: format, sheet, language,
	 *                        offset, limit.
	 * @return array|WP_Error Worker envelope or error.
	 */
	public function parse_file( $path, array $options = array() ) {
		$fields = array(
			'format'   => isset( $options['format'] ) ? (string) $options['format'] : '',
			'sheet'    => isset( $options['sheet'] ) ? (string) $options['sheet'] : '',
			'language' => isset( $options['language'] ) && '' !== (string) $options['language'] ? (string) $options['language'] : 'eng',
			'offset'   => isset( $options['offset'] ) ? absint( $options['offset'] ) : 0,
			'limit'    => isset( $options['limit'] ) ? absint( $options['limit'] ) : 0,
		);

		return $this->sidecar_upload( '/api/ingest/parse', $path, $fields, 120 );
	}
}
