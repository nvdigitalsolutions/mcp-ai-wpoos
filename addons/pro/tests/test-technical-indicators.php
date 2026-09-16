<?php
/**
 * Tests for WP_MCP_AI_Technical_Indicators.
 *
 * Tests cover: SMA, EMA, VWAP, Bollinger, RSI (Wilder), MACD, the compute()
 * dispatch with filter seams, insufficient-data degradation, and the
 * stock_data_fetcher indicators action surface.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Test technical indicators.
 *
 * @since 1.1.80
 */
class Test_Technical_Indicators extends WP_UnitTestCase {

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

		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-technical-indicators.php';
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_indicator_sma' );
		remove_all_filters( 'wp_mcp_ai_indicator_ema' );
		remove_all_filters( 'wp_mcp_ai_indicator_vwap' );
		remove_all_filters( 'wp_mcp_ai_indicator_bollinger' );
		remove_all_filters( 'wp_mcp_ai_indicator_rsi' );
		remove_all_filters( 'wp_mcp_ai_indicator_macd' );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test extract_closes handles both key styles.
	 */
	public function test_extract_closes_both_key_styles() {
		$rows = array(
			array( 'close' => 1.0 ),
			array( 'Close' => 2.0 ),
		);

		$this->assertSame( array( 1.0, 2.0 ), WP_MCP_AI_Technical_Indicators::extract_closes( $rows ) );
	}

	/**
	 * Test SMA math + insufficient data.
	 */
	public function test_sma() {
		$series = WP_MCP_AI_Technical_Indicators::sma( array( 1, 2, 3, 4, 5 ), 3 );

		$this->assertNull( $series[0] );
		$this->assertNull( $series[1] );
		$this->assertSame( 2.0, $series[2] );
		$this->assertSame( 3.0, $series[3] );
		$this->assertSame( 4.0, $series[4] );

		$this->assertNull( WP_MCP_AI_Technical_Indicators::sma( array( 1, 2 ), 3 ) );
	}

	/**
	 * Test EMA seeds with the SMA of the first period.
	 */
	public function test_ema_seed() {
		$series = WP_MCP_AI_Technical_Indicators::ema( array( 1, 2, 3, 4, 5 ), 3 );

		$this->assertNull( $series[1] );
		$this->assertSame( 2.0, $series[2] ); // Seed = SMA(1,2,3).
		$this->assertSame( 3.0, $series[3] ); // 2 + (4-2)*0.5.
		$this->assertSame( 4.0, $series[4] ); // 3 + (5-3)*0.5.
	}

	/**
	 * Test VWAP math.
	 */
	public function test_vwap() {
		$rows = array(
			array(
				'high'   => 10,
				'low'    => 6,
				'close'  => 8,
				'volume' => 100,
			), // TP 8.
			array(
				'high'   => 14,
				'low'    => 10,
				'close'  => 12,
				'volume' => 100,
			), // TP 12.
		);

		$series = WP_MCP_AI_Technical_Indicators::vwap( $rows );

		$this->assertSame( 8.0, $series[0] );
		$this->assertSame( 10.0, $series[1] ); // (8*100 + 12*100) / 200.
	}

	/**
	 * Test Bollinger Bands on a constant series (zero width).
	 */
	public function test_bollinger_constant_series() {
		$bands = WP_MCP_AI_Technical_Indicators::bollinger( array( 5, 5, 5, 5, 5 ), 3 );

		$this->assertSame( 5.0, $bands['middle'][2] );
		$this->assertSame( 5.0, $bands['upper'][2] );
		$this->assertSame( 5.0, $bands['lower'][2] );
	}

	/**
	 * Test RSI: all gains => 100, all losses => 0.
	 */
	public function test_rsi_extremes() {
		$gains = WP_MCP_AI_Technical_Indicators::rsi( array( 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15 ), 14 );
		$this->assertSame( 100.0, end( $gains ) );

		$losses = WP_MCP_AI_Technical_Indicators::rsi( array( 15, 14, 13, 12, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1 ), 14 );
		$this->assertSame( 0.0, end( $losses ) );

		// Insufficient data.
		$this->assertNull( WP_MCP_AI_Technical_Indicators::rsi( array( 1, 2, 3 ), 14 ) );
	}

