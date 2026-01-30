<?php
/**
 * Pro PDF Tool - AI-powered PDF document generation.
 *
 * Creates professional PDF documents using AI-generated content with pdfkit.
 * Supports structured document generation, formatting, and template-based creation.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the document response trait from base plugin.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-document-response.php';

// Load HTML formatter class.
require_once __DIR__ . '/class-wp-mcp-ai-html-formatter.php';

/**
 * Pro PDF tool for AI-powered PDF document generation.
 *
 * This tool leverages AI to create professional PDF documents:
 * - Generating PDF content from natural language descriptions
 * - Creating structured documents with sections, headings, lists
 * - Formatting text with styles, fonts, colors
 * - Adding tables, images, and other elements
 * - Template-based document generation
 * - Multi-page document support
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Pro_PDF implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Document_Response;

	/**
	 * HTML formatter instance.
	 *
	 * @var WP_MCP_AI_HTML_Formatter
	 */
	protected $html_formatter;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->html_formatter = new WP_MCP_AI_HTML_Formatter();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'pro_pdf';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Pro PDF', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'AI-powered PDF document generation. Create professional PDF documents from natural language descriptions. Generate structured documents with sections, headings, tables, and formatting. Supports multi-page documents, custom fonts, colors, and layouts.', 'mcp-ai-wpoos' );
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
					'enum'        => array( 'generate', 'structure', 'format' ),
					'description' => __( 'Operation to perform: "generate" (create PDF from description), "structure" (create structured document with sections), "format" (apply formatting to content).', 'mcp-ai-wpoos' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Natural language description of the PDF document you want to create.', 'mcp-ai-wpoos' ),
				),
				'content'     => array(
					'type'        => 'string',
					'description' => __( 'Content to include in the PDF document. Can be plain text or structured data.', 'mcp-ai-wpoos' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Document title (appears in PDF metadata and optionally on first page).', 'mcp-ai-wpoos' ),
				),
				'author'      => array(
					'type'        => 'string',
					'description' => __( 'Document author (appears in PDF metadata).', 'mcp-ai-wpoos' ),
				),
				'sections'    => array(
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'heading' => array( 'type' => 'string' ),
							'content' => array( 'type' => 'string' ),
						),
					),
					'description' => __( 'Array of document sections with headings and content (for structure operation).', 'mcp-ai-wpoos' ),
				),
				'formatting'  => array(
					'type'        => 'object',
					'properties'  => array(
						'font_size'   => array( 'type' => 'number' ),
						'font_family' => array( 'type' => 'string' ),
						'color'       => array( 'type' => 'string' ),
						'line_height' => array( 'type' => 'number' ),
					),
					'description' => __( 'Formatting options for the document (font size, family, color, line height).', 'mcp-ai-wpoos' ),
				),
				'page_size'   => array(
					'type'        => 'string',
					'enum'        => array( 'A4', 'Letter', 'Legal', 'A3', 'A5' ),
					'description' => __( 'Page size for the document. Default: A4.', 'mcp-ai-wpoos' ),
					'default'     => 'A4',
				),
				'orientation' => array(
					'type'        => 'string',
					'enum'        => array( 'portrait', 'landscape' ),
					'description' => __( 'Page orientation. Default: portrait.', 'mcp-ai-wpoos' ),
					'default'     => 'portrait',
				),
				'model'       => array(
					'type'        => 'string',
					'description' => __( 'AI model to use for content generation. If not specified, uses assistant default or global default.', 'mcp-ai-wpoos' ),
				),
				'upload'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to upload the generated PDF to WordPress media library. Default: true.', 'mcp-ai-wpoos' ),
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
				__( 'You must be logged in to use the Pro PDF tool.', 'mcp-ai-wpoos' )
			);
		}

		// Check user has required capability (upload_files).
		if ( ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the Pro PDF tool.', 'mcp-ai-wpoos' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Validate operation parameter.
		if ( empty( $arguments['operation'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_operation',
				__( 'The "operation" parameter is required.', 'mcp-ai-wpoos' )
			);
		}

		$operation        = sanitize_text_field( $arguments['operation'] );
		$valid_operations = array( 'generate', 'structure', 'format' );

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

		// Get page settings.
		$page_size   = isset( $arguments['page_size'] ) ? sanitize_text_field( $arguments['page_size'] ) : 'A4';
		$orientation = isset( $arguments['orientation'] ) ? sanitize_text_field( $arguments['orientation'] ) : 'portrait';

		if ( ! in_array( $page_size, array( 'A4', 'Letter', 'Legal', 'A3', 'A5' ), true ) ) {
			$page_size = 'A4';
		}

		if ( ! in_array( $orientation, array( 'portrait', 'landscape' ), true ) ) {
			$orientation = 'portrait';
		}

		// Route to appropriate handler based on operation.
		switch ( $operation ) {
			case 'generate':
				return $this->handle_generate_operation( $arguments, $context, $page_size, $orientation );

			case 'structure':
				return $this->handle_structure_operation( $arguments, $context, $page_size, $orientation );

			case 'format':
				return $this->handle_format_operation( $arguments, $context, $page_size, $orientation );

			default:
				return new WP_Error(
					'wp_mcp_ai_unhandled_operation',
					__( 'Operation not yet implemented.', 'mcp-ai-wpoos' )
				);
		}
	}

	/**
	 * Handle PDF generation from description.
	 *
	 * @param array  $arguments  Tool arguments.
	 * @param array  $context    Execution context.
	 * @param string $page_size  Page size.
	 * @param string $orientation Page orientation.
	 * @return array|WP_Error Result or error.
	 */
	protected function handle_generate_operation( array $arguments, array $context, $page_size, $orientation ) {
		if ( empty( $arguments['description'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_description',
				__( 'The "description" parameter is required for PDF generation.', 'mcp-ai-wpoos' )
			);
		}

		$description = sanitize_textarea_field( $arguments['description'] );
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$author      = isset( $arguments['author'] ) ? sanitize_text_field( $arguments['author'] ) : '';

		// Build the system prompt.
		$system_prompt = $this->build_generation_system_prompt();

		// Build the user prompt.
		$user_prompt = $this->build_generation_user_prompt( $description, $title );

		// Get AI response.
		$ai_response = $this->call_ai_model( $system_prompt, $user_prompt, $arguments, $context );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		// Generate PDF from AI content.
		$pdf_result = $this->generate_pdf_document(
			array(
				'content'     => $ai_response['content'],
				'title'       => $title,
				'author'      => $author,
				'page_size'   => $page_size,
				'orientation' => $orientation,
			),
			$arguments,
			$context
		);

		if ( is_wp_error( $pdf_result ) ) {
			return $pdf_result;
		}

		$result = array(
			'operation'     => 'generate',
			'page_size'     => $page_size,
			'orientation'   => $orientation,
			'title'         => $title,
			'file_url'      => $pdf_result['url'],
			'url'           => $pdf_result['url'], // Add for trait compatibility.
			'file_path'     => $pdf_result['file'],
			'file_name'     => basename( $pdf_result['file'] ),
			'mime_type'     => 'application/pdf',
			'bytes'         => isset( $pdf_result['bytes'] ) ? $pdf_result['bytes'] : filesize( $pdf_result['file'] ),
			'attachment_id' => $pdf_result['attachment_id'],
			'text'          => sprintf(
				/* translators: %s: document title */
				__( 'Generated PDF document: %s', 'mcp-ai-wpoos' ),
				$title ?: __( 'Untitled', 'mcp-ai-wpoos' )
			),
		);

		// Add rendered document HTML to the response for display in chat UI.
		return $this->add_document_html_to_response( $result );
	}

	/**
	 * Handle structured PDF document creation.
	 *
	 * @param array  $arguments  Tool arguments.
	 * @param array  $context    Execution context.
	 * @param string $page_size  Page size.
	 * @param string $orientation Page orientation.
	 * @return array|WP_Error Result or error.
	 */
	protected function handle_structure_operation( array $arguments, array $context, $page_size, $orientation ) {
		if ( empty( $arguments['sections'] ) && empty( $arguments['description'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'Either "sections" or "description" parameter is required for structured document creation.', 'mcp-ai-wpoos' )
			);
		}

		$title  = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$author = isset( $arguments['author'] ) ? sanitize_text_field( $arguments['author'] ) : '';

		// If sections are provided, use them directly.
		if ( ! empty( $arguments['sections'] ) && is_array( $arguments['sections'] ) ) {
			$sections = $arguments['sections'];
		} else {
			// Use AI to generate sections from description.
			$description   = sanitize_textarea_field( $arguments['description'] );
			$system_prompt = $this->build_structure_system_prompt();
			$user_prompt   = "Create a structured document with sections for:\n\n{$description}";

			$ai_response = $this->call_ai_model( $system_prompt, $user_prompt, $arguments, $context );

			if ( is_wp_error( $ai_response ) ) {
				return $ai_response;
			}

			$sections = $ai_response['sections'] ?? array();
		}

		// Generate PDF with structured content.
		$pdf_result = $this->generate_pdf_document(
			array(
				'sections'    => $sections,
				'title'       => $title,
				'author'      => $author,
				'page_size'   => $page_size,
				'orientation' => $orientation,
			),
			$arguments,
			$context
		);

		if ( is_wp_error( $pdf_result ) ) {
			return $pdf_result;
		}

		$result = array(
			'operation'     => 'structure',
			'page_size'     => $page_size,
			'orientation'   => $orientation,
			'title'         => $title,
			'section_count' => count( $sections ),
			'file_url'      => $pdf_result['url'],
			'url'           => $pdf_result['url'],
			'file_path'     => $pdf_result['file'],
			'file_name'     => basename( $pdf_result['file'] ),
			'mime_type'     => 'application/pdf',
			'bytes'         => isset( $pdf_result['bytes'] ) ? $pdf_result['bytes'] : filesize( $pdf_result['file'] ),
			'attachment_id' => $pdf_result['attachment_id'],
			'text'          => sprintf(
				/* translators: %s: document title */
				__( 'Generated structured PDF document: %s', 'mcp-ai-wpoos' ),
				$title ?: __( 'Untitled', 'mcp-ai-wpoos' )
			),
		);

		// Add rendered document HTML to the response for display in chat UI.
		return $this->add_document_html_to_response( $result );
	}

	/**
	 * Handle formatted PDF document creation.
	 *
	 * @param array  $arguments  Tool arguments.
	 * @param array  $context    Execution context.
	 * @param string $page_size  Page size.
	 * @param string $orientation Page orientation.
	 * @return array|WP_Error Result or error.
	 */
	protected function handle_format_operation( array $arguments, array $context, $page_size, $orientation ) {
		if ( empty( $arguments['content'] ) && empty( $arguments['description'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'Either "content" or "description" parameter is required for formatted document creation.', 'mcp-ai-wpoos' )
			);
		}

		$title      = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$author     = isset( $arguments['author'] ) ? sanitize_text_field( $arguments['author'] ) : '';
		$formatting = isset( $arguments['formatting'] ) && is_array( $arguments['formatting'] ) ? $arguments['formatting'] : array();

		// Get content (either direct or AI-generated).
		if ( ! empty( $arguments['content'] ) ) {
			$content = sanitize_textarea_field( $arguments['content'] );
		} else {
			$description   = sanitize_textarea_field( $arguments['description'] );
			$system_prompt = $this->build_generation_system_prompt();
			$user_prompt   = "Generate formatted content for:\n\n{$description}";

			$ai_response = $this->call_ai_model( $system_prompt, $user_prompt, $arguments, $context );

			if ( is_wp_error( $ai_response ) ) {
				return $ai_response;
			}

			$content = $ai_response['content'];
		}

		// Generate PDF with formatting.
		$pdf_result = $this->generate_pdf_document(
			array(
				'content'     => $content,
				'title'       => $title,
				'author'      => $author,
				'formatting'  => $formatting,
				'page_size'   => $page_size,
				'orientation' => $orientation,
			),
			$arguments,
			$context
		);

		if ( is_wp_error( $pdf_result ) ) {
			return $pdf_result;
		}

		$result = array(
			'operation'     => 'format',
			'page_size'     => $page_size,
			'orientation'   => $orientation,
			'title'         => $title,
			'file_url'      => $pdf_result['url'],
			'url'           => $pdf_result['url'],
			'file_path'     => $pdf_result['file'],
			'file_name'     => basename( $pdf_result['file'] ),
			'mime_type'     => 'application/pdf',
			'bytes'         => isset( $pdf_result['bytes'] ) ? $pdf_result['bytes'] : filesize( $pdf_result['file'] ),
			'attachment_id' => $pdf_result['attachment_id'],
			'text'          => sprintf(
				/* translators: %s: document title */
				__( 'Generated formatted PDF document: %s', 'mcp-ai-wpoos' ),
				$title ?: __( 'Untitled', 'mcp-ai-wpoos' )
			),
		);

		// Add rendered document HTML to the response for display in chat UI.
		return $this->add_document_html_to_response( $result );
	}

	/**
	 * Convert document data to well-formatted HTML.
	 *
	 * @param array $document_data Document data with content/sections.
	 * @return string Formatted HTML content.
	 */
	protected function convert_to_html( array $document_data ) {
		$html_content = '';

		// Handle sections if provided.
		if ( ! empty( $document_data['sections'] ) && is_array( $document_data['sections'] ) ) {
			$html_content = $this->html_formatter->sections_to_html( $document_data['sections'] );
		} elseif ( ! empty( $document_data['content'] ) ) {
			// Convert plain text content to HTML.
			$html_content = $this->html_formatter->text_to_html( $document_data['content'] );
		}

		// Wrap in a complete HTML document with proper structure.
		$options = array(
			'title'       => ! empty( $document_data['title'] ) ? $document_data['title'] : 'Document',
			'author'      => ! empty( $document_data['author'] ) ? $document_data['author'] : '',
			'orientation' => ! empty( $document_data['orientation'] ) ? $document_data['orientation'] : 'portrait',
			'page_width'  => ! empty( $document_data['page_size'] ) ? $this->get_page_width( $document_data['page_size'] ) : '816px',
		);

		// Apply formatting options if provided.
		if ( ! empty( $document_data['formatting'] ) ) {
			if ( isset( $document_data['formatting']['font_family'] ) ) {
				$options['font_family'] = $document_data['formatting']['font_family'];
			}
			if ( isset( $document_data['formatting']['font_size'] ) ) {
				$options['font_size'] = $document_data['formatting']['font_size'];
			}
		}

		return $this->html_formatter->create_document( $html_content, $options );
	}

	/**
	 * Get page width in pixels based on page size.
	 *
	 * @param string $page_size Page size (A4, Letter, Legal, A3, A5).
	 * @return string Width in pixels.
	 */
	protected function get_page_width( $page_size ) {
		$widths = array(
			'A4'     => '816px',  // Approximation (actual A4 is 8.27", using Letter width for consistency).
			'Letter' => '816px',  // 8.5 inches at 96 DPI.
			'Legal'  => '816px',  // 8.5 inches at 96 DPI.
			'A3'     => '1123px', // Approximation (actual A3 is 11.69", ~1122px at 96 DPI).
			'A5'     => '559px',  // Approximation (actual A5 is 5.83", ~560px at 96 DPI).
		);

		return isset( $widths[ $page_size ] ) ? $widths[ $page_size ] : $widths['A4'];
	}

	/**
	 * Generate PDF document.
	 *
	 * This method creates a PDF using Node.js/pdfkit via a shell command.
	 * The actual PDF generation happens in a Node.js script.
	 *
	 * @param array $document_data Document configuration and content.
	 * @param array $arguments     Original tool arguments.
	 * @param array $context       Execution context.
	 * @return array|WP_Error Array with file, url, attachment_id or WP_Error.
	 */
	protected function generate_pdf_document( array $document_data, array $arguments, array $context ) {
		// Convert content to HTML for improved formatting.
		$html_content = $this->convert_to_html( $document_data );
		$document_data['html_content'] = $html_content;

		// Create temporary file for PDF output.
		$upload_dir = wp_upload_dir();
		$temp_file  = wp_tempnam( 'pdf-' . time() );
		$pdf_file   = $temp_file . '.pdf';

		// Rename temp file to have .pdf extension.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		rename( $temp_file, $pdf_file );

		// Create JSON file with document data for Node.js script.
		$json_file = $temp_file . '.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		file_put_contents( $json_file, wp_json_encode( $document_data ) );

		// Get bundled PDF generation script.
		$script_file = $this->get_pdf_generation_script_path();
		if ( is_wp_error( $script_file ) ) {
			// Clean up temp files.
			@unlink( $pdf_file );
			@unlink( $json_file );
			return $script_file;
		}

		// Execute Node.js script to generate PDF.
		$node_binary = $this->get_node_binary();
		if ( is_wp_error( $node_binary ) ) {
			// Clean up temp files.
			@unlink( $pdf_file );
			@unlink( $json_file );
			return $node_binary;
		}

		// Escape command arguments.
		$cmd = sprintf(
			'%s %s %s %s 2>&1',
			escapeshellarg( $node_binary ),
			escapeshellarg( $script_file ),
			escapeshellarg( $json_file ),
			escapeshellarg( $pdf_file )
		);

		// Execute command.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $cmd, $output, $return_code );

		// Clean up temp files.
		@unlink( $json_file );

		if ( 0 !== $return_code ) {
			@unlink( $pdf_file );
			return new WP_Error(
				'wp_mcp_ai_pdf_generation_failed',
				sprintf(
					/* translators: %s: error output */
					__( 'PDF generation failed: %s', 'mcp-ai-wpoos' ),
					implode( "\n", $output )
				)
			);
		}

		// Check if PDF was created.
		if ( ! file_exists( $pdf_file ) || 0 === filesize( $pdf_file ) ) {
			@unlink( $pdf_file );
			return new WP_Error(
				'wp_mcp_ai_pdf_not_created',
				__( 'PDF file was not created successfully.', 'mcp-ai-wpoos' )
			);
		}

		// Upload to media library if requested.
		$should_upload = isset( $arguments['upload'] ) ? (bool) $arguments['upload'] : true;

		if ( $should_upload ) {
			// Prepare file for WordPress upload.
			$title    = ! empty( $document_data['title'] ) ? $document_data['title'] : 'Generated PDF';
			$filename = sanitize_file_name( $title . '.pdf' );

			// Move to uploads directory.
			$final_file = $upload_dir['path'] . '/' . $filename;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			$move_result = rename( $pdf_file, $final_file );

			if ( ! $move_result ) {
				@unlink( $pdf_file );
				return new WP_Error(
					'wp_mcp_ai_pdf_move_failed',
					__( 'Failed to move PDF to uploads directory.', 'mcp-ai-wpoos' )
				);
			}

			// Create attachment.
			$attachment = array(
				'post_mime_type' => 'application/pdf',
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
			'file'          => $pdf_file,
			'url'           => '',
			'attachment_id' => 0,
		);
	}

	/**
	 * Get path to bundled PDF generation script.
	 *
	 * @return string|WP_Error Path to script or error if not found.
	 */
	protected function get_pdf_generation_script_path() {
		// Use bundled script that includes all dependencies.
		$script_path = WP_MCP_AI_PRO_PATH . 'bin/generate-pdf.bundle.js';

		if ( ! file_exists( $script_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_script_not_found',
				sprintf(
					/* translators: %s: script path */
					__( 'PDF generation script not found: %s. Run "npm run build:js:pro" to build it.', 'mcp-ai-wpoos' ),
					$script_path
				)
			);
		}

		return $script_path;
	}

	/**
	 * Create Node.js script for PDF generation.
	 *
	 * @deprecated Use bundled script instead.
	 * @return string Node.js script content.
	 */
	protected function create_pdf_generation_script() {
		return <<<'JAVASCRIPT'
const fs = require('fs');
const PDFDocument = require('pdfkit');

const [, , jsonFile, outputFile] = process.argv;

try {
	const data = JSON.parse(fs.readFileSync(jsonFile, 'utf8'));
	const doc = new PDFDocument({
		size: data.page_size || 'A4',
		layout: data.orientation || 'portrait',
		margin: 50
	});

	doc.pipe(fs.createWriteStream(outputFile));

	// Set document metadata.
	if (data.title) {
		doc.info.Title = data.title;
	}
	if (data.author) {
		doc.info.Author = data.author;
	}

	// Add title.
	if (data.title) {
		doc.fontSize(24).font('Helvetica-Bold').text(data.title, {
			align: 'center'
		});
		doc.moveDown(2);
	}

	// Handle different content types.
	if (data.sections && Array.isArray(data.sections)) {
		// Structured document with sections.
		data.sections.forEach(section => {
			if (section.heading) {
				doc.fontSize(18).font('Helvetica-Bold').text(section.heading);
				doc.moveDown(0.5);
			}
			if (section.content) {
				doc.fontSize(12).font('Helvetica').text(section.content, {
					align: 'justify'
				});
				doc.moveDown(1);
			}
		});
	} else if (data.content) {
		// Simple content.
		const fontSize = (data.formatting && data.formatting.font_size) || 12;
		const font = (data.formatting && data.formatting.font_family) || 'Helvetica';
		
		doc.fontSize(fontSize).font(font).text(data.content, {
			align: 'justify'
		});
	}

	doc.end();
	console.log('PDF generated successfully');
	process.exit(0);
} catch (error) {
	console.error('Error generating PDF:', error.message);
	process.exit(1);
}
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
				__( 'Node.js is not installed or not found in PATH. PDF generation requires Node.js.', 'mcp-ai-wpoos' )
			);
		}

		return $node_path;
	}

	/**
	 * Build system prompt for PDF content generation.
	 *
	 * @return string System prompt.
	 */
	protected function build_generation_system_prompt() {
		$prompt  = "You are an expert document writer specializing in creating professional PDF content.\n\n";
		$prompt .= "Task: Generate well-structured, professional content for PDF documents.\n\n";
		$prompt .= "Best practices:\n";
		$prompt .= "- Use clear, concise language\n";
		$prompt .= "- Organize content logically with headings and sections\n";
		$prompt .= "- Include relevant details and examples\n";
		$prompt .= "- Format for readability (paragraphs, lists, emphasis)\n";
		$prompt .= "- Maintain professional tone\n";
		$prompt .= "- Consider document purpose and audience\n\n";
		$prompt .= 'Provide content that is ready to be rendered in a PDF document.';

		return $prompt;
	}

	/**
	 * Build user prompt for PDF content generation.
	 *
	 * @param string $description User's document description.
	 * @param string $title       Document title.
	 * @return string User prompt.
	 */
	protected function build_generation_user_prompt( $description, $title ) {
		$prompt = "Generate professional content for a PDF document:\n\n";

		if ( $title ) {
			$prompt .= "Title: {$title}\n\n";
		}

		$prompt .= "Description: {$description}\n\n";
		$prompt .= 'Provide well-structured, professional content ready for PDF rendering.';

		return $prompt;
	}

	/**
	 * Build system prompt for structured document creation.
	 *
	 * @return string System prompt.
	 */
	protected function build_structure_system_prompt() {
		$prompt  = "You are an expert document architect specializing in creating structured documents.\n\n";
		$prompt .= "Task: Create a structured document outline with sections, headings, and content.\n\n";
		$prompt .= "Response format (JSON):\n";
		$prompt .= "{\n";
		$prompt .= '  "sections": [';
		$prompt .= "\n";
		$prompt .= '    {"heading": "Section Title", "content": "Section content..."},';
		$prompt .= "\n";
		$prompt .= '    {"heading": "Another Section", "content": "More content..."}';
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
