<?php
/**
 * Tests for the Agent Skills registry.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_Skill_Registry_Test extends WP_UnitTestCase {

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

		// Use a temp dir for testing to avoid affecting real uploads.
		$this->test_skills_dir = sys_get_temp_dir() . '/wp-mcp-ai-test-skills-' . uniqid();
		mkdir( $this->test_skills_dir, 0755, true );

		// Override the upload dir for tests.
		add_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'upload_dir', array( $this, 'filter_upload_dir' ) );

		// Clean up temp dir.
		$this->recursive_rmdir( $this->test_skills_dir );

		WP_MCP_AI_Skill_Registry::reset();
		delete_option( WP_MCP_AI_Skill_Registry::OPTION_SKILL_INDEX );

		parent::tearDown();
	}

	/**
	 * Filter upload dir to use a temp directory for tests.
	 *
	 * @param array $upload_dir Upload directory data.
	 * @return array Modified upload directory data.
	 */
	public function filter_upload_dir( $upload_dir ) {
		$upload_dir['basedir'] = dirname( $this->test_skills_dir );
		return $upload_dir;
	}

	/**
	 * Test singleton instance.
	 */
	public function test_singleton_instance() {
		$instance1 = WP_MCP_AI_Skill_Registry::instance();
		$instance2 = WP_MCP_AI_Skill_Registry::instance();

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test loading skills from empty directory.
	 */
	public function test_load_skills_empty_directory() {
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$skills   = $registry->get_all_skills();

		$this->assertIsArray( $skills );
		$this->assertEmpty( $skills );
	}

	/**
	 * Test installing a skill from content.
	 */
	public function test_install_skill() {
		$content = "---\nname: test-skill\ndescription: A test skill.\n---\n\n# Test\n\nInstructions here.";

		$registry = WP_MCP_AI_Skill_Registry::instance();
		$result   = $registry->install_skill( $content );

		$this->assertNotWPError( $result );
		$this->assertSame( 'test-skill', $result['name'] );

		// Verify skill is now accessible.
		$skill = $registry->get_skill( 'test-skill' );
		$this->assertNotNull( $skill );
		$this->assertSame( 'A test skill.', $skill['description'] );
	}

	/**
	 * Test that installed skill files exist on disk.
	 */
	public function test_install_skill_creates_files() {
		$content = "---\nname: disk-skill\ndescription: Written to disk.\n---\n\nInstructions.";

		$registry = WP_MCP_AI_Skill_Registry::instance();
		$registry->install_skill( $content );

		$skill_dir  = $registry->get_skills_dir() . '/disk-skill';
		$skill_file = $skill_dir . '/SKILL.md';

		$this->assertDirectoryExists( $skill_dir );
		$this->assertFileExists( $skill_file );
	}

	/**
	 * Test installing a skill with extra files.
	 */
	public function test_install_skill_with_extra_files() {
		$content     = "---\nname: extra-files-skill\ndescription: Has extra files.\n---\n\nInstructions.";
		$extra_files = array(
			'examples/example1.md' => '# Example 1',
			'resources/data.json'  => '{"key": "value"}',
		);

		$registry = WP_MCP_AI_Skill_Registry::instance();
		$result   = $registry->install_skill( $content, $extra_files );

		$this->assertNotWPError( $result );

		$skill_dir = $registry->get_skills_dir() . '/extra-files-skill';
		$this->assertFileExists( $skill_dir . '/examples/example1.md' );
		$this->assertFileExists( $skill_dir . '/resources/data.json' );
	}

	/**
	 * Test uninstalling a skill.
	 */
	public function test_uninstall_skill() {
		$content = "---\nname: removable-skill\ndescription: Will be removed.\n---\n\nInstructions.";

		$registry = WP_MCP_AI_Skill_Registry::instance();
		$registry->install_skill( $content );

		// Verify installed.
		$skill = $registry->get_skill( 'removable-skill' );
		$this->assertNotNull( $skill );

		// Uninstall.
		$result = $registry->uninstall_skill( 'removable-skill' );
		$this->assertTrue( $result );

		// Verify removed.
		$skill = $registry->get_skill( 'removable-skill' );
		$this->assertNull( $skill );
	}

	/**
	 * Test uninstalling a nonexistent skill returns error.
	 */
	public function test_uninstall_nonexistent_skill() {
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$result   = $registry->uninstall_skill( 'does-not-exist' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_skill_not_found', $result->get_error_code() );
	}

	/**
	 * Test uninstalling with directory traversal is rejected.
	 */
	public function test_uninstall_directory_traversal() {
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$result   = $registry->uninstall_skill( '../../../etc' );

		$this->assertWPError( $result );
	}

	/**
	 * Test getting skill index returns lightweight data.
	 */
	public function test_get_skill_index() {
		$content1 = "---\nname: skill-one\ndescription: First skill.\n---\n\nInstructions 1.";
		$content2 = "---\nname: skill-two\ndescription: Second skill.\n---\n\nInstructions 2.";

		$registry = WP_MCP_AI_Skill_Registry::instance();
		$registry->install_skill( $content1 );
		$registry->install_skill( $content2 );

		$index = $registry->get_skill_index();

		$this->assertIsArray( $index );
		$this->assertCount( 2, $index );

		$names = array_column( $index, 'name' );
		$this->assertContains( 'skill-one', $names );
		$this->assertContains( 'skill-two', $names );
	}

	/**
	 * Test building skills prompt from selected skills.
	 */
	public function test_build_skills_prompt() {
		$content1 = "---\nname: prompt-skill\ndescription: Skill with instructions.\n---\n\n# Important Guidelines\n\nFollow these rules carefully.";

		$registry = WP_MCP_AI_Skill_Registry::instance();
		$registry->install_skill( $content1 );

		$prompt = $registry->build_skills_prompt( array( 'prompt-skill' ) );

		$this->assertNotEmpty( $prompt );
		$this->assertStringContainsString( 'Active Skills', $prompt );
		$this->assertStringContainsString( 'prompt-skill', $prompt );
		$this->assertStringContainsString( 'Skill with instructions.', $prompt );
		$this->assertStringContainsString( 'Important Guidelines', $prompt );
		$this->assertStringContainsString( 'Follow these rules carefully.', $prompt );
	}

	/**
	 * Test building skills prompt with empty selection.
	 */
	public function test_build_skills_prompt_empty() {
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$prompt   = $registry->build_skills_prompt( array() );

		$this->assertSame( '', $prompt );
	}

	/**
	 * Test building skills prompt with nonexistent skill name.
	 */
	public function test_build_skills_prompt_nonexistent_skill() {
		$registry = WP_MCP_AI_Skill_Registry::instance();
		$prompt   = $registry->build_skills_prompt( array( 'nonexistent-skill' ) );

		$this->assertSame( '', $prompt );
	}

	/**
	 * Test building skills prompt with multiple skills.
	 */
	public function test_build_skills_prompt_multiple() {
		$content1 = "---\nname: skill-alpha\ndescription: Alpha skill.\n---\n\nAlpha instructions.";
		$content2 = "---\nname: skill-beta\ndescription: Beta skill.\n---\n\nBeta instructions.";

		$registry = WP_MCP_AI_Skill_Registry::instance();
		$registry->install_skill( $content1 );
		$registry->install_skill( $content2 );

		$prompt = $registry->build_skills_prompt( array( 'skill-alpha', 'skill-beta' ) );

		$this->assertStringContainsString( 'skill-alpha', $prompt );
		$this->assertStringContainsString( 'skill-beta', $prompt );
		$this->assertStringContainsString( 'Alpha instructions.', $prompt );
		$this->assertStringContainsString( 'Beta instructions.', $prompt );
		// Check separator between skills.
		$this->assertStringContainsString( '---', $prompt );
	}

	/**
	 * Test that invalid SKILL.md content is rejected during install.
	 */
	public function test_install_invalid_skill() {
		$content = 'No frontmatter here, just text.';

		$registry = WP_MCP_AI_Skill_Registry::instance();
		$result   = $registry->install_skill( $content );

		$this->assertWPError( $result );
	}

	/**
	 * Test that folder name must match skill name.
	 */
	public function test_folder_name_must_match_skill_name() {
		// Manually create a skill with mismatched folder name.
		$skills_dir = WP_MCP_AI_Skill_Registry::instance()->get_skills_dir();
		$wrong_dir  = $skills_dir . '/wrong-name';
		mkdir( $wrong_dir, 0755, true );
		file_put_contents(
			$wrong_dir . '/SKILL.md',
			"---\nname: correct-name\ndescription: Mismatched folder.\n---\n\nInstructions."
		);

		$registry = WP_MCP_AI_Skill_Registry::instance();
		$skills   = $registry->load_skills( true );

		// Should not load because folder name 'wrong-name' != skill name 'correct-name'.
		$this->assertArrayNotHasKey( 'correct-name', $skills );
		$this->assertArrayNotHasKey( 'wrong-name', $skills );
	}

	/**
	 * Test that skill index is persisted in options.
	 */
	public function test_skill_index_persisted_in_options() {
		$content = "---\nname: indexed-skill\ndescription: Should be indexed.\n---\n\nInstructions.";

		$registry = WP_MCP_AI_Skill_Registry::instance();
		$registry->install_skill( $content );

		$stored_index = get_option( WP_MCP_AI_Skill_Registry::OPTION_SKILL_INDEX );
		$this->assertIsArray( $stored_index );
		$this->assertArrayHasKey( 'indexed-skill', $stored_index );
		$this->assertSame( 'Should be indexed.', $stored_index['indexed-skill']['description'] );
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
