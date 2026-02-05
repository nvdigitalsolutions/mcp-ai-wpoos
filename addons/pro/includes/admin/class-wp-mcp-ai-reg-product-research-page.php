<?php
/**
 * Product Research Page for Regulatory Registration Toolkit.
 *
 * Provides AI-assisted product research and creation interface.
 *
 * @package WP_MCP_AI
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

		?>
		<div class="wrap wp-mcp-ai-research-page">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Research & Add Product', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<?php self::render_chat_interface( $assistant_id ); ?>
		</div>
		<?php
	}

	/**
	 * Render the chat interface.
	 *
	 * @param int $assistant_id Assistant ID.
	 */
	protected static function render_chat_interface( $assistant_id ) {
		?>
			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
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
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import product data', 'mcp-ai-wpoos-pro' ); ?></p>
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

					<!-- Import Data Workflow -->
					<div id="workflow-import" class="workflow-content">
						<?php self::render_import_workflow(); ?>
					</div>

					<!-- Review & Quality Workflow -->
					<div id="workflow-review" class="workflow-content">
						<?php self::render_review_workflow(); ?>
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
			throw new Exception( __( 'Unable to open file.', 'mcp-ai-wpoos-pro' ) );
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
}

WP_MCP_AI_Reg_Product_Research_Page::init();
