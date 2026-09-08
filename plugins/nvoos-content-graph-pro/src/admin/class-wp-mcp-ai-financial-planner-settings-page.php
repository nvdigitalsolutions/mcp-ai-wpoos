<?php
/**
 * Financial admin page (ecosystem port — Wave F2, financial admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-financial-planner-settings-page.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the
 * `src/` root (base-class/yfinance-service requires).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Financial Planner Toolkit Settings Page Class
 */
class WP_MCP_AI_Financial_Planner_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'financial_planner';
		$this->toolkit_name     = __( 'Financial Planner Toolkit', 'nvoos-content-graph-pro' );
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
			<h2><?php esc_html_e( 'Financial Planner Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Comprehensive financial planning toolkit with 24 powerful tools for retirement planning, budgeting, portfolio management, and financial analysis.', 'nvoos-content-graph-pro' ); ?></p>
				<p><strong><?php esc_html_e( 'Works Independently:', 'nvoos-content-graph-pro' ); ?></strong> <?php esc_html_e( 'All tools function without requiring external API connections. You can manually manage all financial data. Optional Plaid API integration available for automatic bank account sync.', 'nvoos-content-graph-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Manual Financial Management: Track accounts, budgets, and transactions without any API dependencies', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Retirement Planning: Calculate retirement needs, optimize social security, and plan withdrawals', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Budget Management: Track expenses, analyze cash flow, and plan savings goals', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Investment Analysis: Visualize portfolios, plan asset allocation, and track rebalancing', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Debt Management: Calculate payoff strategies, track mortgage amortization', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Tax Planning: Estimate taxes, track tax-loss harvesting opportunities', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Financial Health: Calculate net worth, analyze financial health score, plan insurance needs', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Optional API Sync: Connect to Plaid for automatic bank transaction sync (not required)', 'nvoos-content-graph-pro' ); ?></li>
			</ul>

			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Privacy First:', 'nvoos-content-graph-pro' ); ?></strong>
					<?php esc_html_e( 'Your financial data stays in your WordPress database. External API connections are completely optional.', 'nvoos-content-graph-pro' ); ?>
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
			<h2><?php esc_html_e( 'Financial Planner Toolkit Configuration', 'nvoos-content-graph-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Currency', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<input type="text" name="default_currency" value="USD" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Default currency for financial calculations', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Interest Rate', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<input type="number" name="default_interest_rate" value="7" step="0.1" min="0" max="100" class="small-text" />
						<span>%</span>
						<p class="description"><?php esc_html_e( 'Default annual interest rate for investment calculations', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Inflation Rate', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<input type="number" name="default_inflation_rate" value="3" step="0.1" min="0" max="100" class="small-text" />
						<span>%</span>
						<p class="description"><?php esc_html_e( 'Default annual inflation rate for future value calculations', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Bank Account Sync', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_bank_sync" value="1" />
							<?php esc_html_e( 'Allow optional syncing with bank accounts via third-party services', 'nvoos-content-graph-pro' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'Optional feature. The toolkit works completely independently without this. When enabled, users can choose to connect their bank accounts via Plaid API.', 'nvoos-content-graph-pro' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Market Data Integration (yfinance)', 'nvoos-content-graph-pro' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Configure automatic market data fetching for portfolio tools using the yfinance service.', 'nvoos-content-graph-pro' ); ?>
			</p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable yfinance Service', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<?php
						$settings   = get_option( 'wp_mcp_ai_settings', array() );
						$is_enabled = ! empty( $settings['enable_yfinance_service'] );
						?>
						<label>
							<input type="checkbox" name="wp_mcp_ai_settings[enable_yfinance_service]" value="1" <?php checked( $is_enabled ); ?> />
							<?php esc_html_e( 'Enable automatic price fetching from yfinance microservice', 'nvoos-content-graph-pro' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, portfolio tools can automatically fetch current stock prices instead of requiring manual input.', 'nvoos-content-graph-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'yfinance Service URL', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<?php
						$service_url = isset( $settings['yfinance_service_url'] ) ? $settings['yfinance_service_url'] : 'http://localhost:5000';
						?>
						<input type="url" name="wp_mcp_ai_settings[yfinance_service_url]" value="<?php echo esc_attr( $service_url ); ?>" class="regular-text" />
						<p class="description">
							<?php esc_html_e( 'URL of the yfinance Python microservice (default: http://localhost:5000)', 'nvoos-content-graph-pro' ); ?>
						</p>
						<?php
						// Check service health if enabled.
						if ( $is_enabled ) :
							if ( ! class_exists( 'WP_MCP_AI_YFinance_Service' ) ) {
								require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-yfinance-service.php';
							}
							$yf_service = WP_MCP_AI_YFinance_Service::get_instance();
							$health     = $yf_service->check_health();
							?>
							<p>
								<strong><?php esc_html_e( 'Service Status:', 'nvoos-content-graph-pro' ); ?></strong>
								<?php if ( isset( $health['success'] ) && $health['success'] ) : ?>
									<span style="color: green;">● <?php esc_html_e( 'Online', 'nvoos-content-graph-pro' ); ?></span>
									<?php if ( isset( $health['version'] ) ) : ?>
										<span class="description">(<?php echo esc_html( $health['version'] ); ?>)</span>
									<?php endif; ?>
								<?php else : ?>
									<span style="color: red;">● <?php esc_html_e( 'Offline', 'nvoos-content-graph-pro' ); ?></span>
									<?php if ( isset( $health['error'] ) ) : ?>
										<br><span class="description"><?php echo esc_html( $health['error'] ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cache Duration', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<?php
						$cache_ttl = isset( $settings['yfinance_cache_ttl'] ) ? absint( $settings['yfinance_cache_ttl'] ) : 15;
						?>
						<input type="number" name="wp_mcp_ai_settings[yfinance_cache_ttl]" value="<?php echo esc_attr( $cache_ttl ); ?>" min="1" max="1440" class="small-text" />
						<span><?php esc_html_e( 'minutes', 'nvoos-content-graph-pro' ); ?></span>
						<p class="description">
							<?php esc_html_e( 'How long to cache price data before fetching fresh data (default: 15 minutes)', 'nvoos-content-graph-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cache Management', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<button type="button" class="button" id="clear-yfinance-cache">
							<?php esc_html_e( 'Clear All Cached Prices', 'nvoos-content-graph-pro' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'Clear all cached market data to force fresh fetches on next request.', 'nvoos-content-graph-pro' ); ?>
						</p>
						<div id="clear-cache-result" style="margin-top: 10px;"></div>
					</td>
				</tr>
			</table>

			<div class="notice notice-info inline">
				<p>
					<strong><?php esc_html_e( 'Important:', 'nvoos-content-graph-pro' ); ?></strong>
					<?php esc_html_e( 'The yfinance service must be running for automatic price fetching to work. See the documentation for setup instructions.', 'nvoos-content-graph-pro' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Educational Use Only:', 'nvoos-content-graph-pro' ); ?></strong>
					<?php esc_html_e( 'Market data from yfinance is for educational purposes only and may be delayed by 15 minutes or more. Not for actual trading decisions.', 'nvoos-content-graph-pro' ); ?>
				</p>
			</div>

			<script>
			jQuery(document).ready(function($) {
				$('#clear-yfinance-cache').on('click', function() {
					var button = $(this);
					var resultDiv = $('#clear-cache-result');
					
					button.prop('disabled', true).text('<?php esc_html_e( 'Clearing...', 'nvoos-content-graph-pro' ); ?>');
					resultDiv.html('');
					
					$.post(ajaxurl, {
						action: 'wp_mcp_ai_clear_yfinance_cache',
						nonce: '<?php echo esc_js( wp_create_nonce( 'wp_mcp_ai_clear_yfinance_cache' ) ); ?>'
					}, function(response) {
						button.prop('disabled', false).text('<?php esc_html_e( 'Clear All Cached Prices', 'nvoos-content-graph-pro' ); ?>');
						
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
			'retirement_calculator'        => __( 'Retirement Calculator', 'nvoos-content-graph-pro' ),
			'ira_roth_comparison'          => __( 'IRA/Roth Comparison', 'nvoos-content-graph-pro' ),
			'withdrawal_strategy_planner'  => __( 'Withdrawal Strategy Planner', 'nvoos-content-graph-pro' ),
			'social_security_optimizer'    => __( 'Social Security Optimizer', 'nvoos-content-graph-pro' ),
			'pension_analyzer'             => __( 'Pension Analyzer', 'nvoos-content-graph-pro' ),
			'budget_planner'               => __( 'Budget Planner', 'nvoos-content-graph-pro' ),
			'expense_tracker'              => __( 'Expense Tracker', 'nvoos-content-graph-pro' ),
			'net_worth_calculator'         => __( 'Net Worth Calculator', 'nvoos-content-graph-pro' ),
			'cash_flow_analyzer'           => __( 'Cash Flow Analyzer', 'nvoos-content-graph-pro' ),
			'bank_account_sync'            => __( 'Bank Account Sync', 'nvoos-content-graph-pro' ),
			'portfolio_visualizer'         => __( 'Portfolio Visualizer', 'nvoos-content-graph-pro' ),
			'asset_allocation_planner'     => __( 'Asset Allocation Planner', 'nvoos-content-graph-pro' ),
			'investment_return_calculator' => __( 'Investment Return Calculator', 'nvoos-content-graph-pro' ),
			'rebalancing_analyzer'         => __( 'Rebalancing Analyzer', 'nvoos-content-graph-pro' ),
			'tax_loss_harvesting_tracker'  => __( 'Tax Loss Harvesting Tracker', 'nvoos-content-graph-pro' ),
			'debt_payoff_calculator'       => __( 'Debt Payoff Calculator', 'nvoos-content-graph-pro' ),
			'mortgage_calculator'          => __( 'Mortgage Calculator', 'nvoos-content-graph-pro' ),
			'credit_score_tracker'         => __( 'Credit Score Tracker', 'nvoos-content-graph-pro' ),
			'savings_goal_planner'         => __( 'Savings Goal Planner', 'nvoos-content-graph-pro' ),
			'emergency_fund_calculator'    => __( 'Emergency Fund Calculator', 'nvoos-content-graph-pro' ),
			'financial_health_score'       => __( 'Financial Health Score', 'nvoos-content-graph-pro' ),
			'tax_estimator'                => __( 'Tax Estimator', 'nvoos-content-graph-pro' ),
			'college_savings_calculator'   => __( 'College Savings Calculator', 'nvoos-content-graph-pro' ),
			'insurance_needs_analyzer'     => __( 'Insurance Needs Analyzer', 'nvoos-content-graph-pro' ),
		);
	}
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Legacy AJAX handler must remain at file scope.
/**
 * AJAX handler to clear yfinance cache.
 *
 * @deprecated This function is a legacy AJAX handler that must remain at file scope.
 */
function wp_mcp_ai_ajax_clear_yfinance_cache() {
	// Check nonce.
	check_ajax_referer( 'wp_mcp_ai_clear_yfinance_cache', 'nonce' );

	// Check capabilities.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'You do not have permission to clear cache.', 'nvoos-content-graph-pro' ),
			)
		);
	}

	// Load service class.
	if ( ! class_exists( 'WP_MCP_AI_YFinance_Service' ) ) {
		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/services/class-wp-mcp-ai-yfinance-service.php';
	}

	$service = WP_MCP_AI_YFinance_Service::get_instance();
	$cleared = $service->clear_all_caches();

	wp_send_json_success(
		array(
			'message' => sprintf(
			/* translators: %d: number of caches cleared */
				__( 'Successfully cleared %d cached prices.', 'nvoos-content-graph-pro' ),
				$cleared
			),
		)
	);
}
add_action( 'wp_ajax_wp_mcp_ai_clear_yfinance_cache', 'wp_mcp_ai_ajax_clear_yfinance_cache' );

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Financial_Planner_Settings_Page();
}
