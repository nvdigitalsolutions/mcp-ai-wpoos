<?php
/**
 * Build Assistant Page.
 *
 * Admin page for building assistants with a tabbed interface.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Build Assistant page in admin.
 */
class WP_MCP_AI_Build_Assistant_Page {
	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	protected $page_hook;

	/**
	 * Initialize the page.
	 */
	public static function init() {
		$instance = new self();
		add_action( 'admin_menu', array( $instance, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue_scripts' ) );
	}

	/**
	 * Register the admin page.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'edit.php?post_type=mcp_ai_assistant',
			__( 'Build Assistant', 'wp-mcp-ai' ),
			__( 'Build Assistant', 'wp-mcp-ai' ),
			'edit_posts',
			'wp-mcp-ai-build-assistant',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts and styles for this page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-build-assistant',
			WP_MCP_AI_URL . 'assets/css/admin-build-assistant.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-build-assistant',
			WP_MCP_AI_URL . 'assets/js/admin-build-assistant.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);
	}

	/**
	 * Get the currently active tab.
	 *
	 * @return string Active tab ID.
	 */
	private function get_active_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter check.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'configuration';

		$valid_tabs = array( 'configuration', 'advanced' );
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'configuration';
		}

		return $tab;
	}

	/**
	 * Get tab definitions.
	 *
	 * @return array
	 */
	private function get_tabs() {
		return array(
			'configuration' => array(
				'title' => __( 'Configuration', 'wp-mcp-ai' ),
				'icon'  => 'dashicons-admin-settings',
			),
			'advanced'      => array(
				'title' => __( 'Advanced', 'wp-mcp-ai' ),
				'icon'  => 'dashicons-admin-generic',
			),
		);
	}

	/**
	 * Render the page content.
	 */
	public function render_page() {
		$active_tab = $this->get_active_tab();
		$tabs       = $this->get_tabs();

		?>
		<div class="wrap wp-mcp-ai-build-assistant-page">
			<h1><?php esc_html_e( 'Build Assistant', 'wp-mcp-ai' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure and build custom AI assistants with advanced settings and options.', 'wp-mcp-ai' ); ?>
			</p>

			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Build Assistant tabs', 'wp-mcp-ai' ); ?>">
				<?php foreach ( $tabs as $tab_id => $tab ) : ?>
					<?php
					$tab_url = add_query_arg(
						array(
							'post_type' => 'mcp_ai_assistant',
							'page'      => 'wp-mcp-ai-build-assistant',
							'tab'       => $tab_id,
						),
						admin_url( 'edit.php' )
					);
					$active  = ( $tab_id === $active_tab ) ? 'nav-tab-active' : '';
					?>
					<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo esc_attr( $active ); ?>">
						<span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
						<?php echo esc_html( $tab['title'] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="tab-content">
				<?php
				if ( 'configuration' === $active_tab ) {
					$this->render_configuration_tab();
				} elseif ( 'advanced' === $active_tab ) {
					$this->render_advanced_tab();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Configuration tab content.
	 */
	private function render_configuration_tab() {
		?>
		<div class="wp-mcp-ai-tab-content wp-mcp-ai-configuration-tab">
			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Assistant Configuration', 'wp-mcp-ai' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Configure the basic settings for your AI assistant.', 'wp-mcp-ai' ); ?></p>

				<div class="wp-mcp-ai-config-grid">
					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-format-chat"></span>
						<h3><?php esc_html_e( 'Create from Template', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'Create a new assistant using a professional template with pre-configured settings.', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-add-assistant' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Use Template', 'wp-mcp-ai' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-plus-alt"></span>
						<h3><?php esc_html_e( 'Create Custom', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'Create a new custom assistant from scratch with your own configuration.', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_assistant' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Add New', 'wp-mcp-ai' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-list-view"></span>
						<h3><?php esc_html_e( 'Manage Assistants', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'View and manage all existing AI assistants in your system.', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'View All', 'wp-mcp-ai' ); ?>
						</a>
					</div>
				</div>
			</div>

			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Quick Statistics', 'wp-mcp-ai' ); ?></h2>
				<?php $this->render_assistant_stats(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Advanced tab content.
	 */
	private function render_advanced_tab() {
		?>
		<div class="wp-mcp-ai-tab-content wp-mcp-ai-advanced-tab">
			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Advanced Settings', 'wp-mcp-ai' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Advanced configuration options for power users.', 'wp-mcp-ai' ); ?></p>

				<div class="wp-mcp-ai-config-grid">
					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-admin-users"></span>
						<h3><?php esc_html_e( 'Professional Templates', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'Manage professional templates that define roles, tools, and knowledge bases for assistants.', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Manage Templates', 'wp-mcp-ai' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-groups"></span>
						<h3><?php esc_html_e( 'Teams', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'Create teams of assistants that can work together on complex tasks.', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_team' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Manage Teams', 'wp-mcp-ai' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-admin-tools"></span>
						<h3><?php esc_html_e( 'Tools & Features', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'Configure available tools and features that assistants can use.', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Configure Tools', 'wp-mcp-ai' ); ?>
						</a>
					</div>

					<div class="wp-mcp-ai-config-card">
						<span class="dashicons dashicons-admin-generic"></span>
						<h3><?php esc_html_e( 'AI Providers', 'wp-mcp-ai' ); ?></h3>
						<p><?php esc_html_e( 'Configure API keys and settings for AI providers (OpenAI, Anthropic, Gemini, etc.).', 'wp-mcp-ai' ); ?></p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=providers' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Configure Providers', 'wp-mcp-ai' ); ?>
						</a>
					</div>
				</div>
			</div>

			<div class="wp-mcp-ai-section">
				<h2><?php esc_html_e( 'Documentation', 'wp-mcp-ai' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s: URL to documentation */
						esc_html__( 'For detailed documentation on building and configuring assistants, visit the %s.', 'wp-mcp-ai' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=overview' ) ) . '">' . esc_html__( 'Overview page', 'wp-mcp-ai' ) . '</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render assistant statistics.
	 */
	private function render_assistant_stats() {
		$assistants_count  = wp_count_posts( 'mcp_ai_assistant' );
		$professions_count = wp_count_posts( 'mcp_ai_profession' );
		$teams_count       = wp_count_posts( 'mcp_ai_team' );

		$published_assistants  = isset( $assistants_count->publish ) ? $assistants_count->publish : 0;
		$published_professions = isset( $professions_count->publish ) ? $professions_count->publish : 0;
		$published_teams       = isset( $teams_count->publish ) ? $teams_count->publish : 0;
		?>
		<div class="wp-mcp-ai-stats-grid">
			<div class="wp-mcp-ai-stat-card">
				<span class="wp-mcp-ai-stat-number"><?php echo esc_html( $published_assistants ); ?></span>
				<span class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Active Assistants', 'wp-mcp-ai' ); ?></span>
			</div>
			<div class="wp-mcp-ai-stat-card">
				<span class="wp-mcp-ai-stat-number"><?php echo esc_html( $published_professions ); ?></span>
				<span class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Professional Templates', 'wp-mcp-ai' ); ?></span>
			</div>
			<div class="wp-mcp-ai-stat-card">
				<span class="wp-mcp-ai-stat-number"><?php echo esc_html( $published_teams ); ?></span>
				<span class="wp-mcp-ai-stat-label"><?php esc_html_e( 'Teams', 'wp-mcp-ai' ); ?></span>
			</div>
		</div>
		<?php
	}
}
