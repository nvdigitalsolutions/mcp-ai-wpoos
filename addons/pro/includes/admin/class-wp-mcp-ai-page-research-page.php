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

/**
 * Page Research Admin Page
 *
 * Adds a submenu page under Pages menu for AI-powered content research.
 */
class WP_MCP_AI_Page_Research_Page {
	use WP_MCP_AI_Research_Page_Featured_Image;

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
		// For the built-in 'page' post type, WordPress uses 'page_page_{slug}' format.
		if ( 'page_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'        => wp_create_nonce( 'wp_mcp_ai_research_page' ),
				'addNewUrl'    => admin_url( 'post-new.php?post_type=page' ),
				'researchTool' => 'research_page',
				'strings'      => array(
					'researching'   => __( 'Researching...', 'mcp-ai-wpoos-pro' ),
					'error'         => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'creating'      => __( 'Creating page...', 'mcp-ai-wpoos-pro' ),
					'created'       => __( 'Page created successfully!', 'mcp-ai-wpoos-pro' ),
					'confirmCreate' => __( 'Create a page with the researched information?', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Output inline styles for admin menu highlighting.
	 */
	public static function admin_head_styles() {
		$screen = get_current_screen();
		
		// Only output on our research page.
		if ( ! $screen || 'page_page_' . self::PAGE_SLUG !== $screen->id ) {
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
}

// Initialize.
WP_MCP_AI_Page_Research_Page::init();
