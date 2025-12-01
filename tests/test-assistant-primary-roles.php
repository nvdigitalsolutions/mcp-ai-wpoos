<?php
/**
 * Test Assistant Primary Roles Functionality
 *
 * Tests the programmatic primary roles feature for AI assistants.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for Assistant Primary Roles.
 */
class Test_Assistant_Primary_Roles extends WP_UnitTestCase {

	/**
	 * Test profession IDs.
	 *
	 * @var array
	 */
	protected $profession_ids = array();

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure classes are loaded.
		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
		}

		// Create test professions.
		$this->profession_ids['photographer'] = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_profession',
				'post_title'   => 'Test Photographer',
				'post_content' => 'Photography expertise',
				'post_status'  => 'publish',
			)
		);

		update_post_meta( $this->profession_ids['photographer'], '_wp_mcp_ai_profession_role_description', 'You are a professional photographer specializing in digital photography.' );
		update_post_meta( $this->profession_ids['photographer'], '_wp_mcp_ai_profession_knowledge_base', 'Understanding of lighting, composition, and camera settings is essential.' );
		update_post_meta( $this->profession_ids['photographer'], '_wp_mcp_ai_profession_expertise', array( 'Digital Photography', 'Lighting', 'Composition' ) );
		update_post_meta( $this->profession_ids['photographer'], '_wp_mcp_ai_profession_warnings', array( 'Respect copyright laws', 'Obtain proper releases' ) );

		$this->profession_ids['video_editor'] = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_profession',
				'post_title'   => 'Test Video Editor',
				'post_content' => 'Video editing expertise',
				'post_status'  => 'publish',
			)
		);

		update_post_meta( $this->profession_ids['video_editor'], '_wp_mcp_ai_profession_role_description', 'You are a professional video editor skilled in post-production.' );
		update_post_meta( $this->profession_ids['video_editor'], '_wp_mcp_ai_profession_knowledge_base', 'Video editing requires attention to pacing, transitions, and color grading.' );
		update_post_meta( $this->profession_ids['video_editor'], '_wp_mcp_ai_profession_expertise', array( 'Video Editing', 'Color Grading', 'Audio Sync' ) );

		$this->profession_ids['video_producer'] = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_profession',
				'post_title'   => 'Test Video Producer',
				'post_content' => 'Video production expertise',
				'post_status'  => 'publish',
			)
		);

		update_post_meta( $this->profession_ids['video_producer'], '_wp_mcp_ai_profession_role_description', 'You are a video producer managing production workflows.' );

		// Create test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_assistant',
				'post_title'   => 'Test Assistant',
				'post_content' => 'Test assistant for primary roles',
				'post_status'  => 'publish',
			)
		);
	}

	/**
	 * Tear down test.
	 */
	public function tearDown(): void {
		// Clean up.
		foreach ( $this->profession_ids as $id ) {
			wp_delete_post( $id, true );
		}
		wp_delete_post( $this->assistant_id, true );

		parent::tearDown();
	}

	/**
	 * Test sanitize_primary_roles_meta() with valid input.
	 */
	public function test_sanitize_primary_roles_meta_valid() {
		$input = array(
			$this->profession_ids['photographer'],
			$this->profession_ids['video_editor'],
		);

		$result = WP_MCP_AI_Assistant_CPT::sanitize_primary_roles_meta( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertEquals( $this->profession_ids['photographer'], $result[0] );
		$this->assertEquals( $this->profession_ids['video_editor'], $result[1] );
	}

	/**
	 * Test sanitize_primary_roles_meta() enforces max 3 limit.
	 */
	public function test_sanitize_primary_roles_meta_max_three() {
		// Create a 4th profession.
		$fourth_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Fourth Profession',
				'post_status' => 'publish',
			)
		);

		$input = array(
			$this->profession_ids['photographer'],
			$this->profession_ids['video_editor'],
			$this->profession_ids['video_producer'],
			$fourth_id,
		);

		$result = WP_MCP_AI_Assistant_CPT::sanitize_primary_roles_meta( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result, 'Should limit to 3 roles maximum' );

		wp_delete_post( $fourth_id, true );
	}

	/**
	 * Test sanitize_primary_roles_meta() removes duplicates.
	 */
	public function test_sanitize_primary_roles_meta_removes_duplicates() {
		$input = array(
			$this->profession_ids['photographer'],
			$this->profession_ids['photographer'],
			$this->profession_ids['video_editor'],
		);

		$result = WP_MCP_AI_Assistant_CPT::sanitize_primary_roles_meta( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result, 'Should remove duplicate entries' );
	}

	/**
	 * Test sanitize_primary_roles_meta() rejects invalid profession IDs.
	 */
	public function test_sanitize_primary_roles_meta_rejects_invalid() {
		$input = array(
			$this->profession_ids['photographer'],
			99999, // Invalid ID.
			'invalid', // Invalid type.
			$this->assistant_id, // Valid ID but wrong post type.
		);

		$result = WP_MCP_AI_Assistant_CPT::sanitize_primary_roles_meta( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result, 'Should only keep valid profession IDs' );
		$this->assertEquals( $this->profession_ids['photographer'], $result[0] );
	}

	/**
	 * Test sanitize_primary_roles_meta() with non-array input.
	 */
	public function test_sanitize_primary_roles_meta_non_array() {
		$result = WP_MCP_AI_Assistant_CPT::sanitize_primary_roles_meta( 'not an array' );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );

		$result = WP_MCP_AI_Assistant_CPT::sanitize_primary_roles_meta( null );
		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Test build_prompt_from_primary_roles() with single role.
	 */
	public function test_build_prompt_from_single_role() {
		$roles = array( $this->profession_ids['photographer'] );

		$prompt = WP_MCP_AI_Assistant_CPT::build_prompt_from_primary_roles( $roles );

		$this->assertIsString( $prompt );
		$this->assertStringContainsString( 'Test Photographer', $prompt );
		$this->assertStringContainsString( 'professional photographer', $prompt );
		$this->assertStringContainsString( 'Digital Photography', $prompt );
		$this->assertStringContainsString( 'Respect copyright laws', $prompt );
	}

	/**
	 * Test build_prompt_from_primary_roles() with multiple roles.
	 */
	public function test_build_prompt_from_multiple_roles() {
		$roles = array(
			$this->profession_ids['photographer'],
			$this->profession_ids['video_editor'],
		);

		$prompt = WP_MCP_AI_Assistant_CPT::build_prompt_from_primary_roles( $roles );

		$this->assertIsString( $prompt );
		$this->assertStringContainsString( 'Test Photographer', $prompt );
		$this->assertStringContainsString( 'Test Video Editor', $prompt );
		$this->assertStringContainsString( 'professional photographer', $prompt );
		$this->assertStringContainsString( 'professional video editor', $prompt );
	}

	/**
	 * Test build_prompt_from_primary_roles() with empty array.
	 */
	public function test_build_prompt_from_empty_array() {
		$prompt = WP_MCP_AI_Assistant_CPT::build_prompt_from_primary_roles( array() );

		$this->assertIsString( $prompt );
		$this->assertEmpty( $prompt );
	}

	/**
	 * Test build_prompt_from_primary_roles() with invalid role ID.
	 */
	public function test_build_prompt_from_invalid_role() {
		$roles = array( 99999 );

		$prompt = WP_MCP_AI_Assistant_CPT::build_prompt_from_primary_roles( $roles );

		$this->assertIsString( $prompt );
		$this->assertEmpty( $prompt );
	}

	/**
	 * Test get_assistant_configuration() includes primary roles prompt.
	 */
	public function test_get_assistant_configuration_with_primary_roles() {
		// Set primary roles.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_primary_roles', array( $this->profession_ids['photographer'] ) );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'system_prompt', $config );
		$this->assertStringContainsString( 'Test Photographer', $config['system_prompt'] );
		$this->assertStringContainsString( 'professional photographer', $config['system_prompt'] );
	}

	/**
	 * Test get_assistant_configuration() combines primary roles with custom prompt.
	 */
	public function test_get_assistant_configuration_combines_prompts() {
		// Set primary roles.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_primary_roles', array( $this->profession_ids['photographer'] ) );

		// Set custom system prompt.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_system_prompt', 'Custom additional instructions here.' );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'system_prompt', $config );
		$this->assertStringContainsString( 'Test Photographer', $config['system_prompt'] );
		$this->assertStringContainsString( 'Custom additional instructions here.', $config['system_prompt'] );
	}

	/**
	 * Test get_assistant_configuration() with no primary roles.
	 */
	public function test_get_assistant_configuration_without_primary_roles() {
		// Set only custom system prompt.
		update_post_meta( $this->assistant_id, '_wp_mcp_ai_system_prompt', 'Only custom prompt.' );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $this->assistant_id );

		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'system_prompt', $config );
		$this->assertEquals( 'Only custom prompt.', $config['system_prompt'] );
	}

	/**
	 * Test that primary-roles metabox is registered.
	 */
	public function test_primary_roles_metabox_is_registered() {
		// Create a mock registry.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		// Create assistant CPT instance.
		$assistant_cpt = new WP_MCP_AI_Assistant_CPT( $registry );

		// Simulate editing an assistant post.
		global $current_screen;
		$current_screen = (object) array(
			'post_type' => 'mcp_ai_assistant',
		);

		// Call register_meta_boxes.
		$assistant_cpt->register_meta_boxes();

		// Check that the primary-roles metabox is registered.
		global $wp_meta_boxes;
		$this->assertNotEmpty( $wp_meta_boxes['mcp_ai_assistant'], 'Metaboxes should be registered for assistant post type' );

		// Find the primary-roles metabox.
		$found = false;
		foreach ( $wp_meta_boxes['mcp_ai_assistant'] as $context => $priority_boxes ) {
			foreach ( $priority_boxes as $priority => $boxes ) {
				if ( isset( $boxes['wp_mcp_ai_primary_roles'] ) ) {
					$found = true;
					break 2;
				}
			}
		}

		$this->assertTrue( $found, 'Primary Roles metabox should be registered' );

		// Clean up.
		$current_screen = null;
	}
}
