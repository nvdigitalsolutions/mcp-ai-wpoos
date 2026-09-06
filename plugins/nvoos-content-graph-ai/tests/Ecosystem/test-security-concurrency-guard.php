<?php
/**
 * Concurrency guard port tests (Wave D4g).
 *
 * Characterization suite for `ConcurrencyGuard`,
 * `ConcurrencyGuardSubscriber`, and the `ConcurrencyLimitReached`
 * exception. Assertions mirror the base plugin's concurrency guard:
 * constants, slot table creation, DB-path acquire/release accounting,
 * the counter cap behaviour, filterable limits, usage shape, expired-slot
 * cleanup, the subscriber lifecycle via a testable subclass, and the
 * exception's WP_Error envelope. The slots table is created with real DDL
 * — the WP framework's TEMPORARY-table rewrite is suspended in setUp and
 * restored in tearDown.
 *
 * Note (byte-identical base behaviour): the InnoDB acquire path caps the
 * counter at the limit instead of exceeding it, so the over-capacity
 * rejection branch only triggers on the persistent object-cache path
 * (not reachable in tests). The rejection branch is covered by the
 * exception class directly.
 *
 * @package NvoosContentGraphAi\Tests
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tests;

use NvoosContentGraphAi\Security\ConcurrencyGuard;
use NvoosContentGraphAi\Security\ConcurrencyGuardSubscriber;
use NvoosContentGraphAi\Security\Exceptions\ConcurrencyLimitReached;

/**
 * Testable subscriber with an injected mock tool.
 */
class Testable_Concurrency_Subscriber extends ConcurrencyGuardSubscriber {

	/**
	 * Mock tool carrying image-generation capability flags.
	 *
	 * @var object
	 */
	private static $mock_tool;

	/**
	 * Set the tool returned by resolve_tool().
	 *
	 * @param object|null $tool Mock tool instance.
	 * @return void
	 */
	public static function set_mock_tool( $tool ): void {
		self::$mock_tool = $tool;
	}

	/**
	 * Always treat the mock tool as capability-flagged.
	 *
	 * @param object|null $tool Tool instance.
	 * @return bool
	 */
	protected static function is_capability_flag_tool( $tool ) {
		return null !== $tool && is_object( $tool );
	}

	/**
	 * Return the injected mock tool.
	 *
	 * @param string $tool_slug Tool identifier.
	 * @return object|null
	 */
	protected static function resolve_tool( $tool_slug ) {
		return self::$mock_tool;
	}
}

/**
 * @group security
 */
