<?php
/**
 * Characterization tests for the Wave F2 financial-planning OpenTerminal
 * lessons sub-cluster — the eight market-data/portfolio/alert tools, the two
 * market-data services (providers + indicators), the portfolio-transaction
 * CPT, and the standalone-only init wiring.
 *
 * Matrix-aware:
 * - Monolith matrix (base plugin active): the base Pro addon owns the
 *   symbols (classmap-autoloaded); the byte-identical surfaces are asserted.
 * - Standalone matrix (base plugin absent): the ported copies in
 *   `src/services/` + `src/tools/financial-planning/` are asserted in full,
 *   including the filter and ecosystem registrations.
 *
 * @package NvoosContentGraphPro\Tests
 */

declare(strict_types=1);

/**
 * Financial market data tools tests (ecosystem).
 */
class Test_Financial_Market_Data_Tools_Ecosystem extends WP_UnitTestCase {

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
	 * The ported tool symbols must follow the ownership boundary.
	 */
	public function test_tool_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Tool_Market_Screener'           => 'tools/financial-planning/class-wp-mcp-ai-tool-market-screener.php',
			'WP_MCP_AI_Tool_Macro_Data_Fetcher'        => 'tools/financial-planning/class-wp-mcp-ai-tool-macro-data-fetcher.php',
			'WP_MCP_AI_Tool_Economic_Calendar_Fetcher' => 'tools/financial-planning/class-wp-mcp-ai-tool-economic-calendar-fetcher.php',
			'WP_MCP_AI_Tool_Earnings_Calendar_Fetcher' => 'tools/financial-planning/class-wp-mcp-ai-tool-earnings-calendar-fetcher.php',
			'WP_MCP_AI_Tool_Options_Chain_Fetcher'     => 'tools/financial-planning/class-wp-mcp-ai-tool-options-chain-fetcher.php',
			'WP_MCP_AI_Tool_Crypto_Market_Data'        => 'tools/financial-planning/class-wp-mcp-ai-tool-crypto-market-data.php',
			'WP_MCP_AI_Tool_Portfolio_Transaction_Log' => 'tools/financial-planning/class-wp-mcp-ai-tool-portfolio-transaction-log.php',
			'WP_MCP_AI_Tool_Price_Alerts'              => 'tools/financial-planning/class-wp-mcp-ai-tool-price-alerts.php',
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
	 * The ported service symbols must follow the ownership boundary.
	 */
	public function test_service_serving_sources(): void {
		$symbols = array(
			'WP_MCP_AI_Market_Data_Providers'  => 'services/class-wp-mcp-ai-market-data-providers.php',
			'WP_MCP_AI_Technical_Indicators'   => 'services/class-wp-mcp-ai-technical-indicators.php',
			'WP_MCP_AI_Financial_Transaction_CPT' => 'class-wp-mcp-ai-financial-transaction-cpt.php',
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
	 * The new tool surfaces stay byte-identical across matrices.
	 */
	public function test_tool_surfaces(): void {
		$surfaces = array(
			'WP_MCP_AI_Tool_Market_Screener'           => 'market_screener',
			'WP_MCP_AI_Tool_Macro_Data_Fetcher'        => 'macro_data_fetcher',
			'WP_MCP_AI_Tool_Economic_Calendar_Fetcher' => 'economic_calendar_fetcher',
			'WP_MCP_AI_Tool_Earnings_Calendar_Fetcher' => 'earnings_calendar_fetcher',
			'WP_MCP_AI_Tool_Options_Chain_Fetcher'     => 'options_chain_fetcher',
			'WP_MCP_AI_Tool_Crypto_Market_Data'        => 'crypto_market_data',
			'WP_MCP_AI_Tool_Portfolio_Transaction_Log' => 'portfolio_transaction_log',
			'WP_MCP_AI_Tool_Price_Alerts'              => 'price_alerts',
		);

		foreach ( $surfaces as $class => $slug ) {
			$tool = new $class();
			$this->assertSame( $slug, $tool->get_slug(), $class );
			$this->assertSame( 'edit_posts', $tool->get_required_capability(), $class );
		}

		// Spot-check the action enums and constants.
		$this->assertSame( array( 'add', 'list', 'remove', 'position_summary' ), ( new WP_MCP_AI_Tool_Portfolio_Transaction_Log() )->get_parameters_schema()['properties']['action']['enum'] );
		$this->assertSame( array( 'create', 'list', 'delete', 'check_now' ), ( new WP_MCP_AI_Tool_Price_Alerts() )->get_parameters_schema()['properties']['action']['enum'] );
		$this->assertSame( array( 'board', 'quote', 'history' ), ( new WP_MCP_AI_Tool_Crypto_Market_Data() )->get_parameters_schema()['properties']['action']['enum'] );
		$this->assertSame( 'mcp_ai_fin_txn', WP_MCP_AI_Financial_Transaction_CPT::POST_TYPE );
		$this->assertSame( 'wp_mcp_ai_price_alert_check_daily', WP_MCP_AI_Tool_Price_Alerts::CRON_HOOK );
	}

	/**
	 * Standalone-only tool filter carries the eight new tools.
	 */
	public function test_filter_shape_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin builds the financial tool map inline.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/init.php';
		add_filter( 'wp_mcp_ai_pro_tools', 'wp_mcp_ai_pro_register_financial_tools', 10 );

		$tools = apply_filters( 'wp_mcp_ai_pro_tools', array() );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Market_Screener', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Macro_Data_Fetcher', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Economic_Calendar_Fetcher', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Earnings_Calendar_Fetcher', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Options_Chain_Fetcher', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Crypto_Market_Data', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Portfolio_Transaction_Log', $tools );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Price_Alerts', $tools );
	}

