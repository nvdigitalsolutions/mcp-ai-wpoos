<?php
/**
 * Tests for mesh router with AI-powered load balancing.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_Mesh_Router
 */
class Test_Mesh_Router extends WP_UnitTestCase {
	/**
	 * Admin user for testing.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Assistant ID for testing.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create a test assistant.
		$this->assistant_id = $this->factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * Test default hub configuration.
	 */
	public function test_default_hub_config() {
		$config = WP_MCP_AI_Mesh_Router::get_hub_config( $this->assistant_id );

		$this->assertIsArray( $config );
		$this->assertEquals( 'ai_optimized', $config['routing_strategy'] );
		$this->assertIsArray( $config['preferred_peers'] );
		$this->assertEmpty( $config['preferred_peers'] );
		$this->assertIsArray( $config['compute_hubs'] );
		$this->assertEmpty( $config['compute_hubs'] );
		$this->assertTrue( $config['enable_retry'] );
		$this->assertEquals( 3, $config['max_retries'] );
	}

	/**
	 * Test updating hub configuration.
	 */
	public function test_update_hub_config() {
		$config = array(
			'routing_strategy' => 'round_robin',
			'preferred_peers'  => array( 'peer1', 'peer2' ),
			'compute_hubs'     => array( 'peer1' ),
			'enable_retry'     => false,
			'max_retries'      => 5,
		);

		$result = WP_MCP_AI_Mesh_Router::update_hub_config( $this->assistant_id, $config );
		$this->assertTrue( $result );

		$saved_config = WP_MCP_AI_Mesh_Router::get_hub_config( $this->assistant_id );
		$this->assertEquals( 'round_robin', $saved_config['routing_strategy'] );
		$this->assertCount( 2, $saved_config['preferred_peers'] );
		$this->assertContains( 'peer1', $saved_config['preferred_peers'] );
		$this->assertContains( 'peer2', $saved_config['preferred_peers'] );
		$this->assertCount( 1, $saved_config['compute_hubs'] );
		$this->assertContains( 'peer1', $saved_config['compute_hubs'] );
		$this->assertFalse( $saved_config['enable_retry'] );
		$this->assertEquals( 5, $saved_config['max_retries'] );
	}

