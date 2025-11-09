<?php
/**
 * Displays Dashboard Admin Page
 *
 * Showcases all available Elementor widgets and Gutenberg blocks
 * with main operator buttons and functionality.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Displays_Dashboard' ) ) {
	/**
	 * Manages the Displays Dashboard admin page.
	 */
	class WP_MCP_AI_Admin_Displays_Dashboard {
		const PAGE_SLUG = 'wp-mcp-ai-displays';

		/**
		 * Page hook suffix.
		 *
		 * @var string
		 */
		private $page_hook = '';

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_page' ), 5 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Register the displays dashboard page under the WP oOS menu.
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'wp-mcp-ai-dashboard',
				__( 'Displays Dashboard - WP oOS', 'wp-mcp-ai' ),
				__( 'Displays Dashboard', 'wp-mcp-ai' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Enqueue CSS and JavaScript assets for the displays dashboard.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function enqueue_assets( $hook ) {
			if ( $this->page_hook !== $hook ) {
				return;
			}

			$plugin_url = plugin_dir_url( dirname( dirname( __FILE__ ) ) );

			// Enqueue styles.
			wp_enqueue_style(
				'wp-mcp-ai-displays-dashboard',
				$plugin_url . 'assets/css/displays-dashboard.css',
				array(),
				WP_MCP_AI_VERSION
			);

			// Enqueue scripts.
			wp_enqueue_script(
				'wp-mcp-ai-displays-dashboard',
				$plugin_url . 'assets/js/displays-dashboard.js',
				array( 'jquery' ),
				WP_MCP_AI_VERSION,
				true
			);

			// Localize script with settings.
			wp_localize_script(
				'wp-mcp-ai-displays-dashboard',
				'wpMcpAiDisplays',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp_mcp_ai_displays' ),
				)
			);
		}

		/**
		 * Get all registered Elementor widgets.
		 *
		 * @return array Array of widget information.
		 */
		private function get_elementor_widgets() {
			$widgets = array(
				'chat' => array(
					'title'   => __( 'Chat Widgets', 'wp-mcp-ai' ),
					'widgets' => array(
						array(
							'name'        => __( 'AI Chat', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_chat',
							'description' => __( 'Interactive AI chat interface with streaming support', 'wp-mcp-ai' ),
							'icon'        => 'eicon-comments',
						),
						array(
							'name'        => __( 'Chat FAQ', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_chat_faq',
							'description' => __( 'Frequently asked questions display for chat', 'wp-mcp-ai' ),
							'icon'        => 'eicon-help',
						),
						array(
							'name'        => __( 'Chat Intro', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_chat_intro',
							'description' => __( 'Welcome message and chat introduction', 'wp-mcp-ai' ),
							'icon'        => 'eicon-alert',
						),
						array(
							'name'        => __( 'Chat Usage Timer', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_chat_usage_timer',
							'description' => __( 'Display chat session usage and timer', 'wp-mcp-ai' ),
							'icon'        => 'eicon-time-line-o',
						),
					),
				),
				'assistant' => array(
					'title'   => __( 'Assistant Configuration Widgets', 'wp-mcp-ai' ),
					'widgets' => array(
						array(
							'name'        => __( 'Assistant Base Knowledge', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_assistant_base_knowledge',
							'description' => __( 'Display and manage assistant base knowledge', 'wp-mcp-ai' ),
							'icon'        => 'eicon-database',
						),
						array(
							'name'        => __( 'Assistant Defaults', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_assistant_defaults',
							'description' => __( 'Configure assistant default settings', 'wp-mcp-ai' ),
							'icon'        => 'eicon-settings',
						),
						array(
							'name'        => __( 'Assistant Prompt Shortcuts', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_assistant_prompt_shortcuts',
							'description' => __( 'Quick access to common prompts', 'wp-mcp-ai' ),
							'icon'        => 'eicon-flash',
						),
						array(
							'name'        => __( 'Assistant Tools', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_assistant_tools',
							'description' => __( 'Manage available tools for assistant', 'wp-mcp-ai' ),
							'icon'        => 'eicon-wrench',
						),
					),
				),
				'dashboard' => array(
					'title'   => __( 'Dashboard Widgets', 'wp-mcp-ai' ),
					'widgets' => array(
						array(
							'name'        => __( 'Tool Matrix', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_tool_matrix',
							'description' => __( 'Display matrix of available tools', 'wp-mcp-ai' ),
							'icon'        => 'eicon-table',
						),
						array(
							'name'        => __( 'User Capability', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_user_capability',
							'description' => __( 'Display user capabilities and permissions', 'wp-mcp-ai' ),
							'icon'        => 'eicon-lock-user',
						),
						array(
							'name'        => __( 'User Files', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_user_files',
							'description' => __( 'Display user uploaded files', 'wp-mcp-ai' ),
							'icon'        => 'eicon-folder',
						),
						array(
							'name'        => __( 'User Chats', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_user_chats',
							'description' => __( 'Display recent chat history', 'wp-mcp-ai' ),
							'icon'        => 'eicon-post-list',
						),
						array(
							'name'        => __( 'Theme Preview', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_theme_preview',
							'description' => __( 'Preview chat theme colors', 'wp-mcp-ai' ),
							'icon'        => 'eicon-paint-brush',
						),
						array(
							'name'        => __( 'Provider Links', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_provider_links',
							'description' => __( 'Quick links to AI provider dashboards', 'wp-mcp-ai' ),
							'icon'        => 'eicon-external-link-square',
						),
						array(
							'name'        => __( 'Activity Feed', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_activity_feed',
							'description' => __( 'Display recent system activity', 'wp-mcp-ai' ),
							'icon'        => 'eicon-archive',
						),
					),
				),
				'performance' => array(
					'title'   => __( 'Performance Monitoring Widgets', 'wp-mcp-ai' ),
					'widgets' => array(
						array(
							'name'        => __( 'Performance Metrics', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_performance_metrics',
							'description' => __( 'Display system performance metrics', 'wp-mcp-ai' ),
							'icon'        => 'eicon-dashboard',
						),
						array(
							'name'        => __( 'Performance Recommendations', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_performance_recommendations',
							'description' => __( 'Display performance optimization recommendations', 'wp-mcp-ai' ),
							'icon'        => 'eicon-lightbulb',
						),
						array(
							'name'        => __( 'Performance Test Runner', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_performance_test_runner',
							'description' => __( 'Run and display performance tests', 'wp-mcp-ai' ),
							'icon'        => 'eicon-play',
						),
						array(
							'name'        => __( 'Performance Trends', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_performance_trends',
							'description' => __( 'Display performance trends over time', 'wp-mcp-ai' ),
							'icon'        => 'eicon-trending-up',
						),
						array(
							'name'        => __( 'Test Results Table', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_test_results_table',
							'description' => __( 'Display test results in table format', 'wp-mcp-ai' ),
							'icon'        => 'eicon-table',
						),
					),
				),
				'system' => array(
					'title'   => __( 'System Health Widgets', 'wp-mcp-ai' ),
					'widgets' => array(
						array(
							'name'        => __( 'System Health Status', 'wp-mcp-ai' ),
							'slug'        => 'wp_mcp_ai_system_health',
							'description' => __( 'Display system health indicators', 'wp-mcp-ai' ),
							'icon'        => 'eicon-heart-o',
						),
					),
				),
			);

			return $widgets;
		}

		/**
		 * Get all registered Gutenberg blocks.
		 *
		 * @return array Array of block information.
		 */
		private function get_gutenberg_blocks() {
			$blocks = array(
				'chat' => array(
					'title'  => __( 'Chat Blocks', 'wp-mcp-ai' ),
					'blocks' => array(
						array(
							'name'        => __( 'AI Chat Block', 'wp-mcp-ai' ),
							'slug'        => 'wp-mcp-ai/chat',
							'description' => __( 'Interactive AI chat interface for Gutenberg', 'wp-mcp-ai' ),
							'icon'        => 'format-chat',
						),
					),
				),
				'assistant' => array(
					'title'  => __( 'Assistant Blocks', 'wp-mcp-ai' ),
					'blocks' => array(
						array(
							'name'        => __( 'Assistant Selector', 'wp-mcp-ai' ),
							'slug'        => 'wp-mcp-ai/assistant-selector',
							'description' => __( 'Choose from available assistants', 'wp-mcp-ai' ),
							'icon'        => 'admin-users',
						),
					),
				),
				'dashboard' => array(
					'title'  => __( 'Dashboard Blocks', 'wp-mcp-ai' ),
					'blocks' => array(
						array(
							'name'        => __( 'Tool Matrix Block', 'wp-mcp-ai' ),
							'slug'        => 'wp-mcp-ai/dashboard-tool-matrix',
							'description' => __( 'Display available tools in a matrix', 'wp-mcp-ai' ),
							'icon'        => 'grid-view',
						),
						array(
							'name'        => __( 'User Capability Block', 'wp-mcp-ai' ),
							'slug'        => 'wp-mcp-ai/dashboard-user-capability',
							'description' => __( 'Display user capabilities', 'wp-mcp-ai' ),
							'icon'        => 'admin-users',
						),
						array(
							'name'        => __( 'User Files Block', 'wp-mcp-ai' ),
							'slug'        => 'wp-mcp-ai/dashboard-user-files',
							'description' => __( 'Display user files', 'wp-mcp-ai' ),
							'icon'        => 'media-default',
						),
						array(
							'name'        => __( 'User Chats Block', 'wp-mcp-ai' ),
							'slug'        => 'wp-mcp-ai/dashboard-user-chats',
							'description' => __( 'Display recent chats', 'wp-mcp-ai' ),
							'icon'        => 'list-view',
						),
						array(
							'name'        => __( 'Theme Preview Block', 'wp-mcp-ai' ),
							'slug'        => 'wp-mcp-ai/dashboard-theme-preview',
							'description' => __( 'Preview theme colors', 'wp-mcp-ai' ),
							'icon'        => 'color-picker',
						),
						array(
							'name'        => __( 'Provider Links Block', 'wp-mcp-ai' ),
							'slug'        => 'wp-mcp-ai/dashboard-provider-links',
							'description' => __( 'Links to AI providers', 'wp-mcp-ai' ),
							'icon'        => 'admin-links',
						),
						array(
							'name'        => __( 'Activity Feed Block', 'wp-mcp-ai' ),
							'slug'        => 'wp-mcp-ai/dashboard-activity-feed',
							'description' => __( 'Display recent activity', 'wp-mcp-ai' ),
							'icon'        => 'feedback',
						),
					),
				),
				'performance' => array(
					'title'  => __( 'Performance Blocks', 'wp-mcp-ai' ),
					'blocks' => array(
						array(
							'name'        => __( 'Performance Metrics Block', 'wp-mcp-ai' ),
							'slug'        => 'wp-mcp-ai/performance-metrics',
							'description' => __( 'Display performance metrics', 'wp-mcp-ai' ),
							'icon'        => 'performance',
						),
					),
				),
			);

			return $blocks;
		}

		/**
		 * Render the displays dashboard page.
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-mcp-ai' ) );
			}

			$elementor_widgets = $this->get_elementor_widgets();
			$gutenberg_blocks  = $this->get_gutenberg_blocks();
			$elementor_active  = class_exists( '\\Elementor\\Plugin' );

			?>
			<div class="wrap wp-mcp-ai-displays-dashboard">
				<h1><?php esc_html_e( 'Displays Dashboard', 'wp-mcp-ai' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'Manage and explore all available display widgets and blocks for WP oOS. Use these components to build custom dashboards, chat interfaces, and monitoring displays.', 'wp-mcp-ai' ); ?>
				</p>

				<div class="wp-mcp-ai-displays-actions" style="margin: 20px 0; display: flex; gap: 10px; flex-wrap: wrap;">
					<?php if ( $elementor_active ) : ?>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=elementor_library' ) ); ?>" class="button button-primary">
							<span class="dashicons dashicons-plus-alt" style="margin-top: 3px;"></span>
							<?php esc_html_e( 'Create New Elementor Template', 'wp-mcp-ai' ); ?>
						</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=page' ) ); ?>" class="button button-secondary">
						<span class="dashicons dashicons-edit" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Create New Page', 'wp-mcp-ai' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=overview' ) ); ?>" class="button button-secondary">
						<span class="dashicons dashicons-admin-generic" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Settings', 'wp-mcp-ai' ); ?>
					</a>
					<a href="<?php echo esc_url( 'https://github.com/nvdigitalsolutions/wp-mcp-ai/blob/main/docs/DOCUMENTATION_INDEX.md' ); ?>" class="button button-secondary" target="_blank">
						<span class="dashicons dashicons-book" style="margin-top: 3px;"></span>
						<?php esc_html_e( 'Documentation', 'wp-mcp-ai' ); ?>
					</a>
				</div>

				<!-- Search and Filter -->
				<div class="wp-mcp-ai-displays-filter" style="margin: 20px 0; background: #fff; padding: 15px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
					<label for="wp-mcp-ai-search-displays" style="font-weight: 600;">
						<?php esc_html_e( 'Search Displays:', 'wp-mcp-ai' ); ?>
					</label>
					<input type="text" id="wp-mcp-ai-search-displays" class="regular-text" placeholder="<?php esc_attr_e( 'Search by name or description...', 'wp-mcp-ai' ); ?>" style="margin-left: 10px;" />
					<button type="button" class="button" id="wp-mcp-ai-clear-search" style="margin-left: 10px;">
						<?php esc_html_e( 'Clear', 'wp-mcp-ai' ); ?>
					</button>
				</div>

				<!-- Elementor Widgets Section -->
				<div class="wp-mcp-ai-displays-section">
					<div style="background: <?php echo esc_attr( $elementor_active ? '#d5f0db' : '#f0f0f1' ); ?>; border-left: 4px solid <?php echo esc_attr( $elementor_active ? '#0a5f1a' : '#646970' ); ?>; padding: 1.5rem; margin: 1.5rem 0;">
						<?php if ( $elementor_active ) : ?>
							<h2 style="margin-top: 0; color: #0a5f1a;">
								<span class="dashicons dashicons-yes-alt" style="font-size: 24px; vertical-align: middle;"></span>
								<?php esc_html_e( 'Elementor Widgets (Active)', 'wp-mcp-ai' ); ?>
							</h2>
							<p><?php esc_html_e( 'Elementor is active. All widgets below are available in the Elementor page builder.', 'wp-mcp-ai' ); ?></p>
						<?php else : ?>
							<h2 style="margin-top: 0; color: #646970;">
								<?php esc_html_e( 'Elementor Widgets (Elementor Not Active)', 'wp-mcp-ai' ); ?>
							</h2>
							<p><?php esc_html_e( 'Install and activate Elementor to use these widgets. Visit', 'wp-mcp-ai' ); ?> <a href="https://elementor.com/" target="_blank"><?php esc_html_e( 'Elementor website', 'wp-mcp-ai' ); ?></a></p>
						<?php endif; ?>
					</div>

					<?php foreach ( $elementor_widgets as $category_key => $category ) : ?>
						<div class="wp-mcp-ai-widget-category" data-category="<?php echo esc_attr( $category_key ); ?>">
							<h3><?php echo esc_html( $category['title'] ); ?></h3>
							<div class="wp-mcp-ai-widgets-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
								<?php foreach ( $category['widgets'] as $widget ) : ?>
									<div class="wp-mcp-ai-widget-card" data-searchable="<?php echo esc_attr( strtolower( $widget['name'] . ' ' . $widget['description'] ) ); ?>" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-radius: 4px;">
										<div style="display: flex; align-items: center; margin-bottom: 10px;">
											<span class="dashicons dashicons-<?php echo esc_attr( str_replace( 'eicon-', '', $widget['icon'] ) ); ?>" style="font-size: 32px; color: #2271b1; margin-right: 15px;"></span>
											<div>
												<h4 style="margin: 0; font-size: 16px;"><?php echo esc_html( $widget['name'] ); ?></h4>
												<code style="font-size: 11px; color: #646970;"><?php echo esc_html( $widget['slug'] ); ?></code>
											</div>
										</div>
										<p style="margin: 10px 0 0 0; color: #646970; font-size: 14px;">
											<?php echo esc_html( $widget['description'] ); ?>
										</p>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Gutenberg Blocks Section -->
				<div class="wp-mcp-ai-displays-section" style="margin-top: 40px;">
					<div style="background: #d5f0db; border-left: 4px solid #0a5f1a; padding: 1.5rem; margin: 1.5rem 0;">
						<h2 style="margin-top: 0; color: #0a5f1a;">
							<span class="dashicons dashicons-yes-alt" style="font-size: 24px; vertical-align: middle;"></span>
							<?php esc_html_e( 'Gutenberg Blocks (Active)', 'wp-mcp-ai' ); ?>
						</h2>
						<p><?php esc_html_e( 'These blocks are available in the WordPress block editor (Gutenberg) for any page or post.', 'wp-mcp-ai' ); ?></p>
					</div>

					<?php foreach ( $gutenberg_blocks as $category_key => $category ) : ?>
						<div class="wp-mcp-ai-block-category" data-category="<?php echo esc_attr( $category_key ); ?>">
							<h3><?php echo esc_html( $category['title'] ); ?></h3>
							<div class="wp-mcp-ai-blocks-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px;">
								<?php foreach ( $category['blocks'] as $block ) : ?>
									<div class="wp-mcp-ai-block-card" data-searchable="<?php echo esc_attr( strtolower( $block['name'] . ' ' . $block['description'] ) ); ?>" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-radius: 4px;">
										<div style="display: flex; align-items: center; margin-bottom: 10px;">
											<span class="dashicons dashicons-<?php echo esc_attr( $block['icon'] ); ?>" style="font-size: 32px; color: #2271b1; margin-right: 15px;"></span>
											<div>
												<h4 style="margin: 0; font-size: 16px;"><?php echo esc_html( $block['name'] ); ?></h4>
												<code style="font-size: 11px; color: #646970;"><?php echo esc_html( $block['slug'] ); ?></code>
											</div>
										</div>
										<p style="margin: 10px 0 0 0; color: #646970; font-size: 14px;">
											<?php echo esc_html( $block['description'] ); ?>
										</p>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<!-- Usage Guide Section -->
				<div class="wp-mcp-ai-displays-guide" style="margin-top: 40px; background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1.5rem;">
					<h2 style="margin-top: 0;"><?php esc_html_e( 'Usage Guide', 'wp-mcp-ai' ); ?></h2>
					<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
						<div>
							<h3><?php esc_html_e( 'Using Elementor Widgets', 'wp-mcp-ai' ); ?></h3>
							<ol>
								<li><?php esc_html_e( 'Create or edit a page with Elementor', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Search for "WP oOS" or the widget name in the Elementor panel', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Drag and drop the widget onto your page', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Configure widget settings in the left panel', 'wp-mcp-ai' ); ?></li>
							</ol>
						</div>
						<div>
							<h3><?php esc_html_e( 'Using Gutenberg Blocks', 'wp-mcp-ai' ); ?></h3>
							<ol>
								<li><?php esc_html_e( 'Create or edit a page or post', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Click the "+" button to add a block', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Search for "WP oOS" or the block name', 'wp-mcp-ai' ); ?></li>
								<li><?php esc_html_e( 'Click to insert the block and configure settings', 'wp-mcp-ai' ); ?></li>
							</ol>
						</div>
						<div>
							<h3><?php esc_html_e( 'Quick Links', 'wp-mcp-ai' ); ?></h3>
							<ul>
								<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>"><?php esc_html_e( 'Manage Assistants', 'wp-mcp-ai' ); ?></a></li>
								<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>"><?php esc_html_e( 'View Available Tools', 'wp-mcp-ai' ); ?></a></li>
								<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-elementor' ) ); ?>"><?php esc_html_e( 'Elementor Integration Settings', 'wp-mcp-ai' ); ?></a></li>
								<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-jetengine' ) ); ?>"><?php esc_html_e( 'JetEngine Integration Settings', 'wp-mcp-ai' ); ?></a></li>
							</ul>
						</div>
					</div>
				</div>
			</div>
			<?php
		}
	}
}
