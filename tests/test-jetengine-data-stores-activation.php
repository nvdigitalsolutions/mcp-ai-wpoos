<?php
/**
 * Tests for automatic JetEngine data stores module activation.
 *
 * @package WP_MCP_AI
 */

if ( ! class_exists( 'Jet_Engine_Modules' ) ) {
	/**
	 * Mock JetEngine Modules class.
	 */
	class Jet_Engine_Modules {
		private $active_modules = array();
		private $modules        = array();

		public function __construct() {
			// Mock data stores module.
			$this->modules['data-stores'] = new stdClass();
		}

		public function is_module_active( $module_id ) {
			return in_array( $module_id, $this->active_modules, true );
		}

		public function get_module( $module_id ) {
			return isset( $this->modules[ $module_id ] ) ? $this->modules[ $module_id ] : false;
		}

		public function activate_module( $module ) {
			if ( ! in_array( $module, $this->active_modules, true ) ) {
				$this->active_modules[] = $module;
			}
		}

		public function get_active_modules() {
			return $this->active_modules;
		}
	}
}

if ( ! class_exists( 'Jet_Engine' ) ) {
	/**
	 * Mock JetEngine class.
	 */
	class Jet_Engine {
		public $modules;

		public function __construct() {
			$this->modules = new Jet_Engine_Modules();
		}
	}
}

$wp_mcp_ai_mock_jet_engine_cct = null;

if ( ! function_exists( 'jet_engine' ) ) {
	/**
	 * Mock jet_engine() function.
	 */
	function jet_engine() {
		global $wp_mcp_ai_mock_jet_engine_cct;

		if ( null === $wp_mcp_ai_mock_jet_engine_cct ) {
			$wp_mcp_ai_mock_jet_engine_cct = new Jet_Engine();
		}

		return $wp_mcp_ai_mock_jet_engine_cct;
	}
}

/**
 * Test class for JetEngine data stores activation.
 */
class WP_MCP_AI_JetEngine_Data_Stores_Activation_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset the global mock to ensure a fresh state.
		global $wp_mcp_ai_mock_jet_engine_cct;
		$wp_mcp_ai_mock_jet_engine_cct = null;
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		global $wp_mcp_ai_mock_jet_engine_cct;
		$wp_mcp_ai_mock_jet_engine_cct = null;
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
