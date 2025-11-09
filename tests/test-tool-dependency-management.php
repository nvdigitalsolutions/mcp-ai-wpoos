<?php
/**
 * Tests for tool dependency management and graceful handling.
 *
 * Ensures tools that depend on optional plugins check dependencies
 * properly and return clean error messages when dependencies are missing.
 *
 * @package WP_MCP_AI
 */

/**
 * @group tool-dependencies
 * @group tool-robustness
 */
class WP_MCP_AI_Tool_Dependency_Tests extends WP_UnitTestCase {

	/**
	 * Test that tools with dependencies implement is_available() method.
	 */
	public function test_dependency_tools_implement_is_available() {
		$tools_with_dependencies = array(
			'WP_MCP_AI_Tool_Get_Woo_Orders',
			'WP_MCP_AI_Tool_Get_Woo_Products',
			'WP_MCP_AI_Tool_Create_Woo_Product',
			'WP_MCP_AI_Tool_Get_Elementor_Templates',
			'WP_MCP_AI_Tool_Get_JetEngine_Items',
			'WP_MCP_AI_Tool_Get_JetFormBuilder_Forms',
			'WP_MCP_AI_Tool_Get_JetFormBuilder_Submissions',
			'WP_MCP_AI_Tool_List_JetEngine_Routes',
			'WP_MCP_AI_Tool_Invoke_JetEngine_Route',
			'WP_MCP_AI_Tool_Get_RankMath_SEO',
			'WP_MCP_AI_Tool_Create_WPCode_Snippet',
		);

		foreach ( $tools_with_dependencies as $tool_class ) {
			if ( ! class_exists( $tool_class ) ) {
				// Load the tool file.
				$slug = $this->class_to_slug( $tool_class );
				$file = WP_MCP_AI_PATH . "includes/tools/class-wp-mcp-ai-tool-{$slug}.php";

				if ( file_exists( $file ) ) {
					require_once $file;
				}
			}

			if ( class_exists( $tool_class ) ) {
				$this->assertTrue(
					method_exists( $tool_class, 'is_available' ),
					sprintf( '%s should implement is_available() static method', $tool_class )
				);

				$this->assertTrue(
					method_exists( $tool_class, 'get_unavailable_reason' ),
					sprintf( '%s should implement get_unavailable_reason() static method', $tool_class )
				);
			}
		}
	}

