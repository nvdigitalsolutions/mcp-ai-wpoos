<?php
/**
 * Tests for JetEngine WebChat Messages CCT module access.
 *
 * This test ensures that the WebChat Messages CCT class uses the correct
 * JetEngine API to access modules (using public methods instead of private properties).
 *
 * @package WP_MCP_AI
 */

// Mock classes needed for testing.
if ( ! class_exists( 'Jet_Engine_Modules' ) ) {
	/**
	 * Mock JetEngine Modules class.
	 */
	class Jet_Engine_Modules {
		private $active_modules = array();
		private $modules        = array();

		/**
		 * Constructor.
		 */
		public function __construct() {
			// Mock custom-content-types module wrapper.
			$module_wrapper           = new stdClass();
			$module_wrapper->instance = new stdClass();
			$module_wrapper->instance->manager = new stdClass();

			$this->modules['custom-content-types'] = $module_wrapper;
			$this->active_modules[]                = 'custom-content-types';
		}

		/**
		 * Check if module is active.
		 *
		 * @param string $module_id Module ID.
		 * @return bool
		 */
		public function is_module_active( $module_id ) {
			return in_array( $module_id, $this->active_modules, true );
		}

		/**
		 * Get module by ID.
		 *
		 * @param string $module_id Module ID.
		 * @return object|false
		 */
		public function get_module( $module_id ) {
			return isset( $this->modules[ $module_id ] ) ? $this->modules[ $module_id ] : false;
		}
	}
}

if ( ! class_exists( 'Jet_Engine' ) ) {
	/**
	 * Mock JetEngine class.
	 */
	class Jet_Engine {
		public $modules;

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->modules = new Jet_Engine_Modules();
		}
	}
}

$wp_mcp_ai_mock_jet_engine_webchat = null;

if ( ! function_exists( 'jet_engine' ) ) {
	/**
	 * Mock jet_engine() function.
	 */
	function jet_engine() {
		global $wp_mcp_ai_mock_jet_engine_webchat;

		if ( null === $wp_mcp_ai_mock_jet_engine_webchat ) {
			$wp_mcp_ai_mock_jet_engine_webchat = new Jet_Engine();
		}

		return $wp_mcp_ai_mock_jet_engine_webchat;
	}
}

/**
 * Test class for JetEngine WebChat Messages CCT module access.
 */
class WP_MCP_AI_JetEngine_WebChat_CCT_Module_Access_Test extends WP_UnitTestCase {

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();

		// Reset the global mock to ensure a fresh state.
		global $wp_mcp_ai_mock_jet_engine_webchat;
		$wp_mcp_ai_mock_jet_engine_webchat = null;
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		global $wp_mcp_ai_mock_jet_engine_webchat;
		$wp_mcp_ai_mock_jet_engine_webchat = null;
		parent::tearDown();
	}

	/**
	 * Test that get_cct_module uses public API instead of private property.
	 *
	 * This test verifies that the WebChat Messages CCT class correctly uses
	 * $engine->modules->get_module() (public method) instead of accessing
	 * the private $engine->modules->modules property directly.
	 */
	public function test_get_cct_module_uses_public_api() {
		// Verify JetEngine is available.
		$this->assertTrue( function_exists( 'jet_engine' ), 'jet_engine() function should be available' );

		$engine = jet_engine();
		$this->assertInstanceOf( 'Jet_Engine', $engine, 'jet_engine() should return Jet_Engine instance' );

		// Use reflection to access the protected get_cct_module method.
		$class  = new ReflectionClass( 'WP_MCP_AI_JetEngine_WebChat_Messages_CCT' );
		$method = $class->getMethod( 'get_cct_module' );
		$method->setAccessible( true );

		// Call get_cct_module and verify it returns the module instance.
		$module = $method->invoke( null );

		$this->assertNotNull( $module, 'get_cct_module() should return module instance' );
		$this->assertIsObject( $module, 'Module should be an object' );
		$this->assertObjectHasProperty( 'manager', $module, 'Module should have manager property' );
	}

	/**
	 * Test that get_cct_module handles missing custom-content-types module gracefully.
	 */
	public function test_get_cct_module_handles_missing_module() {
		$engine = jet_engine();

		// Mock inactive custom-content-types module.
		$reflection = new ReflectionProperty( Jet_Engine_Modules::class, 'active_modules' );
		$reflection->setAccessible( true );
		$reflection->setValue( $engine->modules, array() );

		// Use reflection to access the protected get_cct_module method.
		$class  = new ReflectionClass( 'WP_MCP_AI_JetEngine_WebChat_Messages_CCT' );
		$method = $class->getMethod( 'get_cct_module' );
		$method->setAccessible( true );

		// Call get_cct_module and verify it returns null.
		$module = $method->invoke( null );

		$this->assertNull( $module, 'get_cct_module() should return null when module is not active' );
	}

	/**
	 * Test that get_cct_module handles missing module instance gracefully.
	 */
	public function test_get_cct_module_handles_missing_instance() {
		$engine = jet_engine();

		// Mock module wrapper without instance.
		$reflection = new ReflectionProperty( Jet_Engine_Modules::class, 'modules' );
		$reflection->setAccessible( true );
		$modules                               = $reflection->getValue( $engine->modules );
		$modules['custom-content-types']       = new stdClass();
		// Note: no 'instance' property on purpose.
		$reflection->setValue( $engine->modules, $modules );

		// Use reflection to access the protected get_cct_module method.
		$class  = new ReflectionClass( 'WP_MCP_AI_JetEngine_WebChat_Messages_CCT' );
		$method = $class->getMethod( 'get_cct_module' );
		$method->setAccessible( true );

		// Call get_cct_module and verify it returns null.
		$module = $method->invoke( null );

		$this->assertNull( $module, 'get_cct_module() should return null when module wrapper has no instance' );
	}
}
