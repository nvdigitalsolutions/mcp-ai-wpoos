<?php
/**
 * Tests for the Memory Provenance Tracer tool (Phase 6 of the 2026 Memory
 * Layer Enhancements).
 *
 * Covers:
 *  - Valid / invalid / missing arguments and WP_Error envelope shape.
 *  - Independent include flags for audit / versions / graph sections.
 *  - Graphify-absent graceful degradation.
 *  - max_depth clamping via the
 *    `wp_mcp_ai_memory_provenance_max_depth` filter.
 *  - Summary derivation (modification_count, first_source, first_seen).
 *  - `wp_mcp_ai_memory_provenance_traced` action firing exactly once per
 *    successful trace.
 *
 * The tool is read-only and reads the same transient keys that
 * `WP_MCP_AI_Tool_Memory_Audit_Trail` writes, so fixtures are constructed
 * by populating those transients directly — no API keys or network are
 * involved.
 *
 * @package WP_MCP_AI
 * @since 1.1.20
 */

if ( ! class_exists( 'WP_MCP_AI_Tool_Trace_Memory_Provenance' ) ) {
	require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-trace-memory-provenance.php';
}

/**
 * Test case for `WP_MCP_AI_Tool_Trace_Memory_Provenance`.
 *
 * @since 1.1.20
 */
class Test_Memory_Provenance_Tracer extends WP_UnitTestCase {

	/**
	 * Tool under test.
	 *
	 * @var WP_MCP_AI_Tool_Trace_Memory_Provenance
	 */
	private $tool;

	/**
	 * Reusable fixture agent id.
	 *
	 * @var int
	 */
	private $agent_id = 4242;

	/**
	 * Reusable fixture context id.
	 *
	 * @var string
	 */
	private $context_id = 'ctx_provenance_test_abc';

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->tool = new WP_MCP_AI_Tool_Trace_Memory_Provenance();

