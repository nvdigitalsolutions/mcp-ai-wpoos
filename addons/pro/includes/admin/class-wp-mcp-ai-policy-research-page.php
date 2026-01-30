<?php
/**
 * Research & Add admin page for Policy CPT.
 *
 * Provides a dedicated page for researching insurance policies before adding them,
 * with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Policy Research Admin Page
 *
 * Adds a submenu page under Policies menu for AI-powered policy research.
 */
class WP_MCP_AI_Policy_Research_Page {
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
	const PAGE_SLUG = 'research-policy';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_policy_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_policy', array( __CLASS__, 'handle_import_policy' ) );
	}

	/**
	 * Add submenu page under Policies menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_policy',
			__( 'Research & Add Policy', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_ai_policy_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_policy' ),
				'entityType' => 'policy',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_policy_settings', array() );
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
				<?php esc_html_e( 'Research & Add Policy', 'mcp-ai-wpoos-pro' ); ?>
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
							<li><?php esc_html_e( 'Search existing policies or research new ones on the web', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Use deep research for comprehensive policy analysis', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Find similar policies with semantic search', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create policy entries directly in your database', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Check existing policies to avoid duplicates', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Deep research:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Use for comprehensive policy analysis', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Find similar:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Use semantic search for related policies', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Compare options:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Research multiple policy types or providers', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research comprehensive pet health insurance coverage including preventive care">
								<?php esc_html_e( '"Research pet health insurance..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Find information about life insurance policy types for families">
								<?php esc_html_e( '"Find life insurance policy types..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research dental insurance with orthodontic coverage">
								<?php esc_html_e( '"Research dental insurance..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_policy' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Policies', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_policy' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Policy Manually', 'mcp-ai-wpoos-pro' ); ?>
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
								<p><?php esc_html_e( 'Research and create policies with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import policy data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View policy quality and completeness', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive policy tools.
							// Includes research, creation, management, and content discovery tools.
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="research_policy,create_policy,get_policy,web_search,deep_research,search_content,semantic_content_search"]'
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
	 * Get supported import formats.
	 *
	 * @return array Array of format => label pairs.
	 */
	protected static function get_import_formats() {
		return array(
			'pdf'  => 'PDF',
			'docx' => 'DOCX',
			'txt'  => 'Plain Text',
			'html' => 'HTML',
		);
	}

	/**
	 * Process import data based on format.
	 *
	 * @param mixed  $data   The data to process.
	 * @param string $format The format of the data.
	 * @return array|WP_Error Processed data or error.
	 */
	protected static function process_import_data( $data, $format ) {
		// Suppress unused parameter warnings - implementation coming soon.
		unset( $data, $format );
		return new WP_Error( 'not_implemented', __( 'Policy import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema for policy data.
	 *
	 * @return array Validation schema with required/recommended fields and rules.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title'          => __( 'Policy Title', 'mcp-ai-wpoos-pro' ),
				'content'        => __( 'Policy Content', 'mcp-ai-wpoos-pro' ),
				'effective_date' => __( 'Effective Date', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'category'       => __( 'Policy Category', 'mcp-ai-wpoos-pro' ),
				'version'        => __( 'Version Number', 'mcp-ai-wpoos-pro' ),
				'review_date'    => __( 'Review Date', 'mcp-ai-wpoos-pro' ),
				'compliance_tag' => __( 'Compliance Tags', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'effective_date' => array( 'type' => 'datetime' ),
				'review_date'    => array( 'type' => 'datetime' ),
			),
			'quality_dimensions' => array(
				'wcag_compliance',
				'legal_accuracy',
				'completeness',
				'readability',
			),
		);
	}

	/**
	 * Calculate completeness percentage for policies.
	 *
	 * @return array Array with percentage, missing items, and suggestions.
	 */
	protected static function calculate_completeness() {
		$policies = get_posts(
			array(
				'post_type'      => 'mcp_ai_policy',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $policies );
		$complete = 0;

		foreach ( $policies as $policy ) {
			$effective_date = get_post_meta( $policy->ID, 'effective_date', true );
			$category       = get_post_meta( $policy->ID, 'category', true );
			if ( ! empty( $effective_date ) && ! empty( $category ) && ! empty( $policy->post_content ) ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		return array(
			'percentage'  => $percentage,
			'missing'     => array(),
			'suggestions' => array(
				__( 'Set effective dates for all policies', 'mcp-ai-wpoos-pro' ),
				__( 'Categorize policies properly', 'mcp-ai-wpoos-pro' ),
				__( 'Ensure WCAG compliance', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get policies for review.
	 *
	 * @return array Array of policy items with metadata.
	 */
	protected static function get_items_for_review() {
		$policies = get_posts(
			array(
				'post_type'      => 'mcp_ai_policy',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $policies as $policy ) {
			$items[] = array(
				'id'    => $policy->ID,
				'title' => $policy->post_title,
				'meta'  => array(
					'effective_date' => get_post_meta( $policy->ID, 'effective_date', true ),
					'category'       => get_post_meta( $policy->ID, 'category', true ),
					'version'        => get_post_meta( $policy->ID, 'version', true ),
				),
			);
		}

		return $items;
	}

	/**
	 * Calculate quality score for a policy item.
	 *
	 * @param array $item Policy item with title and meta.
	 * @return array Quality score data with score, level, status, and issues.
	 */
	protected static function calculate_quality_score( $item ) {
		$score  = 0;
		$issues = array();

		if ( ! empty( $item['meta']['effective_date'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing effective date', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['category'] ) ) {
			$score += 30;
		} else {
			$issues[] = __( 'Missing category', 'mcp-ai-wpoos-pro' );
		}

		if ( ! empty( $item['meta']['version'] ) ) {
			$score += 20;
		} else {
			$issues[] = __( 'Missing version number', 'mcp-ai-wpoos-pro' );
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
	 * Handle AJAX request for policy import.
	 */
	public static function handle_import_policy() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_policy', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import policies.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get import data.
		$format = isset( $_POST['format'] ) ? sanitize_text_field( wp_unslash( $_POST['format'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized in process_import_data.
		$data = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';

		if ( empty( $format ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid import data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Validate format.
		$formats = self::get_import_formats();
		if ( ! isset( $formats[ $format ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unsupported import format.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process import.
		$result = self::process_import_data( $data, $format );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Handle AJAX request to create policy from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_policy', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create policies.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) || empty( $research_data['policy_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Process featured image generation request.
		$research_data = self::process_featured_image_request( $research_data, $research_data['policy_name'], 'a policy' );

		// Use the create_policy tool to create the policy.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Policy' ) ) {
			wp_send_json_error( array( 'message' => __( 'Create policy tool not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$tool   = new WP_MCP_AI_Tool_Create_Policy();
		$result = $tool->execute(
			$research_data,
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Return success with policy ID and edit URL.
		$policy_id = isset( $result['policy_id'] ) ? $result['policy_id'] : 0;
		$edit_url  = $policy_id > 0 ? admin_url( 'post.php?post=' . $policy_id . '&action=edit' ) : '';

		wp_send_json_success(
			array(
				'message'   => __( 'Policy created successfully!', 'mcp-ai-wpoos-pro' ),
				'policy_id' => $policy_id,
				'edit_url'  => $edit_url,
			)
		);
	}

	/**
	 * Render import workflow.
	 */
	protected static function render_import_workflow() {
		?>
		<div class="wp-mcp-ai-import-section">
			<h2><?php esc_html_e( 'Import Policy Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import policies from PDF, DOCX, HTML, or paste structured data. The AI will automatically parse and organize the policy information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include policy title, content, and effective date', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify category and version number', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Add review dates and compliance tags', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Ensure WCAG compliance and readability', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_policies', 'import_nonce' ); ?>
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".pdf,.docx,.html,.txt,.json" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: PDF, DOCX, HTML, TXT, JSON', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nTitle: Privacy Policy\nContent: This privacy policy explains...\nEffective Date: 2024-01-01\nCategory: Legal\nVersion: 1.0\n\nTitle: Terms of Service\nContent: By using this service...\nEffective Date: 2024-01-01\nCategory: Legal\nVersion: 1.0', 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create policies (recommended)', 'mcp-ai-wpoos-pro' ); ?>
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
		// Get policy statistics.
		$total_policies  = wp_count_posts( 'mcp_ai_policy' );
		$published_count = isset( $total_policies->publish ) ? $total_policies->publish : 0;

		// Calculate data quality metrics.
		$policies = get_posts(
			array(
				'post_type'      => 'mcp_ai_policy',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count      = 0;
		$with_effective_date = 0;
		$with_category       = 0;

		foreach ( $policies as $policy ) {
			$effective_date = get_post_meta( $policy->ID, 'effective_date', true );
			$category       = get_post_meta( $policy->ID, 'category', true );
			$has_content    = ! empty( $policy->post_content );

			if ( ! empty( $effective_date ) ) {
				++$with_effective_date;
			}
			if ( ! empty( $category ) ) {
				++$with_category;
			}
			if ( ! empty( $effective_date ) && ! empty( $category ) && $has_content ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Policy Quality Dashboard', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Policies', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_effective_date ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Effective Date', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_category ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Category', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Policy completeness is %d%%. Consider adding effective dates, categories, and content to improve quality.', 'mcp-ai-wpoos-pro' ),
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
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_policy' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Policies', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_policy' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Policy', 'mcp-ai-wpoos-pro' ); ?>
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

// Initialize.
WP_MCP_AI_Policy_Research_Page::init();
