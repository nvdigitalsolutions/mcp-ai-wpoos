<?php
/**
 * Tests for the Phase 4a Graphify-backed memory bridge.
 *
 * Covers two paths:
 *   - Graphify-absent: the action fires, no listener crashes, wake_up still works.
 *   - Graphify-present (mocked): a stub listener is registered against the action
 *     and the wake_up_context tool's `mode: 'graph'` short-circuits to graph
 *     ordering when a stub `NV_oOS_Graphify_Memory_Bridge::retrieve_graph` is
 *     available.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

/**
 * Test case for Phase 4a memory→graph bridge.
 *
 * @since 1.1.0
 */
class Test_MemPalace_Phase4a_Graphify_Bridge extends WP_UnitTestCase {

	/**
	 * Tool registry.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Set up.
	 */
	public function setUp(): void {
		parent::setUp();

		// The wp-phpunit framework resets the current user to 0 after each
		// test, and the bootstrap's one-shot admin is not restored. Create a
		// fresh authenticated admin per test so tools like store_agent_context
		// (which checks `user_can( $user_id, 'read' )`) don't fail with
		// `wp_mcp_ai_forbidden` for every test after the first.
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
	}

	/**
	 * Tear down: clear transient memory store + drop hooks.
	 */
	public function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_mcp_ai_ctx_' ) . '%'
			)
		);

		remove_all_actions( 'wp_mcp_ai_memory_stored' );
		remove_all_filters( 'wp_mcp_ai_wake_up_graph_context_ids' );

		parent::tearDown();
	}

	// ------------------------------------------------------------------------
	// Action hook
	// ------------------------------------------------------------------------

	/**
	 * Storing a context fires `wp_mcp_ai_memory_stored` with the documented
	 * payload contract.
	 */
	public function test_store_fires_memory_stored_action_with_full_payload() {
		$captured = array();
		add_action(
			'wp_mcp_ai_memory_stored',
			static function ( $payload ) use ( &$captured ) {
				$captured[] = $payload;
			},
			10,
			1
		);

		$store  = $this->registry->get_tool( 'store_agent_context' );
		$result = $store->execute(
			array(
				'agent_id'     => 41001,
				'context_type' => 'fact',
				'context_data' => array(
					'title'      => 'Acme uses HSL color tokens',
					'content'    => 'All Acme components use the HSL design tokens for theming.',
					'importance' => 'high',
					'tags'       => array( 'design', 'tokens' ),
				),
				'wing'         => 'client-acme',
				'room'         => 'design-system',
				'verbatim'     => true,
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $captured, 'Expected exactly one event for one store call.' );

		$payload = $captured[0];
		$this->assertSame( $result['context_id'], $payload['context_id'] );
		$this->assertSame( 41001, $payload['agent_id'] );
		$this->assertSame( 'fact', $payload['context_type'] );
		$this->assertSame( 'Acme uses HSL color tokens', $payload['title'] );
		$this->assertStringContainsString( 'HSL design tokens', $payload['content'] );
		$this->assertSame( 'high', $payload['importance'] );
		$this->assertSame( array( 'design', 'tokens' ), $payload['tags'] );
		$this->assertSame( 'client-acme', $payload['wing'] );
		$this->assertSame( 'design-system', $payload['room'] );
		$this->assertTrue( $payload['verbatim'] );
		$this->assertSame( 0, $payload['source_post_id'] );
		$this->assertSame( '', $payload['source_url'] );
		$this->assertSame( '', $payload['source_type'] );
		$this->assertNotEmpty( $payload['stored_at'] );
		$this->assertNotEmpty( $payload['expires_at'] );
		$this->assertGreaterThanOrEqual( 3600, $payload['ttl'] );
	}

	/**
	 * `mine_agent_memory` routes through `store_agent_context`, so each mined
	 * item should also produce one `wp_mcp_ai_memory_stored` event.
	 */
	public function test_mine_fires_one_event_per_item() {
		$count = 0;
		add_action(
			'wp_mcp_ai_memory_stored',
			static function () use ( &$count ) {
				++$count;
			},
			10,
			1
		);

		$mine = $this->registry->get_tool( 'mine_agent_memory' );
		$mine->execute(
			array(
				'agent_id' => 41002,
				'source'   => 'text',
				'wing'     => 'team-alpha',
				'items'    => array(
					array(
						'title'   => 'Fact 1',
						'content' => 'Alpha team standups are at 09:30 GMT.',
					),
					array(
						'title'   => 'Fact 2',
						'content' => 'PRs require two approvals before merge.',
					),
				),
			),
			array()
		);

		$this->assertSame( 2, $count, 'mine_agent_memory should fire one event per stored item.' );
	}

	/**
	 * When no listener is registered (i.e. Graphify is not active) the store
	 * call must still succeed and behave identically to before Phase 4a.
	 *
	 * The plugin's own CCT bridge always listens to `wp_mcp_ai_memory_stored`
	 * in the full version, so the pre-condition checks only that the Graphify
	 * bridge handler is absent — matching the intent of this test.
	 */
	public function test_store_succeeds_when_no_listener_registered() {
		// The Graphify addon may already be loaded by other suites in the same
		// process (e.g. tests/graphify/*), in which case its bridge listener is
		// attached. Detach it so this test deterministically exercises the
		// no-listener path, then restore it afterwards.
		$listener = array( 'NV_oOS_Graphify_Memory_Bridge', 'on_memory_stored' );
		$priority = has_action( 'wp_mcp_ai_memory_stored', $listener );
		if ( false !== $priority ) {
			remove_action( 'wp_mcp_ai_memory_stored', $listener, $priority );
		}

		$store  = $this->registry->get_tool( 'store_agent_context' );
		$result = $store->execute(
			array(
				'agent_id'     => 41003,
				'context_type' => 'note',
				'context_data' => array(
					'title'   => 'Plain note',
					'content' => 'Nothing fancy here.',
				),
			),
			array()
		);

		if ( false !== $priority ) {
			add_action( 'wp_mcp_ai_memory_stored', $listener, $priority, 1 );
		}

		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['context_id'] );
	}

	/**
	 * A listener that throws must not break the memory write — failures in the
	 * bridge are advisory, not load-bearing.
	 *
	 * The contract: `do_action` does not catch exceptions itself, so the bridge
	 * implementation MUST swallow its own errors. Until the bridge is loaded
	 * (i.e. when only user code is listening) a throwing listener is expected
	 * to bubble up. We assert the bridge's own static handler is exception-safe.
	 */
	public function test_bridge_handler_swallows_exceptions() {
		$bridge_path = dirname( __DIR__ ) . '/addons/graphify/includes/class-nvoos-graphify-memory-bridge.php';
		if ( file_exists( $bridge_path ) ) {
			require_once $bridge_path;
		}
		if ( ! class_exists( 'NV_oOS_Graphify_Memory_Bridge' ) ) {
			$this->markTestSkipped( 'Graphify addon not loaded in this test run.' );
		}

		// Pass a malformed payload that would explode if not guarded.
		$ok = true;
		try {
			NV_oOS_Graphify_Memory_Bridge::on_memory_stored( array() );
			NV_oOS_Graphify_Memory_Bridge::on_memory_stored( array( 'context_id' => '' ) );
		} catch ( \Throwable $e ) {
			$ok = false;
		}
		$this->assertTrue( $ok, 'Bridge handler must swallow exceptions and never propagate them.' );
	}

	// ------------------------------------------------------------------------
	// wake_up_context — graph mode
	// ------------------------------------------------------------------------

	/**
	 * `mode: 'graph'` returns an error when the Graphify bridge class is not
	 * available — callers should fall back to `mode: 'auto'` for graceful UX.
	 */
	public function test_wake_up_graph_mode_errors_without_graphify() {
		if ( class_exists( 'NV_oOS_Graphify_Memory_Bridge' ) ) {
			$this->markTestSkipped( 'Graphify is loaded; this test verifies the absent-path.' );
		}

		$store = $this->registry->get_tool( 'store_agent_context' );
		$store->execute(
			array(
				'agent_id'     => 41004,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Anything',
					'content' => 'Anything',
				),
			),
			array()
		);

		$wake = $this->registry->get_tool( 'wake_up_context' );
		$res  = $wake->execute(
			array(
				'agent_id' => 41004,
				'mode'     => 'graph',
			),
			array()
		);

		$this->assertFalse( $res['success'] );
		$this->assertStringContainsString( 'Graphify', $res['message'] );
	}

	/**
	 * `mode: 'auto'` falls back to the transient path when Graphify is absent.
	 */
	public function test_wake_up_auto_mode_falls_back_to_transient() {
		if ( class_exists( 'NV_oOS_Graphify_Memory_Bridge' ) ) {
			$this->markTestSkipped( 'Graphify is loaded; absent-path test.' );
		}

		$store = $this->registry->get_tool( 'store_agent_context' );
		$store->execute(
			array(
				'agent_id'     => 41005,
				'context_type' => 'fact',
				'context_data' => array(
					'title'      => 'Auto-fallback memory',
					'content'    => 'This memory should surface via the legacy retrieval path.',
					'importance' => 'high',
				),
			),
			array()
		);

		$wake = $this->registry->get_tool( 'wake_up_context' );
		$res  = $wake->execute(
			array(
				'agent_id' => 41005,
				'mode'     => 'auto',
			),
			array()
		);

		$this->assertTrue( $res['success'] );
		$this->assertSame( 'transient', $res['retrieval_path'] );
		$this->assertGreaterThanOrEqual( 1, $res['count'] );
	}

	/**
	 * `mode: 'transient'` always uses the legacy path even when Graphify is
	 * present (smoke test — works in either environment).
	 */
	public function test_wake_up_transient_mode_uses_legacy_path() {
		$store = $this->registry->get_tool( 'store_agent_context' );
		$store->execute(
			array(
				'agent_id'     => 41006,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Forced legacy',
					'content' => 'Forced legacy retrieval.',
				),
			),
			array()
		);

		$wake = $this->registry->get_tool( 'wake_up_context' );
		$res  = $wake->execute(
			array(
				'agent_id' => 41006,
				'mode'     => 'transient',
			),
			array()
		);

		$this->assertTrue( $res['success'] );
		$this->assertSame( 'transient', $res['retrieval_path'] );
	}

	// ------------------------------------------------------------------------
	// wake_up_context — graph-mode happy path with a stubbed bridge.
	// ------------------------------------------------------------------------

	/**
	 * Verify the graph-mode happy path:
	 *  - the wake_up tool calls into the bridge
	 *  - the returned ordered context_ids drive memory selection
	 *  - the rendered system_block reflects the bridge's order
	 *
	 * Implemented by registering the `wp_mcp_ai_wake_up_graph_context_ids`
	 * filter to inject a synthetic ordered list, which is the same surface a
	 * real Graphify graph traversal would land on. This avoids depending on
	 * the addon being loaded inside the test runner.
	 */
	public function test_wake_up_graph_mode_orders_memories_by_graph() {
		$bridge_path = dirname( __DIR__ ) . '/addons/graphify/includes/class-nvoos-graphify-memory-bridge.php';
		if ( file_exists( $bridge_path ) ) {
			require_once $bridge_path;
		}
		if ( ! class_exists( 'NV_oOS_Graphify_Memory_Bridge' ) ) {
			$this->markTestSkipped( 'Graph happy-path requires the bridge class to be loadable.' );
		}

		$store = $this->registry->get_tool( 'store_agent_context' );

		$first  = $store->execute(
			array(
				'agent_id'     => 41010,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'AAA — first stored, lowest graph rank',
					'content' => 'first',
				),
			),
			array()
		);
		$second = $store->execute(
			array(
				'agent_id'     => 41010,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'BBB — second stored, highest graph rank',
					'content' => 'second',
				),
			),
			array()
		);

		$this->assertTrue( $first['success'] );
		$this->assertTrue( $second['success'] );

		// Inject a synthetic graph ordering (B before A) to prove that
		// graph-determined order wins over the legacy recency order.
		add_filter(
			'wp_mcp_ai_wake_up_graph_context_ids',
			static function () use ( $second, $first ) {
				return array( $second['context_id'], $first['context_id'] );
			},
			10,
			6
		);

		$wake = $this->registry->get_tool( 'wake_up_context' );
		$res  = $wake->execute(
			array(
				'agent_id' => 41010,
				'mode'     => 'graph',
			),
			array()
		);

		$this->assertTrue( $res['success'] );
		$this->assertSame( 'graph', $res['retrieval_path'] );
		$this->assertSame( 2, $res['count'] );
		$this->assertSame( $second['context_id'], $res['memories_loaded'][0]['context_id'] );
		$this->assertSame( $first['context_id'], $res['memories_loaded'][1]['context_id'] );
	}

	/**
	 * If the graph yields no ids the wake-up should silently fall back to the
	 * transient path so the operator still sees recent memories.
	 */
	public function test_wake_up_graph_falls_back_when_graph_empty() {
		$bridge_path = dirname( __DIR__ ) . '/addons/graphify/includes/class-nvoos-graphify-memory-bridge.php';
		if ( file_exists( $bridge_path ) ) {
			require_once $bridge_path;
		}
		if ( ! class_exists( 'NV_oOS_Graphify_Memory_Bridge' ) ) {
			$this->markTestSkipped( 'Requires the bridge class.' );
		}

		$store = $this->registry->get_tool( 'store_agent_context' );
		$store->execute(
			array(
				'agent_id'     => 41011,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Plain',
					'content' => 'Plain content.',
				),
			),
			array()
		);

		add_filter(
			'wp_mcp_ai_wake_up_graph_context_ids',
			static function () {
				return array();
			},
			10,
			6
		);

		$wake = $this->registry->get_tool( 'wake_up_context' );
		$res  = $wake->execute(
			array(
				'agent_id' => 41011,
				'mode'     => 'auto',
			),
			array()
		);

		$this->assertTrue( $res['success'] );
		$this->assertSame( 'transient', $res['retrieval_path'] );
		$this->assertGreaterThanOrEqual( 1, $res['count'] );
	}

	/**
	 * A graph-ranked context_id that no longer resolves (expired or deleted
	 * between ranking and fetch) makes retrieve_agent_memory return WP_Error.
	 * wake_up_context must skip the dead id — not fatal with "Cannot use
	 * object of type WP_Error as array" — and still serve the live records.
	 */
	public function test_wake_up_graph_skips_stale_context_ids() {
		$bridge_path = dirname( __DIR__ ) . '/addons/graphify/includes/class-nvoos-graphify-memory-bridge.php';
		if ( file_exists( $bridge_path ) ) {
			require_once $bridge_path;
		}
		if ( ! class_exists( 'NV_oOS_Graphify_Memory_Bridge' ) ) {
			$this->markTestSkipped( 'Requires the bridge class.' );
		}

		$store = $this->registry->get_tool( 'store_agent_context' );
		$valid = $store->execute(
			array(
				'agent_id'     => 41012,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Survives',
					'content' => 'Still live.',
				),
			),
			array()
		);
		$this->assertTrue( $valid['success'] );

		// Graph ranking surfaces a stale id alongside the live one.
		add_filter(
			'wp_mcp_ai_wake_up_graph_context_ids',
			static function () use ( $valid ) {
				return array( 'stale-deleted-context-id', $valid['context_id'] );
			},
			10,
			6
		);

		$wake = $this->registry->get_tool( 'wake_up_context' );
		$res  = $wake->execute(
			array(
				'agent_id' => 41012,
				'mode'     => 'graph',
			),
			array()
		);

		$this->assertTrue( $res['success'] );
		$this->assertSame( 'graph', $res['retrieval_path'] );
		$this->assertSame( 1, $res['count'] );
		$this->assertSame( $valid['context_id'], $res['memories_loaded'][0]['context_id'] );
	}

	/**
	 * Industry-standard observability: `memories_loaded[].via` should surface
	 * the provenance signals produced by graph retrieval (mem0/Letta-style
	 * retrieval-log convention). With a real Graphify graph the list is
	 * non-empty; this test locks the plumbing (the key always exists and is
	 * an array) using the synthetic rank-list injection the other graph-mode
	 * tests rely on.
	 */
	public function test_wake_up_graph_surfaces_via_provenance() {
		$bridge_path = dirname( __DIR__ ) . '/addons/graphify/includes/class-nvoos-graphify-memory-bridge.php';
		if ( ! file_exists( $bridge_path ) ) {
			$this->markTestSkipped( 'Graphify addon not present.' );
		}
		require_once $bridge_path;

		$store = $this->registry->get_tool( 'store_agent_context' );
		$one   = $store->execute(
			array(
				'agent_id'     => 41011,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Provenance memory',
					'content' => 'Provenance memory.',
				),
				'wing'         => 'project-via',
			),
			array()
		);
		$this->assertTrue( $one['success'] );

		// Inject ranked structure as if graph retrieval produced it. In this
		// test environment the Graphify DB is absent, so `retrieve_graph`
		// returns an empty ranked list and the real `via` payload is empty —
		// the assertion locks the response shape, not the signal content.
		add_filter(
			'wp_mcp_ai_wake_up_graph_context_ids',
			static function ( $ids, $ranked ) use ( $one ) {
				return array( $one['context_id'] );
			},
			10,
			6
		);

		$wake = $this->registry->get_tool( 'wake_up_context' );
		$res  = $wake->execute(
			array(
				'agent_id' => 41011,
				'mode'     => 'graph',
			),
			array()
		);

		$this->assertTrue( $res['success'] );
		$this->assertSame( 'graph', $res['retrieval_path'] );
		$this->assertSame( 1, $res['count'] );
		$this->assertSame( $one['context_id'], $res['memories_loaded'][0]['context_id'] );
		$this->assertArrayHasKey( 'via', $res['memories_loaded'][0] );
		$this->assertIsArray( $res['memories_loaded'][0]['via'] );
	}

	/**
	 * Industry-standard linear-combination weights should be filterable so
	 * operators can rebalance the three GraphRAG signals without code edits
	 * (Microsoft GraphRAG / Neo4j / LlamaIndex convention). Verifies the
	 * filter is invoked with the expected default keys.
	 */
	public function test_graph_score_weights_filter_invoked() {
		$bridge_path = dirname( __DIR__ ) . '/addons/graphify/includes/class-nvoos-graphify-memory-bridge.php';
		if ( ! file_exists( $bridge_path ) ) {
			$this->markTestSkipped( 'Graphify addon not present.' );
		}
		if ( ! class_exists( 'NV_oOS_Graphify_DB' ) ) {
			$this->markTestSkipped( 'Graphify DB class not loadable in test env.' );
		}
		require_once $bridge_path;

		$captured = array();
		add_filter(
			'wp_mcp_ai_graph_score_weights',
			static function ( $weights, $args ) use ( &$captured ) {
				$captured = $weights;
				return $weights;
			},
			10,
			2
		);

		NV_oOS_Graphify_Memory_Bridge::retrieve_graph(
			array(
				'agent_id' => 41012,
				'wing'     => 'p',
				'room'     => 'r',
				'query'    => 'q',
				'limit'    => 5,
			)
		);

		$this->assertArrayHasKey( 'agent', $captured );
		$this->assertArrayHasKey( 'wing', $captured );
		$this->assertArrayHasKey( 'room', $captured );
		$this->assertArrayHasKey( 'keyword', $captured );
		$this->assertArrayHasKey( 'vector', $captured );
	}
}
