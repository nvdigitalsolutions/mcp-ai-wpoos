<?php
/**
 * Tests for the portfolio transaction ledger.
 *
 * Tests cover: mcp_ai_fin_txn CPT registration, add/list/remove actions,
 * position summary math (average cost, realized/unrealized P&L), and the
 * permission gates.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Test portfolio transactions.
 *
 * @since 1.1.80
 */
class Test_Financial_Portfolio_Transactions extends WP_UnitTestCase {

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

		require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-financial-transaction-cpt.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-portfolio-transaction-log.php';

		WP_MCP_AI_Financial_Transaction_CPT::register_post_type();

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
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_yfinance_batch_prices' );
		remove_all_filters( 'wp_mcp_ai_market_data_http_response' );
		delete_option( 'wp_mcp_ai_settings' );
		parent::tearDown();
	}

	/**
	 * Test CPT registration.
	 */
	public function test_cpt_registered() {
		$this->assertTrue( post_type_exists( 'mcp_ai_fin_txn' ) );
		$this->assertSame( 'mcp_ai_fin_txn', WP_MCP_AI_Financial_Transaction_CPT::POST_TYPE );
	}

	/**
	 * Test tool surface.
	 */
	public function test_tool_surface() {
		$tool = new WP_MCP_AI_Tool_Portfolio_Transaction_Log();
		$this->assertSame( 'portfolio_transaction_log', $tool->get_slug() );
		$this->assertSame( 'edit_posts', $tool->get_required_capability() );

		$schema = $tool->get_parameters_schema();
		$this->assertSame( array( 'add', 'list', 'remove', 'position_summary' ), $schema['properties']['action']['enum'] );
	}

	/**
	 * Test add action persists meta.
	 */
	public function test_add_transaction() {
		$tool   = new WP_MCP_AI_Tool_Portfolio_Transaction_Log();
		$result = $tool->execute(
			array(
				'action'      => 'add',
				'ticker'      => 'AAPL',
				'side'        => 'buy',
				'quantity'    => 10,
				'price'       => 200.0,
				'fee'         => 1.0,
				'executed_at' => '2026-09-10',
				'currency'    => 'USD',
			),
			array( 'user_id' => $this->editor_user )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );

		$txn_id = $result['transaction_id'];
		$this->assertSame( 'AAPL', get_post_meta( $txn_id, WP_MCP_AI_Financial_Transaction_CPT::META_TICKER, true ) );
		$this->assertSame( 'buy', get_post_meta( $txn_id, WP_MCP_AI_Financial_Transaction_CPT::META_SIDE, true ) );
		$this->assertSame( 10.0, (float) get_post_meta( $txn_id, WP_MCP_AI_Financial_Transaction_CPT::META_QUANTITY, true ) );
	}

	/**
	 * Test add action argument gates.
	 */
	public function test_add_transaction_gates() {
		$tool = new WP_MCP_AI_Tool_Portfolio_Transaction_Log();

		$missing = $tool->execute(
			array(
				'action'   => 'add',
				'side'     => 'buy',
				'quantity' => 1,
				'price'    => 1,
			),
			array( 'user_id' => $this->editor_user )
		);
		$this->assertWPError( $missing );
		$this->assertSame( 'missing_ticker', $missing->get_error_code() );

		$bad_side = $tool->execute(
			array(
				'action'   => 'add',
				'ticker'   => 'AAPL',
				'side'     => 'hold',
				'quantity' => 1,
				'price'    => 1,
			),
			array( 'user_id' => $this->editor_user )
		);
		$this->assertWPError( $bad_side );
		$this->assertSame( 'invalid_side', $bad_side->get_error_code() );

		$bad_qty = $tool->execute(
			array(
				'action'   => 'add',
				'ticker'   => 'AAPL',
				'side'     => 'buy',
				'quantity' => 0,
				'price'    => 1,
			),
			array( 'user_id' => $this->editor_user )
		);
		$this->assertWPError( $bad_qty );
		$this->assertSame( 'invalid_quantity', $bad_qty->get_error_code() );
	}

	/**
	 * Test list action scopes to the current user.
	 */
	public function test_list_transactions_scoped() {
		$tool = new WP_MCP_AI_Tool_Portfolio_Transaction_Log();

		$tool->execute(
			array(
				'action'   => 'add',
				'ticker'   => 'AAPL',
				'side'     => 'buy',
				'quantity' => 5,
				'price'    => 100,
			),
			array( 'user_id' => $this->editor_user )
		);

		// A second user's transaction must not appear in the editor's list.
		$other = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $other );
		$tool->execute(
			array(
				'action'   => 'add',
				'ticker'   => 'MSFT',
				'side'     => 'buy',
				'quantity' => 5,
				'price'    => 100,
			),
			array( 'user_id' => $other )
		);

		wp_set_current_user( $this->editor_user );
		$result = $tool->execute( array( 'action' => 'list' ), array( 'user_id' => $this->editor_user ) );

		$this->assertNotWPError( $result );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'AAPL', $result['transactions'][0]['ticker'] );
	}

	/**
	 * Test remove action + ownership gate.
	 */
	public function test_remove_transaction_ownership() {
		$tool = new WP_MCP_AI_Tool_Portfolio_Transaction_Log();

		$added  = $tool->execute(
			array(
				'action'   => 'add',
				'ticker'   => 'AAPL',
				'side'     => 'buy',
				'quantity' => 5,
				'price'    => 100,
			),
			array( 'user_id' => $this->editor_user )
		);
		$txn_id = $added['transaction_id'];

		$other = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $other );

		$forbidden = $tool->execute(
			array(
				'action'         => 'remove',
				'transaction_id' => $txn_id,
			),
			array( 'user_id' => $other )
		);
		$this->assertWPError( $forbidden );
		$this->assertSame( 'wp_mcp_ai_forbidden', $forbidden->get_error_code() );

		wp_set_current_user( $this->editor_user );
		$removed = $tool->execute(
			array(
				'action'         => 'remove',
				'transaction_id' => $txn_id,
			),
			array( 'user_id' => $this->editor_user )
		);
		$this->assertNotWPError( $removed );
		$this->assertNull( get_post( $txn_id ) );
	}

	/**
	 * Test position summary math: average cost + realized P&L.
	 */
	public function test_position_summary_math() {
		$tool = new WP_MCP_AI_Tool_Portfolio_Transaction_Log();

		// Buy 10 @ 200 + 1 fee => cost 2001, avg 200.10.
		$tool->execute(
			array(
				'action'      => 'add',
				'ticker'      => 'AAPL',
				'side'        => 'buy',
				'quantity'    => 10,
				'price'       => 200,
				'fee'         => 1,
				'executed_at' => '2026-09-01',
			),
			array( 'user_id' => $this->editor_user )
		);
		// Sell 4 @ 210 - 1 fee => proceeds 839, cost basis 800.40 => realized 38.60.
		$tool->execute(
			array(
				'action'      => 'add',
				'ticker'      => 'AAPL',
				'side'        => 'sell',
				'quantity'    => 4,
				'price'       => 210,
				'fee'         => 1,
				'executed_at' => '2026-09-02',
			),
			array( 'user_id' => $this->editor_user )
		);

		// Mock current price at 220 for unrealized P&L.
		add_filter(
			'wp_mcp_ai_yfinance_batch_prices',
			function () {
				return array(
					'data' => array(
						'AAPL' => array(
							'current_price' => 220.0,
							'source'        => 'test',
						),
					),
				);
			},
			10
		);

		update_option(
			'wp_mcp_ai_settings',
			array(
				'enable_financial_planner_toolkit' => true,
				'enable_yfinance_service'          => true,
			)
		);

		$result = $tool->execute( array( 'action' => 'position_summary' ), array( 'user_id' => $this->editor_user ) );

		$this->assertNotWPError( $result );
		$this->assertCount( 1, $result['positions'] );

		$position = $result['positions'][0];
		$this->assertSame( 'AAPL', $position['ticker'] );
		$this->assertSame( 6.0, $position['quantity'] );
		$this->assertEqualsWithDelta( 200.10, $position['average_cost'], 0.001 );
		$this->assertEqualsWithDelta( 38.60, $position['realized_pnl'], 0.001 );
		$this->assertEqualsWithDelta( 119.40, $position['unrealized_pnl'], 0.001 ); // 6 * (220 - 200.10).
		$this->assertEqualsWithDelta( 158.0, $result['totals']['realized_pnl'] + $result['totals']['unrealized_pnl'], 0.001 );
	}

	/**
	 * Test position summary degrades without a price source.
	 */
	public function test_position_summary_no_price_source() {
		$tool = new WP_MCP_AI_Tool_Portfolio_Transaction_Log();

		$tool->execute(
			array(
				'action'   => 'add',
				'ticker'   => 'AAPL',
				'side'     => 'buy',
				'quantity' => 10,
				'price'    => 200,
			),
			array( 'user_id' => $this->editor_user )
		);

		// yfinance disabled => no price source.
		update_option(
			'wp_mcp_ai_settings',
			array( 'enable_financial_planner_toolkit' => true )
		);

		$result = $tool->execute( array( 'action' => 'position_summary' ), array( 'user_id' => $this->editor_user ) );

		$this->assertNotWPError( $result );
		$this->assertNull( $result['positions'][0]['unrealized_pnl'] );
		$this->assertSame( 'unavailable', $result['positions'][0]['price_source'] );
	}
}
