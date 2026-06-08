<?php
/**
 * Research & Add admin page for Customer CPT.
 *
 * Provides a dedicated page for researching and adding customers,
 * with AI-powered chat interface, customer health scoring, and lifecycle analysis.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.7.0
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
 * Customer Research Admin Page
 *
 * Adds a submenu page under Customers CPT menu for AI-powered customer research and creation.
 */
class WP_MCP_AI_Customer_Research_Page {
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
	const PAGE_SLUG = 'research-customer';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 26 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_customer_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_customer', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under Customers CPT menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_customer',
			__( 'Research & Add Customer', 'mcp-ai-wpoos-pro' ),
			__( 'Customer Research & Add', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_ai_customer_page_' . self::PAGE_SLUG !== $hook ) {
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
				'entityType' => 'customer',
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
				<?php esc_html_e( 'Research & Add Customer', 'mcp-ai-wpoos-pro' ); ?>
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
		// Customer-specific tools available for research.
		$customer_tools = array(
			// Customer CRUD.
			'create_customer',
			'get_customer',
			'list_customers',
			// Lead context (source of customers).
			'list_leads',
			'get_lead',
			'convert_lead_to_customer',
			// Company research (for customer context).
			'get_companies',
			'research_company',
			// Deal context.
			'list_deals',
			'get_deal',
			// Activities and interactions.
			'create_crm_activity',
			'list_crm_activities',
			// Web research.
			'web_search',
			'search_content',
			'semantic_content_search',
			'crawl_website',
			// Command center.
			'get_workflow_inbox',
			'auto_route_inbound_message',
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
						<li><?php esc_html_e( 'Research customers by industry, revenue, or lifecycle', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Analyse customer health, churn risk & LTV', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Convert qualified leads into customer records', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Manage customer lifecycle stages in your CRM', 'mcp-ai-wpoos-pro' ); ?></li>
					</ol>
				</div>

				<div class="wp-mcp-ai-research-tips">
					<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
					<ul>
						<li><strong><?php esc_html_e( 'Conversion:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Convert qualified leads to customers instantly', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Health Analysis:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Assess customer health, engagement, and churn risk', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Revenue Tracking:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Monitor lifetime value and revenue trends', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Lifecycle:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Manage stages from customer to evangelist', 'mcp-ai-wpoos-pro' ); ?></li>
					</ul>
				</div>

				<div class="wp-mcp-ai-research-examples">
					<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
					<ul class="wp-mcp-ai-example-list">
						<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Find all customers with lifetime value over $10,000 and no recent deals">
							<?php esc_html_e( '"Find high-LTV customers at risk..."', 'mcp-ai-wpoos-pro' ); ?>
						</button></li>
						<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Analyse my customer base by lifecycle stage and show revenue breakdown">
							<?php esc_html_e( '"Analyse customer lifecycle stages..."', 'mcp-ai-wpoos-pro' ); ?>
						</button></li>
						<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Convert the lead 'Jane Smith' to a customer with a $50,000 annual deal">
							<?php esc_html_e( '"Convert lead to customer..."', 'mcp-ai-wpoos-pro' ); ?>
						</button></li>
						<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Show me my top 10 customers by total revenue with their deal history">
							<?php esc_html_e( '"Show top 10 customers by revenue..."', 'mcp-ai-wpoos-pro' ); ?>
						</button></li>
					</ul>
				</div>

				<div class="wp-mcp-ai-research-actions">
					<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_customer' ) ); ?>" class="button">
							<?php esc_html_e( 'View All Customers', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_customer' ) ); ?>" class="button">
							<?php esc_html_e( 'Add Customer Manually', 'mcp-ai-wpoos-pro' ); ?>
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
							<p><?php esc_html_e( 'Research and manage customers with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
						</button>
						<button type="button" class="workflow-option" data-workflow="import">
							<span class="dashicons dashicons-upload"></span>
							<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
							<p><?php esc_html_e( 'Bulk import customer data', 'mcp-ai-wpoos-pro' ); ?></p>
						</button>
						<button type="button" class="workflow-option" data-workflow="review">
							<span class="dashicons dashicons-analytics"></span>
							<strong><?php esc_html_e( 'Review & Health', 'mcp-ai-wpoos-pro' ); ?></strong>
							<p><?php esc_html_e( 'View customer health and lifecycle metrics', 'mcp-ai-wpoos-pro' ); ?></p>
						</button>
					</div>
				</div>

				<!-- AI Research Workflow (Default) -->
				<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="' . esc_attr( implode( ',', $customer_tools ) ) . '"]'
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

				<!-- Review & Health Workflow -->
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
			<h2><?php esc_html_e( 'Import Customer Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import customers from CSV, JSON, or paste structured data. The AI will automatically parse and organize the customer information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include customer name, email, and company', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify lifecycle stage and total revenue', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Add lifetime value and customer since date', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include source lead ID for traceability', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_customers', 'import_nonce' ); ?>

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
						placeholder="<?php esc_attr_e( "Example:\n\nCustomer Name: John Smith\nEmail: john@example.com\nCompany: Acme Corp\nLifecycle Stage: customer\nTotal Revenue: 150000\nLTV: 250000\nCustomer Since: 2025-01-15\nSource Lead ID: 123\n\nCustomer Name: Jane Doe\nEmail: jane@globex.com\nCompany: Globex Inc\nLifecycle Stage: evangelist\nTotal Revenue: 320000\nLTV: 500000\nCustomer Since: 2024-06-01", 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>

					<div class="import-options">
						<label for="auto-create-customers">
							<input type="checkbox" id="auto-create-customers" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create customers (recommended)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<label for="validate-customer-data">
							<input type="checkbox" id="validate-customer-data" name="validate_data" value="1" checked>
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
	 * Render review & health workflow section.
	 *
	 * Industry-standard Customer 360 dashboard with health score, revenue KPIs,
	 * lifecycle segments, at-risk detection, and actionable insights — aligned
	 * with Salesforce Customer 360, HubSpot Service Hub, and Gainsight patterns.
	 */
	protected static function render_review_workflow() {
		$total_customers     = wp_count_posts( 'mcp_ai_customer' );
		$published_count     = isset( $total_customers->publish ) ? $total_customers->publish : 0;

		$customers = get_posts(
			array(
				'post_type'      => 'mcp_ai_customer',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		// --- Compute industry-standard customer health metrics ---
		$total_revenue     = 0;
		$total_ltv         = 0;
		$with_revenue      = 0;
		$with_email        = 0;
		$with_ltv          = 0;
		$complete_count    = 0;
		$at_risk_count     = 0;
		$healthy_count     = 0;
		$high_value_count  = 0; // LTV > $50k

		// Lifecycle stage buckets.
		$stages = array(
			'customer'   => 0,
			'evangelist' => 0,
			'other'      => 0,
		);

		// Revenue tier segmentation.
		$revenue_tiers = array(
			'enterprise' => 0, // $100k+
			'mid_market' => 0, // $10k-$100k
			'smb'        => 0, // < $10k
			'unknown'    => 0,
		);

		foreach ( $customers as $customer ) {
			$email     = get_post_meta( $customer->ID, 'email', true );
			$revenue   = floatval( get_post_meta( $customer->ID, 'total_revenue', true ) );
			$ltv       = floatval( get_post_meta( $customer->ID, 'lifetime_value', true ) );
			$lifecycle = get_post_meta( $customer->ID, 'lifecycle_stage', true );

			if ( $email ) {
				++$with_email;
			}
			if ( $revenue > 0 ) {
				$total_revenue += $revenue;
				++$with_revenue;

				// Revenue tier classification.
				if ( $revenue >= 100000 ) {
					++$revenue_tiers['enterprise'];
				} elseif ( $revenue >= 10000 ) {
					++$revenue_tiers['mid_market'];
				} else {
					++$revenue_tiers['smb'];
				}
			} else {
				++$revenue_tiers['unknown'];
			}
			if ( $ltv > 0 ) {
				$total_ltv += $ltv;
				++$with_ltv;

				if ( $ltv >= 50000 ) {
					++$high_value_count;
				}
			}

			// Lifecycle stage bucketing.
			if ( 'evangelist' === $lifecycle ) {
				++$stages['evangelist'];
			} elseif ( 'customer' === $lifecycle || empty( $lifecycle ) ) {
				++$stages['customer'];
			} else {
				++$stages['other'];
			}

			// Completeness: has email, revenue, and LTV.
			if ( $email && $revenue > 0 && $ltv > 0 ) {
				++$complete_count;
			}

			// At-risk: no deals in last 90 days (simple heuristic using customer meta).
			// We check the _wp_mcp_ai_customer_last_deal_date meta (set by deal tools)
			// instead of querying deals per-customer, avoiding N+1 queries.
			$last_deal_date = get_post_meta( $customer->ID, '_wp_mcp_ai_customer_last_deal_date', true );
			$cutoff         = strtotime( '-90 days' );
			if ( empty( $last_deal_date ) || strtotime( $last_deal_date ) < $cutoff ) {
				++$at_risk_count;
			} else {
				++$healthy_count;
			}
		}

		// --- Derived KPIs ---
		$completeness     = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;
		$avg_revenue      = $with_revenue > 0 ? round( $total_revenue / $with_revenue, 2 ) : 0;
		$avg_ltv          = $with_ltv > 0 ? round( $total_ltv / $with_ltv, 2 ) : 0;
		$health_score     = $published_count > 0 ? round( ( $healthy_count / $published_count ) * 100 ) : 0;
		$churn_risk_pct   = $published_count > 0 ? round( ( $at_risk_count / $published_count ) * 100 ) : 0;
		$evangelist_pct   = $published_count > 0 ? round( ( $stages['evangelist'] / $published_count ) * 100 ) : 0;
		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Customer 360 — Health & Insights', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Composite health view combining revenue, engagement, lifecycle stage, and recency signals — aligned with Customer 360 industry standards.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<!-- Row 1: Health Score + Risk -->
			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
				<div class="toolkit-stat-card" style="background: <?php echo $health_score >= 70 ? '#edfaef' : ( $health_score >= 40 ? '#fef8ee' : '#fcf0f1' ); ?>; padding: 20px; border-left: 4px solid <?php echo $health_score >= 70 ? '#00a32a' : ( $health_score >= 40 ? '#dba617' : '#d63638' ); ?>;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Customer Health Score', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p style="font-size: 36px; margin: 10px 0; font-weight: bold;"><?php echo esc_html( $health_score ); ?>%</p>
					<p style="color: #666;">
						<?php
						printf(
							/* translators: %d: Number of healthy customers */
							esc_html__( '%d customers actively engaged', 'mcp-ai-wpoos-pro' ),
							absint( $healthy_count )
						);
						?>
					</p>
				</div>
				<div class="toolkit-stat-card" style="background: <?php echo $churn_risk_pct <= 30 ? '#edfaef' : ( $churn_risk_pct <= 60 ? '#fef8ee' : '#fcf0f1' ); ?>; padding: 20px; border-left: 4px solid <?php echo $churn_risk_pct <= 30 ? '#00a32a' : ( $churn_risk_pct <= 60 ? '#dba617' : '#d63638' ); ?>;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'At-Risk Customers', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p style="font-size: 36px; margin: 10px 0; font-weight: bold;"><?php echo esc_html( $churn_risk_pct ); ?>%</p>
					<p style="color: #666;">
						<?php
						printf(
							/* translators: %d: Number of at-risk customers */
							esc_html__( '%d with no deals in 90 days', 'mcp-ai-wpoos-pro' ),
							absint( $at_risk_count )
						);
						?>
					</p>
				</div>
			</div>

			<!-- Row 2: Revenue KPIs -->
			<h3><?php esc_html_e( 'Revenue Health', 'mcp-ai-wpoos-pro' ); ?></h3>
			<div class="quality-metrics" style="margin-bottom: 20px;">
				<div class="quality-metric">
					<span class="quality-metric-value"><?php echo esc_html( '$' . number_format_i18n( $total_revenue, 0 ) ); ?></span>
					<span class="quality-metric-label"><?php esc_html_e( 'Total Revenue', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="quality-metric">
					<span class="quality-metric-value"><?php echo esc_html( '$' . number_format_i18n( $avg_revenue, 0 ) ); ?></span>
					<span class="quality-metric-label"><?php esc_html_e( 'Avg Revenue / Customer', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="quality-metric">
					<span class="quality-metric-value"><?php echo esc_html( '$' . number_format_i18n( $avg_ltv, 0 ) ); ?></span>
					<span class="quality-metric-label"><?php esc_html_e( 'Avg LTV', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
				<div class="quality-metric">
					<span class="quality-metric-value"><?php echo esc_html( number_format_i18n( $high_value_count ) ); ?></span>
					<span class="quality-metric-label"><?php esc_html_e( 'High-Value (LTV $50k+)', 'mcp-ai-wpoos-pro' ); ?></span>
				</div>
			</div>

			<!-- Row 3: Lifecycle & Segment Breakdown -->
			<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
				<!-- Lifecycle Stages -->
				<div class="toolkit-stat-card" style="background: #f0f6fc; padding: 20px;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Lifecycle Stages', 'mcp-ai-wpoos-pro' ); ?></h3>
					<table class="widefat striped" style="margin-top: 10px;">
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Customer', 'mcp-ai-wpoos-pro' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $stages['customer'] ) ); ?></td>
								<td><?php echo esc_html( $published_count > 0 ? round( ( $stages['customer'] / $published_count ) * 100 ) . '%' : '0%' ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Evangelist', 'mcp-ai-wpoos-pro' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $stages['evangelist'] ) ); ?></td>
								<td><?php echo esc_html( $evangelist_pct . '%' ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Other', 'mcp-ai-wpoos-pro' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $stages['other'] ) ); ?></td>
								<td><?php echo esc_html( $published_count > 0 ? round( ( $stages['other'] / $published_count ) * 100 ) . '%' : '0%' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Revenue Tiers (ARPU Segmentation) -->
				<div class="toolkit-stat-card" style="background: #f0f6fc; padding: 20px;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Revenue Tiers', 'mcp-ai-wpoos-pro' ); ?></h3>
					<table class="widefat striped" style="margin-top: 10px;">
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Enterprise ($100k+)', 'mcp-ai-wpoos-pro' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $revenue_tiers['enterprise'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Mid-Market ($10k-$100k)', 'mcp-ai-wpoos-pro' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $revenue_tiers['mid_market'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'SMB (< $10k)', 'mcp-ai-wpoos-pro' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $revenue_tiers['smb'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Unknown Revenue', 'mcp-ai-wpoos-pro' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $revenue_tiers['unknown'] ) ); ?></td>
							</tr>
						</tbody>
				</table>
				</div>
			</div>

			<!-- Data completeness bar -->
			<div style="margin-bottom: 20px;">
				<h3><?php esc_html_e( 'Data Completeness', 'mcp-ai-wpoos-pro' ); ?>
					<span style="font-weight: normal; font-size: 14px; color: #666;">
						<?php
						printf(
							/* translators: %d: Completeness percentage */
							esc_html__( '(%d%% have email + revenue + LTV)', 'mcp-ai-wpoos-pro' ),
							absint( $completeness )
						);
						?>
					</span>
				</h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>
			</div>

			<!-- Alerts -->
			<?php if ( $churn_risk_pct > 50 ) : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						printf(
							/* translators: %d: At-risk percentage */
							esc_html__( '⚠️ High churn risk: %d%% of customers have no deal activity in the last 90 days. Consider launching a re-engagement campaign.', 'mcp-ai-wpoos-pro' ),
							absint( $churn_risk_pct )
						);
						?>
					</p>
				</div>
			<?php elseif ( $completeness < 60 ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %d: Completeness percentage */
							esc_html__( 'Data completeness is at %d%%. Enrich customer records with email, revenue, and LTV for accurate health scoring.', 'mcp-ai-wpoos-pro' ),
							absint( $completeness )
						);
						?>
					</p>
				</div>
			<?php elseif ( $evangelist_pct < 10 && $published_count > 5 ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php esc_html_e( '💡 Low evangelist count. Identify satisfied high-value customers and nurture them toward advocacy.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<!-- Quick Actions -->
			<div class="items-list-table">
				<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_customer' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Customers', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_customer' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Customer', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_lead' ) ); ?>" class="button">
						<?php esc_html_e( 'View Leads to Convert', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to create customer from research.
	 */
	public static function handle_create_from_research() {
		check_ajax_referer( 'wp_mcp_ai_research_page', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$customer_name = isset( $_POST['customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['customer_name'] ) ) : '';
		$email         = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( empty( $customer_name ) || empty( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Customer name and email are required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Create the customer post.
		$post_data = array(
			'post_title'  => $customer_name,
			'post_type'   => 'mcp_ai_customer',
			'post_status' => 'publish',
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Get default settings.
		$settings = get_option( 'wp_mcp_ai_customer_settings', array() );

		// Save customer metadata.
		$meta_fields = array(
			'email'             => 'email',
			'phone'             => 'phone',
			'company'           => 'company',
			'total_revenue'     => 'total_revenue',
			'lifetime_value'    => 'ltv',
			'customer_since'    => 'customer_since',
			'source_lead_id'    => 'source_lead_id',
			'lifecycle_stage'   => 'lifecycle_stage',
			'notes'             => 'notes',
		);

		foreach ( $meta_fields as $meta_key => $post_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				if ( 'notes' === $post_key ) {
					$value = sanitize_textarea_field( wp_unslash( $_POST[ $post_key ] ) );
				} elseif ( 'email' === $post_key ) {
					$value = sanitize_email( wp_unslash( $_POST[ $post_key ] ) );
				} elseif ( 'total_revenue' === $meta_key || 'lifetime_value' === $meta_key ) {
					$value = floatval( $_POST[ $post_key ] );
				} elseif ( 'source_lead_id' === $meta_key || 'customer_since' === $meta_key ) {
					$value = absint( $_POST[ $post_key ] );
				} else {
					$value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
				}
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		// Set default lifecycle stage if not already set.
		if ( ! get_post_meta( $post_id, 'lifecycle_stage', true ) ) {
			$default_stage = isset( $settings['default_lifecycle_stage'] ) ? $settings['default_lifecycle_stage'] : 'customer';
			update_post_meta( $post_id, 'lifecycle_stage', $default_stage );
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Customer created successfully!', 'mcp-ai-wpoos-pro' ),
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
