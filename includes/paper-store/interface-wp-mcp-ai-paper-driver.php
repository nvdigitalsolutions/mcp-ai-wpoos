<?php
/**
 * Paper Driver Interface — Contract for flat-file format drivers.
 *
 * Each driver handles one file format (JSON, Markdown+YAML, etc.).
 * Base implementations must be PHP 7.4+ compatible.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface WP_MCP_AI_Paper_Driver_Interface
 *
 * Contract for Paper Store format drivers. Each driver reads/writes
 * one file format and normalizes records to/from PHP arrays.
 */
interface WP_MCP_AI_Paper_Driver_Interface {

	/**
	 * Read a single record from a file path.
	 *
	 * @param string $file_path Absolute path to the record file.
	 * @return array|WP_Error  Normalized record array, or WP_Error on failure.
	 */
	public function read( $file_path );

	/**
	 * Write a record to a file atomically.
	 *
	 * @param string $file_path Absolute path to the record file.
	 * @param array  $record    Normalized record array.
	 * @return bool|WP_Error    True on success, WP_Error on failure.
	 */
	public function write( $file_path, array $record );

	/**
	 * Delete a record file.
	 *
	 * @param string $file_path Absolute path to the record file.
	 * @return bool|WP_Error    True on success, WP_Error on failure.
	 */
	public function delete( $file_path );

	/**
	 * Get the file extension this driver handles (including dot).
	 *
	 * @return string e.g. '.json', '.md'
	 */
	public function get_extension();
}
