<?php
/**
 * Pro Excel Document Tool - AI-powered Excel spreadsheet generation.
 *
 * Creates professional Excel (.xlsx) spreadsheets using AI-generated content with ExcelJS.
 * Supports structured data tables, formatting, formulas, and multi-sheet workbooks.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the document response trait from base plugin.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-document-response.php';

/**
 * Pro Excel Document tool for AI-powered Excel spreadsheet generation.
 *
 * This tool leverages AI to create professional Excel spreadsheets:
 * - Generating Excel spreadsheets from natural language descriptions
 * - Creating data tables with headers and formatted cells
 * - Adding formulas and calculations
 * - Multi-sheet workbooks
 * - Cell formatting (fonts, colors, borders, alignment)
 * - Charts and data visualization
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Pro_Excel_Document implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Document_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'pro_excel_document';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Pro Excel Document', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'AI-powered Excel spreadsheet (.xlsx) generation. Create professional Excel documents from natural language descriptions. Generate data tables, apply formatting, add formulas, and create multi-sheet workbooks with charts and calculations.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'operation'   => array(
					'type'        => 'string',
					'enum'        => array( 'generate', 'table', 'multi_sheet', 'chart' ),
					'description' => __( 'Operation to perform: "generate" (create spreadsheet from description), "table" (create data table), "multi_sheet" (multiple worksheets), "chart" (add charts and visualizations).', 'mcp-ai-wpoos' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Natural language description of the Excel spreadsheet you want to create.', 'mcp-ai-wpoos' ),
				),
				'data'        => array(
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
					'description' => __( 'Array of data rows for table generation. Each row is an array of cell values.', 'mcp-ai-wpoos' ),
				),
				'headers'     => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => __( 'Column headers for the data table.', 'mcp-ai-wpoos' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Spreadsheet title (appears in document properties and optionally as worksheet name).', 'mcp-ai-wpoos' ),
				),
				'author'      => array(
					'type'        => 'string',
					'description' => __( 'Document author (appears in document properties).', 'mcp-ai-wpoos' ),
				),
				'sheets'      => array(
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name' => array( 'type' => 'string' ),
							'data' => array(
								'type'  => 'array',
								'items' => array(
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
							),
						),
					),
					'description' => __( 'Array of worksheet definitions with names and data (for multi_sheet operation).', 'mcp-ai-wpoos' ),
				),
				'formatting'  => array(
					'type'        => 'object',
					'properties'  => array(
						'header_bg'     => array( 'type' => 'string' ),
						'header_font'   => array( 'type' => 'string' ),
						'auto_filter'   => array( 'type' => 'boolean' ),
						'freeze_header' => array( 'type' => 'boolean' ),
					),
					'description' => __( 'Formatting options for the spreadsheet (colors, auto-filter, freeze panes).', 'mcp-ai-wpoos' ),
				),
				'formulas'    => array(
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'cell'    => array( 'type' => 'string' ),
							'formula' => array( 'type' => 'string' ),
						),
					),
					'description' => __( 'Array of formulas to add to specific cells (e.g., {"cell": "D2", "formula": "=SUM(B2:C2)"}).', 'mcp-ai-wpoos' ),
				),
				'model'       => array(
					'type'        => 'string',
					'description' => __( 'AI model to use for content generation. If not specified, uses assistant default or global default.', 'mcp-ai-wpoos' ),
				),
				'upload'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to upload the generated spreadsheet to WordPress media library. Default: true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'             => array( 'operation' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                   // Pro tier feature.
			'requires-credentials',  // Requires AI provider API credentials.
			'requires-capability',   // Requires user to be logged in.
			'requires-model',        // Needs AI model to generate content.
			'consumes-tokens',       // Uses AI model tokens.
			'model-dependent',       // Quality varies by model selected.
			'external-api',          // Makes API calls to AI providers.
			'network-dependent',     // Requires internet connectivity.
			'write',                 // Creates files.
			'state-changing',        // Uploads to media library.
			'cacheable',             // Results can be cached for identical inputs.
			'non-deterministic',     // AI may generate different content for same description.
		);
	}

	/**
	 * Get tool definition for LLM payload.
	 *
	 * @return array Tool definition including name, description, parameters, and required capability.
	 */
	public function get_definition() {
		return array(
			'name'                => $this->get_name(),
			'description'         => $this->get_description(),
			'parameters'          => $this->get_parameters_schema(),
			'required_capability' => 'upload_files',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id, assistant_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Verify user is logged in.
		if ( ! $user_id ) {
			return new WP_Error(
				'wp_mcp_ai_unauthorized',
				__( 'You must be logged in to use the Pro Excel Document tool.', 'mcp-ai-wpoos' )
			);
		}

		// Check user has required capability (upload_files).
		if ( ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the Pro Excel Document tool.', 'mcp-ai-wpoos' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Check if ExcelJS package is available.
		if ( function_exists( 'wp_mcp_ai_is_npm_package_available' ) && ! wp_mcp_ai_is_npm_package_available( 'exceljs' ) ) {
			return new WP_Error(
				'wp_mcp_ai_package_not_available',
				__( 'ExcelJS package is not available. Please ensure Node.js and ExcelJS are properly installed. Visit the Pro Packages settings page for installation instructions.', 'mcp-ai-wpoos' ),
				array(
					'package'      => 'exceljs',
					'settings_url' => admin_url( 'admin.php?page=wp-mcp-ai-pro-packages-settings' ),
				)
			);
		}

		// Validate operation parameter.
		if ( empty( $arguments['operation'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_operation',
				__( 'The "operation" parameter is required.', 'mcp-ai-wpoos' )
			);
		}

		$operation        = sanitize_text_field( $arguments['operation'] );
		$valid_operations = array( 'generate', 'table', 'multi_sheet', 'chart' );

		if ( ! in_array( $operation, $valid_operations, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_operation',
				sprintf(
					/* translators: %s: comma-separated list of valid operations */
					__( 'Invalid operation. Must be one of: %s', 'mcp-ai-wpoos' ),
					implode( ', ', $valid_operations )
				)
			);
		}

		// Route to appropriate handler based on operation.
		switch ( $operation ) {
			case 'generate':
				return $this->handle_generate_operation( $arguments, $context );

			case 'table':
				return $this->handle_table_operation( $arguments, $context );

			case 'multi_sheet':
				return $this->handle_multi_sheet_operation( $arguments, $context );

			case 'chart':
				return $this->handle_chart_operation( $arguments, $context );

			default:
				return new WP_Error(
					'wp_mcp_ai_unhandled_operation',
					__( 'Operation not yet implemented.', 'mcp-ai-wpoos' )
				);
		}
	}

	/**
	 * Handle Excel spreadsheet generation from description.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Result or error.
	 */
	protected function handle_generate_operation( array $arguments, array $context ) {
		if ( empty( $arguments['description'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_description',
				__( 'The "description" parameter is required for spreadsheet generation.', 'mcp-ai-wpoos' )
			);
		}

		$description = sanitize_textarea_field( $arguments['description'] );
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$author      = isset( $arguments['author'] ) ? sanitize_text_field( $arguments['author'] ) : '';

		// Build the system prompt.
		$system_prompt = $this->build_generation_system_prompt();

		// Build the user prompt.
		$user_prompt = "Generate an Excel spreadsheet structure for:\n\n{$description}\n\nProvide data in JSON format with headers and rows.";

		// Get AI response.
		$ai_response = $this->call_ai_model( $system_prompt, $user_prompt, $arguments, $context );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		// Extract data from AI response.
		$data    = $ai_response['data'] ?? array();
		$headers = $ai_response['headers'] ?? array();

		// Generate Excel spreadsheet from AI content.
		$xlsx_result = $this->generate_excel_document(
			array(
				'data'    => $data,
				'headers' => $headers,
				'title'   => $title,
				'author'  => $author,
			),
			$arguments,
			$context
		);

		if ( is_wp_error( $xlsx_result ) ) {
			return $xlsx_result;
		}

		$result = array(
			'operation'     => 'generate',
			'title'         => $title,
			'row_count'     => count( $data ),
			'column_count'  => count( $headers ),
			'file_url'      => $xlsx_result['url'],
			'url'           => $xlsx_result['url'],
			'file_path'     => $xlsx_result['file'],
			'file_name'     => basename( $xlsx_result['file'] ),
			'mime_type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'bytes'         => isset( $xlsx_result['bytes'] ) ? $xlsx_result['bytes'] : filesize( $xlsx_result['file'] ),
			'attachment_id' => $xlsx_result['attachment_id'],
			'text'          => sprintf(
				/* translators: %s: spreadsheet title */
				__( 'Generated Excel spreadsheet: %s', 'mcp-ai-wpoos' ),
				$title ?: __( 'Untitled', 'mcp-ai-wpoos' )
			),
		);

		// Add rendered document HTML to the response for display in chat UI.
		return $this->add_document_html_to_response( $result );
	}

	/**
	 * Handle data table creation.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Result or error.
	 */
	protected function handle_table_operation( array $arguments, array $context ) {
		if ( empty( $arguments['data'] ) && empty( $arguments['description'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'Either "data" or "description" parameter is required for table creation.', 'mcp-ai-wpoos' )
			);
		}

		$title      = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$author     = isset( $arguments['author'] ) ? sanitize_text_field( $arguments['author'] ) : '';
		$formatting = isset( $arguments['formatting'] ) && is_array( $arguments['formatting'] ) ? $arguments['formatting'] : array();
		$formulas   = isset( $arguments['formulas'] ) && is_array( $arguments['formulas'] ) ? $arguments['formulas'] : array();

		// Get data (either direct or AI-generated).
		if ( ! empty( $arguments['data'] ) && is_array( $arguments['data'] ) ) {
			$data    = $arguments['data'];
			$headers = isset( $arguments['headers'] ) && is_array( $arguments['headers'] ) ? $arguments['headers'] : array();
		} else {
			$description   = sanitize_textarea_field( $arguments['description'] );
			$system_prompt = $this->build_generation_system_prompt();
			$user_prompt   = "Generate table data for:\n\n{$description}\n\nProvide structured data with headers and rows in JSON format.";

			$ai_response = $this->call_ai_model( $system_prompt, $user_prompt, $arguments, $context );

			if ( is_wp_error( $ai_response ) ) {
				return $ai_response;
			}

			$data    = $ai_response['data'] ?? array();
			$headers = $ai_response['headers'] ?? array();
		}

		// Generate Excel spreadsheet with table.
		$xlsx_result = $this->generate_excel_document(
			array(
				'data'       => $data,
				'headers'    => $headers,
				'title'      => $title,
				'author'     => $author,
				'formatting' => $formatting,
				'formulas'   => $formulas,
			),
			$arguments,
			$context
		);

		if ( is_wp_error( $xlsx_result ) ) {
			return $xlsx_result;
		}

		$result = array(
			'operation'     => 'table',
			'title'         => $title,
			'row_count'     => count( $data ),
			'column_count'  => count( $headers ),
			'file_url'      => $xlsx_result['url'],
			'url'           => $xlsx_result['url'],
			'file_path'     => $xlsx_result['file'],
			'file_name'     => basename( $xlsx_result['file'] ),
			'mime_type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'bytes'         => isset( $xlsx_result['bytes'] ) ? $xlsx_result['bytes'] : filesize( $xlsx_result['file'] ),
			'attachment_id' => $xlsx_result['attachment_id'],
			'text'          => sprintf(
				/* translators: %s: spreadsheet title */
				__( 'Generated Excel table: %s', 'mcp-ai-wpoos' ),
				$title ?: __( 'Untitled', 'mcp-ai-wpoos' )
			),
		);

		// Add rendered document HTML to the response for display in chat UI.
		return $this->add_document_html_to_response( $result );
	}

	/**
	 * Handle multi-sheet workbook creation.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Result or error.
	 */
	protected function handle_multi_sheet_operation( array $arguments, array $context ) {
		if ( empty( $arguments['sheets'] ) && empty( $arguments['description'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'Either "sheets" or "description" parameter is required for multi-sheet workbook creation.', 'mcp-ai-wpoos' )
			);
		}

		$title  = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$author = isset( $arguments['author'] ) ? sanitize_text_field( $arguments['author'] ) : '';

		// Get sheets data (either direct or AI-generated).
		if ( ! empty( $arguments['sheets'] ) && is_array( $arguments['sheets'] ) ) {
			$sheets = $arguments['sheets'];
		} else {
			$description   = sanitize_textarea_field( $arguments['description'] );
			$system_prompt = $this->build_multi_sheet_system_prompt();
			$user_prompt   = "Generate multi-sheet workbook structure for:\n\n{$description}";

			$ai_response = $this->call_ai_model( $system_prompt, $user_prompt, $arguments, $context );

			if ( is_wp_error( $ai_response ) ) {
				return $ai_response;
			}

			$sheets = $ai_response['sheets'] ?? array();
		}

		// Generate Excel workbook with multiple sheets.
		$xlsx_result = $this->generate_excel_document(
			array(
				'sheets' => $sheets,
				'title'  => $title,
				'author' => $author,
			),
			$arguments,
			$context
		);

		if ( is_wp_error( $xlsx_result ) ) {
			return $xlsx_result;
		}

		$result = array(
			'operation'     => 'multi_sheet',
			'title'         => $title,
			'sheet_count'   => count( $sheets ),
			'file_url'      => $xlsx_result['url'],
			'url'           => $xlsx_result['url'],
			'file_path'     => $xlsx_result['file'],
			'file_name'     => basename( $xlsx_result['file'] ),
			'mime_type'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'bytes'         => isset( $xlsx_result['bytes'] ) ? $xlsx_result['bytes'] : filesize( $xlsx_result['file'] ),
			'attachment_id' => $xlsx_result['attachment_id'],
			'text'          => sprintf(
				/* translators: 1: spreadsheet title, 2: number of sheets */
				__( 'Generated Excel workbook with %2$d sheets: %1$s', 'mcp-ai-wpoos' ),
				$title ?: __( 'Untitled', 'mcp-ai-wpoos' ),
				count( $sheets )
			),
		);

		// Add rendered document HTML to the response for display in chat UI.
		return $this->add_document_html_to_response( $result );
	}

	/**
	 * Handle chart creation operation.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Result or error.
	 */
	protected function handle_chart_operation( array $arguments, array $context ) {
		return new WP_Error(
			'wp_mcp_ai_not_implemented',
			__( 'Chart generation is not yet implemented. Please use the table operation and add charts manually in Excel.', 'mcp-ai-wpoos' )
		);
	}

	/**
	 * Generate Excel document.
	 *
	 * This method creates an Excel spreadsheet using Node.js/ExcelJS via a shell command.
	 * The actual document generation happens in a Node.js script.
	 *
	 * @param array $document_data Document configuration and content.
	 * @param array $arguments     Original tool arguments.
	 * @param array $context       Execution context.
	 * @return array|WP_Error Array with file, url, attachment_id or WP_Error.
	 */
	protected function generate_excel_document( array $document_data, array $arguments, array $context ) {
		// Create temporary file for document output.
		$upload_dir = wp_upload_dir();
		$temp_file  = wp_tempnam( 'xlsx-' . time() );
		$xlsx_file  = $temp_file . '.xlsx';

		// Rename temp file to have .xlsx extension.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		rename( $temp_file, $xlsx_file );

		// Create JSON file with document data for Node.js script.
		$json_file = $temp_file . '.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		file_put_contents( $json_file, wp_json_encode( $document_data ) );

		// Get bundled Excel generation script.
		$script_file = $this->get_excel_generation_script_path();
		if ( is_wp_error( $script_file ) ) {
			// Clean up temp files.
			@unlink( $xlsx_file );
			@unlink( $json_file );
			return $script_file;
		}

		// Execute Node.js script to generate document.
		$node_binary = $this->get_node_binary();
		if ( is_wp_error( $node_binary ) ) {
			// Clean up temp files.
			@unlink( $xlsx_file );
			@unlink( $json_file );
			return $node_binary;
		}

		// Escape command arguments.
		$cmd = sprintf(
			'%s %s %s %s 2>&1',
			escapeshellarg( $node_binary ),
			escapeshellarg( $script_file ),
			escapeshellarg( $json_file ),
			escapeshellarg( $xlsx_file )
		);

		// Execute command.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $cmd, $output, $return_code );

		// Clean up temp files.
		@unlink( $json_file );

		if ( 0 !== $return_code ) {
			@unlink( $xlsx_file );
			return new WP_Error(
				'wp_mcp_ai_excel_generation_failed',
				sprintf(
					/* translators: %s: error output */
					__( 'Excel document generation failed: %s', 'mcp-ai-wpoos' ),
					implode( "\n", $output )
				)
			);
		}

		// Check if document was created.
		if ( ! file_exists( $xlsx_file ) || 0 === filesize( $xlsx_file ) ) {
			@unlink( $xlsx_file );
			return new WP_Error(
				'wp_mcp_ai_excel_not_created',
				__( 'Excel document was not created successfully.', 'mcp-ai-wpoos' )
			);
		}

		// Upload to media library if requested.
		$should_upload = isset( $arguments['upload'] ) ? (bool) $arguments['upload'] : true;

		if ( $should_upload ) {
			// Prepare file for WordPress upload.
			$title    = ! empty( $document_data['title'] ) ? $document_data['title'] : 'Generated Spreadsheet';
			$filename = sanitize_file_name( $title . '.xlsx' );

			// Move to uploads directory.
			$final_file = $upload_dir['path'] . '/' . $filename;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			$move_result = rename( $xlsx_file, $final_file );

			if ( ! $move_result ) {
				@unlink( $xlsx_file );
				return new WP_Error(
					'wp_mcp_ai_excel_move_failed',
					__( 'Failed to move Excel document to uploads directory.', 'mcp-ai-wpoos' )
				);
			}

			// Create attachment.
			$attachment = array(
				'post_mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			if ( $user_id ) {
				$attachment['post_author'] = $user_id;
			}

			$attachment_id = wp_insert_attachment( $attachment, $final_file );

			if ( is_wp_error( $attachment_id ) ) {
				@unlink( $final_file );
				return $attachment_id;
			}

			// Generate attachment metadata.
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $final_file );
			wp_update_attachment_metadata( $attachment_id, $attachment_data );

			return array(
				'file'          => $final_file,
				'url'           => wp_get_attachment_url( $attachment_id ),
				'attachment_id' => $attachment_id,
			);
		}

		// Return file path only (no upload).
		return array(
			'file'          => $xlsx_file,
			'url'           => '',
			'attachment_id' => 0,
		);
	}

	/**
	 * Get path to bundled Excel generation script.
	 *
	 * @return string|WP_Error Path to script or error if not found.
	 */
	protected function get_excel_generation_script_path() {
		// Use bundled script that includes all dependencies.
		$script_path = WP_MCP_AI_PRO_PATH . 'bin/generate-excel.bundle.js';

		if ( ! file_exists( $script_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_script_not_found',
				sprintf(
					/* translators: %s: script path */
					__( 'Excel generation script not found: %s. Run "npm run build:js:pro" to build it.', 'mcp-ai-wpoos' ),
					$script_path
				)
			);
		}

		return $script_path;
	}

	/**
	 * Create Node.js script for Excel document generation.
	 *
	 * @deprecated Use bundled script instead.
	 * @return string Node.js script content.
	 */
	protected function create_excel_generation_script() {
		return <<<'JAVASCRIPT'
const fs = require('fs');
const ExcelJS = require('exceljs');

const [, , jsonFile, outputFile] = process.argv;

async function generateExcel() {
	try {
		const data = JSON.parse(fs.readFileSync(jsonFile, 'utf8'));
		const workbook = new ExcelJS.Workbook();
		
		// Set workbook properties.
		workbook.creator = data.author || 'WordPress MCP AI';
		workbook.created = new Date();
		workbook.modified = new Date();
		workbook.lastModifiedBy = data.author || 'WordPress MCP AI';
		
		// Handle different data structures.
		if (data.sheets && Array.isArray(data.sheets)) {
			// Multi-sheet workbook.
			data.sheets.forEach(sheetData => {
				const worksheet = workbook.addWorksheet(sheetData.name || 'Sheet');
				if (sheetData.data && Array.isArray(sheetData.data)) {
					if (sheetData.headers) {
						worksheet.addRow(sheetData.headers);
						worksheet.getRow(1).font = { bold: true };
					}
					sheetData.data.forEach(row => worksheet.addRow(row));
				}
			});
		} else {
			// Single sheet.
			const worksheet = workbook.addWorksheet(data.title || 'Sheet1');
			
			// Add headers if present.
			if (data.headers && Array.isArray(data.headers)) {
				const headerRow = worksheet.addRow(data.headers);
				headerRow.font = { bold: true };
				headerRow.fill = {
					type: 'pattern',
					pattern: 'solid',
					fgColor: { argb: data.formatting?.header_bg || 'FF4472C4' }
				};
				headerRow.font = { 
					bold: true, 
					color: { argb: data.formatting?.header_font || 'FFFFFFFF' }
				};
			}
			
			// Add data rows.
			if (data.data && Array.isArray(data.data)) {
				data.data.forEach(row => {
					worksheet.addRow(row);
				});
			}
			
			// Apply formatting.
			if (data.formatting) {
				if (data.formatting.auto_filter && data.headers) {
					worksheet.autoFilter = {
						from: 'A1',
						to: String.fromCharCode(64 + data.headers.length) + '1'
					};
				}
				if (data.formatting.freeze_header) {
					worksheet.views = [
						{ state: 'frozen', xSplit: 0, ySplit: 1 }
					];
				}
			}
			
			// Add formulas.
			if (data.formulas && Array.isArray(data.formulas)) {
				data.formulas.forEach(formula => {
					if (formula.cell && formula.formula) {
						const cell = worksheet.getCell(formula.cell);
						cell.value = { formula: formula.formula };
					}
				});
			}
			
			// Auto-size columns.
			worksheet.columns.forEach(column => {
				let maxLength = 0;
				column.eachCell({ includeEmpty: true }, cell => {
					const length = cell.value ? cell.value.toString().length : 10;
					if (length > maxLength) maxLength = length;
				});
				column.width = Math.min(maxLength + 2, 50);
			});
		}
		
		// Write to file.
		await workbook.xlsx.writeFile(outputFile);
		console.log('Excel document generated successfully');
		process.exit(0);
	} catch (error) {
		console.error('Error generating Excel document:', error.message);
		process.exit(1);
	}
}

generateExcel();
JAVASCRIPT;
	}

	/**
	 * Get Node.js binary path.
	 *
	 * @return string|WP_Error Node.js binary path or error.
	 */
	protected function get_node_binary() {
		// Use Process Service to get Node.js binary path.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		$node_path       = $process_service->get_command_path( 'node' );

		if ( false === $node_path ) {
			return new WP_Error(
				'wp_mcp_ai_node_not_found',
				__( 'Node.js is not installed or not found in PATH. Excel document generation requires Node.js.', 'mcp-ai-wpoos' )
			);
		}

		return $node_path;
	}

	/**
	 * Build system prompt for spreadsheet content generation.
	 *
	 * @return string System prompt.
	 */
	protected function build_generation_system_prompt() {
		$prompt  = "You are an expert data analyst specializing in creating Excel spreadsheets.\n\n";
		$prompt .= "Task: Generate structured data for Excel spreadsheets from natural language descriptions.\n\n";
		$prompt .= "Response format (JSON):\n";
		$prompt .= "{\n";
		$prompt .= '  "headers": ["Column1", "Column2", "Column3"],';
		$prompt .= "\n";
		$prompt .= '  "data": [';
		$prompt .= "\n";
		$prompt .= '    ["Value1", "Value2", "Value3"],';
		$prompt .= "\n";
		$prompt .= '    ["Value4", "Value5", "Value6"]';
		$prompt .= "\n";
		$prompt .= "  ]\n";
		$prompt .= '}';

		return $prompt;
	}

	/**
	 * Build system prompt for multi-sheet workbook generation.
	 *
	 * @return string System prompt.
	 */
	protected function build_multi_sheet_system_prompt() {
		$prompt  = "You are an expert data analyst specializing in creating multi-sheet Excel workbooks.\n\n";
		$prompt .= "Task: Generate structured multi-sheet workbook data.\n\n";
		$prompt .= "Response format (JSON):\n";
		$prompt .= "{\n";
		$prompt .= '  "sheets": [';
		$prompt .= "\n";
		$prompt .= '    {';
		$prompt .= "\n";
		$prompt .= '      "name": "Sheet1",';
		$prompt .= "\n";
		$prompt .= '      "headers": ["Col1", "Col2"],';
		$prompt .= "\n";
		$prompt .= '      "data": [["Val1", "Val2"]]';
		$prompt .= "\n";
		$prompt .= '    }';
		$prompt .= "\n";
		$prompt .= "  ]\n";
		$prompt .= '}';

		return $prompt;
	}

	/**
	 * Call AI model to process the request.
	 *
	 * @param string $system_prompt System instructions.
	 * @param string $user_prompt   User request.
	 * @param array  $arguments     Tool arguments (may include model preference).
	 * @param array  $context       Execution context.
	 * @return array|WP_Error AI response or error.
	 */
	protected function call_ai_model( $system_prompt, $user_prompt, array $arguments, array $context ) {
		// Get model preference.
		$model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : '';

		// If no model specified, try to get from assistant context or use default.
		if ( empty( $model ) ) {
			if ( isset( $context['assistant_id'] ) ) {
				$assistant_id = absint( $context['assistant_id'] );
				$model        = get_post_meta( $assistant_id, '_wp_mcp_ai_model', true );
			}

			if ( empty( $model ) ) {
				// Get global default model.
				if ( class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
					$model = WP_MCP_AI_Settings_Registry::get_setting( 'default_model', 'gpt-4o-mini' );
				} else {
					$model = 'gpt-4o-mini';
				}
			}
		}

		// Prepare messages for AI model.
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system_prompt,
			),
			array(
				'role'    => 'user',
				'content' => $user_prompt,
			),
		);

		// Get AI provider based on model.
		$provider = $this->get_provider_for_model( $model );

		// Call the appropriate provider.
		$response = $this->call_provider( $provider, $model, $messages, $context );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Try to parse JSON response if present.
		$content = $response['content'] ?? '';
		$parsed  = $this->try_parse_json_response( $content );

		if ( $parsed ) {
			// JSON response successfully parsed.
			return array_merge( array( 'content' => $content ), $parsed );
		}

		// Plain text response.
		return array( 'content' => $content );
	}

	/**
	 * Get provider name for a model.
	 *
	 * @param string $model Model identifier.
	 * @return string Provider name (openai, gemini, ollama).
	 */
	protected function get_provider_for_model( $model ) {
		// Check for Gemini models.
		if ( false !== strpos( $model, 'gemini' ) ) {
			return 'gemini';
		}

		// Check for Ollama models.
		if ( false !== strpos( $model, 'llama' ) || false !== strpos( $model, 'mistral' ) || false !== strpos( $model, 'qwen' ) ) {
			return 'ollama';
		}

		// Default to OpenAI.
		return 'openai';
	}

	/**
	 * Call AI provider with messages.
	 *
	 * @param string $provider Provider name.
	 * @param string $model    Model identifier.
	 * @param array  $messages Message array.
	 * @param array  $context  Execution context.
	 * @return array|WP_Error Response or error.
	 */
	protected function call_provider( $provider, $model, array $messages, array $context ) {
		// Load client classes if needed.
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
		}

		try {
			switch ( $provider ) {
				case 'gemini':
					if ( ! class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
						require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-gemini-client.php';
					}
					$client_instance = new WP_MCP_AI_Gemini_Client();
					break;

				case 'ollama':
					if ( ! class_exists( 'WP_MCP_AI_Ollama_Client' ) ) {
						require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-ollama-client.php';
					}
					$client_instance = new WP_MCP_AI_Ollama_Client();
					break;

				case 'openai':
				default:
					$client_instance = new WP_MCP_AI_OpenAI_Client();
					break;
			}

			// Make API call.
			$response = $client_instance->create_chat_completion(
				$messages,
				array(
					'model' => $model,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Extract content from response.
			$content = '';
			if ( isset( $response['choices'][0]['message']['content'] ) ) {
				$content = $response['choices'][0]['message']['content'];
			} elseif ( isset( $response['content'] ) ) {
				$content = $response['content'];
			}

			return array( 'content' => $content );

		} catch ( Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_provider_error',
				sprintf(
					/* translators: %s: error message */
					__( 'AI provider error: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Try to parse JSON response from AI model.
	 *
	 * @param string $content Response content.
	 * @return array|false Parsed JSON or false if not valid JSON.
	 */
	protected function try_parse_json_response( $content ) {
		// Try to find JSON in the response (may be wrapped in markdown code blocks).
		$json_pattern = '/```(?:json)?\s*(\{.*?\})\s*```/s';
		if ( preg_match( $json_pattern, $content, $matches ) ) {
			$json_str = $matches[1];
		} else {
			// Try to find JSON object directly.
			if ( preg_match( '/\{.*\}/s', $content, $matches ) ) {
				$json_str = $matches[0];
			} else {
				return false;
			}
		}

		$parsed = json_decode( $json_str, true );

		if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
			return $parsed;
		}

		return false;
	}
}
