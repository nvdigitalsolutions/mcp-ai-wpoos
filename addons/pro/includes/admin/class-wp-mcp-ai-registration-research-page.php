<?php
/**
 * Registration Research Page for Regulatory Registration Toolkit.
 *
 * Provides AI-assisted registration research and creation interface.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Registration Research Page class.
 */
class WP_MCP_AI_Registration_Research_Page {
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
	const PAGE_SLUG = 'wp-mcp-ai-registration-research';

	/**
	 * Initialize the class.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 21 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_registration_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_registration', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_registration',
			__( 'Research & Add Registrations', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_ai_registration_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_registration' ),
				'entityType' => 'registration',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_registration_settings', array() );
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
				<?php esc_html_e( 'Research & Add Registration', 'mcp-ai-wpoos-pro' ); ?>
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
							<li><?php esc_html_e( 'Search existing registrations or research country requirements', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Review submission timelines and document checklists', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create registrations linked to products and countries', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Track approval status and expiry dates', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Check for existing registrations', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Country requirements:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Research specific regulatory frameworks', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Document checklist:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Verify all required documents', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Timeline planning:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Factor in 4-6 month approval times', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research registering a cosmetic product in Sri Lanka NMRA including timeline, fees, and required documents">
								<?php esc_html_e( '"Research registering a product in Sri Lanka..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Find information about UAE cosmetic registration requirements for MOHAP and Dubai Municipality">
								<?php esc_html_e( '"Find UAE registration requirements..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research multi-country registration strategy for GCC region with mutual recognition">
								<?php esc_html_e( '"Research multi-country GCC strategy..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_registration' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Registrations', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_registration' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Registration Manually', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_registration&page=wp-mcp-ai-registration-dashboard' ) ); ?>" class="button">
								<?php esc_html_e( 'View Dashboard', 'mcp-ai-wpoos-pro' ); ?>
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
								<p><?php esc_html_e( 'Research and create registrations with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import registration data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Status', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View registration status and expiry dates', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive registration tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="create_registration,list_registrations,get_registration,list_reg_products,get_reg_product,check_product_compliance,web_search"]'
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
	 * Render import workflow section.
	 */
	protected static function render_import_workflow() {
		self::render_import_section();
	}

	/**
	 * Render review workflow section.
	 */
	protected static function render_review_workflow() {
		self::render_consolidation_dashboard();
	}

	/**
	 * Handle AJAX request to create registration from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_registration', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create registrations.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized by tool execute method.
		$research_data_raw = isset( $_POST['research_data'] ) ? wp_unslash( $_POST['research_data'] ) : '';

		if ( empty( $research_data_raw ) ) {
			wp_send_json_error( array( 'message' => __( 'No research data provided.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$research_data = json_decode( $research_data_raw, true );

		// Validate JSON decoding.
		if ( null === $research_data || JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error( array( 'message' => __( 'Invalid JSON data format.', 'mcp-ai-wpoos-pro' ) ) );
		}

		if ( empty( $research_data['product_id'] ) && empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Product ID or registration title is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Use the create_registration tool to create the registration.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Registration' ) ) {
			wp_send_json_error( array( 'message' => __( 'Create Registration tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$tool   = new WP_MCP_AI_Tool_Create_Registration();
		$result = $tool->execute(
			$research_data,
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Return success with registration ID and edit URL.
		$registration_id = isset( $result['registration_id'] ) ? $result['registration_id'] : 0;
		$edit_url        = $registration_id > 0 ? admin_url( 'post.php?post=' . $registration_id . '&action=edit' ) : '';

		wp_send_json_success(
			array(
				'message'         => __( 'Registration created successfully!', 'mcp-ai-wpoos-pro' ),
				'registration_id' => $registration_id,
				'edit_url'        => $edit_url,
			)
		);
	}

	/**
	 * Get supported import formats.
	 *
	 * @return array Import formats.
	 */
	protected static function get_import_formats() {
		return array(
			'csv'  => 'CSV',
			'xlsx' => 'Excel',
			'json' => 'JSON',
		);
	}

