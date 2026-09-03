<?php
/**
 * Tests for pre-built shortcut collection resilience.
 *
 * Verifies that a Throwable raised by a single tool's shortcut metadata —
 * or by a callback on one of the shortcut filters — is contained when the
 * assistant edit screen builds its pre-built shortcut map, instead of
 * taking down the page.
 *
 * @package WP_MCP_AI\Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class Test_Assistant_Prebuilt_Shortcuts_Resilience extends WP_UnitTestCase {

	/**
	 * Set up the registry with a clean, initialized state.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->clear_tools();
		$registry->init();
	}

	/**
	 * Build a throwing-shortcut tool stub.
	 *
	 * @return WP_MCP_AI_Tool_Interface Tool whose get_shortcut_tasks() throws.
	 */
	private function build_throwing_tool() {
		return new class() implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Shortcuts_Interface {
			use WP_MCP_AI_Tool_Default_Capability;

			/**
			 * Get the tool slug.
			 *
			 * @return string Tool slug.
			 */
			public function get_slug() {
				return 'test_throwing_shortcut_tool';
			}

			/**
			 * Get the tool name.
			 *
			 * @return string Tool name.
			 */
			public function get_name() {
				return 'Test Throwing Shortcut Tool';
			}

			/**
			 * Get the tool description.
			 *
			 * @return string Tool description.
			 */
			public function get_description() {
				return 'Stub tool whose shortcut metadata throws.';
			}

			/**
			 * Get the parameters schema.
			 *
			 * @return array Parameters schema.
			 */
			public function get_parameters_schema() {
				return array();
			}

			/**
			 * Execute the tool.
			 *
			 * @param array $arguments Tool arguments.
			 * @param array $context Execution context.
			 * @return array|WP_Error Tool result.
			 */
			public function execute( array $arguments = array(), array $context = array() ) {
				return array();
			}

			/**
			 * Simulate a broken tool implementation.
			 *
			 * @return never
			 * @throws RuntimeException Always.
			 */
			public function get_shortcut_tasks() {
				throw new RuntimeException( 'Broken shortcut metadata.' );
			}
		};
	}

	/**
	 * Build a healthy-shortcut tool stub.
	 *
	 * @return WP_MCP_AI_Tool_Interface Tool with valid shortcut metadata.
	 */
	private function build_healthy_tool() {
		return new class() implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Shortcuts_Interface {
			use WP_MCP_AI_Tool_Default_Capability;

			/**
			 * Get the tool slug.
			 *
			 * @return string Tool slug.
			 */
			public function get_slug() {
				return 'test_resilient_shortcut_tool';
			}

			/**
			 * Get the tool name.
			 *
			 * @return string Tool name.
			 */
			public function get_name() {
				return 'Test Resilient Shortcut Tool';
			}

			/**
			 * Get the tool description.
			 *
			 * @return string Tool description.
			 */
			public function get_description() {
				return 'Stub tool with valid shortcut metadata.';
			}

			/**
			 * Get the parameters schema.
			 *
			 * @return array Parameters schema.
			 */
			public function get_parameters_schema() {
				return array();
			}

			/**
			 * Execute the tool.
			 *
			 * @param array $arguments Tool arguments.
			 * @param array $context Execution context.
			 * @return array|WP_Error Tool result.
			 */
			public function execute( array $arguments = array(), array $context = array() ) {
				return array();
			}

			/**
			 * Return valid shortcut tasks.
			 *
			 * @return array
			 */
			public function get_shortcut_tasks() {
				return array(
					array(
						'label'   => 'Resilient summary',
						'payload' => 'summarize the latest updates',
					),
				);
			}
		};
	}

	/**
	 * Build the shortcut map for the supplied slugs, exposing the protected
	 * builder via an anonymous subclass.
	 *
	 * @param array $tool_slugs   Tool slugs to inspect.
	 * @param int   $assistant_id Assistant post ID.
	 * @return array Shortcut entries keyed by tool slug.
	 */
	private function get_defaults_map( array $tool_slugs, $assistant_id ) {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$cpt = new class( $registry ) extends WP_MCP_AI_Assistant_CPT {
			/**
			 * Expose the protected map builder for assertions.
			 *
			 * @param array $tool_slugs   Tool slugs to inspect.
			 * @param int   $assistant_id Assistant post ID.
			 * @return array Shortcut entries keyed by tool slug.
			 */
			public function get_defaults_map( array $tool_slugs, $assistant_id ) {
				return $this->get_default_prebuilt_shortcuts_map( $tool_slugs, $assistant_id );
			}
		};

		return $cpt->get_defaults_map( $tool_slugs, $assistant_id );
	}

	/**
	 * Create an assistant post for shortcut-map assertions.
	 *
	 * @return int Assistant post ID.
	 */
	private function create_assistant() {
		return self::factory()->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Shortcut Resilience Assistant',
			)
		);
	}

	/**
	 * Ensure a tool whose get_shortcut_tasks() throws is skipped, not fatal.
	 */
	public function test_map_builder_contains_throwing_tool() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$throwing = $this->build_throwing_tool();
		$registry->register_tool( $throwing );

		$assistant_id = $this->create_assistant();

		$map = $this->get_defaults_map( array( $throwing->get_slug() ), $assistant_id );

		$registry->unregister_tool( $throwing->get_slug() );

		$this->assertIsArray( $map, 'The shortcut map should still be returned.' );
		$this->assertArrayHasKey( $throwing->get_slug(), $map, 'The failing tool should map to an empty entry set.' );
		$this->assertSame( array(), $map[ $throwing->get_slug() ], 'The failing tool should contribute no shortcuts.' );
	}

	/**
	 * Ensure a filter callback that throws is contained per tool.
	 */
	public function test_map_builder_contains_throwing_filter() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$tool = $this->build_healthy_tool();
		$registry->register_tool( $tool );

		$filter_hook = 'wp_mcp_ai_tool_shortcut_tasks_' . $tool->get_slug();

		add_filter(
			$filter_hook,
			static function () {
				throw new RuntimeException( 'Broken shortcut filter.' );
			}
		);

		$assistant_id = $this->create_assistant();

		$map = $this->get_defaults_map( array( $tool->get_slug() ), $assistant_id );

		remove_all_filters( $filter_hook );
		$registry->unregister_tool( $tool->get_slug() );

		$this->assertIsArray( $map, 'The shortcut map should still be returned.' );
		$this->assertArrayHasKey( $tool->get_slug(), $map, 'The filtered tool should map to an empty entry set.' );
		$this->assertSame( array(), $map[ $tool->get_slug() ], 'The filtered tool should contribute no shortcuts.' );
	}

	/**
	 * Ensure healthy tools still contribute their shortcuts alongside a failing one.
	 */
	public function test_map_builder_skips_failing_tool_but_keeps_healthy_entries() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		$throwing = $this->build_throwing_tool();
		$healthy  = $this->build_healthy_tool();
		$registry->register_tool( $throwing );
		$registry->register_tool( $healthy );

		$assistant_id = $this->create_assistant();

		$map = $this->get_defaults_map(
			array( $throwing->get_slug(), $healthy->get_slug() ),
			$assistant_id
		);

		$registry->unregister_tool( $throwing->get_slug() );
		$registry->unregister_tool( $healthy->get_slug() );

		$this->assertSame( array(), $map[ $throwing->get_slug() ], 'The failing tool should be skipped.' );
		$this->assertNotEmpty( $map[ $healthy->get_slug() ], 'The healthy tool should still contribute shortcuts.' );
		$this->assertSame( 'Resilient summary', $map[ $healthy->get_slug() ][0]['label'] );
	}
}
