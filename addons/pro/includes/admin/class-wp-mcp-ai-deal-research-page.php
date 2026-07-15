<?php
/**
 * Research & Add admin page for Deal CPT.
 *
 * Provides a dedicated page for researching and creating deals/opportunities,
 * with AI-powered pipeline stage analysis, forecasting, and deal structuring.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.1.24
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Deal Research Admin Page
 *
 * Adds a submenu page under Deals CPT menu for AI-powered deal research and creation.
 */
class WP_MCP_AI_Deal_Research_Page {
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
	const PAGE_SLUG = 'research-deal';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 26 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_deal_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_deal', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under Deals CPT menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_deal',
			__( 'Research & Add Deal', 'mcp-ai-wpoos-pro' ),
			__( 'Deal Research & Add', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_ai_deal_page_' . self::PAGE_SLUG !== $hook ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page slug check for asset enqueue.
			if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
				return;
			}
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
				'entityType' => 'deal',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get CRM toolkit settings for the assigned research assistant.
		$crm_settings       = class_exists( 'WP_MCP_AI_CRM_Engine' ) ? WP_MCP_AI_CRM_Engine::get_toolkit_settings() : array();
		$assigned_assistant = isset( $crm_settings['research_assistant'] ) ? $crm_settings['research_assistant'] : 'default';

		// Resolve assistant name to ID if needed.
		$assistant_id = 0;
		if ( is_numeric( $assigned_assistant ) ) {
			$assistant_id = absint( $assigned_assistant );
		} elseif ( 'default' !== $assigned_assistant && ! empty( $assigned_assistant ) ) {
			$assistant_post = get_page_by_path( $assigned_assistant, OBJECT, 'mcp_ai_assistant' );
			if ( ! $assistant_post ) {
				$assistant_post = get_posts(
					array(
						'post_type'      => 'mcp_ai_assistant',
						'title'          => $assigned_assistant,
						'posts_per_page' => 1,
					)
				);
				$assistant_post = ! empty( $assistant_post ) ? $assistant_post[0] : null;
			}
			if ( $assistant_post ) {
				$assistant_id = $assistant_post->ID;
			}
		}

		// Fallback: get first available assistant.
		if ( ! $assistant_id || 'publish' !== get_post_status( $assistant_id ) ) {
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
		}

		?>
		<div class="wrap wp-mcp-ai-research-page">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Research & Add Deal', 'mcp-ai-wpoos-pro' ); ?>
			</h1>

			<hr class="wp-header-end">

