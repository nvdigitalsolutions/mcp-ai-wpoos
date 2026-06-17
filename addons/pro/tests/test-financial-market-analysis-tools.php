<?php
/**
 * Tests for Financial Market Analysis Tools.
 *
 * Tests cover:
 *  - Financial News Aggregator (slug, name, schema, capability flags, permissions, availability)
 *  - Stock Data Fetcher (slug, schema, permissions, missing action validation)
 *  - Market Sentiment Analyzer (slug, schema, permissions, keyword-based analysis)
 *  - Market Forecast Analyzer (slug, schema, permissions, statistical methods)
 *  - Investment Signal Tracker (slug, schema, permissions, create/list/evaluate lifecycle)
 *  - Financial Logic Visualizer (slug, schema, permissions, Mermaid output)
 *  - Financial Report Generator (slug, schema, permissions, report generation)
 *  - Financial Search (slug, schema, permissions)
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Test Financial Market Analysis Tools.
 *
 * @since 1.1.0
 */
class Test_Financial_Market_Analysis_Tools extends WP_UnitTestCase {

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
			define( 'WP_MCP_AI_PRO_VERSION', '1.1.0-test' );
		}

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			define( 'WP_MCP_AI_PRO_PATH', dirname( __DIR__ ) . '/' );
		}

		// Enable financial planner toolkit.
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_financial_planner_toolkit' => true )
		);

		// Create editor user.
		$this->editor_user = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_user );

		// Load tool classes.
		$base = dirname( __DIR__ ) . '/includes/tools/financial-planning/';

		if ( ! class_exists( 'WP_MCP_AI_Tool_Financial_News_Aggregator' ) ) {
			require_once $base . 'class-wp-mcp-ai-tool-financial-news-aggregator.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Stock_Data_Fetcher' ) ) {
			require_once $base . 'class-wp-mcp-ai-tool-stock-data-fetcher.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Market_Sentiment_Analyzer' ) ) {
			require_once $base . 'class-wp-mcp-ai-tool-market-sentiment-analyzer.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Market_Forecast_Analyzer' ) ) {
			require_once $base . 'class-wp-mcp-ai-tool-market-forecast-analyzer.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Investment_Signal_Tracker' ) ) {
			require_once $base . 'class-wp-mcp-ai-tool-investment-signal-tracker.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Financial_Logic_Visualizer' ) ) {
			require_once $base . 'class-wp-mcp-ai-tool-financial-logic-visualizer.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Financial_Report_Generator' ) ) {
			require_once $base . 'class-wp-mcp-ai-tool-financial-report-generator.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Tool_Financial_Search' ) ) {
			require_once $base . 'class-wp-mcp-ai-tool-financial-search.php';
		}
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		delete_option( 'wp_mcp_ai_settings' );
		// Clean up signal tracker data.
		delete_option( 'wp_mcp_ai_investment_signals_' . $this->editor_user );
		parent::tearDown();
	}

	// =========================================================================
	// Financial News Aggregator.
	// =========================================================================

	/**
	 * Test news aggregator slug.
	 */
	public function test_news_aggregator_slug() {
		$tool = new WP_MCP_AI_Tool_Financial_News_Aggregator();
		$this->assertSame( 'financial_news_aggregator', $tool->get_slug() );
	}

	/**
	 * Test news aggregator name.
	 */
	public function test_news_aggregator_name() {
		$tool = new WP_MCP_AI_Tool_Financial_News_Aggregator();
		$this->assertSame( 'Financial News Aggregator', $tool->get_name() );
	}

	/**
	 * Test news aggregator schema has expected properties.
	 */
	public function test_news_aggregator_schema() {
		$tool   = new WP_MCP_AI_Tool_Financial_News_Aggregator();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'sources', $schema['properties'] );
		$this->assertArrayHasKey( 'category', $schema['properties'] );
		$this->assertArrayHasKey( 'keywords', $schema['properties'] );
		$this->assertArrayHasKey( 'limit', $schema['properties'] );
		$this->assertArrayHasKey( 'hours_back', $schema['properties'] );
	}

	/**
	 * Test news aggregator capability flags.
	 */
	public function test_news_aggregator_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Financial_News_Aggregator();
		$flags = $tool->get_capability_flags();

		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'computation', $flags );
	}

	/**
	 * Test news aggregator permission check rejects unauthenticated users.
	 */
	public function test_news_aggregator_permission_check() {
		wp_set_current_user( 0 );
		$tool   = new WP_MCP_AI_Tool_Financial_News_Aggregator();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test news aggregator is available when toolkit is enabled.
	 */
	public function test_news_aggregator_availability() {
		$this->assertTrue( WP_MCP_AI_Tool_Financial_News_Aggregator::is_available() );
	}

	/**
	 * Test news aggregator is unavailable when toolkit is disabled.
	 */
	public function test_news_aggregator_unavailable_when_disabled() {
		update_option( 'wp_mcp_ai_settings', array() );
		$this->assertFalse( WP_MCP_AI_Tool_Financial_News_Aggregator::is_available() );
	}

	// =========================================================================
	// Stock Data Fetcher.
	// =========================================================================

	/**
	 * Test stock data fetcher slug.
	 */
	public function test_stock_data_fetcher_slug() {
		$tool = new WP_MCP_AI_Tool_Stock_Data_Fetcher();
		$this->assertSame( 'stock_data_fetcher', $tool->get_slug() );
	}

	/**
	 * Test stock data fetcher schema has action in required.
	 */
	public function test_stock_data_fetcher_schema_has_action() {
		$tool   = new WP_MCP_AI_Tool_Stock_Data_Fetcher();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'action', $schema['required'] );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
	}

	/**
	 * Test stock data fetcher permission check rejects unauthenticated users.
	 */
	public function test_stock_data_fetcher_permission_check() {
		wp_set_current_user( 0 );
		$tool   = new WP_MCP_AI_Tool_Stock_Data_Fetcher();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test stock data fetcher returns error when action is missing.
	 */
	public function test_stock_data_fetcher_missing_action() {
		$tool   = new WP_MCP_AI_Tool_Stock_Data_Fetcher();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_action', $result->get_error_code() );
	}

	// =========================================================================
	// Market Sentiment Analyzer.
	// =========================================================================

	/**
	 * Test sentiment analyzer slug.
	 */
	public function test_sentiment_analyzer_slug() {
		$tool = new WP_MCP_AI_Tool_Market_Sentiment_Analyzer();
		$this->assertSame( 'market_sentiment_analyzer', $tool->get_slug() );
	}

	/**
	 * Test sentiment analyzer schema has texts in required.
	 */
	public function test_sentiment_analyzer_schema() {
		$tool   = new WP_MCP_AI_Tool_Market_Sentiment_Analyzer();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'texts', $schema['required'] );
		$this->assertArrayHasKey( 'texts', $schema['properties'] );
		$this->assertArrayHasKey( 'include_aggregate', $schema['properties'] );
	}

	/**
	 * Test sentiment analyzer permission check rejects unauthenticated users.
	 */
	public function test_sentiment_analyzer_permission_check() {
		wp_set_current_user( 0 );
		$tool   = new WP_MCP_AI_Tool_Market_Sentiment_Analyzer();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test sentiment analyzer returns error for empty texts.
	 */
	public function test_sentiment_analyzer_empty_texts() {
		$tool   = new WP_MCP_AI_Tool_Market_Sentiment_Analyzer();
		$result = $tool->execute( array( 'texts' => array() ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'empty_texts', $result->get_error_code() );
	}

	/**
	 * Test sentiment analyzer returns positive score for bullish text.
	 */
	public function test_sentiment_analyzer_positive_text() {
		$tool   = new WP_MCP_AI_Tool_Market_Sentiment_Analyzer();
		$result = $tool->execute(
			array(
				'texts'             => array(
					'Stock surged to all-time high after strong earnings beat with massive revenue growth and bullish momentum.',
				),
				'include_aggregate' => false,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $result['results'] );
		$this->assertGreaterThan( 0, $result['results'][0]['score'] );
		$this->assertSame( 'positive', $result['results'][0]['label'] );
		$this->assertNotEmpty( $result['results'][0]['positive_matches'] );
	}

	/**
	 * Test sentiment analyzer returns negative score for bearish text.
	 */
	public function test_sentiment_analyzer_negative_text() {
		$tool   = new WP_MCP_AI_Tool_Market_Sentiment_Analyzer();
		$result = $tool->execute(
			array(
				'texts'             => array(
					'Market crashed amid recession fears as losses mounted, bearish selloff triggered by earnings miss and bankruptcy warnings.',
				),
				'include_aggregate' => false,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertLessThan( 0, $result['results'][0]['score'] );
		$this->assertSame( 'negative', $result['results'][0]['label'] );
		$this->assertNotEmpty( $result['results'][0]['negative_matches'] );
	}

	/**
	 * Test sentiment analyzer returns near-zero score for neutral text.
	 */
	public function test_sentiment_analyzer_neutral_text() {
		$tool   = new WP_MCP_AI_Tool_Market_Sentiment_Analyzer();
		$result = $tool->execute(
			array(
				'texts'             => array(
					'The committee met today to discuss procedural updates for the next fiscal quarter schedule.',
				),
				'include_aggregate' => false,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		// Score should be between -0.1 and 0.1 for neutral.
		$score = $result['results'][0]['score'];
		$this->assertGreaterThanOrEqual( -0.1, $score );
		$this->assertLessThanOrEqual( 0.1, $score );
		$this->assertSame( 'neutral', $result['results'][0]['label'] );
	}

	/**
	 * Test sentiment analyzer aggregate output when include_aggregate is true.
	 */
	public function test_sentiment_analyzer_aggregate() {
		$tool   = new WP_MCP_AI_Tool_Market_Sentiment_Analyzer();
		$result = $tool->execute(
			array(
				'texts'             => array(
					'Stock surged after earnings beat.',
					'Market declined sharply on recession fears.',
				),
				'include_aggregate' => true,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertArrayHasKey( 'aggregate', $result );
		$this->assertArrayHasKey( 'average_score', $result['aggregate'] );
		$this->assertArrayHasKey( 'average_confidence', $result['aggregate'] );
		$this->assertArrayHasKey( 'overall_label', $result['aggregate'] );
		$this->assertArrayHasKey( 'texts_analyzed', $result['aggregate'] );
		$this->assertSame( 2, $result['aggregate']['texts_analyzed'] );
	}

	// =========================================================================
	// Market Forecast Analyzer.
	// =========================================================================

	/**
	 * Test forecast analyzer slug.
	 */
	public function test_forecast_analyzer_slug() {
		$tool = new WP_MCP_AI_Tool_Market_Forecast_Analyzer();
		$this->assertSame( 'market_forecast_analyzer', $tool->get_slug() );
	}

	/**
	 * Test forecast analyzer schema has historical_data in required.
	 */
	public function test_forecast_analyzer_schema() {
		$tool   = new WP_MCP_AI_Tool_Market_Forecast_Analyzer();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'historical_data', $schema['required'] );
		$this->assertArrayHasKey( 'historical_data', $schema['properties'] );
		$this->assertArrayHasKey( 'method', $schema['properties'] );
		$this->assertArrayHasKey( 'forecast_periods', $schema['properties'] );
	}

	/**
	 * Test forecast analyzer permission check rejects unauthenticated users.
	 */
	public function test_forecast_analyzer_permission_check() {
		wp_set_current_user( 0 );
		$tool   = new WP_MCP_AI_Tool_Market_Forecast_Analyzer();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test forecast analyzer returns error with insufficient data (< 3 points).
	 */
	public function test_forecast_analyzer_insufficient_data() {
		$tool   = new WP_MCP_AI_Tool_Market_Forecast_Analyzer();
		$result = $tool->execute(
			array(
				'historical_data' => array(
					array(
						'date'  => '2024-01-01',
						'value' => 100,
					),
					array(
						'date'  => '2024-01-02',
						'value' => 102,
					),
				),
			),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'insufficient_data', $result->get_error_code() );
	}

	/**
	 * Test forecast analyzer linear regression with ascending data.
	 */
	public function test_forecast_analyzer_linear_regression() {
		$tool   = new WP_MCP_AI_Tool_Market_Forecast_Analyzer();
		$result = $tool->execute(
			array(
				'historical_data'  => array(
					array(
						'date'  => '2024-01-01',
						'value' => 1,
					),
					array(
						'date'  => '2024-01-02',
						'value' => 2,
					),
					array(
						'date'  => '2024-01-03',
						'value' => 3,
					),
					array(
						'date'  => '2024-01-04',
						'value' => 4,
					),
					array(
						'date'  => '2024-01-05',
						'value' => 5,
					),
				),
				'method'           => 'linear_regression',
				'forecast_periods' => 3,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'linear_regression', $result['method'] );
		$this->assertSame( 5, $result['data_points'] );

		// With perfectly linear data [1,2,3,4,5], next forecast should be > 5.
		$forecast_points = $result['forecast']['points'];
		$this->assertCount( 3, $forecast_points );
		$this->assertGreaterThan( 5, $forecast_points[0]['forecast_value'] );

		// Trend should be upward.
		$this->assertSame( 'upward', $result['trend']['direction'] );
		$this->assertSame( 'strong', $result['trend']['strength'] );
	}

	/**
	 * Test forecast analyzer moving average method.
	 */
	public function test_forecast_analyzer_moving_average() {
		$tool   = new WP_MCP_AI_Tool_Market_Forecast_Analyzer();
		$result = $tool->execute(
			array(
				'historical_data'       => array(
					array(
						'date'  => '2024-01-01',
						'value' => 10,
					),
					array(
						'date'  => '2024-01-02',
						'value' => 12,
					),
					array(
						'date'  => '2024-01-03',
						'value' => 14,
					),
					array(
						'date'  => '2024-01-04',
						'value' => 16,
					),
					array(
						'date'  => '2024-01-05',
						'value' => 18,
					),
				),
				'method'                => 'moving_average',
				'moving_average_window' => 3,
				'forecast_periods'      => 2,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'moving_average', $result['method'] );

		// Moving average of last 3 values: (14+16+18)/3 = 16.
		$forecast_points = $result['forecast']['points'];
		$this->assertCount( 2, $forecast_points );
		$this->assertEquals( 16.0, $forecast_points[0]['forecast_value'], '', 0.01 );
	}

	/**
	 * Test forecast analyzer exponential smoothing method.
	 */
	public function test_forecast_analyzer_exponential_smoothing() {
		$tool   = new WP_MCP_AI_Tool_Market_Forecast_Analyzer();
		$result = $tool->execute(
			array(
				'historical_data'  => array(
					array(
						'date'  => '2024-01-01',
						'value' => 100,
					),
					array(
						'date'  => '2024-01-02',
						'value' => 110,
					),
					array(
						'date'  => '2024-01-03',
						'value' => 120,
					),
				),
				'method'           => 'exponential_smoothing',
				'smoothing_factor' => 0.5,
				'forecast_periods' => 1,
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'exponential_smoothing', $result['method'] );

		// Manual: s0=100, s1=0.5*110+0.5*100=105, s2=0.5*120+0.5*105=112.5.
		$forecast_value = $result['forecast']['points'][0]['forecast_value'];
		$this->assertEquals( 112.5, $forecast_value, '', 0.01 );
	}

	/**
	 * Test forecast analyzer sentiment adjustment modifies forecast values.
	 */
	public function test_forecast_analyzer_sentiment_adjustment() {
		$tool      = new WP_MCP_AI_Tool_Market_Forecast_Analyzer();
		$base_args = array(
			'historical_data'              => array(
				array(
					'date'  => '2024-01-01',
					'value' => 100,
				),
				array(
					'date'  => '2024-01-02',
					'value' => 100,
				),
				array(
					'date'  => '2024-01-03',
					'value' => 100,
				),
			),
			'method'                       => 'linear_regression',
			'forecast_periods'             => 1,
			'include_confidence_intervals' => false,
		);

		// Baseline without adjustment.
		$result_base = $tool->execute(
			array_merge( $base_args, array( 'sentiment_adjustment' => 0 ) ),
			array()
		);

		// Positive sentiment adjustment.
		$result_positive = $tool->execute(
			array_merge( $base_args, array( 'sentiment_adjustment' => 1.0 ) ),
			array()
		);

		$this->assertIsArray( $result_base );
		$this->assertIsArray( $result_positive );

		$base_value     = $result_base['forecast']['points'][0]['forecast_value'];
		$positive_value = $result_positive['forecast']['points'][0]['forecast_value'];

		// Positive sentiment should increase forecast value.
		$this->assertGreaterThan( $base_value, $positive_value );
	}

	// =========================================================================
	// Investment Signal Tracker.
	// =========================================================================

	/**
	 * Test signal tracker slug.
	 */
	public function test_signal_tracker_slug() {
		$tool = new WP_MCP_AI_Tool_Investment_Signal_Tracker();
		$this->assertSame( 'investment_signal_tracker', $tool->get_slug() );
	}

	/**
	 * Test signal tracker schema has action in required.
	 */
	public function test_signal_tracker_schema() {
		$tool   = new WP_MCP_AI_Tool_Investment_Signal_Tracker();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'action', $schema['required'] );
		$this->assertArrayHasKey( 'action', $schema['properties'] );
		$this->assertArrayHasKey( 'signal', $schema['properties'] );
		$this->assertArrayHasKey( 'signal_id', $schema['properties'] );
	}

	/**
	 * Test signal tracker permission check rejects unauthenticated users.
	 */
	public function test_signal_tracker_permission_check() {
		wp_set_current_user( 0 );
		$tool   = new WP_MCP_AI_Tool_Investment_Signal_Tracker();
		$result = $tool->execute( array( 'action' => 'list' ), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test signal tracker creates a signal and returns it.
	 */
	public function test_signal_tracker_create_signal() {
		$tool   = new WP_MCP_AI_Tool_Investment_Signal_Tracker();
		$result = $tool->execute(
			array(
				'action' => 'create',
				'signal' => array(
					'ticker'       => 'AAPL',
					'thesis'       => 'Strong iPhone demand for Q4',
					'direction'    => 'bullish',
					'confidence'   => 80,
					'entry_price'  => 150.00,
					'target_price' => 180.00,
					'stop_loss'    => 140.00,
				),
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'create', $result['action'] );
		$this->assertArrayHasKey( 'signal', $result );
		$this->assertSame( 'AAPL', $result['signal']['ticker'] );
		$this->assertSame( 'bullish', $result['signal']['direction'] );
		$this->assertSame( 80, $result['signal']['confidence'] );
		$this->assertSame( 'active', $result['signal']['status'] );
		$this->assertStringStartsWith( 'sig_', $result['signal']['signal_id'] );
	}

	/**
	 * Test signal tracker lists signals after creation.
	 */
	public function test_signal_tracker_list_signals() {
		$tool = new WP_MCP_AI_Tool_Investment_Signal_Tracker();

		// Create a signal first.
		$tool->execute(
			array(
				'action' => 'create',
				'signal' => array(
					'ticker'    => 'MSFT',
					'thesis'    => 'Cloud growth thesis',
					'direction' => 'bullish',
				),
			),
			array()
		);

		// List signals.
		$result = $tool->execute(
			array( 'action' => 'list' ),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'list', $result['action'] );
		$this->assertSame( 1, $result['total_count'] );
		$this->assertSame( 1, $result['active_count'] );
		$this->assertCount( 1, $result['signals'] );
		$this->assertSame( 'MSFT', $result['signals'][0]['ticker'] );
	}

	/**
	 * Test signal tracker evaluates a signal with current_price.
	 */
	public function test_signal_tracker_evaluate_signal() {
		$tool = new WP_MCP_AI_Tool_Investment_Signal_Tracker();

		// Create a signal.
		$create_result = $tool->execute(
			array(
				'action' => 'create',
				'signal' => array(
					'ticker'       => 'GOOG',
					'thesis'       => 'AI growth catalyst',
					'direction'    => 'bullish',
					'confidence'   => 70,
					'entry_price'  => 100.00,
					'target_price' => 130.00,
					'stop_loss'    => 90.00,
				),
			),
			array()
		);

		$signal_id = $create_result['signal']['signal_id'];

		// Evaluate with a higher price (profit).
		$eval_result = $tool->execute(
			array(
				'action'        => 'evaluate',
				'signal_id'     => $signal_id,
				'current_price' => 115.00,
			),
			array()
		);

		$this->assertIsArray( $eval_result );
		$this->assertTrue( $eval_result['success'] );
		$this->assertSame( 'evaluate', $eval_result['action'] );
		$this->assertSame( $signal_id, $eval_result['signal_id'] );
		$this->assertArrayHasKey( 'price_analysis', $eval_result );
		$this->assertGreaterThan( 0, $eval_result['price_analysis']['pnl'] );
		$this->assertGreaterThan( 0, $eval_result['price_analysis']['pnl_pct'] );
	}

	// =========================================================================
	// Financial Logic Visualizer.
	// =========================================================================

	/**
	 * Test logic visualizer slug.
	 */
	public function test_logic_visualizer_slug() {
		$tool = new WP_MCP_AI_Tool_Financial_Logic_Visualizer();
		$this->assertSame( 'financial_logic_visualizer', $tool->get_slug() );
	}

	/**
	 * Test logic visualizer schema has required fields.
	 */
	public function test_logic_visualizer_schema() {
		$tool   = new WP_MCP_AI_Tool_Financial_Logic_Visualizer();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'chain_type', $schema['required'] );
		$this->assertContains( 'nodes', $schema['required'] );
		$this->assertContains( 'connections', $schema['required'] );
	}

	/**
	 * Test logic visualizer permission check rejects unauthenticated users.
	 */
	public function test_logic_visualizer_permission_check() {
		wp_set_current_user( 0 );
		$tool   = new WP_MCP_AI_Tool_Financial_Logic_Visualizer();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test logic visualizer generates valid Mermaid for a transmission chain.
	 */
	public function test_logic_visualizer_transmission_chain() {
		$tool   = new WP_MCP_AI_Tool_Financial_Logic_Visualizer();
		$result = $tool->execute(
			array(
				'chain_type'  => 'transmission_chain',
				'nodes'       => array(
					array(
						'id'    => 'A',
						'label' => 'Rate Hike',
						'type'  => 'event',
					),
					array(
						'id'    => 'B',
						'label' => 'Bond Yields Rise',
						'type'  => 'factor',
					),
					array(
						'id'    => 'C',
						'label' => 'Stocks Decline',
						'type'  => 'outcome',
					),
				),
				'connections' => array(
					array(
						'from'     => 'A',
						'to'       => 'B',
						'label'    => 'causes',
						'strength' => 'strong',
					),
					array(
						'from'     => 'B',
						'to'       => 'C',
						'label'    => 'leads to',
						'strength' => 'moderate',
					),
				),
				'direction'   => 'LR',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'transmission_chain', $result['diagram_type'] );
		$this->assertSame( 3, $result['node_count'] );
		$this->assertSame( 2, $result['connection_count'] );

		// Verify Mermaid syntax.
		$mermaid = $result['mermaid_code'];
		$this->assertStringContainsString( 'flowchart LR', $mermaid );
		$this->assertStringContainsString( 'A(', $mermaid );
		$this->assertStringContainsString( 'B[', $mermaid );
		$this->assertStringContainsString( 'C[[', $mermaid );
		$this->assertStringContainsString( '==>', $mermaid );
		$this->assertStringContainsString( '-->', $mermaid );
	}

	/**
	 * Test logic visualizer generates valid Mermaid for a decision tree.
	 */
	public function test_logic_visualizer_decision_tree() {
		$tool   = new WP_MCP_AI_Tool_Financial_Logic_Visualizer();
		$result = $tool->execute(
			array(
				'chain_type'  => 'decision_tree',
				'nodes'       => array(
					array(
						'id'    => 'D1',
						'label' => 'Invest?',
						'type'  => 'decision',
					),
					array(
						'id'    => 'O1',
						'label' => 'Buy Stocks',
						'type'  => 'outcome',
					),
					array(
						'id'    => 'O2',
						'label' => 'Hold Cash',
						'type'  => 'outcome',
					),
				),
				'connections' => array(
					array(
						'from'     => 'D1',
						'to'       => 'O1',
						'label'    => 'Yes',
						'strength' => 'strong',
					),
					array(
						'from'     => 'D1',
						'to'       => 'O2',
						'label'    => 'No',
						'strength' => 'weak',
					),
				),
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'decision_tree', $result['diagram_type'] );

		$mermaid = $result['mermaid_code'];
		$this->assertStringContainsString( 'flowchart', $mermaid );
		// Decision nodes use diamond shape { }.
		$this->assertStringContainsString( 'D1{', $mermaid );
		// Outcome nodes use double brackets [[ ]].
		$this->assertStringContainsString( 'O1[[', $mermaid );
		// Weak arrow uses dotted style.
		$this->assertStringContainsString( '-.->', $mermaid );
	}

	// =========================================================================
	// Financial Report Generator.
	// =========================================================================

	/**
	 * Test report generator slug.
	 */
	public function test_report_generator_slug() {
		$tool = new WP_MCP_AI_Tool_Financial_Report_Generator();
		$this->assertSame( 'financial_report_generator', $tool->get_slug() );
	}

	/**
	 * Test report generator schema has required fields.
	 */
	public function test_report_generator_schema() {
		$tool   = new WP_MCP_AI_Tool_Financial_Report_Generator();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'report_type', $schema['required'] );
		$this->assertContains( 'title', $schema['required'] );
		$this->assertContains( 'data', $schema['required'] );
	}

	/**
	 * Test report generator permission check rejects unauthenticated users.
	 */
	public function test_report_generator_permission_check() {
		wp_set_current_user( 0 );
		$tool   = new WP_MCP_AI_Tool_Financial_Report_Generator();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test report generator produces a portfolio summary with markdown sections.
	 */
	public function test_report_generator_portfolio_summary() {
		$tool   = new WP_MCP_AI_Tool_Financial_Report_Generator();
		$result = $tool->execute(
			array(
				'report_type' => 'portfolio_summary',
				'title'       => 'Q4 Portfolio Review',
				'data'        => array(
					'holdings' => array(
						array(
							'ticker'        => 'AAPL',
							'shares'        => 10,
							'current_price' => 180.00,
							'cost_basis'    => 150.00,
							'asset_class'   => 'equity',
						),
						array(
							'ticker'        => 'BND',
							'shares'        => 50,
							'current_price' => 75.00,
							'cost_basis'    => 78.00,
							'asset_class'   => 'bonds',
						),
					),
				),
				'format'      => 'markdown',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'portfolio_summary', $result['report_type'] );
		$this->assertSame( 'markdown', $result['format'] );

		// Verify markdown content has expected sections.
		$content = $result['content'];
		$this->assertStringContainsString( '# Q4 Portfolio Review', $content );
		$this->assertStringContainsString( '## Executive Summary', $content );
		$this->assertStringContainsString( '## Key Metrics', $content );
		$this->assertStringContainsString( 'AAPL', $content );
	}

	/**
	 * Test report generator produces a market analysis report.
	 */
	public function test_report_generator_market_analysis() {
		$tool   = new WP_MCP_AI_Tool_Financial_Report_Generator();
		$result = $tool->execute(
			array(
				'report_type' => 'market_analysis',
				'title'       => 'Weekly Market Overview',
				'data'        => array(
					'summary' => 'Markets rallied on strong earnings.',
					'indices' => array(
						array(
							'name'       => 'S&P 500',
							'value'      => 5100.50,
							'change'     => 75.30,
							'change_pct' => 1.50,
						),
					),
					'trends'  => array(
						array(
							'name'        => 'Technology',
							'description' => 'AI-driven growth across the sector.',
						),
					),
				),
				'format'      => 'markdown',
			),
			array()
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'market_analysis', $result['report_type'] );

		$content = $result['content'];
		$this->assertStringContainsString( '# Weekly Market Overview', $content );
		$this->assertStringContainsString( 'S&P 500', $content );
		$this->assertStringContainsString( 'Technology', $content );
	}

	// =========================================================================
	// Financial Search.
	// =========================================================================

	/**
	 * Test financial search slug.
	 */
	public function test_financial_search_slug() {
		$tool = new WP_MCP_AI_Tool_Financial_Search();
		$this->assertSame( 'financial_search', $tool->get_slug() );
	}

	/**
	 * Test financial search schema has query in required.
	 */
	public function test_financial_search_schema() {
		$tool   = new WP_MCP_AI_Tool_Financial_Search();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'query', $schema['required'] );
		$this->assertArrayHasKey( 'query', $schema['properties'] );
		$this->assertArrayHasKey( 'sources', $schema['properties'] );
		$this->assertArrayHasKey( 'search_type', $schema['properties'] );
	}

	/**
	 * Test financial search permission check rejects unauthenticated users.
	 */
	public function test_financial_search_permission_check() {
		wp_set_current_user( 0 );
		$tool   = new WP_MCP_AI_Tool_Financial_Search();
		$result = $tool->execute( array(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}
}
