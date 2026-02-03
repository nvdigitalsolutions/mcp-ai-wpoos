<?php
/**
 * Research & Add admin page for Financial Account CPT.
 *
 * Provides a dedicated page for researching financial accounts and planning before creating accounts,
 * with full chat interface for AI assistance.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-research-page-featured-image.php';
require_once __DIR__ . '/trait-wp-mcp-ai-research-page-enhancements.php';

/**
 * Financial Account Research Admin Page
 *
 * Adds a submenu page under Financial Accounts menu for AI-powered financial research.
 */
class WP_MCP_AI_Financial_Account_Research_Page {
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
	const PAGE_SLUG = 'research-financial-account';

	/**
	 * Initialize the page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_financial_account_from_research', array( __CLASS__, 'handle_create_from_research' ) );
		add_action( 'wp_ajax_wp_mcp_ai_import_financial_account', array( __CLASS__, 'ajax_handle_import' ) );
	}

	/**
	 * Add submenu page under Financial Accounts menu.
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_fin_account',
			__( 'Research & Add Account', 'mcp-ai-wpoos-pro' ),
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
		if ( 'mcp_ai_fin_account_page_' . self::PAGE_SLUG !== $hook ) {
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
				'nonce'      => wp_create_nonce( 'wp_mcp_ai_research_financial_account' ),
				'entityType' => 'financial_account',
			)
		);
	}

	/**
	 * Render the research page.
	 */
	public static function render_page() {
		// Get assistant from settings.
		$settings     = get_option( 'wp_mcp_ai_financial_planner_settings', array() );
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
				<?php esc_html_e( 'Research & Add Financial Account', 'mcp-ai-wpoos-pro' ); ?>
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
							<li><?php esc_html_e( 'Research financial institutions and account types', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Use AI to analyze account features and compare options', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Get recommendations based on your financial goals', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><?php esc_html_e( 'Create accounts directly or import account data', 'mcp-ai-wpoos-pro' ); ?></li>
						</ol>
					</div>

					<div class="wp-mcp-ai-research-tips">
						<h3><?php esc_html_e( 'Research Tips', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul>
							<li><strong><?php esc_html_e( 'Search first:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Review existing accounts before adding new ones', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Be specific:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Include account type, institution, and balance details', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Compare options:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Research interest rates, fees, and account features', 'mcp-ai-wpoos-pro' ); ?></li>
							<li><strong><?php esc_html_e( 'Industry standards:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'Ask about best practices and financial planning strategies', 'mcp-ai-wpoos-pro' ); ?></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-examples">
						<h3><?php esc_html_e( 'Example Queries', 'mcp-ai-wpoos-pro' ); ?></h3>
						<ul class="wp-mcp-ai-example-list">
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="List all my financial accounts">
								<?php esc_html_e( '"List all accounts"', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Search the web for best high-yield savings accounts 2026">
								<?php esc_html_e( '"Best high-yield savings..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="What are the industry best practices for retirement account allocation">
								<?php esc_html_e( '"Retirement best practices..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Calculate my total net worth across all accounts">
								<?php esc_html_e( '"Calculate net worth..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
							<li><button type="button" class="button button-secondary wp-mcp-ai-example-query" data-query="Help me create a diversified investment portfolio">
								<?php esc_html_e( '"Diversified portfolio..."', 'mcp-ai-wpoos-pro' ); ?>
							</button></li>
						</ul>
					</div>

					<div class="wp-mcp-ai-research-preview" id="wp-mcp-ai-account-preview" style="display: none;">
						<h3><?php esc_html_e( 'Account Preview', 'mcp-ai-wpoos-pro' ); ?></h3>
						<div class="wp-mcp-ai-preview-content">
							<div class="wp-mcp-ai-preview-loading">
								<span class="spinner is-active"></span>
								<p><?php esc_html_e( 'Building account preview...', 'mcp-ai-wpoos-pro' ); ?></p>
							</div>
							<div class="wp-mcp-ai-preview-data" style="display: none;">
								<div class="wp-mcp-ai-preview-header">
									<h4 class="wp-mcp-ai-preview-title"></h4>
									<p class="wp-mcp-ai-preview-meta"></p>
								</div>
								<div class="wp-mcp-ai-preview-details"></div>
							</div>
						</div>
					</div>

					<div class="wp-mcp-ai-research-actions">
						<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
						<p>
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_fin_account' ) ); ?>" class="button">
								<?php esc_html_e( 'View All Accounts', 'mcp-ai-wpoos-pro' ); ?>
							</a>
						</p>
						<p>
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_fin_account' ) ); ?>" class="button">
								<?php esc_html_e( 'Add Account Manually', 'mcp-ai-wpoos-pro' ); ?>
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
								<p><?php esc_html_e( 'Research and create financial accounts with AI assistance', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="import">
								<span class="dashicons dashicons-upload"></span>
								<strong><?php esc_html_e( 'Import Data', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'Bulk import account data', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
							<button type="button" class="workflow-option" data-workflow="review">
								<span class="dashicons dashicons-analytics"></span>
								<strong><?php esc_html_e( 'Review & Quality', 'mcp-ai-wpoos-pro' ); ?></strong>
								<p><?php esc_html_e( 'View account quality and financial health', 'mcp-ai-wpoos-pro' ); ?></p>
							</button>
						</div>
					</div>

					<!-- AI Research Workflow (Default) -->
					<div id="workflow-research" class="workflow-content active">
					<?php if ( $assistant_id > 0 ) : ?>
						<div class="wp-mcp-ai-research-chat">
							<?php
							// Render chat interface with comprehensive financial planning tools.
							// Includes calculators, analyzers, trackers, and planners.
							$financial_tools = array(
								// Retirement and long-term planning.
								'retirement_calculator',
								'ira_roth_comparison',
								'social_security_optimizer',
								'pension_analyzer',
								'withdrawal_strategy_planner',
								// Budgeting and cash management.
								'budget_planner',
								'savings_goal_planner',
								'emergency_fund_calculator',
								'cash_flow_analyzer',
								'expense_tracker',
								// Debt and credit management.
								'debt_payoff_calculator',
								'mortgage_calculator',
								'credit_score_tracker',
								// Investment tools.
								'investment_return_calculator',
								'asset_allocation_planner',
								'portfolio_visualizer',
								'rebalancing_analyzer',
								// Tax and insurance planning.
								'tax_estimator',
								'tax_loss_harvesting_tracker',
								'insurance_needs_analyzer',
								// Education and net worth.
								'college_savings_calculator',
								'net_worth_calculator',
								// Account management.
								'bank_account_sync',
								'financial_health_score',
								// General research tools.
								'web_search',
								'search_content',
								'semantic_content_search',
							);
							echo do_shortcode(
								'[mcp_ai_chat assistant="' . absint( $assistant_id ) . '" additional_tools="' . esc_attr( implode( ',', $financial_tools ) ) . '"]'
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
	 * Handle AJAX request to create financial account from research.
	 */
	public static function handle_create_from_research() {
		// Verify nonce.
		check_ajax_referer( 'wp_mcp_ai_research_financial_account', 'nonce' );

		// Check user capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create financial accounts.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get research data from request.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Data is sanitized below per field.
		$research_data = isset( $_POST['research_data'] ) ? json_decode( wp_unslash( $_POST['research_data'] ), true ) : array();

		if ( empty( $research_data ) || empty( $research_data['title'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid research data. Account title is required.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$title       = sanitize_text_field( $research_data['title'] );
		$institution = isset( $research_data['institution'] ) ? sanitize_text_field( $research_data['institution'] ) : '';

		// Check for duplicate accounts based on title and institution.
		$existing_query_args = array(
			'post_type'      => 'mcp_ai_fin_account',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'title'          => $title,
		);

		// If institution is provided, also check by institution meta.
		if ( ! empty( $institution ) ) {
			$existing_query_args['meta_query'] = array(
				array(
					'key'     => '_institution',
					'value'   => $institution,
					'compare' => '=',
				),
			);
		}

		$existing_accounts = get_posts( $existing_query_args );

		if ( ! empty( $existing_accounts ) ) {
			$existing_id  = $existing_accounts[0];
			$existing_url = admin_url( 'post.php?post=' . $existing_id . '&action=edit' );

			wp_send_json_error(
				array(
					'message'      => sprintf(
						/* translators: 1: Account title, 2: Institution name */
						__( 'A financial account with the title "%1$s"%2$s already exists. Please use a different title or update the existing account.', 'mcp-ai-wpoos-pro' ),
						$title,
						! empty( $institution ) ? ' ' . sprintf( __( 'at %s', 'mcp-ai-wpoos-pro' ), $institution ) : ''
					),
					'duplicate'    => true,
					'existing_id'  => $existing_id,
					'existing_url' => $existing_url,
				)
			);
		}

		// Create financial account post.
		$account_data = array(
			'post_type'   => 'mcp_ai_fin_account',
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_author' => get_current_user_id(),
		);

		$account_id = wp_insert_post( $account_data );

		if ( is_wp_error( $account_id ) ) {
			wp_send_json_error( array( 'message' => $account_id->get_error_message() ) );
		}

		// Save account metadata.
		if ( ! empty( $institution ) ) {
			update_post_meta( $account_id, '_institution', $institution );
		}
		if ( isset( $research_data['account_number'] ) ) {
			update_post_meta( $account_id, '_account_number', sanitize_text_field( $research_data['account_number'] ) );
		}
		if ( isset( $research_data['balance'] ) ) {
			update_post_meta( $account_id, '_balance', floatval( $research_data['balance'] ) );
		}
		if ( isset( $research_data['currency'] ) ) {
			update_post_meta( $account_id, '_currency', sanitize_text_field( $research_data['currency'] ) );
		}
		if ( isset( $research_data['interest_rate'] ) ) {
			update_post_meta( $account_id, '_interest_rate', floatval( $research_data['interest_rate'] ) );
		}
		if ( isset( $research_data['credit_limit'] ) ) {
			update_post_meta( $account_id, '_credit_limit', floatval( $research_data['credit_limit'] ) );
		}
		if ( isset( $research_data['notes'] ) ) {
			update_post_meta( $account_id, '_notes', sanitize_textarea_field( $research_data['notes'] ) );
		}

		// Set account type taxonomy if provided.
		if ( isset( $research_data['account_type'] ) ) {
			wp_set_object_terms( $account_id, sanitize_text_field( $research_data['account_type'] ), 'mcp_ai_account_type' );
		}

		$edit_url = admin_url( 'post.php?post=' . $account_id . '&action=edit' );

		wp_send_json_success(
			array(
				'message'    => __( 'Financial account created successfully!', 'mcp-ai-wpoos-pro' ),
				'account_id' => $account_id,
				'edit_url'   => $edit_url,
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
			'ofx'  => 'OFX',
			'qfx'  => 'QFX',
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
		return new WP_Error( 'not_implemented', __( 'Financial account import processing coming soon', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Get validation schema.
	 *
	 * @return array Validation schema.
	 */
	protected static function get_validation_schema() {
		return array(
			'required_fields'    => array(
				'title'       => __( 'Account Title', 'mcp-ai-wpoos-pro' ),
				'institution' => __( 'Institution Name', 'mcp-ai-wpoos-pro' ),
				'balance'     => __( 'Current Balance', 'mcp-ai-wpoos-pro' ),
			),
			'recommended_fields' => array(
				'account_type'   => __( 'Account Type', 'mcp-ai-wpoos-pro' ),
				'account_number' => __( 'Account Number', 'mcp-ai-wpoos-pro' ),
				'currency'       => __( 'Currency', 'mcp-ai-wpoos-pro' ),
				'interest_rate'  => __( 'Interest Rate', 'mcp-ai-wpoos-pro' ),
			),
			'validation_rules'   => array(
				'balance'       => array(
					'type'      => 'numeric',
					'min_value' => 0,
				),
				'interest_rate' => array(
					'type'      => 'numeric',
					'min_value' => 0,
					'max_value' => 100,
				),
			),
			'quality_dimensions' => array(
				'completeness',
				'accuracy',
				'security',
				'documentation',
			),
		);
	}

	/**
	 * Calculate completeness.
	 *
	 * @return array Completeness data.
	 */
	protected static function calculate_completeness() {
		$accounts = get_posts(
			array(
				'post_type'      => 'mcp_ai_fin_account',
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);

		$total    = count( $accounts );
		$complete = 0;
		$missing  = array();

		foreach ( $accounts as $account ) {
			$institution = get_post_meta( $account->ID, '_institution', true );
			$balance     = get_post_meta( $account->ID, '_balance', true );
			$has_type    = has_term( '', 'mcp_ai_account_type', $account->ID );

			if ( $institution && '' !== $balance && $has_type ) {
				++$complete;
			}
		}

		$percentage = $total > 0 ? round( ( $complete / $total ) * 100 ) : 0;

		if ( $complete < $total ) {
			$missing[] = sprintf(
				/* translators: %d: Number of incomplete accounts */
				__( '%d accounts missing institution, balance, or type information', 'mcp-ai-wpoos-pro' ),
				$total - $complete
			);
		}

		return array(
			'percentage'  => $percentage,
			'missing'     => $missing,
			'suggestions' => array(
				__( 'Ensure all accounts have institution names', 'mcp-ai-wpoos-pro' ),
				__( 'Add account types (checking, savings, investment, etc.)', 'mcp-ai-wpoos-pro' ),
				__( 'Update balances regularly for accurate net worth tracking', 'mcp-ai-wpoos-pro' ),
				__( 'Review and update interest rates quarterly', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Get items for review.
	 *
	 * @return array Items.
	 */
	protected static function get_items_for_review() {
		$accounts = get_posts(
			array(
				'post_type'      => 'mcp_ai_fin_account',
				'post_status'    => 'any',
				'posts_per_page' => 20,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $accounts as $account ) {
			$items[] = array(
				'id'    => $account->ID,
				'title' => $account->post_title,
				'meta'  => array(
					'institution'  => get_post_meta( $account->ID, '_institution', true ),
					'balance'      => get_post_meta( $account->ID, '_balance', true ),
					'account_type' => wp_get_object_terms( $account->ID, 'mcp_ai_account_type', array( 'fields' => 'names' ) ),
				),
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

		// Check institution (25 points).
		if ( ! empty( $item['meta']['institution'] ) ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing institution name', 'mcp-ai-wpoos-pro' );
		}

		// Check balance (25 points).
		if ( isset( $item['meta']['balance'] ) && '' !== $item['meta']['balance'] ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing balance information', 'mcp-ai-wpoos-pro' );
		}

		// Check account type (25 points).
		if ( ! empty( $item['meta']['account_type'] ) && is_array( $item['meta']['account_type'] ) && count( $item['meta']['account_type'] ) > 0 ) {
			$score += 25;
		} else {
			$issues[] = __( 'Missing account type', 'mcp-ai-wpoos-pro' );
		}

		// Check title (25 points).
		if ( ! empty( $item['title'] ) && strlen( $item['title'] ) > 5 ) {
			$score += 25;
		} else {
			$issues[] = __( 'Account name needs improvement', 'mcp-ai-wpoos-pro' );
		}

		// Determine level.
		if ( $score >= 80 ) {
			$level = 'high';
		} elseif ( $score >= 50 ) {
			$level = 'medium';
		} else {
			$level = 'low';
		}

		return array(
			'score'  => $score,
			'level'  => $level,
			'status' => 'high' === $level ? __( 'Complete', 'mcp-ai-wpoos-pro' ) : __( 'Needs Work', 'mcp-ai-wpoos-pro' ),
			'issues' => $issues,
		);
	}

	/**
	 * Render import workflow.
	 */
	protected static function render_import_workflow() {
		?>
		<div class="wp-mcp-ai-import-section">
			<h2><?php esc_html_e( 'Import Financial Account Data', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Import accounts from CSV, OFX, QFX, JSON, or paste structured data. The AI will automatically parse and organize the account information.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			
			<div class="import-tips">
				<h4><?php esc_html_e( 'Tips for better results:', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul>
					<li><?php esc_html_e( '✓ Include account name, institution, and current balance', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Specify account type (checking, savings, investment, etc.)', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Add account numbers (last 4 digits) for tracking', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Include interest rates for interest-bearing accounts', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( '✓ Add credit limits for credit card accounts', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>

			<div class="import-form">
				<h3><?php esc_html_e( 'Upload File or Paste Data', 'mcp-ai-wpoos-pro' ); ?></h3>
				<form id="wp-mcp-ai-import-form" method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'wp_mcp_ai_import_accounts', 'import_nonce' ); ?>
					
					<div class="import-file-section">
						<input type="file" id="wp-mcp-ai-import-file-input" name="import_file" accept=".csv,.ofx,.qfx,.json,.txt" style="display: none;">
						<button type="button" class="button" onclick="document.getElementById('wp-mcp-ai-import-file-input').click();">
							<span class="dashicons dashicons-upload"></span>
							<?php esc_html_e( 'Choose File', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<span class="import-file-selected" style="margin-left: 10px; display: none;"></span>
						<p class="description"><?php esc_html_e( 'Supported: CSV, OFX, QFX, JSON, TXT', 'mcp-ai-wpoos-pro' ); ?></p>
					</div>

					<p><strong><?php esc_html_e( 'OR', 'mcp-ai-wpoos-pro' ); ?></strong></p>

					<textarea 
						id="wp-mcp-ai-import-text" 
						name="import_data" 
						class="widefat" 
						rows="12" 
						placeholder="<?php esc_attr_e( 'Example:\n\nAccount: Chase Checking\nInstitution: Chase Bank\nAccount Type: Checking\nBalance: 5,250.00\nAccount Number: ...1234\nNotes: Primary checking account\n\nAccount: Vanguard 401k\nInstitution: Vanguard\nAccount Type: Investment\nBalance: 125,000.00\nInterest Rate: 7.5%\nNotes: Employer retirement account', 'mcp-ai-wpoos-pro' ); ?>"
					></textarea>
					
					<div class="import-options">
						<label>
							<input type="checkbox" name="auto_create" value="1" checked>
							<?php esc_html_e( 'Automatically create accounts (recommended)', 'mcp-ai-wpoos-pro' ); ?>
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
		// Get account statistics.
		$total_accounts  = wp_count_posts( 'mcp_ai_fin_account' );
		$published_count = isset( $total_accounts->publish ) ? $total_accounts->publish : 0;

		// Calculate data quality metrics.
		$accounts = get_posts(
			array(
				'post_type'      => 'mcp_ai_fin_account',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$complete_count    = 0;
		$with_institution  = 0;
		$with_balance      = 0;
		$total_balance     = 0;

		foreach ( $accounts as $account ) {
			$institution = get_post_meta( $account->ID, '_institution', true );
			$balance     = get_post_meta( $account->ID, '_balance', true );
			$has_type    = has_term( '', 'mcp_ai_account_type', $account->ID );

			if ( $institution ) {
				++$with_institution;
			}
			if ( '' !== $balance && null !== $balance ) {
				++$with_balance;
				$total_balance += floatval( $balance );
			}
			if ( $institution && '' !== $balance && $has_type ) {
				++$complete_count;
			}
		}

		$completeness = $published_count > 0 ? round( ( $complete_count / $published_count ) * 100 ) : 0;

		?>
		<div class="wp-mcp-ai-consolidate-section">
			<h2><?php esc_html_e( 'Financial Account Quality Dashboard', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="quality-dashboard">
				<h3><?php esc_html_e( 'Overall Completeness', 'mcp-ai-wpoos-pro' ); ?></h3>
				<div class="completeness-indicator">
					<div class="completeness-bar" style="width: <?php echo esc_attr( $completeness ); ?>%;"></div>
					<span class="completeness-percentage"><?php echo esc_html( $completeness ); ?>%</span>
				</div>

				<div class="quality-metrics">
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $published_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Accounts', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $complete_count ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Fully Complete', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value"><?php echo esc_html( $with_institution ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'With Institution', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
					<div class="quality-metric">
						<span class="quality-metric-value">$<?php echo esc_html( number_format( $total_balance, 2 ) ); ?></span>
						<span class="quality-metric-label"><?php esc_html_e( 'Total Balance', 'mcp-ai-wpoos-pro' ); ?></span>
					</div>
				</div>

				<?php if ( $completeness < 80 ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %d: Completeness percentage */
								esc_html__( 'Account completeness is %d%%. Ensure all accounts have institution names, balances, and account types for accurate financial tracking.', 'mcp-ai-wpoos-pro' ),
								esc_html( $completeness )
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<div class="notice notice-info inline">
					<p>
						<strong><?php esc_html_e( 'Privacy & Security:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php esc_html_e( 'All financial data is stored securely in your WordPress database. Use account number masking (last 4 digits only) for sensitive information.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				</div>
			</div>

			<?php self::render_quality_table(); ?>

			<div class="items-list-table">
				<h3><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h3>
				<p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_fin_account' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'View All Accounts', 'mcp-ai-wpoos-pro' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_fin_account' ) ); ?>" class="button">
						<?php esc_html_e( 'Add New Account', 'mcp-ai-wpoos-pro' ); ?>
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
WP_MCP_AI_Financial_Account_Research_Page::init();
