<?php
/**
 * WP_MCP_AI_Tool_Excel_Data_Import (ecosystem port - Wave F2, document-generation tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/document-generation/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger/trait requires gain exists-check seams resolving from the addon's D8-compat `src/` copies; the monolith-gated openai/gemini/ollama client requires stay monolith-gated (architectural-design precedent).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}


// Load the chat response trait from base plugin.
if ( ! trait_exists( 'WP_MCP_AI_Tool_Chat_Response' ) ) {
	$nvoos_content_graph_pro_tool_chat_response = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/trait-wp-mcp-ai-tool-chat-response.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_chat_response ) ) {
		require_once $nvoos_content_graph_pro_tool_chat_response;
	}
}

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
		return __( 'Excel Data Import', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Import data from Excel spreadsheets (.xlsx, .xls). Extract tables, cell values, and formatting for processing or database import. Supports multiple sheets and data validation.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'WordPress attachment ID of the Excel file to import.', 'nvoos-content-graph-pro' ),
				),
				'sheet_index'   => array(
					'type'        => 'integer',
					'description' => __( 'Sheet index to import (0-based). Default: 0 (first sheet)', 'nvoos-content-graph-pro' ),
				),
				'has_headers'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether first row contains column headers. Default: true', 'nvoos-content-graph-pro' ),
				),
				'max_rows'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of rows to import. Default: all rows', 'nvoos-content-graph-pro' ),
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
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check user capability.
		if ( ! current_user_can( 'read' ) ) {
			return array(
				'error' => __( 'You do not have permission to access files.', 'nvoos-content-graph-pro' ),
			);
		}

		// Validate required parameters.
		if ( empty( $arguments['attachment_id'] ) ) {
			return array(
				'error' => __( 'attachment_id is required.', 'nvoos-content-graph-pro' ),
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
					__( 'Successfully imported %1$d rows with %2$d columns from Excel file.', 'nvoos-content-graph-pro' ),
					$result['row_count'],
					$result['column_count']
				)
			);

		} catch ( Exception $e ) {
			return array(
				'error' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to import Excel data: %s', 'nvoos-content-graph-pro' ),
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
			return new WP_Error( 'file_not_found', __( 'Excel file not found.', 'nvoos-content-graph-pro' ) );
		}

		// Validate it's an Excel file.
		$mime_type   = mime_content_type( $file_path );
		$valid_types = array(
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx.
			'application/vnd.ms-excel', // .xls.
		);

		if ( ! in_array( $mime_type, $valid_types, true ) ) {
			return new WP_Error( 'invalid_file', __( 'File is not a valid Excel spreadsheet.', 'nvoos-content-graph-pro' ) );
		}

		// Try PhpSpreadsheet.
		if ( class_exists( '\PhpOffice\PhpSpreadsheet\IOFactory' ) ) {
			return $this->import_with_phpspreadsheet( $file_path, $sheet_index, $has_headers, $max_rows );
		}

		// No suitable import method available.
		return new WP_Error(
			'no_importer',
			__( 'Excel data import requires PhpSpreadsheet library (already in composer.json - run: cd addons/pro && composer install).', 'nvoos-content-graph-pro' )
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
					$headers      = array_shift( $data );
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
					__( 'PhpSpreadsheet import failed: %s', 'nvoos-content-graph-pro' ),
					$e->getMessage()
				)
			);
		}
	}
}
