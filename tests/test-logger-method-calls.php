<?php
/**
 * Test that all logger method calls use correct signatures
 *
 * This test verifies the fix for the fatal error:
 * "Call to undefined method WP_MCP_AI_Logger::log()"
 *
 * @package WP_MCP_AI
 */

/**
 * Test logger method calls
 */
class Test_Logger_Method_Calls extends WP_UnitTestCase {

	/**
	 * Test that WP_MCP_AI_Logger::log_event exists
	 */
	public function test_log_event_method_exists() {
		$this->assertTrue(
			method_exists( 'WP_MCP_AI_Logger', 'log_event' ),
			'WP_MCP_AI_Logger::log_event method should exist'
		);
	}

	/**
	 * Test that WP_MCP_AI_Logger::log does NOT exist
	 */
	public function test_log_method_does_not_exist() {
		$this->assertFalse(
			method_exists( 'WP_MCP_AI_Logger', 'log' ),
			'WP_MCP_AI_Logger::log method should NOT exist (use log_event instead)'
		);
	}

	/**
	 * Test that log_event has correct signature
	 */
	public function test_log_event_signature() {
		$reflection = new ReflectionMethod( 'WP_MCP_AI_Logger', 'log_event' );
		$parameters = $reflection->getParameters();

		$this->assertCount( 3, $parameters, 'log_event should have 3 parameters' );
		$this->assertEquals( 'type', $parameters[0]->getName(), 'First parameter should be $type' );
		$this->assertEquals( 'message', $parameters[1]->getName(), 'Second parameter should be $message' );
		$this->assertEquals( 'context', $parameters[2]->getName(), 'Third parameter should be $context' );
	}

	/**
	 * Test that agent team orchestrator uses correct logger call
	 */
	public function test_agent_team_orchestrator_logger_usage() {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Team_Orchestrator' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Agent_Team_Orchestrator class not available.' );
		}

		$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();
		
		// Call compose_team which internally uses logger
		$task_requirements = array(
			'task_type' => 'content',
		);

		// This should not throw a fatal error anymore
		$result = $orchestrator->compose_team( $task_requirements );

		// If we got this far without a fatal error, the fix works
		$this->assertTrue( true, 'Agent team orchestrator should not throw fatal error on logger call' );
	}

	/**
	 * Test that create_agent_team tool uses correct logger call
	 */
	public function test_create_agent_team_tool_logger_usage() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Create_Agent_Team' ) ) {
			$this->markTestSkipped( 'WP_MCP_AI_Tool_Create_Agent_Team class not available.' );
		}

		$tool = new WP_MCP_AI_Tool_Create_Agent_Team();

		$arguments = array(
			'task_type' => 'content',
			'requirements' => array(
				'expertise_needed' => array( 'marketing' ),
			),
		);

		$context = array(
			'assistant_id' => 1,
			'user_id'      => 1,
		);

		// This should not throw a fatal error anymore
		$result = $tool->execute( $arguments, $context );

		// If we got this far without a fatal error, the fix works
		$this->assertTrue( true, 'Create agent team tool should not throw fatal error on logger call' );
		$this->assertIsArray( $result, 'Result should be an array' );
	}

	/**
	 * Test no files still use WP_MCP_AI_Logger::log() incorrectly
	 */
	public function test_no_files_use_incorrect_logger_call() {
		$plugin_dir = dirname( dirname( __FILE__ ) );
		
		// Files that were fixed
		$fixed_files = array(
			'includes/services/class-wp-mcp-ai-agent-communication-service.php',
			'includes/services/class-wp-mcp-ai-agent-team-orchestrator.php',
			'includes/class-wp-mcp-ai-supplier-security.php',
			'includes/class-wp-mcp-ai-security-training.php',
			'includes/agents/class-wp-mcp-ai-agent-role-base.php',
			'includes/class-wp-mcp-ai-asset-inventory.php',
			'includes/class-wp-mcp-ai-elementor-integration.php',
		);

		foreach ( $fixed_files as $file ) {
			$file_path = $plugin_dir . '/' . $file;
			
			if ( ! file_exists( $file_path ) ) {
				continue;
			}

			$content = file_get_contents( $file_path );
			
			// Check that file doesn't contain "WP_MCP_AI_Logger::log(" without "log_event", "log_error", etc.
			$pattern = '/WP_MCP_AI_Logger::log\s*\(/';
			preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE );

			foreach ( $matches[0] as $match ) {
				$offset = $match[1];
				$context = substr( $content, $offset, 50 );
				
				// Make sure it's not a valid method like log_event, log_error, etc.
				$this->assertMatchesRegularExpression(
					'/log_(event|error|warning|info|debug|critical|chat_interaction|tool_execution)/',
					$context,
					"File {$file} should only use valid logger methods, found: {$context}"
				);
			}
		}
	}
}
