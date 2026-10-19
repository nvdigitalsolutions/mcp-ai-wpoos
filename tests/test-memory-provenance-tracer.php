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

require_once __DIR__ . '/helpers/memory-provenance-graph-stubs.php';

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
		remove_all_filters( 'wp_mcp_ai_memory_provenance_graph_bridge' );
		remove_all_filters( 'wp_mcp_ai_memory_provenance_graph_db' );
		remove_all_filters( 'wp_mcp_ai_memory_provenance_graph_relations' );
		remove_all_actions( 'wp_mcp_ai_memory_provenance_traced' );

		WP_MCP_AI_Test_Memory_Graph_Db_Stub::$edges = array();

		$this->clear_fixtures( $this->agent_id, $this->context_id );
		parent::tearDown();
	}

	/*
	------------------------------------------------------------------
	 * Fixture helpers
	 * ------------------------------------------------------------------
	 */

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
			array(
				'source' => array(
					'type'  => 'tool',
					'value' => 'store_agent_context',
				),
			)
		);
		$this->seed_audit_entry(
			$this->agent_id,
			$this->context_id,
			'update',
			'2026-11-02 10:15:00',
			array(
				'source' => array(
					'type'  => 'user',
					'value' => 'editor-1',
				),
			)
		);
		$this->seed_version(
			$this->agent_id,
			$this->context_id,
			1,
			array(
				'title'   => 'v1',
				'content' => 'first revision',
			),
			'2026-11-01 09:00:00'
		);
		$this->seed_version(
			$this->agent_id,
			$this->context_id,
			2,
			array(
				'title'   => 'v2',
				'content' => 'second revision',
			),
			'2026-11-02 10:15:00'
		);
	}

	/*
	------------------------------------------------------------------
	 * 1. Happy path
	 * ------------------------------------------------------------------
	 */

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

	/*
	------------------------------------------------------------------
	 * 2. Missing context_id
	 * ------------------------------------------------------------------
	 */

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

	/*
	------------------------------------------------------------------
	 * 3. Non-existent context_id
	 * ------------------------------------------------------------------
	 */

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

	/*
	------------------------------------------------------------------
	 * 4. include_audit=false
	 * ------------------------------------------------------------------
	 */

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

	/*
	------------------------------------------------------------------
	 * 5. include_versions=false
	 * ------------------------------------------------------------------
	 */

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

	/*
	------------------------------------------------------------------
	 * 6. include_graph=false
	 * ------------------------------------------------------------------
	 */

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

	/*
	------------------------------------------------------------------
	 * 7. Graphify absent
	 * ------------------------------------------------------------------
	 */

	/**
	 * When no graph bridge class is loaded (the default in the Base-build
	 * test environment), the graph section reports available=false and the
	 * call still succeeds.
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
		$this->assertSame( 'Graph bridge unavailable', $result['trace']['graph']['reason'] );
	}

	/*
	------------------------------------------------------------------
	 * 7b. Graph bridge filter seams
	 * ------------------------------------------------------------------
	 */

	/**
	 * The bridge + DB walker seams let a site swap in any duck-typed graph
	 * implementation (e.g. the standalone Content Graph plugin) and the BFS
	 * traverses the relations the memory bridges actually write, surfacing
	 * non-memory anchors with their kind.
	 */
	public function test_graph_bridge_filter_seam_walks_populated_relations() {
		$this->seed_full_fixture();

		WP_MCP_AI_Test_Memory_Graph_Db_Stub::$edges = array(
			'memory:' . $this->context_id => array(
				'OBSERVED_BY'  => array( 'agent:' . $this->agent_id ),
				'MEMBER_OF'    => array( 'wing:site-a' ),
				'DERIVED_FROM' => array( 'post_7' ),
			),
			'wing:site-a'                 => array(
				'MEMBER_OF' => array( 'memory:ctx_related' ),
			),
		);

		add_filter(
			'wp_mcp_ai_memory_provenance_graph_bridge',
			static function () {
				return 'WP_MCP_AI_Test_Memory_Graph_Bridge_Stub';
			}
		);
		add_filter(
			'wp_mcp_ai_memory_provenance_graph_db',
			static function () {
				return 'WP_MCP_AI_Test_Memory_Graph_Db_Stub';
			}
		);

		$result = $this->tool->execute(
			array(
				'agent_id'   => $this->agent_id,
				'context_id' => $this->context_id,
				'max_depth'  => 2,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['trace']['graph']['available'] );

		$nodes = $result['trace']['graph']['nodes'];

		$agent_node = $this->find_graph_node( $nodes, 'agent:' . $this->agent_id );
		$this->assertNotNull( $agent_node );
		$this->assertSame( 1, $agent_node['depth'] );
		$this->assertSame( 'OBSERVED_BY', $agent_node['edge'] );
		$this->assertSame( 'agent', $agent_node['kind'] );

		$wing_node = $this->find_graph_node( $nodes, 'wing:site-a' );
		$this->assertNotNull( $wing_node );
		$this->assertSame( 'MEMBER_OF', $wing_node['edge'] );
		$this->assertSame( 'wing', $wing_node['kind'] );

		$post_node = $this->find_graph_node( $nodes, 'post_7' );
		$this->assertNotNull( $post_node );
		$this->assertSame( 'DERIVED_FROM', $post_node['edge'] );
		$this->assertSame( 'other', $post_node['kind'] );
		$this->assertSame( '', $post_node['context_id'] );

		$related = $this->find_graph_node( $nodes, 'memory:ctx_related' );
		$this->assertNotNull( $related );
		$this->assertSame( 2, $related['depth'] );
		$this->assertSame( 'ctx_related', $related['context_id'] );
		$this->assertSame( 'memory', $related['kind'] );
	}

	/**
	 * An empty relations list (via filter) disables the graph walk without
	 * failing the whole trace.
	 */
	public function test_empty_graph_relations_disables_walk() {
		$this->seed_full_fixture();

		add_filter(
			'wp_mcp_ai_memory_provenance_graph_bridge',
			static function () {
				return 'WP_MCP_AI_Test_Memory_Graph_Bridge_Stub';
			}
		);
		add_filter(
			'wp_mcp_ai_memory_provenance_graph_db',
			static function () {
				return 'WP_MCP_AI_Test_Memory_Graph_Db_Stub';
			}
		);
		add_filter( 'wp_mcp_ai_memory_provenance_graph_relations', '__return_empty_array' );

		$result = $this->tool->execute(
			array(
				'agent_id'   => $this->agent_id,
				'context_id' => $this->context_id,
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['trace']['graph']['available'] );
		$this->assertSame( 'No graph relations configured', $result['trace']['graph']['reason'] );
	}

	/**
	 * Find a node in the graph section by node id.
	 *
	 * @param array  $nodes   Graph nodes list.
	 * @param string $node_id Node id to find.
	 * @return array|null Node payload or null.
	 */
	private function find_graph_node( $nodes, $node_id ) {
		foreach ( $nodes as $node ) {
			if ( isset( $node['node_id'] ) && $node_id === $node['node_id'] ) {
				return $node;
			}
		}
		return null;
	}

	/*
	------------------------------------------------------------------
	 * 8. Summary modification_count
	 * ------------------------------------------------------------------
	 */

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
			array(
				'title'   => 'v3',
				'content' => 'third revision',
			),
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

	/*
	------------------------------------------------------------------
	 * 9. max_depth clamping via filter
	 * ------------------------------------------------------------------
	 */

	/**
	 * Setting the `wp_mcp_ai_memory_provenance_max_depth` filter to 3
	 * and passing max_depth=10 results in depth=3 surfacing in the
	 * graph section.
	 *
	 * Loads the real Graphify bridge + DB classes bundled with the addon.
	 * A fresh test database has no graph rows, so the BFS walk returns an
	 * empty neighbourhood and the section is `available=true` with `depth`
	 * reflecting the clamp, even on Base builds where the addon is not
	 * active.
	 *
	 * The real files are used instead of eval-declared stub classes: stubs
	 * pollute the global namespace for the rest of the single-process run
	 * and make later tests that require the real files die with
	 * "Cannot declare class NV_oOS_Graphify_Memory_Bridge".
	 */
	public function test_max_depth_filter_clamps_caller_value() {
		$this->seed_full_fixture();

		if ( ! class_exists( 'NV_oOS_Graphify_Memory_Bridge' ) ) {
			$bridge_path = dirname( __DIR__ ) . '/addons/graphify/includes/class-nvoos-graphify-memory-bridge.php';
			if ( file_exists( $bridge_path ) ) {
				require_once $bridge_path;
			}
		}
		if ( ! class_exists( 'NV_oOS_Graphify_DB' ) ) {
			$db_path = dirname( __DIR__ ) . '/addons/graphify/includes/class-nvoos-graphify-db.php';
			if ( file_exists( $db_path ) ) {
				require_once $db_path;
			}
		}

		if ( ! class_exists( 'NV_oOS_Graphify_Memory_Bridge' )
			|| ! method_exists( 'NV_oOS_Graphify_DB', 'get_neighbor_ids' ) ) {
			$this->markTestSkipped( 'Graphify addon classes are not loadable.' );
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

	/*
	------------------------------------------------------------------
	 * 10. Action fires once per successful trace
	 * ------------------------------------------------------------------
	 */

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

	/*
	------------------------------------------------------------------
	 * 11. Action does NOT fire on failure (defensive bonus case)
	 * ------------------------------------------------------------------
	 */

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
