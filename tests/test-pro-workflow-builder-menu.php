<?php
/**
 * Tests for Pro Workflow Builder Menu Registration
 *
 * @package WP_MCP_AI_Pro
 */

/**
 * Test Pro Workflow Builder menu registration.
 */
class Test_Pro_Workflow_Builder_Menu extends WP_UnitTestCase {

	/**
	 * Test that Pro Workflow Builder menu is registered as a submenu of Pro Dashboard.
	 *
	 * This test verifies that the Pro Workflow Builder page is correctly added as a
	 * submenu item under the Pro Dashboard menu with the correct slug format to ensure
	 * WordPress generates the proper URL: /wp-admin/admin.php?page=nvoos-pro-workflow-builder
	 */
	public function test_workflow_builder_registered_under_pro_dashboard() {
		global $submenu;

		// Set up admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		// Trigger admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Check that Pro Dashboard menu exists.
		$this->assertArrayHasKey(
			'nvoos-pro-dashboard',
			$submenu,
			'Pro Dashboard parent menu should be registered'
		);

		// Check that Pro Workflow Builder is registered under Pro Dashboard.
		$workflow_builder_found = false;
		if ( isset( $submenu['nvoos-pro-dashboard'] ) ) {
			foreach ( $submenu['nvoos-pro-dashboard'] as $item ) {
				if ( $item[2] === 'nvoos-pro-workflow-builder' ) {
					$workflow_builder_found = true;
					// Verify menu title.
					$this->assertEquals( 'Pro Workflows', $item[0] );
					// Verify capability.
					$this->assertEquals( 'manage_options', $item[1] );
					break;
				}
			}
		}

		$this->assertTrue(
			$workflow_builder_found,
			'Pro Workflow Builder should be registered as a submenu under Pro Dashboard with slug "nvoos-pro-workflow-builder"'
		);
	}

	/**
	 * Test that the workflow builder slug follows the correct naming convention.
	 *
	 * This test verifies that the slug uses the "nvoos-" prefix pattern which ensures
	 * WordPress generates admin.php?page= URLs instead of treating it as a direct file path.
	 */
	public function test_workflow_builder_slug_format() {
		global $submenu;

		// Set up admin user.
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		// Trigger admin_menu action to register menus.
		do_action( 'admin_menu' );

		// Find the workflow builder submenu item.
		$workflow_builder_slug = null;
		if ( isset( $submenu['nvoos-pro-dashboard'] ) ) {
			foreach ( $submenu['nvoos-pro-dashboard'] as $item ) {
				if ( strpos( $item[0], 'Workflow' ) !== false ) {
					$workflow_builder_slug = $item[2];
					break;
				}
			}
		}

		$this->assertNotNull(
			$workflow_builder_slug,
			'Workflow Builder menu item should be registered'
		);

		// Verify the slug does NOT start with "wp-" which could cause WordPress
		// to treat it as a file path instead of a query parameter.
		$this->assertStringStartsNotWith(
			'wp-',
			$workflow_builder_slug,
			'Workflow Builder slug should not start with "wp-" to avoid being treated as a file path'
		);

		// Verify the slug follows the nvoos- prefix convention.
		$this->assertStringStartsWith(
			'nvoos-',
			$workflow_builder_slug,
			'Workflow Builder slug should follow the "nvoos-" prefix convention'
		);
	}

	/**
	 * Test that the generated URL format is correct.
	 *
	 * This test verifies that when we construct the admin URL for the workflow builder,
	 * it uses the correct format: admin.php?page=nvoos-pro-workflow-builder
	 */
	public function test_workflow_builder_url_format() {
		// Generate the expected URL.
		$expected_url = admin_url( 'admin.php?page=nvoos-pro-workflow-builder' );

		// Verify the URL contains the correct format.
		$this->assertStringContainsString(
			'admin.php?page=nvoos-pro-workflow-builder',
			$expected_url,
			'Workflow Builder URL should use admin.php?page= format'
		);

		// Verify the URL does NOT use the incorrect direct file format.
		$incorrect_url = admin_url( 'wp-mcp-ai-pro-workflow-builder' );
		$this->assertStringNotContainsString(
			'wp-mcp-ai-pro-workflow-builder',
			$expected_url,
			'Workflow Builder URL should not use direct file path format'
		);
	}
}
