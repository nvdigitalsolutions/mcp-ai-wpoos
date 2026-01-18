<?php
/**
 * Research & Add admin page for Team CPT.
 *
 * Provides a dedicated page for researching team composition, orchestration modes,
 * and workflows before creating teams, with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Team Research Admin Page
 *
 * Adds a submenu page under Teams menu for AI-powered team research.
 */
class WP_MCP_AI_Admin_Team_Research_Page {
	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'research-team';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add submenu page under Teams menu.
	 */
	public static function add_menu_page() {
		$post_type = class_exists( 'WP_MCP_AI_Team_CPT' ) ? WP_MCP_AI_Team_CPT::POST_TYPE : 'mcp_ai_team';

		add_submenu_page(
			'edit.php?post_type=' . $post_type,
			__( 'Research & Add Team', 'mcp-ai-wpoos' ),
			__( 'Research & Add', 'mcp-ai-wpoos' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			15 // Before Test Team (priority 20) and Settings (priority 25).
		);
	}

	/**
	 * Enqueue assets for the research page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		$post_type = class_exists( 'WP_MCP_AI_Team_CPT' ) ? WP_MCP_AI_Team_CPT::POST_TYPE : 'mcp_ai_team';

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
		$post_type = class_exists( 'WP_MCP_AI_Team_CPT' ) ? WP_MCP_AI_Team_CPT::POST_TYPE : 'mcp_ai_team';

		// Get assistant - try team settings first, then first available.
		$settings     = get_option( 'wp_mcp_ai_team_settings', array() );
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
				<?php esc_html_e( 'Research & Add Team', 'mcp-ai-wpoos' ); ?>
			</h1>

			<hr class="wp-header-end">

			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-research-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Search existing teams and professions', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Research team composition and role distribution', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Define orchestration mode (sequential, parallel, swarm)', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Create teams with proper member assignments', 'mcp-ai-wpoos' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Check existing teams to avoid duplicates', 'mcp-ai-wpoos' ); ?></li>
							<li><strong><?php esc_html_e( 'Role balance:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Mix planners, executors, and critics for best results', 'mcp-ai-wpoos' ); ?></li>
							<li><strong><?php esc_html_e( 'Orchestration:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Choose mode based on task complexity', 'mcp-ai-wpoos' ); ?></li>
							<li><strong><?php esc_html_e( 'Aggregation:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'Define how team results should be combined', 'mcp-ai-wpoos' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research a software development team with roles for planning, coding, and quality assurance">
								<?php esc_html_e( '"Research a software development team..."', 'mcp-ai-wpoos' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a content creation team with writers, editors, and SEO specialists working in parallel">
								<?php esc_html_e( '"Create a content creation team..."', 'mcp-ai-wpoos' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research a product launch team with sequential workflow from planning to execution to review">
								<?php esc_html_e( '"Research a product launch team..."', 'mcp-ai-wpoos' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>" class="button">
								<?php esc_html_e( 'View All Teams', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>" class="button">
								<?php esc_html_e( 'Add Team Manually', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type . '&page=test-team' ) ); ?>" class="button">
								<?php esc_html_e( 'Test Team', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-secondary">
								<?php esc_html_e( 'View Professions', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-research-main">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with team-related tools.
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
WP_MCP_AI_Admin_Team_Research_Page::init();
