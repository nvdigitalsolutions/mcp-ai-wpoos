<?php
/**
 * Test container instance filtering functionality
 *
 * Tests the wp_mcp_ai_container_get_{$id} filter hook and type validation.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for container instance filtering.
 */
class Test_Container_Instance_Filtering extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		parent::tearDown();
		// Remove all filters we added during tests.
		remove_all_filters( 'wp_mcp_ai_container_get_section.tools' );
		remove_all_filters( 'wp_mcp_ai_container_get_test.service' );
		// Clear container cache to ensure fresh instances.
		wp_mcp_ai_container()->clear();
	}

	/**
	 * Test that filter hook is applied when getting service.
	 */
	public function test_filter_hook_is_applied() {
		$filter_called = false;

		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) use ( &$filter_called ) {
				$filter_called = true;
				return $instance;
			}
		);

		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		$this->assertTrue( $filter_called, 'Filter should be called when getting service' );
		$this->assertInstanceOf( 'WP_MCP_AI_Section_Tools', $section );
	}

	/**
	 * Test that unmodified instance is returned when filter doesn't change it.
	 */
	public function test_unmodified_instance_returned() {
		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) {
				// Don't modify the instance.
				return $instance;
			}
		);

		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		$this->assertInstanceOf( 'WP_MCP_AI_Section_Tools', $section );
		$this->assertTrue(
			method_exists( $section, 'handle_elementor_kit_import' ),
			'Original method should exist'
		);
	}

	/**
	 * Test that compatible subclass instance passes validation.
	 */
	public function test_compatible_subclass_passes_validation() {
		// Create a test subclass.
		if ( ! class_exists( 'Test_WP_MCP_AI_Section_Tools_Extended' ) ) {
			eval( '
				class Test_WP_MCP_AI_Section_Tools_Extended extends WP_MCP_AI_Section_Tools {
					public function get_title() {
						return "Extended Tools";
					}
				}
			' );
		}

		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) {
				return new Test_WP_MCP_AI_Section_Tools_Extended();
			}
		);

		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		// Should accept the subclass.
		$this->assertInstanceOf( 'Test_WP_MCP_AI_Section_Tools_Extended', $section );
		$this->assertInstanceOf( 'WP_MCP_AI_Section_Tools', $section );
		$this->assertTrue(
			method_exists( $section, 'handle_elementor_kit_import' ),
			'Extended class should have parent methods'
		);
	}

	/**
	 * Test that incompatible instance is rejected and original returned.
	 */
	public function test_incompatible_instance_rejected() {
		// Create a completely different class.
		if ( ! class_exists( 'Test_Incompatible_Class' ) ) {
			eval( '
				class Test_Incompatible_Class {
					public function some_method() {
						return "test";
					}
				}
			' );
		}

		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) {
				// Return incompatible instance.
				return new Test_Incompatible_Class();
			}
		);

		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		// Should return original instance, not the filtered one.
		$this->assertInstanceOf( 'WP_MCP_AI_Section_Tools', $section );
		$this->assertNotInstanceOf( 'Test_Incompatible_Class', $section );
		$this->assertTrue(
			method_exists( $section, 'handle_elementor_kit_import' ),
			'Original method should exist after rejecting incompatible instance'
		);
	}

	/**
	 * Test that non-object return value is rejected.
	 */
	public function test_non_object_return_rejected() {
		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) {
				// Return a string instead of object.
				return 'invalid';
			}
		);

		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		// Should return original instance.
		$this->assertInstanceOf( 'WP_MCP_AI_Section_Tools', $section );
		$this->assertTrue(
			method_exists( $section, 'handle_elementor_kit_import' ),
			'Original method should exist'
		);
	}

	/**
	 * Test that null return value is rejected.
	 */
	public function test_null_return_rejected() {
		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) {
				return null;
			}
		);

		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		// Should return original instance.
		$this->assertInstanceOf( 'WP_MCP_AI_Section_Tools', $section );
		$this->assertNotNull( $section );
	}

	/**
	 * Test that section without required methods is rejected.
	 */
	public function test_section_without_required_methods_rejected() {
		// Create a section that extends base but missing methods.
		if ( ! class_exists( 'Test_Incomplete_Section' ) ) {
			eval( '
				class Test_Incomplete_Section extends WP_MCP_AI_Settings_Section {
					public function get_id() { return "test"; }
					// Missing get_title, get_tab, render methods.
				}
			' );
		}

		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) {
				return new Test_Incomplete_Section();
			}
		);

		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		// Should return original instance because filtered one is incomplete.
		$this->assertInstanceOf( 'WP_MCP_AI_Section_Tools', $section );
		$this->assertNotInstanceOf( 'Test_Incomplete_Section', $section );
	}

	/**
	 * Test that method callable after filtering with compatible instance.
	 */
	public function test_method_callable_after_filtering() {
		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) {
				// Return same instance (no modification).
				return $instance;
			}
		);

		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		$callback = array( $section, 'handle_elementor_kit_import' );
		$this->assertTrue(
			is_callable( $callback ),
			'Method should be callable after filtering'
		);
	}

	/**
	 * Test that hooks registered in constructor still work after filtering.
	 */
	public function test_constructor_hooks_work_after_filtering() {
		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) {
				return $instance;
			}
		);

		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		// Check if admin_init hook is registered.
		$has_action = has_action( 'admin_init', array( $section, 'handle_elementor_kit_import' ) );
		$this->assertNotFalse(
			$has_action,
			'Constructor hook should be registered after filtering'
		);
	}

	/**
	 * Test that multiple filters can be applied.
	 */
	public function test_multiple_filters_applied() {
		$filter1_called = false;
		$filter2_called = false;

		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) use ( &$filter1_called ) {
				$filter1_called = true;
				return $instance;
			},
			10
		);

		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance ) use ( &$filter2_called ) {
				$filter2_called = true;
				return $instance;
			},
			20
		);

		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		$this->assertTrue( $filter1_called, 'First filter should be called' );
		$this->assertTrue( $filter2_called, 'Second filter should be called' );
		$this->assertInstanceOf( 'WP_MCP_AI_Section_Tools', $section );
	}

	/**
	 * Test that filter receives correct parameters.
	 */
	public function test_filter_receives_correct_parameters() {
		$received_id        = null;
		$received_instance  = null;
		$received_container = null;

		add_filter(
			'wp_mcp_ai_container_get_section.tools',
			function ( $instance, $id, $container ) use ( &$received_instance, &$received_id, &$received_container ) {
				$received_instance  = $instance;
				$received_id        = $id;
				$received_container = $container;
				return $instance;
			},
			10,
			3
		);

		$container = wp_mcp_ai_container();
		$section   = $container->get( 'section.tools' );

		$this->assertInstanceOf( 'WP_MCP_AI_Section_Tools', $received_instance );
		$this->assertEquals( 'section.tools', $received_id );
		$this->assertInstanceOf( 'WP_MCP_AI_Container', $received_container );
	}
}
