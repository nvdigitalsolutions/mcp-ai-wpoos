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

		// Enqueue research page specific styles.
		wp_enqueue_style(
			'wp-mcp-ai-research-page',
			WP_MCP_AI_PRO_URL . 'assets/css/research-page.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue research page script.
		wp_enqueue_script(
			'wp-mcp-ai-research-page',
			WP_MCP_AI_PRO_URL . 'assets/js/research-page.js',
			array( 'jquery', 'wp-api', WP_MCP_AI_Shortcode::SCRIPT_HANDLE ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'wp-mcp-ai-research-page',
			'wpMcpAiResearchPage',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'wp_mcp_ai_design_media' ),
				'addNewUrl'     => admin_url( 'post-new.php?post_type=mcp_ai_media_tpl' ),
				'researchTool'  => 'create_media_template',
				'strings'       => array(
					'researching'       => __( 'Designing...', 'mcp-ai-wpoos-pro' ),
					'error'             => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'creating'          => __( 'Creating media template...', 'mcp-ai-wpoos-pro' ),
					'created'           => __( 'Media template created successfully!', 'mcp-ai-wpoos-pro' ),
					'confirmCreate'     => __( 'Create a media template with the designed configuration?', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
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
							<li><?php esc_html_e( 'Generate new images with AI or upload/select existing ones', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Use the AI assistant to design and apply graphic edits', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Ask for operations like "Generate an image of..." or "Resize to 1920x1080"', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create collections to group images for batch processing', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create templates for reuse or work with collections for batch operations', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Design Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Generate images:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Ask AI to create images from text descriptions', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'AI editing:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Use AI to edit existing images with natural language instructions', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Be specific:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Specify operation type and exact parameters', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Use templates:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Create reusable templates for common operations', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Use collections:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Group images and apply templates to entire collections', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Create collections:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Organize generated or edited images into collections', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate an image of a sunset over mountains">
								<?php esc_html_e( '"Generate sunset image..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Edit image 123 to add clouds to the sky">
								<?php esc_html_e( '"AI edit: add clouds..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Resize the image to 1920x1080 for Instagram">
								<?php esc_html_e( '"Resize for Instagram..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a collection called Product Photos and add images 123, 124, 125">
								<?php esc_html_e( '"Create collection..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Process collection 5 with template 12">
								<?php esc_html_e( '"Process collection..."', 'mcp-ai-wpoos-pro' ); ?>
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
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive media tools.
							// Includes tools for image generation, AI editing, templates, and collections.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="graphic_editor_plus,generate_openai_image,generate_gemini_image,generate_cloudflareai_image,edit_openai_image,edit_gemini_image,create_media_template,apply_media_template,list_media_templates,create_media_collection,process_collection,apply_collection_template"]'
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
}

// Initialize.
WP_MCP_AI_Media_Design_Page::init();
