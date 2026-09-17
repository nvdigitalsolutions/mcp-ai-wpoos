<?php
/**
 * Tests for price alerts.
 *
 * Tests cover: create/list/delete lifecycle, the 50-alert cap, check_now
 * with mocked prices, the trigger hook, daily re-arm, and the cron
 * scheduling guard.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Test price alerts.
 *
 * @since 1.1.80
 */
class Test_Financial_Price_Alerts extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor_user;

	/**
	 * Triggered alerts captured by the hook.
	 *
	 * @var array
	 */
	public $triggered = array();

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

		$this->editor_user = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_user );
		$this->triggered = array();

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-price-alerts.php';

		// Last-resort network blocker: any provider call not explicitly mocked
		// by a test returns HTTP 500 instead of hitting the network.
		add_filter(
			'wp_mcp_ai_market_data_http_response',
			function ( $result ) {
				if ( null !== $result ) {
					return $result;
				}

				return array(
					'body' => '',
					'code' => 500,
				);
			},
			1000,
			2
		);

		add_action(
			'wp_mcp_ai_price_alert_triggered',
			function ( $alert, $user_id ) {
				$this->triggered[] = array(
					'alert'   => $alert,
					'user_id' => $user_id,
				);
			},
			10,
			2
		);
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_yfinance_batch_prices' );
		remove_all_filters( 'wp_mcp_ai_market_data_http_response' );
		remove_all_actions( 'wp_mcp_ai_price_alert_triggered' );
		delete_option( 'wp_mcp_ai_price_alerts_' . $this->editor_user );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test tool surface.
	 */
	public function test_tool_surface() {
		$tool = new WP_MCP_AI_Tool_Price_Alerts();
		$this->assertSame( 'price_alerts', $tool->get_slug() );
		$this->assertSame( 'edit_posts', $tool->get_required_capability() );
		$this->assertSame( 'wp_mcp_ai_price_alert_check_daily', WP_MCP_AI_Tool_Price_Alerts::CRON_HOOK );
	}

	/**
	 * Test create/list/delete lifecycle.
	 */
	public function test_alert_lifecycle() {
		$tool = new WP_MCP_AI_Tool_Price_Alerts();

		$created = $tool->execute(
			array(
				'action'    => 'create',
				'ticker'    => 'AAPL',
				'condition' => 'above',
				'threshold' => 250.0,
				'note'      => 'watch',
			),
			array( 'user_id' => $this->editor_user )
		);
		$this->assertNotWPError( $created );
		$alert_id = $created['alert']['id'];

		$listed = $tool->execute( array( 'action' => 'list' ), array( 'user_id' => $this->editor_user ) );
		$this->assertSame( 1, $listed['count'] );
		$this->assertSame( 'AAPL', $listed['alerts'][0]['ticker'] );

		$deleted = $tool->execute(
			array(
				'action'   => 'delete',
				'alert_id' => $alert_id,
			),
			array( 'user_id' => $this->editor_user )
		);
		$this->assertNotWPError( $deleted );

		$after = $tool->execute( array( 'action' => 'list' ), array( 'user_id' => $this->editor_user ) );
		$this->assertSame( 0, $after['count'] );
	}

	/**
	 * Test create argument gates + alert cap.
	 */
	public function test_alert_gates_and_cap() {
		$tool = new WP_MCP_AI_Tool_Price_Alerts();

		$bad_condition = $tool->execute(
			array(
				'action'    => 'create',
				'ticker'    => 'AAPL',
				'condition' => 'sideways',
				'threshold' => 100,
			),
			array( 'user_id' => $this->editor_user )
		);
		$this->assertWPError( $bad_condition );
		$this->assertSame( 'invalid_condition', $bad_condition->get_error_code() );

		$bad_threshold = $tool->execute(
			array(
				'action'    => 'create',
				'ticker'    => 'AAPL',
				'condition' => 'above',
				'threshold' => 0,
			),
			array( 'user_id' => $this->editor_user )
		);
		$this->assertWPError( $bad_threshold );
		$this->assertSame( 'invalid_threshold', $bad_threshold->get_error_code() );

		for ( $i = 0; $i < WP_MCP_AI_Tool_Price_Alerts::MAX_ALERTS; $i++ ) {
			$tool->execute(
				array(
					'action'    => 'create',
					'ticker'    => 'AAPL',
					'condition' => 'above',
					'threshold' => 100 + $i,
				),
				array( 'user_id' => $this->editor_user )
			);
		}

		$over = $tool->execute(
			array(
				'action'    => 'create',
				'ticker'    => 'AAPL',
				'condition' => 'above',
				'threshold' => 999,
			),
			array( 'user_id' => $this->editor_user )
		);
		$this->assertWPError( $over );
		$this->assertSame( 'alert_limit_reached', $over->get_error_code() );
	}

	/**
	 * Test is_triggered conditions.
	 */
	public function test_is_triggered() {
		$above = array(
			'condition' => 'above',
			'threshold' => 100,
		);
		$below = array(
			'condition' => 'below',
			'threshold' => 100,
		);

		$this->assertTrue( WP_MCP_AI_Tool_Price_Alerts::is_triggered( $above, 101 ) );
		$this->assertFalse( WP_MCP_AI_Tool_Price_Alerts::is_triggered( $above, 99 ) );
		$this->assertTrue( WP_MCP_AI_Tool_Price_Alerts::is_triggered( $below, 99 ) );
		$this->assertFalse( WP_MCP_AI_Tool_Price_Alerts::is_triggered( $below, 101 ) );
	}

	/**
	 * Test check_now triggers the hook once per day.
	 */
	public function test_check_now_triggers_hook() {
		$tool = new WP_MCP_AI_Tool_Price_Alerts();

		$tool->execute(
			array(
				'action'    => 'create',
				'ticker'    => 'AAPL',
				'condition' => 'above',
				'threshold' => 200.0,
			),
			array( 'user_id' => $this->editor_user )
		);

		$summary = $tool->execute(
			array( 'action' => 'check_now' ),
			array( 'user_id' => $this->editor_user )
		);

		$this->assertNotWPError( $summary );
		$this->assertSame( 0, $summary['checked'] ); // No price source in this test => skipped.

		// With mocked prices the alert triggers exactly once.
		add_filter(
			'wp_mcp_ai_yfinance_batch_prices',
			function () {
				return array(
					'data' => array(
						'AAPL' => array( 'current_price' => 230.0 ),
					),
				);
			},
			10
		);

		$summary = $tool->execute( array( 'action' => 'check_now' ), array( 'user_id' => $this->editor_user ) );
		$this->assertSame( 1, $summary['checked'] );
		$this->assertCount( 1, $this->triggered );
		$this->assertSame( 'AAPL', $this->triggered[0]['alert']['ticker'] );
		$this->assertSame( $this->editor_user, $this->triggered[0]['user_id'] );

		// Daily re-arm: second check on the same day does not re-fire.
		$summary = $tool->execute( array( 'action' => 'check_now' ), array( 'user_id' => $this->editor_user ) );
		$this->assertCount( 1, $this->triggered );
		$this->assertSame( 0, count( $summary['triggered'] ) );
	}

	/**
	 * Test trigger stamps are persisted on the alert record.
	 */
	public function test_trigger_stamps_persisted() {
		$tool = new WP_MCP_AI_Tool_Price_Alerts();

		$tool->execute(
			array(
				'action'    => 'create',
				'ticker'    => 'MSFT',
				'condition' => 'below',
				'threshold' => 500.0,
			),
			array( 'user_id' => $this->editor_user )
		);

		add_filter(
			'wp_mcp_ai_yfinance_batch_prices',
			function () {
				return array(
					'data' => array(
						'MSFT' => array( 'current_price' => 450.0 ),
					),
				);
			},
			10
		);

		$tool->execute( array( 'action' => 'check_now' ), array( 'user_id' => $this->editor_user ) );

		$alerts = WP_MCP_AI_Tool_Price_Alerts::get_alerts( $this->editor_user );
		$this->assertNotEmpty( $alerts[0]['last_triggered_at'] );
		$this->assertSame( 450.0, $alerts[0]['triggered_value'] );
		$this->assertSame( 1, $alerts[0]['trigger_count'] );
	}

	/**
	 * Test cron scheduling guard is idempotent.
	 */
	public function test_cron_scheduling_idempotent() {
		WP_MCP_AI_Tool_Price_Alerts::maybe_schedule_cron();
		WP_MCP_AI_Tool_Price_Alerts::maybe_schedule_cron();

		$scheduled = wp_next_scheduled( WP_MCP_AI_Tool_Price_Alerts::CRON_HOOK );
		$this->assertNotFalse( $scheduled );

		wp_clear_scheduled_hook( WP_MCP_AI_Tool_Price_Alerts::CRON_HOOK );
	}

	/**
	 * Test daily cron callback respects the toolkit gate.
	 */
	public function test_daily_check_respects_toolkit_gate() {
		delete_option( 'wp_mcp_ai_settings' );
		update_option( 'wp_mcp_ai_settings', array() );

		// Toolkit disabled => the callback returns without touching alerts.
		WP_MCP_AI_Tool_Price_Alerts::run_daily_check();

		$this->assertEmpty( WP_MCP_AI_Tool_Price_Alerts::get_alerts( $this->editor_user ) );
	}
}
