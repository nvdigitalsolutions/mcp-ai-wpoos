<?php
/**
 * Tests for NPM package management tools.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for NPM package management tools.
 */
class Test_NPM_Tools extends WP_UnitTestCase {
	/**
	 * NPM Install tool instance.
	 *
	 * @var WP_MCP_AI_Tool_NPM_Install_Package
	 */
	private $install_tool;

	/**
	 * NPM Update tool instance.
	 *
	 * @var WP_MCP_AI_Tool_NPM_Update_Package
	 */
	private $update_tool;

	/**
	 * NPM Remove tool instance.
	 *
	 * @var WP_MCP_AI_Tool_NPM_Remove_Package
	 */
	private $remove_tool;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber_user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-npm-install-package.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-npm-update-package.php';
		require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-npm-remove-package.php';

		$this->install_tool = new WP_MCP_AI_Tool_NPM_Install_Package();
		$this->update_tool  = new WP_MCP_AI_Tool_NPM_Update_Package();
		$this->remove_tool  = new WP_MCP_AI_Tool_NPM_Remove_Package();

		// Create test users.
		$this->admin_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);

		$this->subscriber_user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);
	}

	/**
	 * Test NPM Install tool metadata.
	 */
	public function test_npm_install_tool_metadata() {
		$this->assertSame( 'npm_install_package', $this->install_tool->get_slug() );
		$this->assertNotEmpty( $this->install_tool->get_name() );
		$this->assertNotEmpty( $this->install_tool->get_description() );

		$schema = $this->install_tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'packages', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'packages', $schema['required'] );
	}

	/**
	 * Test NPM Update tool metadata.
	 */
	public function test_npm_update_tool_metadata() {
		$this->assertSame( 'npm_update_package', $this->update_tool->get_slug() );
		$this->assertNotEmpty( $this->update_tool->get_name() );
		$this->assertNotEmpty( $this->update_tool->get_description() );

		$schema = $this->update_tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'packages', $schema['properties'] );
	}

	/**
	 * Test NPM Remove tool metadata.
	 */
	public function test_npm_remove_tool_metadata() {
		$this->assertSame( 'npm_remove_package', $this->remove_tool->get_slug() );
		$this->assertNotEmpty( $this->remove_tool->get_name() );
		$this->assertNotEmpty( $this->remove_tool->get_description() );

		$schema = $this->remove_tool->get_parameters_schema();
		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'packages', $schema['properties'] );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'packages', $schema['required'] );
	}

	/**
	 * Test permission check for NPM Install tool.
	 */
	public function test_npm_install_requires_manage_options() {
		$context = array( 'user_id' => $this->subscriber_user_id );
		$result  = $this->install_tool->execute(
			array( 'packages' => array( 'lodash' ) ),
			$context
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test permission check for NPM Update tool.
	 */
	public function test_npm_update_requires_manage_options() {
		$context = array( 'user_id' => $this->subscriber_user_id );
		$result  = $this->update_tool->execute(
			array( 'packages' => array( 'lodash' ) ),
			$context
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test permission check for NPM Remove tool.
	 */
	public function test_npm_remove_requires_manage_options() {
		$context = array( 'user_id' => $this->subscriber_user_id );
		$result  = $this->remove_tool->execute(
			array( 'packages' => array( 'lodash' ) ),
			$context
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test NPM Install with invalid packages parameter.
	 */
	public function test_npm_install_with_invalid_packages() {
		$context = array( 'user_id' => $this->admin_user_id );

		// Empty packages.
		$result = $this->install_tool->execute( array( 'packages' => array() ), $context );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_packages', $result->get_error_code() );

		// Non-array packages.
		$result = $this->install_tool->execute( array( 'packages' => 'lodash' ), $context );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_packages', $result->get_error_code() );
	}

	/**
	 * Test NPM Remove with invalid packages parameter.
	 */
	public function test_npm_remove_with_invalid_packages() {
		$context = array( 'user_id' => $this->admin_user_id );

		// Empty packages.
		$result = $this->remove_tool->execute( array( 'packages' => array() ), $context );
		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_packages', $result->get_error_code() );
	}

	/**
	 * Test package specification validation.
	 */
	public function test_package_spec_validation() {
		$context = array( 'user_id' => $this->admin_user_id );

		// Valid package specs should not immediately fail on validation.
		// (They may fail later if npm is not available, which is expected).
		$valid_specs = array(
			'lodash',
			'@babel/core',
			'react@18.0.0',
			'typescript@^4.5.0',
			'webpack@latest',
		);

		foreach ( $valid_specs as $spec ) {
			$result = $this->install_tool->execute(
				array( 'packages' => array( $spec ) ),
				$context
			);

			// Should fail on npm availability or package.json, not on package spec validation.
			if ( is_wp_error( $result ) ) {
				$this->assertNotSame( 'wp_mcp_ai_invalid_package_spec', $result->get_error_code() );
			}
		}
	}

	/**
	 * Test invalid package specifications.
	 */
	public function test_invalid_package_specs() {
		$context = array( 'user_id' => $this->admin_user_id );

		// Invalid package specs.
		$invalid_specs = array(
			'../malicious',
			'package with spaces',
			'',
		);

		foreach ( $invalid_specs as $spec ) {
			$result = $this->install_tool->execute(
				array( 'packages' => array( $spec ) ),
				$context
			);

			$this->assertWPError( $result );
			// Should be invalid spec or invalid packages error.
			$this->assertTrue(
				in_array(
					$result->get_error_code(),
					array( 'wp_mcp_ai_invalid_package_spec', 'wp_mcp_ai_invalid_packages' ),
					true
				)
			);
		}
	}

	/**
	 * Test working directory validation.
	 */
	public function test_working_directory_validation() {
		$context = array( 'user_id' => $this->admin_user_id );

		// Try to use directory traversal.
		$result = $this->install_tool->execute(
			array(
				'packages'    => array( 'lodash' ),
				'working_dir' => '../../../etc',
			),
			$context
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_path', $result->get_error_code() );
	}

	/**
	 * Test multisite access control.
	 */
	public function test_multisite_access_control() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite tests require multisite installation' );
		}

		// Create user on different blog.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Switch to different blog.
		$blog_id = $this->factory->blog->create();
		switch_to_blog( $blog_id );

		$context = array( 'user_id' => $user_id );
		$result  = $this->install_tool->execute(
			array( 'packages' => array( 'lodash' ) ),
			$context
		);

		restore_current_blog();

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_wrong_site', $result->get_error_code() );
	}

	/**
	 * Test tools are registered in the registry.
	 */
	public function test_tools_registered_in_registry() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$this->assertTrue( $registry->is_tool_registered( 'npm_install_package' ) );
		$this->assertTrue( $registry->is_tool_registered( 'npm_update_package' ) );
		$this->assertTrue( $registry->is_tool_registered( 'npm_remove_package' ) );

		// Verify tools are in wordpress-core group.
		$group_map = $registry->get_tool_group_map();
		$this->assertArrayHasKey( 'npm_install_package', $group_map );
		$this->assertSame( 'wordpress-core', $group_map['npm_install_package'] );
		$this->assertArrayHasKey( 'npm_update_package', $group_map );
		$this->assertSame( 'wordpress-core', $group_map['npm_update_package'] );
		$this->assertArrayHasKey( 'npm_remove_package', $group_map );
		$this->assertSame( 'wordpress-core', $group_map['npm_remove_package'] );
	}
}
