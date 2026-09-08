<?php
/**
 * Characterization tests for the Wave F2 financial-planning tools batch A —
 * the retirement/budget/investment/debt groups (18 tools) plus the yfinance
 * service.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the tool
 *   classes (classmap-autoloaded); the byte-identical surfaces and
 *   fail-closed gates are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/tools/financial-planning/` are asserted in full, including the
 *   filter and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Financial tools batch A tests.
 */
class Test_Financial_Tools_A extends WP_UnitTestCase {

	/**
	 * Enable the financial planner toolkit for availability gates.
	 */
	public function setUp(): void {
		parent::setUp();
		$settings                                     = get_option( 'wp_mcp_ai_settings', array() );
		$settings                                     = is_array( $settings ) ? $settings : array();
		$settings['enable_financial_planner_toolkit'] = 1;
		update_option( 'wp_mcp_ai_settings', $settings );
	}

	/**
	 * The ported symbols must follow the ownership boundary.
	 */
	public function test_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Retirement_Calculator'        => 'tools/financial-planning/class-wp-mcp-ai-tool-retirement-calculator.php',
			'WP_MCP_AI_Tool_IRA_Roth_Comparison'          => 'tools/financial-planning/class-wp-mcp-ai-tool-ira-roth-comparison.php',
			'WP_MCP_AI_Tool_Withdrawal_Strategy_Planner'  => 'tools/financial-planning/class-wp-mcp-ai-tool-withdrawal-strategy-planner.php',
			'WP_MCP_AI_Tool_Social_Security_Optimizer'    => 'tools/financial-planning/class-wp-mcp-ai-tool-social-security-optimizer.php',
			'WP_MCP_AI_Tool_Pension_Analyzer'             => 'tools/financial-planning/class-wp-mcp-ai-tool-pension-analyzer.php',
			'WP_MCP_AI_Tool_Budget_Planner'               => 'tools/financial-planning/class-wp-mcp-ai-tool-budget-planner.php',
			'WP_MCP_AI_Tool_Expense_Tracker'              => 'tools/financial-planning/class-wp-mcp-ai-tool-expense-tracker.php',
			'WP_MCP_AI_Tool_Net_Worth_Calculator'         => 'tools/financial-planning/class-wp-mcp-ai-tool-net-worth-calculator.php',
			'WP_MCP_AI_Tool_Cash_Flow_Analyzer'           => 'tools/financial-planning/class-wp-mcp-ai-tool-cash-flow-analyzer.php',
			'WP_MCP_AI_Tool_Bank_Account_Sync'            => 'tools/financial-planning/class-wp-mcp-ai-tool-bank-account-sync.php',
			'WP_MCP_AI_Tool_Portfolio_Visualizer'         => 'tools/financial-planning/class-wp-mcp-ai-tool-portfolio-visualizer.php',
			'WP_MCP_AI_Tool_Asset_Allocation_Planner'     => 'tools/financial-planning/class-wp-mcp-ai-tool-asset-allocation-planner.php',
			'WP_MCP_AI_Tool_Investment_Return_Calculator' => 'tools/financial-planning/class-wp-mcp-ai-tool-investment-return-calculator.php',
			'WP_MCP_AI_Tool_Rebalancing_Analyzer'         => 'tools/financial-planning/class-wp-mcp-ai-tool-rebalancing-analyzer.php',
			'WP_MCP_AI_Tool_Tax_Loss_Harvesting_Tracker'  => 'tools/financial-planning/class-wp-mcp-ai-tool-tax-loss-harvesting-tracker.php',
			'WP_MCP_AI_Tool_Debt_Payoff_Calculator'       => 'tools/financial-planning/class-wp-mcp-ai-tool-debt-payoff-calculator.php',
			'WP_MCP_AI_Tool_Mortgage_Calculator'          => 'tools/financial-planning/class-wp-mcp-ai-tool-mortgage-calculator.php',
			'WP_MCP_AI_Tool_Credit_Score_Tracker'         => 'tools/financial-planning/class-wp-mcp-ai-tool-credit-score-tracker.php',
			'WP_MCP_AI_YFinance_Service'                  => 'services/class-wp-mcp-ai-yfinance-service.php',
		);

		foreach ( $symbols as $class => $file ) {
			$reflection = new ReflectionClass( $class );
			$path       = str_replace( '\\', '/', (string) $reflection->getFileName() );
			if ( defined( 'WP_MCP_AI_PATH' ) ) {
				$this->assertStringContainsString( 'addons/pro/includes/' . $file, $path, $class );
			} else {
				$this->assertStringContainsString( 'nvoos-content-graph-pro/src/' . $file, $path, $class );
			}
		}
	}

	/**
	 * The eighteen tool surfaces must be byte-identical (all edit_posts).
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Retirement_Calculator'        => 'retirement_calculator',
			'WP_MCP_AI_Tool_IRA_Roth_Comparison'          => 'ira_roth_comparison',
			'WP_MCP_AI_Tool_Withdrawal_Strategy_Planner'  => 'withdrawal_strategy_planner',
			'WP_MCP_AI_Tool_Social_Security_Optimizer'    => 'social_security_optimizer',
			'WP_MCP_AI_Tool_Pension_Analyzer'             => 'pension_analyzer',
			'WP_MCP_AI_Tool_Budget_Planner'               => 'budget_planner',
			'WP_MCP_AI_Tool_Expense_Tracker'              => 'expense_tracker',
			'WP_MCP_AI_Tool_Net_Worth_Calculator'         => 'net_worth_calculator',
			'WP_MCP_AI_Tool_Cash_Flow_Analyzer'           => 'cash_flow_analyzer',
			'WP_MCP_AI_Tool_Bank_Account_Sync'            => 'bank_account_sync',
			'WP_MCP_AI_Tool_Portfolio_Visualizer'         => 'portfolio_visualizer',
			'WP_MCP_AI_Tool_Asset_Allocation_Planner'     => 'asset_allocation_planner',
			'WP_MCP_AI_Tool_Investment_Return_Calculator' => 'investment_return_calculator',
			'WP_MCP_AI_Tool_Rebalancing_Analyzer'         => 'rebalancing_analyzer',
			'WP_MCP_AI_Tool_Tax_Loss_Harvesting_Tracker'  => 'tax_loss_harvesting_tracker',
			'WP_MCP_AI_Tool_Debt_Payoff_Calculator'       => 'debt_payoff_calculator',
			'WP_MCP_AI_Tool_Mortgage_Calculator'          => 'mortgage_calculator',
			'WP_MCP_AI_Tool_Credit_Score_Tracker'         => 'credit_score_tracker',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}
	}

	/**
	 * All eighteen tools must fail closed without an edit_posts user.
	 */
	public function test_permission_gates(): void {
		$classes = array(
			'WP_MCP_AI_Tool_Retirement_Calculator',
			'WP_MCP_AI_Tool_IRA_Roth_Comparison',
			'WP_MCP_AI_Tool_Withdrawal_Strategy_Planner',
			'WP_MCP_AI_Tool_Social_Security_Optimizer',
			'WP_MCP_AI_Tool_Pension_Analyzer',
			'WP_MCP_AI_Tool_Budget_Planner',
			'WP_MCP_AI_Tool_Expense_Tracker',
			'WP_MCP_AI_Tool_Net_Worth_Calculator',
			'WP_MCP_AI_Tool_Cash_Flow_Analyzer',
			'WP_MCP_AI_Tool_Bank_Account_Sync',
			'WP_MCP_AI_Tool_Portfolio_Visualizer',
			'WP_MCP_AI_Tool_Asset_Allocation_Planner',
			'WP_MCP_AI_Tool_Investment_Return_Calculator',
			'WP_MCP_AI_Tool_Rebalancing_Analyzer',
			'WP_MCP_AI_Tool_Tax_Loss_Harvesting_Tracker',
			'WP_MCP_AI_Tool_Debt_Payoff_Calculator',
			'WP_MCP_AI_Tool_Mortgage_Calculator',
			'WP_MCP_AI_Tool_Credit_Score_Tracker',
		);

		foreach ( $classes as $class ) {
			$tool   = new $class();
			$result = $tool->execute( array(), array() );
			$this->assertWPError( $result, $class );
			$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code(), $class );
		}
	}

	/**
	 * As an editor, the calculator tools must reach their argument gates.
	 */
	public function test_argument_gates(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$args   = array( 'user_id' => $editor );

		$retirement = new WP_MCP_AI_Tool_Retirement_Calculator();
		$this->assertSame( 'invalid_current_age', $retirement->execute( array(), $args )->get_error_code() );

		$budget = new WP_MCP_AI_Tool_Budget_Planner();
		$this->assertSame( 'invalid_income', $budget->execute( array(), $args )->get_error_code() );

		$mortgage = new WP_MCP_AI_Tool_Mortgage_Calculator();
		$this->assertSame( 'invalid_amount', $mortgage->execute( array(), $args )->get_error_code() );
	}

	/**
	 * Standalone only: the init's tool filter must carry the batch.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the financial tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_financial_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Retirement_Calculator', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Credit_Score_Tracker', $tools );
	}

	/**
	 * Standalone only: the ecosystem registration must register the batch
	 * into both registries.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/init.php';
		wp_mcp_ai_pro_register_financial_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['retirement_calculator'] ?? null );
		$this->assertNotNull( $parent->all()['mortgage_calculator'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'budget_planner' ) );
		$this->assertTrue( $core_tools->has( 'credit_score_tracker' ) );
	}
}
