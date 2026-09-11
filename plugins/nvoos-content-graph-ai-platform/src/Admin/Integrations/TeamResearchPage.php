<?php
/**
 * Team research page (Wave E-UI-3, sub-cluster 2).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Admin_Team_Research_Page`
 * (`includes/admin/class-wp-mcp-ai-admin-team-research-page.php`):
 * byte-identical page surface — the `research-team` page slug
 * (position 15 under the team CPT menu, `edit_posts`), the
 * fully-static contract (`init()`/`add_menu_page()`/`enqueue_assets()`/
 * `render_page()`), the how-it-works/research-tips/example-query/quick-
 * action sidebar (incl. the View Professions cross-link), the
 * three-workflow selector (AI research with the `[mcp_ai_chat]` embed
 * + no-assistant notice, import, review), the import form (nonce
 * field, file/text input, auto-create/validate options), the review
 * quality dashboard (completeness bar, members/orchestration metrics,
 * sub-80% warning), and the `wpMcpAiResearchPage` localized envelope
 * (ajaxUrl/nonce/entityType=team).
 *
 * Documented deviations:
 *  - Class name/namespace — the platform addon's PSR-4 tree (decision
 *    D-UI/E-UI: operator admin UI ports land in
 *    `nvoos-content-graph-ai-platform` under `Admin\Integrations\`
 *    (the E-UI-3 wave folder).
 *  - Wiring is invoked standalone-only via
 *    `Plugin::registerIntegrationsScreens()`; the base loader owns the
 *    same page monolith (eager `init()`).
 *  - Collaborators resolve per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator — never bare
 *    `class_exists()` for base-owned classes): the team post type via
 *    the base `WP_MCP_AI_Team_CPT` monolith / the platform's
 *    `Teams\TeamCpt` standalone (byte-identical `POST_TYPE`); the chat
 *    shortcode via the base `WP_MCP_AI_Shortcode` monolith / null
 *    standalone (the chat UI is CG-AI-owned — its assets are skipped
 *    standalone, documented forward-reference).
 *  - The page's own assets (enhanced-research-page.css/js) are shared
 *    byte-identically with the profession research page (landed in
 *    sub-cluster 1); versioning resolves through the platform's
 *    per-file asset seam.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Integrations
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Integrations;

/**
 * Team Research admin page (fully static contract).
 *
 * @since 2.0.0
 */
class TeamResearchPage {

	/**
	 * Page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'research-team';

	/**
	 * Initialize the page.
	 *
	 * @return void
	 */
	public static function init(): void {
		\add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		\add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Team post type slug (per-mode seam).
	 *
	 * @return string
	 */
	protected static function team_post_type() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Team_CPT' ) ) {
			return \WP_MCP_AI_Team_CPT::POST_TYPE;
		}

		if ( \class_exists( 'NvoosContentGraphAiPlatform\Teams\TeamCpt' ) ) {
			return \NvoosContentGraphAiPlatform\Teams\TeamCpt::POST_TYPE;
		}

