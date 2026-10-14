<?php
/**
 * Food & Beverage Management Toolkit — CSV Exporter.
 *
 * Exports the configured Google Drive workbook's worksheets into local CSV
 * files (one per spec table) so the CSV/fixture adapter can read the demo
 * data without network access. This closes the gap left when the bundled
 * `tests/fixtures/` CSV files are stripped from the shipped plugin (the
 * release build excludes the `tests/` tree).
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
 * Exports a Drive workbook to local CSV files keyed by spec table.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Csv_Exporter {

	/**
	 * Default directory (relative to WP_CONTENT_DIR) used when no explicit
	 * CSV data directory is configured.
	 *
	 * @var string
	 */
	const DEFAULT_SUBDIR = 'uploads/fnb-data';

	/**
	 * Resolve the target directory for CSV exports.
	 *
	 * Prefers the configured `csv_data_dir`; otherwise falls back to a stable
	 * default under the uploads directory. Creates the directory on demand.
	 *
	 * @return string|WP_Error Absolute directory path, or WP_Error on failure.
	 */
	public static function target_directory() {
		$dir = WP_MCP_AI_Fnb_Settings::get( 'csv_data_dir', '' );
		$dir = is_string( $dir ) ? trim( $dir ) : '';

		if ( '' === $dir ) {
			$dir = trailingslashit( WP_CONTENT_DIR ) . self::DEFAULT_SUBDIR;
		}

		$dir = wp_normalize_path( untrailingslashit( $dir ) );

		// Never allow a path that escapes the content/uploads root implicitly —
		// keep the default under WP_CONTENT_DIR and require an explicit
		// absolute path otherwise (which is admin-curated via manage_options).
		$made = wp_mkdir_p( $dir );
		if ( ! $made ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_export_dir_failed',
				/* translators: %s: directory path */
				sprintf( __( 'Could not create the CSV data directory at %s.', 'mcp-ai-wpoos-pro' ), $dir )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Checking a server directory the toolkit owns; WP_Filesystem is overkill for a local existence/writability probe.
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return new WP_Error(
				'wp_mcp_ai_fnb_export_dir_unwritable',
				/* translators: %s: directory path */
				sprintf( __( 'The CSV data directory %s is not writable by PHP.', 'mcp-ai-wpoos-pro' ), $dir )
			);
		}

		return $dir;
	}

	/**
	 * Export every worksheet of the configured Drive workbook to local CSV.
	 *
	 * @return array|WP_Error array{dir:string,exported:int,skipped:int,files:string[]} or WP_Error.
	 */
	public static function export_workbook() {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Google_Sheets_Reader' ) ) {
			require_once __DIR__ . '/class-wp-mcp-ai-fnb-google-sheets-reader.php';
		}

		$dir = self::target_directory();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$reader = new WP_MCP_AI_Fnb_Google_Sheets_Reader();
		$result = $reader->export_workbook_to_csv( $dir );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Persist the resolved directory so the CSV adapter reads from it next
		// time (unless the operator already set an explicit path).
		if ( '' === trim( (string) WP_MCP_AI_Fnb_Settings::get( 'csv_data_dir', '' ) ) ) {
			$all                 = WP_MCP_AI_Fnb_Settings::get_all();
			$all['csv_data_dir'] = $dir;
			$all['adapter']      = 'csv';
			update_option( WP_MCP_AI_Fnb_Settings::OPTION_KEY, $all );
		}

		$result['dir'] = $dir;

		return $result;
	}

	/**
	 * Enumerate Drive connection IDs for the admin "Data Folder" dropdown.
	 *
	 * @return array<int,array{id:string,name:string,folder_id:string}> Connection rows (redacted).
	 */
	public static function list_drive_connections() {
		$connections = array();

		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$all = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			if ( is_array( $all ) ) {
				foreach ( $all as $conn_id => $connection ) {
					if ( ! is_array( $connection ) ) {
						continue;
					}
					$type = isset( $connection['connection_type'] ) ? sanitize_key( $connection['connection_type'] ) : '';
					if ( 'google_drive' !== $type ) {
						continue;
					}
					$connections[] = array(
						'id'        => (string) $conn_id,
						'name'      => isset( $connection['name'] ) ? (string) $connection['name'] : (string) $conn_id,
						'folder_id' => isset( $connection['folder_id'] ) ? (string) $connection['folder_id'] : '',
					);
				}
			}
		}

		// Settings-based default connection.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( ! empty( $settings['google_drive_client_id'] ) && ! empty( $settings['google_drive_refresh_token'] ) ) {
				$connections[] = array(
					'id'        => 'settings',
					'name'      => __( 'NV oOS Google Drive settings (default connection)', 'mcp-ai-wpoos-pro' ),
					'folder_id' => '',
				);
			}
		}

		return $connections;
	}

	/**
	 * Enumerate configured Drive folder IDs available to the exporter.
	 *
	 * Combines the explicitly configured data/setup folder IDs with the
	 * per-connection scoped folder IDs so the dropdown can offer every source
	 * the workbook might live in.
	 *
	 * @return array<int,array{id:string,label:string}> Folder rows (deduplicated).
	 */
	public static function list_drive_folders() {
		$folders = array();
		$seen    = array();

		foreach ( array( 'data_folder_id', 'setup_folder_id' ) as $key ) {
			$value = WP_MCP_AI_Fnb_Settings::get( $key, '' );
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' !== $value && ! isset( $seen[ $value ] ) ) {
				$seen[ $value ] = true;
				$folders[]      = array(
					'id'    => $value,
					'label' => ( 'data_folder_id' === $key )
						? __( 'Data Folder', 'mcp-ai-wpoos-pro' ) . ' (' . $value . ')'
						: __( 'Setup Folder', 'mcp-ai-wpoos-pro' ) . ' (' . $value . ')',
				);
			}
		}

		foreach ( self::list_drive_connections() as $connection ) {
			$folder_id = isset( $connection['folder_id'] ) ? trim( (string) $connection['folder_id'] ) : '';
			if ( '' !== $folder_id && ! isset( $seen[ $folder_id ] ) ) {
				$seen[ $folder_id ] = true;
				$folders[]          = array(
					'id'    => $folder_id,
					'label' => $connection['name'] . ' (' . $folder_id . ')',
				);
			}
		}

		return $folders;
	}
}
