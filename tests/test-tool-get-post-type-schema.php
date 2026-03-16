<?php
/**
 * Tests for get_post_type_schema tool.
 *
 * @package WP_MCP_AI
 */

/**
 * Test get_post_type_schema tool functionality.
 */
class Test_Tool_Get_Post_Type_Schema extends WP_UnitTestCase {

	/**
	 * Tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Get_Post_Type_Schema
	 */
	private $tool;

	/**
	 * Test user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		require_once dirname( __DIR__ ) . '/includes/tools/class-wp-mcp-ai-tool-get-post-type-schema.php';

		$this->user_id = $this->factory->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		wp_set_current_user( $this->user_id );

		$this->tool = new WP_MCP_AI_Tool_Get_Post_Type_Schema();
	}

	/**
	 * Test tool metadata.
	 */
	public function test_tool_metadata() {
		$this->assertEquals( 'get_post_type_schema', $this->tool->get_slug() );
		$this->assertNotEmpty( $this->tool->get_name() );
		$this->assertNotEmpty( $this->tool->get_description() );
	}

	/**
	 * Test parameters schema.
	 */
	public function test_parameters_schema() {
		$schema = $this->tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'post_type', $schema['required'] );
		$this->assertArrayHasKey( 'post_type', $schema['properties'] );
		$this->assertArrayHasKey( 'include_meta_schema', $schema['properties'] );
	}

	/**
	 * Test capability flags.
	 */
	public function test_capability_flags() {
		$flags = $this->tool->get_capability_flags();

		$this->assertIsArray( $flags );
		$this->assertContains( 'read-only', $flags );
		$this->assertContains( 'local-only', $flags );
		$this->assertContains( 'requires-capability', $flags );
		$this->assertContains( 'cacheable', $flags );
	}

	/**
	 * Test get_definition returns expected keys.
	 */
	public function test_get_definition() {
		$definition = $this->tool->get_definition();

		$this->assertIsArray( $definition );
		$this->assertArrayHasKey( 'toolkit', $definition );
		$this->assertArrayHasKey( 'risk_level', $definition );
		$this->assertEquals( 'content_publishing', $definition['toolkit'] );
		$this->assertEquals( 'info', $definition['risk_level'] );
	}

	/**
	 * Test that the tool returns an error when post_type is missing.
	 */
	public function test_missing_post_type_returns_error() {
		$result = $this->tool->execute( array(), array( 'user_id' => $this->user_id ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_missing_param', $result->get_error_code() );
	}

	/**
	 * Test that the tool returns an error for an unregistered post type.
	 */
	public function test_unregistered_post_type_returns_error() {
		$result = $this->tool->execute(
			array( 'post_type' => 'this_post_type_does_not_exist' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_not_found', $result->get_error_code() );
	}

	/**
	 * Test that the tool returns schema for the built-in 'post' post type.
	 */
	public function test_returns_schema_for_post_type() {
		$result = $this->tool->execute(
			array( 'post_type' => 'post' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'post', $result['post_type'] );
		$this->assertArrayHasKey( 'label', $result );
		$this->assertArrayHasKey( 'labels', $result );
		$this->assertArrayHasKey( 'capabilities', $result );
		$this->assertArrayHasKey( 'supports', $result );
		$this->assertArrayHasKey( 'taxonomies', $result );
		$this->assertArrayHasKey( 'statuses', $result );
		$this->assertArrayHasKey( 'message', $result );
	}

	/**
	 * Test that the 'post' post type reports standard supported features.
	 */
	public function test_post_type_supports_includes_expected_features() {
		$result = $this->tool->execute(
			array( 'post_type' => 'post' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertContains( 'title', $result['supports'] );
		$this->assertContains( 'editor', $result['supports'] );
	}

	/**
	 * Test that the 'post' post type includes the category taxonomy.
	 */
	public function test_post_type_includes_category_taxonomy() {
		$result = $this->tool->execute(
			array( 'post_type' => 'post' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertArrayHasKey( 'category', $result['taxonomies'] );
		$this->assertArrayHasKey( 'post_tag', $result['taxonomies'] );
	}

	/**
	 * Test that meta_schema is returned when a filter provides it.
	 */
	public function test_meta_schema_filter_is_applied() {
		$test_schema = array(
			'_my_meta_key' => array(
				'meta_key'    => '_my_meta_key',
				'label'       => 'My Meta',
				'type'        => 'string',
				'description' => 'A test meta field.',
			),
		);

		add_filter(
			'wp_mcp_ai_post_type_meta_schema',
			function ( $schema, $post_type ) use ( $test_schema ) {
				if ( 'post' === $post_type ) {
					return array_merge( $schema, $test_schema );
				}
				return $schema;
			},
			10,
			2
		);

		$result = $this->tool->execute(
			array(
				'post_type'           => 'post',
				'include_meta_schema' => true,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertArrayHasKey( 'meta_schema', $result );
		$this->assertArrayHasKey( '_my_meta_key', $result['meta_schema'] );
	}

	/**
	 * Test that meta_schema is not present when include_meta_schema is false.
	 */
	public function test_meta_schema_excluded_when_not_requested() {
		$result = $this->tool->execute(
			array(
				'post_type'           => 'post',
				'include_meta_schema' => false,
			),
			array( 'user_id' => $this->user_id )
		);

		$this->assertArrayNotHasKey( 'meta_schema', $result );
	}

	/**
	 * Test that unauthenticated requests return a forbidden error.
	 */
	public function test_no_permission_returns_error() {
		$result = $this->tool->execute(
			array( 'post_type' => 'post' ),
			array( 'user_id' => 0 )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code() );
	}

	/**
	 * Test that the tool implements the required interfaces.
	 */
	public function test_implements_interfaces() {
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Interface', $this->tool );
		$this->assertInstanceOf( 'WP_MCP_AI_Tool_Capability_Flags_Interface', $this->tool );
	}

	/**
	 * Test that a custom post type can also be described.
	 */
	public function test_custom_post_type_schema() {
		register_post_type(
			'test_cpt',
			array(
				'label'           => 'Test CPT',
				'public'          => true,
				'supports'        => array( 'title', 'editor' ),
				'capability_type' => 'post',
			)
		);

		$result = $this->tool->execute(
			array( 'post_type' => 'test_cpt' ),
			array( 'user_id' => $this->user_id )
		);

		$this->assertIsArray( $result );
		$this->assertEquals( 'test_cpt', $result['post_type'] );
		$this->assertContains( 'title', $result['supports'] );

		// Clean up.
		unregister_post_type( 'test_cpt' );
	}
}
