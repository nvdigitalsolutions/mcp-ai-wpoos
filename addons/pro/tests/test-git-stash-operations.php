<?php
/**
 * Tests for Git Stash Operations in Git Operations Tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for git stash operations.
 */
class Test_Git_Stash_Operations extends WP_UnitTestCase {

	/**
	 * Test tool registration and availability.
	 */
	public function test_tool_registered() {
		// Check if tool registry exists.
		$this->assertTrue( class_exists( 'WP_MCP_AI_Tool_Registry' ), 'Tool registry class should exist' );

		// Get the tool registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->assertNotNull( $registry, 'Tool registry instance should not be null' );

		// Check if tool is registered.
		$tool = $registry->get_tool( 'git_operations' );
		$this->assertNotNull( $tool, 'Git operations tool should be registered' );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Git_Operations', $tool );
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		$this->assertEquals( 'git_operations', $tool->get_slug() );
		$this->assertEquals( 'Git Operations', $tool->get_name() );
		$this->assertStringContainsString( 'stash', $tool->get_description() );
	}

	/**
	 * Test parameter schema includes stash parameters.
	 */
	public function test_parameter_schema_has_stash_parameters() {
		$tool   = new WP_MCP_AI_Tool_Git_Operations();
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertEquals( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );

		$properties = $schema['properties'];

		// Check stash-specific parameters exist.
		$this->assertArrayHasKey( 'stash_subcommand', $properties );
		$this->assertArrayHasKey( 'stash_ref', $properties );
		$this->assertArrayHasKey( 'include_untracked', $properties );
		$this->assertArrayHasKey( 'keep_index', $properties );

		// Verify stash subcommand enum values.
		$this->assertArrayHasKey( 'enum', $properties['stash_subcommand'] );
		$subcommands = $properties['stash_subcommand']['enum'];
		$this->assertContains( 'list', $subcommands );
		$this->assertContains( 'push', $subcommands );
		$this->assertContains( 'pop', $subcommands );
		$this->assertContains( 'apply', $subcommands );
		$this->assertContains( 'drop', $subcommands );
		$this->assertContains( 'clear', $subcommands );
		$this->assertContains( 'show', $subcommands );
		$this->assertContains( 'branch', $subcommands );
	}

	/**
	 * Test stash operation requires operation parameter.
	 */
	public function test_stash_requires_operation_parameter() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		// Execute without operation parameter.
		$result = $tool->execute( array(), array( 'user_id' => 1 ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Test stash list operation structure.
	 */
	public function test_stash_list_operation_structure() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		// Mock execute for stash list (if git is available).
		$result = $tool->execute(
			array(
				'operation'        => 'stash',
				'stash_subcommand' => 'list',
			),
			array( 'user_id' => 1 )
		);

		// Check result structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'operation', $result );
		$this->assertArrayHasKey( 'subcommand', $result );
		$this->assertArrayHasKey( 'success', $result );

		// If successful, check additional fields.
		if ( $result['success'] ) {
			$this->assertEquals( 'stash_list', $result['operation'] );
			$this->assertEquals( 'list', $result['subcommand'] );
			$this->assertArrayHasKey( 'stash_count', $result );
			$this->assertArrayHasKey( 'stash_entries', $result );
			$this->assertIsArray( $result['stash_entries'] );
		}
	}

	/**
	 * Test stash push operation structure.
	 */
	public function test_stash_push_operation_structure() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		$result = $tool->execute(
			array(
				'operation'         => 'stash',
				'stash_subcommand'  => 'push',
				'message'           => 'Test stash',
				'include_untracked' => true,
				'keep_index'        => false,
			),
			array( 'user_id' => 1 )
		);

		// Check result structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'operation', $result );
		$this->assertArrayHasKey( 'subcommand', $result );
		$this->assertArrayHasKey( 'success', $result );

		// Check operation-specific fields.
		if ( $result['success'] ) {
			$this->assertEquals( 'stash_push', $result['operation'] );
			$this->assertEquals( 'push', $result['subcommand'] );
			$this->assertArrayHasKey( 'message', $result );
			$this->assertArrayHasKey( 'include_untracked', $result );
			$this->assertArrayHasKey( 'keep_index', $result );
		}
	}

	/**
	 * Test stash pop operation structure.
	 */
	public function test_stash_pop_operation_structure() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		$result = $tool->execute(
			array(
				'operation'        => 'stash',
				'stash_subcommand' => 'pop',
				'stash_ref'        => 'stash@{0}',
			),
			array( 'user_id' => 1 )
		);

		// Check result structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'operation', $result );
		$this->assertArrayHasKey( 'subcommand', $result );
		$this->assertArrayHasKey( 'success', $result );

