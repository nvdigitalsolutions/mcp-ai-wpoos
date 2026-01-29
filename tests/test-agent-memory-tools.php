<?php
/**
 * Test DeepSeek V4 Agent Memory Tools
 *
 * Validates that the agent memory tools (store_agent_context and
 * retrieve_agent_memory) are properly implemented and functional.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
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
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
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
		$this->assertFalse( $result['success'], 'Should fail without agent_id' );

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
		$this->assertFalse( $result['success'], 'Should fail without context_type' );

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
		$this->assertFalse( $result['success'], 'Should fail without context title' );
	}

	/**
	 * Test retrieve_agent_memory tool can retrieve stored context.
	 */
	public function test_retrieve_agent_memory_retrieval() {
		// First, store a context.
		$store_tool = $this->registry->get_tool( 'store_agent_context' );
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
		$retrieve_tool = $this->registry->get_tool( 'retrieve_agent_memory' );
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
		$result = $retrieve_tool->execute(
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
		$result = $retrieve_tool->execute(
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

		// Wait for expiration.
		sleep( 2 );

		// Try to retrieve - should fail.
		$retrieve_tool = $this->registry->get_tool( 'retrieve_agent_memory' );
		$result = $retrieve_tool->execute(
			array(
				'agent_id'   => 111,
				'context_id' => $context_id,
			),
			array()
		);

		$this->assertFalse( $result['success'], 'Should not retrieve expired context' );
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
		$this->assertTrue( $flags['safe'], 'Tool should be safe' );
		$this->assertTrue( $flags['local-only'], 'Tool should be local-only' );
		$this->assertFalse( $flags['read-only'], 'Tool should not be read-only (writes data)' );
		$this->assertTrue( $flags['requires-auth'], 'Tool should require authentication' );
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
		$this->assertTrue( $flags['safe'], 'Tool should be safe' );
		$this->assertTrue( $flags['local-only'], 'Tool should be local-only' );
		$this->assertTrue( $flags['read-only'], 'Tool should be read-only' );
		$this->assertTrue( $flags['cacheable'], 'Tool results should be cacheable' );
		$this->assertTrue( $flags['requires-auth'], 'Tool should require authentication' );
	}

	/**
	 * Test tool presets include new memory tools.
	 */
	public function test_agent_memory_tools_in_presets() {
		$presets = WP_MCP_AI_Tool_Presets_Helper::get_all_presets();

		// Check agentic_workflow preset includes new tools.
		$this->assertArrayHasKey( 'agentic_workflow', $presets, 'Agentic workflow preset should exist' );
		$agentic_tools = $presets['agentic_workflow']['tools'];

		$this->assertContains( 'store_agent_context', $agentic_tools, 'Preset should include store_agent_context' );
		$this->assertContains( 'retrieve_agent_memory', $agentic_tools, 'Preset should include retrieve_agent_memory' );
	}
}
