<?php
/**
 * Document Generation Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Document Generation functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Document Generation Settings Page
 */
class WP_MCP_AI_Document_Generation_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_document_generation_settings';
		$this->post_type   = 'mcp_ai_doc_tpl';
		$this->page_title  = __( 'Document Generation Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'document-generation-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add document generation-specific settings.
		add_settings_field(
			'default_page_size',
			__( 'Default Page Size', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_page_size_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'default_orientation',
			__( 'Default Orientation', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_orientation_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_branding',
			__( 'Enable Branding', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_branding_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'nodejs_available',
			__( 'Node.js Status', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_nodejs_status_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);

		add_settings_field(
			'enable_research',
			__( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render default page size field.
	 */
	public function render_default_page_size_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_page_size'] ) ? $options['default_page_size'] : 'a4';

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[default_page_size]" class="regular-text">
			<option value="a4" <?php selected( $value, 'a4' ); ?>>A4</option>
			<option value="letter" <?php selected( $value, 'letter' ); ?>>Letter</option>
			<option value="legal" <?php selected( $value, 'legal' ); ?>>Legal</option>
		</select>
		<p class="description"><?php esc_html_e( 'Default page size for generated documents', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render default orientation field.
	 */
	public function render_default_orientation_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['default_orientation'] ) ? $options['default_orientation'] : 'portrait';

		?>
		<select name="<?php echo esc_attr( $this->option_name ); ?>[default_orientation]">
			<option value="portrait" <?php selected( $value, 'portrait' ); ?>><?php esc_html_e( 'Portrait', 'mcp-ai-wpoos-pro' ); ?></option>
			<option value="landscape" <?php selected( $value, 'landscape' ); ?>><?php esc_html_e( 'Landscape', 'mcp-ai-wpoos-pro' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Default page orientation', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render enable branding field.
	 */
	public function render_enable_branding_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_branding'] ) ? (bool) $options['enable_branding'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_branding]"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Include logo, watermark, and custom branding in generated documents', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render Node.js status field.
	 */
	public function render_nodejs_status_field() {
		$nodejs_available     = $this->check_nodejs_available();
		$npm_packages         = $this->check_npm_packages_installed();
		$optional_npm_packages = $this->check_optional_npm_packages_installed();

		?>
		<p>
			<strong><?php esc_html_e( 'Node.js:', 'mcp-ai-wpoos-pro' ); ?></strong>
			<?php if ( $nodejs_available ) : ?>
				<span style="color: green;">✓ <?php esc_html_e( 'Available', 'mcp-ai-wpoos-pro' ); ?></span>
			<?php else : ?>
				<span style="color: orange;">⚠ <?php esc_html_e( 'Not Available (PHP fallbacks will be used)', 'mcp-ai-wpoos-pro' ); ?></span>
			<?php endif; ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Core NPM Packages:', 'mcp-ai-wpoos-pro' ); ?></strong>
			<?php if ( $npm_packages ) : ?>
				<span style="color: green;">✓ <?php esc_html_e( 'Installed (pdfkit, docx, exceljs, pdf-lib)', 'mcp-ai-wpoos-pro' ); ?></span>
			<?php else : ?>
				<span style="color: orange;">⚠ <?php esc_html_e( 'Not Installed', 'mcp-ai-wpoos-pro' ); ?></span>
				<br>
				<code>cd <?php echo esc_html( WP_MCP_AI_PRO_PATH ); ?> && npm install</code>
			<?php endif; ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Optional Packages:', 'mcp-ai-wpoos-pro' ); ?></strong>
			<?php if ( $optional_npm_packages ) : ?>
				<span style="color: green;">✓ <?php esc_html_e( 'Installed (puppeteer-core for advanced HTML to PDF)', 'mcp-ai-wpoos-pro' ); ?></span>
			<?php else : ?>
				<span style="color: gray;">○ <?php esc_html_e( 'Not Installed (optional - advanced HTML rendering)', 'mcp-ai-wpoos-pro' ); ?></span>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Node.js and NPM packages enable advanced document generation features. PHP fallbacks and command-line tools (pdftk, pdftotext, wkhtmltopdf) are used when Node.js packages are unavailable.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Check if Node.js is available.
	 *
	 * @return bool
	 */
	protected function check_nodejs_available() {
		// Simple check - try to run node --version.
		$output = array();
		$return = null;
		@exec( 'node --version 2>&1', $output, $return ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		return 0 === $return && ! empty( $output );
	}

	/**
	 * Check if NPM packages are installed.
	 *
	 * @return bool
	 */
	protected function check_npm_packages_installed() {
		$node_modules = WP_MCP_AI_PRO_PATH . 'node_modules';
		
		// Check core document generation packages.
		$core_packages = file_exists( $node_modules . '/pdfkit/package.json' ) &&
						file_exists( $node_modules . '/docx/package.json' ) &&
						file_exists( $node_modules . '/exceljs/package.json' );
		
		// Check utility packages (optional).
		$utility_packages = file_exists( $node_modules . '/pdf-lib/package.json' );
		
		return $core_packages && $utility_packages;
	}

	/**
	 * Check if optional NPM packages are installed.
	 *
	 * @return bool
	 */
	protected function check_optional_npm_packages_installed() {
		$node_modules = WP_MCP_AI_PRO_PATH . 'node_modules';
		return file_exists( $node_modules . '/puppeteer-core/package.json' );
	}

	/**
	 * Render enable research field.
	 */
	public function render_enable_research_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_research'] ) ? (bool) $options['enable_research'] : false;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_research]"
				id="enable_research"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable the Research & Add page for document template research', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create document templates using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant and default settings for Document Generation.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Document Generation Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<p><?php esc_html_e( 'Professional document generation toolkit powered by modern NPM packages. Generate PDF documents, Word documents, and Excel spreadsheets with custom styling and branding.', 'mcp-ai-wpoos-pro' ); ?></p>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'PDF Generation: Create PDF documents with PDFKit - custom fonts, images, tables, and styling', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Word Documents: Generate .docx files with docx package - headers, footers, tables, images', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Excel Spreadsheets: Create .xlsx files with ExcelJS - formulas, charts, styling, data validation', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'HTML to PDF: Convert HTML content to PDF with custom styling', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Template System: Reusable document templates with variable substitution', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Custom Branding: Add logos, watermarks, headers, and footers', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Research & Add: AI-assisted document template creation and management', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'NPM Packages Integrated', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong>pdfkit</strong> (500K/week): Advanced PDF generation with vector graphics</li>
				<li><strong>pdf-lib</strong> (700K/week): PDF manipulation - merge, watermark, modify existing PDFs</li>
				<li><strong>docx</strong> (2M/week): Microsoft Word document generation</li>
				<li><strong>exceljs</strong> (2M/week): Excel spreadsheet creation and manipulation</li>
				<li><strong>puppeteer-core</strong> (2M/week, optional): Advanced HTML to PDF rendering with full browser support</li>
			</ul>

			<h3><?php esc_html_e( 'Alternative Technologies', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Command-line tools: pdftk (PDF manipulation), pdftotext (text extraction), wkhtmltopdf (HTML to PDF)', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'PHP fallbacks: DomPDF, mPDF, TCPDF for PDF generation when Node.js unavailable', 'mcp-ai-wpoos-pro' ); ?></li>
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
		</div>
		<?php
	}

	/**
	 * Get tools list.
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			// Core Document Generation Tools.
			'pro_pdf_document'     => __( 'Pro PDF Document', 'mcp-ai-wpoos-pro' ),
			'pro_word_document'    => __( 'Pro Word Document', 'mcp-ai-wpoos-pro' ),
			'pro_excel_document'   => __( 'Pro Excel Document', 'mcp-ai-wpoos-pro' ),

			// Simplified Generation Tools.
			'generate_pdf'         => __( 'Generate PDF', 'mcp-ai-wpoos-pro' ),
			'generate_word'        => __( 'Generate Word', 'mcp-ai-wpoos-pro' ),
			'generate_excel'       => __( 'Generate Excel', 'mcp-ai-wpoos-pro' ),

			// Utility Tools.
			'html_to_pdf'          => __( 'HTML to PDF', 'mcp-ai-wpoos-pro' ),
			'merge_pdfs'           => __( 'Merge PDFs', 'mcp-ai-wpoos-pro' ),
			'add_watermark_to_pdf' => __( 'Add Watermark to PDF', 'mcp-ai-wpoos-pro' ),
			'extract_pdf_text'     => __( 'Extract PDF Text', 'mcp-ai-wpoos-pro' ),

			// Data Import/Export Tools.
			'excel_data_import'    => __( 'Excel Data Import', 'mcp-ai-wpoos-pro' ),
			'excel_data_export'    => __( 'Excel Data Export', 'mcp-ai-wpoos-pro' ),

			// Template-Based Tools.
			'generate_invoice_pdf' => __( 'Generate Invoice PDF', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Call parent sanitization for base fields.
		$sanitized = parent::sanitize_settings( $input );

		// Add document generation-specific sanitization.
		if ( isset( $input['default_page_size'] ) ) {
			$sanitized['default_page_size'] = sanitize_text_field( $input['default_page_size'] );
		}

		if ( isset( $input['default_orientation'] ) ) {
			$sanitized['default_orientation'] = sanitize_text_field( $input['default_orientation'] );
		}

		if ( isset( $input['enable_branding'] ) ) {
			$sanitized['enable_branding'] = (bool) $input['enable_branding'];
		} else {
			$sanitized['enable_branding'] = false;
		}

		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			// Checkbox not checked.
			$sanitized['enable_research'] = false;
		}

		return $sanitized;
	}
}

// Initialize - instantiated in document-generation-toolkit-init.php when toolkit is enabled.