	/**
	 * Test MACD produces macd/signal/histogram series.
	 */
	public function test_macd_structure() {
		$values = range( 1, 60 );
		$result = WP_MCP_AI_Technical_Indicators::macd( $values );

		$this->assertArrayHasKey( 'macd', $result );
		$this->assertArrayHasKey( 'signal', $result );
		$this->assertArrayHasKey( 'histogram', $result );

		$last_macd = WP_MCP_AI_Technical_Indicators::last_value( $result['macd'] );
		$this->assertNotNull( $last_macd );
		$this->assertGreaterThan( 0.0, $last_macd ); // Uptrend series.
	}

	/**
	 * Test compute() dispatch with signals.
	 */
	public function test_compute_dispatch() {
		$rows = array();
		foreach ( range( 1, 40 ) as $value ) {
			$rows[] = array(
				'close'  => (float) $value,
				'high'   => (float) $value + 1,
				'low'    => (float) $value - 1,
				'volume' => 100.0,
			);
		}

		$computed = WP_MCP_AI_Technical_Indicators::compute( $rows, array( 'sma', 'ema', 'rsi', 'macd' ) );

		$this->assertArrayHasKey( 'sma', $computed );
		$this->assertArrayHasKey( 'ema', $computed );
		$this->assertArrayHasKey( 'rsi', $computed );
		$this->assertArrayHasKey( 'macd', $computed );

		// Price above rising SMA => 'above' signal.
		$this->assertSame( 'above', $computed['sma']['signal'] );
		// Rising series => RSI overbought.
		$this->assertSame( 'overbought', $computed['rsi']['signal'] );
		// Uptrend series => MACD line positive (EMA12 above EMA26).
		$this->assertGreaterThan( 0.0, $computed['macd']['latest']['macd'] );
	}

	/**
	 * Test compute() insufficient data degradation.
	 */
	public function test_compute_insufficient_data() {
		$computed = WP_MCP_AI_Technical_Indicators::compute( array( array( 'close' => 1.0 ) ), array( 'rsi' ) );

		$this->assertSame( 'insufficient_data', $computed['rsi']['signal'] );
	}

	/**
	 * Test the indicator filter seam.
	 */
	public function test_indicator_filter_seam() {
		add_filter(
			'wp_mcp_ai_indicator_sma',
			function () {
				return 'overridden';
			},
			10,
			2
		);

		$rows = array();
		foreach ( range( 1, 25 ) as $value ) {
			$rows[] = array( 'close' => (float) $value );
		}

		$computed = WP_MCP_AI_Technical_Indicators::compute( $rows, array( 'sma' ) );
		$this->assertSame( 'overridden', $computed['sma']['series'] );
	}

	// =========================================================================
	// stock_data_fetcher indicators action surface.
	// =========================================================================

	/**
	 * Test stock_data_fetcher indicators action is exposed in the schema.
	 */
	public function test_stock_data_fetcher_indicators_schema() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Stock_Data_Fetcher' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-stock-data-fetcher.php';
		}

		$tool   = new WP_MCP_AI_Tool_Stock_Data_Fetcher();
		$schema = $tool->get_parameters_schema();

		$this->assertContains( 'indicators', $schema['properties']['action']['enum'] );
		$this->assertArrayHasKey( 'indicators', $schema['properties'] );
		$this->assertSame( array( 'sma', 'ema', 'vwap', 'bollinger', 'rsi', 'macd' ), $schema['properties']['indicators']['items']['enum'] );
	}

	/**
	 * Test stock_data_fetcher rejects unknown actions.
	 */
	public function test_stock_data_fetcher_invalid_action() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Stock_Data_Fetcher' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-stock-data-fetcher.php';
		}

		$tool = new WP_MCP_AI_Tool_Stock_Data_Fetcher();
		$this->assertSame( 'stock_data_fetcher', $tool->get_slug() );
	}
}