	/**
	 * Test WooCommerce tools check for WooCommerce.
	 */
	public function test_woocommerce_tools_check_dependency() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is active, cannot test missing dependency' );
		}

		// Test Get Woo Orders tool.
		if ( class_exists( 'WP_MCP_AI_Tool_Get_Woo_Orders' ) ) {
			$tool   = new WP_MCP_AI_Tool_Get_Woo_Orders();
			$result = $tool->execute( array(), array( 'user_id' => 1 ) );

			$this->assertInstanceOf( WP_Error::class, $result, 'WooCommerce tool should return WP_Error when WooCommerce is not active' );
			$this->assertEquals( 'wp_mcp_ai_woo_missing', $result->get_error_code() );
			$this->assertStringContainsString( 'WooCommerce', $result->get_error_message() );
		}

		// Test Get Woo Products tool.
		if ( class_exists( 'WP_MCP_AI_Tool_Get_Woo_Products' ) ) {
			$tool   = new WP_MCP_AI_Tool_Get_Woo_Products();
			$result = $tool->execute( array(), array( 'user_id' => 1 ) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertEquals( 'wp_mcp_ai_woo_missing', $result->get_error_code() );
		}

		// Test Create Woo Product tool.
		if ( class_exists( 'WP_MCP_AI_Tool_Create_Woo_Product' ) ) {
			$tool   = new WP_MCP_AI_Tool_Create_Woo_Product();
			$result = $tool->execute( array( 'name' => 'Test' ), array( 'user_id' => 1 ) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertEquals( 'wp_mcp_ai_woo_missing', $result->get_error_code() );
		}
	}

	/**
	 * Test Elementor tools check for Elementor.
	 */
	public function test_elementor_tools_check_dependency() {
		if ( defined( 'ELEMENTOR_VERSION' ) || class_exists( '\\Elementor\\Plugin' ) ) {
			$this->markTestSkipped( 'Elementor is active, cannot test missing dependency' );
		}

		if ( class_exists( 'WP_MCP_AI_Tool_Get_Elementor_Templates' ) ) {
			$tool   = new WP_MCP_AI_Tool_Get_Elementor_Templates();
			$result = $tool->execute( array(), array( 'user_id' => 1 ) );

			$this->assertInstanceOf( WP_Error::class, $result, 'Elementor tool should return WP_Error when Elementor is not active' );
			$this->assertEquals( 'wp_mcp_ai_elementor_missing', $result->get_error_code() );
			$this->assertStringContainsString( 'Elementor', $result->get_error_message() );
		}
	}

	/**
	 * Test JetEngine tools check for JetEngine.
	 */
	public function test_jetengine_tools_check_dependency() {
		if ( function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' ) ) {
			$this->markTestSkipped( 'JetEngine is active, cannot test missing dependency' );
		}

		// Test Get JetEngine Items tool.
		if ( class_exists( 'WP_MCP_AI_Tool_Get_JetEngine_Items' ) ) {
			$tool   = new WP_MCP_AI_Tool_Get_JetEngine_Items();
			$result = $tool->execute( array( 'post_type' => 'test' ), array( 'user_id' => 1 ) );

			$this->assertInstanceOf( WP_Error::class, $result, 'JetEngine tool should return WP_Error when JetEngine is not active' );
			$this->assertEquals( 'wp_mcp_ai_jetengine_missing', $result->get_error_code() );
			$this->assertStringContainsString( 'JetEngine', $result->get_error_message() );
		}

		// Test List JetEngine Routes tool.
		if ( class_exists( 'WP_MCP_AI_Tool_List_JetEngine_Routes' ) ) {
			$tool   = new WP_MCP_AI_Tool_List_JetEngine_Routes();
			$result = $tool->execute( array(), array( 'user_id' => 1 ) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertEquals( 'wp_mcp_ai_jetengine_missing', $result->get_error_code() );
		}

		// Test Invoke JetEngine Route tool.
		if ( class_exists( 'WP_MCP_AI_Tool_Invoke_JetEngine_Route' ) ) {
			$tool   = new WP_MCP_AI_Tool_Invoke_JetEngine_Route();
			$result = $tool->execute( array( 'route_id' => 'test' ), array( 'user_id' => 1 ) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertEquals( 'wp_mcp_ai_jetengine_missing', $result->get_error_code() );
		}
	}

	/**
	 * Test that error messages are AI-friendly.
	 *
	 * Error messages should be clear and actionable for an AI assistant.
	 */
	public function test_dependency_error_messages_are_ai_friendly() {
		if ( class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is active, cannot test error message' );
		}

		if ( class_exists( 'WP_MCP_AI_Tool_Get_Woo_Orders' ) ) {
			$tool   = new WP_MCP_AI_Tool_Get_Woo_Orders();
			$result = $tool->execute( array(), array( 'user_id' => 1 ) );

			$this->assertInstanceOf( WP_Error::class, $result );

			$message = $result->get_error_message();

			// Message should be descriptive.
			$this->assertNotEmpty( $message );

			// Message should mention the missing plugin.
			$this->assertStringContainsString( 'WooCommerce', $message );

			// Message should indicate it's not active/available.
			$this->assertTrue(
				stripos( $message, 'not active' ) !== false || stripos( $message, 'not available' ) !== false,
				'Error message should indicate the plugin is not active or available'
			);
		}
	}

	/**
	 * Test that tools gracefully handle missing resources.
	 *
	 * For example, requesting a post that doesn't exist.
	 */
	public function test_tools_handle_missing_resources_gracefully() {
		// This test would check specific tools that access resources.
		// For now, we'll test the save_post tool with a non-existent post.

		if ( class_exists( 'WP_MCP_AI_Tool_Save_Post' ) ) {
			$tool = new WP_MCP_AI_Tool_Save_Post();

			// Try to update a non-existent post.
			$result = $tool->execute(
				array(
					'ID'      => 999999, // Non-existent ID.
					'title'   => 'Test',
					'content' => 'Test content',
				),
				array( 'user_id' => 1 )
			);

			// Should return an error or handle gracefully.
			// The behavior depends on the tool implementation.
			// At minimum, it should not throw an uncaught exception.
			$this->assertTrue(
				is_array( $result ) || is_wp_error( $result ),
				'Tool should return array or WP_Error, not throw exception'
			);
		}
	}

	/**
	 * Convert class name to tool slug.
	 *
	 * @param string $class_name Class name.
	 * @return string Tool slug.
	 */
	protected function class_to_slug( $class_name ) {
		// Convert WP_MCP_AI_Tool_Get_Woo_Orders to get-woo-orders.
		$slug = str_replace( 'WP_MCP_AI_Tool_', '', $class_name );
		$slug = str_replace( '_', '-', $slug );
		$slug = strtolower( $slug );

		return $slug;
	}
}
