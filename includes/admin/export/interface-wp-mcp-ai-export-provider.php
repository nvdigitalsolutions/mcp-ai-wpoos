<?php
/**
 * Export Provider Interface.
 *
 * Every data domain that wishes to participate in Backup & Restore
 * must implement this contract. Providers are self-contained classes
 * that know how to export and import their own data.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for export/import providers.
 *
 * @since 1.2.0
 */
interface WP_MCP_AI_Export_Provider {

	/**
	 * Unique provider identifier (kebab-case).
	 *
	 * @return string e.g. 'core_settings', 'remote_sites', 'assistants'.
	 */
	public function get_id(): string;

	/**
	 * Human-readable label for the UI checkbox.
	 *
	 * @return string e.g. 'Remote Sites'.
	 */
	public function get_label(): string;

	/**
	 * Description shown beneath the checkbox in the UI.
	 *
	 * @return string
	 */
	public function get_description(): string;

	/**
	 * Whether this provider is available on the current site.
	 *
	 * @return bool False if a dependency is missing (e.g. Pro not active).
	 */
	public function is_available(): bool;

	/**
	 * Whether the exported data contains sensitive values
	 * (API keys, tokens, passwords). Triggers UI warning.
	 *
	 * @return bool
	 */
	public function contains_sensitive_data(): bool;

	/**
	 * Approximate count of items for the UI badge.
	 *
	 * @return int e.g. 3 for "3 connections", 7 for "7 assistants".
	 */
	public function get_count(): int;

	/**
	 * Export all data owned by this provider.
	 *
	 * @return array Associative array of export data.
	 */
	public function export(): array;

	/**
	 * Dry-run validation before committing an import.
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True if valid, WP_Error with specific failures.
	 */
	public function validate( array $data );

	/**
	 * Import data into the current site.
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function import( array $data );
}