			<?php self::render_chat_interface( $assistant_id ); ?>
		</div>
		<?php
	}

	/**
	 * Render the enhanced chat interface with workflow selector.
	 *
	 * @param int $assistant_id Assistant post ID.
	 */
	protected static function render_chat_interface( $assistant_id ) {
		// Deal-specific tools available for research.
		$deal_tools = array(
			// Deal CRUD.
			'create_deal',
			'get_pipeline_view',
			'forecast_pipeline_revenue',
			'get_conversion_funnel',
			// Related CRM data.
			'create_company',
			'get_companies',
			'create_lead',
			'manage_crm_contact',
			// Activities.
			'create_crm_activity',
			'list_crm_activities',
			'complete_crm_activity',
			'crm_capture_interaction',
			// Research → Paper Store pipeline.
			'generate_research_report',
			'create_post_from_research',
			// Web research.
			'web_search',
			'search_content',
			'semantic_content_search',
			'crawl_website',
			// Command center.
			'get_workflow_inbox',
			'create_workflow_rule',
			'get_owner_workload',
		);

		// Get current mode from query string for initial workflow.
		$current_mode     = self::get_current_mode();
		$initial_workflow = ( 'import' === $current_mode ) ? 'import' : ( ( 'consolidate' === $current_mode ) ? 'review' : 'research' );
		?>
		<script>
			window.wpMcpAiResearchPage = window.wpMcpAiResearchPage || {};
			window.wpMcpAiResearchPage.initialWorkflow = <?php echo wp_json_encode( $initial_workflow ); ?>;
		</script>

		<div class="wp-mcp-ai-research-container">
			<!-- Sidebar -->
			<div class="wp-mcp-ai-research-sidebar">
				<div class="wp-mcp-ai-research-intro">
					<h2><?php esc_html_e( 'How It Works', 'mcp-ai-wpoos-pro' ); ?></h2>
					<ol>
						<li><?php esc_html_e( 'Research deal opportunities and pipeline stages', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Structure deal details with AI assistance', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Analyze pipeline health and forecast revenue', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Create deal records directly in your CRM', 'mcp-ai-wpoos-pro' ); ?></li>
					</ol>
				</div>

				<div class="wp-mcp-ai-research-tips">
					<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
					<ul>
						<li><strong><?php esc_html_e( 'Pipeline:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Analyze pipeline health and forecasting accuracy', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Structuring:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Structure deal details with stage, amount, and probability', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Forecasting:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Weighted and unweighted pipeline forecasts', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Analytics:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Track win/loss rates and conversion metrics', 'mcp-ai-wpoos-pro' ); ?></li>
					</ul>
				</div>

				<div class="wp-mcp-ai-research-examples">
					<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
					<ul class="wp-mcp-ai-example-list">
						<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a deal for Acme Corp worth $50,000 at the proposal stage with 60% probability">
							<?php esc_html_e( '"Create a deal for Acme Corp..."', 'mcp-ai-wpoos-pro' ); ?>
						</button></li>
						<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Analyze my current pipeline and forecast revenue for Q3">
							<?php esc_html_e( '"Analyze pipeline and forecast..."', 'mcp-ai-wpoos-pro' ); ?>
						</button></li>
						<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Show me all deals in negotiation stage and suggest strategies to close them">
							<?php esc_html_e( '"Show deals in negotiation..."', 'mcp-ai-wpoos-pro' ); ?>
						</button></li>
						<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="List deals with high probability but no activity in the last 30 days">
							<?php esc_html_e( '"List stalled high-probability deals..."', 'mcp-ai-wpoos-pro' ); ?>
						</button></li>
					</ul>
				</div>

				<div class="wp-mcp-ai-research-actions">
					<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_deal' ) ); ?>" class="button">
							<?php esc_html_e( 'View All Deals', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_deal' ) ); ?>" class="button">
							<?php esc_html_e( 'Add Deal Manually', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				</div>
			</div>

			<!-- Main Content -->
			<div class="wp-mcp-ai-research-main">
				<!-- Workflow Mode Selector -->
				<div class="wp-mcp-ai-workflow-selector">
					<h2><?php esc_html_e( 'Choose Your Workflow', 'mcp-ai-wpoos-pro' ); ?></h2>
					<div class="workflow-options">
						<button type="button" class="workflow-option active" data-workflow="research">
							<span class="dashicons dashicons-format-chat"></span>
							<strong><?php esc_html_e( 'AI Research', 'mcp-ai-wpoos-pro' ); ?></strong>
							<p><?php esc_html_e( 'Research and create deals with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
						</button>
						<button type="button" class="workflow-option" data-workflow="import">
							<span class="dashicons dashicons-upload"></span>
							<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
							<p><?php esc_html_e( 'Bulk import deal data', 'mcp-ai-wpoos-pro' ); ?></p>
						</button>
						<button type="button" class="workflow-option" data-workflow="review">
							<span class="dashicons dashicons-analytics"></span>
							<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
							<p><?php esc_html_e( 'View pipeline health and deal quality', 'mcp-ai-wpoos-pro' ); ?></p>
						</button>
					</div>
				</div>

				<!-- AI Research Workflow (Default) -->
				<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="' . esc_attr( implode( ',', $deal_tools ) ) . '"]'
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
		?>
		<div class="wp-mcp-ai-import-section">
			<h2><?php esc_html_e( 'Import Deal Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import deals from CSV, JSON, or paste structured data. The AI will automatically parse and organize the deal information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include deal name, amount, and stage', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify currency and close date', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Add probability and deal owner', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Link to contacts and companies if available', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_deals', 'import_nonce' ); ?>

					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".csv,.json,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: CSV, JSON, TXT', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea
						id="wp-mcp-ai-import-text"
						name="import_data"
						class="widefat"
						rows="12"
						placeholder="<?php esc_attr_e( "Example:\n\nDeal Name: Enterprise License - Acme Corp\nAmount: 50000\nCurrency: USD\nStage: proposal\nProbability: 60\nClose Date: 2026-06-30\nOwner: 1\n\nDeal Name: SMB Package - Globex Inc\nAmount: 12000\nCurrency: USD\nStage: qualification\nProbability: 30", 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>

					<div class="import-options">
						<label for="auto-create-deals">
							<input type="checkbox" id="auto-create-deals" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create deals (recommended)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<label for="validate-deal-data">
							<input type="checkbox" id="validate-deal-data" name="validate_data" value="1" checked>
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
	 * Render review & quality workflow section.
	 */
	protected static function render_review_workflow() {
		$total_deals     = wp_count_posts( 'mcp_ai_deal' );
		$published_count = isset( $total_deals->publish ) ? $total_deals->publish : 0;

		$deals = get_posts(
			array(
				'post_type'      => 'mcp_ai_deal',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count = 0;
		$with_amount    = 0;
		$with_stage     = 0;
		$won_count      = 0;
		$total_value    = 0;

		foreach ( $deals as $deal ) {
			$amount = get_post_meta( $deal->ID, 'deal_amount', true );
			$stage  = get_post_meta( $deal->ID, 'deal_stage', true );

			if ( $amount ) {
				++$with_amount;
				$total_value += floatval( $amount );
			}
			if ( $stage ) {
				++$with_stage;
			}
			if ( 'closed_won' === $stage ) {
				++$won_count;
			}
			if ( $amount && $stage ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;
		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Deal Pipeline Dashboard', 'mcp-ai-wpoos-pro' ); ?></h2>

			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Deals', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $won_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Closed Won', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( number_format( $total_value, 0 ) ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Pipeline Value', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Deal data completeness is %d%%. Ensure deals have amount and stage for accurate pipeline forecasting.', 'mcp-ai-wpoos-pro' ),
								esc_html( $completeness )
							);
							?>
						</p>
					</div>
				<?php endif; ?>
			</div>

			<div class="items-list-table">
				<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_deal' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Deals', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_deal' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Deal', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to create deal from research.
	 */
	public static function handle_create_from_research() {
		check_ajax_referer( 'wp_mcp_ai_research_page', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$deal_name   = isset( $_POST['deal_name'] ) ? sanitize_text_field( wp_unslash( $_POST['deal_name'] ) ) : '';
		$deal_amount = isset( $_POST['deal_amount'] ) ? floatval( $_POST['deal_amount'] ) : 0;

		if ( empty( $deal_name ) ) {
			wp_send_json_error( array( 'message' => __( 'Deal name is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Create the deal post.
		$post_data = array(
			'post_title'  => $deal_name,
			'post_type'   => 'mcp_ai_deal',
			'post_status' => 'publish',
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Save deal metadata.
		$deal_stage       = isset( $_POST['deal_stage'] ) ? sanitize_text_field( wp_unslash( $_POST['deal_stage'] ) ) : 'prospecting';
		$deal_probability = isset( $_POST['deal_probability'] ) ? floatval( $_POST['deal_probability'] ) / 100 : 0.5;

		$meta_fields = array(
			'deal_amount'      => 'deal_amount',
			'deal_currency'    => 'deal_currency',
			'deal_stage'       => 'deal_stage',
			'deal_probability' => 'deal_probability',
			'close_date'       => 'close_date',
			'deal_owner'       => 'deal_owner',
			'deal_source'      => 'deal_source',
			'deal_type'        => 'deal_type',
			'contact_id'       => 'contact_id',
			'company_id'       => 'company_id',
			'deal_notes'       => 'deal_notes',
		);

		foreach ( $meta_fields as $meta_key => $post_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				if ( 'deal_amount' === $meta_key ) {
					$value = floatval( $_POST[ $post_key ] );
				} elseif ( 'deal_probability' === $meta_key ) {
					$value = floatval( $_POST[ $post_key ] ) / 100;
				} elseif ( 'deal_notes' === $post_key ) {
					$value = sanitize_textarea_field( wp_unslash( $_POST[ $post_key ] ) );
				} else {
					$value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
				}
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Deal created successfully!', 'mcp-ai-wpoos-pro' ),
				'post_id'  => $post_id,
				'edit_url' => get_edit_post_link( $post_id, 'raw' ),
			)
		);
	}

	/**
	 * Handle AJAX import (placeholder for future bulk import functionality).
	 */
	public static function ajax_handle_import() {
		check_ajax_referer( 'wp_mcp_ai_research_page', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Import functionality coming soon.', 'mcp-ai-wpoos-pro' ) ) );
	}
}
