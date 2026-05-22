<?php
/**
 * Product Research Page for Regulatory Registration Toolkit.
 *
 * Provides AI-assisted product research and creation interface.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Product Research Page class.
 */
class WP_MCP_AI_Reg_Product_Research_Page {
	use WP_MCP_AI_Research_Page_Featured_Image;
	use WP_MCP_AI_Research_Page_Import_Handler;
	use WP_MCP_AI_Research_Page_Consolidation;
	use WP_MCP_AI_Research_Page_Data_Validation;
	use WP_MCP_AI_Research_Page_Mode_Tabs;

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-reg-product-research';

	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 21 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_reg_product_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_reg_product', array( __CLASS__, 'ajax_handle_import' ) );
		add_action( 'wp_ajax_wp_mcp_ai_preview_excel', array( __CLASS__, 'ajax_preview_excel' ) );
		add_action( 'wp_ajax_wp_mcp_ai_bulk_import_reg_products', array( __CLASS__, 'handle_bulk_import' ) );
		add_action( 'wp_ajax_wp_mcp_ai_upload_reg_document', array( __CLASS__, 'handle_document_upload' ) );
		add_action( 'wp_ajax_wp_mcp_ai_get_product_records_preview', array( __CLASS__, 'handle_get_product_preview' ) );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_reg_product',
			__( 'Research & Add Products', 'mcp-ai-wpoos-pro' ),
			__( 'Research & Add', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the research page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our research page.
		if ( 'mcp_ai_reg_product_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		// Enqueue enhanced research page styles.
		wp_enqueue_style(
			'wp-mcp-ai-enhanced-research-page',
			WP_MCP_AI_URL . 'assets/css/enhanced-research-page.css',
			array(),
			WP_MCP_AI_VERSION
		);

		// Enqueue enhanced research page script.
		wp_enqueue_script(
			'wp-mcp-ai-enhanced-research-page',
			WP_MCP_AI_URL . 'assets/js/enhanced-research-page.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-enhanced-research-page',
			'wpMcpAiResearchPage',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_reg_product' ),
				'entityType' => 'reg_product',
			)
		);

		// Enqueue registration consolidation styles.
		wp_enqueue_style(
			'wp-mcp-ai-reg-product-consolidate',
			WP_MCP_AI_PRO_URL . 'assets/css/reg-product-consolidate.css',
			array( 'wp-mcp-ai-enhanced-research-page' ),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue registration consolidation script.
		wp_enqueue_script(
			'wp-mcp-ai-reg-product-consolidate',
			WP_MCP_AI_PRO_URL . 'assets/js/reg-product-consolidate.js',
			array( 'jquery', 'wp-mcp-ai-enhanced-research-page' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize registration consolidation script.
		wp_localize_script(
			'wp-mcp-ai-reg-product-consolidate',
			'wpMcpAiRegConsolidate',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'wp_mcp_ai_reg_consolidate' ),
				'productsUrl'   => admin_url( 'edit.php?post_type=mcp_ai_reg_product' ),
				'addProductUrl' => admin_url( 'post-new.php?post_type=mcp_ai_reg_product' ),
				'addRegUrl'     => admin_url( 'post-new.php?post_type=mcp_ai_registration' ),
				'addDocUrl'     => admin_url( 'post-new.php?post_type=mcp_ai_reg_document' ),
				'strings'       => array(
					'loading'          => __( 'Loading product data...', 'mcp-ai-wpoos-pro' ),
					'error'            => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'selectProduct'    => __( 'Select a product to view its registration records.', 'mcp-ai-wpoos-pro' ),
					'analyzing'        => __( 'Analyzing and importing data...', 'mcp-ai-wpoos-pro' ),
					'enterProductInfo' => __( 'Please enter product information to import.', 'mcp-ai-wpoos-pro' ),
					'importComplete'   => __( 'Import complete!', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_reg_product_settings', array() );
		$assistant_id = isset( $settings['assistant_id'] ) ? absint( $settings['assistant_id'] ) : 0;

		// If no assistant configured or invalid, get the first available assistant.
		if ( ! $assistant_id || 'publish' !== get_post_status( $assistant_id ) ) {
			$assistants = get_posts(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			$assistant_id = ! empty( $assistants ) ? $assistants[0]->ID : 0;
		}

		// Get all products for the dropdown.
		$products = get_posts(
			array(
				'post_type'      => 'mcp_ai_reg_product',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		?>
		<div class="wrap wp-mcp-ai-research-page">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Research & Add Product', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<?php self::render_chat_interface( $assistant_id, $products ); ?>
		</div>
		<?php
	}

	/**
	 * Render the chat interface.
	 *
	 * @param int   $assistant_id Assistant ID.
	 * @param array $products     Array of product post objects.
	 */
	protected static function render_chat_interface( $assistant_id, $products = array() ) {
		?>
			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-product-selector">
						<h3><?php esc_html_e( 'Select Product', 'mcp-ai-wpoos-pro' ); ?></h3>
						<?php if ( ! empty( $products ) ) : ?>
							<select id="wp-mcp-ai-product-select" class="widefat">
								<option value=""><?php esc_html_e( '-- Select a Product --', 'mcp-ai-wpoos-pro' ); ?></option>
								<?php foreach ( $products as $product ) : ?>
									<?php
									$brands = wp_get_object_terms( $product->ID, 'mcp_ai_reg_brand', array( 'fields' => 'names' ) );
									$brand  = ! empty( $brands ) && ! is_wp_error( $brands ) ? $brands[0] : '';
									?>
									<option value="<?php echo esc_attr( $product->ID ); ?>">
										<?php
										echo esc_html( $product->post_title );
										if ( $brand ) {
											echo ' (' . esc_html( $brand ) . ')';
										}
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p>
								<button type="button" id="wp-mcp-ai-load-product-btn" class="button button-primary">
									<?php esc_html_e( 'Load Product Records', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</p>
						<?php else : ?>
							<p class="description">
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: URL to create a product */
										__( 'No products found. <a href="%s">Create a product</a> first.', 'mcp-ai-wpoos-pro' ),
										admin_url( 'post-new.php?post_type=mcp_ai_reg_product' )
									)
								);
								?>
							</p>
						<?php endif; ?>
					</div>
					<div class="wp-mcp-ai-research-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Search existing products or research regulatory requirements', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Verify ingredient compliance and INCI nomenclature', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create products with complete regulatory data', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Link products to brands and categories', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Check if product already exists', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'INCI compliance:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Validate ingredient nomenclature', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Regulatory research:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Find country-specific requirements', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Complete data:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Include all manufacturer and origin details', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research creating a new skincare moisturizer product with INCI ingredients, manufacturer details, and HS code">
								<?php esc_html_e( '"Research creating a new skincare moisturizer..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Find information about registering a perfume in Sri Lanka NMRA including required documents and timeline">
								<?php esc_html_e( '"Find information about registering a perfume..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research compliance requirements for a haircare product with allergen information">
								<?php esc_html_e( '"Research compliance requirements for haircare..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-document-tools-info">
						<h3><?php esc_html_e( '📄 Document Processing Tools', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p><strong><?php esc_html_e( 'The AI assistant has access to document tools:', 'mcp-ai-wpoos-pro' ); ?></strong></p>
						<ul>
							<li><?php esc_html_e( 'Extract text from PDFs (certificates, dossiers)', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Generate regulatory reports (PDF, Word, Excel)', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Import/export product data from spreadsheets', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Merge multiple regulatory documents', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Add watermarks to compliance certificates', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Generate submission packages', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
						<p>
							<em><?php esc_html_e( 'Try: "Extract text from this certificate PDF" or "Generate a compliance report for this product"', 'mcp-ai-wpoos-pro' ); ?></em>
						</p>
					</div>

					<div class="wp-mcp-ai-research-preview" id="wp-mcp-ai-product-preview" style="display: none;">
						<h3><?php esc_html_e( 'Excel Data Preview', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="wp-mcp-ai-preview-content">
							<div class="wp-mcp-ai-preview-loading">
								<span class="spinner is-active"></span>
								<p><?php esc_html_e( 'Loading Excel data...', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div class="wp-mcp-ai-preview-data" style="display: none;">
								<div class="wp-mcp-ai-preview-header">
									<h4 class="wp-mcp-ai-preview-title"></h4>
									<p class="wp-mcp-ai-preview-meta"></p>
								</div>
								<div class="wp-mcp-ai-preview-table-wrapper">
									<table class="wp-mcp-ai-preview-table widefat striped">
										<thead></thead>
										<tbody></tbody>
									</table>
								</div>
								<div class="wp-mcp-ai-preview-pagination" style="display: none;">
									<button type="button" class="button wp-mcp-ai-preview-prev" disabled>
										<span class="dashicons dashicons-arrow-left-alt2"></span>
										<?php esc_html_e( 'Previous', 'mcp-ai-wpoos-pro' ); ?>
									</button>
									<span class="wp-mcp-ai-preview-page-info">
										<span class="wp-mcp-ai-preview-current-page">1</span>
										<?php esc_html_e( 'of', 'mcp-ai-wpoos-pro' ); ?>
										<span class="wp-mcp-ai-preview-total-pages">1</span>
									</span>
									<button type="button" class="button wp-mcp-ai-preview-next">
										<?php esc_html_e( 'Next', 'mcp-ai-wpoos-pro' ); ?>
										<span class="dashicons dashicons-arrow-right-alt2"></span>
									</button>
								</div>
								<div class="wp-mcp-ai-preview-actions">
									<button type="button" class="button button-primary wp-mcp-ai-import-excel-data">
										<?php esc_html_e( 'Import All Products', 'mcp-ai-wpoos-pro' ); ?>
									</button>
									<button type="button" class="button wp-mcp-ai-close-preview">
										<?php esc_html_e( 'Close Preview', 'mcp-ai-wpoos-pro' ); ?>
									</button>
								</div>
							</div>
						</div>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_reg_product' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Products', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_reg_product' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Product Manually', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<button type="button" class="button wp-mcp-ai-select-excel-file">
								<span class="dashicons dashicons-media-spreadsheet"></span>
								<?php esc_html_e( 'Preview Excel File', 'mcp-ai-wpoos-pro' ); ?>
							</button>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-research-main">
					<!-- Workflow Mode Selector -->
					<div class="wp-mcp-ai-workflow-selector">
						<h2><?php esc_html_e( 'Choose Your Workflow', 'mcp-ai-wpoos-pro' ); ?></h2>
						<div class="workflow-options">
							<button type="button" class="workflow-option active" data-workflow="research">
								<span class="dashicons dashicons-format-chat"></span>
								<strong><?php esc_html_e( 'AI Research', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Research and create products with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="quick-import">
								<span class="dashicons dashicons-database-import"></span>
								<strong><?php esc_html_e( 'Quick Import', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Paste or upload product data for AI parsing', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import product data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="guided">
								<span class="dashicons dashicons-welcome-learn-more"></span>
								<strong><?php esc_html_e( 'Guided Entry', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Step-by-step record creation', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View product quality and completeness', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive regulatory product tools.
							// Include all regulatory registration toolkit tools to enable full management capabilities.
							$reg_tools = array(
								// Core product management.
								'create_reg_product',
								'update_reg_product',
								'delete_reg_product',
								'duplicate_reg_product',
								'get_reg_product',
								'list_reg_products',
								'search_reg_products',
								'validate_reg_product',
								// Registration management.
								'create_registration',
								'get_registration',
								'list_registrations',
								'list_registrations_by_country',
								'list_expiring_registrations',
								'update_registration_status',
								'approve_registration',
								'submit_registration',
								'renew_registration',
								'get_registration_timeline',
								'submit_to_authority',
								// Document management.
								'upload_reg_document',
								'get_reg_document',
								'update_reg_document',
								'list_reg_documents',
								'validate_document_checklist',
								'track_document_version',
								'check_document_expiry',
								// Excel import/export.
								'import_products_from_excel',
								'export_products_to_excel',
								'validate_excel_import',
								'import_registrations_from_excel',
								'export_registrations_to_excel',
								// Compliance & validation.
								'check_product_compliance',
								'validate_inci_ingredients',
								'check_hs_code',
								'get_regulatory_requirements',
								'get_regulatory_updates',
								'add_regulatory_requirement',
								'check_authority_status',
								// Reports & analytics.
								'generate_compliance_report',
								'generate_compliance_certificate',
								'generate_cost_analysis',
								'generate_country_performance',
								'generate_expiry_forecast',
								'generate_pipeline_report',
								'generate_pdf_dossier',
								'generate_submission_pack',
								'generate_cover_letter',
								// Notifications.
								'configure_email_notifications',
								'send_expiry_alerts',
								'send_status_change_notification',
								'get_notification_history',
								// Workflow automation.
								'create_workflow_rule',
								'update_workflow_rule',
								'delete_workflow_rule',
								'list_workflow_rules',
								'test_workflow_rule',
								'get_workflow_execution_log',
								// Authority integrations.
								'sync_with_mohap',
								'sync_with_nmra',
								// General research tools.
								'web_search',
								'search_content',
								'semantic_content_search',
								// Document processing tools.
								'extract_pdf_text',
								'pro_pdf_document',
								'pro_word_document',
								'pro_excel_document',
								'generate_pdf',
								'generate_word',
								'generate_excel',
								'html_to_pdf',
								'merge_pdfs',
								'add_watermark_to_pdf',
								'excel_data_import',
								'excel_data_export',
								'generate_invoice_pdf',
							);
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="' . esc_attr( implode( ',', $reg_tools ) ) . '"]'
							);
							?>
						</div>

					<?php else : ?>
						<div class="notice notice-error">
							<p>
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: Link to create assistant */
										__( 'No AI assistant found. Please <a href="%s">create an assistant</a> first.', 'mcp-ai-wpoos-pro' ),
										admin_url( 'post-new.php?post_type=mcp_ai_assistant' )
									)
								);
								?>
							</p>
						</div>
					<?php endif; ?>
					</div>

					<!-- Quick Import Workflow -->
					<div id="workflow-quick-import" class="workflow-content">
						<div class="wp-mcp-ai-bulk-import-section">
							<h2><?php esc_html_e( 'Quick Import - Dump Everything Here', 'mcp-ai-wpoos-pro' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Paste or type all your product registration information below, or upload documents (PDFs, certificates, spreadsheets). The AI will automatically parse, categorize, and organize it into structured products and registrations.', 'mcp-ai-wpoos-pro' ); ?>
							</p>

							<div class="bulk-import-tips">
								<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
								<ul>
									<li><?php esc_html_e( '✓ Include product names and brands', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( '✓ Add INCI ingredient lists where available', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( '✓ Include registration numbers and countries', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( '✓ Mention expiry dates and status', 'mcp-ai-wpoos-pro' ); ?></li>
									<li><?php esc_html_e( '✓ Upload original certificates - they will be kept as attachments', 'mcp-ai-wpoos-pro' ); ?></li>
								</ul>
							</div>

							<div class="bulk-import-form">
								<!-- File Upload Section -->
								<div class="bulk-import-file-section">
									<h3><?php esc_html_e( 'Upload Documents (Optional)', 'mcp-ai-wpoos-pro' ); ?></h3>
									<p class="description">
										<?php esc_html_e( 'Upload registration certificates, compliance documents, product specifications, or spreadsheets. Original files are preserved in your media library.', 'mcp-ai-wpoos-pro' ); ?>
									</p>
									<div class="file-upload-area">
										<input type="file" id="wp-mcp-ai-reg-file-upload" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.txt,.csv,.xlsx,.xls" style="display: none;">
										<button type="button" id="wp-mcp-ai-reg-file-upload-btn" class="button">
											<span class="dashicons dashicons-upload"></span>
											<?php esc_html_e( 'Choose Files to Upload', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<span class="file-upload-note"><?php esc_html_e( 'Accepted: PDF, JPG, PNG, DOC, DOCX, TXT, CSV, XLSX', 'mcp-ai-wpoos-pro' ); ?></span>
									</div>
									<div id="wp-mcp-ai-reg-file-list" class="file-upload-list" style="display: none;">
										<h4><?php esc_html_e( 'Files to Upload:', 'mcp-ai-wpoos-pro' ); ?></h4>
										<ul id="wp-mcp-ai-reg-file-items"></ul>
									</div>
								</div>

								<hr class="form-section-divider">

								<!-- Text Import Section -->
								<h3><?php esc_html_e( 'Or Paste/Type Product Information', 'mcp-ai-wpoos-pro' ); ?></h3>
								<textarea
									id="wp-mcp-ai-reg-bulk-import-text"
									class="widefat"
									rows="12"
									placeholder="<?php esc_attr_e( "Example:\nProduct: Hydrating Face Cream\nBrand: BeautySkin\nINCI: Aqua, Glycerin, Cetyl Alcohol, Stearyl Alcohol\nManufacturer: ABC Cosmetics Ltd\nOrigin: France\n\nRegistration: UAE MOHAP\nReg Number: MOHAP-2024-12345\nStatus: Approved\nExpiry: 2026-12-31\n\nProduct: Anti-Aging Serum\nBrand: DermaPro\nCategory: Skincare\nHS Code: 3304.99", 'mcp-ai-wpoos-pro' ); ?>"
								></textarea>

								<div class="bulk-import-options">
									<label>
										<input type="checkbox" id="wp-mcp-ai-reg-bulk-auto-create" checked>
										<?php esc_html_e( 'Automatically create products and registrations (recommended)', 'mcp-ai-wpoos-pro' ); ?>
									</label>
									<label>
										<input type="checkbox" id="wp-mcp-ai-reg-bulk-require-confirmation">
										<?php esc_html_e( 'Review before creating (for meticulous users)', 'mcp-ai-wpoos-pro' ); ?>
									</label>
								</div>

								<p>
									<button type="button" id="wp-mcp-ai-reg-bulk-import-btn" class="button button-primary button-large">
										<span class="dashicons dashicons-update"></span>
										<?php esc_html_e( 'Import & Organize with AI', 'mcp-ai-wpoos-pro' ); ?>
									</button>
									<button type="button" id="wp-mcp-ai-reg-bulk-clear-btn" class="button button-secondary">
										<?php esc_html_e( 'Clear', 'mcp-ai-wpoos-pro' ); ?>
									</button>
								</p>
								<div id="wp-mcp-ai-reg-bulk-import-result" class="bulk-import-result" style="display: none;"></div>
							</div>
						</div>
					</div>

					<!-- Import Data Workflow -->
					<div id="workflow-import" class="workflow-content">
						<?php self::render_import_workflow(); ?>
					</div>

					<!-- Guided Entry Workflow -->
					<div id="workflow-guided" class="workflow-content">
						<div class="wp-mcp-ai-guided-section">
							<h2><?php esc_html_e( 'Guided Record Entry', 'mcp-ai-wpoos-pro' ); ?></h2>
							<p class="description">
								<?php esc_html_e( 'Follow the step-by-step process to add regulatory records. The AI will guide you through each field and ensure all necessary information is captured.', 'mcp-ai-wpoos-pro' ); ?>
							</p>

							<div class="guided-steps">
								<div class="step-selector">
									<h3><?php esc_html_e( 'What would you like to add?', 'mcp-ai-wpoos-pro' ); ?></h3>
									<div class="record-type-buttons">
										<button type="button" class="record-type-btn" data-type="reg_product">
											<span class="dashicons dashicons-archive"></span>
											<?php esc_html_e( 'Product', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<button type="button" class="record-type-btn" data-type="registration">
											<span class="dashicons dashicons-clipboard"></span>
											<?php esc_html_e( 'Registration', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<button type="button" class="record-type-btn" data-type="reg_document">
											<span class="dashicons dashicons-media-document"></span>
											<?php esc_html_e( 'Document', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<button type="button" class="record-type-btn" data-type="country">
											<span class="dashicons dashicons-admin-site-alt3"></span>
											<?php esc_html_e( 'Country/Authority', 'mcp-ai-wpoos-pro' ); ?>
										</button>
										<button type="button" class="record-type-btn" data-type="requirement">
											<span class="dashicons dashicons-shield"></span>
											<?php esc_html_e( 'Requirement', 'mcp-ai-wpoos-pro' ); ?>
										</button>
									</div>
								</div>

								<div id="reg-guided-form-container" class="guided-form-container" style="display: none;">
									<!-- Dynamic form will be loaded here -->
								</div>
							</div>
						</div>
					</div>

					<!-- Review & Quality Workflow -->
					<div id="workflow-review" class="workflow-content">
						<div id="wp-mcp-ai-product-records-preview" class="wp-mcp-ai-records-preview" style="display: none;">
							<!-- Product records preview will be loaded here via AJAX -->
						</div>

						<div id="wp-mcp-ai-no-product-selection" class="notice notice-info inline">
							<p><?php esc_html_e( 'Select a product from the sidebar to view its registration records, or view the overall quality dashboard below.', 'mcp-ai-wpoos-pro' ); ?></p>
						</div>

						<?php self::render_consolidation_dashboard(); ?>
					</div>
				</div>
			</div>
		<?php
	}

	/**
	 * Render import workflow section.
	 */
	protected static function render_import_workflow() {
		self::render_import_section();
	}

	/**
	 * Render review workflow section.
	 */
	protected static function render_review_workflow() {
		self::render_consolidation_dashboard();
	}

	/**
	 * Handle AJAX request to create product from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_reg_product', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create products.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized by tool execute method.
		$research_data_raw = isset( $_POST['research_data'] ) ? wp_unslash( $_POST['research_data'] ) : '';

		if ( empty( $research_data_raw ) ) {
			wp_send_json_error( array( 'message' => __( 'No research data provided.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$research_data = json_decode( $research_data_raw, true );

		// Validate JSON decoding.
		if ( null === $research_data || JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON data format.', 'mcp-ai-wpoos-pro' ) ) );
		}

		if ( empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Product title is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Use the create_reg_product tool to create the product.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Reg_Product' ) ) {
			wp_send_json_error( array( 'message' => __( 'Create Product tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$tool   = new WP_MCP_AI_Tool_Create_Reg_Product();
		$result = $tool->execute(
			$research_data,
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Return success with product ID and edit URL.
		$product_id = isset( $result['product_id'] ) ? $result['product_id'] : 0;
		$edit_url   = $product_id > 0 ? admin_url( 'post.php?post=' . $product_id . '&action=edit' ) : '';

		wp_send_json_success(
			array(
				'message'    => __( 'Product created successfully!', 'mcp-ai-wpoos-pro' ),
				'product_id' => $product_id,
				'edit_url'   => $edit_url,
			)
		);
	}

	/**
	 * Get supported import formats.
	 *
	 * @return array Import formats.
	 */
	protected static function get_import_formats() {
		return array(
			'csv'  => 'CSV',
			'xlsx' => 'Excel',
			'json' => 'JSON',
		);
	}

	/**
	 * Process imported data.
	 *
	 * @param string $data   Import data.
	 * @param string $format Data format.
	 * @return array|WP_Error Result or error.
	 */
	protected static function process_import_data( $data, $format ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by trait interface.
		// This would integrate with the import_products_from_excel tool.
		return new WP_Error( 'not_implemented', __( 'Product import will be handled through Excel import page.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Calculate completeness.
	 *
	 * @return array Completeness data.
	 */
	protected static function calculate_completeness() {
		$products = get_posts(
			array(
				'post_type'      => 'mcp_ai_reg_product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total         = count( $products );
		$complete      = 0;
		$missing_items = array();

		foreach ( $products as $product ) {
			$meta       = get_post_meta( $product->ID );
			$has_brand  = ! empty( $meta['brand'][0] ?? '' );
			$has_inci   = ! empty( $meta['inci_ingredients'][0] ?? '' );
			$has_origin = ! empty( $meta['origin_country'][0] ?? '' );

			if ( $has_brand && $has_inci && $has_origin ) {
				++$complete;
			} else {
				if ( ! $has_brand ) {
					$missing_items[] = sprintf( '%s: Missing brand', $product->post_title );
				}
				if ( ! $has_inci ) {
					$missing_items[] = sprintf( '%s: Missing INCI ingredients', $product->post_title );
				}
				if ( ! $has_origin ) {
					$missing_items[] = sprintf( '%s: Missing origin country', $product->post_title );
				}
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array_slice( $missing_items, 0, 10 ),
			'suggestions' => array(
				__( 'Complete missing brand information', 'mcp-ai-wpoos-pro' ),
				__( 'Add INCI ingredient lists for compliance', 'mcp-ai-wpoos-pro' ),
				__( 'Verify origin country information', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Items.
	 */
	protected static function get_items_for_review() {
		$products = get_posts(
			array(
				'post_type'      => 'mcp_ai_reg_product',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $products as $product ) {
			$items[] = array(
				'id'    => $product->ID,
				'title' => $product->post_title,
				'meta'  => get_post_meta( $product->ID ),
			);
		}

		return $items;
	}

	/**
	 * Calculate quality score for item.
	 *
	 * @param array $item Item data.
	 * @return array Quality data.
	 */
	protected static function calculate_quality_score( $item ) {
		$score  = 0;
		$issues = array();
		$meta   = $item['meta'] ?? array();

		// Check required fields (10 points each).
		$required_fields = array(
			'brand'            => __( 'Brand', 'mcp-ai-wpoos-pro' ),
			'manufacturer'     => __( 'Manufacturer', 'mcp-ai-wpoos-pro' ),
			'origin_country'   => __( 'Origin Country', 'mcp-ai-wpoos-pro' ),
			'inci_ingredients' => __( 'INCI Ingredients', 'mcp-ai-wpoos-pro' ),
		);

		foreach ( $required_fields as $field => $label ) {
			if ( ! empty( $meta[ $field ][0] ?? '' ) ) {
				$score += 25;
			} else {
				$issues[] = sprintf(
					/* translators: %s: Field label */
					__( 'Missing %s', 'mcp-ai-wpoos-pro' ),
					$label
				);
			}
		}

		// Determine quality level.
		if ( $score >= 90 ) {
			$level = 'high';
		} elseif ( $score >= 60 ) {
			$level = 'medium';
		} else {
			$level = 'low';
		}

		return array(
			'score'  => $score,
			'level'  => $level,
			'status' => $score >= 90 ? __( 'Complete', 'mcp-ai-wpoos-pro' ) : __( 'Incomplete', 'mcp-ai-wpoos-pro' ),
			'issues' => $issues,
		);
	}

	/**
	 * Handle AJAX request to preview Excel file.
	 */
	public static function ajax_preview_excel() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_reg_product', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to preview files.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get file information.
		$file_id  = isset( $_POST['file_id'] ) ? absint( $_POST['file_id'] ) : 0;
		$file_url = isset( $_POST['file_url'] ) ? esc_url_raw( wp_unslash( $_POST['file_url'] ) ) : '';

		if ( ! $file_id && ! $file_url ) {
			wp_send_json_error( array( 'message' => __( 'No file specified.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get file path.
		$file_path = '';
		if ( $file_id > 0 ) {
			$file_path = get_attached_file( $file_id );
		} elseif ( $file_url ) {
			// Convert URL to path if it's a local file.
			$upload_dir = wp_upload_dir();
			if ( strpos( $file_url, $upload_dir['baseurl'] ) === 0 ) {
				$file_path = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $file_url );
			}
		}

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			wp_send_json_error( array( 'message' => __( 'File not found.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Parse the file based on extension.
		$file_extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		$preview_data = array(
			'filename' => basename( $file_path ),
			'columns'  => array(),
			'rows'     => array(),
		);

		try {
			if ( 'csv' === $file_extension ) {
				$preview_data = self::parse_csv_file( $file_path );
			} elseif ( in_array( $file_extension, array( 'xlsx', 'xls' ), true ) ) {
				// For Excel files, we'll provide a simplified preview.
				// In production, you'd use PhpSpreadsheet library.
				$preview_data = self::parse_excel_file( $file_path );
			} else {
				wp_send_json_error( array( 'message' => __( 'Unsupported file format. Please use CSV or Excel files.', 'mcp-ai-wpoos-pro' ) ) );
			}

			wp_send_json_success( $preview_data );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Parse CSV file for preview.
	 *
	 * @param string $file_path Path to CSV file.
	 * @return array Preview data with columns and rows.
	 * @throws Exception If the file cannot be opened.
	 */
	protected static function parse_csv_file( $file_path ) {
		$data = array(
			'filename' => basename( $file_path ),
			'columns'  => array(),
			'rows'     => array(),
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Required for CSV parsing.
		$handle = fopen( $file_path, 'r' );
		if ( ! $handle ) {
			throw new Exception( esc_html__( 'Unable to open file.', 'mcp-ai-wpoos-pro' ) );
		}

		// Read header row.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv -- Required for CSV parsing.
		$header = fgetcsv( $handle );
		if ( $header ) {
			$data['columns'] = array_map( 'sanitize_text_field', $header );
		}

		// Read data rows (limit to 100 rows for preview).
		$row_count = 0;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv -- Required for CSV parsing.
		while ( ( $row = fgetcsv( $handle ) ) !== false && $row_count < 100 ) {
			$row_data = array();
			foreach ( $data['columns'] as $index => $column ) {
				$row_data[ $column ] = isset( $row[ $index ] ) ? sanitize_text_field( $row[ $index ] ) : '';
			}
			$data['rows'][] = $row_data;
			++$row_count;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for CSV parsing.
		fclose( $handle );

		return $data;
	}

	/**
	 * Parse Excel file for preview.
	 *
	 * Note: This is a placeholder. In production, use PhpSpreadsheet library.
	 *
	 * @param string $file_path Path to Excel file.
	 * @return array Preview data with columns and rows.
	 */
	protected static function parse_excel_file( $file_path ) {
		// For now, return a sample structure with a note.
		// In production, integrate PhpSpreadsheet library for Excel parsing.
		return array(
			'filename' => basename( $file_path ),
			'columns'  => array( 'Product Name', 'Brand', 'Manufacturer', 'Category', 'INCI Ingredients' ),
			'rows'     => array(
				array(
					'Product Name'     => __( 'Excel preview requires PhpSpreadsheet library', 'mcp-ai-wpoos-pro' ),
					'Brand'            => __( 'Please upload as CSV format', 'mcp-ai-wpoos-pro' ),
					'Manufacturer'     => __( 'Or install PhpSpreadsheet', 'mcp-ai-wpoos-pro' ),
					'Category'         => __( 'For full Excel support', 'mcp-ai-wpoos-pro' ),
					'INCI Ingredients' => __( 'File: ' . basename( $file_path ), 'mcp-ai-wpoos-pro' ),
				),
			),
		);
	}

	/**
	 * Handle AJAX bulk import of reg products.
	 */
	public static function handle_bulk_import() {
		check_ajax_referer( 'wp_mcp_ai_reg_consolidate', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import products.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via wp_kses_post to preserve line breaks needed for parsing.
		$raw_text    = isset( $_POST['bulk_text'] ) ? wp_kses_post( wp_unslash( $_POST['bulk_text'] ) ) : '';
		$auto_create = isset( $_POST['auto_create'] ) && 'true' === $_POST['auto_create'];

		if ( empty( $raw_text ) ) {
			wp_send_json_error( array( 'message' => __( 'No product data provided.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$parsed = self::parse_bulk_product_data( $raw_text );

		if ( empty( $parsed ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not parse any product data from the input.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$created = array();
		$errors  = array();

		if ( $auto_create ) {
			foreach ( $parsed as $product_data ) {
				$post_data = array(
					'post_title'  => sanitize_text_field( $product_data['name'] ),
					'post_type'   => 'mcp_ai_reg_product',
					'post_status' => 'draft',
				);

				$post_id = wp_insert_post( $post_data, true );

				if ( is_wp_error( $post_id ) ) {
					$errors[] = $product_data['name'] . ': ' . $post_id->get_error_message();
					continue;
				}

				// Set brand taxonomy if available.
				if ( ! empty( $product_data['brand'] ) ) {
					wp_set_object_terms( $post_id, sanitize_text_field( $product_data['brand'] ), 'mcp_ai_reg_brand' );
				}

				// Set category taxonomy if available.
				if ( ! empty( $product_data['category'] ) ) {
					wp_set_object_terms( $post_id, sanitize_text_field( $product_data['category'] ), 'mcp_ai_reg_category' );
				}

				// Save meta fields.
				$meta_fields = array(
					'manufacturer'     => '_mcp_ai_manufacturer',
					'inci_ingredients' => '_mcp_ai_inci_ingredients',
					'origin_country'   => '_mcp_ai_origin_country',
					'hs_code'          => '_mcp_ai_hs_code',
				);

				foreach ( $meta_fields as $key => $meta_key ) {
					if ( ! empty( $product_data[ $key ] ) ) {
						if ( 'inci_ingredients' === $key ) {
							update_post_meta( $post_id, $meta_key, sanitize_textarea_field( $product_data[ $key ] ) );
						} else {
							update_post_meta( $post_id, $meta_key, sanitize_text_field( $product_data[ $key ] ) );
						}
					}
				}

				$created[] = array(
					'id'    => $post_id,
					'title' => $product_data['name'],
					'url'   => get_edit_post_link( $post_id, 'raw' ),
				);
			}
		}

		$summary = self::render_bulk_import_summary( $parsed, $created, $errors, $auto_create );

		wp_send_json_success(
			array(
				'parsed'  => $parsed,
				'created' => $created,
				'errors'  => $errors,
				'summary' => $summary,
			)
		);
	}

	/**
	 * Parse bulk product data from raw text input.
	 *
	 * @param string $text Raw text input.
	 * @return array Parsed product data.
	 */
	private static function parse_bulk_product_data( $text ) {
		$products = array();
		// Normalise line endings first so the double-newline split below works across OS formats.
		$text    = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$blocks  = preg_split( '/\n{2,}/', trim( $text ) );
		$current = array();

		foreach ( $blocks as $block ) {
			$lines = explode( "\n", trim( $block ) );

			foreach ( $lines as $line ) {
				$line = trim( $line );

				if ( empty( $line ) ) {
					continue;
				}

				// Match key: value pattern.
				if ( preg_match( '/^(product|brand|inci|manufacturer|origin|category|hs\s*code|reg\s*number|status|expiry|registration|country)\s*:\s*(.+)$/i', $line, $matches ) ) {
					// Normalise the matched key by collapsing whitespace to underscore.
					$key   = str_replace( ' ', '_', strtolower( trim( preg_replace( '/\s+/', ' ', $matches[1] ) ) ) );
					$value = trim( $matches[2] );

					switch ( $key ) {
						case 'product':
							if ( ! empty( $current['name'] ) ) {
								$products[] = $current;
							}
							$current         = array();
							$current['name'] = $value;
							break;
						case 'brand':
							$current['brand'] = $value;
							break;
						case 'inci':
							$current['inci_ingredients'] = $value;
							break;
						case 'manufacturer':
							$current['manufacturer'] = $value;
							break;
						case 'origin':
							$current['origin_country'] = $value;
							break;
						case 'category':
							$current['category'] = $value;
							break;
						case 'hs_code':
							$current['hs_code'] = $value;
							break;
						case 'reg_number':
							$current['reg_number'] = $value;
							break;
						case 'status':
							$current['status'] = $value;
							break;
						case 'expiry':
							$current['expiry'] = $value;
							break;
						case 'registration':
						case 'country':
							$current['reg_country'] = $value;
							break;
					}
				}
			}
		}

		// Add last product.
		if ( ! empty( $current['name'] ) ) {
			$products[] = $current;
		}

		return $products;
	}

	/**
	 * Render summary HTML for bulk import results.
	 *
	 * @param array $parsed      Parsed products.
	 * @param array $created     Created products.
	 * @param array $errors      Errors encountered.
	 * @param bool  $auto_create Whether auto-create was used.
	 * @return string HTML summary.
	 */
	private static function render_bulk_import_summary( $parsed, $created, $errors, $auto_create ) {
		ob_start();
		?>
		<div class="bulk-import-summary">
			<h3><?php esc_html_e( 'Import Results', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p>
				<?php
				printf(
					/* translators: %d: number of products parsed */
					esc_html__( 'Parsed %d product(s) from input.', 'mcp-ai-wpoos-pro' ),
					count( $parsed )
				);
				?>
			</p>

			<?php if ( $auto_create && ! empty( $created ) ) : ?>
				<h4><?php esc_html_e( 'Created Products:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<?php foreach ( $created as $item ) : ?>
						<li>
							<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank">
								<?php echo esc_html( $item['title'] ); ?>
							</a>
							<span class="status-badge draft"><?php esc_html_e( 'Draft', 'mcp-ai-wpoos-pro' ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-warning inline">
					<h4><?php esc_html_e( 'Errors:', 'mcp-ai-wpoos-pro' ); ?></h4>
					<ul>
						<?php foreach ( $errors as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( ! $auto_create ) : ?>
				<h4><?php esc_html_e( 'Parsed Data (Review Mode):', 'mcp-ai-wpoos-pro' ); ?></h4>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product Name', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Brand', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Category', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Fields Found', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $parsed as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['name'] ?? '—' ); ?></td>
								<td><?php echo esc_html( $item['brand'] ?? '—' ); ?></td>
								<td><?php echo esc_html( $item['category'] ?? '—' ); ?></td>
								<td><?php echo esc_html( count( $item ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Handle AJAX document upload for registration products.
	 */
	public static function handle_document_upload() {
		check_ajax_referer( 'wp_mcp_ai_reg_consolidate', 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to upload files.', 'mcp-ai-wpoos-pro' ) ) );
		}

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file provided.', 'mcp-ai-wpoos-pro' ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'file', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
		}

		$file_url  = wp_get_attachment_url( $attachment_id );
		$file_type = get_post_mime_type( $attachment_id );
		$file_name = get_the_title( $attachment_id );

		wp_send_json_success(
			array(
				'attachment_id' => $attachment_id,
				'url'           => $file_url,
				'type'          => $file_type,
				'name'          => $file_name,
				'message'       => sprintf(
					/* translators: %s: file name */
					__( 'File "%s" uploaded successfully.', 'mcp-ai-wpoos-pro' ),
					$file_name
				),
			)
		);
	}

	/**
	 * Handle AJAX request to get product records preview.
	 */
	public static function handle_get_product_preview() {
		check_ajax_referer( 'wp_mcp_ai_reg_consolidate', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to view product records.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => __( 'No product ID provided.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$product = get_post( $product_id );

		if ( ! $product || 'mcp_ai_reg_product' !== $product->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$html = self::render_product_records_preview( $product );

		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Render product records preview HTML.
	 *
	 * @param WP_Post $product Product post object.
	 * @return string HTML content.
	 */
	private static function render_product_records_preview( $product ) {
		// Get registrations for this product.
		$registrations = get_posts(
			array(
				'post_type'      => 'mcp_ai_registration',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_mcp_ai_product_id',
						'value' => $product->ID,
					),
				),
			)
		);

		// Get documents for this product.
		$documents = get_posts(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_mcp_ai_product_id',
						'value' => $product->ID,
					),
				),
			)
		);

		$brands = wp_get_object_terms( $product->ID, 'mcp_ai_reg_brand', array( 'fields' => 'names' ) );
		$brand  = ! empty( $brands ) && ! is_wp_error( $brands ) ? $brands[0] : '—';

		ob_start();
		?>
		<div class="product-records-header">
			<h2><?php echo esc_html( $product->post_title ); ?></h2>
			<p class="product-meta">
				<span class="product-brand"><?php echo esc_html( $brand ); ?></span>
				<span class="product-status"><?php echo esc_html( get_post_status_object( $product->post_status )->label ); ?></span>
			</p>
			<p>
				<a href="<?php echo esc_url( get_edit_post_link( $product->ID ) ); ?>" class="button button-small" target="_blank">
					<?php esc_html_e( 'Edit Product', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>

		<div class="product-records-section">
			<h3>
				<?php esc_html_e( 'Registrations', 'mcp-ai-wpoos-pro' ); ?>
				<span class="count">(<?php echo absint( count( $registrations ) ); ?>)</span>
			</h3>
			<?php if ( ! empty( $registrations ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Country/Authority', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Reg Number', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Expiry', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $registrations as $reg ) : ?>
							<tr>
								<td><?php echo esc_html( get_post_meta( $reg->ID, '_mcp_ai_country', true ) ? get_post_meta( $reg->ID, '_mcp_ai_country', true ) : '—' ); ?></td>
								<td><?php echo esc_html( get_post_meta( $reg->ID, '_mcp_ai_reg_number', true ) ? get_post_meta( $reg->ID, '_mcp_ai_reg_number', true ) : '—' ); ?></td>
								<td><?php echo esc_html( get_post_meta( $reg->ID, '_mcp_ai_reg_status', true ) ? get_post_meta( $reg->ID, '_mcp_ai_reg_status', true ) : '—' ); ?></td>
								<td><?php echo esc_html( get_post_meta( $reg->ID, '_mcp_ai_expiry_date', true ) ? get_post_meta( $reg->ID, '_mcp_ai_expiry_date', true ) : '—' ); ?></td>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $reg->ID ) ); ?>" target="_blank">
										<?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No registrations found for this product.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="product-records-section">
			<h3>
				<?php esc_html_e( 'Documents', 'mcp-ai-wpoos-pro' ); ?>
				<span class="count">(<?php echo absint( count( $documents ) ); ?>)</span>
			</h3>
			<?php if ( ! empty( $documents ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Document', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $documents as $doc ) : ?>
							<tr>
								<td><?php echo esc_html( $doc->post_title ); ?></td>
								<td><?php echo esc_html( get_post_meta( $doc->ID, '_mcp_ai_doc_type', true ) ? get_post_meta( $doc->ID, '_mcp_ai_doc_type', true ) : '—' ); ?></td>
								<td><?php echo esc_html( $doc->post_status ); ?></td>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $doc->ID ) ); ?>" target="_blank">
										<?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No documents found for this product.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}

WP_MCP_AI_Reg_Product_Research_Page::init();
