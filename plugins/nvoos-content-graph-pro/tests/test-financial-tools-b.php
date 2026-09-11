<?php
/**
 * Characterization tests for the Wave F2 financial-planning tools batch B —
 * the goal/literacy/market/transaction groups (16 tools) plus the tree-only
 * import-financial-planning-blueprint tool and the examples JSON.
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
 * Financial tools batch B tests.
 */
class Test_Financial_Tools_B extends WP_UnitTestCase {

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
			'WP_MCP_AI_Tool_Savings_Goal_Planner'       => 'tools/financial-planning/class-wp-mcp-ai-tool-savings-goal-planner.php',
			'WP_MCP_AI_Tool_Emergency_Fund_Calculator'  => 'tools/financial-planning/class-wp-mcp-ai-tool-emergency-fund-calculator.php',
			'WP_MCP_AI_Tool_Financial_Health_Score'     => 'tools/financial-planning/class-wp-mcp-ai-tool-financial-health-score.php',
			'WP_MCP_AI_Tool_Tax_Estimator'              => 'tools/financial-planning/class-wp-mcp-ai-tool-tax-estimator.php',
			'WP_MCP_AI_Tool_College_Savings_Calculator' => 'tools/financial-planning/class-wp-mcp-ai-tool-college-savings-calculator.php',
			'WP_MCP_AI_Tool_Insurance_Needs_Analyzer'   => 'tools/financial-planning/class-wp-mcp-ai-tool-insurance-needs-analyzer.php',
			'WP_MCP_AI_Tool_Financial_News_Aggregator'  => 'tools/financial-planning/class-wp-mcp-ai-tool-financial-news-aggregator.php',
			'WP_MCP_AI_Tool_Stock_Data_Fetcher'         => 'tools/financial-planning/class-wp-mcp-ai-tool-stock-data-fetcher.php',
			'WP_MCP_AI_Tool_Market_Sentiment_Analyzer'  => 'tools/financial-planning/class-wp-mcp-ai-tool-market-sentiment-analyzer.php',
			'WP_MCP_AI_Tool_Market_Forecast_Analyzer'   => 'tools/financial-planning/class-wp-mcp-ai-tool-market-forecast-analyzer.php',
			'WP_MCP_AI_Tool_Investment_Signal_Tracker'  => 'tools/financial-planning/class-wp-mcp-ai-tool-investment-signal-tracker.php',
			'WP_MCP_AI_Tool_Financial_Logic_Visualizer' => 'tools/financial-planning/class-wp-mcp-ai-tool-financial-logic-visualizer.php',
			'WP_MCP_AI_Tool_Financial_Report_Generator' => 'tools/financial-planning/class-wp-mcp-ai-tool-financial-report-generator.php',
			'WP_MCP_AI_Tool_Financial_Search'           => 'tools/financial-planning/class-wp-mcp-ai-tool-financial-search.php',
			'WP_MCP_AI_Tool_Get_Uncategorised_Transactions' => 'tools/financial-planning/class-wp-mcp-ai-tool-get-uncategorised-transactions.php',
			'WP_MCP_AI_Tool_Categorise_Transactions'    => 'tools/financial-planning/class-wp-mcp-ai-tool-categorise-transactions.php',
			'WP_MCP_AI_Tool_Import_Financial_Planning_Blueprint' => 'tools/financial-planning/examples/class-wp-mcp-ai-tool-import-financial-planning-blueprint.php',
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
	 * The seventeen tool surfaces must be byte-identical (one `read`).
	 */
	public function test_tool_surfaces(): void {
		$slugs = array(
			'WP_MCP_AI_Tool_Savings_Goal_Planner'       => 'savings_goal_planner',
			'WP_MCP_AI_Tool_Emergency_Fund_Calculator'  => 'emergency_fund_calculator',
			'WP_MCP_AI_Tool_Financial_Health_Score'     => 'financial_health_score',
			'WP_MCP_AI_Tool_Tax_Estimator'              => 'tax_estimator',
			'WP_MCP_AI_Tool_College_Savings_Calculator' => 'college_savings_calculator',
			'WP_MCP_AI_Tool_Insurance_Needs_Analyzer'   => 'insurance_needs_analyzer',
			'WP_MCP_AI_Tool_Financial_News_Aggregator'  => 'financial_news_aggregator',
			'WP_MCP_AI_Tool_Stock_Data_Fetcher'         => 'stock_data_fetcher',
			'WP_MCP_AI_Tool_Market_Sentiment_Analyzer'  => 'market_sentiment_analyzer',
			'WP_MCP_AI_Tool_Market_Forecast_Analyzer'   => 'market_forecast_analyzer',
			'WP_MCP_AI_Tool_Investment_Signal_Tracker'  => 'investment_signal_tracker',
			'WP_MCP_AI_Tool_Financial_Logic_Visualizer' => 'financial_logic_visualizer',
			'WP_MCP_AI_Tool_Financial_Report_Generator' => 'financial_report_generator',
			'WP_MCP_AI_Tool_Financial_Search'           => 'financial_search',
			'WP_MCP_AI_Tool_Get_Uncategorised_Transactions' => 'get_uncategorised_transactions',
			'WP_MCP_AI_Tool_Categorise_Transactions'    => 'categorise_transactions',
			'WP_MCP_AI_Tool_Import_Financial_Planning_Blueprint' => 'import_financial_planning_blueprint',
		);

		foreach ( $slugs as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$expected_cap = 'WP_MCP_AI_Tool_Get_Uncategorised_Transactions' === $class ? 'read' : 'edit_posts';
			$this->assertSame( $expected_cap, $tool->get_required_capability(), $class );
		}
	}

