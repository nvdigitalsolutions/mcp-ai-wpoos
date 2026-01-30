<?php
/**
 * Research & Add admin page for Page CPT.
 *
 * Provides a dedicated page for researching page content before creating pages,
 * with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Page Research Admin Page
 *
 * Adds a submenu page under Pages menu for AI-powered content research.
 */
class WP_MCP_AI_Page_Research_Page {
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
	const PAGE_SLUG = 'page-research-page';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_head_styles' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_page_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_page', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Add submenu page under Pages menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=page',
			__( 'Research & Add Page', 'mcp-ai-wpoos-pro' ),
			__( 'Research & Add', 'mcp-ai-wpoos-pro' ),
			'edit_pages',
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
		// For the built-in 'page' post type, WordPress uses 'pages_page_{slug}' format (note the 's').
		if ( 'pages_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_page_entity' ),
				'entityType' => 'page',
			)
		);
	}

	/**
	 * Output inline styles for admin menu highlighting.
	 */
	public static function admin_head_styles() {
		$screen = get_current_screen();

		// Only output on our research page.
		if ( ! $screen || 'pages_page_' . self::PAGE_SLUG !== $screen->id ) {
			return;
		}

		?>
		<style>
			/* Ensure proper admin menu highlighting for Pages > Research & Add */
			#adminmenu #menu-pages .wp-submenu li.current a,
			#adminmenu #menu-pages .wp-submenu li.current {
				color: #fff;
			}
		</style>
		<?php
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_page_settings', array() );
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
				<?php esc_html_e( 'Research & Add Page', 'mcp-ai-wpoos-pro' ); ?>
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
							<li><?php esc_html_e( 'Search existing pages or use Elementor templates', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Research page content with web search or deep analysis', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Generate content with SEO optimization', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create pages directly with optional Elementor integration', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search pages:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Find existing pages for reference', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Use templates:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Browse Elementor templates for design ideas', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'SEO optimize:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Get SEO analysis before publishing', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Add visuals:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Generate image captions for better accessibility', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create an About Us page for a digital marketing agency">
								<?php esc_html_e( '"Create an About Us page..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Write a Privacy Policy page for an e-commerce website">
								<?php esc_html_e( '"Write a Privacy Policy page..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate a Contact page with business hours and location details">
								<?php esc_html_e( '"Generate a Contact page..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Pages', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Page Manually', 'mcp-ai-wpoos-pro' ); ?>
							</a>
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
								<p><?php esc_html_e( 'Research and create pages with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import page data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View page quality and completeness', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive page tools.
							// Includes creation, management, Elementor, research, and SEO tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="create_post,save_post,web_search,deep_research,search_content,semantic_content_search,get_elementor_templates,import_elementor_template_kit,get_rankmath_seo,generate_image_caption"]'
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
	 * Handle AJAX request to create page from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_page', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create pages.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) || empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process featured image generation request.
		$research_data = self::process_featured_image_request( $research_data, $research_data['title'], 'a page' );

		// Use the create_post tool to create the page.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Post' ) ) {
			wp_send_json_error( array( 'message' => __( 'Create post tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Ensure post_type is set to 'page'.
		$research_data['post_type'] = 'page';

		$tool   = new WP_MCP_AI_Tool_Create_Post();
		$result = $tool->execute(
			$research_data,
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Return success with page ID and edit URL.
		$page_id  = isset( $result['post_id'] ) ? $result['post_id'] : 0;
		$edit_url = $page_id > 0 ? admin_url( 'post.php?post=' . $page_id . '&action=edit' ) : '';

		wp_send_json_success(
			array(
				'message'  => __( 'Page created successfully!', 'mcp-ai-wpoos-pro' ),
				'page_id'  => $page_id,
				'edit_url' => $edit_url,
			)
		);
	}

	/**
	 * Get supported import formats for pages.
	 *
	 * @return array Array of format key => label pairs.
	 */
	protected static function get_import_formats() {
		return array(
			'xml'  => 'WordPress XML',
			'json' => 'JSON',
			'csv'  => 'CSV',
			'html' => 'HTML',
		);
	}

	/**
	 * Process import data based on format.
	 *
	 * @param array  $data   The import data.
	 * @param string $format The import format.
	 * @return array|WP_Error Processed data or error.
	 */
	protected static function process_import_data( $data, $format ) {
		// Prevent unused variable warnings.
		unset( $data, $format );

		return new WP_Error( 'not_implemented', __( 'Page import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema for page content.
	 *
	 * @return array Validation schema with required/recommended fields and rules.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title'   => __( 'Page Title', 'mcp-ai-wpoos-pro' ),
				'content' => __( 'Page Content', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'featured_image'   => __( 'Featured Image', 'mcp-ai-wpoos-pro' ),
				'meta_description' => __( 'Meta Description', 'mcp-ai-wpoos-pro' ),
				'parent_page'      => __( 'Parent Page', 'mcp-ai-wpoos-pro' ),
				'page_template'    => __( 'Page Template', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'title' => array( 'max_length' => 60 ),
			),
			'quality_dimensions' => array(
				'seo_optimization',
				'accessibility',
				'completeness',
				'hierarchy_structure',
			),
		);
	}

	/**
	 * Calculate page completeness score.
	 *
	 * @return array Completeness data with percentage, missing items, and suggestions.
	 */
	protected static function calculate_completeness() {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $pages );
		$complete = 0;

		foreach ( $pages as $page ) {
			$has_image   = has_post_thumbnail( $page->ID );
			$has_content = ! empty( $page->post_content ) && strlen( $page->post_content ) > 100;
			if ( $has_image && $has_content ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array(),
			'suggestions' => array(
				__( 'Add featured images to all pages', 'mcp-ai-wpoos-pro' ),
				__( 'Ensure pages have substantial content', 'mcp-ai-wpoos-pro' ),
				__( 'Optimize titles for SEO (under 60 chars)', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get pages for quality review.
	 *
	 * @return array Array of pages with metadata for review.
	 */
	protected static function get_items_for_review() {
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $pages as $page ) {
			$items[] = array(
				'id'    => $page->ID,
				'title' => $page->post_title,
				'meta'  => array(
					'has_image'      => has_post_thumbnail( $page->ID ),
					'content_length' => strlen( $page->post_content ),
					'parent_id'      => $page->post_parent,
				),
			);
		}

		return $items;
	}

	/**
	 * Calculate quality score for a page item.
	 *
	 * @param array $item Page item data.
	 * @return array Quality score data with score, level, status, and issues.
	 */
	protected static function calculate_quality_score( $item ) {
		$score  = 0;
		$issues = array();

		if ( ! empty( $item['meta']['has_image'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing featured image', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['content_length'] ) && $item['meta']['content_length'] > 100 ) {
			$score += 30;
		} else {
			$issues[] = __( 'Content too short', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) <= 60 && strlen( $item['title'] ) >= 10 ) {
			$score += 40;
		} else {
			$issues[] = __( 'Title length not optimal', 'mcp-ai-wpoos-pro' );
		}

		$level = $score >= 80 ? 'high' : ( $score >= 50 ? 'medium' : 'low' );

		return array(
			'score'  => $score,
			'level'  => $level,
			'status' => 'high' === $level ? __( 'Complete', 'mcp-ai-wpoos-pro' ) : __( 'Needs Work', 'mcp-ai-wpoos-pro' ),
			'issues' => $issues,
		);
	}

	/**
	 * Handle AJAX import request for pages.
	 */
	public static function handle_import() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_page', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_pages' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import pages.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get import data.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is validated and sanitized below.
		$import_data = isset( $_POST['import_data'] ) ? json_decode( wp_unslash( $_POST['import_data'] ), true ) : array();
		$format      = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : 'xml';

		if ( empty( $import_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process import data.
		$result = self::process_import_data( $import_data, $format );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Pages imported successfully!', 'mcp-ai-wpoos-pro' ),
				'result'  => $result,
			)
		);
	}

	/**
	 * Render import workflow.
	 */
	protected static function render_import_workflow() {
		?>
		<div class="wp-mcp-ai-import-section">
			<h2><?php esc_html_e( 'Import Page Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import pages from CSV, JSON, XML, HTML, or paste structured data. The AI will automatically parse and organize the page information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include page title and content', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify page templates and parent pages for hierarchy', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Add featured image URLs or generate with AI', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include meta descriptions for SEO', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_pages', 'import_nonce' ); ?>
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".csv,.json,.xml,.html,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: CSV, JSON, XML, HTML, TXT', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nTitle: About Us\nContent: We are a leading digital agency...\nTemplate: full-width\nParent: Home\n\nTitle: Privacy Policy\nContent: This privacy policy explains...\nTemplate: default', 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create pages (recommended)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<label>
							<input type="checkbox" name="validate_data" value="1" checked>
							<?php esc_html_e( 'Validate data quality before importing', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</div>

					<p>
						<button type="submit" class="button button-primary button-large">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Import & Process', 'mcp-ai-wpoos-pro' ); ?>
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
		// Get page statistics.
		$total_pages     = wp_count_posts( 'page' );
		$published_count = isset( $total_pages->publish ) ? $total_pages->publish : 0;

		// Calculate data quality metrics.
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count = 0;
		$with_image     = 0;
		$with_content   = 0;

		foreach ( $pages as $page ) {
			$has_image   = has_post_thumbnail( $page->ID );
			$has_content = ! empty( $page->post_content ) && strlen( $page->post_content ) > 100;

			if ( $has_image ) {
				++$with_image;
			}
			if ( $has_content ) {
				++$with_content;
			}
			if ( $has_image && $has_content ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Page Quality Dashboard', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Pages', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_image ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Featured Image', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_content ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Substantial Content', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Page completeness is %d%%. Consider adding featured images and substantial content to improve quality.', 'mcp-ai-wpoos-pro' ),
								esc_html( $completeness )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<?php self::render_quality_table(); ?>

			<div class="items-list-table">
				<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Pages', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Page', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<button type="button" class="button refresh-quality-data">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh Data', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}
}

// Initialize.
WP_MCP_AI_Page_Research_Page::init();
