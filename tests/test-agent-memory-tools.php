<?php
/**
 * Test DeepSeek V4 Agent Memory Tools
 *
 * Validates that the agent memory tools (store_agent_context and
 * retrieve_agent_memory) are properly implemented and functional.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test case for agent memory tools validation
 *
 * @since 1.1.0
 */
class Test_Agent_Memory_Tools extends WP_UnitTestCase {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Administrator user ID used by execution tests.
	 *
	 * @var int
	 */
	private $admin_id = 0;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Both tools gate on a logged-in user with the 'read' capability, and
		// user_can( 0, ... ) is always false, so give the suite a real user.
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
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

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that store_agent_context tool is registered.
	 */
	public function test_store_agent_context_tool_registered() {
		$tool = $this->registry->get_tool( 'store_agent_context' );
		$this->assertNotNull( $tool, 'store_agent_context tool should be registered' );
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Store_Agent_Context',
			$tool,
			'Tool should be instance of WP_MCP_AI_Tool_Store_Agent_Context'
		);
	}

	/**
	 * Test that retrieve_agent_memory tool is registered.
	 */
	public function test_retrieve_agent_memory_tool_registered() {
		$tool = $this->registry->get_tool( 'retrieve_agent_memory' );
		$this->assertNotNull( $tool, 'retrieve_agent_memory tool should be registered' );
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Retrieve_Agent_Memory',
			$tool,
			'Tool should be instance of WP_MCP_AI_Tool_Retrieve_Agent_Memory'
		);
	}

	/**
	 * Test store_agent_context tool execution with valid data.
	 */
	public function test_store_agent_context_execution() {
		$tool = $this->registry->get_tool( 'store_agent_context' );

		$arguments = array(
			'agent_id'     => 123,
			'context_type' => 'learning',
			'context_data' => array(
				'title'      => 'Test Learning',
				'content'    => 'This is a test learning context',
				'importance' => 'high',
				'tags'       => array( 'test', 'learning' ),
			),
			'ttl'          => 3600, // 1 hour.
		);

		$result = $tool->execute( $arguments, array() );

		$this->assertTrue( $result['success'], 'Execution should succeed' );
		$this->assertArrayHasKey( 'context_id', $result, 'Result should contain context_id' );
		$this->assertNotEmpty( $result['context_id'], 'Context ID should not be empty' );
		$this->assertEquals( 123, $result['agent_id'], 'Agent ID should match' );
		$this->assertArrayHasKey( 'stored_at', $result, 'Result should contain stored_at timestamp' );
		$this->assertArrayHasKey( 'expires_at', $result, 'Result should contain expires_at timestamp' );
	}

	/**
	 * Test store_agent_context resolves a virtual agent key to the canonical
	 * assistant_id from the execution context (identity fix #1/#2).
	 */
	public function test_store_agent_context_resolves_virtual_agent_key() {
		$tool = $this->registry->get_tool( 'store_agent_context' );

		$result = $tool->execute(
			array(
				'agent_id'     => 'nvoos-pro-spa-memory-drawer',
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Virtual-key fact',
					'content' => 'Stored under a virtual key, resolved to 953.',
				),
			),
			array(
				'user_id'      => get_current_user_id(),
				'assistant_id' => 953,
			)
		);

		$this->assertTrue( $result['success'], 'Execution should succeed' );
		$this->assertSame( 953, $result['agent_id'], 'Memory should be stored under the canonical assistant ID' );
		$this->assertTrue( $result['agent_id_resolved'], 'The virtual key should be reported as resolved' );
		$this->assertSame( 'nvoos-pro-spa-memory-drawer', $result['original_agent_id'], 'The caller key should be echoed back' );

		// The alias must be persisted so future stores resolve without context.
		// The resolver stores canonical IDs as strings; the store response uses int.
		$this->assertSame( '953', WP_MCP_AI_Agent_Identity_Resolver::get_canonical( 'nvoos-pro-spa-memory-drawer' ) );

		// And the record must be retrievable under the canonical ID — the
		// exact bucket the chat-memory drawer recalls from.
		$retrieve = $this->registry->get_tool( 'retrieve_agent_memory' );
		$recall   = $retrieve->execute(
			array(
				'agent_id' => 953,
				'limit'    => 10,
			),
			array()
		);

		$this->assertTrue( $recall['success'] );
		$this->assertNotEmpty( $recall['contexts'], 'The stored record should appear in the canonical bucket' );
		$this->assertSame( $result['context_id'], $recall['contexts'][0]['context_id'] );
	}

	/**
	 * Test store_agent_context with missing required fields.
	 */
	public function test_store_agent_context_missing_fields() {
		$tool = $this->registry->get_tool( 'store_agent_context' );

		// Missing agent_id.
		$result = $tool->execute(
			array(
				'context_type' => 'learning',
				'context_data' => array(
					'title'   => 'Test',
					'content' => 'Test content',
				),
			),
			array()
		);
		$this->assertWPError( $result );
		$this->assertSame( 'store_agent_context_missing_agent_id', $result->get_error_code(), 'Should fail without agent_id' );

		// Missing context_type.
		$result = $tool->execute(
			array(
				'agent_id'     => 123,
				'context_data' => array(
					'title'   => 'Test',
					'content' => 'Test content',
				),
			),
			array()
		);
		$this->assertWPError( $result );
		$this->assertSame( 'store_agent_context_missing_context_type', $result->get_error_code(), 'Should fail without context_type' );

		// Missing context_data title.
		$result = $tool->execute(
			array(
				'agent_id'     => 123,
				'context_type' => 'learning',
				'context_data' => array(
					'content' => 'Test content',
				),
			),
			array()
		);
		$this->assertWPError( $result );
		$this->assertSame( 'store_agent_context_missing_title_content', $result->get_error_code(), 'Should fail without context title' );
	}

	/**
	 * Test retrieve_agent_memory tool can retrieve stored context.
	 */
	public function test_retrieve_agent_memory_retrieval() {
		// First, store a context.
		$store_tool   = $this->registry->get_tool( 'store_agent_context' );
		$store_result = $store_tool->execute(
			array(
				'agent_id'     => 456,
				'context_type' => 'fact',
				'context_data' => array(
					'title'      => 'Test Fact',
					'content'    => 'This is a test fact for retrieval',
					'importance' => 'medium',
					'tags'       => array( 'test', 'fact' ),
				),
			),
			array()
		);

		$this->assertTrue( $store_result['success'], 'Store should succeed' );
		$context_id = $store_result['context_id'];

		// Now retrieve it by context_id.
		$retrieve_tool   = $this->registry->get_tool( 'retrieve_agent_memory' );
		$retrieve_result = $retrieve_tool->execute(
			array(
				'agent_id'   => 456,
				'context_id' => $context_id,
			),
			array()
		);

		$this->assertTrue( $retrieve_result['success'], 'Retrieval should succeed' );
		$this->assertEquals( 1, $retrieve_result['count'], 'Should find 1 context' );
		$this->assertArrayHasKey( 'contexts', $retrieve_result, 'Result should contain contexts array' );
		$this->assertEquals( $context_id, $retrieve_result['contexts'][0]['context_id'], 'Context ID should match' );
		$this->assertEquals( 'Test Fact', $retrieve_result['contexts'][0]['title'], 'Title should match' );
	}

	/**
	 * Test retrieve_agent_memory with query search.
	 */
	public function test_retrieve_agent_memory_query_search() {
		// Store multiple contexts.
		$store_tool = $this->registry->get_tool( 'store_agent_context' );

		$store_tool->execute(
			array(
				'agent_id'     => 789,
				'context_type' => 'learning',
				'context_data' => array(
					'title'      => 'Machine Learning Basics',
					'content'    => 'Introduction to ML algorithms',
					'importance' => 'high',
					'tags'       => array( 'ml', 'learning' ),
				),
			),
			array()
		);

		$store_tool->execute(
			array(
				'agent_id'     => 789,
				'context_type' => 'fact',
				'context_data' => array(
					'title'      => 'WordPress Plugin Development',
					'content'    => 'Best practices for WordPress plugins',
					'importance' => 'medium',
					'tags'       => array( 'wordpress', 'development' ),
				),
			),
			array()
		);

		// Search for "machine learning".
		$retrieve_tool = $this->registry->get_tool( 'retrieve_agent_memory' );
		$result        = $retrieve_tool->execute(
			array(
				'agent_id' => 789,
				'query'    => 'machine learning',
			),
			array()
		);

		$this->assertTrue( $result['success'], 'Search should succeed' );
		$this->assertGreaterThan( 0, $result['count'], 'Should find at least one result' );

		// The ML context should be ranked higher due to title match.
		$first_result = $result['contexts'][0];
		$this->assertStringContainsString( 'Machine Learning', $first_result['title'], 'Should find ML context' );
	}

	/**
	 * Test retrieve_agent_memory with filters.
	 */
	public function test_retrieve_agent_memory_with_filters() {
		$store_tool = $this->registry->get_tool( 'store_agent_context' );

		// Store contexts with different types and importance.
		$store_tool->execute(
			array(
				'agent_id'     => 999,
				'context_type' => 'learning',
				'context_data' => array(
					'title'      => 'Critical Learning',
					'content'    => 'Very important information',
					'importance' => 'critical',
					'tags'       => array( 'important' ),
				),
			),
			array()
		);

		$store_tool->execute(
			array(
				'agent_id'     => 999,
				'context_type' => 'note',
				'context_data' => array(
					'title'      => 'Minor Note',
					'content'    => 'Less important note',
					'importance' => 'low',
					'tags'       => array( 'minor' ),
				),
			),
			array()
		);

		// Filter by importance: critical only.
		$retrieve_tool = $this->registry->get_tool( 'retrieve_agent_memory' );
		$result        = $retrieve_tool->execute(
			array(
				'agent_id' => 999,
				'filters'  => array(
					'importance' => array( 'critical' ),
				),
			),
			array()
		);

		$this->assertTrue( $result['success'], 'Filtered search should succeed' );
		$this->assertEquals( 1, $result['count'], 'Should find only 1 critical context' );
		$this->assertEquals( 'Critical Learning', $result['contexts'][0]['title'], 'Should find critical context' );
	}

	/**
	 * Test that expired contexts are not retrieved by default.
	 */
	public function test_retrieve_agent_memory_expired_contexts() {
		$store_tool = $this->registry->get_tool( 'store_agent_context' );

		// Store a context with very short TTL (1 second).
		$store_result = $store_tool->execute(
			array(
				'agent_id'     => 111,
				'context_type' => 'note',
				'context_data' => array(
					'title'   => 'Expiring Note',
					'content' => 'This will expire soon',
				),
				'ttl'          => 1,
			),
			array()
		);

		$this->assertTrue( $store_result['success'], 'Store should succeed' );
		$context_id = $store_result['context_id'];

		// The store tool clamps TTL to a 1-hour minimum, so a short TTL cannot
		// be used to force expiry. Backdate the stored record instead so the
		// expiry check is deterministic and does not depend on real time.
		$agent_id       = 111;
		$transient_key  = 'mcp_ai_ctx_' . md5( $agent_id . '_' . $context_id );
		$context_record = get_transient( $transient_key );
		$this->assertIsArray( $context_record, 'Stored record should exist before backdating' );
		$context_record['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 10 );
		set_transient( $transient_key, $context_record, HOUR_IN_SECONDS );

		// Try to retrieve - should fail.
		$retrieve_tool = $this->registry->get_tool( 'retrieve_agent_memory' );
		$result        = $retrieve_tool->execute(
			array(
				'agent_id'   => 111,
				'context_id' => $context_id,
			),
			array()
		);

		$this->assertWPError( $result, 'Should not retrieve expired context' );
		$this->assertSame( 'wp_mcp_ai_error', $result->get_error_code() );
	}

	/**
	 * Test capability flags for store_agent_context.
	 */
	public function test_store_agent_context_capability_flags() {
		$tool = $this->registry->get_tool( 'store_agent_context' );

		$this->assertTrue(
			method_exists( $tool, 'get_capability_flags' ),
			'Tool should implement get_capability_flags()'
		);

		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags, 'Capability flags should be an array' );
		$this->assertContains( 'write', $flags, 'Tool should be flagged as write' );
		$this->assertContains( 'state-changing', $flags, 'Tool should be flagged as state-changing' );
		$this->assertContains( 'external-api', $flags, 'Tool may fetch external URLs' );
		$this->assertNotContains( 'read-only', $flags, 'Tool should not be read-only (writes data)' );
		$this->assertContains( 'requires-capability', $flags, 'Tool should require capability' );
	}

	/**
	 * Test capability flags for retrieve_agent_memory.
	 */
	public function test_retrieve_agent_memory_capability_flags() {
		$tool = $this->registry->get_tool( 'retrieve_agent_memory' );

		$this->assertTrue(
			method_exists( $tool, 'get_capability_flags' ),
			'Tool should implement get_capability_flags()'
		);

		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags, 'Capability flags should be an array' );
		$this->assertContains( 'local-only', $flags, 'Tool should be local-only' );
		$this->assertContains( 'read-only', $flags, 'Tool should be read-only' );
		$this->assertContains( 'cacheable', $flags, 'Tool results should be cacheable' );
		$this->assertContains( 'requires-capability', $flags, 'Tool should require capability' );
	}

	/**
	 * Test tool presets include new memory tools.
	 */
	public function test_agent_memory_tools_in_presets() {
		$presets = WP_MCP_AI_Tool_Presets_Helper::get_all_presets();

		// Check agentic_workflow preset includes the memory-management tools.
		$this->assertArrayHasKey( 'agentic_workflow', $presets, 'Agentic workflow preset should exist' );
		$agentic_tools = $presets['agentic_workflow']['tools'];

		$this->assertContains( 'mine_agent_memory', $agentic_tools, 'Preset should include mine_agent_memory' );
		$this->assertContains( 'manage_context_lifecycle', $agentic_tools, 'Preset should include manage_context_lifecycle' );
		$this->assertContains( 'prioritize_context', $agentic_tools, 'Preset should include prioritize_context' );
		$this->assertContains( 'memory_audit_trail', $agentic_tools, 'Preset should include memory_audit_trail' );
	}

	/**
	 * Test that prioritize_context tool is registered (Phase 5.3).
	 */
	public function test_prioritize_context_tool_registered() {
		$tool = $this->registry->get_tool( 'prioritize_context' );
		$this->assertNotNull( $tool, 'prioritize_context tool should be registered' );
		$this->assertInstanceOf(
			'WP_MCP_AI_Tool_Prioritize_Context',
			$tool,
			'Tool should be instance of WP_MCP_AI_Tool_Prioritize_Context'
		);
	}

	/**
	 * Test prioritize_context with basic token budget (Phase 5.3).
	 */
	public function test_prioritize_context_basic_execution() {
		$tool = $this->registry->get_tool( 'prioritize_context' );

		$context_items = array(
			array(
				'context_id' => 'ctx_1',
				'title'      => 'Important fact',
				'content'    => 'This is a very important piece of information that should be prioritized',
				'importance' => 'high',
				'stored_at'  => current_time( 'mysql' ),
				'tags'       => array( 'important', 'fact' ),
			),
			array(
				'context_id' => 'ctx_2',
				'title'      => 'Minor note',
				'content'    => 'Just a small note',
				'importance' => 'low',
				'stored_at'  => gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ),
				'tags'       => array( 'note' ),
			),
			array(
				'context_id' => 'ctx_3',
				'title'      => 'Critical information',
				'content'    => 'This is critical data that must always be included in context',
				'importance' => 'critical',
				'stored_at'  => current_time( 'mysql' ),
				'tags'       => array( 'critical' ),
			),
		);

		$result = $tool->execute(
			array(
				'context_items' => $context_items,
				'token_budget'  => 100,
				'strategy'      => 'importance',
			),
			array()
		);

		$this->assertTrue( $result['success'], 'Prioritization should succeed' );
		$this->assertArrayHasKey( 'prioritized', $result, 'Result should contain prioritized contexts' );
		$this->assertGreaterThan( 0, $result['count'], 'Should select at least one context' );
		$this->assertLessThanOrEqual( $result['budget'], $result['total_tokens'], 'Should not exceed budget' );

		// Critical context should be first with importance strategy.
		$first_context = $result['prioritized'][0];
		$this->assertEquals( 'ctx_3', $first_context['context_id'], 'Critical context should be first' );
	}

	/**
	 * Test prioritize_context with relevance strategy (Phase 5.3).
	 */
	public function test_prioritize_context_relevance_strategy() {
		$tool = $this->registry->get_tool( 'prioritize_context' );

		$context_items = array(
			array(
				'context_id' => 'ctx_1',
				'title'      => 'PHP Programming',
				'content'    => 'Best practices for PHP development',
				'importance' => 'medium',
				'stored_at'  => current_time( 'mysql' ),
				'tags'       => array( 'php', 'programming' ),
			),
			array(
				'context_id' => 'ctx_2',
				'title'      => 'JavaScript Basics',
				'content'    => 'Introduction to JavaScript',
				'importance' => 'medium',
				'stored_at'  => current_time( 'mysql' ),
				'tags'       => array( 'javascript', 'programming' ),
			),
			array(
				'context_id' => 'ctx_3',
				'title'      => 'Python Tips',
				'content'    => 'Advanced Python techniques',
				'importance' => 'medium',
				'stored_at'  => current_time( 'mysql' ),
				'tags'       => array( 'python', 'programming' ),
			),
		);

		$result = $tool->execute(
			array(
				'context_items' => $context_items,
				'token_budget'  => 200,
				'current_task'  => array(
					'query'    => 'PHP programming best practices',
					'keywords' => array( 'PHP', 'programming' ),
				),
				'strategy'      => 'relevance',
			),
			array()
		);

		$this->assertTrue( $result['success'], 'Relevance-based prioritization should succeed' );
		$this->assertGreaterThan( 0, $result['count'], 'Should select contexts' );

		// PHP context should have highest relevance.
		$first_context = $result['prioritized'][0];
		$this->assertEquals( 'ctx_1', $first_context['context_id'], 'PHP context should be most relevant' );
		$this->assertGreaterThan( 0.5, $first_context['score'], 'Relevance score should be high' );
	}

	/**
	 * Test prioritize_context with recency strategy (Phase 5.3).
	 */
	public function test_prioritize_context_recency_strategy() {
		$tool = $this->registry->get_tool( 'prioritize_context' );

		$context_items = array(
			array(
				'context_id' => 'ctx_old',
				'title'      => 'Old context',
				'content'    => 'This is old information',
				'importance' => 'medium',
				'stored_at'  => gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) ),
			),
			array(
				'context_id' => 'ctx_new',
				'title'      => 'New context',
				'content'    => 'This is fresh information',
				'importance' => 'medium',
				'stored_at'  => current_time( 'mysql' ),
			),
		);

		$result = $tool->execute(
			array(
				'context_items' => $context_items,
				'token_budget'  => 100,
				'strategy'      => 'recency',
			),
			array()
		);

		$this->assertTrue( $result['success'], 'Recency-based prioritization should succeed' );

		// New context should be first.
		$first_context = $result['prioritized'][0];
		$this->assertEquals( 'ctx_new', $first_context['context_id'], 'New context should be prioritized' );
	}

	/**
	 * Test prioritize_context with custom weights (Phase 5.3).
	 */
	public function test_prioritize_context_custom_weights() {
		$tool = $this->registry->get_tool( 'prioritize_context' );

		$context_items = array(
			array(
				'context_id' => 'ctx_1',
				'title'      => 'Test context',
				'content'    => 'Test content for custom weights',
				'importance' => 'medium',
				'stored_at'  => current_time( 'mysql' ),
			),
		);

		$result = $tool->execute(
			array(
				'context_items' => $context_items,
				'token_budget'  => 100,
				'strategy'      => 'balanced',
				'weights'       => array(
					'relevance'  => 0.5,
					'importance' => 0.3,
					'recency'    => 0.2,
				),
			),
			array()
		);

		$this->assertTrue( $result['success'], 'Custom weights should work' );
		$this->assertArrayHasKey( 'weights', $result, 'Result should include weights' );
		$this->assertEquals( 0.5, $result['weights']['relevance'], 'Custom relevance weight should be applied' );
	}

	/**
	 * Test prioritize_context respects token budget (Phase 5.3).
	 */
	public function test_prioritize_context_respects_budget() {
		$tool = $this->registry->get_tool( 'prioritize_context' );

		// Create contexts with known token counts.
		$context_items = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$context_items[] = array(
				'context_id' => "ctx_{$i}",
				'title'      => "Context {$i}",
				'content'    => str_repeat( 'word ', 100 ), // ~100 tokens each.
				'importance' => 'medium',
				'stored_at'  => current_time( 'mysql' ),
			);
		}

		$result = $tool->execute(
			array(
				'context_items' => $context_items,
				'token_budget'  => 300, // Should fit ~3 contexts.
				'strategy'      => 'balanced',
			),
			array()
		);

		$this->assertTrue( $result['success'], 'Budget constraint should work' );
		$this->assertLessThanOrEqual( 300, $result['total_tokens'], 'Should not exceed budget' );
		$this->assertLessThanOrEqual( 4, $result['count'], 'Should select limited number of contexts' );
		$this->assertGreaterThan( 0, $result['excluded_count'], 'Should exclude some contexts' );
	}

	/**
	 * Test prioritize_context capability flags (Phase 5.3).
	 */
	public function test_prioritize_context_capability_flags() {
		$tool = $this->registry->get_tool( 'prioritize_context' );

		$this->assertTrue(
			method_exists( $tool, 'get_capability_flags' ),
			'Tool should implement get_capability_flags()'
		);

		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags, 'Capability flags should be an array' );
		$this->assertContains( 'read-only', $flags, 'Tool should be read-only' );
		$this->assertContains( 'local-only', $flags, 'Tool should be local-only' );
		$this->assertContains( 'idempotent', $flags, 'Tool should be idempotent' );
		$this->assertContains( 'cacheable', $flags, 'Tool results should be cacheable' );
		$this->assertContains( 'requires-capability', $flags, 'Tool should require capability' );
		$this->assertNotContains( 'network-dependent', $flags, 'Tool should not use network' );
		$this->assertNotContains( 'state-changing', $flags, 'Tool should not modify data' );
	}

	/**
	 * Test Agent Context Manager service exists (Phase 5.4).
	 */
	public function test_agent_context_manager_service_exists() {
		$this->assertTrue(
			class_exists( 'WP_MCP_AI_Agent_Context_Manager' ),
			'Agent Context Manager service class should exist'
		);

		$manager = wp_mcp_ai_get_agent_context_manager();
		$this->assertInstanceOf(
			'WP_MCP_AI_Agent_Context_Manager',
			$manager,
			'Helper function should return manager instance'
		);
	}

	/**
	 * Test Agent Context Manager store and retrieve (Phase 5.4).
	 */
	public function test_agent_context_manager_store_retrieve() {
		$manager = wp_mcp_ai_get_agent_context_manager();

		// Store context.
		$agent_id     = 888;
		$context_type = 'learning';
		$context_data = array(
			'title'      => 'Test Learning via Service',
			'content'    => 'This is stored via the context manager service',
			'importance' => 'high',
		);
		$result = $manager->store_context(
			$agent_id,
			$context_type,
			$context_data,
			3600
		);

		$this->assertTrue( $result['success'], 'Store via service should succeed' );
		$this->assertArrayHasKey( 'context_id', $result, 'Should return context_id' );

		// Retrieve context.
		$context = $manager->retrieve_context( 888, $result['context_id'] );
		$this->assertNotNull( $context, 'Should retrieve stored context' );
		$this->assertEquals( 'Test Learning via Service', $context['data']['title'], 'Title should match' );
	}

	/**
	 * Test Agent Context Manager context statistics (Phase 5.4).
	 */
	public function test_agent_context_manager_statistics() {
		$manager = wp_mcp_ai_get_agent_context_manager();

		// Store multiple contexts.
		$manager->store_context(
			777,
			'learning',
			array(
				'title'   => 'L1',
				'content' => 'Learning 1',
			)
		);
		$manager->store_context(
			777,
			'learning',
			array(
				'title'   => 'L2',
				'content' => 'Learning 2',
			)
		);
		$manager->store_context(
			777,
			'fact',
			array(
				'title'   => 'F1',
				'content' => 'Fact 1',
			)
		);

		$stats = $manager->get_context_stats( 777 );

		$this->assertEquals( 3, $stats['total_count'], 'Should count 3 contexts' );
		$this->assertArrayHasKey( 'by_type', $stats, 'Stats should include by_type breakdown' );
		$this->assertEquals( 2, $stats['by_type']['learning'], 'Should have 2 learning contexts' );
		$this->assertEquals( 1, $stats['by_type']['fact'], 'Should have 1 fact context' );
	}

	/**
	 * Test Agent Context Manager session recovery (Phase 5.4).
	 */
	public function test_agent_context_manager_session_recovery() {
		$manager = wp_mcp_ai_get_agent_context_manager();

		// Store contexts for agent.
		$manager->store_context(
			666,
			'learning',
			array(
				'title'   => 'L1',
				'content' => 'Learning 1',
			)
		);
		$manager->store_context(
			666,
			'preference',
			array(
				'title'   => 'P1',
				'content' => 'Preference 1',
			)
		);

		// Recover session.
		$session = $manager->recover_session( 666, 'test_session_123' );

		$this->assertEquals( 666, $session['agent_id'], 'Agent ID should match' );
		$this->assertEquals( 'test_session_123', $session['session_id'], 'Session ID should match' );
		$this->assertGreaterThanOrEqual( 2, $session['context_count'], 'Should have at least 2 contexts' );
		$this->assertArrayHasKey( 'contexts_by_type', $session, 'Should group by type' );
	}

	/**
	 * Test Agent Context Manager clear contexts (Phase 5.4).
	 */
	public function test_agent_context_manager_clear_contexts() {
		$manager = wp_mcp_ai_get_agent_context_manager();

		// Store contexts.
		$manager->store_context(
			555,
			'note',
			array(
				'title'   => 'N1',
				'content' => 'Note 1',
			)
		);
		$manager->store_context(
			555,
			'note',
			array(
				'title'   => 'N2',
				'content' => 'Note 2',
			)
		);

		// Verify they exist.
		$stats_before = $manager->get_context_stats( 555 );
		$this->assertEquals( 2, $stats_before['total_count'], 'Should have 2 contexts before clear' );

		// Clear all contexts.
		$result = $manager->clear_agent_contexts( 555 );

		$this->assertTrue( $result['success'], 'Clear should succeed' );
		$this->assertEquals( 2, $result['deleted'], 'Should delete 2 contexts' );

		// Verify they're gone.
		$stats_after = $manager->get_context_stats( 555 );
		$this->assertEquals( 0, $stats_after['total_count'], 'Should have 0 contexts after clear' );
	}

	/**
	 * Test end-to-end workflow: store, retrieve, prioritize (Phase 5.6).
	 */
	public function test_end_to_end_context_workflow() {
		// Step 1: Store multiple contexts via tool.
		$store_tool = $this->registry->get_tool( 'store_agent_context' );

		$contexts_data = array(
			array(
				'type' => 'learning',
				'data' => array(
					'title'      => 'PHP Coding Standards',
					'content'    => 'Follow WordPress PHP coding standards for best practices',
					'importance' => 'high',
					'tags'       => array( 'php', 'coding', 'standards' ),
				),
			),
			array(
				'type' => 'preference',
				'data' => array(
					'title'      => 'User prefers examples',
					'content'    => 'User frequently requests code examples in explanations',
					'importance' => 'high',
					'tags'       => array( 'preference', 'examples' ),
				),
			),
			array(
				'type' => 'fact',
				'data' => array(
					'title'      => 'WordPress Version Info',
					'content'    => 'Current WordPress version requires PHP 7.4+',
					'importance' => 'medium',
					'tags'       => array( 'wordpress', 'version' ),
				),
			),
		);

		foreach ( $contexts_data as $ctx ) {
			$store_tool->execute(
				array(
					'agent_id'     => 1234,
					'context_type' => $ctx['type'],
					'context_data' => $ctx['data'],
				),
				array()
			);
		}

		// Step 2: Retrieve all contexts.
		$retrieve_tool   = $this->registry->get_tool( 'retrieve_agent_memory' );
		$retrieve_result = $retrieve_tool->execute(
			array(
				'agent_id' => 1234,
				'limit'    => 50,
			),
			array()
		);

		$this->assertTrue( $retrieve_result['success'], 'Retrieve should succeed' );
		$this->assertGreaterThanOrEqual( 3, $retrieve_result['count'], 'Should retrieve all 3 contexts' );

		// Step 3: Prioritize contexts for a specific task.
		$prioritize_tool   = $this->registry->get_tool( 'prioritize_context' );
		$prioritize_result = $prioritize_tool->execute(
			array(
				'context_items' => $retrieve_result['contexts'],
				'token_budget'  => 200,
				'current_task'  => array(
					'query'    => 'PHP coding best practices',
					'keywords' => array( 'PHP', 'coding' ),
					'type'     => 'guidance',
				),
				'strategy'      => 'relevance',
			),
			array()
		);

		$this->assertTrue( $prioritize_result['success'], 'Prioritization should succeed' );
		$this->assertGreaterThan( 0, $prioritize_result['count'], 'Should select contexts' );
		$this->assertLessThanOrEqual( 200, $prioritize_result['total_tokens'], 'Should fit within budget' );

		// PHP Coding Standards should be highly relevant.
		$found_php_context = false;
		foreach ( $prioritize_result['prioritized'] as $context ) {
			if ( strpos( $context['title'], 'PHP Coding Standards' ) !== false ) {
				$found_php_context = true;
				$this->assertGreaterThan( 0.5, $context['score'], 'PHP context should have high relevance' );
				break;
			}
		}
		$this->assertTrue( $found_php_context, 'PHP context should be in prioritized results' );
	}
}
