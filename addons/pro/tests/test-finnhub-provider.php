<?php
/**
 * Tests for the Finnhub market data provider (OpenStock parity, proposal 051).
 *
 * Covers: enablement gating, key parsing, quote/history/search/batch
 * normalization, key rotation on 429, filter-seam pass-through, realtime
 * cache-TTL shortening, SWR stale serving, and response caching.
 *
 * All network access flows through the `wp_mcp_ai_market_data_http_response`
 * filter seam — no live HTTP calls.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Test Finnhub provider.
 *
 * @since 1.1.90
 */
class Test_Finnhub_Provider extends WP_UnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			define( 'WP_MCP_AI_PRO_VERSION', '1.1.90-test' );
		}

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__ ) . '/' );
		}

		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_financial_planner_toolkit' => true,
			)
		);
		update_option(
			'wp_mcp_ai_financial_planner_settings',
			array(
				'finnhub_api_keys' => 'key_one,key_two',
			)
		);

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-finnhub-provider.php';
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_market_data_http_response' );
		remove_all_filters( 'wp_mcp_ai_finnhub_api_keys' );
		remove_all_filters( 'wp_mcp_ai_yfinance_cache_ttl' );
		remove_all_filters( 'wp_mcp_ai_yfinance_current_price' );
		remove_all_filters( 'wp_mcp_ai_yfinance_batch_prices' );
		remove_all_filters( 'wp_mcp_ai_yfinance_search_ticker' );
		remove_all_filters( 'wp_mcp_ai_yfinance_price_history' );

		delete_option( 'wp_mcp_ai_settings' );
		delete_option( 'wp_mcp_ai_financial_planner_settings' );

		parent::tearDown();
	}

	/**
	 * Simulate a Finnhub HTTP response via the seam.
	 *
	 * @param array|WP_Error $body Response body (array) or error.
	 * @param int            $code HTTP status code.
	 * @param callable|null  $url_capture Optional URL capturer.
	 * @return void
	 */
	private function mock_response( $body, $code = 200, $url_capture = null ) {
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $seam, $url ) use ( $body, $code, $url_capture ) {
				if ( is_callable( $url_capture ) ) {
					$url_capture( $url );
				}

				if ( is_wp_error( $body ) ) {
					return $body;
				}

				return array(
					'body' => wp_json_encode( $body ),
					'code' => $code,
				);
			},
			10,
			2
		);
	}

	/**
	 * Enablement requires the toolkit flag and at least one key.
	 */
	public function test_is_enabled_requires_toolkit_and_keys() {
		$this->assertTrue( WP_MCP_AI_Finnhub_Provider::is_enabled() );

		delete_option( 'wp_mcp_ai_financial_planner_settings' );
		$this->assertFalse( WP_MCP_AI_Finnhub_Provider::is_enabled() );

		update_option( 'wp_mcp_ai_financial_planner_settings', array( 'finnhub_api_keys' => 'key_one' ) );
		update_option( 'wp_mcp_ai_settings', array( 'enable_financial_planner_toolkit' => false ) );
		$this->assertFalse( WP_MCP_AI_Finnhub_Provider::is_enabled() );
	}

	/**
	 * Keys parse, trim, and de-duplicate.
	 */
	public function test_get_keys_parses_and_deduplicates() {
		update_option( 'wp_mcp_ai_financial_planner_settings', array( 'finnhub_api_keys' => ' key_one , key_two ,key_one ' ) );

		$keys = WP_MCP_AI_Finnhub_Provider::get_keys();

		$this->assertSame( array( 'key_one', 'key_two' ), $keys );
	}

	/**
	 * Quote normalization maps Finnhub fields to the toolkit shape.
	 */
	public function test_get_quote_normalizes_finnhub_response() {
		$this->mock_response(
			array(
				'c'  => 227.5,
				'd'  => 2.3,
				'dp' => 1.02,
				'h'  => 229.0,
				'l'  => 224.1,
				'o'  => 225.0,
				'pc' => 225.2,
				't'  => 1759363200,
			)
		);

		$quote = WP_MCP_AI_Finnhub_Provider::get_instance()->get_quote( 'AAPL' );

		$this->assertNotWPError( $quote );
		$this->assertSame( 'AAPL', $quote['symbol'] );
		$this->assertSame( 227.5, $quote['current_price'] );
		$this->assertSame( 'finnhub', $quote['source'] );
		$this->assertTrue( $quote['delayed'] );
		$this->assertFalse( $quote['from_cache'] );
	}

	/**
	 * A 429 on the first key rotates to the second key.
	 */
	public function test_quote_rotates_key_after_rate_limit() {
		$urls = array();

		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $seam, $url ) use ( &$urls ) {
				$urls[] = $url;

				if ( false !== strpos( $url, 'token=key_one' ) ) {
					return array(
						'body' => '{"error":"limited"}',
						'code' => 429,
					);
				}

				return array(
					'body' => '{"c": 100, "pc": 99}',
					'code' => 200,
				);
			},
			10,
			2
		);

		$quote = WP_MCP_AI_Finnhub_Provider::get_instance()->get_quote( 'MSFT' );

		$this->assertNotWPError( $quote );
		$this->assertSame( 100.0, $quote['current_price'] );
		$this->assertCount( 2, $urls );
		$this->assertStringContainsString( 'token=key_two', $urls[1] );
	}

	/**
	 * History normalization maps candle arrays to OHLCV rows.
	 */
	public function test_get_history_normalizes_candles() {
		$this->mock_response(
			array(
				's' => 'ok',
				't' => array( 1759276800, 1759363200 ),
				'c' => array( 225.0, 227.5 ),
				'o' => array( 224.5, 225.5 ),
				'h' => array( 226.0, 228.0 ),
				'l' => array( 223.0, 224.0 ),
				'v' => array( 50000000, 51000000 ),
			)
		);

		$history = WP_MCP_AI_Finnhub_Provider::get_instance()->get_history( 'AAPL', '1mo' );

		$this->assertNotWPError( $history );
		$this->assertSame( 2, $history['count'] );
		$this->assertSame( '1d', $history['interval'] );
		$this->assertSame( 227.5, $history['data'][1]['close'] );
		$this->assertSame( 'finnhub', $history['source'] );
	}

	/**
	 * Search returns a plain array of results (Node-client shape parity).
	 */
	public function test_search_symbol_returns_plain_results() {
		$this->mock_response(
			array(
				'count'  => 1,
				'result' => array(
					array(
						'symbol'      => 'aapl',
						'description' => 'APPLE INC',
						'type'        => 'Common Stock',
					),
				),
			)
		);

		$results = WP_MCP_AI_Finnhub_Provider::get_instance()->search_symbol( 'apple' );

		$this->assertNotWPError( $results );
		$this->assertCount( 1, $results );
		$this->assertSame( 'AAPL', $results[0]['symbol'] );
	}

	/**
	 * Batch quotes map multi-symbol responses by symbol.
	 */
	public function test_get_batch_quotes_maps_by_symbol() {
		$this->mock_response(
			array(
				array(
					'symbol' => 'AAPL',
					'c'      => 227.5,
					'd'      => 2.3,
					'dp'     => 1.02,
				),
				array(
					'symbol' => 'MSFT',
					'c'      => 420.1,
					'd'      => -1.1,
					'dp'     => -0.26,
				),
			)
		);

		$batch = WP_MCP_AI_Finnhub_Provider::get_instance()->get_batch_quotes( array( 'msft', 'aapl' ) );

		$this->assertNotWPError( $batch );
		$this->assertArrayHasKey( 'AAPL', $batch['data'] );
		$this->assertSame( 420.1, $batch['data']['MSFT']['current_price'] );
	}

	/**
	 * The yfinance filter handler passes through when disabled.
	 */
	public function test_filter_current_price_passes_through_when_disabled() {
		delete_option( 'wp_mcp_ai_financial_planner_settings' );

		$result = WP_MCP_AI_Finnhub_Provider::filter_current_price( false, array( 'ticker' => 'AAPL' ) );

		$this->assertFalse( $result );
	}

	/**
	 * The yfinance filter handler returns the Finnhub payload when enabled.
	 */
	public function test_filter_current_price_returns_payload_when_enabled() {
		$this->mock_response(
			array(
				'c'  => 227.5,
				'pc' => 225.2,
				't'  => 1759363200,
			)
		);

		$result = WP_MCP_AI_Finnhub_Provider::filter_current_price( false, array( 'ticker' => 'AAPL' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 227.5, $result['current_price'] );
		$this->assertSame( 'finnhub', $result['source'] );
	}

	/**
	 * The cache-TTL filter shortens the shared TTL only in realtime mode.
	 */
	public function test_filter_cache_ttl_shortens_in_realtime_mode() {
		$this->assertSame( 900, WP_MCP_AI_Finnhub_Provider::filter_cache_ttl( 900 ) );

		update_option(
			'wp_mcp_ai_financial_planner_settings',
			array(
				'finnhub_api_keys'  => 'key_one',
				'finnhub_data_mode' => 'realtime',
			)
		);

		$this->assertSame( 60, WP_MCP_AI_Finnhub_Provider::filter_cache_ttl( 900 ) );
	}

	/**
	 * A failed live fetch serves the stale last-known-good copy.
	 */
	public function test_quote_serves_stale_on_failure() {
		$this->mock_response(
			array(
				'c'  => 227.5,
				'pc' => 225.2,
				't'  => 1759363200,
			)
		);

		$provider = WP_MCP_AI_Finnhub_Provider::get_instance();
		$provider->get_quote( 'AAPL' );

		// Drop the fresh copy, then fail the live fetch.
		delete_transient( 'wp_mcp_ai_finnhub_quote_' . md5( 'AAPL' ) );
		remove_all_filters( 'wp_mcp_ai_market_data_http_response' );
		$this->mock_response( new WP_Error( 'http_failed', 'down' ) );

		$stale = $provider->get_quote( 'AAPL' );

		$this->assertNotWPError( $stale );
		$this->assertTrue( $stale['stale'] );
		$this->assertTrue( $stale['stale_while_revalidate'] );
		$this->assertSame( 227.5, $stale['current_price'] );
	}

	/**
	 * A second quote call is served from the fresh cache.
	 */
	public function test_quote_is_cached() {
		$this->mock_response(
			array(
				'c'  => 227.5,
				'pc' => 225.2,
				't'  => 1759363200,
			)
		);

		$provider = WP_MCP_AI_Finnhub_Provider::get_instance();
		$first    = $provider->get_quote( 'AAPL' );

		// Change the upstream answer — cache must win.
		remove_all_filters( 'wp_mcp_ai_market_data_http_response' );
		$this->mock_response(
			array(
				'c'  => 999.0,
				'pc' => 998.0,
				't'  => 1759363200,
			)
		);

		$second = $provider->get_quote( 'AAPL' );

		$this->assertFalse( $first['from_cache'] );
		$this->assertTrue( $second['from_cache'] );
		$this->assertSame( 227.5, $second['current_price'] );
	}
}
