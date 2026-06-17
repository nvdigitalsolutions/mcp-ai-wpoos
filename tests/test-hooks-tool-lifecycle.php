<?php
/**
 * Tests for tool execution lifecycle hooks.
 *
 * Covers the four hooks fired by execute_tool_call_internal():
 *   - wp_mcp_ai_before_tool_execution  (action)
 *   - wp_mcp_ai_after_tool_execution   (action)
 *   - wp_mcp_ai_pre_execute_tool       (filter – short-circuit)
 *   - wp_mcp_ai_tool_output            (filter – transform result)
 * Also covers wp_mcp_ai_register_tools action.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test file contains multiple stub classes by design.
// phpcs:disable Generic.Files.OneObjectStructurePerFile -- test file contains multiple stub classes by design.

if ( ! class_exists( 'Test_Lifecycle_Stub_Tool' ) ) {
	/**
	 * Minimal tool stub used by lifecycle hook tests.
	 */
	class Test_Lifecycle_Stub_Tool implements WP_MCP_AI_Tool_Interface {
		use WP_MCP_AI_Tool_Default_Capability;
		/**
		 * Return the tool slug.
		 *
		 * @return string
		 */
		public function get_slug() {
			return 'test_lifecycle_stub_tool';
		}

		/**
		 * Return the tool name.
		 *
		 * @return string
		 */
		public function get_name() {
			return 'Test Lifecycle Stub Tool';
		}

		/**
		 * Return the tool description.
		 *
		 * @return string
		 */
		public function get_description() {
			return 'Stub used for lifecycle hook tests only.';
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
		 * Execute the tool.
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			return array(
				'success' => true,
				'source'  => 'stub',
			);
		}
	}
}

/**
 * Test class for tool execution lifecycle hooks.
 */
class Test_Hooks_Tool_Lifecycle extends WP_UnitTestCase {

	// ------------------------------------------------------------------
	// wp_mcp_ai_before_tool_execution
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_before_tool_execution fires with correct arguments.
	 */
	public function test_before_tool_execution_hook_fires_with_correct_args() {
		// Arrange.
		$fired         = false;
		$captured_slug = null;
		$captured_args = null;
		$captured_ctx  = null;

		$callback = function ( $slug, $args, $context ) use ( &$fired, &$captured_slug, &$captured_args, &$captured_ctx ) {
			$fired         = true;
			$captured_slug = $slug;
			$captured_args = $args;
			$captured_ctx  = $context;
		};

		add_action( 'wp_mcp_ai_before_tool_execution', $callback, 10, 3 );

		// Act.
		do_action( 'wp_mcp_ai_before_tool_execution', 'my_test_tool', array( 'param' => 'value' ), array( 'user_id' => 42 ) );

		remove_action( 'wp_mcp_ai_before_tool_execution', $callback, 10 );

		// Assert.
		$this->assertTrue( $fired, 'wp_mcp_ai_before_tool_execution should have fired.' );
		$this->assertSame( 'my_test_tool', $captured_slug );
		$this->assertSame( array( 'param' => 'value' ), $captured_args );
		$this->assertSame( 42, $captured_ctx['user_id'] );
	}