	/**
	 * Standalone-only ecosystem registration registers the new tools.
	 */
	public function test_registration_standalone(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			$this->markTestSkipped( 'Monolith matrix: the base plugin registers Pro tools.' );
		}

		require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/financial-planning/init.php';
		wp_mcp_ai_pro_register_financial_ecosystem_tools();

		$parent = nvoos_content_graph_get_tool_registry();
		$this->assertNotNull( $parent->all()['market_screener'] ?? null );
		$this->assertNotNull( $parent->all()['macro_data_fetcher'] ?? null );
		$this->assertNotNull( $parent->all()['price_alerts'] ?? null );
		$this->assertNotNull( $parent->all()['portfolio_transaction_log'] ?? null );
	}

	/**
	 * The resilience providers expose the keyless parsing surface.
	 */
	public function test_providers_surface(): void {
		$this->assertTrue( WP_MCP_AI_Market_Data_Providers::is_valid_symbol( 'AAPL' ) );
		$this->assertFalse( WP_MCP_AI_Market_Data_Providers::is_valid_symbol( 'BAD;SYMBOL' ) );
		$this->assertSame( 30, WP_MCP_AI_Market_Data_Providers::period_to_days( '1mo' ) );
		$this->assertSame( 'bitcoin', WP_MCP_AI_Market_Data_Providers::get_crypto_symbol_map( 'btc' )['id'] );

		$chains = WP_MCP_AI_Market_Data_Providers::get_instance()->get_fallback_chains();
		$this->assertSame( array( 'stooq', 'nasdaq' ), $chains['quote'] );
		$this->assertSame( array( 'coingecko', 'binance' ), $chains['crypto'] );
	}

	/**
	 * The technical indicators expose the deterministic math surface.
	 */
	public function test_indicators_surface(): void {
		$sma = WP_MCP_AI_Technical_Indicators::sma( array( 1, 2, 3, 4, 5 ), 3 );
		$this->assertSame( 4.0, $sma[4] );
		$this->assertNull( WP_MCP_AI_Technical_Indicators::rsi( array( 1, 2 ), 14 ) );
	}

	/**
	 * The yfinance service carries the resilience contracts.
	 */
	public function test_yfinance_service_surface(): void {
		$service = WP_MCP_AI_YFinance_Service::get_instance();
		$this->assertTrue( method_exists( $service, 'fallbacks_enabled' ) );
		$this->assertTrue( method_exists( $service, 'get_api_key' ) );
		$this->assertTrue( method_exists( $service, 'get_stale_ttl' ) );
	}
}
