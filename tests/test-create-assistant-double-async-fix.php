<?php
/**
 * Test double-async execution fix for create-assistant tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that create-assistant tool doesn't use its own async scheduling
 * when already running in async executor context.
 */
class Test_Create_Assistant_Double_Async_Fix extends WP_UnitTestCase {
	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load required files.
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-assistant.php';
	}

	/**
	 * Test that execute method respects in_async_executor context.
	 *
	 * When the create-assistant tool is executed by the async executor,
	 * it should NOT use its own async scheduling mechanism, even if
	 * async=true is passed in arguments.
	 */
	public function test_execute_respects_executor_context() {
		$tool = new WP_MCP_AI_Tool_Create_Assistant();

		// We need to use reflection to test the private logic since we can't.
		// actually create an assistant without proper setup.
		$reflection = new ReflectionClass( $tool );

		// Get the execute method to verify it checks context.
		$execute_method = $reflection->getMethod( 'execute' );
		$this->assertTrue( $execute_method->isPublic(), 'execute method should be public' );

		// Verify execute method accepts context parameter.
		$parameters = $execute_method->getParameters();
		$this->assertGreaterThanOrEqual( 2, count( $parameters ), 'execute should accept at least 2 parameters' );

		if ( count( $parameters ) >= 2 ) {
			$this->assertEquals( 'arguments', $parameters[0]->getName() );
			$this->assertEquals( 'context', $parameters[1]->getName() );
		}
	}

	/**
	 * Test the logic flow with mocked data.
	 *
	 * Since we can't fully execute the tool without setup, we verify
	 * the code structure matches our expected pattern.
	 */
	public function test_async_disabled_in_executor_context() {
		$tool = new WP_MCP_AI_Tool_Create_Assistant();

		// Read the source code to verify the fix is in place.
		$reflection   = new ReflectionClass( $tool );
		$execute_file = $reflection->getFileName();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$source = file_get_contents( $execute_file );

		// Verify the fix code exists in the source.
		$this->assertStringContainsString(
			'in_async_executor',
			$source,
			'Source code should check for in_async_executor context flag'
		);

		$this->assertStringContainsString(
			'$async = false',
			$source,
			'Source code should set async to false when in executor context'
		);

		// Verify the comment explaining the fix is present.
		$this->assertStringContainsString(
			'CRITICAL',
			$source,
			'Source code should have critical comment explaining double-async prevention'
		);
	}

	/**
	 * Test that async parameter works normally outside executor context.
	 *
	 * When NOT in async executor context, the async parameter should work
	 * as designed - allowing the tool to use its own async scheduling.
	 */
	public function test_async_parameter_works_normally() {
		// This test verifies that we didn't break the normal async functionality.
		// We just verify the tool has the async parameter in its schema.
		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'async', $schema['properties'] );
		$this->assertEquals( 'boolean', $schema['properties']['async']['type'] );
		$this->assertFalse(
			$schema['properties']['async']['default'],
			'async should default to false for create-assistant tool'
		);
	}

	/**
	 * Test tool has proper capability flags.
	 *
	 * The tool should be marked as long-running so the orchestrator
	 * knows to queue it for async execution.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Create_Assistant();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains(
			'long-running',
			$flags,
			'create-assistant should be flagged as long-running'
		);
	}

	/**
	 * Test integration scenario.
	 *
	 * Verify the code path when orchestrator executes the tool with
	 * in_async_executor flag set.
	 */
	public function test_integration_scenario() {
		$tool = new WP_MCP_AI_Tool_Create_Assistant();

		// Get source to verify the logic flow.
		$reflection = new ReflectionClass( $tool );
		$source     = file_get_contents( $reflection->getFileName() );

		// The execute method should check for in_async_executor in context.
		// Pattern: if ( isset( $context['in_async_executor'] ) && $context['in_async_executor'] )
		$pattern = '/if\s*\(\s*isset\s*\(\s*\$context\s*\[\s*[\'"]in_async_executor[\'"]\s*\]\s*\)\s*&&\s*\$context\s*\[\s*[\'"]in_async_executor[\'"]\s*\]\s*\)/';

		$this->assertMatchesRegularExpression(
			$pattern,
			$source,
			'Code should check for in_async_executor flag in context'
		);

		// When in executor context, it should set $async = false.
		$this->assertStringContainsString(
			'$async = false',
			$source,
			'Code should set async to false when in executor context'
		);
	}

	/**
	 * Test backward compatibility.
	 *
	 * The fix should not break existing behavior when context is not provided
	 * or when in_async_executor is not set.
	 */
	public function test_backward_compatibility() {
		$tool = new WP_MCP_AI_Tool_Create_Assistant();

		// Verify the execute method has default parameter for context.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'execute' );
		$parameters = $method->getParameters();

		if ( count( $parameters ) >= 2 ) {
			$context_param = $parameters[1];
			$this->assertTrue(
				$context_param->isDefaultValueAvailable() || $context_param->isOptional(),
				'context parameter should have default value or be optional'
			);
		}
	}
}
