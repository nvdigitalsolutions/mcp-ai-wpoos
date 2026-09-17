<?php
/**
 * Tests for Financial Toolkit Resilience (provider fallback chains, SWR).
 *
 * Tests cover:
 *  - WP_MCP_AI_Market_Data_Providers (stooq/nasdaq/fred/coingecko/binance/
 *    forex-factory/tradingview parsing, symbol validation, period mapping,
 *    fetch_with_fallback chain ordering)
 *  - WP_MCP_AI_YFinance_Service (fallback after primary failure, stale-
 *    while-revalidate serving, API-key pass-through, batch fallback)
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Test financial resilience.
 *
 * @since 1.1.80
 */
class Test_Financial_Resilience extends WP_UnitTestCase {

	/**
	 * Captured filter args for assertions.
	 *
	 * @var array
	 */
	public $captured = array();

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
			array(
				'enable_financial_planner_toolkit' => true,
				'enable_yfinance_service'          => true,
			)
		);

		$this->captured = array();

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-market-data-providers.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-yfinance-service.php';
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_market_data_http_response' );
		remove_all_filters( 'wp_mcp_ai_yfinance_ticker_info' );
		remove_all_filters( 'wp_mcp_ai_yfinance_current_price' );
		remove_all_filters( 'wp_mcp_ai_yfinance_batch_prices' );
		remove_all_filters( 'wp_mcp_ai_yfinance_price_history' );
		remove_all_filters( 'wp_mcp_ai_yfinance_search_ticker' );
		remove_all_filters( 'wp_mcp_ai_yfinance_api_key' );
		remove_all_filters( 'wp_mcp_ai_market_data_fallback_chains' );

		$service = WP_MCP_AI_YFinance_Service::get_instance();
		$service->clear_all_caches();

		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	// =========================================================================
	// Providers.
	// =========================================================================

	/**
	 * Test symbol validation.
	 */
	public function test_is_valid_symbol() {
		$this->assertTrue( WP_MCP_AI_Market_Data_Providers::is_valid_symbol( 'AAPL' ) );
		$this->assertTrue( WP_MCP_AI_Market_Data_Providers::is_valid_symbol( 'BRK-B' ) );
		$this->assertTrue( WP_MCP_AI_Market_Data_Providers::is_valid_symbol( '^GSPC' ) );
		$this->assertFalse( WP_MCP_AI_Market_Data_Providers::is_valid_symbol( 'AA; DROP TABLE' ) );
		$this->assertFalse( WP_MCP_AI_Market_Data_Providers::is_valid_symbol( '' ) );
	}

	/**
	 * Test period to days mapping.
	 */
	public function test_period_to_days() {
		$this->assertSame( 30, WP_MCP_AI_Market_Data_Providers::period_to_days( '1mo' ) );
		$this->assertSame( 365, WP_MCP_AI_Market_Data_Providers::period_to_days( '1y' ) );
		$this->assertSame( 30, WP_MCP_AI_Market_Data_Providers::period_to_days( 'bogus' ) );
	}

	/**
	 * Test crypto symbol map.
	 */
	public function test_crypto_symbol_map() {
		$this->assertSame( 'bitcoin', WP_MCP_AI_Market_Data_Providers::get_crypto_symbol_map( 'btc' )['id'] );
		$this->assertFalse( WP_MCP_AI_Market_Data_Providers::get_crypto_symbol_map( 'NOTACOIN' ) );
	}

	/**
	 * Test stooq quote parsing.
	 */
	public function test_stooq_get_quote_parses_csv() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'stooq.com/q/l/' ) ) {
					return array(
						'body' => "Symbol,Date,Time,Open,High,Low,Close,Volume\r\nAAPL,2026-09-15,22:00:02,220.10,224.50,219.80,223.85,52345678\r\n",
						'code' => 200,
					);
				}
				return $result;
			},
			10,
			2
		);

		$providers = WP_MCP_AI_Market_Data_Providers::get_instance();
		$quote     = $providers->stooq_get_quote( 'AAPL' );

		$this->assertNotWPError( $quote );
		$this->assertSame( 223.85, $quote['current_price'] );
		$this->assertSame( 220.10, $quote['open'] );
		$this->assertSame( 52345678, $quote['volume'] );
		$this->assertSame( 'stooq', $quote['source'] );
	}

	/**
	 * Test stooq history parsing.
	 */
	public function test_stooq_get_history_parses_csv() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'stooq.com/q/d/l/' ) ) {
					return array(
						'body' => "Date,Open,High,Low,Close,Volume\r\n2026-09-14,218.00,221.00,217.50,220.10,50000000\r\n2026-09-15,220.10,224.50,219.80,223.85,52345678\r\n",
						'code' => 200,
					);
				}
				return $result;
			},
			10,
			2
		);

		$providers = WP_MCP_AI_Market_Data_Providers::get_instance();
		$history   = $providers->stooq_get_history( 'AAPL', '1mo' );

		$this->assertNotWPError( $history );
		$this->assertSame( 2, $history['count'] );
		$this->assertSame( 223.85, $history['data'][1]['close'] );
		$this->assertSame( 'stooq', $history['source'] );
	}

	/**
	 * Test nasdaq quote parsing.
	 */
	public function test_nasdaq_get_quote_parses_json() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'api.nasdaq.com/api/quote/AAPL/summary' ) ) {
					return array(
						'body' => wp_json_encode(
							array(
								'data' => array(
									'name'        => 'Apple Inc. Common Stock',
									'summaryData' => array(
										array(
											'label' => 'Last Sale Price',
											'value' => '$ 223.85',
										),
										array(
											'label' => 'Volume',
											'value' => '52,345,678',
										),
										array(
											'label' => 'Market Cap',
											'value' => '3.4T',
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

		$providers = WP_MCP_AI_Market_Data_Providers::get_instance();
		$quote     = $providers->nasdaq_get_quote( 'AAPL' );

		$this->assertNotWPError( $quote );
		$this->assertSame( 223.85, $quote['current_price'] );
		$this->assertSame( 52345678, $quote['volume'] );
		$this->assertSame( 'nasdaq', $quote['source'] );
	}

	/**
	 * Test FRED series allowlist rejection.
	 */
	public function test_fred_get_series_rejects_unknown_series() {
		$providers = WP_MCP_AI_Market_Data_Providers::get_instance();
		$result    = $providers->fred_get_series( array( 'EVIL;SERIES' ), 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_series', $result->get_error_code() );
	}

	/**
	 * Test FRED CSV parsing.
	 */
	public function test_fred_get_series_parses_csv() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'fred.stlouisfed.org/graph/fredgraph.csv' ) ) {
					return array(
						'body' => "DATE,DGS10,VIXCLS\r\n2026-09-14,4.21,15.3\r\n2026-09-15,4.19,14.9\r\n",
						'code' => 200,
					);
				}
				return $result;
			},
			10,
			2
		);

		$providers = WP_MCP_AI_Market_Data_Providers::get_instance();
		$result    = $providers->fred_get_series( array( 'DGS10', 'VIXCLS' ), 10 );

		$this->assertNotWPError( $result );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( 4.19, $result['observations'][1]['DGS10'] );
		$this->assertSame( 'fred', $result['source'] );
	}

	/**
	 * Test coingecko markets parsing.
	 */
	public function test_coingecko_get_markets_parses_json() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'api.coingecko.com/api/v3/coins/markets' ) ) {
					return array(
						'body' => wp_json_encode(
							array(
								array(
									'symbol'          => 'btc',
									'name'            => 'Bitcoin',
									'current_price'   => 65000.5,
									'market_cap'      => 1280000000000,
									'market_cap_rank' => 1,
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

		$providers = WP_MCP_AI_Market_Data_Providers::get_instance();
		$result    = $providers->coingecko_get_markets( 'usd', 10 );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'BTC', $result['assets'][0]['symbol'] );
		$this->assertSame( 65000.5, $result['assets'][0]['current_price'] );
	}

	/**
	 * Test binance klines parsing.
	 */
	public function test_binance_get_history_parses_json() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'api.binance.com/api/v3/klines' ) ) {
					return array(
						'body' => wp_json_encode(
							array(
								array( 1726358400000, '64000', '66000', '63500', '65800', '12500.5' ),
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

		$providers = WP_MCP_AI_Market_Data_Providers::get_instance();
		$result    = $providers->binance_get_history( 'BTC', '1mo' );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 65800.0, $result['data'][0]['close'] );
		$this->assertSame( 'binance', $result['source'] );
	}

	/**
	 * Test forex factory calendar parsing.
	 */
	public function test_forex_factory_get_calendar_parses_json() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'ff_calendar_thisweek.json' ) ) {
					return array(
						'body' => wp_json_encode(
							array(
								array(
									'title'    => 'FOMC Statement',
									'country'  => 'USD',
									'date'     => '2026-09-17T11:00:00-05:00',
									'impact'   => 'High',
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

		$providers = WP_MCP_AI_Market_Data_Providers::get_instance();
		$result    = $providers->forex_factory_get_calendar();

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'High', $result['events'][0]['impact'] );
	}

	/**
	 * Test tradingview screener POST + parsing.
	 */
	public function test_tradingview_screen_stocks_parses_json() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url, $headers, $method, $body ) {
				if ( false !== strpos( $url, 'scanner.tradingview.com/america/scan' ) ) {
					$this->captured['method'] = $method;
					$this->captured['body']   = $body;

					return array(
						'body' => wp_json_encode(
							array(
								'data' => array(
									array(
										's' => 'AAPL',
										'd' => array( 'Apple Inc.', 'Technology', 223.85, 1.2, 52345678, 3400000000000, 'Technology', 0.5 ),
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
			5
		);

		$providers = WP_MCP_AI_Market_Data_Providers::get_instance();
		$result    = $providers->tradingview_screen_stocks( array( 'sector' => 'Technology' ), 50 );

		$this->assertNotWPError( $result );
		$this->assertSame( 'POST', $this->captured['method'] );
		$this->assertSame( 'AAPL', $result['results'][0]['symbol'] );
		$this->assertSame( 1.2, $result['results'][0]['change_pct'] );
		$this->assertSame( 'tradingview', $result['source'] );
	}

	/**
	 * Test fallback chain: first provider fails, second succeeds.
	 */
	public function test_fetch_with_fallback_uses_second_provider() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'stooq.com' ) ) {
					return array(
						'body' => '',
						'code' => 500,
					);
				}
				if ( false !== strpos( $url, 'api.nasdaq.com' ) ) {
					return array(
						'body' => wp_json_encode(
							array(
								'data' => array(
									'name'        => 'Apple Inc.',
									'summaryData' => array(
										array(
											'label' => 'Last Sale Price',
											'value' => '$ 223.85',
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

		$providers = WP_MCP_AI_Market_Data_Providers::get_instance();
		$result    = $providers->fetch_with_fallback( 'quote', array( 'symbol' => 'AAPL' ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 'nasdaq', $result['source'] );
		$this->assertTrue( $result['fallback_used'] );
		$this->assertArrayHasKey( 'stooq', $result['errors'] );
	}

	/**
	 * Test fallback chain: all providers fail.
	 */
	public function test_fetch_with_fallback_all_fail() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function () {
				return array(
					'body' => '',
					'code' => 500,
				);
			},
			10
		);

		$providers = WP_MCP_AI_Market_Data_Providers::get_instance();
		$result    = $providers->fetch_with_fallback( 'quote', array( 'symbol' => 'AAPL' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_market_data_all_providers_failed', $result->get_error_code() );
	}

	// =========================================================================
	// YFinance service: fallback + SWR + API key.
	// =========================================================================

	/**
	 * Test API key pass-through into the filter params.
	 */
	public function test_service_api_key_pass_through() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_financial_planner_toolkit' => true,
				'enable_yfinance_service'          => true,
				'yfinance_api_key'                 => 'top-secret',
			)
		);

		add_filter(
			'wp_mcp_ai_yfinance_current_price',
			function ( $result, $params ) {
				$this->captured['api_key'] = $params['api_key'];

				return array( 'current_price' => 100.0 );
			},
			10,
			2
		);

		$service = WP_MCP_AI_YFinance_Service::get_instance();
		$result  = $service->get_current_price( 'AAPL' );

		$this->assertNotWPError( $result );
		$this->assertSame( 'top-secret', $this->captured['api_key'] );
		$this->assertSame( 'yfinance', $result['source'] );
	}

	/**
	 * Test fallback to stooq when the microservice is down.
	 */
	public function test_service_falls_back_to_stooq() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'stooq.com/q/l/' ) ) {
					return array(
						'body' => "Symbol,Date,Time,Open,High,Low,Close,Volume\r\nAAPL,2026-09-15,22:00:02,220.10,224.50,219.80,223.85,52345678\r\n",
						'code' => 200,
					);
				}
				return array(
					'body' => '',
					'code' => 500,
				);
			},
			10,
			2
		);

		$service = WP_MCP_AI_YFinance_Service::get_instance();
		$result  = $service->get_current_price( 'AAPL' );

		$this->assertNotWPError( $result );
		$this->assertSame( 223.85, $result['current_price'] );
		$this->assertSame( 'stooq', $result['source'] );
	}

	/**
	 * Test stale-while-revalidate: fresh expired + fetch failure serves stale.
	 */
	public function test_service_serves_stale_on_failure() {
		// 1. Successful primary fetch seeds fresh + stale copies.
		add_filter(
			'wp_mcp_ai_yfinance_current_price',
			function () {
				return array( 'current_price' => 100.0 );
			},
			10
		);

		$service = WP_MCP_AI_YFinance_Service::get_instance();
		$first   = $service->get_current_price( 'AAPL' );
		$this->assertSame( 100.0, $first['current_price'] );

		// 2. Expire the fresh copy only (keep the stale copy).
		$fresh_key = 'wp_mcp_ai_yfinance_price_' . md5( 'AAPL_1d' );
		delete_transient( $fresh_key );

		// 3. Primary + all fallbacks now fail.
		remove_all_filters( 'wp_mcp_ai_yfinance_current_price' );
		add_filter(
			'wp_mcp_ai_yfinance_current_price',
			function () {
				return new WP_Error( 'microservice_down', 'down' );
			},
			10
		);
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function () {
				return array(
					'body' => '',
					'code' => 500,
				);
			},
			10
		);

		$second = $service->get_current_price( 'AAPL' );

		$this->assertNotWPError( $second );
		$this->assertSame( 100.0, $second['current_price'] );
		$this->assertTrue( $second['stale'] );
		$this->assertTrue( $second['stale_while_revalidate'] );
		$this->assertArrayHasKey( 'data_age_seconds', $second );
	}

	/**
	 * Test fallbacks-disabled gate: primary failure without fallback errors out
	 * unless a stale copy exists.
	 */
	public function test_service_respects_fallback_disable() {
		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_financial_planner_toolkit' => true,
				'enable_yfinance_service'          => true,
				'yfinance_fallback_providers'      => 0,
			)
		);

		add_filter(
			'wp_mcp_ai_yfinance_current_price',
			function () {
				return new WP_Error( 'microservice_down', 'down' );
			},
			10
		);

		$service = WP_MCP_AI_YFinance_Service::get_instance();
		$result  = $service->get_current_price( 'AAPL' );

		$this->assertWPError( $result );
		$this->assertSame( 'microservice_down', $result->get_error_code() );
	}

	/**
	 * Test batch fallback: per-ticker stooq quotes with partial errors.
	 */
	public function test_service_batch_fallback_partial() {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result, $url ) {
				if ( false !== strpos( $url, 'stooq.com/q/l/?s=AAPL' ) ) {
					return array(
						'body' => "Symbol,Date,Time,Open,High,Low,Close,Volume\r\nAAPL,2026-09-15,22:00:02,220.10,224.50,219.80,223.85,52345678\r\n",
						'code' => 200,
					);
				}
				if ( false !== strpos( $url, 'stooq.com/q/l/?s=MSFT' ) ) {
					return array(
						'body' => "Symbol,Date,Time,Open,High,Low,Close,Volume\r\nMSFT,2026-09-15,22:00:02,410,412,408,411.5,20000000\r\n",
						'code' => 200,
					);
				}
				return array(
					'body' => 'N/D',
					'code' => 200,
				);
			},
			10,
			2
		);

		$service = WP_MCP_AI_YFinance_Service::get_instance();
		$result  = $service->get_batch_prices( array( 'AAPL', 'MSFT', 'ZZZZ' ) );

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['fallback_used'] );
		$this->assertTrue( $result['partial'] );
		$this->assertArrayHasKey( 'AAPL', $result['data'] );
		$this->assertArrayHasKey( 'MSFT', $result['data'] );
		$this->assertArrayHasKey( 'ZZZZ', $result['errors'] );
	}
}