	/**
	 * Process imported data.
	 *
	 * @param string $data   Import data.
	 * @param string $format Data format.
	 * @return array|WP_Error Result or error.
	 */
	protected static function process_import_data( $data, $format ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by trait interface.
		// This would integrate with the import_registrations_from_excel tool.
		return new WP_Error( 'not_implemented', __( 'Registration import will be handled through Excel import page.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Calculate completeness.
	 *
	 * @return array Completeness data.
	 */
	protected static function calculate_completeness() {
		$registrations = get_posts(
			array(
				'post_type'      => 'mcp_ai_registration',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total         = count( $registrations );
		$complete      = 0;
		$missing_items = array();

		foreach ( $registrations as $registration ) {
			$meta           = get_post_meta( $registration->ID );
			$has_product    = ! empty( $meta['product_id'][0] ?? '' );
			$has_country    = ! empty( $meta['country'][0] ?? '' );
			$has_submission = ! empty( $meta['submission_date'][0] ?? '' );

			if ( $has_product && $has_country && $has_submission ) {
				++$complete;
			} else {
				if ( ! $has_product ) {
					$missing_items[] = sprintf( '%s: Missing product link', $registration->post_title );
				}
				if ( ! $has_country ) {
					$missing_items[] = sprintf( '%s: Missing country', $registration->post_title );
				}
				if ( ! $has_submission ) {
					$missing_items[] = sprintf( '%s: Missing submission date', $registration->post_title );
				}
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array_slice( $missing_items, 0, 10 ),
			'suggestions' => array(
				__( 'Link all registrations to products', 'mcp-ai-wpoos-pro' ),
				__( 'Add country and regulatory authority', 'mcp-ai-wpoos-pro' ),
				__( 'Update submission and approval dates', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Items.
	 */
	protected static function get_items_for_review() {
		$registrations = get_posts(
			array(
				'post_type'      => 'mcp_ai_registration',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $registrations as $registration ) {
			$items[] = array(
				'id'    => $registration->ID,
				'title' => $registration->post_title,
				'meta'  => get_post_meta( $registration->ID ),
			);
		}

		return $items;
	}

	/**
	 * Calculate quality score for item.
	 *
	 * @param array $item Item data.
	 * @return array Quality data.
	 */
	protected static function calculate_quality_score( $item ) {
		$score  = 0;
		$issues = array();
		$meta   = $item['meta'] ?? array();

		// Check required fields (20 points each).
		$required_fields = array(
			'product_id'      => __( 'Product Link', 'mcp-ai-wpoos-pro' ),
			'country'         => __( 'Country', 'mcp-ai-wpoos-pro' ),
			'submission_date' => __( 'Submission Date', 'mcp-ai-wpoos-pro' ),
			'approval_date'   => __( 'Approval Date', 'mcp-ai-wpoos-pro' ),
			'expiry_date'     => __( 'Expiry Date', 'mcp-ai-wpoos-pro' ),
		);

		foreach ( $required_fields as $field => $label ) {
			if ( ! empty( $meta[ $field ][0] ?? '' ) ) {
				$score += 20;
			} else {
				$issues[] = sprintf(
					/* translators: %s: Field label */
					__( 'Missing %s', 'mcp-ai-wpoos-pro' ),
					$label
				);
			}
		}

		// Determine quality level.
		if ( $score >= 90 ) {
			$level = 'high';
		} elseif ( $score >= 60 ) {
			$level = 'medium';
		} else {
			$level = 'low';
		}

		return array(
			'score'  => $score,
			'level'  => $level,
			'status' => $score >= 90 ? __( 'Complete', 'mcp-ai-wpoos-pro' ) : __( 'Incomplete', 'mcp-ai-wpoos-pro' ),
			'issues' => $issues,
		);
	}
}

WP_MCP_AI_Registration_Research_Page::init();
