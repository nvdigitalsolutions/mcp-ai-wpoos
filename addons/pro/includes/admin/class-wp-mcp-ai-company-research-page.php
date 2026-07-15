<?php
/**
 * Research & Add admin page for Company CPT.
 *
 * Provides a dedicated page for researching companies before adding them,
 * with full chat interface for AI assistance and web search capabilities.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 1.1.0
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
 * Company Research Admin Page
 *
 * Adds a submenu page under Companies menu for AI-powered company research.
 */
class WP_MCP_AI_Company_Research_Page {
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
	const PAGE_SLUG = 'research-company';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 26 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_company_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_company', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under Companies CPT menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_company',
			__( 'Research & Add Company', 'mcp-ai-wpoos-pro' ),
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
		// Only load on our research page (now under Companies CPT menu).
		if ( 'mcp_ai_company_page_' . self::PAGE_SLUG !== $hook ) {
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
				'entityType' => 'company',
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
			// Look up assistant by slug/title.
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
				<?php esc_html_e( 'Research & Add Company', 'mcp-ai-wpoos-pro' ); ?>
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
		// CRM-specific tools available for company research.
		$company_tools = array(
			// Company CRUD.
			'create_company',
			'get_companies',
			'research_company',
			// CRM contact management.
			'manage_crm_contact',
			'crm_capture_interaction',
			// CRM email integration.
			'crm_email_search_leads',
			'crm_email_search_correspondence',
			'crm_email_search_accounting',
			// Activities.
			'create_crm_activity',
			'list_crm_activities',
			'complete_crm_activity',
			// Research → Paper Store pipeline.
			'generate_research_report',
			'create_post_from_research',
			// Web research.
			'web_search',
			'search_content',
			'semantic_content_search',
			'crawl_website',
			'scrape_structured_data',
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
						<li><?php esc_html_e( 'Research companies by industry, location, or name', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Analyze company data with AI assistance', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Identify target prospects and decision makers', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><?php esc_html_e( 'Create company records directly in your CRM', 'mcp-ai-wpoos-pro' ); ?></li>
					</ol>
				</div>

				<div class="wp-mcp-ai-research-tips">
					<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
					<ul>
						<li><strong><?php esc_html_e( 'Industry:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Research best practices and trends', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Size:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Filter by employee count or revenue', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Location:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Target by city, state, or country', 'mcp-ai-wpoos-pro' ); ?></li>
						<li><strong><?php esc_html_e( 'Intel:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Gather LinkedIn, website, and news data', 'mcp-ai-wpoos-pro' ); ?></li>
					</ul>
				</div>

				<div class="wp-mcp-ai-research-examples">
					<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
					<ul class="wp-mcp-ai-example-list">
						<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Find SaaS companies in California with 50-200 employees">
							<?php esc_html_e( '"Find SaaS companies in..."', 'mcp-ai-wpoos-pro' ); ?>
						</button></li>
						<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Research the top 5 fintech startups in New York">
							<?php esc_html_e( '"Research top fintech startups..."', 'mcp-ai-wpoos-pro' ); ?>
						</button></li>
						<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Create a company profile for Acme Corp including industry analysis">
							<?php esc_html_e( '"Create company profile..."', 'mcp-ai-wpoos-pro' ); ?>
						</button></li>
						<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="List all companies in my CRM and analyze their target status">
							<?php esc_html_e( '"List all companies in CRM..."', 'mcp-ai-wpoos-pro' ); ?>
						</button></li>
					</ul>
				</div>

				<div class="wp-mcp-ai-research-actions">
					<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
					<p>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_company' ) ); ?>" class="button">
							<?php esc_html_e( 'View All Companies', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_company' ) ); ?>" class="button">
							<?php esc_html_e( 'Add Company Manually', 'mcp-ai-wpoos-pro' ); ?>
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
							<p><?php esc_html_e( 'Research and create companies with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
						</button>
						<button type="button" class="workflow-option" data-workflow="import">
							<span class="dashicons dashicons-upload"></span>
							<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
							<p><?php esc_html_e( 'Bulk import company data', 'mcp-ai-wpoos-pro' ); ?></p>
						</button>
						<button type="button" class="workflow-option" data-workflow="review">
							<span class="dashicons dashicons-analytics"></span>
							<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
							<p><?php esc_html_e( 'View company data quality and completeness', 'mcp-ai-wpoos-pro' ); ?></p>
						</button>
					</div>
				</div>

				<!-- AI Research Workflow (Default) -->
				<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="' . esc_attr( implode( ',', $company_tools ) ) . '"]'
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
			<h2><?php esc_html_e( 'Import Company Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import companies from CSV, JSON, or paste structured data. The AI will automatically parse and organize the company information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include company name, industry, and website', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify company size and revenue if available', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Add location data (city, state, country)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include target status and contact information', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_companies', 'import_nonce' ); ?>

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
						placeholder="<?php esc_attr_e( "Example:\n\nCompany Name: Acme Corp\nIndustry: Software\nWebsite: https://acme.com\nCompany Size: 51-200\nCity: San Francisco\nState: CA\nCountry: USA\nTarget Status: prospect\n\nCompany Name: Globex Inc\nIndustry: Manufacturing\nWebsite: https://globex.com", 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>

					<div class="import-options">
						<label for="auto-create-companies">
							<input type="checkbox" id="auto-create-companies" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create companies (recommended)', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<label for="validate-company-data">
							<input type="checkbox" id="validate-company-data" name="validate_data" value="1" checked>
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
		$total_companies = wp_count_posts( 'mcp_ai_company' );
		$published_count = isset( $total_companies->publish ) ? $total_companies->publish : 0;

		$companies = get_posts(
			array(
				'post_type'      => 'mcp_ai_company',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count = 0;
		$with_industry  = 0;
		$with_website   = 0;
		$with_location  = 0;

		foreach ( $companies as $company ) {
			$industry = get_post_meta( $company->ID, '_company_industry', true );
			$website  = get_post_meta( $company->ID, '_company_website', true );
			$city     = get_post_meta( $company->ID, '_company_city', true );

			if ( $industry ) {
				++$with_industry;
			}
			if ( $website ) {
				++$with_website;
			}
			if ( $city ) {
				++$with_location;
			}
			if ( $industry && $website ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;
		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Company Quality Dashboard', 'mcp-ai-wpoos-pro' ); ?></h2>

			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Companies', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_industry ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Industry', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_website ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Website', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Company data completeness is %d%%. Ensure companies have industry and website for best results.', 'mcp-ai-wpoos-pro' ),
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
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_company' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Companies', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_company' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Company', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to create company from research.
	 */
	public static function handle_create_from_research() {
		check_ajax_referer( 'wp_mcp_ai_research_page', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$company_name = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
		$industry     = isset( $_POST['industry'] ) ? sanitize_text_field( wp_unslash( $_POST['industry'] ) ) : '';

		if ( empty( $company_name ) || empty( $industry ) ) {
			wp_send_json_error( array( 'message' => __( 'Company name and industry are required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Create the company post.
		$post_data = array(
			'post_title'   => $company_name,
			'post_content' => isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '',
			'post_type'    => 'mcp_ai_company',
			'post_status'  => 'publish',
		);

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
		}

		// Save company metadata.
		$meta_fields = array(
			'_company_industry'      => 'industry',
			'_company_size'          => 'company_size',
			'_company_website'       => 'website',
			'_company_city'          => 'city',
			'_company_state'         => 'state',
			'_company_country'       => 'country',
			'_company_phone'         => 'phone',
			'_company_revenue'       => 'revenue',
			'_company_target_status' => 'target_status',
			'_company_linkedin'      => 'linkedin',
			'_company_twitter'       => 'twitter',
			'_company_notes'         => 'notes',
		);

		foreach ( $meta_fields as $meta_key => $post_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
				if ( 'notes' === $post_key || 'description' === $post_key ) {
					$value = wp_kses_post( wp_unslash( $_POST[ $post_key ] ) );
				}
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		wp_send_json_success(
			array(
				'message'  => __( 'Company created successfully!', 'mcp-ai-wpoos-pro' ),
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

		// Placeholder for future import functionality.
		wp_send_json_error( array( 'message' => __( 'Import functionality coming soon.', 'mcp-ai-wpoos-pro' ) ) );
	}
}
