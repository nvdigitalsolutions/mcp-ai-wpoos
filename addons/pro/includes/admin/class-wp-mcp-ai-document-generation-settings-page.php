<?php
/**
 * Document Generation Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Document Generation Toolkit Settings Page Class
 */
class WP_MCP_AI_Document_Generation_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'document_generation';
		$this->toolkit_name     = __( 'Document Generation Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_document_generation_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-document-generation-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-media-document';

		parent::__construct();
	}

	/**
	 * Get toolkit slug
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Render overview tab
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'Document Generation Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Professional document generation toolkit powered by modern NPM packages. Generate PDF documents, Word documents, and Excel spreadsheets with custom styling and branding.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'PDF Generation: Create PDF documents with PDFKit - custom fonts, images, tables, and styling', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'PDF Text Extraction: Extract text from PDF files with 3-tier fallback (Node.js/pdftotext/PHP)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Word Documents: Generate .docx files with docx package - headers, footers, tables, images', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Excel Spreadsheets: Create .xlsx files with ExcelJS - formulas, charts, styling, data validation', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'HTML to PDF: Convert HTML content to PDF with custom styling', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Template System: Reusable document templates with variable substitution', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Custom Branding: Add logos, watermarks, headers, and footers', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Research & Add: AI-assisted document template creation and management', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Packages Integrated', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong>pdfkit</strong> (NPM): Advanced PDF generation with vector graphics</li>
				<li><strong>docx</strong> (NPM): Microsoft Word document generation</li>
				<li><strong>exceljs</strong> (NPM): Excel spreadsheet creation and manipulation</li>
				<li><strong>pdf-parse</strong> (NPM): PDF text extraction via Node.js service</li>
				<li><strong>smalot/pdfparser</strong> (Composer): PHP fallback for PDF text extraction</li>
			</ul>

			<h3><?php esc_html_e( 'Use Cases', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Report generation and business intelligence', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Invoice and receipt creation', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Contract and legal document management', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Data export and analytics reports', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Marketing materials and brochures', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Document template library management', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Requirements & Status', 'mcp-ai-wpoos-pro' ); ?></h3>
			<table class="widefat" style="max-width: 800px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Component', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'mcp-ai-wpoos-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><strong>Node.js</strong></td>
						<td>Runtime</td>
						<td><?php echo $this->check_nodejs_available() ? '<span style="color: green;">✓ Available</span>' : '<span style="color: orange;">⚠ Not Detected</span>'; ?></td>
						<td><?php esc_html_e( 'Optional - PHP fallbacks available', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong>pdf-parse</strong></td>
						<td>NPM (Bundled)</td>
						<td><?php echo $this->check_pdf_parse_bundled() ? '<span style="color: green;">✓ Bundled</span>' : '<span style="color: red;">✗ Missing</span>'; ?></td>
						<td><?php esc_html_e( 'Pre-bundled in assets/vendor/', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong>smalot/pdfparser</strong></td>
						<td>Composer (Bundled)</td>
						<td><?php echo $this->check_smalot_pdfparser() ? '<span style="color: green;">✓ Installed</span>' : '<span style="color: orange;">⚠ Missing</span>'; ?></td>
						<td><?php esc_html_e( 'PHP fallback for PDF extraction', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong>pdfkit</strong></td>
						<td>NPM (Bundled)</td>
						<td><?php echo wp_mcp_ai_is_npm_package_available( 'pdfkit' ) ? '<span style="color: green;">✓ Bundled</span>' : '<span style="color: orange;">⚠ Missing</span>'; ?></td>
						<td><?php esc_html_e( 'Pre-bundled in assets/vendor/', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong>docx</strong></td>
						<td>NPM (Bundled)</td>
						<td><?php echo wp_mcp_ai_is_npm_package_available( 'docx' ) ? '<span style="color: green;">✓ Bundled</span>' : '<span style="color: orange;">⚠ Missing</span>'; ?></td>
						<td><?php esc_html_e( 'Pre-bundled in assets/vendor/', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
					<tr>
						<td><strong>exceljs</strong></td>
						<td>NPM (Bundled)</td>
						<td><?php echo wp_mcp_ai_is_npm_package_available( 'exceljs' ) ? '<span style="color: green;">✓ Bundled</span>' : '<span style="color: orange;">⚠ Missing</span>'; ?></td>
						<td><?php esc_html_e( 'Pre-bundled in assets/vendor/', 'mcp-ai-wpoos-pro' ); ?></td>
					</tr>
				</tbody>
			</table>
			<p style="margin-top: 10px;">
				<strong><?php esc_html_e( 'Ready to Use:', 'mcp-ai-wpoos-pro' ); ?></strong> 
				<?php if ( $this->check_all_dependencies() ): ?>
					<span style="color: green;">✓ All dependencies are bundled and ready. No installation required!</span>
				<?php else: ?>
					<span style="color: orange;">⚠ Some dependencies may be missing. The plugin will use available fallbacks.</span>
				<?php endif; ?>
			</p>

			<h3><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p><?php esc_html_e( 'For detailed usage examples and API reference:', 'mcp-ai-wpoos-pro' ); ?></p>
			<ul>
				<li><code>addons/pro/includes/tools/document-generation/README.md</code> - Complete documentation</li>
				<li><code>docs/tools/pro/document-generation.md</code> - Tool reference</li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render configuration tab
	 */
	protected function render_configuration_tab() {
		$options = get_option( $this->option_name, array() );
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Document Generation Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Document Format', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $this->option_name ); ?>[default_format]">
							<option value="pdf" <?php selected( $options['default_format'] ?? 'pdf', 'pdf' ); ?>>PDF</option>
							<option value="docx" <?php selected( $options['default_format'] ?? 'pdf', 'docx' ); ?>>Word (.docx)</option>
							<option value="xlsx" <?php selected( $options['default_format'] ?? 'pdf', 'xlsx' ); ?>>Excel (.xlsx)</option>
						</select>
						<p class="description"><?php esc_html_e( 'Default format when not specified in tool calls', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'PDF Page Size', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $this->option_name ); ?>[pdf_page_size]">
							<option value="letter" <?php selected( $options['pdf_page_size'] ?? 'letter', 'letter' ); ?>>Letter (8.5" x 11")</option>
							<option value="a4" <?php selected( $options['pdf_page_size'] ?? 'letter', 'a4' ); ?>>A4</option>
							<option value="legal" <?php selected( $options['pdf_page_size'] ?? 'letter', 'legal' ); ?>>Legal (8.5" x 14")</option>
						</select>
						<p class="description"><?php esc_html_e( 'Default page size for PDF generation', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Company Logo URL', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input 
							type="url" 
							name="<?php echo esc_attr( $this->option_name ); ?>[company_logo]" 
							value="<?php echo esc_url( $options['company_logo'] ?? '' ); ?>" 
							class="regular-text" 
							placeholder="https://example.com/logo.png"
						/>
						<p class="description"><?php esc_html_e( 'Default company logo for headers and branding', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Company Name', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input 
							type="text" 
							name="<?php echo esc_attr( $this->option_name ); ?>[company_name]" 
							value="<?php echo esc_attr( $options['company_name'] ?? get_bloginfo( 'name' ) ); ?>" 
							class="regular-text"
						/>
						<p class="description"><?php esc_html_e( 'Company name for document headers and footers', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Font Family', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $this->option_name ); ?>[default_font]">
							<option value="Helvetica" <?php selected( $options['default_font'] ?? 'Helvetica', 'Helvetica' ); ?>>Helvetica</option>
							<option value="Times-Roman" <?php selected( $options['default_font'] ?? 'Helvetica', 'Times-Roman' ); ?>>Times New Roman</option>
							<option value="Courier" <?php selected( $options['default_font'] ?? 'Helvetica', 'Courier' ); ?>>Courier</option>
						</select>
						<p class="description"><?php esc_html_e( 'Default font for PDF documents', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Storage Location', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input 
							type="text" 
							name="<?php echo esc_attr( $this->option_name ); ?>[storage_path]" 
							value="<?php echo esc_attr( $options['storage_path'] ?? 'wp-content/uploads/documents' ); ?>" 
							class="regular-text"
						/>
						<p class="description"><?php esc_html_e( 'Relative path from WordPress root for generated documents', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-Delete After', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input 
							type="number" 
							name="<?php echo esc_attr( $this->option_name ); ?>[auto_delete_days]" 
							value="<?php echo esc_attr( $options['auto_delete_days'] ?? '30' ); ?>" 
							min="0" 
							max="365" 
							class="small-text"
						/> <?php esc_html_e( 'days', 'mcp-ai-wpoos-pro' ); ?>
						<p class="description"><?php esc_html_e( 'Automatically delete generated documents after this many days (0 = never)', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Get list of tools for this toolkit
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'generate_pdf'         => __( 'Generate PDF Document', 'mcp-ai-wpoos-pro' ),
			'generate_word'        => __( 'Generate Word Document', 'mcp-ai-wpoos-pro' ),
			'generate_excel'       => __( 'Generate Excel Spreadsheet', 'mcp-ai-wpoos-pro' ),
			'html_to_pdf'          => __( 'Convert HTML to PDF', 'mcp-ai-wpoos-pro' ),
			'merge_pdfs'           => __( 'Merge Multiple PDFs', 'mcp-ai-wpoos-pro' ),
			'add_watermark_to_pdf' => __( 'Add Watermark to PDF', 'mcp-ai-wpoos-pro' ),
			'extract_pdf_text'     => __( 'Extract Text from PDF', 'mcp-ai-wpoos-pro' ),
			'excel_data_import'    => __( 'Import Data from Excel', 'mcp-ai-wpoos-pro' ),
			'excel_data_export'    => __( 'Export Data to Excel', 'mcp-ai-wpoos-pro' ),
			'generate_invoice_pdf' => __( 'Generate Invoice PDF', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check if Node.js is available
	 *
	 * @return bool
	 */
	private function check_nodejs_available() {
		// Simple check - can be enhanced.
		$node_path = function_exists( 'shell_exec' ) ? shell_exec( 'which node 2>/dev/null' ) : '';
		return ! empty( $node_path );
	}

	/**
	 * Check if NPM packages are installed
	 *
	 * Checks CDN availability, vendor directory, bundle files, and node_modules.
	 *
	 * @return bool
	 */
	private function check_npm_packages_installed() {
		$bin_dir = WP_MCP_AI_PRO_PATH . 'bin';
		
		// Use the centralized helper function for CDN-aware package checking.
		$has_pdfkit = (
			wp_mcp_ai_is_npm_package_available( 'pdfkit' ) ||
			file_exists( $bin_dir . '/generate-pdf.bundle.js' )
		);
		
		$has_docx = (
			wp_mcp_ai_is_npm_package_available( 'docx' ) ||
			file_exists( $bin_dir . '/generate-word.bundle.js' )
		);
		
		$has_exceljs = (
			wp_mcp_ai_is_npm_package_available( 'exceljs' ) ||
			file_exists( $bin_dir . '/generate-excel.bundle.js' )
		);
		
		return $has_pdfkit && $has_docx && $has_exceljs;
	}

	/**
	 * Check if pdf-parse is bundled
	 *
	 * @return bool
	 */
	private function check_pdf_parse_bundled() {
		return wp_mcp_ai_is_npm_package_available( 'pdf-parse' );
	}

	/**
	 * Check if smalot/pdfparser is installed
	 *
	 * @return bool
	 */
	private function check_smalot_pdfparser() {
		return class_exists( '\Smalot\PdfParser\Parser' );
	}

	/**
	 * Check if all dependencies are available
	 *
	 * @return bool
	 */
	private function check_all_dependencies() {
		return $this->check_pdf_parse_bundled() &&
		       $this->check_smalot_pdfparser() &&
		       $this->check_npm_packages_installed();
	}
}

// Initialize the settings page.
new WP_MCP_AI_Document_Generation_Settings_Page();
