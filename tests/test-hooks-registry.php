<?php
/**
 * Tests for tool registry hooks.
 *
 * Covers hooks declared in class-wp-mcp-ai-tool-registry.php:
 *   - wp_mcp_ai_register_tools         (action)
 *   - wp_mcp_ai_base_version           (filter)
 *   - wp_mcp_ai_default_tools          (filter)
 *   - wp_mcp_ai_provider_tool_mapping  (filter)
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test file contains multiple stub classes by design.
// phpcs:disable Generic.Files.OneObjectStructurePerFile -- test file contains multiple stub classes by design.

if ( ! class_exists( 'Test_Registry_Hook_Stub_Tool' ) ) {
	/**
	 * Minimal tool stub for registry hook tests.
	 */
	class Test_Registry_Hook_Stub_Tool implements WP_MCP_AI_Tool_Interface {
		use WP_MCP_AI_Tool_Default_Capability;
		/**
		 * Return the tool slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'test_registry_hook_stub';
		}

		/**
		 * Return the tool name.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'Test Registry Hook Stub';
		}

		/**
		 * Return the tool description.
		 *
		 * @return string
		 */
		public function get_description() {
			return 'Stub for registry hook tests.';
		}

		/**
		 * Return an empty parameter schema.
		 *
		 * @return array
		 */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(),
			);
		}

		/**
		 * Execute the tool (no-op for tests).
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			return array( 'success' => true );
		}
	}
}

/**
 * Test class for tool registry hooks.
 */
class Test_Hooks_Registry extends WP_UnitTestCase {