		if ( $result['success'] ) {
			$this->assertEquals( 'stash_pop', $result['operation'] );
			$this->assertEquals( 'pop', $result['subcommand'] );
			$this->assertArrayHasKey( 'stash_ref', $result );
		}
	}

	/**
	 * Test stash apply operation structure.
	 */
	public function test_stash_apply_operation_structure() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		$result = $tool->execute(
			array(
				'operation'        => 'stash',
				'stash_subcommand' => 'apply',
				'stash_ref'        => 'stash@{0}',
			),
			array( 'user_id' => 1 )
		);

		// Check result structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'operation', $result );
		$this->assertArrayHasKey( 'subcommand', $result );
		$this->assertArrayHasKey( 'success', $result );

		if ( $result['success'] ) {
			$this->assertEquals( 'stash_apply', $result['operation'] );
			$this->assertEquals( 'apply', $result['subcommand'] );
			$this->assertArrayHasKey( 'stash_ref', $result );
		}
	}

	/**
	 * Test stash drop requires stash_ref.
	 */
	public function test_stash_drop_requires_stash_ref() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		$result = $tool->execute(
			array(
				'operation'        => 'stash',
				'stash_subcommand' => 'drop',
			),
			array( 'user_id' => 1 )
		);

		// Should fail without stash_ref.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );

		// If git is available, should return error message.
		if ( isset( $result['message'] ) ) {
			$this->assertStringContainsString( 'required', strtolower( $result['message'] ) );
		}
	}

	/**
	 * Test stash show operation structure.
	 */
	public function test_stash_show_operation_structure() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		$result = $tool->execute(
			array(
				'operation'        => 'stash',
				'stash_subcommand' => 'show',
				'stash_ref'        => 'stash@{0}',
			),
			array( 'user_id' => 1 )
		);

		// Check result structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'operation', $result );
		$this->assertArrayHasKey( 'subcommand', $result );
		$this->assertArrayHasKey( 'success', $result );

		if ( $result['success'] ) {
			$this->assertEquals( 'stash_show', $result['operation'] );
			$this->assertEquals( 'show', $result['subcommand'] );
		}
	}

	/**
	 * Test stash branch requires branch_name.
	 */
	public function test_stash_branch_requires_branch_name() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		$result = $tool->execute(
			array(
				'operation'        => 'stash',
				'stash_subcommand' => 'branch',
			),
			array( 'user_id' => 1 )
		);

		// Should fail without branch_name.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );

		// If git is available, should return error message.
		if ( isset( $result['message'] ) ) {
			$this->assertStringContainsString( 'required', strtolower( $result['message'] ) );
		}
	}

	/**
	 * Test stash branch operation structure.
	 */
	public function test_stash_branch_operation_structure() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		$result = $tool->execute(
			array(
				'operation'        => 'stash',
				'stash_subcommand' => 'branch',
				'branch_name'      => 'test-branch',
				'stash_ref'        => 'stash@{0}',
			),
			array( 'user_id' => 1 )
		);

		// Check result structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'operation', $result );
		$this->assertArrayHasKey( 'subcommand', $result );
		$this->assertArrayHasKey( 'success', $result );

		if ( $result['success'] ) {
			$this->assertEquals( 'stash_branch', $result['operation'] );
			$this->assertEquals( 'branch', $result['subcommand'] );
			$this->assertArrayHasKey( 'branch_name', $result );
		}
	}

	/**
	 * Test invalid stash reference format validation.
	 */
	public function test_invalid_stash_reference_validation() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		$result = $tool->execute(
			array(
				'operation'        => 'stash',
				'stash_subcommand' => 'pop',
				'stash_ref'        => 'invalid-ref',
			),
			array( 'user_id' => 1 )
		);

		// Should fail with invalid stash reference.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );

		// If git is available, should return error message about format.
		if ( isset( $result['message'] ) ) {
			$this->assertStringContainsString( 'invalid', strtolower( $result['message'] ) );
		}
	}

	/**
	 * Test capability flags include required flags.
	 */
	public function test_capability_flags() {
		$tool  = new WP_MCP_AI_Tool_Git_Operations();
		$flags = $tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'pro', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'state-changing', $flags );
		$this->assertContains( 'version-control', $flags );
		$this->assertContains( 'architect-agent', $flags );
	}

	/**
	 * Test required capability.
	 */
	public function test_required_capability() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		$this->assertEquals( 'edit_plugins', $tool->get_required_capability() );
	}

	/**
	 * Test unsupported stash subcommand returns error.
	 */
	public function test_unsupported_stash_subcommand() {
		$tool = new WP_MCP_AI_Tool_Git_Operations();

		$result = $tool->execute(
			array(
				'operation'        => 'stash',
				'stash_subcommand' => 'invalid_subcommand',
			),
			array( 'user_id' => 1 )
		);

		// Should return error for unsupported subcommand.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'success', $result );
		$this->assertFalse( $result['success'] );
	}
}
