<?php
/**
 * Architectural Drawing Research & Add Page
 *
 * Provides AI-assisted architectural drawing creation interface.
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
 * Architectural Drawing Research & Add Page
 *
 * Adds a submenu page under Drawings menu for AI-powered drawing generation.
 */
class WP_MCP_AI_Architectural_Drawing_Research_Page {
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
	const PAGE_SLUG = 'architectural-drawing-research';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_arch_drawing_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_arch_drawing', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Add submenu page under Design Projects menu (same parent as Project research).
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_arch_proj',
			__( 'Research & Add Drawings', 'mcp-ai-wpoos-pro' ),
			__( 'Research Drawings', 'mcp-ai-wpoos-pro' ),
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
		// Now under mcp_ai_arch_proj parent menu like all other research pages.
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_arch_drawing' ),
				'entityType' => 'architectural_drawing',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_architectural_drawing_settings', array() );
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
				<?php esc_html_e( 'Research & Add Architectural Drawings', 'mcp-ai-wpoos-pro' ); ?>
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
							<li><?php esc_html_e( 'Generate professional architectural drawings', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Follow AIA/NCS layer naming standards', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create floor plans, elevations, sections, details', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Export in multiple formats (DWG, PDF, SVG)', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Drawing Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Specify type:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Floor plan, elevation, section, or detail', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Set scale:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Use standard scales (1/4" = 1\'-0", 1:100)', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Link project:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Associate drawings with parent project', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Track revisions:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Document changes with revision numbers', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'NCS Reports:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Request drawing reports with Sheet Organization, Layer Structure (Discipline-Major-Minor-Status), Scale Standards, and Quality Control Checklist', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate a floor plan for Project #123">
								<?php esc_html_e( '"Generate a floor plan for Project #123"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create elevation drawings showing all four facades">
								<?php esc_html_e( '"Create elevation drawings for all facades"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate building sections with dimensions and annotations">
								<?php esc_html_e( '"Generate sections with dimensions"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research CAD layer standards for commercial building and generate an NCS-compliant drawing documentation report. Use comprehensive depth and focus on layer organization and quality control.">
								<?php esc_html_e( '"Research CAD standards (NCS report)..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_arch_draw' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Drawings', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_arch_draw' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Drawing Manually', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
					</div>

					<div class="drawing-types-reference" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px 16px; margin: 20px 0;">
						<h4 style="margin-top: 0;"><?php esc_html_e( 'AIA/NCS Standard Drawing Types', 'mcp-ai-wpoos-pro' ); ?></h4>
						<ul style="font-size: 13px; margin: 8px 0;">
							<li><strong>A-FLOR:</strong> Floor Plans</li>
							<li><strong>A-ELEV:</strong> Elevations</li>
							<li><strong>A-SECT:</strong> Sections</li>
							<li><strong>A-DETL:</strong> Details</li>
							<li><strong>A-RCPN:</strong> Reflected Ceiling Plans</li>
							<li><strong>A-SITE:</strong> Site Plans</li>
						</ul>
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
								<p><?php esc_html_e( 'Generate drawings with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import drawing data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View drawing quality and completeness', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with drawing-focused tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="generate_floor_plan,create_floor_plan_variations,convert_sketch_to_floor_plan,generate_3d_model,render_architectural_view,create_walkthrough_animation,generate_construction_drawings,generate_detail_drawings,export_architectural_documents,generate_research_report,web_search,search_content"]'
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
	 * Handle AJAX request to create drawing from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_arch_drawing', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create drawings.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) || empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process featured image generation request.
		$research_data = self::process_featured_image_request( $research_data, $research_data['title'], 'an architectural drawing' );

		// Create the drawing post.
		$post_data = array(
			'post_type'    => 'mcp_ai_arch_draw',
			'post_title'   => sanitize_text_field( $research_data['title'] ),
			'post_content' => wp_kses_post( $research_data['content'] ?? '' ),
			'post_status'  => 'publish',
		);

		$drawing_id = wp_insert_post( $post_data );

		if ( is_wp_error( $drawing_id ) ) {
			wp_send_json_error( array( 'message' => $drawing_id->get_error_message() ) );
		}

		// Set featured image if generated.
		if ( ! empty( $research_data['featured_image_id'] ) ) {
			set_post_thumbnail( $drawing_id, absint( $research_data['featured_image_id'] ) );
		}

		// Save metadata.
		$meta_fields = array( 'drawing_type', 'drawing_number', 'scale', 'project_id', 'revision', 'layer_naming' );
		foreach ( $meta_fields as $field ) {
			if ( isset( $research_data[ $field ] ) ) {
				update_post_meta( $drawing_id, $field, sanitize_text_field( $research_data[ $field ] ) );
			}
		}

		// Return success with drawing ID and edit URL.
		$edit_url = admin_url( 'post.php?post=' . $drawing_id . '&action=edit' );

		wp_send_json_success(
			array(
				'message'    => __( 'Architectural drawing created successfully!', 'mcp-ai-wpoos-pro' ),
				'drawing_id' => $drawing_id,
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
			'dwg'  => 'DWG',
			'dxf'  => 'DXF',
			'pdf'  => 'PDF',
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
		return new WP_Error( 'not_implemented', __( 'Drawing import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema for drawing data.
	 *
	 * @return array Validation schema configuration.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title'          => __( 'Drawing Title', 'mcp-ai-wpoos-pro' ),
				'drawing_type'   => __( 'Drawing Type', 'mcp-ai-wpoos-pro' ),
				'drawing_number' => __( 'Drawing Number', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'scale'        => __( 'Scale', 'mcp-ai-wpoos-pro' ),
				'project_id'   => __( 'Project', 'mcp-ai-wpoos-pro' ),
				'revision'     => __( 'Revision', 'mcp-ai-wpoos-pro' ),
				'layer_naming' => __( 'Layer Naming', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'drawing_number' => array( 'max_length' => 50 ),
				'project_id'     => array( 'type' => 'numeric' ),
			),
			'quality_dimensions' => array(
				'completeness',
				'accuracy',
				'standards_compliance',
				'documentation',
			),
		);
	}

	/**
	 * Calculate completeness score for drawings.
	 *
	 * @return array Completeness data with percentage and suggestions.
	 */
	protected static function calculate_completeness() {
		$drawings = get_posts(
			array(
				'post_type'      => 'mcp_ai_arch_draw',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $drawings );
		$complete = 0;

		foreach ( $drawings as $drawing ) {
			$drawing_type   = get_post_meta( $drawing->ID, 'drawing_type', true );
			$drawing_number = get_post_meta( $drawing->ID, 'drawing_number', true );
			$scale          = get_post_meta( $drawing->ID, 'scale', true );

			if ( ! empty( $drawing_type ) && ! empty( $drawing_number ) && ! empty( $scale ) && ! empty( $drawing->post_content ) ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array(),
			'suggestions' => array(
				__( 'Assign unique drawing numbers to all drawings', 'mcp-ai-wpoos-pro' ),
				__( 'Specify drawing type and scale for each drawing', 'mcp-ai-wpoos-pro' ),
				__( 'Link drawings to their parent projects', 'mcp-ai-wpoos-pro' ),
				__( 'Follow AIA/NCS layer naming conventions', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Array of drawing items for review.
	 */
	protected static function get_items_for_review() {
		$drawings = get_posts(
			array(
				'post_type'      => 'mcp_ai_arch_draw',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $drawings as $drawing ) {
			$items[] = array(
				'id'    => $drawing->ID,
				'title' => $drawing->post_title,
				'meta'  => array(
					'drawing_type'   => get_post_meta( $drawing->ID, 'drawing_type', true ),
					'drawing_number' => get_post_meta( $drawing->ID, 'drawing_number', true ),
					'scale'          => get_post_meta( $drawing->ID, 'scale', true ),
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

		if ( ! empty( $item['meta']['drawing_type'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing drawing type', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['drawing_number'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing drawing number', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['scale'] ) ) {
			$score += 20;
		} else {
			$issues[] = __( 'Missing scale', 'mcp-ai-wpoos-pro' );
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
	 * Handle import AJAX request.
	 */
	public static function handle_import() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_arch_drawing', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import drawings.', 'mcp-ai-wpoos-pro' ) ) );
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
			<h2><?php esc_html_e( 'Import Architectural Drawing Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import drawings from DWG, DXF, CSV, JSON, or paste structured data. The AI will automatically parse and organize the drawing information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include drawing number, type, and title', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify scale notation (1/4" = 1\'-0", 1:100)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Link drawings to parent projects', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include revision numbers and dates', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".dwg,.dxf,.csv,.json,.pdf,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: DWG, DXF, CSV, JSON, PDF, TXT', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nNumber: A-101\nTitle: First Floor Plan\nType: Floor Plan\nScale: 1/4\" = 1\'-0\"\nProject: #123\n\nNumber: A-201\nTitle: North Elevation\nType: Elevation\nScale: 1/4\" = 1\'-0\"', 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create drawings (recommended)', 'mcp-ai-wpoos-pro' ); ?>
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
		// Get drawing statistics.
		$total_drawings  = wp_count_posts( 'mcp_ai_arch_draw' );
		$published_count = isset( $total_drawings->publish ) ? $total_drawings->publish : 0;

		// Calculate data quality metrics.
		$drawings = get_posts(
			array(
				'post_type'      => 'mcp_ai_arch_draw',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count = 0;
		$with_type      = 0;
		$with_number    = 0;

		foreach ( $drawings as $drawing ) {
			$drawing_type   = get_post_meta( $drawing->ID, 'drawing_type', true );
			$drawing_number = get_post_meta( $drawing->ID, 'drawing_number', true );
			$scale          = get_post_meta( $drawing->ID, 'scale', true );
			$has_desc       = ! empty( $drawing->post_content );

			if ( ! empty( $drawing_type ) ) {
				++$with_type;
			}
			if ( ! empty( $drawing_number ) ) {
				++$with_number;
			}
			if ( ! empty( $drawing_type ) && ! empty( $drawing_number ) && ! empty( $scale ) && $has_desc ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Drawing Quality Dashboard', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Drawings', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_type ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Type', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_number ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Number', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Drawing completeness is %d%%. Consider adding drawing numbers, types, and scales to improve quality.', 'mcp-ai-wpoos-pro' ),
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
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_arch_draw' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Drawings', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_arch_draw' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Drawing', 'mcp-ai-wpoos-pro' ); ?>
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
