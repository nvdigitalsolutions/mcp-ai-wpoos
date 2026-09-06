<?php
/**
 * Paper driver interface (Wave E6, sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Paper_Driver_Interface`
 * (`includes/paper-store/interface-wp-mcp-ai-paper-driver.php`): the
 * flat-file format-driver contract — `read()` / `write()` / `delete()`
 * against an absolute file path plus the extension probe, with
 * `WP_Error` failure envelopes.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4:
 *    engine pieces fold into `nvoos-content-graph-ai`).
 *  - `WP_Error` is fully qualified.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine\PaperStore
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine\PaperStore;

/**
 * Contract for Paper Store format drivers.
 *
 * Each driver reads/writes one file format and normalizes records
 * to/from PHP arrays.
 *
 * @since 1.1.0
 */
interface PaperDriverInterface {

	/**
	 * Read a single record from a file path.
	 *
	 * @param string $file_path Absolute path to the record file.
	 * @return array|\WP_Error Normalized record array, or WP_Error on failure.
	 */
	public function read( $file_path );

	/**
	 * Write a record to a file atomically.
	 *
	 * @param string $file_path Absolute path to the record file.
	 * @param array  $record    Normalized record array.
	 * @return bool|\WP_Error  True on success, WP_Error on failure.
	 */
	public function write( $file_path, array $record );

	/**
	 * Delete a record file.
	 *
	 * @param string $file_path Absolute path to the record file.
	 * @return bool|\WP_Error  True on success, WP_Error on failure.
	 */
	public function delete( $file_path );

	/**
	 * Get the file extension this driver handles (including dot).
	 *
	 * @return string e.g. '.json', '.md'
	 */
	public function get_extension();
}
