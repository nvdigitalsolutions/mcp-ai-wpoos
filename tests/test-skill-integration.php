<?php
/**
 * Tests for Agent Skills integration with the assistant CPT.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Skill_Integration_Test extends WP_UnitTestCase {

	/**
	 * Temporary skill directory for testing.
	 *
	 * @var string
	 */
	private $test_skills_dir;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		WP_MCP_AI_Skill_Registry::reset();

		$this->test_skills_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-skills-integration-' . uniqid();
		mkdir( $this->test_skills_dir, 0755, true );

		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
		$this->recursive_rmdir( $this->test_skills_dir );
		WP_MCP_AI_Skill_Registry::reset();
		delete_option( WP_MCP_AI_Skill_Registry::OPTION_SKILL_INDEX );

		parent::tearDown();
	}

	/**
	 * Filter upload dir for test isolation.
	 *
	 * @param array $upload_dir Upload directory data.
	 * @return array Modified upload directory data.
	 */
	public function filter_upload_dir( $upload_dir ) {
		$upload_dir['basedir'] = dirname( $this->test_skills_dir );
		return $upload_dir;
	}

	/**
	 * Test that META_SKILLS constant is defined.
	 */
	public function test_meta_skills_constant_exists() {
		$this->assertSame( '_wp_mcp_ai_skills', WP_MCP_AI_Assistant_CPT::META_SKILLS );
	}

	/**
	 * Test that skills are included in system prompt via get_assistant_configuration.
	 */
	public function test_skills_injected_into_system_prompt() {
		// Install a skill.
		$skill_content = "---\nname: test-inject-skill\ndescription: Injected into prompt.\n---\n\n# Test Injection\n\nFollow these instructions.";
		$registry      = WP_MCP_AI_Skill_Registry::instance();
		$registry->install_skill( $skill_content );

		// Create an assistant post.
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Skills Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Assign the skill.
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SKILLS, array( 'test-inject-skill' ) );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, 'You are a helpful assistant.' );

		// Get the configuration.
		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$this->assertNotEmpty( $config['system_prompt'] );
		$this->assertStringContainsString( 'Active Skills', $config['system_prompt'] );
		$this->assertStringContainsString( 'test-inject-skill', $config['system_prompt'] );
		$this->assertStringContainsString( 'Follow these instructions.', $config['system_prompt'] );
		$this->assertStringContainsString( 'You are a helpful assistant.', $config['system_prompt'] );
	}

	/**
	 * Test that skills work without a base system prompt.
	 */
	public function test_skills_without_base_system_prompt() {
		$skill_content = "---\nname: standalone-skill\ndescription: Works alone.\n---\n\nStandalone instructions.";
		$registry      = WP_MCP_AI_Skill_Registry::instance();
		$registry->install_skill( $skill_content );

		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'Skills Only Assistant',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SKILLS, array( 'standalone-skill' ) );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$this->assertNotEmpty( $config['system_prompt'] );
		$this->assertStringContainsString( 'Standalone instructions.', $config['system_prompt'] );
	}

	/**
	 * Test that empty skills array does not affect system prompt.
	 */
	public function test_empty_skills_does_not_modify_prompt() {
		$assistant_id = $this->factory->post->create(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_title'  => 'No Skills Assistant',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, 'Base prompt only.' );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_SKILLS, array() );

		$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );

		$this->assertSame( 'Base prompt only.', $config['system_prompt'] );
	}

	/**
	 * Test sanitize_skills_meta with valid inputs.
	 */
	public function test_sanitize_skills_meta_valid() {
		$input = array( 'brand-guidelines', 'internal-comms', 'skill123' );

		$result = WP_MCP_AI_Assistant_CPT::sanitize_skills_meta( $input );

		$this->assertCount( 3, $result );
		$this->assertContains( 'brand-guidelines', $result );
		$this->assertContains( 'internal-comms', $result );
		$this->assertContains( 'skill123', $result );
	}

	/**
	 * Test sanitize_skills_meta filters invalid names.
	 */
	public function test_sanitize_skills_meta_filters_invalid() {
		$input = array(
			'valid-skill',
			'Invalid-Name',      // Uppercase.
			'--leading-hyphens', // Leading hyphens.
			'double--hyphen',    // Consecutive hyphens.
			'',                  // Empty.
			str_repeat( 'a', 65 ), // Too long.
		);

		$result = WP_MCP_AI_Assistant_CPT::sanitize_skills_meta( $input );

		$this->assertCount( 1, $result );
		$this->assertContains( 'valid-skill', $result );
	}

	/**
	 * Test sanitize_skills_meta removes duplicates.
	 */
	public function test_sanitize_skills_meta_removes_duplicates() {
		$input = array( 'skill-one', 'skill-one', 'skill-two' );

		$result = WP_MCP_AI_Assistant_CPT::sanitize_skills_meta( $input );

		$this->assertCount( 2, $result );
	}

	/**
	 * Test sanitize_skills_meta with non-array input.
	 */
	public function test_sanitize_skills_meta_non_array() {
		$result = WP_MCP_AI_Assistant_CPT::sanitize_skills_meta( 'not-an-array' );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Recursively remove a directory (test cleanup helper).
	 *
	 * @param string $dir Directory path.
	 */
	private function recursive_rmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->recursive_rmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