		return 'mcp_ai_team';
	}

	/**
	 * Chat shortcode class name (per-mode seam).
	 *
	 * Base-owned; standalone skips the chat asset registration (the chat
	 * UI is CG-AI-owned — documented forward-reference).
	 *
	 * @return string|null
	 */
	protected static function shortcode_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			return 'WP_MCP_AI_Shortcode';
		}

		return null;
	}

	/**
	 * Asset URL for the platform's local copies (per-mode seam).
	 *
	 * @param string $relative_path Asset path relative to the platform assets dir.
	 * @return string
	 */
	protected static function asset_url( $relative_path ) {
		return NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/' . \ltrim( $relative_path, '/' );
	}

	/**
	 * Asset version for the platform's local copies (per-file mtime).
	 *
	 * @param string $relative_path Asset path relative to the platform assets dir.
	 * @return string
	 */
	protected static function asset_version( $relative_path ) {
		$absolute_path = NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'assets/' . \ltrim( $relative_path, '/' );

		if ( \file_exists( $absolute_path ) ) {
			$modified = \filemtime( $absolute_path );
			if ( $modified ) {
				return NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION . '.' . $modified;
			}
		}

		return NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION;
	}

	/**
	 * Add submenu page under Teams menu.
	 *
	 * @return void
	 */
	public static function add_menu_page(): void {
		\add_submenu_page(
			'edit.php?post_type=' . self::team_post_type(),
			__( 'Research & Add Team', 'nvoos-content-graph-ai-platform' ),
			__( 'Research & Add', 'nvoos-content-graph-ai-platform' ),
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
	 * @return void
	 */
	public static function enqueue_assets( $hook ): void {
		// Only load on our research page.
		if ( self::team_post_type() . '_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets (monolith-only — see the class docblock).
		$shortcode_class = self::shortcode_class();
		if ( null !== $shortcode_class ) {
			$shortcode_instance = new $shortcode_class();
			$shortcode_instance->register_assets();
			\wp_enqueue_style( $shortcode_class::STYLE_HANDLE );
			\wp_enqueue_script( $shortcode_class::SCRIPT_HANDLE );
		}

		// Enqueue enhanced research page styles (shared with the
		// profession research page — sub-cluster 1).
		\wp_enqueue_style(
			'wp-mcp-ai-enhanced-research-page',
			self::asset_url( 'css/enhanced-research-page.css' ),
			array(),
			self::asset_version( 'css/enhanced-research-page.css' )
		);

		// Enqueue enhanced research page script.
		\wp_enqueue_script(
			'wp-mcp-ai-enhanced-research-page',
			self::asset_url( 'js/enhanced-research-page.js' ),
			array( 'jquery' ),
			self::asset_version( 'js/enhanced-research-page.js' ),
			true
		);

		// Localize script.
		\wp_localize_script(
			'wp-mcp-ai-enhanced-research-page',
			'wpMcpAiResearchPage',
			array(
				'ajaxUrl'    => \admin_url( 'admin-ajax.php' ),
				'nonce'      => \wp_create_nonce( 'wp_mcp_ai_research_page' ),
				'entityType' => 'team',
			)
		);
	}

	/**
	 * Render the research page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		$post_type = self::team_post_type();

		// Get assistant - try team settings first, then first available.
		$settings     = \get_option( 'wp_mcp_ai_team_settings', array() );
		$assistant_id = isset( $settings['assistant_id'] ) ? \absint( $settings['assistant_id'] ) : 0;

		// If no assistant configured or invalid, get the first available assistant.
		if ( ! $assistant_id || 'publish' !== \get_post_status( $assistant_id ) ) {
			$assistants = \get_posts(
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
				<?php \esc_html_e( 'Research & Add Team', 'nvoos-content-graph-ai-platform' ); ?>
			</h1>

			<hr class="wp-header-end">

			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-research-intro">
						<h2><?php \esc_html_e( 'How It Works', 'nvoos-content-graph-ai-platform' ); ?></h2>
						<ol>
							<li><?php \esc_html_e( 'Search existing teams and professions', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><?php \esc_html_e( 'Research team composition and role distribution', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><?php \esc_html_e( 'Define orchestration mode (sequential, parallel, swarm)', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><?php \esc_html_e( 'Create teams with proper member assignments', 'nvoos-content-graph-ai-platform' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php \esc_html_e( 'Research Tips', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<ul>
							<li><strong><?php \esc_html_e( 'Search first:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Check existing teams to avoid duplicates', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><strong><?php \esc_html_e( 'Role balance:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Mix planners, executors, and critics for best results', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><strong><?php \esc_html_e( 'Orchestration:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Choose mode based on task complexity', 'nvoos-content-graph-ai-platform' ); ?></li>
							<li><strong><?php \esc_html_e( 'Aggregation:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'Define how team results should be combined', 'nvoos-content-graph-ai-platform' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php \esc_html_e( 'Example Queries', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research a software development team with roles for planning, coding, and quality assurance">
								<?php \esc_html_e( '"Research a software development team..."', 'nvoos-content-graph-ai-platform' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a content creation team with writers, editors, and SEO specialists working in parallel">
								<?php \esc_html_e( '"Create a content creation team..."', 'nvoos-content-graph-ai-platform' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research a product launch team with sequential workflow from planning to execution to review">
								<?php \esc_html_e( '"Research a product launch team..."', 'nvoos-content-graph-ai-platform' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php \esc_html_e( 'Quick Actions', 'nvoos-content-graph-ai-platform' ); ?></h3>
						<p>
							<a href="<?php echo \esc_url( \admin_url( 'edit.php?post_type=' . $post_type ) ); ?>" class="button">
								<?php \esc_html_e( 'View All Teams', 'nvoos-content-graph-ai-platform' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo \esc_url( \admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>" class="button">
								<?php \esc_html_e( 'Add Team Manually', 'nvoos-content-graph-ai-platform' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo \esc_url( \admin_url( 'edit.php?post_type=' . $post_type . '&page=test-team' ) ); ?>" class="button">
								<?php \esc_html_e( 'Test Team', 'nvoos-content-graph-ai-platform' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo \esc_url( \admin_url( 'edit.php?post_type=mcp_ai_profession' ) ); ?>" class="button button-secondary">
								<?php \esc_html_e( 'View Professions', 'nvoos-content-graph-ai-platform' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-research-main">
					<!-- Workflow Mode Selector -->
					<div class="wp-mcp-ai-workflow-selector">
						<h2><?php \esc_html_e( 'Choose Your Workflow', 'nvoos-content-graph-ai-platform' ); ?></h2>
						<div class="workflow-options">
							<button type="button" class="workflow-option active" data-workflow="research">
								<span class="dashicons dashicons-format-chat"></span>
								<strong><?php \esc_html_e( 'AI Research', 'nvoos-content-graph-ai-platform' ); ?></strong>
								<p><?php \esc_html_e( 'Research teams with AI assistance', 'nvoos-content-graph-ai-platform' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php \esc_html_e( 'Import Data', 'nvoos-content-graph-ai-platform' ); ?></strong>
								<p><?php \esc_html_e( 'Bulk import team configurations', 'nvoos-content-graph-ai-platform' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php \esc_html_e( 'Review & Quality', 'nvoos-content-graph-ai-platform' ); ?></strong>
								<p><?php \esc_html_e( 'View data quality and completeness', 'nvoos-content-graph-ai-platform' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
						<?php if ( $assistant_id > 0 ) : ?>
							<div class="wp-mcp-ai-research-chat">
								<?php
								// Render chat interface with team-related tools.
								// Includes search, web research, and content management tools.
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is generated by the chat renderer, which individually escapes all values; wp_kses_post() strips data-* attributes, SVGs, form elements, and <script type="application/json"> config blocks the chat UI requires.
								echo \do_shortcode(
									'[mcp_ai_chat assistant="' . \absint( $assistant_id ) . '" additional_tools="generate_research_report,create_post_from_research,search_content,web_search,list_tools,create_agent_team"]'
								);
								?>
							</div>
						<?php else : ?>
							<div class="notice notice-error">
								<p>
									<?php
									echo \wp_kses_post(
										\sprintf(
											/* translators: %s: Link to create assistant */
											__( 'No AI assistant found. Please <a href="%s">create an assistant</a> first.', 'nvoos-content-graph-ai-platform' ),
											\admin_url( 'post-new.php?post_type=mcp_ai_assistant' )
										)
									);
									?>
								</p>
							</div>
						<?php endif; ?>
					</div>

					<!-- Import Data Workflow -->
					<div id="workflow-import" class="workflow-content">
						<?php self::render_import_workflow(); ?>
					</div>

					<!-- Review & Quality Workflow -->
					<div id="workflow-review" class="workflow-content">
						<?php self::render_review_workflow(); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render import workflow.
	 *
	 * @return void
	 */
	protected static function render_import_workflow(): void {
		?>
		<div class="wp-mcp-ai-import-section">
			<h2><?php \esc_html_e( 'Import Team Data', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<p class="description">
				<?php \esc_html_e( 'Import team configurations from CSV, JSON, or paste structured data. The AI will automatically parse and organize the team information.', 'nvoos-content-graph-ai-platform' ); ?>
			</p>

			<div class="import-tips">
				<h4><?php \esc_html_e( 'Tips for better results:', 'nvoos-content-graph-ai-platform' ); ?></h4>
				<ul>
					<li><?php \esc_html_e( '✓ Include team name, description, and orchestration mode', 'nvoos-content-graph-ai-platform' ); ?></li>
					<li><?php \esc_html_e( '✓ List team members (profession IDs or names)', 'nvoos-content-graph-ai-platform' ); ?></li>
					<li><?php \esc_html_e( '✓ Specify orchestration mode (sequential, parallel, swarm)', 'nvoos-content-graph-ai-platform' ); ?></li>
					<li><?php \esc_html_e( '✓ Define aggregation strategy if needed', 'nvoos-content-graph-ai-platform' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php \esc_html_e( 'Upload File or Paste Data', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php \wp_nonce_field( 'wp_mcp_ai_import_teams', 'import_nonce' ); ?>

					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".csv,.json,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php \esc_html_e( 'Choose File', 'nvoos-content-graph-ai-platform' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php \esc_html_e( 'Supported: CSV, JSON, TXT', 'nvoos-content-graph-ai-platform' ); ?></p>
					</div>

					<p><strong><?php \esc_html_e( 'OR', 'nvoos-content-graph-ai-platform' ); ?></strong></p>

					<textarea
						id="wp-mcp-ai-import-text"
						name="import_data"
						class="widefat"
						rows="12"
						placeholder="<?php \esc_attr_e( 'Example:\n\nTeam Name: Software Development Team\nDescription: Full-stack development team\nOrchestration Mode: sequential\nMembers: Senior Software Engineer, QA Engineer, UI/UX Designer\nAggregation: consensus\n\nTeam Name: Content Creation Team\nDescription: Content writers and editors\nOrchestration Mode: parallel\nMembers: Content Writer, Editor, SEO Specialist', 'nvoos-content-graph-ai-platform' ); ?>"
					></textarea>

					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php \esc_html_e( 'Automatically create teams (recommended)', 'nvoos-content-graph-ai-platform' ); ?>
						</label>
						<label>
							<input type="checkbox" name="validate_data" value="1" checked>
							<?php \esc_html_e( 'Validate data quality before importing', 'nvoos-content-graph-ai-platform' ); ?>
						</label>
					</div>

					<p>
						<button type="submit" class="button button-primary button-large">
							<span class="dashicons dashicons-update"></span>
							<?php \esc_html_e( 'Import & Process', 'nvoos-content-graph-ai-platform' ); ?>
						</button>
					</p>
					<div class="import-result" style="display: none;"></div>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render review workflow.
	 *
	 * @return void
	 */
	protected static function render_review_workflow(): void {
		$post_type = self::team_post_type();

		// Get team statistics.
		$total_teams     = \wp_count_posts( $post_type );
		$published_count = isset( $total_teams->publish ) ? $total_teams->publish : 0;

		// Calculate data quality metrics.
		$teams = \get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count     = 0;
		$with_members       = 0;
		$with_orchestration = 0;
		$with_driver        = 0;

		foreach ( $teams as $team ) {
			$members       = \get_post_meta( $team->ID, '_wp_mcp_ai_team_members', true );
			$orchestration = \get_post_meta( $team->ID, '_wp_mcp_ai_team_orchestration_mode', true );
			$driver        = \get_post_meta( $team->ID, '_wp_mcp_ai_team_driver_assistant_id', true );

			if ( ! empty( $members ) && \is_array( $members ) ) {
				++$with_members;
			}
			if ( ! empty( $orchestration ) ) {
				++$with_orchestration;
			}
			if ( ! empty( $driver ) ) {
				++$with_driver;
			}
			if ( ! empty( $members ) && ! empty( $orchestration ) && ! empty( $driver ) ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? \round( ( $complete_count / $published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php \esc_html_e( 'Team Data Quality', 'nvoos-content-graph-ai-platform' ); ?></h2>

			<div class="quality-dashboard">
				<h3><?php \esc_html_e( 'Overall Completeness', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo \esc_attr( (string) $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo \esc_html( (string) $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo \esc_html( (string) $published_count ); ?></span>
						<span class="quality-metric-label"><?php \esc_html_e( 'Total Teams', 'nvoos-content-graph-ai-platform' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo \esc_html( (string) $complete_count ); ?></span>
						<span class="quality-metric-label"><?php \esc_html_e( 'Fully Complete', 'nvoos-content-graph-ai-platform' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo \esc_html( (string) $with_members ); ?></span>
						<span class="quality-metric-label"><?php \esc_html_e( 'With Members', 'nvoos-content-graph-ai-platform' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo \esc_html( (string) $with_orchestration ); ?></span>
						<span class="quality-metric-label"><?php \esc_html_e( 'With Orchestration', 'nvoos-content-graph-ai-platform' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							\printf(
								/* translators: %d: Completeness percentage */
								\esc_html__( 'Data completeness is %d%%. Consider adding team members and orchestration modes for better team performance.', 'nvoos-content-graph-ai-platform' ),
								\esc_html( (string) $completeness )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<div class="items-list-table">
				<h3><?php \esc_html_e( 'Quick Actions', 'nvoos-content-graph-ai-platform' ); ?></h3>
				<p>
					<a href="<?php echo \esc_url( \admin_url( 'edit.php?post_type=' . $post_type ) ); ?>" class="button button-primary">
						<?php \esc_html_e( 'View All Teams', 'nvoos-content-graph-ai-platform' ); ?>
					</a>
					<a href="<?php echo \esc_url( \admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>" class="button">
						<?php \esc_html_e( 'Add New Team', 'nvoos-content-graph-ai-platform' ); ?>
					</a>
					<button type="button" class="button refresh-quality-data">
						<span class="dashicons dashicons-update"></span>
						<?php \esc_html_e( 'Refresh Data', 'nvoos-content-graph-ai-platform' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}
}
