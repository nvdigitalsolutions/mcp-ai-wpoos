<?php
/**
 * Architectural Specification Research & Add Page
 *
 * Provides AI-assisted specification writing interface following CSI MasterFormat.
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
 * Architectural Specification Research & Add Page
 *
 * Adds a submenu page under Specifications menu for AI-powered specification writing.
 */
class WP_MCP_AI_Architectural_Specification_Research_Page {
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
	const PAGE_SLUG = 'architectural-specification-research';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_arch_spec_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_arch_spec', array( __CLASS__, 'handle_import' ) );
	}

	/**
	 * Add submenu page under Design Projects menu (same parent as Project research).
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_arch_proj',
			__( 'Research & Add Specifications', 'mcp-ai-wpoos-pro' ),
			__( 'Research Specifications', 'mcp-ai-wpoos-pro' ),
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_arch_spec' ),
				'entityType' => 'architectural_specification',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_architectural_specification_settings', array() );
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
				<?php esc_html_e( 'Research & Add Architectural Specifications', 'mcp-ai-wpoos-pro' ); ?>
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
							<li><?php esc_html_e( 'Write professional construction specifications', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Follow CSI MasterFormat standards', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Organize by division and section numbers', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Include three-part section structure', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Specification Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'CSI Division:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Organize by MasterFormat divisions (03-Concrete, 09-Finishes)', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Three-part:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Include Part 1 (General), Part 2 (Products), Part 3 (Execution)', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Manufacturers:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Specify product names, model numbers, and acceptable alternatives', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Standards:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Reference industry standards (ASTM, ANSI, UL)', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'CSI Reports:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Request specification reports using Three-Part Format (Part 1: General/Scope/Submittals, Part 2: Products/Materials/Standards, Part 3: Execution/Installation/Quality Control)', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Write a specification for Division 03 - Concrete work">
								<?php esc_html_e( '"Write spec for Division 03 - Concrete"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create specifications for Division 09 - Interior finishes">
								<?php esc_html_e( '"Create Division 09 interior finishes spec"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate Division 07 specifications for roofing and waterproofing">
								<?php esc_html_e( '"Generate Division 07 roofing spec"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Generate a CSI MasterFormat specification report using Three-Part Format: Part 1 (General requirements, scope, submittals, quality assurance), Part 2 (Products, materials, manufacturers, standards), Part 3 (Execution, installation methods, quality control, testing)">
								<?php esc_html_e( '"Generate CSI three-part specification report..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_arch_spec' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Specifications', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_arch_spec' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Specification Manually', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
					</div>

					<div class="csi-divisions-reference" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px 16px; margin: 20px 0;">
						<h4 style="margin-top: 0;"><?php esc_html_e( 'CSI MasterFormat Divisions', 'mcp-ai-wpoos-pro' ); ?></h4>
						<div style="font-size: 13px; columns: 2; column-gap: 16px;">
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>00</strong> - Procurement and Contracting</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>01</strong> - General Requirements</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>02</strong> - Existing Conditions</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>03</strong> - Concrete</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>04</strong> - Masonry</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>05</strong> - Metals</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>06</strong> - Wood, Plastics, Composites</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>07</strong> - Thermal & Moisture Protection</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>08</strong> - Openings</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>09</strong> - Finishes</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>10</strong> - Specialties</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>11</strong> - Equipment</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>12</strong> - Furnishings</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>13</strong> - Special Construction</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>14</strong> - Conveying Equipment</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>21</strong> - Fire Suppression</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>22</strong> - Plumbing</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>23</strong> - HVAC</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>26</strong> - Electrical</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>27</strong> - Communications</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>28</strong> - Electronic Safety & Security</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>31</strong> - Earthwork</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>32</strong> - Exterior Improvements</div>
							<div style="break-inside: avoid; margin-bottom: 4px;"><strong>33</strong> - Utilities</div>
						</div>
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
								<p><?php esc_html_e( 'Write specifications with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import specification data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View specification quality and completeness', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive architectural specification tools.
							$spec_tools = array(
								// Material and cost analysis.
								'generate_material_schedule',
								'estimate_construction_cost',
								// Code compliance and structural analysis.
								'check_building_code_compliance',
								'analyze_structural_feasibility',
								'calculate_sustainability_metrics',
								// Report generation.
								'generate_research_report',
								// Research tools.
								'web_search',
								'search_content',
								'semantic_content_search',
							);
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="' . esc_attr( implode( ',', $spec_tools ) ) . '"]'
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
	 * Handle AJAX request to create specification from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_arch_spec', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create specifications.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) || empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process featured image generation request.
		$research_data = self::process_featured_image_request( $research_data, $research_data['title'], 'a construction specification document' );

		// Create the specification post.
		$post_data = array(
			'post_type'    => 'mcp_ai_arch_spec',
			'post_title'   => sanitize_text_field( $research_data['title'] ),
			'post_content' => wp_kses_post( $research_data['content'] ?? '' ),
			'post_status'  => 'publish',
		);

		$spec_id = wp_insert_post( $post_data );

		if ( is_wp_error( $spec_id ) ) {
			wp_send_json_error( array( 'message' => $spec_id->get_error_message() ) );
		}

		// Set featured image if generated.
		if ( ! empty( $research_data['featured_image_id'] ) ) {
			set_post_thumbnail( $spec_id, absint( $research_data['featured_image_id'] ) );
		}

		// Save metadata.
		$meta_fields = array( 'division', 'specification_type', 'part_structure', 'references' );
		foreach ( $meta_fields as $field ) {
			if ( isset( $research_data[ $field ] ) ) {
				update_post_meta( $spec_id, $field, sanitize_text_field( $research_data[ $field ] ) );
			}
		}

		// Return success with specification ID and edit URL.
		$edit_url = admin_url( 'post.php?post=' . $spec_id . '&action=edit' );

		wp_send_json_success(
			array(
				'message'  => __( 'Architectural specification created successfully!', 'mcp-ai-wpoos-pro' ),
				'spec_id'  => $spec_id,
				'edit_url' => $edit_url,
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
			'txt'  => 'TXT',
			'docx' => 'DOCX',
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
		return new WP_Error( 'not_implemented', __( 'Specification import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema for specification data.
	 *
	 * @return array Validation schema configuration.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title'              => __( 'Specification Title', 'mcp-ai-wpoos-pro' ),
				'division'           => __( 'CSI Division', 'mcp-ai-wpoos-pro' ),
				'specification_type' => __( 'Specification Type', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'part_structure' => __( 'Three-Part Structure', 'mcp-ai-wpoos-pro' ),
				'references'     => __( 'Industry References', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'division' => array( 'pattern' => '/^\d{2}$/' ),
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
	 * Calculate completeness score for specifications.
	 *
	 * @return array Completeness data with percentage and suggestions.
	 */
	protected static function calculate_completeness() {
		$specifications = get_posts(
			array(
				'post_type'      => 'mcp_ai_arch_spec',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $specifications );
		$complete = 0;

		foreach ( $specifications as $spec ) {
			$division           = get_post_meta( $spec->ID, 'division', true );
			$specification_type = get_post_meta( $spec->ID, 'specification_type', true );
			$part_structure     = get_post_meta( $spec->ID, 'part_structure', true );
			$references         = get_post_meta( $spec->ID, 'references', true );

			if ( ! empty( $division ) && ! empty( $specification_type ) && ! empty( $part_structure ) && ! empty( $references ) ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array(),
			'suggestions' => array(
				__( 'Organize all specifications by CSI MasterFormat divisions', 'mcp-ai-wpoos-pro' ),
				__( 'Include three-part structure (General, Products, Execution)', 'mcp-ai-wpoos-pro' ),
				__( 'Add manufacturer names and model numbers', 'mcp-ai-wpoos-pro' ),
				__( 'Reference industry standards (ASTM, ANSI, UL)', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Array of specification items for review.
	 */
	protected static function get_items_for_review() {
		$specifications = get_posts(
			array(
				'post_type'      => 'mcp_ai_arch_spec',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $specifications as $spec ) {
			$items[] = array(
				'id'    => $spec->ID,
				'title' => $spec->post_title,
				'meta'  => array(
					'division'           => get_post_meta( $spec->ID, 'division', true ),
					'specification_type' => get_post_meta( $spec->ID, 'specification_type', true ),
					'part_structure'     => get_post_meta( $spec->ID, 'part_structure', true ),
					'references'         => get_post_meta( $spec->ID, 'references', true ),
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

		if ( ! empty( $item['meta']['division'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing CSI division', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['specification_type'] ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing specification type', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['part_structure'] ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing three-part structure', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['references'] ) ) {
			$score += 20;
		} else {
			$issues[] = __( 'Missing industry references', 'mcp-ai-wpoos-pro' );
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
		check_ajax_referer( 'wp_mcp_ai_research_arch_spec', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import specifications.', 'mcp-ai-wpoos-pro' ) ) );
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
			<h2><?php esc_html_e( 'Import Architectural Specification Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import specifications from TXT, DOCX, PDF, CSV, JSON, or paste structured data. The AI will automatically parse and organize the specification information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include CSI division numbers (03-Concrete, 09-Finishes)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Follow three-part structure (Part 1: General, Part 2: Products, Part 3: Execution)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include manufacturer specifications and model numbers', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Reference industry standards (ASTM, ANSI, UL)', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".txt,.docx,.pdf,.csv,.json" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: TXT, DOCX, PDF, CSV, JSON', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nDivision: 03\nTitle: Concrete Formwork\nType: Technical Specification\n\nPart 1 - General:\nScope, references, submittals...\n\nPart 2 - Products:\nMaterials, manufacturers...\n\nPart 3 - Execution:\nInstallation procedures...', 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create specifications (recommended)', 'mcp-ai-wpoos-pro' ); ?>
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
		// Get specification statistics.
		$total_specs     = wp_count_posts( 'mcp_ai_arch_spec' );
		$published_count = isset( $total_specs->publish ) ? $total_specs->publish : 0;

		// Calculate data quality metrics.
		$specifications = get_posts(
			array(
				'post_type'      => 'mcp_ai_arch_spec',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count  = 0;
		$with_division   = 0;
		$with_structure  = 0;
		$with_references = 0;

		foreach ( $specifications as $spec ) {
			$division           = get_post_meta( $spec->ID, 'division', true );
			$specification_type = get_post_meta( $spec->ID, 'specification_type', true );
			$part_structure     = get_post_meta( $spec->ID, 'part_structure', true );
			$references         = get_post_meta( $spec->ID, 'references', true );

			if ( ! empty( $division ) ) {
				++$with_division;
			}
			if ( ! empty( $part_structure ) ) {
				++$with_structure;
			}
			if ( ! empty( $references ) ) {
				++$with_references;
			}
			if ( ! empty( $division ) && ! empty( $specification_type ) && ! empty( $part_structure ) && ! empty( $references ) ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Specification Quality Dashboard', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Specifications', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_division ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Division', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_structure ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Structure', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Specification completeness is %d%%. Consider adding CSI divisions, three-part structure, and industry references to improve quality.', 'mcp-ai-wpoos-pro' ),
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
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_arch_spec' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Specifications', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_arch_spec' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Specification', 'mcp-ai-wpoos-pro' ); ?>
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
