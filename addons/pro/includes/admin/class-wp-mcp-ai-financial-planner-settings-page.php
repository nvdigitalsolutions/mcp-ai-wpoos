<?php
/**
 * Financial Planner Toolkit Settings Page (DEPRECATED)
 *
 * @deprecated 1.1.1 Replaced by class-wp-mcp-ai-financial-planner-cpt-settings-page.php
 * @see WP_MCP_AI_Financial_Planner_CPT_Settings_Page
 *
 * This file is deprecated and no longer loaded. It has been replaced with a
 * CPT-based settings page that follows the same pattern as Quiz, Project,
 * and other toolkits. The new settings page appears under the Financial Accounts
 * CPT menu instead of under "NV oOS Pro" menu.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Financial Planner Toolkit Settings Page Class
 */
class WP_MCP_AI_Financial_Planner_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'financial_planner';
		$this->toolkit_name     = __( 'Financial Planner Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_financial_planner_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-financial-planner-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-money-alt';

		// Don't call parent constructor yet - we need to set up hooks first.
		// Register admin hooks at priority 30 (after Pro Dashboard at priority 25).
		add_action( 'admin_menu', array( $this, 'add_settings_page' ), 30 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Get toolkit slug
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Render overview tab
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'Financial Planner Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Comprehensive financial planning toolkit with 24 powerful tools for retirement planning, budgeting, portfolio management, and financial analysis.', 'mcp-ai-wpoos-pro' ); ?></p>
				<p><strong><?php esc_html_e( 'Works Independently:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php esc_html_e( 'All tools function without requiring external API connections. You can manually manage all financial data. Optional Plaid API integration available for automatic bank account sync.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Manual Financial Management: Track accounts, budgets, and transactions without any API dependencies', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Retirement Planning: Calculate retirement needs, optimize social security, and plan withdrawals', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Budget Management: Track expenses, analyze cash flow, and plan savings goals', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Investment Analysis: Visualize portfolios, plan asset allocation, and track rebalancing', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Debt Management: Calculate payoff strategies, track mortgage amortization', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Tax Planning: Estimate taxes, track tax-loss harvesting opportunities', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Financial Health: Calculate net worth, analyze financial health score, plan insurance needs', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Optional API Sync: Connect to Plaid for automatic bank transaction sync (not required)', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Privacy First:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php esc_html_e( 'Your financial data stays in your WordPress database. External API connections are completely optional.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render configuration tab
	 */
	protected function render_configuration_tab() {
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Financial Planner Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Currency', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="default_currency" value="USD" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Default currency for financial calculations', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Interest Rate', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="default_interest_rate" value="7" step="0.1" min="0" max="100" class="small-text" />
						<span>%</span>
						<p class="description"><?php esc_html_e( 'Default annual interest rate for investment calculations', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Inflation Rate', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="default_inflation_rate" value="3" step="0.1" min="0" max="100" class="small-text" />
						<span>%</span>
						<p class="description"><?php esc_html_e( 'Default annual inflation rate for future value calculations', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Bank Account Sync', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_bank_sync" value="1" />
							<?php esc_html_e( 'Allow optional syncing with bank accounts via third-party services', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Optional feature. The toolkit works completely independently without this. When enabled, users can choose to connect their bank accounts via Plaid API.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Market Data Integration (yfinance)', 'mcp-ai-wpoos-pro' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Configure automatic market data fetching for portfolio tools using the yfinance service.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable yfinance Service', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$settings = get_option( 'wp_mcp_ai_settings', array() );
						$is_enabled = ! empty( $settings['enable_yfinance_service'] );
						?>
						<label>
							<input type="checkbox" name="wp_mcp_ai_settings[enable_yfinance_service]" value="1" <?php checked( $is_enabled ); ?> />
							<?php esc_html_e( 'Enable automatic price fetching from yfinance microservice', 'mcp-ai-wpoos-pro' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, portfolio tools can automatically fetch current stock prices instead of requiring manual input.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'yfinance Service URL', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$service_url = isset( $settings['yfinance_service_url'] ) ? $settings['yfinance_service_url'] : 'http://localhost:5000';
						?>
						<input type="url" name="wp_mcp_ai_settings[yfinance_service_url]" value="<?php echo esc_attr( $service_url ); ?>" class="regular-text" />
						<p class="description">
							<?php esc_html_e( 'URL of the yfinance Python microservice (default: http://localhost:5000)', 'mcp-ai-wpoos-pro' ); ?>
						</p>
						<?php
						// Check service health if enabled.
						if ( $is_enabled ) :
							if ( ! class_exists( 'WP_MCP_AI_YFinance_Service' ) ) {
								require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-yfinance-service.php';
							}
							$yf_service = WP_MCP_AI_YFinance_Service::get_instance();
							$health = $yf_service->check_health();
							?>
							<p>
								<strong><?php esc_html_e( 'Service Status:', 'mcp-ai-wpoos-pro' ); ?></strong>
								<?php if ( isset( $health['success'] ) && $health['success'] ) : ?>
									<span style="color: green;">● <?php esc_html_e( 'Online', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php if ( isset( $health['version'] ) ) : ?>
										<span class="description">(<?php echo esc_html( $health['version'] ); ?>)</span>
									<?php endif; ?>
								<?php else : ?>
									<span style="color: red;">● <?php esc_html_e( 'Offline', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php if ( isset( $health['error'] ) ) : ?>
										<br><span class="description"><?php echo esc_html( $health['error'] ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cache Duration', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<?php
						$cache_ttl = isset( $settings['yfinance_cache_ttl'] ) ? absint( $settings['yfinance_cache_ttl'] ) : 15;
						?>
						<input type="number" name="wp_mcp_ai_settings[yfinance_cache_ttl]" value="<?php echo esc_attr( $cache_ttl ); ?>" min="1" max="1440" class="small-text" />
						<span><?php esc_html_e( 'minutes', 'mcp-ai-wpoos-pro' ); ?></span>
						<p class="description">
							<?php esc_html_e( 'How long to cache price data before fetching fresh data (default: 15 minutes)', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cache Management', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<button type="button" class="button" id="clear-yfinance-cache">
							<?php esc_html_e( 'Clear All Cached Prices', 'mcp-ai-wpoos-pro' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Clear all cached market data to force fresh fetches on next request.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
						<div id="clear-cache-result" style="margin-top: 10px;"></div>
					</td>
				</tr>
			</table>

			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Important:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php esc_html_e( 'The yfinance service must be running for automatic price fetching to work. See the documentation for setup instructions.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Educational Use Only:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php esc_html_e( 'Market data from yfinance is for educational purposes only and may be delayed by 15 minutes or more. Not for actual trading decisions.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
			</div>

			<script>
			jQuery(document).ready(function($) {
				$('#clear-yfinance-cache').on('click', function() {
					var button = $(this);
					var resultDiv = $('#clear-cache-result');
					
					button.prop('disabled', true).text('<?php esc_html_e( 'Clearing...', 'mcp-ai-wpoos-pro' ); ?>');
					resultDiv.html('');
					
					$.post(ajaxurl, {
						action: 'wp_mcp_ai_clear_yfinance_cache',
						nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_clear_yfinance_cache' ) ); ?>'
					}, function(response) {
						button.prop('disabled', false).text('<?php esc_html_e( 'Clear All Cached Prices', 'mcp-ai-wpoos-pro' ); ?>');
						
						if (response.success) {
							resultDiv.html('<span style="color: green;">✓ ' + response.data.message + '</span>');
						} else {
							resultDiv.html('<span style="color: red;">✗ ' + response.data.message + '</span>');
						}
						
						setTimeout(function() {
							resultDiv.fadeOut(function() {
								$(this).html('').show();
							});
						}, 3000);
					});
				});
			});
			</script>
		</div>
		<?php
	}

	/**
	 * Get tools list
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'retirement_calculator'        => __( 'Retirement Calculator', 'mcp-ai-wpoos-pro' ),
			'ira_roth_comparison'          => __( 'IRA/Roth Comparison', 'mcp-ai-wpoos-pro' ),
			'withdrawal_strategy_planner'  => __( 'Withdrawal Strategy Planner', 'mcp-ai-wpoos-pro' ),
			'social_security_optimizer'    => __( 'Social Security Optimizer', 'mcp-ai-wpoos-pro' ),
			'pension_analyzer'             => __( 'Pension Analyzer', 'mcp-ai-wpoos-pro' ),
			'budget_planner'               => __( 'Budget Planner', 'mcp-ai-wpoos-pro' ),
			'expense_tracker'              => __( 'Expense Tracker', 'mcp-ai-wpoos-pro' ),
			'net_worth_calculator'         => __( 'Net Worth Calculator', 'mcp-ai-wpoos-pro' ),
			'cash_flow_analyzer'           => __( 'Cash Flow Analyzer', 'mcp-ai-wpoos-pro' ),
			'bank_account_sync'            => __( 'Bank Account Sync', 'mcp-ai-wpoos-pro' ),
			'portfolio_visualizer'         => __( 'Portfolio Visualizer', 'mcp-ai-wpoos-pro' ),
			'asset_allocation_planner'     => __( 'Asset Allocation Planner', 'mcp-ai-wpoos-pro' ),
			'investment_return_calculator' => __( 'Investment Return Calculator', 'mcp-ai-wpoos-pro' ),
			'rebalancing_analyzer'         => __( 'Rebalancing Analyzer', 'mcp-ai-wpoos-pro' ),
			'tax_loss_harvesting_tracker'  => __( 'Tax Loss Harvesting Tracker', 'mcp-ai-wpoos-pro' ),
			'debt_payoff_calculator'       => __( 'Debt Payoff Calculator', 'mcp-ai-wpoos-pro' ),
			'mortgage_calculator'          => __( 'Mortgage Calculator', 'mcp-ai-wpoos-pro' ),
			'credit_score_tracker'         => __( 'Credit Score Tracker', 'mcp-ai-wpoos-pro' ),
			'savings_goal_planner'         => __( 'Savings Goal Planner', 'mcp-ai-wpoos-pro' ),
			'emergency_fund_calculator'    => __( 'Emergency Fund Calculator', 'mcp-ai-wpoos-pro' ),
			'financial_health_score'       => __( 'Financial Health Score', 'mcp-ai-wpoos-pro' ),
			'tax_estimator'                => __( 'Tax Estimator', 'mcp-ai-wpoos-pro' ),
			'college_savings_calculator'   => __( 'College Savings Calculator', 'mcp-ai-wpoos-pro' ),
			'insurance_needs_analyzer'     => __( 'Insurance Needs Analyzer', 'mcp-ai-wpoos-pro' ),
		);
	}
}

/**
 * AJAX handler to clear yfinance cache
 */
function wp_mcp_ai_ajax_clear_yfinance_cache() {
	// Check nonce.
	check_ajax_referer( 'wp_mcp_ai_clear_yfinance_cache', 'nonce' );

	// Check capabilities.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array(
			'message' => __( 'You do not have permission to clear cache.', 'mcp-ai-wpoos-pro' ),
		) );
	}

	// Load service class.
	if ( ! class_exists( 'WP_MCP_AI_YFinance_Service' ) ) {
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-yfinance-service.php';
	}

	$service = WP_MCP_AI_YFinance_Service::get_instance();
	$cleared = $service->clear_all_caches();

	wp_send_json_success( array(
		'message' => sprintf(
			/* translators: %d: number of caches cleared */
			__( 'Successfully cleared %d cached prices.', 'mcp-ai-wpoos-pro' ),
			$cleared
		),
	) );
}
add_action( 'wp_ajax_wp_mcp_ai_clear_yfinance_cache', 'wp_mcp_ai_ajax_clear_yfinance_cache' );

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Financial_Planner_Settings_Page();
}
