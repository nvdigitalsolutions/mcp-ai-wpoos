<?php
/**
 * Design & Add admin page for Media Templates.
 *
 * Provides a dedicated page for designing media templates before creating them,
 * with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';

/**
 * Media Design Admin Page
 *
 * Adds a submenu page under Media menu for AI-powered media design.
 */
class WP_MCP_AI_Media_Design_Page {
	use WP_MCP_AI_Research_Page_Featured_Image;

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'design-media';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_head', array( __CLASS__, 'admin_head_styles' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_media_from_design', array( __CLASS__, 'handle_create_from_design' ) );
	}

	/**
	 * Add submenu page under Media menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'upload.php',
			__( 'Design & Add Media', 'mcp-ai-wpoos-pro' ),
			__( 'Design & Add', 'mcp-ai-wpoos-pro' ),
			'upload_files',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the design page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		// Only load on our design page.
		if ( 'media_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_design_media' ),
				'entityType' => 'media',
			)
		);
	}

	/**
	 * Output inline styles for admin menu highlighting.
	 */
	public static function admin_head_styles() {
		$screen = get_current_screen();

		// Only output on our design page.
		if ( ! $screen || 'media_page_' . self::PAGE_SLUG !== $screen->id ) {
			return;
		}

		?>
		<style>
			/* Ensure proper admin menu highlighting for Media > Design & Add */
			#adminmenu #menu-media .wp-submenu li.current a,
			#adminmenu #menu-media .wp-submenu li.current {
				color: #fff;
			}
		</style>
		<?php
	}

	/**
	 * Render the design page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_media_settings', array() );
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
				<?php esc_html_e( 'Design & Add Media', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-research-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Search for existing images or generate new ones with AI', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Edit images with AI or apply standard operations (resize, crop, rotate, convert)', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Generate alt text and captions for accessibility', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create templates for reusable operations', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Organize images into collections for batch processing', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Design Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search images:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Find existing images in your Media Library by keywords or type', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Generate images:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Create images from text descriptions', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'AI editing:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Edit images with natural language instructions', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Image operations:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Resize, crop, rotate, convert formats, create variations', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Accessibility:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Generate alt text and captions automatically', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Templates & Collections:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Save operations as templates, organize images in collections', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Search for product images uploaded this month">
								<?php esc_html_e( '"Search for images..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate an image of a sunset over mountains">
								<?php esc_html_e( '"Generate sunset..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Crop image 123 to 16:9 aspect ratio">
								<?php esc_html_e( '"Crop to 16:9..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate alt text for image 456">
								<?php esc_html_e( '"Generate alt text..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a collection with images 123, 124, 125">
								<?php esc_html_e( '"Create collection..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>" class="button">
								<?php esc_html_e( 'Media Library', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_media_tpl' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Templates', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_media_coll' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Collections', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_media_tpl' ) ); ?>" class="button">
								<?php esc_html_e( 'Create Template', 'mcp-ai-wpoos-pro' ); ?>
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
								<strong><?php esc_html_e( 'AI Design', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Design media with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import media templates', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View template quality and completeness', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Design Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
						<?php if ( $assistant_id > 0 ) : ?>
							<div class="wp-mcp-ai-research-chat">
								<?php
								// Render chat interface with comprehensive media tools.
								// Includes tools for searching, generating, editing, operations, templates, and collections.
								echo do_shortcode(
									'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="search_attachments,graphic_editor_plus,generate_openai_image,generate_gemini_image,generate_cloudflareai_image,edit_openai_image,edit_gemini_image,create_image_variation,resize_image,crop_image,rotate_image,convert_image_format,generate_image_alt_text,generate_image_caption,vectorize_image,create_media_template,apply_media_template,list_media_templates,create_media_collection,process_collection,apply_collection_template"]'
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
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to create/update media from design.
	 */
	public static function handle_create_from_design() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_design_media', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage media.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get design data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$design_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $design_data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid design data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Check if this is a template creation or graphic operation.
		$is_template = isset( $design_data['operation'] ) && isset( $design_data['parameters'] ) && ! isset( $design_data['attachment_id'] );

		if ( $is_template ) {
			// Create a media template.
			if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Media_Template' ) ) {
				wp_send_json_error( array( 'message' => __( 'Create media template tool not available.', 'mcp-ai-wpoos-pro' ) ) );
			}

			$tool   = new WP_MCP_AI_Tool_Create_Media_Template();
			$result = $tool->execute(
				$design_data,
				array( 'user_id' => get_current_user_id() )
			);

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			// Return success with template ID and edit URL.
			$template_id = isset( $result['template_id'] ) ? $result['template_id'] : 0;
			$edit_url    = $template_id > 0 ? admin_url( 'post.php?post=' . $template_id . '&action=edit' ) : '';

			wp_send_json_success(
				array(
					'message'     => __( 'Media template created successfully!', 'mcp-ai-wpoos-pro' ),
					'template_id' => $template_id,
					'edit_url'    => $edit_url,
				)
			);
		} else {
			// Apply graphic operation directly to an image.
			if ( ! class_exists( 'WP_MCP_AI_Tool_Graphic_Editor_Plus' ) ) {
				wp_send_json_error( array( 'message' => __( 'Graphic Editor Plus tool not available.', 'mcp-ai-wpoos-pro' ) ) );
			}

			$tool   = new WP_MCP_AI_Tool_Graphic_Editor_Plus();
			$result = $tool->execute(
				$design_data,
				array( 'user_id' => get_current_user_id() )
			);

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			// Return success with attachment ID and edit URL.
			$attachment_id = isset( $result['attachment_id'] ) ? $result['attachment_id'] : 0;
			$edit_url      = $attachment_id > 0 ? admin_url( 'post.php?post=' . $attachment_id . '&action=edit' ) : '';
			$preview_url   = $attachment_id > 0 ? wp_get_attachment_url( $attachment_id ) : '';

			wp_send_json_success(
				array(
					'message'       => __( 'Image processed successfully!', 'mcp-ai-wpoos-pro' ),
					'attachment_id' => $attachment_id,
					'edit_url'      => $edit_url,
					'preview_url'   => $preview_url,
				)
			);
		}
	}

	/**
	 * Render import workflow.
	 */
	protected static function render_import_workflow() {
		?>
		<div class="wp-mcp-ai-import-section">
			<h2><?php esc_html_e( 'Import Media Template Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import media templates from CSV, JSON, or paste structured data. The AI will automatically parse and organize the template information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include template name, operation type, and parameters', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify operation details (resize, crop, filter settings)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Define target dimensions and quality settings', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include description and use case notes', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_media_templates', 'import_nonce' ); ?>
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".csv,.json,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: CSV, JSON, TXT', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nTemplate Name: Blog Image Resize\nOperation: resize\nWidth: 1200\nHeight: 630\nMaintain Aspect: yes\nDescription: Standard blog post header image\n\nTemplate Name: Product Thumbnail\nOperation: crop\nWidth: 300\nHeight: 300\nPosition: center', 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create templates (recommended)', 'mcp-ai-wpoos-pro' ); ?>
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
		// Get template statistics.
		$total_templates          = wp_count_posts( 'mcp_ai_media_tpl' );
		$template_published_count = isset( $total_templates->publish ) ? $total_templates->publish : 0;

		// Get collection statistics.
		$total_collections          = wp_count_posts( 'mcp_ai_media_coll' );
		$collection_published_count = isset( $total_collections->publish ) ? $total_collections->publish : 0;

		// Calculate template quality metrics.
		$templates = get_posts(
			array(
				'post_type'      => 'mcp_ai_media_tpl',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$template_complete_count = 0;
		$with_operation          = 0;
		$with_params             = 0;

		foreach ( $templates as $template ) {
			$operation  = get_post_meta( $template->ID, '_wp_mcp_ai_media_tpl_operation', true );
			$parameters = get_post_meta( $template->ID, '_wp_mcp_ai_media_tpl_parameters', true );

			if ( ! empty( $operation ) ) {
				++$with_operation;
			}
			if ( ! empty( $parameters ) && is_array( $parameters ) ) {
				++$with_params;
			}
			if ( ! empty( $operation ) && ! empty( $parameters ) ) {
				++$template_complete_count;
			}
		}

		// Calculate collection quality metrics.
		$collections = get_posts(
			array(
				'post_type'      => 'mcp_ai_media_coll',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$collection_complete_count = 0;
		$with_images               = 0;
		$with_template             = 0;

		foreach ( $collections as $collection ) {
			$images      = get_post_meta( $collection->ID, '_wp_mcp_ai_media_coll_images', true );
			$template_id = get_post_meta( $collection->ID, '_wp_mcp_ai_media_coll_template', true );

			if ( ! empty( $images ) && is_array( $images ) && count( $images ) > 0 ) {
				++$with_images;
			}
			if ( ! empty( $template_id ) ) {
				++$with_template;
			}
			if ( ! empty( $images ) && is_array( $images ) && count( $images ) > 0 ) {
				++$collection_complete_count;
			}
		}

		$template_completeness   = $template_published_count > 0 ? round( ( $template_complete_count / $template_published_count ) * 100 ) : 0;
		$collection_completeness = $collection_published_count > 0 ? round( ( $collection_complete_count / $collection_published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Media Template Quality', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<!-- Templates Section -->
			<div class="quality-dashboard" style="margin-bottom: 30px;">
				<h3><?php esc_html_e( 'Templates - Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $template_completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $template_completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $template_published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Templates', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $template_complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_operation ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Operation', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_params ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Parameters', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $template_completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Template completeness is %d%%. Consider defining operations and parameters for better template functionality.', 'mcp-ai-wpoos-pro' ),
								esc_html( $template_completeness )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<!-- Collections Section -->
			<div class="quality-dashboard" style="margin-bottom: 30px;">
				<h3><?php esc_html_e( 'Collections - Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $collection_completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $collection_completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $collection_published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Collections', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $collection_complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Images', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_images ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Non-Empty', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_template ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Template', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $collection_completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Collection completeness is %d%%. Add images to collections for better organization.', 'mcp-ai-wpoos-pro' ),
								esc_html( $collection_completeness )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<!-- Templates List Table -->
			<?php self::render_templates_table(); ?>

			<!-- Collections List Table -->
			<?php self::render_collections_table(); ?>

			<div class="items-list-table">
				<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_media_tpl' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Templates', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_media_coll' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Collections', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_media_tpl' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Template', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>" class="button">
						<?php esc_html_e( 'Media Library', 'mcp-ai-wpoos-pro' ); ?>
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

	/**
	 * Render templates list table.
	 */
	protected static function render_templates_table() {
		$templates = get_posts(
			array(
				'post_type'      => 'mcp_ai_media_tpl',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		?>
		<div class="templates-table" style="margin-bottom: 30px;">
			<h3><?php esc_html_e( 'Recent Templates', 'mcp-ai-wpoos-pro' ); ?></h3>
			
			<?php if ( empty( $templates ) ) : ?>
				<p><?php esc_html_e( 'No templates found. Create one using the AI Design workflow above.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Template Name', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Operation', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Parameters', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Created', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $templates as $template ) : ?>
							<?php
							$operation   = get_post_meta( $template->ID, '_wp_mcp_ai_media_tpl_operation', true );
							$parameters  = get_post_meta( $template->ID, '_wp_mcp_ai_media_tpl_parameters', true );
							$param_count = is_array( $parameters ) ? count( $parameters ) : 0;
							$edit_url    = admin_url( 'post.php?post=' . $template->ID . '&action=edit' );
							?>
							<tr>
								<td>
									<strong>
										<a href="<?php echo esc_url( $edit_url ); ?>">
											<?php echo esc_html( $template->post_title ); ?>
										</a>
									</strong>
								</td>
								<td>
									<?php if ( ! empty( $operation ) ) : ?>
										<code><?php echo esc_html( $operation ); ?></code>
									<?php else : ?>
										<span style="color: #999;"><?php esc_html_e( 'Not set', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $param_count > 0 ) : ?>
										<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span>
										<?php
										printf(
											/* translators: %d: Number of parameters */
											esc_html( _n( '%d parameter', '%d parameters', $param_count, 'mcp-ai-wpoos-pro' ) ),
											esc_html( $param_count )
										);
										?>
									<?php else : ?>
										<span style="color: #999;"><?php esc_html_e( 'None', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( get_the_date( '', $template ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
										<?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render collections list table.
	 */
	protected static function render_collections_table() {
		$collections = get_posts(
			array(
				'post_type'      => 'mcp_ai_media_coll',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		?>
		<div class="collections-table" style="margin-bottom: 30px;">
			<h3><?php esc_html_e( 'Recent Collections', 'mcp-ai-wpoos-pro' ); ?></h3>
			
			<?php if ( empty( $collections ) ) : ?>
				<p><?php esc_html_e( 'No collections found. Create one using the AI Design workflow above.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Collection Name', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Images', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Template', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Created', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $collections as $collection ) : ?>
							<?php
							$images      = get_post_meta( $collection->ID, '_wp_mcp_ai_media_coll_images', true );
							$template_id = get_post_meta( $collection->ID, '_wp_mcp_ai_media_coll_template', true );
							$image_count = is_array( $images ) ? count( $images ) : 0;
							$edit_url    = admin_url( 'post.php?post=' . $collection->ID . '&action=edit' );
							?>
							<tr>
								<td>
									<strong>
										<a href="<?php echo esc_url( $edit_url ); ?>">
											<?php echo esc_html( $collection->post_title ); ?>
										</a>
									</strong>
								</td>
								<td>
									<?php if ( $image_count > 0 ) : ?>
										<span class="dashicons dashicons-images-alt2" style="color: #00a32a;"></span>
										<?php
										printf(
											/* translators: %d: Number of images */
											esc_html( _n( '%d image', '%d images', $image_count, 'mcp-ai-wpoos-pro' ) ),
											esc_html( $image_count )
										);
										?>
									<?php else : ?>
										<span style="color: #999;"><?php esc_html_e( 'Empty', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! empty( $template_id ) ) : ?>
										<?php
										$template = get_post( $template_id );
										if ( $template ) :
											?>
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $template_id . '&action=edit' ) ); ?>">
												<?php echo esc_html( $template->post_title ); ?>
											</a>
										<?php else : ?>
											<span style="color: #999;"><?php esc_html_e( 'Deleted', 'mcp-ai-wpoos-pro' ); ?></span>
										<?php endif; ?>
									<?php else : ?>
										<span style="color: #999;"><?php esc_html_e( 'None', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( get_the_date( '', $collection ) ); ?></td>
								<td>
									<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
										<?php esc_html_e( 'Edit', 'mcp-ai-wpoos-pro' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

// Initialize.
WP_MCP_AI_Media_Design_Page::init();
