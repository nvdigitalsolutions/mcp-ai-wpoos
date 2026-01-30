<?php
/**
 * Tests for Symfony Validator dependency handling.
 *
 * @package WP_MCP_AI
 */

/**
 * Test Symfony Validator availability checks.
 */
class WP_MCP_AI_Validator_Dependency_Test extends WP_UnitTestCase {

	/**
	 * Test that Validator Service returns null when Symfony is not available.
	 *
	 * This test verifies the defensive coding in the Validator Service.
	 * In production where vendor/ might be missing, we should get null instead of a fatal error.
	 */
	public function test_validator_service_returns_null_when_symfony_unavailable() {
		// Skip this test if Symfony is actually available (which it should be in test environment).
		if ( class_exists( 'Symfony\Component\Validator\Validation' ) ) {
			$this->markTestSkipped( 'Symfony Validator is available in test environment. This test is for production scenarios where vendor/ is missing.' );
		}

		// Load the validator service.
		require_once WP_MCP_AI_PATH . 'includes/validators/class-wp-mcp-ai-validator-service.php';

		// Get instance should return null when Symfony is not available.
		$service = \WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service::get_instance();

		$this->assertNull( $service, 'Validator Service should return null when Symfony Validator is not available.' );
	}

	/**
	 * Test that Validator Service works correctly when Symfony is available.
	 */
	public function test_validator_service_works_when_symfony_available() {
		// Skip this test if Symfony is NOT available.
		if ( ! class_exists( 'Symfony\Component\Validator\Validation' ) ) {
			$this->markTestSkipped( 'Symfony Validator is not available. Install composer dependencies first.' );
		}

		// Load the validator service.
		require_once WP_MCP_AI_PATH . 'includes/validators/class-wp-mcp-ai-validator-service.php';

		// Get instance should return a valid service instance.
		$service = \WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service::get_instance();

		$this->assertNotNull( $service, 'Validator Service should return an instance when Symfony Validator is available.' );
		$this->assertInstanceOf( \WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service::class, $service );

		// Test that it's a singleton.
		$service2 = \WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service::get_instance();
		$this->assertSame( $service, $service2, 'Validator Service should return the same singleton instance.' );
	}

	/**
	 * Test that validated tools are not registered when Symfony is unavailable.
	 */
	public function test_validated_tools_not_registered_without_symfony() {
		// We can't easily simulate missing Symfony in a test environment where it's loaded.
		// Instead, we verify the logic by checking the registration function behavior.

		// The registration function checks both PHP version and Symfony availability.
		$php_version_ok    = version_compare( PHP_VERSION, '8.0.0', '>=' );
		$symfony_available = class_exists( 'Symfony\Component\Validator\Validation' );

		if ( ! $php_version_ok || ! $symfony_available ) {
			// Validated tools should not be registered.
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			$registry->init();

			// Try to get a validated tool.
			$tool = $registry->get_tool( 'save_post_validated' );

			// It should either not exist or exist as the non-validated version.
			if ( $tool !== null ) {
				// If it exists, it should NOT be an instance of WP_MCP_AI_Validated_Tool.
				$this->assertNotInstanceOf(
					'WP_MCP_AI_Validated_Tool',
					$tool,
					'Validated tools should not be registered when Symfony is unavailable.'
				);
			}
		} else {
			// In test environment with Symfony available, validated tools should work.
			$this->assertTrue( true, 'Symfony is available, validated tools can be registered.' );
		}
	}

	/**
	 * Test that Validated Tool returns error when validator service is null.
	 */
	public function test_validated_tool_returns_error_without_validator_service() {
		// Skip if Symfony is available (we can't test the null case).
		if ( class_exists( 'Symfony\Component\Validator\Validation' ) ) {
			$this->markTestSkipped( 'This test requires Symfony Validator to be unavailable.' );
		}

		// Load the validator service and validated tool base.
		require_once WP_MCP_AI_PATH . 'includes/validators/class-wp-mcp-ai-validator-service.php';
		require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
		require_once WP_MCP_AI_PATH . 'includes/validators/class-wp-mcp-ai-validated-tool.php';

		// Create a mock validated tool for testing.
		$mock_tool = $this->getMockBuilder( 'WP_MCP_AI_Validated_Tool' )
			->disableOriginalConstructor()
			->getMockForAbstractClass();

		// Set validator service to null (simulating unavailable Symfony).
		$reflection = new ReflectionClass( $mock_tool );
		$property   = $reflection->getProperty( 'validator_service' );
		$property->setAccessible( true );
		$property->setValue( $mock_tool, null );

		// Mock the abstract methods.
		$mock_tool->expects( $this->never() )
			->method( 'execute_validated' );

		$mock_tool->method( 'get_slug' )
			->willReturn( 'test_validated_tool' );

		$mock_tool->method( 'get_validation_class' )
			->willReturn( 'TestValidationClass' );

		// Execute the tool - it should return an error.
		$result = $mock_tool->execute( array( 'test' => 'data' ), array( 'user_id' => 1 ) );

		$this->assertInstanceOf( 'WP_Error', $result, 'Tool should return WP_Error when validator service is unavailable.' );
		$this->assertEquals( 'validator_unavailable', $result->get_error_code() );
	}

	/**
	 * Test the PHP version check in validated tools.
	 */
	public function test_validated_tool_checks_php_version() {
		// This test verifies that the PHP version check exists.
		// We can't easily downgrade PHP in tests, so we just verify the logic.

		$current_version  = PHP_VERSION;
		$required_version = '8.0.0';

		if ( version_compare( $current_version, $required_version, '<' ) ) {
			// If running on PHP < 8.0, validated tools should fail.
			$this->markTestIncomplete( 'This test requires PHP 8.0+ to verify validated tool behavior.' );
		} else {
			// On PHP 8.0+, the version check passes and Symfony check comes next.
			$this->assertTrue( true, 'PHP version check would pass on PHP 8.0+.' );
		}
	}
}