	/**
	 * All seventeen tools must fail closed without a user.
	 */
	public function test_permission_gates(): void {
		$classes = array(
			'WP_MCP_AI_Tool_Savings_Goal_Planner',
			'WP_MCP_AI_Tool_Emergency_Fund_Calculator',
			'WP_MCP_AI_Tool_Financial_Health_Score',
			'WP_MCP_AI_Tool_Tax_Estimator',
			'WP_MCP_AI_Tool_College_Savings_Calculator',
			'WP_MCP_AI_Tool_Insurance_Needs_Analyzer',
			'WP_MCP_AI_Tool_Financial_News_Aggregator',
			'WP_MCP_AI_Tool_Stock_Data_Fetcher',
			'WP_MCP_AI_Tool_Market_Sentiment_Analyzer',
			'WP_MCP_AI_Tool_Market_Forecast_Analyzer',
			'WP_MCP_AI_Tool_Investment_Signal_Tracker',
			'WP_MCP_AI_Tool_Financial_Logic_Visualizer',
			'WP_MCP_AI_Tool_Financial_Report_Generator',
			'WP_MCP_AI_Tool_Financial_Search',
			'WP_MCP_AI_Tool_Get_Uncategorised_Transactions',
			'WP_MCP_AI_Tool_Categorise_Transactions',
		);

		foreach ( $classes as $class ) {
			$tool   = new $class();
			$result = $tool->execute( array(), array() );
			$this->assertWPError( $result, $class );
			$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code(), $class );
		}
	}

	/**
	 * As an editor, the argument-gated tools must reach their gates.
	 */
	public function test_argument_gates(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$args   = array( 'user_id' => $editor );

		$emergency = new WP_MCP_AI_Tool_Emergency_Fund_Calculator();
		$this->assertSame( 'invalid_expenses', $emergency->execute( array(), $args )->get_error_code() );

		$search = new WP_MCP_AI_Tool_Financial_Search();
		$this->assertSame( 'missing_query', $search->execute( array(), $args )->get_error_code() );

		$report = new WP_MCP_AI_Tool_Financial_Report_Generator();
		$this->assertSame( 'invalid_report_type', $report->execute( array(), $args )->get_error_code() );

		$categorise = new WP_MCP_AI_Tool_Categorise_Transactions();
		$this->assertSame( 'missing_category', $categorise->execute( array(), $args )->get_error_code() );

		$savings = new WP_MCP_AI_Tool_Savings_Goal_Planner();
		$this->assertSame( 'invalid_action', $savings->execute( array( 'action' => 'bogus' ), $args )->get_error_code() );
	}

	/**
	 * The import-blueprint tool has no user gate — it must delegate to the
	 * shared installer and fail closed on a missing blueprint.
	 */
	public function test_import_blueprint_missing(): void {
		$tool   = new WP_MCP_AI_Tool_Import_Financial_Planning_Blueprint();
		$result = $tool->execute( array(), array() );
		$this->assertWPError( $result );
		$this->assertSame( 'blueprint_not_found', $result->get_error_code() );
	}

	/**
	 * The import-blueprint tool's blueprints dir must resolve per mode and
	 * the shipped budget-coach.json must exist there.
	 */
	public function test_import_blueprint_dir(): void {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Import_Financial_Planning_Blueprint' );
		$dir        = $reflection->getConstant( 'BLUEPRINTS_DIR' );
		$this->assertStringContainsString( 'financial-planning/examples', $dir );
		$this->assertFileExists( $dir . '/budget-coach.json' );
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
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Financial_Search', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Import_Financial_Planning_Blueprint', $tools );
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
		$this->assertNotNull( $parent->all()['financial_search'] ?? null );
		$this->assertNotNull( $parent->all()['import_financial_planning_blueprint'] ?? null );

		$core_tools = \NvoosContentGraphAi\CoreBridge::instance()->tools;
		$this->assertTrue( $core_tools->has( 'stock_data_fetcher' ) );
		$this->assertTrue( $core_tools->has( 'categorise_transactions' ) );
	}
}
