<?php
/**
 * Research & Add admin page for Profession CPT.
 *
 * Provides a dedicated page for researching profession roles, expertise areas,
 * and capabilities before creating professions, with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Profession Research Admin Page
 *
 * Adds a submenu page under Professions menu for AI-powered profession research.
 */
class WP_MCP_AI_Admin_Profession_Research_Page {
	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'research-profession';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add submenu page under Professions menu.
	 */
	public static function add_menu_page() {
		$post_type = class_exists( 'WP_MCP_AI_Profession_CPT' ) ? WP_MCP_AI_Profession_CPT::POST_TYPE : 'mcp_ai_profession';

		add_submenu_page(
			'edit.php?post_type=' . $post_type,
			__( 'Research & Add Profession', 'mcp-ai-wpoos' ),
			__( 'Research & Add', 'mcp-ai-wpoos' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			15 // Before Test Profession (priority 20) and Settings (priority 25).
		);
	}

	/**
	 * Enqueue assets for the research page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		$post_type = class_exists( 'WP_MCP_AI_Profession_CPT' ) ? WP_MCP_AI_Profession_CPT::POST_TYPE : 'mcp_ai_profession';

		// Only load on our research page.
		if ( $post_type . '_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		// Enqueue inline styles (no separate CSS file needed).
		wp_add_inline_style(
			WP_MCP_AI_Shortcode::STYLE_HANDLE,
			'
			.wp-mcp-ai-research-page {
				max-width: 100%;
			}
			.wp-mcp-ai-research-container {
				display: flex;
				gap: 20px;
				margin-top: 20px;
			}
			.wp-mcp-ai-research-sidebar {
				flex: 0 0 300px;
				background: #fff;
				padding: 20px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
			}
			.wp-mcp-ai-research-main {
				flex: 1;
				min-width: 0;
				background: #fff;
				padding: 20px;
				border: 1px solid #c3c4c7;
				border-radius: 4px;
			}
			.wp-mcp-ai-research-sidebar h2,
			.wp-mcp-ai-research-sidebar h3 {
				margin-top: 0;
				font-size: 16px;
				font-weight: 600;
			}
			.wp-mcp-ai-research-sidebar h3 {
				font-size: 14px;
				margin-top: 20px;
			}
			.wp-mcp-ai-research-sidebar ol,
			.wp-mcp-ai-research-sidebar ul {
				margin: 10px 0;
				padding-left: 20px;
			}
			.wp-mcp-ai-research-sidebar li {
				margin-bottom: 8px;
			}
			.wp-mcp-ai-example-list {
				list-style: none;
				padding: 0;
			}
			.wp-mcp-ai-example-list li {
				margin-bottom: 10px;
			}
			.wp-mcp-ai-example-query {
				width: 100%;
				text-align: left;
				white-space: normal;
				height: auto;
				padding: 8px 12px;
			}
			.wp-mcp-ai-research-actions p {
				margin: 10px 0;
			}
			.wp-mcp-ai-research-actions .button {
				width: 100%;
				text-align: center;
			}
			@media (max-width: 782px) {
				.wp-mcp-ai-research-container {
					flex-direction: column;
				}
				.wp-mcp-ai-research-sidebar {
					flex: 1 1 auto;
				}
			}
			'
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		$post_type = class_exists( 'WP_MCP_AI_Profession_CPT' ) ? WP_MCP_AI_Profession_CPT::POST_TYPE : 'mcp_ai_profession';

		// Get assistant - try profession settings first, then first available.
		$settings     = get_option( 'wp_mcp_ai_profession_settings', array() );
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
				<?php esc_html_e( 'Research & Add Profession', 'mcp-ai-wpoos' ); ?>
			</h1>

			<hr class="wp-header-end">

			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-research-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Search existing professions to avoid duplicates', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Research profession roles, expertise, and best practices', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Define agent roles (planner, executor, critic, etc.)', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Create professions with proper tool configurations', 'mcp-ai-wpoos' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Check if similar professions already exist', 'mcp-ai-wpoos' ); ?></li>
							<li><strong><?php esc_html_e( 'Define roles:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Choose primary and secondary agent roles', 'mcp-ai-wpoos' ); ?></li>
							<li><strong><?php esc_html_e( 'Tools matter:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Select appropriate default tools for the profession', 'mcp-ai-wpoos' ); ?></li>
							<li><strong><?php esc_html_e( 'Expertise areas:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Define clear areas of expertise and knowledge', 'mcp-ai-wpoos' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research a Senior Software Engineer profession with technical leadership expertise and code review capabilities">
								<?php esc_html_e( '"Research a Senior Software Engineer profession..."', 'mcp-ai-wpoos' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a QA Engineer profession focused on test planning, execution, and quality assurance best practices">
								<?php esc_html_e( '"Create a QA Engineer profession..."', 'mcp-ai-wpoos' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research a Product Manager profession with planning and stakeholder management skills">
								<?php esc_html_e( '"Research a Product Manager profession..."', 'mcp-ai-wpoos' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>" class="button">
								<?php esc_html_e( 'View All Professions', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>" class="button">
								<?php esc_html_e( 'Add Profession Manually', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type . '&page=test-profession' ) ); ?>" class="button">
								<?php esc_html_e( 'Test Profession', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-research-main">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with profession-related tools.
							// Includes search, web research, and content management tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="search_content,web_search,list_tools"]'
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
										__( 'No AI assistant found. Please <a href="%s">create an assistant</a> first.', 'mcp-ai-wpoos' ),
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
}

// Initialize.
WP_MCP_AI_Admin_Profession_Research_Page::init();