	/**
	 * Original registry singleton preserved across setUp/tearDown.
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	private $original_instance;

	/**
	 * Registry singleton under test.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	private $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Reset the singleton instance to get a clean registry per test.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$this->original_instance = $property->getValue();
		$property->setValue( null, null );

		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->clear_tools();
	}

	/**
	 * Tear down test environment — clean up any stub tools added during tests.
	 */
	public function tearDown(): void {
		$this->registry->unregister_tool( 'test_registry_hook_stub' );

		// Clear tools from the original instance to prevent leakage to other test files.
		if ( $this->original_instance instanceof WP_MCP_AI_Tool_Registry ) {
			$this->original_instance->clear_tools();
		}

		// Restore original instance.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Tool_Registry' );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, $this->original_instance );

		parent::tearDown();
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_register_tools
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_register_tools action passes a WP_MCP_AI_Tool_Registry instance.
	 */
	public function test_register_tools_action_delivers_registry_instance() {
		// Arrange.
		$received = null;

		$callback = function ( $registry ) use ( &$received ) {
			$received = $registry;
		};

		add_action( 'wp_mcp_ai_register_tools', $callback, 10, 1 );

		// Act.
		do_action( 'wp_mcp_ai_register_tools', $this->registry );

		remove_action( 'wp_mcp_ai_register_tools', $callback, 10 );

		// Assert.
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Registry', $received );
	}

	/**
	 * Test that same registry singleton is passed through the action.
	 */
	public function test_register_tools_action_passes_singleton() {
		// Arrange.
		$received = null;

		$callback = function ( $registry ) use ( &$received ) {
			$received = $registry;
		};

		add_action( 'wp_mcp_ai_register_tools', $callback, 10, 1 );

		// Act.
		do_action( 'wp_mcp_ai_register_tools', $this->registry );

		remove_action( 'wp_mcp_ai_register_tools', $callback, 10 );

		// Assert.
		$this->assertSame( $this->registry, $received, 'The passed registry must be the singleton instance.' );
	}

	/**
	 * Test that a tool registered inside the wp_mcp_ai_register_tools callback is available.
	 */
	public function test_tool_registered_inside_register_tools_callback() {
		// Arrange.
		$stub = new Test_Registry_Hook_Stub_Tool();
		$slug = $stub->get_slug();

		$this->registry->unregister_tool( $slug );

		$callback = function ( $registry ) use ( $stub ) {
			$registry->register_tool( $stub );
		};

		add_action( 'wp_mcp_ai_register_tools', $callback, 10, 1 );

		// Act.
		do_action( 'wp_mcp_ai_register_tools', $this->registry );

		remove_action( 'wp_mcp_ai_register_tools', $callback, 10 );

		// Assert.
		$tool = $this->registry->get_tool( $slug );
		$this->assertNotNull( $tool );
		$this->assertSame( $slug, $tool->get_slug() );
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_base_version
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_base_version filter can force-return true (base mode).
	 */
	public function test_base_version_filter_can_force_true() {
		// Arrange.
		$filter = function ( $is_base ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return true;
		};

		add_filter( 'wp_mcp_ai_base_version', $filter, 10, 1 );

		// Act.
		$result = (bool) apply_filters( 'wp_mcp_ai_base_version', false );

		remove_filter( 'wp_mcp_ai_base_version', $filter, 10 );

		// Assert.
		$this->assertTrue( $result );
	}

	/**
	 * Test that wp_mcp_ai_base_version filter can force-return false (full mode).
	 */
	public function test_base_version_filter_can_force_false() {
		// Arrange.
		$filter = function ( $is_base ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return false;
		};

		add_filter( 'wp_mcp_ai_base_version', $filter, 10, 1 );

		// Act.
		$result = (bool) apply_filters( 'wp_mcp_ai_base_version', true );

		remove_filter( 'wp_mcp_ai_base_version', $filter, 10 );

		// Assert.
		$this->assertFalse( $result );
	}

	/**
	 * Test that wp_mcp_ai_base_version filter receives the original value as its argument.
	 */
	public function test_base_version_filter_receives_original_value() {
		// Arrange.
		$received_value = null;

		$filter = function ( $is_base ) use ( &$received_value ) {
			$received_value = $is_base;
			return $is_base;
		};

		add_filter( 'wp_mcp_ai_base_version', $filter, 10, 1 );

		// Act.
		apply_filters( 'wp_mcp_ai_base_version', true );

		remove_filter( 'wp_mcp_ai_base_version', $filter, 10 );

		// Assert.
		$this->assertIsBool( $received_value );
		$this->assertTrue( $received_value );
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_default_tools
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_default_tools filter passes the default tool array through.
	 */
	public function test_default_tools_filter_passes_through_by_default() {
		// Arrange.
		$original = array(
			'WP_MCP_AI_Tool_Fake_A' => '/path/to/a.php',
			'WP_MCP_AI_Tool_Fake_B' => '/path/to/b.php',
		);

		// Act.
		$result = apply_filters( 'wp_mcp_ai_default_tools', $original, false );

		// Assert.
		$this->assertSame( $original, $result );
	}

	/**
	 * Test that wp_mcp_ai_default_tools filter can add a tool to the list.
	 */
	public function test_default_tools_filter_can_add_tool() {
		// Arrange.
		$original = array(
			'WP_MCP_AI_Tool_Fake_A' => '/path/to/a.php',
		);

		$filter = function ( $tools, $is_base ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			$tools['WP_MCP_AI_Tool_Added_By_Filter'] = '/path/to/added.php';
			return $tools;
		};

		add_filter( 'wp_mcp_ai_default_tools', $filter, 10, 2 );

		// Act.
		$result = apply_filters( 'wp_mcp_ai_default_tools', $original, false );

		remove_filter( 'wp_mcp_ai_default_tools', $filter, 10 );

		// Assert.
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Added_By_Filter', $result );
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Fake_A', $result );
		$this->assertCount( 2, $result );
	}

	/**
	 * Test that wp_mcp_ai_default_tools filter can remove a tool from the list.
	 */
	public function test_default_tools_filter_can_remove_tool() {
		// Arrange.
		$original = array(
			'WP_MCP_AI_Tool_Keep'   => '/path/to/keep.php',
			'WP_MCP_AI_Tool_Remove' => '/path/to/remove.php',
		);

		$filter = function ( $tools, $is_base ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			unset( $tools['WP_MCP_AI_Tool_Remove'] );
			return $tools;
		};

		add_filter( 'wp_mcp_ai_default_tools', $filter, 10, 2 );

		// Act.
		$result = apply_filters( 'wp_mcp_ai_default_tools', $original, false );

		remove_filter( 'wp_mcp_ai_default_tools', $filter, 10 );

		// Assert.
		$this->assertArrayHasKey( 'WP_MCP_AI_Tool_Keep', $result );
		$this->assertArrayNotHasKey( 'WP_MCP_AI_Tool_Remove', $result );
	}

	/**
	 * Test that wp_mcp_ai_default_tools filter receives the is_base_version flag.
	 */
	public function test_default_tools_filter_receives_is_base_flag() {
		// Arrange.
		$received_flag = null;

		$filter = function ( $tools, $is_base ) use ( &$received_flag ) {
			$received_flag = $is_base;
			return $tools;
		};

		add_filter( 'wp_mcp_ai_default_tools', $filter, 10, 2 );

		// Act.
		apply_filters( 'wp_mcp_ai_default_tools', array(), true );

		remove_filter( 'wp_mcp_ai_default_tools', $filter, 10 );

		// Assert.
		$this->assertIsBool( $received_flag );
		$this->assertTrue( $received_flag );
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_provider_tool_mapping
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_provider_tool_mapping filter receives an array with provider keys.
	 */
	public function test_provider_tool_mapping_filter_receives_array_with_provider_keys() {
		// Arrange.
		$received_mapping = null;

		$filter = function ( $mapping ) use ( &$received_mapping ) {
			$received_mapping = $mapping;
			return $mapping;
		};

		add_filter( 'wp_mcp_ai_provider_tool_mapping', $filter, 10, 1 );

		// Act — simulate a mapping array identical to what the registry builds.
		$sample_mapping = array(
			'generate_openai_image' => array(
				'gemini'    => 'generate_gemini_image',
				'anthropic' => 'generate_openai_image',
			),
			'generate_gemini_image' => array(
				'openai'    => 'generate_openai_image',
				'anthropic' => 'generate_openai_image',
			),
		);

		$result = apply_filters( 'wp_mcp_ai_provider_tool_mapping', $sample_mapping );

		remove_filter( 'wp_mcp_ai_provider_tool_mapping', $filter, 10 );

		// Assert.
		$this->assertIsArray( $received_mapping );
		$this->assertArrayHasKey( 'generate_openai_image', $received_mapping );
		$this->assertArrayHasKey( 'generate_gemini_image', $received_mapping );
	}

	/**
	 * Test that wp_mcp_ai_provider_tool_mapping filter can add a new mapping entry.
	 */
	public function test_provider_tool_mapping_filter_can_add_entry() {
		// Arrange.
		$existing_mapping = array(
			'generate_openai_image' => array( 'gemini' => 'generate_gemini_image' ),
		);

		$filter = function ( $mapping ) {
			$mapping['custom_openai_tool'] = array( 'gemini' => 'custom_gemini_tool' );
			return $mapping;
		};

		add_filter( 'wp_mcp_ai_provider_tool_mapping', $filter, 10, 1 );

		// Act.
		$result = apply_filters( 'wp_mcp_ai_provider_tool_mapping', $existing_mapping );

		remove_filter( 'wp_mcp_ai_provider_tool_mapping', $filter, 10 );

		// Assert.
		$this->assertArrayHasKey( 'custom_openai_tool', $result );
		$this->assertSame( 'custom_gemini_tool', $result['custom_openai_tool']['gemini'] );
	}

	/**
	 * Test that wp_mcp_ai_provider_tool_mapping filter result is an array (structure check).
	 */
	public function test_provider_tool_mapping_result_is_array() {
		// Arrange.
		$base_mapping = array(
			'generate_openai_image' => array( 'gemini' => 'generate_gemini_image' ),
		);

		// Act.
		$result = apply_filters( 'wp_mcp_ai_provider_tool_mapping', $base_mapping );

		// Assert.
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		// Each mapping entry should be an array of provider => target_slug mappings.
		foreach ( $result as $source_slug => $provider_map ) {
			$this->assertIsString( $source_slug );
			$this->assertIsArray( $provider_map );
		}
	}

	/**
	 * Test that registry's get_tool returns null for an unknown slug.
	 */
	public function test_registry_get_tool_returns_null_for_unknown_slug() {
		// Act.
		$tool = $this->registry->get_tool( 'definitely_not_a_real_tool_slug_xyz123' );

		// Assert.
		$this->assertNull( $tool );
	}

	/**
	 * Test that registry register_tool works with a valid interface implementation.
	 */
	public function test_registry_register_tool_stores_tool_by_slug() {
		// Arrange.
		$stub = new Test_Registry_Hook_Stub_Tool();
		$slug = $stub->get_slug();

		$this->registry->unregister_tool( $slug );

		// Act.
		$registered = $this->registry->register_tool( $stub );

		// Assert.
		$this->assertTrue( $registered );
		$this->assertNotNull( $this->registry->get_tool( $slug ) );

		// Cleanup.
		$this->registry->unregister_tool( $slug );
	}
}
