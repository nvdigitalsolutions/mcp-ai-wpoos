<?php
/**
 * Test_Toolkit_MCP_Audit_Log
 *
 * Phase 4 — cross-mount audit trail.
 *
 * Covers:
 *   1. record() stores an entry.
 *   2. Ring buffer trims to MAX_ENTRIES (filter override).
 *   3. get_entries() returns most-recent-first.
 *   4. get_entries() respects consumer filter.
 *   5. get_summary() groups by consumer→source pair.
 *   6. clear() empties the log.
 *   7. wp_mcp_ai_toolkit_mcp_cross_mount_read action triggers on_cross_mount_read().
 *   8. wp_mcp_ai_toolkit_mcp_audit_recorded action fires after record().
 *
 * @package WP_MCP_AI_Pro
 */

require_once dirname( __DIR__ ) . '/includes/mcp-servers/class-wp-mcp-ai-toolkit-mcp-audit-log.php';

/**
 * PHPUnit test case for the cross-mount audit log.
 */
class Test_Toolkit_MCP_Audit_Log extends WP_UnitTestCase {

	/**
	 * Fresh log instance (singleton reset between tests).
	 *
	 * @var WP_MCP_AI_Toolkit_MCP_Audit_Log
	 */
	private $log;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset singleton so each test starts clean.
		$ref = new ReflectionProperty( WP_MCP_AI_Toolkit_MCP_Audit_Log::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );

		$this->log = WP_MCP_AI_Toolkit_MCP_Audit_Log::get_instance();
		$this->log->clear();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		$this->log->clear();
		parent::tearDown();
	}

	// -----------------------------------------------------------------------
	// 1. record() stores an entry.
	// -----------------------------------------------------------------------

	/** Test record stores entry.
	 */
	public function test_record_stores_entry() {
		$this->log->record(
			array(
				'consumer' => 'arch-design',
				'source'   => 'healthcare',
				'entity'   => 'health_record',
				'uri'      => 'nvoos://arch-design/_mounted/healthcare/health_record',
				'method'   => 'resources/read',
				'user_id'  => 1,
			)
		);

		$entries = $this->log->get_entries( 10 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'arch-design', $entries[0]['consumer'] );
		$this->assertSame( 'healthcare', $entries[0]['source'] );
		$this->assertSame( 'resources/read', $entries[0]['method'] );
	}

	// -----------------------------------------------------------------------
	// 2. Ring buffer trims when over the max.
	// -----------------------------------------------------------------------

	/** Test ring buffer respects max.
	 */
	public function test_ring_buffer_respects_max() {
		// Override max to 3 for this test.
		add_filter(
			'wp_mcp_ai_toolkit_mcp_audit_max_entries',
			static function () {
				return 3;
			}
		);

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->log->record(
				array(
					'consumer' => 'consumer',
					'source'   => 'source',
					'entity'   => 'entity_' . $i,
					'method'   => 'resources/read',
				)
			);
		}

		$entries = $this->log->get_entries( 10 );
		$this->assertCount( 3, $entries );

		remove_all_filters( 'wp_mcp_ai_toolkit_mcp_audit_max_entries' );
	}

	// -----------------------------------------------------------------------
	// 3. get_entries() returns most-recent-first.
	// -----------------------------------------------------------------------

	/** Test get entries most recent first.
	 */
	public function test_get_entries_most_recent_first() {
		$now = time();
		foreach ( array( 100, 200, 300 ) as $offset ) {
			$this->log->record(
				array(
					'consumer' => 'c',
					'source'   => 's',
					'entity'   => 'e',
					'method'   => 'resources/read',
					'ts'       => $now + $offset,
				)
			);
		}

		$entries = $this->log->get_entries( 3 );
		$this->assertSame( $now + 300, $entries[0]['ts'] );
		$this->assertSame( $now + 100, $entries[2]['ts'] );
	}

	// -----------------------------------------------------------------------
	// 4. get_entries() respects consumer filter.
	// -----------------------------------------------------------------------

	/** Test get entries consumer filter.
	 */
	public function test_get_entries_consumer_filter() {
		$this->log->record(
			array(
				'consumer' => 'a',
				'source'   => 's',
				'entity'   => 'e',
				'method'   => 'resources/read',
			)
		);
		$this->log->record(
			array(
				'consumer' => 'b',
				'source'   => 's',
				'entity'   => 'e',
				'method'   => 'resources/read',
			)
		);
		$this->log->record(
			array(
				'consumer' => 'a',
				'source'   => 's',
				'entity'   => 'e',
				'method'   => 'prompts/get',
			)
		);

		$entries = $this->log->get_entries( 10, 'a', 'consumer' );
		$this->assertCount( 2, $entries );
		foreach ( $entries as $entry ) {
			$this->assertSame( 'a', $entry['consumer'] );
		}
	}

	// -----------------------------------------------------------------------
	// 5. get_summary() groups by consumer→source pair.
	// -----------------------------------------------------------------------

	/** Test get summary groups pairs.
	 */
	public function test_get_summary_groups_pairs() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->log->record(
				array(
					'consumer' => 'arch',
					'source'   => 'health',
					'entity'   => 'e',
					'method'   => 'resources/read',
				)
			);
		}
		$this->log->record(
			array(
				'consumer' => 'arch',
				'source'   => 'crm',
				'entity'   => 'e',
				'method'   => 'prompts/get',
			)
		);

		$summary = $this->log->get_summary();
		$this->assertCount( 2, $summary );

		// First item should be arch→health (highest count).
		$this->assertSame( 'arch', $summary[0]['consumer'] );
		$this->assertSame( 'health', $summary[0]['source'] );
		$this->assertSame( 3, $summary[0]['count'] );
	}

	// -----------------------------------------------------------------------
	// 6. clear() empties the log.
	// -----------------------------------------------------------------------

	/** Test clear empties log.
	 */
	public function test_clear_empties_log() {
		$this->log->record(
			array(
				'consumer' => 'c',
				'source'   => 's',
				'entity'   => 'e',
				'method'   => 'resources/read',
			)
		);
		$this->assertCount( 1, $this->log->get_entries( 10 ) );

		$this->log->clear();
		$this->assertCount( 0, $this->log->get_entries( 10 ) );
	}

	// -----------------------------------------------------------------------
	// 7. wp_mcp_ai_toolkit_mcp_cross_mount_read action triggers recording.
	// -----------------------------------------------------------------------

	/** Test cross mount read action triggers recording.
	 */
	public function test_cross_mount_read_action_triggers_recording() {
		$this->log->init();

		do_action( 'wp_mcp_ai_toolkit_mcp_cross_mount_read', 'arch-design', 'healthcare', 'health_record', 'nvoos://arch-design/_mounted/healthcare/health_record', 'resources/read', null );

		$entries = $this->log->get_entries( 5 );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'arch-design', $entries[0]['consumer'] );
		$this->assertSame( 'healthcare', $entries[0]['source'] );
	}

	// -----------------------------------------------------------------------
	// 8. wp_mcp_ai_toolkit_mcp_audit_recorded fires after record().
	// -----------------------------------------------------------------------

	/** Test audit recorded action fires.
	 */
	public function test_audit_recorded_action_fires() {
		$fired = false;
		add_action(
			'wp_mcp_ai_toolkit_mcp_audit_recorded',
			static function () use ( &$fired ) {
				$fired = true;
			}
		);

		$this->log->record(
			array(
				'consumer' => 'c',
				'source'   => 's',
				'entity'   => 'e',
				'method'   => 'prompts/get',
			)
		);

		$this->assertTrue( $fired );
	}
}
