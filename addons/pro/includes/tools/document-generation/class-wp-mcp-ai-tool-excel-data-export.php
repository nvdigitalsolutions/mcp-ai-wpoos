<?php
/**
 * Excel Data Export Tool - Export data to Excel spreadsheets.
 *
 * Creates Excel files (.xlsx) from arrays, database queries, or
 * structured data for reporting and data sharing.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the document response trait from base plugin.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-document-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Export data to Excel files.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Excel_Data_Export implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Document_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'excel_data_export';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Excel Data Export', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Export data to Excel spreadsheets (.xlsx). Create formatted Excel files from arrays, database results, or structured data. Perfect for reports, data sharing, and analytics.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'data'     => array(
					'type'        => 'array',
					'items'       => array(
						'type'  => 'array',
						'items' => array(
							'anyOf' => array(
								array( 'type' => 'string' ),
								array( 'type' => 'number' ),
								array( 'type' => 'boolean' ),
								array( 'type' => 'null' ),
							),
						),
					),
					'description' => __( 'Array of data rows to export. Each row is an array of cell values.', 'mcp-ai-wpoos-pro' ),
				),
				'headers'  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Optional column headers for the first row.', 'mcp-ai-wpoos-pro' ),
				),
				'title'    => array(
					'type'        => 'string',
					'description' => __( 'Spreadsheet title (used for metadata and sheet name).', 'mcp-ai-wpoos-pro' ),
				),
				'filename' => array(
					'type'        => 'string',
					'description' => __( 'Output filename (without extension). Defaults to "export".', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'data' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability', // upload_files.
			'write',
			'state-changing',
			'local-only', // No AI required.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check user capability.
		if ( ! current_user_can( 'upload_files' ) ) {
			return array(
				'error' => __( 'You do not have permission to create files.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate required parameters.
		if ( empty( $arguments['data'] ) || ! is_array( $arguments['data'] ) ) {
			return array(
				'error' => __( 'data array is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$data     = $arguments['data'];
		$headers  = ! empty( $arguments['headers'] ) && is_array( $arguments['headers'] ) ? $arguments['headers'] : array();
		$title    = ! empty( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : 'Export';
		$filename = ! empty( $arguments['filename'] ) ? sanitize_file_name( $arguments['filename'] ) : 'export';

		try {
			// Export data to Excel.
			$result = $this->export_to_excel( $data, $headers, $title, $filename );

			if ( is_wp_error( $result ) ) {
				return array(
					'error' => $result->get_error_message(),
				);
			}

			// Add document HTML to response for chat display.
			return $this->add_document_html_to_response( $result );

		} catch ( Exception $e ) {
			return array(
				'error' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to export to Excel: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Export data to Excel file.
	 *
	 * @param array  $data     Data rows.
	 * @param array  $headers  Column headers.
	 * @param string $title    Spreadsheet title.
	 * @param string $filename Output filename.
	 * @return array|WP_Error Result array or error.
	 */
	protected function export_to_excel( $data, $headers, $title, $filename ) {
		// Try PhpSpreadsheet first (Composer dependency).
		if ( class_exists( '\PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
			return $this->export_with_phpspreadsheet( $data, $headers, $title, $filename );
		}

		// Fallback to CSV format.
		return $this->export_to_csv( $data, $headers, $filename );
	}

	/**
	 * Export data using PhpSpreadsheet.
	 *
	 * @param array  $data     Data rows.
	 * @param array  $headers  Column headers.
	 * @param string $title    Spreadsheet title.
	 * @param string $filename Output filename.
	 * @return array|WP_Error Result array or error.
	 */
	protected function export_with_phpspreadsheet( $data, $headers, $title, $filename ) {
		try {
			$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
			$sheet       = $spreadsheet->getActiveSheet();
			$sheet->setTitle( substr( $title, 0, 31 ) ); // Excel sheet name limit.

			$row = 1;

			// Write headers if provided.
			if ( ! empty( $headers ) ) {
				$col = 'A';
				foreach ( $headers as $header ) {
					$sheet->setCellValue( $col . $row, $header );
					// Bold headers.
					$sheet->getStyle( $col . $row )->getFont()->setBold( true );
					$col++;
				}
				$row++;
			}

			// Write data rows.
			foreach ( $data as $data_row ) {
				if ( is_array( $data_row ) ) {
					$col = 'A';
					foreach ( $data_row as $cell_value ) {
						$sheet->setCellValue( $col . $row, $cell_value );
						$col++;
					}
					$row++;
				}
			}

			// Auto-size columns.
			foreach ( range( 'A', $sheet->getHighestColumn() ) as $col ) {
				$sheet->getColumnDimension( $col )->setAutoSize( true );
			}

			// Create temp file.
			$temp_file = tempnam( sys_get_temp_dir(), 'excel_' );
			$writer    = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
			$writer->save( $temp_file );

			// Upload to WordPress media library.
			$file_array = array(
				'name'     => $filename . '.xlsx',
				'tmp_name' => $temp_file,
			);

			$attachment_id = media_handle_sideload( $file_array, 0 );

			@unlink( $temp_file );

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			// Get attachment details.
			$attachment_url = wp_get_attachment_url( $attachment_id );
			$file_path      = get_attached_file( $attachment_id );
			$file_size      = filesize( $file_path );

			return array(
				'attachment_id' => $attachment_id,
				'url'           => $attachment_url,
				'filename'      => basename( $file_path ),
				'mime_type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'size'          => $file_size,
				'text'          => sprintf(
					/* translators: 1: row count, 2: file size */
					__( 'Successfully exported %1$d rows to Excel file (%2$s).', 'mcp-ai-wpoos-pro' ),
					count( $data ),
					size_format( $file_size )
				),
			);

		} catch ( Exception $e ) {
			return new WP_Error(
				'phpspreadsheet_error',
				sprintf(
					/* translators: %s: error message */
					__( 'PhpSpreadsheet export failed: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Export data to CSV (fallback when PhpSpreadsheet not available).
	 *
	 * @param array  $data     Data rows.
	 * @param array  $headers  Column headers.
	 * @param string $filename Output filename.
	 * @return array|WP_Error Result array or error.
	 */
	protected function export_to_csv( $data, $headers, $filename ) {
		$temp_file = tempnam( sys_get_temp_dir(), 'csv_' );
		$handle    = fopen( $temp_file, 'w' );

		if ( ! $handle ) {
			return new WP_Error( 'file_creation_failed', __( 'Failed to create temporary file.', 'mcp-ai-wpoos-pro' ) );
		}

		// Write headers if provided.
		if ( ! empty( $headers ) ) {
			fputcsv( $handle, $headers );
		}

		// Write data rows.
		foreach ( $data as $row ) {
			if ( is_array( $row ) ) {
				fputcsv( $handle, $row );
			}
		}

		fclose( $handle );

		// Upload to WordPress media library with CSV extension.
		$file_array = array(
			'name'     => $filename . '.csv',
			'tmp_name' => $temp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		@unlink( $temp_file );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Get attachment details.
		$attachment_url = wp_get_attachment_url( $attachment_id );
		$file_path      = get_attached_file( $attachment_id );
		$file_size      = filesize( $file_path );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => $attachment_url,
			'filename'      => basename( $file_path ),
			'mime_type'     => 'text/csv',
			'size'          => $file_size,
			'text'          => sprintf(
				/* translators: 1: row count, 2: file size */
				__( 'Successfully exported %1$d rows to CSV file (%2$s). Note: PhpSpreadsheet not available, exported as CSV instead of Excel.', 'mcp-ai-wpoos-pro' ),
				count( $data ),
				size_format( $file_size )
			),
		);
	}
}
