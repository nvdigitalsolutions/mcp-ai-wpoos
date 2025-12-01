<?php
/**
 * Test double-async execution fix for video generation.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test that video generation tool doesn't use its own async mode
 * when already running in async executor context.
 */
class Test_Veo_Double_Async_Fix extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php';
	}

	/**
	 * Test that should_use_async returns false when in_async_executor is set.
	 *
	 * This prevents double-async execution where:
	 * 1. Orchestrator queues tool async (job_id: async_xxx)
	 * 2. Tool tries to queue another async job (job_id: veo_xxx)
	 * 3. Client receives nested async response and doesn't know how to handle it
	 */
	public function test_should_use_async_respects_executor_context() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'should_use_async' );
		$method->setAccessible( true );

		// Test: Default behavior (no context) should use async.
		$result = $method->invoke( $tool, array(), array() );
		$this->assertTrue( $result, 'Should use async mode by default' );

		// Test: When in async executor context, should NOT use async.
		$context = array( 'in_async_executor' => true );
		$result  = $method->invoke( $tool, array(), $context );
		$this->assertFalse( $result, 'Should NOT use async when already in async executor context' );

		// Test: Even with explicit async=true, should override when in executor context.
		$result = $method->invoke( $tool, array( 'async' => true ), $context );
		$this->assertFalse( $result, 'Should override explicit async=true when in executor context' );

		// Test: Can still disable async explicitly when NOT in executor context.
		$result = $method->invoke( $tool, array( 'async' => false ), array() );
		$this->assertFalse( $result, 'Should respect explicit async=false' );
	}

	/**
	 * Test that context is passed correctly through execute method.
	 */
	public function test_execute_passes_context_to_should_use_async() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		// We can't fully test execute without mocking the video service,.
		// but we can verify that the context is being passed to should_use_async.
		// by checking the method signature accepts context parameter.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'should_use_async' );

		$parameters = $method->getParameters();
		$this->assertCount( 2, $parameters, 'should_use_async should accept 2 parameters' );
		$this->assertEquals( 'arguments', $parameters[0]->getName() );
		$this->assertEquals( 'context', $parameters[1]->getName() );
		$this->assertTrue( $parameters[1]->isOptional(), 'context parameter should be optional' );
	}

	/**
	 * Test that method signature is backward compatible.
	 *
	 * The context parameter should be optional to maintain backward compatibility
	 * with any existing code that calls should_use_async with only arguments.
	 */
	public function test_backward_compatibility() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'should_use_async' );
		$method->setAccessible( true );

		// Test calling with only arguments (old way) - should still work.
		$result = $method->invoke( $tool, array() );
		$this->assertTrue( $result, 'Should work when called with only arguments parameter' );

		// Test calling with both arguments and context (new way).
		$result = $method->invoke( $tool, array(), array() );
		$this->assertTrue( $result, 'Should work when called with both parameters' );
	}

	/**
	 * Test integration scenario: Tool execution in async executor context.
	 *
	 * This simulates what happens when the orchestrator queues the tool for
	 * async execution and the cron job executes it.
	 */
	public function test_integration_async_executor_context() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'should_use_async' );
		$method->setAccessible( true );

		// Simulate the context that async executor adds.
		$context = array(
			'user_id'           => 1,
			'in_async_executor' => true,  // This is the critical flag.
			'assistant_id'      => 123,
		);

		// Even though the tool would normally default to async=true,.
		// it should return false when in async executor context.
		$result = $method->invoke( $tool, array(), $context );
		$this->assertFalse(
			$result,
			'Tool should disable its own async mode when running in async executor to prevent double-async'
		);
	}

	/**
	 * Test that in_async_executor=false doesn't disable async.
	 *
	 * Only the true value should disable async, not just the presence of the key.
	 */
	public function test_in_async_executor_false_allows_async() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'should_use_async' );
		$method->setAccessible( true );

		// Test with in_async_executor explicitly set to false.
		$context = array( 'in_async_executor' => false );
		$result  = $method->invoke( $tool, array(), $context );
		$this->assertTrue( $result, 'Should use async when in_async_executor is false' );

		// Test with in_async_executor set to 0 (falsy but not boolean false).
		$context = array( 'in_async_executor' => 0 );
		$result  = $method->invoke( $tool, array(), $context );
		$this->assertTrue( $result, 'Should use async when in_async_executor is 0' );

		// Test with in_async_executor set to '' (empty string).
		$context = array( 'in_async_executor' => '' );
		$result  = $method->invoke( $tool, array(), $context );
		$this->assertTrue( $result, 'Should use async when in_async_executor is empty string' );
	}

	/**
	 * Test that agentic_loop context forces synchronous execution.
	 *
	 * This prevents the tool from returning a nested async job_id when
	 * the orchestrator has already forced synchronous execution for the
	 * agentic loop. The loop needs actual results to continue the conversation.
	 */
	public function test_should_use_async_respects_agentic_loop_context() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'should_use_async' );
		$method->setAccessible( true );

		// Test: Default behavior (no context) should use async.
		$result = $method->invoke( $tool, array(), array() );
		$this->assertTrue( $result, 'Should use async mode by default' );

		// Test: When in agentic loop context, should NOT use async.
		$context = array( 'agentic_loop' => true );
		$result  = $method->invoke( $tool, array(), $context );
		$this->assertFalse( $result, 'Should NOT use async when in agentic loop context' );

		// Test: Even with explicit async=true, should override when in agentic loop.
		$result = $method->invoke( $tool, array( 'async' => true ), $context );
		$this->assertFalse( $result, 'Should override explicit async=true when in agentic loop' );

		// Test: Can still disable async explicitly when NOT in agentic loop.
		$result = $method->invoke( $tool, array( 'async' => false ), array() );
		$this->assertFalse( $result, 'Should respect explicit async=false' );
	}

	/**
	 * Test agentic loop context priority over in_async_executor.
	 *
	 * The agentic_loop flag should have the same priority as in_async_executor.
	 * Both should prevent async execution.
	 */
	public function test_agentic_loop_and_async_executor_both_prevent_async() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'should_use_async' );
		$method->setAccessible( true );

		// Test with both flags set.
		$context = array(
			'agentic_loop'      => true,
			'in_async_executor' => true,
		);
		$result  = $method->invoke( $tool, array(), $context );
		$this->assertFalse( $result, 'Should NOT use async when both flags are set' );

		// Test with only agentic_loop.
		$context = array( 'agentic_loop' => true );
		$result  = $method->invoke( $tool, array(), $context );
		$this->assertFalse( $result, 'Should NOT use async with only agentic_loop' );

		// Test with only in_async_executor.
		$context = array( 'in_async_executor' => true );
		$result  = $method->invoke( $tool, array(), $context );
		$this->assertFalse( $result, 'Should NOT use async with only in_async_executor' );
	}

	/**
	 * Test that agentic_loop=false doesn't disable async.
	 *
	 * Only the true value should disable async, not just the presence of the key.
	 */
	public function test_agentic_loop_false_allows_async() {
		$tool = new WP_MCP_AI_Tool_Generate_Veo_Video();

		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'should_use_async' );
		$method->setAccessible( true );

		// Test with agentic_loop explicitly set to false.
		$context = array( 'agentic_loop' => false );
		$result  = $method->invoke( $tool, array(), $context );
		$this->assertTrue( $result, 'Should use async when agentic_loop is false' );

		// Test with agentic_loop set to 0 (falsy but not boolean false).
		$context = array( 'agentic_loop' => 0 );
		$result  = $method->invoke( $tool, array(), $context );
		$this->assertTrue( $result, 'Should use async when agentic_loop is 0' );
	}
}
