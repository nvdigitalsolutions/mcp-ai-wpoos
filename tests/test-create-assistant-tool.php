<?php
/**
 * Tests for Create Assistant tool.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Create_Assistant_Tool_Test extends WP_UnitTestCase {

	/**
	 * Test that the tool is registered.
	 */
	public function test_create_assistant_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );

		$this->assertNotNull( $tool, 'The create_assistant tool should be registered.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );
	}

	/**
	 * Test tool metadata.
	 */
	public function test_create_assistant_tool_metadata() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );
		$this->assertNotNull( $tool );

		$this->assertSame( 'create_assistant', $tool->get_slug() );
		$this->assertNotEmpty( $tool->get_name() );
		$this->assertNotEmpty( $tool->get_description() );
	}

	/**
	 * Test parameter schema structure.
	 */
	public function test_create_assistant_parameter_schema() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool   = $registry->get_tool( 'create_assistant' );
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );

		// Check required fields.
		$this->assertContains( 'title', $schema['required'] );
		$this->assertContains( 'professions', $schema['required'] );
		$this->assertContains( 'regions', $schema['required'] );

		// Check key properties exist.
		$this->assertArrayHasKey( 'title', $schema['properties'] );
		$this->assertArrayHasKey( 'professions', $schema['properties'] );
		$this->assertArrayHasKey( 'regions', $schema['properties'] );
		$this->assertArrayHasKey( 'industry_focus', $schema['properties'] );
		$this->assertArrayHasKey( 'async', $schema['properties'] );
	}

	/**
	 * Test profession array validation.
	 */
	public function test_professions_has_valid_enum() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool   = $registry->get_tool( 'create_assistant' );
		$schema = $tool->get_parameters_schema();

		$professions_schema = $schema['properties']['professions'];
		$this->assertArrayHasKey( 'items', $professions_schema );
		$this->assertArrayHasKey( 'enum', $professions_schema['items'] );

		$enum = $professions_schema['items']['enum'];
		$this->assertContains( 'tax_advisor', $enum );
		$this->assertContains( 'accountant', $enum );
		$this->assertContains( 'customs_broker', $enum );
		$this->assertContains( 'lawyer', $enum );
	}

	/**
	 * Test regions array validation.
	 */
	public function test_regions_has_valid_enum() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool   = $registry->get_tool( 'create_assistant' );
		$schema = $tool->get_parameters_schema();

		$regions_schema = $schema['properties']['regions'];
		$this->assertArrayHasKey( 'items', $regions_schema );
		$this->assertArrayHasKey( 'enum', $regions_schema['items'] );

		$enum = $regions_schema['items']['enum'];
		$this->assertContains( 'jamaica', $enum );
		$this->assertContains( 'sri_lanka', $enum );
		$this->assertContains( 'united_states', $enum );
		$this->assertContains( 'global', $enum );
	}

	/**
	 * Test max items validation for professions and regions.
	 */
	public function test_professions_and_regions_max_items() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool   = $registry->get_tool( 'create_assistant' );
		$schema = $tool->get_parameters_schema();

		// Professions max 3.
		$this->assertArrayHasKey( 'maxItems', $schema['properties']['professions'] );
		$this->assertSame( 3, $schema['properties']['professions']['maxItems'] );

		// Regions max 2.
		$this->assertArrayHasKey( 'maxItems', $schema['properties']['regions'] );
		$this->assertSame( 2, $schema['properties']['regions']['maxItems'] );
	}

	/**
	 * Test tool capability flags.
	 */
	public function test_create_assistant_capability_flags() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );

		if ( $tool instanceof WP_MCP_AI_Tool_Capability_Flags_Interface ) {
			$flags = $tool->get_capability_flags();

			$this->assertIsArray( $flags );
			$this->assertContains( 'write', $flags );
			$this->assertContains( 'local-only', $flags );
			$this->assertContains( 'requires-capability', $flags );
			$this->assertContains( 'state-changing', $flags );
			$this->assertContains( 'async-capable', $flags );
		}
	}

	/**
	 * Test that tool requires authentication.
	 */
	public function test_create_assistant_requires_authenticated_user() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );

		$arguments = array(
			'title'       => 'Test Assistant',
			'professions' => array( 'tax_advisor' ),
			'regions'     => array( 'jamaica' ),
		);

		$context = array( 'user_id' => 0 );

		$result = $tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test missing required fields.
	 */
	public function test_create_assistant_requires_title() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );

		// Create a test user with edit_posts capability.
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$arguments = array(
			'professions' => array( 'tax_advisor' ),
			'regions'     => array( 'jamaica' ),
		);

		$context = array( 'user_id' => $user_id );

		$result = $tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_missing_title', $result->get_error_code() );
	}

	/**
	 * Test profession limit validation.
	 */
	public function test_create_assistant_profession_limit() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$arguments = array(
			'title'       => 'Test Assistant',
			'professions' => array( 'tax_advisor', 'accountant', 'lawyer', 'customs_broker' ), // 4 professions - exceeds limit.
			'regions'     => array( 'jamaica' ),
		);

		$context = array( 'user_id' => $user_id );

		$result = $tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_too_many_professions', $result->get_error_code() );
	}

	/**
	 * Test region limit validation.
	 */
	public function test_create_assistant_region_limit() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$arguments = array(
			'title'       => 'Test Assistant',
			'professions' => array( 'tax_advisor' ),
			'regions'     => array( 'jamaica', 'sri_lanka', 'united_states' ), // 3 regions - exceeds limit.
		);

		$context = array( 'user_id' => $user_id );

		$result = $tool->execute( $arguments, $context );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_mcp_ai_too_many_regions', $result->get_error_code() );
	}

	/**
	 * Test successful assistant creation (synchronous).
	 */
	public function test_create_assistant_success() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$arguments = array(
			'title'          => 'Jamaica Tax Assistant',
			'professions'    => array( 'tax_advisor' ),
			'regions'        => array( 'jamaica' ),
			'industry_focus' => 'Small Business',
			'async'          => false,
		);

		$context = array( 'user_id' => $user_id );

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'assistant_id', $result );
		$this->assertArrayHasKey( 'title', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertSame( 'draft', $result['status'] );

		// Verify the post was created.
		$assistant_id = $result['assistant_id'];
		$post         = get_post( $assistant_id );
		$this->assertNotNull( $post );
		$this->assertSame( 'mcp_ai_assistant', $post->post_type );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 'Jamaica Tax Assistant', $post->post_title );

		// Verify meta was saved.
		$instructions = get_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', true );
		$this->assertNotEmpty( $instructions );
		$this->assertStringContainsString( 'Tax', $instructions );
		$this->assertStringContainsString( 'Jamaica', $instructions );

		// Verify documents were created.
		$documents = get_post_meta( $assistant_id, '_wp_mcp_ai_memory_files', true );
		$this->assertIsArray( $documents );
		$this->assertNotEmpty( $documents );
	}

	/**
	 * Test async scheduling.
	 */
	public function test_create_assistant_async_scheduling() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );

		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );

		$arguments = array(
			'title'       => 'Async Test Assistant',
			'professions' => array( 'accountant' ),
			'regions'     => array( 'canada' ),
			'async'       => true,
		);

		$context = array( 'user_id' => $user_id );

		$result = $tool->execute( $arguments, $context );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'job_id', $result );
		$this->assertArrayHasKey( 'status', $result );
		$this->assertSame( 'scheduled', $result['status'] );
		$this->assertArrayHasKey( 'scheduled_for', $result );
	}

	/**
	 * Test that async hook handler is registered.
	 */
	public function test_create_assistant_async_hook_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'create_assistant' );

		// Verify that the hook is registered after tool initialization.
		$this->assertTrue(
			has_action( 'wp_mcp_ai_create_assistant_async', array( 'WP_MCP_AI_Tool_Create_Assistant', 'process_async_creation' ) ) !== false,
			'The wp_mcp_ai_create_assistant_async hook should be registered after tool initialization.'
		);
	}

	/**
	 * Test that creating multiple instances does not register the hook multiple times.
	 */
	public function test_create_assistant_hook_registered_only_once() {
		global $wp_filter;

		// Clear any existing registrations from previous tests.
		if ( isset( $wp_filter['wp_mcp_ai_create_assistant_async'] ) ) {
			unset( $wp_filter['wp_mcp_ai_create_assistant_async'] );
		}

		// Create first instance.
		$tool1 = new WP_MCP_AI_Tool_Create_Assistant();

		// Get the number of callbacks registered.
		$callbacks_after_first = isset( $wp_filter['wp_mcp_ai_create_assistant_async'] )
			? count( $wp_filter['wp_mcp_ai_create_assistant_async']->callbacks )
			: 0;

		// Create second instance (simulating what happens in process_async_creation).
		$tool2 = new WP_MCP_AI_Tool_Create_Assistant();

		// Get the number of callbacks after second instance.
		$callbacks_after_second = isset( $wp_filter['wp_mcp_ai_create_assistant_async'] )
			? count( $wp_filter['wp_mcp_ai_create_assistant_async']->callbacks )
			: 0;

		// Verify the hook is registered.
		$this->assertGreaterThan( 0, $callbacks_after_first, 'Hook should be registered after first instance.' );

		// Verify the number of callbacks hasn't increased.
		$this->assertEquals(
			$callbacks_after_first,
			$callbacks_after_second,
			'Creating a second instance should not register the hook again.'
		);

		// Create third instance to be thorough.
		$tool3 = new WP_MCP_AI_Tool_Create_Assistant();

		$callbacks_after_third = isset( $wp_filter['wp_mcp_ai_create_assistant_async'] )
			? count( $wp_filter['wp_mcp_ai_create_assistant_async']->callbacks )
			: 0;

		$this->assertEquals(
			$callbacks_after_first,
			$callbacks_after_third,
			'Creating a third instance should not register the hook again.'
		);
	}
}