	/**
	 * Test that multiple callbacks on wp_mcp_ai_before_tool_execution all fire.
	 */
	public function test_before_tool_execution_fires_multiple_callbacks() {
		// Arrange.
		$fire_count = 0;

		$cb1 = function () use ( &$fire_count ) {
			++$fire_count;
		};
		$cb2 = function () use ( &$fire_count ) {
			++$fire_count;
		};

		add_action( 'wp_mcp_ai_before_tool_execution', $cb1, 10, 3 );
		add_action( 'wp_mcp_ai_before_tool_execution', $cb2, 20, 3 );

		// Act.
		do_action( 'wp_mcp_ai_before_tool_execution', 'slug', array(), array() );

		remove_action( 'wp_mcp_ai_before_tool_execution', $cb1, 10 );
		remove_action( 'wp_mcp_ai_before_tool_execution', $cb2, 20 );

		// Assert.
		$this->assertSame( 2, $fire_count );
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_after_tool_execution
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_after_tool_execution fires with the tool result.
	 */
	public function test_after_tool_execution_hook_fires_with_result() {
		// Arrange.
		$fired           = false;
		$captured_result = null;

		$callback = function ( $slug, $args, $context, $result ) use ( &$fired, &$captured_result ) {
			$fired           = true;
			$captured_result = $result;
		};

		add_action( 'wp_mcp_ai_after_tool_execution', $callback, 10, 4 );

		$expected_result = array(
			'success' => true,
			'message' => 'Done.',
		);

		// Act.
		do_action( 'wp_mcp_ai_after_tool_execution', 'my_test_tool', array(), array( 'user_id' => 1 ), $expected_result );

		remove_action( 'wp_mcp_ai_after_tool_execution', $callback, 10 );

		// Assert.
		$this->assertTrue( $fired );
		$this->assertSame( $expected_result, $captured_result );
	}

	/**
	 * Test that wp_mcp_ai_after_tool_execution receives the correct slug.
	 */
	public function test_after_tool_execution_receives_tool_slug() {
		// Arrange.
		$captured_slug = null;

		$callback = function ( $slug ) use ( &$captured_slug ) {
			$captured_slug = $slug;
		};

		add_action( 'wp_mcp_ai_after_tool_execution', $callback, 10, 4 );

		// Act.
		do_action( 'wp_mcp_ai_after_tool_execution', 'expected_slug', array(), array(), array() );

		remove_action( 'wp_mcp_ai_after_tool_execution', $callback, 10 );

		// Assert.
		$this->assertSame( 'expected_slug', $captured_slug );
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_pre_execute_tool
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_pre_execute_tool returns null when no filter is added.
	 */
	public function test_pre_execute_tool_filter_returns_null_by_default() {
		// Act.
		$result = apply_filters( 'wp_mcp_ai_pre_execute_tool', null, new stdClass(), array(), array() );

		// Assert.
		$this->assertNull( $result, 'With no filter, pre_execute_tool should return null (no short-circuit).' );
	}

	/**
	 * Test that a non-null return from wp_mcp_ai_pre_execute_tool short-circuits execution.
	 */
	public function test_pre_execute_tool_filter_short_circuits_with_non_null() {
		// Arrange.
		$short_circuit_result = array(
			'short_circuited' => true,
			'from'            => 'filter',
		);

		$filter = function ( $pre_result, $tool, $args, $context ) use ( $short_circuit_result ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return $short_circuit_result;
		};

		add_filter( 'wp_mcp_ai_pre_execute_tool', $filter, 10, 4 );

		// Act.
		$result = apply_filters( 'wp_mcp_ai_pre_execute_tool', null, new stdClass(), array( 'arg' => 1 ), array() );

		remove_filter( 'wp_mcp_ai_pre_execute_tool', $filter, 10 );

		// Assert.
		$this->assertNotNull( $result );
		$this->assertSame( $short_circuit_result, $result );
		$this->assertTrue( $result['short_circuited'] );
	}

	/**
	 * Test that filter receives the tool object for inspection.
	 */
	public function test_pre_execute_tool_filter_receives_tool_object() {
		// Arrange.
		$stub          = new Test_Lifecycle_Stub_Tool();
		$received_tool = null;

		$filter = function ( $pre_result, $tool ) use ( &$received_tool ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			$received_tool = $tool;
			return null; // Do not short-circuit.
		};

		add_filter( 'wp_mcp_ai_pre_execute_tool', $filter, 10, 4 );

		// Act.
		apply_filters( 'wp_mcp_ai_pre_execute_tool', null, $stub, array(), array() );

		remove_filter( 'wp_mcp_ai_pre_execute_tool', $filter, 10 );

		// Assert.
		$this->assertInstanceOf( 'Test_Lifecycle_Stub_Tool', $received_tool );
		$this->assertSame( 'test_lifecycle_stub_tool', $received_tool->get_slug() );
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_tool_output
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_tool_output passes result through with no filter.
	 */
	public function test_tool_output_filter_passes_through_by_default() {
		// Arrange.
		$original = array(
			'success' => true,
			'data'    => 'original',
		);

		// Act.
		$result = apply_filters( 'wp_mcp_ai_tool_output', $original, 'test_tool', array(), array() );

		// Assert.
		$this->assertSame( $original, $result );
	}

	/**
	 * Test that wp_mcp_ai_tool_output filter can modify the result.
	 */
	public function test_tool_output_filter_can_transform_result() {
		// Arrange.
		$original = array(
			'success' => true,
			'data'    => 'original',
		);
		$modified = array(
			'success' => true,
			'data'    => 'transformed',
			'extra'   => 'injected',
		);

		$filter = function ( $result, $slug, $args, $context ) use ( $modified ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return $modified;
		};

		add_filter( 'wp_mcp_ai_tool_output', $filter, 10, 4 );

		// Act.
		$result = apply_filters( 'wp_mcp_ai_tool_output', $original, 'test_tool', array(), array() );

		remove_filter( 'wp_mcp_ai_tool_output', $filter, 10 );

		// Assert.
		$this->assertSame( $modified, $result );
		$this->assertSame( 'transformed', $result['data'] );
		$this->assertArrayHasKey( 'extra', $result );
	}

	/**
	 * Test that wp_mcp_ai_tool_output filter receives the correct slug.
	 */
	public function test_tool_output_filter_receives_correct_slug() {
		// Arrange.
		$captured_slug = null;

		$filter = function ( $result, $slug ) use ( &$captured_slug ) {
			$captured_slug = $slug;
			return $result;
		};

		add_filter( 'wp_mcp_ai_tool_output', $filter, 10, 4 );

		// Act.
		apply_filters( 'wp_mcp_ai_tool_output', array( 'success' => true ), 'expected_slug', array(), array() );

		remove_filter( 'wp_mcp_ai_tool_output', $filter, 10 );

		// Assert.
		$this->assertSame( 'expected_slug', $captured_slug );
	}

	// ------------------------------------------------------------------
	// wp_mcp_ai_register_tools
	// ------------------------------------------------------------------

	/**
	 * Test that wp_mcp_ai_register_tools action fires and passes registry instance.
	 */
	public function test_register_tools_action_receives_registry_instance() {
		// Arrange.
		$received_registry = null;

		$callback = function ( $registry ) use ( &$received_registry ) {
			$received_registry = $registry;
		};

		add_action( 'wp_mcp_ai_register_tools', $callback, 10, 1 );

		// Act.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		do_action( 'wp_mcp_ai_register_tools', $registry );

		remove_action( 'wp_mcp_ai_register_tools', $callback, 10 );

		// Assert.
		$this->assertNotNull( $received_registry );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Registry', $received_registry );
		$this->assertSame( $registry, $received_registry );
	}

	/**
	 * Test that a tool registered via wp_mcp_ai_register_tools callback is available in the registry.
	 */
	public function test_tool_registered_via_register_tools_hook() {
		// Arrange.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$stub     = new Test_Lifecycle_Stub_Tool();
		$slug     = $stub->get_slug();

		// Ensure slug is not already registered.
		$registry->unregister_tool( $slug );
		$this->assertNull( $registry->get_tool( $slug ) );

		$callback = function ( $reg ) use ( $stub ) {
			$reg->register_tool( $stub );
		};

		add_action( 'wp_mcp_ai_register_tools', $callback, 10, 1 );

		// Act.
		do_action( 'wp_mcp_ai_register_tools', $registry );

		remove_action( 'wp_mcp_ai_register_tools', $callback, 10 );

		// Assert.
		$this->assertNotNull( $registry->get_tool( $slug ) );
		$this->assertSame( $slug, $registry->get_tool( $slug )->get_slug() );

		// Cleanup.
		$registry->unregister_tool( $slug );
	}

	/**
	 * Test that hooks do NOT fire unless explicitly triggered (unknown slug scenario).
	 */
	public function test_before_hook_does_not_fire_if_action_not_called() {
		// Arrange.
		$fired = false;

		$callback = function () use ( &$fired ) {
			$fired = true;
		};

		add_action( 'wp_mcp_ai_before_tool_execution', $callback, 10, 3 );

		// Act — do NOT call do_action.

		remove_action( 'wp_mcp_ai_before_tool_execution', $callback, 10 );

		// Assert.
		$this->assertFalse( $fired, 'Hook should not fire if do_action was never called.' );
	}
}
