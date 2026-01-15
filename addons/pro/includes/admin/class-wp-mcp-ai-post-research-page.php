<?php
/**
 * Research & Add admin page for Post CPT.
 *
 * Provides a dedicated page for researching post topics before creating posts,
 * with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post Research Admin Page
 *
 * Adds a submenu page under Posts menu for AI-powered topic research.
 */
class WP_MCP_AI_Post_Research_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'research-post';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_post_from_research', array( __CLASS__, 'handle_create_from_research' ) );
	}

	/**
	 * Add submenu page under Posts menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php',
			__( 'Research & Add Post', 'mcp-ai-wpoos-pro' ),
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
		if ( 'posts_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'         => wp_create_nonce( 'wp_mcp_ai_research_post' ),
				'addNewUrl'     => admin_url( 'post-new.php' ),
				'researchTool'  => 'research_post',
				'strings'       => array(
					'researching'       => __( 'Researching...', 'mcp-ai-wpoos-pro' ),
					'error'             => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'creating'          => __( 'Creating post...', 'mcp-ai-wpoos-pro' ),
					'created'           => __( 'Post created successfully!', 'mcp-ai-wpoos-pro' ),
					'confirmCreate'     => __( 'Create a post with the researched information?', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_post_settings', array() );
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
				<?php esc_html_e( 'Research & Add Post', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-research-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Use the AI assistant to research a topic for your post', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Ask questions like "Research the latest trends in AI technology"', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Review the research results and generated content', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Click "Create Post from Research" to add it to your site', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Be specific:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Include keywords and target audience', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Set length:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Request specific word count or structure', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Review content:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Always review and edit AI-generated content', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Write a blog post about the benefits of meditation">
								<?php esc_html_e( '"Write a blog post about meditation"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research and write about sustainable living tips for beginners">
								<?php esc_html_e( '"Research sustainable living tips..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a how-to guide for starting a podcast">
								<?php esc_html_e( '"Create a how-to guide for podcasting"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Posts', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Post Manually', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-research-main">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '"]'
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
	 * Handle AJAX request to create post from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_post', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create posts.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) || empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Check if featured image generation is requested.
		$generate_image = isset( $_POST['generate_featured_image'] ) && 'true' === $_POST['generate_featured_image'];
		$image_prompt   = isset( $_POST['image_prompt'] ) ? sanitize_text_field( wp_unslash( $_POST['image_prompt'] ) ) : '';

		// Generate featured image if requested.
		if ( $generate_image ) {
			$image_attachment_id = self::generate_featured_image( $image_prompt, $research_data['title'] );
			if ( $image_attachment_id && ! is_wp_error( $image_attachment_id ) ) {
				$research_data['featured_image_id'] = $image_attachment_id;
			}
		}

		// Use the create_post tool to create the post.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Post' ) ) {
			wp_send_json_error( array( 'message' => __( 'Create post tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Ensure post_type is set to 'post'.
		$research_data['post_type'] = 'post';

		$tool   = new WP_MCP_AI_Tool_Create_Post();
		$result = $tool->execute(
			$research_data,
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Return success with post ID and edit URL.
		$post_id  = isset( $result['post_id'] ) ? $result['post_id'] : 0;
		$edit_url = $post_id > 0 ? admin_url( 'post.php?post=' . $post_id . '&action=edit' ) : '';

		wp_send_json_success(
			array(
				'message'  => __( 'Post created successfully!', 'mcp-ai-wpoos-pro' ),
				'post_id'  => $post_id,
				'edit_url' => $edit_url,
			)
		);
	}

	/**
	 * Generate featured image using AI.
	 *
	 * @param string $prompt Custom prompt or empty to use title.
	 * @param string $title  Post title for fallback prompt.
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	private static function generate_featured_image( $prompt, $title ) {
		// Use provided prompt or generate from title.
		if ( empty( $prompt ) ) {
			$prompt = sprintf(
				/* translators: %s: Post title */
				__( 'A professional featured image for a blog post about: %s', 'mcp-ai-wpoos-pro' ),
				$title
			);
		}

		// Try OpenAI image generation first.
		if ( class_exists( 'WP_MCP_AI_Tool_Generate_OpenAI_Image' ) ) {
			$tool = new WP_MCP_AI_Tool_Generate_OpenAI_Image();
			$result = $tool->execute(
				array(
					'prompt' => $prompt,
					'size'   => '1792x1024', // 16:9 aspect ratio.
				),
				array( 'user_id' => get_current_user_id() )
			);

			if ( ! is_wp_error( $result ) && isset( $result['attachment_id'] ) ) {
				return $result['attachment_id'];
			}
		}

		// Fallback to Gemini image generation.
		if ( class_exists( 'WP_MCP_AI_Tool_Generate_Gemini_Image' ) ) {
			$tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
			$result = $tool->execute(
				array(
					'prompt'       => $prompt,
					'aspect_ratio' => '16:9',
				),
				array( 'user_id' => get_current_user_id() )
			);

			if ( ! is_wp_error( $result ) && isset( $result['attachment_id'] ) ) {
				return $result['attachment_id'];
			}
		}

		return new WP_Error( 'image_generation_failed', __( 'Failed to generate featured image.', 'mcp-ai-wpoos-pro' ) );
	}
}

// Initialize.
WP_MCP_AI_Post_Research_Page::init();