		// Clear any stale fixtures from previous tests in the same run.
		$this->clear_fixtures( $this->agent_id, $this->context_id );
	}

	/**
	 * Tear down: remove filters and actions we installed.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_mcp_ai_memory_provenance_max_depth' );
		remove_all_filters( 'wp_mcp_ai_memory_provenance_include_audit_default' );
		remove_all_filters( 'wp_mcp_ai_memory_provenance_include_versions_default' );
		remove_all_filters( 'wp_mcp_ai_memory_provenance_include_graph_default' );
		remove_all_filters( 'wp_mcp_ai_memory_provenance_summary' );
		remove_all_actions( 'wp_mcp_ai_memory_provenance_traced' );

		$this->clear_fixtures( $this->agent_id, $this->context_id );
		parent::tearDown();
	}

	/* ------------------------------------------------------------------
	 * Fixture helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Seed an audit log entry for the given agent + context.
	 *
	 * @param int    $agent_id   Agent id.
	 * @param string $context_id Context id.
	 * @param string $action     Action label (`create`, `update`, etc.).
	 * @param string $timestamp  MySQL-formatted timestamp.
	 * @param array  $metadata   Extra metadata.
	 */
	private function seed_audit_entry( $agent_id, $context_id, $action, $timestamp, $metadata = array() ) {
		$key = 'mcp_ai_audit_log_' . md5( (string) $agent_id );
		$log = get_transient( $key );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'context_id' => $context_id,
			'action'     => $action,
			'metadata'   => $metadata,
			'timestamp'  => $timestamp,
			'user_id'    => 0,
		);
		set_transient( $key, $log, YEAR_IN_SECONDS );
	}

	/**
	 * Seed a version snapshot for the given agent + context.
	 *
	 * @param int    $agent_id   Agent id.
	 * @param string $context_id Context id.
	 * @param int    $version    Version number.
	 * @param array  $data       Version payload.
	 * @param string $timestamp  MySQL-formatted timestamp.
	 */
	private function seed_version( $agent_id, $context_id, $version, $data, $timestamp ) {
		$key     = 'mcp_ai_ctx_history_' . md5( $agent_id . '_' . $context_id );
		$history = get_transient( $key );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		$history[ $version ] = array(
			'version'     => $version,
			'data'        => $data,
			'change_type' => 'update',
			'timestamp'   => $timestamp,
		);
		set_transient( $key, $history, YEAR_IN_SECONDS );
	}

	/**
	 * Delete every transient this suite writes for one (agent, context) pair.
	 *
	 * @param int    $agent_id   Agent id.
	 * @param string $context_id Context id.
	 */
	private function clear_fixtures( $agent_id, $context_id ) {
		delete_transient( 'mcp_ai_audit_log_' . md5( (string) $agent_id ) );
		delete_transient( 'mcp_ai_ctx_history_' . md5( $agent_id . '_' . $context_id ) );
		delete_transient( 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id ) );
	}

	/**
	 * Seed both an audit entry and a version for the canonical fixture
	 * context. Used by tests that just want "some history exists".
	 */
	private function seed_full_fixture() {
		$this->seed_audit_entry(
			$this->agent_id,
			$this->context_id,
			'create',
			'2026-11-01 09:00:00',
			array( 'source' => array( 'type' => 'tool', 'value' => 'store_agent_context' ) )
		);
		$this->seed_audit_entry(
			$this->agent_id,
			$this->context_id,
			'update',
			'2026-11-02 10:15:00',
			array( 'source' => array( 'type' => 'user', 'value' => 'editor-1' ) )
		);
		$this->seed_version(
			$this->agent_id,
			$this->context_id,
			1,
			array( 'title' => 'v1', 'content' => 'first revision' ),
			'2026-11-01 09:00:00'
		);
		$this->seed_version(
			$this->agent_id,
			$this->context_id,
			2,
			array( 'title' => 'v2', 'content' => 'second revision' ),
			'2026-11-02 10:15:00'
		);
	}

	/* ------------------------------------------------------------------
	 * 1. Happy path
	 * ------------------------------------------------------------------ */

	/**
	 * Valid agent_id + context_id with audit + version data returns the
	 * full envelope and all three sections.
	 */
	public function test_full_envelope_with_all_sources_present() {
		$this->seed_full_fixture();

		$result = $this->tool->execute(
			array(
				'agent_id'   => $this->agent_id,
				'context_id' => $this->context_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( 'errors', $result, 'should not be a WP_Error' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $this->context_id, $result['context_id'] );
		$this->assertSame( $this->agent_id, $result['agent_id'] );

		$this->assertArrayHasKey( 'audit', $result['trace'] );
		$this->assertArrayHasKey( 'versions', $result['trace'] );
		$this->assertArrayHasKey( 'graph', $result['trace'] );

		$this->assertTrue( $result['trace']['audit']['available'] );
		$this->assertSame( 2, $result['trace']['audit']['total'] );

		$this->assertTrue( $result['trace']['versions']['available'] );
		$this->assertSame( 2, $result['trace']['versions']['total'] );

		// Audit events sorted oldest-first.
		$this->assertSame( 'create', $result['trace']['audit']['events'][0]['action'] );
		$this->assertSame( 'update', $result['trace']['audit']['events'][1]['action'] );
	}

	/* ------------------------------------------------------------------
	 * 2. Missing context_id
	 * ------------------------------------------------------------------ */

	/**
	 * Missing context_id returns WP_Error with code `invalid_context_id`.
	 */
	public function test_missing_context_id_returns_wp_error() {
		$result = $this->tool->execute(
			array(
				'agent_id' => $this->agent_id,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_context_id', $result->get_error_code() );
	}

	/* ------------------------------------------------------------------
	 * 3. Non-existent context_id
	 * ------------------------------------------------------------------ */

	/**
	 * Non-existent context_id returns WP_Error with code `memory_not_found`.
	 */
	public function test_unknown_context_id_returns_memory_not_found() {
		$result = $this->tool->execute(
			array(
				'agent_id'   => $this->agent_id,
				'context_id' => 'ctx_does_not_exist_xyz',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'memory_not_found', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 404, $data['status'] );
	}

	/* ------------------------------------------------------------------
	 * 4. include_audit=false
	 * ------------------------------------------------------------------ */

	/**
	 * Setting include_audit=false suppresses the audit section while
	 * preserving versions + graph.
	 */
	public function test_include_audit_false_suppresses_audit_only() {
		$this->seed_full_fixture();

		$result = $this->tool->execute(
			array(
				'agent_id'      => $this->agent_id,
				'context_id'    => $this->context_id,
				'include_audit' => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['trace']['audit']['available'] );
		$this->assertSame( 'suppressed by caller', $result['trace']['audit']['reason'] );
		$this->assertArrayNotHasKey( 'events', $result['trace']['audit'] );

		$this->assertTrue( $result['trace']['versions']['available'] );
		$this->assertArrayHasKey( 'available', $result['trace']['graph'] );
	}

	/* ------------------------------------------------------------------
	 * 5. include_versions=false
	 * ------------------------------------------------------------------ */

	/**
	 * Setting include_versions=false suppresses the versions section while
	 * preserving audit + graph.
	 */
	public function test_include_versions_false_suppresses_versions_only() {
		$this->seed_full_fixture();

		$result = $this->tool->execute(
			array(
				'agent_id'         => $this->agent_id,
				'context_id'       => $this->context_id,
				'include_versions' => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['trace']['versions']['available'] );
		$this->assertSame( 'suppressed by caller', $result['trace']['versions']['reason'] );
		$this->assertArrayNotHasKey( 'versions', $result['trace']['versions'] );

		$this->assertTrue( $result['trace']['audit']['available'] );
		$this->assertArrayHasKey( 'available', $result['trace']['graph'] );
	}

	/* ------------------------------------------------------------------
	 * 6. include_graph=false
	 * ------------------------------------------------------------------ */

	/**
	 * Setting include_graph=false suppresses the graph section while
	 * preserving audit + versions.
	 */
	public function test_include_graph_false_suppresses_graph_only() {
		$this->seed_full_fixture();

		$result = $this->tool->execute(
			array(
				'agent_id'      => $this->agent_id,
				'context_id'    => $this->context_id,
				'include_graph' => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['trace']['graph']['available'] );
		$this->assertSame( 'suppressed by caller', $result['trace']['graph']['reason'] );

		$this->assertTrue( $result['trace']['audit']['available'] );
		$this->assertTrue( $result['trace']['versions']['available'] );
	}

	/* ------------------------------------------------------------------
	 * 7. Graphify absent
	 * ------------------------------------------------------------------ */

	/**
	 * When the Graphify bridge class isn't loaded (the default in the
	 * Base-build test environment), the graph section reports
	 * available=false and the call still succeeds.
	 */
	public function test_graphify_absent_returns_unavailable_graph() {
		if ( class_exists( 'NV_oOS_Graphify_Memory_Bridge' ) ) {
			$this->markTestSkipped( 'Graphify bridge is loaded — this test only applies to Base builds.' );
		}

		$this->seed_full_fixture();

		$result = $this->tool->execute(
			array(
				'agent_id'   => $this->agent_id,
				'context_id' => $this->context_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['trace']['graph']['available'] );
		$this->assertSame( 'Graphify bridge unavailable', $result['trace']['graph']['reason'] );
	}

	/* ------------------------------------------------------------------
	 * 8. Summary modification_count
	 * ------------------------------------------------------------------ */

	/**
	 * `summary.modification_count` equals the number of stored versions.
	 */
	public function test_summary_modification_count_matches_versions() {
		$this->seed_full_fixture();
		// Add one more version → 3 total.
		$this->seed_version(
			$this->agent_id,
			$this->context_id,
			3,
			array( 'title' => 'v3', 'content' => 'third revision' ),
			'2026-11-03 11:30:00'
		);

		$result = $this->tool->execute(
			array(
				'agent_id'   => $this->agent_id,
				'context_id' => $this->context_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 3, $result['summary']['modification_count'] );
		$this->assertSame( '2026-11-01 09:00:00', $result['summary']['first_seen'] );
		// first_source pulled from the earliest audit entry's metadata.
		$this->assertSame( 'tool', $result['summary']['first_source']['type'] );
	}

	/* ------------------------------------------------------------------
	 * 9. max_depth clamping via filter
	 * ------------------------------------------------------------------ */

	/**
	 * Setting the `wp_mcp_ai_memory_provenance_max_depth` filter to 3
	 * and passing max_depth=10 results in depth=3 surfacing in the
	 * graph section.
	 *
	 * The fixture stubs out the Graphify bridge with an empty walker so
	 * the section is `available=true` with `depth` reflecting the clamp,
	 * even on Base builds where Graphify is absent.
	 */
	public function test_max_depth_filter_clamps_caller_value() {
		$this->seed_full_fixture();

		// Define minimal Graphify stubs once per test run. The classes
		// live in the global namespace so a subsequent test run that
		// *does* load real Graphify will not duplicate the symbol.
		if ( ! class_exists( 'NV_oOS_Graphify_Memory_Bridge' ) ) {
			eval( 'class NV_oOS_Graphify_Memory_Bridge { const NODE_PREFIX_MEMORY = "memory:"; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- intentional test stub.
		}
		if ( ! class_exists( 'NV_oOS_Graphify_DB' ) ) {
			eval( 'class NV_oOS_Graphify_DB { public static function get_neighbor_ids( $node_id, $edge ) { return array(); } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- intentional test stub.
		}

		add_filter(
			'wp_mcp_ai_memory_provenance_max_depth',
			static function () {
				return 3;
			}
		);

		$result = $this->tool->execute(
			array(
				'agent_id'   => $this->agent_id,
				'context_id' => $this->context_id,
				'max_depth'  => 10,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['trace']['graph']['available'] );
		$this->assertSame( 3, $result['trace']['graph']['depth'] );
	}

	/* ------------------------------------------------------------------
	 * 10. Action fires once per successful trace
	 * ------------------------------------------------------------------ */

	/**
	 * `wp_mcp_ai_memory_provenance_traced` fires exactly once per
	 * successful trace and receives the canonical (context_id, agent_id,
	 * summary) signature.
	 */
	public function test_traced_action_fires_exactly_once_on_success() {
		$this->seed_full_fixture();

		$calls = array();
		add_action(
			'wp_mcp_ai_memory_provenance_traced',
			static function ( $context_id, $agent_id, $summary ) use ( &$calls ) {
				$calls[] = array(
					'context_id' => $context_id,
					'agent_id'   => $agent_id,
					'summary'    => $summary,
				);
			},
			10,
			3
		);

		$result = $this->tool->execute(
			array(
				'agent_id'   => $this->agent_id,
				'context_id' => $this->context_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $calls, 'action should fire exactly once per successful trace' );
		$this->assertSame( $this->context_id, $calls[0]['context_id'] );
		$this->assertSame( $this->agent_id, $calls[0]['agent_id'] );
		$this->assertIsArray( $calls[0]['summary'] );
		$this->assertArrayHasKey( 'modification_count', $calls[0]['summary'] );
	}

	/* ------------------------------------------------------------------
	 * 11. Action does NOT fire on failure (defensive bonus case)
	 * ------------------------------------------------------------------ */

	/**
	 * The `wp_mcp_ai_memory_provenance_traced` action must NOT fire when
	 * the tool fails validation — keeps observers from seeing partial /
	 * fabricated traces.
	 */
	public function test_traced_action_does_not_fire_on_error() {
		$calls = 0;
		add_action(
			'wp_mcp_ai_memory_provenance_traced',
			static function () use ( &$calls ) {
				++$calls;
			}
		);

		$missing_ctx = $this->tool->execute( array( 'agent_id' => $this->agent_id ) );
		$this->assertInstanceOf( WP_Error::class, $missing_ctx );

		$not_found = $this->tool->execute(
			array(
				'agent_id'   => $this->agent_id,
				'context_id' => 'ctx_never_existed_zzz',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $not_found );

		$this->assertSame( 0, $calls, 'action must not fire on validation or not-found errors' );
	}
}
