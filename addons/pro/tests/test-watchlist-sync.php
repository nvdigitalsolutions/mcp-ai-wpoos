<?php
/**
 * Tests for the Watchlist Sync tool (OpenStock parity, proposal 051).
 *
 * Covers: list/add/remove with unique-symbol constraint, user scoping,
 * capability enforcement (self and cross-user), and bulk quotes routed
 * through the yfinance service seam.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Test watchlist sync tool.
 *
 * @since 1.1.90
 */
class Test_Watchlist_Sync extends WP_UnitTestCase {

	/**
	 * Author user ID (has edit_posts).
	 *
	 * @var int
	 */
	private $author_id;

	/**
	 * Subscriber user ID (lacks edit_posts).
	 *
	 * @var int
	 */
	private $subscriber_id;

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Watchlist_Sync
	 */
	private $tool;

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
				'enable_yfinance_service'          => true,
			)
		);

		$this->author_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		require_once WP_MCP_AI_PRO_PATH . 'includes/tools/financial-planning/class-wp-mcp-ai-tool-watchlist-sync.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-yfinance-service.php';

		$this->tool = new WP_MCP_AI_Tool_Watchlist_Sync();
	}

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_yfinance_batch_prices' );

		if ( $this->author_id ) {
			delete_user_meta( $this->author_id, WP_MCP_AI_Tool_Watchlist_Sync::META_KEY );
		}
		if ( $this->subscriber_id ) {
			delete_user_meta( $this->subscriber_id, WP_MCP_AI_Tool_Watchlist_Sync::META_KEY );
		}

		delete_option( 'wp_mcp_ai_settings' );

		parent::tearDown();
	}

	/**
	 * Convenience context wrapper for the tool.
	 *
	 * @param int $user_id User ID.
	 * @return array Context.
	 */
	private function context_for( $user_id ) {
		return array( 'user_id' => $user_id );
	}

	/**
	 * Listing an empty watchlist returns an empty symbol set.
	 */
	public function test_list_empty_watchlist() {
		$result = $this->tool->execute(
			array( 'action' => 'list' ),
			$this->context_for( $this->author_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( array(), $result['symbols'] );
	}

	/**
	 * Add de-duplicates against existing symbols (unique-symbol constraint).
	 */
	public function test_add_deduplicates_symbols() {
		$tool = $this->tool;

		$tool->execute(
			array(
				'action' => 'add',
				'symbol' => 'aapl',
			),
			$this->context_for( $this->author_id )
		);
		$second = $tool->execute(
			array(
				'action'  => 'add',
				'symbols' => array( 'AAPL', 'MSFT', 'aapl' ),
			),
			$this->context_for( $this->author_id )
		);

		$this->assertNotWPError( $second );
		$this->assertTrue( $second['success'] );
		$this->assertSame( 2, $second['count'] );
		$this->assertSame( 1, $second['was_already'] );

		$list = $tool->execute( array( 'action' => 'list' ), $this->context_for( $this->author_id ) );
		$this->assertSame( array( 'AAPL', 'MSFT' ), $list['symbols'] );
	}

	/**
	 * Remove deletes only the requested symbols.
	 */
	public function test_remove_symbols() {
		$tool = $this->tool;

		$tool->execute(
			array(
				'action'  => 'add',
				'symbols' => array( 'AAPL', 'MSFT', 'GOOGL' ),
			),
			$this->context_for( $this->author_id )
		);
		$result = $tool->execute(
			array(
				'action'  => 'remove',
				'symbols' => array( 'MSFT' ),
			),
			$this->context_for( $this->author_id )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( 1, $result['removed_count'] );

		$list = $tool->execute( array( 'action' => 'list' ), $this->context_for( $this->author_id ) );
		$this->assertSame( array( 'AAPL', 'GOOGL' ), $list['symbols'] );
	}

	/**
	 * A user without edit_posts cannot manage a watchlist.
	 */
	public function test_subscriber_is_forbidden() {
		$result = $this->tool->execute(
			array( 'action' => 'list' ),
			$this->context_for( $this->subscriber_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Targeting another user requires edit_users capability.
	 */
	public function test_cross_user_requires_edit_users() {
		$result = $this->tool->execute(
			array(
				'action'  => 'add',
				'symbol'  => 'AAPL',
				'user_id' => $this->subscriber_id,
			),
			$this->context_for( $this->author_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Add rejects invalid symbols outright.
	 */
	public function test_add_requires_valid_symbols() {
		$result = $this->tool->execute(
			array(
				'action'  => 'add',
				'symbols' => array( 'BAD SYMBOL!', '<script>' ),
			),
			$this->context_for( $this->author_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'missing_symbols', $result->get_error_code() );
	}

	/**
	 * Bulk quotes route through the yfinance batch seam.
	 */
	public function test_bulk_quote_uses_yfinance_batch() {
		$tool = $this->tool;

		$tool->execute(
			array(
				'action'  => 'add',
				'symbols' => array( 'AAPL', 'MSFT' ),
			),
			$this->context_for( $this->author_id )
		);

		add_filter(
			'wp_mcp_ai_yfinance_batch_prices',
			function ( $result, $params ) {
				$map = array();
				foreach ( $params['tickers'] as $ticker ) {
					$map[ $ticker ] = array( 'current_price' => 100.0 + strlen( $ticker ) );
				}

				return array( 'data' => $map );
			},
			10,
			2
		);

		$result = $tool->execute(
			array( 'action' => 'bulk_quote' ),
			$this->context_for( $this->author_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( 'AAPL', $result['quotes'][0]['symbol'] );
		$this->assertSame( 104.0, $result['quotes'][0]['current_price'] );
	}

	/**
	 * Bulk quotes on an empty watchlist return an empty set, not an error.
	 */
	public function test_bulk_quote_empty_watchlist() {
		$result = $this->tool->execute(
			array( 'action' => 'bulk_quote' ),
			$this->context_for( $this->author_id )
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['count'] );
	}
}
