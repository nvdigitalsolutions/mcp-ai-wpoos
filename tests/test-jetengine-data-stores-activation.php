<?php
/**
 * Tests for automatic JetEngine data stores module activation.
 *
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

require_once __DIR__ . '/helpers/jetengine-stubs.php';

/**
 * Test class for JetEngine data stores activation.
 */
class WP_MCP_AI_JetEngine_Data_Stores_Activation_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset the shared stub so jet_engine() lazily creates a fresh mock.
		wp_mcp_ai_jetengine_stub_reset();
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		wp_mcp_ai_jetengine_stub_reset();
		parent::tearDown();
	}

	/**
	 * Test that data stores module is activated when JetEngine is available.
	 */
	public function test_data_stores_module_is_activated_when_jetengine_available() {
		$engine = jet_engine();

		// Ensure data stores is not active initially.
		$this->assertFalse( $engine->modules->is_module_active( 'data-stores' ) );

		// Call the activation method.
		WP_MCP_AI_JetEngine_CCT::maybe_enable_data_stores();

		// Verify data stores is now active.
		$this->assertTrue( $engine->modules->is_module_active( 'data-stores' ) );
	}

	/**
	 * Test that activation is idempotent (doesn't activate twice).
	 */
	public function test_data_stores_activation_is_idempotent() {
		$engine = jet_engine();

		// Activate once.
		WP_MCP_AI_JetEngine_CCT::maybe_enable_data_stores();
		$this->assertTrue( $engine->modules->is_module_active( 'data-stores' ) );

		// Get count of active modules.
		$active_count_before = count( $engine->modules->get_active_modules() );

		// Call activation again.
		WP_MCP_AI_JetEngine_CCT::maybe_enable_data_stores();

		// Verify it's still active but not duplicated.
		$this->assertTrue( $engine->modules->is_module_active( 'data-stores' ) );
		$this->assertEquals( $active_count_before, count( $engine->modules->get_active_modules() ) );
	}

	/**
	 * Test that nothing happens if data stores module doesn't exist.
	 */
	public function test_activation_gracefully_handles_missing_module() {
		$engine = jet_engine();

		// Remove the data stores module.
		$reflection = new ReflectionProperty( Jet_Engine_Modules::class, 'modules' );
		$reflection->setAccessible( true );
		$modules = $reflection->getValue( $engine->modules );
		unset( $modules['data-stores'] );
		$reflection->setValue( $engine->modules, $modules );

		// This should not throw an error.
		WP_MCP_AI_JetEngine_CCT::maybe_enable_data_stores();

		// Verify data stores is not active.
		$this->assertFalse( $engine->modules->is_module_active( 'data-stores' ) );
	}
}