	/**
	 * Test get optimal peer with mesh disabled.
	 */
	public function test_get_optimal_peer_mesh_disabled() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'     => false,
				'mesh_peer_sites' => array(),
			)
		);

		$result = WP_MCP_AI_Mesh_Router::get_optimal_peer( $this->assistant_id, 'Test prompt' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_mesh_disabled', $result->get_error_code() );
	}

	/**
	 * Test get optimal peer with no peers configured.
	 */
	public function test_get_optimal_peer_no_peers() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'     => true,
				'mesh_peer_sites' => array(),
			)
		);

		$result = WP_MCP_AI_Mesh_Router::get_optimal_peer( $this->assistant_id, 'Test prompt' );

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_no_peers', $result->get_error_code() );
	}

	/**
	 * Test AI optimized peer selection logic.
	 */
	public function test_ai_optimized_peer_selection() {
		// Configure mesh with multiple peers.
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'     => true,
				'mesh_peer_sites' => array(
					array(
						'name'    => 'Peer A',
						'url'     => 'https://peer-a.example.com',
						'api_key' => 'mesh_test_key_a',
					),
					array(
						'name'    => 'Peer B',
						'url'     => 'https://peer-b.example.com',
						'api_key' => 'mesh_test_key_b',
					),
					array(
						'name'    => 'Peer C',
						'url'     => 'https://peer-c.example.com',
						'api_key' => 'mesh_test_key_c',
					),
				),
			)
		);

		// Set health metrics to simulate different peer states.
		$health_metrics = array(
			'Peer A' => array(
				'status'            => 'healthy',
				'current_load'      => 5,
				'avg_response_time' => 2.0,
				'success_rate'      => 95,
				'last_update'       => time(),
			),
			'Peer B' => array(
				'status'            => 'healthy',
				'current_load'      => 10,
				'avg_response_time' => 5.0,
				'success_rate'      => 85,
				'last_update'       => time(),
			),
			'Peer C' => array(
				'status'            => 'healthy',
				'current_load'      => 2,
				'avg_response_time' => 1.5,
				'success_rate'      => 98,
				'last_update'       => time(),
			),
		);
		update_option( WP_MCP_AI_Mesh_Router::HEALTH_METRICS_OPTION, $health_metrics, false );

		// Configure assistant with AI optimized routing.
		WP_MCP_AI_Mesh_Router::update_hub_config(
			$this->assistant_id,
			array(
				'routing_strategy' => 'ai_optimized',
				'compute_hubs'     => array(),
			)
		);

		$result = WP_MCP_AI_Mesh_Router::get_optimal_peer( $this->assistant_id, 'Simple question' );

		// Should select Peer C (best response time, lowest load, highest success rate).
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'name', $result );
		$this->assertEquals( 'Peer C', $result['name'] );
	}

	/**
	 * Test round robin peer selection.
	 */
	public function test_round_robin_peer_selection() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'     => true,
				'mesh_peer_sites' => array(
					array(
						'name'    => 'Peer 1',
						'url'     => 'https://peer1.example.com',
						'api_key' => 'mesh_key_1',
					),
					array(
						'name'    => 'Peer 2',
						'url'     => 'https://peer2.example.com',
						'api_key' => 'mesh_key_2',
					),
					array(
						'name'    => 'Peer 3',
						'url'     => 'https://peer3.example.com',
						'api_key' => 'mesh_key_3',
					),
				),
			)
		);

		WP_MCP_AI_Mesh_Router::update_hub_config(
			$this->assistant_id,
			array( 'routing_strategy' => 'round_robin' )
		);

		// First call should select Peer 1.
		$result1 = WP_MCP_AI_Mesh_Router::get_optimal_peer( $this->assistant_id, 'Query 1' );
		$this->assertEquals( 'Peer 1', $result1['name'] );

		// Second call should select Peer 2.
		$result2 = WP_MCP_AI_Mesh_Router::get_optimal_peer( $this->assistant_id, 'Query 2' );
		$this->assertEquals( 'Peer 2', $result2['name'] );

		// Third call should select Peer 3.
		$result3 = WP_MCP_AI_Mesh_Router::get_optimal_peer( $this->assistant_id, 'Query 3' );
		$this->assertEquals( 'Peer 3', $result3['name'] );

		// Fourth call should wrap around to Peer 1.
		$result4 = WP_MCP_AI_Mesh_Router::get_optimal_peer( $this->assistant_id, 'Query 4' );
		$this->assertEquals( 'Peer 1', $result4['name'] );
	}

	/**
	 * Test preferred peer selection with fallback.
	 */
	public function test_preferred_peer_with_fallback() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'     => true,
				'mesh_peer_sites' => array(
					array(
						'name'    => 'Primary',
						'url'     => 'https://primary.example.com',
						'api_key' => 'mesh_key_primary',
					),
					array(
						'name'    => 'Secondary',
						'url'     => 'https://secondary.example.com',
						'api_key' => 'mesh_key_secondary',
					),
					array(
						'name'    => 'Tertiary',
						'url'     => 'https://tertiary.example.com',
						'api_key' => 'mesh_key_tertiary',
					),
				),
			)
		);

		WP_MCP_AI_Mesh_Router::update_hub_config(
			$this->assistant_id,
			array(
				'routing_strategy' => 'preferred_with_fallback',
				'preferred_peers'  => array( 'Primary', 'Secondary' ),
			)
		);

		// Should select Primary (first preferred peer).
		$result = WP_MCP_AI_Mesh_Router::get_optimal_peer( $this->assistant_id, 'Test query' );
		$this->assertEquals( 'Primary', $result['name'] );
	}

	/**
	 * Test compute hub priority for complex tasks.
	 */
	public function test_compute_hub_priority_complex_task() {
		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array(
				'enable_mesh'     => true,
				'mesh_peer_sites' => array(
					array(
						'name'    => 'Regular Site',
						'url'     => 'https://regular.example.com',
						'api_key' => 'mesh_key_regular',
					),
					array(
						'name'    => 'Compute Hub',
						'url'     => 'https://hub.example.com',
						'api_key' => 'mesh_key_hub',
					),
				),
			)
		);

		// Set similar health metrics for both.
		$health_metrics = array(
			'Regular Site' => array(
				'status'            => 'healthy',
				'current_load'      => 5,
				'avg_response_time' => 2.0,
				'success_rate'      => 95,
				'last_update'       => time(),
			),
			'Compute Hub'  => array(
				'status'            => 'healthy',
				'current_load'      => 5,
				'avg_response_time' => 2.0,
				'success_rate'      => 95,
				'last_update'       => time(),
			),
		);
		update_option( WP_MCP_AI_Mesh_Router::HEALTH_METRICS_OPTION, $health_metrics, false );

		// Configure Compute Hub designation.
		WP_MCP_AI_Mesh_Router::update_hub_config(
			$this->assistant_id,
			array(
				'routing_strategy' => 'ai_optimized',
				'compute_hubs'     => array( 'Compute Hub' ),
			)
		);

		// Complex prompt should prefer compute hub.
		$complex_prompt = 'Please provide a comprehensive, detailed analysis of the following complex research topic with in-depth examination of all factors...';
		$result         = WP_MCP_AI_Mesh_Router::get_optimal_peer( $this->assistant_id, $complex_prompt );

		$this->assertEquals( 'Compute Hub', $result['name'] );
	}

	/**
	 * Test health metrics tracking.
	 */
	public function test_health_metrics_tracking() {
		// Clear existing metrics.
		delete_option( WP_MCP_AI_Mesh_Router::HEALTH_METRICS_OPTION );

		// Simulate successful request.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Mesh_Router' );
		$method     = $reflection->getMethod( 'update_health_metrics' );
		$method->setAccessible( true );

		$method->invokeArgs( null, array( 'Test Peer', 1.5, true ) );

		$metrics = get_option( WP_MCP_AI_Mesh_Router::HEALTH_METRICS_OPTION, array() );

		$this->assertArrayHasKey( 'Test Peer', $metrics );
		$this->assertEquals( 1.5, $metrics['Test Peer']['avg_response_time'] );
		$this->assertEquals( 1, $metrics['Test Peer']['success_count'] );
		$this->assertEquals( 0, $metrics['Test Peer']['failure_count'] );
		$this->assertEquals( 100, $metrics['Test Peer']['success_rate'] );
		$this->assertEquals( 'healthy', $metrics['Test Peer']['status'] );

		// Simulate failed request.
		$method->invokeArgs( null, array( 'Test Peer', 5.0, false ) );

		$metrics = get_option( WP_MCP_AI_Mesh_Router::HEALTH_METRICS_OPTION, array() );

		$this->assertEquals( 1, $metrics['Test Peer']['success_count'] );
		$this->assertEquals( 1, $metrics['Test Peer']['failure_count'] );
		$this->assertEquals( 50, $metrics['Test Peer']['success_rate'] );
		$this->assertEquals( 'down', $metrics['Test Peer']['status'] );
	}

	/**
	 * Test health metrics expiration.
	 */
	public function test_health_metrics_expiration() {
		$old_time = time() - 400; // 400 seconds ago (exceeds 300 second max age).

		update_option(
			WP_MCP_AI_Mesh_Router::HEALTH_METRICS_OPTION,
			array(
				'Old Peer'    => array(
					'status'      => 'healthy',
					'last_update' => $old_time,
				),
				'Recent Peer' => array(
					'status'      => 'healthy',
					'last_update' => time(),
				),
			),
			false
		);

		$reflection = new ReflectionClass( 'WP_MCP_AI_Mesh_Router' );
		$method     = $reflection->getMethod( 'get_health_metrics' );
		$method->setAccessible( true );

		$metrics = $method->invoke( null );

		// Old peer should be removed.
		$this->assertArrayNotHasKey( 'Old Peer', $metrics );
		// Recent peer should be retained.
		$this->assertArrayHasKey( 'Recent Peer', $metrics );
	}

	/**
	 * Test prompt complexity analysis.
	 */
	public function test_prompt_complexity_analysis() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Mesh_Router' );
		$method     = $reflection->getMethod( 'analyze_prompt_complexity' );
		$method->setAccessible( true );

		// Simple prompt.
		$simple_score = $method->invokeArgs( null, array( 'What is 2+2?' ) );
		$this->assertLessThanOrEqual( 6, $simple_score );

		// Complex prompt with keywords.
		$complex_prompt = 'Please analyze in detail and provide a comprehensive explanation of this complex topic...';
		$complex_score  = $method->invokeArgs( null, array( $complex_prompt ) );
		$this->assertGreaterThanOrEqual( 7, $complex_score );

		// Long prompt.
		$long_prompt = str_repeat( 'word ', 150 );
		$long_score  = $method->invokeArgs( null, array( $long_prompt ) );
		$this->assertGreaterThanOrEqual( 7, $long_score );

		// Multiple questions.
		$multi_question = 'What is this? How does it work? Why is it important?';
		$multi_score    = $method->invokeArgs( null, array( $multi_question ) );
		$this->assertGreaterThanOrEqual( 6, $multi_score );
	}

	/**
	 * Test query_mesh_intelligent tool registration.
	 */
	public function test_query_mesh_intelligent_tool_registration() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'query_mesh_intelligent' );

		$this->assertNotNull( $tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Query_Mesh_Intelligent', $tool );
		$this->assertEquals( 'query_mesh_intelligent', $tool->get_slug() );
		$this->assertEquals( 'Query Mesh (Intelligent Routing)', $tool->get_name() );
	}

	/**
	 * Test query_mesh_intelligent tool without permission.
	 */
	public function test_query_mesh_intelligent_tool_without_permission() {
		$subscriber_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'query_mesh_intelligent' );

		$result = $tool->execute(
			array( 'prompt' => 'Test query' ),
			array(
				'user_id'      => $subscriber_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test query_mesh_intelligent tool with mesh disabled.
	 */
	public function test_query_mesh_intelligent_tool_mesh_disabled() {
		wp_set_current_user( $this->admin_user_id );

		update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			array( 'enable_mesh' => false )
		);

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'query_mesh_intelligent' );

		$result = $tool->execute(
			array( 'prompt' => 'Test query' ),
			array(
				'user_id'      => $this->admin_user_id,
				'assistant_id' => $this->assistant_id,
			)
		);

		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertEquals( 'wp_mcp_ai_mesh_disabled', $result->get_error_code() );
	}

	/**
	 * Test query_mesh_intelligent tool parameter schema.
	 */
	public function test_query_mesh_intelligent_tool_schema() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'query_mesh_intelligent' );
		$schema   = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'prompt', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'prompt', $schema['required'] );
		// Should NOT require peer_name (unlike query_remote_site).
		$this->assertArrayNotHasKey( 'peer_name', $schema['properties'] );
	}
}