class Test_Concurrency_Guard extends \WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		// Allow real DDL on the custom slots table.
		\remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		\remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		// Recreate the table explicitly — the guard's per-request
		// `$ensured` static persists across tests while tearDown drops
		// the table.
		ConcurrencyGuard::create_slots_table();

		\remove_all_actions( 'wp_mcp_ai_before_tool_execution' );
		\remove_all_actions( 'wp_mcp_ai_after_tool_execution' );
	}

	public function tearDown(): void {
		global $wpdb;

		// Drop the real table BEFORE re-adding the framework temp-table filter.
		$table = $wpdb->prefix . ConcurrencyGuard::SLOTS_TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test teardown for custom table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		\add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		\add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		\remove_all_filters( 'wp_mcp_ai_concurrency_limits' );
		\remove_all_actions( 'wp_mcp_ai_before_tool_execution' );
		\remove_all_actions( 'wp_mcp_ai_after_tool_execution' );

		parent::tearDown();
	}

	/**
	 * Read a slot counter straight from the table.
	 *
	 * @param string $key Slot key.
	 * @return int
	 */
	private function db_count( string $key ): int {
		global $wpdb;
		$table = $wpdb->prefix . ConcurrencyGuard::SLOTS_TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT current_count FROM {$table} WHERE slot_key = %s", $key ) );
	}

	public function test_constants_match_base(): void {
		$this->assertSame( 'wp_mcp_ai_concurrency_', ConcurrencyGuard::TRANSIENT_PREFIX );
		$this->assertSame( 'wp_mcp_ai_concurrency', ConcurrencyGuard::CACHE_GROUP );
		$this->assertSame( 'mcp_ai_concurrency_slots', ConcurrencyGuard::SLOTS_TABLE );
		$this->assertSame( 600, ConcurrencyGuard::LOCK_TTL );
		$this->assertSame( 3, ConcurrencyGuard::LIMITS['image_generation'] );
		$this->assertSame( 1, ConcurrencyGuard::LIMITS['video_generation'] );
		$this->assertSame( 5, ConcurrencyGuard::LIMITS['default'] );
	}

	public function test_acquire_and_release_roundtrip(): void {
		$key = ConcurrencyGuard::TRANSIENT_PREFIX . 'image_generation';

		$this->assertTrue( ConcurrencyGuard::acquire( 'image_generation' ) );
		$this->assertSame( 1, $this->db_count( $key ) );

		$this->assertTrue( ConcurrencyGuard::acquire( 'image_generation' ) );
		$this->assertSame( 2, $this->db_count( $key ) );

		ConcurrencyGuard::release( 'image_generation' );
		$this->assertSame( 1, $this->db_count( $key ) );

		ConcurrencyGuard::release( 'image_generation' );
		$this->assertSame( 0, $this->db_count( $key ) );

		// Releasing an empty slot stays at zero.
		ConcurrencyGuard::release( 'image_generation' );
		$this->assertSame( 0, $this->db_count( $key ) );
	}

	public function test_unknown_operation_uses_default_limit(): void {
		$this->assertSame( 5, ConcurrencyGuard::get_limit( 'unknown_operation' ) );
	}

	public function test_limit_filter_is_honoured(): void {
		\add_filter( 'wp_mcp_ai_concurrency_limits', static function ( $limits ) {
			$limits['image_generation'] = 1;
			return $limits;
		} );

		$this->assertSame( 1, ConcurrencyGuard::get_limit( 'image_generation' ) );
	}

	public function test_counter_caps_at_limit_without_exceeding(): void {
		\add_filter( 'wp_mcp_ai_concurrency_limits', static function ( $limits ) {
			$limits['image_generation'] = 2;
			return $limits;
		} );

		$key = ConcurrencyGuard::TRANSIENT_PREFIX . 'image_generation';

		$this->assertTrue( ConcurrencyGuard::acquire( 'image_generation' ) );
		$this->assertTrue( ConcurrencyGuard::acquire( 'image_generation' ) );
		$this->assertSame( 2, $this->db_count( $key ) );

		// Byte-identical base behaviour: further acquires cap the counter
		// rather than exceeding it.
		$this->assertTrue( ConcurrencyGuard::acquire( 'image_generation' ) );
		$this->assertSame( 2, $this->db_count( $key ) );
	}

	public function test_get_usage_shape(): void {
		ConcurrencyGuard::acquire( 'music_generation' );

		$usage = ConcurrencyGuard::get_usage();

		$this->assertArrayHasKey( 'image_generation', $usage );
		$this->assertArrayHasKey( 'music_generation', $usage );
		$this->assertArrayHasKey( 'default', $usage );

		$this->assertSame( 1, $usage['music_generation']['current'] );
		$this->assertSame( 2, $usage['music_generation']['max'] );
		$this->assertSame( 0, $usage['image_generation']['current'] );
		$this->assertSame( 3, $usage['image_generation']['max'] );
	}

	public function test_cleanup_expired_slots_removes_stale_rows(): void {
		global $wpdb;
		$table = $wpdb->prefix . ConcurrencyGuard::SLOTS_TABLE_NAME;

		ConcurrencyGuard::acquire( 'deep_research' );

		// Age one slot beyond its expiry.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE slot_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ConcurrencyGuard::TRANSIENT_PREFIX . 'deep_research'
			)
		);

		ConcurrencyGuard::cleanup_expired_slots();

		$this->assertSame( 0, $this->db_count( ConcurrencyGuard::TRANSIENT_PREFIX . 'deep_research' ) );
	}

	public function test_subscriber_acquires_and_releases_slots(): void {
		$mock = new class() {
			public function get_capability_flags() {
				return array( 'image-generation' );
			}
		};
		Testable_Concurrency_Subscriber::set_mock_tool( $mock );

		Testable_Concurrency_Subscriber::on_before( 'tool_a', array(), array() );
		$this->assertSame( 1, $this->db_count( ConcurrencyGuard::TRANSIENT_PREFIX . 'image_generation' ) );

		// Release even with a WP_Error result.
		Testable_Concurrency_Subscriber::on_after( 'tool_a', array(), array(), new \WP_Error( 'boom' ) );
		$this->assertSame( 0, $this->db_count( ConcurrencyGuard::TRANSIENT_PREFIX . 'image_generation' ) );

		Testable_Concurrency_Subscriber::set_mock_tool( null );
	}

	public function test_subscriber_ignores_non_relevant_flags(): void {
		$mock = new class() {
			public function get_capability_flags() {
				return array( 'text-generation' );
			}
		};
		Testable_Concurrency_Subscriber::set_mock_tool( $mock );

		Testable_Concurrency_Subscriber::on_before( 'tool_b', array(), array() );
		Testable_Concurrency_Subscriber::on_after( 'tool_b', array(), array(), array() );

		$this->assertSame( 0, $this->db_count( ConcurrencyGuard::TRANSIENT_PREFIX . 'image_generation' ) );

		Testable_Concurrency_Subscriber::set_mock_tool( null );
	}

	public function test_exception_wp_error_envelope(): void {
		$exception = new ConcurrencyLimitReached( 'video_generation', 'Maximum 1 concurrent video_generation operations reached.' );

		$this->assertSame( 'video_generation', $exception->get_operation_type() );
		$this->assertSame( 429, $exception->getCode() );

		$error = $exception->to_wp_error();
		$this->assertWPError( $error );
		$this->assertSame( 'concurrency_limit', $error->get_error_code() );
		$data = $error->get_error_data();
		$this->assertSame( 429, $data['status'] );
		$this->assertSame( 'video_generation', $data['operation_type'] );
		$this->assertSame( 30, $data['retry_after'] );
	}
}
