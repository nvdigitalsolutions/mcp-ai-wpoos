<?php
/**
 * Tests for MemPalace-inspired memory enhancements.
 *
 * Covers:
 *  - Hierarchical scoping via the `wing` and `room` fields on
 *    `store_agent_context` and the matching `retrieve_agent_memory` filters.
 *  - The verbatim-storage discipline and the
 *    `wp_mcp_ai_memory_pre_store_transform` filter.
 *  - The hybrid retrieval scoring boosters layered on top of cosine
 *    similarity in `WP_MCP_AI_Vector_Context_Service`.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

/**
 * Test case for MemPalace-inspired memory enhancements.
 *
 * @since 1.1.0
 */
class Test_MemPalace_Memory_Enhancements extends WP_UnitTestCase {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();

		// The WP test framework logs out after every test (wp_set_current_user(0)
		// in WP_UnitTestCase::tearDown), but the memory tools resolve their
		// capability check against the current user. Log in an administrator so
		// every test exercises the authenticated path.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		// Clean up any test transients.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_mcp_ai_ctx_' ) . '%'
			)
		);

		// Remove any filters added during a test.
		remove_all_filters( 'wp_mcp_ai_memory_pre_store_transform' );
		remove_all_filters( 'wp_mcp_ai_memory_score_boost_keyword_weight' );
		remove_all_filters( 'wp_mcp_ai_memory_score_boost_temporal_weight' );
		remove_all_filters( 'wp_mcp_ai_memory_score_boost_exact_match_weight' );
		remove_all_filters( 'wp_mcp_ai_memory_score_boost_total_cap' );

		parent::tearDown();
	}

	/*
	 * ------------------------------------------------------------------
	 * 1. Hierarchical scoping (wings / rooms)
	 * ------------------------------------------------------------------
	 */

	/**
	 * Storing a context with `wing` and `room` should persist them on the
	 * record and surface them in the response payload.
	 */
	public function test_store_persists_wing_and_room() {
		$tool = $this->registry->get_tool( 'store_agent_context' );

		$result = $tool->execute(
			array(
				'agent_id'     => 1001,
				'context_type' => 'learning',
				'context_data' => array(
					'title'   => 'GraphQL migration',
					'content' => 'Notes about the GraphQL migration.',
				),
				'wing'         => 'client-acme',
				'room'         => 'api-design',
			),
			array()
		);

		$this->assertTrue( $result['success'], 'Store should succeed' );
		$this->assertSame( 'client-acme', $result['wing'], 'Wing should be returned' );
		$this->assertSame( 'api-design', $result['room'], 'Room should be returned' );

		// Verify retrieval by context_id surfaces wing/room.
		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$lookup   = $retrieve->execute(
			array(
				'agent_id'   => 1001,
				'context_id' => $result['context_id'],
			),
			array()
		);

		$this->assertTrue( $lookup['success'], 'Retrieve should succeed' );
		$this->assertSame( 'client-acme', $lookup['contexts'][0]['wing'] );
		$this->assertSame( 'api-design', $lookup['contexts'][0]['room'] );
	}

	/**
	 * The `wing` filter on `retrieve_agent_memory` should scope candidates
	 * before any ranking happens.
	 */
	public function test_retrieve_filters_by_wing() {
		$store = $this->registry->get_tool( 'store_agent_context' );

		$store->execute(
			array(
				'agent_id'     => 1002,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Acme deploy schedule',
					'content' => 'Deploys on Tuesdays.',
				),
				'wing'         => 'client-acme',
			),
			array()
		);
		$store->execute(
			array(
				'agent_id'     => 1002,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Globex deploy schedule',
					'content' => 'Deploys on Thursdays.',
				),
				'wing'         => 'client-globex',
			),
			array()
		);
		$store->execute(
			array(
				'agent_id'     => 1002,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Generic note',
					'content' => 'Has no wing.',
				),
			),
			array()
		);

		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$result   = $retrieve->execute(
			array(
				'agent_id' => 1002,
				'filters'  => array(
					'wing' => 'client-acme',
				),
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['count'], 'Only the Acme record should match' );
		$this->assertSame( 'client-acme', $result['contexts'][0]['wing'] );
	}

	/**
	 * The `room` filter narrows further within a wing.
	 */
	public function test_retrieve_filters_by_room() {
		$store = $this->registry->get_tool( 'store_agent_context' );

		$store->execute(
			array(
				'agent_id'     => 1003,
				'context_type' => 'note',
				'context_data' => array(
					'title'   => 'Auth flow A',
					'content' => '...',
				),
				'wing'         => 'client-acme',
				'room'         => 'auth-flows',
			),
			array()
		);
		$store->execute(
			array(
				'agent_id'     => 1003,
				'context_type' => 'note',
				'context_data' => array(
					'title'   => 'Billing change',
					'content' => '...',
				),
				'wing'         => 'client-acme',
				'room'         => 'billing',
			),
			array()
		);

		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$result   = $retrieve->execute(
			array(
				'agent_id' => 1003,
				'filters'  => array(
					'wing' => 'client-acme',
					'room' => 'auth-flows',
				),
			),
			array()
		);

		$this->assertSame( 1, $result['count'], 'Only the auth-flows record should match' );
		$this->assertSame( 'auth-flows', $result['contexts'][0]['room'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * 2. Verbatim-storage discipline
	 * ------------------------------------------------------------------
	 */

	/**
	 * The verbatim flag must be persisted and surfaced.
	 */
	public function test_verbatim_flag_persists() {
		$tool   = $this->registry->get_tool( 'store_agent_context' );
		$result = $tool->execute(
			array(
				'agent_id'     => 2001,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Quote',
					'content' => 'Exact words that must not change.',
				),
				'verbatim'     => true,
			),
			array()
		);

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['verbatim'] );

		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$lookup   = $retrieve->execute(
			array(
				'agent_id'   => 2001,
				'context_id' => $result['context_id'],
			),
			array()
		);

		$this->assertTrue( $lookup['contexts'][0]['verbatim'] );
		$this->assertStringContainsString(
			'Exact words that must not change.',
			$lookup['contexts'][0]['content']
		);
	}

	/**
	 * Pre-store transforms must be skipped when verbatim is true.
	 */
	public function test_verbatim_skips_pre_store_transform() {
		// Listener that always rewrites content. It MUST be ignored when verbatim=true.
		add_filter(
			'wp_mcp_ai_memory_pre_store_transform',
			static function ( $context_data ) {
				$context_data['content'] = '[REWRITTEN]';
				return $context_data;
			},
			10,
			1
		);

		$tool   = $this->registry->get_tool( 'store_agent_context' );
		$result = $tool->execute(
			array(
				'agent_id'     => 2002,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Verbatim',
					'content' => 'ORIGINAL',
				),
				'verbatim'     => true,
			),
			array()
		);
		$this->assertTrue( $result['success'] );

		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$lookup   = $retrieve->execute(
			array(
				'agent_id'   => 2002,
				'context_id' => $result['context_id'],
			),
			array()
		);

		$this->assertSame( 'ORIGINAL', $lookup['contexts'][0]['content'], 'Verbatim content must be preserved' );
		$this->assertStringNotContainsString( 'REWRITTEN', $lookup['contexts'][0]['content'] );
	}

	/**
	 * Without verbatim, pre-store transforms apply normally.
	 */
	public function test_pre_store_transform_runs_when_not_verbatim() {
		add_filter(
			'wp_mcp_ai_memory_pre_store_transform',
			static function ( $context_data ) {
				$context_data['content'] = $context_data['content'] . ' [tagged]';
				return $context_data;
			}
		);

		$tool   = $this->registry->get_tool( 'store_agent_context' );
		$result = $tool->execute(
			array(
				'agent_id'     => 2003,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Test',
					'content' => 'Hello',
				),
			),
			array()
		);
		$this->assertTrue( $result['success'] );

		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$lookup   = $retrieve->execute(
			array(
				'agent_id'   => 2003,
				'context_id' => $result['context_id'],
			),
			array()
		);

		$this->assertStringContainsString( '[tagged]', $lookup['contexts'][0]['content'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * 3. Hybrid retrieval scoring
	 * ------------------------------------------------------------------
	 */

	/**
	 * The score booster method should produce zero contribution when all
	 * weights are filtered to zero, recovering pure cosine ranking.
	 */
	public function test_boosters_can_be_disabled_via_filters() {
		add_filter( 'wp_mcp_ai_memory_score_boost_keyword_weight', '__return_zero' );
		add_filter( 'wp_mcp_ai_memory_score_boost_temporal_weight', '__return_zero' );
		add_filter( 'wp_mcp_ai_memory_score_boost_exact_match_weight', '__return_zero' );

		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$method  = new ReflectionMethod( $service, 'calculate_score_boosters' );
		$method->setAccessible( true );

		$context = array(
			'data'      => array(
				'title'   => 'Machine learning',
				'content' => 'Discusses ML algorithms.',
				'tags'    => array( 'ml' ),
			),
			'wing'      => 'client-acme',
			'room'      => 'training',
			'stored_at' => current_time( 'mysql' ),
		);
		$query   = 'machine learning';
		$filters = array(
			'wing' => 'client-acme',
			'tags' => array( 'ml' ),
		);

		$result = $method->invoke( $service, $context, $query, $filters );

		$this->assertSame( 0.0, $result['keyword'] );
		$this->assertSame( 0.0, $result['temporal'] );
		$this->assertSame( 0.0, $result['exact_match'] );
		$this->assertSame( 0.0, $result['total'] );
	}

	/**
	 * The keyword booster should fire for query terms that appear in title/content.
	 */
	public function test_keyword_booster_rewards_overlap() {
		// Disable the other boosters to isolate the keyword signal.
		add_filter( 'wp_mcp_ai_memory_score_boost_temporal_weight', '__return_zero' );
		add_filter( 'wp_mcp_ai_memory_score_boost_exact_match_weight', '__return_zero' );

		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$method  = new ReflectionMethod( $service, 'calculate_score_boosters' );
		$method->setAccessible( true );

		$matching_context = array(
			'data'      => array(
				'title'   => 'Machine learning algorithms',
				'content' => 'A deep dive into supervised learning.',
			),
			'stored_at' => current_time( 'mysql' ),
		);
		$other_context    = array(
			'data'      => array(
				'title'   => 'Cooking recipes',
				'content' => 'Pasta carbonara instructions.',
			),
			'stored_at' => current_time( 'mysql' ),
		);

		$matching = $method->invoke( $service, $matching_context, 'machine learning', array() );
		$other    = $method->invoke( $service, $other_context, 'machine learning', array() );

		$this->assertGreaterThan( 0.0, $matching['keyword'], 'Matching context should get keyword boost' );
		$this->assertSame( 0.0, $other['keyword'], 'Non-matching context should get no keyword boost' );
		$this->assertGreaterThan( $other['total'], $matching['total'] );
	}

	/**
	 * The temporal booster should reward recent contexts more than old ones.
	 */
	public function test_temporal_booster_rewards_recency() {
		add_filter( 'wp_mcp_ai_memory_score_boost_keyword_weight', '__return_zero' );
		add_filter( 'wp_mcp_ai_memory_score_boost_exact_match_weight', '__return_zero' );

		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$method  = new ReflectionMethod( $service, 'calculate_score_boosters' );
		$method->setAccessible( true );

		$now_iso = current_time( 'mysql' );
		$old_iso = gmdate( 'Y-m-d H:i:s', time() - ( 365 * DAY_IN_SECONDS ) );

		$recent = array(
			'data'      => array(
				'title'   => 'A',
				'content' => 'B',
			),
			'stored_at' => $now_iso,
		);
		$old    = array(
			'data'      => array(
				'title'   => 'A',
				'content' => 'B',
			),
			'stored_at' => $old_iso,
		);

		$recent_score = $method->invoke( $service, $recent, 'anything', array() );
		$old_score    = $method->invoke( $service, $old, 'anything', array() );

		$this->assertGreaterThan( $old_score['temporal'], $recent_score['temporal'] );
	}

	/**
	 * The exact-match booster should reward wing/room/tag matches against
	 * the active retrieval filters.
	 */
	public function test_exact_match_booster_rewards_matches() {
		add_filter( 'wp_mcp_ai_memory_score_boost_keyword_weight', '__return_zero' );
		add_filter( 'wp_mcp_ai_memory_score_boost_temporal_weight', '__return_zero' );

		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$method  = new ReflectionMethod( $service, 'calculate_score_boosters' );
		$method->setAccessible( true );

		$matching = array(
			'data'      => array(
				'title'   => 'X',
				'content' => 'Y',
				'tags'    => array( 'ml', 'training' ),
			),
			'wing'      => 'client-acme',
			'room'      => 'training',
			'stored_at' => current_time( 'mysql' ),
		);
		$other    = array(
			'data'      => array(
				'title'   => 'X',
				'content' => 'Y',
				'tags'    => array( 'unrelated' ),
			),
			'wing'      => 'client-globex',
			'room'      => 'billing',
			'stored_at' => current_time( 'mysql' ),
		);
		$filters  = array(
			'wing' => 'client-acme',
			'room' => 'training',
			'tags' => array( 'ml' ),
		);

		$matching_score = $method->invoke( $service, $matching, '', $filters );
		$other_score    = $method->invoke( $service, $other, '', $filters );

		$this->assertGreaterThan( 0.0, $matching_score['exact_match'] );
		$this->assertSame( 0.0, $other_score['exact_match'] );
	}

	/**
	 * The total booster contribution is capped (default 0.25).
	 */
	public function test_boosters_are_capped() {
		// Force every booster to its maximum.
		add_filter(
			'wp_mcp_ai_memory_score_boost_keyword_weight',
			static function () {
				return 1.0;
			}
		);
		add_filter(
			'wp_mcp_ai_memory_score_boost_temporal_weight',
			static function () {
				return 1.0;
			}
		);
		add_filter(
			'wp_mcp_ai_memory_score_boost_exact_match_weight',
			static function () {
				return 1.0;
			}
		);

		$service = WP_MCP_AI_Vector_Context_Service::get_instance();
		$method  = new ReflectionMethod( $service, 'calculate_score_boosters' );
		$method->setAccessible( true );

		$context = array(
			'data'      => array(
				'title'   => 'machine learning',
				'content' => 'machine learning content',
				'tags'    => array( 'ml' ),
			),
			'wing'      => 'w',
			'room'      => 'r',
			'stored_at' => current_time( 'mysql' ),
		);
		$filters = array(
			'wing' => 'w',
			'room' => 'r',
			'tags' => array( 'ml' ),
		);

		$result = $method->invoke( $service, $context, 'machine learning', $filters );

		// The default cap is 0.25.
		$this->assertLessThanOrEqual( 0.25 + 1e-9, $result['total'] );
	}
}
