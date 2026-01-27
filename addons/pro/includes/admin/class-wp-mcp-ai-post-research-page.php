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

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';

/**
 * Post Research Admin Page
 *
 * Adds a submenu page under Posts menu for AI-powered topic research.
 */
class WP_MCP_AI_Post_Research_Page {
	use WP_MCP_AI_Research_Page_Featured_Image;

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
		add_action( 'wp_ajax_wp_mcp_ai_import_post', array( __CLASS__, 'handle_import' ) );
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
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'wp_mcp_ai_research_post' ),
				'addNewUrl'    => admin_url( 'post-new.php' ),
				'researchTool' => 'research_post',
				'strings'      => array(
					'researching'   => __( 'Researching...', 'mcp-ai-wpoos-pro' ),
					'error'         => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'creating'      => __( 'Creating post...', 'mcp-ai-wpoos-pro' ),
					'created'       => __( 'Post created successfully!', 'mcp-ai-wpoos-pro' ),
					'confirmCreate' => __( 'Create a post with the researched information?', 'mcp-ai-wpoos-pro' ),
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
							<li><?php esc_html_e( 'Search existing posts or research new topics on the web', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Use deep research for comprehensive topic analysis', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Generate content with SEO optimization', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create posts directly or save for later editing', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Check existing posts to avoid duplicates', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Deep research:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Use for comprehensive topic analysis', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'SEO optimize:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Get SEO analysis with Rank Math integration', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Add images:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Generate captions for featured images', 'mcp-ai-wpoos-pro' ); ?></li>
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
							// Render chat interface with comprehensive post tools.
							// Includes creation, management, research, and content discovery tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="create_post,save_post,get_recent_posts,web_search,deep_research,search_content,semantic_content_search,get_rankmath_seo,generate_image_caption"]'
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
	 * Get supported import formats.
	 *
	 * @return array Associative array of format key => label.
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
	 * Process import data.
	 *
	 * @param array  $data   Import data.
	 * @param string $format Import format.
	 * @return array|WP_Error Processed data or error.
	 */
	protected static function process_import_data( $data, $format ) {
		// Prevent unused variable warnings.
		unset( $data, $format );

		return new WP_Error( 'not_implemented', __( 'Post import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema for posts.
	 *
	 * @return array Validation schema with required/recommended fields and rules.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title'   => __( 'Post Title', 'mcp-ai-wpoos-pro' ),
				'content' => __( 'Post Content', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'excerpt'          => __( 'Excerpt', 'mcp-ai-wpoos-pro' ),
				'featured_image'   => __( 'Featured Image', 'mcp-ai-wpoos-pro' ),
				'category'         => __( 'Category', 'mcp-ai-wpoos-pro' ),
				'tags'             => __( 'Tags', 'mcp-ai-wpoos-pro' ),
				'meta_description' => __( 'Meta Description', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'title'   => array( 'max_length' => 60 ),
				'excerpt' => array( 'max_length' => 160 ),
			),
			'quality_dimensions' => array(
				'seo_optimization',
				'readability',
				'completeness',
				'media_quality',
			),
		);
	}

	/**
	 * Calculate completeness percentage for posts.
	 *
	 * @return array Completeness data with percentage, missing items, and suggestions.
	 */
	protected static function calculate_completeness() {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $posts );
		$complete = 0;

		foreach ( $posts as $post ) {
			$has_image    = has_post_thumbnail( $post->ID );
			$has_excerpt  = ! empty( $post->post_excerpt );
			$has_category = ! empty( wp_get_post_categories( $post->ID ) );
			if ( $has_image && $has_excerpt && $has_category ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array(),
			'suggestions' => array(
				__( 'Add featured images to all posts', 'mcp-ai-wpoos-pro' ),
				__( 'Write compelling excerpts', 'mcp-ai-wpoos-pro' ),
				__( 'Optimize titles for SEO (under 60 chars)', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Array of posts with metadata.
	 */
	protected static function get_items_for_review() {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
				'meta'  => array(
					'has_image'   => has_post_thumbnail( $post->ID ),
					'has_excerpt' => ! empty( $post->post_excerpt ),
					'categories'  => wp_get_post_categories( $post->ID ),
				),
			);
		}

		return $items;
	}

	/**
	 * Calculate quality score for a post item.
	 *
	 * @param array $item Post item with metadata.
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

		if ( ! empty( $item['meta']['has_excerpt'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing excerpt', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['categories'] ) ) {
			$score += 20;
		} else {
			$issues[] = __( 'Missing categories', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) <= 60 ) {
			$score += 20;
		} else {
			$issues[] = __( 'Title too long or missing', 'mcp-ai-wpoos-pro' );
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
	 * Handle AJAX import request.
	 */
	public static function handle_import() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_post', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import posts.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get import data.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$import_data = isset( $_POST['import_data'] ) ? json_decode( wp_unslash( $_POST['import_data'] ), true ) : array();
		$format      = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : '';

		if ( empty( $import_data ) || empty( $format ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Validate format.
		$formats = self::get_import_formats();
		if ( ! isset( $formats[ $format ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import format.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process import.
		$result = self::process_import_data( $import_data, $format );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Import completed successfully!', 'mcp-ai-wpoos-pro' ),
				'result'  => $result,
			)
		);
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

		// Process featured image generation request.
		$research_data = self::process_featured_image_request( $research_data, $research_data['title'], 'a blog post' );

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
}

// Initialize.
WP_MCP_AI_Post_Research_Page::init();
