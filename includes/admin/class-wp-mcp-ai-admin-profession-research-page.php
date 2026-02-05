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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_page' ),
				'entityType' => 'profession',
			)
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
					<!-- Workflow Mode Selector -->
					<div class="wp-mcp-ai-workflow-selector">
						<h2><?php esc_html_e( 'Choose Your Workflow', 'mcp-ai-wpoos' ); ?></h2>
						<div class="workflow-options">
							<button type="button" class="workflow-option active" data-workflow="research">
								<span class="dashicons dashicons-format-chat"></span>
								<strong><?php esc_html_e( 'AI Research', 'mcp-ai-wpoos' ); ?></strong>
								<p><?php esc_html_e( 'Research professions with AI assistance', 'mcp-ai-wpoos' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import profession profiles', 'mcp-ai-wpoos' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos' ); ?></strong>
								<p><?php esc_html_e( 'View data quality and completeness', 'mcp-ai-wpoos' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
						<?php if ( $assistant_id > 0 ) : ?>
							<div class="wp-mcp-ai-research-chat">
								<?php
								// Render chat interface with profession-related tools.
								// Includes search, web research, and content management tools.
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is already escaped.
								echo do_shortcode(
									'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="search_content,web_search,list_tools,list_professions,get_profession,save_profession"]'
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
	 */
	protected static function render_import_workflow() {
		?>
		<div class="wp-mcp-ai-import-section">
			<h2><?php esc_html_e( 'Import Profession Data', 'mcp-ai-wpoos' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import profession profiles from CSV, JSON, or paste structured data. The AI will automatically parse and organize the information.', 'mcp-ai-wpoos' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include profession title, category, and expertise areas', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( '✓ Specify agent roles (planner, executor, critic, specialist, generalist)', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( '✓ List default tools for each profession', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( '✓ Separate different professions with blank lines', 'mcp-ai-wpoos' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_professions', 'import_nonce' ); ?>
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".csv,.json,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: CSV, JSON, TXT', 'mcp-ai-wpoos' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nTitle: Senior Software Engineer\nCategory: Technology\nExpertise: Backend development, API design, Code review\nAgent Role: Executor\nDefault Tools: search_content, web_search, code_analyzer\n\nTitle: UX Designer\nCategory: Design\nExpertise: User research, Wireframing, Prototyping\nAgent Role: Specialist\nDefault Tools: graphic_editor_plus, search_attachments', 'mcp-ai-wpoos' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create professions (recommended)', 'mcp-ai-wpoos' ); ?>
						</label>
						<label>
							<input type="checkbox" name="validate_data" value="1" checked>
							<?php esc_html_e( 'Validate data quality before importing', 'mcp-ai-wpoos' ); ?>
						</label>
					</div>

					<p>
						<button type="submit" class="button button-primary button-large">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Import & Process', 'mcp-ai-wpoos' ); ?>
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
	 */
	protected static function render_review_workflow() {
		$post_type = class_exists( 'WP_MCP_AI_Profession_CPT' ) ? WP_MCP_AI_Profession_CPT::POST_TYPE : 'mcp_ai_profession';

		// Get profession statistics.
		$total_professions = wp_count_posts( $post_type );
		$published_count   = isset( $total_professions->publish ) ? $total_professions->publish : 0;

		// Calculate data quality metrics.
		$professions = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count = 0;
		$with_expertise = 0;
		$with_role      = 0;
		$with_tools     = 0;

		foreach ( $professions as $profession ) {
			$expertise = get_post_meta( $profession->ID, '_wp_mcp_ai_profession_expertise', true );
			$role      = get_post_meta( $profession->ID, '_wp_mcp_ai_profession_agent_role', true );
			$tools     = get_post_meta( $profession->ID, '_wp_mcp_ai_profession_default_tools', true );

			if ( ! empty( $expertise ) ) {
				++$with_expertise;
			}
			if ( ! empty( $role ) ) {
				++$with_role;
			}
			if ( ! empty( $tools ) ) {
				++$with_tools;
			}
			if ( ! empty( $expertise ) && ! empty( $role ) && ! empty( $tools ) ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Profession Data Quality', 'mcp-ai-wpoos' ); ?></h2>
			
			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Professions', 'mcp-ai-wpoos' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_expertise ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Expertise', 'mcp-ai-wpoos' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_role ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Agent Role', 'mcp-ai-wpoos' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Data completeness is %d%%. Consider adding expertise areas and agent roles to professions for better AI performance.', 'mcp-ai-wpoos' ),
								esc_html( $completeness )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<div class="items-list-table">
				<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . $post_type ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Professions', 'mcp-ai-wpoos' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $post_type ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Profession', 'mcp-ai-wpoos' ); ?>
					</a>
					<button type="button" class="button refresh-quality-data">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh Data', 'mcp-ai-wpoos' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}
}

// Initialize.
WP_MCP_AI_Admin_Profession_Research_Page::init();
