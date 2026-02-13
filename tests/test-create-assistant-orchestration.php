<?php
/**
 * Tests for the create_assistant tool with orchestration mode.
 *
 * @package WP_MCP_AI
 */

require_once WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-create-assistant.php';

/**
 * Test case for multi-step orchestration in create_assistant tool.
 */
class WP_MCP_AI_Create_Assistant_Orchestration_Test extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that orchestration mode is disabled by default.
	 */
	public function test_orchestration_mode_disabled_by_default() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$result = $tool->execute(
			array(
				'title'       => 'Test Assistant',
				'description' => 'A test assistant',
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			$this->assertIsArray( $result );
			// Legacy mode should not include orchestration metadata.
			$this->assertArrayNotHasKey( 'orchestration', $result );

			// Clean up.
			if ( isset( $result['assistant_id'] ) ) {
				wp_delete_post( $result['assistant_id'], true );
			}
		}
	}

	/**
	 * Test that parameter schema includes orchestration params.
	 */
	public function test_parameter_schema_includes_orchestration_params() {
		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$schema = $tool->get_parameters_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'orchestration_mode', $schema['properties'] );
		$this->assertArrayHasKey( 'auto_research', $schema['properties'] );
		$this->assertArrayHasKey( 'optimize_instructions', $schema['properties'] );
		$this->assertArrayHasKey( 'optimize_tools', $schema['properties'] );
		$this->assertArrayHasKey( 'optimize', $schema['properties'] );
		$this->assertArrayHasKey( 'generate_avatar', $schema['properties'] );
	}

	/**
	 * Test that tool description mentions orchestration.
	 */
	public function test_tool_description_mentions_orchestration() {
		$tool        = new WP_MCP_AI_Tool_Create_Assistant();
		$description = $tool->get_description();

		$this->assertStringContainsString( 'orchestration', strtolower( $description ) );
		$this->assertStringContainsString( '6-step', strtolower( $description ) );
	}

	/**
	 * Test validation rejects missing title.
	 */
	public function test_validation_rejects_missing_title() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$result = $tool->execute(
			array(
				'orchestration_mode' => true,
				'description'        => 'Assistant without title',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Title is required', $result->get_error_message() );
	}

	/**
	 * Test validation rejects too-long title.
	 */
	public function test_validation_rejects_long_title() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$result = $tool->execute(
			array(
				'orchestration_mode' => true,
				'title'              => str_repeat( 'A', 201 ), // 201 characters.
				'description'        => 'Test',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( '200 characters', $result->get_error_message() );
	}

	/**
	 * Test validation rejects too many professions.
	 */
	public function test_validation_rejects_too_many_professions() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$result = $tool->execute(
			array(
				'orchestration_mode' => true,
				'title'              => 'Test Assistant',
				'professions'        => array( 'tax_advisor', 'accountant', 'lawyer', 'bookkeeper' ), // 4 professions.
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Maximum 3 professions', $result->get_error_message() );
	}

	/**
	 * Test validation rejects too many regions.
	 */
	public function test_validation_rejects_too_many_regions() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$result = $tool->execute(
			array(
				'orchestration_mode' => true,
				'title'              => 'Test Assistant',
				'regions'            => array( 'united_states', 'canada', 'jamaica' ), // 3 regions.
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Maximum 2 regions', $result->get_error_message() );
	}

	/**
	 * Test validation accepts valid assistant data.
	 */
	public function test_validation_accepts_valid_data() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$result = $tool->execute(
			array(
				'orchestration_mode' => true,
				'title'              => 'Valid Assistant',
				'professions'        => array( 'tax_advisor' ),
				'regions'            => array( 'united_states' ),
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'assistant_id', $result );
			$this->assertArrayHasKey( 'orchestration', $result );

			// Clean up.
			wp_delete_post( $result['assistant_id'], true );
		} else {
			$this->fail( 'Valid data should not produce error: ' . $result->get_error_message() );
		}
	}

	/**
	 * Test validation rejects invalid temperature.
	 */
	public function test_validation_rejects_invalid_temperature() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$result = $tool->execute(
			array(
				'orchestration_mode' => true,
				'title'              => 'Test Assistant',
				'description'        => 'Test',
				'temperature'        => 3.0, // Invalid: > 2.
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Temperature must be between 0 and 2', $result->get_error_message() );
	}

	/**
	 * Test that orchestration includes proper step logging.
	 */
	public function test_orchestration_includes_step_logging() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$result = $tool->execute(
			array(
				'orchestration_mode' => true,
				'title'              => 'Logged Assistant',
				'description'        => 'Test logging',
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			$this->assertArrayHasKey( 'orchestration', $result );
			$this->assertArrayHasKey( 'steps', $result['orchestration'] );
			$this->assertIsArray( $result['orchestration']['steps'] );
			$this->assertNotEmpty( $result['orchestration']['steps'] );

			// Check for expected steps.
			$steps = array_column( $result['orchestration']['steps'], 'step' );
			$this->assertContains( 'started', $steps );
			$this->assertContains( 'validation_complete', $steps );
			$this->assertContains( 'creation_complete', $steps );
			$this->assertContains( 'completed', $steps );

			// Clean up.
			wp_delete_post( $result['assistant_id'], true );
		}
	}

	/**
	 * Test instruction optimization step (mocked).
	 */
	public function test_instruction_optimization_step() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool = new WP_MCP_AI_Tool_Create_Assistant();

		// Use reflection to test protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'step_optimize_instructions' );
		$method->setAccessible( true );

		$arguments = array(
			'title'       => 'Test Assistant',
			'description' => 'A helpful assistant',
		);

		$result = $method->invoke( $tool, $arguments, array(), 'test-exec-id' );

		// Should return a string (optimized or original).
		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Test backward compatibility with legacy mode.
	 */
	public function test_backward_compatibility_with_legacy_mode() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$result = $tool->execute(
			array(
				'title'       => 'Legacy Assistant',
				'description' => 'Created without orchestration',
				// orchestration_mode not specified, defaults to false.
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'assistant_id', $result );
			$this->assertArrayHasKey( 'title', $result );
			$this->assertArrayNotHasKey( 'orchestration', $result );

			// Verify assistant was created.
			$assistant = get_post( $result['assistant_id'] );
			$this->assertInstanceOf( 'WP_Post', $assistant );
			$this->assertEquals( 'mcp_ai_assistant', $assistant->post_type );

			// Clean up.
			wp_delete_post( $result['assistant_id'], true );
		}
	}

	/**
	 * Test validation step using reflection.
	 */
	public function test_validation_step_method() {
		$tool = new WP_MCP_AI_Tool_Create_Assistant();

		// Use reflection to test protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'step_validate' );
		$method->setAccessible( true );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Test valid data.
		$valid_arguments = array(
			'title'       => 'Valid Assistant',
			'description' => 'A test',
		);
		$result          = $method->invoke( $tool, $valid_arguments, $user_id, 'test-exec-id' );
		$this->assertTrue( $result );

		// Test invalid data (missing title).
		$invalid_arguments = array(
			'description' => 'No title',
		);
		$result            = $method->invoke( $tool, $invalid_arguments, $user_id, 'test-exec-id' );
		$this->assertWPError( $result );
	}

	/**
	 * Test that orchestration works with async mode.
	 */
	public function test_orchestration_with_async_disabled() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$result = $tool->execute(
			array(
				'orchestration_mode' => true,
				'async'              => false, // Explicitly disable async.
				'title'              => 'Sync Orchestrated Assistant',
				'description'        => 'Test sync execution',
			),
			array( 'user_id' => $user_id )
		);

		if ( ! is_wp_error( $result ) ) {
			$this->assertIsArray( $result );
			$this->assertArrayHasKey( 'orchestration', $result );
			$this->assertArrayHasKey( 'assistant_id', $result );

			// Clean up.
			wp_delete_post( $result['assistant_id'], true );
		}
	}

	/**
	 * Test tool enhancement step using reflection.
	 */
	public function test_tool_enhancement_step() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Create a test assistant first.
		$assistant_id = self::factory()->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'draft',
				'post_author' => $user_id,
			)
		);

		$tool = new WP_MCP_AI_Tool_Create_Assistant();

		// Use reflection to test protected method.
		$reflection = new ReflectionClass( $tool );
		$method     = $reflection->getMethod( 'step_enhance_tools' );
		$method->setAccessible( true );

		$arguments = array(
			'professions' => array( 'tax_advisor' ),
		);

		$result = $method->invoke( $tool, $assistant_id, $arguments, array(), 'test-exec-id' );

		$this->assertTrue( $result );

		// Verify tools were added.
		$tools = get_post_meta( $assistant_id, '_wp_mcp_ai_tools', true );
		$this->assertIsArray( $tools );
		$this->assertNotEmpty( $tools );
		$this->assertContains( 'web_search', $tools );

		// Clean up.
		wp_delete_post( $assistant_id, true );
	}

	/**
	 * Test validation requires at least one context field.
	 */
	public function test_validation_requires_context() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$tool   = new WP_MCP_AI_Tool_Create_Assistant();
		$result = $tool->execute(
			array(
				'orchestration_mode' => true,
				'title'              => 'No Context Assistant',
				// No professions, regions, description, or system_prompt.
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertEquals( 'validation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Must provide at least', $result->get_error_message() );
	}
}
