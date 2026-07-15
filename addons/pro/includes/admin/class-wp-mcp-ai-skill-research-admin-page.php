<?php
/**
 * Research & Add admin page for Agent Skills.
 *
 * Provides a dedicated page for researching agent skills, exploring existing
 * skills, and adding new ones with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.10.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Skill Research Admin Page
 *
 * Adds a submenu page under the Assistants menu for AI-powered skill research.
 *
 * @since 1.10.0
 */
class WP_MCP_AI_Skill_Research_Admin_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'research-skill';

	/**
	 * Initialize the page.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 25 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add submenu page under the Assistants CPT menu.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_assistant',
			__( 'Research & Add Skill', 'mcp-ai-wpoos-pro' ),
			__( 'Research & Add', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the research page.
	 *
	 * @since 1.10.0
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'mcp_ai_assistant_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		// Enqueue chat assets.
		if ( class_exists( 'WP_MCP_AI_Shortcode' ) ) {
			$shortcode_instance = new WP_MCP_AI_Shortcode();
			$shortcode_instance->register_assets();
			wp_enqueue_style( WP_MCP_AI_Shortcode::STYLE_HANDLE );
			wp_enqueue_script( WP_MCP_AI_Shortcode::SCRIPT_HANDLE );
		}

		// Enqueue enhanced research page styles (shared with profession research).
		wp_enqueue_style(
			'wp-mcp-ai-enhanced-research-page',
			WP_MCP_AI_URL . 'assets/css/enhanced-research-page.css',
			array(),
			WP_MCP_AI_VERSION
		);

		// Enqueue enhanced research page script (shared with profession research).
		wp_enqueue_script(
			'wp-mcp-ai-enhanced-research-page',
			WP_MCP_AI_URL . 'assets/js/enhanced-research-page.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Localize script with skill-specific entity type.
		wp_localize_script(
			'wp-mcp-ai-enhanced-research-page',
			'wpMcpAiResearchPage',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_page' ),
				'entityType' => 'skill',
			)
		);
	}

	/**
	 * Render the research page.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	public static function render_page() {
		// Get assistant - try to find the first available one.
		$assistants   = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$assistant_id = ! empty( $assistants ) ? $assistants[0]->ID : 0;

		?>
		<div class="wrap wp-mcp-ai-research-page">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Research & Add Skill', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-research-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Search existing skills to avoid duplicates', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Research skill procedures, triggers, and best practices', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Define skill instructions following the agentskills.io spec', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Install the skill and assign it to assistants', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Check if a similar skill already exists', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Define triggers:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Specify precise scenarios when the skill should be invoked', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Keep it focused:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Each skill should do one thing well', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Use the Builder:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Use the Skill Manager Builder tab to assemble SKILL.md', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li>
								<button type="button" class="button button-secondary wp-mcp-ai-example-query"
										data-query="<?php esc_attr_e( 'Research a PDF text extraction skill that reads and summarizes PDF documents', 'mcp-ai-wpoos-pro' ); ?>">
									<?php esc_html_e( '"Research a PDF text extraction skill..."', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</li>
							<li>
								<button type="button" class="button button-secondary wp-mcp-ai-example-query"
										data-query="<?php esc_attr_e( 'Create a web research skill for gathering and summarizing information from the internet', 'mcp-ai-wpoos-pro' ); ?>">
									<?php esc_html_e( '"Create a web research skill..."', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</li>
							<li>
								<button type="button" class="button button-secondary wp-mcp-ai-example-query"
										data-query="<?php esc_attr_e( 'Research a code review skill with best practices for reviewing PHP and JavaScript', 'mcp-ai-wpoos-pro' ); ?>">
									<?php esc_html_e( '"Research a code review skill..."', 'mcp-ai-wpoos-pro' ); ?>
								</button>
							</li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager' ) ); ?>" class="button">
								<?php esc_html_e( 'View Skill Manager', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager&tab=install' ) ); ?>" class="button">
								<?php esc_html_e( 'Upload & Install Skill', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager&tab=research-skill' ) ); ?>" class="button">
								<?php esc_html_e( 'Open Builder', 'mcp-ai-wpoos-pro' ); ?>
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
								<p><?php esc_html_e( 'Research skills with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import skill definitions', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View installed skill quality', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
						<?php if ( $assistant_id > 0 ) : ?>
							<div class="wp-mcp-ai-research-chat">
								<?php
								// Render chat interface with skill-related tools.
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is already escaped.
								echo do_shortcode(
									'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="generate_research_report,create_post_from_research,search_content,web_search,list_tools"]'
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
	 * Render import workflow for bulk skill import.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	protected static function render_import_workflow() {
		?>
		<div class="wp-mcp-ai-import-section">
			<h2><?php esc_html_e( 'Import Skill Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import skill definitions from CSV, JSON, or paste structured data. Use this to bulk-describe skills before creating their SKILL.md files via the Builder tab.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include skill name, description, and trigger scenarios', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify the domain or category (e.g. document-processing, web-research)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ List any allowed tools or external dependencies', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Separate different skills with blank lines', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-skill-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_skills', 'import_nonce' ); ?>

					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-skill-import-file-input" name="import_file" accept=".csv,.json,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-skill-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: CSV, JSON, TXT', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea
						id="wp-mcp-ai-skill-import-text"
						name="import_data"
						class="widefat"
						rows="12"
						placeholder="<?php esc_attr_e( "Name: PDF Text Extractor\nDescription: Extracts and summarises text from PDF documents\nDomain: document-processing\nTriggers: When a user asks to read, extract, or summarise a PDF file\nAllowed Tools: ReadFiles, Bash\n\nName: Web Research\nDescription: Gathers and summarises information from the internet\nDomain: web-research\nTriggers: When a user asks to find or research information online\nAllowed Tools: WebSearch", 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>

					<p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager&tab=install' ) ); ?>" class="button button-primary button-large">
							<span class="dashicons dashicons-hammer"></span>
							<?php esc_html_e( 'Go to Skill Manager to Install', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render review and quality workflow.
	 *
	 * @since 1.10.0
	 * @return void
	 */
	protected static function render_review_workflow() {
		$registry = class_exists( 'WP_MCP_AI_Skill_Registry' ) ? WP_MCP_AI_Skill_Registry::instance() : null;
		$skills   = $registry ? $registry->get_all_skills() : array();

		$total_count       = count( $skills );
		$with_description  = 0;
		$with_instructions = 0;
		$with_license      = 0;
		$complete_count    = 0;

		foreach ( $skills as $skill ) {
			$has_desc         = ! empty( $skill['description'] );
			$has_instructions = ! empty( $skill['instructions'] );
			$has_license      = ! empty( $skill['license'] ) && 'unknown' !== strtolower( $skill['license'] );

			if ( $has_desc ) {
				++$with_description;
			}
			if ( $has_instructions ) {
				++$with_instructions;
			}
			if ( $has_license ) {
				++$with_license;
			}
			if ( $has_desc && $has_instructions && $has_license ) {
				++$complete_count;
			}
		}

		$completeness = $total_count > 0 ? round( ( $complete_count / $total_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Skill Data Quality', 'mcp-ai-wpoos-pro' ); ?></h2>

			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $total_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Skills', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_description ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Description', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_instructions ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Instructions', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $total_count > 0 && $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Skill completeness is %d%%. Consider adding descriptions, instructions, and license fields to skills for better agent performance.', 'mcp-ai-wpoos-pro' ),
								absint( $completeness )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<div class="items-list-table">
				<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Skills', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager&tab=install' ) ); ?>" class="button">
						<?php esc_html_e( 'Upload & Install Skill', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-skill-manager&tab=research-skill' ) ); ?>" class="button">
						<?php esc_html_e( 'Open Builder', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}
}
