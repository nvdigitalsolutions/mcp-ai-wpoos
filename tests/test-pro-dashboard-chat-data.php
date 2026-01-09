<?php
/**
 * Pro Dashboard Chat Data Tests
 *
 * Tests that chat data is properly included in the Pro Dashboard.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Pro Dashboard chat data functionality.
 */
class Test_Pro_Dashboard_Chat_Data extends WP_UnitTestCase {

	/**
	 * Pro Dashboard instance.
	 *
	 * @var WP_MCP_AI_Pro_Dashboard
	 */
	private $dashboard;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure Pro Dashboard class is available.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Dashboard' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-pro-dashboard.php';
		}

		$this->dashboard = WP_MCP_AI_Pro_Dashboard::get_instance();
	}

	/**
	 * Test that chat data is included in chart data.
	 */
	public function test_chat_data_included_in_chart_data() {
		// Use reflection to call the private get_chart_data method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_chart_data' );
		$method->setAccessible( true );

		$chart_data = $method->invoke( $this->dashboard );

		// Assert that chartData key exists.
		$this->assertArrayHasKey( 'chatData', $chart_data, 'Chart data should include chatData key' );

		// Assert that chatData has the expected structure.
		$chat_data = $chart_data['chatData'];
		$this->assertIsArray( $chat_data, 'chatData should be an array' );
		$this->assertArrayHasKey( 'total_conversations', $chat_data, 'chatData should have total_conversations' );
		$this->assertArrayHasKey( 'active_users', $chat_data, 'chatData should have active_users' );
		$this->assertArrayHasKey( 'today_conversations', $chat_data, 'chatData should have today_conversations' );
		$this->assertArrayHasKey( 'this_week_conversations', $chat_data, 'chatData should have this_week_conversations' );
		$this->assertArrayHasKey( 'top_tools', $chat_data, 'chatData should have top_tools' );
		$this->assertArrayHasKey( 'top_providers', $chat_data, 'chatData should have top_providers' );
		$this->assertArrayHasKey( 'top_models', $chat_data, 'chatData should have top_models' );
		$this->assertArrayHasKey( 'total_tokens_used', $chat_data, 'chatData should have total_tokens_used' );
		$this->assertArrayHasKey( 'total_cost', $chat_data, 'chatData should have total_cost' );

		// Assert that values are integers.
		$this->assertIsInt( $chat_data['total_conversations'], 'total_conversations should be an integer' );
		$this->assertIsInt( $chat_data['active_users'], 'active_users should be an integer' );
		$this->assertIsInt( $chat_data['today_conversations'], 'today_conversations should be an integer' );
		$this->assertIsInt( $chat_data['this_week_conversations'], 'this_week_conversations should be an integer' );
		$this->assertIsInt( $chat_data['total_tokens_used'], 'total_tokens_used should be an integer' );

		// Assert that arrays are arrays.
		$this->assertIsArray( $chat_data['top_tools'], 'top_tools should be an array' );
		$this->assertIsArray( $chat_data['top_providers'], 'top_providers should be an array' );
		$this->assertIsArray( $chat_data['top_models'], 'top_models should be an array' );

		// Assert that values are non-negative.
		$this->assertGreaterThanOrEqual( 0, $chat_data['total_conversations'], 'total_conversations should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $chat_data['active_users'], 'active_users should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $chat_data['today_conversations'], 'today_conversations should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $chat_data['this_week_conversations'], 'this_week_conversations should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $chat_data['total_tokens_used'], 'total_tokens_used should be non-negative' );
		$this->assertGreaterThanOrEqual( 0, $chat_data['total_cost'], 'total_cost should be non-negative' );
	}

	/**
	 * Test that get_chat_data returns proper structure when transcript table doesn't exist.
	 */
	public function test_get_chat_data_without_transcript_table() {
		// Use reflection to call the private get_chat_data method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_chat_data' );
		$method->setAccessible( true );

		$chat_data = $method->invoke( $this->dashboard );

		// Assert structure.
		$this->assertIsArray( $chat_data, 'Chat data should be an array' );
		$this->assertArrayHasKey( 'total_conversations', $chat_data );
		$this->assertArrayHasKey( 'active_users', $chat_data );
		$this->assertArrayHasKey( 'today_conversations', $chat_data );
		$this->assertArrayHasKey( 'this_week_conversations', $chat_data );
		$this->assertArrayHasKey( 'top_tools', $chat_data );
		$this->assertArrayHasKey( 'top_providers', $chat_data );
		$this->assertArrayHasKey( 'top_models', $chat_data );
		$this->assertArrayHasKey( 'total_tokens_used', $chat_data );
		$this->assertArrayHasKey( 'total_cost', $chat_data );

		// Without a transcript table, all values should be 0.
		$this->assertEquals( 0, $chat_data['total_conversations'], 'Should be 0 without transcript table' );
		$this->assertEquals( 0, $chat_data['active_users'], 'Should be 0 without transcript table' );
		$this->assertEquals( 0, $chat_data['today_conversations'], 'Should be 0 without transcript table' );
		$this->assertEquals( 0, $chat_data['this_week_conversations'], 'Should be 0 without transcript table' );
	}

	/**
	 * Test that chat data filter works.
	 */
	public function test_chat_data_filter() {
		// Add filter to modify chat data.
		$filter_applied = false;
		add_filter(
			'wp_mcp_ai_pro_dashboard_chat_data',
			function ( $chat_data ) use ( &$filter_applied ) {
				$filter_applied             = true;
				$chat_data['custom_metric'] = 123;
				return $chat_data;
			}
		);

		// Use reflection to call the private get_chat_data method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_chat_data' );
		$method->setAccessible( true );

		$chat_data = $method->invoke( $this->dashboard );

		// Assert filter was applied.
		$this->assertTrue( $filter_applied, 'Filter should have been applied' );
		$this->assertArrayHasKey( 'custom_metric', $chat_data, 'Filter should add custom metric' );
		$this->assertEquals( 123, $chat_data['custom_metric'], 'Custom metric value should match' );
	}

	/**
	 * Test that chart data maintains backward compatibility.
	 */
	public function test_chart_data_backward_compatibility() {
		// Use reflection to call the private get_chart_data method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'get_chart_data' );
		$method->setAccessible( true );

		$chart_data = $method->invoke( $this->dashboard );

		// Assert original keys still exist.
		$this->assertArrayHasKey( 'controls', $chart_data, 'Chart data should still include controls' );
		$this->assertArrayHasKey( 'risks', $chart_data, 'Chart data should still include risks' );
		$this->assertArrayHasKey( 'metrics', $chart_data, 'Chart data should still include metrics' );

		// Assert controls structure.
		$controls = $chart_data['controls'];
		$this->assertArrayHasKey( 'implemented', $controls );
		$this->assertArrayHasKey( 'partial', $controls );
		$this->assertArrayHasKey( 'planned', $controls );
		$this->assertArrayHasKey( 'not_applicable', $controls );
		$this->assertArrayHasKey( 'total', $controls );

		// Assert risks structure.
		$risks = $chart_data['risks'];
		$this->assertArrayHasKey( 'critical', $risks );
		$this->assertArrayHasKey( 'high', $risks );
		$this->assertArrayHasKey( 'medium', $risks );
		$this->assertArrayHasKey( 'low', $risks );

		// Assert metrics structure.
		$metrics = $chart_data['metrics'];
		$this->assertArrayHasKey( 'incidents', $metrics );
		$this->assertArrayHasKey( 'vulnerabilities_fixed', $metrics );
	}

	/**
	 * Test that render_chat_statistics method exists and is callable.
	 */
	public function test_render_chat_statistics_method_exists() {
		$reflection = new ReflectionClass( $this->dashboard );
		$this->assertTrue(
			$reflection->hasMethod( 'render_chat_statistics' ),
			'Pro Dashboard should have render_chat_statistics method'
		);
	}

	/**
	 * Test that render_chat_statistics produces output.
	 */
	public function test_render_chat_statistics_produces_output() {
		// Use reflection to call the private render_chat_statistics method.
		$reflection = new ReflectionClass( $this->dashboard );
		$method     = $reflection->getMethod( 'render_chat_statistics' );
		$method->setAccessible( true );

		ob_start();
		$method->invoke( $this->dashboard );
		$output = ob_get_clean();

		// Assert output is not empty.
		$this->assertNotEmpty( $output, 'render_chat_statistics should produce output' );

		// Assert output contains expected elements.
		$this->assertStringContainsString( 'wp-mcp-ai-chat-stats-grid', $output, 'Output should contain chat stats grid' );
		$this->assertStringContainsString( 'wp-mcp-ai-chat-stat-card', $output, 'Output should contain stat cards' );
		$this->assertStringContainsString( 'Total Conversations', $output, 'Output should contain Total Conversations label' );
		$this->assertStringContainsString( 'Active Users', $output, 'Output should contain Active Users label' );
		$this->assertStringContainsString( 'Today', $output, 'Output should contain Today label' );
		$this->assertStringContainsString( 'This Week', $output, 'Output should contain This Week label' );

		// Assert styling is included.
		$this->assertStringContainsString( '<style>', $output, 'Output should include CSS styling' );
	}
}
