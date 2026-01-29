<?php
/**
 * Architectural Project Research & Add Page
 *
 * Provides AI-assisted design project creation interface for Architectural Design Toolkit.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Architectural Project Research & Add Page
 *
 * Adds a submenu page under Design Projects menu for AI-powered architectural research.
 */
class WP_MCP_AI_Architectural_Project_Research_Page {
	use WP_MCP_AI_Research_Page_Featured_Image;
	use WP_MCP_AI_Research_Page_Import_Handler;
	use WP_MCP_AI_Research_Page_Consolidation;
	use WP_MCP_AI_Research_Page_Data_Validation;
	use WP_MCP_AI_Research_Page_Mode_Tabs;

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'architectural-project-research';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_arch_project_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_arch_project', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Add submenu page under Design Projects menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_arch_proj',
			__( 'Research & Add Design Projects', 'mcp-ai-wpoos-pro' ),
			__( 'Research Projects', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_ai_arch_proj_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_arch_project' ),
				'entityType' => 'architectural_project',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_architectural_project_settings', array() );
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
				<?php esc_html_e( 'Research & Add Design Projects', 'mcp-ai-wpoos-pro' ); ?>
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
							<li><?php esc_html_e( 'Research architectural designs and building codes', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Generate floor plans and 3D models', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create projects with drawings and specifications', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Estimate costs and generate timelines', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Define scope:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Specify project type, size, and requirements', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Check codes:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Research applicable building codes and zoning', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Use standards:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Follow AIA and CSI MasterFormat conventions', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Generate plans:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Create floor plans and visualizations', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a new residential project with 3 bedrooms, 2 bathrooms, 2000 sq ft">
								<?php esc_html_e( '"Create a new residential project with 3 bedrooms..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate a floor plan for a commercial office space">
								<?php esc_html_e( '"Generate a floor plan for a commercial office..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research zoning requirements for an urban infill project">
								<?php esc_html_e( '"Research zoning requirements for urban infill..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_arch_proj' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Projects', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_arch_proj' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Project Manually', 'mcp-ai-wpoos-pro' ); ?>
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
								<p><?php esc_html_e( 'Research and create projects with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import project data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View project quality and completeness', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive architectural design tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="generate_floor_plan,optimize_space_layout,create_floor_plan_variations,generate_3d_model,check_building_code_compliance,analyze_structural_feasibility,calculate_sustainability_metrics,estimate_construction_cost,generate_construction_timeline,web_search,search_content"]'
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
		<?php
	}

	/**
	 * Handle AJAX request to create architectural project from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_arch_project', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create projects.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) || empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process featured image generation request.
		$research_data = self::process_featured_image_request( $research_data, $research_data['title'], 'an architectural design project' );

		// Create the architectural project post.
		$post_data = array(
			'post_type'    => 'mcp_ai_arch_proj',
			'post_title'   => sanitize_text_field( $research_data['title'] ),
			'post_content' => wp_kses_post( $research_data['content'] ?? '' ),
			'post_status'  => 'publish',
		);

		$project_id = wp_insert_post( $post_data );

		if ( is_wp_error( $project_id ) ) {
			wp_send_json_error( array( 'message' => $project_id->get_error_message() ) );
		}

		// Set featured image if generated.
		if ( ! empty( $research_data['featured_image_id'] ) ) {
			set_post_thumbnail( $project_id, absint( $research_data['featured_image_id'] ) );
		}

		// Save metadata.
		$meta_fields = array( 'project_type', 'location', 'square_footage', 'budget', 'timeline', 'style', 'materials' );
		foreach ( $meta_fields as $field ) {
			if ( isset( $research_data[ $field ] ) ) {
				update_post_meta( $project_id, $field, sanitize_text_field( $research_data[ $field ] ) );
			}
		}

		// Return success with project ID and edit URL.
		$edit_url = admin_url( 'post.php?post=' . $project_id . '&action=edit' );

		wp_send_json_success(
			array(
				'message'    => __( 'Architectural project created successfully!', 'mcp-ai-wpoos-pro' ),
				'project_id' => $project_id,
				'edit_url'   => $edit_url,
			)
		);
	}

	/**
	 * Get supported import formats.
	 *
	 * @return array Array of format slug => label pairs.
	 */
	protected static function get_import_formats() {
		return array(
			'pdf'  => 'PDF',
			'docx' => 'DOCX',
			'csv'  => 'CSV',
			'json' => 'JSON',
		);
	}

	/**
	 * Process import data.
	 *
	 * @param mixed  $data   Data to import.
	 * @param string $format File format.
	 * @return array|WP_Error Array of processed items or WP_Error on failure.
	 */
	protected static function process_import_data( $data, $format ) {
		return new WP_Error( 'not_implemented', __( 'Architectural project import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema for architectural project data.
	 *
	 * @return array Validation schema configuration.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title'        => __( 'Project Title', 'mcp-ai-wpoos-pro' ),
				'content'      => __( 'Project Description', 'mcp-ai-wpoos-pro' ),
				'project_type' => __( 'Project Type', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'location'        => __( 'Location', 'mcp-ai-wpoos-pro' ),
				'square_footage'  => __( 'Square Footage', 'mcp-ai-wpoos-pro' ),
				'budget'          => __( 'Budget', 'mcp-ai-wpoos-pro' ),
				'timeline'        => __( 'Timeline', 'mcp-ai-wpoos-pro' ),
				'style'           => __( 'Architectural Style', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'square_footage' => array( 'type' => 'numeric', 'min_value' => 0 ),
				'budget'         => array( 'type' => 'numeric', 'min_value' => 0 ),
			),
			'quality_dimensions' => array(
				'completeness',
				'accuracy',
				'code_compliance',
				'documentation',
			),
		);
	}

	/**
	 * Calculate completeness score for projects.
	 *
	 * @return array Completeness data with percentage and suggestions.
	 */
	protected static function calculate_completeness() {
		$projects = get_posts(
			array(
				'post_type'      => 'mcp_ai_arch_proj',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $projects );
		$complete = 0;

		foreach ( $projects as $project ) {
			$project_type    = get_post_meta( $project->ID, 'project_type', true );
			$square_footage  = get_post_meta( $project->ID, 'square_footage', true );
			$location        = get_post_meta( $project->ID, 'location', true );
			
			if ( ! empty( $project_type ) && ! empty( $square_footage ) && ! empty( $location ) && ! empty( $project->post_content ) ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array(),
			'suggestions' => array(
				__( 'Specify project type for all projects', 'mcp-ai-wpoos-pro' ),
				__( 'Add location and square footage details', 'mcp-ai-wpoos-pro' ),
				__( 'Include comprehensive project descriptions', 'mcp-ai-wpoos-pro' ),
				__( 'Attach relevant drawings and specifications', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Array of project items for review.
	 */
	protected static function get_items_for_review() {
		$projects = get_posts(
			array(
				'post_type'      => 'mcp_ai_arch_proj',
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
					'project_type'   => get_post_meta( $project->ID, 'project_type', true ),
					'square_footage' => get_post_meta( $project->ID, 'square_footage', true ),
					'location'       => get_post_meta( $project->ID, 'location', true ),
				),
			);
		}

		return $items;
	}

	/**
	 * Calculate quality score for an item.
	 *
	 * @param array $item Item data to score.
	 * @return array Quality score data with score, level, status, and issues.
	 */
	protected static function calculate_quality_score( $item ) {
		$score  = 0;
		$issues = array();

		if ( ! empty( $item['meta']['project_type'] ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing project type', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['square_footage'] ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing square footage', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['location'] ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing location', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) > 10 ) {
			$score += 25;
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
	 * Handle import AJAX request.
	 */
	public static function handle_import() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_arch_project', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import projects.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get import data from request.
		$format = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized in process_import_data.
		$data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';

		if ( empty( $format ) || empty( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process import.
		$result = self::process_import_data( $data, $format );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Render import workflow.
	 */
	protected static function render_import_workflow() {
		?>
		<div class="wp-mcp-ai-import-section">
			<h2><?php esc_html_e( 'Import Architectural Project Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import projects from CSV, JSON, or paste structured data. The AI will automatically parse and organize the project information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include project title, type, and description', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify location, square footage, and budget', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Add architectural style and materials', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include timeline and project constraints', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".csv,.json,.pdf,.docx,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: CSV, JSON, PDF, DOCX, TXT', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nTitle: Modern Residential House\nType: Residential\nLocation: Downtown Seattle\nSquare Footage: 2500\nBudget: $500,000\nStyle: Contemporary\n\nTitle: Commercial Office Building\nType: Commercial\nLocation: Tech District\nSquare Footage: 15000', 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create projects (recommended)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<label>
							<input type="checkbox" name="validate_data" value="1" checked>
							<?php esc_html_e( 'Validate data quality before importing', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</div>

					<p>
						<button type="submit" class="button button-primary button-large">
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Import & Process', 'mcp-ai-wpoos-pro' ); ?>
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
		// Get project statistics.
		$total_projects = wp_count_posts( 'mcp_ai_arch_proj' );
		$published_count = isset( $total_projects->publish ) ? $total_projects->publish : 0;
		
		// Calculate data quality metrics.
		$projects = get_posts(
			array(
				'post_type'      => 'mcp_ai_arch_proj',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count = 0;
		$with_type = 0;
		$with_location = 0;

		foreach ( $projects as $project ) {
			$project_type   = get_post_meta( $project->ID, 'project_type', true );
			$location       = get_post_meta( $project->ID, 'location', true );
			$square_footage = get_post_meta( $project->ID, 'square_footage', true );
			$has_desc       = ! empty( $project->post_content );
			
			if ( ! empty( $project_type ) ) {
				$with_type++;
			}
			if ( ! empty( $location ) ) {
				$with_location++;
			}
			if ( ! empty( $project_type ) && ! empty( $location ) && ! empty( $square_footage ) && $has_desc ) {
				$complete_count++;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;
		
		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Project Quality Dashboard', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Projects', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_type ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Project Type', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_location ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Location', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Project completeness is %d%%. Consider adding project types, locations, and detailed descriptions to improve quality.', 'mcp-ai-wpoos-pro' ),
								esc_html( $completeness )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<?php self::render_quality_table(); ?>

			<div class="items-list-table">
				<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_arch_proj' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Projects', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_arch_proj' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Project', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<button type="button" class="button refresh-quality-data">
						<span class="dashicons dashicons-update"></span>
						<?php esc_html_e( 'Refresh Data', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
	}
}
