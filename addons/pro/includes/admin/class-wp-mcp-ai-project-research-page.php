<?php
/**
 * Research & Add admin page for Project CPT.
 *
 * Provides a dedicated page for researching projects before adding them,
 * with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';

/**
 * Project Research Admin Page
 *
 * Adds a submenu page under Projects menu for AI-powered project research.
 */
class WP_MCP_AI_Project_Research_Page {
	use WP_MCP_AI_Research_Page_Featured_Image;

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'research-project';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_project_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_project', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Add submenu page under Projects menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_project',
			__( 'Research & Add Project', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_ai_project_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'        => wp_create_nonce( 'wp_mcp_ai_research_project' ),
				'addNewUrl'    => admin_url( 'post-new.php?post_type=mcp_ai_project' ),
				'researchTool' => 'research_project',
				'strings'      => array(
					'researching'   => __( 'Researching...', 'mcp-ai-wpoos-pro' ),
					'error'         => __( 'An error occurred. Please try again.', 'mcp-ai-wpoos-pro' ),
					'creating'      => __( 'Creating Project...', 'mcp-ai-wpoos-pro' ),
					'created'       => __( 'Project created successfully!', 'mcp-ai-wpoos-pro' ),
					'confirmCreate' => __( 'Create a project with the researched information?', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_project_settings', array() );
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
				<?php esc_html_e( 'Research & Add Project', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<div class="wp-mcp-ai-research-container">
				<div class="wp-mcp-ai-research-sidebar">
					<div class="wp-mcp-ai-research-intro">
						<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
						<ol>
							<li><?php esc_html_e( 'Search existing projects or research new ideas on the web', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Use calendar view to check timelines and conflicts', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create projects with tasks and milestones', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Manage project resources and assignments', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'List existing projects to avoid duplicates', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Check timeline:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Review schedule conflicts before creating', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Web research:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Find best practices and methodologies', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Break it down:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Define tasks and milestones after creating', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research a website redesign project with timeline, phases, and deliverables">
								<?php esc_html_e( '"Research a website redesign project..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Find information about planning a product launch including marketing strategy and milestones">
								<?php esc_html_e( '"Find information about planning a product launch..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research an employee training program with curriculum, schedule, and assessment plan">
								<?php esc_html_e( '"Research an employee training program..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_project' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Projects', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_project' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Project Manually', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
					</div>
				</div>

				<div class="wp-mcp-ai-research-main">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive project tools.
							// Includes research, creation, task management, and calendar tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="research_project,create_project,list_projects,create_task,list_tasks,create_event,list_events,get_calendar_view,web_search,search_content"]'
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
	 * Handle AJAX request to create project from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_project', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create projects.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data_raw = isset( $_POST['research_data'] ) ? wp_unslash( $_POST['research_data'] ) : '';

		if ( empty( $research_data_raw ) ) {
			wp_send_json_error( array( 'message' => __( 'No research data provided.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$research_data = json_decode( $research_data_raw, true );

		// Validate JSON decoding.
		if ( null === $research_data || JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON data format.', 'mcp-ai-wpoos-pro' ) ) );
		}

		if ( empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Project title is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process featured image generation request.
		$research_data = self::process_featured_image_request( $research_data, $research_data['title'], 'a project' );

		// Use the create_project tool to create the project.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Project' ) ) {
			wp_send_json_error( array( 'message' => __( 'Create Project tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$tool   = new WP_MCP_AI_Tool_Create_Project();
		$result = $tool->execute(
			$research_data,
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Return success with project ID and edit URL.
		$project_id = isset( $result['project_id'] ) ? $result['project_id'] : 0;
		$edit_url   = $project_id > 0 ? admin_url( 'post.php?post=' . $project_id . '&action=edit' ) : '';

		wp_send_json_success(
			array(
				'message'    => __( 'Project created successfully!', 'mcp-ai-wpoos-pro' ),
				'project_id' => $project_id,
				'edit_url'   => $edit_url,
			)
		);
	}

	/**
	 * Get supported import formats.
	 *
	 * @return array Format key => label pairs.
	 */
	protected static function get_import_formats() {
		return array(
			'xml'  => 'MS Project XML',
			'csv'  => 'CSV',
			'json' => 'JSON',
		);
	}

	/**
	 * Process imported data.
	 *
	 * @param mixed  $data   Data to process.
	 * @param string $format Import format.
	 * @return array|WP_Error Processed data or error.
	 */
	protected static function process_import_data( $data, $format ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Parameters reserved for future implementation.
		return new WP_Error( 'not_implemented', __( 'Project import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema for projects.
	 *
	 * @return array Validation schema with required and recommended fields.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'name'       => __( 'Project Name', 'mcp-ai-wpoos-pro' ),
				'start_date' => __( 'Start Date', 'mcp-ai-wpoos-pro' ),
				'duration'   => __( 'Duration', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'end_date'    => __( 'End Date', 'mcp-ai-wpoos-pro' ),
				'budget'      => __( 'Budget', 'mcp-ai-wpoos-pro' ),
				'status'      => __( 'Status', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Description', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'start_date' => array( 'type' => 'datetime' ),
				'end_date'   => array( 'type' => 'datetime' ),
				'budget'     => array(
					'type'      => 'numeric',
					'min_value' => 0,
				),
			),
			'quality_dimensions' => array(
				'completeness',
				'accuracy',
				'constraint_validation',
				'dependency_integrity',
			),
		);
	}

	/**
	 * Calculate completeness metrics for projects.
	 *
	 * @return array Completeness percentage and suggestions.
	 */
	protected static function calculate_completeness() {
		$projects = get_posts(
			array(
				'post_type'      => 'mcp_ai_project',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $projects );
		$complete = 0;

		foreach ( $projects as $project ) {
			$start_date = get_post_meta( $project->ID, 'start_date', true );
			$status     = get_post_meta( $project->ID, 'status', true );
			if ( ! empty( $start_date ) && ! empty( $status ) && ! empty( $project->post_content ) ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array(),
			'suggestions' => array(
				__( 'Set start and end dates for all projects', 'mcp-ai-wpoos-pro' ),
				__( 'Define project budgets and milestones', 'mcp-ai-wpoos-pro' ),
				__( 'Add detailed project descriptions', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Array of project items with metadata.
	 */
	protected static function get_items_for_review() {
		$projects = get_posts(
			array(
				'post_type'      => 'mcp_ai_project',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $projects as $project ) {
			$items[] = array(
				'id'    => $project->ID,
				'title' => $project->post_title,
				'meta'  => array(
					'start_date' => get_post_meta( $project->ID, 'start_date', true ),
					'end_date'   => get_post_meta( $project->ID, 'end_date', true ),
					'status'     => get_post_meta( $project->ID, 'status', true ),
				),
			);
		}

		return $items;
	}

	/**
	 * Calculate quality score for a project item.
	 *
	 * @param array $item Project item data.
	 * @return array Quality score with level, status, and issues.
	 */
	protected static function calculate_quality_score( $item ) {
		$score  = 0;
		$issues = array();

		if ( ! empty( $item['meta']['start_date'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing start date', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['end_date'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing end date', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['status'] ) ) {
			$score += 20;
		} else {
			$issues[] = __( 'Missing status', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) > 10 ) {
			$score += 20;
		} else {
			$issues[] = __( 'Title needs improvement', 'mcp-ai-wpoos-pro' );
		}

		$level = $score >= 80 ? 'high' : ( $score >= 50 ? 'medium' : 'low' );

		return array(
			'score'  => $score,
			'level'  => $level,
			'status' => 'high' === $level ? __( 'Complete', 'mcp-ai-wpoos-pro' ) : __( 'Needs Work', 'mcp-ai-wpoos-pro' ),
			'issues' => $issues,
		);
	}

	/**
	 * Handle AJAX import request.
	 */
	public static function handle_import() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_project', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import projects.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get import data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$import_data_raw = isset( $_POST['import_data'] ) ? wp_unslash( $_POST['import_data'] ) : '';
		$format          = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : '';

		if ( empty( $import_data_raw ) || empty( $format ) ) {
			wp_send_json_error( array( 'message' => __( 'Import data and format are required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process import.
		$result = self::process_import_data( $import_data_raw, $format );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Import completed successfully!', 'mcp-ai-wpoos-pro' ),
				'result'  => $result,
			)
		);
	}
}

// Initialize.
WP_MCP_AI_Project_Research_Page::init();
