<?php
/**
 * Tests for the six keyless market-data tools.
 *
 * Tests cover: market_screener, macro_data_fetcher,
 * economic_calendar_fetcher, earnings_calendar_fetcher,
 * options_chain_fetcher, crypto_market_data — surfaces, gates, and
 * mocked-HTTP execute paths.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Test financial market data tools.
 *
 * @since 1.1.80
 */
class Test_Financial_Market_Data_Tools extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_user;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			define( 'WP_MCP_AI_PRO_VERSION', '1.1.80-test' );
		}

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__ ) . '/' );
		}

		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_financial_planner_toolkit' => true )
		);

		$this->editor_user = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_user );

		$base = WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/';

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-market-data-providers.php';

		$classes = array(
			'WP_MCP_AI_Tool_Market_Screener'           => 'class-wp-mcp-ai-tool-market-screener.php',
			'WP_MCP_AI_Tool_Macro_Data_Fetcher'        => 'class-wp-mcp-ai-tool-macro-data-fetcher.php',
			'WP_MCP_AI_Tool_Economic_Calendar_Fetcher' => 'class-wp-mcp-ai-tool-economic-calendar-fetcher.php',
			'WP_MCP_AI_Tool_Earnings_Calendar_Fetcher' => 'class-wp-mcp-ai-tool-earnings-calendar-fetcher.php',
			'WP_MCP_AI_Tool_Options_Chain_Fetcher'     => 'class-wp-mcp-ai-tool-options-chain-fetcher.php',
			'WP_MCP_AI_Tool_Crypto_Market_Data'        => 'class-wp-mcp-ai-tool-crypto-market-data.php',
		);

		foreach ( $classes as $class => $file ) {
			if ( ! class_exists( $class ) ) {
				require_once $base . $file;
			}
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_market_data_http_response' );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Build the HTTP seam for the screener.
	 */
	private function mock_screener_response() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'scanner.tradingview.com' ) ) {
					return array(
						'body' => wp_json_encode(
							array(
								'data' => array(
									array(
										's' => 'NVDA',
										'd' => array( 'NVIDIA', 'Tech', 120.5, 3.2, 90000000, 3000000000000, 'Technology', 1.0 ),
									),
								),
							)
						),
						'code' => 200,
					);
				}
				return $result;
			},
			10,
			2
		);
	}

	/**
	 * Build the HTTP seam for FRED.
	 */
	private function mock_fred_response() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'fred.stlouisfed.org' ) ) {
					return array(
						'body' => "DATE,DGS10\r\n2026-09-15,4.19\r\n",
						'code' => 200,
					);
				}
				return $result;
			},
			10,
			2
		);
	}

	/**
	 * Build the HTTP seam for Forex Factory.
	 */
	private function mock_forex_response() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'ff_calendar_thisweek.json' ) ) {
					return array(
						'body' => wp_json_encode(
							array(
								array(
									'title'    => 'CPI m/m',
									'country'  => 'USD',
									'date'     => '2026-09-17T11:00:00-05:00',
									'impact'   => 'High',
									'forecast' => '0.2%',
									'previous' => '0.3%',
								),
								array(
									'title'    => 'German ZEW',
									'country'  => 'EUR',
									'date'     => '2026-09-18T04:00:00-05:00',
									'impact'   => 'Medium',
									'forecast' => '',
									'previous' => '',
								),
							)
						),
						'code' => 200,
					);
				}
				return $result;
			},
			10,
			2
		);
	}

	/**
	 * Build the HTTP seam for Nasdaq earnings.
	 */
	private function mock_earnings_response() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'api.nasdaq.com/api/calendar/earnings' ) ) {
					return array(
						'body' => wp_json_encode(
							array(
								'data' => array(
									'rows' => array(
										array(
											'symbol'      => 'AAPL',
											'companyName' => 'Apple Inc.',
											'marketCap'   => '3.4T',
											'time'        => 'After Market Close',
											'epsForecast' => '1.61',
											'noOfEsts'    => '23',
										),
									),
								),
							)
						),
						'code' => 200,
					);
				}
				return $result;
			},
			10,
			2
		);
	}

	/**
	 * Build the HTTP seam for Nasdaq options.
	 */
	private function mock_options_response() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'option-chain' ) ) {
					return array(
						'body' => wp_json_encode(
							array(
								'data' => array(
									'table' => array(
										array(
											'calls' => array(
												array(
													'expiryGroup' => '2026-10-16',
													'strike' => '225.00',
													'bid' => '4.10',
													'ask' => '4.35',
													'volume' => 1200,
													'openInterest' => 45000,
													'inTheMoney' => false,
												),
											),
											'puts'  => array(
												array(
													'expiryGroup' => '2026-10-16',
													'strike' => '225.00',
													'bid' => '5.20',
													'ask' => '5.45',
													'volume' => 900,
													'openInterest' => 38000,
													'inTheMoney' => false,
												),
											),
										),
									),
								),
							)
						),
						'code' => 200,
					);
				}
				return $result;
			},
			10,
			2
		);
	}

	/**
	 * Build the HTTP seam for CoinGecko.
	 */
	private function mock_coingecko_response() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'api.coingecko.com/api/v3/coins/markets' ) ) {
					return array(
						'body' => wp_json_encode(
							array(
								array(
									'symbol'        => 'btc',
									'name'          => 'Bitcoin',
									'current_price' => 65000.5,
									'market_cap'    => 1280000000000,
								),
							)
						),
						'code' => 200,
					);
				}
				if ( false !== strpos( $url, 'market_chart' ) ) {
					return array(
						'body' => wp_json_encode(
							array(
								'prices'        => array(
									array( 1726358400000, 64000.0 ),
									array( 1726444800000, 65800.0 ),
								),
								'total_volumes' => array(
									array( 1726358400000, 1000000.0 ),
									array( 1726444800000, 1200000.0 ),
								),
							)
						),
						'code' => 200,
					);
				}
				return $result;
			},
			10,
			2
		);
	}

	// =========================================================================
	// market_screener.
	// =========================================================================

	/**
	 * Test screener surface + execute with mocked HTTP.
	 */
	public function test_market_screener_execute() {
		$this->mock_screener_response();

		$tool   = new WP_MCP_AI_Tool_Market_Screener();
		$result = $tool->execute(
			array(
				'sector' => 'Technology',
				'limit'  => 50,
			),
			array( 'user_id' => $this->editor_user )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'NVDA', $result['results'][0]['symbol'] );
		$this->assertSame( 'tradingview', $result['source'] );
	}

	/**
	 * Test screener forbidden gate.
	 */
	public function test_market_screener_forbidden() {
		wp_set_current_user( 0 );

		$tool   = new WP_MCP_AI_Tool_Market_Screener();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );

		wp_set_current_user( $this->editor_user );
	}

	// =========================================================================
	// macro_data_fetcher.
	// =========================================================================

	/**
	 * Test macro fetcher execute with mocked FRED.
	 */
	public function test_macro_data_fetcher_execute() {
		$this->mock_fred_response();

		$tool   = new WP_MCP_AI_Tool_Macro_Data_Fetcher();
		$result = $tool->execute( array( 'series' => array( 'DGS10' ) ), array( 'user_id' => $this->editor_user ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 4.19, $result['latest']['DGS10']['value'] );
		$this->assertSame( 'fred', $result['source'] );
	}

	/**
	 * Test macro fetcher rejects unknown series.
	 */
	public function test_macro_data_fetcher_invalid_series() {
		$tool   = new WP_MCP_AI_Tool_Macro_Data_Fetcher();
		$result = $tool->execute( array( 'series' => array( 'EVIL' ) ), array( 'user_id' => $this->editor_user ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_series', $result->get_error_code() );
	}

	// =========================================================================
	// economic_calendar_fetcher.
	// =========================================================================

	/**
	 * Test economic calendar filtering by impact.
	 */
	public function test_economic_calendar_filter_impact() {
		$this->mock_forex_response();

		$tool   = new WP_MCP_AI_Tool_Economic_Calendar_Fetcher();
		$result = $tool->execute( array( 'impact' => 'High' ), array( 'user_id' => $this->editor_user ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'CPI m/m', $result['events'][0]['title'] );
	}

	/**
	 * Test economic calendar invalid date gate.
	 */
	public function test_economic_calendar_invalid_date() {
		$tool   = new WP_MCP_AI_Tool_Economic_Calendar_Fetcher();
		$result = $tool->execute( array( 'date' => '17/09/2026' ), array( 'user_id' => $this->editor_user ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_date', $result->get_error_code() );
	}

	// =========================================================================
	// earnings_calendar_fetcher.
	// =========================================================================

	/**
	 * Test earnings calendar execute with symbol filter.
	 */
	public function test_earnings_calendar_execute() {
		$this->mock_earnings_response();

		$tool   = new WP_MCP_AI_Tool_Earnings_Calendar_Fetcher();
		$result = $tool->execute(
			array(
				'symbol' => 'AAPL',
				'date'   => '2026-09-17',
			),
			array( 'user_id' => $this->editor_user )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'AAPL', $result['events'][0]['symbol'] );
	}

	// =========================================================================
	// options_chain_fetcher.
	// =========================================================================

	/**
	 * Test options chain execute.
	 */
	public function test_options_chain_execute() {
		$this->mock_options_response();

		$tool   = new WP_MCP_AI_Tool_Options_Chain_Fetcher();
		$result = $tool->execute( array( 'ticker' => 'AAPL' ), array( 'user_id' => $this->editor_user ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['calls_count'] );
		$this->assertSame( 1, $result['puts_count'] );
		$this->assertSame( 225.0, $result['calls'][0]['strike'] );
		$this->assertSame( 'put', $result['puts'][0]['side'] );
	}

	/**
	 * Test options chain missing ticker gate.
	 */
	public function test_options_chain_missing_ticker() {
		$tool   = new WP_MCP_AI_Tool_Options_Chain_Fetcher();
		$result = $tool->execute( array(), array( 'user_id' => $this->editor_user ) );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_ticker', $result->get_error_code() );
	}

	// =========================================================================
	// crypto_market_data.
	// =========================================================================

	/**
	 * Test crypto board execute.
	 */
	public function test_crypto_board_execute() {
		$this->mock_coingecko_response();

		$tool   = new WP_MCP_AI_Tool_Crypto_Market_Data();
		$result = $tool->execute( array( 'action' => 'board' ), array( 'user_id' => $this->editor_user ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'BTC', $result['assets'][0]['symbol'] );
	}

	/**
	 * Test crypto quote execute (derived from history).
	 */
	public function test_crypto_quote_execute() {
		$this->mock_coingecko_response();

		$tool   = new WP_MCP_AI_Tool_Crypto_Market_Data();
		$result = $tool->execute(
			array(
				'action'  => 'quote',
				'symbols' => array( 'BTC' ),
			),
			array( 'user_id' => $this->editor_user )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 65800.0, $result['quotes']['BTC']['current_price'] );
		$this->assertSame( 'coingecko', $result['quotes']['BTC']['source'] );
	}

	/**
	 * Test crypto history missing symbols gate.
	 */
	public function test_crypto_history_missing_symbols() {
		$tool   = new WP_MCP_AI_Tool_Crypto_Market_Data();
		$result = $tool->execute( array( 'action' => 'history' ), array( 'user_id' => $this->editor_user ) );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_symbols', $result->get_error_code() );
	}
}
