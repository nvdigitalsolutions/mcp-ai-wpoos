<?php
/**
 * Research & Add admin page for Fantasy Football CPT.
 *
 * Provides a dedicated page for researching fantasy football topics before creating teams,
 * with full chat interface for AI assistance and multiple data input methods.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Fantasy Football Research Admin Page
 *
 * Adds a submenu page under Fantasy Football menu for AI-powered research.
 */
class WP_MCP_AI_Fantasy_Football_Research_Page {
	use WP_MCP_AI_Research_Page_Featured_Image;
	use WP_MCP_AI_Research_Page_Import_Handler;
	use WP_MCP_AI_Research_Page_Consolidation;
	use WP_MCP_AI_Research_Page_Data_Validation;

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
		add_action( 'wp_ajax_wp_mcp_ai_create_ff_team_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_ff_team', array( __CLASS__, 'ajax_handle_import' ) );
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_ff_team' ),
				'entityType' => 'ff_team',
			)
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
					<!-- Workflow Mode Selector -->
					<div class="wp-mcp-ai-workflow-selector">
						<h2><?php esc_html_e( 'Choose Your Workflow', 'mcp-ai-wpoos-pro' ); ?></h2>
						<div class="workflow-options">
							<button type="button" class="workflow-option active" data-workflow="research">
								<span class="dashicons dashicons-format-chat"></span>
								<strong><?php esc_html_e( 'AI Research', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Research players and analyze trades with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import team and player data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="yahoo">
								<span class="dashicons dashicons-networking"></span>
								<strong><?php esc_html_e( 'Yahoo Sync', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Sync data from Yahoo Fantasy Sports', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Stats', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View team performance and league stats', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
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
							// Render the chat interface using shortcode with comprehensive fantasy football tools.
							$ff_tools = array(
								// Yahoo Fantasy Football API tools.
								'yahoo_ff_auth',
								'yahoo_ff_get_leagues',
								'yahoo_ff_league_standings',
								'yahoo_ff_get_roster',
								'yahoo_ff_get_player_stats',
								'yahoo_ff_trade_analyzer',
								// ESPN Fantasy Football API tools.
								'espn_fantasy_get_league',
								'espn_fantasy_get_roster',
								'espn_fantasy_get_standings',
								'espn_fantasy_get_teams',
								'espn_fantasy_analyze_lineup',
								'espn_fantasy_sync_league',
								// Fantasy Football specific tools.
								'ff_generate_team_logo',
								'ff_create_league_report',
								'ff_player_research',
								// General research tools.
								'web_search',
								'deep_research',
								'search_content',
								'semantic_content_search',
							);
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="' . esc_attr( implode( ',', $ff_tools ) ) . '" height="600" show_title="false"]'
							);
						}
						?>
					</div>

					<!-- Import Data Workflow -->
					<div id="workflow-import" class="workflow-content">
						<?php self::render_import_workflow(); ?>
					</div>

					<!-- Yahoo Sync Workflow -->
					<div id="workflow-yahoo" class="workflow-content">
						<?php self::render_yahoo_sync_workflow(); ?>
					</div>

					<!-- Review & Stats Workflow -->
					<div id="workflow-review" class="workflow-content">
						<?php self::render_review_workflow(); ?>
					</div>
				</div>
			</div>
		<?php
	}

	/**
	 * Render import workflow.
	 */
	protected static function render_import_workflow() {
		?>
		<h2><?php esc_html_e( 'Import Fantasy Football Data', 'mcp-ai-wpoos-pro' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Import team and player data from CSV, JSON, or other formatted sources.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
		self::render_import_section();
	}

	/**
	 * Render Yahoo sync workflow.
	 */
	protected static function render_yahoo_sync_workflow() {
		?>
		<h2><?php esc_html_e( 'Yahoo Fantasy Sports Sync', 'mcp-ai-wpoos-pro' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Connect to Yahoo Fantasy Sports to automatically sync your leagues, rosters, and player data.', 'mcp-ai-wpoos-pro' ); ?>
		</p>

		<div class="wp-mcp-ai-yahoo-sync-container">
			<div class="sync-status">
				<h3><?php esc_html_e( 'Connection Status', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<?php
					$connection_status = get_option( 'wp_mcp_ai_yahoo_sports_connection', array() );
					if ( ! empty( $connection_status['access_token'] ) ) {
						echo '<span class="dashicons dashicons-yes-alt" style="color: green;"></span> ';
						esc_html_e( 'Connected to Yahoo Fantasy Sports', 'mcp-ai-wpoos-pro' );
					} else {
						echo '<span class="dashicons dashicons-warning" style="color: orange;"></span> ';
						esc_html_e( 'Not connected to Yahoo Fantasy Sports', 'mcp-ai-wpoos-pro' );
					}
					?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=yahoo_sports' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Configure Yahoo Connection', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>

			<div class="sync-instructions">
				<h3><?php esc_html_e( 'How to Sync', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ol>
					<li><?php esc_html_e( 'Configure your Yahoo API credentials in the Connections settings', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Use the AI Assistant in the "AI Research" tab to authenticate', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Ask the AI to "Get my Yahoo Fantasy leagues" or "Show my roster"', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'The AI will automatically sync your data', 'mcp-ai-wpoos-pro' ); ?></li>
				</ol>
			</div>

			<div class="sync-tips">
				<h3><?php esc_html_e( 'Sync Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Ensure your Yahoo app has proper permissions', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Sync data regularly to keep rosters up to date', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Use the AI to analyze recent trades and player movements', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Render review workflow.
	 */
	protected static function render_review_workflow() {
		?>
		<h2><?php esc_html_e( 'Review Teams & Stats', 'mcp-ai-wpoos-pro' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'View team performance, league statistics, and data quality metrics.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
		self::render_consolidation_dashboard();
	}

	/**
	 * Get supported import formats for Fantasy Football.
	 *
	 * @return array Import formats.
	 */
	protected static function get_import_formats() {
		return array(
			'csv'  => 'CSV',
			'json' => 'JSON',
			'xlsx' => 'Excel',
		);
	}

	/**
	 * Process imported data.
	 *
	 * @param string $data   Import data.
	 * @param string $format Data format.
	 * @return array|WP_Error Result or error.
	 */
	protected static function process_import_data( $data, $format ) {
		if ( empty( $data ) ) {
			return new WP_Error( 'empty_data', __( 'No data provided', 'mcp-ai-wpoos-pro' ) );
		}

		// Parse the data based on format.
		$parsed_data = array();
		switch ( $format ) {
			case 'csv':
				$parsed_data = self::parse_csv_data( $data );
				break;
			case 'json':
				$parsed_data = json_decode( $data, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					return new WP_Error( 'invalid_json', __( 'Invalid JSON data', 'mcp-ai-wpoos-pro' ) );
				}
				break;
			default:
				return new WP_Error( 'unsupported_format', __( 'Unsupported format', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $parsed_data ) ) {
			return new WP_Error( 'parse_failed', __( 'Failed to parse data', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success' => true,
			'count'   => count( $parsed_data ),
			'data'    => $parsed_data,
			'message' => sprintf(
				/* translators: %d: Number of items parsed */
				__( 'Successfully parsed %d items', 'mcp-ai-wpoos-pro' ),
				count( $parsed_data )
			),
		);
	}

	/**
	 * Parse CSV data.
	 *
	 * @param string $data CSV data.
	 * @return array Parsed data.
	 */
	protected static function parse_csv_data( $data ) {
		$rows   = str_getcsv( $data, "\n" );
		$header = str_getcsv( array_shift( $rows ) );
		$result = array();

		foreach ( $rows as $row ) {
			$row_data = str_getcsv( $row );
			if ( count( $row_data ) === count( $header ) ) {
				$result[] = array_combine( $header, $row_data );
			}
		}

		return $result;
	}

	/**
	 * Calculate completeness for Fantasy Football teams.
	 *
	 * @return array Completeness metrics.
	 */
	protected static function calculate_completeness() {
		$total_teams = wp_count_posts( 'ff_team' )->publish;

		return array(
			'total'      => $total_teams,
			'complete'   => $total_teams, // All published teams are considered complete.
			'percentage' => 100,
		);
	}

	/**
	 * Handle AJAX request to create fantasy football team from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_ff_team', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create teams.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) || empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Create the fantasy football team post.
		$post_data = array(
			'post_title'   => sanitize_text_field( $research_data['title'] ),
			'post_content' => isset( $research_data['description'] ) ? wp_kses_post( $research_data['description'] ) : '',
			'post_status'  => 'publish',
			'post_type'    => 'ff_team',
		);

		$team_id = wp_insert_post( $post_data );

		if ( is_wp_error( $team_id ) ) {
			wp_send_json_error( array( 'message' => $team_id->get_error_message() ) );
		}

		// Add team metadata if provided.
		if ( ! empty( $research_data['league_id'] ) ) {
			update_post_meta( $team_id, 'league_id', sanitize_text_field( $research_data['league_id'] ) );
		}

		if ( ! empty( $research_data['team_key'] ) ) {
			update_post_meta( $team_id, 'team_key', sanitize_text_field( $research_data['team_key'] ) );
		}

		// Return success with team ID and edit URL.
		$edit_url = admin_url( 'post.php?post=' . $team_id . '&action=edit' );

		wp_send_json_success(
			array(
				'message'  => __( 'Fantasy football team created successfully!', 'mcp-ai-wpoos-pro' ),
				'team_id'  => $team_id,
				'edit_url' => $edit_url,
			)
		);
	}
}

