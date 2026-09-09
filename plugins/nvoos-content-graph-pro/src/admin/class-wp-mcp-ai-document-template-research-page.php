<?php
/**
 * WP_MCP_AI_Document_Template_Research_Page (ecosystem port - Wave F2, document-generation QMS/admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-document-template-research-page.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is
 * defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the `__DIR__` research-page trait requires resolve from the same `src/admin/` batch.
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

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Document Template Research Admin Page
 *
 * Adds a submenu page under Document Templates menu for AI-powered template research.
 */
class WP_MCP_AI_Document_Template_Research_Page {
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
	const PAGE_SLUG = 'research-document-template';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_document_template_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_document_template', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under Document Templates menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_doc_tpl',
			__( 'Research & Add Template', 'nvoos-content-graph-pro' ),
			__( 'Research & Add', 'nvoos-content-graph-pro' ),
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
		if ( 'mcp_ai_doc_tpl_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_document_template' ),
				'entityType' => 'document_template',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_document_generation_settings', array() );
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
				<?php esc_html_e( 'Research & Add Document Template', 'nvoos-content-graph-pro' ); ?>
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
						<h2><?php esc_html_e( 'How It Works', 'nvoos-content-graph-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Research document template ideas and structures', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Generate template content with AI assistance', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Configure page size, orientation, and branding', 'nvoos-content-graph-pro' ); ?></li>
							<li><?php esc_html_e( 'Create templates directly in your library', 'nvoos-content-graph-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'nvoos-content-graph-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Template types:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'PDF, Word (.docx), or Excel (.xlsx)', 'nvoos-content-graph-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Variables:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Use placeholders for dynamic content', 'nvoos-content-graph-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Branding:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Add logos, headers, and footers', 'nvoos-content-graph-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Page setup:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'Configure size and orientation', 'nvoos-content-graph-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'nvoos-content-graph-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create an invoice template with company logo and payment details">
								<?php esc_html_e( '"Create invoice template..."', 'nvoos-content-graph-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate a professional report template with table of contents">
								<?php esc_html_e( '"Generate report template..."', 'nvoos-content-graph-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Design a certificate template with custom fonts and borders">
								<?php esc_html_e( '"Design certificate..."', 'nvoos-content-graph-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Build an Excel spreadsheet template for financial data">
								<?php esc_html_e( '"Build spreadsheet template..."', 'nvoos-content-graph-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-preview" id="wp-mcp-ai-document-template-preview" style="display: none;">
						<h3><?php esc_html_e( 'Template Preview', 'nvoos-content-graph-pro' ); ?></h3>
						<div class="wp-mcp-ai-preview-content">
							<div class="wp-mcp-ai-preview-loading">
								<span class="spinner is-active"></span>
								<p><?php esc_html_e( 'Building template preview...', 'nvoos-content-graph-pro' ); ?></p>
							</div>
							<div class="wp-mcp-ai-preview-data" style="display: none;">
								<div class="wp-mcp-ai-preview-header">
									<h4 class="wp-mcp-ai-preview-title"></h4>
									<p class="wp-mcp-ai-preview-meta"></p>
								</div>
								<div class="wp-mcp-ai-preview-details"></div>
							</div>
						</div>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'nvoos-content-graph-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_doc_tpl' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Templates', 'nvoos-content-graph-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_doc_tpl' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Template Manually', 'nvoos-content-graph-pro' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-research-main">
					<!-- Workflow Mode Selector -->
					<div class="wp-mcp-ai-workflow-selector">
						<h2><?php esc_html_e( 'Choose Your Workflow', 'nvoos-content-graph-pro' ); ?></h2>
						<div class="workflow-options">
							<button type="button" class="workflow-option active" data-workflow="research">
								<span class="dashicons dashicons-format-chat"></span>
								<strong><?php esc_html_e( 'AI Research', 'nvoos-content-graph-pro' ); ?></strong>
								<p><?php esc_html_e( 'Research and create templates with AI assistance', 'nvoos-content-graph-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'nvoos-content-graph-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import template data', 'nvoos-content-graph-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'nvoos-content-graph-pro' ); ?></strong>
								<p><?php esc_html_e( 'View template quality and completeness', 'nvoos-content-graph-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive document generation tools.
							$doc_tools = array(
								// Advanced document generation (Pro).
								'pro_pdf_document',
								'pro_word_document',
								'pro_excel_document',
								// Basic document generation.
								'generate_pdf',
								'generate_word',
								'generate_excel',
								'html_to_pdf',
								// PDF manipulation and utilities.
								'merge_pdfs',
								'add_watermark_to_pdf',
								'extract_pdf_text',
								'generate_invoice_pdf',
								// Excel data tools.
								'excel_data_import',
								'excel_data_export',
								// Email template generation.
								'generate_email_template',
								// Template management.
								'create_template',
								'list_templates',
								'instantiate_template',
								'seed_template_library',
								// Media templates.
								'create_media_template',
								'list_media_templates',
								'apply_media_template',
								'apply_collection_template',
								// Research → Paper Store pipeline.
								'generate_research_report',
								'create_post_from_research',
								// Research tools.
								'web_search',
								'search_content',
								'semantic_content_search',
							);
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="' . esc_attr( implode( ',', $doc_tools ) ) . '"]'
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
										__( 'No AI assistant found. Please <a href="%s">create an assistant</a> first.', 'nvoos-content-graph-pro' ),
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
	 * Handle AJAX request to create document template from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_document_template', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create document templates.', 'nvoos-content-graph-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data.', 'nvoos-content-graph-pro' ) ) );
		}

		// Create document template post.
		$post_data = array(
			'post_type'    => 'mcp_ai_doc_tpl',
			'post_title'   => isset( $research_data['title'] ) ? sanitize_text_field( $research_data['title'] ) : __( 'New Document Template', 'nvoos-content-graph-pro' ),
			'post_content' => isset( $research_data['content'] ) ? wp_kses_post( $research_data['content'] ) : '',
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Save template metadata.
		if ( isset( $research_data['document_type'] ) ) {
			update_post_meta( $post_id, '_document_type', sanitize_text_field( $research_data['document_type'] ) );
		}
		if ( isset( $research_data['page_size'] ) ) {
			update_post_meta( $post_id, '_page_size', sanitize_text_field( $research_data['page_size'] ) );
		}
		if ( isset( $research_data['orientation'] ) ) {
			update_post_meta( $post_id, '_orientation', sanitize_text_field( $research_data['orientation'] ) );
		}
		if ( isset( $research_data['output_format'] ) ) {
			update_post_meta( $post_id, '_output_format', sanitize_text_field( $research_data['output_format'] ) );
		}

		// Return success with template ID and edit URL.
		$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );

		wp_send_json_success(
			array(
				'message'     => __( 'Document template created successfully!', 'nvoos-content-graph-pro' ),
				'template_id' => $post_id,
				'edit_url'    => $edit_url,
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
	protected static function process_import_data( $data, $format ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by trait interface.
		return new WP_Error( 'not_implemented', __( 'Template import processing coming soon', 'nvoos-content-graph-pro' ) );
	}

	/**
	 * Get validation schema.
	 *
	 * @return array Validation schema.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title'         => __( 'Template Name', 'nvoos-content-graph-pro' ),
				'document_type' => __( 'Document Type', 'nvoos-content-graph-pro' ),
			),
			'recommended_fields' => array(
				'content'       => __( 'Template Content', 'nvoos-content-graph-pro' ),
				'page_size'     => __( 'Page Size', 'nvoos-content-graph-pro' ),
				'orientation'   => __( 'Orientation', 'nvoos-content-graph-pro' ),
				'output_format' => __( 'Output Format', 'nvoos-content-graph-pro' ),
			),
			'quality_dimensions' => array(
				'completeness',
				'consistency',
				'usability',
			),
		);
	}

	/**
	 * Calculate completeness.
	 *
	 * @return array Completeness data.
	 */
	protected static function calculate_completeness() {
		$templates = get_posts(
			array(
				'post_type'      => 'mcp_ai_doc_tpl',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $templates );
		$complete = 0;

		foreach ( $templates as $template ) {
			$doc_type    = get_post_meta( $template->ID, '_document_type', true );
			$page_size   = get_post_meta( $template->ID, '_page_size', true );
			$has_content = ! empty( $template->post_content );

			if ( $doc_type && $page_size && $has_content ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array(),
			'suggestions' => array(
				__( 'Ensure all templates have document type specified', 'nvoos-content-graph-pro' ),
				__( 'Add template content and configuration', 'nvoos-content-graph-pro' ),
				__( 'Set page size and orientation', 'nvoos-content-graph-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Items.
	 */
	protected static function get_items_for_review() {
		$templates = get_posts(
			array(
				'post_type'      => 'mcp_ai_doc_tpl',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $templates as $template ) {
			$items[] = array(
				'id'    => $template->ID,
				'title' => $template->post_title,
				'meta'  => array(
					'document_type' => get_post_meta( $template->ID, '_document_type', true ),
					'page_size'     => get_post_meta( $template->ID, '_page_size', true ),
					'orientation'   => get_post_meta( $template->ID, '_orientation', true ),
				),
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

		if ( ! empty( $item['meta']['document_type'] ) ) {
			$score += 33;
		} else {
			$issues[] = __( 'Missing document type', 'nvoos-content-graph-pro' );
		}

		if ( ! empty( $item['meta']['page_size'] ) ) {
			$score += 33;
		} else {
			$issues[] = __( 'Missing page size', 'nvoos-content-graph-pro' );
		}

		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) > 5 ) {
			$score += 34;
		} else {
			$issues[] = __( 'Title needs improvement', 'nvoos-content-graph-pro' );
		}

		$level = $score >= 80 ? 'high' : ( $score >= 50 ? 'medium' : 'low' );

		return array(
			'score'  => $score,
			'level'  => $level,
			'status' => 'high' === $level ? __( 'Complete', 'nvoos-content-graph-pro' ) : __( 'Needs Work', 'nvoos-content-graph-pro' ),
			'issues' => $issues,
		);
	}

	/**
	 * Render import workflow.
	 */
	protected static function render_import_workflow() {
		?>
		<div class="wp-mcp-ai-import-section">
			<h2><?php esc_html_e( 'Import Template Data', 'nvoos-content-graph-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import templates from CSV, JSON, or paste structured data. The AI will automatically parse and organize the template information.', 'nvoos-content-graph-pro' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'nvoos-content-graph-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include template name, type, and content', 'nvoos-content-graph-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify page size and orientation', 'nvoos-content-graph-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Add template variables and placeholders', 'nvoos-content-graph-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include header and footer content if applicable', 'nvoos-content-graph-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'nvoos-content-graph-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_templates', 'import_nonce' ); ?>
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".csv,.json,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'nvoos-content-graph-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: CSV, JSON, TXT', 'nvoos-content-graph-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'nvoos-content-graph-pro' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nTemplate Name: Invoice Template\nType: PDF\nPage Size: Letter\nOrientation: Portrait\nContent: [Invoice header and body content here]\n\nTemplate Name: Report Template\nType: Word\nPage Size: A4\nOrientation: Portrait', 'nvoos-content-graph-pro' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label for="auto-create-templates">
							<input type="checkbox" id="auto-create-templates" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create templates (recommended)', 'nvoos-content-graph-pro' ); ?>
						</label>
						<label for="validate-template-data">
							<input type="checkbox" id="validate-template-data" name="validate_data" value="1" checked>
							<?php esc_html_e( 'Validate data quality before importing', 'nvoos-content-graph-pro' ); ?>
						</label>
					</div>

					<p>
						<button type="submit" class="button button-primary button-large">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Import & Process', 'nvoos-content-graph-pro' ); ?>
						</button>
					</p>
					<div class="import-result" style="display: none;"></div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render review workflow.
	 */
	protected static function render_review_workflow() {
		// Get template statistics.
		$total_templates = wp_count_posts( 'mcp_ai_doc_tpl' );
		$published_count = isset( $total_templates->publish ) ? $total_templates->publish : 0;

		// Calculate data quality metrics.
		$templates = get_posts(
			array(
				'post_type'      => 'mcp_ai_doc_tpl',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count   = 0;
		$with_type        = 0;
		$with_page_config = 0;

		foreach ( $templates as $template ) {
			$doc_type    = get_post_meta( $template->ID, '_document_type', true );
			$page_size   = get_post_meta( $template->ID, '_page_size', true );
			$has_content = ! empty( $template->post_content );

			if ( $doc_type ) {
				++$with_type;
			}
			if ( $page_size ) {
				++$with_page_config;
			}
			if ( $doc_type && $page_size && $has_content ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Template Quality Dashboard', 'nvoos-content-graph-pro' ); ?></h2>
			
			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'nvoos-content-graph-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Templates', 'nvoos-content-graph-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'nvoos-content-graph-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_type ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Type', 'nvoos-content-graph-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_page_config ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Page Config', 'nvoos-content-graph-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Template completeness is %d%%. Ensure all templates have document type, page size, and content for best results.', 'nvoos-content-graph-pro' ),
								esc_html( $completeness )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<?php self::render_quality_table(); ?>

			<div class="items-list-table">
				<h3><?php esc_html_e( 'Quick Actions', 'nvoos-content-graph-pro' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_doc_tpl' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Templates', 'nvoos-content-graph-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_doc_tpl' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Template', 'nvoos-content-graph-pro' ); ?>
					</a>
					<button type="button" class="button refresh-quality-data">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh Data', 'nvoos-content-graph-pro' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}
}

// Initialize.
WP_MCP_AI_Document_Template_Research_Page::init();
