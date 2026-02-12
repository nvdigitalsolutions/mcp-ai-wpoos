<?php
/**
 * Excel Data Import Tool - Import data from Excel spreadsheets.
 *
 * Parses Excel files (.xlsx, .xls) and extracts data for processing,
 * analysis, or database import.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the chat response trait from base plugin.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Import data from Excel files.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Excel_Data_Import implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'excel_data_import';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Excel Data Import', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import data from Excel spreadsheets (.xlsx, .xls). Extract tables, cell values, and formatting for processing or database import. Supports multiple sheets and data validation.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress attachment ID of the Excel file to import.', 'mcp-ai-wpoos-pro' ),
				),
				'sheet_index'   => array(
					'type'        => 'integer',
					'description' => __( 'Sheet index to import (0-based). Default: 0 (first sheet)', 'mcp-ai-wpoos-pro' ),
				),
				'has_headers'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether first row contains column headers. Default: true', 'mcp-ai-wpoos-pro' ),
				),
				'max_rows'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of rows to import. Default: all rows', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'attachment_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability', // read.
			'read-only',
			'local-only', // No AI required.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check user capability.
		if ( ! current_user_can( 'read' ) ) {
			return array(
				'error' => __( 'You do not have permission to access files.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate required parameters.
		if ( empty( $arguments['attachment_id'] ) ) {
			return array(
				'error' => __( 'attachment_id is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$attachment_id = absint( $arguments['attachment_id'] );
		$sheet_index   = isset( $arguments['sheet_index'] ) ? absint( $arguments['sheet_index'] ) : 0;
		$has_headers   = isset( $arguments['has_headers'] ) ? (bool) $arguments['has_headers'] : true;
		$max_rows      = isset( $arguments['max_rows'] ) ? absint( $arguments['max_rows'] ) : 0;

		try {
			// Import Excel data.
			$result = $this->import_excel_data( $attachment_id, $sheet_index, $has_headers, $max_rows );

			if ( is_wp_error( $result ) ) {
				return array(
					'error' => $result->get_error_message(),
				);
			}

			return $this->format_chat_response(
				$result,
				sprintf(
					/* translators: 1: row count, 2: column count */
					__( 'Successfully imported %1$d rows with %2$d columns from Excel file.', 'mcp-ai-wpoos-pro' ),
					$result['row_count'],
					$result['column_count']
				)
			);

		} catch ( Exception $e ) {
			return array(
				'error' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to import Excel data: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Import data from Excel file.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param int  $sheet_index   Sheet index.
	 * @param bool $has_headers   Has headers flag.
	 * @param int  $max_rows      Maximum rows.
	 * @return array|WP_Error Import result or error.
	 */
	protected function import_excel_data( $attachment_id, $sheet_index, $has_headers, $max_rows ) {
		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Excel file not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate it's an Excel file.
		$mime_type = mime_content_type( $file_path );
		$valid_types = array(
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx.
			'application/vnd.ms-excel', // .xls.
		);

		if ( ! in_array( $mime_type, $valid_types, true ) ) {
			return new WP_Error( 'invalid_file', __( 'File is not a valid Excel spreadsheet.', 'mcp-ai-wpoos-pro' ) );
		}

		// Try PhpSpreadsheet.
		if ( class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			return $this->import_with_phpspreadsheet( $file_path, $sheet_index, $has_headers, $max_rows );
		}

		// No suitable import method available.
		return new WP_Error(
			'no_importer',
			__( 'Excel data import requires PhpSpreadsheet library (already in composer.json - run: cd addons/pro && composer install).', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Import data using PhpSpreadsheet.
	 *
	 * @param string $file_path   File path.
	 * @param int    $sheet_index Sheet index.
	 * @param bool   $has_headers Has headers flag.
	 * @param int    $max_rows    Maximum rows.
	 * @return array|WP_Error Import result or error.
	 */
	protected function import_with_phpspreadsheet( $file_path, $sheet_index, $has_headers, $max_rows ) {
		try {
			$spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load( $file_path );
			$sheet       = $spreadsheet->getSheet( $sheet_index );
			$sheet_name  = $sheet->getTitle();

			// Get all data.
			$data         = $sheet->toArray();
			$headers      = array();
			$rows         = array();
			$row_count    = 0;
			$column_count = 0;

			if ( ! empty( $data ) ) {
				// Extract headers if specified.
				if ( $has_headers && count( $data ) > 0 ) {
					$headers = array_shift( $data );
					$column_count = count( $headers );
				} else {
					// Determine column count from first row.
					$column_count = count( $data[0] ?? array() );
				}

				// Apply max_rows limit.
				if ( $max_rows > 0 ) {
					$data = array_slice( $data, 0, $max_rows );
				}

				$rows      = $data;
				$row_count = count( $rows );
			}

			return array(
				'headers'      => $headers,
				'rows'         => $rows,
				'row_count'    => $row_count,
				'column_count' => $column_count,
				'sheet_name'   => $sheet_name,
			);

		} catch ( Exception $e ) {
			return new WP_Error(
				'phpspreadsheet_error',
				sprintf(
					/* translators: %s: error message */
					__( 'PhpSpreadsheet import failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				)
			);
		}
	}
}
