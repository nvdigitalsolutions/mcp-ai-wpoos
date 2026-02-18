<?php
/**
 * Tests for Agent Memory Stats Cache Invalidation
 *
 * Verifies that the agent memory statistics cache is properly
 * invalidated when contexts are stored, updated, or deleted.
 *
 * @package WP_MCP_AI
 */

/**
 * Test agent memory stats cache functionality.
 */
class Test_Orchestration_Memory_Stats_Cache extends WP_UnitTestCase {

	/**
	 * Instance of the dashboard class
	 *
	 * @var WP_MCP_AI_Admin_Orchestration_Dashboard
	 */
	private $dashboard;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Set up an admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Initialize the dashboard class.
		if ( class_exists( 'WP_MCP_AI_Admin_Orchestration_Dashboard' ) ) {
			$this->dashboard = new WP_MCP_AI_Admin_Orchestration_Dashboard();
		} else {
			$this->markTestSkipped( 'WP_MCP_AI_Admin_Orchestration_Dashboard class not available' );
		}

		// Clear any existing cache before tests.
		delete_transient( 'wp_mcp_ai_agent_memory_stats' );
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		// Clean up transients.
		delete_transient( 'wp_mcp_ai_agent_memory_stats' );
		parent::tearDown();
	}

	/**
	 * Test AJAX refresh memory stats action is registered.
	 */
	public function test_ajax_refresh_memory_stats_registered() {
		$this->assertTrue(
			has_action( 'wp_ajax_wp_mcp_ai_refresh_memory_stats' ),
			'AJAX action wp_ajax_wp_mcp_ai_refresh_memory_stats should be registered'
		);
	}

	/**
	 * Test cache is created when stats are retrieved.
	 */
	public function test_cache_created_on_stats_retrieval() {
		// Use reflection to access the protected method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_agent_memory_stats' );
		$method->setAccessible( true );

		// First call should create cache.
		$stats1 = $method->invoke( $this->dashboard );
		$cached = get_transient( 'wp_mcp_ai_agent_memory_stats' );

		$this->assertNotFalse( $cached, 'Cache should be created after first stats retrieval' );
		$this->assertIsArray( $cached, 'Cached data should be an array' );
		$this->assertArrayHasKey( 'total_contexts', $cached );
		$this->assertArrayHasKey( 'total_agents', $cached );
		$this->assertArrayHasKey( 'contexts_by_type', $cached );
	}

	/**
	 * Test cache is used on subsequent calls.
	 */
	public function test_cache_used_on_subsequent_calls() {
		// Use reflection to access the protected method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_agent_memory_stats' );
		$method->setAccessible( true );

		// First call creates cache.
		$stats1 = $method->invoke( $this->dashboard );

		// Manually modify cache to verify it's being used.
		$modified_cache = array(
			'total_contexts'   => 999,
			'total_agents'     => 999,
			'contexts_by_type' => array( 'test' => 999 ),
		);
		set_transient( 'wp_mcp_ai_agent_memory_stats', $modified_cache, 5 * MINUTE_IN_SECONDS );

		// Second call should return modified cache.
		$stats2 = $method->invoke( $this->dashboard );

		$this->assertEquals( 999, $stats2['total_contexts'], 'Should return cached value' );
		$this->assertEquals( 999, $stats2['total_agents'], 'Should return cached value' );
	}

	/**
	 * Test cache is invalidated when context is stored.
	 */
	public function test_cache_invalidated_on_context_store() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Store_Agent_Context' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Store_Agent_Context class not available' );
		}

		// Create initial cache.
		set_transient( 'wp_mcp_ai_agent_memory_stats', array( 'test' => 'data' ), 5 * MINUTE_IN_SECONDS );

		// Store a context.
		$tool = new WP_MCP_AI_Tool_Store_Agent_Context();
		$tool->execute(
			array(
				'agent_id'     => 123,
				'context_type' => 'note',
				'context_data' => array(
					'title'   => 'Test Context',
					'content' => 'Test content',
				),
			),
			array()
		);

		// Cache should be invalidated.
		$cached = get_transient( 'wp_mcp_ai_agent_memory_stats' );
		$this->assertFalse( $cached, 'Cache should be invalidated after storing context' );
	}

	/**
	 * Test cache is invalidated when context is deleted.
	 */
	public function test_cache_invalidated_on_context_delete() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Manage_Context_Lifecycle' ) || ! class_exists( 'WP_MCP_AI_Tool_Store_Agent_Context' ) ) {
			$this->markTestSkipped( 'Required classes not available' );
		}

		// First, store a context.
		$store_tool = new WP_MCP_AI_Tool_Store_Agent_Context();
		$result     = $store_tool->execute(
			array(
				'agent_id'     => 456,
				'context_type' => 'fact',
				'context_data' => array(
					'title'   => 'Test Fact',
					'content' => 'Test fact content',
				),
			),
			array()
		);

		$context_id = $result['context_id'];

		// Create cache.
		set_transient( 'wp_mcp_ai_agent_memory_stats', array( 'test' => 'data' ), 5 * MINUTE_IN_SECONDS );

		// Delete the context.
		$manage_tool = new WP_MCP_AI_Tool_Manage_Context_Lifecycle();
		$manage_tool->execute(
			array(
				'agent_id'   => 456,
				'context_id' => $context_id,
				'action'     => 'delete',
			),
			array()
		);

		// Cache should be invalidated.
		$cached = get_transient( 'wp_mcp_ai_agent_memory_stats' );
		$this->assertFalse( $cached, 'Cache should be invalidated after deleting context' );
	}

	/**
	 * Test cache is invalidated when context is updated.
	 */
	public function test_cache_invalidated_on_context_update() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Manage_Context_Lifecycle' ) || ! class_exists( 'WP_MCP_AI_Tool_Store_Agent_Context' ) ) {
			$this->markTestSkipped( 'Required classes not available' );
		}

		// First, store a context.
		$store_tool = new WP_MCP_AI_Tool_Store_Agent_Context();
		$result     = $store_tool->execute(
			array(
				'agent_id'     => 789,
				'context_type' => 'learning',
				'context_data' => array(
					'title'   => 'Test Learning',
					'content' => 'Initial content',
				),
			),
			array()
		);

		$context_id = $result['context_id'];

		// Create cache.
		set_transient( 'wp_mcp_ai_agent_memory_stats', array( 'test' => 'data' ), 5 * MINUTE_IN_SECONDS );

		// Update the context.
		$manage_tool = new WP_MCP_AI_Tool_Manage_Context_Lifecycle();
		$manage_tool->execute(
			array(
				'agent_id'   => 789,
				'context_id' => $context_id,
				'action'     => 'update',
				'updates'    => array(
					'data.content' => 'Updated content',
				),
			),
			array()
		);

		// Cache should be invalidated.
		$cached = get_transient( 'wp_mcp_ai_agent_memory_stats' );
		$this->assertFalse( $cached, 'Cache should be invalidated after updating context' );
	}

	/**
	 * Test AJAX refresh endpoint clears cache and returns fresh stats.
	 */
	public function test_ajax_refresh_clears_cache() {
		// Set up nonce.
		$_POST['nonce'] = wp_create_nonce( 'wp_mcp_ai_orchestration' );

		// Create stale cache.
		set_transient(
			'wp_mcp_ai_agent_memory_stats',
			array(
				'total_contexts'   => 999,
				'total_agents'     => 999,
				'contexts_by_type' => array( 'test' => 999 ),
			),
			5 * MINUTE_IN_SECONDS
		);

		// Capture AJAX output.
		try {
			$this->_handleAjax( 'wp_mcp_ai_refresh_memory_stats' );
		} catch ( WPAjaxDieContinueException $e ) {
			// Expected exception, check response.
		}

		$response = json_decode( $this->_last_response, true );

		$this->assertTrue( $response['success'], 'AJAX request should succeed' );
		$this->assertArrayHasKey( 'stats', $response['data'], 'Response should contain stats' );
		$this->assertArrayHasKey( 'total_contexts', $response['data']['stats'] );
		$this->assertArrayHasKey( 'total_agents', $response['data']['stats'] );
		$this->assertArrayHasKey( 'contexts_by_type', $response['data']['stats'] );
	}

	/**
	 * Test stats structure is correct.
	 */
	public function test_stats_structure() {
		// Use reflection to access the protected method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_agent_memory_stats' );
		$method->setAccessible( true );

		$stats = $method->invoke( $this->dashboard );

		$this->assertIsArray( $stats, 'Stats should be an array' );
		$this->assertArrayHasKey( 'total_contexts', $stats );
		$this->assertArrayHasKey( 'total_agents', $stats );
		$this->assertArrayHasKey( 'contexts_by_type', $stats );
		$this->assertIsInt( $stats['total_contexts'] );
		$this->assertIsInt( $stats['total_agents'] );
		$this->assertIsArray( $stats['contexts_by_type'] );
	}
}
