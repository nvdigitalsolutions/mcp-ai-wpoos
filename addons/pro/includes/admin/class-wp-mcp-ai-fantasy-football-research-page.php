<?php
/**
 * Research & Add admin page for Fantasy Football CPT.
 *
 * Provides a dedicated page for researching fantasy football topics before creating teams,
 * with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fantasy Football Research Admin Page
 *
 * Adds a submenu page under Fantasy Football menu for AI-powered research.
 */
class WP_MCP_AI_Fantasy_Football_Research_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'research-fantasy-football';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add submenu page under Fantasy Football menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=ff_team',
			__( 'Research & Add', 'mcp-ai-wpoos-pro' ),
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
		if ( 'ff_team_page_' . self::PAGE_SLUG !== $hook ) {
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
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_fantasy_football_settings', array() );
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
				<?php esc_html_e( 'Fantasy Football Research & Add', 'mcp-ai-wpoos-pro' ); ?>
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
							<li><?php esc_html_e( 'Authenticate with Yahoo Fantasy Sports API', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Search leagues, rosters, and player stats', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Use AI to analyze trades and generate reports', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create team branding and manage fantasy teams', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Start with auth:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Authenticate with Yahoo Fantasy Sports first', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Get player stats:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Research players before making trade decisions', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Analyze trades:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Use AI to evaluate trade proposals', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Generate reports:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Create league reports with AI analysis', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Get my Yahoo Fantasy leagues">
								<?php esc_html_e( '"Get my leagues"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Show me my current roster">
								<?php esc_html_e( '"Show my roster"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Get stats for Patrick Mahomes">
								<?php esc_html_e( '"Get player stats..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Analyze trading Travis Kelce for CeeDee Lamb">
								<?php esc_html_e( '"Analyze trade..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ff_team' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Teams', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=ff_team' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Team Manually', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ff_team&page=fantasy-football-settings' ) ); ?>" class="button">
								<?php esc_html_e( 'Settings', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-research-main">
					<h2><?php esc_html_e( 'AI Research Assistant', 'mcp-ai-wpoos-pro' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Use the AI assistant below to research fantasy football topics, analyze trades, get player stats, and generate team reports.', 'mcp-ai-wpoos-pro' ); ?>
					</p>

					<?php
					if ( ! $assistant_id ) {
						?>
						<div class="notice notice-error">
							<p>
								<?php
								printf(
									/* translators: %s: Assistant creation link */
									esc_html__( 'No assistant configured. Please %s or configure one in the settings.', 'mcp-ai-wpoos-pro' ),
									'<a href="' . esc_url( admin_url( 'post-new.php?post_type=mcp_ai_assistant' ) ) . '">' . esc_html__( 'create an assistant', 'mcp-ai-wpoos-pro' ) . '</a>'
								);
								?>
							</p>
						</div>
						<?php
					} else {
						// Render the chat interface using shortcode.
						echo do_shortcode( '[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" height="600" show_title="false"]' );
					}
					?>
				</div>
			</div>
		<?php
	}
}
